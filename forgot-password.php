<?php
session_start();
require_once 'config/database.php';
require_once 'includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $_SESSION['error'] = 'Please enter your email account.';
    } else {
        $db = (new Database())->getConnection();
        
        $query = "
            SELECT 
                u.id, 
                u.name, 
                u.username,
                COALESCE(t.email, u.email) as email, 
                COALESCE(t.email_verified, u.is_email_verified) as is_email_verified
            FROM users u
            LEFT JOIN teachers t ON u.id = t.user_id
            WHERE u.email = :email 
               OR u.username = :username 
               OR t.email = :email
            LIMIT 1
        ";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':username', $email);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $userEmail = !empty($user['email']) ? $user['email'] : '';
            
            if (empty($userEmail) || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['error'] = 'This account does not have a valid email address properly configured.';
            } else if (isset($user['is_email_verified']) && $user['is_email_verified'] != 1) {
                $_SESSION['error'] = 'Your email address is not verified. Password recovery is only available for verified accounts.';
            } else {
                // Generate 6-digit numeric code
                $code = sprintf("%06d", mt_rand(1, 999999));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                
                $update = $db->prepare("UPDATE users SET reset_token = :token, reset_token_expires = :expires WHERE id = :id");
                $update->execute([
                    ':token' => $code,
                    ':expires' => $expiresAt,
                    ':id' => $user['id']
                ]);
                
                sendPasswordResetCodeEmail($userEmail, $user['name'], $code, $expiresAt);
                
                $_SESSION['reset_email'] = $userEmail;
                $_SESSION['reset_user_id'] = $user['id'];
                header("Location: verify-reset.php");
                exit();
            }
        } else {
            $_SESSION['error'] = 'No account found with that email.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - AI Classroom Evaluation System</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
        }
        .login-body {
            background: url('smccnasipit_cover.jpg') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Arial, Helvetica, sans-serif;
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

        .btn-login { background: #073b5eff; border: none; padding: 12px; font-weight: 600; border-radius: 8px; transition: all 0.3s; }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 5px 15px #0a436aff; }
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
            <h4 class="text-center mb-4">Forgot Password</h4>
            
            <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="mb-4">
                    <p class="text-muted text-center" style="font-size: 0.95rem;">Enter the email associated with your account to receive a 6-digit recovery code.</p>
                    <label for="email" class="form-label" style="font-weight: 600;">Email Address or Username</label>
                    <input type="text" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required placeholder="user@gmail.com">
                </div>
                
                <button type="submit" class="btn btn-primary btn-login w-100 mb-3">
                    <i class="fas fa-paper-plane me-2"></i>Send Verification Code
                </button>
                
                <div class="text-center">
                    <a href="login.php" class="text-decoration-none" style="color: var(--secondary); font-weight: 500;">
                        <i class="fas fa-arrow-left me-1"></i> Back to Login
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
