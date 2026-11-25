<?php
session_start();

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

include 'config/database.php';
$user_id = intval($_SESSION['user_id']);
$prefColumns = [];
$prefResult = mysqli_query($conn, "SHOW COLUMNS FROM users");
if ($prefResult) while ($prefColumn = mysqli_fetch_assoc($prefResult)) $prefColumns[$prefColumn['Field']] = true;
$focusColumn = isset($prefColumns['pomodoro_interval']) ? 'pomodoro_interval' : '25';
$breakColumn = isset($prefColumns['break_duration']) ? 'break_duration' : '5';
$userPrefs = mysqli_fetch_assoc(mysqli_query($conn, "SELECT $focusColumn AS pomodoro_interval, $breakColumn AS break_duration FROM users WHERE id=$user_id")) ?: [];
$pomodoroMinutes = max(5, min(180, (int)($userPrefs['pomodoro_interval'] ?? 25)));
$breakMinutes = max(1, min(60, (int)($userPrefs['break_duration'] ?? 5)));
if (!isset($_SESSION['pomodoro_csrf'])) $_SESSION['pomodoro_csrf'] = bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_pomodoro_phase'])) {
    header('Content-Type: application/json; charset=utf-8');
    $phase = $_POST['phase'] ?? '';
    $duration = (int)($_POST['duration_minutes'] ?? 0);
    $taskId = !empty($_POST['task_id']) ? (int)$_POST['task_id'] : null;
    $subject = trim($_POST['subject'] ?? '');
    if (!hash_equals($_SESSION['pomodoro_csrf'], $_POST['csrf'] ?? '') || !in_array($phase, ['focus', 'break'], true) || $duration < 1 || $duration > 180) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'The timer session expired. Reload the page and start again.']); exit();
    }
    if ($taskId !== null) {
        $checkTask = $conn->prepare('SELECT id FROM tasks WHERE id = ? AND user_id = ?');
        $checkTask->bind_param('ii', $taskId, $user_id);
        $checkTask->execute();
        $validTask = $checkTask->get_result()->num_rows > 0;
        $checkTask->close();
        // A saved timer can outlive its task or the account that started it. Keep
        // the completed session, but detach any task ID the user no longer owns.
        if (!$validTask) $taskId = null;
    }
    $date = date('Y-m-d');
    $end = date('H:i:s');
    $start = date('H:i:s', time() - ($duration * 60));
    if ($phase === 'focus') {
        $stmt = $conn->prepare('INSERT INTO study_sessions (user_id, task_id, subject, duration_minutes, session_date, start_time, end_time, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Could not prepare the study session record.']); exit(); }
        $notes = 'Completed Pomodoro focus session';
        $stmt->bind_param('iisissss', $user_id, $taskId, $subject, $duration, $date, $start, $end, $notes);
    } else {
        $stmt = $conn->prepare("INSERT INTO procrastiblock_logs (user_id, log_type, task_id, reason, duration_minutes, created_at) VALUES (?, 'break', ?, 'Completed Pomodoro break', ?, NOW())");
        if (!$stmt) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Could not prepare the break record.']); exit(); }
        $stmt->bind_param('iii', $user_id, $taskId, $duration);
    }
    $ok = $stmt && $stmt->execute();
    if ($stmt) $stmt->close();
    if (!$ok) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'The database could not save this timer phase.']); exit(); }
    echo json_encode(['ok' => true]);
    exit();
}

$tasks = mysqli_query($conn, "SELECT id, title, priority, status FROM tasks WHERE user_id=$user_id AND status<>'Completed' ORDER BY deadline ASC, id DESC");

$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weeklyMinutes = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(duration_minutes),0) AS total FROM study_sessions WHERE user_id=$user_id AND session_date BETWEEN '$weekStart' AND '$today'"));
$weeklyProgress = min(100, round(((int)$weeklyMinutes['total'] / 600) * 100, 1));
$weeklyHours = round((int)$weeklyMinutes['total'] / 60, 1);
$weeklyMinutesRemaining = max(0, 600 - (int)$weeklyMinutes['total']);
$sessions = mysqli_query($conn, "SELECT ss.*, t.title as task_title FROM study_sessions ss LEFT JOIN tasks t ON ss.task_id = t.id WHERE ss.user_id=$user_id ORDER BY ss.created_at DESC LIMIT 20");

