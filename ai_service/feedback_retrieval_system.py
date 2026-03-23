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
import zlib

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

    def insert_template(self, field_name: str, evaluation_comment: str, feedback_text: str, embedding_vector: bytes) -> int:
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
                embedding_vector BLOB NOT NULL
            )
            """
        )
        self.connection.execute(
            "CREATE INDEX IF NOT EXISTS idx_feedback_field ON feedback_templates(field_name)"
        )
        self.connection.commit()

    def insert_template(self, field_name: str, evaluation_comment: str, feedback_text: str, embedding_vector: bytes) -> int:
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
                    `embedding_vector` LONGBLOB NOT NULL,
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

    def insert_template(self, field_name: str, evaluation_comment: str, feedback_text: str, embedding_vector: bytes, auto_commit: bool = True) -> int:
        with self.connection.cursor() as cur:
            cur.execute(
                f"""
                INSERT INTO `{self.table_name}` (field_name, evaluation_comment, feedback_text, embedding_vector)
                VALUES (%s, %s, %s, %s)
                """,
                (field_name, evaluation_comment, feedback_text, embedding_vector),
            )
            inserted_id = int(cur.lastrowid)
        if auto_commit:
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
    def serialize_embedding(vector: np.ndarray) -> bytes:
        packed = np.asarray(vector, dtype=np.float32).tobytes(order="C")
        return zlib.compress(packed, level=9)

    @staticmethod
    def deserialize_embedding(raw_value: Any) -> np.ndarray:
        if raw_value is None:
            return np.asarray([], dtype=np.float32)

        if isinstance(raw_value, memoryview):
            raw_value = raw_value.tobytes()

        if isinstance(raw_value, (bytes, bytearray)):
            try:
                unpacked = zlib.decompress(bytes(raw_value))
                return np.frombuffer(unpacked, dtype=np.float32)
            except Exception:
                try:
                    return np.asarray(json.loads(bytes(raw_value).decode("utf-8")), dtype=np.float32)
                except Exception:
                    return np.asarray([], dtype=np.float32)

        if isinstance(raw_value, str):
            return np.asarray(json.loads(raw_value), dtype=np.float32)

        return np.asarray(raw_value, dtype=np.float32)

    def add_feedback_template(
        self,
        field_name: str,
        evaluation_comment: str,
        feedback_text: str,
        auto_commit: bool = True,
    ) -> int:
        if field_name not in SUPPORTED_FIELDS:
            raise ValueError(f"Unsupported field_name '{field_name}'. Expected one of: {', '.join(SUPPORTED_FIELDS)}")

        embedding = self.encode_text(evaluation_comment)
        return self.backend.insert_template(
            field_name,
            evaluation_comment.strip(),
            feedback_text.strip(),
            self.serialize_embedding(embedding),
            auto_commit=auto_commit,
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
            copy["_embedding"] = stored_embedding
            scored_rows.append(copy)

        scored_rows.sort(key=lambda item: item.get("_score", -1.0), reverse=True)
        desired = max(1, int(top_k or 1))
        shortlist = scored_rows[: max(desired * 4, desired)]

        selected: List[Dict[str, Any]] = []
        while shortlist and len(selected) < desired:
            best_index = 0
            best_value = None
            for idx, candidate in enumerate(shortlist):
                relevance = float(candidate.get("_score", -1.0))
                diversity_penalty = 0.0
                if selected:
                    similarities_to_selected = [
                        self.cosine_similarity(candidate.get("_embedding"), picked.get("_embedding"))
                        for picked in selected
                        if candidate.get("_embedding") is not None and picked.get("_embedding") is not None
                    ]
                    if similarities_to_selected:
                        diversity_penalty = max(similarities_to_selected) * 0.35

                # Penalize same indicator appearing too often
                indicator_penalty = 0.0
                candidate_indicator = (candidate.get('evaluation_comment') or '').strip().lower()
                if selected and candidate_indicator:
                    indicator_count = sum(1 for p in selected if (p.get('evaluation_comment') or '').strip().lower() == candidate_indicator)
                    if indicator_count >= 3:
                        indicator_penalty = 0.15
                    elif indicator_count >= 2:
                        indicator_penalty = 0.08

                lexical_penalty = 0.0
                candidate_text = f"{candidate.get('evaluation_comment') or ''} {candidate.get('feedback_text') or ''}".strip().lower()
                if selected and candidate_text:
                    selected_texts = {
                        f"{picked.get('evaluation_comment') or ''} {picked.get('feedback_text') or ''}".strip().lower()
                        for picked in selected
                    }
                    if candidate_text in selected_texts:
                        lexical_penalty = 0.2

                mmr_value = relevance - diversity_penalty - lexical_penalty - indicator_penalty
                if best_value is None or mmr_value > best_value:
                    best_value = mmr_value
                    best_index = idx

            selected.append(shortlist.pop(best_index))

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

    def retrieve_feedback_for_form(self, evaluation_inputs: Dict[str, str], top_k: int = 1) -> Dict[str, Optional[FeedbackTemplate]]:
        results: Dict[str, Optional[FeedbackTemplate]] = {}
        for field_name in SUPPORTED_FIELDS:
            query = (evaluation_inputs.get(field_name) or "").strip()
            if not query:
                results[field_name] = None
                continue
            matches = self.retrieve_top_feedback(field_name, query, top_k=max(1, int(top_k or 1)))
            results[field_name] = matches[0] if matches else None
        return results

    def retrieve_top_feedback_for_form(self, evaluation_inputs: Dict[str, str], top_k: int = 5) -> Dict[str, List[FeedbackTemplate]]:
        results: Dict[str, List[FeedbackTemplate]] = {}
        for field_name in SUPPORTED_FIELDS:
            query = (evaluation_inputs.get(field_name) or "").strip()
            if not query:
                results[field_name] = []
                continue
            results[field_name] = self.retrieve_top_feedback(field_name, query, top_k=max(1, int(top_k or 1)))
        return results

    def seed_feedback_templates(self, templates: Iterable[Dict[str, str]]) -> None:
        count = 0
        for template in templates:
            self.add_feedback_template(
                field_name=template["field_name"],
                evaluation_comment=template["evaluation_comment"],
                feedback_text=template["feedback_text"],
                auto_commit=False,
            )
            count += 1
            # Batch commit every 50 rows
            if count % 50 == 0:
                self.backend.connection.commit()
        # Final commit
        self.backend.connection.commit()

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
        ("excellent", "high-performing", "highly consistent", "The lesson demonstrates strong evidence of effective practice", "Performance is already strong and should now be sustained and deepened."),
        ("very satisfactory", "well-developed", "consistently visible", "The lesson demonstrates dependable evidence of effective teaching practice", "Performance is solid, with targeted refinements needed to reach an excellent level."),
        ("satisfactory", "developing", "partially established", "The lesson demonstrates acceptable but still developing evidence of effective practice", "Performance is workable but still needs more consistent classroom evidence."),
        ("below satisfactory", "priority support", "not yet consistent", "The lesson reflects limited evidence in this area and needs focused improvement support", "Performance needs stronger routines, follow-through, and targeted support."),
        ("needs improvement", "intensive support", "rarely visible", "The lesson reflects minimal evidence in this area and needs immediate instructional support", "Performance should focus first on foundational routines and measurable classroom improvement."),
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
        "Filipino lesson",
        "Physical education class",
        "Technology class",
        "Values education lesson",
    ]
    observation_types = ["formal lesson review", "informal lesson review", "classroom walk-through", "scheduled class visit"]

    strengths_focuses = [
        # Communications Competence domain (first so they appear even with small per_field)
        ("voice clarity and audibility", "uses an audible voice that can be heard clearly by all learners throughout the room", "uses a clear and audible voice throughout the lesson, ensuring that instructions and explanations are heard by all learners including those at the back of the room"),
        ("language fluency in instruction", "speaks fluently in the language of instruction with smooth and natural delivery", "speaks fluently and naturally in the language of instruction, maintaining a smooth delivery that helps learners follow explanations without unnecessary pauses"),
        ("dynamic discussion facilitation", "facilitates a dynamic and engaging discussion that encourages two-way interaction", "facilitates dynamic discussion by encouraging two-way interaction where learners actively respond, build on ideas, and engage in meaningful verbal exchange"),
        ("effective non-verbal communication", "uses engaging non-verbal cues including facial expressions and gestures to reinforce instruction", "uses purposeful non-verbal communication including facial expressions, gestures, and body movement to reinforce verbal messages and maintain learner attention"),
        ("appropriate vocabulary use", "uses words and expressions suited to the developmental level and comprehension of the students", "uses vocabulary and expressions that are well-suited to the learners' developmental level, making explanations accessible and easy to understand for all students"),
        # Management and general domains
        ("instructional clarity", "explains concepts clearly and connects examples to lesson goals", "demonstrates strong instructional clarity by presenting key concepts through clear explanations and relevant examples"),
        ("classroom management", "maintains orderly routines and respectful learner behavior throughout the class", "maintains a productive classroom climate through consistent routines and respectful management practices"),
        ("learner engagement", "encourages active participation and keeps learners focused during activities", "creates engaging learning opportunities that keep students attentive and involved in lesson tasks"),
        ("assessment practices", "checks for understanding during instruction and responds to learner needs", "uses formative assessment purposefully to monitor understanding and adjust instruction when needed"),
        ("lesson organization", "presents the lesson in a structured sequence with smooth transitions", "delivers a well-organized lesson sequence that supports learner progress from one task to the next"),
        ("learner support", "adapts explanations and guidance when learners need support", "responds to learner needs with appropriate guidance and instructional adjustments"),
        ("questioning technique", "uses questions that encourage thinking and learner participation", "uses purposeful questioning to keep learners thinking and participating throughout the lesson"),
        ("learning climate", "creates a respectful atmosphere where learners remain focused and cooperative", "builds a positive learning climate that supports concentration, respect, and classroom participation"),
        ("content mastery", "demonstrates command of the lesson content and answers learner questions with confidence", "demonstrates strong content mastery by explaining ideas accurately and responding confidently to learner questions"),
        ("instructional delivery", "presents lesson steps in a logical sequence and keeps tasks aligned to objectives", "delivers instruction in a logical, objective-aligned sequence that helps learners follow the lesson clearly"),
        ("student support", "monitors struggling learners and provides timely support during tasks", "supports learner progress by monitoring needs closely and offering timely guidance during classroom tasks"),
        ("use of resources", "uses instructional materials that help learners understand the lesson", "uses classroom resources and instructional materials effectively to support learner understanding"),
        ("task alignment", "aligns activities directly to the stated lesson objectives", "aligns lesson activities directly to stated objectives so every task contributes to measurable learning outcomes"),
        ("verbal communication", "speaks clearly and uses language appropriate to the learners' level", "communicates instructions and explanations clearly using language suited to the learners' developmental level"),
        ("time management", "allocates time effectively across lesson segments and activities", "manages instructional time effectively, ensuring each lesson segment receives adequate attention and focus"),
        ("motivational strategies", "uses encouragement and positive reinforcement to keep learners motivated", "employs motivational strategies such as praise, encouragement, and recognition to sustain learner effort"),
        ("collaborative learning", "facilitates meaningful peer interaction and group work during lessons", "facilitates productive collaborative learning by structuring group tasks that promote peer interaction and shared understanding"),
        ("board and visual presentation", "uses board work and visual aids clearly to reinforce lesson content", "uses board work and visual presentations effectively to reinforce key concepts and support learner comprehension"),
    ]
    strengths_modifiers = [
        ("during whole-class discussion", "throughout the lesson period"),
        ("during guided practice", "across the lesson sequence"),
        ("while facilitating learner tasks", "in ways that supported steady learner participation"),
        ("during transitions between activities", "while sustaining a focused classroom atmosphere"),
        ("while presenting examples and directions", "with consistent attention to learner understanding"),
        ("during collaborative learning tasks", "while encouraging responsible learner participation"),
        ("during recitation and questioning", "while keeping the lesson structured and responsive"),
        ("throughout the class period", "while maintaining clear instructional flow"),
        ("during independent practice activities", "while ensuring learners remained on task and focused"),
        ("while checking learner work", "with purposeful attention to individual learner progress"),
        ("during the lesson introduction", "while setting clear expectations for the learning tasks"),
        ("during group work and peer activities", "while fostering a collaborative and supportive learning space"),
    ]

    improvement_focuses = [
        # Communications Competence domain (first so they appear even with small per_field)
        ("voice projection", "the teacher's voice is not consistently audible to all learners especially those seated at the back of the room", "voice projection could be strengthened so all learners, including those at the back, can hear instructions and explanations clearly throughout the lesson"),
        ("language fluency", "the teacher occasionally hesitates or uses filler words that interrupt the flow of instruction in the language of instruction", "fluency in the language of instruction could be improved so explanations flow more naturally and learners can follow the lesson without unnecessary pauses"),
        ("discussion facilitation", "discussions tend to be one-directional with limited dynamic exchange between the teacher and learners", "discussion facilitation could become more dynamic by encouraging two-way exchanges and inviting learners to build on each other's responses during dynamic discussion"),
        ("non-verbal communication", "the teacher's use of non-verbal cues such as facial expressions and gestures is limited and does not reinforce verbal instructions", "non-verbal communication such as purposeful gestures and facial expressions could be used more deliberately to reinforce verbal messages and maintain learner attention"),
        ("vocabulary appropriateness", "some words and expressions used during instruction are above the comprehension level of the learners", "the vocabulary and expressions used during instruction could be better suited to the learners' developmental level so all students can follow the discussion clearly"),
        ("speech clarity", "the teacher speaks too quickly at times making it difficult for learners to process instructions and explanations", "speech pacing and clarity could be improved so learners have enough time to process verbal instructions and follow the lesson comfortably"),
        # Management and general domains
        ("student participation", "student participation remains limited and only a few learners respond during questioning", "student participation remains uneven and would benefit from broader engagement strategies during class interaction"),
        ("formative assessment", "checks for understanding are not yet frequent enough to confirm learner mastery", "formative assessment routines could be used more consistently to verify learner understanding before moving forward"),
        ("lesson pacing", "lesson pacing slows during task transitions and affects instructional momentum", "lesson pacing during transitions could be strengthened to maintain momentum and maximize instructional time"),
        ("questioning techniques", "questions are often directed to the same learners and limit wider participation", "questioning strategies could be expanded to involve a wider range of learners during recitation and discussion"),
        ("feedback follow-through", "feedback is provided but is not always followed by opportunities for learners to respond or improve", "feedback follow-through could be strengthened by giving learners time to act on corrections and refine their responses"),
        ("instructional differentiation", "activities are delivered in one way and do not yet address varied learner needs", "instructional differentiation could be strengthened so activities better address varied learner readiness and participation levels"),
        ("classroom routines", "classroom routines are not yet applied consistently during shifts in lesson activities", "classroom routines could be strengthened to improve consistency during transitions and independent work"),
        ("learner accountability", "learners are not always held accountable for completing and responding to tasks", "learner accountability routines could be reinforced so more students stay engaged and complete lesson tasks"),
        ("lesson objectives", "lesson objectives are not always revisited clearly during the class", "lesson objectives could be emphasized more consistently so learners remain aware of the target outcomes throughout the class"),
        ("feedback quality", "feedback is present but not always specific enough to guide learner improvement", "feedback quality could be improved by giving learners more specific guidance linked to lesson expectations"),
        ("engagement strategies", "activities do not always sustain learner attention across the full lesson", "engagement strategies could be strengthened so learner attention remains more consistent from beginning to end of the lesson"),
        ("monitoring of learning", "learner understanding is not always monitored before the lesson progresses", "monitoring of learning could be more visible so instructional decisions are clearly guided by learner responses"),
        ("use of higher-order questions", "most questions asked require only recall rather than critical thinking from learners", "higher-order questioning could be integrated more frequently to challenge learners beyond simple recall"),
        ("clarity of directions", "directions for activities are sometimes unclear, causing confusion among learners", "directions for learning tasks could be communicated more clearly so learners understand exactly what is expected before beginning work"),
        ("time allocation", "some lesson segments receive too much time while others feel rushed", "time allocation across lesson segments could be balanced more carefully to give adequate attention to each instructional phase"),
        ("student collaboration", "group tasks lack clear structure and some learners remain passive", "collaborative learning tasks could be structured more deliberately so each learner has a clear and active role to fulfill"),
        ("closure and summary", "the lesson ends without a clear summary or consolidation of key concepts", "lesson closure could be strengthened by including a brief summary or review activity that reinforces the key takeaways"),
        ("connection to prior learning", "new content is introduced without linking it to what learners already know", "instructional planning could include explicit connections between new content and previously learned material to deepen understanding"),
    ]
    improvement_modifiers = [
        ("during independent work", "before the lesson moves to the next task"),
        ("during class discussion", "to make the improvement more visible in future lessons"),
        ("during group activities", "so learner engagement remains consistent across the class"),
        ("during assessment segments", "to support stronger evidence of learner understanding"),
        ("throughout the lesson period", "while keeping instruction focused and efficient"),
        ("during transitions between lesson parts", "so instructional flow remains steady and purposeful"),
        ("during recitation and questioning", "to broaden learner participation and response quality"),
        ("during follow-up activities", "so feedback leads to clearer learner improvement"),
        ("during the lesson introduction", "so learning expectations are clear from the start"),
        ("during practice exercises", "to confirm that learners can apply what was taught"),
        ("during learner presentations", "to encourage deeper thinking and accountability"),
        ("during the closing segment", "so key learning points are reinforced before the class ends"),
    ]

    recommendation_focuses = [
        # Communications Competence domain (first so they appear even with small per_field)
        ("voice projection", "practice consistent voice projection techniques so all learners hear instructions clearly from any seat in the room", "Practice deliberate voice projection and vary vocal volume so instructions and explanations reach all learners clearly, including those seated at the back of the room."),
        ("language fluency", "rehearse lesson explanations beforehand to improve fluency and reduce filler words in the language of instruction", "Rehearse lesson explanations and key vocabulary before class to improve fluency in the language of instruction, reducing pauses and filler words that interrupt lesson flow."),
        ("discussion dynamism", "plan open-ended prompts and follow-up questions that encourage dynamic two-way discussion", "Plan open-ended discussion prompts and follow-up questions that encourage dynamic discussion where learners respond to each other and build on shared ideas."),
        ("non-verbal cues", "use purposeful gestures and facial expressions to reinforce verbal messages and maintain learner attention", "Use purposeful gestures, facial expressions, and movement to reinforce verbal instructions and sustain learner attention throughout the discussion."),
        ("vocabulary adjustment", "simplify vocabulary and rephrase complex terms to match the developmental level of the learners", "Adjust vocabulary and rephrase complex terms using simpler language suited to the learners' developmental level so all students can follow the lesson clearly."),
        ("speech pacing", "slow down speech during key explanations and pause briefly to let learners process information", "Slow down speech during key explanations and insert brief pauses so learners have time to process verbal instructions before the lesson moves forward."),
        # Management and general domains
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
        ("higher-order thinking", "integrate questions and tasks that require analysis, comparison, or application beyond simple recall", "Incorporate higher-order questions and tasks that require learners to analyze, compare, or apply knowledge rather than rely solely on recall."),
        ("lesson closure", "end each lesson with a brief review or exit activity that consolidates key learning points", "End each lesson with a structured closure activity such as a summary question or exit ticket to consolidate and reinforce key learning points."),
        ("student collaboration", "design group tasks with clear roles and shared accountability for each participant", "Design collaborative tasks with clearly defined roles and shared accountability so every learner contributes meaningfully to group work."),
        ("scaffolding", "break complex tasks into smaller steps and provide guided support before independent practice", "Break complex tasks into manageable steps and provide guided support before releasing learners to independent practice."),
        ("real-world connections", "connect lesson content to practical examples and real-life situations familiar to learners", "Connect lesson content to real-world examples and practical situations so learners can see the relevance and applicability of what they are learning."),
        ("board organization", "keep board work organized and legible to reinforce key information throughout the lesson", "Maintain organized and legible board work throughout the lesson so key information remains visible and accessible to all learners."),
    ]
    recommendation_modifiers = [
        "This can make the targeted improvement more visible in the next lesson.",
        "This should support stronger learner response and clearer classroom evidence.",
        "This can help sustain instructional momentum while improving learner participation.",
        "This may strengthen both lesson delivery and learner understanding during class activities.",
        "This can support more consistent evidence of effective teaching practice.",
        "This can help the teacher move from developing to more consistent performance.",
        "This may help turn identified weaknesses into clearer strengths during the next cycle.",
        "This can improve the visibility of effective teaching decisions in future lessons.",
        "Applying this consistently can foster a more responsive and learner-centered classroom.",
        "This practical adjustment is achievable within the current lesson structure.",
        "When practiced regularly, this can become a reliable part of the instructional routine.",
        "This can create a stronger foundation for sustained classroom improvement.",
    ]

    reflection_phrases = [
        "As reflected in the lesson,",
        "Throughout the lesson,",
        "Based on lesson evidence,",
        "From the evidence gathered during instruction,",
        "As indicated by the performance indicators,",
        "Across the lesson segments,",
        "Looking at the overall lesson delivery,",
        "Drawing from the classroom evidence,",
        "Considering the full scope of the lesson,",
        "With attention to instructional practice,",
    ]

    human_closers = [
        "This was reflected in the delivery and monitoring of the lesson.",
        "This contributed to a clearer sense of direction and active student participation.",
        "This made the teaching practice more apparent throughout the lesson.",
        "This supported a more organized and student-centered lesson flow.",
        "This provided stronger evidence of intentional and responsive instructional practice.",
        "This demonstrated a clearer connection between instruction, engagement, and follow-through.",
        "This added depth and coherence to the overall learning experience.",
        "This reinforced a purposeful and well-structured approach to classroom instruction.",
        "This helped create a more cohesive and learner-focused class session.",
        "This strengthened the alignment between planned activities and actual learner engagement.",
    ]

    sentence_openers = [
        "A key aspect of the lesson was that",
        "Based on lesson evidence,",
        "The lesson clearly reflected that",
        "Upon review of the lesson,",
        "Throughout the class,",
        "A notable element of the lesson was that",
        "An important feature of the class was that",
        "From the perspective of instructional delivery,",
        "It became apparent during the lesson that",
        "A significant part of the lesson demonstrated that",
    ]

    natural_bridges = [
        "In practical terms,",
        "Throughout the lesson,",
        "As the lesson progressed,",
        "Furthermore,",
        "In a manner evident to learners,",
        "Within the context of the lesson,",
        "Building on this,",
        "At multiple points during the class,",
        "From a broader instructional standpoint,",
        "Taken together with other lesson elements,",
    ]

    humanized_strength_addons = [
        "Students appeared engaged and responsive, suggesting that the teaching approach was well-received.",
        "The classroom atmosphere reflected a well-managed environment conducive to learning.",
        "This contributed to a structured and productive learning experience for the students.",
        "The consistency of the teacher helped maintain focus and facilitated smooth lesson delivery.",
        "There was clear evidence that established routines supported student attention and participation.",
        "The deliberate nature of the instructional practice strengthened the overall quality of the lesson.",
        "Learners were visibly focused, which suggests the instructional strategies were effective and well-timed.",
        "The purposeful structure of activities helped learners stay engaged from start to finish.",
        "The classroom dynamic reflected a positive learning culture built on clear expectations and routines.",
        "This practice contributed meaningfully to the overall coherence and professionalism of the lesson.",
    ]

    humanized_improvement_addons = [
        "This is an achievable goal since the practice is already partially present and can be developed with consistent effort.",
        "While not a critical concern, it is noticeable enough to warrant focused attention in daily instruction.",
        "A small but consistent adjustment in this area could yield visible improvement in student response.",
        "This area has potential and is close to becoming a reliable strength with sustained practice.",
        "Improvement in this area would likely be evident in subsequent lessons with deliberate reinforcement.",
        "The focus should be on making this practice more consistent rather than introducing entirely new strategies.",
        "With minor adjustments, this practice can transition from developing to proficient in upcoming lessons.",
        "Addressing this area directly will likely lead to noticeable progress in learner engagement.",
        "This is a practical and manageable area for professional growth that can yield quick results.",
        "Sustained attention to this aspect of instruction can make a meaningful difference in lesson effectiveness.",
    ]

    humanized_recommendation_addons = [
        "Small, consistently applied routines tend to produce more lasting improvement than large one-time changes.",
        "This step can realistically be incorporated into the existing lesson structure without major disruption.",
        "It may be helpful to implement one strategy at a time, refining it based on student response.",
        "This recommendation is most effective when paired with regular monitoring and timely follow-up.",
        "The aim is to develop this into a habitual teaching practice through regular and intentional application.",
        "When applied consistently, this practical step can produce measurable improvement in classroom outcomes.",
        "Starting with one targeted adjustment and building from there is often the most sustainable approach.",
        "This can serve as a concrete action step that the teacher can begin applying in the very next lesson.",
        "Pairing this recommendation with peer collaboration or mentoring can accelerate professional growth.",
        "This focused change can have a ripple effect on several related aspects of the teaching process.",
    ]

    improvement_closers = [
        "Addressing this consistently can lead to noticeable improvement in upcoming lessons.",
        "With focused effort, this area has strong potential for meaningful growth.",
        "Consistent attention to this aspect will contribute to a more well-rounded instructional practice.",
        "This represents a tangible opportunity for professional development in future lessons.",
        "Strengthening this area can positively impact both lesson flow and learner outcomes.",
        "Gradual and deliberate improvement in this area will support a stronger instructional foundation.",
        "Targeted effort here can bridge the gap between current practice and the expected standard.",
        "Making this a priority can lead to measurable progress in overall lesson quality.",
        "With sustained attention, this area can develop into a more reliable aspect of instruction.",
        "Focusing on this area will help create more balanced and effective lesson delivery.",
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
        sentence_opener = sentence_openers[index % len(sentence_openers)]
        bridge = natural_bridges[(index // len(sentence_openers)) % len(natural_bridges)]
        addon = humanized_strength_addons[(index // len(natural_bridges)) % len(humanized_strength_addons)]
        subject = subjects[index % len(subjects)]
        observation = observation_types[(index // len(subjects)) % len(observation_types)]
        add(
            "strengths",
            f"{opener} in the {subject}, the teacher demonstrates {band[1]} evidence of {focus[0]} and {focus[1]} {modifier[0]} during a {observation}",
            f"{sentence_opener} the teacher {focus[2]}. {addon} {closer}",
        )

    for index in range(per_field):
        band = rating_bands[index % len(rating_bands)]
        focus = improvement_focuses[(index // len(rating_bands)) % len(improvement_focuses)]
        modifier = improvement_modifiers[(index // (len(rating_bands) * len(improvement_focuses))) % len(improvement_modifiers)]
        opener = reflection_phrases[index % len(reflection_phrases)]
        closer = human_closers[(index // len(reflection_phrases)) % len(human_closers)]
        sentence_opener = sentence_openers[index % len(sentence_openers)]
        bridge = natural_bridges[(index // len(sentence_openers)) % len(natural_bridges)]
        addon = humanized_improvement_addons[(index // len(natural_bridges)) % len(humanized_improvement_addons)]
        subject = subjects[index % len(subjects)]
        observation = observation_types[(index // len(subjects)) % len(observation_types)]
        improvement_closer = improvement_closers[(index // len(reflection_phrases)) % len(improvement_closers)]
        add(
            "areas_for_improvement",
            f"{opener} in the {subject}, {focus[1]} {modifier[0]} during a {observation} and reflects a {band[0]} performance concern in this criterion",
            f"{sentence_opener} {focus[2]}. {addon} {improvement_closer}",
        )

    for index in range(per_field):
        band = rating_bands[index % len(rating_bands)]
        focus = recommendation_focuses[(index // len(rating_bands)) % len(recommendation_focuses)]
        closer = recommendation_modifiers[(index // (len(rating_bands) * len(recommendation_focuses))) % len(recommendation_modifiers)]
        opener = reflection_phrases[index % len(reflection_phrases)]
        sentence_opener = sentence_openers[index % len(sentence_openers)]
        bridge = natural_bridges[(index // len(sentence_openers)) % len(natural_bridges)]
        addon = humanized_recommendation_addons[(index // len(natural_bridges)) % len(humanized_recommendation_addons)]
        subject = subjects[index % len(subjects)]
        observation = observation_types[(index // len(subjects)) % len(observation_types)]
        add(
            "recommendations",
            f"{opener} in the {subject}, the teacher needs support in {focus[0]} and should {focus[1]} to address a {band[0]} classroom performance pattern noted in the {observation}",
            f"{focus[2]} {addon} {closer}",
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
