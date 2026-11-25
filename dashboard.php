<?php
session_start();

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

include 'config/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/lib/task_priority.php';
$effectivePriority = task_priority_sql();
require_once __DIR__ . '/lib/procrastination_classifier.php';
require_once __DIR__ . '/lib/weekly_report.php';
require_once __DIR__ . '/lib/checkin_support.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['fullname'] ?? 'Student';
$today = date('Y-m-d');
$today_dt = new DateTime($today);

// ===== TASK DATA =====
$total_tasks = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id"));
$completed_tasks = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status='Completed'"));
$pending_tasks = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status='Pending'"));
$in_progress_tasks = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status='In Progress'"));
$overdue_tasks = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status!='Completed' AND " . app_task_overdue_sql()));
$today_deadlines = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status!='Completed' AND deadline=CURDATE()"));

// Completion rate
$completion_rate = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;

// Shared all-time activity score and activity counts
$delay_count = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='delay'"));
$study_sessions = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM study_sessions WHERE user_id=$user_id"));
$checkin_count = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='checkin'"));
require_once __DIR__.'/lib/activity_score.php';
$activityScore = app_activity_score($conn, (int)$user_id);
$productivity_score = $activityScore['score'];

$checkinSummary = checkin_dashboard_summary($conn, (int)$user_id);
$has_weekly_checkin = $checkinSummary['weekly_submitted'];
$checkinNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$checkinSunday = checkin_submission_is_sunday($checkinNow);
$nextCheckinDate = checkin_next_available_date($checkinNow, $has_weekly_checkin);
$today_study = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(duration_minutes),0) as total FROM study_sessions WHERE user_id=$user_id AND session_date=CURDATE()"));
$today_study_min = intval($today_study['total'] ?? 0);

// ===== UPCOMING DEADLINES =====
$upcoming_deadlines = mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status!='Completed' AND " . app_task_deadline_upcoming_sql() . " ORDER BY deadline ASC, deadline_time ASC LIMIT 5");

// ===== TODAY'S TASKS =====
$todays_tasks = mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status!='Completed' AND deadline=CURDATE() ORDER BY FIELD($effectivePriority,'High','Medium','Low'), id ASC");

// ===== RECENT ACTIVITY =====
$activities = [];
$t = mysqli_query($conn, "SELECT title, status, deadline, created_at, completed_at FROM tasks WHERE user_id=$user_id AND status='Completed' ORDER BY completed_at DESC, created_at DESC LIMIT 4");
while($row = mysqli_fetch_assoc($t)){
    $activities[] = [
        'icon' => '✅', 'dot' => 'green',
        'text' => "Task Completed", 'sub' => htmlspecialchars($row['title']),
        'time' => $row['completed_at'] ?? $row['created_at'],
    ];
}
$d = mysqli_query($conn, "SELECT reason, created_at FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='delay' ORDER BY created_at DESC LIMIT 3");
while($row = mysqli_fetch_assoc($d)){
    $activities[] = [
        'icon' => '⚠️', 'dot' => 'orange',
        'text' => "Delay Logged", 'sub' => htmlspecialchars(substr($row['reason'] ?: 'No reason given', 0, 60)),
        'time' => $row['created_at'],
    ];
}
$s = mysqli_query($conn, "SELECT duration_minutes, created_at FROM study_sessions WHERE user_id=$user_id ORDER BY created_at DESC LIMIT 3");
while($row = mysqli_fetch_assoc($s)){
    $activities[] = [
        'icon' => '📚', 'dot' => 'blue',
        'text' => "Study Session", 'sub' => htmlspecialchars($row['duration_minutes'] . " min session"),
        'time' => $row['created_at'],
    ];
}
$ci = mysqli_query($conn, "SELECT l.mood, l.notes, l.created_at, r.answers, r.checkin_type FROM procrastiblock_logs l LEFT JOIN checkin_reflections r ON r.log_id=l.id AND r.user_id=l.user_id WHERE l.user_id=$user_id AND l.log_type='checkin' ORDER BY l.created_at DESC LIMIT 3");
while($row = mysqli_fetch_assoc($ci)){
    $activityAnswers = json_decode((string)($row['answers'] ?? ''), true);
    $activityType = checkin_entry_type($row, is_array($activityAnswers) ? $activityAnswers : null);
    $activities[] = [
        'icon' => '📝', 'dot' => 'purple',
        'text' => "Check-in", 'sub' => htmlspecialchars($activityType . ' behavior check-in'),
        'time' => $row['created_at'],
    ];
}
usort($activities, function($a, $b) { return strtotime($b['time']) - strtotime($a['time']); });
$activities = array_slice($activities, 0, 6);

