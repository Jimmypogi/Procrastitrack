<?php
session_start();

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

include 'config/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__.'/lib/ai_task_priority.php';
$user_id = intval($_SESSION['user_id']);

$message = '';
$message_type = '';
$values = ['title' => '', 'description' => '', 'deadline' => '', 'deadline_time' => ''];

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    app_csrf_require();
    foreach ($values as $field => $value) $values[$field] = trim((string)($_POST[$field] ?? ''));
    $deadlineDate = DateTime::createFromFormat('!Y-m-d', $values['deadline']);
    $validDeadlineTime = $values['deadline_time'] === '' || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $values['deadline_time']);
    if($values['title'] === ''){
        $message = 'Task title is required.';
        $message_type = 'error';
    } elseif(strlen($values['title']) > 255){
        $message = 'Please use a shorter task title (maximum 255 bytes).';
        $message_type = 'error';
    } elseif(strlen($values['description']) > 8000){
        $message = 'Please keep the task description under 8,000 bytes so AI can review it.';
        $message_type = 'error';
    } elseif(!$deadlineDate || $deadlineDate->format('Y-m-d') !== $values['deadline'] || $values['deadline'] < date('Y-m-d')){
        $message = 'Choose a valid deadline today or later.';
        $message_type = 'error';
    } elseif(!$validDeadlineTime){
        $message = 'Choose a valid deadline time.';
        $message_type = 'error';
    } else {
        $aiPriority = ai_assign_task_priority($values['title'], $values['description'], $values['deadline'], $values['deadline_time']);
        if (!$aiPriority['ok']) {
            $message = $aiPriority['error'];
            $message_type = 'error';
        } else {
        $priority = $aiPriority['priority'];
        $description = $values['description'] !== '' ? $values['description'] : null;
        $taskTitle = $values['title'];
        $taskDeadline = $values['deadline'];
        $taskDeadlineTime = $values['deadline_time'] !== '' ? $values['deadline_time'] : null;
        $stmt = mysqli_prepare($conn, "INSERT INTO tasks (user_id, title, subject, description, deadline, deadline_time, priority, status) VALUES (?, ?, NULL, ?, ?, ?, ?, 'Pending')");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'isssss', $user_id, $taskTitle, $description, $taskDeadline, $taskDeadlineTime, $priority);
        }

        if($stmt && mysqli_stmt_execute($stmt)){
            $taskId = mysqli_insert_id($conn);
            $historyReason = 'Initial deadline';
            $historyStmt = mysqli_prepare($conn, 'INSERT INTO task_deadline_history (task_id, user_id, old_deadline, new_deadline, change_reason) VALUES (?, ?, NULL, ?, ?)');
            if ($historyStmt) {
                mysqli_stmt_bind_param($historyStmt, 'iiss', $taskId, $user_id, $taskDeadline, $historyReason);
                mysqli_stmt_execute($historyStmt);
                mysqli_stmt_close($historyStmt);
            }
            
            $notifTbl = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
            if($notifTbl && mysqli_num_rows($notifTbl) > 0){
                $notifTitle = 'New Task Added';
                $dueText = app_task_deadline_display($taskDeadline, $taskDeadlineTime, 'M j, Y');
                $notifMessage = "New task '{$values['title']}' added. Due: {$dueText} (Priority: {$priority})";
                $notifLink = "tasks.php#task-{$taskId}";
                $notifStmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, title, message, link, priority, created_at) VALUES (?, ?, ?, ?, 'normal', NOW())");
                if ($notifStmt) {
                    mysqli_stmt_bind_param($notifStmt, 'isss', $user_id, $notifTitle, $notifMessage, $notifLink);
                    mysqli_stmt_execute($notifStmt);
                    mysqli_stmt_close($notifStmt);
                }
            }
            
            require_once __DIR__.'/lib/task_created_email.php';
            $_SESSION['task_email_status'] = app_send_created_task_email($conn, $user_id, (int)$taskId);
            $_SESSION['task_ai_priority'] = ['priority' => $priority, 'reason' => $aiPriority['reason']];
            header("Location: tasks.php?added=1");
            exit();
        } else {
            if ($stmt) mysqli_stmt_close($stmt);
            $message = 'Error creating task. Please try again.';
            $message_type = 'error';
        }
        }
    }
}

$totalCount = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM tasks WHERE user_id=$user_id"));
$urgentCount = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status!='Completed' AND " . app_task_overdue_sql()));
$pendingCount = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status='Pending'"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Task - ProcrastiTrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/student.css">
<link rel="stylesheet" href="assets/css/task-create.css?v=20261115">
</head>
<body class="student-body task-create-page">
<?php include 'includes/sidebar.php'; ?>

<main class="s-main">
<div class="s-inner">

