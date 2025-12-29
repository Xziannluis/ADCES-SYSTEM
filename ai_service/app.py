import os
from functools import lru_cache
from typing import Any, Dict, List, Optional, Union
import random
import traceback

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
    # Optional narrative style: short | standard | detailed
    style: Optional[str] = "standard"


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
    # Use flan-t5-base for better quality AI-generated feedback (250M params)
    # Still runs locally but generates much better text than flan-t5-small
    flan_name = os.getenv("FLAN_T5_MODEL", "google/flan-t5-base")

    sbert = SentenceTransformer(sbert_name)
    tok = AutoTokenizer.from_pretrained(flan_name)
    model = AutoModelForSeq2SeqLM.from_pretrained(flan_name)

    return sbert, tok, model


def _build_prompt(payload: GenerateRequest, similar_texts: List[str]) -> str:
    # Build a clear, structured prompt for the AI model
    avg = payload.averages
    
    # Build context from actual comments
    context_lines = []
    for i, text in enumerate(similar_texts[:8], 1):
        context_lines.append(f"{i}. {text}")
    context = "\n".join(context_lines) if context_lines else "No specific comments provided."
    
    # Create instruction-based prompt.
    # IMPORTANT: Do not include bracketed placeholders like "[Write ...]" because smaller seq2seq models
    # may copy them verbatim.
    prompt = f"""You are writing a PROFESSIONAL teacher evaluation narrative.
Use the ratings and observations as evidence.

Rules:
- Do NOT copy any instruction text.
- Do NOT output brackets [], placeholders, or template text.
- Write in complete sentences.
- Keep a professional and constructive tone.

Teacher: {payload.faculty_name or 'The teacher'}
Subject: {payload.subject_observed or 'Not specified'}
Type: {payload.observation_type or 'Classroom observation'}

RATINGS (out of 5.0):
- Communication skills: {avg.communications}
- Classroom management: {avg.management}
- Assessment methods: {avg.assessment}
- Overall performance: {avg.overall}

OBSERVATIONS:
{context}

TASK:
Write three short paragraphs. Each paragraph should be 2 to 4 sentences.

Output format (use these exact labels, one per line):
STRENGTHS: <your paragraph>
AREAS_FOR_IMPROVEMENT: <your paragraph>
RECOMMENDATIONS: <your paragraph>
"""
    
    return prompt


def _build_ratings_only_prompt(payload: GenerateRequest) -> str:
    """
    Ratings-only prompt (no comments).
    Goal: produce human-like, professional paragraphs WITHOUT echoing numeric scores.
    """
    avg = payload.averages
    teacher = (payload.faculty_name or "").strip() or "The teacher"
    subject = (payload.subject_observed or "").strip() or "the observed class"
    obs_type = (payload.observation_type or "").strip() or "classroom observation"

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

    # Map domains -> scores
    domains = {
        "Communication & instruction": float(avg.communications or 0),
        "Classroom management & learning environment": float(avg.management or 0),
        "Assessment & feedback practices": float(avg.assessment or 0),
    }
    weakest = min(domains, key=domains.get)
    strongest = max(domains, key=domains.get)
    overall_level = band(avg.overall)

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

    # IMPORTANT:
    # - Do NOT show numeric ratings anywhere (models tend to copy them).
    # - Use domain levels (Excellent / Satisfactory...) as the only “rating signal”.
    return f"""
You are an academic evaluator writing a professional teacher evaluation narrative based ONLY on domain ratings.

Hard rules:
- Do NOT output any numbers, fractions, or score summaries.
- Do NOT write expressions like "=" or "/5".
- Do NOT include headings other than the required labels.
- Do NOT invent specific classroom events. Stay general and professional.
- Write in complete sentences (human-like), constructive and respectful.

Context:
- Teacher: {teacher}
- Subject observed: {subject}
- Observation type: {obs_type}
- Overall level: {overall_level}
- Strongest domain: {strongest} ({band(domains[strongest])})
- Priority for improvement: {weakest} ({band(domains[weakest])})

Writing requirements:
- Write exactly THREE paragraphs.
- If style is "short": 2 sentences per paragraph.
- If style is "standard": 3–4 sentences per paragraph.
- If style is "detailed": 5–6 sentences per paragraph.
- Strengths must highlight the strongest domain using phrases like: {", ".join(s_phrases[:2])}.
- Areas for improvement and recommendations must focus mainly on the priority domain using phrases like: {", ".join(w_improve_phrases[:2])} and actions like: {", ".join(w_reco_phrases[:2])}.
- Do not mention "scores" or "ratings"; express performance using the domain levels instead.

Output format (use these exact labels, one per line):
STRENGTHS: <paragraph>
AREAS_FOR_IMPROVEMENT: <paragraph>
RECOMMENDATIONS: <paragraph>
""".strip()


def _build_retry_prompt(payload: GenerateRequest, similar_texts: List[str]) -> str:
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

    return f"""
Write a professional, human-like teacher evaluation narrative based ONLY on rating levels.

Rules:
- NO numbers, NO "=" signs, NO "/5", NO score recap.
- Do not invent details; keep statements general but specific to domains.
- Use complete sentences.

Information you may use:
- Overall level: {band(payload.averages.overall)}
- Strongest domain: {strongest} ({band(domains[strongest])})
- Priority domain: {weakest} ({band(domains[weakest])})
- Teacher: {teacher}

Return EXACTLY three labeled paragraphs:
STRENGTHS: 2–6 sentences depending on the requested style.
AREAS_FOR_IMPROVEMENT: 2–6 sentences.
RECOMMENDATIONS: 2–6 sentences.
""".strip()


