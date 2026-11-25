<?php
if(session_status() !== PHP_SESSION_ACTIVE){ @session_start(); }
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = $_SESSION['fullname'] ?? 'Student';
$user_email = $_SESSION['email'] ?? '';

include_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../csrf.php';
$preferences = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT dark_mode, language FROM users WHERE id=' . (int)$user_id)) ?: [];


$tbl = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
$new_overdue_notifications = [];
if($tbl && mysqli_num_rows($tbl) > 0){
    // Create one in-app alert for each task/deadline when the user next visits after it becomes overdue.
    require_once __DIR__ . '/../lib/overdue_email.php';
    $overdueResult = mysqli_query($conn, "SELECT t.id, t.title, t.deadline, t.deadline_time, t.status, (SELECT dl.id FROM procrastiblock_logs dl WHERE dl.user_id=t.user_id AND dl.task_id=t.id AND dl.log_type='delay' ORDER BY dl.created_at DESC, dl.id DESC LIMIT 1) AS delay_log_id FROM tasks t WHERE t.user_id=" . (int)$user_id . " AND t.status <> 'Completed' AND " . app_task_overdue_sql('t') . " ORDER BY t.deadline ASC, t.deadline_time ASC LIMIT 50");
    if($overdueResult){
        $findNotice = mysqli_prepare($conn, 'SELECT id FROM notifications WHERE user_id = ? AND type = ? AND link = ? LIMIT 1');
        $saveNotice = mysqli_prepare($conn, 'INSERT INTO notifications (user_id, type, title, message, link, priority) VALUES (?, ?, ?, ?, ?, ?)');
        if($findNotice && $saveNotice){
            while($overdueTask = mysqli_fetch_assoc($overdueResult)){
                $taskId = (int)$overdueTask['id'];
                $taskDeadline = $overdueTask['deadline'];
                $noticeType = 'overdue';
$noticeLink = 'view_task.php?id=' . $taskId . '&overdue_due=' . rawurlencode($taskDeadline);
                mysqli_stmt_bind_param($findNotice, 'iss', $user_id, $noticeType, $noticeLink);
                mysqli_stmt_execute($findNotice);
                $exists = mysqli_stmt_get_result($findNotice);
                if($exists && mysqli_num_rows($exists) === 0){
                    $noticeTitle = 'Task overdue: ' . $overdueTask['title'];
                    $noticeMessage = 'This task was due ' . app_task_deadline_display($taskDeadline, $overdueTask['deadline_time']) . '. Open it to review or update your plan.';
                    $noticePriority = 'high';
                    mysqli_stmt_bind_param($saveNotice, 'isssss', $user_id, $noticeType, $noticeTitle, $noticeMessage, $noticeLink, $noticePriority);
                    if(mysqli_stmt_execute($saveNotice)){
                        $new_overdue_notifications[] = [
                            'title' => $noticeTitle,
                            'message' => $noticeMessage,
                            'link' => $noticeLink,
                            'task_id' => $taskId,
                            'delay_log_id' => (int)($overdueTask['delay_log_id'] ?? 0),
                            'can_log_delay' => in_array($overdueTask['status'], ['Pending', 'In Progress'], true),
                        ];
                    }
                }
                app_send_overdue_email($conn, $user_id, $overdueTask['title'], $taskDeadline, $taskId, $overdueTask['deadline_time']);
            }
        }
        if($findNotice) mysqli_stmt_close($findNotice);
        if($saveNotice) mysqli_stmt_close($saveNotice);
    }
}
$unread_count = 0;
if($tbl && mysqli_num_rows($tbl) > 0){
    $res = mysqli_query($conn, "SELECT COUNT(*) as c FROM notifications WHERE user_id=$user_id AND read_at IS NULL");
    if($res){ $r = mysqli_fetch_assoc($res); $unread_count = intval($r['c']); }
}

?>

<div class="topbar">
    <div class="topbar-right">
        <button type="button" class="topbar-btn" id="themeToggle" aria-label="Dark mode" title="Dark mode" aria-pressed="<?php echo !empty($preferences['dark_mode']) ? 'true' : 'false'; ?>"><span class="btn-icon"><i class="app-icon" data-lucide="moon" aria-hidden="true"></i></span></button>
        <a href="notifications.php" class="topbar-btn" id="notificationBell" title="Notifications" aria-label="Notifications">
            <span class="btn-icon"><i class="app-icon" data-lucide="bell" aria-hidden="true"></i></span>
            <?php if($unread_count > 0): ?>
                <span class="topbar-badge"><?php echo $unread_count; ?></span>
            <?php endif; ?>
        </a>
        <div class="profile-dropdown" id="profileDropdown">
            <div class="profile-trigger">
                <div class="avatar"><?php echo substr($user_name, 0, 1); ?></div>
                <div class="profile-info">
                    <span class="profile-name"><?php echo htmlspecialchars($user_name); ?></span>
                    <span class="profile-email"><?php echo htmlspecialchars($user_email ?: 'student@procrastitrack.edu'); ?></span>
                </div>
                <span class="chevron"><i class="app-icon" data-lucide="chevron-down" aria-hidden="true"></i></span>
            </div>
            <div class="dropdown-menu">
                <a href="profile.php" class="dropdown-item"><i class="app-icon" data-lucide="user-round" aria-hidden="true"></i> Profile</a>
                <a href="settings.php" class="dropdown-item"><i class="app-icon" data-lucide="settings" aria-hidden="true"></i> Settings</a>
                <div class="dropdown-divider"></div>
                <a href="logout.php" class="dropdown-item logout"><i class="app-icon" data-lucide="log-out" aria-hidden="true"></i> Logout</a>
            </div>
        </div>
    </div>
