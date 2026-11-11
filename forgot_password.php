<?php

require_once __DIR__ . '/config.php';

$message = '';
$messageType = 'success';

/*
|--------------------------------------------------------------------------
| CROSS-SITE REQUEST FORGERY CHECK
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $messageType = 'danger';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id
                FROM users
                WHERE username = ?
                LIMIT 1
            ");

            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $token = bin2hex(random_bytes(32));

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET reset_token = ?,
                        reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR)
                    WHERE id = ?
                ");

                $stmt->execute([$token, (int)$user['id']]);

                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    ? 'https'
                    : 'http';

                $basePath = rtrim(
                    str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')),
                    '/'
                );

                $resetLink = $scheme . '://' . $_SERVER['HTTP_HOST'] .
                    ($basePath !== '' ? $basePath : '') .
                    '/reset_password.php?token=' . $token;

                $subject = 'Password Reset - Abalos Dental Clinic';

                $body =
                    "Hello,\n\n" .
                    "You requested a password reset for your Abalos Dental Clinic account.\n\n" .
                    "Click the link below within 1 hour to set a new password:\n" .
                    $resetLink . "\n\n" .
                    "If you did not request this, please ignore this email.\n\n" .
                    "Thank you.";

                list($sent, $sendError) = sendClinicGmail($email, $subject, $body);

                if (!$sent) {
                    $message = 'Could not send reset email: ' . $sendError;
                    $messageType = 'danger';
                }
            }

            if ($message === '') {
                $message = 'If the email exists in our records, a reset link was sent. Please check your Gmail inbox (and spam folder).';
                $messageType = 'success';
            }
        } catch (Throwable $e) {
            $message = 'Unable to process request. Please try again later.';
            $messageType = 'danger';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot Password | Abalos Dental Clinic</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: "Inter", "DM Sans", system-ui, sans-serif;
    min-height: 100vh;
    background: linear-gradient(135deg, #022c22, #059669, #065f46, #10b981, #022c22);
    background-size: 300% 300%;
    animation: loginGradientMove 6s ease infinite;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 25px;
    color: #173b4d;
    position: relative;
    overflow-x: hidden;
}

@keyframes loginGradientMove {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

/* Animated floating dental icons — identical to login */
.login-float-layer {
    position: fixed;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
    z-index: 0;
}

.login-float {
    position: absolute;
    color: rgba(255,255,255,.42);
    line-height: 1;
    animation: loginFloatUp linear infinite;
    text-shadow: 0 0 12px rgba(255,255,255,.35), 0 0 28px rgba(167,243,208,.25);
    filter: drop-shadow(0 4px 10px rgba(0,0,0,.15));
}

.login-float i {
    display: block;
    animation: loginSpinPulse 4s ease-in-out infinite alternate;
}

.login-float:nth-child(odd) i {
    animation: loginSway 3.5s ease-in-out infinite alternate;
}

.login-float:nth-child(3n) {
    color: rgba(167,243,208,.55);
}

@keyframes loginFloatUp {
    0% { transform: translateY(60px) rotate(0deg); opacity: 0; }
    10% { opacity: 1; }
    90% { opacity: 1; }
    100% { transform: translateY(-800px) rotate(25deg); opacity: 0; }
}

@keyframes loginSpinPulse {
    0% { transform: rotate(-10deg) scale(1); }
    100% { transform: rotate(12deg) scale(1.18); }
}

@keyframes loginSway {
    0% { transform: translateX(-8px) rotate(-8deg) scale(1); }
    100% { transform: translateX(8px) rotate(10deg) scale(1.15); }
}

/* Card Shell */
.reset-card {
    width: 100%;
    max-width: 480px;
    background: rgba(255, 255, 255, .95);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border: 1px solid rgba(255, 255, 255, .65);
    border-radius: 26px;
    padding: 44px 40px;
    box-shadow:
        0 25px 70px rgba(2, 44, 34, .35),
        0 8px 25px rgba(5, 150, 105, .18);
    position: relative;
    z-index: 2;
}

