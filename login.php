<?php

require_once "config.php";

/*
|--------------------------------------------------------------------------
| LOGIN.PHP
|--------------------------------------------------------------------------
*/

if (isset($_SESSION['user_id'])) {
    $existingRole = strtolower(trim($_SESSION['role'] ?? ''));
    if ($existingRole === 'admin') {
        header("Location: admin/dashboard.php");
    } elseif ($existingRole === 'patient') {
        header("Location: patient/patient_dashboard.php");
    } elseif ($existingRole === 'dentist') {
        header("Location: dentist/dentist_dashboard.php");
    } else {
        header("Location: dashboard.php");
    }
    exit;
}

$error = "";
$successMessage = "";

if (isset($_GET['verified']) && $_GET['verified'] === '1') {
    $successMessage = "Your email has been verified successfully! You may now sign in to your account.";
}


/*
|--------------------------------------------------------------------------
| CROSS-SITE REQUEST FORGERY CHECK
|--------------------------------------------------------------------------
| Every request that changes data is compared with the token held in the
| session before any handler of this page runs. A submission that carries
| no token, or a token that does not match, is refused.
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === "" || $password === "") {

        $error = "Please enter your email and password.";

    } else {

        /*
        |--------------------------------------------------------------------------
        | FIND USER
        |--------------------------------------------------------------------------
        | Registration uses email as username.
        */

        $stmt = $pdo->prepare("
            SELECT *
            FROM users
            WHERE username = ?
            LIMIT 1
        ");

        $stmt->execute([$username]);

        $user = $stmt->fetch();

        /*
        |--------------------------------------------------------------------------
        | VERIFY PASSWORD
        |--------------------------------------------------------------------------
        */

        if (
            $user &&
            password_verify($password, $user['password'])
        ) {

            /*
            |--------------------------------------------------------------------------
            | CHECK EMAIL VERIFICATION (FOR PATIENTS)
            |--------------------------------------------------------------------------
            */
            if (
                strtolower(trim($user['role'] ?? '')) === 'patient' &&
                isset($user['is_verified']) &&
                (int)$user['is_verified'] === 0
            ) {
                $_SESSION['pending_verify_user_id'] = (int)$user['id'];
                $_SESSION['pending_verify_email'] = $user['username'];
                $_SESSION['pending_verify_name'] = $user['full_name'];

                // Automatically resend fresh OTP if expired or missing
                $expiresAt = strtotime((string)($user['otp_expires_at'] ?? ''));
                if (!$expiresAt || $expiresAt <= time() || empty($user['otp_code'])) {
                    sendPatientVerificationOtp((int)$user['id'], $user['username'], $user['full_name']);
                }

                header("Location: verify_otp.php?unverified=1");
                exit;
            }

            /*
            |--------------------------------------------------------------------------
            | CREATE SESSION
            |--------------------------------------------------------------------------
            | The session identifier is replaced as soon as the credentials are
            | accepted. A pre-login identifier that an attacker may have been
            | able to fix in advance therefore becomes useless, which closes
            | the session fixation weakness.
            */

            session_regenerate_id(false);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];

            /*
            |--------------------------------------------------------------------------
            | REDIRECT BY ROLE - restored existing system routing
            |--------------------------------------------------------------------------
            */
            $loginRole = strtolower(trim($user['role'] ?? ''));
            if ($loginRole === 'admin') {
                header("Location: admin/dashboard.php");
            } elseif ($loginRole === 'patient') {
                header("Location: patient/patient_dashboard.php");
            } elseif ($loginRole === 'dentist') {
                header("Location: dentist/dentist_dashboard.php");
            } else {
                header("Location: dashboard.php");
            }
            exit;

        } else {

            $error = "Invalid email or password.";

        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <title>Abalos Dental Clinic | Login</title>

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link
        href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
        rel="stylesheet">


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
    overflow: hidden;
}

