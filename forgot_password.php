<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/csrf.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

$createTokenTable = "CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `token_hash` char(64) NOT NULL,
    `expires_at` datetime NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_password_reset_token_hash` (`token_hash`),
    KEY `idx_password_reset_user` (`user_id`),
    KEY `idx_password_reset_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$conn->query($createTokenTable)) {
    error_log('ProcrastiTrack password recovery table could not be initialized: ' . $conn->error);
    http_response_code(503);
    exit('Password recovery is temporarily unavailable. Please try again later.');
}

function app_send_password_reset_email(array $recipient, string $token): bool
{
    $configPath = __DIR__ . '/config/mail.local.php';
    $autoloadPath = __DIR__ . '/vendor/autoload.php';
    if (!is_file($configPath) || !is_file($autoloadPath)) {
        error_log('ProcrastiTrack password recovery email is not configured.');
        return false;
    }

    $config = require $configPath;
    $baseUrl = is_array($config) ? trim((string)($config['app_url'] ?? '')) : '';
    if (!is_array($config) || empty($config['gmail_address']) || empty($config['app_password']) ||
        !filter_var($baseUrl, FILTER_VALIDATE_URL) ||
        !in_array(strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        error_log('ProcrastiTrack password recovery email configuration is incomplete.');
        return false;
    }

    require_once $autoloadPath;
    $resetUrl = rtrim($baseUrl, '/') . '/forgot_password.php?token=' . rawurlencode($token);
    $safeName = htmlspecialchars($recipient['fullname'] ?: 'there', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $config['gmail_address'];
        $mail->Password = preg_replace('/\s+/', '', $config['app_password']);
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 10;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($config['gmail_address'], $config['from_name'] ?? 'ProcrastiTrack');
        $mail->addAddress($recipient['email'], $recipient['fullname'] ?: $recipient['email']);
        $mail->isHTML(true);
        $mail->Subject = 'Reset your ProcrastiTrack password';
        $mail->Body = '<div style="margin:0;padding:36px 16px;background:#f5f2fb;font-family:Arial,sans-serif;color:#211b35"><div style="max-width:560px;margin:auto;padding:36px;border:1px solid #e8e1f3;border-radius:22px;background:#fff"><p style="margin:0 0 10px;color:#7640d8;font-size:12px;font-weight:bold;letter-spacing:2px">PROCRASTITRACK</p><h1 style="margin:0 0 14px;font-size:26px">Reset your password</h1><p style="color:#6b6580">Hi ' . $safeName . ', we received a request to reset your password. Use the secure link below within the next hour.</p><p style="margin:28px 0"><a href="' . $safeUrl . '" style="display:inline-block;padding:14px 22px;border-radius:12px;background:#7137d4;color:#fff;font-weight:bold;text-decoration:none">Create a new password</a></p><p style="color:#6b6580;font-size:13px">If you did not request this, you can safely ignore this email. This link can only be used once.</p><p style="margin:26px 0 0;color:#9690a4;font-size:12px">For your security, this link expires in one hour.</p></div></div>';
        $mail->AltBody = "Hi " . ($recipient['fullname'] ?: 'there') . ", reset your ProcrastiTrack password within one hour: " . $resetUrl . "\nIf you did not request this, ignore this email.";
        $mail->send();
        return true;
    } catch (Throwable $error) {
        error_log('ProcrastiTrack password recovery email failed: ' . (isset($mail) ? $mail->ErrorInfo : $error->getMessage()));
        return false;
    }
}

$message = '';
$messageType = '';
$passwordResetComplete = false;
$token = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
$tokenIsValid = false;

if (isset($_POST['request_reset'])) {
    app_csrf_require();
    $email = trim((string)($_POST['email'] ?? ''));

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $conn->prepare('SELECT id, fullname, email FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        if (!$stmt) {
            error_log('ProcrastiTrack password recovery lookup could not be prepared: ' . $conn->error);
            http_response_code(503);
            $message = 'Password recovery is temporarily unavailable. Please try again later.';
            $messageType = 'error';
        } else {
            $stmt->bind_param('s', $email);
            if (!$stmt->execute()) {
                error_log('ProcrastiTrack password recovery lookup failed: ' . $stmt->error);
                http_response_code(503);
                $message = 'Password recovery is temporarily unavailable. Please try again later.';
                $messageType = 'error';
                $stmt->close();
            } else {
                $recipient = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($recipient) {
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);
                    $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                    $conn->begin_transaction();
                    $deleteExpired = $conn->prepare('DELETE FROM password_reset_tokens WHERE expires_at <= NOW()');
                    $deleteUserTokens = $conn->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
                    $insertToken = $conn->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
                    if (!$deleteExpired || !$deleteUserTokens || !$insertToken) {
                        $conn->rollback();
                        error_log('ProcrastiTrack password recovery token could not be prepared: ' . $conn->error);
                        http_response_code(503);
                        $message = 'Password recovery is temporarily unavailable. Please try again later.';
                        $messageType = 'error';
                    } else {
                        $userId = (int)$recipient['id'];
                        $tokenSaved = $deleteExpired->execute()
                            && $deleteUserTokens->bind_param('i', $userId)
                            && $deleteUserTokens->execute()
                            && $insertToken->bind_param('iss', $userId, $tokenHash, $expiresAt)
                            && $insertToken->execute();
                        if ($tokenSaved) {
                            $conn->commit();
                            if (!app_send_password_reset_email($recipient, $token)) {
                                $removeToken = $conn->prepare('DELETE FROM password_reset_tokens WHERE token_hash = ?');
                                if ($removeToken) {
                                    $removeToken->bind_param('s', $tokenHash);
                                    if (!$removeToken->execute()) {
                                        error_log('ProcrastiTrack could not remove an unsent password recovery token: ' . $removeToken->error);
                                    }
                                    $removeToken->close();
                                } else {
                                    error_log('ProcrastiTrack could not prepare removal of an unsent password recovery token: ' . $conn->error);
                                }
                            }
                        } else {
                            $conn->rollback();
                            error_log('ProcrastiTrack password recovery token could not be saved: ' . ($insertToken->error ?: $deleteUserTokens->error ?: $deleteExpired->error));
                            http_response_code(503);
                            $message = 'Password recovery is temporarily unavailable. Please try again later.';
                            $messageType = 'error';
                        }
                        $deleteExpired->close();
                        $deleteUserTokens->close();
                        $insertToken->close();
                    }
                }

                if ($messageType !== 'error') {
                    $message = 'If an active account uses that email, a secure reset link will arrive shortly. Check your spam folder if needed.';
                    $messageType = 'success';
                }
            }
        }
    } else {
        $message = 'Enter a valid email address and try again.';
        $messageType = 'error';
    }
}

