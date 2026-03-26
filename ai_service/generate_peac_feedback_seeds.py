"""
Generate PEAC-specific feedback seed templates for ai_feedback_templates.

PEAC evaluations use a 0-4 rating scale with two domains:
  - Teacher Actions (6 indicators)
  - Student Learning Actions (9 indicators)

The generated templates follow the same structure as the ISO seeds but
with naturally-worded language grounded in the PEAC observation instrument
used by PEAC-accredited schools. Each template consists of:
  - evaluation_comment: a description of what was observed (used for SBERT matching)
  - feedback_text: the professional evaluator narrative (what the AI outputs)

Templates are intentionally varied through combinatorial mixing of focuses,
modifiers, openers, closers, and humanizing addons to prevent repetition.
"""
from __future__ import annotations

from typing import Dict, List, Tuple


# PEAC uses a 0-4 scale: 4=Excellent, 3=Very Satisfactory, 2=Satisfactory, 1=Needs Improvement, 0=Not Observed
PEAC_RATING_BANDS: List[Tuple[str, str, str, str, str]] = [
    ("excellent", "highly effective", "consistently evident",
     "The lesson demonstrates clear and sustained evidence of effective teaching aligned to PEAC standards",
     "Practice is already strong and should be sustained across future classroom observations."),
    ("very satisfactory", "well-established", "regularly observed",
     "The lesson demonstrates reliable evidence of competent teaching practice aligned to PEAC standards",
     "Practice is dependable, with focused refinements needed to reach an exemplary level of instruction."),
    ("satisfactory", "developing", "partially visible",
     "The lesson demonstrates acceptable but developing evidence of instructional competence",
     "Practice shows potential but needs more consistent application across classroom activities."),
    ("needs improvement", "emerging", "inconsistently present",
     "The lesson reflects limited evidence in this area and requires targeted intervention and coaching",
     "Practice requires deliberate support, mentoring, and sustained follow-through to reach the expected standard."),
    ("not observed", "not yet evident", "not demonstrated",
     "The lesson shows no observable evidence of this teaching practice during the classroom visit",
     "This competency must be prioritized in the teacher's professional development plan."),
]

PEAC_SUBJECTS = [
    "Mathematics lesson",
    "Science lesson",
    "English lesson",
    "Filipino lesson",
    "Social studies lesson",
    "Values education lesson",
    "Technology and Livelihood Education",
    "MAPEH lesson",
    "Computer science class",
    "Religious education lesson",
    "Research class",
    "General education class",
]

PEAC_OBSERVATION_TYPES = [
    "formal PEAC classroom observation",
    "scheduled classroom visit",
    "PEAC accreditation classroom review",
    "instructional supervision visit",
]

