<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();
if(!$db) {
    die("<h2 style='color:red'>Cannot connect to database. Make sure you ran install.php or imported database_complete.sql.</h2>");
}

try {
    $stmt = $db->query("SELECT id, username, password, name, role, department, status, created_at FROM users ORDER BY id");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("<h2 style='color:red'>Query error: " . htmlspecialchars($e->getMessage()) . "</h2>");
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Users Diagnostic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
    <div class="container">
        <h1>Users Table Diagnostic</h1>
        <p class="text-muted">This page lists users so you can verify the database import and password hashes.</p>
        <?php if(empty($users)): ?>
            <div class="alert alert-warning">No users found. Database may not be imported.</div>
        <?php else: ?>
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Password Hash (truncated)</th>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Dept</th>
                        <th>Status</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($users as $u): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($u['id']); ?></td>
                        <td><?php echo htmlspecialchars($u['username']); ?></td>
                        <td style="font-family:monospace;"><?php echo htmlspecialchars(substr($u['password'],0,40)); ?>...</td>
                        <td><?php echo htmlspecialchars($u['name']); ?></td>
                        <td><?php echo htmlspecialchars($u['role']); ?></td>
                        <td><?php echo htmlspecialchars($u['department']); ?></td>
                        <td><?php echo htmlspecialchars($u['status']); ?></td>
                        <td><?php echo htmlspecialchars($u['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="mt-3">
            <h5>Next steps</h5>
            <ul>
                <li>If you see placeholder hashes like <code>$2y$10$YourHashedPasswordHere</code> then run <code>install.php</code> in your browser to import and set real bcrypt passwords.</li>
                <li>If you see no users, import <code>database_complete.sql</code> via phpMyAdmin or run <code>install.php</code>.</li>
                <li>After verifying, try logging in at <code>login.php</code> and ensure you select the matching role in the dropdown.</li>
            </ul>
        </div>
    </div>
</body>
</html>
