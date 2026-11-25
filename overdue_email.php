<?php
/** Send one Gmail SMTP alert per task and due date. */
function app_send_overdue_email(mysqli $conn, int $userId, string $taskTitle, string $deadline, int $taskId, ?string $deadlineTime = null): bool
{
    $configPath = __DIR__ . '/../config/mail.local.php';
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($configPath) || !is_file($autoloadPath)) return false;

    $config = require $configPath;
    if (!is_array($config) || empty($config['gmail_address']) || empty($config['app_password'])) return false;

    $stmt = $conn->prepare('SELECT fullname, email, is_active, email_notifications, reminder_notifications FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) { $stmt->close(); return false; }
    $recipient = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$recipient || empty($recipient['is_active']) || empty($recipient['email_notifications']) || empty($recipient['reminder_notifications']) || !filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) return false;

    // Reserve this task/deadline before contacting Gmail to avoid duplicate mail on repeated page loads.
    $reserve = $conn->prepare('INSERT IGNORE INTO task_overdue_email_log (user_id, task_id, deadline) VALUES (?, ?, ?)');
    if (!$reserve) return false;
    $reserve->bind_param('iis', $userId, $taskId, $deadline);
    $reserved = $reserve->execute() && $reserve->affected_rows === 1;
    $reserve->close();
    if (!$reserved) return false;

    require_once $autoloadPath;
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $config['gmail_address'];
        $mail->Password = preg_replace('/\s+/', '', $config['app_password']);
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 10;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($config['gmail_address'], $config['from_name'] ?? 'ProcrastiTrack');
        $mail->addAddress($recipient['email'], $recipient['fullname'] ?: $recipient['email']);
        $mail->isHTML(true);
        $safeTitle = htmlspecialchars($taskTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeName = htmlspecialchars($recipient['fullname'] ?: 'there', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeDeadline = htmlspecialchars(app_task_deadline_display($deadline, $deadlineTime, 'F j, Y'), ENT_QUOTES, 'UTF-8');
        $taskUrl = rtrim($config['app_url'] ?? '', '/') . '/view_task.php?id=' . $taskId;
        $safeTaskUrl = htmlspecialchars($taskUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mail->Subject = 'Task overdue: ' . preg_replace('/[\r\n]+/', ' ', $taskTitle);
        $mail->Body = '<div style="margin:0;padding:32px 16px;background:#f4f6f4;font-family:Arial,sans-serif;color:#26342a"><div style="max-width:560px;margin:auto;padding:30px;border:1px solid #e3e9e4;border-radius:18px;background:#fff"><p style="margin:0 0 8px;color:#64836d;font-size:12px;font-weight:bold;letter-spacing:1.5px">PROCRASTITRACK</p><h1 style="margin:0 0 14px;font-size:23px">A task is overdue</h1><p style="color:#657169">Hi ' . $safeName . ', this is a reminder that the task below passed its deadline.</p><div style="margin:22px 0;padding:16px;border-radius:12px;background:#f7f9f7"><strong style="font-size:17px">' . $safeTitle . '</strong><p style="margin:8px 0 0;color:#657169">Due ' . $safeDeadline . '</p></div><a href="' . $safeTaskUrl . '" style="display:inline-block;padding:12px 18px;border-radius:10px;background:#668872;color:#fff;font-weight:bold;text-decoration:none">Review task</a><p style="margin:26px 0 0;color:#8b958e;font-size:12px">You received this email because email notifications are enabled in your ProcrastiTrack settings.</p></div></div>';
        $mail->AltBody = 'Hi ' . ($recipient['fullname'] ?: 'there') . ', the task "' . $taskTitle . '" was due ' . app_task_deadline_display($deadline, $deadlineTime, 'F j, Y') . '. Review it here: ' . $taskUrl;
        $mail->send();
        return true;
    } catch (Throwable $e) {
        $delete = $conn->prepare('DELETE FROM task_overdue_email_log WHERE user_id = ? AND task_id = ? AND deadline = ?');
        if ($delete) { $delete->bind_param('iis', $userId, $taskId, $deadline); $delete->execute(); $delete->close(); }
        error_log('ProcrastiTrack overdue email failed: ' . (isset($mail) ? $mail->ErrorInfo : $e->getMessage()));
        return false;
    }
}
