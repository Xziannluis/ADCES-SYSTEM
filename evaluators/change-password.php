<?php
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

function verify_user_password($input, $stored) {
    $passwordMatches = password_verify($input, $stored);

    if (!$passwordMatches) {
        $hashInfo = password_get_info($stored);
        if ($hashInfo['algo'] === 0) {
            if ($input === $stored) {
                $passwordMatches = true;
            } elseif (preg_match('/^[a-f0-9]{32}$/i', $stored)) {
                $passwordMatches = hash_equals(strtolower($stored), md5($input));
            } elseif (preg_match('/^[a-f0-9]{40}$/i', $stored)) {
                $passwordMatches = hash_equals(strtolower($stored), sha1($input));
            }
        }
    }

    if (!$passwordMatches && strpos($stored, 'YourHashedPasswordHere') !== false) {
        // EDP default password checker from User.php
        $passwordMatches = hash_equals('edp123', $input);
    }

    return $passwordMatches;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = trim($_POST['current_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if ($current_password === '' || $new_password === '' || $confirm_password === '') {
        $_SESSION['error'] = "All password fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $_SESSION['error'] = "New password and confirmation do not match.";
    } elseif (strlen($new_password) < 6) {
        $_SESSION['error'] = "New password must be at least 6 characters long.";
    } elseif ($new_password === $current_password) {
        $_SESSION['error'] = "New password must be different from the current password.";
    } else {
        $stmt = $db->prepare("SELECT id, password, role FROM users WHERE id = :id LIMIT 1");
        $stmt->bindParam(':id', $_SESSION['user_id']);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $_SESSION['error'] = "User not found.";
        } elseif (!verify_user_password($current_password, (string)$user['password'])) {
            $_SESSION['error'] = "Current password is incorrect.";
        } else {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $db->prepare("UPDATE users SET password = :password WHERE id = :id");
            $update->bindParam(':password', $new_hash);
            $update->bindParam(':id', $_SESSION['user_id']);

            if ($update->execute()) {
                $_SESSION['success'] = "Password updated successfully.";
            } else {
                $_SESSION['error'] = "Unable to update password. Please try again.";
            }
        }
    }
    
    header("Location: change-password.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Evaluator</title>
    <?php include '../includes/header.php'; ?>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Change Password</h3>
                <div class="dropdown">
                    <button class="btn btn-primary dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog fa-fw me-2"></i>Settings</a></li>
                        <li><a class="dropdown-item" href="change-password.php"><i class="fas fa-key fa-fw me-2"></i>Change Password</a></li>
                    </ul>
                </div>
            </div>

            <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required>
                            <div class="form-text">Minimum 6 characters.</div>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Password
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
</body>
</html>
