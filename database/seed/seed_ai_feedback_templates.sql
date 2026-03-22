-- =====================================================================
-- AI Feedback Templates Seed Data
-- Database: ai_classroom_eval
-- Table: ai_feedback_templates
-- Generated for SBERT embedding-based retrieval system
-- Total: ~460+ unique professional feedback comments
-- Categories: strengths, areas_for_improvement, recommendations
-- =====================================================================

USE ai_classroom_eval;

-- Clear existing seed data to avoid duplicates
DELETE FROM ai_feedback_templates WHERE source = 'seed';

-- =====================================================================
-- CATEGORY 1: STRENGTHS
-- 155 unique feedback comments covering all evaluation criteria
-- =====================================================================

INSERT INTO ai_feedback_templates (field_name, evaluation_comment, feedback_text, embedding_vector, source, is_active) VALUES

-- === COMMUNICATIONS: Vocal Clarity and Projection ===
('strengths', 'Teacher uses an audible voice that can be heard at the back of the room', 'Every word reaches the farthest corners of the classroom, ensuring no student misses any part of the discussion.', '', 'seed', 1),
('strengths', 'Teacher uses an audible voice that can be heard at the back of the room', 'Voice projection is consistently strong and well-modulated throughout the session, maintaining student attention from start to finish.', '', 'seed', 1),
('strengths', 'Teacher uses an audible voice that can be heard at the back of the room', 'Students at all seating positions can clearly hear the lesson without straining, reflecting excellent vocal presence in the classroom.', '', 'seed', 1),
('strengths', 'Teacher uses an audible voice that can be heard at the back of the room', 'The volume and clarity of speech are well-suited to the room size, allowing for comfortable listening even in the back rows.', '', 'seed', 1),
('strengths', 'Teacher uses an audible voice that can be heard at the back of the room', 'Articulation is crisp and audible, which helps maintain focus and reduces the likelihood of misunderstanding key points.', '', 'seed', 1),
('strengths', 'Teacher uses an audible voice that can be heard at the back of the room', 'A confident and resonant speaking voice fills the room, contributing to a professional and engaging learning atmosphere.', '', 'seed', 1),
('strengths', 'Teacher uses an audible voice that can be heard at the back of the room', 'Clear pronunciation paired with appropriate volume makes the lesson accessible to all learners in the classroom.', '', 'seed', 1),

-- === COMMUNICATIONS: Language Fluency ===
('strengths', 'Teacher speaks fluently in the language of instruction', 'Fluency in the language of instruction is evident, as ideas are communicated smoothly without awkward pauses or hesitation.', '', 'seed', 1),
('strengths', 'Teacher speaks fluently in the language of instruction', 'Smooth and natural speech patterns reflect strong command of the instructional language, which builds student confidence in the material.', '', 'seed', 1),
('strengths', 'Teacher speaks fluently in the language of instruction', 'The seamless delivery of complex ideas in the language of instruction highlights a deep mastery that students find easy to follow.', '', 'seed', 1),
('strengths', 'Teacher speaks fluently in the language of instruction', 'Grammatically precise and fluent communication establishes credibility and ensures clarity in every explanation.', '', 'seed', 1),
('strengths', 'Teacher speaks fluently in the language of instruction', 'Thought transitions and explanations flow naturally, showcasing a high level of linguistic proficiency that enhances learning.', '', 'seed', 1),
('strengths', 'Teacher speaks fluently in the language of instruction', 'Vocabulary choices are accurate and well-placed, demonstrating strong fluency in the medium of instruction.', '', 'seed', 1),

-- === COMMUNICATIONS: Engagement and Discussion Facilitation ===
('strengths', 'Teacher facilitates a dynamic discussion', 'Classroom discussions are lively and well-directed, with students actively contributing ideas and building on each other''s perspectives.', '', 'seed', 1),
('strengths', 'Teacher facilitates a dynamic discussion', 'Open-ended questioning techniques encourage students to think critically and share their viewpoints, making the class interactive.', '', 'seed', 1),
('strengths', 'Teacher facilitates a dynamic discussion', 'A balance of structured prompts and organic follow-up questions keeps the discussion moving and engages multiple students.', '', 'seed', 1),
('strengths', 'Teacher facilitates a dynamic discussion', 'Students are visibly engaged during class discussions, eagerly raising hands and offering thoughtful responses to posed questions.', '', 'seed', 1),
('strengths', 'Teacher facilitates a dynamic discussion', 'Skillful facilitation ensures that quiet students also get opportunities to speak, creating an inclusive and vibrant discussion environment.', '', 'seed', 1),
('strengths', 'Teacher facilitates a dynamic discussion', 'The classroom dialogue feels collaborative rather than one-sided, reflecting a genuine effort to draw out student voices.', '', 'seed', 1),
('strengths', 'Teacher facilitates a dynamic discussion', 'Discussions are well-paced and purposeful, tying student contributions back to the lesson''s core objectives effectively.', '', 'seed', 1),

-- === COMMUNICATIONS: Non-verbal Communication ===
('strengths', 'Teacher uses engaging non-verbal cues such as facial expressions and gestures', 'Expressive gestures and facial expressions bring the lesson to life, helping students connect emotionally with the material.', '', 'seed', 1),
('strengths', 'Teacher uses engaging non-verbal cues such as facial expressions and gestures', 'Body language is purposeful and animated, reinforcing key concepts and keeping the energy level high throughout the session.', '', 'seed', 1),
('strengths', 'Teacher uses engaging non-verbal cues such as facial expressions and gestures', 'Strategic use of hand gestures, eye contact, and movement around the room creates a dynamic and visually stimulating presentation.', '', 'seed', 1),
('strengths', 'Teacher uses engaging non-verbal cues such as facial expressions and gestures', 'Warm smiles and encouraging nods put students at ease, fostering a welcoming environment for participation and inquiry.', '', 'seed', 1),
('strengths', 'Teacher uses engaging non-verbal cues such as facial expressions and gestures', 'Non-verbal cues effectively complement verbal instruction, adding emphasis and clarity to important discussion points.', '', 'seed', 1),
('strengths', 'Teacher uses engaging non-verbal cues such as facial expressions and gestures', 'Physical presence in the classroom is commanding yet approachable, making lessons more memorable through visual engagement.', '', 'seed', 1),

-- === COMMUNICATIONS: Appropriate Language Level ===
('strengths', 'Teacher uses words and expressions suited to the level of the students', 'Language is carefully calibrated to student comprehension levels, making even complex topics accessible and understandable.', '', 'seed', 1),
('strengths', 'Teacher uses words and expressions suited to the level of the students', 'Technical terms are introduced gradually with clear definitions, scaffolding understanding for students at varying ability levels.', '', 'seed', 1),
('strengths', 'Teacher uses words and expressions suited to the level of the students', 'Explanations avoid unnecessary jargon and instead rely on relatable vocabulary that resonates with the student audience.', '', 'seed', 1),
('strengths', 'Teacher uses words and expressions suited to the level of the students', 'Word choices reflect an awareness of student backgrounds, ensuring inclusivity and clarity in all verbal explanations.', '', 'seed', 1),
('strengths', 'Teacher uses words and expressions suited to the level of the students', 'Academic vocabulary is woven naturally into instruction, building student familiarity without overwhelming them.', '', 'seed', 1),

-- === MANAGEMENT: Learning Outcomes Clarity ===
('strengths', 'The TILO (Topic Intended Learning Outcomes) are clearly presented', 'Learning outcomes are stated upfront with precision, giving students a clear roadmap of what they are expected to achieve during the session.', '', 'seed', 1),
('strengths', 'The TILO (Topic Intended Learning Outcomes) are clearly presented', 'Objectives are written in student-friendly language and revisited throughout the lesson, reinforcing the purpose of each activity.', '', 'seed', 1),
('strengths', 'The TILO (Topic Intended Learning Outcomes) are clearly presented', 'By clearly outlining the intended learning outcomes at the start, students gain a sense of direction and remain focused and motivated.', '', 'seed', 1),
('strengths', 'The TILO (Topic Intended Learning Outcomes) are clearly presented', 'Each learning outcome is specific and measurable, setting clear expectations for student performance and achievement.', '', 'seed', 1),
('strengths', 'The TILO (Topic Intended Learning Outcomes) are clearly presented', 'The alignment between stated outcomes and actual lesson activities is commendable, ensuring coherence in the learning experience.', '', 'seed', 1),

-- === MANAGEMENT: Lesson Continuity ===
('strengths', 'Teacher recalls and connects previous lessons to new lessons', 'Smooth transitions between old and new material help students build on what they already know, deepening their understanding.', '', 'seed', 1),
('strengths', 'Teacher recalls and connects previous lessons to new lessons', 'Brief but effective recaps of prior topics establish a strong foundation before introducing new concepts.', '', 'seed', 1),
('strengths', 'Teacher recalls and connects previous lessons to new lessons', 'Linking new content to previously discussed ideas creates a cohesive learning journey that students can easily follow.', '', 'seed', 1),
('strengths', 'Teacher recalls and connects previous lessons to new lessons', 'Students are encouraged to recall what they learned previously, reinforcing retention and contextualizing new discussions.', '', 'seed', 1),
('strengths', 'Teacher recalls and connects previous lessons to new lessons', 'The continuity between lessons is well-maintained, helping students see the bigger picture and how topics interrelate.', '', 'seed', 1),

-- === MANAGEMENT: Lesson Introduction ===
('strengths', 'The lesson is introduced in an interesting and engaging way', 'Opening activities immediately capture student interest, setting an enthusiastic tone for the entire class period.', '', 'seed', 1),
('strengths', 'The lesson is introduced in an interesting and engaging way', 'A creative hook at the beginning of the lesson sparks curiosity and motivates students to stay engaged throughout.', '', 'seed', 1),
('strengths', 'The lesson is introduced in an interesting and engaging way', 'The lesson introduction effectively uses storytelling, questions, or media to draw students into the topic from the very start.', '', 'seed', 1),
('strengths', 'The lesson is introduced in an interesting and engaging way', 'Thought-provoking opening questions invite students to think about the topic before formal instruction begins.', '', 'seed', 1),
('strengths', 'The lesson is introduced in an interesting and engaging way', 'The engaging start to the lesson generates authentic excitement and primes students for meaningful participation.', '', 'seed', 1),

