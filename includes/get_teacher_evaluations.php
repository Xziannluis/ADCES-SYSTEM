<?php
require_once '../auth/session-check.php';

if (!in_array($_SESSION['role'] ?? '', ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

require_once '../config/database.php';


header('Content-Type: application/json');

$teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
if ($teacher_id <= 0) {
    echo json_encode([]);
    exit();
}

$db = (new Database())->getConnection();

$role = $_SESSION['role'];
$userId = $_SESSION['user_id'] ?? 0;
$department = $_SESSION['department'] ?? '';

// Build query based on role
$query = "SELECT e.id, e.evaluator_id, e.observation_date, e.observation_time, e.academic_year, e.semester, e.subject_observed,
                 e.overall_avg, e.evaluation_form_type, e.status, u.name AS evaluator_name
          FROM evaluations e
          JOIN teachers t ON t.id = e.teacher_id
          JOIN users u ON u.id = e.evaluator_id
          WHERE e.teacher_id = :teacher_id
            AND e.status = 'completed'
            AND e.overall_avg IS NOT NULL
            AND e.overall_avg > 0";
$params = [':teacher_id' => $teacher_id];

// Optional form type filter
$form_type_filter = isset($_GET['form_type']) ? trim($_GET['form_type']) : '';
if (in_array($form_type_filter, ['iso', 'peac'], true)) {
    $query .= " AND e.evaluation_form_type = :form_type";
    $params[':form_type'] = $form_type_filter;
}

// Coordinators can only see their own evaluations
if (in_array($role, ['chairperson', 'subject_coordinator', 'grade_level_coordinator'])) {
    $query .= " AND e.evaluator_id = :evaluator_id";
    $params[':evaluator_id'] = $userId;
}
// Presidents/VPs see only their own evaluations
elseif (in_array($role, ['president', 'vice_president'])) {
    $query .= " AND e.evaluator_id = :evaluator_id";
    $params[':evaluator_id'] = $userId;
}
// Dean account can print completed forms for its department, including
// evaluations submitted by observers/evaluators requested for a scoped schedule.
elseif ($role === 'dean') {
    $query .= " AND (
        e.department = :department_eval
        OR ((e.department IS NULL OR e.department = '') AND t.department = :department_teacher)
        OR e.evaluator_id = :evaluator_id
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
    $params[':evaluator_id'] = $userId;
    $params[':request_src_evaluator_id_assign'] = $userId;
    $params[':request_department_eval_assign'] = $department;
    $params[':request_teacher_department_assign'] = $department;
    $params[':request_src_evaluator_id_notif'] = $userId;
    $params[':request_department_eval_notif'] = $department;
    $params[':request_teacher_department_notif'] = $department;
}
// Principal and other non-dean heads keep the narrower existing scope.
elseif ($role === 'principal') {
    $query .= " AND e.evaluator_id = :evaluator_id";
    $params[':evaluator_id'] = $userId;
}

$query .= " ORDER BY e.observation_date DESC, e.id DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Deduplicate rows for the same schedule slot + form type + evaluator.
// Keep only the newest record (highest id).
$deduped = [];
foreach ($results as $row) {
    $k = implode('|', [
        (string)($row['observation_date'] ?? ''),
        (string)($row['observation_time'] ?? ''),
        mb_strtolower(trim((string)($row['subject_observed'] ?? ''))),
        (string)($row['academic_year'] ?? ''),
        (string)($row['semester'] ?? ''),
        (string)($row['evaluation_form_type'] ?? 'iso'),
        (string)($row['evaluator_name'] ?? ''),
    ]);
    $id = (int)($row['id'] ?? 0);
    if (!isset($deduped[$k]) || $id > (int)$deduped[$k]['id']) {
        $deduped[$k] = $row;
    }
}
$results = array_values($deduped);

// Format for display
$output = [];
foreach ($results as $row) {
    $rawDate = trim((string)($row['observation_date'] ?? ''));
    $dateTs = $rawDate !== '' ? strtotime($rawDate) : false;
    $date = $dateTs ? date('M j, Y', $dateTs) : 'N/A';
    $avg = $row['overall_avg'] !== null ? number_format((float)$row['overall_avg'], 1) : 'N/A';
    $output[] = [
        'id'          => (int)$row['id'],
        'evaluator_id' => (int)($row['evaluator_id'] ?? 0),
        'date'        => $date,
        'date_raw'    => $rawDate,
        'month'       => $dateTs ? date('n', $dateTs) : '',
        'month_name'  => $dateTs ? date('F', $dateTs) : '',
        'academic_year' => $row['academic_year'] ?? '',
        'semester'    => $row['semester'] ?? '',
        'subject'     => $row['subject_observed'] ?? '',
        'evaluator'   => $row['evaluator_name'] ?? '',
        'overall_avg' => $avg,
        'form_type'   => $row['evaluation_form_type'] ?? 'iso',
    ];
}

echo json_encode($output);
