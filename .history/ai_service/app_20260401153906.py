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

# Use locally cached HuggingFace models — avoid network calls that fail on some machines
os.environ.setdefault("HF_HUB_OFFLINE", "1")
os.environ.setdefault("TRANSFORMERS_OFFLINE", "1")

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
ROOT_PATH = BASE_PATH.resolve().parent
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
    # PEAC form categories
    "teacher_actions": "Teacher actions & instructional practice",
    "teacher actions": "Teacher actions & instructional practice",
    "student_learning_actions": "Student learning actions & engagement",
    "student learning actions": "Student learning actions & engagement",
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
    # PEAC form domains
    "Teacher actions & instructional practice": (
        "lesson structure and delivery, content explanation and accuracy, "
        "instructional strategies and pedagogy, questioning and discussion, "
        "classroom management and discipline, use of teaching resources"
    ),
    "Student learning actions & engagement": (
        "student participation and involvement, collaborative learning and group work, "
        "critical thinking and problem solving, student responses and interaction, "
        "self-directed learning, application of concepts, "
        "student engagement and attentiveness, learning outcomes demonstration, "
        "peer learning and support"
    ),
}

# Keywords used to detect which domain a retrieved seed text belongs to.
_DOMAIN_FILTER_KEYWORDS: Dict[str, List[str]] = {
    "communications": [
        "audible voice", "audible", "voice projection", "voice clarity",
        "non-verbal", "nonverbal", "non verbal",
        "fluency in the language", "fluent speech", "fluently",
        "language of instruction", "spoken language",
        "verbal communication", "speech clarity", "speaks clearly",
        "speech pacing", "speaks fluently",
        "language suited", "language appropriate", "developmental level",
        "facial expression", "gestures", "tone of voice",
        "dynamic discussion", "discussion facilitation", "discussion dynamism",
        "two-way interaction", "two-way exchange",
        "vocabulary", "pronunciation", "articulation",
        "communicates instructions", "communication competence",
        "voice throughout", "heard by all", "heard at the back",
    ],
    "management": [
        "classroom management", "classroom routines", "lesson organization",
        "time management", "time allocation", "transition cues", "seating plan",
        "teaching aids", "core values", "institutional core values",
        "lesson introductions and closure", "lesson planning and learning outcomes",
        "instructional strategies", "educational technology",
        "collaborative learning", "collaborative task", "group work", "group task",
        "lesson objective", "learning outcome", "TILO",
        "instructional planning", "lesson planning", "lesson plan",
        "participation routine", "engagement strateg",
        "seating", "seat plan", "lesson pacing",
        "activity shift", "transition",
        "teaching aid", "instructional material",
        "real-life example", "real-world",
        "differentiation", "differentiated",
        "scaffolding", "scaffold",
        "lesson closure", "exit activity", "closure activity",
        "student collaboration", "peer interaction",
        "independent practice", "guided practice",
        "learner engagement", "learner accountability",
        "classroom climate", "learning environment", "learning climate",
        "instructional delivery", "lesson delivery",
        "think-pair-share", "random calling",
        "task alignment", "lesson structure",
        "board work", "board organization",
        "connection to prior learning", "previously learned",
        "motivational strateg", "positive reinforcement",
        "learner support", "learner needs", "student support",
        "instructional clarity", "instructional adjustment",
        "questioning technique", "questioning routine",
        "learner participation", "student participation",
        "inclusive questioning", "participation more evenly",
        "content mastery", "lesson content",
        "task-aligned", "aligned to objective",
        "use of resources", "instructional resource",
        "organized and student-centered",
        "learner-centered",
        "responsive classroom", "classroom evidence",
        "class discussion on key concepts",
        "management practices", "consistent routines",
        "productive classroom", "orderly routines",
        "instructional momentum", "lesson segments",
    ],
    "assessment": [
        "assessment", "formative", "summative", "rubric", "grading",
        "criterion-referenced", "normative assessment", "comprehension check",
        "exit ticket", "checking for understanding", "monitor understanding",
        "feedback follow-through", "feedback practices for student",
        "assessment alignment", "assessment data",
        "higher-order thinking", "higher-order question",
        "monitoring routine", "completion check",
        "feedback quality", "feedback specificity", "corrective feedback",
        "formative check", "content check",
        "learner mastery", "checks for understanding",
    ],
    "teacher_actions": [
        "lesson structure", "content explanation", "instructional strateg",
        "pedagogy", "questioning and discussion", "classroom management",
        "discipline", "teaching resources", "lesson delivery",
        "teacher demonstration", "instructional practice",
        "teacher facilitation", "teacher guidance",
    ],
    "student_learning_actions": [
        "student participation", "collaborative learning", "group work",
        "critical thinking", "problem solving", "student response",
        "self-directed", "application of concepts", "student engagement",
        "peer learning", "learner involvement", "student interaction",
        "learning demonstration", "student attentiveness",
    ],
}


def _count_domain_keyword_matches(text: str, domain: str) -> int:
    """Count how many keywords from a domain match in the text."""
    lower = text.lower()
    count = 0
    for kw in _DOMAIN_FILTER_KEYWORDS.get(domain, []):
        if kw.lower() in lower:
            count += 1
    return count