$totalSessions = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM study_sessions WHERE user_id=$user_id"));
$totalMinutes = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(duration_minutes),0) as total FROM study_sessions WHERE user_id=$user_id"));
$todaySessions = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM study_sessions WHERE user_id=$user_id AND session_date='$today'"));
$todayMinutes = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(duration_minutes),0) as total FROM study_sessions WHERE user_id=$user_id AND session_date='$today'"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Study Sessions — ProcrastiTrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/student.css">
<link rel="stylesheet" href="assets/css/study-sessions.css?v=20261124-pomodoro-only">
</head>
<body class="student-body study-sessions-page">
<?php include 'includes/sidebar.php'; ?>

<main class="s-main">
<div class="s-inner">

<header class="s-head">
<div>
<div class="s-label">Your focus studio</div>
<h1>Make time for deep work.</h1>
<p>Start a Pomodoro focus sprint. Completed focus sessions are saved automatically.</p>
</div>
</header>

<section class="s-grid study-session-stats" aria-label="Study session summary">
<article class="s-card">
<div class="session-stat-icon"><i class="app-icon" data-lucide="layers-3" aria-hidden="true"></i></div>
<div class="s-card-label">Study sessions</div>
<div class="s-card-value"><?php echo $totalSessions; ?></div>
<div class="s-card-meta">All time</div>
</article>
<article class="s-card">
<div class="session-stat-icon"><i class="app-icon" data-lucide="hourglass" aria-hidden="true"></i></div>
<div class="s-card-label">Hours invested</div>
<div class="s-card-value"><?php echo number_format((int)$totalMinutes['total'] / 60, 1); ?></div>
<div class="s-card-meta"><?php echo number_format((int)$totalMinutes['total']); ?> <?php echo (int)$totalMinutes['total'] === 1 ? 'minute' : 'minutes'; ?> total</div>
</article>
<article class="s-card">
<div class="session-stat-icon"><i class="app-icon" data-lucide="sun" aria-hidden="true"></i></div>
<div class="s-card-label">Today</div>
<div class="s-card-value"><?php echo $todaySessions; ?></div>
<div class="s-card-meta"><?php echo $todayMinutes['total']; ?> min today</div>
</article>
<article class="s-card">
<div class="session-stat-icon"><i class="app-icon" data-lucide="gauge" aria-hidden="true"></i></div>
<div class="s-card-label">Session average</div>
<div class="s-card-value"><?php echo $totalSessions > 0 ? round($totalMinutes['total'] / $totalSessions) : 0; ?></div>
<div class="s-card-meta">Minutes per focus block</div>
</article>
</section>

