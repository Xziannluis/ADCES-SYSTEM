import os
from functools import lru_cache
from typing import Any, Dict, List, Optional, Union
import random

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
    """Prompt used when you want feedback purely from radio-button ratings (no comments)."""
    avg = payload.averages
    teacher = payload.faculty_name or "The teacher"
    subject = payload.subject_observed or "(not specified)"
    obs_type = payload.observation_type or "(not specified)"

    # Convert numeric ratings into qualitative descriptors (so the model writes words, not echoes numbers)
    def band(x: float) -> str:
        if x >= 4.6:
            return "Excellent"
        if x >= 3.6:
            return "Very satisfactory"
        if x >= 2.9:
            return "Satisfactory"
        if x >= 1.8:
            return "Below satisfactory"
        return "Needs improvement"

    return (
        "You are an experienced academic evaluator. Write a human-like evaluation narrative based ONLY on the ratings.\n"
        "Do NOT repeat raw scores as your output. Do NOT write fragments like 'Communication = 5'.\n"
        "Write complete sentences in a professional tone.\n\n"
        f"Teacher: {teacher}\n"
        f"Subject observed: {subject}\n"
        f"Observation type: {obs_type}\n\n"
        "Performance levels:\n"
        f"- Communication: {band(avg.communications)}\n"
        f"- Classroom management: {band(avg.management)}\n"
        f"- Assessment: {band(avg.assessment)}\n"
        f"- Overall: {band(avg.overall)}\n\n"
        "Write exactly three labeled paragraphs (2-4 sentences each):\n"
        "STRENGTHS: Describe likely strengths consistent with the ratings.\n"
        "AREAS_FOR_IMPROVEMENT: Mention realistic improvements consistent with the lowest domain.\n"
        "RECOMMENDATIONS: Provide actionable professional-growth steps.\n"
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
    # Optimized parameters for flan-t5-base to generate coherent, professional feedback:
    # - max_new_tokens: 300 to allow full paragraphs for all 3 sections
    # - num_beams: 4 for better quality (beam search)
    # - length_penalty: 1.2 to encourage complete sentences
    # - no_repeat_ngram_size: 3 to avoid repetition
    # - early_stopping: True to stop when all beams finish
    out = model.generate(
        **inputs,
        max_new_tokens=300,
        num_beams=4,
        length_penalty=1.2,
        no_repeat_ngram_size=3,
        early_stopping=True,
        do_sample=False,  # Deterministic with beam search
    )
    return tok.decode(out[0], skip_special_tokens=True)


def _looks_like_bad_generation(text: str) -> bool:
    """Heuristics: detect outputs that are clearly not the requested 3-paragraph content."""
    if not text:
        return True

    t = text.strip()
    if len(t) < 80:
        return True

    # model sometimes returns a single bullet (e.g. '- Communication skills: 5.0')
    if t.startswith("-") and "\n" not in t:
        return True

    # numeric-fragment outputs (what we see in the UI screenshots)
    lower = t.lower()
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
    ]
    if any(m in lower for m in bad_markers) and len(t) < 300:
        return True

    # If it doesn't contain any of our section labels, assume it missed instructions.
    has_labels = ("STRENGTHS:" in t) and ("AREAS_FOR_IMPROVEMENT:" in t) and ("RECOMMENDATIONS:" in t)
    if not has_labels:
        return True

    return False


