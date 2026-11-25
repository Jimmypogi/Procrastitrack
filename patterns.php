<?php
session_start();

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

include 'config/database.php';
$user_id = intval($_SESSION['user_id']);
require_once __DIR__ . '/lib/activity_gaps.php';
require_once __DIR__ . '/lib/completion_deadline.php';
// Study dates/times describe when work happened; an upload timestamp does not.
$studyMoment = "TIMESTAMP(session_date, COALESCE(start_time, end_time))";
$workEvents = "SELECT created_at AS activity_at, 'session' AS log_type FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='session' UNION ALL SELECT $studyMoment AS activity_at, 'study' AS log_type FROM study_sessions WHERE user_id=$user_id AND session_date IS NOT NULL AND COALESCE(start_time, end_time) IS NOT NULL";


// ===== PATTERN 1: LATE-NIGHT WORK =====
$workStats = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total, COALESCE(SUM(HOUR(activity_at)>=22 OR HOUR(activity_at)<5),0) AS late_count FROM ($workEvents) work_events WHERE activity_at<=NOW()"));
$allLogsCount = (int)$workStats['total'];
$lateNightPct = $allLogsCount ? round(100 * $workStats['late_count'] / $allLogsCount) : 0;
$lateNightByDay = [];
for ($i=6; $i>=0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM ($workEvents) work_events WHERE DATE(activity_at)='$d' AND activity_at<=NOW() AND (HOUR(activity_at)>=22 OR HOUR(activity_at)<5)"));
    $lateNightByDay[date('D', strtotime($d))] = (int)$row['c'];
}
$lateNightRecentSessions = [];
$lateNightRecentResult = mysqli_query($conn, "SELECT session_date, start_time, end_time, created_at FROM study_sessions WHERE user_id=$user_id AND session_date IS NOT NULL AND COALESCE(start_time,end_time) IS NOT NULL AND TIMESTAMP(session_date,COALESCE(start_time,end_time))<=NOW() ORDER BY TIMESTAMP(session_date,COALESCE(start_time,end_time)) DESC LIMIT 5");
while($session = mysqli_fetch_assoc($lateNightRecentResult)){
    $time = $session['start_time'] ?: $session['end_time'];
    $hour = (int)substr((string)$time, 0, 2);
    $lateNightRecentSessions[] = [
        'task' => 'Study session',
        'date' => $session['session_date'],
        'time' => $time,
        'is_late' => $hour >= 22 || $hour < 5
    ];
}
$lateNightRecentTotal = count($lateNightRecentSessions);
$lateNightRecentCount = count(array_filter($lateNightRecentSessions, fn($session) => $session['is_late']));
$lateNightRecentPct = $lateNightRecentTotal ? round(100 * $lateNightRecentCount / $lateNightRecentTotal) : 0;
$lateNightPatternDetected = $lateNightRecentTotal >= 3 && $lateNightRecentPct >= 60;

// ===== PATTERN 2: REPEATED DEADLINE EXTENSIONS =====
$extendedTasks = [];
$deadlineChanges = mysqli_query($conn, "SELECT t.*, COUNT(h.id) as extension_count, MAX(h.changed_at) as last_deadline_change FROM tasks t INNER JOIN task_deadline_history h ON h.task_id=t.id AND h.user_id=t.user_id WHERE t.user_id=$user_id AND h.old_deadline IS NOT NULL AND h.new_deadline > h.old_deadline GROUP BY t.id HAVING extension_count >= 2 ORDER BY extension_count DESC, last_deadline_change DESC");
while($t = mysqli_fetch_assoc($deadlineChanges)){
    $extendedTasks[] = [
        'id' => $t['id'],
        'title' => $t['title'],
        'subject' => $t['subject'],
        'deadline' => $t['deadline'],
        'priority' => $t['priority'],
        'status' => $t['status'],
        'days_overdue' => max(0, (int)floor((strtotime('now') - strtotime($t['deadline'])) / 86400)),
        'extension_count' => (int)$t['extension_count'],
        'type' => 'deadline_changed'
    ];
}
usort($extendedTasks, function($a, $b) { return $b['extension_count'] - $a['extension_count']; });
$extendedCount = count($extendedTasks);
$deadlineExtensionTask = !empty($extendedTasks) ? $extendedTasks[0] : null;
$deadlineExtensionTimeline = [];
if($deadlineExtensionTask){
    $deadlineTaskId = (int)$deadlineExtensionTask['id'];
    $deadlineHistoryResult = mysqli_query($conn, "SELECT old_deadline,new_deadline,changed_at FROM task_deadline_history WHERE user_id=$user_id AND task_id=$deadlineTaskId AND old_deadline IS NOT NULL AND new_deadline IS NOT NULL AND new_deadline>old_deadline ORDER BY changed_at ASC,id ASC");
    $historyNumber = 0;
    $firstOldDeadline = null;
    while($history = mysqli_fetch_assoc($deadlineHistoryResult)){
        if($firstOldDeadline === null){
            $firstOldDeadline = $history['old_deadline'];
            $deadlineExtensionTimeline[] = [
                'label' => 'Original deadline',
                'date' => $history['old_deadline'],
                'extension' => false
            ];
        }
        $historyNumber++;
        $deadlineExtensionTimeline[] = [
            'label' => $historyNumber === 1 ? 'First extension' : ($historyNumber === 2 ? 'Second extension' : $historyNumber . 'th extension'),
            'date' => $history['new_deadline'],
            'extension' => true
        ];
    }
}
$deadlineExtensionPatternDetected = $deadlineExtensionTask && (int)$deadlineExtensionTask['extension_count'] >= 2;

