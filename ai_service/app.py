import hashlib
import json
import os
import pathlib
import random
import re
import traceback
from datetime import datetime
from functools import lru_cache
from threading import Lock
from typing import Any, Dict, List, Optional, Tuple, Union

import numpy as np
from fastapi import FastAPI, HTTPException, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

try:
    from .feedback_retrieval_system import FeedbackRetrievalSystem, build_mysql_seed_system
except ImportError:
    from feedback_retrieval_system import FeedbackRetrievalSystem, build_mysql_seed_system


app = FastAPI(title="ADCES AI Service", version="2.0.0")


@app.get("/health")
async def health():
    return {"ok": True}


@app.post("/backfill_embeddings")
async def backfill_embeddings():
    """Generate SBERT embeddings for all seed rows with empty embedding_vector."""
    import pymysql as _pymysql
    config = _parse_php_db_config()
    conn = _pymysql.connect(
        host=config["host"], user=config["user"],
        password=config["password"], database=config["database"],
        charset="utf8mb4", cursorclass=_pymysql.cursors.DictCursor,
        use_unicode=True,
    )
    with conn.cursor() as cur:
        cur.execute(
            "SELECT id, evaluation_comment, LENGTH(embedding_vector) as ev_len "
            "FROM ai_feedback_templates WHERE is_active = 1"
        )
        rows = cur.fetchall()

    needs_update = [
        r for r in rows
        if not r["ev_len"] or int(r["ev_len"]) < 10
    ]
    if not needs_update:
        conn.close()
        return {"ok": True, "updated": 0, "total": len(rows), "message": "All rows already have embeddings."}

    retrieval = _load_feedback_retrieval_system()
    updated = 0
    errors = 0
    with conn.cursor() as cur:
        for row in needs_update:
            try:
                comment = row["evaluation_comment"]
                if isinstance(comment, bytes):
                    comment = comment.decode("utf-8", errors="replace")
                vec = retrieval.encode_text(str(comment))
                serialized = FeedbackRetrievalSystem.serialize_embedding(vec)
                cur.execute(
                    "UPDATE ai_feedback_templates SET embedding_vector = %s WHERE id = %s",
                    (serialized, row["id"]),
                )
                updated += 1
            except Exception as e:
                errors += 1
                print(f"Error on row {row['id']}: {e}")
    conn.commit()
    conn.close()

    # Clear cache so service reloads fresh embeddings
    if EMBEDDINGS_CACHE_PATH.exists():
        EMBEDDINGS_CACHE_PATH.unlink()

    return {"ok": True, "updated": updated, "errors": errors, "total": len(rows)}


@app.exception_handler(RequestValidationError)
async def _validation_exception_handler(request: Request, exc: RequestValidationError):
    try:
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
    try:
        body = await request.json()
    except Exception:
        body = None
    return {"ok": True, "received": body}


BASE_PATH = pathlib.Path(__file__).parent
ROOT_PATH = BASE_PATH.resolve().parent.parent
PHP_DB_CONFIG_PATH = ROOT_PATH / "config" / "database.php"
FEEDBACK_PATH = BASE_PATH / "ai_feedback.jsonl"
EMBEDDINGS_CACHE_PATH = BASE_PATH / "comment_embeddings_cache.npz"
_feedback_lock = Lock()
_embedding_lock = Lock()

TOP_K_RETRIEVAL = 5
OUTPUT_RECOMMENDATIONS = 3
DEFAULT_SIMILARITY_THRESHOLD = 0.15

DOMAIN_ALIASES = {
    "communications": "Communication & instruction",
    "communication": "Communication & instruction",
    "instruction": "Communication & instruction",
    "management": "Classroom management & learning environment",
    "classroom_management": "Classroom management & learning environment",
    "assessment": "Assessment & feedback practices",
    "assessment_feedback": "Assessment & feedback practices",
    "feedback": "Assessment & feedback practices",
}

# Keywords matching seed evaluation_comment text for each domain — used to
# build SBERT queries that align closely with stored embeddings.
DOMAIN_QUERY_KEYWORDS: Dict[str, str] = {
    "Communication & instruction": (
        "voice projection and clarity, visual aids and teaching materials, "
        "non-verbal communication strategies, vocabulary and comprehension levels, "
        "fluency in the language of instruction"
    ),
    "Classroom management & learning environment": (
        "lesson planning and learning outcomes, classroom time management, "
        "student participation and engagement, instructional strategies, "
        "lesson introductions and closure, real-life examples, "
        "diverse learner needs, class discussions, connections between lessons, "
        "institutional core values, educational technology"
    ),
    "Assessment & feedback practices": (
        "questioning techniques and higher-order thinking, comprehension checks, "
        "formative assessment data and instruction, assessment alignment with competencies, "
        "rubrics and criteria, normative assessments before grading, "
        "feedback practices for student growth"
    ),
}



def _normalize_whitespace(text: str) -> str:
    return re.sub(r"\s+", " ", (text or "").strip())


def _safe_lower_label(text: str) -> str:
    return _normalize_whitespace(text).lower()


def _normalize_sentence(text: str) -> str:
    cleaned = _normalize_whitespace(text).strip(" .;,-")
    if not cleaned:
        return ""
    cleaned = cleaned[0].upper() + cleaned[1:]
    if cleaned[-1] not in ".!?":
        cleaned += "."
    return cleaned


def _stable_seed(*parts: Any) -> int:
    raw = "||".join(_normalize_whitespace(str(part)) for part in parts if part is not None)
    digest = hashlib.sha256(raw.encode("utf-8")).hexdigest()
    return int(digest[:16], 16)


def _score_band(x: float) -> str:
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


class RatingItem(BaseModel):
    rating: float = Field(..., ge=1, le=5)
    comment: Optional[str] = ""
    criterion_text: Optional[str] = ""


class IndicatorCommentItem(BaseModel):
    category: Optional[str] = ""
    criterion_index: Optional[int] = None
    criterion_text: Optional[str] = ""
    rating: Optional[float] = None
    comment: Optional[str] = ""


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
    ratings: Dict[str, Union[Dict[str, Any], List[Any]]] = Field(default_factory=dict)
    indicator_comments: List[IndicatorCommentItem] = Field(default_factory=list)
    comments_summary: Dict[str, List[str]] = Field(default_factory=dict)
    averages: Averages = Field(default_factory=Averages)
    strengths: Optional[str] = ""
    improvement_areas: Optional[str] = ""
    recommendations: Optional[str] = ""
    style: Optional[str] = "standard"
    regeneration_nonce: Optional[str] = ""
    previously_shown: Dict[str, List[str]] = Field(default_factory=dict)


class FeedbackItem(BaseModel):
    request: GenerateRequest
    generated_strengths: Optional[str] = None
    generated_improvement_areas: Optional[str] = None
    generated_recommendations: Optional[str] = None
    accurate: Optional[bool] = None
    corrected_strengths: Optional[str] = None
    corrected_improvement_areas: Optional[str] = None
    corrected_recommendations: Optional[str] = None
    comment: Optional[str] = None


class GenerateResponse(BaseModel):
    strengths: str
    improvement_areas: str
    recommendations: str
    strengths_options: Optional[List[str]] = None
    improvement_areas_options: Optional[List[str]] = None
    recommendations_options: Optional[List[str]] = None
    debug: Optional[Dict[str, Any]] = None


@lru_cache(maxsize=1)
def _load_feedback_retrieval_system() -> FeedbackRetrievalSystem:
    return build_mysql_seed_system(_parse_php_db_config())


@app.post("/feedback")
async def feedback(item: FeedbackItem):
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
        _feedback_lock.acquire()
        FEEDBACK_PATH.parent.mkdir(parents=True, exist_ok=True)
        with FEEDBACK_PATH.open("a", encoding="utf-8") as fh:
            fh.write(json.dumps(entry, ensure_ascii=False) + "\n")
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc))
    finally:
        try:
            _feedback_lock.release()
        except Exception:
            pass

    return {"ok": True}


def _coerce_rating_item(v: Any) -> Optional[RatingItem]:
    if v is None:
        return None
    if isinstance(v, RatingItem):
        return v
    if isinstance(v, (int, float, str)):
        try:
            return RatingItem(rating=float(v), comment="")
        except Exception:
            return None
    if isinstance(v, dict) and "rating" in v:
        try:
            return RatingItem(
                rating=float(v.get("rating")),
                comment=str(v.get("comment") or ""),
                criterion_text=str(v.get("criterion_text") or ""),
            )
        except Exception:
            return None
    return None


def _parse_php_db_config() -> Dict[str, str]:
    try:
        text = PHP_DB_CONFIG_PATH.read_text(encoding="utf-8")
    except Exception:
        return {
            "host": "localhost",
            "database": "ai_classroom_eval",
            "user": "root",
            "password": "",
        }

    def grab(key: str, default: str = "") -> str:
        marker = f'private ${key} = "'
        start = text.find(marker)
        if start == -1:
            return default
        start += len(marker)
        end = text.find('"', start)
        return text[start:end] if end != -1 else default

    return {
        "host": grab("host", "localhost"),
        "database": grab("db_name", "ai_classroom_eval"),
        "user": grab("username", "root"),
        "password": grab("password", ""),
    }


