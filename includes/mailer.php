<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send schedule notification email to an evaluator (dean, chairperson, principal, subject_coordinator, etc.)
 * Tells them that a teacher under their supervision has a scheduled evaluation.
 */
function sendScheduleNotificationToEvaluator($toEmail, $evaluatorName, $teacherName, $schedule, $room, $setterName) {
    $configPath = __DIR__ . '/../config/mail.php';
    if (!file_exists($configPath)) {
        error_log('Mailer config missing at ' . $configPath);
        return false;
    }

    $config = require $configPath;
    if (empty($config['enabled'])) {
        return false;
    }

    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        error_log('Composer autoload not found at ' . $autoloadPath);
        return false;
    }
    require_once $autoloadPath;

    $formattedSchedule = $schedule ? date('F d, Y \a\t h:i A', strtotime($schedule)) : 'To be announced';
    $scheduleDate = $schedule ? date('Y-m-d', strtotime($schedule)) : '';
    $today = date('Y-m-d');

    $subject = $scheduleDate === $today
        ? 'Evaluation Schedule Today — ' . $teacherName
        : 'Evaluation Schedule Set — ' . $teacherName;

    $headline = $scheduleDate === $today
        ? 'A classroom evaluation is scheduled today for a teacher under your supervision.'
        : 'A classroom evaluation schedule has been set for a teacher under your supervision.';

    $roomLine = $room ? "Room: {$room}" : 'Room: To be announced';

    $body = "<p>Hi {$evaluatorName},</p>";
    $body .= "<p>{$headline}</p>";
    $body .= "<p><strong>Teacher:</strong> {$teacherName}<br>";
    $body .= "<strong>Date & Time:</strong> {$formattedSchedule}<br>";
    $body .= "<strong>{$roomLine}</strong></p>";
    $body .= "<p>Set by: {$setterName}</p>";
    $body .= "<p>Please coordinate with the teacher for the evaluation.</p>";

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $config['host'] ?? '';
        $mail->Port = (int)($config['port'] ?? 587);
        $mail->SMTPAuth = !empty($config['smtp_auth']);
        $mail->Username = $config['username'] ?? '';
        $mail->Password = $config['password'] ?? '';
        $mail->SMTPSecure = $config['encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS;

        $fromEmail = $config['from_email'] ?? 'no-reply@example.com';
        $fromName = $config['from_name'] ?? 'SMCC Evaluation System';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $evaluatorName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n"], $body));

        return $mail->send();
    } catch (Exception $e) {
        error_log('Mailer error (evaluator schedule notification): ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Notify all relevant parties when a schedule is set:
 * 1. The teacher being evaluated (if they have a verified email)
 * 2. All evaluators assigned to this teacher (dean, chairperson, principal, subject_coordinator, etc.)
 *    — except the person who just set the schedule (they already know)
 *
 * Works for both Higher Ed (Dean + Chairperson) and Basic Ed (Principal + Subject Coordinator).
 */
function notifyScheduleParticipants($db, $teacherId, $schedule, $room, $setterId, $setterName) {
    if (empty($schedule) && empty($room)) {
        return;
    }

    try {
        // 1. Get teacher info and send them an email
        $tq = $db->prepare("SELECT user_id, name, email FROM teachers WHERE id = :id LIMIT 1");
        $tq->execute([':id' => $teacherId]);
        $tdata = $tq->fetch(PDO::FETCH_ASSOC);

        if ($tdata && !empty($tdata['email'])) {
            sendScheduleNotificationEmail(
                $tdata['email'],
                $tdata['name'] ?? 'Teacher',
                $schedule,
                $room,
                $setterName
            );
        }
        $teacherName = $tdata['name'] ?? 'Teacher';

        // 2. Find all evaluators assigned to this teacher via teacher_assignments
        $evalStmt = $db->prepare(
            "SELECT DISTINCT u.id, u.name, u.email, u.role
             FROM teacher_assignments ta
             JOIN users u ON u.id = ta.evaluator_id
             WHERE ta.teacher_id = :teacher_id
               AND u.status = 'active'
               AND u.id != :setter_id
               AND u.email IS NOT NULL
               AND u.email != ''"
        );
        $evalStmt->execute([':teacher_id' => $teacherId, ':setter_id' => $setterId]);
        $assignedEvaluators = $evalStmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Also find department-level evaluators (dean/principal) for this teacher's department
        //    who may not be in teacher_assignments but still oversee the department
        $teacherDept = null;
        $deptStmt = $db->prepare("SELECT department FROM teachers WHERE id = :id LIMIT 1");
        $deptStmt->execute([':id' => $teacherId]);
        $teacherDept = $deptStmt->fetchColumn();

        $deptEvaluators = [];
        if (!empty($teacherDept)) {
            $deptEvalStmt = $db->prepare(
                "SELECT DISTINCT u.id, u.name, u.email, u.role
                 FROM users u
                 WHERE u.department = :dept
                   AND u.role IN ('dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator')
                   AND u.status = 'active'
                   AND u.id != :setter_id
                   AND u.email IS NOT NULL
                   AND u.email != ''"
            );
            $deptEvalStmt->execute([':dept' => $teacherDept, ':setter_id' => $setterId]);
            $deptEvaluators = $deptEvalStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 4. Merge and deduplicate evaluators
        $notified = [];
        $allEvaluators = array_merge($assignedEvaluators, $deptEvaluators);

        $formattedSchedule = $schedule ? date('F d, Y \a\t h:i A', strtotime($schedule)) : 'TBA';
        $notifTitle = "Evaluation Schedule Set — {$teacherName}";
        $notifMessage = sprintf(
            "Schedule set for %s on %s in %s. Set by %s.",
            $teacherName,
            $formattedSchedule,
            $room ?: 'TBA',
            $setterName
        );

        // Prepare in-app notification insert
        $notifInsert = $db->prepare(
            "INSERT INTO notifications (user_id, teacher_id, type, title, message, link) VALUES (:user_id, :teacher_id, 'schedule', :title, :message, :link)"
        );

        foreach ($allEvaluators as $evaluator) {
            $uid = $evaluator['id'];
            if (isset($notified[$uid])) {
                continue;
            }
            $notified[$uid] = true;

            // Send email notification
            if (!empty($evaluator['email'])) {
                sendScheduleNotificationToEvaluator(
                    $evaluator['email'],
                    $evaluator['name'] ?? 'Evaluator',
                    $teacherName,
                    $schedule,
                    $room,
                    $setterName
                );
            }

            // Insert in-app notification
            $link = in_array($evaluator['role'] ?? '', ['president', 'vice_president'])
                ? 'leaders/teachers.php'
                : 'evaluators/teachers.php';
            $notifInsert->execute([
                ':user_id' => $uid,
                ':teacher_id' => $teacherId,
                ':title' => $notifTitle,
                ':message' => $notifMessage,
                ':link' => $link,
            ]);
        }

        // 5. Audit log
        if ($tdata && !empty($tdata['user_id'])) {
            $description = sprintf(
                "Schedule set for %s: %s in %s. Set by %s.",
                $teacherName,
                $schedule ?: 'N/A',
                $room ?: 'N/A',
                $setterName
            );
            $logStmt = $db->prepare(
                "INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES (:user_id, :action, :description, :ip)"
            );
            $logStmt->execute([
                ':user_id' => $tdata['user_id'],
                ':action' => 'SCHEDULE_ASSIGNED',
                ':description' => $description,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
        }
    } catch (Exception $e) {
        error_log('notifyScheduleParticipants error: ' . $e->getMessage());
    }
}

function sendScheduleNotificationEmail($toEmail, $teacherName, $schedule, $room, $setterName) {
    $configPath = __DIR__ . '/../config/mail.php';
    if (!file_exists($configPath)) {
        error_log('Mailer config missing at ' . $configPath);
        return false;
    }

    $config = require $configPath;
    if (empty($config['enabled'])) {
        return false;
    }

    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        error_log('Composer autoload not found at ' . $autoloadPath);
        return false;
    }
    require_once $autoloadPath;

    $formattedSchedule = $schedule ? date('F d, Y \a\t h:i A', strtotime($schedule)) : 'To be announced';
    $scheduleDate = $schedule ? date('Y-m-d', strtotime($schedule)) : '';
    $today = date('Y-m-d');

    $subject = $scheduleDate === $today
        ? 'Evaluation Schedule Today'
        : 'Evaluation Schedule Set';

    $headline = $scheduleDate === $today
        ? 'You have a classroom evaluation scheduled today.'
        : 'Your classroom evaluation schedule has been set.';

    $roomLine = $room ? "Room: {$room}" : 'Room: To be announced';

    $body = "<p>Hi {$teacherName},</p>";
    $body .= "<p>{$headline}</p>";
    $body .= "<p><strong>Date & Time:</strong> {$formattedSchedule}<br><strong>{$roomLine}</strong></p>";
    $body .= "<p>Set by: {$setterName}</p>";
    $body .= "<p>Please prepare for your evaluation schedule.</p>";

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $config['host'] ?? '';
        $mail->Port = (int)($config['port'] ?? 587);
        $mail->SMTPAuth = !empty($config['smtp_auth']);
        $mail->Username = $config['username'] ?? '';
        $mail->Password = $config['password'] ?? '';
        $mail->SMTPSecure = $config['encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS;

        $fromEmail = $config['from_email'] ?? 'no-reply@example.com';
        $fromName = $config['from_name'] ?? 'SMCC Evaluation System';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $teacherName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags(str_replace('<br>', "\n", $body));

        return $mail->send();
    } catch (Exception $e) {
        error_log('Mailer error: ' . $mail->ErrorInfo);
        return false;
    }
}

function sendEmailVerificationCode($toEmail, $teacherName, $code, $expiresAt) {
    $configPath = __DIR__ . '/../config/mail.php';
    if (!file_exists($configPath)) {
        error_log('Mailer config missing at ' . $configPath);
        return false;
    }

    $config = require $configPath;
    if (empty($config['enabled'])) {
        return false;
    }

    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        error_log('Composer autoload not found at ' . $autoloadPath);
        return false;
    }
    require_once $autoloadPath;

    $formattedExpiry = $expiresAt ? date('F d, Y \a\t h:i A', strtotime($expiresAt)) : 'soon';

    $subject = 'Verify your email address';
    $body = "<p>Hi {$teacherName},</p>";
    $body .= "<p>Your email verification code is:</p>";
    $body .= "<h2 style=\"letter-spacing:2px;\">{$code}</h2>";
    $body .= "<p>This code expires on <strong>{$formattedExpiry}</strong>.</p>";
    $body .= "<p>If you did not request this, you can ignore this email.</p>";

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $config['host'] ?? '';
        $mail->Port = (int)($config['port'] ?? 587);
        $mail->SMTPAuth = !empty($config['smtp_auth']);
        $mail->Username = $config['username'] ?? '';
        $mail->Password = $config['password'] ?? '';
        $mail->SMTPSecure = $config['encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS;

        $fromEmail = $config['from_email'] ?? 'no-reply@example.com';
        $fromName = $config['from_name'] ?? 'SMCC Evaluation System';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $teacherName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags(str_replace('<br>', "\n", $body));

        return $mail->send();
    } catch (Exception $e) {
        error_log('Mailer error: ' . $mail->ErrorInfo);
        return false;
    }
}

function sendVerificationLinkEmail($toEmail, $userName, $link) {
    $configPath = __DIR__ . '/../config/mail.php';
    if (!file_exists($configPath)) {
        error_log('Mailer config missing at ' . $configPath);
        return false;
    }

    $config = require $configPath;
    if (empty($config['enabled'])) {
        return false;
    }

    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        error_log('Composer autoload not found at ' . $autoloadPath);
        return false;
    }
    require_once $autoloadPath;

    $subject = 'Verify your email address';
    $body = "<p>Hi {$userName},</p>";
    $body .= "<p>Please click the link below to verify your email address:</p>";
    $body .= "<p><a href=\"" . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . "\" style=\"display:inline-block;padding:10px 20px;background-color:#3498db;color:#fff;text-decoration:none;border-radius:5px;\">Verify Email</a></p>";
    $body .= "<p>Or copy and paste this URL into your browser:</p>";
    $body .= "<p>" . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . "</p>";
    $body .= "<p>If you did not request this, you can ignore this email.</p>";

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $config['host'] ?? '';
        $mail->Port = (int)($config['port'] ?? 587);
        $mail->SMTPAuth = !empty($config['smtp_auth']);
        $mail->Username = $config['username'] ?? '';
        $mail->Password = $config['password'] ?? '';
        $mail->SMTPSecure = $config['encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS;

        $fromEmail = $config['from_email'] ?? 'no-reply@example.com';
        $fromName = $config['from_name'] ?? 'SMCC Evaluation System';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $userName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n"], $body));

        return $mail->send();
    } catch (Exception $e) {
        error_log('Mailer error: ' . $mail->ErrorInfo);
        return false;
    }
}

function sendPasswordResetCodeEmail($toEmail, $userName, $code, $expiresAt) {
    $configPath = __DIR__ . '/../config/mail.php';
    if (!file_exists($configPath)) return false;
    $config = require $configPath;
    if (empty($config['enabled'])) return false;

    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoloadPath)) require_once $autoloadPath;
    
    $formattedExpiry = $expiresAt ? date('F d, Y \a\t h:i A', strtotime($expiresAt)) : '15 minutes';

    $subject = 'Your Password Reset Code';
    $body = "<p>Hi {$userName},</p>";
    $body .= "<p>You requested a password reset. Your 6-digit verification code is:</p>";
    $body .= "<h2 style=\"letter-spacing:5px; color:#3498db; font-size:24px; font-weight:bold;\">{$code}</h2>";
    $body .= "<p>This code expires on <strong>{$formattedExpiry}</strong>.</p>";
    $body .= "<p>If you did not request a password reset, you can safely ignore this email.</p>";

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $config['host'] ?? '';
        $mail->Port = (int)($config['port'] ?? 587);
        $mail->SMTPAuth = !empty($config['smtp_auth']);
        $mail->Username = $config['username'] ?? '';
        $mail->Password = $config['password'] ?? '';
        $mail->SMTPSecure = $config['encryption'] ?? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

        $mail->setFrom($config['from_email'] ?? 'no-reply@example.com', $config['from_name'] ?? 'SMCC Evaluation System');
        $mail->addAddress($toEmail, $userName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        return $mail->send();
    } catch (Exception $e) {
        return false;
    }
}

