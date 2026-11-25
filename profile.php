<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/csrf.php';
$user_id = (int)$_SESSION['user_id'];
// ensure profile-related columns exist so settings can be saved
$expectedCols = [
    'university' => "VARCHAR(255) DEFAULT NULL",
    'major' => "VARCHAR(255) DEFAULT NULL",
    'year_of_study' => "VARCHAR(100) DEFAULT NULL",
    'work_start' => "VARCHAR(10) DEFAULT '09:00'",
    'work_end' => "VARCHAR(10) DEFAULT '17:00'",
    'notifications_task_reminders' => "TINYINT(1) DEFAULT 1",
    'pomodoro_interval' => "INT DEFAULT 25",
    'break_duration' => "INT DEFAULT 5",
    'target_hours' => "INT DEFAULT 2",
    'deep_work_mode' => "TINYINT(1) DEFAULT 0"
];
$colsRes = mysqli_query($conn, "SHOW COLUMNS FROM users");
$existingCols = [];
if($colsRes){
    while($col = mysqli_fetch_assoc($colsRes)){
        $existingCols[$col['Field']] = true;
    }
}
foreach($expectedCols as $field => $definition){
    if(!isset($existingCols[$field])){
        mysqli_query($conn, "ALTER TABLE users ADD COLUMN `$field` $definition");
    }
}


