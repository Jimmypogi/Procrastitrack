<?php
session_start();

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

// The standalone Behavior page has been retired; its core tools remain available
// through Check-ins, Study Sessions, Delay Logging, and Productivity.
header('Location: charts.php', true, 302);
exit();

include 'config/database.php';
$user_id = intval($_SESSION['user_id']);
require_once __DIR__.'/lib/activity_score.php';
$activityScore = app_activity_score($conn, $user_id);
require_once __DIR__.'/lib/checkin_support.php';
checkin_support_schema($conn);
$behaviorToday = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
$behaviorWeekStart = $behaviorToday->modify('monday this week')->format('Y-m-d');

$totals = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total, SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) as completed FROM tasks WHERE user_id=$user_id"));
$task_stats = [
    'total' => intval($totals['total'] ?? 0),
    'completed' => intval($totals['completed'] ?? 0),
];
$rate = $task_stats['total'] > 0 ? round(($task_stats['completed'] / $task_stats['total']) * 100) : 0;

$delayCount = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='delay'"));
$sessionCount = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='session'"));
$checkinCount = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='checkin'"));
$todayCheckins = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='checkin' AND DATE(created_at)=CURDATE()"));
$weekCheckins = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='checkin' AND created_at >= '$behaviorWeekStart' AND created_at <= NOW()"));
$studySessions = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM study_sessions WHERE user_id=$user_id"));

$avgFocusAll = mysqli_fetch_assoc(mysqli_query($conn, "SELECT AVG(focus_level) as avg_f FROM procrastiblock_logs WHERE user_id=$user_id AND focus_level IS NOT NULL"));
$avgEnergyAll = mysqli_fetch_assoc(mysqli_query($conn, "SELECT AVG(energy_level) as avg_e FROM procrastiblock_logs WHERE user_id=$user_id AND energy_level IS NOT NULL"));
$avgProcrast = mysqli_fetch_assoc(mysqli_query($conn, "SELECT AVG(procrastination_level) as avg_p FROM procrastiblock_logs WHERE user_id=$user_id AND procrastination_level IS NOT NULL"));
$avgCheckinFocus = mysqli_fetch_assoc(mysqli_query($conn, "SELECT AVG(focus_level) as avg_f FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='checkin' AND focus_level BETWEEN 1 AND 10 AND created_at <= NOW()"));
$avgCheckinEnergy = mysqli_fetch_assoc(mysqli_query($conn, "SELECT AVG(energy_level) as avg_e FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='checkin' AND energy_level BETWEEN 1 AND 10 AND created_at <= NOW()"));

$entries = mysqli_query($conn, "SELECT * FROM procrastiblock_logs WHERE user_id=$user_id ORDER BY created_at DESC LIMIT 15");

$totalDelayMin = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(duration_minutes),0) as total FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='delay'"));
$totalStudyMin = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(duration_minutes),0) as total FROM study_sessions WHERE user_id=$user_id"));

$upcoming = mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status!='Completed' ORDER BY deadline ASC LIMIT 3");

$hasTodayCheckin = false;
$hasWeekCheckin = false;
$typedCheckins = $conn->prepare("SELECT l.created_at,l.notes,r.answers FROM procrastiblock_logs l LEFT JOIN checkin_reflections r ON r.log_id=l.id AND r.user_id=l.user_id WHERE l.user_id=? AND l.log_type='checkin' AND l.created_at>=? AND l.created_at<=NOW()");
$typedCheckins->bind_param('is', $user_id, $behaviorWeekStart);
$typedCheckins->execute();
foreach ($typedCheckins->get_result()->fetch_all(MYSQLI_ASSOC) as $entry) {
    $answers = json_decode($entry['answers'] ?? '', true);
    $type = is_array($answers) ? ($answers['checkin_type'] ?? '') : '';
    // Older entries may have an explicit type in notes. Never guess an untyped entry.
    if (!in_array($type, ['Daily','Weekly'], true)) {
        $type = preg_match('/^(Daily|Weekly)(?::|$)/', $entry['notes'] ?? '', $match) ? $match[1] : '';
    }
    if ($type === 'Daily' && substr($entry['created_at'], 0, 10) === $behaviorToday->format('Y-m-d')) $hasTodayCheckin = true;
    if ($type === 'Weekly') $hasWeekCheckin = true;
}
$typedCheckins->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Behavior Tracking — ProcrastiTrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/student.css">
<link rel="stylesheet" href="assets/css/behavior-capture.css?v=20261105">
<link rel="stylesheet" href="assets/css/activity-score.css?v=20261105">
</head>
<body class="student-body">
<?php include 'includes/sidebar.php'; ?>

