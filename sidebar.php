<link rel="stylesheet" href="assets/css/navigation.css?v=20261119-sidebar-labels">
<link rel="stylesheet" href="assets/css/mobile.css?v=20261114-task-cards-v2">
<script defer src="assets/js/mobile-navigation.js?v=20261104"></script>
<script defer src="assets/js/auto-dismiss-notices.js?v=20261124"></script>
<?php $currentPage = basename($_SERVER['PHP_SELF']);
$unread_count = 0;
$dark_mode_enabled = false;
if(session_status() !== PHP_SESSION_ACTIVE){ @session_start(); }
if(isset($_SESSION['user_id'])){
    include_once __DIR__ . '/../config/database.php';
    $uid = intval($_SESSION['user_id']);
    require_once __DIR__ . '/../lib/streak.php';
    app_record_visit($conn, $uid);
    $user_settings = mysqli_fetch_assoc(mysqli_query($conn, "SELECT dark_mode FROM users WHERE id=$uid"));
    $dark_mode_enabled = !empty($user_settings['dark_mode']);
    $unread_count = 0;
    $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
    if($tbl && mysqli_num_rows($tbl) > 0){
        $res = mysqli_query($conn, "SELECT COUNT(*) as c FROM notifications WHERE user_id=$uid AND read_at IS NULL");
        if($res){ $r = mysqli_fetch_assoc($res); $unread_count = intval($r['c']); }
    }
}
?>
<div class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-mark" aria-hidden="true">P</div>
        <div class="brand-copy">
            <h2>ProcrastiTrack</h2>
            <p>Academic focus</p>
        </div>
    </div>

    <nav class="sidebar-nav">
        <span class="nav-section-label">Workspace</span>
        <a href="dashboard.php" data-label="Dashboard" aria-label="Dashboard" class="<?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>"><i class="app-icon" data-lucide="house" aria-hidden="true"></i>Dashboard</a>
        <a href="tasks.php" data-label="My Tasks" aria-label="My Tasks" class="<?php echo $currentPage === 'tasks.php' ? 'active' : ''; ?>"><i class="app-icon" data-lucide="list-checks" aria-hidden="true"></i>My Tasks</a>
        <a href="calendar.php" data-label="Calendar" aria-label="Calendar" class="<?php echo $currentPage === 'calendar.php' ? 'active' : ''; ?>"><i class="app-icon" data-lucide="calendar-days" aria-hidden="true"></i>Calendar</a>
        <a href="study_sessions.php" data-label="Study Sessions" aria-label="Study Sessions" class="<?php echo $currentPage === 'study_sessions.php' ? 'active' : ''; ?>"><i class="app-icon" data-lucide="timer" aria-hidden="true"></i>Study Sessions</a>
        <a href="charts.php" data-label="Productivity" aria-label="Productivity" class="<?php echo $currentPage === 'charts.php' ? 'active' : ''; ?>"><i class="app-icon" data-lucide="trending-up" aria-hidden="true"></i>Productivity</a>
        <a href="patterns.php" data-label="Insights" aria-label="Insights" class="<?php echo in_array($currentPage, ['reports.php', 'patterns.php', 'tendency.php'], true) ? 'active' : ''; ?>"><i class="app-icon" data-lucide="chart-no-axes-combined" aria-hidden="true"></i>Insights</a>
        <a href="study_coach.php" data-label="Study Coach" aria-label="Study Coach" class="<?php echo in_array($currentPage, ['study_coach.php', 'student_recommendations.php', 'recommendations.php'], true) ? 'active' : ''; ?>"><i class="app-icon" data-lucide="sparkles" aria-hidden="true"></i>Study Coach</a>
        <a href="checkin.php" data-label="Check-ins" aria-label="Check-ins" class="<?php echo $currentPage === 'checkin.php' ? 'active' : ''; ?>"><i class="app-icon" data-lucide="clipboard-check" aria-hidden="true"></i>Check-ins</a>
        <span class="nav-section-label secondary">Account</span>
        <button type="button" class="mobile-more" id="mobileMore" aria-label="More navigation" aria-haspopup="dialog" aria-controls="mobileNavigation" data-label="More"><i class="app-icon" data-lucide="grid-2x2" aria-hidden="true"></i><span>More</span></button>
    </nav>
</div>
<dialog id="mobileNavigation" class="mobile-navigation" aria-labelledby="mobileNavigationTitle" data-i18n>
<header><div><small>YOUR STUDY SPACE</small><h2 id="mobileNavigationTitle">Where to next?</h2></div><button type="button" id="mobileNavigationClose" aria-label="Close navigation">&times;</button></header>
<nav class="mobile-navigation-links" aria-label="All student pages"></nav>
<footer><a href="profile.php">My profile</a><a href="settings.php">Settings</a><a href="logout.php">Sign out</a></footer>
</dialog>
<script defer src="https://unpkg.com/lucide@1.47.0/dist/umd/lucide.min.js"></script>
<script defer src="assets/js/app-icons.js"></script>
<script>
    if(<?php echo $dark_mode_enabled ? 'true' : 'false'; ?>){
        document.body.classList.add('dark-mode');
    }
</script>
<?php
$pagesWithTopbar = ['analytics.php', 'calendar.php', 'dashboard.php', 'settings.php', 'view_task.php'];
if(!in_array($currentPage, $pagesWithTopbar, true)){
    include __DIR__ . '/topbar.php';
}
?>
    <script>
    // Poll unread count and update badge in sidebar
    function __pt_updateSidebarBadge(count){
        var bell = document.getElementById('notificationBell');
        var b = bell ? bell.querySelector('.topbar-badge') : document.getElementById('notif-badge');
        if (!b && bell && count > 0) { b = document.createElement('span'); b.className = 'topbar-badge'; bell.appendChild(b); }
        if(!b) return;
        if(count > 0){
            b.textContent = count;
            b.style.visibility = 'visible';
            b.style.display = 'inline-flex';
        } else {
            b.style.visibility = 'hidden';
            b.style.display = 'none';
        }
    }
    function __pt_poll(){
        fetch('/Procrastitrack/unread_count.php', {headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(r => r.json())
            .then(function(j){ 
                __pt_updateSidebarBadge(j.count); 
            })
            .catch(()=>{});
    }
    // Initial load
    __pt_updateSidebarBadge(<?php echo $unread_count; ?>);
    // Poll every 5 seconds for updates
    setInterval(__pt_poll, 5000);
    </script>
