<?php
session_start();

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

include 'config/database.php';
$user_id = intval($_SESSION['user_id']);

// ===== TASK DATA =====
$taskData = [];
$result = mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id");
$totalTasks = mysqli_num_rows($result);
$completedTasks = 0;
$pendingTasks = 0;
$inProgressTasks = 0;
$highPriority = 0;
$mediumPriority = 0;
$lowPriority = 0;
$priorityCompleted = ['High' => 0, 'Medium' => 0, 'Low' => 0];
$priorityUnfinished = ['High' => 0, 'Medium' => 0, 'Low' => 0];

while($row = mysqli_fetch_assoc($result)){
    $isCompleted = ($row['status'] === 'Completed');
    if($isCompleted) $completedTasks++;
    elseif($row['status'] === 'Pending') $pendingTasks++;
    else $inProgressTasks++;
    $p = $row['priority'] ?? 'Low';
    if (!in_array($p, ['High', 'Medium', 'Low'], true)) $p = 'Low';
    if($p === 'High') $highPriority++;
    elseif($p === 'Medium') $mediumPriority++;
    else $lowPriority++;
    if ($isCompleted) {
        $priorityCompleted[$p]++;
    } else {
        $priorityUnfinished[$p]++;
    }
}
$completionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;
$rescheduledTasks = $conn->query("SELECT task_id, title, old_deadline, new_deadline, change_count
    FROM (
        SELECT h.task_id, t.title, h.old_deadline, h.new_deadline, h.changed_at, h.id,
            COUNT(*) OVER (PARTITION BY h.task_id) AS change_count,
            ROW_NUMBER() OVER (PARTITION BY h.task_id ORDER BY h.changed_at DESC, h.id DESC) AS latest
        FROM task_deadline_history h JOIN tasks t ON t.id=h.task_id AND t.user_id=h.user_id
        WHERE h.user_id=$user_id AND NOT (h.old_deadline <=> h.new_deadline)
        AND (h.old_deadline IS NOT NULL OR h.change_reason='Deadline changed in task editor')
    ) AS changes WHERE latest=1 ORDER BY changed_at DESC, id DESC")->fetch_all(MYSQLI_ASSOC);
$scheduleChangeCount = array_sum(array_column($rescheduledTasks, 'change_count'));

if ($totalTasks === 0) {
    $priorityInsight = 'No tasks recorded yet. Add tasks with priorities to track how you handle High, Medium, and Low priority work.';
} elseif ($priorityCompleted['Low'] > $priorityCompleted['High'] && $priorityUnfinished['High'] > 0) {
    $priorityInsight = "You complete more low-priority tasks ({$priorityCompleted['Low']}) than high-priority tasks ({$priorityCompleted['High']}). Consider prioritizing important assignments.";
} elseif ($priorityUnfinished['High'] > $priorityCompleted['High']) {
    $priorityInsight = "You have {$priorityUnfinished['High']} unfinished high-priority task(s) compared to {$priorityCompleted['High']} completed. Try tackling an important task first.";
} elseif ($priorityCompleted['High'] > 0) {
    $priorityInsight = "Great focus! You have completed {$priorityCompleted['High']} high-priority task(s)" . ($priorityUnfinished['High'] > 0 ? " with {$priorityUnfinished['High']} remaining." : " and cleared all high-priority items.");
} else {
    $priorityInsight = 'Keep balancing your workload across High, Medium, and Low priority tasks.';
}

// Weekly task completion
$weeklyCompleted = [];
$weeklyCreated = [];
$weekDays = [];
$weekShortDays = [];
$weekFullDays = [];
for($i = 6; $i >= 0; $i--){
    $d = date('Y-m-d', strtotime("-$i days"));
    $weekDays[] = date('D, M j', strtotime($d));
    $weekShortDays[] = date('D', strtotime($d));
    $weekFullDays[] = date('l', strtotime($d));
    $completed = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tasks WHERE user_id=$user_id AND status='Completed' AND DATE(completed_at)='$d'"))['c'];
    $created = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tasks WHERE user_id=$user_id AND DATE(created_at)='$d'"))['c'];
    $weeklyCompleted[] = $completed;
    $weeklyCreated[] = $created;
}

$totalWeeklyCompleted = array_sum($weeklyCompleted);
if ($totalWeeklyCompleted === 0) {
    $weeklyInsight = 'No tasks completed in the last 7 days yet. Complete a task to see which days you are most productive.';
} else {
    $maxVal = max($weeklyCompleted);
    $minVal = min($weeklyCompleted);
    $maxIdx = array_search($maxVal, $weeklyCompleted, true);
    $minIdx = array_search($minVal, $weeklyCompleted, true);
    $maxDay = $weekFullDays[$maxIdx];
    $minDay = $weekFullDays[$minIdx];
    if ($maxVal === $minVal) {
        $weeklyInsight = "You maintained a steady pace with {$maxVal} task(s) completed each day this week.";
    } else {
        $weeklyInsight = "You completed the most tasks on {$maxDay} ({$maxVal}) and the fewest on {$minDay} ({$minVal}).";
    }
}

// Late completions over the last 4 weeks
$lateWeeksLabels = ['Week 1', 'Week 2', 'Week 3', 'Week 4'];
$lateWeeksCounts = [];
for ($w = 3; $w >= 0; $w--) {
    $wStart = date('Y-m-d', strtotime('-' . ($w * 7 + 6) . ' days'));
    $wEnd = date('Y-m-d', strtotime('-' . ($w * 7) . ' days'));
    $lateRow = mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT COUNT(*) as c FROM tasks WHERE user_id=$user_id AND status='Completed' AND deadline IS NOT NULL AND completed_at IS NOT NULL AND DATE(completed_at) > deadline AND DATE(completed_at) BETWEEN '$wStart' AND '$wEnd'"
    ));
    $lateWeeksCounts[] = (int)($lateRow['c'] ?? 0);
}

