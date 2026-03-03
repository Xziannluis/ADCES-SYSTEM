<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['reset_user_id']) || empty($_SESSION['reset_verified'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    
    if (empty($new_password) || empty($confirm_password)) {
        $_SESSION['error'] = 'All fields are required.';
    } elseif ($new_password !== $confirm_password) {
        $_SESSION['error'] = 'Passwords do not match.';
    } elseif (strlen($new_password) < 6) {
        $_SESSION['error'] = 'Password must be at least 6 characters.';
    } else {
        $db = (new Database())->getConnection();
        
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $db->prepare("UPDATE users SET password = :password, reset_token = NULL, reset_token_expires = NULL, updated_at = NOW() WHERE id = :id");
        $update->bindParam(':password', $new_hash);
        $update->bindParam(':id', $_SESSION['reset_user_id']);
        
        if ($update->execute()) {
            // Clean up session
            unset($_SESSION['reset_user_id']);
            unset($_SESSION['reset_email']);
            unset($_SESSION['reset_verified']);
            
            $_SESSION['success'] = "Password resetting successful! You can now login with your new password.";
            header("Location: login.php");
            exit();
        } else {
            $_SESSION['error'] = 'Failed to reset password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Password - AI Classroom Evaluation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .login-body {
            background: url('smccnasipit_cover.jpg') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        .login-body::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background: url('smccnasipit_cover.jpg') no-repeat center center fixed;
            background-size: cover; filter: blur(8px); z-index: 0;
        }
        .login-body::after {
            content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.4); z-index: 1;
        }
        .login-container {
            width: 100%; max-width: 490px; padding: 20px; position: relative; z-index: 2;
        }
        .login-card {
            background: white; padding: 40px 30px; border-radius: 10px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3); border: none; backdrop-filter: blur(2px);
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h2 {
            color: #ffffff;
            font-weight: 800;
            margin-bottom: 5px;
            font-size: 1.8rem;
            text-transform: uppercase;
            text-shadow: -1px -1px 0 #003366, 1px -1px 0 #003366, -1px 1px 0 #003366, 1px 1px 0 #003366, 2px 2px 5px rgba(0,0,0,0.6);
            white-space: nowrap;
        }
        
        .login-header p {
            color: #ffffff;
            font-size: 1.2rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-shadow: -1px -1px 0 #003366, 1px -1px 0 #003366, -1px 1px 0 #003366, 1px 1px 0 #003366, 2px 2px 4px rgba(0,0,0,0.6);
            margin-bottom: 0;
        }
        
        .logo-container {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .logo-image {
            max-width: 130px;
            height: auto;
            filter: drop-shadow(0 5px 10px rgba(0,0,0,0.2));
        }

        .btn-login { background: #27ae60; border: none; padding: 12px; font-weight: 600; border-radius: 8px; transition: all 0.3s; }
        .btn-login:hover { background: #219653; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(39,174,96,0.3); }
    </style>
</head>
<body class="login-body">
    <div class="login-container">
        <!-- SMCC Logo -->
        <div class="logo-container">
            <img src="assets/img/sd.webp" alt="SMCC Logo" class="logo-image">
        </div>
        
        <div class="login-header">
            <h2><i class="fas fa-robot me-2"></i>ADCES</h2>
            <p>PASSWORD RECOVERY</p>
        </div>

        <div class="login-card">
            <div class="text-center mb-4">
                <i class="fas fa-lock" style="font-size: 3rem; color: #27ae60;"></i>
            </div>
            <h4 class="text-center mb-3">Create New Password</h4>
            
            <p class="text-center text-muted mb-4">Your identity has been verified. Please enter your new password below.</p>
            
            <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="mb-3">
                    <label for="new_password" class="form-label fw-bold text-secondary">New Password</label>
                    <input type="password" class="form-control px-3 py-2" id="new_password" name="new_password" required placeholder="Minimum 6 characters">
                </div>
                <div class="mb-4">
                    <label for="confirm_password" class="form-label fw-bold text-secondary">Confirm New Password</label>
                    <input type="password" class="form-control px-3 py-2" id="confirm_password" name="confirm_password" required placeholder="Re-enter password">
                </div>
                
                <button type="submit" class="btn btn-primary btn-login w-100 mb-3 text-white">
                    <i class="fas fa-save me-2"></i>Reset Password
                </button>
            </form>
        </div>
    </div>
</body>
</html>
