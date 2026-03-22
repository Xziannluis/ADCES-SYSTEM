<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Evaluation.php';
require_once __DIR__ . '/../models/Teacher.php';
require_once __DIR__ . '/../controllers/AIController.php';
require_once __DIR__ . '/../controllers/EvaluationController.php';

$db = (new Database())->getConnection();
if (!$db) {
    fwrite(STDERR, "Database connection failed\n");
    exit(1);
}

$sql = "
SELECT
    u.id AS evaluator_id,
    u.role,
    u.department AS evaluator_department,
    t.id AS teacher_id,
    t.name AS teacher_name,
    t.department AS teacher_department,
    t.evaluation_schedule,
    t.evaluation_room
FROM users u
JOIN teacher_assignments ta ON ta.evaluator_id = u.id
JOIN teachers t ON t.id = ta.teacher_id
WHERE t.status = 'active'
  AND (t.evaluation_schedule IS NOT NULL OR t.evaluation_room IS NOT NULL)
  AND u.role IN ('subject_coordinator', 'chairperson', 'grade_level_coordinator', 'evaluator', 'leader')
ORDER BY u.id, t.id
LIMIT 1
";

$row = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    fwrite(STDERR, "No assigned scheduled teacher found for a real final submission.\n");
    exit(2);
}

$controller = new EvaluationController($db);
$today = date('Y-m-d');
$payload = [
    'teacher_id' => $row['teacher_id'],
    'faculty_name' => $row['teacher_name'],
    'academic_year' => '2025-2026',
    'semester' => '2nd',
    'department' => $row['teacher_department'],
    'subject_observed' => 'Database Management Systems',
    'observation_time' => '10:00 AM - 11:00 AM',
    'observation_date' => $today,
    'observation_type' => 'Formal',
    'seat_plan' => 1,
    'course_syllabi' => 1,
    'others_requirements' => 1,
    'others_specify' => 'Instructional materials and prepared activity sheets',
    'strengths' => 'The teacher explained concepts clearly, maintained a focused classroom environment, and used examples that helped learners connect theory to classroom tasks.',
    'improvement_areas' => 'Learner participation can be deepened further through more structured questioning and consistent formative checks during discussion.',
    'recommendations' => 'Add brief think-pair-share moments, use targeted comprehension checks, and close the lesson with a concise synthesis of key points.',
    'agreement' => 'Agreed to implement more structured questioning and quick checks for understanding in succeeding observations.',
    'rater_printed_name' => 'System Validation Probe',
    'rater_signature' => 'System Validation Probe',
    'rater_date' => $today,
    'faculty_printed_name' => $row['teacher_name'],
    'faculty_signature' => $row['teacher_name'],
    'faculty_date' => $today,
];

$commentSeeds = [
    'communications' => [
        'Clarified lesson objectives before the discussion and connected new terms to prior learning.',
        'Used concise explanations and examples that kept the flow of the lesson understandable.',
        'Asked follow-up questions that supported learner thinking and response quality.',
        'Maintained a respectful speaking pace and reinforced key concepts at transition points.',
        'Provided directions clearly enough for students to move from discussion to activity work.'
    ],
    'management' => [
        'Opened the class with routines that helped learners settle and prepare for instruction.',
        'Maintained attention and minimized off-task behavior throughout the observed session.',
        'Used time efficiently so lesson parts were completed within the observation window.',
        'Supported participation by circulating and checking learner progress during activities.',
        'Created a classroom climate where students were comfortable responding to prompts.',
        'Linked activities to lesson targets so students understood why each step mattered.',
        'Provided transitions that kept the class organized between explanation and task work.',
        'Monitored learner engagement and redirected attention without disrupting the lesson.',
        'Used available resources appropriately to support delivery of the topic.',
        'Maintained a professional and encouraging tone while handling questions.',
        'Reinforced expectations for participation and respectful interaction.',
        'Balanced teacher guidance with opportunities for learner contribution.'
    ],
    'assessment' => [
        'Checked understanding through direct questions during the lesson.',
        'Used feedback statements that helped learners refine their responses.',
        'Observed student work and clarified misconceptions as they appeared.',
        'Aligned short tasks with the lesson objective and expected outputs.',
        'Encouraged learners to explain answers, not only provide final responses.',
        'Closed with review prompts that surfaced the most important takeaways.'
    ],
];

for ($i = 0; $i < 5; $i++) {
    $payload["communications{$i}"] = 4 + ($i % 2);
    $payload["communications_comment{$i}"] = $commentSeeds['communications'][$i];
}
for ($i = 0; $i < 12; $i++) {
    $payload["management{$i}"] = ($i % 3 === 0) ? 5 : 4;
    $payload["management_comment{$i}"] = $commentSeeds['management'][$i];
}
for ($i = 0; $i < 6; $i++) {
    $payload["assessment{$i}"] = ($i % 2 === 0) ? 4 : 5;
    $payload["assessment_comment{$i}"] = $commentSeeds['assessment'][$i];
}

$result = $controller->submitEvaluation($payload, (int)$row['evaluator_id']);
$output = [
    'candidate' => $row,
    'result' => $result,
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

exit(($result['success'] ?? false) ? 0 : 3);