@keyframes loginGradientMove {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

/* Animated dental icons - tulad sa register */
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


/* =========================================================
   SPLIT-SCREEN SHELL
========================================================= */

.login-shell {

    width: 100%;

    max-width: 920px;

    background: rgba(255,255,255,.94);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border: 1px solid rgba(255,255,255,.55);

    border-radius: 26px;

    overflow: hidden;

    display: flex;

    box-shadow:
        0 25px 70px rgba(2,44,34,.35),
        0 8px 25px rgba(5,150,105,.18);
    position: relative;
    z-index: 2;

}


/* =========================================================
   BRAND PANEL
========================================================= */

.login-brand {

    flex: 0 0 400px;

    background:
        radial-gradient(
            circle at 85% 12%,
            rgba(255,255,255,.14),
            transparent 34%
        ),
        radial-gradient(
            circle at 8% 92%,
            rgba(255,255,255,.10),
            transparent 36%
        ),
        linear-gradient(
            150deg,
            #149A72 0%,
            #0E6B5E 45%,
            #0A4A41 100%
        );

    color: #ffffff;

    padding: 48px 42px;

    display: flex;

    flex-direction: column;

    justify-content: center;

    position: relative;

    overflow: hidden;

}

.login-brand::after {

    content: "";

    position: absolute;

    width: 230px;
    height: 230px;

    border-radius: 50%;

    background: rgba(255,255,255,.08);

    right: -80px;
    bottom: -90px;

}

.login-brand-logo {

    width: 62px;
    height: 62px;

    border-radius: 18px;

    background: rgba(255,255,255,.16);

    border: 1px solid rgba(201,162,39,.55);

    display: flex;

    align-items: center;
    justify-content: center;

    font-size: 30px;

    margin-bottom: 22px;

}

.login-brand h1 {

    font-family: "Fraunces", serif;

    font-size: 30px;
    font-weight: 700;
    letter-spacing: -0.01em;

    margin-bottom: 8px;

}

.login-brand h1 span {

    color: #E9CE7A;

}

.gold-rule {

    width: 56px;
    height: 3px;

    background: #C9A227;

    border-radius: 2px;

    margin-bottom: 26px;

}

.login-brand .tagline {

    font-size: 13.5px;

    color: #D9F2E6;

    margin-bottom: 30px;

}

.brand-features {

    display: flex;
    flex-direction: column;
    gap: 16px;

    position: relative;
    z-index: 2;

}

.brand-feature {

    display: flex;
    align-items: center;
    gap: 13px;

}

.brand-feature i {

    width: 40px;
    height: 40px;
    flex-shrink: 0;

    border-radius: 12px;

    background: rgba(255,255,255,.16);

    display: flex;

    align-items: center;
    justify-content: center;

    font-size: 18px;

}

.brand-feature strong {

    display: block;
    font-size: 13.5px;

}

.brand-feature span {

    display: block;
    font-size: 12px;
    color: #C4E8D6;

}

.login-brand address {

    margin-top: 34px;

    font-style: normal;
    font-size: 12px;

    color: #C4E8D6;

    position: relative;
    z-index: 2;

}


/* =========================================================
   LOGIN CARD (form panel)
========================================================= */

.login-card {

    flex: 1;

    width: 100%;

    background: #ffffff;

    border: none;

    border-radius: 0;

    padding: 48px 46px;

}


/* =========================================================
   LOGO
========================================================= */

.logo-circle {

    width: 64px;

    height: 64px;

    margin: 0;

    border-radius: 18px;

    background: linear-gradient(135deg, #149A72, #0A4A41);

    color: white;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 30px;

    box-shadow:
        0 12px 25px rgba(14,107,94,.28);

}

.login-card h2 {

    font-family: "Plus Jakarta Sans", sans-serif;

    color: #153f50;

    font-size: 24px;

    font-weight: 800;

    letter-spacing: -0.02em;

    margin-top: 18px;

    margin-bottom: 5px;

}

.login-card p {

    font-size: 13px;

}


/* =========================================================
   FORM
========================================================= */

.form-label {

    color: #365d6c;

    font-size: 12px;

    font-weight: 700;

}

.form-control {

    height: 48px;

    border: 1px solid #dbe9ed;

    border-radius: 12px;

    background: #fbfdfe;

    color: #254f60;

    font-size: 14px;

}

.form-control:focus {

    border-color: #0E6B5E;

    box-shadow:
        0 0 0 3px rgba(14,107,94,.10);

    background: white;

}


/* =========================================================
   LOGIN BUTTON
========================================================= */

.login-button {

    height: 50px;

    border: none;

    border-radius: 12px;

    background: linear-gradient(135deg, #149A72, #0A4A41);

    color: white;

    font-size: 15px;

    font-weight: 800;

    box-shadow:
        0 8px 20px rgba(14,107,94,.28);

    transition: .2s;

}

.login-button:hover {

    background: linear-gradient(135deg, #12B187, #0A4A41);

    color: white;

    transform: translateY(-1px);

}


/* =========================================================
   ALERT
========================================================= */

.alert {

    border-radius: 10px;

    font-size: 13px;

}


/* =========================================================
   LINKS
========================================================= */

.bottom-text {

    text-align: center;

    margin-top: 22px;

    color: #81969e;

    font-size: 13px;

}

.bottom-text a {

    color: #0E6B5E;

    font-weight: 800;

    text-decoration: none;

}

.back-home {

    display: block;

    text-align: center;

    margin-top: 14px;

    color: #718991;

    font-size: 12.5px;

    text-decoration: none;

}

.back-home:hover {

    color: #0E6B5E;

}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 860px) {

    .login-shell {

        flex-direction: column;

        max-width: 460px;

    }

    .login-brand {

        flex: none;

        padding: 34px 30px;

    }

    .brand-features,
    .login-brand address {

        display: none;

    }

    .login-brand h1 {

        font-size: 24px;

    }

    .login-brand .tagline {

        margin-bottom: 0;

    }

    .login-card {

        padding: 34px 28px;

    }

}

@media (max-width: 500px) {

    body {

        padding: 15px;

    }

    .login-card {

        padding: 30px 24px;

    }

}

</style>

</head>


<body>

<!-- Animated dental icons - tulad sa register -->
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

<div class="login-shell">


    <!-- BRAND PANEL -->

    <aside class="login-brand">

        <div class="login-brand-logo">

            <i class="bi bi-heart-pulse-fill"></i>

        </div>

        <h1>
            Abalos <span>Dental</span> Clinic
        </h1>

        <div class="tagline">
            Gentle care, healthy smiles — Malolos Avenue, Caloocan City
        </div>

        <div class="gold-rule"></div>

        <div class="brand-features">

            <div class="brand-feature">
                <i class="bi bi-calendar2-check-fill"></i>
                <div>
                    <strong>Smart Scheduling</strong>
                    <span>Book and manage visits in seconds</span>
                </div>
            </div>

            <div class="brand-feature">
                <i class="bi bi-graph-up-arrow"></i>
                <div>
                    <strong>AI Automated No-Show</strong>
                    <span>Smarter reminders for every patient</span>
                </div>
            </div>

            <div class="brand-feature">
                <i class="bi bi-people-fill"></i>
                <div>
                    <strong>Queue Management</strong>
                    <span>Real-time chairside patient flow</span>
                </div>
            </div>

        </div>

        <address>
            <i class="bi bi-geo-alt-fill me-1"></i>
            Malolos Avenue, Caloocan City · Mon–Sat 8:00 AM–6:00 PM
        </address>

    </aside>


    <!-- FORM PANEL -->

    <div class="login-card">


    <!-- LOGO -->

    <div class="mb-4">

        <div class="logo-circle">

            <i class="bi bi-heart-pulse-fill"></i>

        </div>


        <h2>
            Welcome back
        </h2>


        <p class="text-muted">
            Sign in to your Abalos Dental account
        </p>

    </div>



    <!-- SUCCESS -->
    <?php if ($successMessage): ?>
        <div class="alert alert-success d-flex align-items-center mb-3" role="alert" style="background:#eaf7f0;border:1px solid #a7f3d0;color:#065f46;border-radius:14px;font-size:13.5px;padding:12px 16px;">
            <i class="bi bi-check-circle-fill me-2 fs-5 text-success"></i>
            <div><?= htmlspecialchars($successMessage) ?></div>
        </div>
    <?php endif; ?>

    <!-- ERROR -->

    <?php if ($error): ?>

        <div
            class="alert alert-danger"
            role="alert">

            <i class="bi bi-exclamation-circle me-1"></i>

            <?php echo htmlspecialchars($error); ?>

        </div>

    <?php endif; ?>



    <!-- LOGIN FORM -->

    <form
        method="POST"
        action="login.php"
        autocomplete="off">
<?= csrfField() ?>


        <!-- EMAIL / USERNAME -->

        <div class="mb-3">

            <label class="form-label">

                Email Address

            </label>


            <input
                type="text"
                name="username"
                class="form-control"
                placeholder="Enter username or email"
                value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                required>

        </div>



        <!-- PASSWORD -->

        <div class="mb-4">

            <label class="form-label">

                Password

            </label>


            <input
                type="password"
                name="password"
                class="form-control"
                placeholder="Enter your password"
                required>

        </div>



        <!-- LOGIN -->

        <button
            type="submit"
            class="btn login-button w-100">

            <i class="bi bi-box-arrow-in-right me-1"></i>

            Login

        </button>


    </form>



    <!-- REGISTER -->

    <div class="bottom-text">

        <a href="forgot_password.php">
            Forgot password?
        </a>

        <br><br>

        Don't have an account?

        <a href="register.php">
            Register here
        </a>

    </div>



    <!-- HOME -->

    <a
        href="index.php"
        class="back-home">

        <i class="bi bi-arrow-left me-1"></i>

        Back to Home

    </a>


    </div>


</div>


</body>

</html>