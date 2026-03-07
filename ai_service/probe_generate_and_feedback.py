from __future__ import annotations

import json

import httpx


BASE_URL = "http://127.0.0.1:8001"


def main() -> None:
    generate_payload = {
        "faculty_name": "Test Teacher",
        "department": "CCS",
        "subject_observed": "Database Management Systems",
        "observation_type": "Formal",
        "ratings": {
            "communications": [
                {"rating": 5, "comment": "The teacher explained concepts clearly and used examples effectively."},
                {"rating": 4, "comment": "Directions were clear and the class stayed focused during the lesson."}
            ],
            "management": [
                {"rating": 4, "comment": "Routines were established and transitions were generally smooth."},
                {"rating": 4, "comment": "Learners remained engaged during the activities."}
            ],
            "assessment": [
                {"rating": 3, "comment": "Checks for understanding can be more frequent during the lesson."},
                {"rating": 4, "comment": "Feedback was provided but could include more follow-up prompts."}
            ]
        },
        "averages": {
            "communications": 4.5,
            "management": 4.0,
            "assessment": 3.5,
            "overall": 4.0
        },
        "style": "standard"
    }

    with httpx.Client(timeout=30.0) as client:
        generate_response = client.post(f"{BASE_URL}/generate", json=generate_payload)
        generate_response.raise_for_status()
        generated = generate_response.json()

        feedback_payload = {
            "request": generate_payload,
            "generated_strengths": generated.get("strengths"),
            "generated_improvement_areas": generated.get("improvement_areas"),
            "generated_recommendations": generated.get("recommendations"),
            "accurate": True,
            "corrected_strengths": generated.get("strengths"),
            "corrected_improvement_areas": generated.get("improvement_areas"),
            "corrected_recommendations": generated.get("recommendations"),
            "comment": "Probe marked the AI output as accurate.",
        }
        feedback_response = client.post(f"{BASE_URL}/feedback", json=feedback_payload)
        feedback_response.raise_for_status()

    print(json.dumps({
        "generate_status": generate_response.status_code,
        "generated": generated,
        "feedback_status": feedback_response.status_code,
        "feedback_result": feedback_response.json(),
    }, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