# ============================================================
# TEACHER ACTIONS — 6 PEAC indicators, expanded to multiple focuses
# ============================================================
PEAC_TA_STRENGTHS_FOCUSES = [
    # TA1: Clear expectations aligned to unit standards
    ("communicating clear performance expectations",
     "communicates clear expectations of student performance in line with the unit standards and competencies",
     "communicates clear and specific expectations of student performance, ensuring learners understand the unit standards and competencies they are expected to achieve during the lesson"),
    ("aligning lesson goals to unit standards",
     "presents lesson objectives that are directly aligned to the unit standards and competencies",
     "aligns lesson goals explicitly to the unit standards and competencies, giving students a clear sense of purpose and direction throughout the class session"),
    # TA2: Various learning materials and strategies
    ("utilizing varied learning materials and strategies",
     "utilizes various learning materials, resources, and strategies to help all students learn and achieve the unit standards",
     "utilizes a thoughtful combination of learning materials, resources, and instructional strategies that enable all students to engage meaningfully and achieve the unit standards and competencies"),
    ("selecting appropriate instructional resources",
     "selects and uses appropriate instructional resources that support student learning and mastery of competencies",
     "selects instructional resources that are well-matched to the lesson content, making unit standards and competencies more accessible and concrete for learners"),
    # TA3: Monitoring and checking through varied assessments
    ("monitoring student learning through varied assessments",
     "monitors and checks on students' learning and attainment of the unit standards through varied forms of assessment during class discussion",
     "monitors student learning systematically through varied forms of assessment during class discussion, confirming whether learners are progressing toward the unit standards and competencies"),
    ("using formative checks during instruction",
     "conducts formative checks during discussions to verify student understanding of unit standards",
     "conducts well-timed formative checks during class discussions, verifying that students are grasping the key concepts tied to the unit standards and competencies"),
    # TA4: Providing appropriate feedback and interventions
    ("providing timely feedback and interventions",
     "provides appropriate feedback or interventions to enable students in attaining the unit standards and competencies",
     "provides timely and specific feedback or targeted interventions that guide students toward achieving the unit standards and competencies within the lesson"),
    ("offering corrective guidance aligned to competencies",
     "offers corrective guidance that helps students address gaps in their understanding of the unit standards",
     "offers corrective guidance that directly addresses learning gaps, helping students refine their understanding and move closer to meeting the unit standards and competencies"),
    # TA5: Managing classroom environment and time
    ("managing the classroom environment effectively",
     "manages the classroom environment and time in a way that supports student learning and achievement of the unit standards",
     "manages the classroom environment and instructional time effectively, creating conditions that support sustained student learning and the achievement of unit standards and competencies"),
    ("organizing instructional time purposefully",
     "allocates and manages instructional time in a way that maximizes student engagement and progress toward competencies",
     "organizes instructional time purposefully so that each segment of the lesson contributes to student progress toward the unit standards and competencies"),
    # TA6: Processing understanding through critical questions
    ("facilitating critical thinking through questioning",
     "processes students' understanding by asking clarifying or critical thinking questions related to the unit standards",
     "processes student understanding through well-crafted clarifying and critical thinking questions that deepen engagement with the unit standards and competencies"),
    ("asking probing questions to deepen understanding",
     "asks probing questions that push students to think more deeply about the lesson content and unit standards",
     "asks probing questions that challenge students to think beyond surface-level answers, strengthening their grasp of the unit standards and competencies"),
]

PEAC_TA_IMPROVEMENT_FOCUSES = [
    # TA1
    ("clarity of performance expectations",
     "the lesson objectives and performance expectations are not clearly communicated in relation to the unit standards and competencies",
     "performance expectations could be communicated more clearly so students understand exactly how their work relates to the unit standards and competencies"),
    ("alignment of activities to unit standards",
     "some lesson activities do not clearly connect to the stated unit standards and competencies",
     "lesson activities could be more explicitly aligned to the unit standards and competencies so students can see the purpose behind each task"),
    # TA2
    ("variety of learning materials",
     "the lesson relies on a limited range of materials and strategies to help students achieve the unit standards",
     "a broader variety of learning materials, resources, and instructional strategies could be used to support all students in achieving the unit standards and competencies"),
    ("differentiation of instructional strategies",
     "instructional strategies are not varied enough to address different student learning needs during the lesson",
     "instructional strategies could be differentiated to better address the diverse learning needs of students while working toward the unit standards and competencies"),
    # TA3
    ("frequency of learning checks",
     "checks on student learning and understanding of the unit standards are not conducted frequently enough during class discussion",
     "monitoring of student learning could be more frequent during class discussions to confirm that learners are on track with the unit standards and competencies"),
    ("variety of assessment methods",
     "assessment during the lesson is limited to a single method and does not provide a full picture of student attainment",
     "assessment methods during class discussion could be varied to give a more complete picture of student progress toward the unit standards and competencies"),
    # TA4
    ("timeliness of feedback",
     "feedback on student performance is not provided promptly enough to guide learners during the lesson",
     "feedback could be provided more promptly during the lesson so students can immediately correct misconceptions and stay on track with the unit standards and competencies"),
    ("specificity of teacher interventions",
     "interventions provided are too general and do not directly address specific gaps in student understanding",
     "teacher interventions could be more specific and targeted to the actual learning gaps observed, helping students make clearer progress toward the unit standards and competencies"),
    # TA5
    ("classroom time management",
     "instructional time is not managed efficiently and some lesson segments receive too much or too little time",
     "classroom time management could be improved so instructional segments are balanced and all activities contribute effectively to the achievement of unit standards and competencies"),
    ("learning environment organization",
     "the classroom environment does not fully support sustained focus and productive learning during the lesson",
     "the learning environment could be organized more intentionally to reduce distractions and better support student engagement with the unit standards and competencies"),
    # TA6
    ("depth of questioning",
     "questions asked during the lesson tend to be recall-level and do not push students toward critical thinking about the unit standards",
     "questioning could go beyond recall to include clarifying and critical thinking questions that deepen student engagement with the unit standards and competencies"),
    ("processing of student understanding",
     "the teacher does not consistently process or follow up on student responses to confirm deeper understanding",
     "student understanding could be processed more deliberately through follow-up questions and guided reflection tied to the unit standards and competencies"),
]