<section class="s-two study-session-workspace">
<div class="s-panel focus-panel" id="pomodoroTimer" data-focus-minutes="<?php echo $pomodoroMinutes; ?>" data-break-minutes="<?php echo $breakMinutes; ?>" data-csrf="<?php echo htmlspecialchars($_SESSION['pomodoro_csrf']); ?>">
<div class="focus-panel-heading"><div><div class="focus-kicker"><span class="focus-live-dot"></span>FOCUS MODE</div><h2>One thing at a time.</h2><p>Set your intention, then settle into the work.</p></div><span class="focus-mode-badge"><i class="app-icon" data-lucide="sparkles" aria-hidden="true"></i> Pomodoro</span></div>
<div class="focus-panel-content">
<div class="focus-timer-display">
<div class="pomodoro-dial" id="pomodoroDial"><div class="pomodoro-dial-inner"><div id="pomodoroPhase">Ready for a focus session</div><div id="pomodoroClock" aria-live="polite">--:--</div><span class="pomodoro-dial-caption">MINUTES : SECONDS</span></div></div>
</div>
<div class="focus-controls">
<div class="s-form-grid">
<div><label for="pomodoroFocusMinutes">Focus (minutes)</label><input id="pomodoroFocusMinutes" type="number" min="1" max="180" step="1" required value="<?php echo $pomodoroMinutes; ?>" aria-describedby="pomodoroDurationHelp"></div>
<div><label for="pomodoroBreakMinutes">Break (minutes)</label><input id="pomodoroBreakMinutes" type="number" min="1" max="180" step="1" required value="<?php echo $breakMinutes; ?>" aria-describedby="pomodoroDurationHelp"></div>
<p id="pomodoroDurationHelp" class="full focus-duration-help">Choose 1–180 minutes. Adjust anytime; elapsed time is kept.</p>
<div class="full"><label for="pomodoroTask">Task (optional)</label><select id="pomodoroTask"><option value="">No specific task</option><?php mysqli_data_seek($tasks, 0); while($task = mysqli_fetch_assoc($tasks)): ?><option value="<?php echo (int)$task['id']; ?>"><?php echo htmlspecialchars($task['title']); ?></option><?php endwhile; ?></select></div>
</div>
<div class="focus-actions"><button type="button" id="pomodoroStart" class="s-btn primary"><i class="app-icon" data-lucide="play" aria-hidden="true"></i> Start focus</button><button type="button" id="pomodoroPause" class="s-btn" disabled><i class="app-icon" data-lucide="pause" aria-hidden="true"></i> Pause</button><button type="button" id="pomodoroReset" class="s-btn" disabled><i class="app-icon" data-lucide="rotate-ccw" aria-hidden="true"></i> Reset</button></div>
<p id="pomodoroStatus" class="focus-status" aria-live="polite">Focus sessions save automatically. Breaks are recorded in your activity.</p>
</div>
</div>
</div>

<div id="pomodoroAlert" class="pomodoro-alert-backdrop" hidden role="presentation">
<section class="pomodoro-alert-dialog" role="alertdialog" aria-modal="true" aria-labelledby="pomodoroAlertTitle" aria-describedby="pomodoroAlertMessage">
<span class="pomodoro-alert-mark"><i class="app-icon" data-lucide="timer" aria-hidden="true"></i></span>
<h2 id="pomodoroAlertTitle">Pomodoro update</h2>
<p id="pomodoroAlertMessage"></p>
<button type="button" id="pomodoroAlertDismiss" class="s-btn primary">Got it</button>
</section>
</div>
<aside class="session-sidebar">
<div class="s-panel weekly-panel">
<div class="s-panel-header">
<div>
<div class="s-label">Your momentum</div>
<h3>This week</h3>
</div>
<span class="weekly-goal-label">10 hr goal</span>
</div>
<div class="weekly-total">
<strong><?php echo number_format($weeklyHours, 1); ?><span>h</span></strong>
<span><?php echo number_format((int)$weeklyMinutes['total']); ?> study minutes tracked</span>
</div>
<div class="weekly-progress-track" role="progressbar" aria-label="Weekly study goal" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $weeklyProgress; ?>">
<span style="width:<?php echo $weeklyProgress; ?>%"></span>
</div>
<div class="weekly-progress-copy"><strong><?php echo number_format($weeklyProgress, 1); ?>%</strong><span><?php echo $weeklyMinutesRemaining > 0 ? number_format($weeklyMinutesRemaining) . ' min to your weekly goal' : 'Weekly goal reached — great work!'; ?></span></div>
</div>

<div class="s-panel sessions-panel">
<div class="s-panel-header">
<div>
<div class="s-label">Recent</div>
<h3>Recent study sessions</h3>
</div>
<span class="session-history-count">Last 20</span>
</div>
<?php if(mysqli_num_rows($sessions) > 0): ?>
<ul class="s-list">
<?php while($session = mysqli_fetch_assoc($sessions)): ?>
<?php $sessionTitle = $session['task_title'] ?: ($session['subject'] ?: 'General Study'); $sessionTopic = !empty($session['task_title']) ? $session['subject'] : ''; ?>
<li>
<span class="session-history-icon"><i class="app-icon" data-lucide="book-open" aria-hidden="true"></i></span>
<div class="session-history-copy">
<div class="s-list-text"><strong><?php echo htmlspecialchars($sessionTitle); ?></strong>
<span class="session-history-duration"><?php echo (int)$session['duration_minutes']; ?> min<?php if (!empty($sessionTopic)): ?> · <?php echo htmlspecialchars($sessionTopic); ?><?php endif; ?></span></div>
<div class="s-list-time"><?php echo $session['session_date'] ? date('M d, Y', strtotime($session['session_date'])) : date('M d, Y', strtotime($session['created_at'])); ?>
<?php if($session['start_time']): ?> · <?php echo date('g:i A', strtotime($session['start_time'])); ?><?php endif; ?>
</div>
</div>
</li>
<?php endwhile; ?>
</ul>
<?php else: ?>
<div class="session-history-empty"><i class="app-icon" data-lucide="notebook-pen" aria-hidden="true"></i><strong>Your study history starts here</strong><span>Complete a Pomodoro focus sprint to see it here.</span></div>
<?php endif; ?>
</div>
</aside>
</section>

