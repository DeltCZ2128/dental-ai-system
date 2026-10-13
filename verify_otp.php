<?php

require_once __DIR__ . '/config.php';

/*
|--------------------------------------------------------------------------
| VERIFY_OTP.PHP - Patient Email Verification
|--------------------------------------------------------------------------
| Verifies new patient email addresses via a 6-digit OTP code before
| granting access to the system.
*/

// If user is already logged in and verified, send to role dashboard
if (isset($_SESSION['user_id'])) {
    $existingRole = strtolower(trim($_SESSION['role'] ?? ''));
    if ($existingRole === 'admin') {
        header("Location: admin/dashboard.php");
        exit;
    } elseif ($existingRole === 'dentist') {
        header("Location: dentist/dentist_dashboard.php");
        exit;
    } elseif ($existingRole === 'patient') {
        // Check if verified in DB
        $stmt = $pdo->prepare("SELECT is_verified FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $chk = $stmt->fetch();
        if ($chk && (int)$chk['is_verified'] === 1) {
            header("Location: patient/patient_dashboard.php");
            exit;
        }
    }
}

// Resolve pending user from session or GET parameter
$pendingUserId = (int)($_SESSION['pending_verify_user_id'] ?? 0);
$pendingEmail = trim((string)($_SESSION['pending_verify_email'] ?? ($_GET['email'] ?? '')));

if ($pendingUserId <= 0 && $pendingEmail !== '') {
    $stmt = $pdo->prepare("SELECT id, username, full_name, role, is_verified FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$pendingEmail]);
    $u = $stmt->fetch();
    if ($u) {
        $pendingUserId = (int)$u['id'];
        $_SESSION['pending_verify_user_id'] = $pendingUserId;
        $_SESSION['pending_verify_email'] = $u['username'];
        $_SESSION['pending_verify_name'] = $u['full_name'];
    }
}

// If no pending user can be identified, send to login
if ($pendingUserId <= 0) {
    header("Location: login.php");
    exit;
}

// Fetch current user details from database
$stmt = $pdo->prepare("
    SELECT id, username, full_name, role, is_verified, otp_code, otp_expires_at
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$pendingUserId]);
$user = $stmt->fetch();

if (!$user) {
    unset($_SESSION['pending_verify_user_id'], $_SESSION['pending_verify_email'], $_SESSION['pending_verify_name']);
    header("Location: login.php");
    exit;
}

// If account is already verified, redirect to login with verified notice
if ((int)($user['is_verified'] ?? 1) === 1) {
    unset($_SESSION['pending_verify_user_id'], $_SESSION['pending_verify_email'], $_SESSION['pending_verify_name']);
    header("Location: login.php?verified=1");
    exit;
}

$error = "";
$success = "";
$info = "";

if (isset($_GET['unverified']) && $_GET['unverified'] === '1') {
    $info = "Please verify your email address before signing in. A verification code has been sent to your inbox.";
}

if (!empty($_SESSION['pending_verify_error'])) {
    $error = "Notice: " . $_SESSION['pending_verify_error'];
    unset($_SESSION['pending_verify_error']);
}

// Helper: Mask email address for privacy (e.g. ro***1@gmail.com)
function maskEmailAddress($email)
{
    $parts = explode('@', (string)$email);
    if (count($parts) < 2) return $email;
    $name = $parts[0];
    $domain = $parts[1];
    $len = strlen($name);
    if ($len <= 2) {
        $masked = substr($name, 0, 1) . '*';
    } elseif ($len <= 4) {
        $masked = substr($name, 0, 1) . '**' . substr($name, -1);
    } else {
        $masked = substr($name, 0, 2) . str_repeat('*', min(5, $len - 3)) . substr($name, -1);
    }
    return $masked . '@' . $domain;
}

/*
|--------------------------------------------------------------------------
| HANDLE POST REQUESTS (VERIFY OR RESEND)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $action = trim($_POST['action'] ?? 'verify');

    if ($action === 'resend') {
        // Rate limit: 60 seconds
        $lastResend = (int)($_SESSION['last_otp_resend_time'] ?? 0);
        $secondsSince = time() - $lastResend;

        if ($secondsSince < 60) {
            $remaining = 60 - $secondsSince;
            $error = "Please wait {$remaining} seconds before requesting another code.";
        } else {
            list($sentOk, $sendErr, $newOtp) = sendPatientVerificationOtp($user['id'], $user['username'], $user['full_name']);
            if ($sentOk) {
                $_SESSION['last_otp_resend_time'] = time();
                $success = "A fresh 6-digit verification code has been sent to your email!";
                // Refresh local user record
                $stmt->execute([$pendingUserId]);
                $user = $stmt->fetch();
            } else {
                $error = !empty($sendErr) ? $sendErr : "Failed to send verification email. Please try again.";
            }
        }
    } else {
        // Verify action
        $enteredOtp = trim($_POST['otp'] ?? '');
        $enteredOtp = preg_replace('/\D/', '', $enteredOtp);

        if (strlen($enteredOtp) !== 6) {
            $error = "Please enter the complete 6-digit verification code.";
        } elseif (empty($user['otp_code'])) {
            $error = "No active verification code found. Please click 'Resend Code'.";
        } elseif (hash_equals((string)$user['otp_code'], (string)$enteredOtp) === false) {
            $error = "Incorrect verification code. Please check your email and try again.";
        } elseif (strtotime((string)$user['otp_expires_at']) < time()) {
            $error = "The verification code has expired (valid for 10 minutes). Please click 'Resend Code'.";
        } else {
            // Valid OTP! Mark account as verified
            try {
                $updateStmt = $pdo->prepare("
                    UPDATE users
                    SET is_verified = 1,
                        otp_code = NULL,
                        otp_expires_at = NULL
                    WHERE id = ?
                ");
                $updateStmt->execute([$user['id']]);

                // Also ensure patient profile is active
                try {
                    $pdo->prepare("UPDATE patients SET status = 'active' WHERE user_id = ?")->execute([$user['id']]);
                } catch (Throwable $pe) {}

                // Clear pending verify session
                unset(
                    $_SESSION['pending_verify_user_id'],
                    $_SESSION['pending_verify_email'],
                    $_SESSION['pending_verify_name'],
                    $_SESSION['pending_verify_error'],
                    $_SESSION['last_otp_resend_time']
                );

                // Auto-login user into patient dashboard
                session_regenerate_id(false);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['just_verified'] = true;

                header("Location: patient/patient_dashboard.php?verified=1");
                exit;

            } catch (PDOException $e) {
                $error = "A database error occurred. Please try again.";
            }
        }
    }
}

// Calculate remaining seconds for countdown timer
$lastResendTime = (int)($_SESSION['last_otp_resend_time'] ?? 0);
$elapsed = time() - $lastResendTime;
$cooldownRemaining = max(0, 60 - $elapsed);
$maskedEmail = maskEmailAddress($user['username']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email | Abalos Dental Clinic</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            background: #022c22;
            color: #0f172a;
            position: relative;
            overflow-x: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }

        /* Animated Emerald Background */
        .page-wrapper {
            position: fixed;
            inset: 0;
            background: linear-gradient(135deg, #022c22, #065f46, #047857, #059669, #022c22);
            background-size: 300% 300%;
            animation: bgShift 14s ease infinite;
            z-index: 0;
        }

        @keyframes bgShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Floating dental icons layer */
        .float-layer {
            position: absolute;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 1;
        }

        .float-icon {
            position: absolute;
            color: rgba(255, 255, 255, 0.28);
            animation: floatUp linear infinite;
            text-shadow: 0 0 16px rgba(167, 243, 208, 0.4);
        }

        @keyframes floatUp {
            0% { transform: translateY(60px) rotate(0deg); opacity: 0; }
            10% { opacity: 0.9; }
            90% { opacity: 0.9; }
            100% { transform: translateY(-800px) rotate(25deg); opacity: 0; }
        }

        /* Verification Card Shell */
        .verify-card {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 500px;
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid rgba(255, 255, 255, 0.6);
            border-radius: 26px;
            box-shadow: 0 25px 70px rgba(2, 44, 34, 0.4), 0 10px 30px rgba(5, 150, 105, 0.2);
            padding: 44px 38px;
            text-align: center;
        }

        /* Icon Badge Header */
        .icon-badge {
            width: 76px;
            height: 76px;
            margin: 0 auto 20px;
            border-radius: 22px;
            background: linear-gradient(135deg, #059669, #022c22);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
            box-shadow: 0 12px 28px rgba(5, 150, 105, 0.35);
        }

        .verify-title {
            font-size: 24px;
            font-weight: 800;
            color: #022c22;
            letter-spacing: -0.5px;
            margin-bottom: 8px;
        }

        .verify-subtitle {
            font-size: 14px;
            color: #64748b;
            line-height: 1.55;
            margin-bottom: 20px;
        }

        .email-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #eaf7f0;
            border: 1px solid #a7f3d0;
            color: #047857;
            padding: 7px 16px;
            border-radius: 999px;
            font-size: 13.5px;
            font-weight: 700;
            margin-bottom: 24px;
        }

        /* OTP Input Field */
        .otp-input-wrapper {
            margin: 22px 0 26px;
        }

        .otp-input {
            width: 100%;
            height: 64px;
            font-family: 'Space Grotesk', 'Courier New', monospace;
            font-size: 32px;
            font-weight: 800;
            letter-spacing: 14px;
            text-align: center;
            color: #022c22;
            background: #f8fafc;
            border: 2px solid #cbd5e1;
            border-radius: 16px;
            transition: all 0.25s ease;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.04);
            padding-left: 14px; /* balance letter spacing visually */
        }

        .otp-input:focus {
            outline: none;
            border-color: #059669;
            background: #ffffff;
            box-shadow: 0 0 0 5px rgba(5, 150, 105, 0.18), inset 0 2px 4px rgba(0, 0, 0, 0.02);
        }

        .btn-verify {
            width: 100%;
            height: 52px;
            border-radius: 14px;
            background: linear-gradient(135deg, #059669, #047857);
            border: none;
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.3px;
            transition: all 0.25s ease;
            box-shadow: 0 10px 24px rgba(5, 150, 105, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-verify:hover {
            background: linear-gradient(135deg, #047857, #022c22);
            transform: translateY(-2px);
            box-shadow: 0 14px 30px rgba(5, 150, 105, 0.38);
            color: #ffffff;
        }

        .btn-verify:active {
            transform: translateY(0);
        }

        /* Resend Section */
        .resend-section {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            font-size: 13.5px;
            color: #64748b;
        }

        .btn-resend {
            background: none;
            border: none;
            padding: 0;
            color: #059669;
            font-weight: 700;
            cursor: pointer;
            text-decoration: underline;
            transition: color 0.2s;
        }

        .btn-resend:hover {
            color: #022c22;
        }

        .btn-resend:disabled {
            color: #94a3b8;
            cursor: not-allowed;
            text-decoration: none;
        }

        .timer-badge {
            font-weight: 700;
            color: #059669;
        }

        /* Links */
        .footer-links {
            margin-top: 20px;
            font-size: 13px;
            display: flex;
            justify-content: center;
            gap: 16px;
        }

        .footer-links a {
            color: #64748b;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }

        .footer-links a:hover {
            color: #059669;
        }

        /* Alerts */
        .alert-custom {
            border-radius: 14px;
            font-size: 13.5px;
            font-weight: 600;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            text-align: left;
            gap: 10px;
        }

        .alert-danger-custom {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .alert-success-custom {
            background: #eaf7f0;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }

        .alert-info-custom {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
        }
    </style>
</head>
<body>

    <!-- Animated Background -->
    <div class="page-wrapper"></div>

    <!-- Floating Background Icons -->
    <div class="float-layer" aria-hidden="true">
        <span class="float-icon" style="left:8%; bottom:-40px; font-size:42px; animation-duration:8.5s; animation-delay:0s;"><i class="bi bi-shield-check"></i></span>
        <span class="float-icon" style="left:22%; bottom:-40px; font-size:28px; animation-duration:10.5s; animation-delay:1.5s;"><i class="bi bi-envelope-check-fill"></i></span>
        <span class="float-icon" style="left:38%; bottom:-40px; font-size:46px; animation-duration:7.8s; animation-delay:2.2s;"><i class="bi bi-heart-pulse-fill"></i></span>
        <span class="float-icon" style="left:55%; bottom:-40px; font-size:32px; animation-duration:9.2s; animation-delay:0.7s;"><i class="bi bi-patch-check-fill"></i></span>
        <span class="float-icon" style="left:72%; bottom:-40px; font-size:48px; animation-duration:8.0s; animation-delay:2.8s;"><i class="bi bi-calendar2-check-fill"></i></span>
        <span class="float-icon" style="left:88%; bottom:-40px; font-size:36px; animation-duration:9.8s; animation-delay:1.2s;"><i class="bi bi-lock-fill"></i></span>
    </div>

    <!-- Main Verification Card -->
    <div class="verify-card">
        
        <div class="icon-badge">
            <i class="bi bi-envelope-paper-heart-fill"></i>
        </div>

        <h1 class="verify-title">Verify Your Email</h1>
        
        <p class="verify-subtitle">
            We've sent a 6-digit verification code to confirm that this is your legitimate email address:
        </p>

        <div class="email-pill">
            <i class="bi bi-envelope-fill"></i>
            <span><?= htmlspecialchars($maskedEmail) ?></span>
        </div>

        <!-- ALERTS -->
        <?php if (!empty($info)): ?>
            <div class="alert-custom alert-info-custom" role="alert">
                <i class="bi bi-info-circle-fill fs-5 text-primary"></i>
                <div><?= htmlspecialchars($info) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert-custom alert-danger-custom" role="alert">
                <i class="bi bi-exclamation-triangle-fill fs-5 text-danger"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-custom alert-success-custom" role="alert">
                <i class="bi bi-check-circle-fill fs-5 text-success"></i>
                <div><?= htmlspecialchars($success) ?></div>
            </div>
        <?php endif; ?>

        <!-- VERIFY OTP FORM -->
        <form method="POST" action="verify_otp.php" id="otpForm" autocomplete="off">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="verify">

            <div class="otp-input-wrapper">
                <input
                    type="text"
                    id="otpInput"
                    name="otp"
                    class="otp-input"
                    maxlength="6"
                    placeholder="------"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    autocomplete="one-time-code"
                    required
                    autofocus
                >
                <div class="mt-2 text-muted" style="font-size: 12.5px;">
                    <i class="bi bi-clock-history me-1"></i> Code expires in <strong>10 minutes</strong>
                </div>
            </div>

            <button type="submit" class="btn btn-verify" id="verifyBtn">
                <i class="bi bi-check2-circle fs-5"></i>
                <span>Verify & Activate Account</span>
            </button>
        </form>

        <!-- RESEND OTP SECTION -->
        <div class="resend-section">
            <div id="countdownWrapper" <?= $cooldownRemaining > 0 ? '' : 'style="display:none;"' ?>>
                Didn't receive the code? Resend in 
                <span class="timer-badge" id="countdownTimer"><?= $cooldownRemaining ?>s</span>
            </div>

            <form method="POST" action="verify_otp.php" id="resendForm" style="display: <?= $cooldownRemaining > 0 ? 'none' : 'inline' ?>;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="resend">
                <span>Didn't receive the email? </span>
                <button type="submit" class="btn-resend" id="resendBtn">
                    Click here to resend code
                </button>
            </form>
        </div>

        <!-- FOOTER LINKS -->
        <div class="footer-links">
            <a href="login.php">
                <i class="bi bi-box-arrow-in-right me-1"></i> Back to Login
            </a>
            <span style="color: #cbd5e1;">&bull;</span>
            <a href="register.php">
                <i class="bi bi-pencil-square me-1"></i> Register Again
            </a>
        </div>

    </div>

    <script>
        const otpInput = document.getElementById('otpInput');
        const otpForm = document.getElementById('otpForm');
        const countdownTimer = document.getElementById('countdownTimer');
        const countdownWrapper = document.getElementById('countdownWrapper');
        const resendForm = document.getElementById('resendForm');

        // Only allow numbers and auto-submit on 6 digits
        otpInput.addEventListener('input', function(e) {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
            if (this.value.length === 6) {
                otpForm.submit();
            }
        });

        // Cooldown timer handler
        let remainingSeconds = <?= (int)$cooldownRemaining ?>;
        if (remainingSeconds > 0) {
            const interval = setInterval(function() {
                remainingSeconds--;
                if (remainingSeconds <= 0) {
                    clearInterval(interval);
                    if (countdownWrapper) countdownWrapper.style.display = 'none';
                    if (resendForm) resendForm.style.display = 'inline';
                } else {
                    if (countdownTimer) countdownTimer.textContent = remainingSeconds + 's';
                }
            }, 1000);
        }
    </script>
</body>
</html>
