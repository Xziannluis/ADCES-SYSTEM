from __future__ import annotations

import argparse
import json
import random
from pathlib import Path
from typing import Dict, List

from feedback_retrieval_system import SUPPORTED_FIELDS, generate_seed_templates

OUTPUT_DIR = Path(__file__).resolve().parent
AI_FEEDBACK_PATH = OUTPUT_DIR / "ai_feedback.synthetic.jsonl"
REFERENCE_EVALS_PATH = OUTPUT_DIR / "reference_evaluations.synthetic.jsonl"

STYLE_VARIANTS = [
    "standard",
    "supportive",
    "reflective",
    "instructional",
    "concise",
]

FACULTY_FIRST_NAMES = [
    "Maria", "Jose", "Ana", "John", "Grace", "Mark", "Liza", "Ramon", "Claire", "Paulo",
    "Jessa", "Carlo", "Miriam", "Noel", "Sheila", "Aileen", "Bryan", "Therese", "Janine", "Leo",
]
FACULTY_LAST_NAMES = [
    "Dela Cruz", "Santos", "Reyes", "Garcia", "Flores", "Mendoza", "Torres", "Navarro", "Villanueva", "Castro",
    "Aquino", "Soriano", "Fernandez", "Bautista", "Lim", "De Leon", "Pascual", "Ramos", "Lopez", "Diaz",
]
DEPARTMENTS = ["CCIS", "CTE", "CBA", "CAS", "COA", "CHM", "CCJE", "Senior High"]
SUBJECTS = [
    "Database Management Systems",
    "Business Mathematics",
    "General Biology",
    "Oral Communication",
    "Programming 2",
    "Principles of Marketing",
    "Earth Science",
    "Reading and Writing",
    "Research Methods",
    "Contemporary World",
    "Physical Science",
    "Accounting Fundamentals",
]
OBSERVATION_TYPES = ["Formal", "Informal", "Classroom Walk-through"]

EXTRA_SENTENCES = {
    "strengths": [
        "The teacher kept the pace steady and made expectations easy for learners to follow.",
        "Observed routines helped maintain a respectful class climate from beginning to end.",
        "Learners responded well because directions were presented clearly and reinforced at the right time.",
        "The class atmosphere supported participation, focus, and productive learning behaviors.",
        "Instruction stayed anchored to the lesson goal, which helped the class remain organized.",
        "The teacher showed confidence in the content and used examples that matched learner needs.",
        "Transitions were handled calmly, allowing the lesson to move forward without unnecessary delay.",
        "Classroom evidence suggests that the teacher consistently used practices that supported engagement and order.",
    ],
    "areas_for_improvement": [
        "A more visible follow-through routine would make the improvement easier to observe in future visits.",
        "This area would benefit from one clear strategy that is applied consistently across the lesson.",
        "A small adjustment in teacher follow-up could improve both participation and learner confidence.",
        "The concern is not major, but it appears often enough to deserve focused attention.",
        "Stronger consistency in this area could help the lesson feel more responsive and complete.",
        "This pattern suggests the practice is emerging, but it still needs clearer and more regular execution.",
        "A more intentional use of feedback and checking routines could strengthen classroom evidence here.",
        "The next observation would likely show improvement if this target were practiced deliberately each day.",
    ],
    "recommendations": [
        "A short, repeatable routine will make the adjustment easier to sustain from one lesson to the next.",
        "This can be implemented without changing the whole lesson structure, which makes it practical for daily use.",
        "A focused classroom routine is likely to produce clearer evidence than several strategies used inconsistently.",
        "It would help to monitor how learners respond so the teacher can refine the strategy over time.",
        "The recommendation is most effective when paired with visible teacher follow-up and brief reflection.",
        "Using the same support routine across several lessons can help turn it into a dependable practice.",
        "A manageable next step is often more sustainable than a large change introduced all at once.",
        "This approach should help the teacher show clearer improvement in a realistic and measurable way.",
    ],
}