// ===== PATTERN 3: MISSED TASKS =====
$missedTasks = [];
$completionHistoryRows = mysqli_query($conn, "SELECT id,task_id,user_id,old_deadline,new_deadline,changed_at,change_reason FROM task_deadline_history WHERE user_id=$user_id ORDER BY changed_at,id");
$completionHistories=[];
while($event=mysqli_fetch_assoc($completionHistoryRows))$completionHistories[(int)$event['task_id']][]=$event;
$unknownCompletionDeadlines=0;
$completedLate = mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status='Completed'");
while($t = mysqli_fetch_assoc($completedLate)){
    $resolved=task_completion_deadline($t,$completionHistories[(int)$t['id']]??[]);
    if(!$resolved['known']){$unknownCompletionDeadlines++;continue;}
    if($resolved['deadline']===null||$resolved['deadline']>=substr($t['completed_at'],0,10))continue;
    $t['deadline']=$resolved['deadline'];
    $missedTasks[] = [
        'id' => $t['id'],
        'title' => $t['title'],
        'subject' => $t['subject'],
        'deadline' => $t['deadline'],
        'completed_at' => $t['completed_at'],
        'priority' => $t['priority'],
        'days_late' => max(0, (int)floor((strtotime($t['completed_at']) - strtotime($t['deadline'])) / 86400))
    ];
}
$longPendingTasks = mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status<>'Completed' AND deadline IS NOT NULL AND " . app_task_overdue_sql());
while($t = mysqli_fetch_assoc($longPendingTasks)){
    $missedTasks[] = [
        'id' => $t['id'],
        'title' => $t['title'],
        'subject' => $t['subject'],
        'deadline' => $t['deadline'],
        'completed_at' => null,
        'priority' => $t['priority'],
        'days_late' => max(0, (int)ceil((time() - strtotime($t['deadline'] . ' ' . (!empty($t['deadline_time']) ? $t['deadline_time'] : '23:59:59'))) / 86400)),
        'type' => 'overdue'
    ];
}
usort($missedTasks, function($a, $b) { return $b['days_late'] - $a['days_late']; });
$missedCount = count($missedTasks);
$missedDeadlineTaskTotal = 0;
$missedTaskIds = [];
foreach($missedTasks as $missedTask){
    $missedTaskIds[(int)$missedTask['id']] = true;
}
$deadlineTaskRows = mysqli_query($conn, "SELECT id,status,deadline,completed_at,completion_deadline,completion_deadline_known FROM tasks WHERE user_id=$user_id AND deadline IS NOT NULL");
while($task = mysqli_fetch_assoc($deadlineTaskRows)){
    $taskId = (int)$task['id'];
    if($task['status'] === 'Completed'){
        $resolved = task_completion_deadline($task, $completionHistories[$taskId] ?? []);
        if(!$resolved['known'] || $resolved['deadline'] === null) continue;
        $missedDeadlineTaskTotal++;
        continue;
    }
    if(isset($missedTaskIds[$taskId])){
        $missedDeadlineTaskTotal++;
    }
}
$missedOnTimeCount = max(0, $missedDeadlineTaskTotal - $missedCount);
$missedDeadlineRate = $missedDeadlineTaskTotal > 0 ? round(100 * $missedCount / $missedDeadlineTaskTotal) : 0;
$missedPatternDetected = $missedDeadlineTaskTotal >= 3 && $missedCount >= 3 && $missedDeadlineRate >= 50;

// ===== PATTERN 4: LONG IDLE PERIODS =====
$activitySql = "SELECT created_at, log_type FROM procrastiblock_logs WHERE user_id=$user_id UNION ALL SELECT TIMESTAMP(COALESCE(session_date, DATE(created_at)), COALESCE(end_time, start_time, '00:00:00')), 'study' FROM study_sessions WHERE user_id=$user_id UNION ALL SELECT created_at, 'task created' FROM tasks WHERE user_id=$user_id UNION ALL SELECT completed_at, 'task completed' FROM tasks WHERE user_id=$user_id AND completed_at IS NOT NULL";
$logEntries = mysqli_query($conn, $activitySql)->fetch_all(MYSQLI_ASSOC);
$idlePeriods = activity_gaps($logEntries, time(), 2);
$idleCount = count($idlePeriods);
$avgIdle = $idleCount > 0 ? round(array_sum(array_column($idlePeriods, 'days')) / $idleCount, 1) : 0;
$longestIdle = $idleCount > 0 ? $idlePeriods[0]['days'] : 0;
$idleTask = null;
$idleTaskDays = 0;
$idleTaskCreated = null;
$idleTaskActivity = null;
$idleTaskResult = mysqli_query($conn, "SELECT id,title,created_at,status FROM tasks WHERE user_id=$user_id AND status<>'Completed' AND created_at IS NOT NULL ORDER BY created_at ASC");
while($task = mysqli_fetch_assoc($idleTaskResult)){
    $lastActivityAt = strtotime($task['created_at']);
    $taskId = (int)$task['id'];
    $idleTaskActivity = null;
    $activityRows = mysqli_query($conn, "SELECT created_at AS activity_at FROM procrastiblock_logs WHERE user_id=$user_id AND task_id=$taskId UNION ALL SELECT TIMESTAMP(COALESCE(session_date,DATE(created_at)),COALESCE(end_time,start_time,'00:00:00')) AS activity_at FROM study_sessions WHERE user_id=$user_id AND task_id=$taskId");
    while($activity = mysqli_fetch_assoc($activityRows)){
        $activityTime = strtotime($activity['activity_at']);
        if($activityTime !== false && $activityTime <= time() && $activityTime > $lastActivityAt){
            $lastActivityAt = $activityTime;
            $idleTaskActivity = $activity['activity_at'];
        }
    }
    $daysIdle = max(0, (int)floor((time() - $lastActivityAt) / 86400));
    if($daysIdle > $idleTaskDays){
        $idleTaskDays = $daysIdle;
        $idleTask = $task;
        $idleTaskCreated = $task['created_at'];
    }
}
$idlePatternDetected = $idleTaskDays >= 5;

