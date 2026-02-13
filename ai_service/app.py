import os
from functools import lru_cache
from typing import Any, Dict, List, Optional, Union
import random
import traceback
import pathlib
import json
import re
from datetime import datetime
from threading import Lock
import numpy as np

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
from fastapi.exceptions import RequestValidationError
from pydantic import BaseModel, Field
from fastapi import HTTPException

# NOTE:
# This service is designed to run locally (same machine as XAMPP).
# It provides a JSON API that your PHP app can call via HTTP.

app = FastAPI(title="ADCES AI Service", version="1.0.0")


@app.get("/health")
async def health():
    """Basic health check used by the PHP proxy and smoke tests."""
    return {"ok": True}


@app.exception_handler(RequestValidationError)
async def _validation_exception_handler(request: Request, exc: RequestValidationError):
    # Return a friendlier JSON payload for frontend/PHP troubleshooting
    try:
        # Show in terminal to immediately see what's failing
        print("[422] Validation error on", request.url.path)
        for err in exc.errors():
            print("  ", err)
    except Exception:
        pass
    return JSONResponse(
        status_code=422,
        content={
            "message": "Request validation failed",
            "detail": exc.errors(),
        },
    )


@app.exception_handler(Exception)
async def _unhandled_exception_handler(request: Request, exc: Exception):
    # Always return JSON so PHP/JS won't say "invalid JSON"
    print("[500] Unhandled error on", request.url.path)
    traceback.print_exc()
    return JSONResponse(
        status_code=500,
        content={
            "message": "AI service crashed",
            "error": str(exc),
            "path": request.url.path,
        },
    )


@app.post("/debug/echo")
async def debug_echo(request: Request):
    """Debug endpoint: echoes whatever JSON payload was received."""
    try:
        body = await request.json()
    except Exception:
        body = None
    return {"ok": True, "received": body}


# Simple feedback collection to gather human judgments / corrections for later fine-tuning.
FEEDBACK_PATH = pathlib.Path(__file__).parent / "ai_feedback.jsonl"
_feedback_lock = Lock()


def _append_feedback(entry: Dict[str, Any]):
    """Append a JSON line to the feedback file in an atomic way."""
    try:
        _feedback_lock.acquire()
        FEEDBACK_PATH.parent.mkdir(parents=True, exist_ok=True)
        with FEEDBACK_PATH.open("a", encoding="utf-8") as fh:
            fh.write(json.dumps(entry, ensure_ascii=False) + "\n")
    finally:
        try:
            _feedback_lock.release()
        except Exception:
            pass


class RatingItem(BaseModel):
    rating: float = Field(..., ge=1, le=5)
    comment: Optional[str] = ""


def _rating_level(value: float) -> str:
    value = float(value or 0)
    if value >= 4.6:
        return "Excellent"
    if value >= 3.6:
        return "Very satisfactory"
    if value >= 2.9:
        return "Satisfactory"
    if value >= 1.8:
        return "Below satisfactory"
    return "Needs improvement"


def _coerce_rating_item(v: Any) -> Optional[RatingItem]:
    """Accept multiple shapes from PHP/JS and normalize to RatingItem.

    Allowed inputs:
    - {"rating": 3, "comment": "..."}
    - {"rating": "3", "comment": "..."}
    - 3 / "3" (rating-only)
    """
    if v is None:
        return None
    if isinstance(v, RatingItem):
        return v
    if isinstance(v, (int, float, str)):
        try:
            return RatingItem(rating=float(v), comment="")
        except Exception:
            return None
    if isinstance(v, dict):
        # common cases
        if "rating" in v:
            try:
                return RatingItem(rating=float(v.get("rating")), comment=str(v.get("comment") or ""))
            except Exception:
                return None
    return None


def _extract_rating_items(req: "GenerateRequest") -> List[Dict[str, Any]]:
    """Normalize ratings into structured items with labels."""
    items: List[Dict[str, Any]] = []
    ratings = req.ratings or {}

    for category, values in ratings.items():
        if values is None:
            continue

        if isinstance(values, dict):
            iterable = list(values.values())
        elif isinstance(values, list):
            iterable = values
        else:
            iterable = [values]

        for idx, raw in enumerate(iterable, 1):
            ri = _coerce_rating_item(raw)
            if not ri:
                continue

            label = ""
            if isinstance(raw, dict):
                label = str(raw.get("label") or raw.get("indicator") or raw.get("item") or "")
            label = (label or f"Item {idx}").strip()

            items.append(
                {
                    "category": str(category),
                    "index": idx,
                    "label": label,
                    "rating": float(ri.rating or 0),
                    "comment": (ri.comment or "").strip(),
                }
            )

    return items