.logo-circle {
    width: 64px;
    height: 64px;
    margin: 0 auto 20px;
    border-radius: 18px;
    background: linear-gradient(135deg, #149A72, #0A4A41);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    box-shadow: 0 12px 25px rgba(14, 107, 94, .28);
}

.reset-card h2 {
    font-family: "Plus Jakarta Sans", sans-serif;
    color: #153f50;
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin-bottom: 6px;
}

.reset-card p.subtitle {
    font-size: 13.5px;
    color: #64748b;
    line-height: 1.5;
    margin-bottom: 24px;
}

.form-label {
    color: #365d6c;
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 7px;
}

.form-control {
    height: 48px;
    border: 1px solid #dbe9ed;
    border-radius: 12px;
    background: #fbfdfe;
    color: #254f60;
    font-size: 14px;
    padding: 0 16px;
    transition: all .2s ease;
}

.form-control:focus {
    border-color: #0E6B5E;
    box-shadow: 0 0 0 3px rgba(14, 107, 94, .12);
    background: #ffffff;
    color: #173b4d;
}

.btn-submit {
    height: 50px;
    border: none;
    border-radius: 12px;
    background: linear-gradient(135deg, #149A72, #0A4A41);
    color: white;
    font-size: 15px;
    font-weight: 800;
    box-shadow: 0 8px 20px rgba(14, 107, 94, .28);
    transition: all .2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.btn-submit:hover {
    background: linear-gradient(135deg, #12B187, #0A4A41);
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 12px 24px rgba(14, 107, 94, .32);
}

.back-link {
    color: #0E6B5E;
    font-weight: 700;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    transition: color .2s ease;
}

.back-link:hover {
    color: #0A4A41;
    text-decoration: underline;
}

.alert-success {
    background: #EAF7F0;
    border: 1px solid #A7F3D0;
    color: #065F46;
    border-radius: 12px;
    font-size: 13px;
    padding: 12px 14px;
}

.alert-danger {
    background: #FDECEC;
    border: 1px solid #FECACA;
    color: #991B1B;
    border-radius: 12px;
    font-size: 13px;
    padding: 12px 14px;
}

@media (max-width: 500px) {
    body {
        padding: 15px;
    }
    .reset-card {
        padding: 32px 24px;
        border-radius: 20px;
    }
}
</style>
</head>
<body>

<!-- Animated floating dental icons layer -->
<div class="login-float-layer">
    <span class="login-float" style="left:5%; bottom:-40px; font-size:42px; animation-duration:6.5s; animation-delay:0s;"><i class="bi bi-heart-pulse-fill"></i></span>
    <span class="login-float" style="left:15%; bottom:-40px; font-size:26px; animation-duration:8.5s; animation-delay:1s;"><i class="bi bi-plus-circle-fill"></i></span>
    <span class="login-float" style="left:28%; bottom:-40px; font-size:56px; animation-duration:7.5s; animation-delay:0.5s;"><i class="bi bi-shield-check"></i></span>
    <span class="login-float" style="left:42%; bottom:-40px; font-size:30px; animation-duration:9.5s; animation-delay:1.5s;"><i class="bi bi-clipboard2-pulse-fill"></i></span>
    <span class="login-float" style="left:55%; bottom:-40px; font-size:48px; animation-duration:7s; animation-delay:0.2s;"><i class="bi bi-capsule"></i></span>
    <span class="login-float" style="left:68%; bottom:-40px; font-size:28px; animation-duration:9s; animation-delay:1.2s;"><i class="bi bi-bandaid-fill"></i></span>
    <span class="login-float" style="left:80%; bottom:-40px; font-size:60px; animation-duration:8s; animation-delay:0.7s;"><i class="bi bi-stars"></i></span>
    <span class="login-float" style="left:92%; bottom:-40px; font-size:34px; animation-duration:10s; animation-delay:2s;"><i class="bi bi-emoji-laughing-fill"></i></span>
    <span class="login-float" style="left:10%; bottom:-40px; font-size:32px; animation-duration:7.5s; animation-delay:2.5s;"><i class="bi bi-calendar-plus-fill"></i></span>
    <span class="login-float" style="left:22%; bottom:-40px; font-size:44px; animation-duration:6s; animation-delay:3s;"><i class="bi bi-emoji-smile-fill"></i></span>
    <span class="login-float" style="left:35%; bottom:-40px; font-size:24px; animation-duration:10.5s; animation-delay:0.6s;"><i class="bi bi-clipboard-check-fill"></i></span>
    <span class="login-float" style="left:48%; bottom:-40px; font-size:52px; animation-duration:6.8s; animation-delay:1.7s;"><i class="bi bi-hospital-fill"></i></span>
    <span class="login-float" style="left:3%; bottom:-40px; font-size:30px; animation-duration:7s; animation-delay:0.3s;"><i class="bi bi-check-circle-fill"></i></span>
    <span class="login-float" style="left:12%; bottom:-40px; font-size:50px; animation-duration:8.8s; animation-delay:1.8s;"><i class="bi bi-person-check-fill"></i></span>
    <span class="login-float" style="left:25%; bottom:-40px; font-size:28px; animation-duration:6.2s; animation-delay:0.9s;"><i class="bi bi-patch-plus-fill"></i></span>
    <span class="login-float" style="left:38%; bottom:-40px; font-size:45px; animation-duration:9.3s; animation-delay:2.1s;"><i class="bi bi-shield-fill-check"></i></span>
    <span class="login-float" style="left:52%; bottom:-40px; font-size:31px; animation-duration:7.8s; animation-delay:1.4s;"><i class="bi bi-brightness-alt-high-fill"></i></span>
    <span class="login-float" style="left:65%; bottom:-40px; font-size:54px; animation-duration:6.4s; animation-delay:2.9s;"><i class="bi bi-calendar2-check-fill"></i></span>
    <span class="login-float" style="left:78%; bottom:-40px; font-size:26px; animation-duration:9.6s; animation-delay:0.6s;"><i class="bi bi-droplet-fill"></i></span>
    <span class="login-float" style="left:90%; bottom:-40px; font-size:40px; animation-duration:7.4s; animation-delay:1.9s;"><i class="bi bi-award-fill"></i></span>
    <span class="login-float" style="left:61%; bottom:-40px; font-size:29px; animation-duration:9.2s; animation-delay:0.4s;"><i class="bi bi-suit-heart-fill"></i></span>
    <span class="login-float" style="left:74%; bottom:-40px; font-size:46px; animation-duration:7.2s; animation-delay:2.7s;"><i class="bi bi-patch-check-fill"></i></span>
    <span class="login-float" style="left:86%; bottom:-40px; font-size:27px; animation-duration:9.8s; animation-delay:1.1s;"><i class="bi bi-gem"></i></span>
    <span class="login-float" style="left:97%; bottom:-40px; font-size:38px; animation-duration:8.2s; animation-delay:2.2s;"><i class="bi bi-heart-fill"></i></span>
</div>

<div class="reset-card">
    <div class="text-center">
        <div class="logo-circle">
            <i class="bi bi-key-fill"></i>
        </div>
        <h2>Forgot Password</h2>
        <p class="subtitle">Enter your account email. We will send a secure password reset link to your email.</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= e($messageType) ?> d-flex align-items-center mb-3" role="alert">
            <i class="bi <?= $messageType === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2 fs-5"></i>
            <div><?= e($message) ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <?= csrfField() ?>
        <div class="mb-3">
            <label class="form-label">Email Address</label>
            <input type="email" name="email" class="form-control" placeholder="Enter your email" required value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <button type="submit" class="btn btn-submit w-100">
            <i class="bi bi-send-fill me-2"></i>Send Reset Link
        </button>
    </form>

    <div class="text-center mt-4">
        <a href="login.php" class="back-link">
            <i class="bi bi-arrow-left me-1"></i>Back to Login
        </a>
    </div>
</div>

</body>
</html>
