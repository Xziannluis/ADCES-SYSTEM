<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../includes/mailer.php';

$db = (new Database())->getConnection();
$teacher = new Teacher($db);
$teacher_data = $teacher->getById($_SESSION['teacher_id']);

if (!$teacher_data) {
    $_SESSION['error'] = "Teacher not found.";
    header("Location: dashboard.php");
    exit();
}

function generateVerificationCode() {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function refreshTeacherData($db, $teacherId) {
    $stmt = $db->prepare("SELECT * FROM teachers WHERE id = :id LIMIT 1");
    $stmt->bindParam(':id', $teacherId);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$emailVerified = (int)($teacher_data['email_verified'] ?? 0) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $emailChanged = $email !== (string)($teacher_data['email'] ?? '');

    if ($name === '') {
        $_SESSION['error'] = "Name is required.";
    } elseif ($email === '') {
        $_SESSION['error'] = "Email address is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Please enter a valid email address.";
    } else {
        $verificationCode = null;
        $verificationExpires = null;

        if ($emailChanged || !$emailVerified) {
            $verificationCode = generateVerificationCode();
            $verificationExpires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        }

    $stmt = $db->prepare("UPDATE teachers SET name = :name, email = :email, phone = :phone, email_verified = :email_verified, email_verification_code = :code, email_verification_expires = :expires, updated_at = NOW() WHERE id = :id");
    $stmt->bindValue(':name', $name);
        $stmt->bindValue(':email', $email !== '' ? $email : null, $email !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':phone', $phone !== '' ? $phone : null, $phone !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':email_verified', $emailChanged || !$emailVerified ? 0 : 1, PDO::PARAM_INT);
        $stmt->bindValue(':code', $verificationCode, $verificationCode ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':expires', $verificationExpires, $verificationExpires ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindParam(':id', $_SESSION['teacher_id']);

        if ($stmt->execute()) {
            $_SESSION['success'] = "Profile updated successfully.";
            if (!empty($teacher_data['user_id'])) {
                $userUpdate = $db->prepare("UPDATE users SET name = :name, updated_at = NOW() WHERE id = :id");
                $userUpdate->bindValue(':name', $name);
                $userUpdate->bindValue(':id', $teacher_data['user_id']);
                $userUpdate->execute();
                $_SESSION['name'] = $name;
            }
            if ($verificationCode) {
                $sent = sendEmailVerificationCode($email, $name ?: ($teacher_data['name'] ?? 'Teacher'), $verificationCode, $verificationExpires);
                if ($sent) {
                    $_SESSION['success'] = "Profile updated. Verification code sent to your email.";
                } else {
                    $_SESSION['error'] = "Profile updated, but verification email could not be sent.";
                }
            }
            $teacher_data = refreshTeacherData($db, $_SESSION['teacher_id']);
            $emailVerified = (int)($teacher_data['email_verified'] ?? 0) === 1;
        } else {
            $_SESSION['error'] = "Unable to update profile. Please try again.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_email') {
    $code = trim($_POST['verification_code'] ?? '');
    if ($code === '') {
        $_SESSION['error'] = "Verification code is required.";
    } else {
        $stmt = $db->prepare("SELECT email_verification_code, email_verification_expires FROM teachers WHERE id = :id LIMIT 1");
        $stmt->bindParam(':id', $_SESSION['teacher_id']);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $expiresAt = $row['email_verification_expires'] ?? null;
        $isExpired = $expiresAt ? strtotime($expiresAt) < time() : true;

        if (!$row || $row['email_verification_code'] !== $code) {
            $_SESSION['error'] = "Invalid verification code.";
        } elseif ($isExpired) {
            $_SESSION['error'] = "Verification code has expired. Please request a new one.";
        } else {
            $update = $db->prepare("UPDATE teachers SET email_verified = 1, email_verification_code = NULL, email_verification_expires = NULL, updated_at = NOW() WHERE id = :id");
            $update->bindParam(':id', $_SESSION['teacher_id']);
            if ($update->execute()) {
                $_SESSION['success'] = "Email verified successfully.";
                $teacher_data = refreshTeacherData($db, $_SESSION['teacher_id']);
                $emailVerified = true;
            } else {
                $_SESSION['error'] = "Unable to verify email. Please try again.";
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_verification') {
    $email = trim($teacher_data['email'] ?? '');
    if ($email === '') {
        $_SESSION['error'] = "Please save your email before requesting a verification code.";
    } else {
        $verificationCode = generateVerificationCode();
        $verificationExpires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        $stmt = $db->prepare("UPDATE teachers SET email_verified = 0, email_verification_code = :code, email_verification_expires = :expires, updated_at = NOW() WHERE id = :id");
        $stmt->bindValue(':code', $verificationCode);
        $stmt->bindValue(':expires', $verificationExpires);
        $stmt->bindParam(':id', $_SESSION['teacher_id']);

        if ($stmt->execute()) {
            $sent = sendEmailVerificationCode($email, $teacher_data['name'] ?? 'Teacher', $verificationCode, $verificationExpires);
            if ($sent) {
                $_SESSION['success'] = "Verification code sent to your email.";
            } else {
                $_SESSION['error'] = "Unable to send verification email. Please try again later.";
            }
            $teacher_data = refreshTeacherData($db, $_SESSION['teacher_id']);
            $emailVerified = (int)($teacher_data['email_verified'] ?? 0) === 1;
        } else {
            $_SESSION['error'] = "Unable to create verification code.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - AI Classroom Evaluation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: var(--primary) !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .profile-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-top: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .avatar {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: var(--secondary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
        }

        .info-item {
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .info-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-graduation-cap me-2"></i>My Profile
            </a>
            <div class="ms-auto">
                <div class="dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="teacherMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($_SESSION['name']); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="teacherMenu">
                        <li>
                            <a class="dropdown-item" href="profile.php">
                                <i class="fas fa-user me-2"></i>Profile
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="change-password.php">
                                <i class="fas fa-key me-2"></i>Change Password
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="../auth/logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i>Sign Out
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show mt-4" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show mt-4" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="profile-card">
            <div class="d-flex align-items-center gap-3 mb-4">
                <div class="avatar">
                    <i class="fas fa-user"></i>
                </div>
                <div>
                    <h3 class="mb-1"><?php echo htmlspecialchars($teacher_data['name']); ?></h3>
                    <div class="text-muted"><?php echo htmlspecialchars($teacher_data['department'] ?? 'Department not set'); ?></div>
                    <span class="badge bg-<?php echo ($teacher_data['status'] ?? '') === 'active' ? 'success' : 'secondary'; ?> mt-2">
                        <?php echo ucfirst($teacher_data['status'] ?? 'active'); ?>
                    </span>
                </div>
            </div>

            <form method="POST" action="">
                <div class="info-item">
                    <label class="form-label"><i class="fas fa-user me-2"></i>Name</label>
                    <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($teacher_data['name'] ?? ''); ?>" placeholder="Enter your name" required>
                </div>
                <div class="info-item">
                    <label class="form-label"><i class="fas fa-envelope me-2"></i>Email</label>
                    <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($teacher_data['email'] ?? ''); ?>" placeholder="Enter your email" required>
                    <?php if (!$emailVerified): ?>
                        <small class="text-warning">Not verified</small>
                    <?php else: ?>
                        <small class="text-success">Verified</small>
                    <?php endif; ?>
                </div>
                <?php if (!$emailVerified && !empty($teacher_data['email'])): ?>
                    <div class="info-item">
                        <label class="form-label"><i class="fas fa-shield-check me-2"></i>Verification Code</label>
                        <div class="d-flex gap-2">
                            <input type="text" class="form-control" name="verification_code" placeholder="Enter 6-digit code">
                            <button type="submit" class="btn btn-success" name="action" value="verify_email">
                                <i class="fas fa-check me-2"></i>Verify
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="info-item">
                    <label class="form-label"><i class="fas fa-phone me-2"></i>Phone</label>
                    <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($teacher_data['phone'] ?? ''); ?>" placeholder="Enter your phone number (optional)">
                </div>
                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary" name="action" value="update_profile">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
