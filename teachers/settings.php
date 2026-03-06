<?php
require_once '../auth/session-check.php';
if($_SESSION['role'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Fetch current user details
$stmt = $db->prepare("SELECT id, username, name, email, is_email_verified FROM users WHERE id = :id LIMIT 1");
$stmt->bindParam(':id', $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: ../auth/logout.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($name === '' || $username === '' || $email === '') {
        $_SESSION['error'] = "Name, Username, and Email are required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Please enter a valid email address.";
    } else {
        // Check if another user has this username
        $checkStmt = $db->prepare("SELECT id FROM users WHERE username = :username AND id != :id LIMIT 1");
        $checkStmt->bindParam(':username', $username);
        $checkStmt->bindParam(':id', $_SESSION['user_id']);
        $checkStmt->execute();

        if ($checkStmt->rowCount() > 0) {
            $_SESSION['error'] = "The username provided is already associated with another account.";
        } else {
            // Check if another user has this email
            $emailCheck = $db->prepare("SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1");
            $emailCheck->bindParam(':email', $email);
            $emailCheck->bindParam(':id', $_SESSION['user_id']);
            $emailCheck->execute();

            if ($emailCheck->rowCount() > 0) {
                $_SESSION['error'] = "The email provided is already associated with another account.";
            } else {
                // Update users table
                $update = $db->prepare("UPDATE users SET name = :name, username = :username, email = :email WHERE id = :id");
                $update->bindParam(':name', $name);
                $update->bindParam(':username', $username);
                $update->bindParam(':email', $email);
                $update->bindParam(':id', $_SESSION['user_id']);

                if ($update->execute()) {
                    if ($email !== $user['email']) {
                        // Email changed, require verification
                        $token = bin2hex(random_bytes(32));
                        $db->prepare("UPDATE users SET is_email_verified = 0, email = :email, verification_token = :t WHERE id = :id")->execute([':email' => $email, ':t' => $token, ':id' => $_SESSION['user_id']]);
                        require_once '../includes/mailer.php';
                        $link = "http://" . $_SERVER['HTTP_HOST'] . "/ADCES-SYSTEM/verify-email.php?token=" . $token;
                        sendVerificationLinkEmail($email, $name, $link);
                        $_SESSION['success'] = "Settings updated. A verification link has been sent to your new email.";
                    } else {
                        $_SESSION['success'] = "Settings updated successfully.";
                    }
                    
                    $_SESSION['name'] = $name;
                    $_SESSION['username'] = $username;
                    $_SESSION['email'] = $email;
                    
                    // Update local user array for the current render loop
                    $user['name'] = $name;
                    $user['username'] = $username;
                    $user['email'] = $email;
                } else {
                    $_SESSION['error'] = "Unable to update settings. Please try again.";
                }
            }
        }
    }
    
    header("Location: settings.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Teacher</title>
    <?php include '../includes/header.php'; ?>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Settings</h3>
                <div class="dropdown">
                    <button class="btn user-menu-btn dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog fa-fw me-2"></i>Settings</a></li>
                        <li><a class="dropdown-item" href="change-password.php"><i class="fas fa-key fa-fw me-2"></i>Change Password</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../auth/logout.php"><i class="fas fa-sign-out-alt fa-fw me-2"></i>Logout</a></li>
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
                            <label for="name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                            <div class="form-text">This is used for logging in.</div>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                            <div class="form-text">
                                Used for password recovery. Verify this email first so reset codes can be sent here.
                                <?php if(isset($user['is_email_verified']) && (int)$user['is_email_verified'] === 1): ?>
                                    <span class="badge bg-success">Verified</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Not verified</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Changes
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