// ===== RECOMMENDATIONS (from existing engine) =====
$recs = [];
$hp_pending = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND $effectivePriority='High' AND status!='Completed'"));
if($overdue_tasks > 0){
    $recs[] = ['icon'=>'🔥','title'=>'Finish Overdue Tasks','desc'=>"You have $overdue_tasks overdue task(s). Start now!",'action'=>'tasks.php'];
}
if($hp_pending > 0){
    $recs[] = ['icon'=>'🎯','title'=>'Start High Priority','desc'=>"You have $hp_pending high-priority task(s) waiting. Tackle the hardest one first.",'action'=>'tasks.php'];
}
if($today_deadlines > 0){
    $recs[] = ['icon'=>'⏰','title'=>'Deadlines Today','desc'=>"You have $today_deadlines deadline(s) due today. Don't wait!",'action'=>'tasks.php'];
}
if(!$has_weekly_checkin){
    $recs[] = $checkinSunday
        ? ['icon'=>'📋','title'=>'Weekly Check-in Available','desc'=>'Complete this week’s reflection and get a personal behavior summary.','action'=>'checkin.php#weeklyCheckin']
        : ['icon'=>'📋','title'=>'Next Weekly Check-in','desc'=>'Your next check-in opens Sunday, ' . $nextCheckinDate->format('M j') . '.','action'=>'checkin.php#weeklyCheckin'];
}
if($study_sessions < 3){
    $recs[] = ['icon'=>'🍅','title'=>'Start a Pomodoro','desc'=>'Set a timer for 25 minutes and focus on one task.','action'=>'study_sessions.php'];
}
if($delay_count > 3){
    $recs[] = ['icon'=>'🛑','title'=>'Reduce Procrastination','desc'=>'You logged ' . $delay_count . ' delays. Try the 2-minute rule: start anything for 2 min.','action'=>'log_delay.php'];
}
if(empty($recs)){
    $recs[] = ['icon'=>'🎉','title'=>'Great Momentum!','desc'=>'No urgent recommendations. Keep up the great work!','action'=>'dashboard.php'];
}
$recs = array_slice($recs, 0, 4);

// ===== TENDENCY (classification) =====
$tendency = ProcrastinationClassifier::classifyUser($conn, $user_id);
$tendency_score = $tendency['score'];
$tendency_label = $tendency['label'];
$tendency_color = $tendency['color'];


// ===== CALENDAR =====
$current_month = date('n');
$current_year = date('Y');
$days_in_month = date('t');
$first_day = date('N', mktime(0,0,0,$current_month,1,$current_year));
$today_num = intval(date('j'));

$month_events = mysqli_query($conn, "SELECT deadline, title FROM tasks WHERE user_id=$user_id AND status!='Completed' AND MONTH(deadline)=$current_month AND YEAR(deadline)=$current_year ORDER BY deadline ASC");

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — ProcrastiTrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/dashboard_modern.css?v=20261114-task-row-full-width">
<link rel="stylesheet" href="assets/css/dashboard-tasks.css?v=20261114-mobile-task-cards">
<link rel="stylesheet" href="assets/css/activity-score.css?v=20261105">
<link rel="stylesheet" href="assets/css/streak.css?v=20261125">
</head>
<body class="dashboard-body">
<?php include 'includes/sidebar.php'; ?>
<?php include 'includes/topbar.php'; ?>

