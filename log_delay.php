<?php
session_start();

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

include 'config/database.php';
require_once __DIR__ . '/csrf.php';
$user_id = intval($_SESSION['user_id']);

$message = '';
$message_type = '';

function delay_task_is_active($conn, $taskId, $userId) {
    $stmt = $conn->prepare("SELECT id FROM tasks WHERE id=? AND user_id=? AND status IN ('Pending','In Progress')");
    $stmt->bind_param('ii', $taskId, $userId);
    $stmt->execute();
    $active = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $active;
}

function delay_coaching_key($reason) {
    $text = function_exists('mb_strtolower') ? mb_strtolower($reason, 'UTF-8') : strtolower($reason);
    $signals = [
        'distraction' => ['phone', 'cellphone', 'social media', 'notification', 'distraction', 'distracted', 'youtube', 'tiktok', 'facebook', 'instagram', 'duwa', 'saba', 'noise', 'chat'],
        'technical' => ['internet', 'wifi', 'laptop', 'computer', 'technical', 'error', 'bug', 'device', 'signal'],
        'unclear' => ['confus', 'unclear', 'don\'t understand', 'dont understand', 'dili kasabot', 'libog', 'lisod', 'hard', 'difficult', 'requirement'],
        'overwhelmed' => ['overwhelm', 'too much', 'many tasks', 'daghan', 'deadline', 'busy', 'no time', 'walay oras', 'kulang oras'],
        'tired' => ['tired', 'sleepy', 'sleep', 'exhaust', 'kapoy', 'sakit', 'low energy'],
        'motivation' => ['motivation', 'unmotivated', 'lazy', 'tapol', 'procrastinat', 'cannot start', 'can\'t start', 'dili ganahan'],
        'interruption' => ['interrupted', 'family', 'unexpected', 'emergency', 'visitor', 'errand', 'interrupt', 'naay bisita'],
    ];

    foreach ($signals as $key => $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($text, $keyword) !== false) return $key;
        }
    }
    return 'general';
}

function delay_coaching_content($key) {
    $messages = [
        'distraction' => ['title' => 'You noticed what pulled you away.', 'body' => 'Distractions happen; noticing the pattern gives you a chance to shape your space next time. You do not need to fix everything at once.', 'step' => 'Put one distraction out of reach, open the task you chose, and work on it for five minutes.'],
        'technical' => ['title' => 'A blocker is not a personal failure.', 'body' => 'Technical problems can stop anyone in their tracks. You recorded what happened, so you can choose a practical workaround instead of blaming yourself.', 'step' => 'Write down the exact issue, then try one workaround or ask one person for help.'],
        'unclear' => ['title' => 'It is okay to need clarity.', 'body' => 'Getting stuck can mean the next step is unclear, not that you cannot do the task. You have already identified where you need support.', 'step' => 'Turn the task into one question you can answer, or ask someone to clarify the first requirement.'],
        'overwhelmed' => ['title' => 'You do not have to solve it all today.', 'body' => 'When a task feels too big, starting can feel heavy. You have named what is making it difficult; now you can make the first part smaller.', 'step' => 'Choose the smallest useful piece and give it ten focused minutes. Leave the rest for later.'],
        'tired' => ['title' => 'Your energy matters too.', 'body' => 'Being tired can make even simple work harder. Logging it helps you notice what you need, and rest can be part of a productive plan.', 'step' => 'Take a short reset, get some water, then decide whether to rest more or do one light task.'],
        'motivation' => ['title' => 'Motivation can come after you begin.', 'body' => 'You do not need to feel fully ready before taking a first step. Showing up and being honest about the delay is already a fresh start.', 'step' => 'Make the task tiny: open your notes, write one line, or work for just five minutes.'],
        'interruption' => ['title' => 'Plans can change; you can restart.', 'body' => 'Unexpected things interrupt everyone. The delay does not erase your progress, and you can return when you have a little room again.', 'step' => 'Pick a realistic time to continue and leave yourself one note about where to restart.'],
        'general' => ['title' => 'You showed up for yourself.', 'body' => 'A delay does not define you. Noticing what got in the way is useful progress, and logging it took honesty. You can begin again one small step at a time.', 'step' => 'Choose one task and spend five minutes getting started. That is enough for now.'],
    ];
    return $messages[$key] ?? $messages['general'];
}