-- === MANAGEMENT: Relevance to Real World ===
('strengths', 'Teacher uses current issues, real life and local examples to enrich class discussion', 'Real-world examples make abstract concepts tangible and relevant, helping students see the practical value of what they are learning.', '', 'seed', 1),
('strengths', 'Teacher uses current issues, real life and local examples to enrich class discussion', 'Local and current events are seamlessly woven into discussions, enriching the lesson with context that students find meaningful.', '', 'seed', 1),
('strengths', 'Teacher uses current issues, real life and local examples to enrich class discussion', 'By connecting lessons to everyday scenarios, the material becomes more relatable and easier for students to retain long-term.', '', 'seed', 1),
('strengths', 'Teacher uses current issues, real life and local examples to enrich class discussion', 'Practical illustrations drawn from familiar situations help bridge the gap between theoretical knowledge and real-world application.', '', 'seed', 1),
('strengths', 'Teacher uses current issues, real life and local examples to enrich class discussion', 'Students respond positively when lessons incorporate relevant social issues, as it validates the importance of what they are studying.', '', 'seed', 1),

-- === MANAGEMENT: Focus on Key Concepts ===
('strengths', 'Teacher focuses class discussion on key concepts of the lesson', 'Discussions remain tightly aligned with the lesson''s central ideas, preventing tangents and maximizing instructional time.', '', 'seed', 1),
('strengths', 'Teacher focuses class discussion on key concepts of the lesson', 'Key concepts are highlighted and revisited throughout the session, ensuring students grasp the most essential takeaways.', '', 'seed', 1),
('strengths', 'Teacher focuses class discussion on key concepts of the lesson', 'The ability to redirect off-topic remarks back to core content demonstrates excellent classroom management and instructional focus.', '', 'seed', 1),
('strengths', 'Teacher focuses class discussion on key concepts of the lesson', 'Important ideas are emphasized through repetition, examples, and clarifying questions, securing deep student understanding.', '', 'seed', 1),
('strengths', 'Teacher focuses class discussion on key concepts of the lesson', 'Lesson pacing ensures adequate time is devoted to unpacking the most critical concepts without rushing through them.', '', 'seed', 1),

-- === MANAGEMENT: Student Participation ===
('strengths', 'Teacher encourages active participation among students and asks questions about the topic', 'All students are given equal opportunities to participate, creating an inclusive and collaborative classroom dynamic.', '', 'seed', 1),
('strengths', 'Teacher encourages active participation among students and asks questions about the topic', 'Probing questions and invitations to share ideas keep student participation levels consistently high throughout the lesson.', '', 'seed', 1),
('strengths', 'Teacher encourages active participation among students and asks questions about the topic', 'A supportive environment where wrong answers are treated as learning opportunities encourages even shy students to speak up.', '', 'seed', 1),
('strengths', 'Teacher encourages active participation among students and asks questions about the topic', 'Varied questioning techniques—including cold calling, think-pair-share, and open prompts—ensure broad student engagement.', '', 'seed', 1),
('strengths', 'Teacher encourages active participation among students and asks questions about the topic', 'Positive reinforcement and acknowledgment of student responses create a safe space where learners feel valued and heard.', '', 'seed', 1),
('strengths', 'Teacher encourages active participation among students and asks questions about the topic', 'Active participation is not limited to verbal responses; hands-on activities and group work also reflect engagement strategies.', '', 'seed', 1),

-- === MANAGEMENT: Instructional Strategies ===
('strengths', 'Teacher uses current instructional strategies and resources', 'Innovative teaching methods keep the classroom experience fresh and aligned with contemporary educational best practices.', '', 'seed', 1),
('strengths', 'Teacher uses current instructional strategies and resources', 'A variety of instructional approaches—including multimedia, cooperative learning, and direct instruction—cater to diverse learning styles.', '', 'seed', 1),
('strengths', 'Teacher uses current instructional strategies and resources', 'Technology is integrated purposefully into the lesson, enhancing rather than distracting from the learning objectives.', '', 'seed', 1),
('strengths', 'Teacher uses current instructional strategies and resources', 'Up-to-date resources and references demonstrate a commitment to staying current in the field and providing students with relevant material.', '', 'seed', 1),
('strengths', 'Teacher uses current instructional strategies and resources', 'Blended approaches that combine traditional and modern strategies reflect thoughtful lesson planning and adaptability.', '', 'seed', 1),

-- === MANAGEMENT: Teaching Aids Design ===
('strengths', 'Teacher designs teaching aids that facilitate understanding of key concepts', 'Visual aids and instructional materials are well-crafted, making complex ideas easier to grasp and remember.', '', 'seed', 1),
('strengths', 'Teacher designs teaching aids that facilitate understanding of key concepts', 'Presentation slides, charts, and handouts are clear, organized, and directly aligned with the lesson''s learning outcomes.', '', 'seed', 1),
('strengths', 'Teacher designs teaching aids that facilitate understanding of key concepts', 'Teaching aids are creative and visually appealing, which helps maintain student interest and supports different learning modalities.', '', 'seed', 1),
('strengths', 'Teacher designs teaching aids that facilitate understanding of key concepts', 'Well-designed graphic organizers and diagrams effectively break down difficult material into manageable, understandable segments.', '', 'seed', 1),
('strengths', 'Teacher designs teaching aids that facilitate understanding of key concepts', 'Supplementary materials provided are relevant and thoughtfully prepared, serving as excellent study references for students.', '', 'seed', 1),

-- === MANAGEMENT: Adaptability ===
('strengths', 'Teacher adapts teaching approach in the light of student feedback and reactions', 'Adjustments are made seamlessly when students express confusion, showing keen awareness of learner needs in real time.', '', 'seed', 1),
('strengths', 'Teacher adapts teaching approach in the light of student feedback and reactions', 'Responsive teaching is evident as the pace, examples, and explanations shift based on observed student comprehension.', '', 'seed', 1),
('strengths', 'Teacher adapts teaching approach in the light of student feedback and reactions', 'Flexibility in instructional delivery ensures that no student is left behind when the class struggles with a concept.', '', 'seed', 1),
('strengths', 'Teacher adapts teaching approach in the light of student feedback and reactions', 'Quick pivots to alternative explanations or activities when initial approaches do not resonate reflect strong pedagogical instincts.', '', 'seed', 1),
('strengths', 'Teacher adapts teaching approach in the light of student feedback and reactions', 'Student questions and reactions are treated as valuable data, guiding adjustments in real time to optimize learning.', '', 'seed', 1),

-- === MANAGEMENT: Questioning Techniques ===
('strengths', 'Teacher aids students using thought-provoking questions (Art of Questioning)', 'Questions are carefully crafted to promote higher-order thinking, pushing students beyond surface-level recall.', '', 'seed', 1),
('strengths', 'Teacher aids students using thought-provoking questions (Art of Questioning)', 'Thought-provoking prompts challenge students to analyze, evaluate, and synthesize information rather than simply memorizing it.', '', 'seed', 1),
('strengths', 'Teacher aids students using thought-provoking questions (Art of Questioning)', 'Bloom''s Taxonomy is evidently applied in question formulation, moving students through progressively complex cognitive tasks.', '', 'seed', 1),
('strengths', 'Teacher aids students using thought-provoking questions (Art of Questioning)', 'Follow-up questions that probe deeper into student responses demonstrate masterful use of Socratic questioning techniques.', '', 'seed', 1),
('strengths', 'Teacher aids students using thought-provoking questions (Art of Questioning)', 'Scaffolded questioning guides students from basic understanding to critical analysis, building intellectual confidence along the way.', '', 'seed', 1),

-- === MANAGEMENT: Core Values Integration ===
('strengths', 'Teacher integrates institutional core values into the lessons', 'Core values are naturally embedded in lesson discussions, reinforcing the institution''s mission without feeling forced or artificial.', '', 'seed', 1),
('strengths', 'Teacher integrates institutional core values into the lessons', 'Lessons reflect a genuine commitment to the institution''s core values, as evidenced by examples and activities that embody them.', '', 'seed', 1),
('strengths', 'Teacher integrates institutional core values into the lessons', 'Character formation is woven into academic content, helping students internalize values alongside knowledge.', '', 'seed', 1),
('strengths', 'Teacher integrates institutional core values into the lessons', 'The integration of institutional values into everyday instruction models holistic education that goes beyond academic achievement.', '', 'seed', 1),
('strengths', 'Teacher integrates institutional core values into the lessons', 'Students are encouraged to reflect on how lesson content connects to broader ethical principles championed by the institution.', '', 'seed', 1),

-- === MANAGEMENT: SMART Principle ===
('strengths', 'Teacher conducts the lesson using the principle of SMART', 'Lessons are structured with specific, measurable, attainable, relevant, and time-bound objectives that guide every activity.', '', 'seed', 1),
('strengths', 'Teacher conducts the lesson using the principle of SMART', 'SMART principles are evident in both lesson planning and execution, resulting in focused and productive classroom sessions.', '', 'seed', 1),
('strengths', 'Teacher conducts the lesson using the principle of SMART', 'Clear timelines and milestones within the lesson reflect disciplined application of the SMART framework.', '', 'seed', 1),
('strengths', 'Teacher conducts the lesson using the principle of SMART', 'Objectives are realistic and well-scoped, ensuring that students can achieve the intended outcomes within the allotted time.', '', 'seed', 1),
('strengths', 'Teacher conducts the lesson using the principle of SMART', 'The purposeful alignment of activities with SMART goals keeps the lesson on track and maximizes learning efficiency.', '', 'seed', 1),