PEAC_TA_RECOMMENDATION_FOCUSES = [
    ("performance expectations",
     "state learning targets at the start of each lesson using student-friendly language tied directly to the unit standards and competencies",
     "State learning targets at the beginning of each lesson using clear, student-friendly language linked directly to the unit standards and competencies so learners know what they are expected to demonstrate."),
    ("standards alignment",
     "review each activity before the lesson to confirm it directly supports the stated unit standards and competencies",
     "Review planned activities before each lesson to verify that every task directly supports the unit standards and competencies, removing or revising tasks that do not clearly contribute."),
    ("material variety",
     "incorporate varied learning materials such as visual aids, manipulatives, and digital resources to reach different learner types",
     "Incorporate a wider variety of learning materials including visual aids, manipulatives, and digital resources to ensure all students can engage with the unit standards and competencies through multiple pathways."),
    ("strategy differentiation",
     "prepare tiered activities or flexible grouping to address students at different readiness levels within the same lesson",
     "Prepare tiered activities or flexible grouping strategies so students at different readiness levels can each make progress toward the unit standards and competencies during the same lesson."),
    ("formative monitoring",
     "embed quick formative checks at key points during discussion to verify student understanding before moving forward",
     "Embed brief formative checks at transition points during class discussion to verify student understanding of the unit standards and competencies before advancing to the next concept."),
    ("assessment variety",
     "use a mix of oral checks, written responses, and peer assessment to monitor student mastery from multiple angles",
     "Use a combination of oral checks, written responses, and peer assessment during lessons to gather richer evidence of student progress toward the unit standards and competencies."),
    ("feedback timeliness",
     "provide immediate verbal or written feedback as students complete tasks so corrections happen while the content is still fresh",
     "Provide immediate verbal or written feedback as students work on tasks so they can correct errors and deepen understanding while the lesson content remains fresh and relevant."),
    ("targeted interventions",
     "identify specific student misconceptions during class and address them through brief reteaching or guided examples",
     "Identify specific student misconceptions during class discussion and address them through brief reteaching segments or guided worked examples tied to the unit standards and competencies."),
    ("time allocation",
     "plan a time breakdown for each lesson segment and use a visible timer or cue to keep the lesson on pace",
     "Plan explicit time allocations for each lesson segment and use a visible timer or pacing cue to ensure that instructional time supports full coverage of the unit standards and competencies."),
    ("environment management",
     "establish and consistently reinforce classroom routines that minimize disruptions and maximize time for learning",
     "Establish and consistently reinforce classroom routines and procedures that minimize disruptions, creating an environment where students can focus on achieving the unit standards and competencies."),
    ("critical questioning",
     "prepare a bank of higher-order questions before each lesson that require analysis, evaluation, or application of the unit standards",
     "Prepare a question bank before each lesson that includes analysis, evaluation, and application-level questions to challenge students beyond recall and deepen their engagement with the unit standards and competencies."),
    ("processing understanding",
     "use think-alouds, summarization prompts, or exit reflections to help students consolidate their understanding after each major concept",
     "Use think-alouds, summarization prompts, or exit reflections after each major concept to help students consolidate and articulate their understanding of the unit standards and competencies."),
]

