<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../includes/mailer.php';
require_once '../includes/photo_helper.php';

$db = (new Database())->getConnection();
$teacher = new Teacher($db);
$teacher_data = $teacher->getById($_SESSION['teacher_id']);

if (!$teacher_data) {
    $_SESSION['error'] = "Teacher not found.";
    header("Location: dashboard.php");
    exit();
}

$accountUsername = '';
if (!empty($teacher_data['user_id'])) {
    $userStmt = $db->prepare("SELECT username FROM users WHERE id = :id LIMIT 1");
    $userStmt->bindValue(':id', $teacher_data['user_id']);
    $userStmt->execute();
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
    $accountUsername = $userRow['username'] ?? '';
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
$show_verification_modal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_photo') {
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "No file uploaded or upload error.";
    } else {
        $file = $_FILES['photo'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];

        if (!in_array($ext, $allowed)) {
            $_SESSION['error'] = "Invalid file type. Please upload JPG, PNG, or GIF images only.";
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $_SESSION['error'] = "File size too large. Maximum size is 2MB.";
        } else {
            $upload_dir = '../uploads/teachers/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            // Delete old photo if exists
            if (!empty($teacher_data['photo_path']) && file_exists($upload_dir . $teacher_data['photo_path'])) {
                unlink($upload_dir . $teacher_data['photo_path']);
            }

            $new_filename = 'teacher_' . $_SESSION['teacher_id'] . '_' . time() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $upload_dir . $new_filename)) {
                $photo_data = file_get_contents($upload_dir . $new_filename);
                $mime_types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
                $photo_mime = $mime_types[$ext] ?? 'image/jpeg';
                $stmt = $db->prepare("UPDATE teachers SET photo_path = :photo_path, photo_data = :photo_data, photo_mime = :photo_mime, updated_at = NOW() WHERE id = :id");
                $stmt->bindValue(':photo_path', $new_filename);
                $stmt->bindValue(':photo_data', $photo_data, PDO::PARAM_LOB);
                $stmt->bindValue(':photo_mime', $photo_mime);
                $stmt->bindValue(':id', $_SESSION['teacher_id']);
                if ($stmt->execute()) {
                    $_SESSION['success'] = "Profile photo updated successfully.";
                    $teacher_data = refreshTeacherData($db, $_SESSION['teacher_id']);
                } else {
                    $_SESSION['error'] = "Failed to update photo in database.";
                }
            } else {
                $_SESSION['error'] = "Failed to upload file.";
            }
        }
    }
    header("Location: profile.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $emailChanged = $email !== (string)($teacher_data['email'] ?? '');

    if ($name === '') {
        $_SESSION['error'] = "Name is required.";
    } elseif ($username === '') {
        $_SESSION['error'] = "Username is required.";
    } elseif ($email === '') {
        $_SESSION['error'] = "Email address is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Please enter a valid email address.";
    } else {
        if (!empty($teacher_data['user_id'])) {
            $usernameCheck = $db->prepare("SELECT id FROM users WHERE username = :username AND id != :id LIMIT 1");
            $usernameCheck->bindValue(':username', $username);
            $usernameCheck->bindValue(':id', $teacher_data['user_id']);
            $usernameCheck->execute();

            if ($usernameCheck->fetch(PDO::FETCH_ASSOC)) {
                $_SESSION['error'] = "The username provided is already associated with another account.";
                header("Location: profile.php");
                exit();
            }
        }

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
                $userUpdate = $db->prepare("UPDATE users SET name = :name, username = :username, updated_at = NOW() WHERE id = :id");
                $userUpdate->bindValue(':name', $name);
                $userUpdate->bindValue(':username', $username);
                $userUpdate->bindValue(':id', $teacher_data['user_id']);
                $userUpdate->execute();
                $_SESSION['name'] = $name;
                $_SESSION['username'] = $username;
                $accountUsername = $username;
            }
            if ($verificationCode) {
                $sent = sendEmailVerificationCode($email, $name ?: ($teacher_data['name'] ?? 'Teacher'), $verificationCode, $verificationExpires);
                if ($sent) {
                    $_SESSION['success'] = "Profile updated. Verification code sent to your email.";
                    $_SESSION['show_verification_modal'] = true;
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
    header("Location: profile.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_email') {
    $code = trim($_POST['verification_code'] ?? '');
    if ($code === '') {
        $_SESSION['error'] = "Please enter the verification code.";
    } else {
        $stmt = $db->prepare("SELECT email_verification_code, email_verification_expires FROM teachers WHERE id = :id LIMIT 1");
        $stmt->bindParam(':id', $_SESSION['teacher_id']);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['email_verification_code'] !== $code) {
            $_SESSION['error'] = "Invalid verification code. Please try again.";
        } elseif ($row['email_verification_expires'] && strtotime($row['email_verification_expires']) < time()) {
            $_SESSION['error'] = "Verification code has expired. Please request a new one.";
        } else {
            $update = $db->prepare("UPDATE teachers SET email_verified = 1, email_verification_code = NULL, email_verification_expires = NULL, updated_at = NOW() WHERE id = :id");
            $update->bindParam(':id', $_SESSION['teacher_id']);
            if ($update->execute()) {
                $_SESSION['success'] = "Email verified successfully!";
            } else {
                $_SESSION['error'] = "Unable to verify email. Please try again.";
            }
        }
    }
    header("Location: profile.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_verification') {
    $email = trim($teacher_data['email'] ?? '');
    if ($email === '') {
        $_SESSION['error'] = "Please save your email before requesting a verification code.";
    } else {
        $verificationCode = generateVerificationCode();
        $verificationExpires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        $stmt = $db->prepare("UPDATE teachers SET email_verification_code = :code, email_verification_expires = :expires, updated_at = NOW() WHERE id = :id");
        $stmt->bindValue(':code', $verificationCode);
        $stmt->bindValue(':expires', $verificationExpires);
        $stmt->bindParam(':id', $_SESSION['teacher_id']);

        if ($stmt->execute()) {
            $sent = sendEmailVerificationCode($email, $teacher_data['name'] ?? 'Teacher', $verificationCode, $verificationExpires);
            if ($sent) {
                $_SESSION['success'] = "A new verification code has been sent to your email.";
            } else {
                $_SESSION['error'] = "Failed to send verification code. Please try again later.";
            }
        } else {
            $_SESSION['error'] = "Unable to create verification code.";
        }
    }
    $_SESSION['show_verification_modal'] = true;
    header("Location: profile.php");
    exit();
}

// Check if we need to show the verification modal
if (isset($_SESSION['show_verification_modal'])) {
    $show_verification_modal = true;
    unset($_SESSION['show_verification_modal']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - AI Classroom Evaluation</title>
    <?php include '../includes/header.php'; ?>
    <style>
        .avatar {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: #3498db;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .avatar:hover .avatar-overlay {
            opacity: 1;
        }
        .avatar-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
            color: white;
            font-size: 1.2rem;
        }
        .info-item {
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .info-item:last-child { border-bottom: none; }
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>

    <div class="main-content" style="padding:0;">
        <div class="dashboard-bg-layer"><div class="bg-img"></div></div>
        <div class="dashboard-topbar">
            <h2>Saint Michael College of Caraga</h2>
            <div class="ms-auto">
                <div class="dropdown">
                    <button class="btn user-menu-btn dropdown-toggle" type="button" id="teacherMenu" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?> (Teacher)
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="teacherMenu">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="change-password.php"><i class="fas fa-key me-2"></i>Change Password</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="dashboard-body-wrap">
        <div class="container-fluid" style="padding:24px;">

        <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body p-4">
            <div class="d-flex align-items-center gap-3 mb-4">
                <div class="avatar" onclick="document.getElementById('photoInput').click();" title="Click to change photo">
                    <?php $teacherPhotoUrl = getPhotoUrl('teacher', $teacher_data['id'], $teacher_data['photo_path'] ?? ''); ?>
                    <?php if ($teacherPhotoUrl): ?>
                        <img id="avatarImg" src="<?php echo htmlspecialchars($teacherPhotoUrl); ?>" alt="Profile Photo"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <i class="fas fa-user" id="avatarIcon" style="display:none;"></i>
                    <?php else: ?>
                        <img id="avatarImg" style="display:none;" alt="Profile Photo">
                        <i class="fas fa-user" id="avatarIcon"></i>
                    <?php endif; ?>
                    <div class="avatar-overlay"><i class="fas fa-camera"></i></div>
                </div>
                <div>
                    <h3 class="mb-1"><?php echo htmlspecialchars($teacher_data['name']); ?></h3>
                    <div class="text-muted"><?php echo htmlspecialchars($teacher_data['department'] ?? 'Department not set'); ?></div>
                    <span class="badge bg-<?php echo ($teacher_data['status'] ?? '') === 'active' ? 'success' : 'secondary'; ?> mt-2">
                        <?php echo ucfirst($teacher_data['status'] ?? 'active'); ?>
                    </span>
                </div>
            </div>

            <!-- Hidden photo upload form -->
            <form id="photoForm" method="POST" action="" enctype="multipart/form-data" style="display:none;">
                <input type="hidden" name="action" value="upload_photo">
                <input type="file" id="photoInput" name="photo" accept="image/jpeg,image/png,image/gif">
            </form>

            <form method="POST" action="">
                <div class="info-item">
                    <label class="form-label"><i class="fas fa-user me-2"></i>Name</label>
                    <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($teacher_data['name'] ?? ''); ?>" readonly>
                </div>
                <div class="info-item">
                    <label class="form-label"><i class="fas fa-id-badge me-2"></i>Username</label>
                    <input type="text" class="form-control" name="username" value="<?php echo htmlspecialchars($accountUsername); ?>" readonly>
                </div>
                <div class="info-item">
                    <label class="form-label"><i class="fas fa-envelope me-2"></i>Email</label>
                    <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($teacher_data['email'] ?? ''); ?>" placeholder="Enter your email" required>
                    <div class="form-text">
                        Used for password recovery. Verify this email first so reset codes can be sent here.
                        <?php if ($emailVerified): ?>
                            <span class="badge bg-success">Verified</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">Not verified</span>
                            <?php if (!empty($teacher_data['email'])): ?>
                                <button type="submit" form="resendCodeForm" class="btn btn-sm btn-outline-primary ms-1">
                                    <i class="fas fa-paper-plane me-1"></i>Send Verification Code
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
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
            <form id="resendCodeForm" method="POST" action="" style="display:none;">
                <input type="hidden" name="action" value="resend_verification">
            </form>
            </div>
        </div>

        </div>
        </div>
    </div>

    <?php if (!$emailVerified && !empty($teacher_data['email'])): ?>
    <!-- Email Verification Code Modal -->
    <div class="modal fade" id="verifyEmailModal" tabindex="-1" aria-labelledby="verifyEmailModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered" style="max-width:460px;">
            <div class="modal-content border-0 shadow-lg" style="border-radius:16px;overflow:hidden;">
                <div class="modal-body text-center p-0">
                    <!-- Top colored banner -->
                    <div style="background:linear-gradient(135deg,#4285f4,#34a853);padding:28px 24px 20px;">
                        <div style="width:72px;height:72px;border-radius:50%;background:rgba(255,255,255,0.2);display:inline-flex;align-items:center;justify-content:center;margin-bottom:8px;">
                            <i class="fas fa-envelope-open-text" style="font-size:32px;color:#fff;"></i>
                        </div>
                        <h5 class="text-white fw-bold mb-0" style="font-size:1.2rem;">Verify Your Email</h5>
                    </div>

                    <!-- Content -->
                    <div class="px-4 pt-3 pb-2">
                        <p class="text-muted mb-1" style="font-size:0.9rem;">We sent a 6-digit code to</p>
                        <p class="fw-semibold mb-3" style="font-size:1rem;"><?php echo htmlspecialchars($teacher_data['email']); ?></p>

                        <form method="POST" action="" id="verifyForm">
                            <input type="hidden" name="action" value="verify_email">
                            <div class="mb-2">
                                <input type="text" class="form-control form-control-lg text-center fw-bold" id="verification_code" name="verification_code" maxlength="6" pattern="[0-9]{6}" placeholder="------" required autocomplete="off" style="letter-spacing:12px;font-size:1.6rem;border-radius:10px;border:2px solid #dee2e6;padding:12px;" inputmode="numeric">
                            </div>
                            <p class="text-muted mb-3" style="font-size:0.8rem;"><i class="fas fa-clock me-1"></i>Code expires in 10 minutes</p>
                            <button type="submit" class="btn btn-primary w-100 py-2 mb-2" style="border-radius:10px;font-size:1rem;">
                                <i class="fas fa-check-circle me-1"></i>Verify Email
                            </button>
                        </form>

                        <div class="d-flex align-items-center justify-content-center gap-2 mt-1 mb-2">
                            <span class="text-muted" style="font-size:0.85rem;">Didn't receive the code?</span>
                            <form method="POST" action="" class="d-inline">
                                <input type="hidden" name="action" value="resend_verification">
                                <button type="submit" class="btn btn-link p-0" style="font-size:0.85rem;text-decoration:none;">Resend Code</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 justify-content-center gap-2 pb-4 pt-2">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal" style="border-radius:8px;">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php include '../includes/footer.php'; ?>
    <?php if($show_verification_modal && !$emailVerified && !empty($teacher_data['email'])): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var modal = new bootstrap.Modal(document.getElementById('verifyEmailModal'));
        modal.show();
        document.getElementById('verification_code').focus();
    });
    </script>
    <?php endif; ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var codeInput = document.getElementById('verification_code');
        if (codeInput) {
            codeInput.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        }
    });
    </script>
    <script>
    document.getElementById('photoInput').addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const file = this.files[0];
            if (file.size > 2 * 1024 * 1024) {
                alert('File size too large. Maximum size is 2MB.');
                this.value = '';
                return;
            }
            // Preview
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = document.getElementById('avatarImg');
                img.src = e.target.result;
                img.style.display = 'block';
                const icon = document.getElementById('avatarIcon');
                if (icon) icon.style.display = 'none';
            };
            reader.readAsDataURL(file);
            // Submit
            document.getElementById('photoForm').submit();
        }
    });
    </script>
</body>
</html>