-- === ASSESSMENT: Understanding Monitoring ===
('strengths', 'Teacher monitors students understanding on key concepts discussed', 'Regular comprehension checks throughout the lesson ensure that students are keeping pace with the material being covered.', '', 'seed', 1),
('strengths', 'Teacher monitors students understanding on key concepts discussed', 'Informal assessments and quick polls during instruction provide real-time insight into student understanding.', '', 'seed', 1),
('strengths', 'Teacher monitors students understanding on key concepts discussed', 'Circulating around the room and checking individual work demonstrates a hands-on approach to monitoring student progress.', '', 'seed', 1),
('strengths', 'Teacher monitors students understanding on key concepts discussed', 'Students are prompted to summarize, explain, or demonstrate concepts, which serves as an effective gauge of comprehension.', '', 'seed', 1),
('strengths', 'Teacher monitors students understanding on key concepts discussed', 'Non-verbal cues from students are noticed and addressed promptly, indicating attentive observation of the class.', '', 'seed', 1),

-- === ASSESSMENT: Assessment Tool Alignment ===
('strengths', 'Teacher uses assessment tools that relate to specific course competencies stated in the syllabus', 'Assessments are tightly aligned with syllabus competencies, ensuring that evaluations truly measure what was taught.', '', 'seed', 1),
('strengths', 'Teacher uses assessment tools that relate to specific course competencies stated in the syllabus', 'Assessment instruments reflect the course objectives, providing valid and reliable measures of student learning.', '', 'seed', 1),
('strengths', 'Teacher uses assessment tools that relate to specific course competencies stated in the syllabus', 'Each assessment item maps directly to a stated competency, ensuring curriculum alignment and fairness in evaluation.', '', 'seed', 1),
('strengths', 'Teacher uses assessment tools that relate to specific course competencies stated in the syllabus', 'Well-constructed rubrics and test items demonstrate careful planning to assess the competencies outlined in the syllabus.', '', 'seed', 1),
('strengths', 'Teacher uses assessment tools that relate to specific course competencies stated in the syllabus', 'The connection between assessment tasks and learning outcomes is clear, giving students confidence that evaluations are fair.', '', 'seed', 1),

-- === ASSESSMENT: Assessment Design ===
('strengths', 'Teacher designs tests, assignments, and assessment tasks that are criterion-based', 'Assessment tasks are criterion-referenced and clearly defined, leaving no ambiguity about expectations for student performance.', '', 'seed', 1),
('strengths', 'Teacher designs tests, assignments, and assessment tasks that are criterion-based', 'Detailed rubrics accompany each assessment, ensuring consistent and transparent grading standards.', '', 'seed', 1),
('strengths', 'Teacher designs tests, assignments, and assessment tasks that are criterion-based', 'Assessment design reflects careful thought about what successful student performance looks like across multiple levels.', '', 'seed', 1),
('strengths', 'Teacher designs tests, assignments, and assessment tasks that are criterion-based', 'Criterion-based assessments promote fairness and objectivity, as students are evaluated against fixed standards rather than peers.', '', 'seed', 1),

-- === ASSESSMENT: Differentiated Learning ===
('strengths', 'Teacher introduces varied activities for differentiated needs of learners', 'Diverse learning activities address multiple intelligences and learning styles, ensuring every student can access the content.', '', 'seed', 1),
('strengths', 'Teacher introduces varied activities for differentiated needs of learners', 'Differentiated tasks allow students to demonstrate mastery in ways that suit their individual strengths and abilities.', '', 'seed', 1),
('strengths', 'Teacher introduces varied activities for differentiated needs of learners', 'A thoughtful mix of individual, paired, and group activities caters to different social and cognitive preferences.', '', 'seed', 1),
('strengths', 'Teacher introduces varied activities for differentiated needs of learners', 'Tiered assignments provide appropriate challenge levels, ensuring that both struggling and advanced learners remain engaged.', '', 'seed', 1),
('strengths', 'Teacher introduces varied activities for differentiated needs of learners', 'Lesson activities reflect an understanding of student diversity, with options that accommodate various readiness levels.', '', 'seed', 1),

-- === ASSESSMENT: Normative Assessment ===
('strengths', 'Teacher conducts normative assessment before evaluating learner performance', 'Baseline assessments establish a clear picture of student knowledge before instruction, informing differentiated planning.', '', 'seed', 1),
('strengths', 'Teacher conducts normative assessment before evaluating learner performance', 'Pre-assessments are used effectively to identify learning gaps and tailor instruction accordingly.', '', 'seed', 1),
('strengths', 'Teacher conducts normative assessment before evaluating learner performance', 'Diagnostic evaluations at the beginning of units help set realistic expectations and personalize learning pathways.', '', 'seed', 1),
('strengths', 'Teacher conducts normative assessment before evaluating learner performance', 'Normative data collection before grading ensures that performance evaluations are contextualized and fair.', '', 'seed', 1),

-- === ASSESSMENT: Formative Assessment Monitoring ===
('strengths', 'Teacher monitors formative assessment results and finds ways to ensure learning', 'Formative assessment data is used strategically to adjust instruction and provide targeted support where needed.', '', 'seed', 1),
('strengths', 'Teacher monitors formative assessment results and finds ways to ensure learning', 'Ongoing formative checks allow for timely interventions, preventing small misunderstandings from becoming larger gaps.', '', 'seed', 1),
('strengths', 'Teacher monitors formative assessment results and finds ways to ensure learning', 'Results from quizzes, exit tickets, and class activities are reviewed and used to shape subsequent lessons.', '', 'seed', 1),
('strengths', 'Teacher monitors formative assessment results and finds ways to ensure learning', 'A data-driven approach to formative assessment demonstrates commitment to continuous improvement in student outcomes.', '', 'seed', 1),
('strengths', 'Teacher monitors formative assessment results and finds ways to ensure learning', 'Formative feedback is timely and specific, helping students understand exactly what they need to work on next.', '', 'seed', 1),

-- === GENERAL STRENGTHS (cross-cutting) ===
('strengths', 'Teacher explains concepts clearly and presents content in an organized way', 'Content is presented in a logical sequence, building from foundational ideas to more complex concepts in a natural progression.', '', 'seed', 1),
('strengths', 'Teacher explains concepts clearly and presents content in an organized way', 'The structured approach to lesson delivery ensures coherence and makes it easy for students to follow along.', '', 'seed', 1),
('strengths', 'Teacher explains concepts clearly and presents content in an organized way', 'Complex topics are broken down into manageable segments, with clear transitions between each part of the lesson.', '', 'seed', 1),
('strengths', 'Teacher keeps the classroom orderly and maintains respectful interactions', 'A well-organized classroom environment minimizes distractions and allows students to concentrate fully on learning.', '', 'seed', 1),
('strengths', 'Teacher keeps the classroom orderly and maintains respectful interactions', 'Mutual respect between the instructor and students creates a positive atmosphere conducive to academic growth.', '', 'seed', 1),
('strengths', 'Teacher keeps the classroom orderly and maintains respectful interactions', 'Classroom routines and expectations are clearly established, resulting in smooth transitions and productive use of time.', '', 'seed', 1),
('strengths', 'Teacher demonstrates mastery of the subject matter', 'Deep subject knowledge is evident in the accuracy and depth of explanations provided during instruction.', '', 'seed', 1),
('strengths', 'Teacher demonstrates mastery of the subject matter', 'Confident handling of student questions on complex topics reflects thorough preparation and expertise in the subject area.', '', 'seed', 1),
('strengths', 'Teacher demonstrates mastery of the subject matter', 'The ability to explain concepts from multiple angles shows a comprehensive understanding of the material.', '', 'seed', 1),
('strengths', 'Teacher shows genuine enthusiasm for teaching', 'Passion for the subject is infectious, motivating students to develop their own interest and curiosity in the topic.', '', 'seed', 1),
('strengths', 'Teacher shows genuine enthusiasm for teaching', 'Energy and enthusiasm are maintained throughout the class, creating a lively and inspiring learning environment.', '', 'seed', 1),
('strengths', 'Teacher manages class time effectively', 'Time management is exemplary, with each segment of the lesson receiving appropriate attention without rushing or dragging.', '', 'seed', 1),
('strengths', 'Teacher manages class time effectively', 'The lesson begins and ends on schedule, demonstrating respect for students'' time and disciplined planning.', '', 'seed', 1),
('strengths', 'Teacher provides clear and specific instructions for activities', 'Step-by-step instructions for class activities eliminate confusion and allow students to work independently with confidence.', '', 'seed', 1),
('strengths', 'Teacher provides clear and specific instructions for activities', 'Written and verbal directions are consistent and detailed, reducing the need for repeated clarification.', '', 'seed', 1),
('strengths', 'Teacher creates a safe and supportive learning environment', 'The classroom atmosphere is welcoming and non-judgmental, encouraging students to take intellectual risks without fear.', '', 'seed', 1),
('strengths', 'Teacher creates a safe and supportive learning environment', 'An inclusive and respectful tone permeates every interaction, making all students feel valued and supported.', '', 'seed', 1),
('strengths', 'Teacher uses effective transitions between lesson segments', 'Transitions between activities are smooth and purposeful, maintaining momentum and minimizing downtime.', '', 'seed', 1),
('strengths', 'Teacher provides timely and meaningful feedback to students', 'Feedback is specific, actionable, and delivered promptly, enabling students to make immediate improvements.', '', 'seed', 1),
('strengths', 'Teacher provides timely and meaningful feedback to students', 'Constructive comments on student work go beyond grades, offering clear guidance on how to enhance performance.', '', 'seed', 1),
('strengths', 'Clear explanations supported learner understanding', 'Explanations are well-structured and illustrated with relevant examples, directly supporting student comprehension.', '', 'seed', 1),
('strengths', 'Directions were clear and pacing stayed steady', 'A consistent and well-calibrated pace allows students to absorb information without feeling rushed or bored.', '', 'seed', 1),
('strengths', 'Teacher encourages collaborative learning among students', 'Group activities and peer interactions foster a collaborative spirit that enriches the overall learning experience.', '', 'seed', 1),
('strengths', 'Teacher encourages collaborative learning among students', 'Cooperative learning structures are implemented thoughtfully, ensuring productive teamwork and shared responsibility.', '', 'seed', 1),
('strengths', 'Teacher demonstrates sensitivity to diverse student backgrounds', 'Cultural awareness is reflected in lesson content and interactions, respecting the diversity present in the classroom.', '', 'seed', 1),
('strengths', 'Teacher integrates technology effectively into instruction', 'Digital tools are used strategically to enhance engagement and deepen understanding of lesson content.', '', 'seed', 1),
('strengths', 'Teacher maintains professional demeanor throughout the lesson', 'Professionalism in conduct and communication sets a positive example for students and upholds institutional standards.', '', 'seed', 1);


