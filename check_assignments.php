<?php
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    echo "Database connection failed\n";
    exit;
}

echo "=== RELEVANT USERS ===\n";
$query = "SELECT id, name, role, department, status FROM users WHERE name LIKE '%DAISA%' OR name LIKE '%ROLLY%' OR name LIKE '%ARVIN%' OR name LIKE '%JAMES%' OR name LIKE '%RONNEL%' ORDER BY name";
$stmt = $conn->prepare($query);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach($users as $u) {
    printf("%3d | %-20s | %-20s | %-10s | %s\n", $u['id'], $u['name'], $u['role'], $u['department'], $u['status']);
}

echo "\n=== COORDINATOR ASSIGNMENTS ===\n";
$query = "SELECT ea.id, u.id as coord_id, u.name as coord_name, u.role as coord_role, u.department as coord_dept, 
                 s.id as super_id, s.name as super_name, s.role as super_role, s.department as super_dept
          FROM evaluator_assignments ea 
          JOIN users u ON ea.evaluator_id = u.id 
          JOIN users s ON ea.supervisor_id = s.id 
          ORDER BY s.id, ea.id";
$stmt = $conn->prepare($query);
$stmt->execute();
$assigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach($assigns as $a) {
    printf("ID:%d | %s(%s) -> %s(%s,%s)\n", 
        $a['id'],
        $a['coord_name'], $a['coord_role'],
        $a['super_name'], $a['super_role'], $a['super_dept']
    );
}

echo "\n=== OBSERVERS (observers table if exists) ===\n";
$query = "SHOW TABLES LIKE 'observer%'";
$stmt = $conn->prepare($query);
$stmt->execute();
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (count($tables) > 0) {
    foreach($tables as $table) {
        echo "Found table: $table\n";
    }
} else {
    echo "No observer table found\n";
}
?>
