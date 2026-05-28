<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Persist notification-email send attempt logs for audit/debugging.
 */
function logNotificationMailAttempt($recipientEmail, $recipientName, $subject, $messageText, $status, $errorMessage = '', $source = 'sendGenericNotificationEmail') {
    try {
        if (!class_exists('Database')) {
            require_once __DIR__ . '/../config/database.php';
        }
        $db = (new Database())->getConnection();
        if (!$db) {
            return false;
        }
        $stmt = $db->prepare(
            "INSERT INTO notification_mail_logs
             (recipient_email, recipient_name, subject, message_text, status, error_message, source)
             VALUES (:recipient_email, :recipient_name, :subject, :message_text, :status, :error_message, :source)"
        );
        $stmt->execute([
            ':recipient_email' => (string)$recipientEmail,
            ':recipient_name' => (string)$recipientName,
            ':subject' => (string)$subject,
            ':message_text' => (string)$messageText,
            ':status' => ($status === 'sent' ? 'sent' : 'failed'),
            ':error_message' => (string)$errorMessage,
            ':source' => (string)$source,
        ]);
        return true;
    } catch (Exception $e) {
        error_log('notification_mail_logs insert failed: ' . $e->getMessage());
        return false;
    }
}

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
        ? 'Evaluation Scheduled Today - ' . $teacherName
        : 'Evaluation Schedule Set - ' . $teacherName;

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
function notifyScheduleParticipants($db, $teacherId, $schedule, $room, $setterId, $setterName, $setterRole = '', $scheduledDepartment = '', $isReschedule = false, $reschedulePvpRecipients = []) {
    if (empty($schedule) && empty($room)) {
        return;
    }

    // Determine setter role if not provided
    if (empty($setterRole)) {
        try {
            $roleStmt = $db->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
            $roleStmt->execute([':id' => $setterId]);
            $setterRole = $roleStmt->fetchColumn() ?: '';
        } catch (PDOException $e) { $setterRole = ''; }
    }

    $isDeanOrPrincipal = in_array($setterRole, ['dean', 'principal']);
    $isCoordinatorOrChair = in_array($setterRole, ['chairperson', 'subject_coordinator', 'grade_level_coordinator']);
    $isPresidentOrVP = in_array($setterRole, ['president', 'vice_president']);

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
                $setterName,
                $isReschedule
            );
        }
        if ($tdata && !empty($tdata['user_id'])) {
            $teacherScheduleText = $schedule ? date('F d, Y \a\t h:i A', strtotime($schedule)) : 'TBA';
            $teacherRoomText = !empty($room) ? $room : 'TBA';
            $teacherTitle = $isReschedule ? 'Evaluation Schedule Rescheduled' : 'Evaluation Schedule Set';
            $teacherMsg = $isReschedule
                ? "Your evaluation schedule was rescheduled to {$teacherScheduleText} in {$teacherRoomText}. Updated by {$setterName}."
                : "Your evaluation is scheduled on {$teacherScheduleText} in {$teacherRoomText}. Set by {$setterName}.";
            $teacherNotif = $db->prepare(
                "INSERT INTO notifications (user_id, teacher_id, type, title, message, link)
                 VALUES (:user_id, :teacher_id, 'schedule', :title, :message, :link)"
            );
            $teacherNotif->execute([
                ':user_id' => (int)$tdata['user_id'],
                ':teacher_id' => (int)$teacherId,
                ':title' => $teacherTitle,
                ':message' => $teacherMsg,
                ':link' => 'observation_plan.php?view=my_observation',
            ]);
        }
        $teacherName = $tdata['name'] ?? 'Teacher';

        // 2. Find evaluators to notify based on setter's role:
        //    - Dean/Principal sets schedule → notify coordinators + chairperson (in setter's dept)
        //    - Coordinator/Chairperson sets schedule → notify dean/principal (in setter's dept)
        $targetRoles = [];
        if ($isDeanOrPrincipal) {
            $targetRoles = ['chairperson', 'subject_coordinator', 'grade_level_coordinator'];
        } elseif ($isCoordinatorOrChair) {
            $targetRoles = ['dean', 'principal'];
        } elseif ($isPresidentOrVP) {
            // President/VP → notify dean, principal, chairperson and coordinators of the scheduled department
            $targetRoles = ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator'];
        } else {
            // Fallback: notify all evaluator roles
            $targetRoles = ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator'];
        }

        // Get the department to scope notifications
        // For president/VP, use the scheduled department
        // For coordinators/deans, use the scheduled department if available, else setter's own department
        $setterDept = '';
        if (!empty($scheduledDepartment)) {
            $setterDept = $scheduledDepartment;
        } else {
            try {
                $sdStmt = $db->prepare("SELECT department FROM users WHERE id = :id LIMIT 1");
                $sdStmt->execute([':id' => $setterId]);
                $setterDept = $sdStmt->fetchColumn() ?: '';
            } catch (PDOException $e) {}
        }

        // Find evaluators assigned to this teacher who match the target roles
        $rolePlaceholders = implode(',', array_fill(0, count($targetRoles), '?'));
        $evalStmt = $db->prepare(
            "SELECT DISTINCT u.id, u.name, u.email, u.role
             FROM teacher_assignments ta
             JOIN users u ON u.id = ta.evaluator_id
             WHERE ta.teacher_id = ?
               AND u.role IN ($rolePlaceholders)
               AND u.status = 'active'
               AND (? = '' OR u.department = ?)
               AND u.id != ?
               AND u.email IS NOT NULL
               AND u.email != ''"
        );
        $evalParams = [$teacherId];
        foreach ($targetRoles as $r) { $evalParams[] = $r; }
        $evalParams[] = $setterDept;
        $evalParams[] = $setterDept;
        $evalParams[] = $setterId;
        $evalStmt->execute($evalParams);
        $assignedEvaluators = $evalStmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Also find department-level evaluators matching target roles
        //    Use setter's department so the right hierarchy is notified
        $deptEvaluators = [];
        if (!empty($setterDept)) {
            $deptEvalStmt = $db->prepare(
                "SELECT DISTINCT u.id, u.name, u.email, u.role
                 FROM users u
                 WHERE u.department = ?
                   AND u.role IN ($rolePlaceholders)
                   AND u.status = 'active'
                   AND u.id != ?
                   AND u.email IS NOT NULL
                   AND u.email != ''"
            );
            $deptParams = [$setterDept];
            foreach ($targetRoles as $r) { $deptParams[] = $r; }
            $deptParams[] = $setterId;
            $deptEvalStmt->execute($deptParams);
            $deptEvaluators = $deptEvalStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 4. Merge and deduplicate evaluators
        $notified = [];
        $allEvaluators = array_merge($assignedEvaluators, $deptEvaluators);

        // Guarantee reciprocal counterpart notifications for this specific teacher only:
        // - If coordinator/chair sets schedule, notify assigned dean account(s) for this teacher.
        // - If dean sets schedule, notify assigned coordinator/chair account(s) for this teacher.
        try {
            $counterpartRoles = [];
            if ($isCoordinatorOrChair) {
                $counterpartRoles = ['dean'];
            } elseif ($isDeanOrPrincipal) {
                $counterpartRoles = ['chairperson', 'subject_coordinator', 'grade_level_coordinator'];
            }

            if (!empty($counterpartRoles) && !empty($setterDept)) {
                $cpPlaceholders = implode(',', array_fill(0, count($counterpartRoles), '?'));

                // Only counterpart accounts explicitly assigned to this teacher.
                $cpAssignedStmt = $db->prepare(
                    "SELECT DISTINCT u.id, u.name, u.email, u.role
                     FROM teacher_assignments ta
                     JOIN users u ON u.id = ta.evaluator_id
                     WHERE ta.teacher_id = ?
                       AND u.department = ?
                       AND u.role IN ($cpPlaceholders)
                       AND u.status = 'active'
                       AND u.id != ?
                       AND u.email IS NOT NULL
                       AND u.email != ''"
                );
                $cpAssignedParams = [$teacherId, $setterDept];
                foreach ($counterpartRoles as $r) { $cpAssignedParams[] = $r; }
                $cpAssignedParams[] = $setterId;
                $cpAssignedStmt->execute($cpAssignedParams);
                $cpAssigned = $cpAssignedStmt->fetchAll(PDO::FETCH_ASSOC);

                $allEvaluators = array_merge($allEvaluators, $cpAssigned);
            }
        } catch (Exception $e) {}

        $formattedSchedule = $schedule ? date('F d, Y \a\t h:i A', strtotime($schedule)) : 'TBA';
        $notifTitle = "Evaluation Schedule Updated";
        $notifMessage = sprintf(
            "The evaluation for %s is scheduled on %s in %s. Set by %s.",
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
            $link = 'evaluators/teachers.php';
            $notifInsert->execute([
                ':user_id' => $uid,
                ':teacher_id' => $teacherId,
                ':title' => $notifTitle,
                ':message' => $notifMessage,
                ':link' => $link,
            ]);
        }

        // 4b. On reschedule, notify President/VP who had previously accepted as observer
        // so they can accept again for the new schedule time.
        if ($isReschedule && is_array($reschedulePvpRecipients) && !empty($reschedulePvpRecipients)) {
            $reschedTitle = "Schedule Rescheduled — Observer Re-acceptance Needed";
            $reschedMessage = sprintf(
                "%s's schedule was rescheduled to %s in %s by %s. Please accept again as observer if you are available.",
                $teacherName,
                $formattedSchedule,
                $room ?: 'TBA',
                $setterName
            );
            $reschedNotif = $db->prepare(
                "INSERT INTO notifications (user_id, teacher_id, type, title, message, link) VALUES (:user_id, :teacher_id, 'schedule', :title, :message, :link)"
            );
            foreach ($reschedulePvpRecipients as $pvp) {
                $pvpId = (int)($pvp['id'] ?? 0);
                if ($pvpId <= 0 || isset($notified[$pvpId])) continue;
                $notified[$pvpId] = true;

                $pvpName = trim((string)($pvp['name'] ?? 'Observer'));
                $pvpEmail = trim((string)($pvp['email'] ?? ''));

                if ($pvpEmail !== '') {
                    sendGenericNotificationEmail(
                        $pvpEmail,
                        $pvpName !== '' ? $pvpName : 'Observer',
                        $reschedTitle,
                        $reschedMessage
                    );
                }

                $reschedNotif->execute([
                    ':user_id' => $pvpId,
                    ':teacher_id' => (int)$teacherId,
                    ':title' => $reschedTitle,
                    ':message' => $reschedMessage,
                    ':link' => 'evaluators/observation_plan.php',
                ]);
            }
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

function sendScheduleNotificationEmail($toEmail, $teacherName, $schedule, $room, $setterName, $isReschedule = false) {
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

    if ($isReschedule) {
        $subject = 'Evaluation Schedule Rescheduled';
        $headline = 'Your classroom evaluation schedule has been rescheduled.';
    } else {
        $subject = $scheduleDate === $today
            ? 'Evaluation Schedule Today'
            : 'Evaluation Schedule Set';
        $headline = $scheduleDate === $today
            ? 'You have a classroom evaluation scheduled today.'
            : 'Your classroom evaluation schedule has been set.';
    }

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
    $expiryMinutes = 10;
    if (!empty($expiresAt)) {
        $delta = strtotime((string)$expiresAt) - time();
        if ($delta > 0) {
            $expiryMinutes = max(1, (int)ceil($delta / 60));
        }
    }

    $subject = 'Verify your email address';
    $safeName = htmlspecialchars((string)$teacherName, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars((string)$code, ENT_QUOTES, 'UTF-8');
    $safeExpiry = htmlspecialchars((string)$formattedExpiry, ENT_QUOTES, 'UTF-8');
    $body = '<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Email Verification</title>
</head>
<body style="margin:0;padding:0;background:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2f7;padding:28px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:18px;overflow:hidden;">
          <tr>
            <td style="padding:0;background:#ffffff;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td style="width:10px;background:#0f766e;"></td>
                  <td style="padding:24px 24px 16px;">
                    <p style="margin:0;font-size:12px;font-weight:700;letter-spacing:1px;color:#0f766e;">SAINT MICHAEL COLLEGE OF CARAGA</p>
                    <h1 style="margin:8px 0 0;font-size:28px;line-height:1.2;color:#0f172a;">Confirm Your Email Address</h1>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 24px 0;">
              <p style="margin:0 0 10px;font-size:16px;">Hi ' . $safeName . ',</p>
              <p style="margin:0;font-size:16px;line-height:1.65;color:#374151;">
                Use the one-time code below to verify your account in the AI Classroom Evaluation System.
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:20px 24px 8px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;border:1px solid #dbeafe;border-radius:14px;">
                <tr>
                  <td style="padding:12px 14px 6px;font-size:12px;font-weight:700;color:#475569;letter-spacing:0.8px;">VERIFICATION CODE</td>
                </tr>
                <tr>
                  <td align="center" style="padding:8px 14px 16px;">
                    <div style="display:inline-block;background:#ffffff;border:2px dashed #0ea5e9;border-radius:10px;padding:14px 18px;">
                      <span style="font-size:38px;font-weight:700;color:#0f172a;letter-spacing:10px;">' . $safeCode . '</span>
                    </div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 24px 4px;">
              <p style="margin:0;font-size:15px;line-height:1.6;color:#334155;">
                Expires in <strong>' . (int)$expiryMinutes . ' minute' . ((int)$expiryMinutes === 1 ? '' : 's') . '</strong>
                <span style="color:#64748b;">(until ' . $safeExpiry . ')</span>
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:14px 24px 24px;">
              <p style="margin:0;font-size:14px;line-height:1.6;color:#64748b;">
                If you did not request this code, you can ignore this message.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
    $altBody = "Hello {$teacherName},\n\n"
        . "Please use this verification code to confirm your email address: {$code}\n\n"
        . "This code expires in {$expiryMinutes} minute" . ($expiryMinutes === 1 ? '' : 's') . ".\n"
        . "Expiry time: {$formattedExpiry}\n\n"
        . "If you did not request this code, you can ignore this email.";

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
        $mail->AltBody = $altBody;

        return $mail->send();
    } catch (Exception $e) {
        error_log('Mailer error: ' . $mail->ErrorInfo);
        return false;
    }
}

function sendEmailVerifiedSuccessEmail($toEmail, $userName) {
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

    $safeName = htmlspecialchars((string)$userName, ENT_QUOTES, 'UTF-8');
    $subject = 'Email Verification Successful';
    $body = '<!doctype html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Email Verified</title></head>
<body style="margin:0;padding:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;">
        <tr><td style="background:#0f766e;padding:18px 22px;color:#fff;font-size:20px;font-weight:700;">AI Classroom Evaluation System</td></tr>
        <tr><td style="padding:24px 22px;">
          <p style="margin:0 0 12px;font-size:16px;">Hi ' . $safeName . ',</p>
          <p style="margin:0 0 14px;font-size:16px;line-height:1.6;">Your email address has been successfully verified.</p>
          <p style="margin:0 0 10px;font-size:15px;color:#475569;">You can now receive schedule and evaluation notifications by email.</p>
          <p style="margin:14px 0 0;font-size:14px;color:#64748b;">If you did not perform this action, please change your password immediately.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';
    $altBody = "Hi {$userName},\n\nYour email address has been successfully verified.\nYou can now receive schedule and evaluation notifications by email.\n\nIf you did not perform this action, please change your password immediately.";

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
        $mail->AltBody = $altBody;
        return $mail->send();
    } catch (Exception $e) {
        error_log('Mailer error (verification success): ' . $mail->ErrorInfo);
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
    
    $formattedExpiry = $expiresAt ? date('F d, Y \a\t h:i A', strtotime($expiresAt)) : 'soon';
    $expiryMinutes = 10;
    if (!empty($expiresAt)) {
        $delta = strtotime((string)$expiresAt) - time();
        if ($delta > 0) {
            $expiryMinutes = max(1, (int)ceil($delta / 60));
        }
    }

    $subject = 'Your Password Reset Code';
    $safeName = htmlspecialchars((string)$userName, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars((string)$code, ENT_QUOTES, 'UTF-8');
    $safeExpiry = htmlspecialchars((string)$formattedExpiry, ENT_QUOTES, 'UTF-8');
    $body = '<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Password Reset</title>
</head>
<body style="margin:0;padding:0;background:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2f7;padding:28px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:18px;overflow:hidden;">
          <tr>
            <td style="padding:0;background:#ffffff;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td style="width:10px;background:#0f766e;"></td>
                  <td style="padding:24px 24px 16px;">
                    <p style="margin:0;font-size:12px;font-weight:700;letter-spacing:1px;color:#0f766e;">SAINT MICHAEL COLLEGE OF CARAGA</p>
                    <h1 style="margin:8px 0 0;font-size:28px;line-height:1.2;color:#0f172a;">Reset Your Password</h1>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 24px 0;">
              <p style="margin:0 0 10px;font-size:16px;">Hi ' . $safeName . ',</p>
              <p style="margin:0;font-size:16px;line-height:1.65;color:#374151;">
                Use the one-time code below to continue resetting your password in the AI Classroom Evaluation System.
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:20px 24px 8px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;border:1px solid #dbeafe;border-radius:14px;">
                <tr>
                  <td style="padding:12px 14px 6px;font-size:12px;font-weight:700;color:#475569;letter-spacing:0.8px;">PASSWORD RESET CODE</td>
                </tr>
                <tr>
                  <td align="center" style="padding:8px 14px 16px;">
                    <div style="display:inline-block;background:#ffffff;border:2px dashed #0ea5e9;border-radius:10px;padding:14px 18px;">
                      <span style="font-size:38px;font-weight:700;color:#0f172a;letter-spacing:10px;">' . $safeCode . '</span>
                    </div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 24px 4px;">
              <p style="margin:0;font-size:15px;line-height:1.6;color:#334155;">
                Expires in <strong>' . (int)$expiryMinutes . ' minute' . ((int)$expiryMinutes === 1 ? '' : 's') . '</strong>
                <span style="color:#64748b;">(until ' . $safeExpiry . ')</span>
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:14px 24px 24px;">
              <p style="margin:0;font-size:14px;line-height:1.6;color:#64748b;">
                If you did not request this code, you can safely ignore this email.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
    $altBody = "Hello {$userName},\n\n"
        . "Please use this password reset code to continue: {$code}\n\n"
        . "This code expires in {$expiryMinutes} minute" . ($expiryMinutes === 1 ? '' : 's') . ".\n"
        . "Expiry time: {$formattedExpiry}\n\n"
        . "If you did not request this code, you can safely ignore this email.";

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
        $mail->AltBody = $altBody;
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

/**
 * Send a generic notification email (used for observer acceptance, etc.)
 */
function sendGenericNotificationEmail($toEmail, $recipientName, $subject, $messageText) {
    $configPath = __DIR__ . '/../config/mail.php';
    if (!file_exists($configPath)) {
        error_log('Mailer config missing at ' . $configPath);
        logNotificationMailAttempt($toEmail, $recipientName, $subject, $messageText, 'failed', 'Mailer config missing', 'sendGenericNotificationEmail');
        return false;
    }

    $config = require $configPath;
    if (empty($config['enabled'])) {
        logNotificationMailAttempt($toEmail, $recipientName, $subject, $messageText, 'failed', 'Mailer disabled', 'sendGenericNotificationEmail');
        return false;
    }

    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        error_log('Composer autoload not found at ' . $autoloadPath);
        logNotificationMailAttempt($toEmail, $recipientName, $subject, $messageText, 'failed', 'Composer autoload not found', 'sendGenericNotificationEmail');
        return false;
    }
    require_once $autoloadPath;

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = $config['smtp_auth'] ?? true;
        $mail->Username   = $config['username'];
        $mail->Password   = $config['password'];
        $mail->SMTPSecure = $config['encryption'] ?? 'tls';
        $mail->Port       = $config['port'] ?? 587;
        $mail->setFrom($config['from_email'], $config['from_name']);

        $mail->addAddress($toEmail, $recipientName);
        $mail->isHTML(true);
        $mail->Subject = $subject;

        $body = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">';
        $body .= '<h2 style="color:#1a73e8;">' . htmlspecialchars($subject) . '</h2>';
        $body .= '<p>Dear ' . htmlspecialchars($recipientName) . ',</p>';
        $body .= '<p>' . htmlspecialchars($messageText) . '</p>';
        $body .= '<hr style="border:none;border-top:1px solid #eee;margin:20px 0;">';
        $body .= '<p style="color:#666;font-size:12px;">This is an automated notification from the ADCES System.</p>';
        $body .= '</div>';

        $mail->Body = $body;
        $mail->AltBody = strip_tags($messageText);

        $ok = $mail->send();
        logNotificationMailAttempt($toEmail, $recipientName, $subject, $messageText, $ok ? 'sent' : 'failed', $ok ? '' : (string)$mail->ErrorInfo, 'sendGenericNotificationEmail');
        return $ok;
    } catch (Exception $e) {
        error_log('Mailer error (generic notification): ' . $mail->ErrorInfo);
        logNotificationMailAttempt($toEmail, $recipientName, $subject, $messageText, 'failed', (string)$mail->ErrorInfo, 'sendGenericNotificationEmail');
        return false;
    }
}

/**
 * Notify evaluators when a teacher signs the observation plan.
 * Notifies all evaluator roles (dean, principal, chairperson, subject_coordinator, grade_level_coordinator)
 * only in the specific department(s) where the signed schedules belong.
 * @param array $departments — the department(s) of the signed schedules
 */
function notifyObservationPlanSigned($db, $teacherId, $teacherName, $departments = []) {
    try {
        // If no departments specified, fall back to teacher's primary department
        if (empty($departments)) {
            $deptStmt = $db->prepare("SELECT department FROM teachers WHERE id = :id LIMIT 1");
            $deptStmt->execute([':id' => $teacherId]);
            $primaryDept = $deptStmt->fetchColumn();
            if (!empty($primaryDept)) {
                $departments = [$primaryDept];
            }
        }

        if (empty($departments)) return;

        // Find all evaluator-role users in those departments
        $placeholders = implode(',', array_fill(0, count($departments), '?'));
        $evalStmt = $db->prepare(
            "SELECT DISTINCT u.id, u.name, u.email, u.role
             FROM users u
             WHERE u.department IN ($placeholders)
               AND u.role IN ('dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator')
               AND u.status = 'active'
               AND u.email IS NOT NULL
               AND u.email != ''"
        );
        $evalStmt->execute(array_values($departments));
        $evaluators = $evalStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($evaluators)) return;

        $notifTitle = "Observation Plan Signed — {$teacherName}";
        $notifMessage = "{$teacherName} has signed the observation plan.";

        // In-app notification
        $notifInsert = $db->prepare(
            "INSERT INTO notifications (user_id, teacher_id, type, title, message, link) VALUES (:user_id, :teacher_id, 'observation_signed', :title, :message, :link)"
        );

        // Email config
        $configPath = __DIR__ . '/../config/mail.php';
        $mailConfig = file_exists($configPath) ? require($configPath) : [];
        $mailEnabled = !empty($mailConfig['enabled']);
        $autoloadPath = __DIR__ . '/../vendor/autoload.php';
        if ($mailEnabled && file_exists($autoloadPath)) {
            require_once $autoloadPath;
        }

        foreach ($evaluators as $ev) {
            // In-app notification
            $link = 'evaluators/observation_plan.php';
            $notifInsert->execute([
                ':user_id' => $ev['id'],
                ':teacher_id' => $teacherId,
                ':title' => $notifTitle,
                ':message' => $notifMessage,
                ':link' => $link,
            ]);

            // Email notification
            if ($mailEnabled && !empty($ev['email'])) {
                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = $mailConfig['host'] ?? '';
                    $mail->Port = (int)($mailConfig['port'] ?? 587);
                    $mail->SMTPAuth = !empty($mailConfig['smtp_auth']);
                    $mail->Username = $mailConfig['username'] ?? '';
                    $mail->Password = $mailConfig['password'] ?? '';
                    $mail->SMTPSecure = $mailConfig['encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS;

                    $mail->setFrom($mailConfig['from_email'] ?? 'no-reply@example.com', $mailConfig['from_name'] ?? 'SMCC Evaluation System');
                    $mail->addAddress($ev['email'], $ev['name'] ?? 'Evaluator');

                    $body = "<p>Hi {$ev['name']},</p>";
                    $body .= "<p><strong>{$teacherName}</strong> has signed the observation plan.</p>";
                    $body .= "<p>You may now proceed with the classroom evaluation.</p>";

                    $mail->isHTML(true);
                    $mail->Subject = $notifTitle;
                    $mail->Body = $body;
                    $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n"], $body));
                    $mail->send();
                } catch (Exception $e) {
                    error_log('Mailer error (observation plan signed): ' . $e->getMessage());
                }
            }
        }

        // Audit log
        $tUserStmt = $db->prepare("SELECT user_id FROM teachers WHERE id = :id LIMIT 1");
        $tUserStmt->execute([':id' => $teacherId]);
        $tUserId = $tUserStmt->fetchColumn();
        if ($tUserId) {
            $logStmt = $db->prepare(
                "INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES (:user_id, :action, :description, :ip)"
            );
            $logStmt->execute([
                ':user_id' => $tUserId,
                ':action' => 'OBSERVATION_PLAN_SIGNED',
                ':description' => "{$teacherName} signed the observation plan.",
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
        }
    } catch (Exception $e) {
        error_log('notifyObservationPlanSigned error: ' . $e->getMessage());
    }
}
