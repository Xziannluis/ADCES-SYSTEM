"""Retrieval-based feedback system for teacher evaluation forms.

This module stores pre-written feedback templates with SBERT embeddings and
retrieves the most similar template for each AI-assisted teacher evaluation field:
- strengths
- areas_for_improvement
- recommendations

The agreement field is intentionally excluded and should remain human-authored.
Storage defaults to SQLite for a simple local setup, while keeping the schema
portable enough to adapt to MySQL later.
"""

from __future__ import annotations

import json
import sqlite3
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Sequence, Tuple

import numpy as np
from sentence_transformers import SentenceTransformer


DEFAULT_MODEL_NAME = "sentence-transformers/all-MiniLM-L6-v2"
DEFAULT_DB_PATH = Path(__file__).with_name("feedback_templates.db")
DEFAULT_MYSQL_TABLE = "ai_feedback_templates"
SUPPORTED_FIELDS = (
    "strengths",
    "areas_for_improvement",
    "recommendations",
)


@dataclass(frozen=True)
class FeedbackTemplate:
    id: int
    field_name: str
    evaluation_comment: str
    feedback_text: str
    similarity: Optional[float] = None


class FeedbackTemplateBackend:
    def ensure_schema(self) -> None:
        raise NotImplementedError

    def insert_template(self, field_name: str, evaluation_comment: str, feedback_text: str, embedding_vector: str) -> int:
        raise NotImplementedError

    def fetch_templates(self, field_name: str) -> List[Dict[str, Any]]:
        raise NotImplementedError

    def count_templates(self) -> int:
        raise NotImplementedError

    def clear_templates(self) -> None:
        raise NotImplementedError

    def close(self) -> None:
        raise NotImplementedError


class SQLiteFeedbackTemplateBackend(FeedbackTemplateBackend):
    def __init__(self, db_path: str | Path) -> None:
        self.db_path = Path(db_path)
        self.connection = sqlite3.connect(self.db_path)
        self.connection.row_factory = sqlite3.Row

    def ensure_schema(self) -> None:
        self.connection.execute(
            """
            CREATE TABLE IF NOT EXISTS feedback_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                field_name TEXT NOT NULL,
                evaluation_comment TEXT NOT NULL,
                feedback_text TEXT NOT NULL,
                embedding_vector TEXT NOT NULL
            )
            """
        )
        self.connection.execute(
            "CREATE INDEX IF NOT EXISTS idx_feedback_field ON feedback_templates(field_name)"
        )
        self.connection.commit()

    def insert_template(self, field_name: str, evaluation_comment: str, feedback_text: str, embedding_vector: str) -> int:
        cursor = self.connection.execute(
            """
            INSERT INTO feedback_templates (field_name, evaluation_comment, feedback_text, embedding_vector)
            VALUES (?, ?, ?, ?)
            """,
            (field_name, evaluation_comment, feedback_text, embedding_vector),
        )
        self.connection.commit()
        return int(cursor.lastrowid)

    def fetch_templates(self, field_name: str) -> List[Dict[str, Any]]:
        cursor = self.connection.execute(
            """
            SELECT id, field_name, evaluation_comment, feedback_text, embedding_vector
            FROM feedback_templates
            WHERE field_name = ?
            ORDER BY id ASC
            """,
            (field_name,),
        )
        return [dict(row) for row in cursor.fetchall()]

    def count_templates(self) -> int:
        row = self.connection.execute("SELECT COUNT(*) AS total FROM feedback_templates").fetchone()
        return int(row["total"] if row else 0)

    def clear_templates(self) -> None:
        self.connection.execute("DELETE FROM feedback_templates")
        self.connection.commit()

    def close(self) -> None:
        self.connection.close()


