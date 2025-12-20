import os
from functools import lru_cache
from typing import Any, Dict, List, Optional, Union

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
from fastapi.exceptions import RequestValidationError
from pydantic import BaseModel, Field

# NOTE:
# This service is designed to run locally (same machine as XAMPP).
# It provides a JSON API that your PHP app can call via HTTP.

app = FastAPI(title="ADCES AI Service", version="1.0.0")


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


@app.post("/debug/echo")
async def debug_echo(request: Request):
    """Debug endpoint: echoes whatever JSON payload was received."""
    try:
        body = await request.json()
    except Exception:
        body = None
    return {"ok": True, "received": body}


class RatingItem(BaseModel):
    rating: float = Field(..., ge=1, le=5)
    comment: Optional[str] = ""


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


class GenerateResponse(BaseModel):
    strengths: str
    improvement_areas: str
    recommendations: str
    debug: Optional[Dict[str, Any]] = None


@lru_cache(maxsize=1)
def _load_models():
    """Lazy-load SBERT and Flan-T5 once."""
    from sentence_transformers import SentenceTransformer
    from transformers import AutoModelForSeq2SeqLM, AutoTokenizer

    sbert_name = os.getenv("SBERT_MODEL", "sentence-transformers/all-MiniLM-L6-v2")
    flan_name = os.getenv("FLAN_T5_MODEL", "google/flan-t5-base")

    sbert = SentenceTransformer(sbert_name)
    tok = AutoTokenizer.from_pretrained(flan_name)
    model = AutoModelForSeq2SeqLM.from_pretrained(flan_name)

    return sbert, tok, model


def _build_prompt(payload: GenerateRequest, similar_texts: List[str]) -> str:
    # Keep prompt simple and instruction-following.
    # We generate three paragraphs: strengths, areas for improvement, recommendations.
    context = "\n".join(f"- {t}" for t in similar_texts)

    return (
        "You are an academic classroom evaluation assistant. "
        "Write concise, professional feedback based on the ratings and comments.\n\n"
        f"Teacher: {payload.faculty_name}\n"
        f"Department: {payload.department}\n"
        f"Subject observed: {payload.subject_observed}\n"
        f"Observation type: {payload.observation_type}\n\n"
        f"Averages: communications={payload.averages.communications}, "
        f"management={payload.averages.management}, assessment={payload.averages.assessment}, "
        f"overall={payload.averages.overall}.\n\n"
        "Evidence (comments / signals):\n"
        f"{context if context else '- (no comments provided)'}\n\n"
        "Return exactly in this format with labels:\n"
        "STRENGTHS: ...\n"
        "AREAS_FOR_IMPROVEMENT: ...\n"
        "RECOMMENDATIONS: ...\n"
        "Do NOT return ellipses ('...') as the content. Provide real sentences even if comments are minimal.\n"
    )


def _flatten_comments(payload: GenerateRequest) -> List[str]:
    texts: List[str] = []
    for cat, items in (payload.ratings or {}).items():
        if items is None:
            continue

        # The PHP/JS may send ratings per category as:
        # - dict: {"0": {rating, comment}, "1": {rating, comment}}
        # - list: [{rating, comment}, {rating, comment}, ...]
        if isinstance(items, dict):
            iterable = list(items.items())
        elif isinstance(items, list):
            iterable = list(enumerate(items))
        else:
            # Unknown container shape; skip
            continue

        for k, v in iterable:
            item = _coerce_rating_item(v)
            if not item:
                continue
            if item.comment and item.comment.strip():
                texts.append(f"{cat} item {k}: rating={item.rating}, comment={item.comment.strip()}")
            else:
                # include at least the rating as signal
                texts.append(f"{cat} item {k}: rating={item.rating}")
    return texts


def _retrieve_top_k(sbert, texts: List[str], k: int = 10) -> List[str]:
    """Very small retrieval step: pick representative comments/ratings."""
    if not texts:
        return []

    import numpy as np

    emb = sbert.encode(texts, normalize_embeddings=True)
    centroid = np.mean(emb, axis=0)
    scores = emb @ centroid
    idx = np.argsort(scores)[::-1][: min(k, len(texts))]
    return [texts[i] for i in idx]


