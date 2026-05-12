from __future__ import annotations

import argparse
import pathlib
from typing import Dict

from feedback_retrieval_system import DEFAULT_MYSQL_TABLE, build_mysql_seed_system, generate_seed_templates


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
    parser = argparse.ArgumentParser(description="Seed MySQL feedback templates for retrieval.")
    parser.add_argument("--per-field", type=int, default=400, help="Number of records per AI-assisted field.")
    parser.add_argument("--table", default=DEFAULT_MYSQL_TABLE, help="MySQL table name for feedback templates.")
    parser.add_argument("--truncate", action="store_true", help="Clear existing templates before seeding.")
    args = parser.parse_args()

    templates = generate_seed_templates(per_field=args.per_field)
    system = build_mysql_seed_system(parse_php_db_config(), table_name=args.table)
    try:
        if args.truncate:
            system.clear_templates()
        system.seed_feedback_templates(templates)
        print(f"Seeded {len(templates)} templates into {args.table}.")
        print(f"Current active template count: {system.count_templates()}")
    finally:
        system.close()


if __name__ == "__main__":
    main()