if (isset($_POST['reset_password'])) {
    app_csrf_require();
    $token = (string)($_POST['token'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        $message = 'This reset link is invalid or has expired. Request a new one below.';
        $messageType = 'error';
    } elseif (strlen($password) < 8) {
        $message = 'Choose a password with at least 8 characters.';
        $messageType = 'error';
    } elseif ($password !== $confirmPassword) {
        $message = 'Your passwords do not match.';
        $messageType = 'error';
    } else {
        $tokenHash = hash('sha256', $token);
        $conn->begin_transaction();
        $lookup = $conn->prepare('SELECT t.user_id FROM password_reset_tokens t INNER JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.expires_at > NOW() AND u.is_active = 1 LIMIT 1 FOR UPDATE');
        if (!$lookup) {
            $conn->rollback();
            error_log('ProcrastiTrack password reset could not be prepared: ' . $conn->error);
            http_response_code(503);
            $message = 'Password recovery is temporarily unavailable. Please try again later.';
            $messageType = 'error';
        } else {
            $lookup->bind_param('s', $tokenHash);
            if (!$lookup->execute()) {
                $conn->rollback();
                error_log('ProcrastiTrack password reset lookup failed: ' . $lookup->error);
                http_response_code(503);
                $message = 'Password recovery is temporarily unavailable. Please try again later.';
                $messageType = 'error';
            } else {
                $resetUser = $lookup->get_result()->fetch_assoc();
                $lookup->close();
                if (!$resetUser) {
                    $conn->rollback();
                    $message = 'This reset link is invalid or has expired. Request a new one below.';
                    $messageType = 'error';
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $update = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                    $removeTokens = $conn->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
                    if (!$update || !$removeTokens) {
                        $conn->rollback();
                        error_log('ProcrastiTrack password reset update could not be prepared: ' . $conn->error);
                        http_response_code(503);
                        $message = 'Password recovery is temporarily unavailable. Please try again later.';
                        $messageType = 'error';
                    } else {
                        $userId = (int)$resetUser['user_id'];
                        $update->bind_param('si', $passwordHash, $userId);
                        $removeTokens->bind_param('i', $userId);
                        if ($update->execute() && $removeTokens->execute()) {
                            $conn->commit();
                            $message = 'Your password has been updated. You can now sign in with your new password.';
                            $messageType = 'success';
                            $passwordResetComplete = true;
                            $token = '';
                        } else {
                            $conn->rollback();
                            error_log('ProcrastiTrack password reset could not be saved: ' . $conn->error);
                            http_response_code(503);
                            $message = 'Password recovery is temporarily unavailable. Please try again later.';
                            $messageType = 'error';
                        }
                        $update->close();
                        $removeTokens->close();
                    }
                }
            }
        }
    }
}

if ($token !== '' && preg_match('/\A[a-f0-9]{64}\z/', $token)) {
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare('SELECT t.user_id FROM password_reset_tokens t INNER JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.expires_at > NOW() AND u.is_active = 1 LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $tokenHash);
        if ($stmt->execute()) {
            $record = $stmt->get_result()->fetch_assoc();
            if ($record) {
                $tokenIsValid = true;
            }
        } else {
            error_log('ProcrastiTrack password reset link lookup failed: ' . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log('ProcrastiTrack password reset link lookup could not be prepared: ' . $conn->error);
    }
}

$showResetForm = $tokenIsValid && !$passwordResetComplete;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#20143c">
<title>ProcrastiTrack — Password Recovery</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:32px 20px;font-family:'DM Sans',sans-serif;color:#241a38;background:radial-gradient(ellipse at 12% 8%,rgba(142,93,255,.32),transparent 35%),radial-gradient(ellipse at 90% 92%,rgba(248,123,72,.19),transparent 32%),#171126}
a{color:inherit;text-decoration:none}
.recovery-shell{width:min(980px,100%);min-height:600px;display:grid;grid-template-columns:.92fr 1.08fr;border:1px solid rgba(255,255,255,.48);border-radius:30px;overflow:hidden;background:#fff;box-shadow:0 32px 100px rgba(8,3,20,.38);animation:rise .65s cubic-bezier(.2,.8,.2,1) both}
@keyframes rise{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}
.story{position:relative;display:flex;flex-direction:column;justify-content:space-between;overflow:hidden;padding:44px;background:linear-gradient(145deg,#46208d 0%,#7138cb 53%,#9858e8 100%);color:white}
.story::before,.story::after{position:absolute;content:"";border:1px solid rgba(255,255,255,.14);border-radius:50%;pointer-events:none}
.story::before{width:360px;height:360px;right:-185px;top:-105px;box-shadow:0 0 0 35px rgba(255,255,255,.035),0 0 0 75px rgba(255,255,255,.025)}
.story::after{width:260px;height:260px;left:-150px;bottom:-130px;box-shadow:0 0 0 38px rgba(255,255,255,.035)}
.brand,.story-copy,.secure-note{position:relative;z-index:1}
.brand{display:flex;align-items:center;gap:12px;font-family:Manrope,sans-serif;font-size:1.04rem;font-weight:800;letter-spacing:-.03em}
.brand-mark{width:40px;height:40px;display:grid;place-items:center;border:1px solid rgba(255,255,255,.3);border-radius:13px;background:rgba(255,255,255,.15);font-size:19px}
.story-copy{margin:auto 0;padding:70px 0 55px}
.eyebrow{display:inline-flex;align-items:center;gap:8px;margin-bottom:20px;padding:7px 11px;border:1px solid rgba(255,255,255,.23);border-radius:999px;background:rgba(255,255,255,.1);font-size:.76rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase}
.spark{width:7px;height:7px;border-radius:50%;background:#fdc776;box-shadow:0 0 14px #fdc776}
h1{max-width:380px;margin:0 0 18px;font:800 clamp(2.2rem,4vw,3.35rem)/1.08 Manrope,sans-serif;letter-spacing:-.055em}
.story-copy p{max-width:350px;margin:0;color:rgba(255,255,255,.76);font-size:1rem;line-height:1.75}
.secure-note{display:flex;align-items:center;gap:10px;color:rgba(255,255,255,.78);font-size:.82rem}
.secure-icon{width:31px;height:31px;display:grid;place-items:center;border-radius:10px;background:rgba(255,255,255,.13)}
.form-side{display:flex;flex-direction:column;justify-content:center;padding:58px clamp(28px,6vw,72px);background:linear-gradient(150deg,#fff 0%,#fcfaff 100%)}
.form-content{width:100%;max-width:390px;margin:0 auto}
.step-icon{width:55px;height:55px;display:grid;place-items:center;margin-bottom:25px;border:1px solid #eee6fa;border-radius:18px;background:linear-gradient(145deg,#f7f1ff,#fff);color:#7138cb;box-shadow:0 8px 20px rgba(76,38,132,.08)}
.step-icon svg{width:25px;height:25px}
.form-heading{margin:0 0 9px;font:800 1.85rem/1.2 Manrope,sans-serif;letter-spacing:-.045em;color:#251b39}
.form-intro{margin:0 0 27px;color:#797287;font-size:.95rem;line-height:1.65}
.field-label{display:block;margin:0 0 8px;color:#4f475f;font-size:.82rem;font-weight:700}
.input-wrap{position:relative}
.input-wrap svg{position:absolute;left:15px;top:50%;width:19px;height:19px;transform:translateY(-50%);color:#938aa3;pointer-events:none}
.text-input{width:100%;height:54px;padding:0 15px 0 46px;border:1px solid #e6e0ed;border-radius:14px;outline:none;background:white;color:#281f3b;font:500 .96rem 'DM Sans',sans-serif;transition:border-color .2s,box-shadow .2s}
.text-input::placeholder{color:#aaa4b5}
.text-input:focus{border-color:#9c6be3;box-shadow:0 0 0 4px rgba(124,58,237,.11)}
.primary-button{width:100%;min-height:54px;display:flex;align-items:center;justify-content:center;gap:9px;margin-top:18px;border:0;border-radius:14px;background:linear-gradient(110deg,#672fc3,#8c50e5);color:white;font:700 .97rem 'DM Sans',sans-serif;cursor:pointer;box-shadow:0 10px 24px rgba(105,52,194,.22);transition:transform .2s,box-shadow .2s}
.primary-button:hover{transform:translateY(-2px);box-shadow:0 14px 28px rgba(105,52,194,.29)}
.primary-button:focus-visible,.back-link:focus-visible{outline:3px solid rgba(124,58,237,.3);outline-offset:3px}
.primary-button svg{width:18px;height:18px}
.notice{margin:0 0 20px;padding:13px 15px;border:1px solid;border-radius:13px;font-size:.86rem;line-height:1.55}
.notice.success{border-color:#cfeada;background:#f0fbf4;color:#24633d}
.notice.error{border-color:#f1d2d3;background:#fff5f5;color:#963c43}
.tip{display:flex;gap:11px;margin-top:22px;padding:14px;border:1px solid #eee8f5;border-radius:14px;background:#faf8fd;color:#81798e;font-size:.79rem;line-height:1.55}
.tip svg{width:17px;height:17px;flex:none;margin-top:1px;color:#9060d5}
.back-link{display:inline-flex;align-items:center;gap:8px;margin-top:25px;color:#7041b7;font-size:.87rem;font-weight:700}
.back-link:hover{color:#51248f}
.back-link svg{width:17px;height:17px}
.password-input{padding-right:16px}
.password-help{margin:8px 0 0;color:#898297;font-size:.76rem}
.password-field+.password-field{margin-top:17px}
@media(max-width:720px){body{padding:16px}.recovery-shell{max-width:500px;min-height:0;grid-template-columns:1fr;border-radius:24px}.story{min-height:235px;padding:25px 28px}.story-copy{padding:34px 0 22px}.story-copy h1{font-size:2rem}.story-copy p{font-size:.9rem}.secure-note{font-size:.75rem}.form-side{padding:34px 27px 38px}.step-icon{margin-bottom:18px}}
@media(max-width:380px){body{padding:10px}.story{padding:22px}.form-side{padding:28px 20px}.story-copy h1{font-size:1.75rem}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{scroll-behavior:auto!important;animation-duration:.01ms!important;transition-duration:.01ms!important}}
</style>
</head>
<body>
<main class="recovery-shell">
  <section class="story" aria-label="Password recovery information">
    <a class="brand" href="landing.html"><span class="brand-mark">P</span><span>ProcrastiTrack</span></a>
    <div class="story-copy">
      <div class="eyebrow"><span class="spark"></span> Your progress is safe</div>
      <h1><?php echo $showResetForm ? 'A fresh start, one password away.' : 'Let’s get you back on track.'; ?></h1>
      <p><?php echo $showResetForm ? 'Choose a strong new password and get back to building better habits.' : 'It happens. Verify your email and we’ll send you a secure link to reset your password.'; ?></p>
    </div>
    <div class="secure-note"><span class="secure-icon" aria-hidden="true">&#128274;</span><span>Secure, private password recovery</span></div>
  </section>

  <section class="form-side">
    <div class="form-content">
      <div class="step-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4.5 6v5.5c0 4.4 3.2 7.7 7.5 9.5 4.3-1.8 7.5-5.1 7.5-9.5V6L12 3Z"/><path d="m9 12 2 2 4-4"/></svg>
      </div>
      <?php if ($message !== ''): ?>
        <div class="notice <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
      <?php endif; ?>

      <?php if ($showResetForm): ?>
        <h2 class="form-heading">Create a new password</h2>
        <p class="form-intro">Make it strong and unique to your ProcrastiTrack account.</p>
        <form method="post" action="forgot_password.php?token=<?php echo rawurlencode($token); ?>">
          <?php app_csrf_field(); ?>
          <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
          <div class="password-field">
            <label class="field-label" for="new-password">New password</label>
            <input class="text-input password-input" id="new-password" type="password" name="password" autocomplete="new-password" minlength="8" placeholder="At least 8 characters" required>
            <p class="password-help">Use at least 8 characters.</p>
          </div>
          <div class="password-field">
            <label class="field-label" for="confirm-password">Confirm new password</label>
            <input class="text-input password-input" id="confirm-password" type="password" name="confirm_password" autocomplete="new-password" placeholder="Enter your password again" required>
          </div>
          <button class="primary-button" type="submit" name="reset_password" value="1">Save new password <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></button>
        </form>
      <?php elseif ($passwordResetComplete): ?>
        <h2 class="form-heading">Password updated</h2>
        <p class="form-intro">Your new password is ready. Sign in to continue your productivity journey.</p>
        <a class="primary-button" href="login.php">Go to sign in <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
      <?php elseif ($token !== '' && !$tokenIsValid): ?>
        <h2 class="form-heading">Link expired or invalid</h2>
        <p class="form-intro">For your security, reset links work once and expire after one hour. Request a fresh link below.</p>
        <form method="post" action="forgot_password.php">
          <?php app_csrf_field(); ?>
          <label class="field-label" for="recovery-email">Email address</label>
          <div class="input-wrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg><input class="text-input" id="recovery-email" type="email" name="email" autocomplete="email" placeholder="you@example.com" required></div>
          <button class="primary-button" type="submit" name="request_reset" value="1">Send a fresh recovery link <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></button>
        </form>
      <?php else: ?>
        <h2 class="form-heading">Forgot your password?</h2>
        <p class="form-intro">Enter the email linked to your account. We’ll send you a one-time reset link.</p>
        <form method="post" action="forgot_password.php">
          <?php app_csrf_field(); ?>
          <label class="field-label" for="recovery-email">Email address</label>
          <div class="input-wrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg><input class="text-input" id="recovery-email" type="email" name="email" autocomplete="email" placeholder="you@example.com" required></div>
          <button class="primary-button" type="submit" name="request_reset" value="1">Send recovery link <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></button>
        </form>
        <div class="tip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg><span>The reset link expires after one hour. If you don’t see it, check your spam folder.</span></div>
      <?php endif; ?>

      <a class="back-link" href="login.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/><path d="M20 12H9"/></svg>Back to sign in</a>
    </div>
  </section>
</main>
<script defer src="assets/js/auto-dismiss-notices.js?v=20261124"></script>
</body>
</html>
