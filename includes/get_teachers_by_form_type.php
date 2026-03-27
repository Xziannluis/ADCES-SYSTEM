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
          WHERE e.evaluation_form_type = :form_type";
$params = [':form_type' => $form_type];

// Coordinators can only see their own evaluations
if (in_array($role, ['chairperson', 'subject_coordinator', 'grade_level_coordinator'])) {
    $query .= " AND e.evaluator_id = :evaluator_id";
    $params[':evaluator_id'] = $userId;
}
// Presidents/VPs see only president/VP evaluations
elseif (in_array($role, ['president', 'vice_president'])) {
    $query .= " AND EXISTS (SELECT 1 FROM users u WHERE u.id = e.evaluator_id AND u.role IN ('president', 'vice_president'))";
}
// Dean/principal see department evaluations
elseif (in_array($role, ['dean', 'principal'])) {
    $query .= " AND (t.department = :department OR e.evaluator_id = :current_user_id)";
    $params[':department'] = $department;
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
