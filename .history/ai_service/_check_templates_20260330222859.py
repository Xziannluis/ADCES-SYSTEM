import re, pymysql

with open('config/database.php', 'r') as f:
    txt = f.read()

host = re.search(r"'host'\s*=>\s*'(.*?)'", txt).group(1)
user = re.search(r"'username'\s*=>\s*'(.*?)'", txt).group(1)
pw = re.search(r"'password'\s*=>\s*'(.*?)'", txt).group(1)
db_name = re.search(r"'database'\s*=>\s*'(.*?)'", txt).group(1)

conn = pymysql.connect(host=host, user=user, password=pw, database=db_name)
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
