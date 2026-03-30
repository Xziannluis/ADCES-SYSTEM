<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

echo "=== TEACHERS TABLE ===\n";
$stmt = $db->query('DESCRIBE teachers');
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $r['Field'] . ' (' . $r['Type'] . ")\n";
}

echo "\n=== EVALUATIONS TABLE ===\n";
$stmt = $db->query('DESCRIBE evaluations');
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $r['Field'] . ' (' . $r['Type'] . ")\n";
}

echo "\n=== EVALUATION_DETAILS TABLE ===\n";
$stmt = $db->query('DESCRIBE evaluation_details');
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $r['Field'] . ' (' . $r['Type'] . ")\n";
}

echo "\n=== TEACHER_ASSIGNMENTS TABLE ===\n";
$stmt = $db->query('DESCRIBE teacher_assignments');
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $r['Field'] . ' (' . $r['Type'] . ")\n";
}

echo "\n=== OBSERVATION_PLAN_ACKNOWLEDGMENTS TABLE ===\n";
try {
    $stmt = $db->query('DESCRIBE observation_plan_acknowledgments');
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $r['Field'] . ' (' . $r['Type'] . ")\n";
    }
} catch (Exception $e) {
    echo "TABLE DOES NOT EXIST\n";
}

echo "\n=== NOTIFICATIONS TABLE ===\n";
try {
    $stmt = $db->query('DESCRIBE notifications');
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $r['Field'] . ' (' . $r['Type'] . ")\n";
    }
} catch (Exception $e) {
    echo "TABLE DOES NOT EXIST\n";
}

echo "\n=== AUDIT_LOGS TABLE ===\n";
try {
    $stmt = $db->query('DESCRIBE audit_logs');
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $r['Field'] . ' (' . $r['Type'] . ")\n";
    }
} catch (Exception $e) {
    echo "TABLE DOES NOT EXIST\n";
}

echo "\n=== FORM_SETTINGS TABLE ===\n";
try {
    $stmt = $db->query('DESCRIBE form_settings');
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $r['Field'] . ' (' . $r['Type'] . ")\n";
    }
} catch (Exception $e) {
    echo "TABLE DOES NOT EXIST\n";
}

echo "\n=== USERS TABLE (email columns) ===\n";
try {
    $stmt = $db->query('DESCRIBE users');
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $r['Field'] . ' (' . $r['Type'] . ")\n";
    }
} catch (Exception $e) {
    echo "TABLE DOES NOT EXIST\n";
}