class MySQLFeedbackTemplateBackend(FeedbackTemplateBackend):
    def __init__(self, connection: Any, table_name: str = DEFAULT_MYSQL_TABLE) -> None:
        self.connection = connection
        self.table_name = table_name

    def ensure_schema(self) -> None:
        with self.connection.cursor() as cur:
            cur.execute(
                f"""
                CREATE TABLE IF NOT EXISTS `{self.table_name}` (
                    `id` INT PRIMARY KEY AUTO_INCREMENT,
                    `field_name` VARCHAR(64) NOT NULL,
                    `evaluation_comment` TEXT NOT NULL,
                    `feedback_text` TEXT NOT NULL,
                    `embedding_vector` LONGTEXT NOT NULL,
                    `source` VARCHAR(64) DEFAULT 'seed',
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY `idx_field_name` (`field_name`),
                    KEY `idx_is_active` (`is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                """
            )
        self.connection.commit()

    def insert_template(self, field_name: str, evaluation_comment: str, feedback_text: str, embedding_vector: str) -> int:
        with self.connection.cursor() as cur:
            cur.execute(
                f"""
                INSERT INTO `{self.table_name}` (field_name, evaluation_comment, feedback_text, embedding_vector)
                VALUES (%s, %s, %s, %s)
                """,
                (field_name, evaluation_comment, feedback_text, embedding_vector),
            )
            inserted_id = int(cur.lastrowid)
        self.connection.commit()
        return inserted_id

    def fetch_templates(self, field_name: str) -> List[Dict[str, Any]]:
        with self.connection.cursor() as cur:
            cur.execute(
                f"""
                SELECT id, field_name, evaluation_comment, feedback_text, embedding_vector
                FROM `{self.table_name}`
                WHERE field_name = %s AND is_active = 1
                ORDER BY id ASC
                """,
                (field_name,),
            )
            rows = cur.fetchall()
        return list(rows)

    def count_templates(self) -> int:
        with self.connection.cursor() as cur:
            cur.execute(f"SELECT COUNT(*) AS total FROM `{self.table_name}` WHERE is_active = 1")
            row = cur.fetchone()
        return int((row or {}).get("total", 0))

    def clear_templates(self) -> None:
        with self.connection.cursor() as cur:
            cur.execute(f"DELETE FROM `{self.table_name}`")
        self.connection.commit()

    def close(self) -> None:
        try:
            self.connection.close()
        except Exception:
            pass


