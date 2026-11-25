<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

include 'config/database.php';
require_once __DIR__ . '/lib/weekly_report.php';
require_once __DIR__ . '/lib/procrastination_classifier.php';

$user_id = intval($_SESSION['user_id']);

// Determine which week to display
$weekOffset = filter_var($_GET['week'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 520]]);
$weekOffset = $weekOffset === false ? 0 : $weekOffset;
$weekStart = (new DateTimeImmutable('monday this week', new DateTimeZone('Asia/Manila')))->modify("-$weekOffset weeks")->format('Y-m-d');
$weekEnd = date('Y-m-d', strtotime("$weekStart +6 days"));

// Generate the report (will persist to DB)
$reporter = new WeeklyReport($conn, $user_id);
$report = $reporter->generate($weekStart, true);

// Also get the tendency for cross-reference
$tendency = ProcrastinationClassifier::classifyUser($conn, $user_id);

// Get report history for navigation
$history = WeeklyReport::getReportHistory($conn, $user_id, 12);

$d = $report['daily'];
$hasDailyChartActivity = array_sum($d['dailyTasksDue']) + array_sum($d['dailyCompleted']) + array_sum($d['dailyCheckins']) + array_sum($d['dailyDelays']) + array_sum($d['dailyStudyMin']) > 0;
$hasFocusEnergyRatings = count(array_filter($d['dailyFocus'], function ($rating) { return $rating !== null; }))
    + count(array_filter($d['dailyEnergy'], function ($rating) { return $rating !== null; })) > 0;
$weekLabel = date('M d', strtotime($weekStart)) . ' – ' . date('M d', strtotime($weekEnd));

// ===== WEEKLY PRODUCTIVITY REPORT CARD METRICS =====
$weekStartDt = new DateTimeImmutable($weekStart, new DateTimeZone('Asia/Manila'));
$weekEndDt = new DateTimeImmutable($weekEnd, new DateTimeZone('Asia/Manila'));
$lastWeekStartDt = $weekStartDt->modify('-7 days');
$lastWeekEndDt = $weekStartDt->modify('-1 day');
$lastWeekStartStr = $lastWeekStartDt->format('Y-m-d');
$lastWeekEndStr = $lastWeekEndDt->format('Y-m-d');
$reportWeekNumber = (int)$weekStartDt->format('W');

if ($weekStartDt->format('M') === $weekEndDt->format('M')) {
    $reportDateRange = $weekStartDt->format('F j') . '–' . $weekEndDt->format('j, Y');
} else {
    $reportDateRange = $weekStartDt->format('F j') . ' – ' . $weekEndDt->format('F j, Y');
}

$thisWeekScheduled = (int)mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COUNT(*) AS c FROM tasks WHERE user_id=$user_id AND ((deadline BETWEEN '$weekStart' AND '$weekEnd') OR (deadline IS NULL AND DATE(created_at) BETWEEN '$weekStart' AND '$weekEnd'))"
))['c'];
$lastWeekScheduled = (int)mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COUNT(*) AS c FROM tasks WHERE user_id=$user_id AND ((deadline BETWEEN '$lastWeekStartStr' AND '$lastWeekEndStr') OR (deadline IS NULL AND DATE(created_at) BETWEEN '$lastWeekStartStr' AND '$lastWeekEndStr'))"
))['c'];

$thisWeekCompleted = (int)mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COUNT(*) AS c FROM tasks WHERE user_id=$user_id AND status='Completed' AND DATE(completed_at) BETWEEN '$weekStart' AND '$weekEnd'"
))['c'];
$lastWeekCompleted = (int)mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COUNT(*) AS c FROM tasks WHERE user_id=$user_id AND status='Completed' AND DATE(completed_at) BETWEEN '$lastWeekStartStr' AND '$lastWeekEndStr'"
))['c'];

$thisWeekOverdue = (int)mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COUNT(*) AS c FROM tasks WHERE user_id=$user_id AND status!='Completed' AND deadline BETWEEN '$weekStart' AND '$weekEnd' AND deadline < CURDATE()"
))['c'];
$lastWeekOverdue = (int)mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COUNT(*) AS c FROM tasks WHERE user_id=$user_id AND status!='Completed' AND deadline BETWEEN '$lastWeekStartStr' AND '$lastWeekEndStr'"
))['c'];

$reportTotalTasks = (int)$report['tasks']['total'];
$reportCompletedTasks = (int)$report['tasks']['completed'];
$reportOverdueTasks = (int)$report['tasks']['overdue'];
$reportPendingTasks = (int)$report['tasks']['pending'];
$reportRate = (int)$report['tasks']['rate'];

if ($reportTotalTasks === 0 && $thisWeekScheduled > 0) {
    $reportTotalTasks = $thisWeekScheduled;
    $reportCompletedTasks = $thisWeekCompleted;
    $reportOverdueTasks = $thisWeekOverdue;
    $reportPendingTasks = max(0, $reportTotalTasks - $reportCompletedTasks - $reportOverdueTasks);
    $reportRate = $reportTotalTasks > 0 ? round(($reportCompletedTasks / $reportTotalTasks) * 100) : 0;
}

$lastWeekRate = $lastWeekScheduled > 0 ? round(($lastWeekCompleted / $lastWeekScheduled) * 100) : 0;

$completedDelta = $reportCompletedTasks - $lastWeekCompleted;
if ($lastWeekScheduled === 0 && $lastWeekCompleted === 0) {
    $completedDeltaText = 'No prior week data';
} elseif ($completedDelta > 0) {
    $completedDeltaText = "+{$completedDelta} from last week";
} elseif ($completedDelta < 0) {
    $completedDeltaText = "{$completedDelta} from last week";
} else {
    $completedDeltaText = 'Same as last week';
}

