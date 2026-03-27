<?php
// Test endpoint - no session needed. Access via browser: http://localhost/ADCES-SYSTEM/database/tools/test_endpoint.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/database.php';

$db = (new Database())->getConnection();

$form_type = 'iso';
$userId = 15;
$department = 'CCIS';

$query = "SELECT DISTINCT t.id, t.name
          FROM evaluations e
          INNER JOIN teachers t ON e.teacher_id = t.id
          WHERE e.evaluation_form_type = :form_type
          AND (t.department = :department OR e.evaluator_id = :current_user_id)
          ORDER BY t.name ASC";
$params = [':form_type' => $form_type, ':department' => $department, ':current_user_id' => $userId];
$stmt = $db->prepare($query);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

$output = [];
foreach ($results as $row) {
    $output[] = ['id' => (int)$row['id'], 'name' => $row['name']];
}
echo json_encode($output);