-- =====================================================================
-- CATEGORY 2: AREAS FOR IMPROVEMENT
-- 155 unique feedback comments
-- =====================================================================

INSERT INTO ai_feedback_templates (field_name, evaluation_comment, feedback_text, embedding_vector, source, is_active) VALUES

-- === COMMUNICATIONS: Vocal Clarity ===
('areas_for_improvement', 'Teacher voice is sometimes too soft for students at the back', 'At times, the volume drops during key explanations, making it difficult for students seated farther away to catch every detail.', '', 'seed', 1),
('areas_for_improvement', 'Teacher voice is sometimes too soft for students at the back', 'Some students in the rear of the classroom have difficulty hearing clearly, indicating room for improvement in voice projection.', '', 'seed', 1),
('areas_for_improvement', 'Teacher voice is sometimes too soft for students at the back', 'While content delivery is strong, occasional dips in volume may leave some students struggling to hear important information.', '', 'seed', 1),
('areas_for_improvement', 'Teacher voice is sometimes too soft for students at the back', 'Maintaining consistent volume throughout the session would help ensure that all students benefit equally from the instruction.', '', 'seed', 1),
('areas_for_improvement', 'Teacher voice is sometimes too soft for students at the back', 'The quality of explanations is high, but reaching students in all parts of the room requires more deliberate vocal projection.', '', 'seed', 1),

-- === COMMUNICATIONS: Language Fluency ===
('areas_for_improvement', 'Teacher occasionally struggles with fluency in the language of instruction', 'Brief hesitations during instruction suggest that building greater fluency in the medium of instruction could strengthen delivery.', '', 'seed', 1),
('areas_for_improvement', 'Teacher occasionally struggles with fluency in the language of instruction', 'Occasional grammatical inconsistencies can distract from otherwise solid content, so polishing language skills would be beneficial.', '', 'seed', 1),
('areas_for_improvement', 'Teacher occasionally struggles with fluency in the language of instruction', 'While ideas are communicated effectively, smoother language transitions would enhance the overall flow of the lesson.', '', 'seed', 1),
('areas_for_improvement', 'Teacher occasionally struggles with fluency in the language of instruction', 'Some pauses while searching for the right words in the language of instruction may slow down the lesson pacing slightly.', '', 'seed', 1),

-- === COMMUNICATIONS: Discussion Facilitation ===
('areas_for_improvement', 'Class discussions tend to be one-sided with limited student participation', 'Discussions sometimes lean toward lecture-style delivery, with fewer opportunities for students to contribute their perspectives.', '', 'seed', 1),
('areas_for_improvement', 'Class discussions tend to be one-sided with limited student participation', 'Only a handful of students dominate the conversation, suggesting a need for strategies that encourage broader participation.', '', 'seed', 1),
('areas_for_improvement', 'Class discussions tend to be one-sided with limited student participation', 'Creating more structured discussion opportunities would help draw out responses from students who tend to remain silent.', '', 'seed', 1),
('areas_for_improvement', 'Class discussions tend to be one-sided with limited student participation', 'The class could benefit from more open-ended questions that invite multiple perspectives rather than single correct answers.', '', 'seed', 1),
('areas_for_improvement', 'Class discussions tend to be one-sided with limited student participation', 'While the content shared is valuable, shifting more responsibility to students during discussions would deepen engagement.', '', 'seed', 1),

-- === COMMUNICATIONS: Non-verbal Cues ===
('areas_for_improvement', 'Teacher relies mostly on verbal instruction with minimal non-verbal engagement', 'Greater use of gestures, facial expressions, and physical movement could make presentations more dynamic and engaging.', '', 'seed', 1),
('areas_for_improvement', 'Teacher relies mostly on verbal instruction with minimal non-verbal engagement', 'Standing in one spot and reading from notes limits the visual energy in the classroom and may reduce student attention.', '', 'seed', 1),
('areas_for_improvement', 'Teacher relies mostly on verbal instruction with minimal non-verbal engagement', 'Incorporating more eye contact and body language awareness could strengthen the connection between instructor and students.', '', 'seed', 1),
('areas_for_improvement', 'Teacher relies mostly on verbal instruction with minimal non-verbal engagement', 'Non-verbal communication is an underutilized tool in the classroom that could significantly enhance message delivery.', '', 'seed', 1),

-- === COMMUNICATIONS: Language Level ===
('areas_for_improvement', 'Some vocabulary used may be too advanced for the students level', 'Technical terms are occasionally introduced without sufficient explanation, which may confuse less prepared students.', '', 'seed', 1),
('areas_for_improvement', 'Some vocabulary used may be too advanced for the students level', 'Simplifying some of the language used during explanations would improve understanding for students who are still building foundational knowledge.', '', 'seed', 1),
('areas_for_improvement', 'Some vocabulary used may be too advanced for the students level', 'Being more mindful of student vocabulary levels when explaining new concepts would make the lesson more inclusive.', '', 'seed', 1),
('areas_for_improvement', 'Some vocabulary used may be too advanced for the students level', 'Providing definitions or synonyms for discipline-specific terms as they are introduced would support student comprehension.', '', 'seed', 1),

-- === MANAGEMENT: Learning Outcomes ===
('areas_for_improvement', 'Learning outcomes are not clearly stated or visible during the lesson', 'Starting the lesson with clearly stated and visible learning outcomes would help students understand the purpose of each activity.', '', 'seed', 1),
('areas_for_improvement', 'Learning outcomes are not clearly stated or visible during the lesson', 'Without explicit learning objectives, students may struggle to connect individual activities to the broader goals of the session.', '', 'seed', 1),
('areas_for_improvement', 'Learning outcomes are not clearly stated or visible during the lesson', 'Posting or projecting the intended learning outcomes at the start of class provides students with a clear sense of direction.', '', 'seed', 1),
('areas_for_improvement', 'Learning outcomes are not clearly stated or visible during the lesson', 'When objectives are vague or absent, students may leave the class unsure of what they were expected to learn.', '', 'seed', 1),
('areas_for_improvement', 'Learning outcomes are not clearly stated or visible during the lesson', 'Revisiting stated outcomes at the end of the lesson can reinforce learning and give closure to the session.', '', 'seed', 1),

-- === MANAGEMENT: Lesson Continuity ===
('areas_for_improvement', 'Connections to previous lessons are weak or missing', 'Bridging the gap between previous and current topics would help students see the logical progression of the curriculum.', '', 'seed', 1),
('areas_for_improvement', 'Connections to previous lessons are weak or missing', 'A brief review or recall activity at the beginning of class could activate prior knowledge and prepare students for new content.', '', 'seed', 1),
('areas_for_improvement', 'Connections to previous lessons are weak or missing', 'Without linking back to earlier material, students may view each lesson as isolated rather than part of a connected learning journey.', '', 'seed', 1),
('areas_for_improvement', 'Connections to previous lessons are weak or missing', 'Strengthening the transition from old to new topics would enhance the coherence and flow of the overall course.', '', 'seed', 1),

-- === MANAGEMENT: Lesson Introduction ===
('areas_for_improvement', 'Lesson introductions could be more engaging to capture student interest', 'The opening of the lesson could benefit from a more creative or attention-grabbing activity to set the tone for learning.', '', 'seed', 1),
('areas_for_improvement', 'Lesson introductions could be more engaging to capture student interest', 'Starting directly with content without a warm-up or motivational activity may miss an opportunity to spark student curiosity.', '', 'seed', 1),
('areas_for_improvement', 'Lesson introductions could be more engaging to capture student interest', 'A thought-provoking question, short video, or interesting fact at the start could draw students in more effectively.', '', 'seed', 1),
('areas_for_improvement', 'Lesson introductions could be more engaging to capture student interest', 'Investing more time in the lesson introduction could yield higher engagement and participation throughout the session.', '', 'seed', 1),

-- === MANAGEMENT: Real-World Examples ===
('areas_for_improvement', 'Lesson lacks real-life examples and local context', 'Incorporating more current events or everyday scenarios into the discussion would make the content more relatable for students.', '', 'seed', 1),
('areas_for_improvement', 'Lesson lacks real-life examples and local context', 'Abstract concepts presented without practical examples may be difficult for students to retain and apply beyond the classroom.', '', 'seed', 1),
('areas_for_improvement', 'Lesson lacks real-life examples and local context', 'Drawing connections between the lesson and students'' lived experiences could significantly increase both interest and retention.', '', 'seed', 1),
('areas_for_improvement', 'Lesson lacks real-life examples and local context', 'The lesson would benefit from situating theoretical content within familiar contexts that students encounter in their daily lives.', '', 'seed', 1),

-- === MANAGEMENT: Key Concepts Focus ===
('areas_for_improvement', 'Discussion sometimes drifts away from the key concepts of the lesson', 'Off-topic discussions, while sometimes interesting, take valuable time away from covering the lesson''s essential content.', '', 'seed', 1),
('areas_for_improvement', 'Discussion sometimes drifts away from the key concepts of the lesson', 'Maintaining tighter focus on core concepts would ensure that the most important ideas receive adequate attention and depth.', '', 'seed', 1),
('areas_for_improvement', 'Discussion sometimes drifts away from the key concepts of the lesson', 'Setting clearer boundaries around discussion topics could help keep the class on track and aligned with planned outcomes.', '', 'seed', 1),
('areas_for_improvement', 'Discussion sometimes drifts away from the key concepts of the lesson', 'While tangential topics can be enriching, prioritizing key concepts first would build a stronger foundation for student learning.', '', 'seed', 1),

