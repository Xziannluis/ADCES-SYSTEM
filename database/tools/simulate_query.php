<?php
require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

// Simulate the exact query for dean user (id=15, dept=CCIS) with form_type='iso'
$form_type = 'iso';
$department = 'CCIS';
$userId = 15;

$query = "SELECT DISTINCT t.id, t.name
          FROM evaluations e
          INNER JOIN teachers t ON e.teacher_id = t.id
          WHERE e.evaluation_form_type = :form_type
          AND (t.department = :department OR e.evaluator_id = :current_user_id)
          ORDER BY t.name ASC";
$params = [
    ':form_type' => $form_type,
    ':department' => $department,
    ':current_user_id' => $userId,
];

$stmt = $db->prepare($query);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Query results for dean (user_id=15, dept=CCIS, form_type=iso):\n";
echo "Count: " . count($results) . "\n";
foreach ($results as $row) {
    echo "  - id={$row['id']}, name={$row['name']}\n";
}

echo "\nJSON output:\n";
$output = [];
foreach ($results as $row) {
    $output[] = ['id' => (int)$row['id'], 'name' => $row['name']];
}
echo json_encode($output) . "\n";

// Also check if the evaluation_form_type column has any NULL values
echo "\n=== Evaluations with NULL evaluation_form_type ===\n";
$stmt = $db->query("SELECT id, teacher_id, evaluator_id, status, evaluation_form_type FROM evaluations WHERE evaluation_form_type IS NULL");
$nullRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Count: " . count($nullRows) . "\n";
foreach ($nullRows as $row) {
    print_r($row);
}

// Check evaluation status values
echo "\n=== Evaluation status values ===\n";
$stmt = $db->query("SELECT DISTINCT status FROM evaluations");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  - '{$row['status']}'\n";
}