COMMENT_PREFIXES = [
    "Observed evidence shows",
    "Classroom evidence indicates",
    "The observation notes that",
    "During the lesson, it was clear that",
    "The walkthrough showed that",
    "The evaluation suggests that",
]


def chunked_write_jsonl(path: Path, rows: List[Dict[str, object]]) -> None:
    with path.open("w", encoding="utf-8") as handle:
        for row in rows:
            handle.write(json.dumps(row, ensure_ascii=False) + "\n")


def vary_text(base_text: str, field_name: str, rng: random.Random, index: int) -> str:
    snippets = EXTRA_SENTENCES[field_name]
    prefix = COMMENT_PREFIXES[index % len(COMMENT_PREFIXES)]
    extra_a = snippets[index % len(snippets)]
    extra_b = snippets[(index * 3 + 2) % len(snippets)]

    text = base_text.strip().rstrip(".") + "."

    variants = [
        f"{text} {extra_a}",
        f"{prefix} {text[0].lower() + text[1:]} {extra_a}",
        f"{text} {extra_a} {extra_b}",
        f"{prefix} {text[0].lower() + text[1:]} {extra_b}",
    ]
    selected = variants[index % len(variants)]
    return " ".join(selected.split())


def build_reference_row(template_bundle: Dict[str, Dict[str, str]], rng: random.Random, index: int) -> Dict[str, object]:
    faculty_name = f"{rng.choice(FACULTY_FIRST_NAMES)} {rng.choice(FACULTY_LAST_NAMES)}"
    comm = round(rng.uniform(3.2, 5.0), 2)
    mgmt = round(rng.uniform(3.0, 5.0), 2)
    assess = round(rng.uniform(3.0, 5.0), 2)
    overall = round((comm + mgmt + assess) / 3, 2)

    strengths_comment = template_bundle["strengths"]["evaluation_comment"]
    improvement_comment = template_bundle["areas_for_improvement"]["evaluation_comment"]
    recommendation_text = template_bundle["recommendations"]["feedback_text"]

    return {
        "faculty_name": faculty_name,
        "department": rng.choice(DEPARTMENTS),
        "subject_observed": rng.choice(SUBJECTS),
        "observation_type": rng.choice(OBSERVATION_TYPES),
        "averages": {
            "communications": comm,
            "management": mgmt,
            "assessment": assess,
            "overall": overall,
        },
        "ratings": {
            "communications": [
                {"rating": int(round(comm)), "comment": strengths_comment},
            ],
            "management": [
                {"rating": int(round(mgmt)), "comment": "Routines and classroom management were observed during the lesson."},
            ],
            "assessment": [
                {"rating": int(round(assess)), "comment": improvement_comment},
            ],
        },
        "strengths": template_bundle["strengths"]["feedback_text"],
        "improvement_areas": template_bundle["areas_for_improvement"]["feedback_text"],
        "recommendations": recommendation_text,
        "source": "synthetic-expanded",
        "source_evaluation_id": index + 1,
        "style": rng.choice(STYLE_VARIANTS),
    }


