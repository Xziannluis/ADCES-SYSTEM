<?php
session_start();
require_once 'config/database.php';

// Only allow passing to verification if initial process generated session
if (!isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_email'])) {
    header("Location: forgot-password.php");
    exit();
}

$email = $_SESSION['reset_email'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    
    if (empty($code) || strlen($code) !== 6) {
        $_SESSION['error'] = 'Enter the 6-digit code correctly.';
    } else {
        $db = (new Database())->getConnection();
        $stmt = $db->prepare("SELECT id FROM users WHERE id = :id AND reset_token = :token AND reset_token_expires > NOW() LIMIT 1");
        $stmt->bindParam(':id', $_SESSION['reset_user_id']);
        $stmt->bindParam(':token', $code);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['reset_verified'] = true;
            header("Location: reset-password.php");
            exit();
        } else {
            $_SESSION['error'] = 'The code is invalid or has expired. Please try again or request a new code.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code - AI Classroom Evaluation System</title>
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

        .login-card h4 { font-weight: 700; color: #2c3e50; }
        .btn-login { background: #073b5eff; border: none; padding: 12px; font-weight: 600; border-radius: 8px; transition: all 0.3s; }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 5px 15px #0a436aff; }
        .code-input { letter-spacing: 12px; font-size: 24px; text-align: center; }
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
                <i class="fas fa-envelope-open-text" style="font-size: 3rem; color: #3498db;"></i>
            </div>
            <h4 class="text-center mb-3">Verify Recovery Code</h4>
            
            <p class="text-center text-muted mb-4">
                We've sent a 6-digit confirmation code to <br><strong><?php echo htmlspecialchars($email); ?></strong>. <br>Please enter it below.
            </p>
            
            <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="mb-4">
                    <input type="text" class="form-control code-input" id="code" name="code" maxlength="6" pattern="\d*" placeholder="000000" autocomplete="one-time-code" required autofocus>
                </div>
                
                <button type="submit" class="btn btn-primary btn-login w-100 mb-3">
                    <i class="fas fa-check-circle me-2"></i>Verify Code
                </button>
                
                <div class="text-center mt-3">
                    <a href="forgot-password.php" style="color: #e74c3c; font-weight: 500; text-decoration: none;">
                        <i class="fas fa-redo me-1"></i> Resend code
                    </a>
                    <span class="mx-2 text-muted">|</span>
                    <a href="login.php" style="color: #7f8c8d; font-weight: 500; text-decoration: none;">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