def _domain_scores(req: GenerateRequest) -> Dict[str, float]:
    avg = req.averages
    return {
        "Communication & instruction": float(avg.communications or 0),
        "Classroom management & learning environment": float(avg.management or 0),
        "Assessment & feedback practices": float(avg.assessment or 0),
    }


def _evaluation_signature(req: GenerateRequest) -> Dict[str, Any]:
    domains = _domain_scores(req)
    weakest = min(domains, key=domains.get) if domains else "Instructional practice"
    strongest = max(domains, key=domains.get) if domains else "Professional practice"
    overall_level = _score_band(req.averages.overall)
    return {
        "teacher": _normalize_whitespace(req.faculty_name or "") or "The teacher",
        "subject": _normalize_whitespace(req.subject_observed or "") or "the observed class",
        "observation_type": _normalize_whitespace(req.observation_type or "") or "Classroom observation",
        "department": _normalize_whitespace(req.department or ""),
        "overall_level": overall_level,
        "overall_numeric": float(req.averages.overall or 0),
        "weakest": weakest,
        "strongest": strongest,
        "domains": domains,
    }


def _band_label(score: float) -> str:
    return _score_band(float(score or 0)).lower()


def _dedupe_preserve_order(items: List[str]) -> List[str]:
    seen = set()
    out: List[str] = []
    for item in items:
        norm = _safe_lower_label(item)
        if not norm or norm in seen:
            continue
        seen.add(norm)
        out.append(_normalize_whitespace(item))
    return out


def _comment_fingerprint(text: str) -> str:

    normalized = re.sub(r"[^a-z0-9\s]", "", _safe_lower_label(text))
    return re.sub(r"\s+", " ", normalized).strip()


def _extract_action_focus(text: str) -> str:
    lowered = _safe_lower_label(text)
    tokens = [
        "assessment",
        "feedback",
        "questioning",
        "participation",
        "engagement",
        "transitions",
        "routines",
        "clarity",
        "directions",
        "checks for understanding",
        "classroom management",
    ]
    for token in tokens:
        if token in lowered:
            return token
    words = [w for w in re.findall(r"[a-zA-Z]+", lowered) if len(w) > 4]
    return words[0] if words else lowered[:40]


def _normalize_domain_name(key: str) -> str:
    return DOMAIN_ALIASES.get(_safe_lower_label(key), _normalize_whitespace(key) or "General")


def _flatten_comments(req: GenerateRequest) -> List[Dict[str, Any]]:
    comment_rows: List[Dict[str, Any]] = []
    seen_explicit = set()

    for idx, item in enumerate(req.indicator_comments or [], 1):
        comment = _normalize_whitespace(item.comment or "")
        if not comment:
            continue
        category_key = item.category or "General"
        domain = _normalize_domain_name(category_key)
        criterion_text = _normalize_whitespace(item.criterion_text or "")
        row = {
            "domain": domain,
            "rating": float(item.rating or 0),
            "comment": comment,
            "index": int(item.criterion_index or idx),
            "criterion_text": criterion_text,
            "category": _normalize_whitespace(category_key) or "General",
            "source": "indicator_comments",
        }
        key = (
            _safe_lower_label(row["category"]),
            int(row["index"]),
            _comment_fingerprint(comment),
            _comment_fingerprint(criterion_text),
        )
        seen_explicit.add(key)
        comment_rows.append(row)

    ratings = req.ratings or {}
    for category, items in ratings.items():
        domain = _normalize_domain_name(category)
        if isinstance(items, dict):
            iterable = list(items.values())
        elif isinstance(items, list):
            iterable = items
        else:
            iterable = [items]

        for idx, raw in enumerate(iterable, 1):
            item = _coerce_rating_item(raw)
            if not item:
                continue
            comment = _normalize_whitespace(item.comment or "")
            criterion_text = _normalize_whitespace(item.criterion_text or "")
            key = (
                _safe_lower_label(category),
                idx,
                _comment_fingerprint(comment),
                _comment_fingerprint(criterion_text),
            )
            if comment and key in seen_explicit:
                continue
            comment_rows.append(
                {
                    "domain": domain,
                    "rating": float(item.rating),
                    "comment": comment,
                    "index": idx,
                    "criterion_text": criterion_text,
                    "category": _normalize_whitespace(category) or "General",
                    "source": "ratings",
                }
            )
    return comment_rows


def _subject_department_context(req: GenerateRequest) -> Dict[str, str]:
    subject = _normalize_whitespace(req.subject_observed or "")
    department = _normalize_whitespace(req.department or "")
    observation_type = _normalize_whitespace(req.observation_type or "")
    lesson_context = subject
    return {
        "subject": subject,
        "lesson_context": lesson_context,
        "department": department,
        "observation_type": observation_type,
        "subject_line": f"Subject/Time of Observation: {lesson_context}." if lesson_context else "",
        "department_line": f"Department/program: {department}." if department else "",
        "context_line": _normalize_whitespace(" ".join([
            f"Subject/Time of Observation: {lesson_context}." if lesson_context else "",
            f"Department/program: {department}." if department else "",
            f"Observation type: {observation_type}." if observation_type else "",
        ])),
    }


def _natural_criterion_reference(text: str) -> str:
    cleaned = _normalize_whitespace(text)
    if not cleaned:
        return ""

    cleaned = re.sub(r"^[Tt]he teacher\s+", "", cleaned)
    cleaned = re.sub(r"^[Uu]ses\s+", "using ", cleaned)
    cleaned = re.sub(r"^[Dd]emonstrates\s+", "demonstrating ", cleaned)
    cleaned = re.sub(r"^[Ee]xplains\s+", "explaining ", cleaned)
    cleaned = re.sub(r"^[Aa]dapts\s+", "adapting ", cleaned)
    cleaned = re.sub(r"^[Ee]ncourages\s+", "encouraging ", cleaned)
    cleaned = re.sub(r"^[Dd]esigns\s+", "designing ", cleaned)
    cleaned = re.sub(r"^[Ii]ntegrate\s+", "integrating ", cleaned)
    cleaned = re.sub(r"^[Ii]ntegrates\s+", "integrating ", cleaned)
    cleaned = re.sub(r"^[Ff]ocuses\s+", "focusing on ", cleaned)
    cleaned = re.sub(r"^[Rr]ecall and connects\s+", "connecting ", cleaned)
    cleaned = re.sub(r"^[Tt]he\s+", "", cleaned)
    cleaned = cleaned.strip(" .;,:-")
    return _normalize_clause_fragment(cleaned)


def _criterion_phrase(item: Dict[str, Any]) -> str:
    criterion_text = _normalize_whitespace(item.get("criterion_text") or "")
    if not criterion_text:
        return ""
    phrase = _natural_criterion_reference(criterion_text)
    if not phrase:
        return ""
    return phrase


def _criterion_phrases_for_field(req: GenerateRequest, comments: List[Dict[str, Any]], field_name: str) -> List[str]:
    sig = _evaluation_signature(req)
    prioritized = _prioritize_comments(req, comments)
    target_domain = sig["strongest"] if field_name == "strengths" else sig["weakest"]

    candidates = [item for item in prioritized if item.get("comment") and item.get("domain") == target_domain]
    if not candidates:
        candidates = [item for item in prioritized if item.get("comment")]

    phrases: List[str] = []
    seen = set()
    for item in candidates:
        phrase = _criterion_phrase(item)
        key = _comment_fingerprint(phrase)
        if not phrase or not key or key in seen:
            continue
        seen.add(key)
        phrases.append(phrase)
        if len(phrases) >= 2:
            break
    return phrases


def _merge_comment_with_criterion(comment: str, criterion_phrase: str, field_name: str) -> str:
    comment = _normalize_whitespace(comment)
    criterion_phrase = _normalize_whitespace(criterion_phrase)
    if not comment:
        return ""
    summarized_comment = _summarize_support_comment(comment, field_name)
    if not criterion_phrase:
        return summarized_comment

    lower_comment = summarized_comment.lower()
    lower_phrase = criterion_phrase.lower()
    if lower_phrase in lower_comment:
        return summarized_comment

    if field_name == "strengths":
        return _normalize_whitespace(f"{summarized_comment}, especially in {criterion_phrase}")
    if field_name == "areas_for_improvement":
        return _normalize_whitespace(f"{summarized_comment}, particularly in {criterion_phrase}")
    return _normalize_whitespace(f"{summarized_comment}, with follow-through on {criterion_phrase}")