</div>

<?php if(!empty($new_overdue_notifications)): ?>
<div class="overdue-alert-stack" aria-live="polite" aria-label="New overdue task alerts">
    <?php foreach(array_slice($new_overdue_notifications, 0, 3) as $notice): ?>
    <article class="overdue-alert">
        <span class="overdue-alert-icon"><i class="app-icon" data-lucide="triangle-alert" aria-hidden="true"></i></span>
        <div class="overdue-alert-copy"><strong><?php echo htmlspecialchars($notice['title'], ENT_QUOTES, 'UTF-8'); ?></strong><p><?php echo htmlspecialchars($notice['message'], ENT_QUOTES, 'UTF-8'); ?></p><div class="overdue-alert-actions"><a href="<?php echo htmlspecialchars($notice['link'], ENT_QUOTES, 'UTF-8'); ?>">Review task</a><?php if($notice['delay_log_id'] > 0): ?><a class="overdue-alert-log" href="log_delay.php?show_history=1#delay-<?php echo (int)$notice['delay_log_id']; ?>">Review delay note</a><?php elseif($notice['can_log_delay']): ?><a class="overdue-alert-log" href="log_delay.php?task=<?php echo (int)$notice['task_id']; ?>">Log delay</a><?php endif; ?></div></div>
        <button type="button" class="overdue-alert-close" aria-label="Dismiss alert">&times;</button>
    </article>
    <?php endforeach; ?>
</div>
<style>
.overdue-alert-stack{position:fixed;top:76px;right:22px;z-index:5000;display:grid;gap:10px;width:min(380px,calc(100vw - 32px))}
.overdue-alert{display:flex;align-items:flex-start;gap:12px;padding:15px 14px;border:1px solid #f2d39b;border-left:4px solid #d99632;border-radius:14px;background:#fffdf8;color:#2f332f;box-shadow:0 14px 38px rgba(35,40,36,.16);animation:overdueAlertIn .25s ease-out}
.overdue-alert-icon{display:grid;place-items:center;flex:0 0 34px;width:34px;height:34px;border-radius:10px;background:#fff2d9;color:#ad6b16}.overdue-alert-icon .app-icon{width:18px;height:18px}
.overdue-alert-copy{min-width:0;flex:1}.overdue-alert-copy strong{display:block;font-size:.86rem;line-height:1.4}.overdue-alert-copy p{margin:4px 0 7px;color:#6c706c;font-size:.76rem;line-height:1.45}.overdue-alert-actions{display:flex;flex-wrap:wrap;align-items:center;gap:8px 14px}.overdue-alert-copy a{color:#587b64;font-size:.76rem;font-weight:700;text-decoration:none}.overdue-alert-copy a:hover{text-decoration:underline}.overdue-alert-copy a.overdue-alert-log{display:inline-flex;align-items:center;min-height:32px;padding:6px 10px;border:1px solid #dce8dd;border-radius:9px;background:#f1f6f1;color:#456b50}.overdue-alert-copy a.overdue-alert-log:hover{background:#e6f0e7;text-decoration:none}
.overdue-alert-close{border:0;background:transparent;color:#929792;font-size:20px;line-height:1;cursor:pointer;padding:0 2px}.overdue-alert-close:hover{color:#333}
@keyframes overdueAlertIn{from{opacity:0;transform:translateY(-7px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:600px){.overdue-alert-stack{top:70px;right:12px}}
</style>
<script>document.querySelectorAll('.overdue-alert-close').forEach(function(button){button.addEventListener('click',function(){button.closest('.overdue-alert').remove();});});</script>
<?php endif; ?>
<script>
(function(){
    // Profile dropdown
    var profileDropdown = document.getElementById('profileDropdown');
    if(profileDropdown){
        var trigger = profileDropdown.querySelector('.profile-trigger');
        trigger.addEventListener('click', function(e){
            e.stopPropagation();
            profileDropdown.classList.toggle('open');
        });
        document.addEventListener('click', function(e){
            if(!profileDropdown.contains(e.target)){
                profileDropdown.classList.remove('open');
            }
        });
    }

})();
</script>

<span id="preferenceStatus" role="status" aria-live="polite"></span>
<script>window.appPreferences = <?php echo json_encode(['dark_mode'=>!empty($preferences['dark_mode']), 'language'=>$preferences['language']??'en', 'csrf'=>app_csrf_token()], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;</script>
<script defer src="assets/js/preferences.js?v=20261104-mobile"></script>
<link rel="stylesheet" href="assets/css/preferences.css?v=20261015">

<script defer src="assets/js/presence.js?v=20261021"></script>