# ============================================================
# STUDENT LEARNING ACTIONS — 9 PEAC indicators, expanded
# ============================================================
PEAC_SLA_STRENGTHS_FOCUSES = [
    # SLA1: Active and engaged with learning tasks
    ("active student engagement with learning tasks",
     "students are active and engaged with the different learning tasks aimed at accomplishing the unit standards and competencies",
     "students are visibly active and engaged with the learning tasks, demonstrating genuine effort and focus toward accomplishing the unit standards and competencies"),
    ("sustained student attention during activities",
     "students maintain focus and participation throughout the lesson activities aligned to the unit standards",
     "students sustain their attention and participation throughout the lesson activities, consistently working toward the accomplishment of the unit standards and competencies"),
    # SLA2-4: Using learning materials and technology (3 indicators, similar focus)
    ("effective use of learning materials and technology",
     "students with the help of different learning materials and resources including technology achieve the learning goals",
     "students effectively use different learning materials and resources including technology to achieve the learning goals tied to the unit standards and competencies"),
    ("student resourcefulness with instructional tools",
     "students demonstrate resourcefulness in using available materials and technology to support their learning",
     "students demonstrate resourcefulness in selecting and applying available learning materials and technology to support their progress toward the unit standards and competencies"),
    # SLA5: Explaining ideas and outputs
    ("student ability to explain their outputs",
     "students are able to explain how their ideas, outputs, or performances accomplish the unit standards and competencies",
     "students can clearly articulate how their ideas, outputs, or performances connect to and accomplish the unit standards and competencies, showing deeper understanding of the lesson goals"),
    ("articulation of learning connections",
     "students verbalize connections between their work and the expected learning outcomes",
     "students verbalize meaningful connections between their work and the expected learning outcomes, demonstrating awareness of how their efforts relate to the unit standards and competencies"),
    # SLA6: Asking questions to clarify or deepen understanding
    ("student-initiated questioning",
     "students when encouraged or on their own ask questions to clarify or deepen their understanding of the unit standards",
     "students proactively ask questions to clarify or deepen their understanding of the unit standards and competencies, showing intellectual curiosity and active engagement with the lesson content"),
    ("quality of student questions",
     "students ask thoughtful questions that go beyond surface-level clarification and push toward deeper understanding",
     "students pose thoughtful questions that move beyond surface-level clarification, pushing toward deeper understanding and more meaningful engagement with the unit standards and competencies"),
    # SLA7: Relating learning to daily life and real-world situations
    ("real-world application of learning",
     "students are able to relate or transfer their learning to daily life and real-world situations",
     "students demonstrate the ability to relate or transfer their classroom learning to daily life and real-world situations, showing practical application of the unit standards and competencies"),
    ("contextual transfer of concepts",
     "students make connections between lesson content and practical situations outside the classroom",
     "students make meaningful connections between lesson content and practical situations outside the classroom, demonstrating that the unit standards and competencies have real-world relevance"),
    # SLA8: Integrating 21st century skills
    ("integration of 21st century skills",
     "students are able to integrate 21st century skills in their achievement of the unit standards and competencies",
     "students integrate 21st century skills such as critical thinking, collaboration, communication, and creativity in their pursuit of the unit standards and competencies"),
    ("demonstration of collaborative and creative thinking",
     "students show evidence of collaboration, creativity, and digital literacy during lesson activities",
     "students demonstrate collaboration, creativity, and digital literacy during lesson activities, integrating 21st century skills as they work toward the unit standards and competencies"),
    # SLA9: Reflecting on and connecting learning with PVMGO
    ("connection of learning to school PVMGO",
     "students are able to reflect on and connect their learning with the school's PVMGO",
     "students reflect on and connect their classroom learning with the school's Philosophy, Vision, Mission, Goals, and Objectives, demonstrating alignment between personal growth and institutional values"),
    ("values integration through reflection",
     "students show awareness of how their learning connects to the broader goals and values of the school community",
     "students show a clear awareness of how their learning experiences connect to the broader goals and values of the school community, reflecting the school's PVMGO in their classroom responses"),
]