// Study session idle periods
$studyEntries = mysqli_query($conn, "SELECT TIMESTAMP(COALESCE(session_date, DATE(created_at)), COALESCE(end_time, start_time, '00:00:00')) AS created_at, 'study' AS log_type FROM study_sessions WHERE user_id=$user_id")->fetch_all(MYSQLI_ASSOC);
$studyIdlePeriods = activity_gaps($studyEntries, time(), 3);
$studyIdleCount = count($studyIdlePeriods);
$avgStudyIdle = $studyIdleCount > 0 ? round(array_sum(array_column($studyIdlePeriods, 'days')) / $studyIdleCount, 1) : 0;

// ===== PATTERN 5: SUBJECT-SPECIFIC DELAY TRENDS =====
$subjectDelays = [];
$subjectsResult = mysqli_query($conn, "SELECT DISTINCT subject FROM tasks WHERE user_id=$user_id AND subject IS NOT NULL");
while($s = mysqli_fetch_assoc($subjectsResult)){
    $subjectLabel = $s['subject'];
    $subject = mysqli_real_escape_string($conn, $subjectLabel);
    $delayCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM procrastiblock_logs pl LEFT JOIN tasks t ON pl.task_id = t.id AND t.user_id=pl.user_id WHERE pl.user_id=$user_id AND pl.log_type='delay' AND t.subject='$subject'"))['c'];
    $totalForSubject = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tasks WHERE user_id=$user_id AND subject='$subject'"))['c'];
    $completedForSubject = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tasks WHERE user_id=$user_id AND subject='$subject' AND status='Completed'"))['c'];
    $completionRate = $totalForSubject > 0 ? round(($completedForSubject / $totalForSubject) * 100) : 0;
    // Completion warnings consider only tasks whose deadline has already passed.
    $pastDueStats = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) total,COALESCE(SUM(status='Completed'),0) completed FROM tasks WHERE user_id=$user_id AND subject='$subject' AND deadline < CURDATE()"));
    $pastDueTotal = (int)$pastDueStats['total'];
    $pastDueCompleted = (int)$pastDueStats['completed'];
    $pastDueRate = $pastDueTotal > 0 ? round(100 * $pastDueCompleted / $pastDueTotal) : null;
    $avgProcrast = mysqli_fetch_assoc(mysqli_query($conn, "SELECT AVG(CASE WHEN procrastination_level BETWEEN 1 AND 10 THEN procrastination_level END) as avg_p FROM procrastiblock_logs pl LEFT JOIN tasks t ON pl.task_id = t.id AND t.user_id=pl.user_id WHERE pl.user_id=$user_id AND pl.log_type='delay' AND t.subject='$subject'"));
    $subjectDelays[$subjectLabel] = [
        'subject' => $subjectLabel,
        'delay_count' => $delayCount,
        'total_tasks' => $totalForSubject,
        'completed_tasks' => $completedForSubject,
        'completion_rate' => $completionRate,
        'past_due_total' => $pastDueTotal,
        'past_due_completed' => $pastDueCompleted,
        'past_due_completion_rate' => $pastDueRate,
        'avg_procrastination' => $avgProcrast['avg_p'] === null ? null : round((float)$avgProcrast['avg_p'], 1)
    ];
}
uasort($subjectDelays, fn($a, $b) => $b['delay_count'] <=> $a['delay_count']);
$subjectTrends = array_filter($subjectDelays, fn($data) => $data['avg_procrastination'] !== null && $data['avg_procrastination'] >= 7 && $data['delay_count'] >= 2);
$subjectTrendCount = count($subjectTrends);

// ===== OVERALL PATTERN SCORE =====
$patternScore = 0;
$patternFlags = [];

if($lateNightPct > 20){
    $patternScore += 20;
    $patternFlags[] = 'late_night';
} elseif($lateNightPct > 10){
    $patternScore += 10;
    $patternFlags[] = 'late_night';
}

if($extendedCount >= 3){
    $patternScore += 25;
    $patternFlags[] = 'deadline_extension';
} elseif($extendedCount >= 1){
    $patternScore += 10;
    $patternFlags[] = 'deadline_extension';
}