function sendEvaluationCompletedEmail($toEmail, $teacherName, $evaluatorName, $evaluatorRole, $observationDate) {
    $configPath = __DIR__ . '/../config/mail.php';
    if (!file_exists($configPath)) {
        error_log('Mailer config missing at ' . $configPath);
        return false;
    }

    $config = require $configPath;
    if (empty($config['enabled'])) {
        return false;
    }

    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        error_log('Composer autoload not found at ' . $autoloadPath);
        return false;
    }
    require_once $autoloadPath;

    $formattedRole = ucwords(str_replace('_', ' ', $evaluatorRole));
    $formattedDate = $observationDate ? date('F d, Y', strtotime($observationDate)) : date('F d, Y');

    $subject = 'Classroom Evaluation Completed';
    $body = "<p>Hi {$teacherName},</p>";
    $body .= "<p>Your classroom evaluation has been completed.</p>";
    $body .= "<p><strong>Evaluated by:</strong> {$evaluatorName} ({$formattedRole})<br>";
    $body .= "<strong>Date of Observation:</strong> {$formattedDate}</p>";
    $body .= "<p>You may view your evaluation results by logging in to the system.</p>";

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $config['host'] ?? '';
        $mail->Port = (int)($config['port'] ?? 587);
        $mail->SMTPAuth = !empty($config['smtp_auth']);
        $mail->Username = $config['username'] ?? '';
        $mail->Password = $config['password'] ?? '';
        $mail->SMTPSecure = $config['encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS;

        $fromEmail = $config['from_email'] ?? 'no-reply@example.com';
        $fromName = $config['from_name'] ?? 'SMCC Evaluation System';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $teacherName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n"], $body));

        return $mail->send();
    } catch (Exception $e) {
        error_log('Mailer error: ' . $mail->ErrorInfo);
        return false;
    }
}