$moods = ['Motivated', 'Neutral', 'Distracted', 'Overwhelmed', 'Tired', 'Anxious'];
$values = ['task_id' => (string)(filter_input(INPUT_GET, 'task', FILTER_VALIDATE_INT) ?: ''), 'reason' => '', 'duration_minutes' => '', 'mood' => '', 'notes' => ''];

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    app_csrf_require();
    if (($_POST['action'] ?? '') === 'edit_delay') {
        $entryId = filter_var($_POST['entry_id'] ?? null, FILTER_VALIDATE_INT);
        $editedReason = is_string($_POST['edited_reason'] ?? null) ? trim($_POST['edited_reason']) : '';
        if (!$entryId || $entryId < 1 || $editedReason === '' || strlen($editedReason) > 5000) {
            $message = 'Please enter a reason (up to 5,000 bytes) before saving.';
        } else {
            $check = $conn->prepare("SELECT id FROM procrastiblock_logs WHERE id=? AND user_id=? AND log_type='delay' LIMIT 1");
            $check->bind_param('ii', $entryId, $user_id);
            $check->execute();
            $ownsEntry = $check->get_result()->num_rows === 1;
            $check->close();
            if ($ownsEntry) {
                $stmt = $conn->prepare("UPDATE procrastiblock_logs SET reason=? WHERE id=? AND user_id=? AND log_type='delay'");
                $stmt->bind_param('sii', $editedReason, $entryId, $user_id);
                $updated = $stmt->execute();
                $stmt->close();
                if ($updated) {
                    header('Location: log_delay.php?updated=1#delay-' . $entryId);
                    exit();
                }
            }
            $message = 'Could not update that delay reason. Please try again.';
        }
        $message_type = 'error';
    } else {
    foreach ($values as $field => $value) {
        $values[$field] = is_string($_POST[$field] ?? '') ? trim($_POST[$field] ?? '') : '';
    }
    $task_id = $values['task_id'] === '' ? null : filter_var($values['task_id'], FILTER_VALIDATE_INT);
    if ($task_id === null || $task_id === false || $task_id < 1) {
        $message = 'Choose an available task before logging a delay.';
    } elseif ($values['reason'] === '' || strlen($values['reason']) > 5000) {
        $message = 'Describe the reason for delay using up to 5,000 bytes of text.';
    } elseif (strlen($values['notes']) > 5000) {
        $message = 'Please shorten your additional notes (maximum 5,000 bytes).';
    } elseif (!app_optional_int_in_range($values['duration_minutes'], 1, 1440)) {
        $message = 'Duration lost must be a whole number from 1 to 1,440 minutes, or left blank.';
    } elseif ($values['mood'] !== '' && !in_array($values['mood'], $moods, true)) {
        $message = 'Please select a mood from the list.';
    } elseif ($task_id !== null && (!$task_id || $task_id < 1 || !delay_task_is_active($conn, $task_id, $user_id))) {
        $message = 'That task is completed or unavailable. Choose an active task or edit its existing entry in your delay history.';
    } elseif ($task_id !== null && app_task_has_delay($conn, $task_id, $user_id)) {
        $message = 'A delay reason is already saved for that task. Edit the existing entry in your delay history instead.';
    } else {
        $reason = $values['reason'];
        $duration = $values['duration_minutes'] === '' ? null : (int)$values['duration_minutes'];
        $mood = $values['mood'] === '' ? null : $values['mood'];
        $notes = $values['notes'] === '' ? null : $values['notes'];
        try {
            $stmt = $conn->prepare("INSERT INTO procrastiblock_logs (user_id, log_type, task_id, reason, duration_minutes, mood, notes) VALUES (?, 'delay', ?, ?, ?, ?, ?)");
            $stmt->bind_param('iisiss', $user_id, $task_id, $reason, $duration, $mood, $notes);
            $saved = $stmt->execute();
            $stmt->close();
        } catch (mysqli_sql_exception $error) {
            error_log('Delay log save failed: ' . $error->getMessage());
            $saved = false;
        }
        if ($saved) {
            $_SESSION['delay_saved_notice'] = true;
            // Notification failure must not make a saved log look unsaved.
            try {
                $title = 'Delay Logged';
                $body = 'You logged a delay: ' . substr($reason, 0, 100);
                $notification = $conn->prepare("INSERT INTO notifications (user_id, title, message, link, priority, created_at) VALUES (?, ?, ?, 'log_delay.php', 'normal', NOW())");
                if ($notification) {
                    $notification->bind_param('iss', $user_id, $title, $body);
                    $notification->execute();
                    $notification->close();
                }
            } catch (mysqli_sql_exception $error) {
                error_log('Delay notification failed: ' . $error->getMessage());
            }
            header('Location: log_delay.php');
            exit();
        }
        $message = 'Could not save your delay. Your details are still here; please try again.';
    }
    $message_type = 'error';
    }
}