def _build_retry_prompt(payload: GenerateRequest, similar_texts: List[str]) -> str:
    """A shorter, more direct prompt that flan-t5-base tends to follow better."""
    avg = payload.averages

    # keep only the plain comment text (avoid "cat item k: rating=..." which can cause copying)
    comment_only: List[str] = []
    for t in similar_texts[:8]:
        # split off "comment=" style
        if "comment=" in t:
            comment_only.append(t.split("comment=", 1)[1].strip())
        elif "comment:" in t:
            comment_only.append(t.split("comment:", 1)[1].strip())
        else:
            # fall back to the raw text
            comment_only.append(t)

    evidence = "\n".join(f"- {c}" for c in comment_only if c) or "- No written comments provided."

    # IMPORTANT: avoid patterns like "communication=5" which the model tends to echo.
    return (
        "Write a professional teacher evaluation narrative based on the scores and evidence. "
        "Write in complete sentences (human-like). Do not output only numbers or score summaries.\n\n"
        "Scores (1 to 5):\n"
        f"- Communication: {avg.communications}\n"
        f"- Management: {avg.management}\n"
        f"- Assessment: {avg.assessment}\n"
        f"- Overall: {avg.overall}\n\n"
        "Evidence:\n"
        f"{evidence}\n\n"
        "Return EXACTLY three labeled paragraphs:\n"
        "STRENGTHS: 2-4 sentences.\n"
        "AREAS_FOR_IMPROVEMENT: 2-4 sentences.\n"
        "RECOMMENDATIONS: 2-4 sentences.\n"
    )


def _parsed_missing_or_too_short(parsed: Dict[str, str]) -> bool:
    s = (parsed.get("strengths") or "").strip()
    i = (parsed.get("improvement_areas") or "").strip()
    r = (parsed.get("recommendations") or "").strip()
    # Require each section to have a reasonable length (avoid single fragments)
    if len(s) < 60 or len(i) < 60 or len(r) < 60:
        return True
    return False


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

    # Fallback: if model didn't use section labels, try to split the output
    if not (out["strengths"] or out["improvement_areas"] or out["recommendations"]):
        # Split by sentences and distribute
        sentences = [s.strip() for s in text.split('.') if s.strip()]
        if len(sentences) >= 3:
            out["strengths"] = sentences[0] + '.'
            out["improvement_areas"] = sentences[1] + '.'
            out["recommendations"] = ' '.join(sentences[2:]) + '.'
        elif sentences:
            out["recommendations"] = text.strip()
    
    # Final safety: ensure at least one field has meaningful content
    if not any(out.values()):
        out["strengths"] = "The teacher demonstrates professional competency in the assessed teaching areas with positive student engagement."
        out["improvement_areas"] = "Continue refining instructional strategies and exploring innovative teaching methodologies to enhance student learning outcomes."
        out["recommendations"] = "Participate in professional development workshops focused on differentiated instruction and formative assessment techniques. Collaborate with peers to share best practices and receive constructive feedback on teaching methods."

    return out


@app.get("/health")
def health():
    return {"ok": True}