<header class="s-head">
<div>
<div class="s-label">Tasks</div>
<h1>Create New Task</h1>
<p>Describe what you need to do. AI will set a priority using the task details and deadline.</p>
</div>
<div class="s-head-actions">
<a href="tasks.php" class="s-btn">&#8592; Back to Tasks</a>
</div>
</header>

<?php if(isset($_GET['added'])): ?>
<div class="s-panel task-create-flash" role="status">
<div>Task created successfully. Your AI-assigned priority is ready.</div>
</div>
<?php endif; ?>

<?php if($message): ?>
<div class="s-panel task-create-message <?php echo $message_type === 'error' ? 'is-error' : 'is-success'; ?>" role="alert">
<div><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
</div>
<?php endif; ?>

<section class="s-two">
<div class="s-panel task-create-card">
<div class="s-panel-header">
<div>
<div class="s-label">Task Details</div>
<h3>What do you need to do?</h3>
</div>
</div>

<form method="POST" class="s-form-grid"><?php app_csrf_field(); ?>
<div class="full">
<label>Task Title <span style="color:#dc2626">*</span></label>
<input type="text" name="title" maxlength="255" value="<?php echo htmlspecialchars($values['title'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g., Complete research paper draft" required>
</div>

<div class="full">
<label>Description</label>
<textarea name="description" maxlength="8000" rows="3" placeholder="Add details, requirements, or notes about this task..."><?php echo htmlspecialchars($values['description'], ENT_QUOTES, 'UTF-8'); ?></textarea>
</div>

<div>
<label>Deadline <span style="color:#dc2626">*</span></label>
<input type="date" name="deadline" value="<?php echo htmlspecialchars($values['deadline'], ENT_QUOTES, 'UTF-8'); ?>" min="<?php echo date('Y-m-d'); ?>" required>
</div>

<div>
<label for="deadline-time">Due time <span style="color:#718075;font-weight:500">(optional)</span></label>
<input id="deadline-time" type="time" name="deadline_time" step="60" value="<?php echo htmlspecialchars($values['deadline_time'], ENT_QUOTES, 'UTF-8'); ?>">
<small>Philippine time. Without a time, the task is due by the end of the day.</small>
</div>

<div class="full task-ai-note" role="note">
<span class="task-ai-mark" aria-hidden="true">AI</span>
<div><strong>AI priority, tailored to your task</strong><p>AI evaluates what you need to do, the details you provide, and how soon it is due. It assigns High, Medium, or Low automatically. If AI is unavailable, your task will not be saved, so its priority is never guessed.</p></div>
</div>

<div class="full">
<button type="submit" name="save" class="s-btn primary task-create-submit">Create Task</button>
<p id="aiPrioritySuggestStatus" role="status" aria-live="polite" class="task-create-status"></p>
</div>
<script defer src="assets/js/task-create.js?v=20261114"></script>
</form>
</div>

<aside style="display:flex;flex-direction:column;gap:18px">
<div class="s-panel">
<div class="s-panel-header">
<div>
<h3>Quick Stats</h3>
</div>
</div>
<div style="padding:8px 0">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
<span style="font-size:0.88rem;color:#4a4458">Pending</span>
<strong style="font-size:1.2rem;color:#f59e0b"><?php echo $pendingCount; ?></strong>
</div>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
<span style="font-size:0.88rem;color:#4a4458">Overdue</span>
<strong style="font-size:1.2rem;color:#dc2626"><?php echo $urgentCount; ?></strong>
</div>
<div style="display:flex;justify-content:space-between;align-items:center">
<span style="font-size:0.88rem;color:#4a4458">Total</span>
<strong style="font-size:1.2rem;color:#7c3aed"><?php echo $totalCount; ?></strong>
</div>
</div>
</div>

<div class="s-panel">
<div class="s-panel-header">
<div>
<h3>Priority Guide</h3>
</div>
</div>
<div style="padding:8px 0">
<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
<span class="s-tags high">High</span>
<span style="font-size:0.82rem;color:#6b6580">Urgent, due soon or important</span>
</div>
<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
<span class="s-tags medium">Medium</span>
<span style="font-size:0.82rem;color:#6b6580">Important but not urgent</span>
</div>
<div style="display:flex;align-items:center;gap:8px">
<span class="s-tags low">Low</span>
<span style="font-size:0.82rem;color:#6b6580">Can be deferred</span>
</div>
</div>
</div>

<div class="s-panel">
<div class="s-panel-header">
<div>
<h3>Tip</h3>
</div>
</div>
<p style="color:#4a4458;font-size:0.88rem;line-height:1.7">
AI assigns each task a <strong>High</strong>, <strong>Medium</strong>, or <strong>Low</strong> priority from its details and deadline. Use delay logging to track when you postpone tasks.
</p>
</div>
</aside>
</section>

</div>
</main>

</body>
</html>
