<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/csrf.php';
$user_id=(int)$_SESSION['user_id'];
$filter=($_GET['filter']??'')==='unread'?'unread':'all';
function notice_text($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Notifications | ProcrastiTrack</title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/student.css">
<link rel="stylesheet" href="assets/css/notifications.css?v=20261016-card-click">
</head>
<body class="student-body notifications-page">
<?php include 'includes/sidebar.php';
// Topbar creates any new overdue notices before the feed is read.
$unread_count=(int)$conn->query("SELECT COUNT(*) FROM notifications WHERE user_id=$user_id AND read_at IS NULL")->fetch_row()[0];
$where=$filter==='unread'?' AND read_at IS NULL':'';
$notices=$conn->query("SELECT * FROM notifications WHERE user_id=$user_id$where ORDER BY (read_at IS NOT NULL), created_at DESC, id DESC LIMIT 200");
?>
<main class="dashboard-main"><div class="notice-container">
<header class="notice-header">
<div><p class="notice-eyebrow">YOUR UPDATES</p><h1>Notifications</h1><p>Deadlines, task updates, and study activity in one place.</p></div>
<form method="post" action="mark_all_read.php"><?php app_csrf_field(); ?><button class="notice-button" type="submit" <?php echo $unread_count===0?'disabled':''; ?>>Mark all as read</button></form>
</header>
<section class="notice-feed" aria-label="Notification feed">
<div class="notice-toolbar"><nav aria-label="Notification filters">
<a href="notifications.php" <?php echo $filter==='all'?'aria-current="page"':''; ?>>All updates</a>
<a href="notifications.php?filter=unread" <?php echo $filter==='unread'?'aria-current="page"':''; ?>>Unread <span><?php echo $unread_count; ?></span></a>
</nav><span class="notice-hint">Opening an update marks it as read</span></div>
<div class="notifications-list">
<?php if (!$notices->num_rows): ?>
<div class="notice-empty"><i class="app-icon" data-lucide="check-check" aria-hidden="true"></i><h2><?php echo $filter==='unread'?'You are all caught up':'No updates yet'; ?></h2><p><?php echo $filter==='unread'?'You have no unread notifications.':'Your task and study updates will appear here.'; ?></p></div>
<?php endif; ?>
<?php while ($n=$notices->fetch_assoc()):
$isUnread=$n['read_at']===null;
$icon=($n['type']==='overdue')?'clock-alert':(str_contains($n['type']??'','checkin')?'notebook-pen':'bell');
?>
<article role="button" tabindex="0" aria-label="Open notification: <?php echo notice_text($n['title']); ?>" class="notice-row <?php echo $isUnread?'is-unread':''; ?>">
<div class="notice-icon"><i class="app-icon" data-lucide="<?php echo $icon; ?>" aria-hidden="true"></i></div>
<div class="notice-copy"><div class="notice-title"><h2><?php echo notice_text($n['title']); ?></h2><?php if ($isUnread): ?><span class="notice-unread">Unread</span><?php endif; ?></div>
<p><?php echo notice_text($n['message']); ?></p>
<time datetime="<?php echo notice_text(date('c',strtotime($n['created_at']))); ?>"><?php echo notice_text(date('M j, Y · g:i A',strtotime($n['created_at']))); ?></time></div>
<form hidden method="post" action="open_notification.php"><?php app_csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>"></form>
</article>
<?php endwhile; ?>
</div>
<footer class="notice-footer">Showing up to 200 recent updates. Times are shown in Philippine time.</footer>
</section>
</div></main>
<script>
window.addEventListener('pageshow',function(event){if(event.persisted) location.reload();});
document.querySelectorAll('.notice-row[role="button"]').forEach(function(row){
 const form=row.querySelector('form');
 function openNotification(){
  if(row.getAttribute('aria-busy')==='true') return;
  row.setAttribute('aria-busy','true');
  form.requestSubmit();
 }
 row.addEventListener('click',openNotification);
 row.addEventListener('keydown',function(event){
  if(event.key==='Enter'||event.key===' '){event.preventDefault();openNotification();}
 });
});
</script>
</body></html>
