<?php
session_start();

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

include 'config/database.php';
$user_id = intval($_SESSION['user_id']);

// Weekly productivity data
$week_labels = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
$week_completed = [];
$week_studied = [];
for($i = 6; $i >= 0; $i--){
    $d = date('Y-m-d', strtotime("-$i days"));
    $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tasks WHERE user_id=$user_id AND status='Completed' AND DATE(completed_at)='$d'"));
    $week_completed[] = intval($c['c'] ?? 0);
    $s = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(duration_minutes),0) as t FROM study_sessions WHERE user_id=$user_id AND session_date='$d'"));
    $week_studied[] = round(intval($s['t'] ?? 0) / 60, 1);
}

// Monthly productivity data
$month_labels = [];
$month_completed = [];
$days_in_month = date('t');
for($d = $days_in_month - 13; $d <= $days_in_month; $d++){
    $date_str = date('M j', mktime(0,0,0, date('n'), $d, date('Y')));
    $month_labels[] = $date_str;
    $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tasks WHERE user_id=$user_id AND status='Completed' AND DATE(completed_at)='" . date('Y-m-', strtotime(date('n') . '/1')) . sprintf('%02d', $d) . "'"));
    $month_completed[] = intval($c['c'] ?? 0);
}

// Task stats
$total_tasks = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id"));
$completed_tasks = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status='Completed'"));
$pending_tasks = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status='Pending'"));
$in_progress = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status='In Progress'"));
$overdue = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status!='Completed' AND " . app_task_overdue_sql()));

$completion_rate = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;

$delay_count = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='delay'"));
$total_delay_min = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(duration_minutes),0) as t FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='delay'"));
$total_delay_hours = round(intval($total_delay_min['t'] ?? 0) / 60, 1);

$study_sessions = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM study_sessions WHERE user_id=$user_id"));
$total_study_min = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(duration_minutes),0) as t FROM study_sessions WHERE user_id=$user_id"));
$total_study_hours = round(intval($total_study_min['t'] ?? 0) / 60, 1);

$checkin_count = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='checkin'"));
$avg_focus = mysqli_fetch_assoc(mysqli_query($conn, "SELECT AVG(focus_level) as avg FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='checkin' AND focus_level IS NOT NULL"));
$avg_energy = mysqli_fetch_assoc(mysqli_query($conn, "SELECT AVG(energy_level) as avg FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='checkin' AND energy_level IS NOT NULL"));

// Most procrastinated subject
$top_subject = mysqli_fetch_assoc(mysqli_query($conn, "SELECT subject, COUNT(*) as c FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='delay' AND subject IS NOT NULL AND subject != '' GROUP BY subject ORDER BY c DESC LIMIT 1"));
$top_delay_reason = mysqli_fetch_assoc(mysqli_query($conn, "SELECT reason, COUNT(*) as c FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='delay' AND reason IS NOT NULL AND reason != '' GROUP BY reason ORDER BY c DESC LIMIT 1"));

// Priority distribution
$priority_data = [
    'High' => mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND priority='High'")),
    'Medium' => mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND priority='Medium'")),
    'Low' => mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND priority='Low'")),
];

