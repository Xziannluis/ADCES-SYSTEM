# Retrieval-Based Feedback System

This module provides a retrieval-only feedback system for teacher evaluation forms.

## Fields supported

- `strengths`
- `areas_for_improvement`
- `recommendations`

`agreement` is intentionally excluded from AI retrieval and should remain human-authored.

## Model

- SBERT model: `sentence-transformers/all-MiniLM-L6-v2`

## Storage schema

SQLite table: `feedback_templates`

MySQL table option: `ai_feedback_templates`

Columns:
- `id`
- `field_name`
- `evaluation_comment`
- `feedback_text`
- `embedding_vector`

## Main features

- connect to the database
- store feedback templates with SBERT embeddings
- compute cosine similarity
- retrieve the best matching feedback template for a given evaluation comment
retrieve one best match per AI-assisted evaluation form field

## Files

- `feedback_retrieval_system.py` — main reusable module
- `feedback_retrieval_demo.py` — runnable demo
- `feedback_templates.db` — created automatically on first run
- `seed_mysql_feedback_templates.py` — seeds MySQL with generated template records

## Example

Input:

`The teacher explains lessons clearly but students rarely participate.`

Retrieved feedback:

`The teacher demonstrates strong instructional clarity but could improve student engagement through interactive activities.`

## Run

```powershell
cd c:\Users\Administrator\Documents\xampp\htdocs\ADCES-SYSTEM\ai_service
python feedback_retrieval_demo.py
```

## MySQL seeding

Create the MySQL table with `database/migrations/migrate_add_ai_feedback_templates.php`, then run the Python seeder.

With `--per-field 100`, the seeder generates **300 total templates** across:

- `strengths`
- `areas_for_improvement`
- `recommendations`

`agreement` is not seeded.

## Reuse in code

```python
from feedback_retrieval_system import FeedbackRetrievalSystem

system = FeedbackRetrievalSystem()
system.add_feedback_template(
    field_name="recommendations",
    evaluation_comment="Students need more formative checks during the lesson.",
    feedback_text="Use brief formative checks and follow-up prompts to monitor learner understanding throughout the lesson.",
)

best = system.retrieve_best_feedback(
    field_name="areas_for_improvement",
    evaluation_comment="The teacher explains lessons clearly but students rarely participate.",
)

if best:
    print(best.feedback_text)
```
