"""Export collected feedback into a simple supervised fine-tuning JSONL.

This script reads `ai_feedback.jsonl` and writes `ai_training_export.jsonl` where each
line is a JSON object: {"prompt": ..., "completion": ...}

The prompt includes the original input (ratings, averages, teacher/subject) and a short
instruction asking for the three labeled paragraphs. The completion is the human-corrected
output if available, otherwise the original generated output.

This format is intentionally generic and can be adapted for specific fine-tuning providers.
"""
import json
import pathlib

BASE = pathlib.Path(__file__).parent
FEEDBACK = BASE / "ai_feedback.jsonl"
OUT = BASE / "ai_training_export.jsonl"

if not FEEDBACK.exists():
    print("No feedback file found at", FEEDBACK)
    raise SystemExit(1)

with FEEDBACK.open("r", encoding="utf-8") as fh_in, OUT.open("w", encoding="utf-8") as fh_out:
    count = 0
    for line in fh_in:
        if not line.strip():
            continue
        try:
            item = json.loads(line)
        except Exception:
            continue

        req = item.get("request") or {}
        teacher = req.get("faculty_name") or "The teacher"
        subject = req.get("subject_observed") or "the observed class"
        averages = req.get("averages") or {}

        # Build a compact prompt
        prompt_parts = [
            f"Teacher: {teacher}",
            f"Subject: {subject}",
            f"Averages: {averages}",
            f"Ratings/evidence: {req.get('ratings')}",
            "Write three labeled paragraphs exactly: STRENGTHS:, AREAS_FOR_IMPROVEMENT:, RECOMMENDATIONS:."
        ]
        prompt = "\n".join(prompt_parts)

        gen = item.get("generated") or {}
        corr = item.get("corrected") or {}

        # Prefer corrected if available, otherwise generated
        strengths = corr.get("strengths") or gen.get("strengths") or ""
        improve = corr.get("improvement_areas") or gen.get("improvement_areas") or ""
        recs = corr.get("recommendations") or gen.get("recommendations") or ""

        completion = f"STRENGTHS: {strengths}\nAREAS_FOR_IMPROVEMENT: {improve}\nRECOMMENDATIONS: {recs}"

        out = {"prompt": prompt, "completion": completion}
        fh_out.write(json.dumps(out, ensure_ascii=False) + "\n")
        count += 1

print(f"Exported {count} examples to {OUT}")