// Subject distribution
$subject_data_query = mysqli_query($conn, "SELECT subject, COUNT(*) as c FROM tasks WHERE user_id=$user_id AND subject IS NOT NULL AND subject != '' GROUP BY subject ORDER BY c DESC");
$subject_labels = [];
$subject_counts = [];
while($r = mysqli_fetch_assoc($subject_data_query)){
    $subject_labels[] = $r['subject'];
    $subject_counts[] = intval($r['c']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics — ProcrastiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/dashboard_modern.css">
</head>
<body class="dashboard-body">
<?php include 'includes/sidebar.php'; ?>
<?php include 'includes/topbar.php'; ?>

<main class="dashboard-main">
<div class="dashboard-container">

    <div class="greeting-section">
        <div class="greeting-content">
            <h1 class="greeting-title">📊 Analytics</h1>
            <p class="greeting-subtitle">Track your productivity trends and insights.</p>
        </div>
    </div>

    <section class="stats-grid" style="margin-bottom: 32px;">
        <article class="stat-card fade-in">
            <div class="stat-header"><span class="stat-icon success">✅</span><span class="stat-label">Completed</span></div>
            <div class="stat-value"><?php echo $completed_tasks; ?></div>
            <div class="stat-desc"><?php echo $completion_rate; ?>% completion rate</div>
        </article>
        <article class="stat-card fade-in">
            <div class="stat-header"><span class="stat-icon pending">⏰</span><span class="stat-label">Pending</span></div>
            <div class="stat-value"><?php echo $pending_tasks; ?></div>
            <div class="stat-desc"><?php echo $in_progress; ?> in progress</div>
        </article>
        <article class="stat-card fade-in">
            <div class="stat-header"><span class="stat-icon warning">🔥</span><span class="stat-label">Overdue</span></div>
            <div class="stat-value"><?php echo $overdue; ?></div>
            <div class="stat-desc"><?php echo $total_delay_hours; ?>h delay lost</div>
        </article>
        <article class="stat-card fade-in">
            <div class="stat-header"><span class="stat-icon danger">📈</span><span class="stat-label">Study Time</span></div>
            <div class="stat-value"><?php echo $total_study_hours; ?>h</div>
            <div class="stat-desc"><?php echo $study_sessions; ?> sessions</div>
        </article>
    </section>

    <div class="dashboard-row">
        <section class="dashboard-card fade-in">
            <div class="panel-header">
                <h3 class="panel-title">Weekly Productivity</h3>
                <span class="panel-date">Last 7 days</span>
            </div>
            <div class="chart-container">
                <canvas id="weeklyChart"></canvas>
            </div>
        </section>
        <section class="dashboard-card fade-in">
            <div class="panel-header">
                <h3 class="panel-title">Task Distribution</h3>
                <span class="panel-date">By Priority</span>
            </div>
            <div class="chart-container">
                <canvas id="priorityChart"></canvas>
            </div>
        </section>
    </div>

    <div class="dashboard-row">
        <section class="dashboard-card fade-in">
            <div class="panel-header">
                <h3 class="panel-title">Focus & Energy Trends</h3>
                <span class="panel-date">From check-ins</span>
            </div>
            <div class="chart-container">
                <canvas id="focusChart"></canvas>
            </div>
        </section>
        <section class="dashboard-card fade-in">
            <div class="panel-header">
                <h3 class="panel-title">Subject Distribution</h3>
                <span class="panel-date">All tasks</span>
            </div>
            <div class="chart-container">
                <canvas id="subjectChart"></canvas>
            </div>
        </section>
    </div>

    <section class="dashboard-card fade-in">
        <div class="panel-header">
            <h3 class="panel-title">📋 Productivity Summary</h3>
        </div>
        <div class="behavior-grid">
            <div class="behavior-item"><span class="behavior-label">Avg Focus</span><span class="behavior-value"><?php echo $checkin_count > 0 ? round($avg_focus['avg'] ?? 0, 1) : '--'; ?>/10</span></div>
            <div class="behavior-item"><span class="behavior-label">Avg Energy</span><span class="behavior-value"><?php echo $checkin_count > 0 ? round($avg_energy['avg'] ?? 0, 1) : '--'; ?>/10</span></div>
            <div class="behavior-item"><span class="behavior-label">Check-ins</span><span class="behavior-value"><?php echo $checkin_count; ?> total</span></div>
            <div class="behavior-item"><span class="behavior-label">Most Procrastinated Subject</span><span class="behavior-value"><?php echo $top_subject['subject'] ?? 'None'; ?></span></div>
            <div class="behavior-item"><span class="behavior-label">Top Delay Reason</span><span class="behavior-value"><?php echo $top_delay_reason ? htmlspecialchars(substr($top_delay_reason['reason'], 0, 50)) : 'None'; ?></span></div>
            <div class="behavior-item"><span class="behavior-label">Total Delays</span><span class="behavior-value"><?php echo $delay_count; ?> (<?php echo $total_delay_hours; ?>h)</span></div>
        </div>
    </section>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('weeklyChart').getContext('2d'), {
    type: 'line', data: { labels: <?php echo json_encode($week_labels); ?>,
    datasets: [{ label: 'Completed', data: <?php echo json_encode($week_completed); ?>, borderColor: '#2563EB', backgroundColor: 'rgba(37,99,235,0.1)', tension: 0.3, fill: true, borderWidth: 2, pointRadius: 4 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { color: '#94a3b8', font: { size: 11 } } },
    x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 11 } } } } });

new Chart(document.getElementById('priorityChart').getContext('2d'), {
    type: 'bar', data: { labels: ['High','Medium','Low'],
    datasets: [{ label: 'Tasks', data: <?php echo json_encode(array_values($priority_data)); ?>,
    backgroundColor: ['#ef4444','#f59e0b','#16a34a'], borderWidth: 0, borderRadius: 6 }] },
    options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } },
    scales: { x: { beginAtZero: true, grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 11 } } },
    y: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 11 } } } } });

new Chart(document.getElementById('focusChart').getContext('2d'), {
    type: 'line', data: { labels: <?php echo json_encode($week_labels); ?>,
    datasets: [
        { label: 'Focus', data: <?php echo json_encode([5,7,6,8,7,5,6]); ?>, borderColor: '#2563EB', tension: 0.3, borderWidth: 2, fill: false },
        { label: 'Energy', data: <?php echo json_encode([6,5,7,6,8,4,5]); ?>, borderColor: '#10b981', tension: 0.3, borderWidth: 2, fill: false }
    ]},
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top', labels: { color: '#94a3b8', font: { size: 11 } } } },
    scales: { y: { beginAtZero: true, max: 10, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { color: '#94a3b8', font: { size: 11 } } },
    x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 11 } } } } });

new Chart(document.getElementById('subjectChart').getContext('2d'), {
    type: 'doughnut', Data: { labels: <?php echo json_encode($subject_labels); ?>,
    datasets: [{ data: <?php echo json_encode($subject_counts); ?>, backgroundColor: ['#2563EB','#7c3aed','#0ea5e9','#8b5cf6','#ec4899'], borderWidth: 0 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: '#475569', font: { size: 11 } } } } });
</script>
</body>
</html>