def _generate_text(tok, model, prompt: str, style: str = "standard") -> str:
    """
    Generation tuned for 'human' paragraphs.
    Use a little sampling for natural phrasing, but keep repetition controls.
    """
    style = (style or "standard").strip().lower()
    if style not in {"short", "standard", "detailed"}:
        style = "standard"

    max_new = 180 if style == "short" else (260 if style == "standard" else 360)

    inputs = tok(prompt, return_tensors="pt", truncation=True, max_length=1024)

    out = model.generate(
        **inputs,
        max_new_tokens=max_new,
        do_sample=True,
        temperature=0.7,         # more natural than beam-only
        top_p=0.9,
        num_beams=1,             # sampling mode
        repetition_penalty=1.12,
        no_repeat_ngram_size=4,
        early_stopping=True,
    )
    return tok.decode(out[0], skip_special_tokens=True)


def _looks_like_bad_generation(text: str) -> bool:
    """Heuristics: detect outputs that are clearly not the requested 3-paragraph content."""
    if not text:
        return True

    t = text.strip()
    if len(t) < 120:
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

    # Contains digits -> likely score echo
    if any(ch.isdigit() for ch in t):
        return True

    has_labels = ("STRENGTHS:" in t) and ("AREAS_FOR_IMPROVEMENT:" in t) and ("RECOMMENDATIONS:" in t)
    if not has_labels:
        return True

    return False


def _flatten_comments(req: GenerateRequest) -> List[str]:
    """Normalize `req.ratings` into a flat list of evidence strings.

    The PHP app may send each category as:
    - dict of index -> {rating, comment}
    - list of {rating, comment}
    - list of scalars (rating-only)

    We only treat non-empty comments as "real comments".
    """
    texts: List[str] = []
    ratings = req.ratings or {}

    for category, items in ratings.items():
        if items is None:
            continue

        # Normalize to iterable of values
        if isinstance(items, dict):
            iterable = list(items.values())
        elif isinstance(items, list):
            iterable = items
        else:
            iterable = [items]

        for idx, raw in enumerate(iterable, 1):
            ri = _coerce_rating_item(raw)
            if not ri:
                continue

            comment = (ri.comment or "").strip()
            rating = ri.rating

            if comment:
                # include rating as context; prompt logic decides whether to use it
                texts.append(f"{category} item {idx}: rating={rating}; comment={comment}")
            else:
                texts.append(f"{category} item {idx}: rating={rating}")

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


def _parsed_missing_or_too_short(parsed: Dict[str, str]) -> bool:
    """Ensure each section has reasonable length."""
    for k in ("strengths", "improvement_areas", "recommendations"):
        v = (parsed.get(k) or "").strip()
        if len(v) < 40:
            return True
    return False


def _generate_template_based_feedback(req: GenerateRequest, texts: List[str]) -> Dict[str, str]:
    """Deterministic fallback so the PHP app always gets usable text."""
    avg = req.averages

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
        if "comment=" in t:
            comment_snips.append(t.split("comment=", 1)[1].strip())
        if len(comment_snips) >= 2:
            break

    evidence = " "
    if comment_snips:
        evidence = " Observations noted: " + "; ".join(comment_snips) + "."

    strengths = (
        f"{teacher} demonstrated {overall_level.lower()} performance in {subject}. "
        f"Strengths were most evident in {strongest.lower()}, supporting effective lesson delivery and learner engagement." 
        f"The overall classroom experience reflected purposeful planning and an appropriate focus on learning outcomes.{evidence}"
    ).strip()

    improvement_areas = (
        f"To further strengthen practice, priority should be given to {weakest.lower()}. "
        "Refining strategies in this area can help increase consistency, deepen learner participation, and improve clarity of expectations. "
        "Maintaining the current positive approaches while targeting these adjustments will support continued growth."
    ).strip()

    recommendations = (
        f"It is recommended that {teacher.lower()} adopts one or two focused routines aligned to {weakest.lower()} and monitors their impact over time. "
        "Using brief formative checks during the lesson and providing timely, specific feedback can strengthen instruction and student understanding. "
        "A short cycle of goal-setting, observation, and reflection (with coaching or peer support if available) is suggested to sustain improvement."
    ).strip()

    return {
        "strengths": strengths,
        "improvement_areas": improvement_areas,
        "recommendations": recommendations,
    }


@app.post("/generate", response_model=GenerateResponse)
def generate(req: GenerateRequest):
    sbert, tok, model = _load_models()

    texts = _flatten_comments(req)
    top = _retrieve_top_k(sbert, texts, k=10)

    has_real_comments = any(("comment=" in t or "comment:" in t) for t in texts)

    if not has_real_comments:
        prompt = _build_ratings_only_prompt(req)
    else:
        prompt = _build_prompt(req, top)

    raw = _generate_text(tok, model, prompt, style=req.style or "standard")

    if _looks_like_bad_generation(raw):
        retry_prompt = _build_retry_prompt(req, top)
        raw = _generate_text(tok, model, retry_prompt, style=req.style or "standard")

    parsed = _parse_sections(raw)

    combined = "\n".join([parsed.get("strengths", ""), parsed.get("improvement_areas", ""), parsed.get("recommendations", "")])
    if _looks_like_bad_generation(combined) or not any(parsed.values()) or _parsed_missing_or_too_short(parsed):
        parsed = _generate_template_based_feedback(req, texts)

    return GenerateResponse(
        strengths=parsed["strengths"],
        improvement_areas=parsed["improvement_areas"],
        recommendations=parsed["recommendations"],
        debug=None,
    )