PEAC_SLA_IMPROVEMENT_FOCUSES = [
    ("student engagement levels",
     "student engagement with learning tasks is inconsistent and some learners appear passive or off-task during the lesson",
     "student engagement could be strengthened so that more learners are actively participating in the learning tasks aimed at accomplishing the unit standards and competencies"),
    ("use of learning materials by students",
     "students do not fully utilize the available learning materials and resources including technology during the lesson",
     "students could be guided to make fuller use of the available learning materials, resources, and technology to support their achievement of the unit standards and competencies"),
    ("student articulation of learning",
     "students have difficulty explaining how their ideas or outputs connect to the unit standards and competencies",
     "students could benefit from more structured opportunities to articulate how their ideas, outputs, or performances accomplish the unit standards and competencies"),
    ("student questioning behavior",
     "students rarely ask questions to clarify or deepen their understanding during the lesson",
     "student questioning could be encouraged more actively so learners develop the habit of asking clarifying and deepening questions related to the unit standards and competencies"),
    ("real-world transfer of learning",
     "students do not consistently connect lesson content to daily life or real-world situations",
     "opportunities for students to relate or transfer their learning to daily life and real-world situations could be made more explicit during the lesson"),
    ("21st century skills integration",
     "evidence of 21st century skills such as critical thinking, collaboration, and creativity is limited during the lesson",
     "21st century skills including critical thinking, collaboration, and creativity could be integrated more deliberately into activities tied to the unit standards and competencies"),
    ("PVMGO connection and reflection",
     "students do not demonstrate awareness of how their learning connects to the school's PVMGO",
     "reflection activities could be incorporated to help students connect their learning experiences with the school's Philosophy, Vision, Mission, Goals, and Objectives"),
]

PEAC_SLA_RECOMMENDATION_FOCUSES = [
    ("student engagement",
     "design learning tasks that require active student participation such as think-pair-share, collaborative projects, or problem-based scenarios",
     "Design learning tasks that require active participation such as think-pair-share, collaborative projects, or problem-based scenarios so that all students are consistently engaged in accomplishing the unit standards and competencies."),
    ("materials and technology use",
     "provide structured guidance on how to use learning materials and technology tools during the lesson",
     "Provide structured guidance and clear instructions on how students should use learning materials and technology tools during the lesson to support their achievement of the unit standards and competencies."),
    ("student articulation practice",
     "build in brief verbal or written reflection prompts where students explain how their work connects to the lesson objectives",
     "Include brief verbal or written reflection prompts where students explain how their ideas, outputs, or performances connect to the lesson objectives and unit standards and competencies."),
    ("questioning encouragement",
     "model and scaffold question-asking by providing question stems and creating a safe environment for student inquiry",
     "Model and scaffold question-asking by providing question stems, sentence frames, and a supportive environment that encourages students to ask clarifying and deepening questions related to the unit standards and competencies."),
    ("real-world connections",
     "connect lesson content to practical everyday situations through examples, case studies, or real-world problem scenarios",
     "Explicitly connect lesson content to practical everyday situations through examples, case studies, or real-world problems so students can see the relevance and transferability of the unit standards and competencies."),
    ("21st century skills",
     "embed collaborative, creative, and critical thinking tasks into lesson activities so students practice these skills alongside content mastery",
     "Embed collaborative, creative, and critical thinking tasks into lesson activities so students practice 21st century skills alongside their achievement of the unit standards and competencies."),
    ("PVMGO reflection",
     "include a brief closing reflection that asks students to identify how the lesson connects to the school's values, vision, or mission",
     "Include a brief closing reflection where students identify how the lesson connects to the school's Philosophy, Vision, Mission, Goals, and Objectives, reinforcing values integration."),
]

# ============================================================
# Variation pools — prevent repetitive output
# ============================================================
PEAC_REFLECTION_PHRASES = [
    "As observed during the PEAC classroom visit,",
    "Based on the classroom observation evidence,",
    "Throughout the lesson observation,",
    "Drawing from the observed teaching and learning interactions,",
    "As reflected in the lesson delivery,",
    "From the evidence gathered during the classroom visit,",
    "Considering the full scope of the observed lesson,",
    "As indicated by the PEAC observation indicators,",
    "Across the different segments of the lesson,",
    "Looking at the overall teaching-learning dynamics,",
    "Upon careful review of the lesson,",
    "As the observation data suggests,",
]

PEAC_SENTENCE_OPENERS = [
    "A key aspect of the observed lesson was that",
    "The classroom evidence clearly showed that",
    "A notable observation during the lesson was that",
    "Upon review of the lesson,",
    "Throughout the classroom visit,",
    "An important finding from the observation was that",
    "From the perspective of the PEAC standards,",
    "It was consistently observed that",
    "A significant element of the lesson demonstrated that",
    "The lesson provided clear evidence that",
    "As the lesson unfolded, it became evident that",
    "One of the distinguishing features of the lesson was that",
]

