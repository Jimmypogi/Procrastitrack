<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__.'/lib/ai_task_priority.php';
$user_id = (int)$_SESSION['user_id'];
$task_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$task_id) {
    header('Location: tasks.php');
    exit();
}

$stmt = mysqli_prepare($conn, 'SELECT id, title, description, deadline, deadline_time, priority, status FROM tasks WHERE id = ? AND user_id = ?');
mysqli_stmt_bind_param($stmt, 'ii', $task_id, $user_id);
mysqli_stmt_execute($stmt);
$task = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$task) {
    header('Location: tasks.php');
    exit();
}
if ($task['status'] === 'Completed') {
    header('Location: view_task.php?id=' . (int)$task_id . '&locked=completed');
    exit();
}

$values = $task;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_csrf_require();
    $values['title'] = trim($_POST['title'] ?? '');
    $values['description'] = trim($_POST['description'] ?? '');
    $values['deadline'] = trim($_POST['deadline'] ?? '');
    $values['deadline_time'] = trim($_POST['deadline_time'] ?? '');
    if ($values['deadline'] === '') $values['deadline_time'] = '';
    $values['status'] = $_POST['status'] ?? '';

    $deadlineDate = $values['deadline'] !== '' ? DateTime::createFromFormat('!Y-m-d', $values['deadline']) : null;
    $validDeadlineTime = $values['deadline_time'] === '' || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $values['deadline_time']);
    if ($values['title'] === '') {
        $error = 'Please enter a task title.';
    } elseif (strlen($values['description']) > 8000) {
        $error = 'Please keep the task description under 8,000 bytes so AI can review it.';
    } elseif ($values['deadline'] !== '' && (!$deadlineDate || $deadlineDate->format('Y-m-d') !== $values['deadline'])) {
        $error = 'Please choose a valid deadline.';
    } elseif (!$validDeadlineTime) {
        $error = 'Choose a valid due time after setting a deadline date.';
    } elseif (!in_array($values['status'], ['Pending', 'In Progress', 'Completed'], true)) {
        $error = 'Please choose a valid status.';
    } else {
        $oldDeadline = $task['deadline'] ? substr((string)$task['deadline'], 0, 10) : '';
        $priorityInputsChanged = $values['title'] !== $task['title']
            || $values['description'] !== (string)$task['description']
            || $values['deadline'] !== $oldDeadline
            || $values['deadline_time'] !== substr((string)$task['deadline_time'], 0, 5);
        if ($priorityInputsChanged) {
            $aiPriority = ai_assign_task_priority($values['title'], $values['description'], $values['deadline'], $values['deadline_time']);
            if (!$aiPriority['ok']) $error = $aiPriority['error'];
            else $values['priority'] = $aiPriority['priority'];
        } else {
            $values['priority'] = $task['priority'];
        }
        if ($error === '') {
        $new_deadline = $values['deadline'] !== '' ? $values['deadline'] : null;
        $new_deadline_time = $values['deadline_time'] !== '' ? $values['deadline_time'] : null;
        $title = $values['title'];
        $description = $values['description'];
        $priority = $values['priority'];
        $status = $values['status'];
        $status_for_completed = $status;
        $status_for_reset = $status;
        $isCompleting = $status === 'Completed' ? 1 : 0;
        try {
            $conn->begin_transaction();
            $lockedTask = $conn->prepare('SELECT deadline, status FROM tasks WHERE id=? AND user_id=? FOR UPDATE');
            $lockedTask->bind_param('ii', $task_id, $user_id);
            $lockedTask->execute();
            $currentTask = $lockedTask->get_result()->fetch_assoc();
            $lockedTask->close();
            if (!$currentTask || $currentTask['status'] === 'Completed') {
                $conn->rollback();
                header('Location: view_task.php?id=' . $task_id);
                exit();
            }
            $stmt = mysqli_prepare($conn, "UPDATE tasks SET title=?, description=?, deadline=?, deadline_time=?, priority=?, completion_deadline=CASE WHEN $isCompleting=1 AND status<>'Completed' THEN deadline WHEN $isCompleting=0 THEN NULL ELSE completion_deadline END, completion_deadline_known=CASE WHEN $isCompleting=1 AND status<>'Completed' THEN 1 WHEN $isCompleting=0 THEN 0 ELSE completion_deadline_known END, completed_at=CASE WHEN ?='Completed' AND status<>'Completed' THEN NOW() WHEN ?<>'Completed' THEN NULL ELSE completed_at END, status=? WHERE id=? AND user_id=?");
            mysqli_stmt_bind_param($stmt, 'ssssssssii', $title, $description, $new_deadline, $new_deadline_time, $priority, $status_for_completed, $status_for_reset, $status, $task_id, $user_id);

            if (mysqli_stmt_execute($stmt)) {
                if ($currentTask['deadline'] !== $new_deadline) {
                    $old_deadline = $currentTask['deadline'];
                    $reason = 'Deadline changed in task editor';
                    $history = mysqli_prepare($conn, 'INSERT INTO task_deadline_history (task_id, user_id, old_deadline, new_deadline, change_reason) VALUES (?, ?, ?, ?, ?)');
                    mysqli_stmt_bind_param($history, 'iisss', $task_id, $user_id, $old_deadline, $new_deadline, $reason);
                    mysqli_stmt_execute($history);
                    mysqli_stmt_close($history);
                }
                $conn->commit();
                header('Location: view_task.php?id=' . $task_id . '&updated=1');
                exit();
            }
            $conn->rollback();
            $error = 'Could not save the changes. Please try again.';
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            error_log('Task update failed: ' . $exception->getMessage());
            $error = 'Could not save the changes. Please try again.';
        }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Task | ProcrastiTrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/student.css">
</head>
<body class="student-body task-edit-page">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<main class="s-main"><div class="s-inner">
    <header class="s-head">
        <div><div class="s-label">Task management</div><h1>Edit task</h1><p>Update the details and keep your plan current.</p></div>
        <a class="s-btn" href="view_task.php?id=<?php echo (int)$task_id; ?>"><i class="app-icon" data-lucide="arrow-left" aria-hidden="true"></i>Back to task</a>
    </header>
    <section class="s-panel task-editor-card">
        <?php if ($error !== ''): ?><div class="task-editor-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <form method="post" action="edit_task.php?id=<?php echo (int)$task_id; ?>">
            <?php app_csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int)$task_id; ?>">
            <div class="task-editor-grid">
                <label class="task-editor-field full">Task title<input type="text" name="title" maxlength="255" value="<?php echo htmlspecialchars($values['title'], ENT_QUOTES, 'UTF-8'); ?>" required></label>
                <label class="task-editor-field">Deadline<input type="date" name="deadline" value="<?php echo htmlspecialchars(substr((string)$values['deadline'], 0, 10), ENT_QUOTES, 'UTF-8'); ?>"><small>Optional</small></label>
                <label class="task-editor-field">Due time<input type="time" name="deadline_time" step="60" value="<?php echo htmlspecialchars(substr((string)$values['deadline_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?>"><small>Optional · Philippine time · without a time, due by end of day</small></label>
                <div class="task-editor-field"><span>AI-assigned priority</span><div class="task-ai-priority"><strong><?php echo htmlspecialchars($values['priority'], ENT_QUOTES, 'UTF-8'); ?></strong><small>AI reassesses priority from the task and deadline when either changes.</small></div></div>
                <label class="task-editor-field">Status<select name="status"><option value="Pending" <?php echo $values['status'] === 'Pending' ? 'selected' : ''; ?>>Pending</option><option value="In Progress" <?php echo $values['status'] === 'In Progress' ? 'selected' : ''; ?>>In progress</option><option value="Completed" <?php echo $values['status'] === 'Completed' ? 'selected' : ''; ?>>Completed</option></select></label>
                <label class="task-editor-field full">Description<textarea name="description" maxlength="8000" rows="5" placeholder="Add details or notes for this task"><?php echo htmlspecialchars((string)$values['description'], ENT_QUOTES, 'UTF-8'); ?></textarea></label>
            </div>
            <div class="task-editor-actions"><a class="s-btn" href="tasks.php">Cancel</a><button class="s-btn primary" type="submit"><i class="app-icon" data-lucide="save" aria-hidden="true"></i>Save changes</button></div>
        </form>
    </section>
</div></main>
<style>
.task-edit-page .task-editor-card { max-width: 900px; }
.task-editor-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
.task-editor-field { display: grid; gap: 7px; color: #59665e; font-size: .78rem; font-weight: 700; }
.task-editor-field.full { grid-column: 1 / -1; }
.task-editor-field input, .task-editor-field select, .task-editor-field textarea { width: 100%; min-height: 44px; padding: 10px 12px; border: 1px solid #dfe7e1; border-radius: 10px; background: #fbfcfb; color: #27352d; font: 400 .9rem Inter, sans-serif; outline: 0; }
.task-editor-field textarea { min-height: 120px; resize: vertical; }
.task-editor-field input:focus, .task-editor-field select:focus, .task-editor-field textarea:focus { border-color: #789b84; box-shadow: 0 0 0 3px rgba(120,155,132,.15); }
.task-editor-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; padding-top: 18px; border-top: 1px solid #edf1ee; }
.task-editor-error { margin-bottom: 16px; padding: 12px 14px; border: 1px solid #f2c9c3; border-radius: 10px; background: #fff6f4; color: #a94e43; font-size: .85rem; }
@media(max-width:640px) { .task-editor-grid { grid-template-columns: 1fr; gap: 14px; } .task-editor-field.full { grid-column: auto; } .task-editor-actions { flex-direction: column-reverse; } .task-editor-actions .s-btn { justify-content: center; } }
body.dark-mode.task-edit-page .task-editor-field { color: #bdc9c0; }
body.dark-mode.task-edit-page .task-editor-field input, body.dark-mode.task-edit-page .task-editor-field select, body.dark-mode.task-edit-page .task-editor-field textarea { border-color: #3d4a42; background: #26312b; color: #e0e9e2; }
body.dark-mode.task-edit-page .task-editor-actions { border-color: #34423a; }
</style>
</body>
</html>