def _indicator_rating_map(comments: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    """Build a list of {criterion_text, rating, domain} from flattened comments.
    Used to check if retrieved templates contradict actual ratings."""
    indicators = []
    for item in comments:
        criterion = _normalize_whitespace(item.get("criterion_text") or "")
        rating = float(item.get("rating") or 0)
        if criterion and rating > 0:
            indicators.append({
                "criterion_text": criterion,
                "criterion_words": set(re.findall(r'[a-z]{4,}', criterion.lower())),
                "rating": rating,
                "domain": item.get("domain", ""),
            })
    return indicators


def _template_contradicts_ratings(
    template_text: str,
    indicators: List[Dict[str, Any]],
    field_name: str,
    max_scale: float = 5.0,
) -> bool:
    """Check if a retrieved template contradicts the actual indicator ratings.
    For improvements/recommendations: reject templates whose topic matches a HIGH-rated indicator.
    For strengths: reject templates whose topic matches a LOW-rated indicator."""
    if not indicators or not template_text:
        return False
    text_words = set(re.findall(r'[a-z]{4,}', template_text.lower()))
    if not text_words:
        return False

    # Thresholds depend on scale
    if max_scale <= 4.0:
        high_threshold = 3.5  # PEAC: 4 out of 4 is clearly high
        low_threshold = 1.5
    else:
        # ISO: Only reject improvement templates when the matched indicator
        # scored a perfect 5/5, since 4/5 still has room for growth.
        # Recommendations are similarly lenient (only reject on perfect score).
        high_threshold = 5.0
        low_threshold = 2.5

    best_overlap = 0.0
    best_rating = 0.0
    for ind in indicators:
        crit_words = ind["criterion_words"]
        if not crit_words:
            continue
        overlap = len(text_words & crit_words) / len(crit_words)
        if overlap > best_overlap:
            best_overlap = overlap
            best_rating = ind["rating"]

    # Only apply contradiction check when there's meaningful overlap
    if best_overlap < 0.25:
        return False

    if field_name in ("areas_for_improvement", "recommendations"):
        # Template matches a high-rated indicator -> contradiction
        return best_rating >= high_threshold
    elif field_name == "strengths":
        # Template matches a low-rated indicator -> contradiction
        return best_rating <= low_threshold
    return False


def _filter_by_rating_relevance(
    items: List[Dict[str, Any]],
    comments: List[Dict[str, Any]],
    field_name: str,
    max_scale: float = 5.0,
) -> List[Dict[str, Any]]:
    """Filter retrieved templates that contradict actual indicator ratings.
    Falls back to original list if filtering removes everything."""
    indicators = _indicator_rating_map(comments)
    if not indicators:
        return items
    filtered = [
        item for item in items
        if not _template_contradicts_ratings(
            item.get("feedback_text") or item.get("text") or "",
            indicators,
            field_name,
            max_scale,
        )
    ]
    return filtered if filtered else items


def _find_critical_indicators(
    comments: List[Dict[str, Any]],
    field_name: str,
    max_scale: float = 5.0,
) -> List[Dict[str, Any]]:
    """Find indicators that stand out as clearly highest or lowest rated.
    For strengths: indicators with the max rating.
    For improvements/recommendations: indicators with the min rating.
    Only returns indicators that are clearly separated from the median."""
    rated = [
        c for c in comments
        if c.get("criterion_text") and float(c.get("rating") or 0) > 0
    ]
    if not rated:
        return []

    ratings = [float(c.get("rating") or 0) for c in rated]
    if not ratings:
        return []

    if field_name == "strengths":
        target_rating = max(ratings)
        median_rating = sorted(ratings)[len(ratings) // 2]
        # Only consider it "critical" if the top rating is above the median
        if target_rating <= median_rating:
            return []
        critical = [c for c in rated if float(c.get("rating") or 0) == target_rating]
    else:
        target_rating = min(ratings)
        median_rating = sorted(ratings)[len(ratings) // 2]
        # Only consider it "critical" if the bottom rating is below the median
        if target_rating >= median_rating:
            return []
        critical = [c for c in rated if float(c.get("rating") or 0) == target_rating]

    return critical


def _ensure_critical_indicator_mentioned(
    options: List[str],
    comments: List[Dict[str, Any]],
    field_name: str,
    max_scale: float = 5.0,
) -> List[str]:
    """Ensure at least one option explicitly mentions the most critical indicator.
    If no option does, replace the last option with a criterion-specific one."""
    critical = _find_critical_indicators(comments, field_name, max_scale)
    if not critical or not options:
        return options

    # Pick the single most critical item (lowest/highest rated)
    target = critical[0]
    criterion = _normalize_whitespace(target.get("criterion_text") or "")
    rating = float(target.get("rating") or 0)
    if not criterion:
        return options

    # Convert criterion text to a readable phrase
    phrase = _natural_criterion_reference(criterion)
    if not phrase:
        return options

    # Check if any option already mentions this indicator's key words
    # Exclude common words that appear in almost every PEAC/ISO template
    _COMMON_INDICATOR_WORDS = {
        "students", "student", "teacher", "unit", "standards", "competencies",
        "learning", "lesson", "class", "classroom", "instruction", "instructional",
        "performance", "practice", "teaching", "actions", "towards", "achieve",
        "achieving", "achievement", "support", "effective", "effectively",
    }
    phrase_words = set(re.findall(r'[a-z]{4,}', phrase.lower())) - _COMMON_INDICATOR_WORDS
    if not phrase_words:
        return options  # Phrase is all common words, can't meaningfully check

    for opt in options:
        opt_words = set(re.findall(r'[a-z]{4,}', opt.lower())) - _COMMON_INDICATOR_WORDS
        if phrase_words and opt_words and len(phrase_words & opt_words) / len(phrase_words) > 0.35:
            return options  # Already covered

    # Build a targeted replacement for the last option using professional language
    if field_name == "strengths":
        _STRENGTH_TEMPLATES = [
            f"The teacher demonstrated commendable competence in {phrase}, which was consistently evident throughout the lesson and contributed to a well-structured learning experience.",
            f"A notable area of proficiency was {phrase}, reflecting the teacher's deliberate and effective instructional approach during the lesson.",
            f"The lesson reflected strong practice in {phrase}, indicating a well-developed instructional skill that positively supported student engagement.",
        ]
        replacement = _STRENGTH_TEMPLATES[len(options) % len(_STRENGTH_TEMPLATES)]
    elif field_name == "areas_for_improvement":
        _IMPROVEMENT_TEMPLATES = [
            f"Further development is needed in {phrase}, which the evaluator identified as the most significant opportunity for instructional growth during the observed lesson.",
            f"The area of {phrase} warrants focused professional attention, as the evaluator's assessment indicated clear potential for measurable improvement in this aspect of instruction.",
            f"The evaluator's observation suggests that {phrase} is an area where more deliberate and consistent practice would strengthen overall instructional effectiveness.",
        ]
        replacement = _IMPROVEMENT_TEMPLATES[len(options) % len(_IMPROVEMENT_TEMPLATES)]
    else:  # recommendations
        _RECOMMENDATION_TEMPLATES = [
            f"It is recommended that the teacher prioritize {phrase} by incorporating targeted strategies that can be consistently applied in subsequent lessons to address the evaluator's findings.",
            f"To strengthen {phrase}, it would be beneficial to implement specific, incremental adjustments focused on this aspect of instruction, as noted in the evaluator's assessment.",
            f"Professional development efforts should focus on {phrase}, as the evaluator identified this as a priority area where structured practice will yield the most meaningful improvement.",
        ]
        replacement = _RECOMMENDATION_TEMPLATES[len(options) % len(_RECOMMENDATION_TEMPLATES)]

    # Replace the last option (least relevant) with the targeted one
    result = list(options)
    result[-1] = _normalize_sentence(replacement)
    return result


def _filter_retrieved_by_focus(items: List[Dict[str, Any]], focus: List[str]) -> List[Dict[str, Any]]:
    """Strict positive-match filter: ONLY keep items whose feedback_text
    matches at least one keyword from an included (focused) domain AND has
    MORE included-domain keyword hits than excluded-domain hits.
    Checks feedback_text only (the actual output text), not evaluation_comment
    (which contains observation-type metadata common to all templates).
    Returns empty list when no items qualify."""
    if not focus:
        return items  # No focus set = all domains active
    excluded = [d for d in ["communications", "management", "assessment"] if d not in focus]
    if not excluded:
        return items  # All domains are in focus
    filtered = []
    for item in items:
        text = item.get('feedback_text', '') or item.get('text', '')
        included_hits = sum(_count_domain_keyword_matches(text, d) for d in focus)
        excluded_hits = sum(_count_domain_keyword_matches(text, d) for d in excluded)
        if included_hits > 0 and included_hits >= excluded_hits:
            filtered.append(item)
    return filtered


def _normalize_whitespace(text: str) -> str:
    # Replace smart quotes, em-dashes, and other problematic Unicode chars with ASCII equivalents
    cleaned = (text or "")
    cleaned = cleaned.replace("\u2018", "'").replace("\u2019", "'")   # smart single quotes
    cleaned = cleaned.replace("\u201c", '"').replace("\u201d", '"')   # smart double quotes
    cleaned = cleaned.replace("\u2014", " - ").replace("\u2013", " - ")  # em-dash, en-dash
    cleaned = cleaned.replace("\u2026", "...")  # ellipsis
    cleaned = cleaned.replace("\ufffd", "")     # replacement character
    cleaned = re.sub(r'[^\x00-\x7F]+', lambda m: m.group(0) if all(0xA0 <= ord(c) <= 0xFF for c in m.group(0)) else '', cleaned)
    return re.sub(r"\s+", " ", cleaned.strip())


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


def _score_band(x: float, max_scale: float = 5.0) -> str:
    x = float(x or 0)
    if max_scale <= 4.0:
        # PEAC 0-4 scale thresholds
        if x >= 3.5:
            return "Excellent"
        if x >= 2.5:
            return "Very satisfactory"
        if x >= 1.5:
            return "Satisfactory"
        if x >= 0.5:
            return "Below satisfactory"
        return "Needs improvement"
    # ISO 1-5 scale thresholds
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
    rating: float = Field(..., ge=0, le=5)
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
    evaluation_focus: Optional[str] = ""
    evaluation_form_type: Optional[str] = ""


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
                rating=float(v["rating"]),
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


def _parse_evaluation_focus(req: GenerateRequest) -> List[str]:
    """Return the list of active focus categories, or empty list if all are active."""
    raw = req.evaluation_focus or ""
    if not raw:
        return []
    try:
        parsed = json.loads(raw)
        if isinstance(parsed, list):
            return [str(x).lower().strip() for x in parsed if str(x).strip()]
    except (json.JSONDecodeError, TypeError):
        pass
    return []


def _is_peac_request(req: GenerateRequest) -> bool:
    explicit = (req.evaluation_form_type or "").strip().lower()
    if explicit == "peac":
        return True
    ratings_keys = set(k.lower().replace(" ", "_") for k in (req.ratings or {}).keys())
    return "teacher_actions" in ratings_keys or "student_learning_actions" in ratings_keys


def _effective_form_type(req: GenerateRequest) -> str:
    """Return 'iso' or 'peac' based on explicit field or inferred from ratings."""
    explicit = (req.evaluation_form_type or "").strip().lower()
    if explicit in ("iso", "peac"):
        return explicit
    return "peac" if _is_peac_request(req) else "iso"


def _domain_scores(req: GenerateRequest) -> Dict[str, float]:
    avg = req.averages
    focus = _parse_evaluation_focus(req)
    is_peac = _is_peac_request(req)
    scores: Dict[str, float] = {}
    if is_peac:
        # PEAC form: averages arrive as communications=teacher_actions avg, management=student_learning_actions avg
        if not focus or "teacher_actions" in focus:
            scores["Teacher actions & instructional practice"] = float(avg.communications or 0)
        if not focus or "student_learning_actions" in focus:
            scores["Student learning actions & engagement"] = float(avg.management or 0)
    else:
        if not focus or "communications" in focus:
            scores["Communication & instruction"] = float(avg.communications or 0)
        if not focus or "management" in focus:
            scores["Classroom management & learning environment"] = float(avg.management or 0)
        if not focus or "assessment" in focus:
            scores["Assessment & feedback practices"] = float(avg.assessment or 0)
    return scores


def _evaluation_signature(req: GenerateRequest) -> Dict[str, Any]:
    domains = _domain_scores(req)
    weakest: str = min(domains.keys(), key=lambda k: domains[k]) if domains else "Instructional practice"
    strongest: str = max(domains.keys(), key=lambda k: domains[k]) if domains else "Professional practice"
    max_scale = 4.0 if _is_peac_request(req) else 5.0
    overall_level = _score_band(req.averages.overall, max_scale)
    # Track all domains tied at the weakest/strongest score for balanced distribution
    if domains:
        min_score = domains[weakest]
        max_score = domains[strongest]
        all_weakest = [k for k, v in domains.items() if v == min_score]
        all_strongest = [k for k, v in domains.items() if v == max_score]
    else:
        all_weakest = [weakest]
        all_strongest = [strongest]
    return {
        "teacher": _normalize_whitespace(req.faculty_name or "") or "The teacher",
        "subject": _normalize_whitespace(req.subject_observed or "") or "the lesson",
        "observation_type": _normalize_whitespace(req.observation_type or "") or "Classroom review",
        "department": _normalize_whitespace(req.department or ""),
        "overall_level": overall_level,
        "overall_numeric": float(req.averages.overall or 0),
        "weakest": weakest,
        "strongest": strongest,
        "all_weakest": all_weakest,
        "all_strongest": all_strongest,
        "domains": domains,
        "max_scale": max_scale,
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
    focus = _parse_evaluation_focus(req)

    for idx, item in enumerate(req.indicator_comments or [], 1):
        comment = _normalize_whitespace(item.comment or "")
        if not comment:
            continue
        category_key = item.category or "General"
        # Skip categories not in focus
        if focus and _safe_lower_label(category_key) not in focus and _safe_lower_label(category_key) != "general":
            continue
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
        # Skip categories not in focus
        if focus and _safe_lower_label(category) not in focus:
            continue
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

    # Normalize ampersand and slash to readable text
    cleaned = cleaned.replace(" & ", " and ")
    cleaned = cleaned.replace("/", " or ")
    cleaned = re.sub(r"\s+", " ", cleaned)

    # Handle specific ISO criterion texts that need special phrasing
    _CRITERION_PHRASE_MAP = {
        "the topic or lesson is introduced in an interesting and engaging way": "introducing the topic or lesson in an engaging and interesting way",
        "the topic/lesson is introduced in an interesting & engaging way": "introducing the topic or lesson in an engaging and interesting way",
        "the topic/lesson is introduced in an interesting and engaging way": "introducing the topic or lesson in an engaging and interesting way",
        "the tilo (topic intended learning outcomes) are clearly presented": "clear presentation of the TILO (Topic Intended Learning Outcomes)",
        "recall and connects previous lessons to the new lessons": "connecting previous lessons to the current topic",
        "recall and connect previous lessons to the new lessons": "connecting previous lessons to the current topic",
        "conduct the lesson using the principle of smart": "applying the SMART principle in lesson delivery",
        "integrate the institutional core values to the lessons": "integrating institutional core values into the lesson",
        "design test/quarter/assignments and other assessment tasks that are corrector-based": "designing corrector-based assessment tasks and assignments",
        # PEAC Teacher Actions
        "applied knowledge of content within and across curriculum teaching areas": "applying knowledge of content within and across curriculum teaching areas",
        "used a range of teaching strategies that enhance learner achievement in literacy and numeracy skills": "using a range of teaching strategies that enhance learner achievement in literacy and numeracy skills",
        "applied a range of teaching strategies to develop critical and creative thinking, as well as other higher-order thinking skills": "applying a range of teaching strategies to develop critical and creative thinking and higher-order thinking skills",
        "managed classroom structure to engage learners, individually or in groups, in meaningful exploration, discovery and hands-on activities": "managing classroom structure to engage learners in meaningful exploration, discovery and hands-on activities",
        "managed learner behavior constructively by applying positive and non-violent discipline to ensure learning focused environments": "managing learner behavior constructively through positive and non-violent discipline",
        "used differentiated, developmentally appropriate learning experiences to address learners gender, needs, strengths, interests": "using differentiated, developmentally appropriate learning experiences to address learner needs, strengths and interests",
        # PEAC Student Learning Actions
        "worked together with other students towards achieving the tilo(s)": "working collaboratively with other students towards achieving the TILOs",
        "shared ideas and responded to questions enthusiastically": "sharing ideas and responding to questions enthusiastically",
        "performed the given learning tasks with enthusiasm and interest": "performing the given learning tasks with enthusiasm and interest",
        "demonstrated awareness and practice of appropriate behavior inside the classroom": "demonstrating awareness and practice of appropriate classroom behavior",
        "applied learning in real life situations through authentic performance tasks": "applying learning in real-life situations through authentic performance tasks",
        "prepared instructional materials and assessment tools with clear directions": "preparing instructional materials and assessment tools with clear directions",
        "designed, selected, organized, and used diagnostic, formative and summative assessment": "designing, selecting, organizing and using diagnostic, formative and summative assessment",
        "monitored and provided interventions to learners achieving the tilos": "monitoring and providing interventions to learners to help achieve the TILOs",
    }
    lower_cleaned = cleaned.lower().strip(" .;,:-")
    for pattern_key, replacement in _CRITERION_PHRASE_MAP.items():
        if lower_cleaned == pattern_key or lower_cleaned.startswith(pattern_key):
            return replacement

    # Strip subject prefixes (ISO: "The teacher", PEAC: "The teacher"/"The students")
    cleaned = re.sub(r"^[Tt]he teacher\s+", "", cleaned)
    cleaned = re.sub(r"^[Tt]he students?\s+", "students ", cleaned)

    # Handle "is/are [verb]ed" patterns (e.g., "TILO are clearly presented")
    cleaned = re.sub(r"^(.+?)\s+(?:is|are)\s+(clearly\s+)?(?:presented|introduced|demonstrated|integrated)\b",
                     lambda m: f"the {m.group(2) or ''}{m.group(0).split()[-1].rstrip('.')} of {m.group(1).lower()}".replace("  ", " "),
                     cleaned)

    # Convert leading verbs to gerund form
    cleaned = re.sub(r"^[Uu]ses\s+", "using ", cleaned)
    cleaned = re.sub(r"^[Uu]tilizes\s+", "utilizing ", cleaned)
    cleaned = re.sub(r"^[Dd]emonstrates\s+", "demonstrating ", cleaned)
    cleaned = re.sub(r"^[Ee]xplains\s+", "explaining ", cleaned)
    cleaned = re.sub(r"^[Aa]dapts\s+", "adapting ", cleaned)
    cleaned = re.sub(r"^[Ee]ncourages\s+", "encouraging ", cleaned)
    cleaned = re.sub(r"^[Dd]esigns?\s+", "designing ", cleaned)
    cleaned = re.sub(r"^[Ii]ntegrates?\s+", "integrating ", cleaned)
    cleaned = re.sub(r"^[Ff]ocuses\s+", "focusing on ", cleaned)
    cleaned = re.sub(r"^[Ff]acilitates?\s+", "facilitating ", cleaned)
    cleaned = re.sub(r"^[Rr]ecalls? and connects?\s+", "recalling and connecting ", cleaned)
    cleaned = re.sub(r"^[Rr]ecall and connects\s+", "connecting ", cleaned)
    cleaned = re.sub(r"^[Cc]ommunicates\s+", "communicating ", cleaned)
    cleaned = re.sub(r"^[Mm]onitors\s+", "monitoring ", cleaned)
    cleaned = re.sub(r"^[Pp]rovides\s+", "providing ", cleaned)
    cleaned = re.sub(r"^[Mm]anages\s+", "managing ", cleaned)
    cleaned = re.sub(r"^[Pp]rocesses\s+", "processing ", cleaned)
    cleaned = re.sub(r"^[Cc]onducts?\s+", "conducting ", cleaned)
    cleaned = re.sub(r"^[Ii]ntroduces\s+", "introducing ", cleaned)
    cleaned = re.sub(r"^[Aa]ids\s+", "aiding ", cleaned)
    cleaned = re.sub(r"^[Ss]peaks\s+", "speaking ", cleaned)
    cleaned = re.sub(r"^[Aa]pplied\s+", "applying ", cleaned)
    cleaned = re.sub(r"^[Aa]pplies\s+", "applying ", cleaned)
    cleaned = re.sub(r"^[Ww]orked\s+", "working ", cleaned)
    cleaned = re.sub(r"^[Ss]hared\s+", "sharing ", cleaned)
    cleaned = re.sub(r"^[Pp]erformed\s+", "performing ", cleaned)
    cleaned = re.sub(r"^[Dd]emonstrated\s+", "demonstrating ", cleaned)
    cleaned = re.sub(r"^[Pp]repared\s+", "preparing ", cleaned)
    cleaned = re.sub(r"^[Uu]sed\s+", "using ", cleaned)
    cleaned = re.sub(r"^[Mm]anaged\s+", "managing ", cleaned)
    cleaned = re.sub(r"^[Dd]esigned,?\s+", "designing ", cleaned)

    # Fix dangling "and find/ask" after gerund conversion (e.g., "monitoring ... and find ways")
    cleaned = re.sub(r"\band find\b", "and finding", cleaned)
    cleaned = re.sub(r"\band ask\b", "and asking", cleaned)

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

    # Prefer items with typed comments, fall back to items with criterion_text
    candidates = [item for item in prioritized if item.get("comment") and item.get("domain") == target_domain]
    if not candidates:
        candidates = [item for item in prioritized if item.get("comment")]
    if not candidates:
        candidates = [item for item in prioritized if item.get("criterion_text") and item.get("domain") == target_domain]
    if not candidates:
        candidates = [item for item in prioritized if item.get("criterion_text")]

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
    # Check if criterion phrase (or its root words) is already in the comment
    if lower_phrase in lower_comment:
        return summarized_comment
    # Also check overlap: if >60% of phrase words appear in comment, skip appending
    phrase_words = set(re.findall(r'[a-z]{3,}', lower_phrase))
    comment_words = set(re.findall(r'[a-z]{3,}', lower_comment))
    if phrase_words and len(phrase_words & comment_words) / len(phrase_words) > 0.6:
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
            return _normalize_sentence(f"the lesson reflected that {clause}")
        return _normalize_sentence(f"a positive instructional practice was noted where {clause}")

    if field_name == "areas_for_improvement":
        # When the comment is actually a criterion text (starts with verb), frame as area to strengthen
        if re.match(r"^(uses|speaks|facilitates|demonstrates|explains|adapts|encourages|integrates|focuses|recall|the)\b", clause, re.IGNORECASE):
            verb_to_gerund = {
                "uses": "using", "speaks": "speaking", "facilitates": "facilitating",
                "demonstrates": "demonstrating", "explains": "explaining", "adapts": "adapting",
                "encourages": "encouraging", "integrates": "integrating", "focuses": "focusing on",
            }
            first_word = clause.split()[0].lower()
            if first_word in verb_to_gerund:
                rest = clause[len(first_word):].strip()
                clause = f"{verb_to_gerund[first_word]} {rest}"
            return _normalize_sentence(f"the teacher could further strengthen {clause}")
        return _normalize_sentence(f"it is worth noting that {clause}")

    return _normalize_sentence(f"a recommended focus area is to address how {clause}")


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
    lesson_context = context["lesson_context"] or "the next lesson"
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
            f"For the next {lesson_context} lesson, address the most critical criterion first because it received a rating of {rating:.0f}"
        )

    return _normalize_sentence(f"For the next {lesson_context} lesson, address the most critical criterion first")


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


def _build_dataset_entries(form_type: str = "") -> List[Dict[str, Any]]:
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
            templates = retrieval_system.fetch_templates(field_name, form_type=form_type)
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


def _ensure_dataset_embeddings(form_type: str = "") -> Tuple[List[Dict[str, Any]], np.ndarray]:
    entries = _build_dataset_entries(form_type=form_type)
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
    form_type = _effective_form_type(req)
    dataset, embeddings = _ensure_dataset_embeddings(form_type=form_type)
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
    # Prefer items with evaluator-typed comments
    field_comments = [item for item in prioritized if item["comment"] and item["domain"] == target_domain]
    if not field_comments:
        field_comments = [item for item in prioritized if item["comment"]]

    # If still empty, fall back to criterion_text (indicator descriptions) for items with ratings
    if not field_comments:
        field_comments = [item for item in prioritized if item.get("criterion_text") and item["domain"] == target_domain]
        if not field_comments:
            field_comments = [item for item in prioritized if item.get("criterion_text")]

    if field_name == "strengths":
        field_comments.sort(key=lambda item: (-float(item.get("priority") or 0), -float(item["rating"]), item["index"]))
    else:
        field_comments.sort(key=lambda item: (-float(item.get("priority") or 0), float(item["rating"]), item["index"]))

    texts = [item["comment"] or item.get("criterion_text", "") for item in field_comments]
    return _dedupe_preserve_order(texts)[:3]


def _compose_field_query(req: GenerateRequest, comments: List[Dict[str, Any]], field_name: str, target_domain_override: str = "") -> str:
    """Build a clean, domain-focused SBERT query for semantic matching against seed evaluation_comments.
    Prioritizes the actual highest/lowest-rated indicator criterion texts for better retrieval accuracy.
    When target_domain_override is provided, uses that domain instead of the default strongest/weakest."""
    sig = _evaluation_signature(req)
    relevant = _relevant_comments_for_field(req, comments, field_name)
    strongest = sig["strongest"]
    weakest = sig["weakest"]

    # Pick the target domain
    if target_domain_override:
        target_domain = target_domain_override
    elif field_name == "strengths":
        target_domain = strongest
    else:
        target_domain = weakest

    # Get the actual highest/lowest-rated indicator criterion texts
    domain_items = [c for c in comments if c.get("criterion_text") and c.get("domain") == target_domain]
    if field_name == "strengths":
        domain_items.sort(key=lambda c: (-float(c.get("rating") or 0), c.get("index", 0)))
    else:
        domain_items.sort(key=lambda c: (float(c.get("rating") or 0), c.get("index", 0)))

    # Build query from the actual top/bottom-rated criterion texts first
    top_criteria = [c["criterion_text"] for c in domain_items[:2] if c.get("criterion_text")]

    if top_criteria:
        # Use actual indicator texts as the primary query — much more accurate than generic keywords
        if field_name == "strengths":
            prompt = f"Teacher demonstrates strong performance in: {'; '.join(top_criteria)}."
        elif field_name == "areas_for_improvement":
            prompt = f"Teacher needs improvement in: {'; '.join(top_criteria)}."
        else:
            prompt = f"Recommendations to improve: {'; '.join(top_criteria)}."
    else:
        # Fall back to generic domain keywords only when no criterion texts exist
        keywords = DOMAIN_QUERY_KEYWORDS.get(target_domain, target_domain.lower())
        if field_name == "strengths":
            prompt = f"Teacher demonstrates strong {keywords}."
        elif field_name == "areas_for_improvement":
            prompt = f"Teacher needs improvement in {keywords}."
        else:
            prompt = f"Recommendations to improve {keywords}."

    # Add evaluator-typed comments as additional evidence
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
        r"\btarget practice\b",
        r"\bvisible in future observations\b",
        r"\bappropriate when\b",
        r"\bmore noticeable during the lesson\b",
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

    normalized = re.sub(r"\bthis made the teaching practice more noticeable during the lesson\b", "", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bmore noticeable during the lesson\b", "", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bthe practice is consistently visible\b", "", normalized, flags=re.IGNORECASE)
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
        "A clear strength in the lesson was that",
        "The teacher consistently demonstrated that",
        "One notable aspect of the lesson was that",
        "A commendable instructional practice was that",
        "The lesson reflected strong capability in that",
    ],
    "areas_for_improvement": [
        "An area that would benefit from further development is",
        "One area that could be strengthened is",
        "There is room for growth in the area where",
        "Further attention is needed in",
        "A growth area worth prioritizing is that",
    ],
    "recommendations": [
        "It is recommended to",
        "Moving forward, it would be beneficial to",
        "A recommended next step would be to",
        "To support continued growth, consider",
        "To strengthen future lessons, it is suggested to",
    ],
}

FIELD_CONNECTORS = {
    "strengths": [
        "This was particularly clear when",
        "This contributed positively to the lesson as",
        "This strength was further reflected when",
        "In addition,",
    ],
    "areas_for_improvement": [
        "This was particularly noticeable when",
        "This became apparent during moments when",
        "Specifically,",
        "This surfaced when",
    ],
    "recommendations": [
        "This approach can be effective because",
        "Additionally,",
        "This would be beneficial since",
        "Alongside this,",
    ],
}

FIELD_CLOSERS = {
    "strengths": [
        "Sustaining this practice will continue to enhance the overall quality of instruction.",
        "Continued application of this strength will benefit both lesson delivery and student learning.",
        "This practice positively contributes to the learning environment and should be maintained.",
    ],
    "areas_for_improvement": [
        "With intentional focus, this area has strong potential for growth in future lessons.",
        "Addressing this area will contribute to a more well-rounded instructional practice.",
        "Consistent attention to this aspect can lead to noticeable improvement in upcoming lessons.",
    ],
    "recommendations": [
        "Implementing this step can lead to measurable improvement in future lessons.",
        "Taking this action can strengthen instructional effectiveness and student engagement over time.",
        "This recommendation, when applied consistently, can positively impact teaching and learning outcomes.",
    ],
}

SAFE_SYNONYM_BANK = {
    "clear": ["clear", "well-articulated", "easy to follow"],
    "clearly": ["clearly", "effectively", "in a well-structured manner"],
    "consistent": ["consistent", "steady", "dependable"],
    "consistently": ["consistently", "dependably", "with regularity"],
    "focused": ["focused", "purposeful", "intentional"],
    "timely": ["timely", "prompt", "well-timed"],
}

FIELD_STYLE_RULES = {
    "strengths": {
        "lead_verbs": ["demonstrates", "exhibits", "displays", "maintains"],
        "tone_words": ["effective", "commendable", "noteworthy", "well-executed"],
    },
    "areas_for_improvement": {
        "lead_verbs": ["needs to develop", "would benefit from strengthening", "should work on", "could improve"],
        "tone_words": ["developing", "inconsistent", "emerging", "needs attention"],
    },
    "recommendations": {
        "lead_verbs": ["consider implementing", "explore strategies for", "focus on developing", "work toward strengthening"],
        "tone_words": ["practical", "achievable", "meaningful", "targeted"],
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
            # Replace the last sentence with a criterion-specific one to stay concise
            result_sents = _split_sentences(result)
            if field_name == "strengths":
                criterion_sent = f"This was particularly evident in {phrase}."
            elif field_name == "areas_for_improvement":
                criterion_sent = f"This is most apparent in {phrase}."
            else:
                criterion_sent = f"Priority attention should be given to {phrase}."
            if len(result_sents) >= 3:
                result_sents[-1] = criterion_sent
            else:
                result_sents.append(criterion_sent)
            result = " ".join(result_sents[:3])

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

    # Collect clean feedback texts from retrieved items — dedup by core content
    candidates: List[str] = []
    seen = set()
    seen_cores = set()
    for item in retrieved:
        text = _normalize_sentence(item.get("feedback_text") or item.get("text") or "")
        if not text or len(text.split()) < 8:
            continue
        key = _comment_fingerprint(text)
        core_key = _core_content_fingerprint(text)
        if not key or key in seen:
            continue
        if core_key and core_key in seen_cores:
            continue
        seen.add(key)
        if core_key:
            seen_cores.add(core_key)
        candidates.append(text)

    if not candidates:
        return ""  # Empty string lets caller fall back to criterion-based text

    # Prefer unseen candidates
    unseen = [c for c in candidates if _comment_fingerprint(c) not in previously_shown]
    pool = unseen if unseen else candidates
    selected = pool[rng.randint(0, len(pool) - 1)]
    return _strip_filler_sentences(_trim_to_sentences(selected, 3))


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
    # When domains are tied, collect items from all tied domains instead of just one
    tied_key = "all_strongest" if field_name == "strengths" else "all_weakest"
    tied_domains = sig.get(tied_key, [target_domain])
    if len(tied_domains) > 1:
        domain_items = [item for item in prioritized if item["comment"] and item.get("domain") in tied_domains]
    else:
        domain_items = [item for item in prioritized if item["comment"] and item["domain"] == target_domain]
    general_items = [item for item in prioritized if item["comment"]]
    subject_phrase = context["subject"] or "the lesson"
    lesson_context = context["lesson_context"] or subject_phrase
    department_phrase = context["department"] or "the department"
    criterion_phrases = _criterion_phrases_for_field(req, comments, field_name)

    # When no typed comments exist, build criterion-based items from ratings + criterion_text
    if not domain_items and not general_items:
        if len(tied_domains) > 1:
            domain_criterion_items = [item for item in prioritized if item.get("criterion_text") and item.get("domain") in tied_domains]
        else:
            domain_criterion_items = [item for item in prioritized if item.get("criterion_text") and item["domain"] == target_domain]
        if field_name == "strengths":
            domain_criterion_items.sort(key=lambda c: (-float(c.get("rating") or 0), c.get("index", 0)))
        else:
            domain_criterion_items.sort(key=lambda c: (float(c.get("rating") or 0), c.get("index", 0)))
        for item in domain_criterion_items[:2]:
            item["comment"] = item["criterion_text"]
        domain_items = domain_criterion_items[:2]
        if not domain_items:
            all_criterion_items = [item for item in prioritized if item.get("criterion_text")]
            if field_name == "strengths":
                all_criterion_items.sort(key=lambda c: (-float(c.get("rating") or 0), c.get("index", 0)))
            else:
                all_criterion_items.sort(key=lambda c: (float(c.get("rating") or 0), c.get("index", 0)))
            for item in all_criterion_items[:2]:
                item["comment"] = item["criterion_text"]
            general_items = all_criterion_items[:2]

    if field_name == "strengths":
        chosen_source = domain_items or general_items
        chosen = [
            _merge_comment_with_criterion(item["comment"], _criterion_phrase(item), field_name)
            for item in chosen_source[:2]
        ]
        chosen = _dedupe_preserve_order([item for item in chosen if item])[:2]
        if not chosen:
            if criterion_phrases:
                return f"The teacher demonstrated notable strength in {target_domain.lower()} during {subject_phrase}, particularly in {criterion_phrases[0]}."
            return f"The teacher demonstrated notable strength in {target_domain.lower()} during {subject_phrase}."
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
                return f"An identified area for improvement in {subject_phrase} is {criterion_phrases[0]} within {target_domain.lower()}."
            return f"An identified area for improvement in {subject_phrase} is {target_domain.lower()}."
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
                fallback = f"It is recommended to provide targeted support in {criterion_phrases[0]} to foster visible improvement in future {lesson_context} lessons."
            else:
                fallback = f"It is recommended to provide targeted support in {target_domain.lower()} to foster visible improvement in future {lesson_context} lessons."
            return _normalize_whitespace(f"{top_issue_sentence} {fallback}".strip())

        tail = _normalize_whitespace(
            f"Recommended actions for {department_phrase}: {' '.join(chosen)}"
        )
        return _normalize_whitespace(f"{top_issue_sentence} {tail}".strip())

    raise ValueError(f"Unsupported AI-assisted field: {field_name}")


def _retrieve_form_feedback(req: GenerateRequest, comments: List[Dict[str, Any]]) -> Tuple[Dict[str, str], Dict[str, List[Dict[str, Any]]]]:
    retrieval_system = _load_feedback_retrieval_system()
    form_type = _effective_form_type(req)
    sig = _evaluation_signature(req)

    # When domains are tied, query additional domains for variety
    all_strongest = sig.get("all_strongest", [sig["strongest"]])
    all_weakest = sig.get("all_weakest", [sig["weakest"]])
    domains_tied = len(all_strongest) > 1 or len(all_weakest) > 1

    queries = {
        "strengths": _normalize_whitespace(req.strengths or "") or _compose_field_query(req, comments, "strengths"),
        "areas_for_improvement": _normalize_whitespace(req.improvement_areas or "") or _compose_field_query(req, comments, "areas_for_improvement"),
        "recommendations": _normalize_whitespace(req.recommendations or "") or _compose_field_query(req, comments, "recommendations"),
    }

    # If domains are tied, build additional queries for other tied domains
    extra_queries: Dict[str, List[str]] = {"strengths": [], "areas_for_improvement": [], "recommendations": []}
    if domains_tied:
        for domain in all_strongest[1:]:
            q = _compose_field_query(req, comments, "strengths", target_domain_override=domain)
            if q:
                extra_queries["strengths"].append(q)
        for domain in all_weakest[1:]:
            for fn in ("areas_for_improvement", "recommendations"):
                q = _compose_field_query(req, comments, fn, target_domain_override=domain)
                if q:
                    extra_queries[fn].append(q)

    try:
        matched_top = retrieval_system.retrieve_top_feedback_for_form(queries, top_k=10, form_type=form_type)
    except Exception:
        matched_top = {}
        for field_name, query in queries.items():
            try:
                matched_top[field_name] = retrieval_system.retrieve_top_feedback(field_name, query, top_k=10, form_type=form_type)
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

    # When domains are tied, retrieve from additional domains and merge results
    if domains_tied:
        for field_name, extra_qs in extra_queries.items():
            for eq in extra_qs:
                try:
                    extra_matches = retrieval_system.retrieve_top_feedback(field_name, eq, top_k=5, form_type=form_type)
                except Exception:
                    extra_matches = []
                existing_fps = {_comment_fingerprint(m.get("feedback_text", "")) for m in field_specific_matches[field_name]}
                for match in extra_matches:
                    ft = getattr(match, "feedback_text", None)
                    if ft and _comment_fingerprint(ft) not in existing_fps:
                        field_specific_matches[field_name].append({
                            "feedback_text": ft,
                            "evaluation_comment": match.evaluation_comment,
                            "category": _field_target_category(req, field_name),
                            "source": f"mysql:{field_name}:tied_domain",
                            "similarity": match.similarity,
                        })
                        existing_fps.add(_comment_fingerprint(ft))

    # Filter retrieved seed items to exclude domains not in the evaluation focus
    focus = _parse_evaluation_focus(req)
    print(f"[RETRIEVE_FORM] focus={focus} fields={list(queries.keys())} pre_filter_counts={{fn: len(items) for fn, items in field_specific_matches.items()}}")
    if focus:
        field_specific_matches = {
            field_name: _filter_retrieved_by_focus(items, focus)
            for field_name, items in field_specific_matches.items()
        }
    print(f"[RETRIEVE_FORM] post_filter_counts={{fn: len(items) for fn, items in field_specific_matches.items()}}")

    # Filter out templates that contradict actual indicator ratings
    all_comments = _flatten_comments(req)
    max_scale = 4.0 if _is_peac_request(req) else 5.0
    pre_contradiction_counts = {fn: len(items) for fn, items in field_specific_matches.items()}
    field_specific_matches = {
        field_name: _filter_by_rating_relevance(items, all_comments, field_name, max_scale)
        for field_name, items in field_specific_matches.items()
    }
    post_contradiction_counts = {fn: len(items) for fn, items in field_specific_matches.items()}
    print(f"[RETRIEVE_FORM] rating_contradiction_filter: before={pre_contradiction_counts} after={post_contradiction_counts}")

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


def _opener_key(text: str, n_words: int = 4) -> str:
    """Extract the first N words as a lowercase key for opener comparison."""
    words = text.split()
    return " ".join(words[:n_words]).lower() if len(words) >= n_words else text.lower()


def _has_same_opener(a: str, b: str) -> bool:
    """Check if two texts share the same opening phrase (first 4+ words)."""
    if not a or not b:
        return False
    # Check 4-word prefix match first (catches "Throughout the class, the...")
    if _opener_key(a, 4) == _opener_key(b, 4):
        return True
    # Also check 3-word match for shorter openers ("The teacher demonstrates...")
    if _opener_key(a, 3) == _opener_key(b, 3):
        return True
    return False


# Rewrite alternatives: maps an opener prefix to a list of replacement openers.
# Each entry provides the number of prefix words to replace and the alternatives.
_OPENER_REWRITES: List[Tuple[str, int, List[str]]] = [
    ("throughout the class,", 3, [
        "During the lesson,",
        "Across the class session,",
        "Over the course of the lesson,",
    ]),
    ("throughout the lesson,", 3, [
        "During the class,",
        "Across the lesson period,",
        "In the course of the lesson,",
    ]),
    ("a key aspect", 3, [
        "A notable element",
        "An important feature",
        "A significant part",
    ]),
    ("a notable element", 3, [
        "A key aspect",
        "An important feature",
        "A significant strength",
    ]),
    ("based on lesson", 3, [
        "Drawing from classroom",
        "As reflected in the",
        "From the lesson",
    ]),
    ("upon review of", 3, [
        "After examining",
        "Looking at",
        "From a review of",
    ]),
    ("the teacher uses", 3, [
        "The instructor demonstrates",
        "Notably, the teacher employs",
        "It was evident that the teacher uses",
    ]),
    ("the teacher maintains", 3, [
        "The instructor sustains",
        "Notably, the teacher keeps",
        "It was clear that the teacher maintains",
    ]),
    ("the teacher delivers", 3, [
        "The instructor presents",
        "Notably, the teacher carries out",
        "It was evident that the teacher delivers",
    ]),
    ("the teacher speaks", 3, [
        "The instructor communicates",
        "Notably, the teacher expresses ideas",
        "It was apparent that the teacher speaks",
    ]),
    ("a clear strength", 3, [
        "A distinct strength",
        "A noteworthy strength",
        "An evident strength",
    ]),
    ("an area that", 3, [
        "One aspect that",
        "A point that",
        "A dimension that",
    ]),
    ("it is recommended", 3, [
        "It would be beneficial",
        "A practical next step is",
        "It is suggested",
    ]),
    ("a significant part", 3, [
        "A key element",
        "An important aspect",
        "A notable observation",
    ]),
]


def _vary_sentence_starters(sentences: List[str]) -> List[str]:
    """Rewrite sentences sharing the same opening phrase to add natural variety."""
    if len(sentences) < 2:
        return sentences

    result: List[str] = []
    used_openers: List[str] = []  # Track opener keys of already-added sentences
    rewrite_counters: Dict[str, int] = {}  # Track which alternative index to use per opener

    for s in sentences:
        # Check if this sentence shares an opener with any already-added sentence
        needs_rewrite = False
        for existing_opener in used_openers:
            if _has_same_opener(s, existing_opener):
                needs_rewrite = True
                break

        if needs_rewrite:
            # Find a matching rewrite rule
            lower_s = s.lower()
            rewritten = False
            for prefix, n_words, alts in _OPENER_REWRITES:
                if lower_s.startswith(prefix):
                    counter = rewrite_counters.get(prefix, 0)
                    if counter < len(alts):
                        words = s.split()
                        rest = " ".join(words[n_words:])
                        if rest:
                            new_text = f"{alts[counter]} {rest[0].lower() + rest[1:]}"
                        else:
                            new_text = alts[counter]
                        result.append(_normalize_sentence(new_text))
                        rewrite_counters[prefix] = counter + 1
                        rewritten = True
                        break
            if not rewritten:
                result.append(s)
        else:
            result.append(s)

        used_openers.append(s)

    return result


# ── Opener randomization pool ─────────────────────────────────────────
# Large pool of natural openers for each option's first sentence.
# These are shuffled per-request so output never feels repetitive.
_RANDOMIZABLE_OPENERS: List[Tuple[re.Pattern, int]] = [
    # Each tuple: (pattern matching the opener, number of words to replace)
    # -- Seed: "A/An [adj] [noun] of/in/from ..."  (tail consumed by _detect_opener_boundary)
    (re.compile(r"^(?:An? (?:key aspect|notable element|notable observation|notable strength|significant part|significant element|important feature|important finding|clear strength|distinct strength|noteworthy strength|evident strength|commendable aspect|positive pattern|defining quality|well-demonstrated practice|consistent practice|effective element|key instructional strength|developing area|gap in instructional consistency|dimension to focus|area of consistent strength|area that could be strengthened))\b", re.IGNORECASE), None),
    # -- Seed: "Based on / Drawing from / As reflected"
    (re.compile(r"^(?:Based on (?:lesson|the lesson|classroom) evidence,?\s*|Drawing from (?:the )?classroom evidence,?\s*|As reflected in the lesson,?\s*|From the (?:lesson )?evidence[^,]*,?\s*)", re.IGNORECASE), None),
    # -- Seed: "Throughout / During / Across / Over"
    (re.compile(r"^(?:Throughout the (?:classroom visit|class|lesson),?\s*|During the lesson,?\s*|Across the (?:class session|lesson segments),?\s*|Over the course of the lesson,?\s*|In the course of the lesson,?\s*)", re.IGNORECASE), None),
    # -- Seed: "Upon review / After examining / Looking at / From a review"
    (re.compile(r"^(?:Upon review of the lesson,?\s*|After examining the lesson,?\s*|Looking at (?:the )?(?:overall )?lesson(?: delivery)?,?\s*|From a review of the lesson,?\s*)", re.IGNORECASE), None),
    # -- Seed: "The teacher/instructor/educator [verb]"
    (re.compile(r"^(?:The (?:teacher|instructor|educator)(?:'s practice reflected that\s*| (?:uses|maintains|delivers|speaks|demonstrates|employs|communicates)))\b", re.IGNORECASE), None),
    # -- Seed: "The lesson/classroom evidence [verb] that"
    (re.compile(r"^(?:The (?:lesson|classroom evidence) (?:clearly (?:reflected|showed)|provided clear evidence|demonstrated|indicated|also showed) that\s*)", re.IGNORECASE), None),
    # -- Seed: "It became/was [state]"
    (re.compile(r"^(?:It (?:became apparent|was evident|was clear|was consistently (?:noted|observed)|was noted))\b", re.IGNORECASE), None),
    # -- Seed: "From the perspective of"
    (re.compile(r"^(?:From the perspective of (?:instructional delivery|the (?:lesson|PEAC standards)|classroom practice),?\s*)", re.IGNORECASE), None),
    # -- Seed: "As the lesson unfolded"
    (re.compile(r"^(?:As the lesson unfolded,?\s*it became evident that\s*)", re.IGNORECASE), None),
    # -- Seed: "One of the distinguishing"
    (re.compile(r"^(?:One of the (?:clear strengths observed|distinguishing features of the lesson) was that\s*)", re.IGNORECASE), None),
    # -- Seed: reflection phrases
    (re.compile(r"^(?:As indicated by the performance indicators,?\s*|Considering the full scope of the lesson,?\s*|With (?:attention|regard) to instructional practice,?\s*)", re.IGNORECASE), None),
    # -- Pool openers that may already be in text (strengths pool + generic)
    (re.compile(r"^(?:Evidence from the lesson confirmed that\s*|Among the strengths noted in the lesson,?\s*|Classroom evidence (?:showed|suggests|indicated|supported) that\s*|The (?:instructional (?:delivery|approach) (?:showed|reflected|effectively demonstrated) that\s*|teaching practice consistently reflected that\s*|lesson (?:reflected (?:strong practice|effective practice)|showed sustained competence) in (?:how|which)\s*))", re.IGNORECASE), None),
    (re.compile(r"^(?:A (?:particularly strong|well-established|distinguishing) (?:element|practice|feature) (?:of|in) the (?:lesson|instructional practice) was that\s*|An evident strength in instructional delivery was that\s*|Consistent classroom evidence indicated that\s*|The teacher's pedagogical approach demonstrated that\s*|The classroom evidence supported that\s*|It was consistently evident throughout the lesson that\s*)", re.IGNORECASE), None),
    # -- Improvement pool openers
    (re.compile(r"^(?:An (?:area (?:that (?:could be strengthened involves|warrants further professional development is (?:that\s*)?)|with clear potential for improvement is (?:that\s*)?|where more (?:deliberate|intentional) practice (?:would be beneficial involves|is needed is (?:that\s*)?))|aspect (?:requiring focused effort involves|of instruction that merits focused attention is (?:that\s*)?))|emerging area for (?:development|instructional development) is (?:that\s*)?|opportunity for (?:growth|professional growth) was noted (?:where|in how)\s*)", re.IGNORECASE), None),
    (re.compile(r"^(?:One aspect that (?:needs further development|could be strengthened|requires continued attention) is (?:that\s*)?)", re.IGNORECASE), None),
    (re.compile(r"^(?:A (?:point that merits (?:attention|further development) is (?:that\s*)?|dimension of instruction requiring focused effort is (?:that\s*)?|developing area in the instructional practice is (?:that\s*)?|key area for instructional growth is (?:that\s*)?))", re.IGNORECASE), None),
    (re.compile(r"^(?:Room for (?:improvement|measured improvement) was noted (?:in how|where)\s*|Further (?:refinement|development) is needed in\s*)", re.IGNORECASE), None),
    (re.compile(r"^(?:The (?:lesson (?:data (?:indicates|reflects) that\s*|indicated (?:that\s*|a need for (?:development|growth) in\s*)|reflected a need for (?:more intentional focus on|growth in (?:that\s*)?))|evidence suggests (?:that\s*|a need for))|Instructional evidence points to a growth area (?:in|where)\s*|Classroom evidence suggests (?:that\s*|a need to strengthen\s*))", re.IGNORECASE), None),
    (re.compile(r"^(?:It was noted during the lesson that\s*|Upon review of instructional practice,?\s*|From the instructional evidence gathered,?\s*|Based on the lesson evidence,?\s*|There is scope for strengthening\s*)", re.IGNORECASE), None),
    # -- Recommendation pool openers
    (re.compile(r"^(?:It is recommended (?:to\s*|that the teacher\s*)|A practical next step would be to\s*|The teacher is encouraged to\s*|Moving forward,?\s*(?:it would (?:help|be beneficial) to\s*)?|To strengthen future (?:lessons|instructional practice),?\s*|An (?:actionable step|evidence-based recommendation) is to\s*|For continued (?:growth|professional growth),?\s*|A (?:focused improvement strategy|recommended (?:practice|instructional adjustment)) (?:is|would be) to\s*|To address this area effectively,?\s*|Going forward,?\s*(?:consider\s*)?|One productive (?:strategy|approach) would be to\s*|To (?:improve|improve instructional effectiveness) in this area,?\s*|A concrete (?:next step|recommendation) is to\s*|For the next lesson cycle,?\s*(?:consider\s*)?|To build on current (?:progress|practice),?\s*|An effective approach would be to\s*|Consider (?:prioritizing|implementing a strategy to)\s*|To (?:raise|raise the quality of) (?:performance|instruction) in this area,?\s*|As (?:a (?:next step|targeted next step)),?\s*|To demonstrate growth in this area,?\s*|For measurable improvement,?\s*)", re.IGNORECASE), None),
    # -- Critical indicator openers
    (re.compile(r"^(?:A clear strength in the lesson was\s*|A distinct strength observed was\s*|A noteworthy strength in the lesson was\s*|An area that would benefit from focused attention is\s*|It would be beneficial to strengthen\s*)", re.IGNORECASE), None),
    # -- Notably / Other
    (re.compile(r"^(?:Notably,?\s*)", re.IGNORECASE), None),
]

_OPENER_POOL_STRENGTHS = [
    "The lesson clearly demonstrated that",
    "A notable area of instructional strength was that",
    "It was consistently evident throughout the lesson that",
    "A well-established practice in the lesson was that",
    "The instructional delivery reflected that",
    "A commendable aspect of the observed lesson was that",
    "Evidence from the lesson confirmed that",
    "The lesson provided clear evidence that",
    "A defining quality of the instructional practice was that",
    "The teacher's pedagogical approach demonstrated that",
    "Consistent classroom evidence indicated that",
    "A well-demonstrated instructional practice was that",
    "The lesson reflected effective practice in which",
    "An evident strength in instructional delivery was that",
    "A distinguishing feature of the lesson was that",
    "The lesson showed sustained competence in how",
    "The instructional approach effectively demonstrated that",
    "A particularly strong element of the lesson was that",
    "The teaching practice consistently reflected that",
    "The classroom evidence supported that",
]

_OPENER_POOL_IMPROVEMENT = [
    "Upon review of instructional practice,",
    "An area that warrants further professional development is that",
    "One aspect that requires continued attention is that",
    "Based on the lesson evidence,",
    "A dimension of instruction requiring focused effort is that",
    "From the instructional evidence gathered,",
    "The lesson indicated that",
    "An area with clear potential for improvement is that",
    "Classroom evidence suggests that",
    "A developing area in the instructional practice is that",
    "An aspect of instruction that merits focused attention is that",
    "The lesson data indicates that",
    "An area where more intentional practice is needed is that",
    "Room for measured improvement was noted where",
    "The evidence suggests that",
    "An emerging area for instructional development is that",
    "The lesson reflected a need for growth in that",
    "Instructional evidence points to a growth area where",
    "It was noted during the lesson that",
    "A key area for instructional growth is that",
]

_OPENER_POOL_RECOMMENDATION = [
    "It is recommended that the teacher",
    "A practical next step would be to",
    "The teacher is encouraged to",
    "To strengthen future instructional practice,",
    "For continued professional growth,",
    "A focused improvement strategy would be to",
    "To address this area effectively,",
    "One productive approach would be to",
    "To improve instructional effectiveness in this area,",
    "A concrete recommendation is to",
    "For the next lesson cycle, consider",
    "To build on current practice,",
    "An evidence-based recommendation is to",
    "To raise the quality of instruction,",
    "As a targeted next step,",
    "A recommended instructional adjustment is to",
    "Moving forward, it would be beneficial to",
    "To demonstrate growth in this area,",
    "For measurable improvement,",
    "Consider implementing a strategy to",
]


def _detect_opener_boundary(text: str) -> int:
    """Find where the 'opener' phrase ends and the substantive content begins.
    Returns the character index where the core content starts."""
    for pattern, _ in _RANDOMIZABLE_OPENERS:
        m = pattern.match(text)
        if m:
            end = m.end()
            # Many seed openers have trailing filler tails like
            # "of the lesson was that" or "during the lesson that"
            # that the initial regex didn't capture.  Consume them here.
            remaining = text[end:]
            tail = re.match(
                r"\s*(?:"
                r"of the (?:observed )?(?:lesson|class)\s+(?:was|demonstrated|is)\s+that"
                r"|during the (?:observed )?(?:lesson|class)\s+(?:was\s+)?that"
                r"|during the lesson\s+that"
                r"|from the observation\s+was\s+that"
                r"|in the (?:observed )?(?:lesson|class)\s+was(?:\s+that)?"
                r"|observed\s+was(?:\s+that)?"
                r"|of the (?:observed )?lesson was that"
                r")\s*",
                remaining, re.IGNORECASE
            )
            if tail:
                end += tail.end()
            # Skip trailing whitespace and commas after the opener
            while end < len(text) and text[end] in " ,":
                end += 1
            return end
    return 0


def _randomize_openers(options: List[str], rng: random.Random, field_name: str = "") -> List[str]:
    """Replace each option's opener with a randomly-selected unique opener.
    Uses the request-seeded RNG for deterministic but varied output."""
    if not options:
        return options

    # Pick pool based on field_name (authoritative) rather than guessing
    if field_name == "recommendations":
        pool = _OPENER_POOL_RECOMMENDATION
    elif field_name == "areas_for_improvement":
        pool = _OPENER_POOL_IMPROVEMENT
    else:
        pool = _OPENER_POOL_STRENGTHS

    result: List[str] = []
    used_opener_keys: set = set()

    # Shuffle pool once, deterministically for this request
    shuffled = list(pool)
    rng.shuffle(shuffled)
    pool_idx = 0

    for text in options:
        boundary = _detect_opener_boundary(text)
        if boundary == 0:
            # No recognized opener — keep as-is
            result.append(text)
            used_opener_keys.add(_opener_key(text, 4))
            continue

        core = text[boundary:]
        if not core.strip():
            result.append(text)
            continue

        # Pick the next opener that doesn't clash with already-used openers
        chosen = None
        for i in range(len(shuffled)):
            candidate_opener = shuffled[(pool_idx + i) % len(shuffled)]
            key = _opener_key(candidate_opener, 3)
            if key not in used_opener_keys:
                chosen = candidate_opener
                pool_idx = (pool_idx + i + 1) % len(shuffled)
                break

        if chosen is None:
            result.append(text)
            used_opener_keys.add(_opener_key(text, 4))
            continue

        # Stitch: opener + core content
        core_start = core.lstrip()
        if core_start:
            joined = f"{chosen} {core_start[0].lower() + core_start[1:]}"
        else:
            joined = chosen

        result.append(_normalize_sentence(joined))
        used_opener_keys.add(_opener_key(chosen, 3))

    return result


def _extract_core_content(text: str) -> str:
    """Strip all opener phrases from feedback text to isolate the substantive content.
    This is critical for dedup: two texts with different openers but identical
    core content (e.g. 'the teacher uses a clear voice...') must be treated as duplicates."""
    boundary = _detect_opener_boundary(text)
    core = text[boundary:].strip() if boundary > 0 else text.strip()
    # Also strip common transitional connectors at the start
    core = re.sub(
        r'^(?:that\s+|it\s+(?:was|is|became)\s+(?:clear|evident|apparent|noted)\s+that\s+)',
        '', core, flags=re.IGNORECASE
    ).strip()
    return core


def _core_content_fingerprint(text: str) -> str:
    """Fingerprint based on core content only, ignoring openers."""
    core = _extract_core_content(text)
    return _comment_fingerprint(core)


def _is_filler_sentence(sentence: str) -> bool:
    """Detect generic filler sentences that add no specific evaluative value.
    These are the template padding sentences (sentences 2 and 3) that use
    generic encouragement language rather than specific feedback."""
    lower = sentence.strip().lower()
    _FILLER_STARTS = [
        "this is an achievable goal",
        "a small but consistent adjustment",
        "while not a critical concern",
        "this area has potential and is close to becoming",
        "improvement in this area would likely be evident",
        "the focus should be on making this practice more consistent",
        "with minor adjustments, this practice can transition",
        "addressing this area directly will likely lead",
        "this is a practical and manageable area for professional growth",
        "sustained attention to this aspect of instruction",
        "students appeared engaged and responsive, suggesting",
        "the classroom atmosphere reflected a well-managed environment",
        "this contributed to a structured and productive learning",
        "the consistency of the teacher helped maintain focus",
        "there was clear evidence that established routines supported",
        "the classroom atmosphere told a positive story",
        "small, consistently applied routines tend to produce",
        "this step can realistically be incorporated",
        "it may be helpful to implement one strategy at a time",
        "this recommendation is most effective when paired with regular",
        "the aim is to develop this into a habitual teaching practice",
        "this was reflected in the delivery",
        "this contributed to a clearer sense of direction",
        "this made the teaching practice more apparent",
        "this supported a more organized and student-centered",
        "this provided stronger evidence of intentional",
        "this can make the targeted improvement more visible",
        "this contributed positively to the overall",
        "this represents a tangible opportunity for professional",
        "gradual and deliberate improvement",
        "targeted effort here can bridge the gap",
        "making this a priority can lead to measurable progress",
        "with sustained attention, this area can develop",
        "focusing on this area will help create more balance",
        "strengthening this area can positively impact",
        "consistent attention to this area",
        "consistent attention to this aspect",
        "the purposeful structure of activities helped",
        "this reinforced a purposeful and well-structured",
        "this should support stronger learner response",
        "this reinforced a structured and purposeful",
        "the deliberate use of",
        "this approach reinforced",
        "this helped create a more focused",
        "this pattern reinforced",
        "this further supported",
        "this practice contributed meaningfully to the overall",
        "this strengthened the alignment between",
        "the practice contributed meaningfully",
        "this approach contributed to a more",
        "this had a positive effect on",
        # PEAC-specific filler patterns
        "students seemed genuinely invested in the tasks",
        "students appeared engaged and responsive",
        "this practice is worth sustaining",
        "the purposeful application of this practice contributed",
        "strengthening this aspect of instruction would enhance",
        "focusing on this dimension of instruction will contribute",
        "with intentional focus, this practice can move",
        "targeted effort in this area can close the gap",
        "starting with one focused adjustment and building",
        "this practical adjustment supports stronger alignment",
        "when applied consistently over several lessons",
        "this step supports the broader institutional goal",
        "this practice reflects a growing alignment",
        "this supports the broader aims of the peac",
        "this aligns with the peac expectation",
        "this practical step can produce measurable",
        "this supports the broader institutional goal",
        "the teacher's practice in this area reflects",
        "implementing this recommendation can help",
        "this practical strategy supports",
        "this aligns well with",
        "this focused effort can",
        "a deliberate focus on this",
    ]
    for start in _FILLER_STARTS:
        if lower.startswith(start):
            return True
    return False


def _strip_filler_sentences(text: str) -> str:
    """Remove generic filler sentences from template output, keeping only
    substantive feedback content. Returns the cleaned text with at least
    the first sentence preserved."""
    sents = _split_sentences(text)
    if len(sents) <= 1:
        return text
    kept = [sents[0]]  # Always keep the first (substantive) sentence
    for s in sents[1:]:
        if not _is_filler_sentence(s):
            kept.append(s)
    return " ".join(kept)


# ── Rating Context: enrich output with evaluator's actual ratings ─────
_INDICATOR_MATCH_STOP_WORDS = {
    "students", "student", "teacher", "unit", "standards", "competencies",
    "learning", "lesson", "class", "classroom", "instruction", "instructional",
    "performance", "practice", "teaching", "actions", "towards", "achieve",
    "achieving", "achievement", "support", "effective", "effectively",
    "with", "that", "this", "from", "their", "they", "able", "during",
}


def _match_indicator_for_text(
    feedback_text: str,
    comments: List[Dict[str, Any]],
) -> Optional[Dict[str, Any]]:
    """Find the indicator whose criterion_text best matches the feedback text.
    Returns the full comment dict or None."""
    text_words = set(re.findall(r'[a-z]{4,}', feedback_text.lower())) - _INDICATOR_MATCH_STOP_WORDS
    if not text_words:
        return None
    best_item = None
    best_score = 0.0
    for c in comments:
        crit = _normalize_whitespace(c.get("criterion_text") or "")
        if not crit or float(c.get("rating") or 0) <= 0:
            continue
        crit_words = set(re.findall(r'[a-z]{4,}', crit.lower())) - _INDICATOR_MATCH_STOP_WORDS
        if not crit_words:
            continue
        score = len(text_words & crit_words) / len(crit_words)
        if score > best_score and score > 0.25:
            best_score = score
            best_item = c
    return best_item


def _detect_text_domain(text: str) -> str:
    """Detect which domain a feedback text best belongs to using keyword matching."""
    best_domain = ""
    best_hits = 0
    for dname in _DOMAIN_FILTER_KEYWORDS:
        hits = _count_domain_keyword_matches(text, dname)
        if hits > best_hits:
            best_hits = hits
            best_domain = dname
    return _normalize_domain_name(best_domain) if best_domain else ""


def _build_rating_context_sentence(
    feedback_text: str,
    comments: List[Dict[str, Any]],
    field_name: str,
    req: GenerateRequest,
    option_idx: int = 0,
) -> str:
    """Build a narrative opinion sentence based on the evaluator's assessment
    for the indicator that best matches the feedback text. No raw numbers —
    written as an evaluator's professional observation."""
    matched = _match_indicator_for_text(feedback_text, comments)
    if not matched:
        return ""

    rating = float(matched.get("rating") or 0)
    if rating <= 0:
        return ""

    domain = matched.get("domain") or "General"
    max_scale = 4.0 if _is_peac_request(req) else 5.0

    # Get domain info
    sig = _evaluation_signature(req)
    domain_scores = sig.get("domains", {})

    # When domains are tied, use keyword-based detection from the actual feedback text
    # instead of the matched indicator's domain (which may not reflect the text's topic)
    all_weakest = sig.get("all_weakest", [])
    all_strongest = sig.get("all_strongest", [])
    domains_tied = (field_name == "strengths" and len(all_strongest) > 1) or \
                   (field_name != "strengths" and len(all_weakest) > 1)
    if domains_tied:
        detected = _detect_text_domain(feedback_text)
        if detected:
            domain = detected

    domain_lower = domain.lower()
    canonical_domain = domain
    for d_name in domain_scores:
        d_lower = d_name.lower()
        if d_lower == domain_lower or d_lower.startswith(domain_lower[:10]) or domain_lower.startswith(d_lower[:10]):
            canonical_domain = d_name
            break

    overall_label = _score_band(float(req.averages.overall or 0), max_scale).lower()
    cd = canonical_domain.lower()

    # Determine relative performance level for narrative language
    is_top = rating >= max_scale
    is_high = rating >= (max_scale - 1)
    is_mid = rating >= (max_scale / 2)

    if field_name == "strengths":
        pool = []
        if is_top:
            pool = [
                f"The evaluator's assessment confirmed that this is one of the teacher's most well-developed instructional competencies, reflecting consistent and exemplary practice in {cd}.",
                f"This aspect of instruction stood out as a particular strength, demonstrating the teacher's thorough command of effective practice within {cd}.",
                f"Consistently strong evidence of this practice was noted throughout the lesson, underscoring the teacher's proficiency in {cd}.",
            ]
        elif is_high:
            pool = [
                f"This was identified as a reliable area of competence in the evaluator's assessment, contributing positively to the teacher's overall {overall_label} instructional performance.",
                f"The evaluator recognized solid proficiency in this area, which supports the teacher's effective delivery across {cd}.",
                f"This practice was evident throughout the lesson and reflects a dependable instructional competency within the teacher's overall {overall_label} performance.",
            ]
        else:
            pool = [
                f"The evaluator recognized this as a developing strength that, with sustained practice, can further enhance the quality of instruction within {cd}.",
                f"While still emerging, this practice shows promise and can be further cultivated to strengthen the teacher's overall instructional delivery.",
                f"The evaluator noted early signs of competence in this area that, with continued development, will contribute meaningfully to {cd}.",
            ]
        return pool[option_idx % len(pool)]

    elif field_name == "areas_for_improvement":
        pool = []
        if rating < (max_scale / 2):
            pool = [
                f"The evaluator's assessment highlighted this as the most critical area requiring focused professional development, as it significantly impacts the overall quality of {cd}.",
                f"This was identified as a priority concern in the evaluator's assessment, suggesting that targeted intervention in {cd} could yield substantial instructional gains.",
                f"The evaluator's findings point to this as a foundational area needing immediate and sustained attention to improve the quality of {cd}.",
            ]
        elif is_mid:
            pool = [
                f"Based on the evaluator's assessment, this area shows room for meaningful growth and would benefit from deliberate, sustained attention to strengthen the teacher's practice in {cd}.",
                f"The evaluator identified an opportunity for professional growth here, noting that focused development in this area would support stronger overall instruction in {cd}.",
                f"This aspect of instruction was noted by the evaluator as having clear potential for improvement, which would contribute to more effective practice in {cd}.",
            ]
        else:
            pool = [
                f"While the evaluator noted competence in this area, there remains an opportunity for refinement that could elevate the overall instructional quality.",
                f"The evaluator acknowledged adequate performance here, though further refinement would help the teacher reach a higher standard of instructional practice.",
                f"This area, though functional, was identified by the evaluator as having untapped potential for growth that would benefit overall instructional effectiveness.",
            ]
        return pool[option_idx % len(pool)]

    else:  # recommendations
        pool = []
        if rating < (max_scale / 2):
            pool = [
                f"The evaluator's findings indicate that prioritizing this recommendation would directly address one of the most significant gaps identified in {cd} and support measurable instructional improvement.",
                f"Acting on this recommendation is essential, as the evaluator identified this as a critical area in {cd} where improvement would have the greatest impact.",
                f"This recommendation addresses a core area of concern highlighted by the evaluator, and implementing it would meaningfully strengthen practice in {cd}.",
            ]
        elif is_mid:
            pool = [
                f"Implementing this recommendation would respond to the evaluator's assessment and contribute to strengthening the teacher's overall instructional effectiveness in {cd}.",
                f"This recommendation reflects the evaluator's professional judgment and, if applied consistently, would support continued growth in {cd}.",
                f"The evaluator's assessment supports this recommendation as a practical step toward strengthening instructional quality in {cd}.",
            ]
        else:
            pool = [
                f"This recommendation aligns with the evaluator's overall assessment and, if consistently applied, would further elevate the quality of instruction.",
                f"Following through on this recommendation would build upon the teacher's existing competencies and further enhance overall instructional delivery.",
                f"The evaluator's assessment suggests this as a refinement opportunity that, if pursued, would contribute to sustained professional growth.",
            ]
        return pool[option_idx % len(pool)]


def _build_domain_rating_summary(
    field_name: str,
    req: GenerateRequest,
    option_idx: int = 0,
    feedback_text: str = "",
) -> str:
    """Build a narrative fallback sentence using domain-level assessment context
    when no specific indicator matched. Written as evaluator opinion.
    When domains are tied, uses keyword detection from feedback_text to pick the
    matching domain instead of mechanical rotation."""
    sig = _evaluation_signature(req)
    max_scale = sig.get("max_scale", 5.0)
    overall_label = _score_band(float(req.averages.overall or 0), max_scale).lower()

    if field_name == "strengths":
        all_strongest = sig.get("all_strongest", [sig.get("strongest", "")])
        # Use keyword detection when domains are tied and feedback text is available
        if len(all_strongest) > 1 and feedback_text:
            detected = _detect_text_domain(feedback_text)
            strongest = detected if detected else all_strongest[option_idx % len(all_strongest)]
        else:
            strongest = all_strongest[option_idx % len(all_strongest)] if all_strongest else sig.get("strongest", "")
        if strongest:
            pool = [
                f"The evaluator's assessment identified {strongest.lower()} as the teacher's most well-developed domain, reflecting consistent and effective instructional practice that positively contributes to the overall {overall_label} teaching performance.",
                f"Among all domains evaluated, {strongest.lower()} emerged as the area where the teacher demonstrated the most consistent and effective instructional practice.",
                f"The evaluator noted that the teacher's strongest instructional performance was in {strongest.lower()}, which positively contributed to the overall {overall_label} assessment.",
            ]
            return pool[option_idx % len(pool)]
    elif field_name in ("areas_for_improvement", "recommendations"):
        all_weakest = sig.get("all_weakest", [sig.get("weakest", "")])
        # Use keyword detection when domains are tied and feedback text is available
        if len(all_weakest) > 1 and feedback_text:
            detected = _detect_text_domain(feedback_text)
            weakest = detected if detected else all_weakest[option_idx % len(all_weakest)]
        else:
            weakest = all_weakest[option_idx % len(all_weakest)] if all_weakest else sig.get("weakest", "")
        if weakest:
            if field_name == "areas_for_improvement":
                pool = [
                    f"The evaluator's assessment indicated that {weakest.lower()} is the domain most in need of focused professional development, representing a key opportunity to strengthen the teacher's overall instructional effectiveness.",
                    f"Among the domains assessed, {weakest.lower()} was identified as the area with the most room for professional growth and instructional refinement.",
                    f"The evaluator highlighted {weakest.lower()} as the domain where targeted development efforts would have the greatest positive impact on the teacher's practice.",
                ]
            else:
                pool = [
                    f"Based on the evaluator's assessment, sustained improvement efforts in {weakest.lower()} would yield the most significant gains in the teacher's overall instructional quality and professional growth.",
                    f"Focusing professional development on {weakest.lower()} would address the evaluator's key findings and support meaningful instructional improvement.",
                    f"The evaluator's assessment suggests that prioritizing growth in {weakest.lower()} would contribute most effectively to the teacher's overall professional advancement.",
                ]
            return pool[option_idx % len(pool)]
    return ""


def _enrich_with_rating_context(
    text: str,
    comments: List[Dict[str, Any]],
    field_name: str,
    req: GenerateRequest,
    option_idx: int = 0,
) -> str:
    """After filler stripping, enrich the feedback text with a narrative
    context sentence summarizing the evaluator's assessment.
    Ensures the output is 2-3 sentences: substantive feedback + opinion context."""
    sents = _split_sentences(text)
    # If already 3+ sentences of non-filler content, no enrichment needed
    if len(sents) >= 3:
        return text
    # Build and append narrative context — try specific indicator first, then domain-level
    context_sent = _build_rating_context_sentence(text, comments, field_name, req, option_idx)
    if not context_sent:
        context_sent = _build_domain_rating_summary(field_name, req, option_idx, feedback_text=text)
    if context_sent:
        return f"{text} {context_sent}"
    return text


def _trim_to_sentences(text: str, max_sentences: int = 3) -> str:
    """Trim text to at most max_sentences sentences."""
    sents = _split_sentences(text)
    if len(sents) <= max_sentences:
        return text
    return " ".join(sents[:max_sentences])


# ── Banned-word sanitizer ──────────────────────────────────────────────
# Final pass to strip any lingering banned words/phrases from generated text
_BANNED_PHRASE_PATTERNS = [
    (re.compile(r"\bDuring the observation,?\s*", re.IGNORECASE), ""),
    (re.compile(r"\bBased on the classroom observation,?\s*", re.IGNORECASE), ""),
    (re.compile(r"\bAs observed during the class,?\s*", re.IGNORECASE), ""),
    (re.compile(r"\bIt was noted that\s*", re.IGNORECASE), ""),
    (re.compile(r"\bThe evaluation reveals that\s*", re.IGNORECASE), ""),
    (re.compile(r"\bThe evaluation indicates that\s*", re.IGNORECASE), ""),
    (re.compile(r"\bThe observation indicates that\s*", re.IGNORECASE), ""),
    (re.compile(r"\bAs noted in the observation,?\s*", re.IGNORECASE), ""),
    (re.compile(r"\bDuring the observed lesson,?\s*", re.IGNORECASE), ""),
    (re.compile(r"\bFrom the evidence gathered during the observation,?\s*", re.IGNORECASE), ""),
    (re.compile(r"\bAcross the observed lesson segments,?\s*", re.IGNORECASE), ""),
    (re.compile(r"\bUpon observation,?\s*", re.IGNORECASE), ""),
    (re.compile(r"\bUpon evaluation,?\s*", re.IGNORECASE), ""),
    (re.compile(r"\bthe observation\b", re.IGNORECASE), "the lesson"),
    (re.compile(r"\bobservation\b", re.IGNORECASE), "lesson"),
    (re.compile(r"\bthe observed lesson\b", re.IGNORECASE), "the lesson"),
    (re.compile(r"\bevaluation\b", re.IGNORECASE), "review"),
    (re.compile(r"\bindicates\b", re.IGNORECASE), "reflects"),
    (re.compile(r"\breveals\b", re.IGNORECASE), "reflects"),
    (re.compile(r"\bshows\b", re.IGNORECASE), "demonstrates"),
]


def _sanitize_banned_words(text: str) -> str:
    """Strip all banned words/phrases from final output text."""
    result = text
    for pattern, replacement in _BANNED_PHRASE_PATTERNS:
        result = pattern.sub(replacement, result)
    # Fix capitalization after removals that leave lowercase starts
    result = re.sub(r"(?<=[.!?]\s)([a-z])", lambda m: m.group(1).upper(), result)
    # Fix start of string
    if result and result[0].islower():
        result = result[0].upper() + result[1:]
    # Clean double spaces
    result = re.sub(r"\s{2,}", " ", result).strip()
    return result


def _make_three_options(base_text: str, req: GenerateRequest, field_name: str, retrieved: List[Dict[str, Any]]) -> List[str]:
    """Build exactly 3 distinct, concise suggestion options.

    Each option uses a single feedback text trimmed to 2-3 sentences.
    The 3 options come from different indicators for variety.
    Sentence starters are varied to avoid repetitive patterns."""
    # Filter retrieved items by evaluation focus before building options
    focus = _parse_evaluation_focus(req)
    if focus:
        retrieved = _filter_retrieved_by_focus(retrieved, focus)

    # Filter out templates that contradict actual indicator ratings
    all_comments = _flatten_comments(req)
    max_scale = 4.0 if _is_peac_request(req) else 5.0
    retrieved = _filter_by_rating_relevance(retrieved, all_comments, field_name, max_scale)

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

    # Collect unique, trimmed feedback texts — dedup by core content, not just full text
    candidates: List[Dict[str, Any]] = []
    seen_fp: set = set()
    seen_core_fp: set = set()
    for item in retrieved:
        text = _normalize_sentence(item.get("feedback_text") or item.get("text") or "")
        if not text or len(text.split()) < 5:
            continue
        # Trim to 3 sentences max, strip generic filler
        text = _strip_filler_sentences(_trim_to_sentences(text, 3))
        fp = _comment_fingerprint(text)
        core_fp = _core_content_fingerprint(text)
        if not fp or fp in seen_fp:
            continue
        # Skip if core content (opener-stripped) matches an already-added candidate
        if core_fp and core_fp in seen_core_fp:
            continue
        seen_fp.add(fp)
        if core_fp:
            seen_core_fp.add(core_fp)
        # Detect which domain this candidate belongs to (for distribution across tied domains)
        best_domain = ""
        best_domain_hits = 0
        for dname in _DOMAIN_FILTER_KEYWORDS:
            hits = _count_domain_keyword_matches(text, dname)
            if hits > best_domain_hits:
                best_domain_hits = hits
                best_domain = dname
        candidates.append({"text": text, "domain": best_domain})

    rng.shuffle(candidates)

    # Prefer unseen candidates
    unseen = [c for c in candidates if _comment_fingerprint(c["text"]) not in previously_shown]
    pool = unseen if unseen else candidates

    # Check if domains are tied (all scores equal)
    sig = _evaluation_signature(req)
    tied_domains = sig.get("all_strongest", []) if field_name == "strengths" else sig.get("all_weakest", [])
    distribute_across_domains = len(tied_domains) > 1

    # Build indicator signatures from the request's ratings for indicator-level dedup
    all_comments = _flatten_comments(req)
    _INDICATOR_STOP_WORDS = {
        "students", "student", "teacher", "unit", "standards", "competencies",
        "learning", "lesson", "class", "classroom", "instruction", "instructional",
        "performance", "practice", "teaching", "actions", "towards", "achieve",
        "achieving", "achievement", "support", "effective", "effectively",
        "with", "that", "this", "from", "their", "they", "able", "during",
    }
    indicator_sigs = []
    for c in all_comments:
        crit = _normalize_whitespace(c.get("criterion_text") or "")
        if crit:
            sig_words = set(re.findall(r'[a-z]{4,}', crit.lower())) - _INDICATOR_STOP_WORDS
            indicator_sigs.append(sig_words)

    def _best_indicator_match(text_str: str) -> int:
        """Return the index of the indicator whose criterion best matches this text, or -1."""
        text_w = set(re.findall(r'[a-z]{4,}', text_str.lower())) - _INDICATOR_STOP_WORDS
        best_idx, best_score = -1, 0.0
        for i, sig_w in enumerate(indicator_sigs):
            if not sig_w:
                continue
            score = len(text_w & sig_w) / len(sig_w)
            if score > best_score and score > 0.25:
                best_score = score
                best_idx = i
        return best_idx

    def _rewrite_opener(text: str, existing_options: List[str]) -> str:
        """Try to rewrite a candidate's opener so it doesn't clash with existing options."""
        lower_text = text.lower()
        for prefix, n_words, alts in _OPENER_REWRITES:
            if lower_text.startswith(prefix):
                for alt in alts:
                    words = text.split()
                    rest = " ".join(words[n_words:])
                    if not rest:
                        continue
                    candidate = f"{alt} {rest[0].lower() + rest[1:]}"
                    candidate = _normalize_sentence(candidate)
                    # Check this rewritten version doesn't clash either
                    clashes = False
                    for existing in existing_options:
                        if _has_same_opener(candidate, existing):
                            clashes = True
                            break
                    if not clashes:
                        return candidate
        return ""  # No rewrite found

    # Pick 3 distinct options, skipping near-duplicates via CORE CONTENT comparison
    # When domains are tied, actively distribute across different domains
    out: List[str] = []
    out_core_fps: set = set()  # Track core-content fingerprints
    out_indicator_matches: List[int] = []  # Track which indicator each option matches
    out_domains: List[str] = []  # Track which domain each option covers
    for candidate_item in pool:
        text = candidate_item["text"]
        candidate_domain = candidate_item["domain"]
        if len(out) >= 3:
            break

        # Domain distribution: when tied, skip candidates from already-covered domains
        # but only if there are still uncovered domains available in remaining pool
        if distribute_across_domains and candidate_domain and candidate_domain in out_domains and len(out) < len(tied_domains):
            # Check if there are any remaining candidates from uncovered domains
            covered = set(out_domains)
            remaining_pool_domains = {c["domain"] for c in pool if c["text"] not in [o for o in out]}
            uncovered = remaining_pool_domains - covered
            if uncovered:
                continue  # Skip this domain; there are uncovered domains left

        # PRIMARY dedup: compare core content (opener-stripped) fingerprints
        text_core_fp = _core_content_fingerprint(text)
        if text_core_fp and text_core_fp in out_core_fps:
            continue  # Same substantive content, different opener — skip

        # SECONDARY dedup: word overlap on core content (catches paraphrases)
        text_core = _extract_core_content(text)
        text_core_words = set(re.sub(r"[^a-z0-9\s]", "", text_core.lower()).split())
        is_too_similar = False
        opener_clash = False
        for existing in out:
            existing_core = _extract_core_content(existing)
            existing_core_words = set(re.sub(r"[^a-z0-9\s]", "", existing_core.lower()).split())
            if not text_core_words or not existing_core_words:
                continue
            # Core-content word overlap (much stricter than full-text)
            overlap = len(text_core_words & existing_core_words) / max(len(text_core_words), len(existing_core_words))
            if overlap > 0.40:
                is_too_similar = True
                break
            # Opener dedup: flag candidates that start with the same 3-4 words
            if _has_same_opener(text, existing):
                opener_clash = True
                break

        # If opener clashes but content is different, try rewriting the opener
        if opener_clash and not is_too_similar:
            rewritten = _rewrite_opener(text, out)
            if rewritten:
                text = rewritten
                opener_clash = False

        if opener_clash:
            is_too_similar = True

        # Indicator-level dedup: skip if this text matches the same indicator as an existing option
        if not is_too_similar and indicator_sigs:
            text_indicator = _best_indicator_match(text)
            if text_indicator >= 0 and text_indicator in out_indicator_matches:
                is_too_similar = True

        if not is_too_similar:
            out.append(text)
            if text_core_fp:
                out_core_fps.add(text_core_fp)
            out_indicator_matches.append(_best_indicator_match(text))
            out_domains.append(candidate_domain)

    # When no retrieved seeds match (e.g. all filtered by focus),
    # generate 3 variations from base_text which is criterion-based
    if not out and base_text:
        base_trimmed = _trim_to_sentences(base_text, 3)
        out.append(base_trimmed)
        # Create 2 additional variations by sentence reordering/rephrasing
        base_sents = _split_sentences(base_trimmed)
        if len(base_sents) >= 2:
            variant2_sents = base_sents[1:] + base_sents[:1]
            out.append(" ".join(variant2_sents))
        if len(base_sents) >= 3:
            variant3_sents = [base_sents[2]] + [base_sents[0]] + base_sents[1:2]
            out.append(" ".join(variant3_sents))

    # Vary sentence starters across the 3 options
    out = _vary_sentence_starters(out)

    # Forcefully randomize openers so each generation feels fresh
    out = _randomize_openers(out, rng, field_name)

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
    max_scale = 4.0 if _is_peac_request(req) else 5.0
    strengths_options = _make_three_options(strengths_primary, req, "strengths", field_retrieved.get("strengths", []))
    improvement_options = _make_three_options(improvement_primary, req, "areas_for_improvement", field_retrieved.get("areas_for_improvement", []))
    recommendation_options = _make_three_options(recommendations_primary, req, "recommendations", field_retrieved.get("recommendations", []))

    # Ensure at least one option in each category targets the most critical indicator
    strengths_options = _ensure_critical_indicator_mentioned(strengths_options, comments, "strengths", max_scale)
    improvement_options = _ensure_critical_indicator_mentioned(improvement_options, comments, "areas_for_improvement", max_scale)
    recommendation_options = _ensure_critical_indicator_mentioned(recommendation_options, comments, "recommendations", max_scale)

    # Enrich each option with evaluator's rating context (adds rating summary sentence)
    strengths_options = [_enrich_with_rating_context(opt, comments, "strengths", req, i) for i, opt in enumerate(strengths_options)]
    improvement_options = [_enrich_with_rating_context(opt, comments, "areas_for_improvement", req, i) for i, opt in enumerate(improvement_options)]
    recommendation_options = [_enrich_with_rating_context(opt, comments, "recommendations", req, i) for i, opt in enumerate(recommendation_options)]

    # Final dedup safety net: remove any exact duplicate options
    def _final_dedup(options: List[str]) -> List[str]:
        seen = set()
        result = []
        for opt in options:
            fp = _comment_fingerprint(opt)
            if fp not in seen:
                seen.add(fp)
                result.append(opt)
        return result

    strengths_options = _final_dedup(strengths_options)
    improvement_options = _final_dedup(improvement_options)
    recommendation_options = _final_dedup(recommendation_options)

    # Use first option as the primary output
    strengths = strengths_options[0] if strengths_options else strengths_primary
    improvement_areas = improvement_options[0] if improvement_options else improvement_primary
    recommendations = recommendation_options[0] if recommendation_options else recommendations_primary

    # Final sanitization: strip any lingering banned words/phrases
    strengths = _sanitize_banned_words(strengths)
    improvement_areas = _sanitize_banned_words(improvement_areas)
    recommendations = _sanitize_banned_words(recommendations)
    strengths_options = [_sanitize_banned_words(opt) for opt in strengths_options]
    improvement_options = [_sanitize_banned_words(opt) for opt in improvement_options]
    recommendation_options = [_sanitize_banned_words(opt) for opt in recommendation_options]

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
            "dataset_size": len(_build_dataset_entries(form_type=_effective_form_type(req))),
            "model": os.getenv("SBERT_MODEL", "sentence-transformers/all-MiniLM-L6-v2"),
            "generator": "mysql-only-retrieval",
            "overall_band": sig["overall_level"],
            "domain_bands": {domain: _score_band(score, sig.get("max_scale", 5.0)).lower() for domain, score in sig["domains"].items()},
            "feedback_queries": {
                "strengths": _normalize_whitespace(req.strengths or "") or _summarize_comments_for_field(req, comments, "strengths"),
                "areas_for_improvement": _normalize_whitespace(req.improvement_areas or "") or _summarize_comments_for_field(req, comments, "areas_for_improvement"),
                "recommendations": _normalize_whitespace(req.recommendations or "") or _summarize_comments_for_field(req, comments, "recommendations"),
            },
        },
    )
