<?php
session_start();

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

include 'config/database.php';
require_once __DIR__ . '/csrf.php';
$user_id = intval($_SESSION['user_id']);
$user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id=$user_id"));

$message = '';
$message_type = '';

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])){ app_csrf_require();
    $current = $_POST['current_password'];
    $new = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    $currentPasswordValid = password_verify($current, $user['password']);
    if(!$currentPasswordValid && hash_equals((string)$user['password'], (string)$current)){
        $currentPasswordValid = true;
    }

    if(!$currentPasswordValid){
        $message = 'Current password is incorrect.';
        $message_type = 'error';
    } elseif($new !== $confirm){
        $message = 'New passwords do not match.';
        $message_type = 'error';
    } elseif(strlen($new) < 6){
        $message = 'Password must be at least 6 characters.';
        $message_type = 'error';
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $passwordStmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $passwordUpdated = false;
        if ($passwordStmt) {
            $passwordStmt->bind_param('si', $hashed, $user_id);
            $passwordUpdated = $passwordStmt->execute();
            $passwordStmt->close();
        }

        if ($passwordUpdated) {
            $noticeTitle = 'Password changed';
            $noticeMessage = 'Your account password was changed successfully.';
            $noticeLink = 'settings.php';
            $noticeType = 'security';
            $noticeStmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, link, priority, created_at) VALUES (?, ?, ?, ?, ?, 'high', NOW())");
            if ($noticeStmt) {
                $noticeStmt->bind_param('issss', $user_id, $noticeType, $noticeTitle, $noticeMessage, $noticeLink);
                $noticeStmt->execute();
                $noticeStmt->close();
            }
            $message = 'Password changed successfully!';
            $message_type = 'success';
        } else {
            $message = 'Could not update your password. Please try again.';
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings &mdash; ProcrastiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/dashboard_modern.css">
<link rel="stylesheet" href="assets/css/settings.css?v=1">
</head>
<body class="dashboard-body settings-body">
<?php include 'includes/sidebar.php'; ?>
<?php include 'includes/topbar.php'; ?>
<main class="dashboard-main settings-main"><div class="dashboard-container settings-shell">
<header class="settings-hero"><div><span class="settings-eyebrow">MAKE IT YOURS</span><h1>Settings</h1><p>A calmer workspace, set up your way.</p></div><a class="settings-profile-link" href="profile.php"><i data-lucide="user-round" class="app-icon" aria-hidden="true"></i> Manage profile <span aria-hidden="true">&#8599;</span></a></header>
<?php if($message): ?><div role="alert" class="settings-alert <?php echo $message_type === 'error' ? 'is-error' : 'is-success'; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<div class="settings-layout">
<nav class="settings-nav" aria-label="Settings sections"><span class="settings-eyebrow">YOUR WORKSPACE</span><a href="#notifications"><i data-lucide="bell" class="app-icon" aria-hidden="true"></i> Notifications</a><a href="#regional"><i data-lucide="globe" class="app-icon" aria-hidden="true"></i> Language</a><a href="#security"><i data-lucide="shield-check" class="app-icon" aria-hidden="true"></i> Password & security</a><div class="settings-nav-note"><i data-lucide="sliders-horizontal" class="app-icon" aria-hidden="true"></i><strong>Small changes. Better focus.</strong><p>Choose which updates you receive.</p></div></nav>
<div class="settings-content">
<form id="preferencesForm" class="settings-preferences"><?php app_csrf_field(); ?>
<section class="settings-section" id="notifications" aria-labelledby="notificationsTitle"><header class="settings-section-heading"><span class="settings-section-icon"><i data-lucide="bell" class="app-icon" aria-hidden="true"></i></span><div><h2 id="notificationsTitle">Notifications</h2><p>A little nudge, when you need it.</p></div></header>
<div class="setting-row"><div><label for="email_notif">Email notifications</label><p id="emailHelp">Receive task updates and overdue alerts by email.</p></div><div class="toggle-switch"><input type="checkbox" name="email_notif" id="email_notif" aria-describedby="emailHelp" value="1" <?php echo !empty($user['email_notifications']) ? 'checked' : ''; ?>><label for="email_notif" class="toggle-slider"><span class="sr-only">Email notifications</span></label></div></div>
<div class="setting-row"><div><label for="reminder_notif">Task reminders</label><p id="reminderHelp">Allow deadline reminders for your tasks.</p></div><div class="toggle-switch"><input type="checkbox" name="reminder_notif" id="reminder_notif" aria-describedby="reminderHelp" value="1" <?php echo !empty($user['reminder_notifications']) ? 'checked' : ''; ?>><label for="reminder_notif" class="toggle-slider"><span class="sr-only">Task reminders</span></label></div></div>
</section>
<section class="settings-section" id="regional" aria-labelledby="languageTitle"><header class="settings-section-heading"><span class="settings-section-icon"><i data-lucide="globe" class="app-icon" aria-hidden="true"></i></span><div><h2 id="languageTitle">Language</h2><p>Make your workspace feel familiar.</p></div></header><div class="setting-row"><div><label for="settingsLanguage">Interface language</label><p>Changes supported navigation and interface labels.</p></div><select id="settingsLanguage" name="language" class="setting-select"><?php foreach(['en'=>'English','es'=>"Espa\u{00F1}ol",'fr'=>"Fran\u{00E7}ais"] as $code=>$label): ?><option value="<?php echo $code; ?>" <?php echo ($user['language'] ?? 'en') === $code ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div></section>
<noscript><p>Enable JavaScript to change notification preferences.</p></noscript>
</form>
<section class="settings-section" id="security" aria-labelledby="securityTitle"><header class="settings-section-heading"><span class="settings-section-icon"><i data-lucide="shield-check" class="app-icon" aria-hidden="true"></i></span><div><h2 id="securityTitle">Password & security</h2><p>Keep your account protected with a strong password.</p></div></header>
<form method="POST" class="settings-security-form"><?php app_csrf_field(); ?><input type="hidden" name="change_password" value="1">
<div class="password-field"><label for="currentPassword">Current password</label><input id="currentPassword" type="password" name="current_password" autocomplete="current-password" required></div>
<div class="settings-password-grid"><div class="password-field"><label for="newPassword">New password</label><input id="newPassword" type="password" name="new_password" autocomplete="new-password" minlength="6" aria-describedby="passwordHelp" required></div><div class="password-field"><label for="confirmPassword">Confirm new password</label><input id="confirmPassword" type="password" name="confirm_password" autocomplete="new-password" minlength="6" required></div></div>
<div class="settings-password-help"><p id="passwordHelp">Use at least 6 characters. A longer, unique password is best.</p><label class="settings-show-password"><input type="checkbox" id="showPasswords"> Show passwords</label></div><footer class="settings-security-footer"><span><i data-lucide="lock-keyhole" class="app-icon" aria-hidden="true"></i> Your current password is required to save.</span><button type="submit" class="settings-button primary">Update password <span aria-hidden="true">&#8594;</span></button></footer></form></section>
<div class="settings-profile-note"><i data-lucide="user-round" class="app-icon" aria-hidden="true"></i><p>Updating your name, email, or university? <a href="profile.php">Head to your profile <span aria-hidden="true">&#8599;</span></a></p></div>
</div></div></div></main>
<script src="assets/js/settings.js?v=1"></script>
</body></html>
