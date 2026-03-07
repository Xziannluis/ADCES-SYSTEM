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

from feedback_retrieval_system import FeedbackRetrievalSystem, build_mysql_seed_system


app = FastAPI(title="ADCES AI Service", version="2.0.0")


@app.get("/health")
async def health():
    return {"ok": True}


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
REFERENCE_EVALS_PATH = BASE_PATH / "reference_evaluations.jsonl"
IMPORTED_REFERENCE_EVALS_PATH = BASE_PATH / "reference_evaluations.imported.jsonl"
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

DOMAIN_ACTIONS = {
    "Communication & instruction": [
        "use clear modeling before independent work",
        "add short guided questioning routines",
        "include quick understanding checks before moving on",
        "give concise directions supported by examples",
    ],
    "Classroom management & learning environment": [
        "tighten transition routines between activities",
        "reinforce clear expectations throughout the lesson",
        "use visible time cues to maintain lesson pace",
        "strengthen classroom procedures for group and independent work",
    ],
    "Assessment & feedback practices": [
        "embed brief formative checks during instruction",
        "give immediate feedback linked to lesson targets",
        "use short follow-up prompts to verify learner understanding",
        "provide opportunities for learners to revise after feedback",
    ],
}

OPENERS = [
    "A practical next step is to",
    "To strengthen the next lesson, consider",
    "An effective follow-through action is to",
    "Continued improvement may be supported by",
    "To build on the current evidence, it would help to",
]

CLOSERS = [
    "This can help improve consistency and learner response during class activities.",
    "This may support stronger classroom evidence in the next observation cycle.",
    "This should make the targeted improvement more visible during instruction.",
    "This can strengthen lesson clarity and support better follow-through for learners.",
]

LEADING_PHRASE_SWAPS = {
    "use": ["use", "apply", "incorporate"],
    "add": ["add", "build in", "introduce"],
    "include": ["include", "integrate", "build in"],
    "give": ["give", "provide", "offer"],
    "tighten": ["tighten", "strengthen", "refine"],
    "reinforce": ["reinforce", "clarify", "highlight"],
    "embed": ["embed", "integrate", "plan"],
    "provide": ["provide", "offer", "deliver"],
    "strengthen": ["strengthen", "improve", "refine"],
}

