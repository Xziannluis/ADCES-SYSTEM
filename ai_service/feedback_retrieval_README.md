# Retrieval-Based Feedback System

This module provides a MySQL-backed retrieval and paraphrase system for teacher evaluation forms.

## Fields supported

- `strengths`
- `areas_for_improvement`
- `recommendations`

`agreement` is intentionally excluded from AI retrieval and should remain human-authored.

## Model

- SBERT model: `sentence-transformers/all-MiniLM-L6-v2`

## Live generation source

The live `/generate` flow in ADCES now uses:

- MySQL-stored templates from `ai_feedback_templates`
- retrieval-based matching
- paraphrase/recombination of retrieved dataset text

The live generation pipeline no longer depends on `reference_evaluations.jsonl` files.

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
- `seed_mysql_feedback_templates.py` — seeds MySQL with generated template records
- `generate_feedback_datasets.py` — generates large synthetic JSONL datasets for retrieval tuning and dataset expansion

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

For larger runs, you can scale this up safely:

- `--per-field 700` → **2,100 templates total**
- `--per-field 1000` → **3,000 templates total**
- `--per-field 1600` → **4,800 templates total**

The generator now includes more sentence openers, bridges, and human-style add-on phrases so the output is less repetitive.

## Generate expanded JSONL datasets (offline utility)

If you want thousands of extra examples for your local datasets, generate synthetic JSONL files:

```powershell
cd c:\Users\Administrator\Documents\xampp\htdocs\ADCES-SYSTEM\ai_service
python generate_feedback_datasets.py --count 2500
```

This creates:

- `ai_feedback.synthetic.jsonl`
- `reference_evaluations.synthetic.jsonl`

Each file will contain the requested number of rows, with varied teacher names, departments, subjects, observation types, and more natural feedback phrasing.

If you want around 5,000 new records per file:

```powershell
cd c:\Users\Administrator\Documents\xampp\htdocs\ADCES-SYSTEM\ai_service
python generate_feedback_datasets.py --count 5000
```

## Suggested usage

- Use MySQL seeding when you want more live retrieval templates in the database.
- Use the synthetic JSONL generator when you want offline dataset experiments, exports, or review data.
- Review a sample of generated records before mixing them into your production dataset, especially if you want tighter tone control by department or role.

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
