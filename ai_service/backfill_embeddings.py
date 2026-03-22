"""Backfill SBERT embeddings for ai_feedback_templates rows that have empty embedding_vector.

Usage:
    cd ai_service
    python backfill_embeddings.py
"""
import sys
import os
sys.path.insert(0, os.path.dirname(__file__))

import pymysql
import numpy as np
from sentence_transformers import SentenceTransformer
from feedback_retrieval_system import FeedbackRetrievalSystem

# Parse DB config from PHP
def parse_php_db_config():
    config_path = os.path.join(os.path.dirname(__file__), "..", "config", "database.php")
    try:
        text = open(config_path, encoding="utf-8").read()
    except Exception:
        return {"host": "localhost", "database": "ai_classroom_eval", "user": "root", "password": ""}

    def grab(key, default=""):
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


def main():
    config = parse_php_db_config()
    print(f"Connecting to MySQL: {config['host']}/{config['database']} as {config['user']}")

    conn = pymysql.connect(
        host=config["host"],
        user=config["user"],
        password=config["password"],
        database=config["database"],
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )

    # Load SBERT model
    model_name = os.getenv("SBERT_MODEL", "sentence-transformers/all-MiniLM-L6-v2")
    print(f"Loading SBERT model: {model_name}")
    model = SentenceTransformer(model_name)

    # Find rows with empty or null embedding_vector
    with conn.cursor() as cur:
        cur.execute(
            "SELECT id, evaluation_comment, feedback_text, embedding_vector "
            "FROM ai_feedback_templates WHERE is_active = 1"
        )
        rows = cur.fetchall()

    needs_update = []
    for row in rows:
        ev = row["embedding_vector"]
        if ev is None or ev == b"" or ev == "" or (isinstance(ev, (bytes, bytearray)) and len(ev) < 10):
            needs_update.append(row)

    print(f"Total active rows: {len(rows)}")
    print(f"Rows needing embedding backfill: {len(needs_update)}")

    if not needs_update:
        print("All rows already have embeddings. Nothing to do.")
        conn.close()
        return

    # Batch encode all evaluation_comments
    texts = [r["evaluation_comment"] for r in needs_update]
    print(f"Encoding {len(texts)} texts with SBERT...")
    embeddings = model.encode(texts, convert_to_numpy=True, normalize_embeddings=True, batch_size=64, show_progress_bar=True)

    # Update each row
    updated = 0
    with conn.cursor() as cur:
        for i, row in enumerate(needs_update):
            vec = np.asarray(embeddings[i], dtype=np.float32)
            serialized = FeedbackRetrievalSystem.serialize_embedding(vec)
            cur.execute(
                "UPDATE ai_feedback_templates SET embedding_vector = %s WHERE id = %s",
                (serialized, row["id"]),
            )
            updated += 1
            if updated % 50 == 0:
                conn.commit()
                print(f"  Updated {updated}/{len(needs_update)}...")

    conn.commit()
    conn.close()
    print(f"Done! Updated {updated} rows with SBERT embeddings.")

    # Clear the embeddings cache so the service re-loads
    cache_path = os.path.join(os.path.dirname(__file__), "comment_embeddings_cache.npz")
    if os.path.exists(cache_path):
        os.remove(cache_path)
        print(f"Cleared embeddings cache: {cache_path}")


if __name__ == "__main__":
    main()
