<?php
require_once '../auth/session-check.php';

if (!in_array($_SESSION['role'] ?? '', ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

require_once '../config/database.php';

header('Content-Type: application/json');

$form_type = isset($_GET['form_type']) ? trim($_GET['form_type']) : '';
if (!in_array($form_type, ['iso', 'peac'], true)) {
    echo json_encode([]);
    exit();
}

$db = (new Database())->getConnection();

$role = $_SESSION['role'];
$userId = $_SESSION['user_id'] ?? 0;
$department = $_SESSION['department'] ?? '';

$query = "SELECT DISTINCT t.id, t.name
          FROM evaluations e
          INNER JOIN teachers t ON e.teacher_id = t.id
          WHERE e.evaluation_form_type = :form_type
            AND e.status = 'completed'
            AND e.overall_avg IS NOT NULL
            AND e.overall_avg > 0";
$params = [':form_type' => $form_type];

// Coordinators can only see their own evaluations
if (in_array($role, ['chairperson', 'subject_coordinator', 'grade_level_coordinator'])) {
    $query .= " AND e.evaluator_id = :evaluator_id";
    $params[':evaluator_id'] = $userId;
}
// Presidents/VPs see all evaluations
elseif (in_array($role, ['president', 'vice_president'])) {
    // No extra filter — top-level leaders can view all evaluations
}
// Dean account can print completed forms for its department, including
// evaluations submitted by observers/evaluators requested for a scoped schedule.
elseif ($role === 'dean') {
    $query .= " AND (
        e.department = :department_eval
        OR ((e.department IS NULL OR e.department = '') AND t.department = :department_teacher)
        OR e.evaluator_id = :current_user_id
        OR EXISTS (
            SELECT 1
            FROM teacher_assignments ta
            JOIN evaluations src ON src.id = ta.eval_id
            WHERE ta.evaluator_id = e.evaluator_id
              AND src.teacher_id = e.teacher_id
              AND COALESCE(src.academic_year, '') = COALESCE(e.academic_year, '')
              AND COALESCE(src.semester, '') = COALESCE(e.semester, '')
              AND DATE(src.observation_date) = DATE(e.observation_date)
              AND COALESCE(NULLIF(LEFT(TRIM(src.observation_time), 5), ''), '00:00') = COALESCE(NULLIF(LEFT(TRIM(e.observation_time), 5), ''), '00:00')
              AND (
                  LOWER(COALESCE(NULLIF(src.evaluation_form_type, ''), 'iso')) = LOWER(COALESCE(NULLIF(e.evaluation_form_type, ''), 'iso'))
                  OR LOWER(COALESCE(src.evaluation_form_type, '')) = 'both'
              )
              AND (
                  src.evaluator_id = :request_src_evaluator_id_assign
                  OR src.department = :request_department_eval_assign
                  OR t.department = :request_teacher_department_assign
              )
        )
        OR EXISTS (
            SELECT 1
            FROM notifications n
            JOIN evaluations src ON src.id = n.request_eval_id
            WHERE n.type = 'observer_request'
              AND n.user_id = e.evaluator_id
              AND src.teacher_id = e.teacher_id
              AND COALESCE(src.academic_year, '') = COALESCE(e.academic_year, '')
              AND COALESCE(src.semester, '') = COALESCE(e.semester, '')
              AND DATE(src.observation_date) = DATE(e.observation_date)
              AND COALESCE(NULLIF(LEFT(TRIM(src.observation_time), 5), ''), '00:00') = COALESCE(NULLIF(LEFT(TRIM(e.observation_time), 5), ''), '00:00')
              AND (
                  LOWER(COALESCE(NULLIF(src.evaluation_form_type, ''), 'iso')) = LOWER(COALESCE(NULLIF(e.evaluation_form_type, ''), 'iso'))
                  OR LOWER(COALESCE(src.evaluation_form_type, '')) = 'both'
              )
              AND (
                  src.evaluator_id = :request_src_evaluator_id_notif
                  OR src.department = :request_department_eval_notif
                  OR t.department = :request_teacher_department_notif
              )
        )
    )";
    $params[':department_eval'] = $department;
    $params[':department_teacher'] = $department;
    $params[':current_user_id'] = $userId;
    $params[':request_src_evaluator_id_assign'] = $userId;
    $params[':request_department_eval_assign'] = $department;
    $params[':request_teacher_department_assign'] = $department;
    $params[':request_src_evaluator_id_notif'] = $userId;
    $params[':request_department_eval_notif'] = $department;
    $params[':request_teacher_department_notif'] = $department;
}
// Principal and other non-dean heads keep the narrower existing scope.
elseif ($role === 'principal') {
    $query .= " AND e.evaluator_id = :current_user_id";
    $params[':current_user_id'] = $userId;
}

$query .= " ORDER BY t.name ASC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

$output = [];
foreach ($results as $row) {
    $output[] = [
        'id'   => (int)$row['id'],
        'name' => $row['name'],
    ];
}

echo json_encode($output);