$tasks = mysqli_query($conn, "SELECT t.id, t.title, t.priority, t.status FROM tasks t WHERE t.user_id=$user_id AND t.status IN ('Pending','In Progress') AND NOT EXISTS (SELECT 1 FROM procrastiblock_logs dl WHERE dl.task_id=t.id AND dl.user_id=t.user_id AND dl.log_type='delay') ORDER BY t.deadline ASC, t.id DESC");
$availableTaskCount = mysqli_num_rows($tasks);
if ($values['task_id'] !== '') {
    $selectedTaskId = filter_var($values['task_id'], FILTER_VALIDATE_INT);
    $selectedTaskValid = false;
    if ($selectedTaskId && $selectedTaskId > 0) {
        $selectedTask = $conn->prepare("SELECT t.id FROM tasks t WHERE t.id=? AND t.user_id=? AND t.status IN ('Pending','In Progress') AND NOT EXISTS (SELECT 1 FROM procrastiblock_logs dl WHERE dl.task_id=t.id AND dl.user_id=t.user_id AND dl.log_type='delay') LIMIT 1");
        $selectedTask->bind_param('ii', $selectedTaskId, $user_id);
        $selectedTask->execute();
        $selectedTaskValid = $selectedTask->get_result()->num_rows === 1;
        $selectedTask->close();
    }
    if (!$selectedTaskValid) $values['task_id'] = '';
}
$activeTaskCount = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM tasks WHERE user_id=$user_id AND status IN ('Pending','In Progress')"))['total'];
$recentDelays = mysqli_query($conn, "SELECT dl.*, t.title as task_title FROM procrastiblock_logs dl LEFT JOIN tasks t ON dl.task_id=t.id AND t.user_id=dl.user_id WHERE dl.user_id=$user_id AND dl.log_type='delay' ORDER BY dl.created_at DESC, dl.id DESC");
$delayHistoryCount = $recentDelays ? mysqli_num_rows($recentDelays) : 0;
function delay_form_value($field) {
    global $values;
    return htmlspecialchars($values[$field], ENT_QUOTES, 'UTF-8');
}

