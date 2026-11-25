<?php
session_start();

require_once __DIR__ . '/lib/task_email_access.php';
include 'config/database.php';
$isEmailTaskView = isset($_GET['email_task']);
if ($isEmailTaskView) {
    header('Cache-Control: no-store, private');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}
$emailTaskAccess = $isEmailTaskView ? app_verify_task_email_access($conn, $_GET) : null;
if ($isEmailTaskView && !$emailTaskAccess) {
    http_response_code(403);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Task link expired | ProcrastiTrack</title><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/student.css"></head><body class="student-body"><main class="s-main" style="margin:0;min-height:100svh;display:grid;place-items:center;padding:24px"><section class="s-panel" style="width:min(100%,560px);padding:clamp(24px,6vw,42px);text-align:center"><p class="s-label">Secure task link</p><h1 style="margin:10px 0">This link has expired</h1><p style="color:#718075;line-height:1.7">For your privacy, task links work for seven days. Sign in to your account to find this task.</p><a class="s-btn primary" style="display:inline-flex;margin-top:20px" href="login.php">Sign in to ProcrastiTrack</a></section></main></body></html><?php
    exit();
}

if(!$isEmailTaskView && !isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/csrf.php';
$user_id = $isEmailTaskView ? (int)$emailTaskAccess['user_id'] : intval($_SESSION['user_id']);

$task_id = $isEmailTaskView ? (int)$emailTaskAccess['task_id'] : (isset($_GET['id']) ? intval($_GET['id']) : 0);
$task = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM tasks WHERE id=$task_id AND user_id=$user_id"));

if(!$task){
    if ($isEmailTaskView) {
        http_response_code(404);
        exit('This task is no longer available. Sign in to view your current tasks.');
    }
    header("Location: tasks.php");
    exit();
}
$fromHistory = ($_GET['from'] ?? '') === 'history';
$completedPage = filter_var($_GET['completed_page'] ?? 1, FILTER_VALIDATE_INT);
$completedPage = $completedPage && $completedPage > 0 ? $completedPage : 1;
$returnUrl = $fromHistory ? 'profile.php?tab=history&completed_page=' . $completedPage . '#completed-tasks' : 'tasks.php';

if(!$isEmailTaskView && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_task'])){
    app_csrf_require();
    $status = $_POST['status'] ?? '';
    if (in_array($status, ['Pending', 'In Progress', 'Completed'], true)) {
        $stmt = mysqli_prepare($conn, "UPDATE tasks SET completed_at = CASE WHEN ? = 'Completed' AND status <> 'Completed' THEN NOW() WHEN ? <> 'Completed' THEN NULL ELSE completed_at END, status = ? WHERE id = ? AND user_id = ?");
        mysqli_stmt_bind_param($stmt, 'sssii', $status, $status, $status, $task_id, $user_id);
        mysqli_stmt_execute($stmt);
        header("Location: view_task.php?id=$task_id&updated=1");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php if ($isEmailTaskView): ?><meta name="referrer" content="no-referrer"><?php endif; ?>
<title>View Task — ProcrastiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/dashboard_modern.css?v=20261113-task-detail">
<?php if ($isEmailTaskView): ?><link rel="stylesheet" href="assets/css/mobile.css?v=20261114-responsive"><?php endif; ?>
<style>
.email-task-shell{display:flex;align-items:center;justify-content:space-between;gap:16px;width:min(100% - 32px,1100px);margin:0 auto;padding:24px 0 8px;color:#355441}
.email-task-brand{display:flex;align-items:center;gap:10px;font-weight:750;letter-spacing:-.025em}.email-task-mark{display:grid;place-items:center;width:36px;height:36px;border-radius:11px;background:#6d8f7a;color:#fff;font-weight:800}
.email-task-expiry{font-size:.75rem;color:#78877d}.email-task-manage{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:9px 16px;border:1px solid #dfe8e1;border-radius:999px;background:#fff;color:#496a54;font-size:.8rem;font-weight:650;text-decoration:none}
.email-task-view .dashboard-main{width:100%!important;max-width:none!important;margin:0!important;padding:18px 20px 48px!important}.email-task-view .dashboard-container{width:min(100%,1100px);margin:0 auto}.email-task-view .greeting-section{align-items:flex-end;gap:18px;margin-bottom:20px}.email-task-view .greeting-title{overflow-wrap:anywhere}.email-task-view .dashboard-card{padding:clamp(20px,4vw,36px);border:1px solid #e4ebe5;border-radius:22px;background:#fff;box-shadow:0 14px 38px #203b2808}.email-task-view .behavior-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 32px}.email-task-view .task-description{overflow-wrap:anywhere}
.email-task-view{background:radial-gradient(ellipse at 50% -10%,#e5efe7 0,transparent 42%),#f5f6f2!important;color:#20352a}.email-task-view .email-task-shell{padding-top:26px;padding-bottom:10px}.email-task-view .email-task-brand{font-size:1rem;text-decoration:none;color:#294536}.email-task-view .email-task-mark{width:40px;height:40px;border-radius:14px;box-shadow:0 7px 18px #426a501f}.email-task-view .email-task-manage{box-shadow:0 5px 16px #263c2d0a;transition:transform .18s,background .18s}.email-task-view .email-task-manage:hover{transform:translateY(-1px);background:#f7faf7}.email-task-view .email-task-expiry{letter-spacing:.01em}.email-task-view .email-preview{overflow:hidden;border:1px solid #e1e9e2;border-radius:26px;background:#fff;box-shadow:0 24px 70px #203b2812}.email-task-view .email-preview-top{height:7px;background:linear-gradient(90deg,#547b64,#9abd9f 55%,#d7e9d9)}.email-task-view .email-preview-inner{padding:clamp(24px,5vw,52px)}.email-task-view .email-preview-kicker{margin:0 0 12px;color:#6d8f7a;font-size:.72rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase}.email-task-view .email-preview-title{margin:0;color:#20352a;font-size:clamp(1.8rem,4vw,2.65rem);line-height:1.12;letter-spacing:-.045em;overflow-wrap:anywhere}.email-task-view .email-preview-subtitle{margin:12px 0 28px;color:#748278;font-size:.98rem;line-height:1.65}.email-task-view .email-preview-summary{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding:22px;border:1px solid #e0e9e1;border-radius:18px;background:linear-gradient(135deg,#f5f9f5,#fbfcfa)}.email-task-view .email-preview-summary-label{display:block;margin-bottom:7px;color:#819087;font-size:.68rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.email-task-view .email-preview-summary-value{font-size:1.05rem;font-weight:700;color:#345641}.email-task-view .email-preview-status{flex:0 0 auto;padding:7px 11px;border-radius:999px;background:#e8f2eb;color:#477156;font-size:.72rem;font-weight:750}.email-task-view .email-preview-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px}.email-task-view .email-preview-stat{padding:17px;border:1px solid #e8ede8;border-radius:15px;background:#fff}.email-task-view .email-preview-stat span{display:block;margin-bottom:7px;color:#87938b;font-size:.68rem;font-weight:750;letter-spacing:.09em;text-transform:uppercase}.email-task-view .email-preview-stat strong{color:#2e4436;font-size:.9rem;font-weight:700;overflow-wrap:anywhere}.email-task-view .email-preview-description{margin-top:14px;padding:20px 22px;border-left:3px solid #8fb29a;border-radius:0 14px 14px 0;background:#f7f9f6;color:#5e6e63;font-size:.9rem;line-height:1.75;overflow-wrap:anywhere}.email-task-view .email-preview-footer{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-top:28px;padding-top:22px;border-top:1px solid #edf0ed}.email-task-view .email-preview-note{max-width:560px;margin:0;color:#89948c;font-size:.75rem;line-height:1.6}.email-task-view .email-preview-cta{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 19px;border-radius:999px;background:#668872;color:#fff;text-decoration:none;font-size:.82rem;font-weight:750;white-space:nowrap;box-shadow:0 8px 20px #6688722c;transition:transform .18s,box-shadow .18s}.email-task-view .email-preview-cta:hover{transform:translateY(-2px);box-shadow:0 11px 24px #6688723d}
@media(max-width:600px){.email-task-shell{width:calc(100% - 32px);padding-top:16px}.email-task-expiry{display:none}.email-task-view .dashboard-main{padding:12px 16px 32px!important}.email-task-view .greeting-section{align-items:flex-start;flex-direction:column}.email-task-view .behavior-grid{grid-template-columns:minmax(0,1fr);gap:14px}.email-task-view .dashboard-card{border-radius:18px}}
@media(max-width:600px){.email-task-view .email-task-shell{width:calc(100% - 32px);padding-top:18px}.email-task-view .email-task-shell .email-task-manage{min-height:40px;padding:8px 12px;font-size:.72rem}.email-task-view .dashboard-main{padding:14px 16px 34px!important}.email-task-view .email-preview{border-radius:21px}.email-task-view .email-preview-inner{padding:24px 20px}.email-task-view .email-preview-subtitle{margin-bottom:21px}.email-task-view .email-preview-summary{padding:17px;gap:10px}.email-task-view .email-preview-grid{grid-template-columns:1fr;gap:9px;margin-top:10px}.email-task-view .email-preview-stat{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:13px 15px}.email-task-view .email-preview-stat span{margin:0}.email-task-view .email-preview-stat strong{text-align:right}.email-task-view .email-preview-footer{align-items:stretch;flex-direction:column-reverse;margin-top:21px}.email-task-view .email-preview-cta{width:100%}}
</style>
</head>
<body class="dashboard-body<?php echo $isEmailTaskView ? ' email-task-view' : ''; ?>">
<?php if ($isEmailTaskView): ?>
<header class="email-task-shell"><a class="email-task-brand" href="landing.html"><span class="email-task-mark">P</span>ProcrastiTrack</a><span class="email-task-expiry">Private task preview · link expires <?php echo date('M j, Y', $emailTaskAccess['expires']); ?></span><a class="email-task-manage" href="login.php">Sign in to manage tasks</a></header>
<?php else: include 'includes/sidebar.php'; include 'includes/topbar.php'; endif; ?>

<main class="dashboard-main">
<div class="dashboard-container">

    <?php if ($isEmailTaskView): ?>
    <article class="email-preview fade-in">
        <div class="email-preview-top"></div>
        <div class="email-preview-inner">
            <p class="email-preview-kicker">Your task, at a glance</p>
            <h1 class="email-preview-title"><?php echo htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="email-preview-subtitle">A clear next step starts with knowing what is ahead. Here are the details saved to your ProcrastiTrack account.</p>
            <div class="email-preview-summary">
                <div><span class="email-preview-summary-label">AI-assigned priority</span><span class="email-preview-summary-value"><?php echo htmlspecialchars($task['priority'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                <span class="email-preview-status"><?php echo htmlspecialchars($task['status'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="email-preview-grid">
                <div class="email-preview-stat"><span>Deadline</span><strong><?php echo htmlspecialchars(app_task_deadline_display($task['deadline'], $task['deadline_time']), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="email-preview-stat"><span>Est. effort</span><strong><?php echo !empty($task['estimated_duration']) ? (int)$task['estimated_duration'] . ' minutes' : 'Not set'; ?></strong></div>
            </div>
            <?php if (!empty($task['description'])): ?><div class="email-preview-description"><?php echo nl2br(htmlspecialchars($task['description'], ENT_QUOTES, 'UTF-8')); ?></div><?php endif; ?>
            <footer class="email-preview-footer"><p class="email-preview-note">This is a private, read-only preview. For your security, this link expires <?php echo date('M j, Y', $emailTaskAccess['expires']); ?>. Sign in to update or manage this task.</p><a class="email-preview-cta" href="login.php">Open my workspace <span aria-hidden="true">&nbsp;→</span></a></footer>
        </div>
    </article>
    <?php else: ?>

    <div class="greeting-section">
        <div class="greeting-content">
            <h1 class="greeting-title">📝 <?php echo htmlspecialchars($task['title']); ?></h1>
            <p class="greeting-subtitle"><?php echo $isEmailTaskView ? 'Task details from your email. Sign in if you want to update it.' : 'View and manage this task\'s details.'; ?></p>
        </div>
        <div class="task-detail-header-actions">
            <?php if ($isEmailTaskView): ?>
            <span class="email-task-manage">Read-only preview</span>
            <?php else: ?>
            <a href="<?php echo htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8'); ?>" class="task-back-link"><i class="app-icon" data-lucide="arrow-left" aria-hidden="true"></i><span><?php echo $fromHistory ? 'Back to history' : 'Back to tasks'; ?></span></a>
            <?php if ($task['status'] !== 'Completed'): ?>
            <a href="edit_task.php?id=<?php echo (int)$task_id; ?>" class="task-header-edit" title="Edit task" aria-label="Edit task"><i class="app-icon" data-lucide="pencil" aria-hidden="true"></i></a>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <section class="dashboard-card fade-in">
        <div class="panel-header">
            <h3 class="panel-title">Task Details</h3>
        </div>
        <div class="behavior-grid">
            <div class="behavior-item">
                <span class="behavior-label">Priority</span>
                <span class="behavior-value">
                    <span class="priority-badge priority-<?php echo strtolower($task['priority']); ?>"><?php echo $task['priority']; ?></span>
                </span>
            </div>
            <div class="behavior-item">
                <span class="behavior-label">Status</span>
                <span class="behavior-value">
                    <span class="status-badge status-<?php echo $task['status'] === 'Completed' ? 'success' : ($task['status'] === 'In Progress' ? 'info' : 'pending'); ?>"><?php echo $task['status']; ?></span>
                </span>
            </div>
            <div class="behavior-item">
                <span class="behavior-label">Deadline</span>
                <span class="behavior-value"><?php echo htmlspecialchars(app_task_deadline_display($task['deadline'], $task['deadline_time']), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="behavior-item">
                <span class="behavior-label">Est. Duration</span>
                <span class="behavior-value"><?php echo $task['estimated_duration'] ? $task['estimated_duration'] . ' min' : 'Not set'; ?></span>
            </div>
            <div class="behavior-item">
                <span class="behavior-label">Created</span>
                <span class="behavior-value"><?php echo date('M j, Y', strtotime($task['created_at'])); ?></span>
            </div>
        </div>

        <?php if($task['description']): ?>
        <div style="margin-top:20px">
            <h4 style="font-size:0.85rem;font-weight:700;color:#334159;margin-bottom:8px">Description</h4>
            <p style="color:#64748b;font-size:0.88rem;line-height:1.6"><?php echo nl2br(htmlspecialchars($task['description'])); ?></p>
        </div>
        <?php endif; ?>

        <?php if(!$isEmailTaskView && $task['status'] !== 'Completed'): ?>
        <form method="POST" style="margin-top:24px"><?php app_csrf_field(); ?>
            <input type="hidden" name="update_task" value="1">
            <div class="form-group">
                <label>Status</label>
                <select name="status" class="setting-select">
                    <option value="Pending" <?php echo $task['status'] === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="In Progress" <?php echo $task['status'] === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="Completed" <?php echo $task['status'] === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                </select>
            </div>
            <button type="submit" class="rec-btn" style="background:#16a34a">Update Status</button>
        </form>
        <?php endif; ?>
        <?php if (!$isEmailTaskView): ?><div class="task-detail-footer">
            <div class="task-delete-copy"><strong>Remove this task</strong><span>This will permanently delete the task from your list.</span></div>
            <form method="post" action="delete_task.php" class="task-delete-form" onsubmit="return confirm('Delete this task? This cannot be undone.')"><input type="hidden" name="id" value="<?php echo (int)$task_id; ?>"><input type="hidden" name="return_to" value="<?php echo $fromHistory ? 'history' : 'tasks'; ?>"><input type="hidden" name="completed_page" value="<?php echo (int)$completedPage; ?>"><?php app_csrf_field(); ?><button type="submit" class="task-delete-action"><i class="app-icon" data-lucide="trash-2" aria-hidden="true"></i><span>Delete task</span></button></form>
        </div><?php endif; ?>
    </section>

    <?php endif; ?>

</div>
</main>
</body>
</html>