def _summarize_indicators(items: List[Dict[str, Any]], top_n: int = 3, low_n: int = 3) -> Dict[str, List[str]]:
    """Return top/low indicator labels for prompt guidance."""
    if not items:
        return {"top": [], "low": []}

    ordered = sorted(items, key=lambda x: (x.get("rating", 0), x.get("label", "")))
    top_items = list(reversed(ordered))[: max(0, int(top_n or 0))]
    low_items = ordered[: max(0, int(low_n or 0))]

    def _labels(src: List[Dict[str, Any]]) -> List[str]:
        labels: List[str] = []
        for item in src:
            label = (item.get("label") or f"{item.get('category', 'item')} {item.get('index', '')}").strip()
            if label and label not in labels:
                labels.append(label)
        return labels

    return {
        "top": _labels(top_items),
        "low": _labels(low_items),
    }


class Averages(BaseModel):
    communications: float = 0
    management: float = 0
    assessment: float = 0
    overall: float = 0


class GenerateRequest(BaseModel):
    faculty_name: Optional[str] = ""
    department: Optional[str] = ""
    subject_observed: Optional[str] = ""
    observation_type: Optional[str] = ""
    # NOTE: The UI may send per-category values either as a dict (index->item)
    # or as a JSON array (list of items). Accept both to avoid 422.
    # We'll normalize it ourselves in _flatten_comments().
    ratings: Dict[str, Union[Dict[str, Any], List[Any]]] = Field(default_factory=dict)
    averages: Averages = Field(default_factory=Averages)
    strengths: Optional[str] = ""
    improvement_areas: Optional[str] = ""
    recommendations: Optional[str] = ""
    # Optional narrative style: short | standard | detailed
    style: Optional[str] = "standard"
    # Optional generation id from client to increase variation
    generation_id: Optional[str] = Field(default="", alias="random_id")

    class Config:
        allow_population_by_field_name = True


class GenerateResponse(BaseModel):
    strengths: str
    improvement_areas: str
    recommendations: str
    debug: Optional[Dict[str, Any]] = None


class FeedbackItem(BaseModel):
    # Minimal structure: include the original request, the generated text, and a human label/correction
    request: GenerateRequest
    generated_strengths: Optional[str] = None
    generated_improvement_areas: Optional[str] = None
    generated_recommendations: Optional[str] = None
    accurate: Optional[bool] = None
    # If the user corrected the output, include corrected text (any or all sections).
    corrected_strengths: Optional[str] = None
    corrected_improvement_areas: Optional[str] = None
    corrected_recommendations: Optional[str] = None
    comment: Optional[str] = None


@app.post("/feedback")
async def feedback(item: FeedbackItem):
    """Collect human feedback / corrections for exports and fine-tuning.

    Save a single JSON line with timestamp to `ai_feedback.jsonl`.
    """
    entry = {
        "ts": datetime.utcnow().isoformat() + "Z",
        "request": item.request.dict(),
        "generated": {
            "strengths": item.generated_strengths,
            "improvement_areas": item.generated_improvement_areas,
            "recommendations": item.generated_recommendations,
        },
        "accurate": item.accurate,
        "corrected": {
            "strengths": item.corrected_strengths,
            "improvement_areas": item.corrected_improvement_areas,
            "recommendations": item.corrected_recommendations,
        },
        "comment": item.comment,
    }

    try:
        _append_feedback(entry)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

    return {"ok": True}


@lru_cache(maxsize=1)
def _load_models():
    """Lazy-load SBERT and Flan-T5 once."""
    from sentence_transformers import SentenceTransformer
    from transformers import AutoModelForSeq2SeqLM, AutoTokenizer

    sbert_name = os.getenv("SBERT_MODEL", "sentence-transformers/all-MiniLM-L6-v2")
    # Use flan-t5-base for better quality AI-generated feedback (250M params)
    # Still runs locally but generates much better text than flan-t5-small
    flan_name = os.getenv("FLAN_T5_MODEL", "google/flan-t5-base")

    sbert = SentenceTransformer(sbert_name)
    tok = AutoTokenizer.from_pretrained(flan_name)
    model = AutoModelForSeq2SeqLM.from_pretrained(flan_name)

    # Move model to GPU if available for better speed/quality when possible.
    try:
        import torch
        device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
        model.to(device)
    except Exception:
        # If torch isn't available or moving fails, continue on CPU.
        pass

    return sbert, tok, model