-- === MANAGEMENT: Student Participation ===
('areas_for_improvement', 'Only a few students answer questions and participation is limited during discussions', 'Most interactions involve the same vocal students, while the rest of the class remains passive observers.', '', 'seed', 1),
('areas_for_improvement', 'Only a few students answer questions and participation is limited during discussions', 'Strategies such as random calling, small group sharing, or digital polling could broaden participation beyond the usual respondents.', '', 'seed', 1),
('areas_for_improvement', 'Only a few students answer questions and participation is limited during discussions', 'Quieter students may need more structured support—like written reflections or partner discussions—before sharing with the whole class.', '', 'seed', 1),
('areas_for_improvement', 'Only a few students answer questions and participation is limited during discussions', 'Creating a classroom culture where all contributions are valued, regardless of accuracy, can help reluctant students participate.', '', 'seed', 1),
('areas_for_improvement', 'Only a few students answer questions and participation is limited during discussions', 'Varying question types and wait times could encourage a wider range of students to engage in classroom discussions.', '', 'seed', 1),

-- === MANAGEMENT: Instructional Strategies ===
('areas_for_improvement', 'Teaching strategies could be more varied and current', 'Relying predominantly on one teaching method limits engagement; incorporating additional strategies would benefit different learner types.', '', 'seed', 1),
('areas_for_improvement', 'Teaching strategies could be more varied and current', 'Exploring newer instructional approaches such as flipped learning, case studies, or project-based tasks could reinvigorate the classroom.', '', 'seed', 1),
('areas_for_improvement', 'Teaching strategies could be more varied and current', 'While traditional lecture is effective for content delivery, mixing in interactive elements would deepen student understanding.', '', 'seed', 1),
('areas_for_improvement', 'Teaching strategies could be more varied and current', 'Professional development opportunities focused on innovative pedagogy could expand the instructional toolkit available.', '', 'seed', 1),

-- === MANAGEMENT: Teaching Aids ===
('areas_for_improvement', 'Teaching aids are minimal or not effectively designed', 'More visually engaging and well-organized teaching materials could enhance student understanding of complex topics.', '', 'seed', 1),
('areas_for_improvement', 'Teaching aids are minimal or not effectively designed', 'Presentation slides with less text and more visuals, diagrams, or infographics would improve clarity and retention.', '', 'seed', 1),
('areas_for_improvement', 'Teaching aids are minimal or not effectively designed', 'The lesson could benefit from supplementary handouts, graphic organizers, or reference sheets that students can review later.', '', 'seed', 1),
('areas_for_improvement', 'Teaching aids are minimal or not effectively designed', 'Investing time in designing purposeful teaching aids would make abstract concepts more concrete and accessible to all learners.', '', 'seed', 1),

-- === MANAGEMENT: Adaptability ===
('areas_for_improvement', 'Teacher does not adjust teaching when students show confusion', 'When student confusion is evident, continuing with the original plan without modification may leave gaps in understanding.', '', 'seed', 1),
('areas_for_improvement', 'Teacher does not adjust teaching when students show confusion', 'Pausing to re-explain concepts using different approaches when students appear confused would strengthen comprehension.', '', 'seed', 1),
('areas_for_improvement', 'Teacher does not adjust teaching when students show confusion', 'Being more responsive to non-verbal signs of confusion, such as puzzled expressions, would improve the learning experience.', '', 'seed', 1),
('areas_for_improvement', 'Teacher does not adjust teaching when students show confusion', 'Flexibility to deviate from the lesson plan when necessary is important for addressing real-time learning challenges.', '', 'seed', 1),

-- === MANAGEMENT: Questioning Techniques ===
('areas_for_improvement', 'Questions tend to be recall-level and do not promote higher-order thinking', 'Incorporating more analysis, synthesis, and evaluation questions would push students to engage with the material on a deeper level.', '', 'seed', 1),
('areas_for_improvement', 'Questions tend to be recall-level and do not promote higher-order thinking', 'Questions that require only yes/no or single-word answers miss opportunities to develop critical thinking skills.', '', 'seed', 1),
('areas_for_improvement', 'Questions tend to be recall-level and do not promote higher-order thinking', 'Encouraging students to explain their reasoning or compare different perspectives would elevate the quality of classroom discourse.', '', 'seed', 1),
('areas_for_improvement', 'Questions tend to be recall-level and do not promote higher-order thinking', 'Moving beyond factual recall to ask "why" and "how" questions can transform passive listeners into active thinkers.', '', 'seed', 1),
('areas_for_improvement', 'Questions tend to be recall-level and do not promote higher-order thinking', 'The art of questioning is underdeveloped; exploring Socratic questioning methods could significantly enhance student engagement.', '', 'seed', 1),

-- === MANAGEMENT: Core Values ===
('areas_for_improvement', 'Institutional core values are not integrated into the lesson', 'Finding natural connection points between the subject matter and institutional values would add depth and purpose to the lesson.', '', 'seed', 1),
('areas_for_improvement', 'Institutional core values are not integrated into the lesson', 'Core values integration need not be forced; simple reflections or brief discussions can weave them organically into the lesson.', '', 'seed', 1),
('areas_for_improvement', 'Institutional core values are not integrated into the lesson', 'Incorporating values-based discussions within the subject context would contribute to holistic student formation.', '', 'seed', 1),
('areas_for_improvement', 'Institutional core values are not integrated into the lesson', 'Recognizing moments in the lesson where character formation can be addressed would strengthen the institution''s educational mission.', '', 'seed', 1),

-- === MANAGEMENT: SMART Principle ===
('areas_for_improvement', 'Lesson structure does not clearly follow the SMART principle', 'Clearer time-bound objectives and measurable benchmarks within the lesson would help maintain focus and productivity.', '', 'seed', 1),
('areas_for_improvement', 'Lesson structure does not clearly follow the SMART principle', 'Some lesson goals feel broad and unmeasurable, making it harder to determine whether learning outcomes have been achieved.', '', 'seed', 1),
('areas_for_improvement', 'Lesson structure does not clearly follow the SMART principle', 'Applying the SMART framework more deliberately to lesson planning could improve both structure and student achievement.', '', 'seed', 1),
('areas_for_improvement', 'Lesson structure does not clearly follow the SMART principle', 'Without specific and time-bound milestones, the lesson may feel unstructured and difficult for students to follow.', '', 'seed', 1),

-- === ASSESSMENT: Understanding Monitoring ===
('areas_for_improvement', 'Checks for understanding are infrequent during the lesson', 'More frequent comprehension checks would help identify and address misunderstandings before they become entrenched.', '', 'seed', 1),
('areas_for_improvement', 'Checks for understanding are infrequent during the lesson', 'Relying solely on end-of-lesson assessments misses opportunities to catch and correct confusion during instruction.', '', 'seed', 1),
('areas_for_improvement', 'Checks for understanding are infrequent during the lesson', 'Quick formative checks such as thumbs up/down, exit slips, or brief quizzes could provide valuable real-time data.', '', 'seed', 1),
('areas_for_improvement', 'Checks for understanding are infrequent during the lesson', 'Asking students to paraphrase or explain concepts in their own words would reveal whether key ideas have been grasped.', '', 'seed', 1),
('areas_for_improvement', 'Checks for understanding should be more visible', 'Making comprehension monitoring a more visible and routine part of the lesson would benefit both the instructor and students.', '', 'seed', 1),

-- === ASSESSMENT: Assessment Alignment ===
('areas_for_improvement', 'Assessment tasks do not clearly align with course competencies', 'Ensuring that every assessment item maps to a specific syllabus competency would strengthen the validity of evaluations.', '', 'seed', 1),
('areas_for_improvement', 'Assessment tasks do not clearly align with course competencies', 'Reviewing assessment tools against stated course objectives could reveal gaps in alignment that affect student evaluation fairness.', '', 'seed', 1),
('areas_for_improvement', 'Assessment tasks do not clearly align with course competencies', 'When assessment tasks and learning outcomes are misaligned, students may feel that evaluations do not reflect what was actually taught.', '', 'seed', 1),
('areas_for_improvement', 'Assessment tasks do not clearly align with course competencies', 'Closer alignment between instruction, assessment, and syllabus objectives would create a more coherent learning experience.', '', 'seed', 1),

-- === ASSESSMENT: Assessment Design ===
('areas_for_improvement', 'Assessment design lacks clear criteria or rubrics', 'Providing detailed rubrics for assignments and assessments would help students understand expectations and self-assess their work.', '', 'seed', 1),
('areas_for_improvement', 'Assessment design lacks clear criteria or rubrics', 'Without transparent grading criteria, students may feel uncertain about how their performance will be evaluated.', '', 'seed', 1),
('areas_for_improvement', 'Assessment design lacks clear criteria or rubrics', 'Criterion-referenced assessments with clear descriptors at each performance level would promote fairness and transparency.', '', 'seed', 1),
('areas_for_improvement', 'Assessment design lacks clear criteria or rubrics', 'Sharing assessment criteria beforehand empowers students to direct their efforts toward meeting specific standards.', '', 'seed', 1),