def _generate_template_based_feedback(req: GenerateRequest, comments: List[str]) -> Dict[str, str]:
    """Generate professional feedback based on ratings and comments using templates."""
    avg = req.averages

    style = (req.style or "standard").strip().lower()
    if style not in {"short", "standard", "detailed"}:
        style = "standard"

    def sentence_limits() -> tuple[int, int]:
        if style == "short":
            return 2, 2
        if style == "detailed":
            return 5, 6
        return 3, 4  # standard

    min_s, max_s = sentence_limits()

    def pick(pool: List[str]) -> str:
        return random.choice(pool) if pool else ""

    def to_sentences(parts: List[str]) -> str:
        # Keep 1..N sentences, join naturally.
        out = [p.strip().rstrip(".") + "." for p in parts if p and p.strip()]
        if not out:
            return ""
        # clamp sentence count
        return " ".join(out[: max_s])

    def lowest_domain() -> str:
        scores = {
            "communication": float(avg.communications or 0),
            "management": float(avg.management or 0),
            "assessment": float(avg.assessment or 0),
        }
        return min(scores, key=scores.get)

    low = lowest_domain()

    # If multiple areas are below target, still emphasize the lowest domain first.
    domain_label = {
        "communication": "communication and student engagement",
        "management": "classroom management and learning environment",
        "assessment": "assessment practices and feedback",
    }.get(low, low)
    
    # Analyze ratings to identify strengths and areas for improvement
    strengths_list = []
    improvements_list = []
    
    if avg.communications >= 4.5:
        strengths_list.append("demonstrates exceptional communication skills with clear articulation and effective student engagement")
    elif avg.communications >= 4.0:
        strengths_list.append("shows strong communication abilities in delivering lesson content")
    elif avg.communications < 3.0:
        improvements_list.append("communication and student engagement strategies")
    
    if avg.management >= 4.5:
        strengths_list.append("exhibits excellent classroom management with well-organized and structured lessons")
    elif avg.management >= 4.0:
        strengths_list.append("maintains good classroom control and learning environment")
    elif avg.management < 3.0:
        improvements_list.append("classroom management and organizational techniques")
    
    if avg.assessment >= 4.5:
        strengths_list.append("implements comprehensive assessment methods that effectively measure student learning outcomes")
    elif avg.assessment >= 4.0:
        strengths_list.append("uses appropriate assessment strategies to evaluate student progress")
    elif avg.assessment < 3.0:
        improvements_list.append("assessment techniques and formative evaluation methods")
    
    # Extract specific comments for context
    comment_highlights = []
    for comment in comments:
        if "comment:" in comment.lower():
            parts = comment.split("comment:", 1)
            if len(parts) > 1 and parts[1].strip():
                comment_highlights.append(parts[1].strip())
    
    teacher = req.faculty_name or "The teacher"

    # Wording variety pools
    strengths_openers = [
        f"{teacher} demonstrates strong professional competency during the observation",
        f"{teacher} shows a commendable level of instructional capability",
        f"{teacher} exhibits positive teaching practices that support learner progress",
    ]

    strengths_closers = [
        "Overall performance reflects consistent preparation and purposeful delivery",
        "The lesson delivery reflects a clear intention to support student learning",
        "These practices contribute to a productive and supportive learning environment",
    ]

    # Build STRENGTHS paragraph (2–6 sentences depending on style)
    s_parts: List[str] = []
    s_parts.append(pick(strengths_openers))
    if strengths_list:
        # turn key strengths into sentences
        if len(strengths_list) == 1:
            s_parts.append(f"The teacher {strengths_list[0]}")
        else:
            s_parts.append(f"The teacher {strengths_list[0]}")
            s_parts.append(f"In addition, the teacher {strengths_list[1]}")
    else:
        s_parts.append("The observed indicators suggest reliable performance across the evaluated domains")
    if comment_highlights and style != "short":
        s_parts.append("Classroom evidence indicates that the teacher applies strategies aligned with effective instruction")
    if style != "short":
        s_parts.append(pick(strengths_closers))
    strengths = to_sentences(s_parts)
    
    # Domain-focused improvement guidance (target lowest domain more heavily)
    domain_focus = {
        "communication": [
            "increasing interactive questioning and checking for understanding",
            "strengthening clarity of instructions and pacing of explanations",
            "using more varied engagement strategies to sustain learner participation",
        ],
        "management": [
            "strengthening classroom routines and transitions to maximize learning time",
            "using proactive behavior supports and clear expectations",
            "improving activity structure to keep learners consistently on-task",
        ],
        "assessment": [
            "using more frequent formative checks aligned to lesson objectives",
            "strengthening feedback practices so learners know how to improve",
            "aligning assessment tasks more closely with targeted competencies",
        ],
    }

    i_parts: List[str] = []
    i_parts.append("To further strengthen teaching effectiveness, the teacher may focus on")

    if improvements_list:
        i_parts.append(", ".join(improvements_list[:2]))
    else:
        i_parts.append(pick(domain_focus.get(low, [])))

    if style != "short":
        i_parts.append("This will help ensure consistent learner engagement and stronger instructional impact")
        if style == "detailed":
            i_parts.append("Consider reviewing lesson flow and identifying points where learners may need additional scaffolding")
    improvements = to_sentences(i_parts)
    
    rec_actions = {
        "communication": [
            "plan short engagement checkpoints (e.g., cold-calling, think-pair-share, exit questions)",
            "use questioning techniques that move from recall to higher-order thinking",
            "practice clarity and pacing through microteaching and peer feedback",
        ],
        "management": [
            "establish clear routines for group work and transitions",
            "use seating/space arrangements that support visibility and movement",
            "apply positive behavior strategies and consistent reinforcement",
        ],
        "assessment": [
            "design quick formative assessments aligned to the lesson objectives",
            "use rubrics or success criteria so learners know what quality work looks like",
            "provide timely feedback and allow opportunities for revision",
        ],
    }

    r_parts: List[str] = []
    r_parts.append("It is recommended that the teacher")
    r_parts.append(pick(rec_actions.get(low, [])))
    if style != "short":
        r_parts.append("engage in peer observation or coaching sessions to reflect on practice and refine strategies")
    if style == "detailed":
        r_parts.append("set 1–2 measurable goals for the next cycle and review progress using brief evidence (student work, quick checks, and observation notes)")
    recommendations = to_sentences(r_parts)
    
    # Ensure each section meets minimum length expectations; if not, pad with a professional sentence.
    def ensure_min(text: str, extra: str) -> str:
        if len((text or "").strip()) >= 80 or style == "short":
            return text
        return (text.rstrip() + " " + extra).strip()

    strengths = ensure_min(strengths, "The overall performance indicates readiness to meet the expected standards for effective instruction.")
    improvements = ensure_min(improvements, "Sustained reflection and targeted practice will support continuous improvement.")
    recommendations = ensure_min(recommendations, "These steps will help strengthen consistency across the evaluated domains.")

    return {
        "strengths": strengths,
        "improvement_areas": improvements,
        "recommendations": recommendations,
    }