if($missedCount >= 3){
    $patternScore += 25;
    $patternFlags[] = 'missed_tasks';
} elseif($missedCount >= 1){
    $patternScore += 10;
    $patternFlags[] = 'missed_tasks';
}

if($avgIdle >= 5){
    $patternScore += 20;
    $patternFlags[] = 'idle_periods';
} elseif($avgIdle >= 3){
    $patternScore += 10;
    $patternFlags[] = 'idle_periods';
}

if(count($subjectDelays) > 0){
    $highProcrastSubjects = $subjectTrends;
    if(count($highProcrastSubjects) >= 2){
        $patternScore += 15;
        $patternFlags[] = 'subject_trends';
    } elseif(count($highProcrastSubjects) >= 1){
        $patternScore += 8;
        $patternFlags[] = 'subject_trends';
    }
}

$patternLevel = $patternScore >= 60 ? 'High' : ($patternScore >= 30 ? 'Medium' : 'Low');

// ===== GENERATE INSIGHTS =====
$insights = [];

// Late-night insights
if($lateNightPct > 20){
    $insights[] = [
        'icon' => '🌙',
        'title' => 'Late-Night Work Pattern',
        'message' => "$lateNightPct% of recorded work with a known time falls between 10PM and 5AM. Consider whether a different study time would suit you.",
        'severity' => 'warning'
    ];
} elseif($lateNightPct > 0){
    $insights[] = [
        'icon' => '🌙',
        'title' => 'Some Late-Night Work',
        'message' => "$lateNightPct% of recorded work with a known time falls between 10PM and 5AM.",
        'severity' => 'info'
    ];
}

// Deadline extension insights
if($extendedCount >= 3){
    $insights[] = [
        'icon' => '🔄',
        'title' => 'Repeated Deadline Extensions',
        'message' => "$extendedCount task(s) have had their deadline moved later at least twice. Consider smaller milestones.",
        'severity' => 'critical'
    ];
} elseif($extendedCount > 0){
    $insights[] = [
        'icon' => '🔄',
        'title' => 'Repeated Deadline Extensions',
        'message' => "$extendedCount task(s) have at least two recorded deadline extensions. Review their scope and schedule.",
        'severity' => 'warning'
    ];
}

// Missed task insights
if($missedCount >= 3){
    $insights[] = [
        'icon' => '❌',
        'title' => 'Missed Tasks Detected',
        'message' => "$missedCount task(s) were not completed on time. Set intermediate checkpoints for long tasks.",
        'severity' => 'critical'
    ];
} elseif($missedCount > 0){
    $insights[] = [
        'icon' => '❌',
        'title' => 'Some Tasks Missed',
        'message' => "$missedCount task(s) were not completed on time or remain overdue. Review priorities.",
        'severity' => 'warning'
    ];
}

// Idle period insights
if($avgIdle >= 5){
    $insights[] = [
        'icon' => '⏸️',
        'title' => 'Long Idle Periods',
        'message' => "Gaps of at least two days average $avgIdle days between recorded activities. Unlogged work may explain some gaps.",
        'severity' => 'warning'
    ];
} elseif($avgIdle >= 3){
    $insights[] = [
        'icon' => '⏸️',
        'title' => 'Moderate Idle Periods',
        'message' => "Gaps of at least two days average $avgIdle days. Record study sessions to keep this history useful.",
        'severity' => 'info'
    ];
}

// Subject trend insights
foreach($subjectDelays as $subject => $data){
    if(isset($subjectTrends[$subject])){
        $insights[] = [
            'icon' => '📚',
            'title' => "$subject Delay Trend",
            'message' => "High procrastination in $subject (avg " . $data['avg_procrastination'] . "/10, {$data['delay_count']} delays). Schedule dedicated blocks for this subject.",
            'severity' => 'warning'
        ];
    }
    if($data['past_due_total'] >= 2 && $data['past_due_completion_rate'] < 30){
        $insights[] = [
            'icon' => '📉',
            'title' => "$subject Low Completion",
            'message' => "{$data['past_due_completed']} of {$data['past_due_total']} $subject tasks whose deadlines have passed are currently completed ({$data['past_due_completion_rate']}%). Tasks due today, future tasks, and tasks without deadlines are excluded. Review the unfinished tasks and choose a next step.",
            'severity' => 'warning'
        ];
    }
}

if(empty($insights)){
    $insights[] = [
        'icon' => '✅',
        'title' => 'No Major Patterns Detected',
        'message' => 'No rules were triggered by the available records. Add study times, task updates, and delay logs for more useful results.',
        'severity' => 'success'
    ];
}
// ===== CHART DATA =====
$barData = [];
foreach($lateNightByDay as $day => $count){
    $barData[] = ['day' => $day, 'count' => $count];
}