</div>
</main>

<script>
(function(){
    const root = document.getElementById('pomodoroTimer');
    const key = 'procrastitrack-pomodoro-<?php echo $user_id; ?>';
    const clock = document.getElementById('pomodoroClock');
    const phaseLabel = document.getElementById('pomodoroPhase');
    const status = document.getElementById('pomodoroStatus');
    const startButton = document.getElementById('pomodoroStart');
    const pauseButton = document.getElementById('pomodoroPause');
    const resetButton = document.getElementById('pomodoroReset');
    const focusInput = document.getElementById('pomodoroFocusMinutes');
    const breakInput = document.getElementById('pomodoroBreakMinutes');
    const durationsKey = 'procrastitrack-pomodoro-durations-<?php echo $user_id; ?>';
    const durations = {focus:Number(root.dataset.focusMinutes), break:Number(root.dataset.breakMinutes)};
    const alertDialog = document.getElementById('pomodoroAlert');
    const alertTitle = document.getElementById('pomodoroAlertTitle');
    const alertMessage = document.getElementById('pomodoroAlertMessage');
    const alertDismiss = document.getElementById('pomodoroAlertDismiss');
    let state = null;
    let saving = false;

    try { state = JSON.parse(localStorage.getItem(key) || 'null'); } catch(e) { state = null; }
    function validMinutes(value){ return Number.isInteger(value) && value >= 1 && value <= 180; }
    try {
        const saved = JSON.parse(localStorage.getItem(durationsKey) || 'null');
        if(saved) for(const phase of ['focus','break']) if(validMinutes(saved[phase])) durations[phase]=saved[phase];
    } catch(e) {}
    if(state && ['focus','break'].includes(state.phase) && validMinutes(state.duration/60000)) durations[state.phase]=state.duration/60000;
    focusInput.value=durations.focus;
    breakInput.value=durations.break;
    function updateDuration(phase,input){
        if(!input.reportValidity()) return false;
        const minutes=Number(input.value);
        if(!validMinutes(minutes)) return false;
        durations[phase]=minutes;
        try { localStorage.setItem(durationsKey,JSON.stringify(durations)); } catch(e) {}
        if(state && state.phase===phase){
            const elapsed=Math.max(0,state.duration-remaining());
            state.duration=minutes*60000;
            state.remaining=Math.max(0,state.duration-elapsed);
            state.endAt=Date.now()+state.remaining;
            render();
            if(!state.paused && state.remaining===0) completePhase();
        }
        return true;
    }
    focusInput.addEventListener('change',function(){updateDuration('focus',focusInput);});
    breakInput.addEventListener('change',function(){updateDuration('break',breakInput);});
    function remaining(){ return state.paused ? state.remaining : Math.max(0, state.endAt - Date.now()); }
    function render(){
        if(!state){ clock.textContent='--:--'; phaseLabel.textContent='Ready when you are'; root.style.setProperty('--pomodoro-progress','0%'); startButton.disabled=false; pauseButton.disabled=true; resetButton.disabled=true; pauseButton.lastChild.textContent=' Pause'; return; }
        const seconds = Math.ceil(remaining()/1000);
        clock.textContent = String(Math.floor(seconds/60)).padStart(2,'0') + ':' + String(seconds%60).padStart(2,'0');
        phaseLabel.textContent = state.phase === 'focus' ? 'Stay in the zone' : 'Take a breather';
        root.style.setProperty('--pomodoro-progress', `${Math.max(0,Math.min(100,(1-remaining()/state.duration)*100))}%`);
        startButton.disabled=true; pauseButton.disabled=false; resetButton.disabled=false;
        pauseButton.lastChild.textContent=state.paused?' Resume':' Pause';
        localStorage.setItem(key, JSON.stringify(state));
    }
    async function saveCompletedPhase(phase, minutes, snapshot){
        const form = new FormData();
        form.append('save_pomodoro_phase','1'); form.append('phase',phase); form.append('duration_minutes',minutes);
        form.append('task_id',snapshot.taskId || ''); form.append('subject',snapshot.subject || ''); form.append('csrf',root.dataset.csrf);
        const response = await fetch('study_sessions.php',{method:'POST',body:form,credentials:'same-origin'});
        let payload;
        try { payload = await response.json(); } catch(e) { throw new Error('The server returned an unreadable response.'); }
        if(!response.ok || !payload.ok) throw new Error(payload.error || 'Unable to save this Pomodoro phase.');
    }
    async function completePhase(){
        if(!state || state.paused || remaining()>0 || saving) return;
        saving=true;
        const snapshot={...state};
        const minutes=Math.max(1,Math.round(snapshot.duration/60000));
        // Finish the timer and alert immediately. A failed save must never put
        // the expired timer back into a retry loop or suppress its completion alert.
        if(snapshot.phase==='focus'){
            state={phase:'break',duration:durations.break*60000,endAt:Date.now()+durations.break*60000,paused:false,taskId:snapshot.taskId,subject:snapshot.subject};
            status.textContent='Focus timer finished. Saving your session…';
            showPhaseAlert('Focus session complete','Your focus time is finished. Your break has started. Take a moment to recharge.');
        } else {
            state=null; localStorage.removeItem(key);
            status.textContent='Break timer finished. Saving your session…';
            showPhaseAlert('Break complete','Your break is finished. You’re ready to start another focus session when you are.');
        }
        render();
        try {
            await saveCompletedPhase(snapshot.phase,minutes,snapshot);
            status.textContent=snapshot.phase==='focus'?'Focus session saved. Your break is underway.':'Break saved. Pomodoro cycle complete.';
        } catch(e) {
            status.textContent='Timer finished, but the session could not be confirmed as saved ('+e.message+'). Check your connection before starting again.';
        } finally { saving=false; render(); }
    }
    function showPhaseAlert(title,message){
        alertTitle.textContent=title;
        alertMessage.textContent=message;
        alertDialog.hidden=false;
        alertDismiss.focus();
        if('Notification' in window && Notification.permission==='granted'){
            try { new Notification(title,{body:message}); } catch(e) {}
        }
    }
    alertDismiss.addEventListener('click',function(){alertDialog.hidden=true;});
    alertDialog.addEventListener('click',function(event){if(event.target===alertDialog)alertDialog.hidden=true;});
    startButton.addEventListener('click',function(){
        if(!updateDuration('focus',focusInput) || !updateDuration('break',breakInput)) return;
        if('Notification' in window && Notification.permission==='default'){
            try { Notification.requestPermission().catch(function(){}); } catch(e) {}
        }
        const duration=durations.focus*60000;
        state={phase:'focus',duration:duration,endAt:Date.now()+duration,paused:false,taskId:document.getElementById('pomodoroTask').value,subject:''};
        status.textContent='Stay focused until the timer ends. You can pause or reset at any time.';
        render();
    });
    pauseButton.addEventListener('click',function(){
        if(!state)return;
        if(state.paused){state.endAt=Date.now()+state.remaining;state.paused=false;}
        else{state.remaining=remaining();state.paused=true;}
        render();
    });
    resetButton.addEventListener('click',function(){
        if(!confirm('Reset this timer? An unfinished phase will not be recorded.'))return;
        state=null;localStorage.removeItem(key);status.textContent='Timer reset. Unfinished time was not recorded.';render();
    });
    render();
    setInterval(function(){if(state){render();if(!state.paused && remaining()<=0)completePhase();}},250);
})();
</script>

</body>
</html>
