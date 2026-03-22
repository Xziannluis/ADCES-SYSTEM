<?php
require_once 'config/database.php';

$message = '';
$messageType = 'danger';

if (!empty($_GET['token'])) {
    $token = $_GET['token'];

    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("SELECT id, name, email FROM users WHERE verification_token = :token AND is_email_verified = 0 LIMIT 1");
    $stmt->bindParam(':token', $token);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $update = $db->prepare("UPDATE users SET is_email_verified = 1, verification_token = NULL WHERE id = :id");
        $update->bindParam(':id', $user['id']);

        if ($update->execute()) {
            $message = "Your email address (<strong>" . htmlspecialchars($user['email']) . "</strong>) has been verified successfully!";
            $messageType = 'success';
        } else {
            $message = "Unable to verify your email. Please try again.";
        }
    } else {
        $message = "Invalid or expired verification link. The email may have already been verified.";
    }
} else {
    $message = "No verification token provided.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - SMCC Evaluation System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-light">
    <div class="container d-flex align-items-center justify-content-center" style="min-height: 100vh;">
        <div class="card shadow" style="max-width: 500px; width: 100%;">
            <div class="card-body text-center p-5">
                <?php if ($messageType === 'success'): ?>
                    <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                <?php else: ?>
                    <i class="fas fa-exclamation-circle text-danger" style="font-size: 4rem;"></i>
                <?php endif; ?>
                <h4 class="mt-3">Email Verification</h4>
                <p class="mt-3"><?php echo $message; ?></p>
                <a href="login.php" class="btn btn-primary mt-3">
                    <i class="fas fa-sign-in-alt me-2"></i>Go to Login
                </a>
            </div>
        </div>
    </div>
</body>
</html>
