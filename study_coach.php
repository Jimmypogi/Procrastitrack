<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
require_once __DIR__ . '/csrf.php';
$csrfToken = app_csrf_token();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/lib/student_suggestions.php';
require_once __DIR__ . '/lib/study_planner.php';
study_planner_schema($conn);
$userId = (int)$_SESSION['user_id'];
$planSavedNotice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_plan') {
    app_csrf_require();
    $planText = is_string($_POST['plan_text'] ?? null) ? trim($_POST['plan_text']) : '';
    if ($planText === '' || mb_strlen($planText) > 12000) {
        http_response_code(422);
        $planSavedNotice = 'The suggested plan is empty or too long. Generate a new plan and try again.';
    } else {
        $stmt = $conn->prepare('INSERT INTO study_plans(user_id,plan_text) SELECT ?,? WHERE NOT EXISTS(SELECT 1 FROM study_plans WHERE user_id=? AND plan_text=?)');
        $stmt->bind_param('isis', $userId, $planText, $userId, $planText);
        $stmt->execute();
        $_SESSION['planner_notice'] = 'Your weekly plan is saved here in Study Coach.';
        header('Location: study_coach.php#saved-plan');
        exit();
    }
}
$savedPlanStmt = $conn->prepare('SELECT plan_text, created_at FROM study_plans WHERE user_id=? ORDER BY id DESC LIMIT 1');
$savedPlanStmt->bind_param('i', $userId);
$savedPlanStmt->execute();
$savedStudyPlan = $savedPlanStmt->get_result()->fetch_assoc();
$planSavedNotice = $_SESSION['planner_notice'] ?? $planSavedNotice;
unset($_SESSION['planner_notice']);
$activeTasks = mysqli_query($conn, "SELECT id, title, deadline, deadline_time, priority, status FROM tasks WHERE user_id=$userId AND status<>'Completed'")->fetch_all(MYSQLI_ASSOC);
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$taskOrder = array_slice(student_ranked_tasks($activeTasks), 0, 4);
foreach ($taskOrder as &$task) {
    $due = app_task_deadline_at($task['deadline'], $task['deadline_time']);
    $task['priority'] = in_array($task['priority'], ['High', 'Medium', 'Low'], true) ? $task['priority'] : 'Unassigned';
    $task['overdue'] = $due !== null && $due < $now;
    $days = $due ? (int)$now->setTime(0, 0)->diff($due->setTime(0, 0))->format('%r%a') : null;
    $task['deadline_label'] = match (true) {
        $due === null => 'No deadline',
        $task['overdue'] => 'Overdue · ' . app_task_deadline_display($task['deadline'], $task['deadline_time']),
        $days === 0 => 'Due today' . ($task['deadline_time'] ? ' at ' . $due->format('g:i A') : ''),
        $days === 1 => 'Due tomorrow' . ($task['deadline_time'] ? ' at ' . $due->format('g:i A') : ''),
        default => 'Due ' . app_task_deadline_display($task['deadline'], $task['deadline_time']),
    };
}
unset($task);
$focusTask = $taskOrder[0] ?? null;
require_once __DIR__ . '/lib/checkin_support.php';
checkin_support_schema($conn);
$weeklyEntries = checkin_weekly_history_sql();
$latestCheckin = $conn->prepare("SELECT l.focus_level, l.energy_level $weeklyEntries
    AND l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY l.created_at DESC, l.id DESC LIMIT 1");
$latestCheckin->bind_param('i', $userId);
$latestCheckin->execute();
$recentRatings = $latestCheckin->get_result()->fetch_assoc();
$latestCheckin->close();
$suggestions = student_suggestions($activeTasks, $recentRatings, $now, $now, true);
$reflection = $conn->query("SELECT r.answers FROM checkin_reflections r JOIN procrastiblock_logs l ON l.id=r.log_id AND l.user_id=r.user_id WHERE r.user_id=$userId AND (r.checkin_type='Weekly' OR (r.checkin_type IS NULL AND l.notes LIKE 'Weekly:%')) AND l.created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) ORDER BY l.created_at DESC,l.id DESC LIMIT 1")->fetch_assoc();
$reflectionAnswers = $reflection ? json_decode($reflection['answers'], true) : null;
$habitAdvice = [];
if (is_array($reflectionAnswers)) {
    $reflectionAnswers['checkin_type'] = 'Weekly';
    $support = checkin_guidance($reflectionAnswers);
    $reason = checkin_answer($reflectionAnswers, 'weekly_reason');
    $habitAdvice[] = [
        'title' => $support['title'],
        'action' => $support['action'],
        'basis' => $reason !== '' && $reason !== 'I did not procrastinate'
            ? 'In your latest weekly check-in, you selected “' . $reason . '”.'
            : 'Based on your latest weekly check-in.',
    ];
}
$weekStart = $now->modify('monday this week')->setTime(0, 0);
$weeklyMetrics = student_weekly_coach_metrics($conn, $userId, $weekStart);
$goals = [];
if ($weeklyMetrics['completion'] !== null) {
    $rate = $weeklyMetrics['completion'];
    $overdue = $weeklyMetrics['overdue'];
    $goals[] = ['Task completion rate', $rate . '%', min(100, $rate + 10) . '%'];
    $goals[] = ['Tasks overdue at week end', (string)$overdue, $overdue > 1 ? ($overdue - 1) . ' or fewer' : '0'];
    if ($overdue > 0) {
        $habitAdvice[] = ['title' => 'Start tasks earlier', 'action' => 'Schedule a short first work session before each upcoming deadline.', 'basis' => $overdue . ' of your tasks due last week were still open when the week ended.'];
    }
}
if ($weeklyMetrics['postponements'] !== null) {
    $postponements = $weeklyMetrics['postponements'];
    $goals[] = ['Deadline postponements', (string)$postponements, $postponements > 1 ? ($postponements - 1) . ' or fewer' : '0'];
    if ($postponements > 0) {
        $habitAdvice[] = ['title' => 'Break down larger tasks', 'action' => 'Pick a task you postponed and turn its first step into a short work session.', 'basis' => 'You moved deadlines later ' . $postponements . ($postponements === 1 ? ' time' : ' times') . ' last week.'];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Study Coach — ProcrastiTrack</title>
<link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/student.css">
<link rel="stylesheet" href="assets/css/coach-response.css?v=6">
<link rel="stylesheet" href="assets/css/study-coach.css?v=20261125-2">
</head>
<body class="student-body study-coach-page">
<?php include 'includes/sidebar.php'; ?>
<main class="s-main"><div class="s-inner">
<header class="s-head">
<div><div class="s-label">Your Study Coach</div><h1>Make your next study step count</h1><p>Get practical, personalized guidance from your recent activity—or build a plan that fits your week.</p></div>
</header>
<?php if ($planSavedNotice): ?><p class="coach-plan-notice" role="status"><?php echo htmlspecialchars($planSavedNotice, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
<?php if ($savedStudyPlan): ?><details class="coach-saved-plan s-panel" id="saved-plan"><summary><span><span class="s-label">YOUR LATEST PLAN</span><strong>Weekly study plan</strong></span><span class="coach-saved-plan-toggle">View saved plan <span aria-hidden="true">↓</span></span></summary><p class="coach-saved-plan-date"><span>Saved <?php echo htmlspecialchars(date('M j, Y', strtotime($savedStudyPlan['created_at'])), ENT_QUOTES, 'UTF-8'); ?> · Adjust it as your week changes.</span><a href="#coachForm">Build a fresh plan <span aria-hidden="true">→</span></a></p><div id="savedStudyPlanContent" class="coach-result" aria-live="polite"></div></details><?php endif; ?>
<section class="s-panel coach-priorities" aria-labelledby="coachPrioritiesHeading">
    <h2 id="coachPrioritiesHeading">Personalized recommendations</h2>
    <?php if ($focusTask): ?>
        <div class="coach-focus-task">
            <h3>Recommended task to focus on</h3>
            <div class="coach-task-meta">
                <span class="coach-priority coach-priority-<?php echo strtolower($focusTask['priority']); ?>"><?php echo $focusTask['priority']; ?> priority</span>
                <span><?php echo htmlspecialchars($focusTask['deadline_label'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <a class="coach-focus-title" href="view_task.php?id=<?php echo (int)$focusTask['id']; ?>"><?php echo htmlspecialchars($focusTask['title'], ENT_QUOTES, 'UTF-8'); ?></a>
            <p class="coach-task-status"><?php echo htmlspecialchars($focusTask['status'], ENT_QUOTES, 'UTF-8'); ?></p>
            <p><strong>Why this task?</strong> It comes first when your unfinished tasks are sorted by priority and then by deadline. <?php echo $focusTask['overdue'] ? 'Review the missed deadline and decide what you can finish next.' : 'Pick one small step to get started.'; ?></p>
        </div>
        <h3>Suggested task order</h3>
        <ol class="coach-task-order">
            <?php foreach ($taskOrder as $index => $task): ?>
                <li>
                    <span class="coach-task-position" aria-hidden="true"><?php echo $index + 1; ?></span>
                    <div><a href="view_task.php?id=<?php echo (int)$task['id']; ?>"><?php echo htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?></a><small><?php echo htmlspecialchars($task['deadline_label'], ENT_QUOTES, 'UTF-8'); ?></small></div>
                    <span class="coach-priority coach-priority-<?php echo strtolower($task['priority']); ?>"><?php echo $task['priority']; ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php else: ?>
        <p>You have no unfinished tasks to prioritize.</p>
        <a class="s-btn" href="add_task.php">Add a task</a>
    <?php endif; ?>
</section>
<section class="s-panel coach-priorities coach-habits" aria-labelledby="coachHabitsHeading">
    <h2 id="coachHabitsHeading">Recommended for your habits</h2>
    <?php if ($habitAdvice): ?>
        <div class="coach-habit-grid">
            <?php foreach ($habitAdvice as $advice): ?>
                <article class="coach-habit-item">
                    <h3><?php echo htmlspecialchars($advice['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p><?php echo htmlspecialchars($advice['action'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="coach-habit-basis"><?php echo htmlspecialchars($advice['basis'], ENT_QUOTES, 'UTF-8'); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p>Habit suggestions appear after a weekly check-in or when your task records show deadlines that need attention.</p>
    <?php endif; ?>
    <div class="coach-weekly-goals">
        <h3>Suggested weekly goals</h3>
        <?php if ($goals): ?>
            <div class="coach-habit-grid">
                <?php foreach ($goals as [$metric, $baseline, $target]): ?>
                    <div class="coach-habit-item">
                        <h4><?php echo $metric; ?></h4>
                        <dl class="coach-goal-comparison">
                            <div><dt>Last week</dt><dd><?php echo $baseline; ?></dd></div>
                            <div><dt>Suggested goal</dt><dd><?php echo $target; ?></dd></div>
                        </dl>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>Goals appear once there are tasks or deadline changes to compare from last week.</p>
        <?php endif; ?>
    </div>
</section>
<?php if ($suggestions): ?>
<section class="s-panel coach-priorities" aria-labelledby="suggestionsHeading">
    <h2 id="suggestionsHeading">Small next steps</h2>
    <div class="coach-habit-grid">
        <?php foreach ($suggestions as $suggestion): ?>
            <article class="coach-habit-item">
                <h3><?php echo htmlspecialchars($suggestion['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                <p><?php echo htmlspecialchars($suggestion['text'], ENT_QUOTES, 'UTF-8'); ?></p>
                <a class="s-btn" style="margin-top:10px" href="<?php echo htmlspecialchars($suggestion['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($suggestion['action'], ENT_QUOTES, 'UTF-8'); ?> <span aria-hidden="true">→</span></a>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
<section class="s-panel coach-guidance-panel" aria-labelledby="coachGuidanceHeading">
<div class="coach-guidance-heading"><div><div class="s-label">YOUR NEXT STEP</div><h2 id="coachGuidanceHeading">Let’s make a plan</h2><p>Choose the kind of help you need. You can adjust your request before sending it.</p></div><span class="coach-guidance-mark" aria-hidden="true">✦</span></div>
<form id="coachForm" class="s-form-grid">
<div class="full coach-mode-field"><label for="coachMode">I’d like help with</label>
<select id="coachMode" name="mode"><option value="suggestions">Give me personalized suggestions</option><option value="assistant">Ask my productivity assistant</option><option value="schedule">Make me a weekly study plan</option></select></div>
<div id="coachQuestionWrap" class="full coach-question-field" hidden><label for="coachQuestion">What would you like help with?</label><textarea id="coachQuestion" name="question" maxlength="1500" rows="3" placeholder="What might be getting in the way of finishing my tasks?"></textarea><p class="s-card-meta">Include task difficulty or estimated time if you know it. Each question uses a fresh snapshot of your records.</p></div>
<div id="coachAvailability" class="full coach-availability-field" hidden>
<label for="availableHours">How many hours can you study on each selected day?</label>
<input id="availableHours" name="available_hours" type="number" min="0.5" max="12" step="0.5" value="2">
<fieldset class="coach-days-field"><legend>Choose the days you are available</legend>
<div class="coach-days-grid">
<?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day): ?>
<label><input type="checkbox" name="days[]" value="<?php echo $day; ?>" <?php echo $day !== 'Sunday' ? 'checked' : ''; ?>><span><?php echo $day; ?></span></label>
<?php endforeach; ?>
</div></fieldset>
</div>
<div class="full coach-submit-row"><button id="coachButton" type="submit" class="s-btn primary">Get suggestions</button><span>Nothing is sent until you request guidance.</span></div>
</form>
<div id="coachResult" role="status" aria-live="polite" class="coach-result" aria-busy="false"></div>
<aside class="coach-privacy-note"><span class="coach-privacy-mark" aria-hidden="true">i</span><p><strong>A note about your data</strong><br>Personalized advice may use a summary of your tasks, latest weekly check-in, and recent study activity. It is sent to an external AI service only when you request guidance. Avoid sensitive personal details, and review suggestions before following them.</p></aside>
</section>
</div></main>
<script src="assets/js/coach-response.js?v=6"></script>
<script>
(() => {
    const form = document.getElementById('coachForm');
    const mode = document.getElementById('coachMode');
    const availability = document.getElementById('coachAvailability');
    const button = document.getElementById('coachButton');
    const result = document.getElementById('coachResult');
    const updateMode = () => {
        const schedule = mode.value === 'schedule';
        availability.hidden = !schedule;
        form.elements.available_hours.required = schedule;
        form.elements.available_hours.disabled = !schedule;
        button.textContent = schedule ? 'Create my weekly plan' : 'Get guidance';
        document.getElementById('coachQuestionWrap').hidden = mode.value !== 'assistant';
        form.elements.question.required = mode.value === 'assistant';
        result.replaceChildren();
    };
    mode.addEventListener('change', updateMode);
    const requestedMode = new URLSearchParams(window.location.search).get('mode');
    if (['suggestions','assistant','schedule'].includes(requestedMode)) mode.value = requestedMode;
    updateMode();
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (button.disabled || !form.reportValidity()) return;
        if (mode.value === 'schedule' && !form.querySelector('input[name="days[]"]:checked')) {
            result.textContent = 'Choose at least one day you are available.';
            return;
        }
        button.disabled = true;
        mode.disabled = true;
        result.setAttribute('aria-busy', 'true');
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 45000);
        result.textContent = 'Preparing your suggestions…';
        const payload = {
            mode: mode.value,
            question: form.elements.question.value,
            available_hours: form.elements.available_hours.value,
            days: Array.from(form.querySelectorAll('input[name="days[]"]:checked')).map(input => input.value)
        };
        try {
            const response = await fetch('ai_task_breakdown.php', {
                method: 'POST',
                signal: controller.signal,
                headers: {'Content-Type':'application/json','X-CSRF-Token':<?php echo json_encode($csrfToken); ?>},
                body: JSON.stringify(payload)
            });
            const data = await response.json();
            if (response.ok && typeof data.answer === 'string' && data.answer.trim()) {
                renderCoachResponse(result, data.answer, payload.mode);
                if (payload.mode === 'assistant') form.elements.question.value = '';
                if (payload.mode === 'schedule') {
                    const saveForm=document.createElement('form');saveForm.method='post';saveForm.action='study_coach.php#saved-plan';saveForm.style.marginTop='20px';
                    const fields={action:'save_plan',plan_text:data.answer,csrf_token:<?php echo json_encode($csrfToken); ?>};
                    for(const [name,value] of Object.entries(fields)){const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;saveForm.append(input);}
                    const saveButton=document.createElement('button');saveButton.type='submit';saveButton.className='s-btn primary';saveButton.textContent='Save plan to my planner';saveForm.append(saveButton);result.append(saveForm);
                }
            } else {
                result.textContent = data.error || 'Could not create suggestions. Please try again.';
            }
        } catch (error) {
            result.textContent = error.name === 'AbortError' ? 'This request took too long. Try again; the suggested next steps above are still available.' : 'Could not connect to the AI service. You can use the suggested next steps above and try again later.';
        } finally {
            result.setAttribute('aria-busy', 'false');
            clearTimeout(timeout);
            button.disabled = false;
            mode.disabled = false;
        }
    });
})();
</script>
<?php if ($savedStudyPlan): ?><script>document.addEventListener('DOMContentLoaded',function(){if(window.renderCoachResponse){renderCoachResponse(document.getElementById('savedStudyPlanContent'),<?php echo json_encode((string)$savedStudyPlan['plan_text'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>,'schedule');}});</script><?php endif; ?>
</body></html>
