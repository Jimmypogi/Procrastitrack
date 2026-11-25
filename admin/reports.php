<?php
require_once __DIR__ . '/auth.php';
admin_require_role(['superadmin', 'admin', 'analyst']);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/_layout.php';

$preset = $_GET['range'] ?? '30';
$allowedRanges = ['7', '30', '90', 'custom'];
if (!in_array($preset, $allowedRanges, true)) $preset = '30';
$today = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-29 days'));
$fromParam = $_GET['from'] ?? null;
$toParam = $_GET['to'] ?? null;
$from = $preset === 'custom' && is_string($fromParam) ? $fromParam : ($preset === 'custom' ? $defaultFrom : date('Y-m-d', strtotime('-' . ((int)$preset - 1) . ' days')));
$to = $preset === 'custom' && is_string($toParam) ? $toParam : $today;
$fromDate = DateTime::createFromFormat('!Y-m-d', $from);
$toDate = DateTime::createFromFormat('!Y-m-d', $to);
$invalidCustomRange = $preset === 'custom' && (
    ($fromParam !== null && (!is_string($fromParam) || $fromParam === '')) ||
    ($toParam !== null && (!is_string($toParam) || $toParam === '')) ||
    !$fromDate || !$toDate ||
    $fromDate->format('Y-m-d') !== $from ||
    $toDate->format('Y-m-d') !== $to ||
    $from > $to
);
$rangeError = '';
if ($invalidCustomRange) {
    $rangeError = 'That custom date range is invalid. Showing the last 30 days instead.';
    $preset = '30';
    $from = $defaultFrom;
    $to = $today;
}
$fromSql = mysqli_real_escape_string($conn, $from);
$toSql = mysqli_real_escape_string($conn, $to);
$privacyThreshold = 5;

$scalar = function ($sql) use ($conn) {
    $result = mysqli_query($conn, $sql);
    if (!$result) return 0;
    $row = mysqli_fetch_row($result);
    return $row[0] ?? 0;
};
$activeCohort = (int)$scalar("SELECT COUNT(*) FROM users WHERE is_active=1");
$checkinCount = (int)$scalar("SELECT COUNT(*) FROM procrastiblock_logs WHERE log_type='checkin' AND DATE(created_at) BETWEEN '$fromSql' AND '$toSql'");
$delayCount = (int)$scalar("SELECT COUNT(*) FROM procrastiblock_logs WHERE log_type='delay' AND DATE(created_at) BETWEEN '$fromSql' AND '$toSql'");
$createdTasks = (int)$scalar("SELECT COUNT(*) FROM tasks WHERE DATE(created_at) BETWEEN '$fromSql' AND '$toSql'");
$completedTasks = (int)$scalar("SELECT COUNT(*) FROM tasks WHERE status='Completed' AND DATE(created_at) BETWEEN '$fromSql' AND '$toSql' AND (completed_at IS NULL OR completed_at <= '$toSql 23:59:59')");
$overdueTasks = (int)$scalar("SELECT COUNT(*) FROM tasks WHERE status!='Completed' AND " . app_task_overdue_sql() . " AND deadline BETWEEN '$fromSql' AND '$toSql'");
$avgFocus = (float)$scalar("SELECT COALESCE(AVG(focus_level),0) FROM procrastiblock_logs WHERE log_type='checkin' AND DATE(created_at) BETWEEN '$fromSql' AND '$toSql'");
$avgEnergy = (float)$scalar("SELECT COALESCE(AVG(energy_level),0) FROM procrastiblock_logs WHERE log_type='checkin' AND DATE(created_at) BETWEEN '$fromSql' AND '$toSql'");
$avgDelay = (float)$scalar("SELECT COALESCE(AVG(duration_minutes),0) FROM procrastiblock_logs WHERE log_type='delay' AND DATE(created_at) BETWEEN '$fromSql' AND '$toSql'");
$completionRate = $createdTasks > 0 ? round(($completedTasks / $createdTasks) * 100) : 0;
$risk = mysqli_query($conn, "SELECT CASE ut.tendency_level WHEN 'very_low' THEN 'Few Delay Signals' WHEN 'low' THEN 'Occasional Delay Signals' WHEN 'moderate' THEN 'Some Delay Signals' WHEN 'high' THEN 'Frequent Delay Signals' WHEN 'very_high' THEN 'Elevated Delay Signals' ELSE 'Unclassified' END level, COUNT(*) total FROM users u LEFT JOIN user_tendency ut ON ut.id=(SELECT latest.id FROM user_tendency latest WHERE latest.user_id=u.id ORDER BY latest.classified_at DESC,latest.id DESC LIMIT 1) WHERE u.is_active=1 GROUP BY level ORDER BY total DESC");
// Consecutive seven-day buckets include periods with no recorded activity.
$activityDays=[];
$activityResult=$conn->query("SELECT DATE(created_at) day,COUNT(*) total FROM procrastiblock_logs WHERE DATE(created_at) BETWEEN '$fromSql' AND '$toSql' GROUP BY DATE(created_at)");
while($day=$activityResult->fetch_assoc()) $activityDays[$day['day']]=(int)$day['total'];
$weekly=[];
$cursor=new DateTimeImmutable($from);$limit=new DateTimeImmutable($to);
while($cursor<=$limit){
 $end=$cursor->modify('+6 days');if($end>$limit)$end=$limit;
 $total=0;for($d=$cursor;$d<=$end;$d=$d->modify('+1 day'))$total+=$activityDays[$d->format('Y-m-d')]??0;
 $weekly[]=['label'=>$cursor->format('M j'),'end_label'=>$end->format('M j, Y'),'count'=>$total];
 $cursor=$end->modify('+1 day');
}

