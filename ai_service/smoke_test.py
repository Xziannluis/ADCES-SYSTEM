"""In-process smoke checks for the FastAPI app.

This avoids starting uvicorn and avoids downloading/loading heavy ML models by
monkeypatching `_load_models` and `_generate_text`.

Run: python smoke_test.py
"""

from fastapi.testclient import TestClient

import app as ai_app


def _fake_load_models():
    # Return placeholders; we won't use them because _generate_text is patched.
    return object(), object(), object()


def _fake_generate_text(tok, model, prompt: str, style: str = "standard") -> str:
    # Return the expected labeled format.
    return (
        "STRENGTHS: The teacher demonstrates effective instructional communication and maintains a constructive learning environment. "
        "AREAS_FOR_IMPROVEMENT: Continued refinement of assessment checks and feedback routines would strengthen learning evidence during lessons. "
        "RECOMMENDATIONS: Use regular formative checks and provide timely, specific feedback aligned to lesson objectives to support all learners."
    )


def main() -> None:
    ai_app._load_models = _fake_load_models  # type: ignore[attr-defined]
    ai_app._generate_text = _fake_generate_text  # type: ignore[attr-defined]

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

    print("OK: /generate returned 200 with required fields")


if __name__ == "__main__":
    main()