-- === ASSESSMENT: Differentiated Learning ===
('areas_for_improvement', 'Activities do not address the differentiated needs of diverse learners', 'A one-size-fits-all approach to activities may not reach students with varying ability levels or learning preferences.', '', 'seed', 1),
('areas_for_improvement', 'Activities do not address the differentiated needs of diverse learners', 'Offering tiered tasks or multiple pathways to demonstrate learning could better serve the diverse needs in the classroom.', '', 'seed', 1),
('areas_for_improvement', 'Activities do not address the differentiated needs of diverse learners', 'Some students may need additional scaffolding or extension activities to feel appropriately challenged or supported.', '', 'seed', 1),
('areas_for_improvement', 'Activities do not address the differentiated needs of diverse learners', 'Recognizing that students learn differently and designing activities accordingly would make the classroom more inclusive.', '', 'seed', 1),
('areas_for_improvement', 'Activities do not address the differentiated needs of diverse learners', 'Incorporating choice-based activities allows students to engage with content in ways that match their strengths and interests.', '', 'seed', 1),

-- === ASSESSMENT: Normative Assessment ===
('areas_for_improvement', 'Normative assessment is not conducted before grading', 'Conducting pre-assessments before grading would provide a fairer baseline from which to measure student growth and achievement.', '', 'seed', 1),
('areas_for_improvement', 'Normative assessment is not conducted before grading', 'Without normative data, it becomes difficult to determine whether grades reflect genuine learning gains or pre-existing knowledge.', '', 'seed', 1),
('areas_for_improvement', 'Normative assessment is not conducted before grading', 'Establishing baseline understanding through diagnostic tools before formal evaluation would improve grading fairness.', '', 'seed', 1),

-- === ASSESSMENT: Formative Assessment ===
('areas_for_improvement', 'Formative assessment results are not effectively used to guide instruction', 'Formative data collected but not acted upon represents a missed opportunity to improve student learning outcomes.', '', 'seed', 1),
('areas_for_improvement', 'Formative assessment results are not effectively used to guide instruction', 'Using formative assessment results to identify struggling students and adjust instruction accordingly would close learning gaps faster.', '', 'seed', 1),
('areas_for_improvement', 'Formative assessment results are not effectively used to guide instruction', 'Following up on formative assessment findings with targeted remediation or enrichment would maximize student progress.', '', 'seed', 1),
('areas_for_improvement', 'Feedback follow-through needs improvement', 'Providing feedback without following up on whether students have understood or applied it limits the impact of assessment.', '', 'seed', 1),
('areas_for_improvement', 'Feedback follow-through needs improvement', 'Closing the feedback loop by checking if students have acted on prior suggestions would reinforce continuous improvement.', '', 'seed', 1),

-- === GENERAL AREAS FOR IMPROVEMENT ===
('areas_for_improvement', 'Teacher explains lessons clearly but students rarely participate', 'Content delivery is sound, but the lesson would benefit greatly from increased student involvement and interaction.', '', 'seed', 1),
('areas_for_improvement', 'Teacher explains lessons clearly but students rarely participate', 'Strong explanations paired with passive student engagement suggest a need to shift toward more learner-centered approaches.', '', 'seed', 1),
('areas_for_improvement', 'Classroom pacing could be improved', 'Some sections of the lesson felt rushed while others dragged, suggesting a need for more balanced time allocation across activities.', '', 'seed', 1),
('areas_for_improvement', 'Classroom pacing could be improved', 'Adjusting the pacing to allow more time for student processing and less time on routine tasks would improve overall effectiveness.', '', 'seed', 1),
('areas_for_improvement', 'Transitions between activities are abrupt', 'Smoother transitions with brief summaries or connection statements would help students shift focus without losing momentum.', '', 'seed', 1),
('areas_for_improvement', 'Transitions between activities are abrupt', 'The sudden shift from one activity to the next can disorient students; signaling transitions with verbal or visual cues would help.', '', 'seed', 1),
('areas_for_improvement', 'Student engagement drops during extended lectures', 'Breaking up lengthy lecture segments with interactive activities would help maintain student focus and energy levels.', '', 'seed', 1),
('areas_for_improvement', 'Student engagement drops during extended lectures', 'Extended periods of teacher-centered instruction without breaks for student activity may lead to reduced attention and retention.', '', 'seed', 1),
('areas_for_improvement', 'Written feedback on student work could be more specific', 'General comments like "good job" or "needs improvement" do not give students enough information to know what to change or continue.', '', 'seed', 1),
('areas_for_improvement', 'Written feedback on student work could be more specific', 'More detailed written feedback on assignments would empower students to understand their specific strengths and areas for growth.', '', 'seed', 1),
('areas_for_improvement', 'Classroom management could be strengthened', 'Minor behavioral disruptions occasionally go unaddressed, which can escalate if not managed proactively.', '', 'seed', 1),
('areas_for_improvement', 'Classroom management could be strengthened', 'Establishing clearer behavioral expectations and consistent follow-through would create a more focused learning environment.', '', 'seed', 1),
('areas_for_improvement', 'Technology integration is limited or ineffective', 'Greater exploration of available educational technology tools could enhance lesson delivery and student engagement.', '', 'seed', 1),
('areas_for_improvement', 'Technology integration is limited or ineffective', 'While low-tech methods have their place, strategic use of digital resources could add variety and depth to the learning experience.', '', 'seed', 1),
('areas_for_improvement', 'Lesson closure could be more effective', 'The lesson ends abruptly without summary or reflection, missing an opportunity to consolidate student learning.', '', 'seed', 1),
('areas_for_improvement', 'Lesson closure could be more effective', 'A structured closing activity such as a summary, reflection question, or exit ticket would give proper closure to the session.', '', 'seed', 1);


-- =====================================================================
-- CATEGORY 3: RECOMMENDATIONS
-- 155 unique feedback comments
-- =====================================================================

INSERT INTO ai_feedback_templates (field_name, evaluation_comment, feedback_text, embedding_vector, source, is_active) VALUES

-- === COMMUNICATIONS: Vocal Projection ===
('recommendations', 'Improve voice projection and clarity', 'Consider practicing voice modulation techniques to ensure consistent audibility across all areas of the classroom.', '', 'seed', 1),
('recommendations', 'Improve voice projection and clarity', 'Using a portable microphone during extended sessions could help maintain vocal clarity without straining.', '', 'seed', 1),
('recommendations', 'Improve voice projection and clarity', 'Positioning yourself in different parts of the room at various points during the lesson can help reach all students effectively.', '', 'seed', 1),
('recommendations', 'Improve voice projection and clarity', 'Practicing breath control and projection exercises can help develop a stronger, more sustained speaking voice for the classroom.', '', 'seed', 1),
('recommendations', 'Improve voice projection and clarity', 'Recording a session and listening to playback can reveal moments where volume drops and help identify areas for improvement.', '', 'seed', 1),

-- === COMMUNICATIONS: Language Fluency ===
('recommendations', 'Enhance fluency in the language of instruction', 'Regular reading and exposure to academic texts in the language of instruction can naturallyimprove fluency over time.', '', 'seed', 1),
('recommendations', 'Enhance fluency in the language of instruction', 'Practicing lesson delivery aloud before class can build confidence and reduce hesitations during actual instruction.', '', 'seed', 1),
('recommendations', 'Enhance fluency in the language of instruction', 'Joining professional learning communities or language workshops would provide structured support for developing linguistic proficiency.', '', 'seed', 1),
('recommendations', 'Enhance fluency in the language of instruction', 'Preparing and rehearsing key vocabulary and transition phrases specific to each lesson can smooth out delivery considerably.', '', 'seed', 1),

-- === COMMUNICATIONS: Discussion Facilitation ===
('recommendations', 'Use strategies to facilitate more dynamic class discussions', 'Try implementing think-pair-share activities before whole-class discussions to give all students time to formulate their ideas.', '', 'seed', 1),
('recommendations', 'Use strategies to facilitate more dynamic class discussions', 'Assign discussion roles such as facilitator, recorder, or devil''s advocate to make group discussions more structured and engaging.', '', 'seed', 1),
('recommendations', 'Use strategies to facilitate more dynamic class discussions', 'Pose open-ended questions that have multiple valid answers to encourage diverse viewpoints and richer discussions.', '', 'seed', 1),
('recommendations', 'Use strategies to facilitate more dynamic class discussions', 'Setting ground rules for respectful dialogue at the start of the course can create a safe space for more open discussions.', '', 'seed', 1),
('recommendations', 'Use strategies to facilitate more dynamic class discussions', 'Using Socratic seminars or fishbowl discussions occasionally can model high-quality academic discourse for students.', '', 'seed', 1),

-- === COMMUNICATIONS: Non-verbal Communication ===
('recommendations', 'Incorporate more non-verbal communication strategies', 'Practice making deliberate eye contact with different sections of the room to increase student connection and attentiveness.', '', 'seed', 1),
('recommendations', 'Incorporate more non-verbal communication strategies', 'Moving around the classroom while teaching can increase proximity with more students and boost engagement.', '', 'seed', 1),
('recommendations', 'Incorporate more non-verbal communication strategies', 'Using hand signals for common classroom routines can add non-verbal variety and reduce verbal overload during instruction.', '', 'seed', 1),
('recommendations', 'Incorporate more non-verbal communication strategies', 'Video-recording yourself teaching and reviewing the footage can reveal unconscious habits and areas where body language can improve.', '', 'seed', 1),

-- === COMMUNICATIONS: Language Level ===
('recommendations', 'Adjust vocabulary to match student comprehension levels', 'Create a glossary of key terms for each lesson that students can reference during and after class discussions.', '', 'seed', 1),
('recommendations', 'Adjust vocabulary to match student comprehension levels', 'When introducing new vocabulary, provide concrete examples, visuals, or analogies to anchor understanding for all students.', '', 'seed', 1),
('recommendations', 'Adjust vocabulary to match student comprehension levels', 'Pausing after introducing a complex term to check for understanding can prevent confusion from accumulating as the lesson progresses.', '', 'seed', 1),
('recommendations', 'Adjust vocabulary to match student comprehension levels', 'Having students create personal vocabulary logs for subject-specific terms can reinforce new words and support independent learning.', '', 'seed', 1),

