"""In-process smoke checks for the FastAPI app.

This avoids starting uvicorn and avoids downloading/loading the real SBERT model by
monkeypatching `_load_sbert` with a deterministic fake encoder.

Run: python smoke_test.py
"""

from fastapi.testclient import TestClient
import pathlib
import tempfile

import numpy as np

import app as ai_app

class _FakeSbert:
    def encode(self, texts, convert_to_numpy=True, normalize_embeddings=False):
        vectors = []
        for text in texts:
            lowered = (text or "").lower()
            vector = np.array(
                [
                    lowered.count("assessment") + lowered.count("feedback"),
                    lowered.count("question") + lowered.count("check"),
                    lowered.count("routine") + lowered.count("transition"),
                    lowered.count("clear") + lowered.count("explain"),
                    max(len(lowered.split()), 1),
                ],
                dtype=np.float32,
            )
            if normalize_embeddings:
                vector = vector / (np.linalg.norm(vector) + 1e-12)
            vectors.append(vector)
        arr = np.vstack(vectors)
        return arr if convert_to_numpy else arr.tolist()


def main() -> None:
    ai_app._load_sbert = lambda: _FakeSbert()  # type: ignore[attr-defined]

    class _FakeRetrievalMatch:
        def __init__(self, feedback_text: str, evaluation_comment: str = "", similarity: float = 0.9):
            self.feedback_text = feedback_text
            self.evaluation_comment = evaluation_comment or feedback_text
            self.similarity = similarity

    class _FakeRetrievalSystem:
        def retrieve_feedback_for_form(self, evaluation_inputs):
            return {
                "strengths": _FakeRetrievalMatch(
                    "The teacher used clear structured explanations and maintained a focused classroom atmosphere.",
                    "Clear explanations supported learner understanding.",
                ),
                "areas_for_improvement": _FakeRetrievalMatch(
                    "Formative checks can be made more visible during transitions between tasks and learner responses.",
                    "Checks for understanding should be more visible.",
                ),
                "recommendations": _FakeRetrievalMatch(
                    "Use brief checkpoints and targeted follow-up questions to confirm understanding before moving forward.",
                    "Use follow-up questions to confirm understanding.",
                ),
            }

        def retrieve_top_feedback_for_form(self, evaluation_inputs, top_k=5):
            return {
                "strengths": [
                    _FakeRetrievalMatch("The teacher used clear structured explanations and maintained a focused classroom atmosphere.", "Clear explanations supported learner understanding.", 0.97),
                    _FakeRetrievalMatch("Learners stayed engaged because directions were easy to follow and classroom flow remained steady.", "Directions were clear and pacing stayed steady.", 0.91),
                    _FakeRetrievalMatch("Instruction remained purposeful and the lesson sequence helped learners follow each part of the discussion.", "Lesson sequence supported learner understanding.", 0.88),
                ],
                "areas_for_improvement": [
                    _FakeRetrievalMatch("Formative checks can be made more visible during transitions between tasks and learner responses.", "Checks for understanding should be more visible.", 0.96),
                    _FakeRetrievalMatch("Feedback routines would be stronger if learners had more chances to respond before the lesson moved on.", "Feedback follow-through needs improvement.", 0.92),
                    _FakeRetrievalMatch("Learner understanding should be monitored more often before the activity shifts to the next task.", "Monitoring of understanding should be more consistent.", 0.89),
                ],
                "recommendations": [
                    _FakeRetrievalMatch("Use brief checkpoints and targeted follow-up questions to confirm understanding before moving forward.", "Use follow-up questions to confirm understanding.", 0.98),
                    _FakeRetrievalMatch("Provide specific feedback and allow learners enough time to revise answers during the lesson.", "Allow time for learners to revise after feedback.", 0.93),
                    _FakeRetrievalMatch("Pause after key explanations and use short oral or written checks before moving to the next activity.", "Pause after key explanations to check understanding.", 0.9),
                ],
            }

        def fetch_templates(self, field_name):
            templates = {
                "strengths": [
                    {"feedback_text": "The teacher used clear structured explanations and maintained a focused classroom atmosphere.", "evaluation_comment": "Clear explanations supported learner understanding.", "source": "mysql:strengths", "template_field": "strengths"},
                    {"feedback_text": "Learners stayed engaged because directions were easy to follow and classroom flow remained steady.", "evaluation_comment": "Directions were clear and pacing stayed steady.", "source": "mysql:strengths", "template_field": "strengths"},
                ],
                "areas_for_improvement": [
                    {"feedback_text": "Formative checks can be made more visible during transitions between tasks and learner responses.", "evaluation_comment": "Checks for understanding should be more visible.", "source": "mysql:areas_for_improvement", "template_field": "areas_for_improvement"},
                    {"feedback_text": "Feedback routines would be stronger if learners had more chances to respond before the lesson moved on.", "evaluation_comment": "Feedback follow-through needs improvement.", "source": "mysql:areas_for_improvement", "template_field": "areas_for_improvement"},
                ],
                "recommendations": [
                    {"feedback_text": "Use brief checkpoints and targeted follow-up questions to confirm understanding before moving forward.", "evaluation_comment": "Use follow-up questions to confirm understanding.", "source": "mysql:recommendations", "template_field": "recommendations"},
                    {"feedback_text": "Provide specific feedback and allow learners enough time to revise answers during the lesson.", "evaluation_comment": "Allow time for learners to revise after feedback.", "source": "mysql:recommendations", "template_field": "recommendations"},
                ],
            }
            return templates.get(field_name, [])

    ai_app._load_feedback_retrieval_system = lambda: _FakeRetrievalSystem()  # type: ignore[attr-defined]

    tmp = tempfile.TemporaryDirectory()
    ai_app.FEEDBACK_PATH = pathlib.Path(tmp.name) / "ai_feedback.jsonl"  # type: ignore[attr-defined]
    ai_app.EMBEDDINGS_CACHE_PATH = pathlib.Path(tmp.name) / "comment_embeddings_cache.npz"  # type: ignore[attr-defined]

    client = TestClient(ai_app.app)

    payload = {
        "faculty_name": "Test Teacher",
        "subject_observed": "Math",
        "observation_type": "Classroom observation",
        "ratings": {
            "assessment": [
                {"rating": 4, "comment": "Explains concepts clearly."},
                {"rating": 4, "comment": "Uses checks for understanding."},
            ]
        },
        "averages": {"communications": 4.0, "management": 4.2, "assessment": 3.8, "overall": 4.0},
        "style": "standard",
    }

    r = client.post("/generate", json=payload)
    assert r.status_code == 200, r.text
    data = r.json()

    for k in ("strengths", "improvement_areas", "recommendations"):
        assert isinstance(data.get(k), str) and data[k].strip(), (k, data)
        assert len({part.strip().lower() for part in data[k].split('.') if part.strip()}) >= 1, (k, data[k])

    debug = data.get("debug") or {}
    assert isinstance(debug.get("top_comments"), list) and len(debug["top_comments"]) >= 1, debug
    assert debug.get("generator") == "mysql-only-retrieval", debug
    assert all(str(source).startswith("mysql:") for source in (debug.get("mysql_sources") or {}).keys()), debug
    options = [
        *(data.get("strengths_options") or []),
        *(data.get("improvement_areas_options") or []),
        *(data.get("recommendations_options") or []),
    ]
    assert any("formative" in option.lower() or "checkpoint" in option.lower() or "feedback" in option.lower() for option in options), data
    assert data["recommendations"] != data["improvement_areas"], data

    print("OK: /generate returned 200 with required fields")
    tmp.cleanup()


if __name__ == "__main__":
    main()