admin_page_start('Reports & trends', 'reports');
?>
<section class="report-toolbar card"><div><strong>Choose a report period</strong><span class="muted"> Select a preset, or choose Custom range to enter dates.</span></div><form method="get" class="filter-bar" id="reportFilterForm"><label for="reportRange">Date range</label><select id="reportRange" name="range"><option value="7" <?php echo $preset === '7' ? 'selected' : ''; ?>>Last 7 days</option><option value="30" <?php echo $preset === '30' ? 'selected' : ''; ?>>Last 30 days</option><option value="90" <?php echo $preset === '90' ? 'selected' : ''; ?>>Last 90 days</option><option value="custom" <?php echo $preset === 'custom' ? 'selected' : ''; ?>>Custom range</option></select><div class="filter-custom-range" id="customRangeFields" <?php echo $preset !== 'custom' ? 'hidden' : ''; ?>><label for="reportFrom">From<input id="reportFrom" type="date" name="from" value="<?php echo htmlspecialchars($from); ?>" <?php echo $preset !== 'custom' ? 'disabled' : 'required'; ?>></label><label for="reportTo">To<input id="reportTo" type="date" name="to" value="<?php echo htmlspecialchars($to); ?>" <?php echo $preset !== 'custom' ? 'disabled' : 'required'; ?>></label></div><button class="button primary" type="submit">Apply</button></form><noscript><p class="muted">Custom date fields need JavaScript. You can still use the 7, 30, or 90 day presets.</p></noscript></section>
<?php if ($rangeError): ?><p class="filter-error" role="alert"><?php echo htmlspecialchars($rangeError, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
<section class="privacy-note"><strong>How to read this report.</strong> Choose a period above and select Apply. The date range filters activity metrics and trends. The risk distribution is the latest saved estimate across active accounts, not a date-filtered result. No names or individual records are shown; groups smaller than <?php echo $privacyThreshold; ?> are masked. These signals can support a conversation, but they are not a diagnosis.</section>
<section class="grid stats report-stats"><article class="card"><div class="stat-label">Active cohort</div><div class="stat-value"><?php echo $activeCohort; ?></div><div class="stat-meta">Account count, not a student list</div></article><article class="card"><div class="stat-label">Completion rate</div><div class="stat-value"><?php echo $completionRate; ?>%</div><div class="stat-meta"><?php echo $completedTasks; ?> of <?php echo $createdTasks; ?> tracked tasks</div></article><article class="card"><div class="stat-label">Avg focus</div><div class="stat-value"><?php echo number_format($avgFocus, 1); ?><small>/10</small></div><div class="stat-meta"><?php echo $checkinCount; ?> check-ins in range</div></article><article class="card"><div class="stat-label">Support signals</div><div class="stat-value"><?php echo $overdueTasks + $delayCount; ?></div><div class="stat-meta"><?php echo $overdueTasks; ?> overdue · <?php echo $delayCount; ?> delays</div></article></section>
<section class="grid two"><article class="card"><div class="card-title"><h2>Activity trend</h2><span class="muted">Weekly signals</span></div><?php include __DIR__.'/_activity_chart.php'; ?></article><article class="card"><div class="card-title"><h2>Risk distribution</h2><span class="muted">Current anonymized snapshot</span></div><?php if ($risk && mysqli_num_rows($risk)): while ($row = mysqli_fetch_assoc($risk)): $visible = (int)$row['total'] >= $privacyThreshold; ?><div class="bar-row"><span><?php echo $visible ? htmlspecialchars($row['level']) : 'Private group'; ?></span><div class="bar"><i style="width:<?php echo $visible && $activeCohort ? round(((int)$row['total'] / $activeCohort) * 100) : 4; ?>%"></i></div><strong><?php echo $visible ? (int)$row['total'] : '&lt;5'; ?></strong></div><?php endwhile; else: ?><p class="muted">No risk profiles available.</p><?php endif; ?></article></section>
<section class="grid three"><article class="card"><div class="card-title"><h3>Focus & energy</h3></div><div class="metric-pair"><span>Focus</span><strong><?php echo number_format($avgFocus, 1); ?>/10</strong></div><div class="metric-pair"><span>Energy</span><strong><?php echo number_format($avgEnergy, 1); ?>/10</strong></div></article><article class="card"><div class="card-title"><h3>Delay pattern</h3></div><div class="metric-pair"><span>Delay logs</span><strong><?php echo $delayCount; ?></strong></div><div class="metric-pair"><span>Average duration</span><strong><?php echo round($avgDelay); ?> min</strong></div></article><article class="card"><div class="card-title"><h3>Counselor next step</h3></div><p class="muted">Use these trends to guide a conversation. They are support signals, not diagnosis.</p><a class="button secondary" href="export.php">Export summary →</a></article></section>
<script>
(() => {
    const range = document.getElementById('reportRange');
    const fields = document.getElementById('customRangeFields');
    const from = document.getElementById('reportFrom');
    const to = document.getElementById('reportTo');
    const updateCustomRange = () => {
        const custom = range.value === 'custom';
        fields.hidden = !custom;
        from.disabled = !custom;
        to.disabled = !custom;
        from.required = custom;
        to.required = custom;
        from.max = to.value;
        to.min = from.value;
    };
    range.addEventListener('change', updateCustomRange);
    from.addEventListener('change', updateCustomRange);
    to.addEventListener('change', updateCustomRange);
    updateCustomRange();
})();
</script>
<?php admin_page_end(); ?>
