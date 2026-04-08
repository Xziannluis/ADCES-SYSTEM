<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
require_once '../models/User.php';

if (!empty($_POST)) {
    // Preserve role for redirect on failure
    $role = isset($_POST['role']) ? preg_replace('/[^a-z_]/', '', $_POST['role']) : '';
    $roleParam = $role ? '?role=' . $role : '';

    $recaptchaSecret = RECAPTCHA_SECRET_KEY;
    $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';

    if (empty($recaptchaResponse)) {
        $_SESSION['error'] = 'Please complete the CAPTCHA.';
        header('Location: ../login.php' . $roleParam);
        exit();
    }

    $verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
    $data = [
        'secret' => $recaptchaSecret,
        'response' => $recaptchaResponse,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ];

    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'timeout' => 10
        ]
    ];

    $context = stream_context_create($options);
    $result = file_get_contents($verifyUrl, false, $context);
    $captchaSuccess = json_decode($result, true);

    if (empty($captchaSuccess['success'])) {
        $_SESSION['error'] = 'CAPTCHA verification failed. Try again.';
        header('Location: ../login.php' . $roleParam);
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();

    if(!$db) {
        $_SESSION['error'] = "Database connection is unavailable right now. Please make sure MySQL is running in XAMPP, then try again.";
        header("Location: ../login.php" . $roleParam);
        exit();
    }
    
    $user = new User($db);
    $user->username = $_POST['username'];
    $user->password = $_POST['password'];

    if($user->login()) {
    session_regenerate_id(true);
    $sessionRole = strtolower(trim((string)$user->role));
    $sessionRole = str_replace(['-', ' '], '_', $sessionRole);
    $sessionRole = preg_replace('/_+/', '_', $sessionRole);

    $_SESSION['user_id'] = $user->id;
    $_SESSION['username'] = $user->username;
    $_SESSION['role'] = $sessionRole; // normalized for consistency
        $_SESSION['department'] = $user->department;
        $_SESSION['name'] = $user->name;
        
        // Get teacher_id for any role that has a teacher record (teachers, evaluators who also teach)
        $teacher_query = "SELECT id FROM teachers WHERE user_id = :user_id LIMIT 1";
        $teacher_stmt = $db->prepare($teacher_query);
        $teacher_stmt->bindParam(':user_id', $user->id);
        $teacher_stmt->execute();
        if($teacher_stmt->rowCount() > 0) {
            $teacher_data = $teacher_stmt->fetch(PDO::FETCH_ASSOC);
            $_SESSION['teacher_id'] = $teacher_data['id'];
        }
            
        // Log the login
        $log_query = "INSERT INTO audit_logs (user_id, action, description, ip_address) 
                     VALUES (:user_id, 'LOGIN', 'User logged into the system', :ip_address)";
        $log_stmt = $db->prepare($log_query);
        $log_stmt->bindParam(':user_id', $user->id);
        $log_stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR']);
        $log_stmt->execute();
        
    $role = $sessionRole;
        if($role == 'edp') {
            header("Location: ../edp/dashboard.php");
        } elseif(in_array($role, ['president', 'vice_president'])) {
            header("Location: ../evaluators/dashboard.php");
        } elseif($role === 'teacher') {
            header("Location: ../teachers/dashboard.php");
        } elseif(in_array($role, ['dean', 'principal'])) {
            header("Location: ../evaluators/dashboard.php");
        } elseif($role === 'chairperson') {
            header("Location: ../evaluators/chairperson.php");
        } elseif($role === 'subject_coordinator') {
            header("Location: ../evaluators/subject_coordinator.php");
        } elseif($role === 'grade_level_coordinator') {
            header("Location: ../evaluators/grade_level_coordinator.php");
        } else {
            header("Location: ../evaluators/dashboard.php");
        }
        exit();
    } else {
        $_SESSION['error'] = "Invalid username or password. Please try again.";
        header("Location: ../login.php" . $roleParam);
        exit();
    }
} else {
    $_SESSION['error'] = "Invalid request method.";
    header("Location: ../login.php");
    exit();
}
?>