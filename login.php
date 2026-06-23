<?php
session_start();
require_once 'config/database.php';
require_once __DIR__ .'/includes/ai_autostart.php';

// Redirect if already logged in
if(isset($_SESSION['user_id'])) {
    $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
    $role = str_replace(['-', ' '], '_', $role);

    if($role === 'edp') {
        header("Location: edp/dashboard.php");
    } elseif(in_array($role, ['president', 'vice_president', 'dean', 'principal'], true)) {
        header("Location: evaluators/dashboard.php");
    } elseif($role === 'teacher') {
        header("Location: teachers/dashboard.php");
    } elseif($role === 'chairperson') {
        header("Location: evaluators/chairperson.php");
    } elseif($role === 'subject_coordinator') {
        header("Location: evaluators/subject_coordinator.php");
    } elseif($role === 'grade_level_coordinator') {
        header("Location: evaluators/grade_level_coordinator.php");
    } else {
        header("Location: evaluators/dashboard.php");
    }
    exit();
}

$info = ['title' => 'Login', 'icon' => 'fas fa-sign-in-alt', 'color' => '#2a5298'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($info['title']); ?> - ADCES</title>
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

        /* Full-page blurred background */
        body::before {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: url('assets/img/smccnasipit_cover.jpg') no-repeat center center;
            background-size: cover;
            filter: blur(8px);
            transform: scale(1.05);
            z-index: 0;
        }

        body::after {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(circle at 72% 18%, rgba(44, 82, 130, 0.32), transparent 32%),
                radial-gradient(circle at 18% 82%, rgba(15, 160, 190, 0.30), transparent 34%),
                linear-gradient(135deg, rgba(3, 12, 28, 0.72), rgba(7, 22, 45, 0.38));
            z-index: 1;
        }

        /* Left Panel — logo side */
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
            width: 200px;
            height: auto;
            margin-bottom: 28px;
            filter: drop-shadow(0 8px 24px rgba(0,0,0,0.5));
            position: relative;
            z-index: 1;
        }

        .left-panel h1 {
            color: #fff;
            font-size: 2rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: 8px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.5);
            position: relative;
            z-index: 1;
        }

        .left-panel .system-name {
            color: rgba(255,255,255,0.85);
            font-size: 1.1rem;
            font-weight: 400;
            letter-spacing: 0.5px;
            text-align: center;
            margin-bottom: 6px;
            text-shadow: 0 1px 6px rgba(0,0,0,0.4);
            position: relative;
            z-index: 1;
        }

        .left-panel .address {
            color: rgba(255,255,255,0.6);
            font-size: 0.95rem;
            text-align: center;
            text-shadow: 0 1px 4px rgba(0,0,0,0.3);
            position: relative;
            z-index: 1;
        }

        /* Right Panel — login form */
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
            width: 100%;
            max-width: 420px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Login Card */
        .panel-heading {
            font-size: 1.5rem;
            font-weight: 700;
            color: #fff;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 20px;
            text-align: center;
            text-shadow: 0 2px 8px rgba(0,0,0,0.5);
        }

        .login-card {
            position: relative;
            overflow: hidden;
            background:
                linear-gradient(145deg, rgba(255,255,255,0.94), rgba(233,243,255,0.88)),
                radial-gradient(circle at top left, rgba(42,82,152,0.20), transparent 40%);
            border: 1px solid rgba(255,255,255,0.72);
            border-radius: 22px;
            padding: 40px 32px 30px;
            width: 100%;
            box-shadow: 0 24px 70px rgba(0,0,0,0.36);
            backdrop-filter: blur(18px);
        }

        .login-card::before {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 8px;
            background: linear-gradient(90deg, #0ea5c6, #2a5298, #4a6cf7);
        }

        .login-card::after {
            content: '';
            position: absolute;
            width: 170px;
            height: 170px;
            right: -82px;
            top: -82px;
            border-radius: 50%;
            background: rgba(42,82,152,0.12);
            pointer-events: none;
        }

        .role-icon-wrap {
            text-align: center;
            margin-bottom: 8px;
        }

        .role-icon-wrap .icon-circle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 52px; height: 52px;
            border-radius: 16px;
            font-size: 1.4rem;
            color: #fff;
            box-shadow: 0 10px 24px rgba(42,82,152,0.35);
        }

        .card-title {
            text-align: center;
            font-size: 1.2rem;
            font-weight: 700;
            color: #18345d;
            margin-bottom: 4px;
        }

        .card-subtitle {
            text-align: center;
            color: #6c757d;
            font-size: 0.85rem;
            margin-bottom: 22px;
        }

        /* Form fields */
        .form-floating {
            margin-bottom: 16px;
        }

        .form-floating .form-control {
            background: rgba(255,255,255,0.78);
            border: 1.5px solid rgba(42,82,152,0.16);
            border-radius: 14px;
            height: 50px;
            font-size: 0.95rem;
            padding: 16px 14px 6px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.8);
        }

        .form-floating .form-control:focus {
            border-color: #2a5298;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(42,82,152,0.14);
        }

        .form-floating label {
            color: #6c757d;
            font-size: 0.9rem;
        }

        /* Buttons */
        .btn-login {
            background: linear-gradient(135deg, #2a5298, #4a6cf7);
            border: none;
            padding: 12px;
            font-weight: 600;
            font-size: 1rem;
            border-radius: 14px;
            color: #fff;
            transition: all 0.25s;
            box-shadow: 0 10px 22px rgba(42,82,152,0.28);
        }

        .btn-login:hover {
            background: linear-gradient(135deg, #234987, #3b5de7);
            transform: translateY(-1px);
            box-shadow: 0 14px 28px rgba(42,82,152,0.36);
            color: #fff;
        }

        .btn-back {
            background: rgba(255,255,255,0.56);
            border: 1px solid rgba(42,82,152,0.16);
            padding: 11px;
            font-weight: 600;
            font-size: 0.95rem;
            border-radius: 14px;
            color: #2f4668;
            transition: all 0.25s;
        }

        .btn-back:hover {
            background: #fff;
            color: #1a2a44;
            box-shadow: 0 8px 18px rgba(42,82,152,0.12);
        }

        .forgot-link {
            color: #2a5298;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 700;
        }

        .forgot-link:hover {
            text-decoration: underline;
        }

        /* reCAPTCHA */
        .recaptcha-container {
            display: flex;
            justify-content: center;
            margin-bottom: 16px;
        }

        .g-recaptcha {
            filter: drop-shadow(0 8px 18px rgba(24,52,93,0.10));
        }

        /* Alert */
        .alert {
            border-radius: 10px;
            border: none;
            padding: 12px 15px;
            font-size: 0.9rem;
        }

        /* Footer */
        .footer-text {
            margin-top: 20px;
            color: rgba(255,255,255,0.55);
            font-size: 0.8rem;
            text-align: center;
        }

        /* Responsive */
        @media (max-width: 768px) {
            body { flex-direction: column; min-height: auto; }
            .left-panel { width: 100%; padding: 30px 20px; }
            .left-panel .logo-img { width: 100px; margin-bottom: 12px; }
            .left-panel h1 { font-size: 1.3rem; }
            .left-panel .system-name { font-size: 0.9rem; }
            .left-panel .address { font-size: 0.8rem; }
            .left-panel::before { width: 100%; background: rgba(10, 20, 40, 0.65); }
            .right-panel { width: 100%; padding: 24px 16px; }
            .login-card { padding: 28px 22px 24px; }
            .recaptcha-container { transform: scale(0.88); transform-origin: center; }
        }
    </style>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
</head>
<body>
    <!-- Left Panel -->
    <div class="left-panel">
        <img src="assets/img/SMCC_LOGO.webp" alt="SMCC Logo" class="logo-img">
        <h1>Saint Michael College of Caraga</h1>
        <p class="system-name">AI-Driven Classroom Evaluation System</p>
        <p class="address">Brgy. 4, Atupan St., Nasipit, Agusan del Norte</p>
    </div>

    <!-- Right Panel -->
    <div class="right-panel">
        <div class="right-content">
            <h2 class="panel-heading">AI-Driven Classroom Evaluation System</h2>
            <div class="login-card">
                <div class="role-icon-wrap">
                    <div class="icon-circle" style="background: <?php echo htmlspecialchars($info['color']); ?>;">
                        <i class="<?php echo htmlspecialchars($info['icon']); ?>"></i>
                    </div>
                </div>

                <div class="card-title"><?php echo htmlspecialchars($info['title']); ?></div>
                <div class="card-subtitle">Enter your credentials to access the system</div>

                <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if(isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <form action="auth/login-process.php" method="POST">
                    <div class="form-floating">
                        <input type="text" class="form-control" id="username" name="username" required placeholder="Username">
                        <label for="username">Username</label>
                    </div>

                    <div class="form-floating">
                        <input type="password" class="form-control" id="password" name="password" required placeholder="Password">
                        <label for="password">Password</label>
                    </div>

                    <div class="recaptcha-container">
                        <div class="g-recaptcha" data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"></div>
                    </div>

                    <div class="text-center mb-3">
                        <a href="forgot-password.php" class="forgot-link">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn btn-login w-100 mb-2">Login</button>
                    <a href="index.php" class="btn btn-back w-100">Back to Home</a>
                </form>
            </div>

            <p class="footer-text">&copy; <?php echo date('Y'); ?> Saint Michael College of Caraga | All Rights Reserved</p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