COMMENT_PHRASE_SWAPS = {
    "clear": ["clear", "explicit", "well-defined"],
    "timely": ["timely", "prompt", "immediate"],
    "consistent": ["consistent", "steady", "reliable"],
    "structured": ["structured", "well-organized", "purposeful"],
    "brief": ["brief", "short", "focused"],
    "targeted": ["targeted", "focused", "specific"],
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
    averages: Averages = Field(default_factory=Averages)
    strengths: Optional[str] = ""
    improvement_areas: Optional[str] = ""
    recommendations: Optional[str] = ""
    style: Optional[str] = "standard"


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


class ReferenceEvaluationItem(BaseModel):
    faculty_name: Optional[str] = ""
    department: Optional[str] = ""
    subject_observed: Optional[str] = ""
    observation_type: Optional[str] = ""
    averages: Averages = Field(default_factory=Averages)
    ratings: Dict[str, Union[Dict[str, Any], List[Any]]] = Field(default_factory=dict)
    strengths: str
    improvement_areas: str
    recommendations: str
    source: Optional[str] = "manual"


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
            return RatingItem(rating=float(v.get("rating")), comment=str(v.get("comment") or ""))
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
    return {
        "teacher": _normalize_whitespace(req.faculty_name or "") or "The teacher",
        "subject": _normalize_whitespace(req.subject_observed or "") or "the observed class",
        "observation_type": _normalize_whitespace(req.observation_type or "") or "Classroom observation",
        "department": _normalize_whitespace(req.department or ""),
        "overall_level": _score_band(req.averages.overall),
        "weakest": weakest,
        "strongest": strongest,
        "domains": domains,
    }


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
            comment_rows.append(
                {
                    "domain": domain,
                    "rating": float(item.rating),
                    "comment": comment,
                    "index": idx,
                }
            )
    return comment_rows


def _compose_query_text(req: GenerateRequest, comments: List[Dict[str, Any]]) -> str:
    sig = _evaluation_signature(req)
    fragments = [
        sig["subject"],
        sig["observation_type"],
        f"priority area {sig['weakest']}",
        f"strong area {sig['strongest']}",
        f"overall {sig['overall_level']}",
    ]
    for item in comments:
        if item["comment"]:
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

    def ingest_reference_payload(payload: Dict[str, Any], source: str) -> None:
        ratings = payload.get("ratings") or {}
        for raw_category, items in ratings.items():
            category = _normalize_domain_name(raw_category)
            iterable = list(items.values()) if isinstance(items, dict) else items if isinstance(items, list) else [items]
            for raw in iterable:
                coerced = _coerce_rating_item(raw)
                if coerced and _normalize_whitespace(coerced.comment or ""):
                    add_entry(
                        coerced.comment or "",
                        category,
                        source,
                        {
                            "kind": "rating_comment",
                            "subject": _normalize_whitespace(payload.get("subject_observed") or ""),
                            "observation_type": _normalize_whitespace(payload.get("observation_type") or ""),
                        },
                    )

        signature_source = GenerateRequest(
            faculty_name=payload.get("faculty_name") or "",
            department=payload.get("department") or "",
            subject_observed=payload.get("subject_observed") or "",
            observation_type=payload.get("observation_type") or "",
            ratings=ratings,
            averages=Averages(**(payload.get("averages") or {})),
        )
        signature = _evaluation_signature(signature_source)
        add_entry(
            payload.get("recommendations") or "",
            signature["weakest"],
            source,
            {
                "kind": "recommendation",
                "subject": signature["subject"],
                "observation_type": signature["observation_type"],
                "overall_level": signature["overall_level"],
            },
        )

    retrieval_system = _load_feedback_retrieval_system()
    field_map = {
        "strengths": "Communication & instruction",
        "areas_for_improvement": "Classroom management & learning environment",
        "recommendations": "Assessment & feedback practices",
    }
    for field_name, category in field_map.items():
        try:
            templates = retrieval_system.fetch_templates(field_name)
        except Exception:
            templates = []
        for row in templates:
            add_entry(
                row.get("evaluation_comment") or "",
                category,
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


def _reference_source_summary(items: List[Dict[str, Any]]) -> Dict[str, int]:
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

    weakest = _evaluation_signature(req)["weakest"]
    ranked_indices = np.argsort(scores)[::-1]
    selected: List[Dict[str, Any]] = []
    seen = set()

    for idx in ranked_indices.tolist():
        item = dict(dataset[idx])
        similarity = float(scores[idx])
        if item.get("category") == weakest:
            similarity += 0.05
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


def _choose_variant(word: str, rng: random.Random, bank: Dict[str, List[str]]) -> str:
    lower = word.lower()
    choices = bank.get(lower)
    if not choices:
        return word
    replacement = rng.choice(choices)
    if word[0].isupper():
        replacement = replacement.capitalize()
    return replacement


def _soft_rephrase_comment(text: str, rng: random.Random) -> str:
    sentence = _normalize_sentence(text)
    for src, variants in COMMENT_PHRASE_SWAPS.items():
        pattern = re.compile(rf"\b{re.escape(src)}\b", re.IGNORECASE)
        if pattern.search(sentence) and rng.random() < 0.75:
            sentence = pattern.sub(rng.choice(variants), sentence, count=1)

    sentence = re.sub(r"\bthe teacher\b", rng.choice(["the teacher", "instruction", "classroom practice"]), sentence, count=1, flags=re.IGNORECASE)
    sentence = re.sub(r"\bshould be\b", rng.choice(["can be", "may be", "should be"]), sentence, count=1, flags=re.IGNORECASE)
    sentence = re.sub(r"\bcan be\b", rng.choice(["can be", "may be"]), sentence, count=1, flags=re.IGNORECASE)
    return _normalize_sentence(sentence)


def _build_context_clause(req: GenerateRequest, category: str, rng: random.Random) -> str:
    sig = _evaluation_signature(req)
    clauses = [
        f"for {sig['subject']}",
        f"within {category.lower()}",
        f"as part of follow-through in {sig['weakest'].lower()}",
        "during the next observation cycle",
    ]
    return rng.choice(clauses)


def _combine_comments(base: str, support: str, req: GenerateRequest, category: str, rng: random.Random) -> str:
    base_clean = _soft_rephrase_comment(base, rng).rstrip(".")
    support_clean = _soft_rephrase_comment(support, rng)
    support_clean = support_clean[0].lower() + support_clean[1:] if len(support_clean) > 1 else support_clean.lower()
    opener = rng.choice(OPENERS)
    connector = rng.choice([
        "while also",
        "and at the same time",
        "together with efforts to",
        "alongside routines to",
    ])
    context_clause = _build_context_clause(req, category, rng)
    return _normalize_sentence(f"{opener} {base_clean.lower()} {context_clause}, {connector} {support_clean}")


def _build_action_sentence(category: str, req: GenerateRequest, used_focuses: set[str], rng: random.Random) -> str:
    actions = list(DOMAIN_ACTIONS.get(category, []))
    if not actions:
        actions = list(DOMAIN_ACTIONS[_evaluation_signature(req)["weakest"]])
    rng.shuffle(actions)
    selected = None
    for action in actions:
        focus = _extract_action_focus(action)
        if focus not in used_focuses:
            selected = action
            used_focuses.add(focus)
            break
    if not selected:
        selected = actions[0]

    verb = selected.split(" ", 1)[0]
    remainder = selected.split(" ", 1)[1] if " " in selected else ""
    varied = f"{_choose_variant(verb, rng, LEADING_PHRASE_SWAPS)} {remainder}".strip()
    opener = rng.choice(OPENERS)
    closer = rng.choice(CLOSERS)
    return _normalize_sentence(f"{opener} {varied}.")[:-1] + f" {closer}"


def _fallback_recommendations(req: GenerateRequest, rng: random.Random) -> List[str]:
    weakest = _evaluation_signature(req)["weakest"]
    used_focuses: set[str] = set()
    return [_build_action_sentence(weakest, req, used_focuses, rng) for _ in range(OUTPUT_RECOMMENDATIONS)]


def _generate_recommendation_variations(req: GenerateRequest, retrieved: List[Dict[str, Any]]) -> List[str]:
    sig = _evaluation_signature(req)
    rng = random.Random()
    rng.seed(_stable_seed(sig["teacher"], sig["subject"], datetime.utcnow().isoformat(timespec="seconds"), random.random()))

    if not retrieved:
        return _fallback_recommendations(req, rng)

    weakest = sig["weakest"]
    used_focuses: set[str] = set()
    outputs: List[str] = []
    raw_texts = [item["text"] for item in retrieved]
    category_groups: Dict[str, List[Dict[str, Any]]] = {}
    for item in retrieved:
        category_groups.setdefault(item["category"], []).append(item)

    preferred = category_groups.get(weakest, []) or retrieved

    first = preferred[0]
    used_focuses.add(_extract_action_focus(first["text"]))
    paraphrased = _soft_rephrase_comment(first["text"], rng)
    paraphrased = _normalize_sentence(f"{rng.choice(OPENERS)} {paraphrased[0].lower() + paraphrased[1:] if len(paraphrased) > 1 else paraphrased.lower()}")
    if _comment_fingerprint(paraphrased) not in {_comment_fingerprint(t) for t in raw_texts}:
        outputs.append(paraphrased)
    else:
        outputs.append(_build_action_sentence(first["category"], req, used_focuses, rng))

    combo_candidates = preferred if len(preferred) >= 2 else retrieved
    if len(combo_candidates) >= 2:
        outputs.append(_combine_comments(combo_candidates[0]["text"], combo_candidates[1]["text"], req, combo_candidates[0]["category"], rng))
        used_focuses.add(_extract_action_focus(combo_candidates[1]["text"]))

    outputs.append(_build_action_sentence(weakest, req, used_focuses, rng))

    deduped: List[str] = []
    seen_dataset = {_comment_fingerprint(text) for text in raw_texts}
    seen_output = set()
    for item in outputs:
        normalized = _normalize_sentence(item)
        fingerprint = _comment_fingerprint(normalized)
        if not fingerprint or fingerprint in seen_output or fingerprint in seen_dataset:
            continue
        seen_output.add(fingerprint)
        deduped.append(normalized)

    while len(deduped) < OUTPUT_RECOMMENDATIONS:
        candidate = _build_action_sentence(weakest, req, used_focuses, rng)
        fingerprint = _comment_fingerprint(candidate)
        if fingerprint in seen_output or fingerprint in seen_dataset:
            continue
        seen_output.add(fingerprint)
        deduped.append(candidate)

    return deduped[:OUTPUT_RECOMMENDATIONS]


def _build_strengths(req: GenerateRequest, comments: List[Dict[str, Any]]) -> str:
    sig = _evaluation_signature(req)
    strongest = sig["strongest"]
    matching = [item["comment"] for item in comments if item["comment"] and item["domain"] == strongest]
    evidence = _dedupe_preserve_order(matching)[:2]
    if not evidence:
        evidence = [
            f"Classroom practice was strongest in {strongest.lower()} during the observed lesson",
            f"The overall profile reflects {sig['overall_level'].lower()} performance in {sig['subject']}",
        ]
    pieces = [
        f"The observation reflects {sig['overall_level'].lower()} performance, with the strongest indicators appearing in {strongest.lower()}.",
        _normalize_sentence(evidence[0]),
    ]
    if len(evidence) > 1:
        pieces.append(_normalize_sentence(evidence[1]))
    return " ".join(_dedupe_preserve_order(pieces))


def _build_improvement_areas(req: GenerateRequest, comments: List[Dict[str, Any]]) -> str:
    sig = _evaluation_signature(req)
    weakest = sig["weakest"]
    matching = [item["comment"] for item in comments if item["comment"] and item["domain"] == weakest]
    evidence = _dedupe_preserve_order(matching)[:2]
    pieces = [
        f"The clearest opportunity for refinement is {weakest.lower()}, where further consistency would strengthen the overall lesson experience.",
    ]
    if evidence:
        pieces.append(_normalize_sentence(evidence[0]))
    else:
        pieces.append(_normalize_sentence(f"Additional attention to {weakest.lower()} would help balance current strengths with more consistent follow-through during instruction"))
    if len(evidence) > 1:
        pieces.append(_normalize_sentence(evidence[1]))
    return " ".join(_dedupe_preserve_order(pieces))


def _summarize_comments_for_field(req: GenerateRequest, comments: List[Dict[str, Any]], field_name: str) -> str:
    sig = _evaluation_signature(req)
    field_domain_map = {
        "strengths": sig["strongest"],
        "areas_for_improvement": sig["weakest"],
        "recommendations": sig["weakest"],
    }
    target_domain = field_domain_map[field_name]
    domain_comments = [item["comment"] for item in comments if item["comment"] and item["domain"] == target_domain]
    general_comments = [item["comment"] for item in comments if item["comment"]]

    if field_name == "strengths":
        chosen = _dedupe_preserve_order(domain_comments or general_comments)[:2]
        if not chosen:
            return f"The teacher showed the strongest evidence in {target_domain.lower()} during the observation."
        return " ".join(chosen)

    if field_name == "areas_for_improvement":
        chosen = _dedupe_preserve_order(domain_comments or general_comments)[:2]
        if not chosen:
            return f"The clearest area for improvement is {target_domain.lower()} based on the evaluation profile."
        return " ".join(chosen)

    if field_name == "recommendations":
        chosen = _dedupe_preserve_order(domain_comments or general_comments)[:2]
        if not chosen:
            return f"Provide support in {target_domain.lower()} so improvement becomes more visible in future lessons."
        return " ".join(chosen)

    raise ValueError(f"Unsupported AI-assisted field: {field_name}")


def _retrieve_form_feedback(req: GenerateRequest, comments: List[Dict[str, Any]]) -> Dict[str, str]:
    retrieval_system = _load_feedback_retrieval_system()
    queries = {
        "strengths": _normalize_whitespace(req.strengths or "") or _summarize_comments_for_field(req, comments, "strengths"),
        "areas_for_improvement": _normalize_whitespace(req.improvement_areas or "") or _summarize_comments_for_field(req, comments, "areas_for_improvement"),
        "recommendations": _normalize_whitespace(req.recommendations or "") or _summarize_comments_for_field(req, comments, "recommendations"),
    }

    matched = retrieval_system.retrieve_feedback_for_form(queries)
    return {
        field_name: (matched[field_name].feedback_text if matched[field_name] else queries[field_name])
        for field_name in queries
    }


def _split_sentences(text: str) -> List[str]:
    parts = re.split(r'(?<=[.!?])\s+', _normalize_whitespace(text))
    return [p.strip() for p in parts if p.strip()]


def _ensure_2_3_sentences(text: str, fallback: str = "") -> str:
    sents = _split_sentences(text)
    if len(sents) >= 2:
        return " ".join(sents[:3])
    if len(sents) == 1 and fallback:
        fb = _split_sentences(fallback)
        if fb:
            return f"{sents[0]} {fb[0]}"
    if len(sents) == 1:
        return f"{sents[0]} This should help make the improvement more visible in the next observation."
    return _normalize_sentence(fallback or "This area should be strengthened through consistent, focused classroom practices.")


def _make_three_options(base_text: str, req: GenerateRequest, field_name: str, retrieved: List[Dict[str, Any]]) -> List[str]:
    sig = _evaluation_signature(req)
    rng = random.Random(_stable_seed(field_name, sig["teacher"], sig["subject"], datetime.utcnow().isoformat(timespec="seconds")))
    weak = sig["weakest"]
    strong = sig["strongest"]

    base = _ensure_2_3_sentences(base_text, fallback=f"In {sig['subject']}, this area should be improved through consistent follow-through.")
    opt1 = base

    if field_name == "strengths":
        alt = (
            f"The observed lesson showed strong evidence in {strong.lower()}, especially in classroom clarity and structure. "
            f"Instruction was delivered in a way that supported learner understanding and lesson flow. "
            f"These practices can be sustained in succeeding observations."
        )
    elif field_name == "areas_for_improvement":
        alt = (
            f"The most visible area for improvement is {weak.lower()}, where consistency can still be strengthened. "
            f"Learner participation and follow-through can improve with more inclusive classroom routines. "
            f"This can create stronger evidence of progress in future evaluations."
        )
    else:
        actions = DOMAIN_ACTIONS.get(weak, DOMAIN_ACTIONS["Assessment & feedback practices"])
        rng.shuffle(actions)
        act1 = actions[0]
        act2 = actions[1] if len(actions) > 1 else actions[0]
        alt = (
            f"A practical next step is to {act1}. "
            f"You may also {act2} to reinforce the target area during class activities. "
            f"These actions can improve learner response and instructional consistency."
        )

    opt2 = _ensure_2_3_sentences(alt, fallback=base)

    retrieved_text = ""
    for item in retrieved:
        t = _normalize_sentence(item.get("text", ""))
        if t:
            retrieved_text = t
            break

    if not retrieved_text:
        retrieved_text = "Current classroom evidence suggests that focused follow-through is needed in this area."

    if field_name == "recommendations":
        opt3_raw = (
            f"Based on the observed evidence, prioritize one focused strategy per lesson segment. "
            f"{retrieved_text} "
            f"Monitor the effect weekly and adjust the strategy based on learner response."
        )
    elif field_name == "strengths":
        opt3_raw = (
            f"Classroom evidence confirms positive performance in this domain. "
            f"{retrieved_text} "
            f"This strength can be maintained through consistent instructional routines."
        )
    else:
        opt3_raw = (
            f"Observed evidence indicates this area still needs targeted improvement. "
            f"{retrieved_text} "
            f"A consistent intervention plan can make progress more measurable."
        )

    opt3 = _ensure_2_3_sentences(opt3_raw, fallback=base)

    out: List[str] = []
    seen = set()
    for o in [opt1, opt2, opt3]:
        key = _comment_fingerprint(o)
        if key and key not in seen:
            seen.add(key)
            out.append(o)
    while len(out) < 3:
        out.append(base)
    return out[:3]


@app.post("/generate", response_model=GenerateResponse)
def generate(req: GenerateRequest):
    comments = _flatten_comments(req)
    retrieved = _retrieve_top_comments(req, comments)
    field_feedback = _retrieve_form_feedback(req, comments)
    strengths = field_feedback["strengths"] or _build_strengths(req, comments)
    improvement_areas = field_feedback["areas_for_improvement"] or _build_improvement_areas(req, comments)
    recommendations = field_feedback["recommendations"]

    strengths_options = _make_three_options(strengths, req, "strengths", retrieved)
    improvement_options = _make_three_options(improvement_areas, req, "areas_for_improvement", retrieved)
    recommendation_options = _make_three_options(recommendations, req, "recommendations", retrieved)

    return GenerateResponse(
        strengths=strengths,
        improvement_areas=improvement_areas,
        recommendations=recommendations,
        strengths_options=strengths_options,
        improvement_areas_options=improvement_options,
        recommendations_options=recommendation_options,
        debug={
            "top_comments": retrieved,
            "reference_sources": _reference_source_summary(retrieved),
            "embedding_cache_path": str(EMBEDDINGS_CACHE_PATH),
            "dataset_size": len(_build_dataset_entries()),
            "model": os.getenv("SBERT_MODEL", "sentence-transformers/all-MiniLM-L6-v2"),
            "generator": "retrieval-only",
            "feedback_queries": {
                "strengths": _normalize_whitespace(req.strengths or "") or _summarize_comments_for_field(req, comments, "strengths"),
                "areas_for_improvement": _normalize_whitespace(req.improvement_areas or "") or _summarize_comments_for_field(req, comments, "areas_for_improvement"),
                "recommendations": _normalize_whitespace(req.recommendations or "") or _summarize_comments_for_field(req, comments, "recommendations"),
            },
        },
    )


@app.post("/reference-evaluations")
async def add_reference_evaluation(item: ReferenceEvaluationItem):
    entry = item.model_dump()
    entry["created_at"] = datetime.utcnow().isoformat() + "Z"
    with REFERENCE_EVALS_PATH.open("a", encoding="utf-8") as fh:
        fh.write(json.dumps(entry, ensure_ascii=False) + "\n")
    try:
        if EMBEDDINGS_CACHE_PATH.exists():
            EMBEDDINGS_CACHE_PATH.unlink()
    except Exception:
        pass
    return {"ok": True}