$totalLate4Weeks = array_sum($lateWeeksCounts);
$w1Late = $lateWeeksCounts[0];
$w4Late = $lateWeeksCounts[3];
if ($totalLate4Weeks === 0) {
    $lateInsight = 'No late task completions recorded over the last 4 weeks. Keep staying ahead of your deadlines!';
} elseif ($w4Late < $w1Late) {
    $lateInsight = "Your late task completions have decreased compared to Week 1 ({$w1Late} → {$w4Late}). This suggests an improvement in meeting deadlines.";
} elseif ($w4Late > $w1Late) {
    $lateInsight = "Your late task completions increased compared to Week 1 ({$w1Late} → {$w4Late}). Consider breaking larger tasks into earlier milestones.";
} else {
    $lateInsight = "Your late task completions remained steady at {$w4Late} compared to Week 1. Try setting earlier reminders to reduce late submissions.";
}

// ===== REPORTED PROCRASTINATION FACTORS (LAST 4 WEEKLY CHECK-INS) =====
$factorCounts = [];
$weeklyCheckinRows = mysqli_query(
    $conn,
    "SELECT answers FROM checkin_reflections WHERE user_id=$user_id AND (checkin_type='Weekly' OR answers LIKE '%\"checkin_type\":\"Weekly\"%' OR answers LIKE '%weekly_distraction%' OR answers LIKE '%weekly_reason%') ORDER BY created_at DESC LIMIT 4"
);
if ($weeklyCheckinRows) {
    while ($ciRow = mysqli_fetch_assoc($weeklyCheckinRows)) {
        $answers = json_decode((string)($ciRow['answers'] ?? ''), true);
        if (!is_array($answers)) {
            continue;
        }
        $candidates = [
            $answers['weekly_distraction'] ?? null,
            $answers['responses']['weekly_distraction']['answer'] ?? null,
            $answers['weekly_reason'] ?? null,
            $answers['responses']['weekly_reason']['answer'] ?? null,
            $answers['blocker'] ?? null,
        ];
        $reason = '';
        foreach ($candidates as $candidate) {
            $val = trim((string)$candidate);
            if ($val !== '' && strcasecmp($val, 'I did not procrastinate') !== 0 && strcasecmp($val, 'None') !== 0) {
                $reason = $val;
                break;
            }
        }
        if ($reason === '') {
            continue;
        }
        $factorCounts[$reason] = ($factorCounts[$reason] ?? 0) + 1;
    }
}
arsort($factorCounts);
$factorCounts = array_slice($factorCounts, 0, 5, true);
$maxFactorCount = !empty($factorCounts) ? max($factorCounts) : 0;
if (empty($factorCounts)) {
    $factorInsight = 'No procrastination factors reported in your last four weekly check-ins yet. Complete a weekly check-in to uncover your patterns.';
} else {
    $topFactor = (string)array_key_first($factorCounts);
    $factorInsight = "{$topFactor} is your most frequently reported reason for procrastinating.";
}

// ===== OVERALL TASK COUNTS =====
$overdueCount = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status!='Completed' AND deadline < CURDATE()"));
$activePendingCount = max(0, ($pendingTasks + $inProgressTasks) - $overdueCount);
$completedPct = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;
$overduePct = $totalTasks > 0 ? round(($overdueCount / $totalTasks) * 100) : 0;
$pendingPct = $totalTasks > 0 ? round(($activePendingCount / $totalTasks) * 100) : 0;

// ===== STUDY SESSIONS DATA =====
$studyWeekly = [];
for($i = 6; $i >= 0; $i--){
    $d = date('Y-m-d', strtotime("-$i days"));
    $minutes = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(duration_minutes),0) as total FROM study_sessions WHERE user_id=$user_id AND session_date='$d'"))['total'];
    $studyWeekly[] = $minutes;
}

// ===== BEHAVIOR DATA =====
$delayWeekly = [];
$delayMinutesWeekly = [];
$sessionWeekly = [];
$checkinWeekly = [];
for($i = 6; $i >= 0; $i--){
    $d = date('Y-m-d', strtotime("-$i days"));
    $delays = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='delay' AND DATE(created_at)='$d'"))['c'];
    $sessions = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM study_sessions WHERE user_id=$user_id AND session_date='$d'"))['c'];
    $checkins = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='checkin' AND DATE(created_at)='$d'"))['c'];
    $delayWeekly[] = (int)$delays;
    $delayMinutesWeekly[] = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(duration_minutes),0) AS minutes FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='delay' AND DATE(created_at)='$d'"))['minutes'];
    $sessionWeekly[] = $sessions;
    $checkinWeekly[] = $checkins;
}

