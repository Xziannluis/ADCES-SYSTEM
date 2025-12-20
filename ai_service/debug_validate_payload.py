import json
import sys

import urllib.request

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

    # If the server is running, also test the real HTTP endpoint.
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        "http://127.0.0.1:8008/generate",
        method="POST",
        data=data,
        headers={"Content-Type": "application/json"},
    )
    print("\n--- Calling http://127.0.0.1:8008/generate ---")
    with urllib.request.urlopen(req, timeout=120) as resp:
        body = resp.read().decode("utf-8", errors="replace")
        print("HTTP", resp.status)
        print(body)
except Exception as e:
    print("INVALID")
    # pydantic v2 has .errors() for details
    try:
        print(json.dumps(e.errors(), indent=2))
    except Exception:
        print(str(e))
    sys.exit(1)