PEAC_NATURAL_BRIDGES = [
    "In practical terms,",
    "As the lesson progressed,",
    "Within the context of PEAC standards,",
    "Furthermore,",
    "Building on this observation,",
    "At multiple points during the lesson,",
    "Looking at the broader picture,",
    "In line with the expected competencies,",
    "Across the observed lesson segments,",
    "From the standpoint of continuous improvement,",
    "Taking into account the classroom dynamics,",
    "When viewed alongside other teaching indicators,",
]

PEAC_STRENGTH_CLOSERS = [
    "This contributed positively to the overall quality of the teaching-learning experience.",
    "Students appeared engaged and responsive, indicating alignment between teaching actions and learner outcomes.",
    "This practice reflects the teacher's commitment to meeting the expected standards of instruction.",
    "The consistency of this practice strengthened the overall coherence and effectiveness of the lesson.",
    "There was clear evidence that this approach supported meaningful student learning during the observation.",
    "This instructional habit contributed to a well-structured and purposeful classroom environment.",
    "Learners were visibly focused, suggesting that the teacher's approach was well-received and effective.",
    "The intentional nature of this practice demonstrated alignment with the expected PEAC competencies.",
    "This teaching behavior supported a productive and goal-directed classroom atmosphere.",
    "The purposeful application of this practice contributed to a professional and learner-centered lesson.",
]

PEAC_HUMANIZED_STRENGTH_ADDONS = [
    "The classroom atmosphere told a positive story about the learning culture being cultivated.",
    "Students seemed genuinely invested in the tasks at hand, which speaks to the teacher's instructional effectiveness.",
    "It was encouraging to see this level of intentionality in the classroom practice.",
    "This kind of consistent practice builds the foundation for long-term student success.",
    "The natural flow of the lesson suggested that these teaching habits are well-established.",
    "Learners appeared comfortable and confident, which is a good indicator of a supportive teaching approach.",
    "The professionalism evident in the lesson delivery reflects strong instructional discipline.",
    "This level of classroom practice demonstrates readiness for sustained professional growth.",
    "The positive student response during the lesson validates the effectiveness of the instructional choices made.",
    "This practice is worth sustaining and even sharing as a model for peer learning.",
]

PEAC_HUMANIZED_IMPROVEMENT_ADDONS = [
    "This is an achievable area for growth since the foundation of good practice is already present.",
    "A focused effort in this area could produce noticeable results even within the next few classroom visits.",
    "While not a major concern, consistent attention to this area can meaningfully elevate lesson quality.",
    "This area has strong potential and could become a reliable strength with deliberate practice.",
    "Improvement here would complement the teacher's existing strengths and create a more balanced lesson.",
    "Small but sustained adjustments in this area can lead to visible changes in student response.",
    "With intentional focus, this practice can move from developing to consistently effective.",
    "Addressing this area supports the broader goal of meeting all PEAC performance indicators.",
    "This is a practical growth area that can be addressed through reflective planning and peer collaboration.",
    "Strengthening this aspect of instruction would enhance the overall alignment with PEAC standards.",
]

PEAC_IMPROVEMENT_CLOSERS = [
    "Addressing this consistently can lead to visible improvement in future classroom observations.",
    "With focused effort, this area has strong potential for meaningful professional growth.",
    "Consistent attention to this aspect will support a more well-rounded PEAC performance profile.",
    "This represents a concrete opportunity for professional development aligned with institutional goals.",
    "Strengthening this area can positively impact both teaching effectiveness and student outcomes.",
    "Gradual and deliberate improvement here will support stronger evidence of effective practice.",
    "Targeted effort in this area can close the gap between current practice and the PEAC standard.",
    "Making this a priority can lead to measurable progress during the next evaluation cycle.",
    "With sustained attention, this area can evolve into a reliable and consistent teaching strength.",
    "Focusing on this dimension of instruction will contribute to a more cohesive and effective lesson.",
]

