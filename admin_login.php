<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../csrf.php';
if (admin_is_logged_in()) { header('Location: dashboard.php'); exit(); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_csrf_require();
    if (admin_login(trim($_POST['admin_identifier'] ?? ''), $_POST['admin_password'] ?? '')) {
        header('Location: dashboard.php'); exit();
    }
    $error = 'Invalid credentials or inactive account.';
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Sign In | ProcrastiTrack</title><link rel="stylesheet" href="admin.css"><link rel="stylesheet" href="login.css?v=20261104"></head>
<body class="admin-login-page">
<div class="login-top"><a href="../landing.html" class="login-brand"><span class="login-logo" aria-hidden="true">P<span></span></span><span><strong>ProcrastiTrack</strong><small>ADMIN WORKSPACE</small></span></a><a class="login-back" href="../landing.html">&larr; Back to website</a></div>
<main class="login-shell">
<section class="login-story" aria-labelledby="storyTitle"><span class="story-tag"><span></span> BUILT FOR STUDENT SUPPORT</span><div class="story-copy"><p class="story-eyebrow">A LITTLE CLARITY GOES A LONG WAY.</p><h2 id="storyTitle">See the patterns.<br>Support the <em>person.</em></h2><p>A thoughtful space to understand student progress and turn everyday activity into meaningful support.</p></div>
<div class="story-art" aria-hidden="true"><div class="art-orbit orbit-one"></div><div class="art-orbit orbit-two"></div><div class="art-orbit orbit-three"></div><div class="art-center"><svg viewBox="0 0 64 64" fill="none"><path d="M15 37 27 25l9 9 14-17" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><path d="M40 17h10v10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg></div><span class="art-node node-one"></span><span class="art-node node-two"></span><span class="art-node node-three"></span></div>
<div class="story-footer"><span class="story-spark" aria-hidden="true">&#10022;</span><div><strong>Better insight. More thoughtful support.</strong><span>One student. One step. One day at a time.</span></div></div></section>
<section class="login-card" aria-labelledby="loginTitle"><div class="login-form-heading"><span class="login-access"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg> ADMIN ACCESS</span><h1 id="loginTitle">Welcome back<span>.</span></h1><p class="login-copy">Sign in to your student support workspace.</p></div>
<?php if ($error): ?><div class="alert error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<form method="post" autocomplete="off"><?php app_csrf_field(); ?>
<label for="adminIdentifier">Username or admin email</label><input id="adminIdentifier" name="admin_identifier" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="Enter your admin username" required>
<label for="adminPassword">Password</label><div class="login-password"><input id="adminPassword" type="password" name="admin_password" autocomplete="off" placeholder="Enter your password" required><button type="button" id="passwordVisibility" aria-label="Show password" aria-pressed="false">Show</button></div>
<button class="button primary" type="submit">Sign in to workspace <span aria-hidden="true">&rarr;</span></button></form>
<p class="login-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/></svg> For authorized counselors and administrators.</p><a class="student-signin" href="../login.php">Looking for your student account? <strong>Sign in here &rarr;</strong></a></section>
</main><footer class="login-page-footer"><span>&copy; <?php echo date('Y'); ?> ProcrastiTrack</span><span>Clarity for every next step.</span></footer>
<script src="login.js?v=20261104"></script><script src="../assets/js/auto-dismiss-notices.js?v=20261124"></script></body></html>
