import re, pymysql

conn = pymysql.connect(host="127.0.0.1", user="root", password="", database="ai_classroom_eval")
cur = conn.cursor()

# Check a few strengths templates
cur.execute("SELECT feedback_text FROM ai_feedback_templates WHERE field_name='strengths' AND form_type='iso' AND is_active=1 LIMIT 5")
print("=== STRENGTHS TEMPLATES ===")
for row in cur.fetchall():
    print("---")
    print(row[0])
    print(f"  [word count: {len(row[0].split())}]")

# Check improvements
cur.execute("SELECT feedback_text FROM ai_feedback_templates WHERE field_name='areas_for_improvement' AND form_type='iso' AND is_active=1 LIMIT 5")
print("\n=== IMPROVEMENT TEMPLATES ===")
for row in cur.fetchall():
    print("---")
    print(row[0])
    print(f"  [word count: {len(row[0].split())}]")

# Check recommendations
cur.execute("SELECT feedback_text FROM ai_feedback_templates WHERE field_name='recommendations' AND form_type='iso' AND is_active=1 LIMIT 5")
print("\n=== RECOMMENDATION TEMPLATES ===")
for row in cur.fetchall():
    print("---")
    print(row[0])
    print(f"  [word count: {len(row[0].split())}]")

conn.close()