class FeedbackRetrievalSystem:
    def __init__(
        self,
        db_path: str | Path = DEFAULT_DB_PATH,
        model_name: str = DEFAULT_MODEL_NAME,
        backend: Optional[FeedbackTemplateBackend] = None,
    ) -> None:
        self.model_name = model_name
        self.model = SentenceTransformer(model_name)
        self.db_path = Path(db_path)
        self.backend = backend or SQLiteFeedbackTemplateBackend(db_path)
        self.ensure_schema()

    def ensure_schema(self) -> None:
        self.backend.ensure_schema()

    def encode_text(self, text: str) -> np.ndarray:
        vector = self.model.encode([text], convert_to_numpy=True, normalize_embeddings=True)[0]
        return np.asarray(vector, dtype=np.float32)

    @staticmethod
    def serialize_embedding(vector: np.ndarray) -> str:
        return json.dumps(vector.astype(float).tolist(), ensure_ascii=False)

    @staticmethod
    def deserialize_embedding(raw_value: str) -> np.ndarray:
        return np.asarray(json.loads(raw_value), dtype=np.float32)

    def add_feedback_template(
        self,
        field_name: str,
        evaluation_comment: str,
        feedback_text: str,
    ) -> int:
        if field_name not in SUPPORTED_FIELDS:
            raise ValueError(f"Unsupported field_name '{field_name}'. Expected one of: {', '.join(SUPPORTED_FIELDS)}")

        embedding = self.encode_text(evaluation_comment)
        return self.backend.insert_template(
            field_name,
            evaluation_comment.strip(),
            feedback_text.strip(),
            self.serialize_embedding(embedding),
        )

    @staticmethod
    def cosine_similarity(vector_a: np.ndarray, vector_b: np.ndarray) -> float:
        numerator = float(np.dot(vector_a, vector_b))
        denominator = float(np.linalg.norm(vector_a) * np.linalg.norm(vector_b)) + 1e-12
        return numerator / denominator

    def fetch_templates(self, field_name: str) -> List[Dict[str, Any]]:
        return self.backend.fetch_templates(field_name)

    def retrieve_best_feedback(
        self,
        field_name: str,
        evaluation_comment: str,
    ) -> Optional[FeedbackTemplate]:
        matches = self.retrieve_top_feedback(field_name, evaluation_comment, top_k=1)
        return matches[0] if matches else None

    def retrieve_top_feedback(
        self,
        field_name: str,
        evaluation_comment: str,
        top_k: int = 3,
    ) -> List[FeedbackTemplate]:
        if field_name not in SUPPORTED_FIELDS:
            raise ValueError(f"Unsupported field_name '{field_name}'. Expected one of: {', '.join(SUPPORTED_FIELDS)}")

        templates = self.fetch_templates(field_name)
        if not templates:
            return []

        query_embedding = self.encode_text(evaluation_comment)
        scored_rows: List[Dict[str, Any]] = []

        for row in templates:
            stored_embedding = self.deserialize_embedding(row["embedding_vector"])
            score = self.cosine_similarity(query_embedding, stored_embedding)
            copy = dict(row)
            copy["_score"] = score
            scored_rows.append(copy)

        scored_rows.sort(key=lambda item: item.get("_score", -1.0), reverse=True)
        selected = scored_rows[: max(1, int(top_k or 1))]

        return [
            FeedbackTemplate(
                id=int(row["id"]),
                field_name=str(row["field_name"]),
                evaluation_comment=str(row["evaluation_comment"]),
                feedback_text=str(row["feedback_text"]),
                similarity=float(row.get("_score", 0.0)),
            )
            for row in selected
        ]

    def retrieve_feedback_for_form(self, evaluation_inputs: Dict[str, str]) -> Dict[str, Optional[FeedbackTemplate]]:
        results: Dict[str, Optional[FeedbackTemplate]] = {}
        for field_name in SUPPORTED_FIELDS:
            query = (evaluation_inputs.get(field_name) or "").strip()
            if not query:
                results[field_name] = None
                continue
            results[field_name] = self.retrieve_best_feedback(field_name, query)
        return results

    def seed_feedback_templates(self, templates: Iterable[Dict[str, str]]) -> None:
        for template in templates:
            self.add_feedback_template(
                field_name=template["field_name"],
                evaluation_comment=template["evaluation_comment"],
                feedback_text=template["feedback_text"],
            )

    def count_templates(self) -> int:
        return self.backend.count_templates()

    def clear_templates(self) -> None:
        self.backend.clear_templates()

    def close(self) -> None:
        self.backend.close()


def mysql_backend_from_config(config: Dict[str, str], table_name: str = DEFAULT_MYSQL_TABLE) -> MySQLFeedbackTemplateBackend:
    import pymysql  # type: ignore

    connection = pymysql.connect(
        host=config.get("host", "127.0.0.1"),
        user=config.get("user", "root"),
        password=config.get("password", ""),
        database=config.get("database", "ai_classroom_eval"),
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
    )
    return MySQLFeedbackTemplateBackend(connection=connection, table_name=table_name)