// ===== SUBJECT CHART DATA =====
$subjectChartData = [];
foreach($subjectDelays as $subject => $data){
    $subjectChartData[] = [
        'subject' => htmlspecialchars($subject),
        'delays' => $data['delay_count'],
        'completion' => $data['completion_rate'],
        'procrastination' => $data['avg_procrastination']
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pattern Analysis — ProcrastiTrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/student.css">
<link rel="stylesheet" href="assets/css/patterns.css?v=20261125-long-idle">
<link rel="stylesheet" href="assets/css/insights-tabs.css?v=20261124">
<style>
.pattern-card {
    border-radius:16px;
    padding:20px;
    margin-bottom:14px;
    border-left:4px solid;
    transition:all .25s;
}
.pattern-card:hover {
    transform:translateY(-2px);
    box-shadow:0 8px 24px rgba(0,0,0,0.08);
}
.pattern-card.critical { background:#fef2f2; border-left-color:#dc2626; }
.pattern-card.warning { background:#fffbeb; border-left-color:#f59e0b; }
.pattern-card.info { background:#eff6ff; border-left-color:#3b82f6; }
.pattern-card.success { background:#f0fdf4; border-left-color:#16a34a; }

.pattern-icon {
    width:44px;
    height:44px;
    border-radius:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:1.4rem;
    flex-shrink:0;
}
.pattern-icon.critical { background:#fee2e2; }
.pattern-icon.warning { background:#fef3c7; }
.pattern-icon.info { background:#dbeafe; }
.pattern-icon.success { background:#dcfce7; }
.pattern-title { font-size:1rem;font-weight:700;color:#1e1b2e; }
.pattern-message { font-size:0.85rem;color:#4a4458;line-height:1.6;margin-top:6px; }

.stat-grid {
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:12px;
}
.stat-box {
    padding:14px;
    border-radius:12px;
    text-align:center;
    background:#f8f6fb;
}
.stat-value { font-size:1.6rem;font-weight:800;color:#1e1b2e; }
.stat-label { font-size:0.75rem;color:#6b6580;margin-top:2px;text-transform:uppercase;letter-spacing:0.06em; }

.bar-chart {
    display:flex;
    align-items:flex-end;
    gap:6px;
    height:120px;
    padding:12px 0;
}
.bar-item {
    flex:1;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:flex-end;
    height:100%;
}
.bar-fill {
    width:100%;
    max-width:32px;
    border-radius:6px 6px 0 0;
    transition:height .5s ease;
}
.bar-fill.high { background:linear-gradient(180deg,#dc2626,#ef4444); }
.bar-fill.medium { background:linear-gradient(180deg,#f59e0b,#fbbf24); }
.bar-fill.low { background:linear-gradient(180deg,#16a34a,#22c55e); }
.bar-label { font-size:0.68rem;color:#948da8;margin-top:6px;font-weight:600; }
.bar-value { font-size:0.72rem;color:#6b6580;font-weight:700;margin-bottom:2px; }

.insight-card {
    padding:14px;
    border-radius:14px;
    margin-bottom:10px;
    display:flex;
    align-items:flex-start;
    gap:12px;
    transition:all .2s;
}
.insight-card:hover { transform:translateX(3px); }
.insight-card.critical { background:#fef2f2; }
.insight-card.warning { background:#fffbeb; }
.insight-card.info { background:#eff6ff; }
.insight-card.success { background:#f0fdf4; }
.insight-icon { font-size:1.4rem;flex-shrink:0; }
.insight-title { font-size:0.9rem;font-weight:700;color:#1e1b2e; }
.insight-message { font-size:0.82rem;color:#4a4458;line-height:1.5;margin-top:3px; }

.severity-tag {
    display:inline-block;
    padding:2px 8px;
    border-radius:6px;
    font-size:0.65rem;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:0.05em;
    margin-left:auto;
    flex-shrink:0;
}
.severity-tag.critical { background:#fee2e2;color:#991b1b; }
.severity-tag.warning { background:#fef3c7;color:#92400e; }
.severity-tag.info { background:#dbeafe;color:#1e40af; }
.severity-tag.success { background:#dcfce7;color:#166534; }

.table-row {
    display:flex;
    align-items:center;
    padding:10px 14px;
    border-radius:10px;
    margin-bottom:6px;
    background:#f8f6fb;
    font-size:0.85rem;
}
.table-row:hover { background:#f1eef8; }
.tb-label { flex:1;color:#1e1b2e;font-weight:500; }
.tb-value { font-weight:700;color:#4a4458; }

.level-badge {
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:8px 16px;
    border-radius:999px;
    font-size:0.85rem;
    font-weight:700;
}
.level-badge.high { background:#fee2e2;color:#991b1b; }
.level-badge.medium { background:#fef3c7;color:#92400e; }
.level-badge.low { background:#dcfce7;color:#166534; }

@media(max-width:768px){
    .stat-grid{grid-template-columns:1fr 1fr;gap:8px}
    .bar-chart{height:80px}
}
</style>
</head>
<body class="student-body pattern-page">
<?php include 'includes/sidebar.php'; ?>

<main class="s-main pattern-main">
<div class="s-inner">

<header class="s-head">
<div>
<div class="s-label">Pattern Analysis</div>
<h1>Understand your study patterns</h1>
<p>A quick snapshot of your study habits and the areas that may need attention.</p>
</div>
</header>
<?php include __DIR__ . '/includes/insights_tabs.php'; ?>
<p class="pattern-method-note" role="note"><span class="pattern-note-mark" aria-hidden="true">i</span><span>Insights are based only on activity recorded in your account.</span></p>

<section class="s-grid pattern-metrics" aria-label="Pattern overview">
<article class="s-card pattern-metric pattern-metric-score">
<div class="pattern-metric-heading"><span class="pattern-metric-mark" aria-hidden="true">✦</span><div class="s-card-label">Pattern score</div></div>
<div class="s-card-value"><?php echo $patternScore; ?></div>
<div class="s-card-meta"><?php echo $patternLevel; ?> signal level · Based on saved history</div>
</article>
<article class="s-card pattern-metric pattern-metric-late">
<div class="pattern-metric-heading"><span class="pattern-metric-mark" aria-hidden="true">◷</span><div class="s-card-label">Late-night activity</div></div>
<div class="s-card-value"><?php echo $lateNightPct; ?>%</div>
<div class="s-card-meta">Recorded work from 10 PM–5 AM</div>
</article>
<article class="s-card pattern-metric pattern-metric-gaps">
<div class="pattern-metric-heading"><span class="pattern-metric-mark" aria-hidden="true">↔</span><div class="s-card-label">Activity gaps</div></div>
<div class="s-card-value"><?php echo $idleCount; ?></div>
<div class="s-card-meta">2+ days · <?php echo $avgIdle; ?> day average</div>
</article>
<article class="s-card pattern-metric pattern-metric-extensions">
<div class="pattern-metric-heading"><span class="pattern-metric-mark" aria-hidden="true">↗</span><div class="s-card-label">Repeated extensions</div></div>
<div class="s-card-value"><?php echo $extendedCount; ?></div>
<div class="s-card-meta">Tasks moved to a later deadline 2+ times</div>
</article>
</section>

<section class="late-night-pattern-card" aria-labelledby="lateNightPatternTitle">
    <div class="late-night-pattern-heading">
        <div>
            <div class="s-label">1. Late-Night Work Pattern</div>
            <h2 id="lateNightPatternTitle">When does your academic work happen?</h2>
            <p>The system checks saved task and study timestamps for work completed between 10 PM and 5 AM.</p>
        </div>
        <span class="late-night-pattern-status <?php echo $lateNightPatternDetected ? 'detected' : 'not-detected'; ?>">
            <?php echo $lateNightPatternDetected ? 'Pattern detected' : 'No strong pattern'; ?>
        </span>
    </div>

    <div class="late-night-pattern-grid">
        <div class="late-night-scenario">
            <h3>Recent study sessions</h3>
            <?php if(!empty($lateNightRecentSessions)): ?>
            <div class="late-night-session-table" role="table" aria-label="Recent study session completion times">
                <div class="late-night-session-row late-night-session-header" role="row">
                    <span role="columnheader">Task</span>
                    <span role="columnheader">Time completed</span>
                </div>
                <?php foreach($lateNightRecentSessions as $session):
                    $completedTime = date('g:i A', strtotime($session['time']));
                ?>
                <div class="late-night-session-row" role="row">
                    <span role="cell">
                        <strong><?php echo htmlspecialchars($session['task']); ?></strong>
                        <small><?php echo date('M j', strtotime($session['date'])); ?></small>
                    </span>
                    <span role="cell" class="<?php echo $session['is_late'] ? 'late-time' : ''; ?>"><?php echo $completedTime; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="late-night-empty">No completed study sessions with saved start or end times yet.</div>
            <?php endif; ?>
        </div>

        <aside class="late-night-output" aria-label="Late-night pattern result">
            <?php if($lateNightPatternDetected): ?>
            <div class="late-night-output-title"><span aria-hidden="true">🌙</span><strong>Late-Night Work Pattern Detected</strong></div>
            <p class="late-night-output-count"><?php echo $lateNightRecentCount; ?> out of <?php echo $lateNightRecentTotal; ?> recent sessions were completed late at night.</p>
            <?php else: ?>
            <div class="late-night-output-title"><span aria-hidden="true">🌙</span><strong>Late-Night Pattern Not Strong Yet</strong></div>
            <p class="late-night-output-count"><?php echo $lateNightRecentCount; ?> out of <?php echo $lateNightRecentTotal; ?> recent sessions were completed late at night.</p>
            <?php endif; ?>
            <div class="late-night-recommendation">
                <strong>Recommended next step</strong>
                <p>Try scheduling important academic work earlier in the day and keep a consistent sleep schedule.</p>
            </div>
            <p class="late-night-disclaimer">This pattern does not necessarily indicate procrastination. It helps strengthen personal habit awareness.</p>
        </aside>
    </div>
</section>

    <section class="deadline-extension-pattern-card" aria-labelledby="deadlineExtensionPatternTitle">
        <div class="deadline-extension-pattern-heading">
            <div>
                <div class="s-label">2. Repeated Deadline Extension Pattern</div>
                <h2 id="deadlineExtensionPatternTitle">Is a task deadline being moved too often?</h2>
                <p>The system saves a previous deadline and a new deadline whenever a task due date is changed.</p>
            </div>
            <span class="deadline-extension-pattern-status <?php echo $deadlineExtensionPatternDetected ? 'detected' : 'not-detected'; ?>">
                <?php echo $deadlineExtensionPatternDetected ? 'Pattern detected' : 'No strong pattern'; ?>
            </span>
        </div>

        <div class="deadline-extension-pattern-grid">
            <div class="deadline-extension-scenario">
                <h3>Example scenario</h3>
                <?php if($deadlineExtensionTask): ?>
                <div class="deadline-extension-task-title">
                    <strong><?php echo htmlspecialchars($deadlineExtensionTask['title']); ?></strong>
                    <span><?php echo (int)$deadlineExtensionTask['extension_count']; ?> recorded extension<?php echo (int)$deadlineExtensionTask['extension_count'] === 1 ? '' : 's'; ?></span>
                </div>
                <div class="deadline-extension-timeline" role="list" aria-label="Deadline change history">
                    <?php foreach(array_slice($deadlineExtensionTimeline, 0, 5) as $index => $event): ?>
                    <div class="deadline-extension-row<?php echo $event['extension'] ? ' extended' : ''; ?>" role="listitem">
                        <span class="deadline-extension-number"><?php echo $index + 1; ?></span>
                        <span class="deadline-extension-date"><?php echo date('F j', strtotime($event['date'])); ?></span>
                        <span class="deadline-extension-dash" aria-hidden="true">—</span>
                        <strong><?php echo htmlspecialchars($event['label']); ?></strong>
                    </div>
                    <?php endforeach; ?>
                    <?php if(empty($deadlineExtensionTimeline)): ?>
                    <div class="deadline-extension-empty">Deadline history is not available for this task.</div>
                    <?php endif; ?>
                </div>
                <p class="deadline-extension-count"><?php echo (int)$deadlineExtensionTask['extension_count']; ?> deadline extension<?php echo (int)$deadlineExtensionTask['extension_count'] === 1 ? '' : 's'; ?> recorded</p>
                <?php else: ?>
                <div class="deadline-extension-empty">No task has repeated deadline extensions yet.</div>
                <?php endif; ?>
            </div>

            <aside class="deadline-extension-output" aria-label="Repeated deadline extension result">
                <?php if($deadlineExtensionPatternDetected): ?>
                <div class="deadline-extension-output-title"><span aria-hidden="true">🔄</span><strong>Repeated Deadline Extension Detected</strong></div>
                <p class="deadline-extension-output-count"><?php echo htmlspecialchars($deadlineExtensionTask['title']); ?> has been moved later <?php echo (int)$deadlineExtensionTask['extension_count']; ?> time<?php echo (int)$deadlineExtensionTask['extension_count'] === 1 ? '' : 's'; ?>.</p>
                <?php else: ?>
                <div class="deadline-extension-output-title"><span aria-hidden="true">🔄</span><strong>No Strong Extension Pattern Yet</strong></div>
                <p class="deadline-extension-output-count">Your saved deadline changes do not show repeated extensions for one task.</p>
                <?php endif; ?>
                <div class="deadline-extension-recommendation">
                    <strong>Recommended next step</strong>
                    <p>Break your assignment into smaller, manageable tasks and set realistic deadlines for each milestone.</p>
                </div>
            </aside>
        </div>
    </section>

<section class="missed-task-pattern-card" aria-labelledby="missedTaskPatternTitle">
    <div class="missed-task-pattern-heading">
        <div>
            <div class="s-label">3. Missed Tasks Pattern</div>
            <h2 id="missedTaskPatternTitle">How often are academic deadlines missed?</h2>
            <p>The system compares each confirmed task deadline with its completion date or current unfinished status.</p>
        </div>
        <span class="missed-task-pattern-status <?php echo $missedPatternDetected ? 'detected' : 'not-detected'; ?>">
            <?php echo $missedPatternDetected ? 'Pattern detected' : 'No strong pattern'; ?>
        </span>
    </div>

    <div class="missed-task-scenario">
        <h3>Example scenario</h3>
        <div class="missed-task-summary">
            <article>
                <span>Total tasks</span>
                <strong><?php echo $missedDeadlineTaskTotal; ?></strong>
            </article>
            <article class="on-time">
                <span>On time</span>
                <strong><?php echo $missedOnTimeCount; ?></strong>
            </article>
            <article class="missed">
                <span>Missed deadlines</span>
                <strong><?php echo $missedCount; ?></strong>
            </article>
        </div>
        <div class="missed-task-progress" role="img" aria-label="<?php echo $missedDeadlineRate; ?> percent missed deadline rate">
            <span class="missed-task-progress-on-time" style="width:<?php echo max(0, 100 - $missedDeadlineRate); ?>%"></span>
            <span class="missed-task-progress-missed" style="width:<?php echo $missedDeadlineRate; ?>%"></span>
        </div>
        <p class="missed-task-rate"><?php echo $missedDeadlineRate; ?>% missed deadline rate</p>
    </div>

    <aside class="missed-task-output" aria-label="Missed task pattern result">
        <?php if($missedPatternDetected): ?>
        <div class="missed-task-output-title"><span aria-hidden="true">❌</span><strong>Frequent Missed Deadlines Detected</strong></div>
        <p>You have missed <?php echo $missedDeadlineRate; ?>% of your recent deadlines.</p>
        <?php else: ?>
        <div class="missed-task-output-title"><span aria-hidden="true">❌</span><strong>No Frequent Missed-Deadline Pattern Yet</strong></div>
        <p><?php echo $missedCount; ?> task<?php echo $missedCount === 1 ? '' : 's'; ?> have confirmed missed deadlines.</p>
        <?php endif; ?>
        <div class="missed-task-recommendation">
            <strong>Recommended next step</strong>
            <p>Prioritize unfinished tasks by urgency and importance. Break larger tasks into smaller steps.</p>
        </div>
    </aside>
</section>

<section class="s-two pattern-section pattern-overview" aria-label="Pattern insights and activity details">
<div class="s-panel pattern-panel">
<div class="s-panel-header">
<div>
<div class="s-label">Activity insights</div>
<h3>What stands out</h3>
</div>
<span class="level-badge <?php echo strtolower($patternLevel); ?>" aria-label="<?php echo $patternLevel; ?> signal level">
<span style="width:8px;height:8px;border-radius:50%;background:<?php echo $patternLevel === 'High' ? '#dc2626' : ($patternLevel === 'Medium' ? '#f59e0b' : '#16a34a'); ?>"></span>
<?php echo $patternLevel; ?> signal
</span>
</div>

<?php foreach($insights as $insight): ?>
<article class="insight-card <?php echo $insight['severity']; ?>">
<span class="insight-icon" aria-hidden="true"><?php echo $insight['icon']; ?></span>
<div>
<div class="insight-title"><?php echo htmlspecialchars($insight['title']); ?></div>
<div class="insight-message"><?php echo htmlspecialchars($insight['message']); ?></div>
</div>
<span class="severity-tag <?php echo $insight['severity']; ?>"><?php echo $insight['severity']==='critical'?'Priority':($insight['severity']==='warning'?'Watch':'Info'); ?></span>
</article>
<?php endforeach; ?>
</div>

<aside class="pattern-overview-aside" aria-label="Activity breakdown">
<section class="s-panel pattern-panel">
<div class="s-panel-header">
<div>
<div class="s-label">Quick check</div>
<h3>Worth a closer look</h3>
</div>
</div>
<div class="stat-grid">
<div class="stat-box">
<div class="stat-value" style="color:#dc2626"><?php echo $missedCount; ?></div>
<div class="stat-label">Missed tasks</div>
</div>
<div class="stat-box">
<div class="stat-value" style="color:#f59e0b"><?php echo $subjectTrendCount; ?></div>
<div class="stat-label">Subject signals</div>
</div>
</div>
<p class="pattern-task-note">Check the task details below for what’s overdue or repeatedly rescheduled.</p>
</section>
</aside>
</section>

<section class="long-idle-pattern-card" aria-labelledby="longIdlePatternTitle">
    <div class="long-idle-pattern-heading">
        <div>
            <div class="s-label">4. Long Idle Periods Pattern</div>
            <h2 id="longIdlePatternTitle">Which unfinished task has stayed inactive the longest?</h2>
            <p>The system compares the task creation date, study or activity dates, and the time without recorded progress.</p>
        </div>
        <span class="long-idle-pattern-status <?php echo $idlePatternDetected ? 'detected' : 'not-detected'; ?>">
            <?php echo $idlePatternDetected ? 'Pattern detected' : 'No strong pattern'; ?>
        </span>
    </div>

    <div class="long-idle-pattern-grid">
        <div class="long-idle-scenario">
            <h3>Example scenario</h3>
            <?php if($idleTask): ?>
            <div class="long-idle-task-title"><?php echo htmlspecialchars($idleTask['title']); ?></div>
            <div class="long-idle-timeline" role="img" aria-label="<?php echo $idleTaskDays; ?> days without recorded progress">
                <div class="long-idle-timeline-labels">
                    <span>Created</span>
                    <span>Started / updated</span>
                </div>
                <div class="long-idle-track">
                    <span class="long-idle-track-dot start"></span>
                    <span class="long-idle-track-line"></span>
                    <span class="long-idle-track-dot end"></span>
                </div>
                <div class="long-idle-dates">
                    <span><?php echo date('n/j', strtotime($idleTaskCreated)); ?></span>
                    <span><?php echo $idleTaskActivity ? date('n/j', strtotime($idleTaskActivity)) : date('n/j'); ?></span>
                </div>
            </div>
            <p class="long-idle-result"><?php echo $idleTaskDays; ?> day<?php echo $idleTaskDays === 1 ? '' : 's'; ?> without recorded progress</p>
            <?php else: ?>
            <div class="long-idle-empty">No unfinished task history is available yet.</div>
            <?php endif; ?>
        </div>

        <aside class="long-idle-output" aria-label="Long idle period result">
            <?php if($idlePatternDetected): ?>
            <div class="long-idle-output-title"><span aria-hidden="true">⏸️</span><strong>Long Task Inactivity Detected</strong></div>
            <p>You have recorded no progress for <strong><?php echo $idleTaskDays; ?> days</strong> on <?php echo htmlspecialchars($idleTask['title']); ?>.</p>
            <?php elseif($idleTask): ?>
            <div class="long-idle-output-title"><span aria-hidden="true">⏸️</span><strong>No Strong Idle Pattern Yet</strong></div>
            <p>Your longest unfinished task inactivity is <?php echo $idleTaskDays; ?> day<?php echo $idleTaskDays === 1 ? '' : 's'; ?>.</p>
            <?php else: ?>
            <div class="long-idle-output-title"><span aria-hidden="true">⏸️</span><strong>No Task Idle Data Yet</strong></div>
            <p>Create tasks and record study sessions to calculate inactivity.</p>
            <?php endif; ?>
            <div class="long-idle-recommendation">
                <strong>Recommended next step</strong>
                <p>Start with a small 10-minute portion of the task, record progress, and reuse momentum for the next step.</p>
            </div>
        </aside>
    </div>
</section>

</div>
</main>

</body>
</html>
