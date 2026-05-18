# ADCES-SYSTEM AI Architecture: Embeddings & RAG Explanation

## System Overview

Your ADCES system uses a **Retrieval-Augmented Generation (RAG)** approach combined with **SBERT embeddings** to provide intelligent feedback suggestions for teacher evaluations. This system avoids expensive API calls and works entirely on-premise.

---

## Core Architecture 🏗️

### 1. **Embeddings Layer**
**Model:** `sentence-transformers/all-MiniLM-L6-v2`
- Lightweight SBERT model (~33MB)
- Converts text into 384-dimensional dense vectors
- Runs locally on your server (no cloud dependency)
- Generates embeddings for all feedback templates

**Storage:** 
- **Database:** `ai_feedback_templates` (MySQL)
- **Column:** `embedding_vector` (LONGBLOB)
- **Count:** 2,400+ templates currently stored

### 2. **Template Database**
Templates stored with associated embeddings:
```
id: unique identifier
field_name: 'strengths', 'areas_for_improvement', 'recommendations'
evaluation_comment: Teacher's observed behavior (INPUT for matching)
feedback_text: AI-suggested response (OUTPUT returned to user)
embedding_vector: Serialized SBERT embedding
form_type: 'iso' or 'peac' (evaluation form type)
source: 'seed' (origin)
is_active: 1/0 (active status)
created_at, updated_at: timestamps
```

### 3. **RAG Retrieval Process**

**Step 1: Query Generation**
When an evaluator fills an evaluation form, the system:
- Extracts their comments from the form
- Identifies the strongest/weakest performance domains
- Builds a semantic query combining actual criterion texts + comments

**Step 2: Embedding & Similarity Matching**
- Converts query to embedding using SBERT
- Computes **cosine similarity** between query embedding and all template embeddings
- Cosine similarity returns scores 0-1 (higher = more similar)

**Step 3: Template Retrieval**
- Filters results using `DEFAULT_SIMILARITY_THRESHOLD = 0.15`
- Ranks by similarity score (descending)
- Returns top 1-5 matches per field

**Step 4: Result Enhancement**
- Re-ranks by domain relevance
- Adds bonus for exact field/form_type matches (+0.03 to score)
- Deduplicates similar suggestions
- Returns sorted, formatted results to evaluator

---

## Data Flow Diagram

```
Evaluator Input (Comments + Ratings)
    ↓
Query Builder (Identify domain + context)
    ↓
SBERT Encoder (Query → 384-dim vector)
    ↓
Cosine Similarity (Query vs 2400 template embeddings)
    ↓
Threshold Filter (similarity > 0.15)
    ↓
Ranking & Deduplication
    ↓
Format & Return Suggestions
```

---

## Three Evaluation Fields Supported

### **1. Strengths**
- **Purpose:** Highlight what teacher did well
- **Template Count:** ~800 (from 2,400 total)
- **Retrieval Logic:** Finds templates matching highest-rated criteria
- **Example:**
  - Input: "The teacher explained concepts clearly and students participated actively"
  - Retrieved: "The teacher demonstrates strong instructional clarity and effectively engages learners through interactive activities."

### **2. Areas for Improvement** 
- **Purpose:** Identify development needs
- **Template Count:** ~800
- **Retrieval Logic:** Finds templates matching lowest-rated criteria
- **Example:**
  - Input: "Teacher didn't check student understanding during transitions"
  - Retrieved: "Formative checks can be made more visible during transitions between tasks and learner responses."

### **3. Recommendations**
- **Purpose:** Suggest actionable improvements
- **Template Count:** ~800
- **Retrieval Logic:** Finds templates for weak areas with solutions
- **Example:**
  - Input: "Need to improve assessment practices"
  - Retrieved: "Use brief checkpoints and targeted follow-up questions to confirm understanding before moving forward."

**Note:** `agreement` field is intentionally NOT AI-assisted (must remain human-authored)

---

## Supported Form Types

### ISO Form (Traditional)
- Communications (verbal, non-verbal, clarity)
- Lesson Management (routines, transitions, engagement)
- Student Assessment (formative checks, feedback)

### PEAC Form (Philippines DepEd Standard)
- Teacher Actions (planning, delivery, assessment)
- Student Learning Actions (participation, engagement)
- Same recommendations & strengths/improvement structure