def default_seed_templates() -> List[Dict[str, str]]:
    return [
        {
            "field_name": "strengths",
            "evaluation_comment": "The teacher explains lessons clearly and presents content in an organized way.",
            "feedback_text": "The teacher demonstrates strong instructional clarity and presents lesson content in a well-organized manner.",
        },
        {
            "field_name": "strengths",
            "evaluation_comment": "The teacher keeps the classroom orderly and maintains respectful interactions with students.",
            "feedback_text": "The teacher maintains a positive learning environment through clear routines and respectful classroom management.",
        },
        {
            "field_name": "areas_for_improvement",
            "evaluation_comment": "The teacher explains lessons clearly but students rarely participate.",
            "feedback_text": "The teacher demonstrates strong instructional clarity but could improve student engagement through interactive activities.",
        },
        {
            "field_name": "areas_for_improvement",
            "evaluation_comment": "Only a few students answer questions and participation is limited during discussions.",
            "feedback_text": "Student participation remains limited during discussions and would benefit from more inclusive questioning strategies.",
        },
        {
            "field_name": "recommendations",
            "evaluation_comment": "Students rarely participate and need more opportunities to engage in the lesson.",
            "feedback_text": "Use collaborative tasks, guided questioning, and short interactive checks to increase student participation during lessons.",
        },
        {
            "field_name": "recommendations",
            "evaluation_comment": "Assessment checks are not frequent enough to monitor student understanding.",
            "feedback_text": "Integrate brief formative assessments and follow-up prompts throughout the lesson to monitor learner understanding more consistently.",
        },
    ]