$overdueDelta = $reportOverdueTasks - $lastWeekOverdue;
if ($lastWeekScheduled === 0 && $lastWeekOverdue === 0) {
    $overdueDeltaText = $reportOverdueTasks === 0 ? 'All on track' : 'Needs attention';
} elseif ($overdueDelta < 0) {
    $overdueDeltaText = "{$overdueDelta} from last week";
} elseif ($overdueDelta > 0) {
    $overdueDeltaText = "+{$overdueDelta} from last week";
} else {
    $overdueDeltaText = 'Same as last week';
}

$rateDelta = $reportRate - $lastWeekRate;
if ($lastWeekScheduled === 0) {
    $rateDeltaText = $weekOffset === 0 ? 'Based on tasks scheduled this week' : 'Based on tasks scheduled in this period';
} elseif ($rateDelta > 0) {
    $rateDeltaText = "{$rateDelta} percentage points higher than last week";
} elseif ($rateDelta < 0) {
    $rateDeltaText = abs($rateDelta) . ' percentage points lower than last week';
} else {
    $rateDeltaText = 'Same completion rate as last week';
}

// Daily comparison (Last week vs This week, Mon-Sun)
$compLabels = [];
$compFullDays = [];
$compThisWeek = [];
$compLastWeek = [];
for ($i = 0; $i < 7; $i++) {
    $thisDay = date('Y-m-d', strtotime("$weekStart +$i days"));
    $lastDay = date('Y-m-d', strtotime("$lastWeekStartStr +$i days"));
    $compLabels[] = date('D', strtotime($thisDay));
    $compFullDays[] = date('l', strtotime($thisDay));
    $compThisWeek[] = (int)mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT COUNT(*) AS c FROM tasks WHERE user_id=$user_id AND status='Completed' AND DATE(completed_at)='$thisDay'"
    ))['c'];
    $compLastWeek[] = (int)mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT COUNT(*) AS c FROM tasks WHERE user_id=$user_id AND status='Completed' AND DATE(completed_at)='$lastDay'"
    ))['c'];
}

// Behavioral findings
$totalCompThisWeek = array_sum($compThisWeek);
if ($totalCompThisWeek > 0) {
    $maxCompVal = max($compThisWeek);
    $minCompVal = min($compThisWeek);
    $mostProductiveDay = $compFullDays[array_search($maxCompVal, $compThisWeek, true)];
    $lowestCompletionDay = $compFullDays[array_search($minCompVal, $compThisWeek, true)];
} else {
    $mostProductiveDay = 'No completed tasks yet';
    $lowestCompletionDay = '—';
}

$mainDistraction = 'None reported yet';
$ciDistractionRes = mysqli_query(
    $conn,
    "SELECT answers FROM checkin_reflections WHERE user_id=$user_id AND (checkin_type='Weekly' OR answers LIKE '%\"checkin_type\":\"Weekly\"%' OR answers LIKE '%weekly_distraction%' OR answers LIKE '%weekly_reason%') ORDER BY created_at DESC LIMIT 4"
);
if ($ciDistractionRes) {
    $distCounts = [];
    while ($ciRow = mysqli_fetch_assoc($ciDistractionRes)) {
        $ans = json_decode((string)($ciRow['answers'] ?? ''), true);
        if (!is_array($ans)) continue;
        $candidates = [
            $ans['weekly_distraction'] ?? null,
            $ans['responses']['weekly_distraction']['answer'] ?? null,
            $ans['weekly_reason'] ?? null,
            $ans['responses']['weekly_reason']['answer'] ?? null,
            $ans['blocker'] ?? null,
        ];
        foreach ($candidates as $cand) {
            $val = trim((string)$cand);
            if ($val !== '' && strcasecmp($val, 'I did not procrastinate') !== 0 && strcasecmp($val, 'None') !== 0) {
                $distCounts[$val] = ($distCounts[$val] ?? 0) + 1;
                break;
            }
        }
    }
    if (!empty($distCounts)) {
        arsort($distCounts);
        $mainDistraction = (string)array_key_first($distCounts);
    }
}
if ($mainDistraction === 'None reported yet' && !empty($report['delays']['top_reasons'][0]['reason'])) {
    $mainDistraction = (string)$report['delays']['top_reasons'][0]['reason'];
}

$neglectedPriority = 'None';
$negRes = mysqli_query(
    $conn,
    "SELECT priority, COUNT(*) AS c FROM tasks WHERE user_id=$user_id AND status!='Completed' GROUP BY priority ORDER BY c DESC, FIELD(priority,'High','Medium','Low') ASC LIMIT 1"
);
if ($negRes && ($negRow = mysqli_fetch_assoc($negRes)) && (int)$negRow['c'] > 0) {
    $neglectedPriority = $negRow['priority'] . '-priority tasks';
}

$postponementsCount = (int)($report['behavior_trends']['deadline_changes']['changes'] ?? 0) + (int)($report['delays']['count'] ?? 0);
$postponementsText = $postponementsCount . ' ' . ($postponementsCount === 1 ? 'time' : 'times');