---

## Key Metrics in Current System

| Metric | Value | Notes |
|--------|-------|-------|
| **Embedding Dimension** | 384 | SBERT L6 output |
| **Similarity Threshold** | 0.15 | Minimum match quality |
| **Templates per Field** | ~800 | Across 3 supported fields |
| **Total Templates** | 2,400+ | Seeded + user-contributed |
| **Model Size** | ~33 MB | All-MiniLM-L6-v2 |
| **Form Types** | 2 | ISO & PEAC |
| **Active Status** | 2,400/2,400 | All active (1 archived) |

---

## Database Connection

**MySQL Integration:**
- Database: `ai_classroom_eval`
- Table: `ai_feedback_templates`
- Records: 2,400 active templates
- Encoded: All embeddings pre-computed via SBERT
- Caching: Embeddings cached in `.npz` format for performance

---

## Python Components

### Main Modules:
1. **`feedback_retrieval_system.py`** 
   - Core RAG engine
   - Handles SBERT encoding
   - Manages template storage/retrieval
   - Computes cosine similarity

2. **`app.py`** (FastAPI service)
   - REST API for feedback generation
   - Orchestrates retrieval pipeline
   - Query building with domain awareness
   - Similarity scoring & ranking

3. **`seed_peac_feedback_templates.py`**
   - Populates database with templates
   - Generates SBERT embeddings for all
   - Supports `--per-field 200+` scaling

4. **`backfill_embeddings.py`**
   - Updates templates missing embeddings
   - Batch SBERT encoding
   - Used after migrations

5. **`smoke_test.py`**
   - Integration tests
   - Validates FastAPI endpoints
   - Uses fake SBERT for speed

---

## System Accuracy & Testing Framework

Based on your RAG implementation, here are the recommended **accuracy tests** for meeting minutes:

### 1. **Retrieval Accuracy** 
**What to test:** Does the system retrieve relevant templates?

Metrics:
- **Precision@1:** Are the top-retrieved templates relevant? (Target: >85%)
- **Precision@5:** Are top-5 results mostly good? (Target: >70%)
- **Mean Reciprocal Rank (MRR):** How high is the first relevant result? (Target: >0.75)

Test Method:
```
For each of 50 evaluation records:
  1. Extract evaluator's original comments + ratings
  2. Query AI system for suggestions
  3. Compare returned feedback to original evaluation
  4. Rate as "exact match" (100%), "similar" (90%), "relevant" (70%), or "irrelevant" (0%)
  5. Average scores across dataset
```

### 2. **Semantic Similarity Consistency**
**What to test:** Does SBERT cosine similarity correctly rank templates?

Metrics:
- **Similarity Score Distribution:** Mean 0.45-0.65 (Target: >0.50)
- **Threshold Appropriateness:** Verify 0.15 threshold catches 90%+ valid matches
- **Score Variance:** Templates for same domain should have consistent similarity

Test Method:
```
For each field (strengths, areas_for_improvement, recommendations):
  1. Generate 20 synthetic evaluation comments
  2. Retrieve top-5 templates for each
  3. Record similarity scores
  4. Verify scores form expected distribution
  5. Check for outliers or threshold mismatches
```

### 3. **Template Coverage & Diversity**
**What to test:** Are suggestions diverse and domain-specific?

Metrics:
- **Unique Templates Retrieved:** >80% unique across queries
- **Domain Accuracy:** For "communications" weakness, get communications templates (Target: >90%)
- **Form-Type Accuracy:** ISO queries return ISO templates (Target: >95%)

Test Method:
```
1. Query system 100 times with random domain combinations
2. Track unique templates returned
3. Verify domain matching (query domain = template domain)
4. Verify form_type alignment
```

### 4. **Speed & Performance**
**What to test:** Can system handle production loads?

Metrics:
- **Query Latency:** <100ms per retrieval (Target: <50ms)
- **Throughput:** 10+ queries/second
- **Memory Usage:** <500MB for 2,400 templates

Test Method:
```
1. Load full template set (2,400)
2. Run 1000 concurrent queries
3. Measure response time, memory, CPU
4. Verify no failures or timeouts
```

### 5. **Human Evaluation (Gold Standard)**
**What to test:** Do end-users find suggestions helpful?