-- === MANAGEMENT: Learning Outcomes ===
('recommendations', 'Present learning outcomes clearly at the start of each lesson', 'Write learning outcomes on the board or display them digitally so students can reference them throughout the session.', '', 'seed', 1),
('recommendations', 'Present learning outcomes clearly at the start of each lesson', 'Begin each lesson by reading the objectives aloud and briefly explaining how each activity connects to the stated goals.', '', 'seed', 1),
('recommendations', 'Present learning outcomes clearly at the start of each lesson', 'Use "I can" statements or student-centered language when writing objectives to make them more relatable and achievable.', '', 'seed', 1),
('recommendations', 'Present learning outcomes clearly at the start of each lesson', 'At the end of each lesson, review the learning outcomes and ask students to self-assess their progress against each one.', '', 'seed', 1),
('recommendations', 'Present learning outcomes clearly at the start of each lesson', 'Aligning every classroom activity with a specific learning outcome helps maintain instructional focus and meaningful engagement.', '', 'seed', 1),

-- === MANAGEMENT: Lesson Continuity ===
('recommendations', 'Strengthen connections between previous and current lessons', 'Start each class with a two-minute recall activity where students share one thing they remember from the previous session.', '', 'seed', 1),
('recommendations', 'Strengthen connections between previous and current lessons', 'Use a brief quiz, warm-up problem, or connecting question that bridges yesterday''s content with today''s new topic.', '', 'seed', 1),
('recommendations', 'Strengthen connections between previous and current lessons', 'Creating a visual roadmap or unit timeline that shows the relationship between lessons can help students see the big picture.', '', 'seed', 1),
('recommendations', 'Strengthen connections between previous and current lessons', 'Asking students to write connections between the previous and current lesson in their notebooks promotes active reflection.', '', 'seed', 1),

-- === MANAGEMENT: Lesson Introduction ===
('recommendations', 'Create more engaging lesson introductions', 'Begin lessons with a surprising fact, a challenging question, or a short multimedia clip to capture immediate attention.', '', 'seed', 1),
('recommendations', 'Create more engaging lesson introductions', 'Use storytelling or personal anecdotes related to the topic to humanize the content and build student interest from the start.', '', 'seed', 1),
('recommendations', 'Create more engaging lesson introductions', 'Try a K-W-L chart at the beginning of new units to activate prior knowledge and set the stage for inquiry-based learning.', '', 'seed', 1),
('recommendations', 'Create more engaging lesson introductions', 'Provocative questions that challenge assumptions or present dilemmas can engage students cognitively from the very first minute.', '', 'seed', 1),

-- === MANAGEMENT: Real-World Connections ===
('recommendations', 'Integrate more real-life and local examples into lessons', 'Identify local news stories, community issues, or student interests that naturally connect to the subject matter being taught.', '', 'seed', 1),
('recommendations', 'Integrate more real-life and local examples into lessons', 'Invite guest speakers from the community or industry to share real-world applications of the concepts being discussed.', '', 'seed', 1),
('recommendations', 'Integrate more real-life and local examples into lessons', 'Design case studies based on local scenarios that require students to apply classroom knowledge to realistic situations.', '', 'seed', 1),
('recommendations', 'Integrate more real-life and local examples into lessons', 'Encouraging students to bring in their own examples of how course content appears in their daily lives creates personal investment.', '', 'seed', 1),
('recommendations', 'Integrate more real-life and local examples into lessons', 'Using current events as discussion starters can bridge the gap between academic content and real-life relevance.', '', 'seed', 1),

-- === MANAGEMENT: Focus on Key Concepts ===
('recommendations', 'Maintain stronger focus on key concepts during discussions', 'Prepare a short list of essential questions that anchor the lesson, and redirect discussions back to these when they veer off track.', '', 'seed', 1),
('recommendations', 'Maintain stronger focus on key concepts during discussions', 'Use a visible "parking lot" for off-topic but interesting ideas, acknowledging them while preserving focus on core content.', '', 'seed', 1),
('recommendations', 'Maintain stronger focus on key concepts during discussions', 'Summarizing key concepts midway through the lesson can help refocus attention and reinforce what has been covered so far.', '', 'seed', 1),
('recommendations', 'Maintain stronger focus on key concepts during discussions', 'Providing students with an outline or graphic organizer of key concepts helps them stay oriented during the lesson.', '', 'seed', 1),

-- === MANAGEMENT: Student Participation ===
('recommendations', 'Implement strategies to increase student participation', 'Use random name selectors or popsicle sticks to ensure equitable and inclusive participation across all students.', '', 'seed', 1),
('recommendations', 'Implement strategies to increase student participation', 'Incorporate collaborative structures like jigsaw activities or gallery walks that require every student to contribute.', '', 'seed', 1),
('recommendations', 'Implement strategies to increase student participation', 'Allow students to respond to questions through written responses or digital polls before sharing verbally, reducing anxiety.', '', 'seed', 1),
('recommendations', 'Implement strategies to increase student participation', 'Create small group discussions before whole-class sharing to give students a safer space to practice articulating their thoughts.', '', 'seed', 1),
('recommendations', 'Implement strategies to increase student participation', 'Recognize and celebrate diverse forms of participation, not just hand-raising, to validate different types of student engagement.', '', 'seed', 1),
('recommendations', 'Use follow-up questions to confirm understanding', 'After a student responds, ask a follow-up question such as "Can you explain why?" or "What led you to that conclusion?" to deepen thinking.', '', 'seed', 1),
('recommendations', 'Use follow-up questions to confirm understanding', 'Redirecting questions to other students ("Does everyone agree? Why or why not?") promotes broader engagement and critical analysis.', '', 'seed', 1),

-- === MANAGEMENT: Instructional Strategies ===
('recommendations', 'Diversify instructional strategies to engage different learners', 'Explore blended learning approaches that combine face-to-face instruction with online resources and activities.', '', 'seed', 1),
('recommendations', 'Diversify instructional strategies to engage different learners', 'Incorporating problem-based or project-based learning into units can deepen student understanding and develop practical skills.', '', 'seed', 1),
('recommendations', 'Diversify instructional strategies to engage different learners', 'Attend professional development workshops focused on contemporary pedagogical methods to expand instructional repertoire.', '', 'seed', 1),
('recommendations', 'Diversify instructional strategies to engage different learners', 'Experimenting with flipped classroom techniques for certain topics could free up in-class time for deeper discussion and application.', '', 'seed', 1),
('recommendations', 'Diversify instructional strategies to engage different learners', 'Balancing direct instruction with discovery-based and inquiry-based activities creates a richer learning experience.', '', 'seed', 1),

-- === MANAGEMENT: Teaching Aids ===
('recommendations', 'Develop more effective and visually engaging teaching aids', 'Use concept maps, infographics, and flowcharts to visually represent relationships between ideas in a more accessible way.', '', 'seed', 1),
('recommendations', 'Develop more effective and visually engaging teaching aids', 'Keep presentation slides clean and focused—limit text and use high-quality images or diagrams to support key points.', '', 'seed', 1),
('recommendations', 'Develop more effective and visually engaging teaching aids', 'Provide printed or digital handouts summarizing key information so students have reference materials to review after class.', '', 'seed', 1),
('recommendations', 'Develop more effective and visually engaging teaching aids', 'Explore free digital tools for creating interactive presentations, virtual whiteboards, and collaborative documents.', '', 'seed', 1),

-- === MANAGEMENT: Adaptability ===
('recommendations', 'Be more responsive to student feedback during lessons', 'Regularly check in with students by asking "Is this clear?" and watching for non-verbal signs of confusion before moving on.', '', 'seed', 1),
('recommendations', 'Be more responsive to student feedback during lessons', 'Prepare alternative explanations or examples for challenging concepts in advance, so pivoting is seamless when needed.', '', 'seed', 1),
('recommendations', 'Be more responsive to student feedback during lessons', 'Use real-time student response tools like mini-whiteboards or quick surveys to gauge understanding and adjust instruction.', '', 'seed', 1),
('recommendations', 'Be more responsive to student feedback during lessons', 'Build flexibility into the lesson plan by having optional activities or extended examples ready for moments when students need more time.', '', 'seed', 1),

-- === MANAGEMENT: Questioning ===
('recommendations', 'Elevate questioning techniques to promote higher-order thinking', 'Integrate Bloom''s Taxonomy into question planning, ensuring a mix of remembering, understanding, applying, analyzing, evaluating, and creating questions.', '', 'seed', 1),
('recommendations', 'Elevate questioning techniques to promote higher-order thinking', 'Wait at least three to five seconds after asking a question before calling on a student to allow deeper processing time.', '', 'seed', 1),
('recommendations', 'Elevate questioning techniques to promote higher-order thinking', 'Use "what if" and scenario-based questions to encourage students to think beyond the text and apply concepts creatively.', '', 'seed', 1),
('recommendations', 'Elevate questioning techniques to promote higher-order thinking', 'Encourage students to generate their own questions about the material, fostering ownership of the learning process.', '', 'seed', 1),
('recommendations', 'Elevate questioning techniques to promote higher-order thinking', 'Model the thinking process by verbalizing your own reasoning when working through complex problems alongside students.', '', 'seed', 1),

-- === MANAGEMENT: Core Values ===
('recommendations', 'Find opportunities to weave institutional core values into lessons', 'Identify specific moments in your content where values like integrity, service, or respect naturally emerge and pause to discuss them.', '', 'seed', 1),
('recommendations', 'Find opportunities to weave institutional core values into lessons', 'Use reflection prompts at the end of lessons that connect the academic content to personal values and ethical considerations.', '', 'seed', 1),
('recommendations', 'Find opportunities to weave institutional core values into lessons', 'Incorporate case studies or scenarios that highlight ethical dilemmas related to the subject, connecting content to character formation.', '', 'seed', 1),
('recommendations', 'Find opportunities to weave institutional core values into lessons', 'Recognize and praise student behaviors that exemplify institutional values during class to reinforce their importance naturally.', '', 'seed', 1),

