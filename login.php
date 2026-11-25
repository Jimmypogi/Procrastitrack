<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/csrf.php';

if(isset($_SESSION['user_id'])){
    header("Location: dashboard.php");
    exit();
}

if(isset($_POST['login'])){
    app_csrf_require();

    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'] ?? '';

    $query = mysqli_query($conn, "SELECT * FROM users WHERE email='$email' LIMIT 1");
    $row = $query ? mysqli_fetch_assoc($query) : null;
    $accountActive = $row && (!array_key_exists('is_active', $row) || (int)$row['is_active'] === 1);
    $passwordMatches = $row && password_verify($password, $row['password']);

    // Upgrade accounts created before password hashing was enabled.
    $storedIsLegacyPlaintext = $row && password_get_info((string)$row['password'])['algoName'] === 'unknown';
    if($accountActive && $row && $storedIsLegacyPlaintext && !$passwordMatches && hash_equals((string)$row['password'], (string)$password)){
      $passwordMatches = true;
      $upgradedHash = mysqli_real_escape_string($conn, password_hash($password, PASSWORD_DEFAULT));
      mysqli_query($conn, "UPDATE users SET password='$upgradedHash' WHERE id=" . intval($row['id']));
    }

    if($passwordMatches && $accountActive){

        session_regenerate_id(true);

        $_SESSION['user_id'] = $row['id'];
        $_SESSION['fullname'] = $row['fullname'];
        $_SESSION['email'] = $row['email'];

        header("Location: dashboard.php");
        exit();

    }else{

        $error = "Invalid email or password.";

    }

}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ProcrastiTrack — Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#FAF7F5;color:#1e1b2e;line-height:1.6;min-height:100vh;overflow-x:hidden;cursor:default}
a{text-decoration:none;color:inherit}
button{cursor:pointer;font-family:inherit;border:none}
a,button,[role="button"],summary{cursor:pointer}
input,textarea,select,[contenteditable="true"]{cursor:text}

.login-page{display:flex;min-height:100vh;position:relative}

.bg-orb{position:fixed;border-radius:50%;filter:blur(100px);pointer-events:none;z-index:0}
.bg-orb-1{width:500px;height:500px;background:rgba(124,58,237,0.12);top:-10%;right:-5%}
.bg-orb-2{width:350px;height:350px;background:rgba(139,92,246,0.1);bottom:5%;left:10%}
.bg-orb-3{width:250px;height:250px;background:rgba(234,88,12,0.08);top:40%;right:30%}

