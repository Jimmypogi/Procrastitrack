<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/config/database.php';
$userId = (int)$_SESSION['user_id'];
$message = '';
$error = '';
$tables = [
    'tasks', 'task_deadline_history', 'study_sessions', 'procrastiblock_logs', 'checkin_reflections', 'user_activity_days', 'task_reminder_email_log',
    'notifications', 'user_tendency', 'tendency_history', 'weekly_reports',
    'daily_checkins', 'calendar_events', 'user_procrastination_profiles'
];

if (!isset($_SESSION['data_controls_csrf'])) {
    $_SESSION['data_controls_csrf'] = bin2hex(random_bytes(32));
}

if (isset($_GET['export']) && $_GET['export'] === '1') {
    $out = fopen('php://output', 'w');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="procrastitrack-my-data-' . date('Y-m-d') . '.csv"');
    fputcsv($out, ['ProcrastiTrack personal data export', date('Y-m-d H:i:s')]);
    fputcsv($out, ['Table', 'Record (JSON)']);

    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($account = $result->fetch_assoc()) {
            unset($account['password']);
            fputcsv($out, ['users', json_encode($account, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
        $stmt->close();
    }

    foreach ($tables as $table) {
        $tableName = mysqli_real_escape_string($conn, $table);
        $exists = mysqli_query($conn, "SHOW TABLES LIKE '$tableName'");
        if (!$exists || mysqli_num_rows($exists) === 0) continue;

        $stmt = $conn->prepare("SELECT * FROM `$table` WHERE user_id = ?");
        if (!$stmt) continue;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($record = $result->fetch_assoc()) {
            fputcsv($out, [$table, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
        $stmt->close();
    }
    fclose($out);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account'])) {
    $token = $_POST['csrf'] ?? '';
    $confirmation = trim($_POST['confirmation'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!hash_equals($_SESSION['data_controls_csrf'], $token)) {
        $error = 'Security check failed. Please reload the page and try again.';
    } elseif ($confirmation !== 'DELETE') {
        $error = 'Type DELETE exactly to confirm account deletion.';
    } else {
        $stmt = $conn->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $passwordValid = $account && (password_verify($password, $account['password']) || hash_equals((string)$account['password'], (string)$password));

        if (!$passwordValid) {
            $error = 'Password is incorrect; your account has not been deleted.';
        } else {
            $conn->begin_transaction();
            $deleted = true;
            foreach ($tables as $table) {
                $tableName = mysqli_real_escape_string($conn, $table);
                $exists = mysqli_query($conn, "SHOW TABLES LIKE '$tableName'");
                if (!$exists || mysqli_num_rows($exists) === 0) continue;
                $stmt = $conn->prepare("DELETE FROM `$table` WHERE user_id = ?");
                if (!$stmt) { $deleted = false; break; }
                $stmt->bind_param('i', $userId);
                if (!$stmt->execute()) $deleted = false;
                $stmt->close();
                if (!$deleted) break;
            }
            if ($deleted) {
                $stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
                if (!$stmt) $deleted = false;
                else {
                    $stmt->bind_param('i', $userId);
                    $deleted = $stmt->execute();
                    $stmt->close();
                }
            }

            if ($deleted) {
                $conn->commit();
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
                }
                session_destroy();
                header('Location: login.php?account_deleted=1');
                exit();
            }
            $conn->rollback();
            $error = 'Deletion could not be completed. No account data was removed; please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Privacy & Data Controls — ProcrastiTrack</title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/student.css">
<style>
.data-card{background:#fff;border:1px solid #e7e2ef;border-radius:16px;padding:24px;margin:0 0 18px;box-shadow:0 8px 24px rgba(30,20,60,.04)}
.data-card h2{margin:0 0 8px;font-size:1.15rem}.data-card p{color:#625b70;line-height:1.6}.data-actions{display:flex;gap:12px;flex-wrap:wrap;align-items:center}.data-actions input{padding:10px;border:1px solid #d8d2e2;border-radius:8px}.data-danger{border-color:#fecaca}.data-danger h2{color:#b91c1c}.data-notice{background:#f5f3ff;border-left:4px solid #7c3aed;padding:14px 16px;border-radius:8px;color:#44365d;line-height:1.6}.data-error{background:#fef2f2;color:#991b1b;padding:12px;border-radius:8px;margin-bottom:16px}
</style>
</head>
<body class="student-body">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<main class="s-main"><div class="s-inner">
<header class="s-head"><div><div class="s-label">Account</div><h1>Privacy & Data Controls</h1><p>Review your data, download a copy, or delete your account.</p></div></header>
<?php if ($error): ?><div class="data-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<section class="data-card"><h2>Who can see your information?</h2><p>Your task details, study sessions, check-ins, and delay logs are available to you in your student account. Admin and counselor pages show cohort-level aggregate behavior reports; account-management pages may show your name, email, role, and account status to authorized administrators. Individual behavior logs are not displayed in those reports.</p></section>
<section class="data-card"><h2>How long is data kept?</h2><p>The application does not currently apply an automatic retention period. Your account data remains in the live application until you delete your account here. Database backups or server copies may be governed separately by the hosting provider's backup settings.</p></section>
<section class="data-card"><h2>Download your data</h2><p>Export your account profile (excluding your password), tasks, deadline history, study sessions, behavior logs, reports, notifications, and related records as a CSV file.</p><div class="data-actions"><a class="s-btn primary" href="data_controls.php?export=1">Download my data (CSV)</a></div></section>
<section class="data-card data-danger"><h2>Delete account and data</h2><p>This permanently deletes your student account and associated records from the live application, then signs you out. This cannot be undone. Deletion from backups may follow the hosting provider's backup schedule.</p><form method="post" onsubmit="return confirm('Permanently delete your account and all associated data? This cannot be undone.');"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['data_controls_csrf']); ?>"><div class="data-actions"><label>Type DELETE <input name="confirmation" required autocomplete="off"></label><label>Current password <input name="password" type="password" required autocomplete="current-password"></label><button class="s-btn" type="submit" name="delete_account" value="1" style="background:#b91c1c;color:white">Delete account and data</button></div></form></section>
</div></main>
</body>
</html>