// ===== OVERALL STATS =====
$totalStudyMin = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(duration_minutes),0) as total FROM study_sessions WHERE user_id=$user_id"));
$totalDelayMin = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(duration_minutes),0) as total FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='delay'"));
$sessionCount = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM study_sessions WHERE user_id=$user_id"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Charts & Summaries — ProcrastiTrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/student.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
.chart-container {
    position:relative;
    height:240px;
    width:100%;
}
.chart-container-lg {
    position:relative;
    height:300px;
    width:100%;
}
.analytics-page .s-inner{max-width:1480px}
.analytics-page{background:radial-gradient(ellipse at 88% 2%,rgba(211,226,214,.28),transparent 34%),#f5f4ef}
.analytics-page .s-head{align-items:flex-end;margin-bottom:32px}
.analytics-page .s-head h1{font-size:clamp(2rem,4vw,3rem);letter-spacing:-.055em;line-height:1.08}
.analytics-page .s-head p{max-width:560px;font-size:1rem}
.analytics-page .s-label{color:#698c73;letter-spacing:.13em}
.analytics-page .s-head-actions .s-btn{border-radius:999px;padding:12px 20px;box-shadow:0 8px 20px #315a4318}
.analytics-page .analytics-tabs{display:flex;gap:6px;flex-wrap:wrap;padding:6px;background:#e9eee9;border:1px solid #e1e9e2;border-radius:16px;margin:0 0 32px;width:fit-content}
.analytics-tabs button{border:0;background:transparent;color:#526259;font-family:inherit;font-size:.9rem;font-weight:600;line-height:1.2;padding:12px 20px;border-radius:11px;cursor:pointer;transition:background .2s ease,color .2s ease,box-shadow .2s ease,transform .2s ease}
.analytics-tabs button:hover{color:#315a43;transform:translateY(-1px)}
.analytics-tabs button[aria-selected="true"]{background:#fff;color:#315a43;box-shadow:0 2px 8px #213d2710}
.analytics-tabs button:focus-visible{outline:2px solid #557d62;outline-offset:2px}
.analytics-category[hidden]{display:none!important}.analytics-category{margin-bottom:28px}
.analytics-heading{margin-bottom:20px}.analytics-heading h2{font-size:1.35rem;letter-spacing:-.03em;margin:0 0 6px}.analytics-heading p{margin:0;color:#748078;font-size:.9rem}
.analytics-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;background:transparent;border:0;border-radius:0;padding:0;margin:0 0 24px}
.analytics-metrics>div{position:relative;overflow:hidden;padding:20px 22px;border:1px solid #e5ebe6;border-radius:18px;background:linear-gradient(145deg,#fff,#fbfcfa);box-shadow:0 8px 24px #233c2c08;transition:transform .2s ease,box-shadow .2s ease}
.analytics-metrics>div:hover{transform:translateY(-2px);box-shadow:0 12px 28px #233c2c10}
.analytics-metrics>div:after{content:"";position:absolute;inset:0 auto 0 0;width:3px;background:#85a891}
.analytics-metrics dt{font-size:.78rem;font-weight:650;letter-spacing:.025em;color:#68776e}.analytics-metrics dd{font-size:2rem;font-weight:750;line-height:1.1;margin:10px 0 8px;color:#315a43;font-variant-numeric:tabular-nums}.analytics-metrics dd small{display:block;margin-top:8px;color:#78827c;font-size:.78rem;font-weight:500;line-height:1.4}
.analytics-page .analytics-charts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px;align-items:stretch}
.analytics-page .analytics-category .s-panel{margin:0 0 22px;padding:24px 26px;box-shadow:0 8px 26px #233c2c08;border:1px solid #e5ebe6;border-radius:20px;background:linear-gradient(150deg,#fff 0%,#fdfefc 100%)}
.analytics-page .analytics-charts .s-panel{margin:0;display:flex;flex-direction:column}
.analytics-page .analytics-charts .s-panel:last-child:nth-child(odd){grid-column:1/-1}
.analytics-page .analytics-charts .s-panel{transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}
.analytics-page .analytics-charts .s-panel:hover{transform:translateY(-2px);border-color:#d4e1d6;box-shadow:0 14px 32px #233c2c10}
.analytics-page .analytics-charts .s-panel-header{min-height:54px;margin-bottom:10px}
.analytics-page .analytics-charts .s-panel-header h3{font-size:1rem;letter-spacing:-.015em}
.analytics-page .chart-container{height:270px;padding:8px 4px 0;border-radius:12px;background:linear-gradient(180deg,rgba(240,246,241,.62),rgba(255,255,255,0) 48%)}
.analytics-page .chart-container canvas{max-width:100%}
.analytics-chart-caption{margin:7px 0 0;max-width:640px;color:#77857b;font-size:.76rem;line-height:1.55}
.analytics-empty-state{display:flex;min-height:230px;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:24px;text-align:center;border:1px dashed #dbe5dc;border-radius:14px;background:linear-gradient(150deg,#f7faf7,#fff)}
.analytics-empty-state strong{color:#385b42;font-size:.95rem}.analytics-empty-state p{max-width:350px;margin:0;color:#77857b;font-size:.82rem;line-height:1.55}.analytics-empty-state a{margin-top:6px;color:#4e7c61;font-size:.8rem;font-weight:650;text-decoration:underline;text-underline-offset:3px}
.analytics-page #chartLoadStatus:not([hidden]){padding:14px 16px;margin:0 0 20px;border:1px solid #eadab8;border-radius:12px;background:#fff9eb;color:#745b25}
body.dark-mode .analytics-tabs{background:#26312b}body.dark-mode .analytics-tabs button{color:#c5d1c8}body.dark-mode .analytics-tabs button[aria-selected="true"]{background:#3b5042;color:#fff}
body.dark-mode .analytics-metrics>div{background:linear-gradient(145deg,#26312b,#202925);border-color:#3d4a42}body.dark-mode .analytics-metrics dd{color:#c4ddcb}body.dark-mode .analytics-metrics dt,body.dark-mode .analytics-metrics small,body.dark-mode .analytics-heading p{color:#b7c3bb}
body.dark-mode .analytics-category .s-panel{border-color:#3d4a42;background:linear-gradient(150deg,#26312b,#202925)}
.dark-mode.analytics-page{background:radial-gradient(ellipse at 88% 2%,rgba(69,103,77,.17),transparent 34%),#171f1a}
.dark-mode.analytics-page .chart-container{background:linear-gradient(180deg,rgba(91,126,99,.12),transparent 52%)}
.analytics-page .chart-container{padding:10px 8px 4px;background:linear-gradient(180deg,rgba(240,246,241,.72),rgba(255,255,255,.2) 72%);border:1px solid #edf2ee}
.dark-mode.analytics-page .chart-container{border-color:#34443a}
.analytics-page .analytics-charts .s-panel-header .s-label{font-size:.68rem}
.analytics-page .analytics-charts .s-panel-header h3{margin-top:3px}
.analytics-page canvas{outline:0}
.dark-mode.analytics-page .analytics-chart-caption,.dark-mode.analytics-page .analytics-empty-state p{color:#b7c3bb}.dark-mode.analytics-page .analytics-empty-state{background:#202925;border-color:#435448}.dark-mode.analytics-page .analytics-empty-state strong{color:#c4ddcb}.dark-mode.analytics-page .analytics-empty-state a{color:#aad0b4}
.dark-mode.analytics-page #chartLoadStatus:not([hidden]){border-color:#665a3f;background:#302b20;color:#e5d6a9}
.overview-summary-card{background:linear-gradient(150deg,#fff 0%,#fbfcfa 100%);border:1px solid #e5ebe6;border-radius:20px;padding:26px 28px;margin:0 0 24px;color:#26372c;box-shadow:0 8px 26px #233c2c08}
.overview-summary-title{margin:0 0 18px;font-size:1.05rem;font-weight:700;color:#26372c;letter-spacing:-.015em}
.overview-kpi-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 24px}
.overview-schedule{--schedule-line:#dce9df;--schedule-soft:#f1f7f2;--schedule-accent:#32684a;--schedule-muted:#597064}
.analytics-page .analytics-category .overview-schedule{background:linear-gradient(145deg,#f1f7ef 0%,#fff 55%);border-color:var(--schedule-line)}
.overview-schedule-head{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:10px 14px;margin-bottom:16px}
.overview-schedule-icon{display:grid;place-items:center;width:44px;height:44px;border-radius:13px;background:#e1eee2;color:var(--schedule-accent)}
.overview-schedule-icon .app-icon{width:21px;height:21px}
.overview-schedule-head h3{margin:0;font-size:1.06rem}
.overview-schedule-head p{margin:4px 0 0;color:var(--schedule-muted);font-size:.82rem}
.overview-schedule-list{display:grid;gap:8px;margin:10px 0;padding:0;list-style:none}
.overview-schedule-list li{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);align-items:center;gap:12px 20px;padding:13px 15px;background:#fff;border:1px solid var(--schedule-line);border-radius:12px}
.overview-schedule-list li>div{min-width:0}
.overview-schedule-list a{color:inherit;font-size:.93rem;font-weight:750;overflow-wrap:anywhere;text-underline-offset:3px}
.overview-schedule-list a:hover,.overview-schedule-list a:focus-visible{text-decoration:underline;color:var(--schedule-accent)}
.overview-schedule-meta{display:flex;flex-wrap:wrap;align-items:center;gap:6px 9px;margin-top:6px;color:var(--schedule-muted);font-size:.74rem}
.overview-schedule-dates{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));align-items:stretch;gap:22px;margin:0}
.overview-schedule-dates>div{position:relative;padding:7px 9px;border-radius:8px;background:var(--schedule-soft)}
.overview-schedule-dates dt{color:var(--schedule-muted);font-size:.68rem}
.overview-schedule-dates dd{margin:3px 0 0;font-size:.8rem;font-weight:700}
.overview-schedule-dates .overview-schedule-updated dd{color:var(--schedule-accent)}
.overview-schedule-arrow{position:absolute;left:calc(100% + 4px);top:50%;transform:translateY(-50%);color:var(--schedule-accent)}
.overview-schedule-badge{width:fit-content;padding:4px 9px;border-radius:999px;background:var(--schedule-soft);color:var(--schedule-accent);font-size:.72rem;font-weight:750}
.overview-schedule-tip,.overview-schedule-empty{margin:12px 0 0}
body.dark-mode .overview-schedule{--schedule-line:#405546;--schedule-soft:#2d4034;--schedule-accent:#b5e4c4;--schedule-muted:#a8b7ad}
body.dark-mode .analytics-category .overview-schedule{background:linear-gradient(145deg,#293c31,#202925 62%);border-color:var(--schedule-line)}
body.dark-mode .overview-schedule-icon{background:#344e3d}
body.dark-mode .overview-schedule-list li{background:#26342c}
@media(max-width:650px){.overview-schedule-head{grid-template-columns:auto minmax(0,1fr)}.overview-schedule-head>.overview-schedule-badge{grid-column:2}.overview-schedule-list li{grid-template-columns:minmax(0,1fr)}}
.overview-kpi{display:flex;flex-direction:column;gap:6px;padding:14px 16px;border-radius:14px;background:#f7faf7;border:1px solid #ebf1ec}
.overview-kpi-label{font-size:.78rem;font-weight:600;color:#68776e;letter-spacing:.01em}
.overview-kpi-value{font-size:1.75rem;font-weight:750;line-height:1.1;color:#26372c;font-variant-numeric:tabular-nums}
.overview-kpi-value.is-green{color:#168f72}
.overview-kpi-value.is-red{color:#dc4c5d}
.overview-summary-divider{height:1px;background:#e8efe9;margin:22px 0}
.overview-status-title{margin:0 0 18px;font-size:.98rem;font-weight:700;color:#26372c}
.overview-status-body{display:flex;align-items:center;gap:32px}
.overview-donut-box{position:relative;width:142px;height:142px;flex-shrink:0}
.overview-donut-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none}
.overview-donut-total{font-size:1.6rem;font-weight:800;line-height:1.05;color:#26372c;font-variant-numeric:tabular-nums}
.overview-donut-sub{font-size:.72rem;font-weight:600;color:#748078;margin-top:2px}
.overview-status-legend{flex:1;display:flex;flex-direction:column;gap:12px;min-width:0}
.overview-legend-row{display:flex;align-items:center;gap:10px;font-size:.9rem;padding:8px 12px;border-radius:10px;background:#f8fbf9;border:1px solid #edf3ee}
.overview-legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.overview-legend-dot.is-completed{background:#168f72}
.overview-legend-dot.is-overdue{background:#e45768}
.overview-legend-dot.is-pending{background:#e6a323}
.overview-legend-name{color:#3c4d42;font-weight:600;flex:1}
.overview-legend-stat{color:#26372c;font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap}
body.dark-mode .overview-summary-card{background:linear-gradient(150deg,#26312b,#202925);border-color:#3d4a42;color:#e6efe8;box-shadow:0 10px 28px rgba(0,0,0,.2)}
body.dark-mode .overview-summary-title,body.dark-mode .overview-status-title,body.dark-mode .overview-kpi-value,body.dark-mode .overview-donut-total,body.dark-mode .overview-legend-stat{color:#eef5f0}
body.dark-mode .overview-kpi{background:#212c26;border-color:#34433a}
body.dark-mode .overview-kpi-label,body.dark-mode .overview-donut-sub{color:#a8b7ad}
body.dark-mode .overview-kpi-value.is-green{color:#34d399}
body.dark-mode .overview-kpi-value.is-red{color:#f87171}
body.dark-mode .overview-summary-divider{background:#34433a}
body.dark-mode .overview-legend-row{background:#212c26;border-color:#34433a}
body.dark-mode .overview-legend-name{color:#d3e0d6}
.chart-subtitle{margin:4px 0 0;font-size:.82rem;color:#748078;font-weight:500}
.chart-insight{margin-top:auto;padding-top:14px;border-top:1px solid #e8efe9;font-size:.84rem;line-height:1.5;color:#4f5f55}
.chart-insight strong{color:#26372c;font-weight:700}
.analytics-page .analytics-charts .chart-container{margin-bottom:16px}
body.dark-mode .chart-subtitle{color:#a8b7ad}
body.dark-mode .chart-insight{border-top-color:#34433a;color:#c3d1c7}
body.dark-mode .chart-insight strong{color:#eef5f0}
.factors-list{display:flex;flex-direction:column;gap:15px;margin:6px 0 18px}
.factor-row{display:flex;flex-direction:column;gap:7px}
.factor-meta{display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:.88rem}
.factor-name{font-weight:600;color:#26372c}
.factor-count{font-weight:750;color:#26372c;font-variant-numeric:tabular-nums}
.factor-track{width:100%;height:10px;border-radius:999px;background:#eaf1ec;overflow:hidden}
.factor-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#3b6e8c,#4f86a6);transition:width .4s ease}
.factors-empty{margin-top:16px;padding:18px;border:1px dashed #d5e1d7;border-radius:12px;background:#f8fbf9;color:#68776e;font-size:.86rem;line-height:1.5}
.factors-empty a{color:#168f72;font-weight:650;text-decoration:underline;text-underline-offset:3px}
body.dark-mode .factors-card{background:linear-gradient(150deg,#26312b,#202925);border-color:#3d4a42}
body.dark-mode .factor-name,body.dark-mode .factor-count{color:#eef5f0}
body.dark-mode .factor-track{background:#34433a}
body.dark-mode .factors-empty{background:#212c26;border-color:#3d4a42;color:#b7c3bb}
@media(max-width:700px){.overview-summary-card{padding:20px}.overview-kpi-grid{gap:16px 20px}.overview-status-body{flex-direction:column;align-items:flex-start;gap:20px}.overview-status-legend{width:100%}}
@media(max-width:700px){.analytics-page .analytics-charts{grid-template-columns:1fr}.analytics-metrics{grid-template-columns:1fr;padding:0}.analytics-metrics>div{padding:18px 20px;border-right:0;border-bottom:1px solid #e5ebe6}.analytics-metrics dd{font-size:1.65rem}.analytics-tabs{width:100%}.analytics-tabs button{flex:1;padding:10px;font-size:.8rem}.analytics-page .analytics-category .s-panel{padding:18px}}
@media(prefers-reduced-motion:reduce){.analytics-tabs button,.analytics-metrics>div,.analytics-page .analytics-charts .s-panel{transition:none}.analytics-tabs button:hover,.analytics-metrics>div:hover,.analytics-page .analytics-charts .s-panel:hover{transform:none}}
</style>
</head>
<body class="student-body analytics-page">
<?php include 'includes/sidebar.php'; ?>

<main class="s-main">
<div class="s-inner">

<header class="s-head">
<div>
<div class="s-label">Charts &amp; Summaries</div>
<h1>Your productivity</h1>
<p>Choose a category to explore your progress, study time, and habits.</p>
</div>
<div class="s-head-actions">
</div>
</header>
<nav class="analytics-tabs" aria-label="Productivity categories">
<button type="button" data-category="tasks">Tasks</button>
<button type="button" data-category="study">Study &amp; Delays</button>
</nav>
<p id="chartLoadStatus" role="status" hidden>Charts could not load. Your summaries are still available.</p>
<section id="analytics-tasks" class="analytics-category" aria-labelledby="heading-tasks"><div class="analytics-heading"><h2 id="heading-tasks">Task progress</h2><p>All saved tasks. The activity chart covers the last 7 days.</p></div>
<div class="overview-summary-card">
    <h3 class="overview-summary-title">Weekly productivity overview</h3>
    <div class="overview-kpi-grid">
        <div class="overview-kpi">
            <span class="overview-kpi-label">Total tasks</span>
            <span class="overview-kpi-value"><?php echo $totalTasks; ?></span>
        </div>
        <div class="overview-kpi">
            <span class="overview-kpi-label">Completion rate</span>
            <span class="overview-kpi-value is-green"><?php echo $completionRate; ?>%</span>
        </div>
        <div class="overview-kpi">
            <span class="overview-kpi-label">Completed tasks</span>
            <span class="overview-kpi-value"><?php echo $completedTasks; ?></span>
        </div>
        <div class="overview-kpi">
            <span class="overview-kpi-label">Overdue tasks</span>
            <span class="overview-kpi-value is-red"><?php echo $overdueCount; ?></span>
        </div>
    </div>
    <div class="overview-summary-divider"></div>
    <h4 class="overview-status-title">Task completion status</h4>
    <div class="overview-status-body">
        <div class="overview-donut-box">
            <canvas id="statusChart" role="img" aria-label="Doughnut chart showing completed, overdue, and pending task counts"></canvas>
            <div class="overview-donut-center">
                <span class="overview-donut-total"><?php echo $totalTasks; ?></span>
                <span class="overview-donut-sub">Tasks</span>
            </div>
        </div>
        <div class="overview-status-legend">
            <div class="overview-legend-row">
                <span class="overview-legend-dot is-completed"></span>
                <span class="overview-legend-name">Completed</span>
                <span class="overview-legend-stat"><?php echo $completedTasks; ?> (<?php echo $completedPct; ?>%)</span>
            </div>
            <div class="overview-legend-row">
                <span class="overview-legend-dot is-overdue"></span>
                <span class="overview-legend-name">Overdue</span>
                <span class="overview-legend-stat"><?php echo $overdueCount; ?> (<?php echo $overduePct; ?>%)</span>
            </div>
            <div class="overview-legend-row">
                <span class="overview-legend-dot is-pending"></span>
                <span class="overview-legend-name">Pending</span>
                <span class="overview-legend-stat"><?php echo $activePendingCount; ?> (<?php echo $pendingPct; ?>%)</span>
            </div>
        </div>
    </div>
</div>
<div class="analytics-charts"><div class="s-panel">
<div class="s-panel-header">
<div>
<div class="s-label">Weekly Productivity Trend</div>
<h3>Daily task completion</h3>
<p class="chart-subtitle">Number of tasks completed per day</p>
</div>
</div>
<div class="chart-container">
<canvas id="weeklyChart" role="img" aria-label="Line chart showing tasks completed each day over the last seven days"></canvas>
</div>
<p class="chart-insight"><strong>System insight:</strong> <?php echo htmlspecialchars($weeklyInsight, ENT_QUOTES, 'UTF-8'); ?></p>
</div><div class="s-panel">
<div class="s-panel-header">
<div>
<div class="s-label">Procrastination Pattern Analysis</div>
<h3>Tasks completed after the deadline</h3>
<p class="chart-subtitle">Late completions over the last 4 weeks</p>
</div>
</div>
<div class="chart-container">
<canvas id="lateCompletionChart" role="img" aria-label="Bar chart showing tasks completed after their deadline across the last four weeks"></canvas>
</div>
<p class="chart-insight"><strong>System insight:</strong> <?php echo htmlspecialchars($lateInsight, ENT_QUOTES, 'UTF-8'); ?></p>
</div><div class="s-panel">
<div class="s-panel-header">
<div>
<div class="s-label">Task Performance Based On Priority</div>
<h3>Task completion by priority</h3>
<p class="chart-subtitle">Completed vs unfinished tasks across priority levels</p>
</div>
</div>
<div class="chart-container">
<canvas id="priorityChart" role="img" aria-label="Grouped bar chart showing completed and unfinished task counts across High, Medium, and Low priorities"></canvas>
</div>
<p class="chart-insight"><strong>System insight:</strong> <?php echo htmlspecialchars($priorityInsight, ENT_QUOTES, 'UTF-8'); ?></p>
</div><div class="s-panel">
<div class="s-panel-header">
<div>
<div class="s-label">Common Reasons For Procrastination</div>
<h3>Reported procrastination factors</h3>
<p class="chart-subtitle">Based on responses from the last four weekly check-ins</p>
</div>
</div>
<?php if (!empty($factorCounts)): ?>
<div class="factors-list">
<?php foreach ($factorCounts as $factorName => $factorCount):
    $barPct = $maxFactorCount > 0 ? round(($factorCount / $maxFactorCount) * 100) : 0;
?>
<div class="factor-row">
    <div class="factor-meta">
        <span class="factor-name"><?php echo htmlspecialchars($factorName, ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="factor-count"><?php echo $factorCount; ?></span>
    </div>
    <div class="factor-track">
        <div class="factor-fill" style="width:<?php echo $barPct; ?>%"></div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="factors-empty">No weekly check-in reasons recorded yet. <a href="checkin.php#weeklyCheckin">Complete a weekly check-in</a> to see your most common procrastination factors.</div>
<?php endif; ?>
<p class="chart-insight"><strong>System insight:</strong> <?php echo htmlspecialchars($factorInsight, ENT_QUOTES, 'UTF-8'); ?></p>
</div></div>
<section class="s-panel overview-schedule" aria-labelledby="overview-schedule-title">
    <header class="overview-schedule-head">
        <span class="overview-schedule-icon" aria-hidden="true"><i class="app-icon" data-lucide="calendar-clock"></i></span>
        <div>
            <h3 id="overview-schedule-title">Deadline changes</h3>
            <p>Review the latest deadline change for each rescheduled task.</p>
        </div>
        <span class="overview-schedule-badge"><?php echo count($rescheduledTasks); ?> <?php echo count($rescheduledTasks) === 1 ? 'task' : 'tasks'; ?> · <?php echo $scheduleChangeCount; ?> <?php echo $scheduleChangeCount === 1 ? 'change' : 'changes'; ?></span>
    </header>
    <?php if ($rescheduledTasks): ?>
        <ul class="overview-schedule-list">
            <?php foreach ($rescheduledTasks as $rescheduled):
                $oldDate = $rescheduled['old_deadline'];
                $newDate = $rescheduled['new_deadline'];
                $changeLabel = match (true) {
                    $oldDate === null => 'Deadline added',
                    $newDate === null => 'Deadline removed',
                    $newDate > $oldDate => 'Extended',
                    default => 'Moved earlier',
                };
                $changeCount = (int)$rescheduled['change_count'];
            ?>
                <li>
                    <div>
                        <a href="view_task.php?id=<?php echo (int)$rescheduled['task_id']; ?>"><?php echo htmlspecialchars($rescheduled['title'], ENT_QUOTES, 'UTF-8'); ?></a>
                        <div class="overview-schedule-meta">
                            <span class="overview-schedule-badge"><?php echo $changeLabel; ?></span>
                            <span><?php echo $changeCount; ?> <?php echo $changeCount === 1 ? 'change' : 'changes'; ?></span>
                        </div>
                    </div>
                    <dl class="overview-schedule-dates">
                        <div><dt>Previous due date</dt><dd><?php echo app_task_deadline_display($oldDate, null); ?><span class="overview-schedule-arrow" aria-hidden="true">→</span></dd></div>
                        <div class="overview-schedule-updated"><dt>Updated due date</dt><dd><?php echo app_task_deadline_display($newDate, null); ?></dd></div>
                    </dl>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="overview-kpi-label overview-schedule-tip">If a deadline keeps moving, plan one small step before the new due date.</p>
    <?php else: ?>
        <p class="overview-kpi-label overview-schedule-empty">No saved deadline date changes yet.</p>
    <?php endif; ?>
</section>
</section><section id="analytics-study" class="analytics-category" aria-labelledby="heading-study"><div class="analytics-heading"><h2 id="heading-study">Study &amp; delays</h2><p>All-time totals below. Charts show the last 7 days.</p></div><dl class="analytics-metrics"><div><dt>Study minutes</dt><dd><?php echo (int)$totalStudyMin['total']; ?><small>Total time logged</small></dd></div><div><dt>Study sessions</dt><dd><?php echo $sessionCount; ?><small>Total sessions recorded</small></dd></div><div><dt>Delay minutes</dt><dd><?php echo (int)$totalDelayMin['total']; ?><small>Total time reported</small></dd></div></dl><div class="analytics-charts"><div class="s-panel">
<div class="s-panel-header">
<div>
<div class="s-label">Last 7 Days</div>
<h3>Study time (minutes)</h3>
</div>
</div>
<div class="chart-container">
<canvas id="studyChart"></canvas>
</div>
</div><div class="s-panel">
<div class="s-panel-header">
<div>
<div class="s-label">Delay vs Study</div>
<h3>Last 7 days (minutes)</h3>
</div>
</div>
<div class="chart-container">
<canvas id="delayStudyChart"></canvas>
</div>
</div><div class="s-panel">
<div class="s-panel-header">
<div>
<div class="s-label">Study Sessions</div>
<h3>Daily logs and study sessions</h3>
</div>
</div>
<div class="chart-container">
<canvas id="behaviorChart"></canvas>
</div>
</div></div></section>
</div>
</main>

<script>
(() => {
const tabBar = document.querySelector('.analytics-tabs');
const tabs = [...tabBar.querySelectorAll('button')];
const categories = [...document.querySelectorAll('.analytics-category')];
tabBar.setAttribute('role', 'tablist');
function selectCategory(name, focus = false) {
    tabs.forEach(tab => {
        const active = tab.dataset.category === name;
        tab.setAttribute('aria-selected', String(active));
        tab.tabIndex = active ? 0 : -1;
        if (active && focus) tab.focus();
    });
    categories.forEach(panel => panel.hidden = panel.id !== 'analytics-' + name);
    if (typeof Chart !== 'undefined') requestAnimationFrame(() => {
        document.querySelectorAll('#analytics-' + name + ' canvas').forEach(canvas => { const chart = Chart.getChart(canvas); if (chart) chart.resize(); });
    });
}
tabs.forEach((tab, index) => {
    tab.id = 'tab-' + tab.dataset.category;
    tab.setAttribute('role', 'tab');
    tab.setAttribute('aria-controls', 'analytics-' + tab.dataset.category);
    const panel = document.getElementById('analytics-' + tab.dataset.category);
    panel.setAttribute('role', 'tabpanel'); panel.setAttribute('aria-labelledby', tab.id);
    tab.addEventListener('click', () => selectCategory(tab.dataset.category));
    tab.addEventListener('keydown', event => {
        let next;
        if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
        if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = tabs.length - 1;
        if (next !== undefined) { event.preventDefault(); selectCategory(tabs[next].dataset.category, true); }
    });
});
selectCategory('tasks');
if (typeof Chart === 'undefined') { document.getElementById('chartLoadStatus').hidden = false; return; }
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.color = '#718078';
Chart.defaults.font.size = 11;
Chart.defaults.scale.grid.color = 'rgba(116, 132, 121, 0.14)';
Chart.defaults.scale.border.color = 'rgba(116, 132, 121, 0.2)';
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.boxWidth = 9;
Chart.defaults.plugins.legend.labels.boxHeight = 9;
Chart.defaults.plugins.legend.labels.padding = 18;
Chart.defaults.plugins.tooltip.backgroundColor = '#1f2e25';
Chart.defaults.plugins.tooltip.titleFont = { weight: '700' };
Chart.defaults.plugins.tooltip.bodyFont = { weight: '500' };
Chart.defaults.plugins.tooltip.padding = 11;
Chart.defaults.plugins.tooltip.cornerRadius = 9;

const centerLabelPlugin = {
    id: 'centerLabel',
    afterDraw(chart, args, options) {
        if (!options || options.value === undefined || options.label === undefined || !chart.chartArea) return;
        const { ctx, chartArea } = chart;
        const centerX = (chartArea.left + chartArea.right) / 2;
        const centerY = (chartArea.top + chartArea.bottom) / 2;
        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = document.body.classList.contains('dark-mode') ? '#f0f5f1' : '#26372c';
        ctx.font = "700 22px Inter, sans-serif";
        ctx.fillText(String(options.value), centerX, centerY - 7);
        ctx.fillStyle = document.body.classList.contains('dark-mode') ? '#aab9ae' : '#78867d';
        ctx.font = "600 9px Inter, sans-serif";
        ctx.fillText(options.label, centerX, centerY + 13);
        ctx.restore();
    }
};
Chart.register(centerLabelPlugin);

function updateChartTheme() {
    const darkMode = document.body.classList.contains('dark-mode');
    const textColor = darkMode ? '#c1cdc4' : '#718078';
    const gridColor = darkMode ? 'rgba(190, 208, 194, 0.12)' : 'rgba(116, 132, 121, 0.14)';
    const borderColor = darkMode ? 'rgba(190, 208, 194, 0.2)' : 'rgba(116, 132, 121, 0.2)';
    Chart.defaults.color = textColor;
    Chart.defaults.scale.grid.color = gridColor;
    Chart.defaults.scale.border.color = borderColor;
    Chart.defaults.plugins.tooltip.backgroundColor = darkMode ? '#101713' : '#1f2e25';
    document.querySelectorAll('.analytics-page canvas').forEach(canvas => {
        const chart = Chart.getChart(canvas);
        if (!chart) return;
        chart.options.color = textColor;
        Object.values(chart.options.scales || {}).forEach(scale => {
            scale.ticks.color = textColor;
            if (scale.grid && scale.grid.display !== false) scale.grid.color = gridColor;
            if (scale.border) scale.border.color = borderColor;
        });
        if (chart.options.plugins.legend) chart.options.plugins.legend.labels.color = textColor;
        chart.options.plugins.tooltip.backgroundColor = darkMode ? '#101713' : '#1f2e25';
        if (chart.config.type === 'doughnut') {
            chart.data.datasets.forEach(dataset => {
                dataset.borderColor = darkMode ? '#26312b' : '#fff';
            });
        }
        chart.update('none');
    });
}
new MutationObserver(updateChartTheme).observe(document.body, { attributes: true, attributeFilter: ['class'] });

// ===== WEEKLY TASK CHART =====
new Chart(document.getElementById('weeklyChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($weekShortDays); ?>,
        datasets: [
            {
                label: 'Completed tasks',
                data: <?php echo json_encode($weeklyCompleted); ?>,
                borderColor: '#168f72',
                backgroundColor: 'rgba(22, 143, 114, 0.12)',
                borderWidth: 2.5,
                tension: 0.15,
                fill: true,
                pointRadius: 4.5,
                pointHoverRadius: 6.5,
                pointBackgroundColor: '#168f72',
                pointBorderColor: '#fff',
                pointBorderWidth: 2
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
            x: {
                grid: { display: false },
                border: { display: false }
            },
            y: {
                beginAtZero: true,
                suggestedMax: 6,
                border: { display: false, dash: [4, 4] },
                grid: { tickLength: 0 },
                ticks: { precision: 0, stepSize: 2 }
            }
        },
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: context => 'Completed tasks: ' + context.parsed.y
                }
            }
        }
    }
});

// ===== LATE COMPLETION CHART =====
new Chart(document.getElementById('lateCompletionChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($lateWeeksLabels); ?>,
        datasets: [
            {
                label: 'Late completions',
                data: <?php echo json_encode($lateWeeksCounts); ?>,
                backgroundColor: 'rgba(59, 110, 140, 0.85)',
                hoverBackgroundColor: '#2c5670',
                borderRadius: { topLeft: 8, topRight: 8, bottomLeft: 0, bottomRight: 0 },
                borderSkipped: 'bottom',
                maxBarThickness: 72
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
            x: {
                grid: { display: false },
                border: { display: false }
            },
            y: {
                beginAtZero: true,
                suggestedMax: 8,
                border: { display: false, dash: [4, 4] },
                grid: { tickLength: 0 },
                ticks: { precision: 0, stepSize: 2 }
            }
        },
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: context => 'Late completions: ' + context.parsed.y
                }
            }
        }
    }
});

// ===== PRIORITY CHART =====
new Chart(document.getElementById('priorityChart'), {
    type: 'bar',
    data: {
        labels: ['High', 'Medium', 'Low'],
        datasets: [
            {
                label: 'Completed',
                data: <?php echo json_encode(array_values($priorityCompleted)); ?>,
                backgroundColor: 'rgba(22, 143, 114, 0.88)',
                hoverBackgroundColor: '#0f765d',
                borderRadius: { topLeft: 8, topRight: 8, bottomLeft: 0, bottomRight: 0 },
                borderSkipped: 'bottom',
                barPercentage: 0.78,
                categoryPercentage: 0.65
            },
            {
                label: 'Unfinished',
                data: <?php echo json_encode(array_values($priorityUnfinished)); ?>,
                backgroundColor: 'rgba(230, 163, 35, 0.88)',
                hoverBackgroundColor: '#cb8612',
                borderRadius: { topLeft: 8, topRight: 8, bottomLeft: 0, bottomRight: 0 },
                borderSkipped: 'bottom',
                barPercentage: 0.78,
                categoryPercentage: 0.65
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
            x: {
                grid: { display: false },
                border: { display: false }
            },
            y: {
                beginAtZero: true,
                suggestedMax: 8,
                border: { display: false, dash: [4, 4] },
                grid: { tickLength: 0 },
                ticks: { precision: 0, stepSize: 2 }
            }
        },
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});

// ===== STUDY CHART =====
new Chart(document.getElementById('studyChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($weekDays); ?>,
        datasets: [{
            label: 'Study Minutes',
            data: <?php echo json_encode($studyWeekly); ?>,
            backgroundColor: 'rgba(14, 165, 233, 0.78)',
            borderColor: '#0284c7',
            borderWidth: 1,
            borderRadius: 7,
            hoverBackgroundColor: '#0369a1'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { x: { grid: { display: false } }, y: { beginAtZero: true } },
        plugins: { legend: { display: false } }
    }
});

// ===== BEHAVIOR CHART =====
new Chart(document.getElementById('behaviorChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($weekDays); ?>,
        datasets: [
            {
                label: 'Delays',
                data: <?php echo json_encode($delayWeekly); ?>,
                borderColor: '#d97706',
                backgroundColor: 'rgba(217,119,6,0.12)',
                tension: 0.4,
                fill: true,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointBackgroundColor: '#fff',
                pointBorderWidth: 2
            },
            {
                label: 'Sessions',
                data: <?php echo json_encode($sessionWeekly); ?>,
                borderColor: '#0284c7',
                backgroundColor: 'rgba(2,132,199,0.10)',
                tension: 0.4,
                fill: true,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointBackgroundColor: '#fff',
                pointBorderWidth: 2
            },
            {
                label: 'Check-ins',
                data: <?php echo json_encode($checkinWeekly); ?>,
                borderColor: '#7c3aed',
                backgroundColor: 'rgba(124,58,237,0.08)',
                tension: 0.4,
                fill: true,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointBackgroundColor: '#fff',
                pointBorderWidth: 2
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0, stepSize: 1 } } },
        plugins: { legend: { position: 'top' } }
    }
});

// ===== DELAY VS STUDY CHART =====
new Chart(document.getElementById('delayStudyChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($weekDays); ?>,
        datasets: [
            {
                label: 'Delay minutes',
                data: <?php echo json_encode($delayMinutesWeekly); ?>,
                borderColor: '#e45768',
                backgroundColor: 'rgba(228,87,104,0.12)',
                tension: 0.4,
                fill: true,
                pointRadius: 4,
                pointHoverRadius: 6
            },
            {
                label: 'Study minutes',
                data: <?php echo json_encode($studyWeekly); ?>,
                borderColor: '#168f72',
                backgroundColor: 'rgba(22,143,114,0.12)',
                tension: 0.4,
                fill: true,
                pointRadius: 4,
                pointHoverRadius: 6
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 } } },
        plugins: { legend: { position: 'top' } }
    }
});

// ===== STATUS CHART =====
const statusCounts = [<?php echo $completedTasks; ?>, <?php echo $overdueCount; ?>, <?php echo $activePendingCount; ?>];
const hasStatusData = statusCounts.some(v => v > 0);
const isDark = document.body.classList.contains('dark-mode');
const emptyRingColor = isDark ? '#34433a' : '#e6ede7';
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: ['Completed', 'Overdue', 'Pending'],
        datasets: [{
            data: hasStatusData ? statusCounts : [0, 0, 1],
            backgroundColor: hasStatusData ? ['#168f72', '#e45768', '#e6a323'] : [emptyRingColor, emptyRingColor, emptyRingColor],
            hoverBackgroundColor: hasStatusData ? ['#0f765d', '#cc3c50', '#cb8612'] : [emptyRingColor, emptyRingColor, emptyRingColor],
            borderWidth: 0,
            hoverOffset: hasStatusData ? 4 : 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
            legend: { display: false },
            tooltip: {
                enabled: hasStatusData,
                callbacks: {
                    label: context => {
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const pct = total > 0 ? Math.round((context.raw / total) * 100) : 0;
                        return context.label + ': ' + context.raw + ' (' + pct + '%)';
                    }
                }
            }
        }
    }
});
updateChartTheme();
})();
</script>
</body>
</html>
