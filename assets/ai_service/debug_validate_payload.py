import json
import sys

from app import GenerateRequest

# A payload similar to what the PHP page sends.
payload = {
    "faculty_name": "Test",
    "department": "CCIS",
    "subject_observed": "Test",
    "observation_type": "Formal",
    "averages": {"communications": 3, "management": 3, "assessment": 3, "overall": 3},
    "ratings": {
        "communications": {"0": {"rating": "3", "comment": "ok"}},
        "management": {"0": {"rating": "3", "comment": "ok"}},
        "assessment": {"0": {"rating": "3", "comment": "ok"}},
    },
}

try:
    obj = GenerateRequest.model_validate(payload)
    print("VALID")
    print(obj.model_dump())
except Exception as e:
    print("INVALID")
    # pydantic v2 has .errors() for details
    try:
        print(json.dumps(e.errors(), indent=2))
    except Exception:
        print(str(e))
    sys.exit(1)