def _get_generation_id(payload: GenerateRequest) -> str:
    raw = (payload.generation_id or "").strip()
    if raw:
        return raw
    return f"gen-{datetime.utcnow().strftime('%Y%m%d%H%M%S')}-{random.randint(1000, 9999)}"


def _build_user_input_summary(
    payload: GenerateRequest,
    rating_items: Optional[List[Dict[str, Any]]] = None,
) -> str:
    avg = payload.averages
    items = rating_items if rating_items is not None else _extract_rating_items(payload)

    domains = {
        "Communication & instruction": float(avg.communications or 0),
        "Classroom management & learning environment": float(avg.management or 0),
        "Assessment & feedback practices": float(avg.assessment or 0),
    }
    strongest = max(domains, key=domains.get) if domains else "Instructional practice"
    weakest = min(domains, key=domains.get) if domains else "Instructional practice"

    indicator_summary = _summarize_indicators(items, top_n=3, low_n=3)
    top_labels = ", ".join(indicator_summary["top"]) if indicator_summary["top"] else "Not specified"
    low_labels = ", ".join(indicator_summary["low"]) if indicator_summary["low"] else "Not specified"

    lines = [
        f"Teacher: {payload.faculty_name or 'The teacher'}",
        f"Department: {payload.department or 'Not specified'}",
        f"Subject: {payload.subject_observed or 'Not specified'}",
        f"Type: {payload.observation_type or 'Classroom observation'}",
        f"Overall level: {_rating_level(avg.overall)}",
        f"Strongest domain: {strongest} ({_rating_level(domains[strongest])})",
        f"Priority domain: {weakest} ({_rating_level(domains[weakest])})",
        f"Top indicators: {top_labels}",
        f"Priority indicators: {low_labels}",
    ]

    if (payload.strengths or "").strip():
        lines.append(f"Manual strengths note: {payload.strengths.strip()}")
    if (payload.improvement_areas or "").strip():
        lines.append(f"Manual improvement note: {payload.improvement_areas.strip()}")
    if (payload.recommendations or "").strip():
        lines.append(f"Manual recommendations note: {payload.recommendations.strip()}")

    return "\n".join(lines).strip()


def _build_prompt(
    payload: GenerateRequest,
    similar_texts: List[str],
    rating_items: Optional[List[Dict[str, Any]]] = None,
    generation_id: Optional[str] = None,
) -> str:
    # Build a clear, structured prompt for the AI model
    items = rating_items if rating_items is not None else _extract_rating_items(payload)
    gen_id = (generation_id or "").strip() or _get_generation_id(payload)
    user_input = _build_user_input_summary(payload, items)

    # Build context from actual comments (up to 8 items)
    context_lines = []
    for text in (similar_texts or [])[:8]:
        context_lines.append(f"- {text}")
    context = "\n".join(context_lines) if context_lines else "No specific comments provided."
    # Create instruction-based prompt.
    # IMPORTANT: Do not include bracketed placeholders like "[Write ...]" because smaller seq2seq models
    # may copy them verbatim.
    prompt = (
        "Task: Generate AI recommendations with high accuracy and variation.\n\n"
        "Rules:\n"
        "- Write recommendations for each category provided (STRENGTHS, AREAS_FOR_IMPROVEMENT, RECOMMENDATIONS).\n"
        "- Produce exactly three sentences per category.\n"
        "- Each sentence must provide new, non-redundant information.\n"
        "- Do not reuse sentence structures, phrasing, or examples from earlier outputs.\n"
        "- If the same input is given again, vary wording, reasoning style, and focus while keeping the meaning correct.\n"
        "- Use the ratings, indicators, and observations as evidence and avoid inventing details.\n\n"
        "Context (ranked by relevance):\n"
        f"{context}\n\n"
        "Input:\n"
        f"{user_input}\n\n"
        f"Generation ID: {gen_id}\n"
        "Use the Generation ID to ensure this output is different from previous generations.\n\n"
        "Output format (use these exact labels, one per line):\n"
        "STRENGTHS: Sentence 1. Sentence 2. Sentence 3.\n"
        "AREAS_FOR_IMPROVEMENT: Sentence 1. Sentence 2. Sentence 3.\n"
        "RECOMMENDATIONS: Sentence 1. Sentence 2. Sentence 3.\n"
    )

    return prompt


