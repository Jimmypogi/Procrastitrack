<?php
include 'config/database.php';
require_once __DIR__ . '/csrf.php';

$message = "";

if(isset($_POST['register'])){ app_csrf_require();

    $fullname = mysqli_real_escape_string($conn,$_POST['fullname']);
    $email = mysqli_real_escape_string($conn,$_POST['email']);
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    $agreedToTerms = isset($_POST['agree_terms']) && $_POST['agree_terms'] === '1';

    if(!$agreedToTerms){

        $message = "Please agree to the Terms before creating your account.";

    }elseif($password != $confirm){

        $message = "Passwords do not match.";

    }else{

        $check = mysqli_query($conn,"SELECT * FROM users WHERE email='$email'");

        if(mysqli_num_rows($check)>0){

            $message = "Email already exists.";

        }else{

            $passwordHash = mysqli_real_escape_string($conn, password_hash($password, PASSWORD_DEFAULT));
            mysqli_query($conn,"INSERT INTO users(fullname,email,password,registration_date)

            VALUES('$fullname','$email','$passwordHash',CURDATE())");

            header("Location: login.php");

            exit();

        }

    }

}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ProcrastiTrack — Create Account</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#FAF7F5;color:#1e1b2e;line-height:1.6;min-height:100vh}
a{text-decoration:none;color:inherit}

.register-page{display:flex;min-height:100vh}

.register-left{
    flex:1;
    background:linear-gradient(160deg,#7c3aed 0%,#a855f7 30%,#c084fc 55%,#f97316 85%,#ea580c 100%);
    display:flex;
    flex-direction:column;
    justify-content:space-between;
    padding:48px;
    position:relative;
    overflow:hidden;
}
.register-left::before{
    content:'';
    position:absolute;
    top:-10%;
    right:-15%;
    width:500px;
    height:500px;
    background:radial-gradient(circle,rgba(255,255,255,0.12),transparent 70%);
    pointer-events:none;
}
.register-left::after{
    content:'';
    position:absolute;
    bottom:-20%;
    left:-10%;
    width:400px;
    height:400px;
    background:radial-gradient(circle,rgba(255,255,255,0.08),transparent 70%);
    pointer-events:none;
}
.left-content{position:relative;z-index:2;margin-top:20px}
.left-brand{display:flex;align-items:center;gap:12px;margin-bottom:48px}
.left-brand-icon{width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,0.2);backdrop-filter:blur(12px);display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;font-weight:900;font-family:'Poppins',sans-serif}
.left-brand-name{font-size:1.4rem;font-weight:800;color:#fff;letter-spacing:-0.02em;font-family:'Poppins',sans-serif}
.left-hero h1{font-size:2.8rem;font-weight:900;color:#fff;line-height:1.15;letter-spacing:-0.03em;margin-bottom:20px;font-family:'Poppins',sans-serif}
.left-hero h1 span{opacity:0.85}
.left-hero p{font-size:1.05rem;color:rgba(255,255,255,0.8);line-height:1.7;max-width:420px}

.left-floats{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-top:40px}
.float-card{
    background:rgba(255,255,255,0.15);
    backdrop-filter:blur(20px);
    border:1px solid rgba(255,255,255,0.2);
    border-radius:18px;
    padding:20px;
    color:#fff;
    animation:floatUp 5s ease-in-out infinite;
}
.float-card:nth-child(1){animation-delay:0s}
.float-card:nth-child(2){animation-delay:.2s}
.float-card:nth-child(3){animation-delay:.4s}
.float-card:nth-child(4){animation-delay:.6s}
@keyframes floatUp{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
.float-label{font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;opacity:0.7;margin-bottom:10px}
.float-value{font-size:1.3rem;font-weight:800;margin-bottom:4px}
.float-sub{font-size:0.75rem;opacity:0.7;margin-top:4px}
.mini-bars{display:flex;align-items:flex-end;gap:3px;height:30px;margin-top:6px}
.mini-bar{flex:1;border-radius:3px 3px 0 0;background:rgba(255,255,255,0.4);transition:height .6s ease}
.progress-dots{display:flex;gap:4px;margin-top:6px}
.progress-dot{width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,0.2)}
.progress-dot.on{background:rgba(255,255,255,0.9)}

.register-right{
    flex:1;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:48px;
    background:#FAF7F5;
    position:relative;
}
.register-right::before{
    content:'';
    position:absolute;
    top:10%;
    right:5%;
    width:300px;
    height:300px;
    background:radial-gradient(circle,rgba(124,58,237,0.06),transparent 70%);
    pointer-events:none;
}
.register-card{
    width:100%;
    max-width:440px;
    background:rgba(255,255,255,0.85);
    backdrop-filter:blur(24px);
    -webkit-backdrop-filter:blur(24px);
    border:1px solid rgba(255,255,255,0.6);
    border-radius:24px;
    padding:48px 40px;
    box-shadow:0 20px 80px rgba(124,58,237,0.08),0 4px 20px rgba(0,0,0,0.04);
    position:relative;
    z-index:1;
    animation:fadeUp .6s ease both;
}
@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.card-header{text-align:center;margin-bottom:36px}
.card-logo{width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,#7c3aed,#ea580c);display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;font-weight:900;font-family:'Poppins',sans-serif;margin:0 auto 16px;box-shadow:0 8px 24px rgba(124,58,237,0.25)}
.card-header h2{font-size:1.6rem;font-weight:800;color:#1e1b2e;letter-spacing:-0.02em;margin-bottom:8px;font-family:'Poppins',sans-serif}
.card-header p{font-size:0.92rem;color:#6b6580}

.message-box{
    padding:12px 16px;
    border-radius:12px;
    font-size:0.88rem;
    font-weight:600;
    margin-bottom:20px;
    text-align:center;
    animation:fadeIn .4s ease;
}
.message-error{background:#fef2f2;color:#dc2626;border:1px solid rgba(220,38,38,0.15)}
.message-success{background:#f0fdf4;color:#16a34a;border:1px solid rgba(22,163,74,0.15)}
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
    border-color:#7c3aed;
    background:#fff;
    box-shadow:0 0 0 4px rgba(124,58,237,0.1);
}
.form-icon{
    position:absolute;
    left:16px;
    top:50%;
    transform:translateY(-50%);
    font-size:1.1rem;
    pointer-events:none;
    opacity:0.6;
}
.form-footer{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:24px;
    font-size:0.85rem;
}
.form-footer label{display:flex;align-items:center;gap:8px;color:#6b6580;cursor:pointer}
.form-footer input[type="checkbox"]{width:16px;height:16px;accent-color:#7c3aed;cursor:pointer}
.form-footer a{color:#7c3aed;font-weight:600}
.form-footer a:hover{text-decoration:underline}

.btn-register{
    width:100%;
    padding:15px 24px;
    border-radius:14px;
    background:linear-gradient(135deg,#7c3aed 0%,#a855f7 50%,#c084fc 100%);
    color:#fff;
    font-size:1rem;
    font-weight:700;
    font-family:'Inter',sans-serif;
    border:none;
    cursor:pointer;
    transition:all .25s cubic-bezier(.23,1,.32,1);
    box-shadow:0 4px 20px rgba(124,58,237,0.3);
    letter-spacing:0.01em;
}
.btn-register:hover{
    transform:translateY(-2px) scale(1.01);
    box-shadow:0 8px 32px rgba(124,58,237,0.4);
}
.btn-register:active{transform:translateY(0) scale(0.99)}

.card-signin{text-align:center;margin-top:28px;font-size:0.92rem;color:#6b6580}
.card-signin a{color:#7c3aed;font-weight:700;transition:color .2s}
.card-signin a:hover{color:#5b21b6}

@media(max-width:900px){
.register-page{flex-direction:column}
.register-left{min-height:340px;padding:36px 24px}
.left-hero h1{font-size:2rem}
.left-floats{height:120px}
.float-card:nth-child(1){width:180px}
.float-card:nth-child(2){display:none}
.float-card:nth-child(3){width:160px}
.float-card:nth-child(4){display:none}
.register-right{padding:36px 20px}
.register-card{padding:36px 28px}
}
@media(max-width:768px){
.left-floats{grid-template-columns:repeat(2,1fr);gap:12px}
}
@media(max-width:480px){
.register-left{padding:28px 20px}
.left-hero h1{font-size:1.6rem}
.left-floats{grid-template-columns:1fr}
.register-right{padding:24px 16px}
.register-card{padding:28px 20px;border-radius:20px}
.form-group input{padding:13px 14px 13px 42px;font-size:0.9rem}
}
</style>
<style>
.form-group:has(.password-eye){position:relative}
.form-group:has(.password-eye) input{padding-right:54px}
.form-icon.password-icon{display:grid;place-items:center;color:#807493;opacity:.65}
.password-icon svg{display:block;width:17px;height:17px;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.password-eye{position:absolute;right:7px;top:50%;transform:translateY(-50%);width:40px;height:40px;display:grid;place-items:center;padding:0;margin:0;background:transparent;border:0;border-radius:10px;color:#807493;cursor:pointer;box-shadow:none}
.password-eye:hover{background:#eee7fc;color:#7c3aed}
.password-eye:focus-visible{outline:2px solid #8b5cf6;outline-offset:1px}
.password-eye .eye-slash{display:none}
.password-eye[aria-pressed="true"] .eye-slash{display:block}
</style></head>
<body>

<div class="register-page">

  <div class="register-left">
    <div class="left-content">
      <div class="left-brand">
        <div class="left-brand-icon">P</div>
        <span class="left-brand-name">ProcrastiTrack</span>
      </div>
      <div class="left-hero">
        <h1>Start Building <span>Better Habits</span> Today.</h1>
        <p>Analyze your procrastination patterns, track your productivity trends, and receive personalized recommendations designed to help you achieve more with less effort.</p>
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
        <div class="float-label">Focus Time</div>
        <div class="float-value">4.2h</div>
        <div class="float-sub">Today</div>
      </div>
      <div class="float-card">
        <div class="float-label">Tasks Done</div>
        <div class="float-value">23</div>
        <div class="float-sub">&#8593; 5 this week</div>
      </div>
      <div class="float-card">
        <div class="float-label">Habit Streak</div>
        <div class="progress-dots">
          <div class="progress-dot on"></div>
          <div class="progress-dot on"></div>
          <div class="progress-dot on"></div>
          <div class="progress-dot on"></div>
          <div class="progress-dot on"></div>
          <div class="progress-dot"></div>
          <div class="progress-dot"></div>
        </div>
        <div class="float-sub" style="margin-top:8px">5/7 active</div>
      </div>
    </div>
  </div>

  <div class="register-right">
    <div class="register-card">
      <div class="card-header">
        <div class="card-logo">P</div>
        <h2>Create Account</h2>
        <p>Start tracking your productivity journey</p>
      </div>

      <?php if(!empty($message)): ?>
        <div class="message-box <?php echo (strpos($message,'does not match') !== false || strpos($message,'exists') !== false || strpos($message,'Please agree') !== false) ? 'message-error' : 'message-success'; ?>">
          <?php echo htmlspecialchars($message); ?>
        </div>
      <?php endif; ?>

      <form method="POST"><?php app_csrf_field(); ?>

        <div class="form-group">
          <span class="form-icon">&#128100;</span>
          <input type="text" name="fullname" placeholder="Full Name" required>
        </div>

        <div class="form-group">
          <span class="form-icon">&#9993;</span>
          <input type="email" name="email" placeholder="Email" required>
        </div>

        <div class="form-group">
          <span class="form-icon password-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
              <rect x="5" y="10" width="14" height="11" rx="3"></rect>
              <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
              <path d="M12 14v3"></path>
            </svg>
          </span>
          <input type="password" id="register-password" autocomplete="new-password" name="password" placeholder="Password" required>
          <button type="button" class="password-eye" data-password-target="register-password" aria-controls="register-password" aria-label="Show password" aria-pressed="false" title="Show password">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="eye-slash" d="m3 3 18 18"/></svg>
          </button>
        </div>

        <div class="form-group">
          <span class="form-icon password-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
              <rect x="5" y="10" width="14" height="11" rx="3"></rect>
              <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
              <path d="M12 14v3"></path>
            </svg>
          </span>
          <input type="password" id="register-confirm" autocomplete="new-password" name="confirm" placeholder="Confirm Password" required>
          <button type="button" class="password-eye" data-password-target="register-confirm" aria-controls="register-confirm" aria-label="Show confirm password" aria-pressed="false" title="Show confirm password">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="eye-slash" d="m3 3 18 18"/></svg>
          </button>
        </div>

        <div class="form-footer">
          <label><input type="checkbox" name="agree_terms" value="1" required> I agree to the Terms.</label>
        </div>

        <button type="submit" name="register" class="btn-register">Register</button>

      </form>

      <div class="card-signin">
        Already have an account? <a href="login.php">Sign In</a>
      </div>
    </div>
  </div>

</div>

<script defer src="https://unpkg.com/lucide@1.47.0/dist/umd/lucide.min.js"></script>
<script defer src="assets/js/app-icons.js"></script>
<script defer src="assets/js/auto-dismiss-notices.js?v=20261124"></script>
<script>
document.querySelectorAll('[data-password-target]').forEach(function(button){
 const input=document.getElementById(button.dataset.passwordTarget);
 const label=input.name==='confirm'?'confirm password':'password';
 function conceal(){input.type='password';button.setAttribute('aria-pressed','false');button.setAttribute('aria-label','Show '+label);button.title='Show '+label;}
 button.addEventListener('click',function(){
  const reveal=input.type==='password';
  input.type=reveal?'text':'password';
  button.setAttribute('aria-pressed',String(reveal));
  button.setAttribute('aria-label',(reveal?'Hide ':'Show ')+label);
  button.title=(reveal?'Hide ':'Show ')+label;
 });
 input.form.addEventListener('submit',conceal);
 window.addEventListener('pageshow',conceal);
});
</script></body>
</html>
