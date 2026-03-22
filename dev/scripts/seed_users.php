<?php
/**
 * Seed default users safely (no destructive changes).
 * - Inserts missing users.
 * - Fixes placeholder/empty passwords.
 * - Ensures teacher records exist for teacher users.
 */

require_once __DIR__ . '/../../config/database.php';

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    die("<h2 style='color:red;'>Database connection failed.</h2>");
}

$defaults = [
    [
        'username' => 'edp_user',
        'password' => 'edp123',
        'name' => 'EDP Admin',
        'role' => 'edp',
        'department' => 'Administration'
    ],
    [
        'username' => 'dean_ccs',
        'password' => 'dean123',
        'name' => 'Dr. Dean CCS',
        'role' => 'dean',
        'department' => 'CCIS'
    ],
    [
        'username' => 'principal',
        'password' => 'principal123',
        'name' => 'Principal Santos',
        'role' => 'principal',
        'department' => null
    ],
    [
        'username' => 'chairperson_ccs',
        'password' => 'chair123',
        'name' => 'Prof. Chairperson CCS',
        'role' => 'chairperson',
        'department' => 'CCIS'
    ],
    [
        'username' => 'coordinator_ccs',
        'password' => 'coord123',
        'name' => 'Prof. Coordinator',
        'role' => 'subject_coordinator',
        'department' => 'CCIS'
    ],
    [
        'username' => 'president',
        'password' => 'president123',
        'name' => 'President Cruz',
        'role' => 'president',
        'department' => null
    ],
    [
        'username' => 'vp_academics',
        'password' => 'vp123',
        'name' => 'VP Academics',
        'role' => 'vice_president',
        'department' => null
    ],
    [
        'username' => 'teacher_john',
        'password' => 'teacher123',
        'name' => 'John Smith',
        'role' => 'teacher',
        'department' => 'CCIS'
    ],
    [
        'username' => 'teacher_mary',
        'password' => 'teacher123',
        'name' => 'Mary Johnson',
        'role' => 'teacher',
        'department' => 'CCIS'
    ],
    [
        'username' => 'teacher_robert',
        'password' => 'teacher123',
        'name' => 'Robert Brown',
        'role' => 'teacher',
        'department' => 'Engineering'
    ]
];

$summary = [
    'inserted' => 0,
    'updated' => 0,
    'teachers_created' => 0,
    'errors' => []
];

try {
    foreach ($defaults as $user) {
        $select = $db->prepare("SELECT id, password, role, name, department FROM users WHERE username = :username LIMIT 1");
        $select->bindParam(':username', $user['username']);
        $select->execute();
        $existing = $select->fetch(PDO::FETCH_ASSOC);

        $hash = password_hash($user['password'], PASSWORD_DEFAULT);

        if (!$existing) {
            $insert = $db->prepare("INSERT INTO users (username, password, name, role, department, status, created_at) VALUES (:username, :password, :name, :role, :department, 'active', NOW())");
            $insert->bindParam(':username', $user['username']);
            $insert->bindParam(':password', $hash);
            $insert->bindParam(':name', $user['name']);
            $insert->bindParam(':role', $user['role']);
            $insert->bindParam(':department', $user['department']);
            $insert->execute();
            $summary['inserted']++;

            $userId = (int)$db->lastInsertId();
        } else {
            $userId = (int)$existing['id'];
            $needsPassword = empty($existing['password']) || strpos($existing['password'], 'YourHashedPasswordHere') !== false;
            $needsRole = empty($existing['role']);
            $needsName = empty($existing['name']);
            $needsDept = empty($existing['department']) && !empty($user['department']);

            if ($needsPassword || $needsRole || $needsName || $needsDept) {
                $update = $db->prepare("UPDATE users SET password = IF(:update_password = 1, :password, password), role = IF(:update_role = 1, :role, role), name = IF(:update_name = 1, :name, name), department = IF(:update_department = 1, :department, department), status = 'active' WHERE id = :id");
                $updatePassword = $needsPassword ? 1 : 0;
                $updateRole = $needsRole ? 1 : 0;
                $updateName = $needsName ? 1 : 0;
                $updateDepartment = $needsDept ? 1 : 0;

                $update->bindParam(':update_password', $updatePassword, PDO::PARAM_INT);
                $update->bindParam(':update_role', $updateRole, PDO::PARAM_INT);
                $update->bindParam(':update_name', $updateName, PDO::PARAM_INT);
                $update->bindParam(':update_department', $updateDepartment, PDO::PARAM_INT);
                $update->bindParam(':password', $hash);
                $update->bindParam(':role', $user['role']);
                $update->bindParam(':name', $user['name']);
                $update->bindParam(':department', $user['department']);
                $update->bindParam(':id', $userId);
                $update->execute();
                $summary['updated']++;
            }
        }

        if ($user['role'] === 'teacher') {
            $teacherCheck = $db->prepare("SELECT id FROM teachers WHERE user_id = :user_id LIMIT 1");
            $teacherCheck->bindParam(':user_id', $userId);
            $teacherCheck->execute();
            if ($teacherCheck->rowCount() === 0) {
                $teacherInsert = $db->prepare("INSERT INTO teachers (user_id, name, department, status, created_at) VALUES (:user_id, :name, :department, 'active', NOW())");
                $teacherInsert->bindParam(':user_id', $userId);
                $teacherInsert->bindParam(':name', $user['name']);
                $teacherInsert->bindParam(':department', $user['department']);
                $teacherInsert->execute();
                $summary['teachers_created']++;
            }
        }
    }
} catch (PDOException $e) {
    $summary['errors'][] = $e->getMessage();
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Seed Users</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container">
    <h1 class="mb-3">Seed Users Result</h1>
    <ul class="list-group mb-3">
        <li class="list-group-item">Inserted users: <strong><?php echo $summary['inserted']; ?></strong></li>
        <li class="list-group-item">Updated users: <strong><?php echo $summary['updated']; ?></strong></li>
        <li class="list-group-item">Teacher records created: <strong><?php echo $summary['teachers_created']; ?></strong></li>
    </ul>

    <?php if (!empty($summary['errors'])): ?>
        <div class="alert alert-danger">
            <strong>Errors:</strong>
            <ul>
                <?php foreach ($summary['errors'] as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php else: ?>
        <div class="alert alert-success">Seed complete. You can now log in using the default accounts.</div>
    <?php endif; ?>

    <h4>Default Logins</h4>
    <ul>
        <li>EDP: <strong>edp_user</strong> / <strong>edp123</strong></li>
        <li>Teacher: <strong>teacher_john</strong> / <strong>teacher123</strong></li>
    </ul>

    <a class="btn btn-primary" href="../../login.php">Go to Login</a>
</div>
</body>
</html>