def _build_ratings_only_prompt(
    payload: GenerateRequest,
    rating_items: List[Dict[str, Any]],
    generation_id: Optional[str] = None,
) -> str:
    """
    Ratings-only prompt (no comments).
    Goal: produce human-like, professional paragraphs WITHOUT echoing numeric scores.
    """
    avg = payload.averages

    def band(x: float) -> str:
        return _rating_level(x)

    # Map domains -> scores
    domains = {
        "Communication & instruction": float(avg.communications or 0),
        "Classroom management & learning environment": float(avg.management or 0),
        "Assessment & feedback practices": float(avg.assessment or 0),
    }
    weakest = min(domains, key=domains.get)
    strongest = max(domains, key=domains.get)
    overall_level = band(avg.overall)

    gen_id = (generation_id or "").strip() or _get_generation_id(payload)
    user_input = _build_user_input_summary(payload, rating_items)

    # Anchors: used as "safe, non-invented" evidence phrases
    anchors = {
        "Communication & instruction": {
            "strength": [
                "clear communication of lesson expectations",
                "effective questioning and checking for understanding",
                "appropriate pacing and explanation of key concepts",
            ],
            "improve": [
                "increasing student talk time and active participation",
                "strengthening clarity of directions and transitions",
                "using more varied engagement strategies during instruction",
            ],
            "reco": [
                "use structured questioning routines (wait time, probing, follow-up questions)",
                "add quick checks for understanding (exit prompts, mini-whiteboards, short quizzes)",
                "plan engagement checkpoints (think-pair-share, cold-calling with support, guided practice)",
            ],
        },
        "Classroom management & learning environment": {
            "strength": [
                "maintaining a respectful learning environment",
                "supporting lesson flow through routines and classroom organization",
                "promoting a focused classroom atmosphere",
            ],
            "improve": [
                "strengthening routines for transitions and task completion",
                "using proactive behavior supports and consistent expectations",
                "maximizing instructional time through clearer procedures",
            ],
            "reco": [
                "establish and rehearse clear routines for entry, transitions, and group tasks",
                "use monitoring and positive reinforcement aligned with expectations",
                "tighten lesson structure with time cues and clear task directions",
            ],
        },
        "Assessment & feedback practices": {
            "strength": [
                "monitoring learner progress through appropriate assessment practices",
                "aligning tasks with intended learning goals",
                "providing opportunities to demonstrate understanding",
            ],
            "improve": [
                "making assessment evidence more frequent and instructional (formative)",
                "strengthening the clarity and usefulness of feedback for next steps",
                "using success criteria so learners understand quality expectations",
            ],
            "reco": [
                "embed short formative checks aligned to objectives throughout the lesson",
                "use rubrics/success criteria and give specific feedback tied to those criteria",
                "include opportunities for corrections or revision after feedback",
            ],
        },
    }

    s_phrases = anchors[strongest]["strength"]
    w_improve_phrases = anchors[weakest]["improve"]
    w_reco_phrases = anchors[weakest]["reco"]

    def pick(items: List[str], n: int = 2) -> str:
        if not items:
            return ""
        n = min(n, len(items))
        return "; ".join(random.sample(items, n))

    base = (
        "Task: Generate AI recommendations with high accuracy and variation.\n\n"
        "Rules:\n"
        "- Write recommendations for each category provided (STRENGTHS, AREAS_FOR_IMPROVEMENT, RECOMMENDATIONS).\n"
        "- Produce exactly three sentences per category.\n"
        "- Each sentence must provide new, non-redundant information.\n"
        "- Do not reuse sentence structures, phrasing, or examples from earlier outputs.\n"
        "- If the same input is given again, vary wording, reasoning style, and focus while keeping the meaning correct.\n"
        "- Do NOT include numeric scores; avoid numeric references.\n\n"
        "Context (ranked by relevance):\n"
        "No comments were provided; rely on rating levels and indicator hints.\n\n"
        "Input:\n"
        f"{user_input}\n\n"
        f"Suggested strength phrases (pick one or two): {pick(s_phrases)}\n"
        f"Suggested improvement phrases (pick one or two): {pick(w_improve_phrases)}\n"
        f"Suggested recommendation phrases (pick one or two): {pick(w_reco_phrases)}\n\n"
        f"Generation ID: {gen_id}\n"
        "Use the Generation ID to ensure this output is different from previous generations.\n\n"
        "Output format (use these exact labels, one per line):\n"
        "STRENGTHS: Sentence 1. Sentence 2. Sentence 3.\n"
        "AREAS_FOR_IMPROVEMENT: Sentence 1. Sentence 2. Sentence 3.\n"
        "RECOMMENDATIONS: Sentence 1. Sentence 2. Sentence 3.\n"
    )

    return base.strip()


