<?php
require_once __DIR__ . '/auth.php';
admin_require_role(['superadmin','admin','analyst']);
require_once __DIR__ . '/../config/database.php';
$filename = 'procrastitrack-summary-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output', 'w');
fputcsv($out, ['ProcrastiTrack anonymized summary', date('Y-m-d')]);
fputcsv($out, []);
fputcsv($out, ['Metric', 'Value']);
$metrics = [
    ['Total students', "SELECT COUNT(*) FROM users"],
    ['Active students', "SELECT COUNT(*) FROM users WHERE is_active=1"],
    ['Total tasks', "SELECT COUNT(*) FROM tasks"],
    ['Completed tasks', "SELECT COUNT(*) FROM tasks WHERE status='Completed'"],
    ['Overdue tasks', "SELECT COUNT(*) FROM tasks WHERE status!='Completed' AND " . app_task_overdue_sql()],
    ['Behavior check-ins', "SELECT COUNT(*) FROM procrastiblock_logs WHERE log_type='checkin'"],
    ['Delay logs', "SELECT COUNT(*) FROM procrastiblock_logs WHERE log_type='delay'"],
    ['Study sessions', "SELECT COUNT(*) FROM study_sessions"],
    ['Average focus', "SELECT ROUND(COALESCE(AVG(focus_level),0),1) FROM procrastiblock_logs WHERE log_type='checkin'"],
    ['Average energy', "SELECT ROUND(COALESCE(AVG(energy_level),0),1) FROM procrastiblock_logs WHERE log_type='checkin'"],
];
foreach ($metrics as [$label, $sql]) { $result = mysqli_query($conn, $sql); $row = $result ? mysqli_fetch_row($result) : [0]; fputcsv($out, [$label, $row[0] ?? 0]); }
fputcsv($out, []);
fputcsv($out, ['Behavior pattern estimate', 'Students', 'Average score']);
$result = mysqli_query($conn, "SELECT CASE ut.tendency_level WHEN 'very_low' THEN 'Few Delay Signals' WHEN 'low' THEN 'Occasional Delay Signals' WHEN 'moderate' THEN 'Some Delay Signals' WHEN 'high' THEN 'Frequent Delay Signals' WHEN 'very_high' THEN 'Elevated Delay Signals' ELSE 'Unclassified' END, COUNT(*), ROUND(AVG(ut.score),1) FROM users u LEFT JOIN user_tendency ut ON ut.id=(SELECT latest.id FROM user_tendency latest WHERE latest.user_id=u.id ORDER BY latest.classified_at DESC,latest.id DESC LIMIT 1) WHERE u.is_active=1 GROUP BY ut.tendency_level ORDER BY COUNT(*) DESC");
$privacyThreshold = 5;
while ($row = mysqli_fetch_row($result)) {
    if ((int)$row[1] < $privacyThreshold) {
        // Suppress both the category and its average so small groups cannot be
        // identified from another statistic in the same row.
        fputcsv($out, ['Private group', '<' . $privacyThreshold, 'Suppressed']);
    } else {
        fputcsv($out, $row);
    }
}
fclose($out);
exit();
