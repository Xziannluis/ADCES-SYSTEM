<?php
require_once '../auth/session-check.php';
if($_SESSION['role'] != 'edp') {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/mailer.php';

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

// Fetch form settings
$formSettings = [];
try {
    $fsStmt = $db->query("SELECT setting_key, setting_value FROM form_settings");
    while ($row = $fsStmt->fetch(PDO::FETCH_ASSOC)) {
        $formSettings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    // table may not exist yet
}

$show_verification_modal = false;
$verification_email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update_settings';

    if ($action === 'verify_code') {
        $code = trim($_POST['verification_code'] ?? '');
        if ($code === '') {
            $_SESSION['error'] = "Please enter the verification code.";
        } else {
            $codeStmt = $db->prepare("SELECT email_verification_code, email_verification_expires FROM users WHERE id = :id LIMIT 1");
            $codeStmt->bindParam(':id', $_SESSION['user_id']);
            $codeStmt->execute();
            $row = $codeStmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || $row['email_verification_code'] !== $code) {
                $_SESSION['error'] = "Invalid verification code. Please try again.";
            } elseif ($row['email_verification_expires'] && strtotime($row['email_verification_expires']) < time()) {
                $_SESSION['error'] = "Verification code has expired. Please update your email again to get a new code.";
            } else {
                $update = $db->prepare("UPDATE users SET is_email_verified = 1, email_verification_code = NULL, email_verification_expires = NULL, verification_token = NULL WHERE id = :id");
                $update->bindParam(':id', $_SESSION['user_id']);
                $update->execute();
                $user['is_email_verified'] = 1;
                $_SESSION['success'] = "Email verified successfully!";
            }
        }
        header("Location: settings.php");
        exit();
    } elseif ($action === 'resend_code') {
        $code = sprintf("%06d", random_int(0, 999999));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $db->prepare("UPDATE users SET email_verification_code = :code, email_verification_expires = :expires WHERE id = :id")
            ->execute([':code' => $code, ':expires' => $expiresAt, ':id' => $_SESSION['user_id']]);
        $sent = sendEmailVerificationCode($user['email'], $user['name'], $code, $expiresAt);
        if ($sent) {
            $_SESSION['success'] = "A new verification code has been sent to your email.";
        } else {
            $_SESSION['error'] = "Failed to send verification code. Please check your email address or try again later.";
        }
        $_SESSION['show_verification_modal'] = true;
        header("Location: settings.php");
        exit();
    } elseif ($action === 'update_form_settings') {
        $fields = ['form_code_no', 'issue_status', 'revision_no', 'date_effective', 'approved_by'];
        $updateStmt = $db->prepare("INSERT INTO form_settings (setting_key, setting_value) VALUES (:key, :val) ON DUPLICATE KEY UPDATE setting_value = :val2, updated_at = NOW()");
        $ok = true;
        foreach ($fields as $f) {
            $val = trim($_POST[$f] ?? '');
            if ($val === '') continue;
            try {
                $updateStmt->execute([':key' => $f, ':val' => $val, ':val2' => $val]);
            } catch (PDOException $e) {
                $ok = false;
            }
        }
        $_SESSION[$ok ? 'success' : 'error'] = $ok ? "Form settings updated successfully." : "Failed to update form settings.";
        header("Location: settings.php");
        exit();
    } else {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($name === '' || $username === '' || $email === '') {
            $_SESSION['error'] = "Name, Username, and Email are required fields.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = "Please enter a valid email address.";
        } else {
            $checkStmt = $db->prepare("SELECT id FROM users WHERE username = :username AND id != :id LIMIT 1");
            $checkStmt->bindParam(':username', $username);
            $checkStmt->bindParam(':id', $_SESSION['user_id']);
            $checkStmt->execute();

            if ($checkStmt->rowCount() > 0) {
                $_SESSION['error'] = "The username provided is already associated with another account.";
            } else {
                $emailCheck = $db->prepare("SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1");
                $emailCheck->bindParam(':email', $email);
                $emailCheck->bindParam(':id', $_SESSION['user_id']);
                $emailCheck->execute();

                if ($emailCheck->rowCount() > 0) {
                    $_SESSION['error'] = "The email provided is already associated with another account.";
                } else {
                    $update = $db->prepare("UPDATE users SET name = :name, username = :username, email = :email WHERE id = :id");
                    $update->bindParam(':name', $name);
                    $update->bindParam(':username', $username);
                    $update->bindParam(':email', $email);
                    $update->bindParam(':id', $_SESSION['user_id']);

                    if ($update->execute()) {
                        if ($email !== $user['email']) {
                            $code = sprintf("%06d", random_int(0, 999999));
                            $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                            $db->prepare("UPDATE users SET is_email_verified = 0, email_verification_code = :code, email_verification_expires = :expires WHERE id = :id")
                                ->execute([':code' => $code, ':expires' => $expiresAt, ':id' => $_SESSION['user_id']]);
                            $sent = sendEmailVerificationCode($email, $name, $code, $expiresAt);
                            if ($sent) {
                                $_SESSION['success'] = "Settings updated. A verification code has been sent to your new email.";
                                $_SESSION['show_verification_modal'] = true;
                            } else {
                                $_SESSION['error'] = "Settings updated but failed to send verification code. Please check your email address or try again.";
                            }
                        } else {
                            $_SESSION['success'] = "Settings updated successfully.";
                        }

                        $_SESSION['name'] = $name;
                        $_SESSION['username'] = $username;
                        $_SESSION['email'] = $email;

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
}

if (isset($_SESSION['show_verification_modal'])) {
    $show_verification_modal = true;
    $verification_email = $user['email'];
    unset($_SESSION['show_verification_modal']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - EDP Admin</title>
    <?php include '../includes/header.php'; ?>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content" style="padding:0;">
        <div class="dashboard-bg-layer"><div class="bg-img"></div></div>
        <div class="dashboard-topbar">
            <h2>Saint Michael College of Caraga</h2>
            <div class="ms-auto">
                <div class="dropdown">
                    <button class="btn user-menu-btn dropdown-toggle" type="button" id="evaluatorMenu" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?> (<?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?>)
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="evaluatorMenu">
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><a class="dropdown-item" href="change-password.php"><i class="fas fa-key me-2"></i>Change Password</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="dashboard-body-wrap">
        <div class="container-fluid" style="padding:24px;">

            <a href="dashboard.php" class="btn btn-outline-secondary mb-3"><i class="fas fa-arrow-left me-2"></i>Back to Dashboard</a>

            <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
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
                            <div class="form-text"></div>
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
                                    <?php if(!empty($user['email'])): ?>
                                        <button type="submit" form="resendCodeForm" class="btn btn-sm btn-outline-primary ms-1">
                                            <i class="fas fa-paper-plane me-1"></i>Send Verification Code
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Changes
                        </button>
                    </form>
                    <form id="resendCodeForm" method="POST" action="" style="display:none;">
                        <input type="hidden" name="action" value="resend_code">
                    </form>
                </div>
            </div>

            <!-- Evaluation Form Settings Card -->
            <div class="card mt-4">
                <div class="card-header" style="background-color:#1a1a2e; color:#fff;">
                    <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Evaluation Form Settings</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">These values appear on the Classroom Evaluation Form header.</p>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_form_settings">
                        <div class="mb-3">
                            <label for="form_code_no" class="form-label fw-bold">Form Code No.</label>
                            <input type="text" class="form-control" id="form_code_no" name="form_code_no" value="<?php echo htmlspecialchars($formSettings['form_code_no'] ?? 'FM-DPM-SMCC-RTH-04'); ?>" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="issue_status" class="form-label fw-bold">Issue Status</label>
                                <input type="text" class="form-control" id="issue_status" name="issue_status" value="<?php echo htmlspecialchars($formSettings['issue_status'] ?? '02'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="revision_no" class="form-label fw-bold">Revision No.</label>
                                <input type="text" class="form-control" id="revision_no" name="revision_no" value="<?php echo htmlspecialchars($formSettings['revision_no'] ?? '02'); ?>" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="date_effective" class="form-label fw-bold">Date Effective</label>
                                <input type="text" class="form-control" id="date_effective" name="date_effective" value="<?php echo htmlspecialchars($formSettings['date_effective'] ?? '13 September 2023'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="approved_by" class="form-label fw-bold">Approved By</label>
                                <input type="text" class="form-control" id="approved_by" name="approved_by" value="<?php echo htmlspecialchars($formSettings['approved_by'] ?? 'President'); ?>" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Form Settings
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>
    </div>

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
                        <p class="fw-semibold mb-3" style="font-size:1rem;"><?php echo htmlspecialchars($user['email'] ?? ''); ?></p>

                        <form method="POST" action="" id="verifyCodeForm">
                            <input type="hidden" name="action" value="verify_code">
                            <div class="mb-2">
                                <input type="text" class="form-control form-control-lg text-center fw-bold" id="verification_code" name="verification_code" maxlength="6" pattern="[0-9]{6}" placeholder="------" required autocomplete="off" style="letter-spacing:12px;font-size:1.6rem;border-radius:10px;border:2px solid #dee2e6;padding:12px;" inputmode="numeric">
                            </div>
                            <p class="text-muted mb-3" style="font-size:0.8rem;"><i class="fas fa-clock me-1"></i>Code expires in 15 minutes</p>
                            <button type="submit" class="btn btn-primary w-100 py-2 mb-2" style="border-radius:10px;font-size:1rem;">
                                <i class="fas fa-check-circle me-1"></i>Verify Email
                            </button>
                        </form>

                        <div class="d-flex align-items-center justify-content-center gap-2 mt-1 mb-2">
                            <span class="text-muted" style="font-size:0.85rem;">Didn't receive the code?</span>
                            <form method="POST" action="" class="d-inline">
                                <input type="hidden" name="action" value="resend_code">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <?php if($show_verification_modal): ?>
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
</body>
</html>