def _summarize_support_comment(comment: str, field_name: str) -> str:
    normalized = _normalize_whitespace(comment)
    if not normalized:
        return ""

    lowered = normalized.lower()
    replacements = [
        (r"\bthe evaluator noted that\b", ""),
        (r"\bneeded more\b", "would benefit from more"),
        (r"\bnot always connected to\b", "could be aligned more closely with"),
        (r"\bwere clear, but\b", "were clear, while"),
        (r"\bmore subject-specific\b", "clearer subject-specific"),
        (r"\bbefore moving to the next activity\b", "during transitions to the next activity"),
    ]

    updated = normalized
    for pattern, replacement in replacements:
        updated = re.sub(pattern, replacement, updated, flags=re.IGNORECASE)

    clause = _normalize_clause_fragment(updated)
    if not clause:
        return normalized

    if field_name == "strengths":
        if re.search(r"\b(clear|effective|well|strong|audible|engaging|appropriate)\b", lowered):
            return _normalize_sentence(f"classroom evidence reflects that {clause}")
        return _normalize_sentence(f"classroom evidence indicates a positive practice in which {clause}")

    if field_name == "areas_for_improvement":
        return _normalize_sentence(f"classroom evidence suggests that {clause}")

    return _normalize_sentence(f"a focused next step is to address how {clause}")


def _comment_priority_value(item: Dict[str, Any], subject: str, department: str) -> float:
    score = 0.0
    rating = float(item.get("rating") or 0)
    domain = _safe_lower_label(item.get("domain") or "")
    category = _safe_lower_label(item.get("category") or "")
    comment = _safe_lower_label(item.get("comment") or "")
    criterion_text = _safe_lower_label(item.get("criterion_text") or "")
    combined = f"{comment} {criterion_text}".strip()

    if item.get("source") == "indicator_comments":
        score += 4.0
    if comment:
        score += min(len(comment.split()) * 0.08, 1.2)
    if criterion_text:
        score += min(len(criterion_text.split()) * 0.04, 0.5)

    if rating > 0:
        if rating <= 2:
            score += 2.2
        elif rating < 3.5:
            score += 1.3
        elif rating >= 4:
            score += 0.7

    subject_tokens = [token for token in re.findall(r"[a-zA-Z0-9]+", subject.lower()) if len(token) > 2]
    department_tokens = [token for token in re.findall(r"[a-zA-Z0-9]+", department.lower()) if len(token) > 2]
    if subject_tokens and any(token in combined for token in subject_tokens):
        score += 2.4
    if department_tokens and any(token in combined for token in department_tokens):
        score += 1.2

    focus_tokens = [
        "example", "examples", "activity", "activities", "question", "questions", "discussion",
        "engagement", "participation", "feedback", "assessment", "strategy", "strategies",
        "objective", "objectives", "real-life", "real life", "local"
    ]
    score += sum(0.18 for token in focus_tokens if token in combined)

    if "assessment" in domain or "assessment" in category:
        score += 0.25
    return score