<main class="s-main">
<div class="s-inner">

<header class="s-head">
<div>
<div class="s-label">Behavior</div>
<h1>Behavior Tracking</h1>
<p>Monitor delays, study sessions, and behavior patterns through regular check-ins.</p>
</div>
  <div class="s-head-actions">
  <a href="checkin.php" class="s-btn primary">📝 Check-in</a>
  <a href="log_delay.php" class="s-btn">&#9888; Log Delay</a>
  <a href="study_sessions.php" class="s-btn">&#9998; Study</a>
  <a href="reports.php" class="s-btn">📈 Weekly Report</a>
  </div>
</header>

<section class="behavior-capture" id="record-activity" aria-labelledby="captureHeading">
<header><div><div class="s-label">ONE SMALL STEP AT A TIME</div><h2 id="captureHeading">Record your progress</h2><p>Choose what happened. Keep your study journey in one place.</p></div><a class="s-btn" href="study_coach.php">Find my next step <span aria-hidden="true">&rarr;</span></a></header>
<?php if (!empty($_SESSION['legacy_capture_notice'])): unset($_SESSION['legacy_capture_notice']); ?>
<p class="behavior-capture-notice" role="status">The previous entry form has been replaced. Your latest submission was not saved. Choose the matching option below to record it. Your previously saved records are still available.</p>
<?php endif; ?>
<div class="behavior-capture-grid">
<a href="log_delay.php"><span class="behavior-capture-icon"><i class="app-icon" data-lucide="pause" aria-hidden="true"></i></span><h3>Log a delay</h3><p>Record what got in the way and the task it affected.</p><span class="behavior-capture-action">Reflect on a delay <span aria-hidden="true">&rarr;</span></span></a>
<a href="study_sessions.php"><span class="behavior-capture-icon"><i class="app-icon" data-lucide="timer" aria-hidden="true"></i></span><h3>Track study time</h3><p>Start a Pomodoro timer; completed focus sessions are saved automatically.</p><span class="behavior-capture-action">Open focus timer <span aria-hidden="true">&rarr;</span></span></a>
<a href="checkin.php"><span class="behavior-capture-icon"><i class="app-icon" data-lucide="notebook-pen" aria-hidden="true"></i></span><h3>Check in with yourself</h3><p>Reflect on your day or week, focus, and energy.</p><span class="behavior-capture-action">Start a reflection <span aria-hidden="true">&rarr;</span></span></a>
<a href="tasks.php"><span class="behavior-capture-icon"><i class="app-icon" data-lucide="circle-check" aria-hidden="true"></i></span><h3>Finish a task</h3><p>Mark a task complete and find it in your Profile history.</p><span class="behavior-capture-action">Go to my tasks <span aria-hidden="true">&rarr;</span></span></a>
</div><p class="behavior-capture-footnote">Study time and task completion are recorded separately. Completing a focus sprint won’t mark its task complete; finish the task from My Tasks.</p>
</section>
<section class="s-grid">
<article class="s-card">
<div class="s-card-label">Tasks Completed</div>
<div class="s-card-value"><?php echo $task_stats['completed']; ?></div>
<div class="s-card-meta"><?php echo $rate; ?>% completion rate</div>
</article>
<article class="s-card">
<div class="s-card-label">Delay Logs</div>
<div class="s-card-value"><?php echo $delayCount; ?></div>
<div class="s-card-meta"><?php echo $totalDelayMin['total']; ?>m lost</div>
</article>
<article class="s-card">
<div class="s-card-label">Study Time</div>
<div class="s-card-value"><?php echo $totalStudyMin['total']; ?>m</div>
<div class="s-card-meta"><?php echo $studySessions; ?> sessions</div>
</article>
<article class="s-card">
<div class="s-card-label">Check-ins</div>
<div class="s-card-value"><?php echo $checkinCount; ?></div>
<div class="s-card-meta"><?php echo $todayCheckins; ?> today, <?php echo $weekCheckins; ?> this week</div>
</article>
<article class="s-card">
<div class="s-card-label">Avg Focus</div>
<div class="s-card-value"><?php echo $avgCheckinFocus['avg_f'] === null ? '<span style="font-size:1rem">No data yet</span>' : round($avgCheckinFocus['avg_f'], 1).'/10'; ?></div>
<div class="s-card-meta">From check-ins</div>
</article>
<article class="s-card">
<div class="s-card-label">Avg Energy</div>
<div class="s-card-value"><?php echo $avgCheckinEnergy['avg_e'] === null ? '<span style="font-size:1rem">No data yet</span>' : round($avgCheckinEnergy['avg_e'], 1).'/10'; ?></div>
<div class="s-card-meta">From check-ins</div>
</article>
</section>