// Weekly summary text
if ($reportTotalTasks === 0) {
    $weeklySummaryText = 'No scheduled tasks were recorded for this week yet. Add tasks and complete your weekly check-in to generate your productivity summary.';
} else {
    if ($lastWeekScheduled === 0 && $lastWeekCompleted === 0) {
        $leadSentence = 'You started tracking your weekly productivity.';
    } elseif ($rateDelta > 0 || $completedDelta > 0) {
        $leadSentence = 'Your productivity improved compared to last week.';
    } elseif ($rateDelta < 0 || $completedDelta < 0) {
        $leadSentence = 'Your productivity dipped slightly compared to last week.';
    } else {
        $leadSentence = 'Your productivity remained steady compared to last week.';
    }
    $progressSentence = "You completed {$reportCompletedTasks} out of {$reportTotalTasks} scheduled tasks.";
    if ($neglectedPriority !== 'None' && $mainDistraction !== 'None reported yet') {
        $challengeSentence = 'However, you still experienced difficulties completing ' . strtolower($neglectedPriority) . ' and managing ' . strtolower($mainDistraction) . '.';
    } elseif ($neglectedPriority !== 'None') {
        $challengeSentence = 'Focus on clearing your remaining ' . strtolower($neglectedPriority) . ' to stay ahead of deadlines.';
    } elseif ($mainDistraction !== 'None reported yet') {
        $challengeSentence = 'Managing ' . strtolower($mainDistraction) . ' will help you maintain strong momentum.';
    } else {
        $challengeSentence = 'Great job staying consistent and keeping your workload under control.';
    }
    $weeklySummaryText = "{$leadSentence} {$progressSentence} {$challengeSentence}";
}

// Personalized recommendations
$weeklyRecommendations = [];
if ($neglectedPriority === 'High-priority tasks') {
    $weeklyRecommendations[] = 'Start working on high-priority tasks earlier in the week.';
} else {
    $weeklyRecommendations[] = 'Keep prioritizing your most important tasks at the start of each week.';
}
if ($mainDistraction !== 'None reported yet') {
    $distLower = strtolower($mainDistraction);
    if (str_contains($distLower, 'social') || str_contains($distLower, 'game') || str_contains($distLower, 'digital') || str_contains($distLower, 'phone')) {
        $weeklyRecommendations[] = "Limit {$distLower} usage during study sessions.";
    } else {
        $weeklyRecommendations[] = "Address {$distLower} by scheduling focused, distraction-free study sessions.";
    }
} else {
    $weeklyRecommendations[] = 'Complete your weekly check-in to identify what distracts you most.';
}
$weeklyRecommendations[] = 'Break difficult assignments into smaller tasks.';
if ($reportRate < 80) {
    $weeklyRecommendations[] = 'Aim to complete at least 80% of your scheduled tasks next week.';
} else {
    $weeklyRecommendations[] = "Keep maintaining your {$reportRate}%+ completion rate next week.";
}

// Week-to-week progress table & 4-week completion rate trend
$thisWeekHighCompleted = (int)mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COUNT(*) AS c FROM tasks WHERE user_id=$user_id AND status='Completed' AND priority='High' AND DATE(completed_at) BETWEEN '$weekStart' AND '$weekEnd'"
))['c'];
$lastWeekHighCompleted = (int)mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COUNT(*) AS c FROM tasks WHERE user_id=$user_id AND status='Completed' AND priority='High' AND DATE(completed_at) BETWEEN '$lastWeekStartStr' AND '$lastWeekEndStr'"
))['c'];
$lastWeekPostponements = (int)mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COUNT(*) AS c FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='delay' AND DATE(created_at) BETWEEN '$lastWeekStartStr' AND '$lastWeekEndStr'"
))['c'];

$fourWeekLabels = ['Week 1', 'Week 2', 'Week 3', 'Week 4'];
$fourWeekRates = [];
for ($w = 3; $w >= 0; $w--) {
    $wStartS = $weekStartDt->modify('-' . ($w * 7) . ' days')->format('Y-m-d');
    $wEndS = $weekStartDt->modify('-' . ($w * 7 - 6) . ' days')->format('Y-m-d');
    $wSched = (int)mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT COUNT(*) AS c FROM tasks WHERE user_id=$user_id AND ((deadline BETWEEN '$wStartS' AND '$wEndS') OR (deadline IS NULL AND DATE(created_at) BETWEEN '$wStartS' AND '$wEndS'))"
    ))['c'];
    $wComp = (int)mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT COUNT(*) AS c FROM tasks WHERE user_id=$user_id AND status='Completed' AND DATE(completed_at) BETWEEN '$wStartS' AND '$wEndS'"
    ))['c'];
    $fourWeekRates[] = $wSched > 0 ? min(100, (int)round(($wComp / $wSched) * 100)) : 0;
}