def _generate_text(tok, model, prompt: str) -> str:
    inputs = tok(prompt, return_tensors="pt", truncation=True, max_length=1024)
    out = model.generate(
        **inputs,
        max_new_tokens=250,
        do_sample=False,
        num_beams=4,
    )
    return tok.decode(out[0], skip_special_tokens=True)


def _parse_sections(text: str) -> Dict[str, str]:
    # Robust-ish parsing for the expected labels
    out = {"strengths": "", "improvement_areas": "", "recommendations": ""}
    upper = text
    marks = {
        "strengths": "STRENGTHS:",
        "improvement_areas": "AREAS_FOR_IMPROVEMENT:",
        "recommendations": "RECOMMENDATIONS:",
    }

    def pick(start: str, end: Optional[str]) -> str:
        s = upper.find(start)
        if s == -1:
            return ""
        s += len(start)
        e = upper.find(end, s) if end else -1
        chunk = upper[s:e].strip() if e != -1 else upper[s:].strip()
        return chunk

    out["strengths"] = pick(marks["strengths"], marks["improvement_areas"]) or ""
    out["improvement_areas"] = pick(marks["improvement_areas"], marks["recommendations"]) or ""
    out["recommendations"] = pick(marks["recommendations"], None) or ""

    # Fallback: if model didn't follow labels, return whole thing as recommendations
    if not (out["strengths"] or out["improvement_areas"] or out["recommendations"]):
        out["recommendations"] = text.strip()

    return out


def _is_placeholder_text(s: str) -> bool:
    t = (s or "").strip()
    if not t:
        return True
    # treat punctuation-only placeholders as empty
    stripped = t.replace(".", "").replace("…", "").replace("-", "").replace("—", "").strip()
    return stripped == "" or t == "..."


def _fallback_response(req: GenerateRequest) -> Dict[str, str]:
    """Provide deterministic, non-empty text so UI never stays at '...'."""
    # Use averages if provided to tailor tone
    avg = req.averages.overall if req.averages else 0
    if avg >= 4.5:
        tone = "very strong"
    elif avg >= 3.5:
        tone = "strong"
    elif avg >= 2.5:
        tone = "developing"
    else:
        tone = "needs improvement"

    strengths = (
        f"The observed lesson shows {tone} performance in key teaching domains. "
        "The teacher demonstrates effort in delivering content and maintaining classroom routines."
    )
    improvement = (
        "To strengthen impact, increase the use of quick checks for understanding and vary questioning strategies. "
        "Consider adding more student-centered activities and clearer success criteria for tasks."
    )
    recommendations = (
        "Continue using the current strengths while setting 1–2 specific goals for the next observation. "
        "Plan short formative assessments (e.g., exit tickets) and provide timely feedback to guide learners."
    )

    return {
        "strengths": strengths,
        "improvement_areas": improvement,
        "recommendations": recommendations,
    }


@app.get("/health")
def health():
    return {"ok": True}


@app.post("/generate", response_model=GenerateResponse)
def generate(req: GenerateRequest):
    sbert, tok, model = _load_models()

    texts = _flatten_comments(req)
    top = _retrieve_top_k(sbert, texts, k=10)

    prompt = _build_prompt(req, top)
    raw = _generate_text(tok, model, prompt)
    parsed = _parse_sections(raw)

    # Never return placeholders, because the UI uses '...' as placeholder.
    if (
        _is_placeholder_text(parsed.get("strengths", ""))
        or _is_placeholder_text(parsed.get("improvement_areas", ""))
        or _is_placeholder_text(parsed.get("recommendations", ""))
    ):
        parsed = _fallback_response(req)

    return GenerateResponse(
        strengths=parsed["strengths"].strip(),
        improvement_areas=parsed["improvement_areas"].strip(),
        recommendations=parsed["recommendations"].strip(),
        debug=None,
    )