def generate_seed_templates(per_field: int = 100) -> List[Dict[str, str]]:
    if per_field <= 0:
        return []

    rating_bands: List[Tuple[str, str, str, str, str]] = [
        ("excellent", "high-performing", "highly consistent", "The observed class shows strong evidence of effective practice", "Performance is already strong and should now be sustained and deepened."),
        ("very satisfactory", "well-developed", "consistently visible", "The observed class shows dependable evidence of effective teaching practice", "Performance is solid, with targeted refinements needed to reach an excellent level."),
        ("satisfactory", "developing", "partially established", "The observed class shows acceptable but still developing evidence of effective practice", "Performance is workable but still needs more consistent classroom evidence."),
        ("below satisfactory", "priority support", "not yet consistent", "The observed class shows limited evidence in this area and needs focused improvement support", "Performance needs stronger routines, follow-through, and targeted support."),
        ("needs improvement", "intensive support", "rarely visible", "The observed class shows minimal evidence in this area and needs immediate instructional support", "Performance should focus first on foundational routines and observable classroom improvement."),
    ]

    subjects = [
        "Mathematics lesson",
        "Science lesson",
        "English lesson",
        "Programming lesson",
        "Social studies lesson",
        "Business class",
        "General education class",
        "Laboratory session",
    ]
    observation_types = ["formal observation", "informal observation", "classroom walk-through"]

    strengths_focuses = [
        ("instructional clarity", "explains concepts clearly and connects examples to lesson goals", "demonstrates strong instructional clarity by presenting key concepts through clear explanations and relevant examples"),
        ("classroom management", "maintains orderly routines and respectful learner behavior throughout the class", "maintains a productive classroom climate through consistent routines and respectful management practices"),
        ("learner engagement", "encourages active participation and keeps learners focused during activities", "creates engaging learning opportunities that keep students attentive and involved in lesson tasks"),
        ("assessment practices", "checks for understanding during instruction and responds to learner needs", "uses formative assessment purposefully to monitor understanding and adjust instruction when needed"),
        ("lesson organization", "presents the lesson in a structured sequence with smooth transitions", "delivers a well-organized lesson sequence that supports learner progress from one task to the next"),
        ("learner support", "adapts explanations and guidance when learners need support", "responds to learner needs with appropriate guidance and instructional adjustments"),
        ("questioning technique", "uses questions that encourage thinking and learner participation", "uses purposeful questioning to keep learners thinking and participating throughout the lesson"),
        ("learning climate", "creates a respectful atmosphere where learners remain focused and cooperative", "builds a positive learning climate that supports concentration, respect, and classroom participation"),
        ("content mastery", "shows command of the lesson content and answers learner questions with confidence", "shows strong content mastery by explaining ideas accurately and responding confidently to learner questions"),
        ("instructional delivery", "presents lesson steps in a logical sequence and keeps tasks aligned to objectives", "delivers instruction in a logical, objective-aligned sequence that helps learners follow the lesson clearly"),
        ("student support", "monitors struggling learners and provides timely support during tasks", "supports learner progress by monitoring needs closely and offering timely guidance during classroom tasks"),
        ("use of resources", "uses instructional materials that help learners understand the lesson", "uses classroom resources and instructional materials effectively to support learner understanding"),
    ]
    strengths_modifiers = [
        ("during whole-class discussion", "throughout the observation period"),
        ("during guided practice", "across the lesson sequence"),
        ("while facilitating learner tasks", "in ways that supported steady learner participation"),
        ("during transitions between activities", "while sustaining a focused classroom atmosphere"),
        ("while presenting examples and directions", "with consistent attention to learner understanding"),
        ("during collaborative learning tasks", "while encouraging responsible learner participation"),
        ("during recitation and questioning", "while keeping the lesson structured and responsive"),
        ("throughout the class period", "while maintaining clear instructional flow"),
    ]

    improvement_focuses = [
        ("student participation", "student participation remains limited and only a few learners respond during questioning", "student participation remains uneven and would benefit from broader engagement strategies during class interaction"),
        ("formative assessment", "checks for understanding are not yet frequent enough to confirm learner mastery", "formative assessment routines could be used more consistently to verify learner understanding before moving forward"),
        ("lesson pacing", "lesson pacing slows during task transitions and affects instructional momentum", "lesson pacing during transitions could be strengthened to maintain momentum and maximize instructional time"),
        ("questioning techniques", "questions are often directed to the same learners and limit wider participation", "questioning strategies could be expanded to involve a wider range of learners during recitation and discussion"),
        ("feedback follow-through", "feedback is provided but is not always followed by opportunities for learners to respond or improve", "feedback follow-through could be strengthened by giving learners time to act on corrections and refine their responses"),
        ("instructional differentiation", "activities are delivered in one way and do not yet address varied learner needs", "instructional differentiation could be strengthened so activities better address varied learner readiness and participation levels"),
        ("classroom routines", "classroom routines are not yet applied consistently during shifts in lesson activities", "classroom routines could be strengthened to improve consistency during transitions and independent work"),
        ("learner accountability", "learners are not always held accountable for completing and responding to tasks", "learner accountability routines could be reinforced so more students stay engaged and complete lesson tasks"),
        ("lesson objectives", "lesson objectives are not always revisited clearly during the observation", "lesson objectives could be emphasized more consistently so learners remain aware of the target outcomes throughout the class"),
        ("feedback quality", "feedback is present but not always specific enough to guide learner improvement", "feedback quality could be improved by giving learners more specific guidance linked to lesson expectations"),
        ("engagement strategies", "activities do not always sustain learner attention across the full lesson", "engagement strategies could be strengthened so learner attention remains more consistent from beginning to end of the lesson"),
        ("monitoring of learning", "learner understanding is not always monitored before the lesson progresses", "monitoring of learning could be more visible so instructional decisions are clearly guided by learner responses"),
    ]
    improvement_modifiers = [
        ("during independent work", "before the lesson moves to the next task"),
        ("during class discussion", "to make the improvement more visible in future observations"),
        ("during group activities", "so learner engagement remains consistent across the class"),
        ("during assessment segments", "to support stronger evidence of learner understanding"),
        ("throughout the observation period", "while keeping instruction focused and efficient"),
        ("during transitions between lesson parts", "so instructional flow remains steady and purposeful"),
        ("during recitation and questioning", "to broaden learner participation and response quality"),
        ("during follow-up activities", "so feedback leads to clearer learner improvement"),
    ]

    recommendation_focuses = [
        ("participation", "use think-pair-share, random calling, and brief collaborative tasks to increase learner participation", "Use structured participation routines such as think-pair-share, random calling, and brief collaborative tasks to involve more learners during the lesson."),
        ("assessment", "embed short formative checks and targeted follow-up questions throughout instruction", "Integrate short formative checks and targeted follow-up questions throughout instruction to monitor learner understanding in real time."),
        ("transitions", "prepare clear transition cues and concise directions before each activity shift", "Use clear transition cues and concise directions before each activity shift to maintain pacing and reduce downtime."),
        ("feedback", "provide immediate corrective feedback and allow learners to revise their responses", "Provide immediate corrective feedback and brief opportunities for learners to revise their responses after checking for understanding."),
        ("questioning", "plan inclusive questioning routines that invite responses from a wider range of learners", "Plan inclusive questioning routines that distribute participation more evenly and encourage broader learner involvement."),
        ("differentiation", "prepare adjusted supports and extension prompts for learners with different readiness levels", "Provide differentiated supports and extension prompts so lesson tasks remain accessible and challenging for a wider range of learners."),
        ("accountability", "use visible monitoring routines and quick completion checks during learner tasks", "Use visible monitoring routines and quick completion checks so learners remain accountable during guided and independent work."),
        ("routines", "model procedures clearly and revisit classroom routines before each major activity", "Reinforce classroom routines by modeling procedures clearly and revisiting expectations before major lesson activities."),
        ("lesson objectives", "restate lesson objectives during key transitions and connect each task to the target outcome", "Restate lesson objectives during key transitions and connect each task clearly to the target outcome so learners understand the purpose of each activity."),
        ("feedback specificity", "give more specific feedback statements linked to learner responses and performance criteria", "Provide more specific feedback statements linked to learner responses and performance criteria so learners know exactly how to improve."),
        ("content checks", "pause after key explanations and use short oral or written checks before moving on", "Pause after key explanations and use quick oral or written checks before moving forward so learner understanding is confirmed during the lesson."),
        ("support routines", "plan short teacher check-ins for learners who need additional support during tasks", "Plan brief teacher check-ins for learners who need additional support so instructional guidance becomes more visible during classroom activities."),
    ]
    recommendation_modifiers = [
        "This can make the targeted improvement more visible in the next observation.",
        "This should support stronger learner response and clearer classroom evidence.",
        "This can help sustain instructional momentum while improving learner participation.",
        "This may strengthen both lesson delivery and learner understanding during class activities.",
        "This can support more consistent evidence of effective teaching practice.",
        "This can help the teacher move the observed practice from developing to more consistent performance.",
        "This may help turn observed weaknesses into clearer strengths during the next cycle of evaluation.",
        "This can improve the visibility of effective teaching decisions during future classroom observations.",
    ]

    reflection_phrases = [
        "Based on the observed evidence,",
        "In the observed lesson,",
        "During the classroom observation,",
        "From the classroom evidence gathered,",
        "According to the performance indicators observed,",
        "Across the lesson segments observed,",
    ]

    human_closers = [
        "This was visible in the way the lesson was delivered and monitored.",
        "This gave the class a clearer sense of direction and participation.",
        "This made the teaching practice more noticeable during the observation.",
        "This contributed to a more organized and learner-focused lesson flow.",
        "This added stronger evidence of intentional and responsive teaching practice.",
        "This helped show a clearer link between instruction, participation, and follow-through.",
    ]

    output: List[Dict[str, str]] = []

    def add(field_name: str, evaluation_comment: str, feedback_text: str) -> None:
        output.append(
            {
                "field_name": field_name,
                "evaluation_comment": evaluation_comment.strip().rstrip(".") + ".",
                "feedback_text": feedback_text.strip().rstrip(".") + ".",
            }
        )

    for index in range(per_field):
        band = rating_bands[index % len(rating_bands)]
        focus = strengths_focuses[(index // len(rating_bands)) % len(strengths_focuses)]
        modifier = strengths_modifiers[(index // (len(rating_bands) * len(strengths_focuses))) % len(strengths_modifiers)]
        opener = reflection_phrases[index % len(reflection_phrases)]
        closer = human_closers[(index // len(reflection_phrases)) % len(human_closers)]
        subject = subjects[index % len(subjects)]
        observation = observation_types[(index // len(subjects)) % len(observation_types)]
        add(
            "strengths",
            f"{opener} in the {subject}, the teacher shows {band[1]} evidence of {focus[0]} and {focus[1]} {modifier[0]} during a {observation}",
            f"The teacher {focus[2]} {modifier[1]}. For a {band[0]} rating pattern, this reflects {band[2]} performance in the observed area. {band[4]} {closer}",
        )

    for index in range(per_field):
        band = rating_bands[index % len(rating_bands)]
        focus = improvement_focuses[(index // len(rating_bands)) % len(improvement_focuses)]
        modifier = improvement_modifiers[(index // (len(rating_bands) * len(improvement_focuses))) % len(improvement_modifiers)]
        opener = reflection_phrases[index % len(reflection_phrases)]
        closer = human_closers[(index // len(reflection_phrases)) % len(human_closers)]
        subject = subjects[index % len(subjects)]
        observation = observation_types[(index // len(subjects)) % len(observation_types)]
        add(
            "areas_for_improvement",
            f"{opener} in the {subject}, {focus[1]} {modifier[0]} during a {observation} and reflects a {band[0]} performance concern in this criterion",
            f"The teacher's practice indicates that {focus[2]} {modifier[1]}. This pattern is more common in {band[0]} profiles where the target practice is {band[2]}. {band[4]} {closer}",
        )

    for index in range(per_field):
        band = rating_bands[index % len(rating_bands)]
        focus = recommendation_focuses[(index // len(rating_bands)) % len(recommendation_focuses)]
        closer = recommendation_modifiers[(index // (len(rating_bands) * len(recommendation_focuses))) % len(recommendation_modifiers)]
        opener = reflection_phrases[index % len(reflection_phrases)]
        subject = subjects[index % len(subjects)]
        observation = observation_types[(index // len(subjects)) % len(observation_types)]
        add(
            "recommendations",
            f"{opener} in the {subject}, the teacher needs support in {focus[0]} and should {focus[1]} to address a {band[0]} classroom performance pattern noted in the {observation}",
            f"{focus[2]} This recommendation is appropriate when the observed practice is {band[2]} and the rating pattern trends toward {band[0]}. {band[4]} {closer}",
        )

    return output


def build_mysql_seed_system(config: Dict[str, str], table_name: str = DEFAULT_MYSQL_TABLE) -> FeedbackRetrievalSystem:
    backend = mysql_backend_from_config(config=config, table_name=table_name)
    return FeedbackRetrievalSystem(backend=backend)


def build_demo_system(db_path: str | Path = DEFAULT_DB_PATH) -> FeedbackRetrievalSystem:
    system = FeedbackRetrievalSystem(db_path=db_path)
    existing = system.count_templates()
    if int(existing) == 0:
        system.seed_feedback_templates(default_seed_templates())
    return system


def demo() -> None:
    system = build_demo_system()
    sample_form = {
        "strengths": "The teacher explains lessons clearly and keeps the lesson organized.",
        "areas_for_improvement": "The teacher explains lessons clearly but students rarely participate.",
        "recommendations": "Students rarely participate and need more opportunities to engage in the lesson.",
    }
    results = system.retrieve_feedback_for_form(sample_form)
    for field_name, template in results.items():
        print(f"[{field_name}]")
        if template is None:
            print("No matching feedback found.\n")
            continue
        print(f"Matched template #{template.id} (similarity={template.similarity:.4f})")
        print(template.feedback_text)
        print()
    system.close()


if __name__ == "__main__":
    demo()