def _prioritize_comments(req: GenerateRequest, comments: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    if not comments:
        return []
    context = _subject_department_context(req)
    subject = context["subject"]
    department = context["department"]
    ranked = []
    for item in comments:
        enriched = dict(item)
        enriched["priority"] = round(_comment_priority_value(enriched, subject, department), 4)
        ranked.append(enriched)
    ranked.sort(key=lambda item: (-float(item.get("priority") or 0), float(item.get("rating") or 0), int(item.get("index") or 0)))
    return ranked


def _most_problematic_comment(req: GenerateRequest, comments: List[Dict[str, Any]]) -> Optional[Dict[str, Any]]:
    prioritized = _prioritize_comments(req, comments)
    if not prioritized:
        return None

    for item in prioritized:
        if item.get("comment") and float(item.get("rating") or 0) > 0:
            return item
    return prioritized[0] if prioritized else None


def _build_top_issue_recommendation_sentence(req: GenerateRequest, comments: List[Dict[str, Any]]) -> str:
    top_item = _most_problematic_comment(req, comments)
    if not top_item:
        return ""

    context = _subject_department_context(req)
    lesson_context = context["lesson_context"] or "the next observed lesson"
    criterion_phrase = _criterion_phrase(top_item)
    comment = _normalize_whitespace(top_item.get("comment") or "")
    domain = _normalize_whitespace(top_item.get("domain") or "this area")
    rating = float(top_item.get("rating") or 0)

    if criterion_phrase and comment:
        support_summary = _summarize_support_comment(comment, "recommendations")
        return _normalize_sentence(
            f"For the next {lesson_context} lesson, prioritize {criterion_phrase} first. {support_summary}"
        )

    if comment:
        support_summary = _summarize_support_comment(comment, "recommendations")
        return _normalize_sentence(
            f"For the next {lesson_context} lesson, address the lowest-rated point in {domain.lower()} first. {support_summary}"
        )

    if criterion_phrase:
        return _normalize_sentence(
            f"For the next {lesson_context} lesson, prioritize {criterion_phrase} first because it received one of the lowest ratings"
        )

    if rating > 0:
        return _normalize_sentence(
            f"For the next {lesson_context} lesson, address the most problematic observed criterion first because it received a rating of {rating:.0f}"
        )

    return _normalize_sentence(f"For the next {lesson_context} lesson, address the most problematic observed criterion first")


def _compose_query_text(req: GenerateRequest, comments: List[Dict[str, Any]]) -> str:
    sig = _evaluation_signature(req)
    context = _subject_department_context(req)
    prioritized = _prioritize_comments(req, comments)
    fragments = [
        context["subject_line"],
        context["department_line"],
        sig["subject"],
        sig["observation_type"],
        f"priority area {sig['weakest']}",
        f"strong area {sig['strongest']}",
        f"overall {sig['overall_level']}",
    ]
    for item in prioritized[:6]:
        if item["comment"]:
            criterion = _normalize_whitespace(item.get("criterion_text") or "")
            if criterion:
                fragments.append(f"{item['domain']} | {criterion}: {item['comment']}")
            else:
                fragments.append(f"{item['domain']}: {item['comment']}")
    if not any(item["comment"] for item in comments):
        for domain, score in sig["domains"].items():
            fragments.append(f"{domain} rating {score:.1f}")
    return _normalize_whitespace(". ".join(fragments))


@lru_cache(maxsize=1)
def _load_sbert():
    from sentence_transformers import SentenceTransformer

    model_name = os.getenv("SBERT_MODEL", "sentence-transformers/all-MiniLM-L6-v2")
    return SentenceTransformer(model_name)


def _build_dataset_entries() -> List[Dict[str, Any]]:
    entries: List[Dict[str, Any]] = []

    def add_entry(text: str, category: str, source: str, meta: Optional[Dict[str, Any]] = None) -> None:
        normalized = _normalize_sentence(text)
        if not normalized:
            return
        item = {
            "text": normalized,
            "category": _normalize_whitespace(category) or "General",
            "source": source,
        }
        if meta:
            item.update(meta)
        entries.append(item)

    retrieval_system = _load_feedback_retrieval_system()
    field_map = {
        "strengths": "strengths",
        "areas_for_improvement": "areas_for_improvement",
        "recommendations": "recommendations",
    }
    for field_name, template_field in field_map.items():
        try:
            templates = retrieval_system.fetch_templates(field_name)
        except Exception:
            templates = []
        for row in templates:
            add_entry(
                row.get("feedback_text") or row.get("evaluation_comment") or "",
                template_field,
                row.get("source") or f"mysql:{field_name}",
                {
                    "kind": f"mysql_template:{field_name}",
                    "feedback_text": _normalize_sentence(row.get("feedback_text") or ""),
                    "template_field": field_name,
                },
            )

    deduped: List[Dict[str, Any]] = []
    seen = set()
    for item in entries:
        key = (_comment_fingerprint(item["text"]), _safe_lower_label(item["category"]))
        if not key[0] or key in seen:
            continue
        seen.add(key)
        deduped.append(item)
    return deduped


def _dataset_signature(entries: List[Dict[str, Any]]) -> str:
    serial = [
        {
            "text": item["text"],
            "category": item["category"],
            "source": item.get("source", ""),
        }
        for item in entries
    ]
    raw = json.dumps(serial, ensure_ascii=False, sort_keys=True)
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()


def _write_embedding_cache(entries: List[Dict[str, Any]], embeddings: np.ndarray) -> None:
    payload = {
        "signature": np.array([_dataset_signature(entries)], dtype=object),
        "texts": np.array([item["text"] for item in entries], dtype=object),
        "categories": np.array([item["category"] for item in entries], dtype=object),
        "sources": np.array([item.get("source", "") for item in entries], dtype=object),
        "embeddings": embeddings.astype(np.float32),
    }
    np.savez_compressed(EMBEDDINGS_CACHE_PATH, **payload)


def _load_embedding_cache(entries: List[Dict[str, Any]]) -> Optional[Tuple[List[Dict[str, Any]], np.ndarray]]:
    if not EMBEDDINGS_CACHE_PATH.exists():
        return None
    try:
        cached = np.load(EMBEDDINGS_CACHE_PATH, allow_pickle=True)
        signature = str(cached["signature"][0])
        if signature != _dataset_signature(entries):
            return None
        embeddings = np.array(cached["embeddings"], dtype=np.float32)
        if len(embeddings) != len(entries):
            return None
        return entries, embeddings
    except Exception:
        return None


def _ensure_dataset_embeddings() -> Tuple[List[Dict[str, Any]], np.ndarray]:
    entries = _build_dataset_entries()
    if not entries:
        return [], np.zeros((0, 384), dtype=np.float32)

    cached = _load_embedding_cache(entries)
    if cached is not None:
        return cached

    try:
        _embedding_lock.acquire()
        cached = _load_embedding_cache(entries)
        if cached is not None:
            return cached
        model = _load_sbert()
        embeddings = model.encode(
            [item["text"] for item in entries],
            convert_to_numpy=True,
            normalize_embeddings=True,
        )
        embeddings = np.array(embeddings, dtype=np.float32)
        _write_embedding_cache(entries, embeddings)
        return entries, embeddings
    finally:
        try:
            _embedding_lock.release()
        except Exception:
            pass


def _mysql_source_summary(items: List[Dict[str, Any]]) -> Dict[str, int]:
    out: Dict[str, int] = {}
    for item in items:
        source = _normalize_whitespace(item.get("source") or "unknown") or "unknown"
        out[source] = out.get(source, 0) + 1
    return out


def _cosine_search(query_embedding: np.ndarray, embeddings: np.ndarray) -> np.ndarray:
    if embeddings.size == 0:
        return np.array([], dtype=np.float32)
    query_vec = np.array(query_embedding, dtype=np.float32)
    if query_vec.ndim > 1:
        query_vec = query_vec[0]
    query_vec = query_vec / (np.linalg.norm(query_vec) + 1e-12)
    return np.matmul(embeddings, query_vec)


def _retrieve_top_comments(req: GenerateRequest, comments: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    dataset, embeddings = _ensure_dataset_embeddings()
    if not dataset:
        return []
    query_text = _compose_query_text(req, comments)
    model = _load_sbert()
    query_embedding = model.encode([query_text], convert_to_numpy=True, normalize_embeddings=True)
    scores = _cosine_search(query_embedding, embeddings)

    ranked_indices = np.argsort(scores)[::-1]
    selected: List[Dict[str, Any]] = []
    seen = set()

    for idx in ranked_indices.tolist():
        item = dict(dataset[idx])
        similarity = float(scores[idx])
        if item.get("category") in {"strengths", "areas_for_improvement", "recommendations"}:
            similarity += 0.03
        fingerprint = _comment_fingerprint(item["text"])
        if not fingerprint or fingerprint in seen:
            continue
        if similarity < DEFAULT_SIMILARITY_THRESHOLD and selected:
            continue
        seen.add(fingerprint)
        item["similarity"] = round(float(similarity), 4)
        selected.append(item)
        if len(selected) >= TOP_K_RETRIEVAL:
            break

    if len(selected) < TOP_K_RETRIEVAL:
        for idx in ranked_indices.tolist():
            item = dict(dataset[idx])
            fingerprint = _comment_fingerprint(item["text"])
            if not fingerprint or fingerprint in seen:
                continue
            seen.add(fingerprint)
            item["similarity"] = round(float(scores[idx]), 4)
            selected.append(item)
            if len(selected) >= TOP_K_RETRIEVAL:
                break
    return selected[:TOP_K_RETRIEVAL]


def _relevant_comments_for_field(req: GenerateRequest, comments: List[Dict[str, Any]], field_name: str) -> List[str]:
    sig = _evaluation_signature(req)
    target_domain = sig["strongest"] if field_name == "strengths" else sig["weakest"]
    prioritized = _prioritize_comments(req, comments)
    field_comments = [item for item in prioritized if item["comment"] and item["domain"] == target_domain]
    if not field_comments:
        field_comments = [item for item in prioritized if item["comment"]]

    if field_name == "strengths":
        field_comments.sort(key=lambda item: (-float(item.get("priority") or 0), -float(item["rating"]), item["index"]))
    else:
        field_comments.sort(key=lambda item: (-float(item.get("priority") or 0), float(item["rating"]), item["index"]))

    return _dedupe_preserve_order([item["comment"] for item in field_comments])[:3]


def _compose_field_query(req: GenerateRequest, comments: List[Dict[str, Any]], field_name: str) -> str:
    """Build a clean, domain-focused SBERT query for semantic matching against seed evaluation_comments."""
    sig = _evaluation_signature(req)
    relevant = _relevant_comments_for_field(req, comments, field_name)
    strongest = sig["strongest"]
    weakest = sig["weakest"]

    # Pick the target domain and get matching keywords
    if field_name == "strengths":
        domain = strongest
    else:
        domain = weakest

    keywords = DOMAIN_QUERY_KEYWORDS.get(domain, domain.lower())

    if field_name == "strengths":
        prompt = f"Teacher demonstrates strong {keywords}."
    elif field_name == "areas_for_improvement":
        prompt = f"Teacher needs improvement in {keywords}."
    else:
        prompt = f"Recommendations to improve {keywords}."

    # Add evaluator comments as evidence for better matching
    if relevant:
        evidence = "; ".join(relevant[:3])
        prompt = f"{prompt} {evidence}"

    return _normalize_whitespace(prompt)


def _field_target_category(req: GenerateRequest, field_name: str) -> str:
    return field_name


def _field_source_text(item: Dict[str, Any]) -> str:
    return _normalize_sentence(item.get("feedback_text") or item.get("text") or "")


def _pick_dataset_candidates(req: GenerateRequest, field_name: str, retrieved: List[Dict[str, Any]]) -> List[str]:
    target_category = _field_target_category(req, field_name)
    preferred = [item for item in retrieved if _normalize_whitespace(item.get("category") or "") == target_category]
    pool = preferred or retrieved
    output: List[str] = []
    seen = set()
    for item in pool:
        text = _field_source_text(item)
        if not _is_clean_candidate_text(text, field_name):
            continue
        key = _comment_fingerprint(text)
        if not key or key in seen:
            continue
        seen.add(key)
        output.append(text)
        if len(output) >= 3:
            break

    previously_shown = {
        _comment_fingerprint(_normalize_sentence(text))
        for text in (req.previously_shown or {}).get(field_name, [])
        if _normalize_whitespace(text)
    }
    unseen = [text for text in output if _comment_fingerprint(text) not in previously_shown]
    if unseen:
        return unseen + [text for text in output if text not in unseen]
    if output:
        return output

    fallback_output: List[str] = []
    fallback_seen = set()
    for item in pool:
        text = _field_source_text(item)
        key = _comment_fingerprint(text)
        if not key or key in fallback_seen:
            continue
        fallback_seen.add(key)
        fallback_output.append(text)
        if len(fallback_output) >= 3:
            break

    fallback_unseen = [text for text in fallback_output if _comment_fingerprint(text) not in previously_shown]
    if fallback_unseen:
        return fallback_unseen + [text for text in fallback_output if text not in fallback_unseen]
    return fallback_output


def _split_clauses(text: str) -> List[str]:
    raw = re.split(r'(?<=[.;:!?])\s+|\s+(?:and|but|while|because|so|which|that)\s+', _normalize_whitespace(text), flags=re.IGNORECASE)
    cleaned: List[str] = []
    seen = set()
    for part in raw:
        sentence = _normalize_sentence(part)
        key = _comment_fingerprint(sentence)
        if not key or key in seen or len(sentence.split()) < 4:
            continue
        seen.add(key)
        cleaned.append(sentence)
    return cleaned


def _is_clean_candidate_text(text: str, field_name: str) -> bool:
    normalized = _normalize_whitespace(text)
    if not normalized or len(normalized.split()) < 6:
        return False

    reject_patterns = [
        r"\bprofile(s)?\b",
        r"\brating pattern\b",
        r"\bevaluation profile\b",
        r"\bobserved practice\b",
        r"\btarget practice\b",
        r"\bperformance in the observed area\b",
        r"\bvisible in future observations\b",
        r"\bappropriate when\b",
        r"\bmore noticeable during the observation\b",
        r"\btargeted refinements needed\b",
    ]
    if any(re.search(pattern, normalized, re.IGNORECASE) for pattern in reject_patterns):
        return False

    clauses = _split_clauses(normalized)
    if not clauses:
        return False

    return any(_normalize_clause_shape(clause, field_name) for clause in clauses)


def _is_clean_reconstruction_clause(text: str, field_name: str) -> bool:
    normalized = _normalize_clause_shape(text, field_name)
    if not normalized or len(normalized.split()) < 4 or _looks_meta_clause(normalized):
        return False

    reject_patterns = [
        r"\bthis\s+(?:is|was|can|should)\b",
        r"\bperformance should\b",
        r"\buse this\b",
        r"\bstill needs more consistent classroom evidence\b",
    ]
    return not any(re.search(pattern, normalized, re.IGNORECASE) for pattern in reject_patterns)


def _looks_meta_clause(text: str) -> bool:
    normalized = _normalize_clause_fragment(text)
    if not normalized:
        return True
    meta_patterns = [
        r"\bwhat stood out during the class was\b",
        r"\bfrom the evaluator'?s notes\b",
        r"\bfrom a classroom perspective\b",
        r"\bthe teacher'?s practice indicates\b",
        r"\bthis area'?s practice indicates\b",
        r"\bthis pattern\b",
        r"\brating pattern\b",
        r"\bevaluation profile\b",
        r"\bobserved practice\b",
        r"\btarget practice\b",
        r"\bclassroom practice\b",
        r"\bperformance in the observed area\b",
        r"\bvisible in future observations\b",
        r"\bappropriate when\b",
        r"\bprofile(s)?\b",
        r"\brating\b",
        r"\bperformance is\b",
        r"\bobservation period\b",
    ]
    return any(re.search(pattern, normalized, re.IGNORECASE) for pattern in meta_patterns)


def _normalize_clause_shape(text: str, field_name: str) -> str:
    normalized = _normalize_clause_fragment(text)
    if not normalized or _looks_meta_clause(normalized):
        return ""

    normalized = re.sub(r"\bthis made the teaching practice more noticeable during the observation\b", "", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bmore noticeable during the observation\b", "", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bthe observed practice is consistently visible\b", "", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\btargeted refinements needed to reach an excellent level\b", "", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bthis added stronger evidence of intentional\b", "", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bshould now be sustained\b", "", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bwhat matters most here is not adding more activities\b", "", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bthis helped show a clearer link between instruction, participation\b", "", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bneeds to strengthen [^.]*\b(contributed|helped)\b[^.]*", "", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bwhat stood out during the class was\b", "", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bfrom the evaluator'?s notes\b", "", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bfrom a classroom perspective\b", "", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bthe teacher'?s practice indicates\b", "", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bthis area'?s practice indicates\b", "", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\s{2,}", " ", normalized).strip(" ,.;:-")

    if not normalized or _looks_meta_clause(normalized):
        return ""

    clause_type = _classify_clause(normalized, field_name)

    if field_name == "strengths":
        verb_map = {
            "adjust": "adjusts",
            "use": "uses",
            "maintain": "maintains",
            "monitor": "monitors",
            "organize": "organizes",
            "support": "supports",
            "check": "checks",
            "explain": "explains",
        }
        for base, inflected in verb_map.items():
            normalized = re.sub(rf"^{base}\b", inflected, normalized, flags=re.IGNORECASE)
        if re.match(r"^(adjusts|uses|maintains|monitors|organizes|supports|checks|explains)\b", normalized, re.IGNORECASE):
            normalized = f"the teacher {normalized}"
    elif field_name == "areas_for_improvement":
        if clause_type == "evidence" and re.match(r"^(adjusts|uses|maintains|monitors|organizes|supports|checks|explains)\b", normalized, re.IGNORECASE):
            normalized = re.sub(r"^(adjusts|uses|maintains|monitors|organizes|supports|checks|explains)\b", r"needs to \1", normalized, count=1, flags=re.IGNORECASE)
        elif clause_type == "detail" and not re.search(r"\bneeds|should|improve|could be improved|benefit from\b", normalized, re.IGNORECASE):
            normalized = f"needs to strengthen {normalized}"
    elif field_name == "recommendations":
        if re.match(r"^(adjusts|uses|maintains|monitors|organizes|supports|checks|explains)\b", normalized, re.IGNORECASE):
            normalized = re.sub(r"^(adjusts|uses|maintains|monitors|organizes|supports|checks|explains)\b", r"\1 more consistently", normalized, count=1, flags=re.IGNORECASE)
        elif clause_type != "action" and not re.match(r"^(use|provide|plan|apply|build|prioritize|strengthen|restate|pause|review|monitor|establish)\b", normalized, re.IGNORECASE):
            normalized = f"use {normalized}"

    normalized = re.sub(r"\bthe teacher demonstrates\s+(adjust|use|maintain|monitor|organize|support|check|explain)\b", r"the teacher \1s", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bthe teacher reflects\s+(use|adjust|maintain|monitor|organize|support|check|explain)\b", r"the teacher \1s", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bthe teacher (demonstrates|shows|reflects|highlights)\s+(uses|adjusts|maintains|monitors|organizes|supports|checks|explains)\b", r"the teacher \2", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\b(use|apply|plan|provide|build|prioritize|strengthen)\s+use\b", r"\1", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bneeds to needs to\b", "needs to", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bneeds to strengthen this\b", "needs to strengthen classroom feedback", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bneeds to strengthen the teacher\b", "needs clearer teacher support in this area", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bneeds to strengthen [^.]*\b(contributed|helped)\b[^.]*", "needs to strengthen classroom feedback routines", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bthe teacher\s+(shows|reflects|highlights)\s+should\b", "the teacher should", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\s{2,}", " ", normalized).strip(" ,.;:-")

    return "" if _looks_meta_clause(normalized) else _normalize_clause_fragment(normalized)


def _classify_clause(text: str, field_name: str) -> str:
    normalized = _normalize_clause_fragment(text)
    if re.match(r"^(use|provide|plan|apply|build|prioritize|strengthen|restate|pause|review|monitor|establish)\b", normalized, re.IGNORECASE):
        return "action"
    if re.search(r"\b(needs|need to|should|improve|improved|limited|lacks|inconsistent|could be improved|benefit from)\b", normalized, re.IGNORECASE):
        return "need"
    if re.search(r"\b(demonstrates|shows|maintains|uses|explains|adjusts|monitors|organizes|supports|checks)\b", normalized, re.IGNORECASE):
        return "evidence"
    if _looks_meta_clause(normalized):
        return "meta"
    return "detail"


def _score_clause_for_field(text: str, field_name: str) -> Tuple[int, int]:
    clause_type = _classify_clause(text, field_name)
    type_weights = {
        "strengths": {"evidence": 4, "detail": 3, "action": 1, "need": 0, "meta": -2},
        "areas_for_improvement": {"need": 4, "detail": 3, "evidence": 2, "action": 1, "meta": -2},
        "recommendations": {"action": 4, "detail": 3, "need": 2, "evidence": 1, "meta": -2},
    }
    weight = type_weights.get(field_name, type_weights["recommendations"]).get(clause_type, 0)
    length_score = min(len(_normalize_clause_fragment(text).split()), 12)
    return weight, length_score


FIELD_OPENERS = {
    "strengths": [
        "Observed evidence shows that",
        "The evaluation indicates that",
        "Classroom evidence suggests that",
        "The observed lesson shows that",
    ],
    "areas_for_improvement": [
        "The evaluation points to an area that still needs stronger consistency:",
        "Classroom evidence suggests one clear improvement priority:",
        "The observation indicates that one area still needs refinement:",
        "The clearest opportunity for improvement is that",
    ],
    "recommendations": [
        "A practical next step is to",
        "To strengthen the next observation, it would help to",
        "One useful follow-through action is to",
        "A realistic recommendation is to",
    ],
}

FIELD_CONNECTORS = {
    "strengths": [
        "This was evident in the way",
        "This could be seen when",
        "This was noticeable because",
        "This was demonstrated as",
    ],
    "areas_for_improvement": [
        "This becomes more visible when",
        "This is noticeable because",
        "This concern appears when",
        "This can be seen when",
    ],
    "recommendations": [
        "This can help because",
        "This is worthwhile since",
        "This should improve results because",
        "This matters because",
    ],
}

FIELD_CLOSERS = {
    "strengths": [
        "This gives the lesson a more organized and purposeful flow.",
        "This helps sustain learner attention and lesson continuity.",
        "This supports clearer evidence of effective classroom practice.",
    ],
    "areas_for_improvement": [
        "Strengthening this area can make improvement more visible in future observations.",
        "A more consistent routine here can produce stronger classroom evidence.",
        "A focused adjustment in this area can improve lesson flow and learner response.",
    ],
    "recommendations": [
        "This can make the improvement easier to observe and sustain over time.",
        "This can support stronger follow-through and clearer learner response.",
        "This can help turn the target area into a more consistent classroom practice.",
    ],
}

SAFE_SYNONYM_BANK = {
    "clear": ["clear", "well-defined", "easy to follow"],
    "clearly": ["clearly", "in a well-structured way", "in a way learners could follow"],
    "consistent": ["consistent", "steady", "reliable"],
    "consistently": ["consistently", "reliably", "more steadily"],
    "focused": ["focused", "purposeful", "well-directed"],
    "timely": ["timely", "prompt", "well-timed"],
}

FIELD_STYLE_RULES = {
    "strengths": {
        "lead_verbs": ["demonstrates", "shows", "reflects", "highlights"],
        "tone_words": ["effective", "purposeful", "affirming", "strong"],
    },
    "areas_for_improvement": {
        "lead_verbs": ["indicates", "suggests", "shows", "reveals"],
        "tone_words": ["developing", "inconsistent", "emerging", "less visible"],
    },
    "recommendations": {
        "lead_verbs": ["prioritize", "strengthen", "apply", "build"],
        "tone_words": ["practical", "manageable", "actionable", "focused"],
    },
}

AWKWARD_LEAD_PATTERNS = [
    (re.compile(r"\bpractice is visible when\b", re.IGNORECASE), ""),
    (re.compile(r"\bpractice is visible as\b", re.IGNORECASE), ""),
    (re.compile(r"\bthis recommendation is appropriate when\b", re.IGNORECASE), ""),
    (re.compile(r"\bthis pattern is more common in\b", re.IGNORECASE), ""),
    (re.compile(r"\bwhat stood out during the class was\b", re.IGNORECASE), ""),
    (re.compile(r"\bfrom the evaluator'?s notes\b", re.IGNORECASE), ""),
    (re.compile(r"\bfrom a classroom perspective\b", re.IGNORECASE), ""),
    (re.compile(r"\bthe teacher's practice indicates\b", re.IGNORECASE), ""),
    (re.compile(r"\bthis area's practice indicates\b", re.IGNORECASE), ""),
]


def _normalize_clause_fragment(text: str) -> str:
    cleaned = _normalize_whitespace(text).strip(" .;,:-")
    if not cleaned:
        return ""
    return cleaned[0].lower() + cleaned[1:] if len(cleaned) > 1 else cleaned.lower()


def _safe_synonym_swap(text: str, rng: random.Random) -> str:
    updated = text
    for source, variants in SAFE_SYNONYM_BANK.items():
        pattern = re.compile(rf"\b{re.escape(source)}\b", re.IGNORECASE)
        if pattern.search(updated) and rng.random() < 0.8:
            replacement = rng.choice(variants)
            updated = pattern.sub(replacement, updated, count=1)
    return updated


def _clean_clause_for_field(fragment: str, field_name: str) -> str:
    cleaned = _normalize_clause_fragment(fragment)
    for pattern, replacement in AWKWARD_LEAD_PATTERNS:
        cleaned = pattern.sub(replacement, cleaned)

    cleaned = re.sub(r"\b(the teacher)\s+(demonstrates|shows|reflects|highlights)\s+(demonstrates|shows|reflects|highlights)\b", r"\1 \2", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\b(prioritize|strengthen|apply|build)\s+(prioritize|strengthen|apply|build)\b", r"\1", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\b(is|was)\s+(visible|noticeable)\b", r"\1", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\bthe teacher\s+(shows|reflects|highlights)\s+(uses|adjusts|maintains|monitors|organizes|supports|checks|explains)\b", r"the teacher \2", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\bthe teacher demonstrates uses\b", "the teacher uses", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\bthe teacher demonstrates adjusts\b", "the teacher adjusts", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\bthe teacher demonstrates explains\b", "the teacher explains", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\bthe teacher shows should\b", "the teacher should", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\bneed(s)? to strengthen this\b", "needs to strengthen classroom feedback", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\bneeds to strengthen [^.]*\b(contributed|helped)\b[^.]*", "needs to strengthen classroom feedback routines", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\bwhat stood out during the class was\b", "", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\bfrom the evaluator'?s notes\b", "", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\bfrom a classroom perspective\b", "", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\bthis area's practice indicates\b", "", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\bthe teacher's practice indicates\b", "", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\s{2,}", " ", cleaned).strip(" ,.;:-")

    if field_name == "areas_for_improvement":
        cleaned = re.sub(r"^less visible\s+", "", cleaned, flags=re.IGNORECASE)
        cleaned = re.sub(r"^developing\s+", "", cleaned, flags=re.IGNORECASE)
        cleaned = re.sub(r"^the teacher\s*$", "the teacher needs more consistent support in this area", cleaned, flags=re.IGNORECASE)
    if field_name == "recommendations":
        cleaned = re.sub(r"^by\s+", "", cleaned, flags=re.IGNORECASE)
    return _normalize_clause_fragment(cleaned)


def _field_tone_adjustment(fragment: str, field_name: str, rng: random.Random) -> str:
    normalized = _normalize_clause_shape(fragment, field_name)
    if not normalized:
        return ""
    normalized = _clean_clause_for_field(normalized, field_name)
    style = FIELD_STYLE_RULES.get(field_name, FIELD_STYLE_RULES["recommendations"])
    normalized = _safe_synonym_swap(normalized, rng)
    clause_type = _classify_clause(normalized, field_name)

    if field_name == "strengths":
        if normalized.startswith("the teacher "):
            if not re.match(r"^the teacher\s+(uses|adjusts|maintains|monitors|organizes|supports|checks|explains|should)\b", normalized, re.IGNORECASE):
                normalized = re.sub(r"^the teacher\s+", f"the teacher {rng.choice(style['lead_verbs'])} ", normalized, count=1, flags=re.IGNORECASE)
        elif clause_type == "evidence" and not normalized.startswith("the teacher "):
            normalized = f"the teacher {normalized}"
        elif not normalized.startswith(("instruction", "classroom evidence", "the lesson", "students", "learners")):
            normalized = f"the teacher {rng.choice(style['lead_verbs'])} {normalized}"
    elif field_name == "areas_for_improvement":
        if clause_type == "need":
            pass
        elif normalized.startswith("the teacher "):
            if not re.match(r"^the teacher\s+(needs|should)\b", normalized, re.IGNORECASE):
                normalized = re.sub(r"^the teacher\s+", "the teacher needs to ", normalized, count=1, flags=re.IGNORECASE)
        else:
            normalized = f"the teacher needs to strengthen {normalized}"
    elif field_name == "recommendations":
        if clause_type == "action":
            pass
        elif re.match(r"^the teacher\s+", normalized, re.IGNORECASE):
            normalized = re.sub(r"^the teacher\s+", "provide support to help the teacher ", normalized, count=1, flags=re.IGNORECASE)
        else:
            normalized = f"{rng.choice(style['lead_verbs'])} {normalized}"

    return _clean_clause_for_field(normalized, field_name)


def _adapt_for_connector(sentence: str, field_name: str) -> str:
    normalized = _normalize_sentence(sentence)
    if field_name != "areas_for_improvement":
        return normalized

    adapted = re.sub(
        r"^needs to strengthen classroom feedback routines$",
        "classroom feedback routines are strengthened",
        normalized,
        flags=re.IGNORECASE,
    )
    adapted = re.sub(
        r"^needs to strengthen classroom feedback$",
        "classroom feedback is strengthened",
        adapted,
        flags=re.IGNORECASE,
    )
    adapted = re.sub(
        r"^needs to ([a-z].+)$",
        r"\1 is strengthened",
        adapted,
        flags=re.IGNORECASE,
    )
    return _normalize_sentence(adapted)


def _choose_reconstruction_clauses(field_name: str, clauses: List[str]) -> List[str]:
    unique: List[str] = []
    seen = set()
    for clause in clauses:
        normalized = _normalize_sentence(clause)
        key = _comment_fingerprint(normalized)
        if not key or key in seen:
            continue
        seen.add(key)
        unique.append(clause)

    ranked = sorted(
        unique,
        key=lambda clause: _score_clause_for_field(clause, field_name),
        reverse=True,
    )

    filtered = [
        clause for clause in ranked
        if _classify_clause(clause, field_name) != "meta"
        and _normalize_clause_shape(clause, field_name)
        and _is_clean_reconstruction_clause(clause, field_name)
    ]
    return (filtered or ranked)[:3]


def _compose_reconstructed_feedback(req: GenerateRequest, field_name: str, clauses: List[str], fallback_query: str) -> str:
    rng = random.Random(
        _stable_seed(
            "reconstruct",
            field_name,
            req.faculty_name or "",
            req.subject_observed or "",
            req.observation_type or "",
            fallback_query,
            req.regeneration_nonce or "",
        )
    )

    openers = FIELD_OPENERS.get(field_name, FIELD_OPENERS["recommendations"])
    connectors = FIELD_CONNECTORS.get(field_name, FIELD_CONNECTORS["recommendations"])
    closers = FIELD_CLOSERS.get(field_name, FIELD_CLOSERS["recommendations"])

    previously_shown = {
        _comment_fingerprint(_normalize_sentence(text))
        for text in (req.previously_shown or {}).get(field_name, [])
        if _normalize_whitespace(text)
    }

    picked = clauses[:]
    rng.shuffle(picked)
    unseen_first = [clause for clause in picked if _comment_fingerprint(_normalize_sentence(clause)) not in previously_shown]
    if unseen_first:
        picked = unseen_first + [clause for clause in picked if clause not in unseen_first]
    picked = picked[:3]

    lead = _field_tone_adjustment(picked[0], field_name, rng) if picked else _field_tone_adjustment(fallback_query, field_name, rng)
    second = _field_tone_adjustment(picked[1], field_name, rng) if len(picked) > 1 else ""
    third = _field_tone_adjustment(picked[2], field_name, rng) if len(picked) > 2 else ""

    picked_sentences = [part for part in [lead, second, third] if part]

    sentences: List[str] = []
    if picked_sentences:
        sentences.append(_normalize_sentence(f"{rng.choice(openers)} {picked_sentences[0]}"))
    if len(picked_sentences) > 1:
        connector = rng.choice(connectors)
        if field_name == "recommendations" and re.match(r"^(use|provide|plan|apply|build|prioritize|strengthen|restate|pause|review|monitor|establish)\b", picked_sentences[1], re.IGNORECASE):
            sentences.append(_normalize_sentence(picked_sentences[1]))
        else:
            connector_text = _adapt_for_connector(picked_sentences[1], field_name)
            sentences.append(_normalize_sentence(f"{connector} {connector_text}"))
    if len(picked_sentences) > 2:
        sentences.append(_normalize_sentence(picked_sentences[2]))

    if not sentences:
        return _normalize_sentence(fallback_query)

    if len(sentences) < 3:
        sentences.append(rng.choice(closers))

    if field_name == "recommendations":
        top_issue_sentence = _build_top_issue_recommendation_sentence(req, _flatten_comments(req))
        if top_issue_sentence:
            remaining = [sentence for sentence in sentences if _comment_fingerprint(sentence) != _comment_fingerprint(top_issue_sentence)]
            sentences = [top_issue_sentence] + remaining

    result = " ".join(sentences[:3])
    criterion_phrases = _criterion_phrases_for_field(req, _flatten_comments(req), field_name)
    if criterion_phrases:
        phrase = criterion_phrases[0]
        if phrase.lower() not in result.lower():
            if field_name == "strengths":
                result = _normalize_whitespace(f"{result} This was especially evident in {phrase}.")
            elif field_name == "areas_for_improvement":
                result = _normalize_whitespace(f"{result} This is most visible in {phrase}.")
            else:
                result = _normalize_whitespace(f"{result} Focus the follow-through on {phrase}.")

    return result


def _paraphrase_dataset_feedback(req: GenerateRequest, field_name: str, retrieved: List[Dict[str, Any]], fallback_query: str) -> str:
    """Select the best matching feedback_text from retrieved seed data.
    Prefer using the high-quality seed text directly rather than
    splitting and reconstructing it."""
    previously_shown = {
        _comment_fingerprint(_normalize_sentence(text))
        for text in (req.previously_shown or {}).get(field_name, [])
        if _normalize_whitespace(text)
    }

    rng = random.Random(
        _stable_seed(
            "paraphrase", field_name,
            req.faculty_name or "", req.subject_observed or "",
            req.regeneration_nonce or "",
        )
    )

    # Collect clean feedback texts from retrieved items
    candidates: List[str] = []
    seen = set()
    for item in retrieved:
        text = _normalize_sentence(item.get("feedback_text") or item.get("text") or "")
        if not text or len(text.split()) < 8:
            continue
        key = _comment_fingerprint(text)
        if not key or key in seen:
            continue
        seen.add(key)
        candidates.append(text)

    if not candidates:
        return _normalize_sentence(fallback_query)

    # Prefer unseen candidates
    unseen = [c for c in candidates if _comment_fingerprint(c) not in previously_shown]
    pool = unseen if unseen else candidates
    return pool[rng.randint(0, len(pool) - 1)]


def _build_clean_option_candidate(req: GenerateRequest, field_name: str, item: Dict[str, Any], fallback_text: str) -> str:
    source_text = _field_source_text(item)
    if _is_clean_candidate_text(source_text, field_name):
        clauses = [
            clause for clause in _split_clauses(source_text)
            if _is_clean_reconstruction_clause(clause, field_name)
        ]
        chosen = _choose_reconstruction_clauses(field_name, clauses)
        if chosen:
            return _compose_reconstructed_feedback(req, field_name, chosen, fallback_text)

    fallback_query = fallback_text or source_text
    reconstructed = _paraphrase_dataset_feedback(req, field_name, [item], fallback_query)
    return _ensure_2_3_sentences(reconstructed, fallback=fallback_text)


def _summarize_comments_for_field(req: GenerateRequest, comments: List[Dict[str, Any]], field_name: str) -> str:
    sig = _evaluation_signature(req)
    context = _subject_department_context(req)
    prioritized = _prioritize_comments(req, comments)
    field_domain_map = {
        "strengths": sig["strongest"],
        "areas_for_improvement": sig["weakest"],
        "recommendations": sig["weakest"],
    }
    target_domain = field_domain_map[field_name]
    domain_items = [item for item in prioritized if item["comment"] and item["domain"] == target_domain]
    general_items = [item for item in prioritized if item["comment"]]
    domain_comments = [item["comment"] for item in domain_items]
    general_comments = [item["comment"] for item in general_items]
    subject_phrase = context["subject"] or "the observed lesson"
    lesson_context = context["lesson_context"] or subject_phrase
    department_phrase = context["department"] or "the department"
    criterion_phrases = _criterion_phrases_for_field(req, comments, field_name)

    if field_name == "strengths":
        chosen_source = domain_items or general_items
        chosen = [
            _merge_comment_with_criterion(item["comment"], _criterion_phrase(item), field_name)
            for item in chosen_source[:2]
        ]
        chosen = _dedupe_preserve_order([item for item in chosen if item])[:2]
        if not chosen:
            if criterion_phrases:
                return f"The teacher showed the strongest evidence in {target_domain.lower()} during {subject_phrase}, especially in {criterion_phrases[0]}."
            return f"The teacher showed the strongest evidence in {target_domain.lower()} during {subject_phrase}."
        return _normalize_whitespace(f"During {subject_phrase}, {chosen[0]} {' '.join(chosen[1:])}")

    if field_name == "areas_for_improvement":
        chosen_source = domain_items or general_items
        chosen = [
            _merge_comment_with_criterion(item["comment"], _criterion_phrase(item), field_name)
            for item in chosen_source[:2]
        ]
        chosen = _dedupe_preserve_order([item for item in chosen if item])[:2]
        if not chosen:
            if criterion_phrases:
                return f"The clearest area for improvement in {subject_phrase} is {criterion_phrases[0]} within {target_domain.lower()}, based on the evaluation comments."
            return f"The clearest area for improvement in {subject_phrase} is {target_domain.lower()} based on the evaluation comments."
        return _normalize_whitespace(f"For {subject_phrase} in {department_phrase}, {' '.join(chosen)}")

    if field_name == "recommendations":
        chosen_source = domain_items or general_items
        chosen = [
            _merge_comment_with_criterion(item["comment"], _criterion_phrase(item), field_name)
            for item in chosen_source[:2]
        ]
        chosen = _dedupe_preserve_order([item for item in chosen if item])[:2]
        top_issue_sentence = _build_top_issue_recommendation_sentence(req, comments)
        if not chosen:
            if criterion_phrases:
                fallback = f"Provide support in {criterion_phrases[0]} so improvement becomes more visible in future {lesson_context} lessons."
            else:
                fallback = f"Provide support in {target_domain.lower()} so improvement becomes more visible in future {lesson_context} lessons."
            return _normalize_whitespace(f"{top_issue_sentence} {fallback}".strip())

        tail = _normalize_whitespace(
            f"Recommended follow-through for {department_phrase}: {' '.join(chosen)}"
        )
        return _normalize_whitespace(f"{top_issue_sentence} {tail}".strip())

    raise ValueError(f"Unsupported AI-assisted field: {field_name}")


def _retrieve_form_feedback(req: GenerateRequest, comments: List[Dict[str, Any]]) -> Tuple[Dict[str, str], Dict[str, List[Dict[str, Any]]]]:
    retrieval_system = _load_feedback_retrieval_system()
    queries = {
        "strengths": _normalize_whitespace(req.strengths or "") or _compose_field_query(req, comments, "strengths"),
        "areas_for_improvement": _normalize_whitespace(req.improvement_areas or "") or _compose_field_query(req, comments, "areas_for_improvement"),
        "recommendations": _normalize_whitespace(req.recommendations or "") or _compose_field_query(req, comments, "recommendations"),
    }

    try:
        matched_top = retrieval_system.retrieve_top_feedback_for_form(queries, top_k=10)
    except Exception:
        matched_top = {}
        for field_name, query in queries.items():
            try:
                matched_top[field_name] = retrieval_system.retrieve_top_feedback(field_name, query, top_k=10)
            except Exception:
                matched_top[field_name] = []

    def _field_matches(field_name: str) -> List[Dict[str, Any]]:
        matches = matched_top.get(field_name) or []
        return [
            {
                "feedback_text": match.feedback_text,
                "evaluation_comment": match.evaluation_comment,
                "category": _field_target_category(req, field_name),
                "source": f"mysql:{field_name}:top",
                "similarity": match.similarity,
            }
            for match in matches
            if getattr(match, "feedback_text", None)
        ]

    field_specific_matches = {
        field_name: _field_matches(field_name)
        for field_name in queries
    }

    feedback = {
        field_name: _paraphrase_dataset_feedback(
            req,
            field_name,
            field_specific_matches[field_name],
            queries[field_name],
        )
        for field_name in queries
    }
    return feedback, field_specific_matches


def _split_sentences(text: str) -> List[str]:
    parts = re.split(r'(?<=[.!?])\s+', _normalize_whitespace(text))
    return [p.strip() for p in parts if p.strip()]


def _ensure_2_3_sentences(text: Optional[str], fallback: str = "") -> str:
    primary = _normalize_whitespace(text or "")
    backup = _normalize_whitespace(fallback or "")
    sents = _split_sentences(primary)
    if len(sents) >= 2:
        return " ".join(sents[:3])
    if len(sents) == 1:
        return sents[0]
    sents_b = _split_sentences(backup)
    if len(sents_b) >= 2:
        return " ".join(sents_b[:3])
    if sents_b:
        return sents_b[0]
    return _normalize_sentence(backup or "No specific feedback available for this criterion.")


def _inject_subject_context(text: str, req: GenerateRequest, field_name: str) -> str:
    """Return the text as-is without adding subject prefixes."""
    return _normalize_sentence(_normalize_whitespace(text) or "")


def _vary_sentence_starters(sentences: List[str]) -> List[str]:
    """Rewrite sentences sharing the same opening word to add natural variety."""
    if len(sentences) < 2:
        return sentences
    starter_counts: Dict[str, int] = {}
    for s in sentences:
        first = s.split()[0].lower() if s.split() else ""
        starter_counts[first] = starter_counts.get(first, 0) + 1
    _ALTS: Dict[str, List[str]] = {
        "the": ["Overall, the", "Notably, the", "Additionally, the"],
        "teacher": ["The educator", "The instructor", "Notably, the teacher"],
        "students": ["Learners", "The class", "Notably, students"],
        "this": ["Such an approach", "Notably, this", "In practice, this"],
        "a": ["Such a", "Notably, a", "In particular, a"],
        "it": ["Notably, it", "In this regard, it"],
    }
    result: List[str] = []
    used_alts: Dict[str, int] = {}
    for s in sentences:
        words = s.split()
        if not words:
            result.append(s)
            continue
        first = words[0].lower()
        if starter_counts.get(first, 0) > 1 and first in _ALTS and len(result) > 0:
            alt_idx = used_alts.get(first, 0)
            alts = _ALTS[first]
            if alt_idx < len(alts):
                rest = " ".join(words[1:])
                rewritten = f"{alts[alt_idx]} {rest[0].lower() + rest[1:]}" if rest else alts[alt_idx]
                result.append(_normalize_sentence(rewritten))
                used_alts[first] = alt_idx + 1
            else:
                result.append(s)
        else:
            result.append(s)
    return result


def _make_three_options(base_text: str, req: GenerateRequest, field_name: str, retrieved: List[Dict[str, Any]]) -> List[str]:
    """Build exactly 3 distinct suggestion options.

    Each option groups 2-3 feedback texts from the SAME evaluation indicator
    (evaluation_comment) so the paragraph reads coherently about one teaching
    criterion.  The 3 options come from 3 different indicators for variety.
    Sentence starters are varied to avoid repetitive patterns."""
    rng = random.Random(
        _stable_seed(
            "options",
            field_name,
            req.faculty_name or "",
            req.subject_observed or "",
            req.observation_type or "",
            req.regeneration_nonce or "",
        )
    )

    previously_shown = {
        _comment_fingerprint(_normalize_sentence(text))
        for text in (req.previously_shown or {}).get(field_name, [])
        if _normalize_whitespace(text)
    }

    # Group retrieved items by evaluation_comment (the indicator they describe)
    indicator_groups: Dict[str, List[str]] = {}
    seen_fp: set = set()
    for item in retrieved:
        text = _normalize_sentence(item.get("feedback_text") or item.get("text") or "")
        indicator = _normalize_whitespace(item.get("evaluation_comment") or "general")
        if not text or len(text.split()) < 5:
            continue
        fp = _comment_fingerprint(text)
        if not fp or fp in seen_fp:
            continue
        seen_fp.add(fp)
        indicator_groups.setdefault(indicator, []).append(text)

    # Sort indicator groups by richness (most texts first), shuffle within each
    sorted_indicators = sorted(indicator_groups.keys(), key=lambda k: -len(indicator_groups[k]))
    for key in sorted_indicators:
        rng.shuffle(indicator_groups[key])

    # Collect all unique texts as a supplementary pool for padding short groups
    all_texts: List[str] = []
    for ind in sorted_indicators:
        all_texts.extend(indicator_groups[ind])

    # Build 3 options from 3 different indicator groups, each guaranteed 2-3 sentences
    out: List[str] = []
    used_indicators: set = set()
    global_used_fp: set = set()

    for indicator in sorted_indicators:
        if len(out) >= 3:
            break
        if indicator in used_indicators:
            continue

        texts = indicator_groups[indicator]
        target_size = rng.choice([2, 3])

        # Start with texts from this indicator
        group = list(texts[:target_size])

        # If not enough from this indicator, supplement from other indicators
        if len(group) < 2:
            supplements = [
                t for t in all_texts
                if _comment_fingerprint(t) not in {_comment_fingerprint(g) for g in group}
                and _comment_fingerprint(t) not in global_used_fp
            ]
            rng.shuffle(supplements)
            for s in supplements:
                if len(group) >= target_size:
                    break
                group.append(s)

        group = _vary_sentence_starters(group)
        combined = " ".join(group)
        if _comment_fingerprint(combined) in previously_shown:
            continue

        out.append(_normalize_sentence(combined))
        used_indicators.add(indicator)
        for g in group:
            global_used_fp.add(_comment_fingerprint(g))

    # Fallback: fill remaining slots from unused texts
    if len(out) < 3:
        remaining = [
            t for t in all_texts
            if _comment_fingerprint(t) not in global_used_fp
        ]
        rng.shuffle(remaining)
        idx = 0
        while len(out) < 3 and idx < len(remaining):
            target_size = rng.choice([2, 3])
            gs = min(target_size, len(remaining) - idx)
            group = _vary_sentence_starters(remaining[idx:idx + gs])
            out.append(_normalize_sentence(" ".join(group)))
            idx += gs

    return out[:3]


@app.post("/generate", response_model=GenerateResponse)
def generate(req: GenerateRequest):
    """Generate 3 unique feedback suggestions per category from seed data."""
    comments = _flatten_comments(req)
    prioritized_comments = _prioritize_comments(req, comments)
    retrieved = _retrieve_top_comments(req, comments)
    field_feedback, field_retrieved = _retrieve_form_feedback(req, comments)
    strengths_fallback = _summarize_comments_for_field(req, comments, "strengths")
    improvement_fallback = _summarize_comments_for_field(req, comments, "areas_for_improvement")
    recommendations_fallback = _summarize_comments_for_field(req, comments, "recommendations")

    # Get the primary feedback text for each field (raw, no subject injection yet)
    strengths_primary = field_feedback.get("strengths") or strengths_fallback
    improvement_primary = field_feedback.get("areas_for_improvement") or improvement_fallback
    recommendations_primary = field_feedback.get("recommendations") or recommendations_fallback
    sig = _evaluation_signature(req)

    # Build 3 options per field — the primary text becomes the first option
    strengths_options = _make_three_options(strengths_primary, req, "strengths", field_retrieved.get("strengths", []))
    improvement_options = _make_three_options(improvement_primary, req, "areas_for_improvement", field_retrieved.get("areas_for_improvement", []))
    recommendation_options = _make_three_options(recommendations_primary, req, "recommendations", field_retrieved.get("recommendations", []))

    # Use first option as the primary output
    strengths = strengths_options[0] if strengths_options else strengths_primary
    improvement_areas = improvement_options[0] if improvement_options else improvement_primary
    recommendations = recommendation_options[0] if recommendation_options else recommendations_primary

    return GenerateResponse(
        strengths=strengths,
        improvement_areas=improvement_areas,
        recommendations=recommendations,
        strengths_options=strengths_options,
        improvement_areas_options=improvement_options,
        recommendations_options=recommendation_options,
        debug={
            "top_comments": retrieved,
            "prioritized_indicator_comments": prioritized_comments[:5],
            "mysql_sources": _mysql_source_summary(retrieved),
            "field_mysql_sources": {
                field_name: _mysql_source_summary(items)
                for field_name, items in field_retrieved.items()
            },
            "embedding_cache_path": str(EMBEDDINGS_CACHE_PATH),
            "dataset_size": len(_build_dataset_entries()),
            "model": os.getenv("SBERT_MODEL", "sentence-transformers/all-MiniLM-L6-v2"),
            "generator": "mysql-only-retrieval",
            "overall_band": sig["overall_level"],
            "domain_bands": {domain: _band_label(score) for domain, score in sig["domains"].items()},
            "feedback_queries": {
                "strengths": _normalize_whitespace(req.strengths or "") or _summarize_comments_for_field(req, comments, "strengths"),
                "areas_for_improvement": _normalize_whitespace(req.improvement_areas or "") or _summarize_comments_for_field(req, comments, "areas_for_improvement"),
                "recommendations": _normalize_whitespace(req.recommendations or "") or _summarize_comments_for_field(req, comments, "recommendations"),
            },
        },
    )
