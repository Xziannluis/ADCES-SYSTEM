<?php
// Debug script: fetch teacher by name and pending schedule rows
require_once __DIR__ . '/../config/database.php';

$nameArg = $argv[1] ?? 'Marlon';
$dbClass = new Database();
$conn = $dbClass->getConnection();
if (!$conn) {
    echo json_encode(['error' => 'DB connection failed', 'details' => $dbClass->getLastError()]);
    exit(1);
}

try {
    $stmt = $conn->prepare("SELECT * FROM teachers WHERE name LIKE :name LIMIT 10");
    $like = '%' . $nameArg . '%';
    $stmt->bindParam(':name', $like);
    $stmt->execute();
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = ['teachers' => $teachers, 'pending_evaluations' => []];
    foreach ($teachers as $t) {
        $tid = (int)$t['id'];
        $q = "SELECT * FROM evaluations WHERE teacher_id = :tid AND observation_date IS NOT NULL AND (status IN ('draft','pending') OR status IS NULL OR status = '') ORDER BY observation_date ASC, COALESCE(observation_time,'00:00:00') ASC";
        $s2 = $conn->prepare($q);
        $s2->bindParam(':tid', $tid, PDO::PARAM_INT);
        $s2->execute();
        $rows = $s2->fetchAll(PDO::FETCH_ASSOC);
        $out['pending_evaluations'][$tid] = $rows;
    }

    echo json_encode($out, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