def _build_retry_prompt(
    payload: GenerateRequest,
    similar_texts: List[str],
    generation_id: Optional[str] = None,
) -> str:
    """
    Even stricter retry prompt.
    Avoid putting numbers near the model to reduce numeric echo.
    """
    avg = payload.averages

    def band(x: float) -> str:
        x = float(x or 0)
        if x >= 4.6:
            return "Excellent"
        if x >= 3.6:
            return "Very satisfactory"
        if x >= 2.9:
            return "Satisfactory"
        if x >= 1.8:
            return "Below satisfactory"
        return "Needs improvement"

    # Determine lowest/highest domain without showing numbers
    domains = {
        "Communication & instruction": float(avg.communications or 0),
        "Classroom management & learning environment": float(avg.management or 0),
        "Assessment & feedback practices": float(avg.assessment or 0),
    }
    weakest = min(domains, key=domains.get)
    strongest = max(domains, key=domains.get)

    teacher = (payload.faculty_name or "").strip() or "The teacher"
    gen_id = (generation_id or "").strip() or _get_generation_id(payload)

    return f"""
Task: Generate AI recommendations with high accuracy and variation.

Rules:
- NO numbers, NO "=" signs, NO "/5", NO score recap.
- Do not invent details; keep statements specific to the domains.
- Use complete sentences with natural variation.
- For each category, write exactly three sentences.
- Vary wording, reasoning style, and focus when the same input appears again.

Information you may use:
- Overall level: {band(payload.averages.overall)}
- Strongest domain: {strongest} ({band(domains[strongest])})
- Priority domain: {weakest} ({band(domains[weakest])})
- Teacher: {teacher}

Generation ID: {gen_id}
Use the Generation ID to ensure this output is different from previous generations.

Output format (use these exact labels, one per line):
STRENGTHS: Sentence 1. Sentence 2. Sentence 3.
AREAS_FOR_IMPROVEMENT: Sentence 1. Sentence 2. Sentence 3.
RECOMMENDATIONS: Sentence 1. Sentence 2. Sentence 3.
""".strip()


def _generate_text(tok, model, prompt: str, style: str = "standard") -> str:
    """
    Generation tuned for 'human' paragraphs.
    Use a little sampling for natural phrasing, but keep repetition controls.
    """
    style = (style or "standard").strip().lower()
    if style not in {"short", "standard", "detailed"}:
        style = "standard"

    max_new = 200 if style == "short" else (250 if style == "standard" else 340)

    inputs = tok(prompt, return_tensors="pt", truncation=True, max_length=1024)

    out = model.generate(
        **inputs,
        max_new_tokens=max_new,
        do_sample=True,
        temperature=0.9,
        top_p=0.95,
        num_beams=1,
        num_return_sequences=1,
        repetition_penalty=1.2,
        no_repeat_ngram_size=3,
        early_stopping=True,
    )
    return tok.decode(out[0], skip_special_tokens=True)


def _looks_like_bad_generation(text: str) -> bool:
    """Heuristics: detect outputs that are clearly not the requested 3-paragraph content."""
    if not text:
        return True

    t = text.strip()
    if len(t) < 80:
        return True

    lower = t.lower()

    # If it contains lots of numeric/score patterns, it's not acceptable.
    # (We now forbid numbers entirely in ratings-only mode.)
    bad_markers = [
        "communication =",
        "management =",
        "assessment =",
        "overall =",
        "communication=",
        "management=",
        "assessment=",
        "overall=",
        "/5",
        "out of 5",
        "rating:",
        "score:",
        "scores:",
    ]
    if any(m in lower for m in bad_markers):
        return True

    # Only flag digits when clearly tied to score/ratings language.
    if any(ch.isdigit() for ch in t):
        score_markers = ["/5", "out of", "rating", "score", "scores"]
        if any(m in lower for m in score_markers):
            return True

    has_labels = ("STRENGTHS:" in t) and ("AREAS_FOR_IMPROVEMENT:" in t) and ("RECOMMENDATIONS:" in t)
    if not has_labels:
        return True

    return False


def _flatten_comments(req: GenerateRequest, rating_items: Optional[List[Dict[str, Any]]] = None) -> List[str]:
    """Normalize `req.ratings` into a flat list of evidence strings.

    The PHP app may send each category as:
    - dict of index -> {rating, comment}
    - list of {rating, comment}
    - list of scalars (rating-only)

    We only treat non-empty comments as "real comments".
    """
    texts: List[str] = []
    items = rating_items if rating_items is not None else _extract_rating_items(req)

    for item in items:
        category = item.get("category") or ""
        label = (item.get("label") or "").strip()
        rating_level = _rating_level(float(item.get("rating", 0)))
        comment = (item.get("comment") or "").strip()

        base = f"{category} - {label}. rating level: {rating_level}."
        if comment:
            base = f"{base} comment: {comment}"
        texts.append(base)

    return texts