PEAC_HUMANIZED_RECOMMENDATION_ADDONS = [
    "Small, consistently applied changes tend to produce more lasting classroom improvement than sweeping overhauls.",
    "This step can be realistically incorporated into the teacher's existing lesson preparation routine.",
    "Starting with one focused adjustment and building from there is often the most effective approach.",
    "It may help to implement this gradually, refining the approach based on student feedback and response.",
    "This recommendation is most impactful when paired with regular self-reflection and peer consultation.",
    "When applied consistently over several lessons, this practical step can produce measurable improvement.",
    "The aim is to develop this into a natural part of the teacher's instructional repertoire.",
    "This can serve as a concrete action step that the teacher can begin applying immediately.",
    "Pairing this with collaborative planning or mentoring can accelerate the development of this practice.",
    "This focused recommendation supports both immediate lesson improvement and longer-term professional growth.",
]

PEAC_RECOMMENDATION_CLOSERS = [
    "This practical adjustment supports stronger alignment with the PEAC classroom observation standards.",
    "Implementing this recommendation can help move the teacher's practice to a higher performance level.",
    "This step supports the broader institutional goal of continuous instructional improvement.",
    "When applied consistently, this change can produce visible results during future classroom visits.",
    "This recommendation is designed to be achievable within the current instructional context.",
    "Following through on this can strengthen both teaching quality and student learning outcomes.",
    "This action step aligns with the professional development priorities identified through the PEAC observation.",
    "Sustained application of this recommendation will contribute to a stronger overall PEAC performance profile.",
    "This change is both practical and meaningful, supporting the teacher's ongoing professional growth journey.",
    "This targeted improvement contributes to the school's broader commitment to quality assurance in instruction.",
]

PEAC_MODIFIERS = [
    ("during the observed lesson", "across the lesson segments"),
    ("throughout the classroom visit", "while maintaining clear instructional direction"),
    ("during direct instruction", "in alignment with the stated learning goals"),
    ("during student activities", "while supporting student progress toward the competencies"),
    ("while facilitating group work", "in ways that encouraged collaboration and peer learning"),
    ("during assessment segments", "with attention to both teaching quality and student response"),
    ("during the lesson introduction", "while establishing the purpose and expectations for learning"),
    ("during guided practice", "while monitoring and adjusting instruction based on student needs"),
    ("during independent work time", "ensuring that all students remained on task and focused"),
    ("during the lesson closing", "while consolidating learning and connecting to future lessons"),
    ("during transitional activities", "while maintaining classroom order and instructional flow"),
    ("during whole-class discussion", "while encouraging broad participation and deeper thinking"),
]