function app_task_has_delay($conn, $taskId, $userId) {
    $stmt = $conn->prepare("SELECT id FROM procrastiblock_logs WHERE task_id=? AND user_id=? AND log_type='delay' LIMIT 1");
    $stmt->bind_param('ii', $taskId, $userId);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

$delayCount = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='delay'"));
$totalDelayMin = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(duration_minutes),0) as total FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='delay'"));
$latestDelay = mysqli_fetch_assoc(mysqli_query($conn, "SELECT reason FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='delay' ORDER BY created_at DESC, id DESC LIMIT 1"));
$coachContent = delay_coaching_content(delay_coaching_key($latestDelay['reason'] ?? ''));
$showSavedNotice = !empty($_SESSION['delay_saved_notice']);
unset($_SESSION['delay_saved_notice']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log Delay — ProcrastiTrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/student.css">
<style>
.delay-coach-card{margin-top:16px;padding:17px 18px;border:1px solid #dce9df;border-radius:15px;background:linear-gradient(145deg,#f5faf6,#edf5ef)}
.delay-coach-kicker{margin:0 0 7px;color:#6d8f77;font-size:.68rem;font-weight:800;letter-spacing:.11em;text-transform:uppercase}
.delay-coach-card h4{margin:0;color:#31563e;font-size:.98rem;letter-spacing:-.015em}
.delay-coach-card p{margin:8px 0 0;color:#627369;font-size:.84rem;line-height:1.6}
.delay-coach-step{margin-top:12px;padding:12px 13px;border-left:3px solid #83a88c;border-radius:0 10px 10px 0;background:rgba(255,255,255,.75);color:#4c6353;font-size:.82rem;line-height:1.55}
.delay-coach-step strong{display:block;margin-bottom:3px;color:#426a4d;font-size:.68rem;letter-spacing:.07em;text-transform:uppercase}
.delay-saved-notice{padding:12px 16px;border:1px solid #bbf7d0;border-radius:14px;background:#f0fdf4;color:#16803c;font-size:.9rem;font-weight:650}
.delay-task-prefilled{display:flex;align-items:center;gap:9px;margin:0 0 16px;padding:11px 13px;border:1px solid #dce9df;border-radius:11px;background:#f1f7f2;color:#456b50;font-size:.78rem;line-height:1.45}
.delay-task-prefilled .app-icon{width:17px;height:17px;flex:0 0 17px}
body.dark-mode .delay-task-prefilled{border-color:#405747;background:#293b2f;color:#c2d7c5}
body.dark-mode .delay-coach-card{border-color:#405747;background:linear-gradient(145deg,#25372b,#293c30)}
body.dark-mode .delay-coach-card h4,body.dark-mode .delay-coach-step strong{color:#c8e0ce}
body.dark-mode .delay-coach-card p,body.dark-mode .delay-coach-step{color:#b5c8b9}
body.dark-mode .delay-coach-step{background:rgba(22,34,25,.5)}
body.dark-mode .delay-records-trigger:hover,body.dark-mode .delay-records[open]>.delay-records-trigger{background:#25342a}
body.dark-mode .delay-records-copy strong{color:#d7e5d9}
body.dark-mode .delay-records-copy small{color:#a8b8ab}
body.dark-mode .delay-records-icon,body.dark-mode .delay-records-count{border-color:#405747;background:#2d4033;color:#c2d7c5}
body.dark-mode .delay-records[open]>.delay-records-trigger,body.dark-mode .delay-edit-form{border-color:#405747}
.delay-records-panel{padding:0!important;overflow:hidden}
.delay-records-trigger{display:flex;align-items:center;gap:14px;min-height:82px;padding:18px 22px;cursor:pointer;list-style:none;transition:background .18s ease}
.delay-records-trigger::-webkit-details-marker{display:none}
.delay-records-trigger:hover{background:#f8faf8}
.delay-records-trigger:focus-visible{outline:3px solid rgba(103,146,116,.28);outline-offset:-4px}
.delay-records-icon{display:grid;place-items:center;flex:0 0 44px;width:44px;height:44px;border:1px solid #dce9df;border-radius:14px;background:#f1f7f2;color:#5c8067}
.delay-records-copy{display:grid;gap:4px;min-width:0;flex:1}
.delay-records-copy strong{color:#304b38;font-size:.91rem;letter-spacing:-.015em}
.delay-records-copy small{color:#78867c;font-size:.76rem;line-height:1.45}
.delay-records-count{padding:6px 10px;border:1px solid #e2ebe3;border-radius:999px;background:#f7faf7;color:#5d7964;font-size:.7rem;font-weight:700;white-space:nowrap}
.delay-records-chevron{flex:0 0 auto;color:#718a75;font-size:1rem;transition:transform .18s ease}
.delay-records[open]>.delay-records-trigger{border-bottom:1px solid #e7ede8;background:#fbfcfb}
.delay-records[open]>.delay-records-trigger .delay-records-chevron{transform:rotate(180deg)}
.delay-records-content{padding:22px}
.delay-records-content .s-panel-header{margin-bottom:14px}
.delay-history{max-height:none;overflow:visible;padding:0;margin:0;scrollbar-width:auto}
.delay-history-item{border:1px solid #e9eee9;background:#fbfcfb!important;transition:border-color .18s ease,box-shadow .18s ease,background .18s ease!important}
.delay-history-item:hover{border-color:#d8e6da;background:#f6faf6!important;box-shadow:0 5px 16px rgba(49,86,62,.06)}
.delay-history-item:target{border-color:#83ad8d;background:#f4faf4!important;box-shadow:0 0 0 3px rgba(112,151,126,.12)}
.delay-history-item>div{min-width:0;flex:1}
.delay-history-item .s-list-text{overflow-wrap:anywhere}
.delay-edit,.delay-extra-notes{margin-top:10px;color:#587161;font-size:.82rem}
.delay-edit summary,.delay-extra-notes summary{width:max-content;cursor:pointer;font-weight:700}
.delay-edit summary:hover,.delay-extra-notes summary:hover{color:#31563e}
.delay-edit-form{display:grid;gap:9px;margin-top:12px;padding:14px;border:1px solid #e2ebe3;border-radius:13px;background:#fff}
.delay-edit-form label{font-size:.8rem;font-weight:700;color:#405847}
.delay-edit-form textarea{width:100%;min-height:82px;padding:11px 12px;border:1px solid #dce6dd;border-radius:10px;background:#fbfcfb;color:#27382d;font:inherit;line-height:1.5;resize:vertical}
.delay-edit-form textarea:focus{outline:3px solid rgba(103,146,116,.18);border-color:#82a98b}
.delay-edit-form .s-btn{width:max-content;min-height:39px;border-color:#d9e7db;background:#edf5ee;color:#3f6b4b;cursor:pointer}
.delay-edit-form .s-btn:hover{background:#e2efe4;color:#31563e}
#save-delay-button:disabled{border-color:#d8e1da!important;background:#e8ede9!important;box-shadow:none!important;color:#89948c!important;cursor:not-allowed;opacity:1;transform:none!important}
#delay-task:disabled{border-color:#e3e9e4!important;background:#f2f5f2!important;color:#87938a!important;cursor:not-allowed}
@media(max-width:640px){.delay-records-trigger{min-height:74px;gap:10px;padding:15px}.delay-records-icon{width:40px;height:40px;flex-basis:40px;border-radius:12px}.delay-records-copy small{font-size:.7rem}.delay-records-count{padding:5px 8px;font-size:.65rem}.delay-records-content{padding:16px}.delay-edit-form .s-btn{width:100%;justify-content:center}}
@media(max-width:360px){.delay-records-trigger{flex-wrap:wrap}.delay-records-copy{flex-basis:calc(100% - 58px)}.delay-records-count{margin-left:54px}.delay-records-chevron{margin-left:auto}}
@media(prefers-reduced-motion:reduce){.delay-records-trigger,.delay-records-chevron{transition:none}}
</style>
</head>
<body class="student-body">
<?php include 'includes/sidebar.php'; ?>

<main class="s-main">
<div class="s-inner">

<header class="s-head">
<div>
<div class="s-label">Delay Logger</div>
<h1>Log Reason for Delay</h1>
<p>Track why tasks are delayed and understand your procrastination patterns.</p>
</div>
<div class="s-head-actions">
<a href="tasks.php" class="s-btn">&#8592; Back to Tasks</a>
</div>
</header>

<?php if($showSavedNotice): ?>
<div class="delay-saved-notice" role="status">Delay logged successfully. Your reflection and tailored next step are in the summary.</div>
<?php endif; ?>

<?php if(isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
<div class="s-panel" role="status" style="background:#f0fdf4;border-color:#bbf7d0">
<div style="color:#16803c;font-weight:600;padding:12px 16px;border-radius:8px">Delay reason updated. Your other log details are unchanged.</div>
</div>
<?php endif; ?>

<?php if(isset($_GET['duplicate']) && $_GET['duplicate'] === '1'): ?>
<div class="s-panel" role="status" style="background:#f5f8f5;border-color:#dce8de">
<div style="color:#46634e;font-weight:600;padding:12px 16px;border-radius:8px">This task already has a delay entry. You can edit its reason in your history below.</div>
</div>
<?php endif; ?>

<?php if($message): ?>
<div class="s-panel" role="alert" style="background:<?php echo $message_type === 'error' ? '#fef2f2' : '#f0fdf4'; ?>;border-color:<?php echo $message_type === 'error' ? '#fecaca' : '#bbf7d0'; ?>">
<div style="color:<?php echo $message_type === 'error' ? '#dc2626' : '#16a34a'; ?>;font-weight:600;padding:12px 16px;border-radius:8px"><?php echo htmlspecialchars($message); ?></div>
</div>
<?php endif; ?>

<section class="s-two">
<div class="s-panel">
<div class="s-panel-header">
<div>
<div class="s-label">New Entry</div>
<h3>Why were you delayed?</h3>
</div>
</div>

<p class="s-card-meta">Choose the task that was delayed, then note what got in the way. Logging it will not change the task status.</p>
<?php if($values['task_id'] !== ''): ?><div class="delay-task-prefilled" role="status"><i class="app-icon" data-lucide="circle-check" aria-hidden="true"></i><span>This task is selected below. Add a reason to keep the delay in your history.</span></div><?php endif; ?>
<form method="POST" class="s-form-grid"><?php app_csrf_field(); ?>
<div class="full">
<label for="delay-task">Related task <span style="color:#dc2626">*</span></label>
<select id="delay-task" name="task_id" required <?php echo $availableTaskCount === 0 ? 'disabled' : ''; ?>>
<option value="" disabled <?php echo $values['task_id'] === '' ? 'selected' : ''; ?>>Choose a task to log a delay</option>
<?php while($task = mysqli_fetch_assoc($tasks)): ?>
<option value="<?php echo (int)$task['id']; ?>" <?php echo (string)$values['task_id'] === (string)$task['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($task['title']); ?> · <?php echo htmlspecialchars($task['priority']); ?> priority</option>
<?php endwhile; ?>
</select>
<p class="s-card-meta"><?php if($availableTaskCount > 0): ?>Each task can have one delay entry. You can edit its reason later from your history.<?php elseif($activeTaskCount > 0): ?>All active tasks already have a delay entry. Edit an existing reason in your history below.<?php else: ?>You need an active task before you can log a delay. Add a task or return when one is active.<?php endif; ?></p>
</div>

<div class="full">
<label>Reason for Delay <span style="color:#dc2626">*</span></label>
<textarea name="reason" rows="4" placeholder="Describe what caused the delay (e.g., misunderstood requirements, lack of motivation, technical issues, distractions...)" maxlength="5000" required><?php echo delay_form_value('reason'); ?></textarea>
</div>

<div>
<label>Duration Lost (minutes, optional)</label>
<input type="number" name="duration_minutes" value="<?php echo delay_form_value('duration_minutes'); ?>" min="1" max="1440" step="1" placeholder="30">
</div>

<div>
<label>Mood</label>
<select name="mood">
<option value="">Select mood</option>
<option value="Motivated" <?php echo $values['mood'] === 'Motivated' ? 'selected' : ''; ?>>Motivated</option>
<option value="Neutral" <?php echo $values['mood'] === 'Neutral' ? 'selected' : ''; ?>>Neutral</option>
<option value="Distracted" <?php echo $values['mood'] === 'Distracted' ? 'selected' : ''; ?>>Distracted</option>
<option value="Overwhelmed" <?php echo $values['mood'] === 'Overwhelmed' ? 'selected' : ''; ?>>Overwhelmed</option>
<option value="Tired" <?php echo $values['mood'] === 'Tired' ? 'selected' : ''; ?>>Tired</option>
<option value="Anxious" <?php echo $values['mood'] === 'Anxious' ? 'selected' : ''; ?>>Anxious</option>
</select>
</div>

<div class="full">
<label>Additional Notes</label>
<textarea name="notes" rows="2" placeholder="Anything else you want to remember?" maxlength="5000"><?php echo delay_form_value('notes'); ?></textarea>
</div>

<div class="full">
<button type="submit" name="save_delay" id="save-delay-button" class="s-btn primary" style="width:100%;justify-content:center" <?php echo $availableTaskCount === 0 ? 'disabled aria-disabled="true"' : ''; ?>>Log Delay</button>
</div>
</form>
</div>

<aside style="display:flex;flex-direction:column;gap:18px">
<div class="s-panel">
<div class="s-panel-header">
<div>
<div class="s-label">Your Stats</div>
<h3>Delay summary</h3>
</div>
</div>
<div style="text-align:center;padding:12px 0">
<div style="font-size:2.4rem;font-weight:800;color:#dc2626"><?php echo $delayCount; ?></div>
<div style="color:#6b6580;font-size:0.85rem">Total delays logged</div>
</div>
<div style="padding:12px 0;border-top:1px solid #f3f0f7">
<div style="font-size:1.6rem;font-weight:700;color:#f59e0b"><?php echo $totalDelayMin['total']; ?></div>
<div style="color:#6b6580;font-size:0.85rem">Minutes lost to delays</div>
</div>
<section class="delay-coach-card" aria-labelledby="delayCoachTitle">
<p class="delay-coach-kicker">A reflection for your next step</p>
<h4 id="delayCoachTitle"><?php echo htmlspecialchars($coachContent['title'], ENT_QUOTES, 'UTF-8'); ?></h4>
<p><?php echo htmlspecialchars($coachContent['body'], ENT_QUOTES, 'UTF-8'); ?></p>
<div class="delay-coach-step"><strong>Try this next</strong><?php echo htmlspecialchars($coachContent['step'], ENT_QUOTES, 'UTF-8'); ?></div>
</section>
</div>

<div class="s-panel delay-records-panel">
<details class="delay-records" id="delayRecords" <?php echo !empty($_GET['updated']) || !empty($_GET['show_history']) ? 'open' : ''; ?>>
<summary class="delay-records-trigger">
<span class="delay-records-icon" aria-hidden="true"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-3"/><path d="M16 3.5a2.1 2.1 0 0 1 3 3L11 14.5 7 15.5l1-4z"/><path d="M8 7h3M8 11h1"/></svg></span>
<span class="delay-records-copy"><strong>Check records</strong><small>Review your saved delay history and edit a reason if needed.</small></span>
<span class="delay-records-count"><?php echo $delayHistoryCount; ?> records</span>
<span class="delay-records-chevron" aria-hidden="true">&#9662;</span>
</summary>
<div class="delay-records-content">
<div class="s-panel-header"><div><div class="s-label">Your records</div><h3>Delay history</h3></div></div>
<?php if($delayHistoryCount > 0): ?>
<ul class="s-list delay-history">
<?php while($entry = mysqli_fetch_assoc($recentDelays)): ?>
<li id="delay-<?php echo (int)$entry['id']; ?>" class="delay-history-item">
<span class="s-dot orange"></span>
<div>
<div class="s-list-text"><strong><?php echo htmlspecialchars($entry['task_title'] ?? 'General'); ?></strong>
<br><span style="font-size:0.8rem;color:#6b6580"><?php echo nl2br(htmlspecialchars($entry['reason'] ?? '', ENT_QUOTES, 'UTF-8')); ?></span></div>
<div class="s-list-time"><?php echo date('M d, Y h:i A', strtotime($entry['created_at'])); ?>
<?php if($entry['mood']): ?> &middot; <?php echo htmlspecialchars($entry['mood']); ?><?php endif; ?>
<?php if($entry['notes']): ?><details><summary>Additional notes</summary><p style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo htmlspecialchars($entry['notes']); ?></p></details><?php endif; ?>
<?php if($entry['duration_minutes']): ?> · <?php echo $entry['duration_minutes']; ?>min lost<?php endif; ?>
</div>
<details class="delay-edit">
<summary>Edit reason</summary>
<form method="POST" class="delay-edit-form">
<?php app_csrf_field(); ?>
<input type="hidden" name="action" value="edit_delay">
<input type="hidden" name="entry_id" value="<?php echo (int)$entry['id']; ?>">
<label for="edit-reason-<?php echo (int)$entry['id']; ?>">Update your reason</label>
<textarea id="edit-reason-<?php echo (int)$entry['id']; ?>" name="edited_reason" rows="3" maxlength="5000" required><?php echo htmlspecialchars($entry['reason'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
<button type="submit" class="s-btn primary">Save changes</button>
</form>
</details>
</div>
</li>
<?php endwhile; ?>
</ul>
<?php else: ?>
<div class="s-empty">No delays logged yet.</div>
<?php endif; ?>
</div>
</details>
</div>
</aside>
</section>

</div>
</main>

<script>
(() => {
    const taskSelect = document.getElementById('delay-task');
    const submitButton = document.getElementById('save-delay-button');
    if (!taskSelect || !submitButton || taskSelect.disabled) return;
    const syncTaskRequirement = () => {
        const hasTask = taskSelect.value !== '';
        submitButton.disabled = !hasTask;
        submitButton.setAttribute('aria-disabled', String(!hasTask));
        submitButton.title = hasTask ? '' : 'Choose a task before logging a delay.';
    };
    taskSelect.addEventListener('change', syncTaskRequirement);
    syncTaskRequirement();
})();
</script>

</body>
</html>