def _retrieve_top_k(sbert, texts: List[str], k: int = 10) -> List[str]:
    """Return top-k 'most relevant' texts.

    In the absence of a query, the simplest robust behavior is to just
    prioritize comment-bearing evidence and keep order stable.

    NOTE: This intentionally doesn't rely on SBERT similarity to avoid runtime
    issues when no comments exist or when models aren't available.
    """
    if not texts:
        return []

    # Prefer an SBERT-based ranking that selects representative / central comments.
    # This helps pick evidence that's most 'typical' when many comments exist.
    use_retrieval = os.getenv("USE_SBERT_RETRIEVAL", "1") not in ("0", "false", "False")
    if use_retrieval:
        try:
            # Encode all texts; if SBERT fails, fall back to simple heuristic below.
            emb = sbert.encode(texts, convert_to_numpy=True)
            if emb is not None and len(emb) == len(texts):
                # Compute centroid and rank by cosine similarity to centroid (most representative first).
                centroid = np.mean(emb, axis=0)
                # normalize to avoid zero-division
                def _cos(a, b):
                    na = a / (np.linalg.norm(a) + 1e-12)
                    nb = b / (np.linalg.norm(b) + 1e-12)
                    return float(np.dot(na, nb))

                scores = [ _cos(v, centroid) for v in emb ]
                idxs = sorted(range(len(scores)), key=lambda i: scores[i], reverse=True)
                ranked = [texts[i] for i in idxs]
                # Keep comment-bearing texts earlier by stable sort if many ties
                with_comments = [t for t in ranked if ("comment=" in t or "comment:" in t)]
                without_comments = [t for t in ranked if t not in with_comments]
                ranked = with_comments + without_comments
                return ranked[: max(0, int(k or 0))] if k else ranked
        except Exception:
            # Fall through to conservative heuristic below on any error.
            pass

    # Conservative fallback: prefer items that explicitly contain comments, keep original order.
    with_comments = [t for t in texts if ("comment=" in t or "comment:" in t)]
    without_comments = [t for t in texts if t not in with_comments]
    ranked = with_comments + without_comments
    return ranked[: max(0, int(k or 0))] if k else ranked


def _parse_sections(text: str) -> Dict[str, str]:
    """Parse model output into the three expected sections.

    Accepts small deviations but prefers the required labels.
    """
    out = {"strengths": "", "improvement_areas": "", "recommendations": ""}
    if not text:
        return out

    # Normalize newlines and strip
    t = text.replace("\r\n", "\n").strip()

    # Find labeled lines (can contain extra spaces)
    lines = [ln.strip() for ln in t.split("\n") if ln.strip()]

    current_key: Optional[str] = None
    buffer: List[str] = []

    def flush():
        nonlocal buffer, current_key
        if current_key and buffer:
            out[current_key] = " ".join(buffer).strip()
        buffer = []

    label_map = {
        "STRENGTHS:": "strengths",
        "AREAS_FOR_IMPROVEMENT:": "improvement_areas",
        "AREAS FOR IMPROVEMENT:": "improvement_areas",
        "RECOMMENDATIONS:": "recommendations",
    }

    for ln in lines:
        upper = ln.upper()
        matched = None
        for lab, key in label_map.items():
            if upper.startswith(lab):
                matched = (lab, key)
                break

        if matched:
            flush()
            _, key = matched
            current_key = key
            content = ln.split(":", 1)[1].strip() if ":" in ln else ""
            if content:
                buffer.append(content)
        else:
            # continuation of current section
            if current_key:
                buffer.append(ln)

    flush()

    # If labels weren't found at all, fall back to treating the whole as strengths.
    if not any(out.values()):
        out["strengths"] = t

    return out


def _split_sentences(text: str) -> List[str]:
    if not text:
        return []
    clean = " ".join(text.replace("\n", " ").split()).strip()
    if not clean:
        return []
    parts = [p.strip() for p in re.split(r"(?<=[.!?])\s+", clean) if p.strip()]
    return parts