def generate_peac_seed_templates(per_field: int = 200) -> List[Dict[str, str]]:
    """Generate PEAC-specific feedback seed templates.

    Returns a list of dicts, each with keys: field_name, evaluation_comment, feedback_text.
    Templates are generated for strengths, areas_for_improvement, and recommendations.
    """
    if per_field <= 0:
        return []

    output: List[Dict[str, str]] = []

    def add(field_name: str, evaluation_comment: str, feedback_text: str) -> None:
        output.append({
            "field_name": field_name,
            "evaluation_comment": evaluation_comment.strip().rstrip(".") + ".",
            "feedback_text": feedback_text.strip().rstrip(".") + ".",
        })

    # Combine Teacher Actions and Student Learning Actions focuses
    all_strengths = PEAC_TA_STRENGTHS_FOCUSES + PEAC_SLA_STRENGTHS_FOCUSES
    all_improvements = PEAC_TA_IMPROVEMENT_FOCUSES + PEAC_SLA_IMPROVEMENT_FOCUSES
    all_recommendations = PEAC_TA_RECOMMENDATION_FOCUSES + PEAC_SLA_RECOMMENDATION_FOCUSES

    # --- STRENGTHS ---
    for index in range(per_field):
        band = PEAC_RATING_BANDS[index % len(PEAC_RATING_BANDS)]
        focus = all_strengths[(index // len(PEAC_RATING_BANDS)) % len(all_strengths)]
        modifier = PEAC_MODIFIERS[(index // (len(PEAC_RATING_BANDS) * len(all_strengths))) % len(PEAC_MODIFIERS)]
        opener = PEAC_REFLECTION_PHRASES[index % len(PEAC_REFLECTION_PHRASES)]
        closer = PEAC_STRENGTH_CLOSERS[(index // len(PEAC_REFLECTION_PHRASES)) % len(PEAC_STRENGTH_CLOSERS)]
        sentence_opener = PEAC_SENTENCE_OPENERS[index % len(PEAC_SENTENCE_OPENERS)]
        addon = PEAC_HUMANIZED_STRENGTH_ADDONS[(index // len(PEAC_SENTENCE_OPENERS)) % len(PEAC_HUMANIZED_STRENGTH_ADDONS)]
        subject = PEAC_SUBJECTS[index % len(PEAC_SUBJECTS)]
        observation = PEAC_OBSERVATION_TYPES[(index // len(PEAC_SUBJECTS)) % len(PEAC_OBSERVATION_TYPES)]
        add(
            "strengths",
            f"{opener} in the {subject}, the teacher demonstrates {band[1]} evidence of {focus[0]} and {focus[1]} {modifier[0]} during a {observation}",
            f"{sentence_opener} the teacher {focus[2]}. {addon} {closer}",
        )

    # --- AREAS FOR IMPROVEMENT ---
    for index in range(per_field):
        band = PEAC_RATING_BANDS[index % len(PEAC_RATING_BANDS)]
        focus = all_improvements[(index // len(PEAC_RATING_BANDS)) % len(all_improvements)]
        modifier = PEAC_MODIFIERS[(index // (len(PEAC_RATING_BANDS) * len(all_improvements))) % len(PEAC_MODIFIERS)]
        opener = PEAC_REFLECTION_PHRASES[index % len(PEAC_REFLECTION_PHRASES)]
        sentence_opener = PEAC_SENTENCE_OPENERS[index % len(PEAC_SENTENCE_OPENERS)]
        addon = PEAC_HUMANIZED_IMPROVEMENT_ADDONS[(index // len(PEAC_SENTENCE_OPENERS)) % len(PEAC_HUMANIZED_IMPROVEMENT_ADDONS)]
        improvement_closer = PEAC_IMPROVEMENT_CLOSERS[(index // len(PEAC_REFLECTION_PHRASES)) % len(PEAC_IMPROVEMENT_CLOSERS)]
        subject = PEAC_SUBJECTS[index % len(PEAC_SUBJECTS)]
        observation = PEAC_OBSERVATION_TYPES[(index // len(PEAC_SUBJECTS)) % len(PEAC_OBSERVATION_TYPES)]
        add(
            "areas_for_improvement",
            f"{opener} in the {subject}, {focus[1]} {modifier[0]} during a {observation} and reflects a {band[0]} performance concern in this criterion",
            f"{sentence_opener} {focus[2]}. {addon} {improvement_closer}",
        )

    # --- RECOMMENDATIONS ---
    for index in range(per_field):
        band = PEAC_RATING_BANDS[index % len(PEAC_RATING_BANDS)]
        focus = all_recommendations[(index // len(PEAC_RATING_BANDS)) % len(all_recommendations)]
        rec_closer = PEAC_RECOMMENDATION_CLOSERS[(index // (len(PEAC_RATING_BANDS) * len(all_recommendations))) % len(PEAC_RECOMMENDATION_CLOSERS)]
        opener = PEAC_REFLECTION_PHRASES[index % len(PEAC_REFLECTION_PHRASES)]
        addon = PEAC_HUMANIZED_RECOMMENDATION_ADDONS[(index // len(PEAC_REFLECTION_PHRASES)) % len(PEAC_HUMANIZED_RECOMMENDATION_ADDONS)]
        subject = PEAC_SUBJECTS[index % len(PEAC_SUBJECTS)]
        observation = PEAC_OBSERVATION_TYPES[(index // len(PEAC_SUBJECTS)) % len(PEAC_OBSERVATION_TYPES)]
        add(
            "recommendations",
            f"{opener} in the {subject}, the teacher needs support in {focus[0]} and should {focus[1]} to address a {band[0]} classroom performance pattern noted in the {observation}",
            f"{focus[2]} {addon} {rec_closer}",
        )

    return output
