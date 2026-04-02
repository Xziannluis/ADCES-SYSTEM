"""Quick test: verify domain distribution when all ratings are tied at 4."""
import requests, re

body = {
    'faculty_name': 'ZILDZIAN ENTERO', 'department': 'CCIS',
    'subject_observed': 'Programming 101', 'observation_type': 'Classroom review',
    'ratings': {
        'communications': {str(i): {'rating': 4, 'criterion_text': c} for i, c in enumerate([
            'Uses an audible voice that can be heard at the back of the room',
            'Speaks fluently in the language of instruction',
            'Facilitates a dynamic discussion',
            'Uses engaging non-verbal cues (facial expression, gestures)',
            'Uses words and expressions suited to the level of the students',
        ], 1)},
        'management': {str(i): {'rating': 4, 'criterion_text': c} for i, c in enumerate([
            'The TILO are clearly presented',
            'Recall and connects previous lessons to the new lessons',
            'The topic/lesson is introduced in an interesting and engaging way',
            'Uses current issues real life and local examples',
            'Focuses class discussion on key concepts of the lesson',
            'Encourages active participation among students',
            'Uses current instructional strategies and resources',
            'Designs teaching aids that facilitate understanding of key concepts',
            'Adapts teaching approach in the light of student feedback',
            'Aids students using thought provoking questions',
            'Integrate the institutional core values to the lessons',
            'Conduct the lesson using the principle of SMART',
        ], 1)},
        'assessment': {str(i): {'rating': 4, 'criterion_text': c} for i, c in enumerate([
            'Monitors students understanding on key concepts discussed',
            'Uses assessment tool that relates specific course competencies',
            'Design test/quarter/assignments that are corrector-based',
            'Introduces varied activities for differentiated needs',
            'Conducts normative assessment before evaluating',
            'Monitors formative assessment results',
        ], 1)},
    },
    'averages': {'communications': 4.0, 'management': 4.0, 'assessment': 4.0, 'overall': 4.0},
}

r = requests.post('http://127.0.0.1:8001/generate', json=body)
data = r.json()

DOMAIN_KEYWORDS = {
    'communication': ['voice', 'fluency', 'fluent', 'non-verbal', 'nonverbal', 'gestures', 'facial', 'language of instruction', 'speech', 'vocabulary', 'expressions suited'],
    'management': ['TILO', 'lesson plan', 'participation', 'instructional strategies', 'teaching aids', 'core values', 'SMART', 'discussion on key concepts', 'real life', 'local examples', 'engagement', 'classroom management', 'lesson introduction'],
    'assessment': ['assessment', 'formative', 'summative', 'corrector', 'rubric', 'grading', 'differentiated', 'normative', 'monitoring understanding', 'feedback follow'],
}

def detect_domain(text):
    t = text.lower()
    scores = {}
    for d, kws in DOMAIN_KEYWORDS.items():
        scores[d] = sum(1 for kw in kws if kw.lower() in t)
    best = max(scores, key=scores.get)
    return best if scores[best] > 0 else 'unknown'

for field in ['strengths_options', 'improvement_areas_options', 'recommendations_options']:
    label = field.replace('_options', '').upper().replace('_', ' ')
    print(f'\n{"="*60}')
    print(f'  {label}')
    print(f'{"="*60}')
    for i, opt in enumerate(data.get(field, []), 1):
        domain = detect_domain(opt)
        # Find domain references in last sentence
        last_sent = opt.rsplit('.', 2)[-2].strip() if '.' in opt else opt
        print(f'\n  Option {i} [detected: {domain}]:')
        print(f'    {opt[:150]}...')
        print(f'    LAST: ...{last_sent[-120:]}.')
