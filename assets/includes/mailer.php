<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

    $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
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