$user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id=$user_id"));
if (!$user) { header('Location: logout.php'); exit(); }
$years = ['First Year (Year 1)', 'Second Year (Year 2)', 'Third Year (Year 3)', 'Fourth Year (Year 4)', 'Fifth Year or above', 'Graduate student'];
if (!empty($user['year_of_study']) && !in_array($user['year_of_study'], $years, true)) $years[] = $user['year_of_study'];
$values = [];
foreach (['fullname', 'university', 'major', 'year_of_study'] as $field) $values[$field] = $user[$field] ?? '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_csrf_require();
    foreach ($values as $field => $value) $values[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
    if ($values['fullname'] === '') $error = 'Please enter your full name.';
    foreach (['fullname', 'university', 'major'] as $field) {
        if (mb_strlen($values[$field]) > 255) $error = 'Keep your name, university, and program within 255 characters each.';
    }
    if ($values['year_of_study'] !== '' && !in_array($values['year_of_study'], $years, true)) $error = 'Choose a year of study from the list.';
    if ($error === '') {
        try {
            $stmt = $conn->prepare('UPDATE users SET fullname=?, university=?, major=?, year_of_study=? WHERE id=?');
            $stmt->bind_param('ssssi', $values['fullname'], $values['university'], $values['major'], $values['year_of_study'], $user_id);
            if (!$stmt->execute()) throw new RuntimeException('Profile update failed');
            $stmt->close();
            $_SESSION['fullname'] = $values['fullname'];
            $_SESSION['profile_saved'] = true;
            header('Location: profile.php'); exit();
        } catch (mysqli_sql_exception | RuntimeException $e) {
            error_log('Profile update failed: ' . $e->getMessage());
            $error = 'Could not save your profile. Your changes are still here; please try again.';
        }
    }
}
$saved = !empty($_SESSION['profile_saved']); unset($_SESSION['profile_saved']);
$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);
function profile_text($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
$name = trim($user['fullname'] ?? '') ?: 'Student';
$words = preg_split('/\s+/u', $name);
$initials = mb_strtoupper(mb_substr($words[0], 0, 1) . (count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
$taskStats = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) total, COALESCE(SUM(status='Completed'),0) completed FROM tasks WHERE user_id=$user_id"));
$studyStats = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) sessions, COALESCE(SUM(duration_minutes),0) minutes FROM study_sessions WHERE user_id=$user_id"));
$checkins = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) total FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='checkin'"))['total'];
$recent = [];
$activityQueries = [
    "SELECT 'Study session' kind, COALESCE(NULLIF(subject,''),'General study') detail, created_at happened_at, 'study_sessions.php' link FROM study_sessions WHERE user_id=$user_id ORDER BY created_at DESC, id DESC LIMIT 4",
    "SELECT 'Task completed' kind, title detail, completed_at happened_at, CONCAT('view_task.php?id=',id) link FROM tasks WHERE user_id=$user_id AND status='Completed' AND completed_at IS NOT NULL ORDER BY completed_at DESC, id DESC LIMIT 4",
    "SELECT 'Check-in' kind, CASE WHEN notes LIKE 'Weekly:%' THEN 'Weekly reflection' WHEN notes LIKE 'Daily:%' THEN 'Daily reflection' ELSE 'Personal reflection' END detail, created_at happened_at, 'checkin.php' link FROM procrastiblock_logs WHERE user_id=$user_id AND log_type='checkin' ORDER BY created_at DESC, id DESC LIMIT 4"
];
foreach ($activityQueries as $query) $recent = array_merge($recent, mysqli_query($conn, $query)->fetch_all(MYSQLI_ASSOC));
usort($recent, fn($a, $b) => strcmp($b['happened_at'], $a['happened_at']));
$recent = array_slice($recent, 0, 4);
$profileFields = array_filter($values, fn($value) => trim($value) !== '');
$profilePercent = (int)round(count($profileFields) / 4 * 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Profile | ProcrastiTrack</title>
<link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/student.css">
<link rel="stylesheet" href="assets/css/profile-completed.css?v=20261104">
</head>
<body class="student-body student-profile">
<?php include 'includes/sidebar.php'; ?>
<style>
.student-profile{--profile-green:#547c63;--profile-ink:#263c2f;--profile-muted:#778279;--profile-line:#e5ece6;--profile-card:#fff;--profile-soft:#f5f8f4}
.student-profile .s-inner{max-width:1240px}.student-profile .s-head{margin-bottom:26px}
.pf-hero{border:1px solid var(--profile-line);border-radius:26px;background:var(--profile-card);overflow:hidden;margin-bottom:24px}
.pf-cover{height:88px;background:linear-gradient(110deg,#dce9da,#eef3e6 65%,#dce8de);position:relative;overflow:hidden}.pf-cover:after{content:'';position:absolute;width:260px;height:260px;border:1px solid #fff9;border-radius:50%;right:8%;top:-180px;box-shadow:0 0 0 35px #ffffff30,0 0 0 70px #ffffff20}
.pf-identity{padding:0 30px 26px;display:flex;align-items:flex-end;gap:20px;position:relative;margin-top:-30px;flex-wrap:wrap}
.pf-avatar{width:96px;height:96px;border:6px solid var(--profile-card);border-radius:28px;background:#6d8f7a;color:#fff;font-size:1.9rem;font-weight:750;display:grid;place-items:center;flex-shrink:0;box-shadow:0 5px 16px #203e2210}
.pf-name{flex:1;min-width:180px;padding-top:42px}.pf-name h2{font-size:1.5rem;letter-spacing:-.025em;line-height:1.3;color:var(--profile-ink);margin:0 0 4px;overflow-wrap:anywhere}.pf-name p{font-size:.85rem;color:var(--profile-muted);overflow-wrap:anywhere}
.pf-badge{display:inline-flex;align-items:center;gap:6px;border-radius:99px;background:#edf4ee;color:#587b62;font-size:.75rem;font-weight:650;padding:6px 12px}
.pf-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));border-top:1px solid var(--profile-line);padding:20px 10px;margin:0}.pf-stats>div{padding:0 24px;border-right:1px solid var(--profile-line)}.pf-stats>div:last-child{border:0}.pf-stats dt{font-size:.78rem;color:var(--profile-muted)}.pf-stats dd{margin:4px 0 0;font-size:1.55rem;font-weight:700;color:var(--profile-ink)}.pf-stats dd small{font-size:.78rem;font-weight:400;color:var(--profile-muted)}
.pf-layout{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(280px,1fr);gap:24px;align-items:start}.pf-column{display:grid;gap:24px;min-width:0}.pf-panel{padding:26px;background:var(--profile-card);border:1px solid var(--profile-line);border-radius:22px}.pf-panel h3{font-size:1.05rem;margin:0;color:var(--profile-ink)}.pf-copy{font-size:.84rem;color:var(--profile-muted);line-height:1.6;margin:6px 0 22px}
.pf-form{display:grid;grid-template-columns:1fr 1fr;gap:20px}.pf-field{display:grid;gap:7px;min-width:0}.pf-field label{font-size:.8rem;font-weight:600;color:var(--profile-ink)}.pf-field input,.pf-field select{width:100%;min-width:0;padding:12px 14px;border:1px solid var(--profile-line);border-radius:11px;background:var(--profile-soft);color:var(--profile-ink);font:inherit;font-size:.87rem}.pf-field input:focus,.pf-field select:focus{outline:3px solid #6d8f7a20;border-color:#89a692}.pf-full{grid-column:1/-1}.pf-field small{color:var(--profile-muted);font-size:.75rem}.pf-actions{display:flex;justify-content:flex-end;gap:10px;align-items:center;border-top:1px solid var(--profile-line);padding-top:20px;margin-top:2px}.pf-actions .s-btn{min-height:42px;padding:10px 18px;font-size:.82rem}
.pf-progress{display:flex;align-items:center;justify-content:space-between;font-size:.8rem;margin:20px 0 8px;color:var(--profile-ink)}.pf-meter{width:100%;height:7px;accent-color:#6d8f7a;display:block}.pf-meter::-webkit-progress-bar{background:#eaf0e9;border-radius:8px}.pf-meter::-webkit-progress-value{background:#6d8f7a;border-radius:8px}
.pf-links{display:grid;margin-top:14px}.pf-link{display:flex;align-items:center;gap:12px;padding:15px 0;border-bottom:1px solid var(--profile-line);text-decoration:none}.pf-link:last-child{border:0;padding-bottom:0}.pf-link:hover strong{color:var(--profile-green)}.pf-link-icon{display:grid;place-items:center;background:var(--profile-soft);color:var(--profile-green);border-radius:12px;width:40px;height:40px;flex-shrink:0}.pf-link-icon .app-icon{width:18px;height:18px}.pf-link>div{flex:1}.pf-link strong{display:block;font-size:.83rem;color:var(--profile-ink)}.pf-link small{display:block;color:var(--profile-muted);font-size:.75rem;margin-top:3px}.pf-arrow{color:#92a297}
.pf-activity{list-style:none;padding:0;margin:18px 0 0}.pf-activity li{display:flex;gap:12px;padding:13px 0;border-bottom:1px solid var(--profile-line)}.pf-activity li:last-child{border:0;padding-bottom:0}.pf-dot{width:8px;height:8px;border-radius:50%;background:#91ad99;margin-top:7px;flex-shrink:0}.pf-activity a{min-width:0;flex:1}.pf-activity strong{font-size:.82rem;color:var(--profile-ink)}.pf-activity p{font-size:.8rem;color:var(--profile-muted);overflow-wrap:anywhere}.pf-activity time{font-size:.72rem;color:var(--profile-muted);display:block;margin-top:5px}.pf-empty{padding:20px 0;color:var(--profile-muted);font-size:.85rem}.pf-notice{padding:14px 18px;border-radius:12px;background:#eaf4ec;color:#386045;margin-bottom:20px;font-size:.87rem}.pf-notice.error{background:#fff0ed;color:#9b4535}
body.dark-mode.student-profile{--profile-ink:#e0eae3;--profile-muted:#a6b6aa;--profile-line:#38483d;--profile-card:#202c24;--profile-soft:#27362c}.dark-mode .pf-cover{background:linear-gradient(110deg,#344e3e,#465741)}.dark-mode .pf-badge{background:#344d3c;color:#c3d9c9}
@media(max-width:850px){.pf-layout{grid-template-columns:1fr}.pf-identity{padding:0 22px 22px}.pf-panel{padding:22px}}
@media(max-width:520px){.pf-form{grid-template-columns:1fr}.pf-identity{gap:12px}.pf-name h2{font-size:1.25rem}.pf-avatar{width:80px;height:80px;font-size:1.5rem;border-radius:23px}.pf-name{padding-top:38px;min-width:140px}.pf-stats>div{padding:0 10px}.pf-stats dd{font-size:1.2rem}.pf-stats dt{font-size:.69rem}.pf-stats dd small{display:block}.pf-actions{justify-content:stretch}.pf-actions .s-btn{flex:1;justify-content:center}}

#academic-details{scroll-margin-top:90px}
.pf-tabs{display:flex;gap:6px;width:fit-content;max-width:100%;margin:0 0 22px;padding:5px;border:1px solid var(--profile-line);border-radius:14px;background:var(--profile-card)}
.pf-tab{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;padding:9px 17px;border:0;border-radius:10px;background:transparent;color:var(--profile-muted);font-family:inherit;font-size:.8rem;font-weight:600;line-height:1.2;cursor:pointer;transition:background .18s ease,color .18s ease,box-shadow .18s ease}
.pf-tab[aria-selected="true"]{background:var(--profile-soft);color:var(--profile-green);box-shadow:0 2px 8px #233b2a0a}
.pf-tab:focus-visible{outline:3px solid #6d8f7a35;outline-offset:2px}
.pf-tab-count{display:inline-grid;place-items:center;min-width:22px;height:22px;padding:0 6px;border-radius:7px;background:var(--profile-card);color:var(--profile-muted);font-size:.68rem;font-weight:700;font-variant-numeric:tabular-nums}
.pf-tab-panel[hidden]{display:none!important}.pf-tab-panel{min-width:0}
.pf-tab-panel>.pf-completions{margin-bottom:24px}.pf-tab-panel>.pf-history{margin-top:0}
.pf-academic-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:18px}
.pf-academic-heading .s-btn{flex-shrink:0;font-size:.8rem;gap:8px}
.pf-academic-heading .app-icon{width:16px;height:16px}
.pf-academic-summary{display:grid;grid-template-columns:1fr 1fr;gap:0 24px;margin:0;border-top:1px solid var(--profile-line)}
.pf-academic-summary>div{padding:20px 0;border-bottom:1px solid var(--profile-line);min-width:0}
.pf-academic-summary dt{font-size:.75rem;color:var(--profile-muted);margin-bottom:8px}
.pf-academic-summary dd{font-size:.95rem;font-weight:600;color:var(--profile-ink);margin:0;overflow-wrap:anywhere;line-height:1.6}
.pf-unset{font-weight:400;color:var(--profile-muted);font-style:italic}
@media(max-width:550px){.pf-academic-heading{flex-wrap:wrap}.pf-academic-heading .pf-copy{margin-bottom:0}.pf-academic-summary{grid-template-columns:1fr;margin-top:20px}}
</style>
<main class="s-main"><div class="s-inner">
<header class="s-head"><div><div class="s-label">Your account</div><h1>My profile</h1><p>Your academic details and study journey, in one place.</p></div><div class="s-head-actions"><a class="s-btn" href="dashboard.php">Back to dashboard</a></div></header>
<?php if ($saved): ?><div class="pf-notice" role="status">Your profile has been updated.</div><?php endif; ?>
<?php if ($flashMessage): ?><div class="pf-notice<?php echo $flashType === 'error' ? ' error' : ''; ?>" role="status"><?php echo profile_text($flashMessage); ?></div><?php endif; ?>
<?php if ($error): ?><div class="pf-notice error" role="alert"><?php echo profile_text($error); ?></div><?php endif; ?>
<section class="pf-hero" aria-label="Student profile">
<div class="pf-cover" aria-hidden="true"></div>
<div class="pf-identity"><div class="pf-avatar" aria-hidden="true"><?php echo profile_text($initials); ?></div><div class="pf-name"><h2><?php echo profile_text($name); ?></h2><p><?php echo profile_text($user['email'] ?? ''); ?></p></div><span class="pf-badge"><i class="app-icon" data-lucide="graduation-cap" aria-hidden="true"></i>Student</span></div>
<dl class="pf-stats"><div><dt><a href="#completed-tasks">Tasks completed &darr;</a></dt><dd><?php echo (int)$taskStats['completed']; ?> <small>of <?php echo (int)$taskStats['total']; ?> tasks</small></dd></div><div><dt>Study time</dt><dd><?php echo round($studyStats['minutes'] / 60, 1); ?> <small>hours logged</small></dd></div><div><dt>Check-ins</dt><dd><?php echo $checkins; ?> <small>reflections saved</small></dd></div></dl>
</section>
<nav class="pf-tabs" role="tablist" aria-label="Profile sections">
<button class="pf-tab" id="profile-tab-overview" type="button" role="tab" aria-selected="true" aria-controls="profile-panel-overview">Overview</button>
<button class="pf-tab" id="profile-tab-history" type="button" role="tab" aria-selected="false" aria-controls="profile-panel-history" tabindex="-1">History <span class="pf-tab-count"><?php echo (int)$taskStats['completed'] + $checkins; ?></span></button>
</nav>
<section class="pf-tab-panel" id="profile-panel-overview" role="tabpanel" aria-labelledby="profile-tab-overview" tabindex="0">
<div class="pf-layout"><div class="pf-column">
<?php $editingProfile = $error !== '' || (($_GET['edit'] ?? '') === '1'); ?>
<section class="pf-panel" id="academic-details" aria-labelledby="academicHeading">
<div class="pf-academic-heading"><div><h3 id="academicHeading">Academic details</h3><p class="pf-copy"><?php echo $editingProfile ? 'Update your academic details. Only your full name is required.' : 'Your saved academic information.'; ?></p></div>
<?php if (!$editingProfile): ?><a class="s-btn" href="profile.php?edit=1#academic-details"><i class="app-icon" data-lucide="pencil" aria-hidden="true"></i>Edit profile</a><?php endif; ?></div>
<?php if (!$editingProfile): ?>
<dl class="pf-academic-summary">
<?php foreach (['fullname'=>'Full name','university'=>'University or school','major'=>'Program or major','year_of_study'=>'Year of study'] as $field=>$label): ?>
<div><dt><?php echo $label; ?></dt><dd><?php echo trim($values[$field]) !== '' ? profile_text($values[$field]) : '<span class="pf-unset">Not added yet</span>'; ?></dd></div>
<?php endforeach; ?>
</dl>
<?php else: ?>
<form method="post" action="profile.php" class="pf-form"><?php app_csrf_field(); ?>
<div class="pf-field pf-full"><label for="profileName">Full name</label><input id="profileName" name="fullname" autocomplete="name" maxlength="255" required value="<?php echo profile_text($values['fullname']); ?>"></div>
<div class="pf-field pf-full"><label for="profileUniversity">University or school</label><input id="profileUniversity" name="university" autocomplete="organization" maxlength="255" placeholder="Where do you study?" value="<?php echo profile_text($values['university']); ?>"></div>
<div class="pf-field"><label for="profileMajor">Program or major</label><input id="profileMajor" name="major" maxlength="255" placeholder="e.g., BS Information Technology" value="<?php echo profile_text($values['major']); ?>"></div>
<div class="pf-field"><label for="profileYear">Year of study</label><select id="profileYear" name="year_of_study"><option value="">Select your year</option><?php foreach ($years as $year): ?><option value="<?php echo profile_text($year); ?>" <?php echo $values['year_of_study'] === $year ? 'selected' : ''; ?>><?php echo profile_text($year); ?></option><?php endforeach; ?></select></div>
<div class="pf-full pf-actions"><a href="profile.php" class="s-btn">Cancel</a><button type="submit" class="s-btn primary">Save profile</button></div>
</form>
<?php endif; ?></section>
<section class="pf-panel" aria-labelledby="activityHeading"><h3 id="activityHeading">Recent activity</h3><p class="pf-copy">Your latest recorded progress across ProcrastiTrack.</p>
<?php if ($recent): ?><ul class="pf-activity"><?php foreach ($recent as $entry): ?><li><span class="pf-dot" aria-hidden="true"></span><a href="<?php echo profile_text($entry['link']); ?>"><strong><?php echo profile_text($entry['kind']); ?></strong><p><?php echo profile_text($entry['detail']); ?></p><time datetime="<?php echo profile_text($entry['happened_at']); ?>"><?php echo date('M j, Y - g:i A', strtotime($entry['happened_at'])); ?></time></a></li><?php endforeach; ?></ul><?php else: ?><div class="pf-empty">Your journey starts here. <a href="add_task.php">Add your first task</a> or <a href="checkin.php">write a check-in</a> to see your activity.</div><?php endif; ?>
</section></div>
<aside class="pf-column" aria-label="Profile and account tools">
<section class="pf-panel"><div class="s-label">A little more about you</div><h3>Your academic profile</h3><div class="pf-progress"><span><?php echo count($profileFields); ?> of 4 details added</span><strong><?php echo $profilePercent; ?>%</strong></div><progress class="pf-meter" max="100" value="<?php echo $profilePercent; ?>" aria-label="Academic profile completeness"><?php echo $profilePercent; ?>%</progress><p class="pf-copy" style="margin-bottom:0"><?php echo $profilePercent === 100 ? 'Your academic details are complete. Keep them up to date as you move through your studies.' : 'Add your school, program, and year whenever you are ready.'; ?></p></section>
<section class="pf-panel"><h3>Your study space</h3><div class="pf-links">
<a class="pf-link" href="tasks.php"><span class="pf-link-icon"><i class="app-icon" data-lucide="list-checks" aria-hidden="true"></i></span><div><strong>My tasks</strong><small>Assignments, priorities, and deadlines</small></div><span class="pf-arrow" aria-hidden="true">&rarr;</span></a>
<a class="pf-link" href="study_sessions.php"><span class="pf-link-icon"><i class="app-icon" data-lucide="timer" aria-hidden="true"></i></span><div><strong>Study sessions</strong><small><?php echo (int)$studyStats['sessions']; ?> sessions recorded</small></div><span class="pf-arrow" aria-hidden="true">&rarr;</span></a>
<a class="pf-link" href="reports.php"><span class="pf-link-icon"><i class="app-icon" data-lucide="chart-no-axes-combined" aria-hidden="true"></i></span><div><strong>Weekly progress</strong><small>Reflect on your study activity</small></div><span class="pf-arrow" aria-hidden="true">&rarr;</span></a>
 </div></section>
<section class="pf-panel"><h3>Account &amp; privacy</h3><div class="pf-links">
<a class="pf-link" href="settings.php"><span class="pf-link-icon"><i class="app-icon" data-lucide="settings-2" aria-hidden="true"></i></span><div><strong>Account settings</strong><small>Password, appearance, and notifications</small></div><span class="pf-arrow" aria-hidden="true">&rarr;</span></a>
<a class="pf-link" href="data_controls.php"><span class="pf-link-icon"><i class="app-icon" data-lucide="shield-check" aria-hidden="true"></i></span><div><strong>Privacy &amp; data</strong><small>Manage your saved information</small></div><span class="pf-arrow" aria-hidden="true">&rarr;</span></a>
</div></section></aside></div>
</section>
<section class="pf-tab-panel" id="profile-panel-history" role="tabpanel" aria-labelledby="profile-tab-history" tabindex="0" hidden>
<?php include __DIR__.'/includes/profile_completed_tasks.php'; ?>
<?php include __DIR__.'/includes/profile_checkin_history.php'; ?>
</section>
</div></main>
<script>
(() => {
    const tabs = [...document.querySelectorAll('.pf-tabs [role="tab"]')];
    const panels = [...document.querySelectorAll('.pf-tab-panel[role="tabpanel"]')];
    if (!tabs.length || !panels.length) return;
    const selectTab = (name, moveFocus = false, updateUrl = true) => {
        tabs.forEach(tab => {
            const active = tab.id === 'profile-tab-' + name;
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            if (active && moveFocus) tab.focus();
        });
        panels.forEach(panel => panel.hidden = panel.id !== 'profile-panel-' + name);
        if (updateUrl) {
            const url = new URL(window.location.href);
            if (name === 'history') url.searchParams.set('tab', 'history');
            else url.searchParams.delete('tab');
            url.hash = '';
            window.history.replaceState({}, '', url);
        }
    };
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => selectTab(tab.id.replace('profile-tab-', '')));
        tab.addEventListener('keydown', event => {
            let next;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = tabs.length - 1;
            if (next !== undefined) { event.preventDefault(); selectTab(tabs[next].id.replace('profile-tab-', ''), true); }
        });
    });
    document.addEventListener('click', event => {
        const link = event.target.closest('a[href="#completed-tasks"],a[href="#checkin-history"]');
        if (!link) return;
        event.preventDefault();
        selectTab('history', false, false);
        window.history.replaceState({}, '', link.hash);
        document.querySelector(link.hash)?.scrollIntoView({behavior: 'smooth', block: 'start'});
    });
    const params = new URLSearchParams(window.location.search);
    const showHistory = params.get('tab') === 'history' || params.has('completed_page') || params.has('checkin_page') || ['#completed-tasks', '#checkin-history'].includes(window.location.hash);
    selectTab(showHistory ? 'history' : 'overview', false, false);
    if (['#completed-tasks', '#checkin-history'].includes(window.location.hash)) {
        requestAnimationFrame(() => document.querySelector(window.location.hash)?.scrollIntoView({block: 'start'}));
    }
})();
</script>
</body></html>
