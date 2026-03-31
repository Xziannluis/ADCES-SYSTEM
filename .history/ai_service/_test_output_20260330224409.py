"""Quick test script for AI service output."""
import json, urllib.request, sys

form_type = sys.argv[1] if len(sys.argv) > 1 else "iso"

if form_type == "peac":
    payload = {
        "faculty_name": "JESSIE MAHINAY",
        "department": "",
        "subject_observed": "",
        "observation_type": "PEAC",
        "evaluation_form_type": "peac",
        "ratings": {
            "Teacher Actions": [
                {"rating": 3, "comment": "", "criterion_text": "Applied knowledge of content within and across curriculum teaching areas."},
                {"rating": 3, "comment": "", "criterion_text": "Used a range of teaching strategies that enhance learner achievement in literacy and numeracy skills."},
                {"rating": 2, "comment": "", "criterion_text": "Applied a range of teaching strategies to develop critical and creative thinking, as well as other higher-order thinking skills."},
                {"rating": 3, "comment": "", "criterion_text": "Managed classroom structure to engage learners, individually or in groups, in meaningful exploration, discovery and hands-on activities."},
                {"rating": 2, "comment": "", "criterion_text": "Managed learner behavior constructively by applying positive and non-violent discipline to ensure learning focused environments."},
                {"rating": 3, "comment": "", "criterion_text": "Used differentiated, developmentally appropriate learning experiences to address learners gender, needs, strengths, interests."}
            ],
            "Student Learning Actions": [
                {"rating": 3, "comment": "", "criterion_text": "Worked together with other students towards achieving the TILO(s)."},
                {"rating": 2, "comment": "", "criterion_text": "Shared ideas and responded to questions enthusiastically."},
                {"rating": 3, "comment": "", "criterion_text": "Performed the given learning tasks with enthusiasm and interest."},
                {"rating": 2, "comment": "", "criterion_text": "Demonstrated awareness and practice of appropriate behavior inside the classroom."},
                {"rating": 3, "comment": "", "criterion_text": "Applied learning in real life situations through authentic performance tasks."},
                {"rating": 2, "comment": "", "criterion_text": "Prepared instructional materials and assessment tools with clear directions."},
                {"rating": 3, "comment": "", "criterion_text": "Used Mother Tongue / Filipino or English as a Medium of Instruction (MOI) as prescribed in the curriculum."},
                {"rating": 2, "comment": "", "criterion_text": "Designed, selected, organized, and used diagnostic, formative and summative assessment."},
                {"rating": 3, "comment": "", "criterion_text": "Monitored and provided interventions to learners achieving the TILOs."}
            ]
        },
        "averages": {"communications": 2.7, "management": 2.6, "overall": 2.6},
        "regeneration_nonce": "test_peac_1"
    }
else:
    payload = {
        "faculty_name": "JESSIE MAHINAY",
        "department": "",
        "subject_observed": "",
        "observation_type": "ISO",
        "evaluation_form_type": "iso",
        "ratings": {
            "Communications Competence": [
                {"rating": 5, "comment": "", "criterion_text": "Uses an audible voice that can be heard at the back of the room."},
                {"rating": 4, "comment": "", "criterion_text": "Speaks fluently in the language of instruction."},
                {"rating": 4, "comment": "", "criterion_text": "Facilitates a dynamic discussion."},
                {"rating": 4, "comment": "", "criterion_text": "Uses engaging non-verbal cues (facial expression, gestures)."},
                {"rating": 5, "comment": "", "criterion_text": "Uses words and expressions suited to the level of the students."}
            ],
            "Management and Presentation of the Lesson": [
                {"rating": 5, "comment": "", "criterion_text": "The TILO (Topic Intended Learning Outcomes) are clearly presented."},
                {"rating": 4, "comment": "", "criterion_text": "Recall and connects previous lessons to the new lessons."},
                {"rating": 3, "comment": "", "criterion_text": "The topic/lesson is introduced in an interesting and engaging way."},
                {"rating": 4, "comment": "", "criterion_text": "Uses current issues, real life and local examples to enrich class discussion."},
                {"rating": 4, "comment": "", "criterion_text": "Focuses class discussion on key concepts of the lesson."},
                {"rating": 5, "comment": "", "criterion_text": "Encourages active participation among students and ask questions about the topic."},
                {"rating": 4, "comment": "", "criterion_text": "Uses current instructional strategies and resources."},
                {"rating": 3, "comment": "", "criterion_text": "Designs teaching aids that facilitate understanding of key concepts."},
                {"rating": 2, "comment": "", "criterion_text": "Adapts teaching approach in the light of student feedback and reactions."},
                {"rating": 3, "comment": "", "criterion_text": "Aids students using thought provoking questions (Art of Questioning)."},
                {"rating": 4, "comment": "", "criterion_text": "Integrate the institutional core values to the lessons."},
                {"rating": 4, "comment": "", "criterion_text": "Conduct the lesson using the principle of SMART"}
            ],
            "Assessment of Students' Learning": [
                {"rating": 5, "comment": "", "criterion_text": "Monitors students understanding on key concepts discussed."},
                {"rating": 4, "comment": "", "criterion_text": "Uses assessment tool that relates specific course competencies stated in the syllabus."},
                {"rating": 4, "comment": "", "criterion_text": "Design test/quarter/assignments and other assessment tasks that are corrector-based."},
                {"rating": 4, "comment": "", "criterion_text": "Introduces varied activities that will answer the differentiated needs to the learners with varied learning style."},
                {"rating": 4, "comment": "", "criterion_text": "Conducts normative assessment before evaluating and grading the learners performance outcome."},
                {"rating": 4, "comment": "", "criterion_text": "Monitors the formative assessment results and find ways to ensure learning for the learners."}
            ]
        },
        "averages": {"communications": 4.4, "management": 3.8, "assessment": 4.2, "overall": 4.0},
        "regeneration_nonce": "test_iso_2"
    }

req = urllib.request.Request(
    "http://127.0.0.1:8001/generate",
    data=json.dumps(payload).encode(),
    headers={"Content-Type": "application/json"},
)
resp = urllib.request.urlopen(req, timeout=60)
data = json.loads(resp.read())

for field, key in [("STRENGTHS", "strengths_options"), ("IMPROVEMENT", "improvement_areas_options"), ("RECOMMENDATIONS", "recommendations_options")]:
    print(f"\n=== {field} OPTIONS ===")
    for i, opt in enumerate(data.get(key, []), 1):
        wc = len(opt.split())
        print(f"\n[{i}] {opt}")
        print(f"  [words: {wc}]")
