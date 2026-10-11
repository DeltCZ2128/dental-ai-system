<?php

require_once __DIR__ . "/../config.php";

/*
|--------------------------------------------------------------------------
| REGISTER.PHP
|--------------------------------------------------------------------------
| Patient registration page
|
| Email address = username
| Password = password_hash()
| Contact number = users.contact_number
|--------------------------------------------------------------------------
*/

$message = "";
$message_type = "";


/*
|--------------------------------------------------------------------------
| REGISTRATION PROCESS
|--------------------------------------------------------------------------
*/


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
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $first_name = trim($_POST["first_name"] ?? "");
    $last_name = trim($_POST["last_name"] ?? "");
    $email = trim($_POST["email"] ?? "");

    // Contact number entered by the patient
    $contact_number = trim($_POST["contact_number"] ?? "");

    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";


    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if (
        empty($first_name) ||
        empty($last_name) ||
        empty($email) ||
        empty($contact_number) ||
        empty($password) ||
        empty($confirm_password)
    ) {

        $message = "Please complete all required fields.";
        $message_type = "danger";


    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";
        $message_type = "danger";


    } elseif (!preg_match('/^09[0-9]{9}$/', $contact_number)) {

        $message = "Please enter a valid 11-digit Philippine contact number starting with 09.";
        $message_type = "danger";


    } elseif ($password !== $confirm_password) {

        $message = "Passwords do not match.";
        $message_type = "danger";


    } elseif (strlen($password) < 6) {

        $message = "Password must be at least 6 characters.";
        $message_type = "danger";


    } else {

        /*
        |--------------------------------------------------------------------------
        | EMAIL WILL BE USED AS USERNAME
        |--------------------------------------------------------------------------
        */

        $username = $email;


        /*
        |--------------------------------------------------------------------------
        | CHECK IF ACCOUNT ALREADY EXISTS
        |--------------------------------------------------------------------------
        */

        $check = $pdo->prepare("
            SELECT id
            FROM users
            WHERE username = ?
            LIMIT 1
        ");

        $check->execute([$username]);


        if ($check->fetch()) {

            $message = "An account with this email already exists. Please login instead.";
            $message_type = "danger";


        } else {

            /*
            |--------------------------------------------------------------------------
            | CREATE FULL NAME
            |--------------------------------------------------------------------------
            */

            $full_name = $first_name . " " . $last_name;


            /*
            |--------------------------------------------------------------------------
            | HASH PASSWORD
            |--------------------------------------------------------------------------
            */

            $hashed_password = password_hash(
                $password,
                PASSWORD_DEFAULT
            );


            /*
            |--------------------------------------------------------------------------
            | INSERT PATIENT
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            | Contact number is automatically saved here.
            |--------------------------------------------------------------------------
            */

            try {

                $stmt = $pdo->prepare("
                    INSERT INTO users
                    (
                        username,
                        password,
                        full_name,
                        contact_number,
                        role
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?
                    )
                ");


                $stmt->execute([
                    $username,
                    $hashed_password,
                    $full_name,
                    $contact_number,
                    "patient"
                ]);

                $newUserId = (int)$pdo->lastInsertId();

                if ($newUserId > 0) {
                    try {
                        $pNum = 'PAT-' . date('Ymd') . '-' . strtoupper(substr(md5($newUserId . uniqid('', true)), 0, 5));
                        $pStmt = $pdo->prepare("
                            INSERT INTO patients
                            (user_id, patient_number, first_name, last_name, contact_number, email, status)
                            VALUES (?, ?, ?, ?, ?, ?, 'Active')
                        ");
                        $pStmt->execute([
                            $newUserId,
                            $pNum,
                            $first_name,
                            $last_name,
                            $contact_number,
                            $email
                        ]);
                    } catch (PDOException $e) {
                        // autoSyncPatientProfiles() in config.php will also keep it synced.
                    }
                }


                /*
                |--------------------------------------------------------------------------
                | SUCCESS MESSAGE
                |--------------------------------------------------------------------------
                */

                $message = "Registration successful! You may now login using your email address.";
                $message_type = "success";


                /*
                |--------------------------------------------------------------------------
                | CLEAR FORM
                |--------------------------------------------------------------------------
                */

                $_POST["first_name"] = "";
                $_POST["last_name"] = "";
                $_POST["email"] = "";
                $_POST["contact_number"] = "";


            } catch (PDOException $e) {

                $message = "Registration failed. Please try again.";
                $message_type = "danger";

            }

        }

    }

}

?>


<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Register | Abalos Dental Clinic</title>


    <!-- Bootstrap -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- Bootstrap Icons -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- Google Fonts -->

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
        rel="stylesheet"
    >


<style>

/* =========================================================
   GENERAL
========================================================= */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: "Inter", "DM Sans", system-ui, sans-serif;
    background: #022c22;
    color: #173b4d;
    min-height: 100vh;
}

a {
    text-decoration: none;
}


/* =========================================================
   BACKGROUND
========================================================= */

.page-wrapper {
    min-height: 100vh;
    position: relative;
    overflow: hidden;
    padding: 35px 15px;
    background: linear-gradient(135deg, #022c22, #059669, #065f46, #10b981, #022c22);
    background-size: 300% 300%;
    animation: registerGradientMove 6s ease infinite;
}

@keyframes registerGradientMove {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

.page-wrapper::before {
    content: "";
    position: absolute;
    width: 420px;
    height: 420px;
    border-radius: 50%;
    background: rgba(255,255,255,.10);
    top: -180px;
    right: -120px;
}

.page-wrapper::after {
    content: "";
    position: absolute;
    width: 350px;
    height: 350px;
    border-radius: 50%;
    background: rgba(255,255,255,.08);
    bottom: -180px;
    left: -130px;
}

/* Dental animated icons - tulad sa front */
.register-float-layer {
    position: absolute;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
    z-index: 1;
}

.register-float {
    position: absolute;
    color: rgba(255,255,255,.42);
    line-height: 1;
    animation: registerFloatUp linear infinite;
    text-shadow: 0 0 12px rgba(255,255,255,.35), 0 0 28px rgba(167,243,208,.25);
    filter: drop-shadow(0 4px 10px rgba(0,0,0,.15));
}

.register-float i {
    display: block;
    animation: registerSpinPulse 4s ease-in-out infinite alternate;
}

.register-float:nth-child(odd) i {
    animation: registerSway 3.5s ease-in-out infinite alternate;
}

.register-float:nth-child(3n) {
    color: rgba(167,243,208,.55);
}

@keyframes registerFloatUp {
    0% { transform: translateY(60px) rotate(0deg); opacity: 0; }
    10% { opacity: 1; }
    90% { opacity: 1; }
    100% { transform: translateY(-700px) rotate(25deg); opacity: 0; }
}

@keyframes registerSpinPulse {
    0% { transform: rotate(-10deg) scale(1); }
    100% { transform: rotate(12deg) scale(1.18); }
}

@keyframes registerSway {
    0% { transform: translateX(-8px) rotate(-8deg) scale(1); }
    100% { transform: translateX(8px) rotate(10deg) scale(1.15); }
}


/* =========================================================
   MAIN CARD
========================================================= */

.register-card {
    width: 100%;
    max-width: 1050px;
    margin: auto;
    background: rgba(255,255,255,.94);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border: 1px solid rgba(255,255,255,.55);
    border-radius: 25px;
    box-shadow:
        0 25px 70px rgba(2,44,34,.35),
        0 8px 25px rgba(5,150,105,.18);
    overflow: hidden;
    position: relative;
    z-index: 2;
}


/* =========================================================
   LEFT SIDE
========================================================= */

.register-info {
    background: linear-gradient(
        145deg,
        #022c22,
        #047857
    );
    color: white;
    padding: 55px 45px;
    min-height: 700px;
    position: relative;
    overflow: hidden;
}

.register-info::before {
    content: "";
    position: absolute;
    width: 300px;
    height: 300px;
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 50%;
    right: -150px;
    top: 60px;
}

.register-info::after {
    content: "";
    position: absolute;
    width: 210px;
    height: 210px;
    border: 1px solid rgba(255,255,255,.15);
    border-radius: 50%;
    left: -110px;
    bottom: 40px;
}


/* =========================================================
   LOGO
========================================================= */

.logo-box {
    width: 50px;
    height: 50px;
    border-radius: 14px;
    background: rgba(255,255,255,.16);
    border: 1px solid rgba(255,255,255,.25);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 23px;
    margin-bottom: 30px;
}

.brand-small {
    font-size: 13px;
    letter-spacing: 1.5px;
    font-weight: 700;
    color: #bdebf3;
    margin-bottom: 10px;
}

.register-info h1 {
    font-family: "Plus Jakarta Sans", sans-serif;
    font-size: 34px;
    line-height: 1.18;
    font-weight: 800;
    margin-bottom: 18px;
}

.register-info h1 span {
    color: #a9edf5;
}

.register-info > p {
    color: #d5f2f6;
    font-size: 12px;
    line-height: 1.8;
    max-width: 390px;
}


/* =========================================================
   BENEFITS
========================================================= */

.benefits {
    margin-top: 35px;
}

.benefit {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
}

.benefit-icon {
    width: 34px;
    height: 34px;
    flex-shrink: 0;
    border-radius: 10px;
    background: rgba(255,255,255,.14);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
}

.benefit span {
    font-size: 13px;
    color: #e1f6f9;
}


/* =========================================================
   INFO BOTTOM
========================================================= */

.info-bottom {
    position: absolute;
    left: 45px;
    bottom: 40px;
    right: 45px;
    z-index: 2;
}

.info-bottom-box {
    background: rgba(255,255,255,.10);
    border: 1px solid rgba(255,255,255,.16);
    border-radius: 14px;
    padding: 15px;
}

.info-bottom-box i {
    font-size: 17px;
    color: #a8edf4;
}

.info-bottom-box strong {
    display: block;
    font-size: 13px;
    margin-top: 7px;
}

.info-bottom-box span {
    display: block;
    color: #c6eaf0;
    font-size: 13.5px;
    margin-top: 3px;
}


/* =========================================================
   RIGHT SIDE
========================================================= */

.register-form-area {
    padding: 50px 50px;
    min-height: 700px;
}

.form-top {
    margin-bottom: 30px;
}

.form-label-small {
    color: #059669;
    font-size: 11.5px;
    font-weight: 800;
    letter-spacing: 1.2px;
    text-transform: uppercase;
}

.form-top h2 {
    font-family: "Plus Jakarta Sans", sans-serif;
    color: #153f50;
    font-size: 27px;
    font-weight: 800;
    margin-top: 8px;
    margin-bottom: 8px;
}

.form-top p {
    color: #81969e;
    font-size: 13px;
    line-height: 1.7;
}


/* =========================================================
   ALERT
========================================================= */

.alert {
    border-radius: 10px;
    font-size: 13px;
    padding: 11px 13px;
}


/* =========================================================
   FORM
========================================================= */

.form-group {
    margin-bottom: 18px;
}

.form-group label {
    display: block;
    color: #365d6c;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 7px;
}

.input-wrapper {
    position: relative;
}

.input-wrapper > i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #8ba4ad;
    font-size: 14px;
    z-index: 2;
}

.form-control {
    height: 45px;
    border: 1px solid #dbe9ed;
    border-radius: 9px;
    background: #fbfdfe;
    padding-left: 40px;
    color: #254f60;
    font-size: 13.5px;
    transition: .2s;
}

.form-control:focus {
    border-color: #059669;
    box-shadow: 0 0 0 3px rgba(5,150,105,.10);
    background: white;
}

.form-control::placeholder {
    color: #a9b8bd;
    font-size: 13px;
}


/* =========================================================
   PASSWORD TOGGLE
========================================================= */

.password-toggle {
    position: absolute;
    right: 13px;
    top: 50%;
    transform: translateY(-50%);
    border: none;
    background: transparent;
    color: #8aa2ab;
    cursor: pointer;
    z-index: 3;
}


/* =========================================================
   TERMS
========================================================= */

.terms {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    margin: 4px 0 22px;
}

.terms input {
    margin-top: 2px;
    accent-color: #059669;
}

.terms label {
    color: #81969e;
    font-size: 11.5px;
    line-height: 1.6;
}

.terms a {
    color: #059669;
    font-weight: 700;
}


/* =========================================================
   REGISTER BUTTON
========================================================= */

.register-btn {
    width: 100%;
    border: none;
    background: #059669;
    color: white;
    border-radius: 9px;
    height: 47px;
    font-size: 13.5px;
    font-weight: 800;
    transition: .2s;
    box-shadow: 0 8px 20px rgba(5,150,105,.18);
}

.register-btn:hover {
    background: #047857;
    transform: translateY(-1px);
}

.register-btn i {
    margin-left: 6px;
}


/* =========================================================
   LOGIN
========================================================= */

.login-text {
    text-align: center;
    margin-top: 20px;
    color: #81969e;
    font-size: 13px;
}

.login-text a {
    color: #059669;
    font-weight: 800;
}


/* =========================================================
   BACK HOME
========================================================= */

.back-home {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #6f8a94;
    font-size: 11.5px;
    font-weight: 700;
    margin-bottom: 20px;
}

.back-home:hover {
    color: #059669;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 991px) {

    .register-info {
        min-height: auto;
        padding: 40px;
    }

    .info-bottom {
        position: static;
        margin-top: 35px;
    }

    .register-form-area {
        min-height: auto;
        padding: 40px;
    }

}


@media (max-width: 575px) {

    .page-wrapper {
        padding: 15px;
    }

    .register-card {
        border-radius: 18px;
    }

    .register-info {
        padding: 30px 25px;
    }

    .register-info h1 {
        font-size: 28px;
    }

    .register-form-area {
        padding: 30px 25px;
    }

    .form-top h2 {
        font-size: 23px;
    }

}

</style>

</head>


<body>

<div class="page-wrapper">

    <!-- Animated dental icons - design only -->
    <div class="register-float-layer">
        <span class="register-float" style="left:5%; bottom:-40px; font-size:42px; animation-duration:6.5s; animation-delay:0s;"><i class="bi bi-heart-pulse-fill"></i></span>
        <span class="register-float" style="left:15%; bottom:-40px; font-size:26px; animation-duration:8.5s; animation-delay:1s;"><i class="bi bi-plus-circle-fill"></i></span>
        <span class="register-float" style="left:28%; bottom:-40px; font-size:56px; animation-duration:7.5s; animation-delay:0.5s;"><i class="bi bi-shield-check"></i></span>
        <span class="register-float" style="left:42%; bottom:-40px; font-size:30px; animation-duration:9.5s; animation-delay:1.5s;"><i class="bi bi-clipboard2-pulse-fill"></i></span>
        <span class="register-float" style="left:55%; bottom:-40px; font-size:48px; animation-duration:7s; animation-delay:0.2s;"><i class="bi bi-capsule"></i></span>
        <span class="register-float" style="left:68%; bottom:-40px; font-size:28px; animation-duration:9s; animation-delay:1.2s;"><i class="bi bi-bandaid-fill"></i></span>
        <span class="register-float" style="left:80%; bottom:-40px; font-size:60px; animation-duration:8s; animation-delay:0.7s;"><i class="bi bi-stars"></i></span>
        <span class="register-float" style="left:92%; bottom:-40px; font-size:34px; animation-duration:10s; animation-delay:2s;"><i class="bi bi-emoji-laughing-fill"></i></span>
        <span class="register-float" style="left:10%; bottom:-40px; font-size:32px; animation-duration:7.5s; animation-delay:2.5s;"><i class="bi bi-calendar-plus-fill"></i></span>
        <span class="register-float" style="left:22%; bottom:-40px; font-size:44px; animation-duration:6s; animation-delay:3s;"><i class="bi bi-emoji-smile-fill"></i></span>
        <span class="register-float" style="left:35%; bottom:-40px; font-size:24px; animation-duration:10.5s; animation-delay:0.6s;"><i class="bi bi-clipboard-check-fill"></i></span>
        <span class="register-float" style="left:48%; bottom:-40px; font-size:52px; animation-duration:6.8s; animation-delay:1.7s;"><i class="bi bi-hospital-fill"></i></span>
    </div>

    <div class="register-card">

        <div class="row g-0">


            <!-- =================================================
                 LEFT INFORMATION
            ================================================== -->

            <div class="col-lg-5">

                <div class="register-info">

                    <div class="logo-box">
                        <i class="bi bi-heart-pulse-fill"></i>
                    </div>


                    <div class="brand-small">
                        ABALOS DENTAL CLINIC
                    </div>


                    <h1>
                        Start Your
                        <span>Dental Care</span>
                        Journey.
                    </h1>


                    <p>
                        Create your patient account and enjoy
                        a more convenient way to manage your
                        dental appointments and information.
                    </p>


                    <div class="benefits">


                        <div class="benefit">

                            <div class="benefit-icon">
                                <i class="bi bi-person-check"></i>
                            </div>

                            <span>
                                Easy patient account registration
                            </span>

                        </div>


                        <div class="benefit">

                            <div class="benefit-icon">
                                <i class="bi bi-calendar-check"></i>
                            </div>

                            <span>
                                Convenient appointment management
                            </span>

                        </div>


                        <div class="benefit">

                            <div class="benefit-icon">
                                <i class="bi bi-file-earmark-person"></i>
                            </div>

                            <span>
                                Organized patient information
                            </span>

                        </div>


                        <div class="benefit">

                            <div class="benefit-icon">
                                <i class="bi bi-shield-check"></i>
                            </div>

                            <span>
                                Secure account access
                            </span>

                        </div>


                    </div>


                    <div class="info-bottom">

                        <div class="info-bottom-box">

                            <i class="bi bi-info-circle"></i>

                            <strong>
                                Patient Registration
                            </strong>

                            <span>
                                Please provide accurate information
                                when creating your account.
                            </span>

                        </div>

                    </div>


                </div>

            </div>


            <!-- =================================================
                 REGISTRATION FORM
            ================================================== -->

            <div class="col-lg-7">

                <div class="register-form-area">


                    <a
                        href="index.php"
                        class="back-home"
                    >

                        <i class="bi bi-arrow-left"></i>

                        Back to Home

                    </a>


                    <div class="form-top">

                        <div class="form-label-small">
                            PATIENT REGISTRATION
                        </div>


                        <h2>
                            Create Your Account
                        </h2>


                        <p>
                            Fill in the information below to
                            register as a patient.
                        </p>

                    </div>


                    <!-- ALERT MESSAGE -->

                    <?php if (!empty($message)): ?>

                        <div
                            class="alert alert-<?php echo htmlspecialchars($message_type); ?>"
                            role="alert"
                        >

                            <i class="bi
                                <?php
                                echo $message_type === "success"
                                    ? "bi-check-circle"
                                    : "bi-exclamation-circle";
                                ?>
                            "></i>

                            <?php echo htmlspecialchars($message); ?>

                        </div>

                    <?php endif; ?>


                    <!-- REGISTRATION FORM -->

                    <form
                        method="POST"
                        action="register.php"
                        autocomplete="off"
                    >
<?= csrfField() ?>


                        <!-- =================================================
                             FIRST + LAST NAME
                        ================================================== -->

                        <div class="row">


                            <!-- FIRST NAME -->

                            <div class="col-md-6">

                                <div class="form-group">

                                    <label>
                                        First Name
                                    </label>


                                    <div class="input-wrapper">

                                        <i class="bi bi-person"></i>


                                        <input
                                            type="text"
                                            name="first_name"
                                            class="form-control"
                                            placeholder="Enter first name"
                                            value="<?php echo htmlspecialchars($_POST["first_name"] ?? ""); ?>"
                                            required
                                        >

                                    </div>

                                </div>

                            </div>


                            <!-- LAST NAME -->

                            <div class="col-md-6">

                                <div class="form-group">

                                    <label>
                                        Last Name
                                    </label>


                                    <div class="input-wrapper">

                                        <i class="bi bi-person"></i>


                                        <input
                                            type="text"
                                            name="last_name"
                                            class="form-control"
                                            placeholder="Enter last name"
                                            value="<?php echo htmlspecialchars($_POST["last_name"] ?? ""); ?>"
                                            required
                                        >

                                    </div>

                                </div>

                            </div>


                        </div>


                        <!-- =================================================
                             EMAIL
                        ================================================== -->

                        <div class="form-group">

                            <label>
                                Email Address
                            </label>


                            <div class="input-wrapper">

                                <i class="bi bi-envelope"></i>


                                <input
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    placeholder="Enter email address"
                                    value="<?php echo htmlspecialchars($_POST["email"] ?? ""); ?>"
                                    required
                                >

                            </div>

                        </div>


                        <!-- =================================================
                             CONTACT NUMBER
                        ================================================== -->

                        <div class="form-group">

                            <label>
                                Contact Number
                            </label>


                            <div class="input-wrapper">

                                <i class="bi bi-telephone"></i>


                                <input
                                    type="tel"
                                    name="contact_number"
                                    id="contact_number"
                                    class="form-control"
                                    placeholder="09XXXXXXXXX"
                                    value="<?php echo htmlspecialchars($_POST["contact_number"] ?? ""); ?>"
                                    maxlength="11"
                                    pattern="09[0-9]{9}"
                                    inputmode="numeric"
                                    required
                                >

                            </div>

                        </div>


                        <!-- =================================================
                             PASSWORD
                        ================================================== -->

                        <div class="row">


                            <!-- PASSWORD -->

                            <div class="col-md-6">

                                <div class="form-group">

                                    <label>
                                        Password
                                    </label>


                                    <div class="input-wrapper">

                                        <i class="bi bi-lock"></i>


                                        <input
                                            type="password"
                                            name="password"
                                            id="password"
                                            class="form-control"
                                            placeholder="Create password"
                                            required
                                        >


                                        <button
                                            type="button"
                                            class="password-toggle"
                                            onclick="togglePassword('password', this)"
                                        >

                                            <i class="bi bi-eye"></i>

                                        </button>


                                    </div>

                                </div>

                            </div>


                            <!-- CONFIRM PASSWORD -->

                            <div class="col-md-6">

                                <div class="form-group">

                                    <label>
                                        Confirm Password
                                    </label>


                                    <div class="input-wrapper">

                                        <i class="bi bi-lock-fill"></i>


                                        <input
                                            type="password"
                                            name="confirm_password"
                                            id="confirm_password"
                                            class="form-control"
                                            placeholder="Confirm password"
                                            required
                                        >


                                        <button
                                            type="button"
                                            class="password-toggle"
                                            onclick="togglePassword('confirm_password', this)"
                                        >

                                            <i class="bi bi-eye"></i>

                                        </button>


                                    </div>

                                </div>

                            </div>


                        </div>


                        <!-- =================================================
                             TERMS
                        ================================================== -->

                        <div class="terms">


                            <input
                                type="checkbox"
                                id="terms"
                                required
                            >


                            <label for="terms">

                                I agree to provide accurate information
                                and allow the clinic to use my information
                                for appointment management.


                                <a href="#">
                                    Terms & Privacy
                                </a>

                            </label>


                        </div>


                        <!-- =================================================
                             REGISTER BUTTON
                        ================================================== -->

                        <button
                            type="submit"
                            class="register-btn"
                        >

                            Create Patient Account

                            <i class="bi bi-arrow-right"></i>

                        </button>


                    </form>


                    <!-- LOGIN -->

                    <div class="login-text">

                        Already have an account?


                        <a href="login.php">
                            Login here
                        </a>

                    </div>


                </div>

            </div>


        </div>

    </div>

</div>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>

function togglePassword(inputId, button) {

    const input = document.getElementById(inputId);
    const icon = button.querySelector("i");


    if (input.type === "password") {

        input.type = "text";

        icon.classList.remove("bi-eye");
        icon.classList.add("bi-eye-slash");

    } else {

        input.type = "password";

        icon.classList.remove("bi-eye-slash");
        icon.classList.add("bi-eye");

    }

}


/*
|--------------------------------------------------------------------------
| CONTACT NUMBER
|--------------------------------------------------------------------------
| Allow numbers only.
| Automatically limits to 11 digits.
|--------------------------------------------------------------------------
*/

const contactNumber = document.getElementById("contact_number");

if (contactNumber) {

    contactNumber.addEventListener("input", function () {

        this.value = this.value.replace(/[^0-9]/g, "");

        if (this.value.length > 11) {
            this.value = this.value.substring(0, 11);
        }

    });

}

</script>


</body>

</html>