@app.post("/generate", response_model=GenerateResponse)
def generate(req: GenerateRequest):
    # Use AI model for generation
    sbert, tok, model = _load_models()
    
    texts = _flatten_comments(req)
    top = _retrieve_top_k(sbert, texts, k=10)

    # If the user doesn't want/need written comments, generate from ratings only.
    # In practice, most rows will have no comment text (and we don't want the model to echo numbers).
    has_real_comments = any(("comment=" in t or "comment:" in t) for t in texts)
    if not has_real_comments:
        prompt = _build_ratings_only_prompt(req)
    else:
        prompt = _build_prompt(req, top)
    
    # Debug logging
    print("\n=== GENERATION DEBUG ===")
    print(f"Input texts count: {len(texts)}")
    print(f"Top retrieved: {len(top)}")
    print(f"Averages: comm={req.averages.communications}, mgmt={req.averages.management}, assess={req.averages.assessment}, overall={req.averages.overall}")
    print(f"Prompt: {prompt[:200]}...")
    
    # Generate with AI model
    raw = _generate_text(tok, model, prompt)

    # Retry with simpler prompt if the first output is low-quality
    if _looks_like_bad_generation(raw):
        retry_prompt = _build_retry_prompt(req, top)
        print("Low-quality generation detected; retrying with simplified prompt")
        raw = _generate_text(tok, model, retry_prompt)
    
    print(f"Raw model output: {raw!r}")
    
    parsed = _parse_sections(raw)
    
    print(f"Parsed strengths: {parsed['strengths'][:100]}...")
    print(f"Parsed improvements: {parsed['improvement_areas'][:100]}...")
    print(f"Parsed recommendations: {parsed['recommendations'][:100]}...")
    
    # Fallback to template if AI generation failed or returned fragments
    combined = "\n".join([parsed.get("strengths", ""), parsed.get("improvement_areas", ""), parsed.get("recommendations", "")])
    if _looks_like_bad_generation(combined) or not any(parsed.values()) or _parsed_missing_or_too_short(parsed):
        print("AI generation insufficient, using template fallback")
        parsed = _generate_template_based_feedback(req, texts)
    
    print("======================\n")

    return GenerateResponse(
        strengths=parsed["strengths"],
        improvement_areas=parsed["improvement_areas"],
        recommendations=parsed["recommendations"],
        debug=None,
    )