-- === MANAGEMENT: SMART Principle ===
('recommendations', 'Apply the SMART framework more consistently in lesson planning', 'Ensure each lesson objective is specific enough that both you and students can clearly determine when it has been achieved.', '', 'seed', 1),
('recommendations', 'Apply the SMART framework more consistently in lesson planning', 'Include time markers in your lesson plan that designate when each objective should be addressed and how long each activity should take.', '', 'seed', 1),
('recommendations', 'Apply the SMART framework more consistently in lesson planning', 'Develop measurable success criteria for each objective so you can assess whether students have met the intended outcomes.', '', 'seed', 1),
('recommendations', 'Apply the SMART framework more consistently in lesson planning', 'Review your lesson plans through the SMART lens before class, checking that all objectives are specific, measurable, attainable, relevant, and time-bound.', '', 'seed', 1),

-- === ASSESSMENT: Understanding Monitoring ===
('recommendations', 'Increase frequency of comprehension checks during instruction', 'Pause every ten to fifteen minutes to ask targeted questions that assess whether students are following the discussion.', '', 'seed', 1),
('recommendations', 'Increase frequency of comprehension checks during instruction', 'Use exit tickets at the end of class where students write one thing they learned and one question they still have.', '', 'seed', 1),
('recommendations', 'Increase frequency of comprehension checks during instruction', 'Implement a traffic light system where students signal green, yellow, or red to indicate their comfort level with the material.', '', 'seed', 1),
('recommendations', 'Increase frequency of comprehension checks during instruction', 'Ask students to teach a concept to a partner as a quick check for understanding before moving to the next topic.', '', 'seed', 1),
('recommendations', 'Increase frequency of comprehension checks during instruction', 'Use digital response systems or apps that allow instant polling to gather class-wide data on comprehension levels.', '', 'seed', 1),

-- === ASSESSMENT: Assessment Alignment ===
('recommendations', 'Align assessment tools more closely with syllabus competencies', 'Create an assessment blueprint or table of specifications that maps each test item to specific course competencies.', '', 'seed', 1),
('recommendations', 'Align assessment tools more closely with syllabus competencies', 'Review existing assessments alongside the syllabus to identify gaps or areas where competencies are not adequately measured.', '', 'seed', 1),
('recommendations', 'Align assessment tools more closely with syllabus competencies', 'Collaborate with colleagues teaching the same course to ensure consistency in how competencies are assessed across sections.', '', 'seed', 1),
('recommendations', 'Align assessment tools more closely with syllabus competencies', 'Include a competency tag or label on each assessment item so students see the direct connection to course objectives.', '', 'seed', 1),

-- === ASSESSMENT: Assessment Design ===
('recommendations', 'Strengthen assessment design with clear criteria and rubrics', 'Develop and share rubrics before assignments are due so students understand expectations and can self-assess before submitting.', '', 'seed', 1),
('recommendations', 'Strengthen assessment design with clear criteria and rubrics', 'Use analytic rubrics that break down performance criteria into separate dimensions, providing more detailed and useful feedback.', '', 'seed', 1),
('recommendations', 'Strengthen assessment design with clear criteria and rubrics', 'Pilot-test assessment items with a small group before full deployment to identify ambiguous or poorly constructed questions.', '', 'seed', 1),
('recommendations', 'Strengthen assessment design with clear criteria and rubrics', 'Incorporate various question types—multiple choice, short answer, essay, and performance tasks—to assess different cognitive levels.', '', 'seed', 1),

-- === ASSESSMENT: Differentiated Learning ===
('recommendations', 'Design activities that address diverse learner needs', 'Offer choice boards or menus that let students select from different activities based on their learning preferences and strengths.', '', 'seed', 1),
('recommendations', 'Design activities that address diverse learner needs', 'Provide scaffolded versions of the same assignment at different difficulty levels to accommodate mixed-ability classrooms.', '', 'seed', 1),
('recommendations', 'Design activities that address diverse learner needs', 'Incorporate multisensory approaches—visual, auditory, kinesthetic—to make learning accessible to students with different learning styles.', '', 'seed', 1),
('recommendations', 'Design activities that address diverse learner needs', 'Use flexible grouping strategies that change based on the activity, allowing students to learn from peers with different strengths.', '', 'seed', 1),
('recommendations', 'Allow time for learners to revise after feedback', 'Build revision cycles into your assessment process so students can apply feedback and demonstrate improved understanding.', '', 'seed', 1),
('recommendations', 'Allow time for learners to revise after feedback', 'Providing opportunities for students to resubmit work after feedback reinforces a growth mindset and prioritizes learning over grades.', '', 'seed', 1),

-- === ASSESSMENT: Normative Assessment ===
('recommendations', 'Implement normative assessments before formal grading', 'Administer a pre-test at the start of each unit to establish a baseline for measuring actual student growth during instruction.', '', 'seed', 1),
('recommendations', 'Implement normative assessments before formal grading', 'Use diagnostic activities like concept mapping or brainstorming sessions to gauge existing student knowledge before teaching new content.', '', 'seed', 1),
('recommendations', 'Implement normative assessments before formal grading', 'Comparing pre and post assessment results provides concrete evidence of learning gains and helps evaluate instructional effectiveness.', '', 'seed', 1),
('recommendations', 'Implement normative assessments before formal grading', 'Normative assessment data can inform differentiated instruction, allowing you to target specific gaps identified before formal teaching begins.', '', 'seed', 1),

-- === ASSESSMENT: Formative Assessment ===
('recommendations', 'Use formative assessment data more effectively to guide instruction', 'After collecting formative data, identify the top three areas of weakness and address them explicitly in the next session.', '', 'seed', 1),
('recommendations', 'Use formative assessment data more effectively to guide instruction', 'Create a simple tracking system for formative assessment results so you can monitor trends in student understanding over time.', '', 'seed', 1),
('recommendations', 'Use formative assessment data more effectively to guide instruction', 'Share formative assessment results with students so they can take ownership of their learning and set personal improvement goals.', '', 'seed', 1),
('recommendations', 'Use formative assessment data more effectively to guide instruction', 'Dedicate a few minutes at the start of subsequent lessons to review and address common errors identified in formative assessments.', '', 'seed', 1),
('recommendations', 'Use formative assessment data more effectively to guide instruction', 'Use formative assessment not just to evaluate students, but as a reflective tool for improving your own instructional practices.', '', 'seed', 1),

-- === GENERAL RECOMMENDATIONS ===
('recommendations', 'Students rarely participate and need more opportunities to engage', 'Consider restructuring lessons to include at least three interactive activities per class period to boost student engagement.', '', 'seed', 1),
('recommendations', 'Students rarely participate and need more opportunities to engage', 'Designing activities where students must produce something—a diagram, a written response, a presentation—keeps them actively involved.', '', 'seed', 1),
('recommendations', 'Assessment checks are not frequent enough to monitor understanding', 'Embed mini-assessments naturally into your lesson flow so they feel like learning activities rather than formal evaluations.', '', 'seed', 1),
('recommendations', 'Assessment checks are not frequent enough to monitor understanding', 'Use quick, low-stakes assessments throughout the lesson to gather continuous data on student comprehension.', '', 'seed', 1),
('recommendations', 'Improve classroom time management', 'Use a visible timer during activities to help both you and students stay aware of time constraints and transitions.', '', 'seed', 1),
('recommendations', 'Improve classroom time management', 'Plan buffer time between major activities so unexpected discussions or questions do not derail the overall lesson schedule.', '', 'seed', 1),
('recommendations', 'Improve classroom time management', 'Prioritize activities based on their alignment with learning outcomes, and have optional extension activities ready for extra time.', '', 'seed', 1),
('recommendations', 'Develop stronger lesson closure strategies', 'End each lesson with a brief summary or student-generated recap that reinforces the key takeaways from the session.', '', 'seed', 1),
('recommendations', 'Develop stronger lesson closure strategies', 'Use closing reflection questions such as "What was the most important thing you learned today?" to solidify learning.', '', 'seed', 1),
('recommendations', 'Develop stronger lesson closure strategies', 'A well-planned lesson closure creates anticipation for the next meeting by previewing upcoming topics or posing a lingering question.', '', 'seed', 1),
('recommendations', 'Enhance feedback practices for student growth', 'Move beyond grades to provide narrative feedback that identifies specific strengths and actionable areas for improvement.', '', 'seed', 1),
('recommendations', 'Enhance feedback practices for student growth', 'Use a "two stars and a wish" format—two positive observations and one constructive suggestion—to structure written feedback.', '', 'seed', 1),
('recommendations', 'Enhance feedback practices for student growth', 'Schedule brief one-on-one conferences with students who are struggling to provide personalized guidance and support.', '', 'seed', 1),
('recommendations', 'Strengthen use of educational technology', 'Explore learning management systems or classroom apps that facilitate assignment submission, feedback, and student tracking.', '', 'seed', 1),
('recommendations', 'Strengthen use of educational technology', 'Use interactive presentation tools that allow real-time student responses, making technology a bridge to engagement rather than a distraction.', '', 'seed', 1),
('recommendations', 'Promote student self-assessment and reflection', 'Introduce self-assessment checklists that students complete after major activities to build metacognitive awareness.', '', 'seed', 1),
('recommendations', 'Promote student self-assessment and reflection', 'Encourage students to maintain learning journals where they track their own progress, questions, and insights throughout the course.', '', 'seed', 1),
('recommendations', 'Foster a growth mindset in the classroom', 'Praise effort and process rather than innate ability, reinforcing the message that improvement comes from persistence and practice.', '', 'seed', 1),
('recommendations', 'Foster a growth mindset in the classroom', 'Normalize mistakes as part of the learning process by sharing examples of how errors lead to deeper understanding.', '', 'seed', 1),
('recommendations', 'Collaborate with peers for professional growth', 'Engage in peer observation and feedback sessions with colleagues to gain new perspectives on instructional practices.', '', 'seed', 1),
('recommendations', 'Collaborate with peers for professional growth', 'Participate in professional learning communities within the department to share best practices and stay current with pedagogical trends.', '', 'seed', 1);