Metrics:
- **Helpfulness Score:** 1-5 rating (Target: >4.0)
- **Acceptance Rate:** % of suggestions used by evaluators (Target: >70%)
- **Edit Rate:** Average edits per suggestion (Target: <1 edit)

Test Method:
```
1. Deploy system to 10 evaluators
2. Have them evaluate 5 teachers each
3. Record:
   - Did they use the AI suggestion? (Yes/No)
   - Did they edit it? (Yes/No, how much?)
   - Rate helpfulness 1-5
4. Calculate aggregate scores
```

### 6. **Embedding Quality**
**What to test:** Are SBERT embeddings meaningful?

Metrics:
- **Intra-cluster Distance:** Similar comments have <0.3 cosine distance
- **Inter-cluster Distance:** Different domains have >0.5 cosine distance
- **Cluster Coherence:** Comments in same domain cluster together

Test Method:
```
1. Encode all 2,400 templates
2. Sample 100 templates across 3 fields
3. Compute pairwise cosine similarities
4. Verify same-field similarity > 0.50
5. Verify different-field similarity < 0.30
```

### 7. **Bias & Fairness** (Optional)
**What to test:** Are suggestions equally good across all departments?

Metrics:
- **Suggestion Quality by Department:** Similar scores across CCIS, JHS, SHS, CAS, ELEM
- **Template Balance:** Each department represented in suggestions

Test Method:
```
1. Sample evaluations from each department
2. Run retrieval for each
3. Compare quality scores
4. Check for statistically significant differences
```

---

## Recommended Accuracy Test Protocol (Meeting Minutes Template)

### Test Execution Plan:

**Phase 1: Baseline (Week 1)**
- Run Retrieval Accuracy test on 50 evaluations
- Measure Similarity Score Distribution
- Document baseline metrics

**Phase 2: Coverage (Week 2)**
- Test Template Coverage with 100 queries
- Verify Domain Accuracy
- Check Form-Type Alignment

**Phase 3: Performance (Week 2)**
- Load test with 1,000 concurrent queries
- Measure latency, memory, throughput
- Identify bottlenecks

**Phase 4: Human Evaluation (Weeks 3-4)**
- Deploy to 10 evaluators
- Collect 50 evaluations with feedback ratings
- Calculate helpfulness & acceptance metrics

**Phase 5: Analysis & Reporting (Week 4)**
- Aggregate all metrics
- Compare vs targets
- Document findings & recommendations

### Success Criteria:

| Test | Target Metric | Acceptance Threshold |
|------|---------------|----------------------|
| Retrieval Accuracy | Precision@1 | >85% |
| Retrieval Accuracy | Precision@5 | >70% |
| Similarity Scores | Mean Similarity | >0.50 |
| Template Coverage | Unique Templates | >80% |
| Domain Accuracy | Correct Domain | >90% |
| Form-Type Accuracy | Correct Form Type | >95% |
| Speed | Query Latency | <100ms |
| Human Evaluation | Helpfulness Score | >4.0/5.0 |
| Human Evaluation | Acceptance Rate | >70% |

---

## Current System Stats (From Database Analysis)

- **Evaluations Processed:** 6 completed
- **AI Recommendations Generated:** 43 records
- **Template Library:** 2,400 templates
- **Teachers Evaluated:** 93 total, 6 with completed evaluations
- **System Active Since:** March 2026
- **Form Types in Use:** ISO, PEAC

---

## Next Steps for Accuracy Testing:

1. **Set up test harness** - Extract 50-100 representative evaluations
2. **Establish baselines** - Run baseline tests to get current performance
3. **Define gold standard** - Have 5 domain experts rate template relevance (1-10)
4. **Run automated tests** - Execute retrieval accuracy, similarity, coverage tests
5. **Deploy pilot** - Have 5-10 evaluators test with feedback collection
6. **Analyze results** - Compare actual vs target metrics
7. **Iterate** - Adjust threshold, add templates, retrain if needed

---

## Files to Reference

- [Feedback Retrieval README](ai_service/feedback_retrieval_README.md)
- [Main RAG Engine](ai_service/feedback_retrieval_system.py)
- [FastAPI Service](ai_service/app.py)
- [Smoke Tests](ai_service/smoke_test.py)
- [Database: ai_feedback_templates (2,400 records)](ai_classroom_eval)

