"""
Seed PEAC-specific feedback templates into ai_feedback_templates.

Usage:
    cd ai_service
    python seed_peac_feedback_templates.py [--per-field 200] [--truncate-peac]

This adds PEAC templates alongside the existing ISO templates.
Use --truncate-peac to remove only PEAC-originated templates before re-seeding.
"""
from __future__ import annotations

import argparse
import pathlib
from typing import Dict

from feedback_retrieval_system import DEFAULT_MYSQL_TABLE, build_mysql_seed_system
from generate_peac_feedback_seeds import generate_peac_seed_templates


ROOT_PATH = pathlib.Path(__file__).resolve().parent.parent
PHP_DB_CONFIG_PATH = ROOT_PATH / "config" / "database.php"


def parse_php_db_config() -> Dict[str, str]:
    try:
        text = PHP_DB_CONFIG_PATH.read_text(encoding="utf-8")
    except Exception:
        return {
            "host": "127.0.0.1",
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
        "host": grab("host", "127.0.0.1"),
        "database": grab("db_name", "ai_classroom_eval"),
        "user": grab("username", "root"),
        "password": grab("password", ""),
    }


def main() -> None:
    parser = argparse.ArgumentParser(description="Seed PEAC-specific feedback templates.")
    parser.add_argument("--per-field", type=int, default=200,
                        help="Number of PEAC templates per field (strengths/improvement/recommendations).")
    parser.add_argument("--table", default=DEFAULT_MYSQL_TABLE,
                        help="MySQL table name for feedback templates.")
    parser.add_argument("--truncate-peac", action="store_true",
                        help="Remove existing PEAC templates before seeding (detects PEAC by keyword).")
    args = parser.parse_args()

    templates = generate_peac_seed_templates(per_field=args.per_field)
    print(f"Generated {len(templates)} PEAC templates ({args.per_field} per field x 3 fields).")

    system = build_mysql_seed_system(parse_php_db_config(), table_name=args.table)
    try:
        if args.truncate_peac:
            # Remove only PEAC-originated templates by keyword match
            conn = system.backend.connection
            cursor = conn.cursor()
            cursor.execute(
                f"DELETE FROM `{args.table}` WHERE "
                "evaluation_comment LIKE '%PEAC%' OR "
                "evaluation_comment LIKE '%unit standards and competencies%' OR "
                "evaluation_comment LIKE '%PVMGO%'"
            )
            deleted = cursor.rowcount
            conn.commit()
            cursor.close()
            print(f"Removed {deleted} existing PEAC templates.")

        system.seed_feedback_templates(templates)
        print(f"Seeded {len(templates)} PEAC templates into {args.table}.")
        print(f"Total active template count: {system.count_templates()}")
    finally:
        system.close()


if __name__ == "__main__":
    main()