<main class="dashboard-main">
<div class="dashboard-container">

    <!-- Greeting -->
    <section class="greeting-section">
        <div class="greeting-content">
            <?php
            $hour = date('H');
            if($hour < 12) $greeting = 'Morning';
            elseif($hour < 18) $greeting = 'Afternoon';
            else $greeting = 'Evening';
            ?>
            <h1 class="greeting-title">Good <?php echo $greeting; ?>, <?php echo htmlspecialchars($user_name); ?>!</h1>
            <p class="greeting-subtitle">Stay focused and accomplish your goals today.</p>
        </div>
        <div class="greeting-date">
            <div class="date-main"><?php echo date('D, M j'); ?></div>
            <div class="date-sub"><?php echo date('Y'); ?></div>
        </div>
    </section>

    <?php include __DIR__ . '/includes/streak_card.php'; ?>


    <!-- Quick Stats Cards -->
    <section class="stats-grid">
        <article class="stat-card fade-in">
            <div class="stat-header">
                <span class="stat-icon pending">📝</span>
                <span class="stat-label">Pending Tasks</span>
            </div>
            <div class="stat-value"><?php echo $pending_tasks; ?></div>
            <div class="stat-desc"><?php echo $in_progress_tasks; ?> in progress</div>
            <div class="stat-progress">
                <div class="progress-bar"><div class="progress-fill" style="width:<?php echo $pending_tasks > 0 ? min(100, ($pending_tasks / max(1, $total_tasks)) * 100) : 0; ?>%"></div></div>
            </div>
        </article>

        <article class="stat-card fade-in">
            <div class="stat-header">
                <span class="stat-icon success">✅</span>
                <span class="stat-label">Completed</span>
            </div>
            <div class="stat-value"><?php echo $completed_tasks; ?></div>
            <div class="stat-desc"><?php echo $completion_rate; ?>% completion rate</div>
            <div class="stat-progress">
                <div class="progress-bar"><div class="progress-fill success" style="width:<?php echo $completion_rate; ?>%"></div></div>
            </div>
        </article>

        <article class="stat-card fade-in">
            <div class="stat-header">
                <span class="stat-icon warning">⏰</span>
                <span class="stat-label">Today's Deadlines</span>
            </div>
            <div class="stat-value"><?php echo $today_deadlines; ?></div>
            <div class="stat-desc"><?php echo $overdue_tasks; ?> overdue</div>
            <div class="stat-progress">
                <div class="progress-bar"><div class="progress-fill warning" style="width:<?php echo min(100, $today_deadlines * 25); ?>%"></div></div>
            </div>
        </article>

        <article class="stat-card fade-in">
            <div class="stat-header">
                <span class="stat-icon danger">📈</span>
                <span class="stat-label">All-time activity score</span>
            </div>
            <div class="stat-value <?php echo $activityScore['has_activity'] ? '' : 'activity-score-dashboard-empty'; ?>" data-activity-score="<?php echo $productivity_score === null ? 'unavailable' : $productivity_score; ?>"><?php echo $productivity_score === null ? 'Not enough data yet' : $productivity_score.'<small style="font-size:.75rem;letter-spacing:0"> /100</small>'; ?></div>
            <div class="stat-desc"><?php echo $activityScore['has_activity'] ? htmlspecialchars($activityScore['label']) : 'Log a task, session, or check-in to get started.'; ?></div>
            <div class="stat-progress">
                <div class="progress-bar"><div class="progress-fill warning" style="width:<?php echo $productivity_score ?? 0; ?>%"></div></div>
            </div>
        </article>
    </section>

    <div class="dashboard-row dashboard-row-single">
        <!-- Task Overview Table -->
        <section class="dashboard-panel dashboard-task-overview">
            <div class="panel-header">
                <h3 class="panel-title">📝 Task Overview</h3>
                <a href="tasks.php" class="view-all-link">View all</a>
            </div>
            <div class="table-container">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Task Name</th>
                            <th>Deadline</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th class="action-col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $task_list = mysqli_query($conn, "SELECT *, $effectivePriority AS displayed_priority FROM tasks WHERE user_id=$user_id AND status!='Completed' ORDER BY FIELD(displayed_priority,'High','Medium','Low'), deadline ASC, id ASC LIMIT 10");
                        while($task = mysqli_fetch_assoc($task_list)):
                            $status = $task['status'];
                            $is_overdue = $status !== 'Completed' && app_task_is_overdue($task['deadline'], $task['deadline_time']);
                            $display_status = $status;
                            $status_class = $status === 'Completed' ? 'success' : ($status === 'In Progress' ? 'info' : 'pending');
                            $priority_class = strtolower($task['displayed_priority']);
                        ?>
                        <tr class="fade-in">
                            <td><span class="task-name"><?php echo htmlspecialchars($task['title']); ?></span></td>
                            <td><div class="dashboard-due-date"><?php if ($is_overdue): ?><span class="dashboard-overdue">Overdue</span><?php elseif ($task['deadline'] === $today): ?><span class="dashboard-due-today">Due today</span><?php endif; ?><span class="task-deadline"><?php echo htmlspecialchars(app_task_deadline_display($task['deadline'], $task['deadline_time']), ENT_QUOTES, 'UTF-8'); ?></span></div></td>
                            <td><span class="priority-badge priority-<?php echo $priority_class; ?>"><?php echo htmlspecialchars($task['displayed_priority']); ?></span></td>
                            <td><span class="status-badge status-<?php echo $status_class; ?>"><?php echo htmlspecialchars($display_status); ?></span></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="view_task.php?id=<?php echo $task['id']; ?>" class="action-btn" title="View">👁️</a>
                                    <a href="edit_task.php?id=<?php echo $task['id']; ?>" class="action-btn" title="Edit">✏️</a>
                                    <form method="post" action="complete_task.php" class="dashboard-complete-form"><input type="hidden" name="id" value="<?php echo (int)$task['id']; ?>"><?php app_csrf_field(); ?><button type="submit" class="action-btn success" title="Complete" aria-label="Complete <?php echo htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?>"><i class="app-icon" data-lucide="circle-check" aria-hidden="true"></i></button></form>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <?php if(mysqli_num_rows($task_list) === 0): ?>
                <div class="empty-table">
                    <p>No active tasks. <a href="add_task.php">Create your first task</a>.</p>
                </div>
                <?php endif; ?>
            </div>
        </section>

    </div>

    <div class="dashboard-row">
        <!-- Recommendations -->
        <section class="dashboard-card fade-in">
            <div class="panel-header">
                <h3 class="panel-title">💡 Recommendations</h3>
                <span class="rec-count"><?php echo count($recs); ?> active</span>
            </div>
            <div class="rec-grid">
                <?php foreach($recs as $rec): ?>
                <div class="rec-card">
                    <div class="rec-icon"><?php echo $rec['icon']; ?></div>
                    <div class="rec-content">
                        <h4 class="rec-title"><?php echo $rec['title']; ?></h4>
                        <p class="rec-desc"><?php echo $rec['desc']; ?></p>
                    </div>
                    <a href="<?php echo $rec['action']; ?>" class="rec-btn">Start</a>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Recent Activities -->
        <section class="dashboard-card fade-in">
            <div class="panel-header">
                <h3 class="panel-title">🕒 Recent Activity</h3>
            </div>
            <ul class="activity-list">
                <?php if(!empty($activities)): ?>
                    <?php foreach($activities as $act): ?>
                    <li class="activity-item">
                        <span class="activity-icon"><?php echo $act['icon']; ?></span>
                        <div class="activity-content">
                            <span class="activity-text"><?php echo $act['text']; ?></span>
                            <span class="activity-sub"><?php echo $act['sub']; ?></span>
                        </div>
                        <span class="activity-time"><?php echo date('g:i A', strtotime($act['time'])); ?></span>
                    </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li class="empty-state">No recent activity. Start by creating a task or logging a check-in.</li>
                <?php endif; ?>
            </ul>
        </section>
    </div>


</div>
</main>

<script>


// ===== FADE IN ANIMATION =====
document.addEventListener('DOMContentLoaded', function(){
    var elements = document.querySelectorAll('.fade-in');
    elements.forEach(function(el, i){
        el.style.transitionDelay = (i * 0.05) + 's';
    });
});
</script>
</body>
</html>