.login-left{
    flex:1;
    background:linear-gradient(160deg,#6D28D9 0%,#7c3aed 25%,#8B5CF6 55%,#a78bfa 80%,#c4b5fd 100%);
    display:flex;
    flex-direction:column;
    justify-content:space-between;
    padding:48px;
    position:relative;
    overflow:hidden;
    z-index:1;
}
.login-left::before{
    content:'';
    position:absolute;
    top:-15%;
    right:-20%;
    width:600px;
    height:600px;
    background:radial-gradient(circle,rgba(255,255,255,0.1),transparent 70%);
    pointer-events:none;
}
.login-left::after{
    content:'';
    position:absolute;
    bottom:-25%;
    left:-15%;
    width:450px;
    height:450px;
    background:radial-gradient(circle,rgba(255,255,255,0.06),transparent 70%);
    pointer-events:none;
}
.left-content{position:relative;z-index:2}
.left-brand{display:flex;align-items:center;gap:14px;margin-bottom:48px}
.left-brand-icon{width:46px;height:46px;border-radius:13px;background:rgba(255,255,255,0.18);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);display:flex;align-items:center;justify-content:center;color:#fff;font-size:19px;font-weight:900;font-family:'Poppins',sans-serif}
.left-brand-name{font-size:1.5rem;font-weight:800;color:#fff;letter-spacing:-0.02em;font-family:'Poppins',sans-serif}
.left-hero h1{font-size:3rem;font-weight:900;color:#fff;line-height:1.1;letter-spacing:-0.03em;margin-bottom:20px;font-family:'Poppins',sans-serif}
.left-hero p{font-size:1.08rem;color:rgba(255,255,255,0.75);line-height:1.75;max-width:440px}

.left-floats{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-top:48px}
.float-card{
    background:rgba(255,255,255,0.13);
    backdrop-filter:blur(20px);
    -webkit-backdrop-filter:blur(20px);
    border:1px solid rgba(255,255,255,0.18);
    border-radius:18px;
    padding:20px;
    color:#fff;
    animation:floatUp 5s ease-in-out infinite;
    transition:transform .3s ease;
}
.float-card:nth-child(1){animation-delay:0s}
.float-card:nth-child(2){animation-delay:.2s}
.float-card:nth-child(3){animation-delay:.4s}
.float-card:nth-child(4){animation-delay:.6s}
@keyframes floatUp{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
.float-label{font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;opacity:0.65;margin-bottom:10px}
.float-value{font-size:1.5rem;font-weight:800;margin-bottom:4px}
.float-sub{font-size:0.75rem;opacity:0.6;margin-top:2px}
.mini-bars{display:flex;align-items:flex-end;gap:3px;height:36px;margin-top:8px}
.mini-bar{flex:1;border-radius:3px 3px 0 0;background:rgba(255,255,255,0.35);transition:height .6s ease}
.progress-dots{display:flex;gap:5px;margin-top:8px}
.progress-dot{width:7px;height:7px;border-radius:50%;background:rgba(255,255,255,0.2)}
.progress-dot.on{background:rgba(255,255,255,0.9)}
.ai-card{display:flex;align-items:center;gap:10px}
.ai-icon{width:32px;height:32px;border-radius:10px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
.ai-text{font-size:0.8rem;font-weight:600;line-height:1.4;opacity:0.9;display:block}

.login-right{
    flex:1;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:48px;
    background:#FAF7F5;
    position:relative;
    z-index:1;
}
.login-right::before{
    content:'';
    position:absolute;
    top:15%;
    right:8%;
    width:350px;
    height:350px;
    background:radial-gradient(circle,rgba(124,58,237,0.06),transparent 70%);
    pointer-events:none;
}
.login-card{
    width:100%;
    max-width:440px;
    background:rgba(255,255,255,0.85);
    backdrop-filter:blur(24px);
    -webkit-backdrop-filter:blur(24px);
    border:1px solid rgba(255,255,255,0.6);
    border-radius:24px;
    padding:48px 40px;
    box-shadow:0 20px 80px rgba(124,58,237,0.08),0 4px 20px rgba(0,0,0,0.04);
    animation:fadeUp .6s ease both;
    position:relative;
    z-index:1;
}
.login-right{flex-direction:column;align-items:center;justify-content:center}
.login-content{width:100%;max-width:440px;display:flex;flex-direction:column;z-index:1}
.login-right .back-home{
    position:absolute;
    top:28px;
    right:32px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    flex:0 0 auto;
    width:max-content;
    min-height:48px;
    padding:6px 18px 6px 7px;
    border:1px solid rgba(139,92,246,.23);
    border-radius:16px;
    background:linear-gradient(135deg,rgba(255,255,255,.98),rgba(246,240,255,.96));
    color:#43236f;
    font-size:.86rem;
    font-weight:700;
    white-space:nowrap;
    box-shadow:0 8px 24px rgba(86,45,145,.1),inset 0 1px 0 rgba(255,255,255,.95);
    transition:background .22s ease,border-color .22s ease,color .22s ease,transform .22s ease,box-shadow .22s ease;
}
.login-right .back-home:hover{background:linear-gradient(135deg,#fff,#eee4ff);border-color:#b99ae9;color:#6429b8;transform:translateY(-2px);box-shadow:0 12px 28px rgba(109,40,217,.18),inset 0 1px 0 #fff}
.login-right .back-home:focus-visible{outline:3px solid rgba(139,92,246,.25);outline-offset:3px}
.back-home-arrow{width:36px;height:36px;flex:0 0 36px;display:grid;place-items:center;border-radius:11px;background:linear-gradient(145deg,#985bff,#6D28D9 78%);color:#fff;box-shadow:0 5px 13px rgba(109,40,217,.3),inset 0 1px 0 rgba(255,255,255,.24);transition:transform .22s ease,box-shadow .22s ease}
.back-home-arrow svg{display:block;width:17px;height:17px;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.login-right .back-home:hover .back-home-arrow{transform:translateX(-3px);box-shadow:0 7px 16px rgba(109,40,217,.38),inset 0 1px 0 rgba(255,255,255,.28)}
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
.card-header{text-align:center;margin-bottom:36px}
.card-logo{width:50px;height:50px;border-radius:15px;background:linear-gradient(135deg,#6D28D9,#8B5CF6);display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px;font-weight:900;font-family:'Poppins',sans-serif;margin:0 auto 18px;box-shadow:0 8px 24px rgba(109,40,217,0.3)}
.card-header h1{font-size:1.7rem;font-weight:800;color:#1e1b2e;letter-spacing:-0.02em;margin-bottom:8px;font-family:'Poppins',sans-serif}
.card-header h1 .wave{display:inline-block;animation:wave 2s ease-in-out infinite}
@keyframes wave{0%,100%{transform:rotate(0)}25%{transform:rotate(15deg)}75%{transform:rotate(-10deg)}}
.card-header p{font-size:0.95rem;color:#6b6580}

.error-box{
    padding:14px 16px;
    border-radius:12px;
    background:#fef2f2;
    color:#dc2626;
    border:1px solid rgba(220,38,38,0.12);
    font-size:0.88rem;
    font-weight:500;
    margin-bottom:20px;
    text-align:center;
    animation:fadeIn .35s ease;
}
@keyframes fadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}

.form-group{position:relative;margin-bottom:20px}
.form-group input{
    width:100%;
    padding:14px 16px 14px 46px;
    border:1.5px solid #e5e0ee;
    border-radius:14px;
    background:#f8f6fb;
    color:#1e1b2e;
    font-size:0.95rem;
    font-family:'Inter',sans-serif;
    transition:all .25s ease;
    outline:none;
}
.form-group input::placeholder{color:#a8a3b8}
.form-group input:focus{
    border-color:#8B5CF6;
    background:#fff;
    box-shadow:0 0 0 4px rgba(139,92,246,0.1);
}
.form-icon{
    position:absolute;
    left:16px;
    top:50%;
    transform:translateY(-50%);
    font-size:1.1rem;
    pointer-events:none;
    opacity:0.55;
}
.form-icon.password-icon{
    z-index:2;
    color:#6b6580;
    opacity:.65;
}
.password-icon svg{display:block;width:17px;height:17px;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.password-wrapper{position:relative}
.password-wrapper input{padding-right:48px}
.toggle-password{
    position:absolute;
    right:14px;
    top:50%;
    transform:translateY(-50%);
    background:none;
    border:none;
    font-size:1.05rem;
    cursor:pointer;
    opacity:0.45;
    transition:opacity .2s;
    padding:4px;
}
.toggle-password:hover{opacity:0.8}

.btn-login{
    width:100%;
    padding:15px 24px;
    border-radius:14px;
    background:linear-gradient(135deg,#6D28D9 0%,#7c3aed 50%,#8B5CF6 100%);
    color:#fff;
    font-size:1rem;
    font-weight:700;
    font-family:'Inter',sans-serif;
    transition:all .25s cubic-bezier(.23,1,.32,1);
    box-shadow:0 4px 20px rgba(109,40,217,0.3);
    letter-spacing:0.01em;
    margin-top:4px;
}
.btn-login:hover{
    transform:translateY(-2px);
    box-shadow:0 8px 32px rgba(109,40,217,0.4);
}
.btn-login:active{transform:translateY(0) scale(0.99)}

.form-options{display:flex;justify-content:space-between;align-items:center;margin-top:16px;margin-bottom:28px;font-size:0.88rem}
.form-options label{display:flex;align-items:center;gap:8px;color:#6b6580;cursor:pointer}
.form-options input[type="checkbox"]{width:16px;height:16px;accent-color:#6D28D9;cursor:pointer}
.form-options a{color:#6D28D9;font-weight:600;transition:color .2s}
.form-options a:hover{color:#5b21b6;text-decoration:underline}

.card-footer{text-align:center;padding-top:24px;border-top:1px solid rgba(0,0,0,0.06);font-size:0.92rem;color:#6b6580}
.card-footer a{color:#6D28D9;font-weight:700;transition:color .2s}
.card-footer a:hover{color:#5b21b6;text-decoration:underline}

@media(max-width:900px){
.login-page{flex-direction:column}
.login-left{min-height:360px;padding:36px 24px}
.left-hero h1{font-size:2rem}
.left-floats{grid-template-columns:repeat(2,1fr);gap:12px}
.login-right{padding:36px 20px}
.login-card{padding:36px 28px}
}
@media(max-width:480px){
.login-left{padding:28px 20px}
.left-hero h1{font-size:1.7rem}
.left-brand-name{font-size:1.2rem}
.left-floats{grid-template-columns:1fr}
.login-right{padding:24px 16px}
.login-card{padding:28px 20px;border-radius:20px}
.form-group input{padding:13px 14px 13px 42px;font-size:0.9rem}
}
/* Responsive login layout: keep the form comfortable on narrow, short and wide screens. */
html,body{width:100%;min-width:320px}
.login-page{min-height:100vh;min-height:100svh}
.login-left,.login-right{min-width:0}
.login-card{min-width:0}
.form-group input{min-width:0;min-height:48px}
.form-options{gap:12px;flex-wrap:wrap}
.card-footer{line-height:1.7}
@media (min-width:901px) and (max-width:1180px){
  .login-left,.login-right{padding:32px}
  .left-hero h1{font-size:clamp(2rem,3.2vw,2.7rem)}
  .login-card{padding:40px 32px}
  .float-card{padding:16px}
}
@media (max-width:900px){
  .login-page{display:flex;flex-direction:column;min-height:100svh}
  .login-left{flex:0 0 auto;min-height:0;padding:clamp(24px,6vw,42px) clamp(20px,6vw,44px) 30px}
  .left-brand{margin-bottom:clamp(24px,5vh,42px)}
  .left-hero h1{font-size:clamp(1.8rem,5vw,2.55rem);max-width:620px}
  .left-hero p{font-size:clamp(.92rem,2.1vw,1.03rem);max-width:620px}
  .left-floats{margin-top:28px}
  .login-right{flex:1;align-items:flex-start;padding:clamp(24px,5vw,44px) 20px max(28px,env(safe-area-inset-bottom))}
  .login-content{max-width:520px;margin:0 auto}
  .login-card{max-width:520px;padding:clamp(28px,5vw,40px)}
}
@media (max-width:600px){
  body{background:#f7f6f3}
  .bg-orb{filter:blur(72px);opacity:.65}
  .bg-orb-1{width:280px;height:280px;top:-100px;right:-100px}
  .bg-orb-2{width:220px;height:220px;bottom:0;left:-100px}
  .bg-orb-3{display:none}
  .login-left{padding:22px 22px 25px}
  .left-brand{gap:10px;margin-bottom:24px}
  .left-brand-icon{width:38px;height:38px;border-radius:11px;font-size:16px}
  .left-brand{gap:8px}
  .left-brand-name{font-size:1.05rem}
  .login-right .back-home{position:static;align-self:flex-end;min-height:42px;margin:0 0 12px auto;padding-right:14px;font-size:.8rem}
  .back-home-arrow{width:30px;height:30px;flex-basis:30px;border-radius:9px}
  .left-hero h1{font-size:clamp(1.65rem,7vw,2.1rem);line-height:1.12;margin-bottom:10px}
  .left-hero p{font-size:.88rem;line-height:1.55}
  .left-floats{display:none}
  .login-right{padding:22px 16px max(26px,env(safe-area-inset-bottom))}
  .login-card{padding:28px 22px;border-radius:20px}
  .card-header{margin-bottom:26px}
  .card-logo{width:44px;height:44px;margin-bottom:14px;font-size:19px}
  .card-header h1{font-size:1.45rem}
  .card-header p{font-size:.88rem}
  .form-group{margin-bottom:16px}
  .form-group input{font-size:16px;padding-top:13px;padding-bottom:13px;border-radius:12px}
  .form-options{align-items:flex-start;margin-top:14px;margin-bottom:22px;font-size:.82rem}
  .form-options label{min-height:24px}
  .btn-login{min-height:50px;border-radius:12px}
  .card-footer{padding-top:19px;font-size:.84rem}
}
@media (max-width:360px){
  .login-left{padding-left:17px;padding-right:17px}
  .login-right{padding-left:12px;padding-right:12px}
  .login-card{padding:24px 18px}
  .form-options{flex-direction:column;gap:10px}
}
@media (max-height:700px) and (min-width:901px){
  .login-left,.login-right{padding-top:28px;padding-bottom:28px}
  .left-brand{margin-bottom:28px}
  .left-floats{margin-top:26px}
  .float-card{padding:14px}
  .login-card{padding-top:32px;padding-bottom:32px}
  .card-header{margin-bottom:24px}
}
@media (prefers-reduced-motion:reduce){
  *,*::before,*::after{scroll-behavior:auto!important;animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}
}
</style>
</head>
<body>

<div class="bg-orb bg-orb-1"></div>
<div class="bg-orb bg-orb-2"></div>
<div class="bg-orb bg-orb-3"></div>

<div class="login-page">

  <div class="login-left">
    <div class="left-content">
      <div class="left-brand">
        <div class="left-brand-icon">P</div>
        <span class="left-brand-name">ProcrastiTrack</span>
      </div>
      <div class="left-hero">
        <h1>Stop Procrastinating.<br>Start Achieving.</h1>
        <p>Analyze your productivity habits, understand procrastination patterns, and receive personalized recommendations to improve focus and accomplish more.</p>
      </div>
    </div>

    <div class="left-floats">
      <div class="float-card">
        <div class="float-label">Productivity Score</div>
        <div class="float-value">87%</div>
        <div class="float-sub">&#8593; 12% this week</div>
        <div class="mini-bars">
          <div class="mini-bar" style="height:45%"></div>
          <div class="mini-bar" style="height:60%"></div>
          <div class="mini-bar" style="height:35%"></div>
          <div class="mini-bar" style="height:75%"></div>
          <div class="mini-bar" style="height:55%"></div>
          <div class="mini-bar" style="height:85%"></div>
          <div class="mini-bar" style="height:70%"></div>
        </div>
      </div>
      <div class="float-card">
        <div class="float-label">Weekly Progress</div>
        <div class="float-value">78%</div>
        <div class="float-sub">4 of 5 days</div>
      </div>
      <div class="float-card">
        <div class="float-label">Focus Time</div>
        <div class="float-value">4.2h</div>
        <div class="float-sub">Today</div>
      </div>
      <div class="float-card ai-card">
        <div class="ai-icon">&#129302;</div>
        <div>
<div class="float-label" style="margin-bottom:4px">AI Tip</div>
<div class="ai-text">Best focus: 9&#8211;11 AM</div>
        </div>
      </div>
    </div>
  </div>

  <div class="login-right">
    <div class="login-content">
      <a class="back-home" href="landing.html" aria-label="Back to the ProcrastiTrack home page">
        <span class="back-home-arrow" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M19 12H5M12 19l-7-7 7-7"/></svg></span>
        <span>Back to home</span>
      </a>
      <div class="login-card">
      <div class="card-header">
        <div class="card-logo">P</div>
        <h1>Welcome Back! <span class="wave">&#9995;</span></h1>
        <p>Sign in to continue to your productivity dashboard.</p>
      </div>

      <?php if(isset($error) && !empty($error)): ?>
        <div class="error-box"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <form method="POST">
        <?php app_csrf_field(); ?>
    

        <div class="form-group">
          <span class="form-icon">&#9993;</span>
          <input type="email" name="email" placeholder="Email Address" required>
        </div>

        <div class="form-group">
          <span class="form-icon password-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none">
              <rect x="5" y="10" width="14" height="11" rx="3"></rect>
              <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
              <path d="M12 14v3"></path>
            </svg>
          </span>
          <div class="password-wrapper">
            <input type="password" name="password" id="loginPassword" placeholder="Password" required>
            <button type="button" class="toggle-password" onclick="togglePassword('loginPassword', this)">&#128065;</button>
          </div>
        </div>

        <button type="submit" name="login" class="btn-login">Sign In</button>

        <div class="form-options">
          <label><input type="checkbox" name="remember"> Remember Me</label>
          <a href="forgot_password.php">Forgot Password?</a>
        </div>

      </form>

      <div class="card-footer">
        Don&#8217;t have an account? <a href="register.php">Create Account</a>
      </div>
      </div>
    </div>
  </div>

</div>

<script>
function togglePassword(inputId, btn) {
  var input = document.getElementById(inputId);
  if (input.type === 'password') {
    input.type = 'text';
    btn.innerHTML = '&#128064;';
  } else {
    input.type = 'password';
    btn.innerHTML = '&#128065;';
  }
}
</script>
<script defer src="https://unpkg.com/lucide@1.47.0/dist/umd/lucide.min.js"></script>
<script defer src="assets/js/app-icons.js"></script>
<script defer src="assets/js/auto-dismiss-notices.js?v=20261124"></script>
</body>
</html>
