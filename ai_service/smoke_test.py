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

    tmp = tempfile.TemporaryDirectory()
    ai_app.REFERENCE_EVALS_PATH = pathlib.Path(tmp.name) / "reference_evaluations.jsonl"  # type: ignore[attr-defined]
    ai_app.IMPORTED_REFERENCE_EVALS_PATH = pathlib.Path(tmp.name) / "reference_evaluations.imported.jsonl"  # type: ignore[attr-defined]
    ai_app.FEEDBACK_PATH = pathlib.Path(tmp.name) / "ai_feedback.jsonl"  # type: ignore[attr-defined]
    ai_app.EMBEDDINGS_CACHE_PATH = pathlib.Path(tmp.name) / "comment_embeddings_cache.npz"  # type: ignore[attr-defined]
    ai_app.REFERENCE_EVALS_PATH.write_text(
        '{"faculty_name":"Reference Teacher","subject_observed":"Math","observation_type":"Classroom observation","averages":{"communications":4.0,"management":3.7,"assessment":3.1,"overall":3.6},"ratings":{"assessment":[{"rating":3,"comment":"Formative checks can be made more frequent during the lesson."}]},"strengths":"The teacher maintained a focused class atmosphere and explained content clearly.","improvement_areas":"Assessment checks can be made more frequent to capture learner understanding.","recommendations":"Use short formative checks and timely feedback during each lesson segment.","source":"smoke-test"}\n',
        encoding="utf-8",
    )
    ai_app.IMPORTED_REFERENCE_EVALS_PATH.write_text(
        '{"faculty_name":"Imported Teacher","subject_observed":"Math","observation_type":"Classroom observation","averages":{"communications":4.1,"management":4.0,"assessment":3.9,"overall":4.0},"ratings":{"assessment":[{"rating":4,"comment":"Use brief checkpoints and targeted follow-up questions to confirm understanding before moving forward."}]},"strengths":"The teacher used clear structured explanations and kept learners engaged throughout the lesson.","improvement_areas":"Formative checks can be made more visible during transitions between tasks.","recommendations":"Use brief checkpoints and targeted follow-up questions to confirm understanding before moving forward.","source":"live-submit"}\n',
        encoding="utf-8",
    )

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

    debug = data.get("debug") or {}
    assert isinstance(debug.get("top_comments"), list) and len(debug["top_comments"]) >= 1, debug
    assert debug.get("generator") == "retrieval-only", debug
    assert "interactive activities" in data["improvement_areas"].lower() or "interactive activities" in data["recommendations"].lower(), data

    print("OK: /generate returned 200 with required fields")
    tmp.cleanup()


if __name__ == "__main__":
    main()