function w2w_trend_badge(int $thisVal, int $lastVal, bool $lowerIsBetter = false): string {
    if ($thisVal === $lastVal) {
        return '<span class="w2w-trend is-neutral" title="Same as last week">—</span>';
    }
    $improved = $lowerIsBetter ? ($thisVal < $lastVal) : ($thisVal > $lastVal);
    if ($improved) {
        return '<span class="w2w-trend is-up" aria-label="Improved">↗</span>';
    }
    return '<span class="w2w-trend is-down" aria-label="Needs attention">↘</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Weekly Reports — ProcrastiTrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/student.css">
<link rel="stylesheet" href="assets/css/tendency.css">
<link rel="stylesheet" href="assets/css/reports.css?v=20261124-3">
<link rel="stylesheet" href="assets/css/insights-tabs.css?v=20261124">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
/* ── Report page: compact bento layout ─────────────────────────── */
:root{
  --rpt-radius-lg:16px;
  --rpt-radius-md:12px;
  --rpt-radius-sm:9px;
  --rpt-gap:14px;
  --rpt-pad-card:18px 20px;
  --rpt-pad-hero:18px 22px;
  --rpt-border:#e3ebe5;
  --rpt-bg-card:#ffffff;
  --rpt-bg-card2:#f9fcf9;
  --rpt-shadow:0 4px 18px rgba(30,52,36,.05);
  --rpt-green:#168f72;
  --rpt-green-lt:#eaf4ed;
  --rpt-ink:#1f3326;
  --rpt-muted:#68776e;
  --rpt-eyebrow:#5a8065;
}

/* shell */
.report-bento-shell{display:flex;flex-direction:column;gap:var(--rpt-gap);margin:0 0 24px}

/* hero */
.report-hero-card{
  padding:var(--rpt-pad-hero);
  border:1px solid var(--rpt-border);
  border-radius:var(--rpt-radius-lg);
  background:linear-gradient(140deg,#ffffff 0%,#f7fcf9 100%);
  box-shadow:var(--rpt-shadow);
  color:var(--rpt-ink);
}
.report-hero-top{
  margin-bottom:16px;
}
.report-hero-brand{display:flex;align-items:center;gap:12px}
.report-summary-icon{
  width:40px;height:40px;border-radius:12px;
  background:linear-gradient(135deg,#eaf4ed,#dcece1);
  border:1px solid #cce0d3;color:#2f5c43;
  display:grid;place-items:center;flex-shrink:0;
}
.report-summary-title{margin:0;font-size:1.05rem;font-weight:800;color:var(--rpt-ink);letter-spacing:-.022em;line-height:1.2}
.report-summary-meta-line{margin:2px 0 0;font-size:.77rem;color:var(--rpt-muted);font-weight:500}
.report-summary-meta-line strong{color:#3c4d42;font-weight:650}

/* rate widget */
.report-hero-rate{
  margin-top:14px;
  padding:14px 16px;border-radius:var(--rpt-radius-md);
  background:#edf6ef;border:1px solid #dcebe0;
}
.report-rate-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
.report-rate-label{font-size:.85rem;font-weight:700;color:#3c5746}
.report-rate-pct{font-size:1.65rem;font-weight:800;line-height:1;color:#13775c;font-variant-numeric:tabular-nums}
.report-rate-track{width:100%;height:8px;border-radius:999px;background:#d8e7dc;overflow:hidden}
.report-rate-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#168f72,#20b589);transition:width .45s ease}
.report-rate-note{margin:8px 0 0;font-size:.76rem;color:#526659;font-weight:500}

/* KPI strip — 4 cards inline under hero */
.report-kpi-strip{
  display:grid;
  grid-template-columns:repeat(4,minmax(0,1fr));
  gap:10px;margin-top:14px;
}
.report-kpi-box{
  padding:11px 13px;
  border:1px solid #e9eeeb;border-radius:var(--rpt-radius-sm);
  background:#fafcfb;
  display:flex;flex-direction:column;gap:2px;
  transition:transform .15s,border-color .15s,box-shadow .15s;
}
.report-kpi-box:hover{transform:translateY(-1px);border-color:#cad9cf;box-shadow:0 4px 12px rgba(30,52,36,.05)}
.report-kpi-label{font-size:.72rem;font-weight:650;color:var(--rpt-muted);text-transform:uppercase;letter-spacing:.03em}
.report-kpi-val{font-size:1.6rem;font-weight:800;line-height:1.05;color:var(--rpt-ink);font-variant-numeric:tabular-nums;margin:2px 0}
.report-kpi-sub{font-size:.71rem;font-weight:500;color:#748078}
.report-kpi-sub.is-positive{color:var(--rpt-green);font-weight:700}
.report-kpi-sub.is-warn{color:#dc4c5d;font-weight:700}

/* bento row — 2-col grid */
.report-bento-row{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:var(--rpt-gap);
  align-items:stretch;
}

/* bento card */
.bento-card{
  padding:var(--rpt-pad-card);
  border:1px solid var(--rpt-border);border-radius:var(--rpt-radius-lg);
  background:linear-gradient(150deg,var(--rpt-bg-card) 0%,var(--rpt-bg-card2) 100%);
  box-shadow:var(--rpt-shadow);
  display:flex;flex-direction:column;
}
.bento-card-head{
  display:flex;align-items:flex-start;justify-content:space-between;
  gap:8px;margin-bottom:12px;
}
.bento-card-eyebrow{
  display:block;font-size:.65rem;font-weight:750;
  letter-spacing:.09em;text-transform:uppercase;
  color:var(--rpt-eyebrow);margin-bottom:3px;
}
.bento-card-title{
  margin:0;font-size:.9rem;font-weight:750;
  color:var(--rpt-ink);letter-spacing:-.01em;
  display:flex;align-items:center;gap:7px;line-height:1.3;
}

/* chart wrappers */
.report-comparison-chart{position:relative;height:200px;width:100%;flex:1;min-height:180px}
.w2w-chart-wrap{position:relative;height:200px;width:100%;flex:1;min-height:180px}

/* findings list */
.report-findings-list{display:flex;flex-direction:column;gap:7px;margin:0;padding:0;list-style:none;flex:1}
.report-finding-item{
  display:flex;align-items:center;gap:10px;
  padding:8px 11px;border-radius:10px;
  background:#f6faf7;border:1px solid #e9f1eb;
}
.report-finding-icon{
  width:28px;height:28px;border-radius:7px;
  background:var(--rpt-green-lt);color:#2f5c43;
  display:grid;place-items:center;flex-shrink:0;
}
.report-finding-copy{
  display:flex;align-items:baseline;justify-content:space-between;
  gap:10px;flex:1;min-width:0;flex-wrap:wrap;
}
.report-finding-label{font-size:.75rem;font-weight:600;color:var(--rpt-muted)}
.report-finding-value{font-size:.83rem;font-weight:750;color:var(--rpt-ink);text-align:right}

/* week-to-week table */
.w2w-table-wrap{overflow-x:auto;flex:1}
.w2w-table{
  width:100%;margin:0;border-collapse:collapse;
  background:transparent;box-shadow:none;font-size:.82rem;
}
.w2w-table th{
  padding:10px 12px;text-align:left;background:#e8f0eb;
  font-size:.7rem;font-weight:700;text-transform:uppercase;
  letter-spacing:.05em;color:#405b4a;border:0;border-bottom:1px solid #dce8df;
}
.w2w-table td{
  padding:10px 12px;
  border:0;border-bottom:1px solid #e8efe9;color:#3c4d42;
  font-variant-numeric:tabular-nums;
}
.w2w-table tr:last-child td{border-bottom:0}
.w2w-table .w2w-metric-col{font-weight:600}
.w2w-table .w2w-val-col{
  background:#f3f8f4;color:var(--rpt-ink);font-weight:750;white-space:nowrap;
}
.w2w-trend{
  display:inline-flex;align-items:center;justify-content:center;
  margin-left:4px;font-size:.82rem;font-weight:800;line-height:1;
}
.w2w-trend.is-up{color:var(--rpt-green)}
.w2w-trend.is-down{color:#e45768}
.w2w-trend.is-neutral{color:#94a3a0;font-weight:600}

/* summary & recs */
.report-narrative-text{margin:0;font-size:.86rem;line-height:1.65;color:#46574c}
.report-summary-highlight{
  padding:13px 15px;border-radius:11px;
  background:#f6faf7;border:1px solid #e5ede7;
  border-left:3px solid var(--rpt-green);
  flex:1;display:flex;align-items:center;
}
.report-recs-icon{color:#2f5c43;display:inline-flex;align-items:center}
.report-recs-list{
  margin:0;padding-left:16px;
  display:flex;flex-direction:column;gap:7px;
  font-size:.84rem;line-height:1.55;color:#46574c;
}
.report-recs-list li::marker{font-weight:750;color:var(--rpt-green)}

/* responsive */
@media(max-width:1100px){
  .report-kpi-strip{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:860px){
  .report-bento-row{grid-template-columns:1fr}
  .report-comparison-chart,.w2w-chart-wrap{height:180px}
}
@media(max-width:580px){
  .report-hero-card,.bento-card{padding:14px 16px}
  .report-kpi-strip{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
  .report-finding-copy{flex-direction:column;align-items:flex-start;gap:2px}
  .report-finding-value{text-align:left}
  .report-kpi-val{font-size:1.4rem}
}
@media(max-width:420px){
  .report-kpi-strip{grid-template-columns:1fr}
}

/* dark-mode */
body.dark-mode .report-hero-card,body.dark-mode .bento-card{
  background:linear-gradient(150deg,#242f28,#1e2922);
  border-color:#364040;color:#e3ede6;
}
body.dark-mode .report-summary-icon,body.dark-mode .report-finding-icon{background:#2d4237;border-color:#3c5445;color:#b8d4c0}
body.dark-mode .report-summary-title,body.dark-mode .bento-card-title,
body.dark-mode .report-kpi-val,body.dark-mode .report-finding-value{color:#ecf3ee}
body.dark-mode .report-summary-meta-line,body.dark-mode .report-rate-label,
body.dark-mode .report-rate-note,body.dark-mode .report-kpi-label,
body.dark-mode .report-kpi-sub,body.dark-mode .report-finding-label,
body.dark-mode .bento-card-eyebrow{color:#9ab0a2}
body.dark-mode .report-summary-meta-line strong{color:#cddbd1}
body.dark-mode .report-kpi-box,
body.dark-mode .report-finding-item,body.dark-mode .report-summary-highlight{background:#1a2620;border-color:#2e3f35}
body.dark-mode .report-hero-rate{background:#23372b;border-color:#354d3d}
body.dark-mode .report-rate-track{background:#3a5143}
body.dark-mode .report-rate-pct,body.dark-mode .report-kpi-sub.is-positive,body.dark-mode .w2w-trend.is-up{color:#2fd9a4}
body.dark-mode .report-kpi-sub.is-warn,body.dark-mode .w2w-trend.is-down{color:#f87171}
body.dark-mode .w2w-table th{background:#304139;color:#cee3d4;border-bottom-color:#3a4e42}
body.dark-mode .w2w-table td{color:#bccec3;border-bottom-color:#34453b}
body.dark-mode .w2w-table .w2w-val-col{background:#26372e;color:#ecf3ee}
body.dark-mode .report-narrative-text,body.dark-mode .report-recs-list{color:#bccec3}
body.dark-mode .report-recs-icon{color:#2fd9a4}
</style>
</head>
<body class="student-body weekly-report-page">
<?php include 'includes/sidebar.php'; ?>

<main class="s-main">
<div class="s-inner">

<header class="s-head">
<div>
<div class="s-label">WEEKLY REPORT</div>
<h1>Your activity summary</h1>
<p>Tasks, focus time, and check-ins for the selected week.</p>
</div>
</header>
<?php include __DIR__ . '/includes/insights_tabs.php'; ?>
<?php if (!$report['has_activity']): ?><p role="status" class="report-empty-alert">No activity has been logged for this week yet. Complete a weekly reflection, update a task, focus, or log a delay to build your summary.</p><?php endif; ?>

<div class="report-bento-shell">
    <section class="report-hero-card" aria-labelledby="weeklyReportHeading">
        <header class="report-hero-top">
            <div class="report-hero-brand">
                <div class="report-summary-icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6"/><path d="M9 16h4"/></svg>
                </div>
                <div>
                    <h2 id="weeklyReportHeading" class="report-summary-title">Weekly Productivity Report</h2>
                    <p class="report-summary-meta-line"><strong><?php echo htmlspecialchars($reportDateRange, ENT_QUOTES, 'UTF-8'); ?></strong> · Week <?php echo $reportWeekNumber; ?> · <?php echo $report['is_partial'] ? 'Report in progress' : 'Auto-generated'; ?></p>
                </div>
            </div>
        </header>
        <div class="report-kpi-strip">
            <div class="report-kpi-box">
                <span class="report-kpi-label">Total scheduled tasks</span>
                <span class="report-kpi-val"><?php echo $reportTotalTasks; ?></span>
                <span class="report-kpi-sub"><?php echo $weekOffset === 0 ? 'This week' : 'Selected week'; ?></span>
            </div>
            <div class="report-kpi-box">
                <span class="report-kpi-label">Completed tasks</span>
                <span class="report-kpi-val"><?php echo $reportCompletedTasks; ?></span>
                <span class="report-kpi-sub <?php echo $completedDelta > 0 ? 'is-positive' : ''; ?>"><?php echo htmlspecialchars($completedDeltaText, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="report-kpi-box">
                <span class="report-kpi-label">Overdue tasks</span>
                <span class="report-kpi-val"><?php echo $reportOverdueTasks; ?></span>
                <span class="report-kpi-sub <?php echo $overdueDelta < 0 ? 'is-positive' : ($overdueDelta > 0 ? 'is-warn' : ''); ?>"><?php echo htmlspecialchars($overdueDeltaText, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="report-kpi-box">
                <span class="report-kpi-label">Pending tasks</span>
                <span class="report-kpi-val"><?php echo $reportPendingTasks; ?></span>
                <span class="report-kpi-sub">Not yet overdue</span>
            </div>
        </div>
        <div class="report-hero-rate">
            <div class="report-rate-head">
                <span class="report-rate-label">Weekly completion rate</span>
                <span class="report-rate-pct"><?php echo $reportRate; ?>%</span>
            </div>
            <div class="report-rate-track" aria-hidden="true">
                <div class="report-rate-fill" style="width:<?php echo $reportRate; ?>%"></div>
            </div>
            <p class="report-rate-note"><?php echo htmlspecialchars($rateDeltaText, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    </section>

    <div class="report-bento-row">
        <section class="bento-card" aria-labelledby="compChartHeading">
            <div class="bento-card-head">
                <div>
                    <span class="bento-card-eyebrow">Daily Comparison</span>
                    <h3 id="compChartHeading" class="bento-card-title">Weekly performance comparison</h3>
                </div>
            </div>
            <div class="report-comparison-chart">
                <canvas id="weeklyComparisonChart" role="img" aria-label="Grouped bar chart comparing tasks completed each day last week versus this week"></canvas>
            </div>
        </section>

        <section class="bento-card" aria-labelledby="findingsHeading">
            <div class="bento-card-head">
                <div>
                    <span class="bento-card-eyebrow">Check-in &amp; Activity</span>
                    <h3 id="findingsHeading" class="bento-card-title">Behavioral findings</h3>
                </div>
            </div>
            <ul class="report-findings-list">
                <li class="report-finding-item">
                    <span class="report-finding-icon" aria-hidden="true"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></span>
                    <div class="report-finding-copy">
                        <span class="report-finding-label">Most productive day</span>
                        <strong class="report-finding-value"><?php echo htmlspecialchars($mostProductiveDay, ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                </li>
                <li class="report-finding-item">
                    <span class="report-finding-icon" aria-hidden="true"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg></span>
                    <div class="report-finding-copy">
                        <span class="report-finding-label">Lowest task completion</span>
                        <strong class="report-finding-value"><?php echo htmlspecialchars($lowestCompletionDay, ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                </li>
                <li class="report-finding-item">
                    <span class="report-finding-icon" aria-hidden="true"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg></span>
                    <div class="report-finding-copy">
                        <span class="report-finding-label">Main reported distraction</span>
                        <strong class="report-finding-value"><?php echo htmlspecialchars($mainDistraction, ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                </li>
                <li class="report-finding-item">
                    <span class="report-finding-icon" aria-hidden="true"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg></span>
                    <div class="report-finding-copy">
                        <span class="report-finding-label">Most neglected priority</span>
                        <strong class="report-finding-value"><?php echo htmlspecialchars($neglectedPriority, ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                </li>
                <li class="report-finding-item">
                    <span class="report-finding-icon" aria-hidden="true"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
                    <div class="report-finding-copy">
                        <span class="report-finding-label">Recorded postponements</span>
                        <strong class="report-finding-value"><?php echo htmlspecialchars($postponementsText, ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                </li>
            </ul>
        </section>
    </div>

    <div class="report-bento-row">
        <section class="bento-card" aria-labelledby="weekToWeekHeading">
            <div class="bento-card-head">
                <div>
                    <span class="bento-card-eyebrow">Progress Delta</span>
                    <h3 id="weekToWeekHeading" class="bento-card-title">Week-to-week progress</h3>
                </div>
            </div>
            <div class="w2w-table-wrap">
                <table class="w2w-table">
                    <thead>
                        <tr>
                            <th scope="col">Metric</th>
                            <th scope="col">Last week</th>
                            <th scope="col">This week</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="w2w-metric-col">Completion rate</td>
                            <td><?php echo $lastWeekRate; ?>%</td>
                            <td class="w2w-val-col"><?php echo $reportRate; ?>% <?php echo w2w_trend_badge($reportRate, $lastWeekRate, false); ?></td>
                        </tr>
                        <tr>
                            <td class="w2w-metric-col">Completed tasks</td>
                            <td><?php echo $lastWeekCompleted; ?></td>
                            <td class="w2w-val-col"><?php echo $reportCompletedTasks; ?> <?php echo w2w_trend_badge($reportCompletedTasks, $lastWeekCompleted, false); ?></td>
                        </tr>
                        <tr>
                            <td class="w2w-metric-col">Overdue tasks</td>
                            <td><?php echo $lastWeekOverdue; ?></td>
                            <td class="w2w-val-col"><?php echo $reportOverdueTasks; ?> <?php echo w2w_trend_badge($reportOverdueTasks, $lastWeekOverdue, true); ?></td>
                        </tr>
                        <tr>
                            <td class="w2w-metric-col">Postponements</td>
                            <td><?php echo $lastWeekPostponements; ?></td>
                            <td class="w2w-val-col"><?php echo $postponementsCount; ?> <?php echo w2w_trend_badge($postponementsCount, $lastWeekPostponements, true); ?></td>
                        </tr>
                        <tr>
                            <td class="w2w-metric-col">High-priority completed</td>
                            <td><?php echo $lastWeekHighCompleted; ?></td>
                            <td class="w2w-val-col"><?php echo $thisWeekHighCompleted; ?> <?php echo w2w_trend_badge($thisWeekHighCompleted, $lastWeekHighCompleted, false); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="bento-card" aria-labelledby="fourWeekHeading">
            <div class="bento-card-head">
                <div>
                    <span class="bento-card-eyebrow">4-Week Trend</span>
                    <h3 id="fourWeekHeading" class="bento-card-title">Task completion rate over four weeks</h3>
                </div>
            </div>
            <div class="w2w-chart-wrap">
                <canvas id="fourWeekRateChart" role="img" aria-label="Line chart showing task completion rate percentage over the last four weeks"></canvas>
            </div>
        </section>
    </div>

    <div class="report-bento-row">
        <section class="bento-card" aria-labelledby="weeklySummaryHeading">
            <div class="bento-card-head">
                <div>
                    <span class="bento-card-eyebrow">Narrative</span>
                    <h3 id="weeklySummaryHeading" class="bento-card-title">Weekly summary</h3>
                </div>
            </div>
            <div class="report-summary-highlight">
                <p class="report-narrative-text"><?php echo htmlspecialchars($weeklySummaryText, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </section>

        <section class="bento-card" aria-labelledby="recsHeading">
            <div class="bento-card-head">
                <div>
                    <span class="bento-card-eyebrow">Action Plan</span>
                    <h3 id="recsHeading" class="bento-card-title">
                        <span class="report-recs-icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg></span>
                        Personalized recommendations
                    </h3>
                </div>
            </div>
            <ol class="report-recs-list">
                <?php foreach ($weeklyRecommendations as $recLine): ?>
                <li><?php echo htmlspecialchars($recLine, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ol>
        </section>
    </div>
</div>
</div>
</main>

<script>
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.color = '#6b6580';
Chart.defaults.font.size = 11;
Chart.defaults.scale.grid.color = 'rgba(116, 132, 121, 0.14)';
Chart.defaults.scale.border.color = 'rgba(116, 132, 121, 0.2)';
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.boxWidth = 9;
Chart.defaults.plugins.legend.labels.boxHeight = 9;
Chart.defaults.plugins.legend.labels.padding = 16;
Chart.defaults.plugins.tooltip.backgroundColor = '#1f2e25';
Chart.defaults.plugins.tooltip.padding = 10;
Chart.defaults.plugins.tooltip.cornerRadius = 8;

// 4-week completion rate line chart
const fourWeekCanvas = document.getElementById('fourWeekRateChart');
if (fourWeekCanvas) new Chart(fourWeekCanvas, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($fourWeekLabels); ?>,
        datasets: [{
            label: 'Completion rate',
            data: <?php echo json_encode($fourWeekRates); ?>,
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
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
            x: { grid: { display: false }, border: { display: false } },
            y: {
                beginAtZero: true,
                max: 100,
                border: { display: false, dash: [4, 4] },
                grid: { tickLength: 0 },
                ticks: { stepSize: 25, callback: v => v + '%' }
            }
        },
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => 'Completion rate: ' + ctx.parsed.y + '%' } }
        }
    }
});

// Weekly performance comparison chart
const weeklyCompCanvas = document.getElementById('weeklyComparisonChart');
if (weeklyCompCanvas) new Chart(weeklyCompCanvas, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($compLabels); ?>,
        datasets: [
            {
                label: 'Last week',
                data: <?php echo json_encode($compLastWeek); ?>,
                backgroundColor: 'rgba(59, 110, 140, 0.85)',
                hoverBackgroundColor: '#2c5670',
                borderRadius: { topLeft: 7, topRight: 7, bottomLeft: 0, bottomRight: 0 },
                borderSkipped: 'bottom',
                barPercentage: 0.78,
                categoryPercentage: 0.68
            },
            {
                label: 'This week',
                data: <?php echo json_encode($compThisWeek); ?>,
                backgroundColor: 'rgba(22, 143, 114, 0.88)',
                hoverBackgroundColor: '#0f765d',
                borderRadius: { topLeft: 7, topRight: 7, bottomLeft: 0, bottomRight: 0 },
                borderSkipped: 'bottom',
                barPercentage: 0.78,
                categoryPercentage: 0.68
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
            x: { grid: { display: false }, border: { display: false } },
            y: { beginAtZero: true, suggestedMax: 6, border: { display: false, dash: [4, 4] }, grid: { tickLength: 0 }, ticks: { precision: 0, stepSize: 2 } }
        },
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});

// Daily activity chart
const dailyChartCanvas = document.getElementById('dailyChart');
if (dailyChartCanvas) new Chart(dailyChartCanvas, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($d['days']); ?>,
        datasets: [
            {
                label: 'Tasks due',
                data: <?php echo json_encode($d['dailyTasksDue']); ?>,
                backgroundColor: 'rgba(52,120,212,0.18)',
                borderColor: '#3478d4',
                borderWidth: 2,
                borderRadius: 5,
                maxBarThickness: 22
            },
            {
                label: 'Completed',
                data: <?php echo json_encode($d['dailyCompleted']); ?>,
                backgroundColor: 'rgba(22,143,114,0.22)',
                borderColor: '#168f72',
                borderWidth: 2,
                borderRadius: 5,
                maxBarThickness: 22
            },
            {
                label: 'Check-ins',
                data: <?php echo json_encode($d['dailyCheckins']); ?>,
                backgroundColor: 'rgba(124,58,237,0.18)',
                borderColor: '#7c3aed',
                borderWidth: 2,
                borderRadius: 5,
                maxBarThickness: 22
            },
            {
                label: 'Delays',
                data: <?php echo json_encode($d['dailyDelays']); ?>,
                backgroundColor: 'rgba(220,69,88,0.18)',
                borderColor: '#dc4558',
                borderWidth: 2,
                borderRadius: 5,
                maxBarThickness: 22
            },
            {
                label: 'Study time',
                data: <?php echo json_encode(array_map(fn($m) => round($m / 60, 1), $d['dailyStudyMin'])); ?>,
                type: 'line',
                yAxisID: 'y1',
                borderColor: '#0785b5',
                backgroundColor: '#0785b5',
                borderWidth: 2,
                tension: 0.3,
                fill: false,
                pointRadius: 3,
                pointHoverRadius: 5
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
            x: { grid: { display: false }, ticks: { maxRotation: 0, minRotation: 0 } },
            y: {
                beginAtZero: true,
                suggestedMax: 1,
                position: 'left',
                ticks: { precision: 0, stepSize: 1 },
                title: { display: true, text: 'Tasks / reports' }
            },
            y1: {
                beginAtZero: true,
                suggestedMax: 1,
                position: 'right',
                grid: { drawOnChartArea: false },
                ticks: { precision: 1, callback: value => `${value}h` },
                title: { display: true, text: 'Study time' }
            }
        },
        plugins: {
            legend: { position: 'top', labels: { usePointStyle: true, padding: 14 } },
            tooltip: {
                callbacks: {
                    label: context => context.dataset.yAxisID === 'y1'
                        ? `${context.dataset.label}: ${context.parsed.y}h`
                        : `${context.dataset.label}: ${context.parsed.y}`
                }
            }
        }
    }
});

// Focus & energy chart
const focusChartCanvas = document.getElementById('focusChart');
if (focusChartCanvas) new Chart(focusChartCanvas, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($d['days']); ?>,
        datasets: [
            {
                label: 'Focus',
                data: <?php echo json_encode($d['dailyFocus']); ?>,
                borderColor: '#7c3aed',
                backgroundColor: '#7c3aed',
                tension: 0.3,
                fill: false,
                spanGaps: false,
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5
            },
            {
                label: 'Energy',
                data: <?php echo json_encode($d['dailyEnergy']); ?>,
                borderColor: '#168f72',
                backgroundColor: '#168f72',
                tension: 0.3,
                fill: false,
                spanGaps: false,
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
            x: { grid: { display: false }, ticks: { maxRotation: 0, minRotation: 0 } },
            y: {
                beginAtZero: true,
                min: 0,
                max: 10,
                ticks: { stepSize: 2, callback: value => `${value}/10` },
                title: { display: true, text: 'Rating' }
            }
        },
        plugins: {
            legend: { position: 'top', labels: { usePointStyle: true, padding: 14 } },
            tooltip: {
                callbacks: {
                    label: context => `${context.dataset.label}: ${context.parsed.y}/10`
                }
            }
        }
    }
});

function updateReportChartTheme() {
    const darkMode = document.body.classList.contains('dark-mode');
    const textColor = darkMode ? '#c1cdc4' : '#6b7a70';
    const gridColor = darkMode ? 'rgba(190, 208, 194, 0.12)' : 'rgba(116, 132, 121, 0.14)';
    const borderColor = darkMode ? 'rgba(190, 208, 194, 0.2)' : 'rgba(116, 132, 121, 0.2)';
    Chart.defaults.color = textColor;
    Chart.defaults.scale.grid.color = gridColor;
    Chart.defaults.scale.border.color = borderColor;
    Chart.defaults.plugins.tooltip.backgroundColor = darkMode ? '#101713' : '#1f2e25';
    ['dailyChart', 'focusChart'].forEach(id => {
        const canvas = document.getElementById(id);
        if (!canvas) return;
        const chart = Chart.getChart(canvas);
        if (!chart) return;
        Object.values(chart.options.scales || {}).forEach(scale => {
            scale.ticks.color = textColor;
            if (scale.title) scale.title.color = textColor;
            if (scale.grid && scale.grid.display !== false) scale.grid.color = gridColor;
            if (scale.border) scale.border.color = borderColor;
        });
        if (chart.options.plugins.legend) chart.options.plugins.legend.labels.color = textColor;
        chart.options.plugins.tooltip.backgroundColor = darkMode ? '#101713' : '#1f2e25';
        chart.update('none');
    });
}
new MutationObserver(updateReportChartTheme).observe(document.body, { attributes: true, attributeFilter: ['class'] });
updateReportChartTheme();
</script>

</body>
</html>
