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
    <title>Verify Code - AI-Driven Classroom Evaluation System</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Roboto', Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: url('smccnasipit_cover.jpg') no-repeat center center;
            background-size: cover;
            filter: blur(8px);
            transform: scale(1.05);
            z-index: 0;
        }

        body::after {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.15);
            z-index: 1;
        }

        .left-panel {
            width: 45%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 2;
            padding: 40px;
        }

        .left-panel::before {
            content: '';
            position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 120%;
            background: linear-gradient(to right, rgba(10, 20, 40, 0.75) 0%, rgba(10, 20, 40, 0.7) 60%, rgba(10, 20, 40, 0.4) 85%, transparent 100%);
            z-index: 0;
        }

        .left-panel .logo-img {
            width: 200px; height: auto;
            margin-bottom: 28px;
            filter: drop-shadow(0 8px 24px rgba(0,0,0,0.5));
            position: relative; z-index: 1;
        }

        .left-panel h1 {
            color: #fff; font-size: 2rem; font-weight: 700;
            text-align: center; margin-bottom: 8px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.5);
            position: relative; z-index: 1;
        }

        .left-panel .system-name {
            color: rgba(255,255,255,0.85); font-size: 1.1rem;
            font-weight: 400; letter-spacing: 0.5px;
            text-align: center; margin-bottom: 6px;
            text-shadow: 0 1px 6px rgba(0,0,0,0.4);
            position: relative; z-index: 1;
        }

        .left-panel .address {
            color: rgba(255,255,255,0.6); font-size: 0.95rem;
            text-align: center;
            text-shadow: 0 1px 4px rgba(0,0,0,0.3);
            position: relative; z-index: 1;
        }

        .right-panel {
            width: 55%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px;
            position: relative;
            z-index: 2;
        }

        .right-content {
            width: 100%; max-width: 420px;
            display: flex; flex-direction: column; align-items: center;
        }

        .panel-heading {
            font-size: 1.5rem; font-weight: 700; color: #fff;
            text-transform: uppercase; letter-spacing: 2px;
            margin-bottom: 20px; text-align: center;
            text-shadow: 0 2px 8px rgba(0,0,0,0.5);
        }

        .login-card {
            background: #fff; border-radius: 16px;
            padding: 36px 32px 28px; width: 100%;
            box-shadow: 0 12px 40px rgba(0,0,0,0.25);
        }

        .login-card h4 { font-weight: 700; color: #1a2a44; font-size: 1.2rem; }

        .code-input {
            letter-spacing: 12px; font-size: 24px; text-align: center;
            border: 1.5px solid #dee2e6; border-radius: 10px;
            height: 56px; padding: 10px;
        }
        .code-input:focus {
            border-color: #2a5298;
            box-shadow: 0 0 0 3px rgba(42,82,152,0.12);
        }

        .btn-login {
            background: #4a6cf7; border: none; padding: 12px;
            font-weight: 600; font-size: 1rem; border-radius: 10px;
            color: #fff; transition: all 0.25s;
        }
        .btn-login:hover {
            background: #3b5de7; transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(74,108,247,0.4); color: #fff;
        }

        .alert { border-radius: 10px; border: none; padding: 12px 15px; font-size: 0.9rem; }

        .footer-text {
            margin-top: 20px; color: rgba(255,255,255,0.55);
            font-size: 0.8rem; text-align: center;
        }

        @media (max-width: 768px) {
            body { flex-direction: column; }
            .left-panel { width: 100%; padding: 30px 20px; }
            .left-panel .logo-img { width: 100px; margin-bottom: 12px; }
            .left-panel h1 { font-size: 1.3rem; }
            .left-panel::before { width: 100%; background: rgba(10, 20, 40, 0.65); }
            .right-panel { width: 100%; padding: 24px 16px; }
            .login-card { padding: 28px 22px 24px; }
        }
    </style>
</head>
<body>
    <div class="left-panel">
        <img src="assets/img/SMCC_LOGO.webp" alt="SMCC Logo" class="logo-img">
        <h1>Saint Michael College of Caraga</h1>
        <p class="system-name">AI-Driven Classroom Evaluation System</p>
        <p class="address">Brgy. 4, Atupan St., Nasipit, Agusan del Norte</p>
    </div>

    <div class="right-panel">
        <div class="right-content">
            <h2 class="panel-heading">Password Recovery</h2>

            <div class="login-card">
                <div class="text-center mb-3">
                    <i class="fas fa-envelope-open-text" style="font-size: 2.5rem; color: #4a6cf7;"></i>
                </div>
                <h4 class="text-center mb-3">Verify Recovery Code</h4>

                <p class="text-center text-muted mb-4" style="font-size: 0.88rem;">
                    We've sent a 6-digit confirmation code to <br><strong><?php echo htmlspecialchars($email); ?></strong>. <br>Please enter it below.
                </p>

                <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="mb-4">
                        <input type="text" class="form-control code-input" id="code" name="code" maxlength="6" pattern="\d*" placeholder="000000" autocomplete="one-time-code" required autofocus>
                    </div>

                    <button type="submit" class="btn btn-login w-100 mb-3">
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

            <p class="footer-text">&copy; <?php echo date('Y'); ?> Saint Michael College of Caraga | All Rights Reserved</p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