def generate_ai_feedback_rows(total_rows: int, seed: int) -> List[Dict[str, object]]:
    rng = random.Random(seed)
    per_field = max(1, (total_rows // len(SUPPORTED_FIELDS)) + 5)
    templates = generate_seed_templates(per_field=per_field)

    grouped: Dict[str, List[Dict[str, str]]] = {field: [] for field in SUPPORTED_FIELDS}
    for row in templates:
        grouped[row["field_name"]].append(row)

    rows: List[Dict[str, object]] = []
    for index in range(total_rows):
        style = rng.choice(STYLE_VARIANTS)
        strengths_template = grouped["strengths"][index % len(grouped["strengths"])]
        improve_template = grouped["areas_for_improvement"][(index * 2 + 3) % len(grouped["areas_for_improvement"])]
        recommend_template = grouped["recommendations"][(index * 3 + 5) % len(grouped["recommendations"])]

        generated = {
            "strengths": vary_text(strengths_template["feedback_text"], "strengths", rng, index),
            "improvement_areas": vary_text(improve_template["feedback_text"], "areas_for_improvement", rng, index + 7),
            "recommendations": vary_text(recommend_template["feedback_text"], "recommendations", rng, index + 13),
        }

        request = {
            "faculty_name": f"{rng.choice(FACULTY_FIRST_NAMES)} {rng.choice(FACULTY_LAST_NAMES)}",
            "department": rng.choice(DEPARTMENTS),
            "subject_observed": rng.choice(SUBJECTS),
            "observation_type": rng.choice(OBSERVATION_TYPES),
            "ratings": {
                "communications": [{"rating": rng.randint(3, 5), "comment": strengths_template["evaluation_comment"]}],
                "management": [{"rating": rng.randint(3, 5), "comment": "The class remained generally organized during observed activities."}],
                "assessment": [{"rating": rng.randint(2, 5), "comment": improve_template["evaluation_comment"]}],
            },
            "averages": {
                "communications": round(rng.uniform(3.0, 5.0), 2),
                "management": round(rng.uniform(3.0, 5.0), 2),
                "assessment": round(rng.uniform(2.8, 5.0), 2),
                "overall": round(rng.uniform(3.0, 5.0), 2),
            },
            "strengths": "",
            "improvement_areas": "",
            "recommendations": "",
            "style": style,
        }

        rows.append(
            {
                "ts": f"2026-03-10T{(index % 24):02d}:{(index % 60):02d}:{((index * 7) % 60):02d}Z",
                "request": request,
                "generated": generated,
                "accurate": True,
                "corrected": generated,
                "comment": "Synthetic expanded dataset entry generated for retrieval and style variety.",
            }
        )

    return rows


def generate_reference_rows(total_rows: int, seed: int) -> List[Dict[str, object]]:
    rng = random.Random(seed + 101)
    per_field = max(1, total_rows + 10)
    templates = generate_seed_templates(per_field=per_field)
    grouped: Dict[str, List[Dict[str, str]]] = {field: [] for field in SUPPORTED_FIELDS}
    for row in templates:
        grouped[row["field_name"]].append(row)

    rows: List[Dict[str, object]] = []
    for index in range(total_rows):
        template_bundle = {
            "strengths": grouped["strengths"][index % len(grouped["strengths"] )],
            "areas_for_improvement": grouped["areas_for_improvement"][(index * 2 + 1) % len(grouped["areas_for_improvement"])],
            "recommendations": grouped["recommendations"][(index * 3 + 2) % len(grouped["recommendations"])],
        }
        row = build_reference_row(template_bundle, rng, index)
        row["strengths"] = vary_text(str(row["strengths"]), "strengths", rng, index)
        row["improvement_areas"] = vary_text(str(row["improvement_areas"]), "areas_for_improvement", rng, index + 5)
        row["recommendations"] = vary_text(str(row["recommendations"]), "recommendations", rng, index + 9)
        rows.append(row)
    return rows


def main() -> None:
    parser = argparse.ArgumentParser(description="Generate large synthetic AI feedback and reference evaluation datasets.")
    parser.add_argument("--count", type=int, default=2500, help="Number of rows to generate per output dataset.")
    parser.add_argument("--seed", type=int, default=42, help="Random seed for reproducible output.")
    parser.add_argument("--feedback-out", type=Path, default=AI_FEEDBACK_PATH, help="Output JSONL file for synthetic AI feedback records.")
    parser.add_argument("--reference-out", type=Path, default=REFERENCE_EVALS_PATH, help="Output JSONL file for synthetic reference evaluation records.")
    args = parser.parse_args()

    if args.count < 1:
        raise SystemExit("--count must be at least 1")

    feedback_rows = generate_ai_feedback_rows(args.count, args.seed)
    reference_rows = generate_reference_rows(args.count, args.seed)

    chunked_write_jsonl(args.feedback_out, feedback_rows)
    chunked_write_jsonl(args.reference_out, reference_rows)

    print(f"Wrote {len(feedback_rows)} AI feedback rows to {args.feedback_out}")
    print(f"Wrote {len(reference_rows)} reference evaluation rows to {args.reference_out}")


if __name__ == "__main__":
    main()
