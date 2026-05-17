<?php
require_once __DIR__ . '/../../config/database.php';

$db = (new Database())->getConnection();
if (!$db) {
    throw new RuntimeException('Database connection failed.');
}

$criteria = [
    'communications' => [
        "Uses an audible voice that can be heard at the back of the room.",
        "Speaks fluently in the language of instruction.",
        "Facilitates a dynamic discussion.",
        "Uses engaging non-verbal cues (facial expression, gestures).",
        "Uses words & expressions suited to the level of the students.",
    ],
    'management' => [
        "The TILO (Topic Intended Learning Outcomes) are clearly presented.",
        "Recall and connects previous lessons to the new lessons.",
        "The topic/lesson is introduced in an interesting & engaging way.",
        "Uses current issues, real life & local examples to enrich class discussion.",
        "Focuses class discussion on key concepts of the lesson.",
        "Encourages active participation among students and ask questions about the topic.",
        "Uses current instructional strategies and resources.",
        "Designs teaching aids that facilitate understanding of key concepts.",
        "Adapts teaching approach in the light of student feedback and reactions.",
        "Aids students using thought provoking questions (Art of Questioning).",
        "Integrate the institutional core values to the lessons.",
        "Conduct the lesson using the principle of SMART",
    ],
    'assessment' => [
        "Monitors students' understanding on key concepts discussed.",
        "Uses assessment tool that relates specific course competencies stated in the syllabus.",
        "Design test/quarter/assignments and other assessment tasks that are corrector-based.",
        "Introduces varied activities that will answer the differentiated needs to the learners with varied learning style.",
        "Conducts normative assessment before evaluating and grading the learner's performance outcome.",
        "Monitors the formative assessment results and find ways to ensure learning for the learners.",
    ],
    'teacher_actions' => [
        "The teacher communicates clear expectations of student performance in line with the unit standards and competencies.",
        "The teacher utilizes various learning materials, resources and strategies to enable all students to learn and achieve the unit standards and competencies and learning goals.",
        "The teacher monitors and checks on students' learning and attainment of the unit standards and competencies by conducting varied forms of assessments during class discussion.",
        "The teacher provides appropriate feedback or interventions to enable students in attaining the unit standards and competencies.",
        "The teacher manages the classroom environment and time in a way that supports student learning and the achievement of the unit standards and competencies.",
        "The teacher processes students' understanding by asking clarifying or critical thinking questions related to the unit standards and competencies.",
    ],
    'student_learning_actions' => [
        "The students are active and engaged with the different learning tasks aimed at accomplishing the unit standards and competencies.",
        "The students with the help of different learning materials and resources including technology achieve the learning goals of the unit standards and competencies.",
        "The students with the help of different learning materials and resources including technology achieve the learning goals of the unit standards and competencies.",
        "The students with the help of different learning materials and resources including technology achieve the learning goals of the unit standards and competencies.",
        "The students are able to explain how their ideas, outputs or performances accomplish the unit standards and competencies.",
        "The students, when encouraged or on their own, ask questions to clarify or deepen their understanding of the unit standards and competencies.",
        "The students are able to relate or transfer their learning to daily life and real world situations.",
        "The students are able to integrate 21st century skills in their achievement of the unit standards and competencies.",
        "The students are able to reflect on and connect their learning with the school's PVMGO.",
    ],
];

$db->beginTransaction();
try {
    $sel = $db->prepare("SELECT id FROM evaluation_criteria WHERE category = :category AND criterion_index = :idx LIMIT 1");
    $ins = $db->prepare("INSERT INTO evaluation_criteria (category, criterion_index, criterion_text, description) VALUES (:category, :idx, :text, :desc)");
    $upd = $db->prepare("UPDATE evaluation_criteria SET criterion_text = :text, description = :desc WHERE id = :id");

    $inserted = 0;
    $updated = 0;
    foreach ($criteria as $category => $items) {
        foreach ($items as $idx => $text) {
            $desc = $category . ' criterion ' . ($idx + 1);
            $sel->execute([':category' => $category, ':idx' => $idx]);
            $id = (int)$sel->fetchColumn();
            if ($id > 0) {
                $upd->execute([':text' => $text, ':desc' => $desc, ':id' => $id]);
                $updated++;
            } else {
                $ins->execute([':category' => $category, ':idx' => $idx, ':text' => $text, ':desc' => $desc]);
                $inserted++;
            }
        }
    }
    $db->commit();
    echo "Seed complete. Inserted={$inserted}, Updated={$updated}\n";
} catch (Exception $e) {
    $db->rollBack();
    throw $e;
}