def _ensure_three_sentences(text: str, fallback: str) -> str:
    sentences = _split_sentences(text)
    fallback_sentences = _split_sentences(fallback)

    if len(sentences) < 3:
        needed = 3 - len(sentences)
        if fallback_sentences:
            sentences.extend(fallback_sentences[:needed])
        else:
            sentences.extend(["Further detail was not provided, so a reasonable inference is offered."] * needed)

    if len(sentences) > 3:
        sentences = sentences[:3]

    return " ".join(sentences).strip()


def _parsed_missing_or_too_short(parsed: Dict[str, str]) -> bool:
    """Ensure each section has reasonable length."""
    for k in ("strengths", "improvement_areas", "recommendations"):
        v = (parsed.get(k) or "").strip()
        if len(v) < 40:
            return True
        if len(_split_sentences(v)) < 3:
            return True
    return False


def _generate_template_based_feedback(
    req: GenerateRequest,
    texts: List[str],
    rating_items: Optional[List[Dict[str, Any]]] = None,
) -> Dict[str, str]:
    """Deterministic fallback so the PHP app always gets usable text."""
    avg = req.averages

    def band(x: float) -> str:
        return _rating_level(x)

    domains = {
        "Communication & instruction": float(avg.communications or 0),
        "Classroom management & learning environment": float(avg.management or 0),
        "Assessment & feedback practices": float(avg.assessment or 0),
    }
    weakest = min(domains, key=domains.get) if domains else "Instructional practice"
    strongest = max(domains, key=domains.get) if domains else "Professional practice"
    overall_level = band(avg.overall)

    teacher = (req.faculty_name or "").strip() or "The teacher"
    subject = (req.subject_observed or "").strip() or "the observed class"

    # Pick up to 2 brief comment snippets if available
    comment_snips: List[str] = []
    for t in texts or []:
        if "comment:" in t:
            comment_snips.append(t.split("comment:", 1)[1].strip())
        if len(comment_snips) >= 2:
            break

    evidence = ""
    if comment_snips:
        evidence = "Observations noted: " + "; ".join(comment_snips) + "."

    anchors = {
        "Communication & instruction": {
            "strength": [
                "clear communication of lesson expectations",
                "effective questioning and checking for understanding",
                "appropriate pacing and explanation of key concepts",
            ],
            "improve": [
                "increasing student talk time and active participation",
                "strengthening clarity of directions and transitions",
                "using more varied engagement strategies during instruction",
            ],
            "reco": [
                "use structured questioning routines (wait time, probing, follow-up questions)",
                "add quick checks for understanding (exit prompts, mini-whiteboards, short quizzes)",
                "plan engagement checkpoints (think-pair-share, cold-calling with support, guided practice)",
            ],
        },
        "Classroom management & learning environment": {
            "strength": [
                "maintaining a respectful learning environment",
                "supporting lesson flow through routines and classroom organization",
                "promoting a focused classroom atmosphere",
            ],
            "improve": [
                "strengthening routines for transitions and task completion",
                "using proactive behavior supports and consistent expectations",
                "maximizing instructional time through clearer procedures",
            ],
            "reco": [
                "establish and rehearse clear routines for entry, transitions, and group tasks",
                "use monitoring and positive reinforcement aligned with expectations",
                "tighten lesson structure with time cues and clear task directions",
            ],
        },
        "Assessment & feedback practices": {
            "strength": [
                "monitoring learner progress through appropriate assessment practices",
                "aligning tasks with intended learning goals",
                "providing opportunities to demonstrate understanding",
            ],
            "improve": [
                "making assessment evidence more frequent and instructional (formative)",
                "strengthening the clarity and usefulness of feedback for next steps",
                "using success criteria so learners understand quality expectations",
            ],
            "reco": [
                "embed short formative checks aligned to objectives throughout the lesson",
                "use rubrics/success criteria and give specific feedback tied to those criteria",
                "include opportunities for corrections or revision after feedback",
            ],
        },
    }

    level_openers = {
        "Excellent": [
            "demonstrated strong practice in the observed class",
            "showed high-quality performance throughout the lesson",
        ],
        "Very satisfactory": [
            "demonstrated solid, effective practice in the observed class",
            "showed consistent performance across key teaching domains",
        ],
        "Satisfactory": [
            "demonstrated acceptable practice with room for growth",
            "met expected standards in the observed class",
        ],
        "Below satisfactory": [
            "showed emerging practice with several growth areas",
            "demonstrated partial alignment with expected standards",
        ],
        "Needs improvement": [
            "showed limited alignment with expected standards",
            "demonstrated early-stage practice requiring targeted support",
        ],
    }

    def pick(items: List[str], n: int = 2) -> str:
        if not items:
            return ""
        n = min(n, len(items))
        return "; ".join(random.sample(items, n))

    strengths_focus = pick(anchors[strongest]["strength"], 2)
    improvement_focus = pick(anchors[weakest]["improve"], 2)
    recommendation_focus = pick(anchors[weakest]["reco"], 2)
    opener = random.choice(level_openers.get(overall_level, level_openers["Satisfactory"]))

    items = rating_items if rating_items is not None else _extract_rating_items(req)

    def _pick_indicator_labels(items: List[Dict[str, Any]], n: int, highest: bool) -> List[str]:
        if not items:
            return []
        ordered = sorted(items, key=lambda x: (x.get("rating", 0), x.get("label", "")))
        chosen = ordered[-n:] if highest else ordered[:n]
        labels = []
        for item in chosen:
            label = (item.get("label") or f"{item.get('category', 'item')} {item.get('index', '')}").strip()
            if label:
                labels.append(label)
        return labels

    top_labels = _pick_indicator_labels(items, 2, True)
    low_labels = _pick_indicator_labels(items, 2, False)
    top_hint = ", ".join(top_labels) if top_labels else ""
    low_hint = ", ".join(low_labels) if low_labels else ""

    strengths_sentences = [
        f"{teacher} {opener} in {subject}.",
        f"Strengths were most evident in {strongest.lower()}, including {strengths_focus}.",
        (
            f"Key indicators included {top_hint}."
            if top_hint
            else (evidence if evidence else "Evidence suggests consistent alignment with expected instructional practices.")
        ),
    ]

    improvement_sentences = [
        f"To strengthen performance, priority should be given to {weakest.lower()}, particularly {improvement_focus}.",
        (
            f"Priority indicators include {low_hint}."
            if low_hint
            else "Greater consistency in routines and expectations would improve overall lesson flow."
        ),
        "Refining strategies in this area can improve consistency, learner participation, and clarity of expectations.",
    ]

    recommendation_sentences = [
        f"It is recommended that {teacher.lower()} focuses on {weakest.lower()} by applying {recommendation_focus}.",
        (
            f"Targeted support should address {low_hint}."
            if low_hint
            else "Use small, focused action steps that can be monitored during instruction."
        ),
        "Monitor progress over time through short reflection cycles and targeted feedback to sustain improvement.",
    ]

    strengths = " ".join(strengths_sentences).strip()
    improvement_areas = " ".join(improvement_sentences).strip()
    recommendations = " ".join(recommendation_sentences).strip()

    return {
        "strengths": strengths,
        "improvement_areas": improvement_areas,
        "recommendations": recommendations,
    }