<section class="s-two">
<div class="s-panel">
<div class="s-panel-header">
<div>
<div class="s-label">Pattern Summary</div>
<h3>Procrastination signals & trends</h3>
</div>
</div>
<table style="width:100%;border-collapse:collapse">
<thead>
<tr style="border-bottom:2px solid #f3f0f7">
<th style="text-align:left;padding:8px 0;font-size:0.8rem;color:#948da8;font-weight:600;text-transform:uppercase">Metric</th>
<th style="text-align:right;padding:8px 0;font-size:0.8rem;color:#948da8;font-weight:600">Count</th>
</tr>
</thead>
<tbody>
<tr style="border-bottom:1px solid #f8f6fb">
<td style="padding:10px 0;font-size:0.9rem;color:#1e1b2e">Delay logs</td>
<td style="padding:10px 0;text-align:right;font-size:0.9rem;font-weight:700;color:#f59e0b"><?php echo $delayCount; ?></td>
</tr>
<tr style="border-bottom:1px solid #f8f6fb">
<td style="padding:10px 0;font-size:0.9rem;color:#1e1b2e">Study sessions</td>
<td style="padding:10px 0;text-align:right;font-size:0.9rem;font-weight:700;color:#0ea5e9"><?php echo $studySessions; ?></td>
</tr>
<tr style="border-bottom:1px solid #f8f6fb">
<td style="padding:10px 0;font-size:0.9rem;color:#1e1b2e">Behavior check-ins</td>
<td style="padding:10px 0;text-align:right;font-size:0.9rem;font-weight:700;color:#16a34a"><?php echo $checkinCount; ?></td>
</tr>
<tr style="border-bottom:1px solid #f8f6fb">
<td style="padding:10px 0;font-size:0.9rem;color:#1e1b2e">Today's check-ins</td>
<td style="padding:10px 0;text-align:right;font-size:0.9rem;font-weight:700;color:#7c3aed"><?php echo $todayCheckins; ?></td>
</tr>
<tr style="border-bottom:1px solid #f8f6fb">
<td style="padding:10px 0;font-size:0.9rem;color:#1e1b2e">This week's check-ins</td>
<td style="padding:10px 0;text-align:right;font-size:0.9rem;font-weight:700;color:#8B5CF6"><?php echo $weekCheckins; ?></td>
</tr>
<tr style="border-bottom:1px solid #f8f6fb">
<td style="padding:10px 0;font-size:0.9rem;color:#1e1b2e">High priority pending</td>
<td style="padding:10px 0;text-align:right;font-size:0.9rem;font-weight:700;color:#dc2626">
<?php echo intval(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tasks WHERE user_id=$user_id AND priority='High' AND status!='Completed'"))['c'] ?? 0); ?>
</td>
</tr>
</tbody>
</table>
<?php include __DIR__.'/includes/activity_score_summary.php'; ?>
</div>

<div class="s-panel">
<div class="s-panel-header">
<div>
<div class="s-label">Recent Activity</div>
<h3>Latest logs & check-ins</h3>
</div>
</div>
<ul class="s-list">
<?php
$activities = [];

$t = mysqli_query($conn, "SELECT title, status, deadline, created_at FROM tasks WHERE user_id=$user_id ORDER BY created_at DESC LIMIT 3");
while($row = mysqli_fetch_assoc($t)){
    $activities[] = [
        'type' => 'task',
        'text' => htmlspecialchars($row['title']),
        'sub' => 'Status: ' . htmlspecialchars($row['status']) . ' | Due: ' . htmlspecialchars($row['deadline']),
        'time' => $row['created_at'],
        'dot' => $row['status'] === 'Completed' ? 'green' : ($row['status'] === 'In Progress' ? 'blue' : 'orange'),
    ];
}

$d = mysqli_query($conn, "SELECT reason, created_at, focus_level, energy_level FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='delay' ORDER BY created_at DESC LIMIT 3");
while($row = mysqli_fetch_assoc($d)){
    $activities[] = [
        'type' => 'delay',
        'text' => 'Delay Logged',
        'sub' => htmlspecialchars(substr($row['reason'] ?: '', 0, 80)),
        'time' => $row['created_at'],
        'dot' => 'orange',
    ];
}

$s = mysqli_query($conn, "SELECT duration_minutes, notes, created_at FROM study_sessions WHERE user_id=$user_id ORDER BY created_at DESC LIMIT 3");
while($row = mysqli_fetch_assoc($s)){
    $activities[] = [
        'type' => 'session',
        'text' => 'Study Session: ' . $row['duration_minutes'] . 'min',
        'sub' => htmlspecialchars(substr($row['notes'] ?: 'No notes', 0, 80)),
        'time' => $row['created_at'],
        'dot' => 'blue',
    ];
}

$c = mysqli_query($conn, "SELECT focus_level, energy_level, mood, created_at FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='checkin' ORDER BY created_at DESC LIMIT 3");
while($row = mysqli_fetch_assoc($c)){
    $activities[] = [
        'type' => 'checkin',
        'text' => 'Check-in' . ($row['mood'] ? ' - ' . htmlspecialchars($row['mood']) : ''),
        'sub' => 'Focus: ' . ($row['focus_level'] ?: '?') . '/10 | Energy: ' . ($row['energy_level'] ?: '?') . '/10',
        'time' => $row['created_at'],
        'dot' => 'green',
    ];
}

usort($activities, function($a, $b) { return strtotime($b['time']) - strtotime($a['time']); });
$activities = array_slice($activities, 0, 8);

if(!empty($activities)):
foreach($activities as $act):
?>
<li>
<span class="s-dot <?php echo $act['dot']; ?>"></span>
<div>
<div class="s-list-text"><strong><?php echo $act['text']; ?></strong></div>
<div style="font-size:0.82rem;color:#6b6580;margin-top:2px"><?php echo $act['sub']; ?></div>
<div class="s-list-time"><?php echo date('M d, Y h:i A', strtotime($act['time'])); ?></div>
</div>
</li>
<?php endforeach; ?>
<?php else: ?>
<div class="s-empty">No recent activity yet. Start by doing a check-in!</div>
<?php endif; ?>
</ul>
</div>
</section>

<section class="s-two" style="margin-top:24px">
<div class="s-panel">
<div class="s-panel-header">
<div>
<div class="s-label">Check-in Schedule</div>
<h3>Recommended frequency</h3>
</div>
</div>
<div style="padding:8px 0">
<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;padding:12px;background:#f8f6fb;border-radius:12px">
<span style="font-size:1.5rem">📅</span>
<div>
<strong style="font-size:0.92rem;color:#1e1b2e">Daily Check-in</strong>
<p style="margin:2px 0 0;color:#6b6580;font-size:0.82rem">2 min · Every evening · Reflect on the day</p>
<?php if($hasTodayCheckin): ?>
<span style="display:inline-block;margin-top:6px;padding:4px 10px;border-radius:999px;background:#dcfce7;color:#166534;font-size:0.72rem;font-weight:600">✅ Done today</span>
<?php else: ?>
<span style="display:inline-block;margin-top:6px;padding:4px 10px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:0.72rem;font-weight:600">⏰ Not yet done</span>
<?php endif; ?>
</div>
</div>
<div style="display:flex;align-items:center;gap:12px;padding:12px;background:#f8f6fb;border-radius:12px">
<span style="font-size:1.5rem">📆</span>
<div>
<strong style="font-size:0.92rem;color:#1e1b2e">Weekly Check-in</strong>
<p style="margin:2px 0 0;color:#6b6580;font-size:0.82rem">5 min · End of week · Deep review</p>
<?php if($hasWeekCheckin): ?>
<span style="display:inline-block;margin-top:6px;padding:4px 10px;border-radius:999px;background:#dcfce7;color:#166534;font-size:0.72rem;font-weight:600">✅ Done this week</span>
<?php else: ?>
<span style="display:inline-block;margin-top:6px;padding:4px 10px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:0.72rem;font-weight:600">⏰ Not yet done</span>
<?php endif; ?>
</div>
</div>
</div>
</div>

<div class="s-panel">
<div class="s-panel-header">
<div>
<div class="s-label">Insights</div>
<h3>From your check-in data</h3>
</div>
</div>
<div style="padding:8px 0">
<?php
$insights = [];
if($avgCheckinFocus['avg_f'] === null){
    $insights[] = ['icon' => '', 'text' => 'No focus rating recorded yet. Add a check-in to see your focus insights.'];
} elseif($avgCheckinFocus['avg_f'] >= 7){
    $insights[] = ['icon' => '🎯', 'text' => 'Your average focus is strong (' . round($avgCheckinFocus['avg_f'], 1) . '/10). Keep maintaining your routine.'];
} elseif($avgCheckinFocus['avg_f'] >= 4){
    $insights[] = ['icon' => '⚡', 'text' => 'Your average focus is moderate (' . round($avgCheckinFocus['avg_f'], 1) . '/10). Try morning study blocks for improvement.'];
} else {
    $insights[] = ['icon' => '🔧', 'text' => 'Your average focus is low (' . round($avgCheckinFocus['avg_f'], 1) . '/10). Start with 15-min focused sessions.'];
}
if($avgCheckinEnergy['avg_e'] === null){
    $insights[] = ['icon' => '', 'text' => 'No energy rating recorded yet. Add a check-in to see your energy insights.'];
} elseif($avgCheckinEnergy['avg_e'] >= 7){
    $insights[] = ['icon' => '💪', 'text' => 'Great energy levels! Your body supports your study goals.'];
} elseif($avgCheckinEnergy['avg_e'] < 5){
    $insights[] = ['icon' => '😴', 'text' => 'Low energy detected. Consider better sleep or exercise.'];
}
if($delayCount > 5){
    $insights[] = ['icon' => '⚠️', 'text' => 'High delay count. Use delay logging to identify patterns.'];
}
if($studySessions >= 5){
    $insights[] = ['icon' => '📚', 'text' => 'Excellent study habit! ' . $studySessions . ' sessions logged.'];
}
if($checkinCount >= 5){
    $insights[] = ['icon' => '🏆', 'text' => 'Great consistency! ' . $checkinCount . ' check-ins completed.'];
}
if(empty($insights)){
    $insights[] = ['icon' => '💡', 'text' => 'Start logging to get personalized insights.'];
}
foreach($insights as $insight):
?>
<div style="padding:10px 0;border-bottom:1px solid #f3f0f7;display:flex;align-items:flex-start;gap:10px">
<span style="font-size:1.2rem;flex-shrink:0"><?php echo $insight['icon']; ?></span>
<div style="font-size:0.85rem;color:#334155;line-height:1.5"><?php echo $insight['text']; ?></div>
</div>
<?php endforeach; ?>
</div>
</div>
</section>

</div>
</main>

</body>
</html>
