<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/lib/checkin_values.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: checkin.php'); exit(); }
app_csrf_require();

$userId = (int)$_SESSION['user_id'];
[$values, $error, $record] = checkin_values($_POST);
$type = $values['checkin_type'] ?? '';
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$period = null;
if ($error === '') {
    if (!checkin_submission_is_sunday($now)) {
        $nextAvailable = checkin_next_available_date($now);
        $error = 'The weekly check-in opens on Sunday. Come back on ' . $nextAvailable->format('M j, Y') . '.';
    } else {
        checkin_support_schema($conn);
        $period = checkin_period_bounds($type, $now);
        if (checkin_period_submitted($conn, $userId, $type, $period)) {
            $error = 'Your weekly check-in for this week has already been submitted.';
        }
    }
}

if ($error === '') {
    try {
        $conn->begin_transaction();
        $values['period_start'] = $period['start'];
        $analysis = checkin_generate_analysis($conn, $userId, $values, $period);
        $values['analysis'] = $analysis;
        $values['recommendations'] = $analysis['recommendations'];
        $values['recommendation'] = $analysis['recommendations'][0];
        $stmt = $conn->prepare("INSERT INTO procrastiblock_logs (user_id, log_type, task_id, reason, mood, focus_level, energy_level, notes) VALUES (?, 'checkin', ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iissiis', $userId, $record['task_id'], $record['reason'], $record['mood'], $record['focus'], $record['energy'], $record['notes']);
        $stmt->execute();
        $logId = $conn->insert_id;
        $stmt->close();

        $answers = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $context = $conn->prepare('INSERT INTO checkin_reflections (log_id, user_id, answers, checkin_type, period_start, recommendation) VALUES (?, ?, ?, ?, ?, ?)');
        $context->bind_param('iissss', $logId, $userId, $answers, $type, $period['start'], $values['recommendation']);
        $context->execute();
        $context->close();
        $conn->commit();
    } catch (mysqli_sql_exception | RuntimeException | JsonException $e) {
        $conn->rollback();
        error_log('Check-in save failed: ' . $e->getMessage());
        if ($e instanceof mysqli_sql_exception && $e->getCode() === 1062) {
            $error = 'This check-in period was just submitted in another request. Your existing answers are saved.';
        } else {
            $error = 'Could not save your check-in. Your answers are still here; please try again.';
        }
    }
}

if ($error !== '') {
    $_SESSION['checkin_error'] = $error;
    $_SESSION['checkin_values'] = $values;
    header('Location: checkin.php#weeklyCheckin');
    exit();
}

unset($_SESSION['checkin_error'], $_SESSION['checkin_values']);
try {
    $title = $type . ' Behavior Check-in';
    $body = 'Your ' . strtolower($type) . ' behavior check-in was saved.';
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, link, priority, created_at) VALUES (?, ?, ?, 'checkin.php#weeklyCheckin', 'normal', NOW())");
    if ($stmt) {
        $stmt->bind_param('iss', $userId, $title, $body);
        $stmt->execute();
        $stmt->close();
    }
} catch (mysqli_sql_exception $e) { error_log('Check-in notification failed: ' . $e->getMessage()); }

header('Location: checkin.php?saved=Weekly#weeklyCheckin');
exit();