@app.post("/generate", response_model=GenerateResponse)
def generate(req: GenerateRequest):
    sbert, tok, model = _load_models()

    gen_id = _get_generation_id(req)

    rating_items = _extract_rating_items(req)
    texts = _flatten_comments(req, rating_items)
    top = _retrieve_top_k(sbert, texts, k=10)

    has_real_comments = any(item.get("comment") for item in rating_items)

    if not has_real_comments:
        prompt = _build_ratings_only_prompt(req, rating_items, generation_id=gen_id)
    else:
        prompt = _build_prompt(req, top, rating_items, generation_id=gen_id)

    raw = _generate_text(tok, model, prompt, style=req.style or "standard")

    if _looks_like_bad_generation(raw):
        retry_prompt = _build_retry_prompt(req, top, generation_id=gen_id)
        raw = _generate_text(tok, model, retry_prompt, style=req.style or "standard")

    parsed = _parse_sections(raw)
    fallback = _generate_template_based_feedback(req, texts, rating_items)

    combined = "\n".join([
        parsed.get("strengths", ""),
        parsed.get("improvement_areas", ""),
        parsed.get("recommendations", ""),
    ])
    if _looks_like_bad_generation(combined) or not any(parsed.values()) or _parsed_missing_or_too_short(parsed):
        parsed = fallback

    normalized = {
        "strengths": _ensure_three_sentences(parsed.get("strengths", ""), fallback.get("strengths", "")),
        "improvement_areas": _ensure_three_sentences(
            parsed.get("improvement_areas", ""),
            fallback.get("improvement_areas", ""),
        ),
        "recommendations": _ensure_three_sentences(
            parsed.get("recommendations", ""),
            fallback.get("recommendations", ""),
        ),
    }

    return GenerateResponse(
        strengths=normalized["strengths"],
        improvement_areas=normalized["improvement_areas"],
        recommendations=normalized["recommendations"],
        debug=None,
    )
