<?php

/*
|--------------------------------------------------------------------------
| LANDING PAGE SESSION
|--------------------------------------------------------------------------
| The session is opened with the same guarded pattern used by config.php so
| that this page can be included together with it without starting a second
| session.
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| FRONT SERVICES - dynamic from admin (services table)
|--------------------------------------------------------------------------
| Kung ano ang nilagay sa admin/settings Services ay idadagdag sa grid.
| Fallback sa empty kapag walang DB/table.
*/
$frontDbServices = [];
try {
    require_once __DIR__ . '/config.php';
    if (isset($pdo)) {
        $svcStmt = $pdo->query("SELECT service_name, description FROM services WHERE status = 'Active' ORDER BY id ASC");
        if ($svcStmt) {
            $frontDbServices = $svcStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {
    $frontDbServices = [];
}
$frontExistingNames = ['general dentistry','orthodontics (braces)','oral surgery','cosmetic','fillings or pasta','root canal','oral prophylaxis or cleaning','denture','teeth whitening','crown','bridge','veneers'];
$frontExtraServices = [];
foreach ($frontDbServices as $svc) {
    $nm = strtolower(trim($svc['service_name'] ?? ''));
    if ($nm === '' || in_array($nm, $frontExistingNames, true)) continue;
    // Iwas duplikado kapag malapit ang pangalan (hal. Oral Surgery variant)
    $isDup = false;
    foreach ($frontExistingNames as $ex) {
        if (strpos($nm, $ex) !== false || strpos($ex, $nm) !== false) { $isDup = true; break; }
    }
    if (!$isDup) $frontExtraServices[] = $svc;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Abalos Dental Clinic | Appointment Scheduling System</title>

    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Google Fonts -->
    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
        rel="stylesheet">


<style>

/* =========================================================
   GENERAL
========================================================= */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {
    font-family: "DM Sans", sans-serif;
    background: #ffffff;
    color: #173b4d;
}

a {
    text-decoration: none;
}

.container {
    max-width: 1150px;
}


/* =========================================================
   NAVBAR
========================================================= */

.navbar {
    background: rgba(255,255,255,.96);
    backdrop-filter: blur(10px);
    border-bottom: 1px solid #e4eef2;
    padding: 15px 0;
    position: sticky;
    top: 0;
    z-index: 999;
}

.brand {
    display: flex;
    align-items: center;
    gap: 11px;
}

/* LOGO IMAGE */
.logo {
    width: 45px;
    height: 45px;
    border-radius: 13px;
    overflow: hidden;
    flex-shrink: 0;
}

.logo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.brand-name {
    color: #123d50;
    font-family: "Plus Jakarta Sans", sans-serif;
    font-size: 16px;
    font-weight: 800;
    line-height: 1.1;
}

.brand-sub {
    color: #7b929c;
    font-size: 8px;
    letter-spacing: .8px;
    margin-top: 4px;
}

.navbar-nav {
    gap: 5px;
}

.nav-link {
    color: #496b78 !important;
    font-size: 12px;
    font-weight: 600;
    padding: 9px 13px !important;
}

.nav-link:hover {
    color: #059669 !important;
}

.login-btn {
    background: #059669;
    color: #ffffff !important;
    border-radius: 9px;
    padding: 10px 19px !important;
    margin-left: 8px;
    box-shadow: 0 7px 18px rgba(11,159,189,.18);
}

.login-btn:hover {
    background: #047857;
    color: #ffffff !important;
}


/* =========================================================
   HERO
========================================================= */

.hero {
    background: linear-gradient(135deg, #022c22, #059669, #065f46, #10b981, #022c22);
    background-size: 300% 300%;
    animation: heroGradientMove 6s ease infinite;

    min-height: 680px;

    display: flex;
    align-items: center;

    overflow: hidden;
    position: relative;
}

.hero::before {
    content: "";
    position: absolute;

    width: 520px;
    height: 520px;

    border-radius: 50%;

    background: radial-gradient(circle, rgba(255,255,255,.22) 0%, rgba(255,255,255,0) 70%);

    right: -220px;
    top: -220px;
    animation: floatBlob1 4.5s ease-in-out infinite alternate;
}

.hero::after {
    content: "";
    position: absolute;

    width: 310px;
    height: 310px;

    border-radius: 50%;

    background: radial-gradient(circle, rgba(255,255,255,.18) 0%, rgba(255,255,255,0) 70%);

    left: -170px;
    bottom: -180px;
    animation: floatBlob2 5.5s ease-in-out infinite alternate;
}

@keyframes heroGradientMove {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

@keyframes floatBlob1 {
    0% { transform: translate(0,0) scale(1); }
    100% { transform: translate(-80px,80px) scale(1.15); }
}

@keyframes floatBlob2 {
    0% { transform: translate(0,0) scale(1); }
    100% { transform: translate(70px,-60px) scale(1.2); }
}

/* Dental themed floating icons - same emerald family - RESTORED */
.dental-float-layer {
    position: absolute;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
    z-index: 1;
}

.dental-float {
    position: absolute;
    color: rgba(255,255,255,.42);
    line-height: 1;
    animation: dentalFloatUp linear infinite;
    text-shadow: 0 0 12px rgba(255,255,255,.35), 0 0 28px rgba(167,243,208,.25);
    filter: drop-shadow(0 4px 10px rgba(0,0,0,.15));
}

.dental-float i {
    display: block;
    animation: dentalSpinPulse 4s ease-in-out infinite alternate;
}

.dental-float:nth-child(odd) i {
    animation: dentalSway 3.5s ease-in-out infinite alternate;
}

.dental-float:nth-child(3n) {
    color: rgba(167,243,208,.55);
}

@keyframes dentalFloatUp {
    0% { transform: translateY(60px) rotate(0deg); opacity: 0; }
    10% { opacity: 1; }
    90% { opacity: 1; }
    100% { transform: translateY(-520px) rotate(25deg); opacity: 0; }
}

@keyframes dentalSpinPulse {
    0% { transform: rotate(-10deg) scale(1); }
    100% { transform: rotate(12deg) scale(1.18); }
}

@keyframes dentalSway {
    0% { transform: translateX(-8px) rotate(-8deg) scale(1); }
    100% { transform: translateX(8px) rotate(10deg) scale(1.15); }
}

.dental-shine {
    position: absolute;
    top: -60px;
    left: -80px;
    width: 420px;
    height: 420px;
    background: radial-gradient(circle, rgba(255,255,255,.18) 0%, rgba(255,255,255,0) 70%);
    animation: floatBlob1 3.5s ease-in-out infinite alternate;
    pointer-events: none;
    z-index: 1;
}

.hero-content {
    position: relative;
    z-index: 2;
    padding: 75px 0;
}

.hero-tag {
    display: inline-flex;
    align-items: center;
    gap: 8px;

    background: rgba(255,255,255,.15);
    color: #ecfdf5;

    border: 1px solid rgba(255,255,255,.30);

    border-radius: 30px;

    padding: 8px 13px;

    font-size: 10px;
    font-weight: 700;

    margin-bottom: 20px;
}

.hero h1 {
    font-family: "Plus Jakarta Sans", sans-serif;

    color: #ffffff;

    font-size: clamp(38px, 5vw, 62px);

    font-weight: 800;

    line-height: 1.08;

    letter-spacing: -2px;

    max-width: 650px;
}

.hero h1 span {
    color: #a7f3d0;
}

.hero-text {
    color: #d1fae5;

    font-size: 14px;

    line-height: 1.8;

    max-width: 560px;

    margin: 22px 0 28px;
}

.hero-buttons {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.primary-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    gap: 8px;

    background: #059669;
    color: #ffffff;

    padding: 13px 21px;

    border-radius: 9px;

    font-size: 11px;
    font-weight: 700;

    transition: .2s;

    box-shadow: 0 8px 20px rgba(11,159,189,.18);
}

.primary-btn:hover {
    background: #047857;
    color: white;

    transform: translateY(-2px);
}

.secondary-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    gap: 8px;

    background: #ffffff;
    color: #285a6b;

    padding: 12px 20px;

    border: 1px solid #bbe8cd;

    border-radius: 9px;

    font-size: 11px;
    font-weight: 700;

    transition: .2s;
}

.secondary-btn:hover {
    border-color: #059669;
    color: #047857;

    transform: translateY(-2px);
}


/* =========================================================
   HERO VISUAL
========================================================= */

.hero-visual {
    position: relative;
    height: 600px;
    z-index: 2;
}

.main-card {
    position: absolute;

    right: -30px;
    top: 30px;

    width: 420px;

    background: rgba(255,255,255,.82);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(255,255,255,.45);

    border-radius: 22px;

    padding: 20px;

    box-shadow:
        0 25px 60px rgba(20,83,103,.13);
}

.hero-visual.hero-wide {
    height: auto;

    display: flex;

    justify-content: flex-end;

    margin-top: -50px;
}

.main-card.main-wide {
    position: relative;

    right: auto;
    top: auto;

    width: 100%;

    max-width: 520px;

    margin: 0 0 0 auto;

    padding: 28px 32px;

    background: rgba(255,255,255,.15);

    border: none;
}

.main-card.main-wide .card-title,
.main-card.main-wide .profile strong,
.main-card.main-wide .small-row strong,
.main-card.main-wide .appointment-title,
.main-card.main-wide .floating-card strong {
    color: #071a23;
}

.main-card.main-wide .profile span,
.main-card.main-wide .small-row span,
.main-card.main-wide .floating-card span {
    color: #132e3a;

    font-weight: 600;
}

.main-card.main-wide .card-title {
    font-size: 15px;
}

.main-card.main-wide .profile strong {
    font-size: 14px;
}

.main-card.main-wide .profile span {
    font-size: 11px;
}

.main-card.main-wide .appointment-title,
.main-card.main-wide .appointment-time {
    font-size: 11px;
}

.main-card.main-wide .appointment-time {
    color: #065f46;
}

.main-card.main-wide .small-row strong,
.main-card.main-wide .floating-card strong {
    font-size: 11px;
}

.main-card.main-wide .small-row span,
.main-card.main-wide .floating-card span {
    font-size: 10px;
}

.main-card.main-wide .profile,
.main-card.main-wide .floating-card {
    background: rgba(255,255,255,.25);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;

    margin-bottom: 16px;
}

.card-title {
    font-family: "Plus Jakarta Sans", sans-serif;

    color: #0a1f2a;

    font-size: 14px;
    font-weight: 800;
}

.status {
    background: #dff7e9;

    color: #16834b;

    font-size: 8px;
    font-weight: 700;

    padding: 6px 9px;

    border-radius: 20px;
}

.profile {
    background: #ecfdf5;

    border-radius: 16px;

    padding: 14px;

    display: flex;
    align-items: center;

    gap: 15px;
}

.profile-icon {
    width: 65px;
    height: 65px;

    border-radius: 16px;

    background: #059669;

    color: white;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 29px;
}

.profile strong {
    display: block;

    color: #0a1f2a;

    font-size: 13px;
}

.profile span {
    color: #3d545e;

    font-size: 9px;
}

.appointment {
    margin-top: 12px;

    border: 1px solid #e2edf0;

    border-radius: 13px;

    padding: 12px;
}

.appointment-top {
    display: flex;
    justify-content: space-between;

    margin-bottom: 10px;
}

.appointment-title {
    color: #0e2a36;

    font-size: 10px;
    font-weight: 700;
}

.appointment-time {
    color: #047857;

    font-size: 10px;
    font-weight: 800;
}

.progress {
    height: 7px;

    background: #e8f2f4;

    border-radius: 20px;
}

.progress-bar {
    background: #059669;

    border-radius: 20px;
}

.small-row {
    display: flex;
    align-items: center;

    gap: 9px;

    margin-top: 16px;
}

.small-icon {
    width: 33px;
    height: 33px;

    background: #d1fae5;

    color: #059669;

    border-radius: 9px;

    display: flex;
    align-items: center;
    justify-content: center;

    flex-shrink: 0;
}

.small-row span {
    color: #3a4f59;

    font-size: 9px;
}

.small-row strong {
    display: block;

    color: #0e2a36;

    font-size: 10px;
}


/* =========================================================
   FLOATING CARD
========================================================= */

.floating-card {
    position: relative;

    margin-top: 8px;

    transform: translateY(-4px);

    background: #ecfdf5;

    border: none;

    border-radius: 13px;

    padding: 14px;

    box-shadow:
        0 8px 20px rgba(5,150,105,.18);

    display: flex;

    gap: 9px;

    align-items: center;

    z-index: 3;
}

.floating-icon {
    width: 33px;
    height: 33px;

    background: #059669;

    color: white;

    border-radius: 10px;

    display: flex;
    justify-content: center;
    align-items: center;
}

.floating-card strong {
    display: block;

    color: #0a202b;

    font-size: 10px;
}

.floating-card span {
    color: #3d545e;

    font-size: 8px;
}


/* =========================================================
   STATS
========================================================= */

.stats-area {
    margin-top: -42px;

    position: relative;

    z-index: 10;
}

.stats {
    background: #ffffff;

    border: 1px solid #e1edf0;

    border-radius: 17px;

    box-shadow:
        0 12px 35px rgba(25,83,100,.08);

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);
}

.stat {
    padding: 22px;

    text-align: center;

    border-right: 1px solid #e8eff1;
}

.stat:last-child {
    border-right: none;
}

.stat-number {
    color: #143f51;

    font-family: "Plus Jakarta Sans", sans-serif;

    font-size: 18px;

    font-weight: 800;
}

.stat-label {
    color: #81969e;

    font-size: 9px;

    margin-top: 4px;
}

.stat i {
    color: #059669;

    font-size: 18px;

    margin-bottom: 6px;
}


/* =========================================================
   SECTIONS
========================================================= */

.section {
    padding: 90px 0;
}

.light-section {
    background: #f0fdf4;
}

.section-heading {
    text-align: center;

    margin-bottom: 48px;
}

.section-label {
    color: #059669;

    font-size: 9px;

    font-weight: 800;

    letter-spacing: 1.3px;
}

.section-heading h2 {
    font-family: "Plus Jakarta Sans", sans-serif;

    color: #153f50;

    font-size: 32px;

    font-weight: 800;

    margin-top: 9px;
}

.section-heading p {
    color: #738a94;

    font-size: 11px;

    line-height: 1.8;

    max-width: 570px;

    margin: 12px auto;
}


/* =========================================================
   ABOUT
========================================================= */

.about-card {
    background: #ffffff;

    border: 1px solid #e0ecef;

    border-radius: 18px;

    padding: 32px;

    height: 100%;

    transition: .2s;
}

.about-card:hover {
    transform: translateY(-4px);

    box-shadow:
        0 15px 35px rgba(26,88,106,.07);
}

.about-card.about-wide {
    padding: 40px 44px;

    border-top: 5px solid #059669;
}

.about-card h3 {
    font-family: "Plus Jakarta Sans", sans-serif;

    color: #0f2c38;

    font-size: 20px;

    font-weight: 800;

    margin-bottom: 14px;
}

.about-card p {
    color: #1e3a47;

    font-size: 14px;

    line-height: 1.9;
}

.about-feature {
    display: flex;

    gap: 13px;

    margin-top: 22px;
}

.about-feature i {
    width: 39px;
    height: 39px;

    flex-shrink: 0;

    border-radius: 10px;

    background: #d1fae5;

    color: #059669;

    display: flex;

    align-items: center;

    justify-content: center;
}

.about-feature strong {
    display: block;

    color: #132e3a;

    font-size: 14px;
}

.about-feature span {
    color: #2f4d5a;

    font-size: 13px;
}


/* =========================================================
   SERVICES
========================================================= */

.service-card {
    background: #ffffff;

    border: 1px solid #e0ecef;

    border-top: 5px solid #059669;

    border-radius: 18px;

    padding: 32px;

    height: 100%;

    transition: .2s;
}

.service-card:hover {
    transform: translateY(-4px);

    box-shadow:
        0 15px 35px rgba(26,88,106,.07);
}

.service-icon {
    width: 49px;
    height: 49px;

    border-radius: 12px;

    background: #d1fae5;

    color: #059669;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 21px;

    margin-bottom: 18px;
}

.service-card h3 {
    font-family: "Plus Jakarta Sans", sans-serif;

    color: #0f2c38;

    font-size: 20px;

    font-weight: 800;

    margin-bottom: 14px;
}

.service-card p {
    color: #1e3a47;

    font-size: 14px;

    line-height: 1.9;
}


/* =========================================================
   REGISTER CTA
========================================================= */

.register-section {
    background:
        linear-gradient(
            135deg,
            #ecfdf5,
            #f0fdf4
        );

    padding: 80px 0;
}

.register-box {
    background: #ffffff;

    border: 1px solid #bbe8cd;

    border-radius: 24px;

    padding: 48px;

    box-shadow:
        0 18px 50px rgba(28,92,109,.08);

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 40px;
}

.register-content {
    max-width: 650px;
}

.register-label {
    display: flex;

    align-items: center;

    gap: 7px;

    color: #059669;

    font-size: 9px;

    font-weight: 800;

    letter-spacing: 1px;

    margin-bottom: 13px;
}

.register-box h2 {
    font-family: "Plus Jakarta Sans", sans-serif;

    color: #123e51;

    font-size: 31px;

    font-weight: 800;

    line-height: 1.2;

    margin-bottom: 13px;
}

.register-box h2 span {
    color: #059669;
}

.register-box p {
    color: #728a94;

    font-size: 11px;

    line-height: 1.8;

    max-width: 570px;

    margin-bottom: 20px;
}

.register-list {
    list-style: none;

    padding: 0;

    margin: 0 0 24px;
}

.register-list li {
    color: #426573;

    font-size: 10px;

    margin-bottom: 10px;
}

.register-list i {
    color: #059669;

    margin-right: 7px;
}

.register-icon {
    width: 220px;
    height: 220px;

    border-radius: 50%;

    border: 1px solid #6ee7b7;

    background: #f0fdf4;

    display: flex;

    align-items: center;

    justify-content: center;

    flex-shrink: 0;
}

.register-icon-inner {
    width: 145px;
    height: 145px;

    border-radius: 50%;

    background: #bbf7d0;

    display: flex;

    align-items: center;

    justify-content: center;
}

.register-icon-box {
    width: 95px;
    height: 95px;

    border-radius: 20px;

    background: #059669;

    color: white;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 42px;

    box-shadow:
        0 15px 30px rgba(11,159,189,.20);
}


/* =========================================================
   CONTACT
========================================================= */

.contact-box {
    background: #064e3b;

    border-radius: 22px;

    padding: 45px;

    overflow: hidden;

    position: relative;

    color: #ffffff;
}

.contact-box::after {
    content: "";

    position: absolute;

    width: 260px;
    height: 260px;

    border-radius: 50%;

    background: #065f46;

    right: -100px;
    top: -100px;
}

.contact-box h2 {
    position: relative;

    z-index: 2;

    color: white;

    font-family: "Plus Jakarta Sans", sans-serif;

    font-size: 28px;

    font-weight: 800;
}

.contact-box > p {
    position: relative;

    z-index: 2;

    color: #ffffff;

    font-size: 11px;

    line-height: 1.8;

    max-width: 550px;
}

.contact-info {
    position: relative;

    z-index: 2;

    margin-top: 25px;
}

.contact-item {
    display: flex;

    gap: 12px;

    margin-bottom: 15px;
}

.contact-item i {
    width: 37px;
    height: 37px;

    flex-shrink: 0;

    border-radius: 9px;

    background: #047857;

    color: #ffffff;

    display: flex;

    align-items: center;

    justify-content: center;
}

.contact-item strong {
    display: block;

    color: white;

    font-size: 9px;
}

.contact-item span {
    color: #ffffff;

    font-size: 9px;
}


/* =========================================================
   FOOTER
========================================================= */

footer {
    background: #022c22;

    color: #ffffff;

    padding: 30px 0;

    font-size: 9px;
}

.footer-brand {
    color: white;

    font-size: 12px;

    font-weight: 800;

    margin-bottom: 3px;
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 991px) {

    .hero {
        padding: 70px 0 100px;
    }

    .hero-visual {
        margin-top: 30px;
    }

    .stats {
        grid-template-columns: repeat(2, 1fr);
    }

    .stat:nth-child(2) {
        border-right: none;
    }

    .stat:nth-child(-n+2) {
        border-bottom: 1px solid #e8eff1;
    }

    .register-box {
        flex-direction: column;

        text-align: center;
    }

    .register-label {
        justify-content: center;
    }

    .register-list {
        text-align: left;

        display: inline-block;
    }

}


@media (max-width: 767px) {

    .hero h1 {
        font-size: 40px;
    }

    .hero-visual {
        height: 420px;
    }

    .main-card {
        width: 330px;

        right: 0;
    }

    .floating-card {
        left: 0;
    }

    .stats {
        grid-template-columns: 1fr;
    }

    .stat {
        border-right: none !important;

        border-bottom: 1px solid #e8eff1;
    }

    .stat:last-child {
        border-bottom: none;
    }

    .section {
        padding: 65px 0;
    }

    .contact-box {
        padding: 30px;
    }

    .register-box {
        padding: 30px 24px;
    }

    .register-box h2 {
        font-size: 26px;
    }

    .register-icon {
        width: 170px;
        height: 170px;
    }

    .register-icon-inner {
        width: 115px;
        height: 115px;
    }

    .register-icon-box {
        width: 78px;
        height: 78px;

        font-size: 32px;
    }

}


@media (max-width: 400px) {

    .main-card {
        width: 300px;
    }

    .hero h1 {
        font-size: 35px;
    }

    .hero-text {
        font-size: 13px;
    }

}

</style>

</head>


<body>


<!-- =========================================================
     NAVBAR
========================================================= -->

<nav class="navbar navbar-expand-lg">

    <div class="container">

        <a href="index.php" class="brand">

            <div class="logo">
                <img src="dental-bg.jpg" alt="Abalos Dental Clinic Logo">
            </div>

            <div>

                <div class="brand-name">
                    Abalos Dental
                </div>

                <div class="brand-sub">
                    DENTAL APPOINTMENT SCHEDULING SYSTEM
                </div>

            </div>

        </a>


        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#navbarMenu">

            <span class="navbar-toggler-icon"></span>

        </button>


        <div
            class="collapse navbar-collapse"
            id="navbarMenu">

            <ul class="navbar-nav ms-auto align-items-lg-center">

                <li class="nav-item">
                    <a class="nav-link" href="#home">
                        Home
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#about">
                        About
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#services">
                        Services
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#contact">
                        Contact
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        class="nav-link"
                        href="register.php">

                        <i class="bi bi-person-plus"></i>
                        Register

                    </a>
                </li>

                <li class="nav-item">

                    <a
                        class="nav-link login-btn"
                        href="login.php">

                        <i class="bi bi-box-arrow-in-right"></i>
                        Login

                    </a>

                </li>

            </ul>

        </div>

    </div>

</nav>



<!-- =========================================================
     HERO
========================================================= -->

<section class="hero" id="home">

    <!-- Dental clinic themed animated background - design only, same emerald -->
    <div class="dental-float-layer">
        <div class="dental-shine"></div>
        <span class="dental-float" style="left:5%; bottom:-40px; font-size:42px; animation-duration:6.5s; animation-delay:0s;"><i class="bi bi-heart-pulse-fill"></i></span>
        <span class="dental-float" style="left:15%; bottom:-40px; font-size:26px; animation-duration:8.5s; animation-delay:1s;"><i class="bi bi-plus-circle-fill"></i></span>
        <span class="dental-float" style="left:28%; bottom:-40px; font-size:56px; animation-duration:7.5s; animation-delay:0.5s;"><i class="bi bi-shield-check"></i></span>
        <span class="dental-float" style="left:42%; bottom:-40px; font-size:30px; animation-duration:9.5s; animation-delay:1.5s;"><i class="bi bi-clipboard2-pulse-fill"></i></span>
        <span class="dental-float" style="left:55%; bottom:-40px; font-size:48px; animation-duration:7s; animation-delay:0.2s;"><i class="bi bi-capsule"></i></span>
        <span class="dental-float" style="left:68%; bottom:-40px; font-size:28px; animation-duration:9s; animation-delay:1.2s;"><i class="bi bi-bandaid-fill"></i></span>
        <span class="dental-float" style="left:80%; bottom:-40px; font-size:60px; animation-duration:8s; animation-delay:0.7s;"><i class="bi bi-stars"></i></span>
        <span class="dental-float" style="left:92%; bottom:-40px; font-size:34px; animation-duration:10s; animation-delay:2s;"><i class="bi bi-heart-pulse-fill"></i></span>
        <span class="dental-float" style="left:10%; bottom:-40px; font-size:32px; animation-duration:7.5s; animation-delay:2.5s;"><i class="bi bi-calendar2-check-fill"></i></span>
        <span class="dental-float" style="left:22%; bottom:-40px; font-size:44px; animation-duration:6s; animation-delay:3s;"><i class="bi bi-emoji-smile-fill"></i></span>
        <span class="dental-float" style="left:35%; bottom:-40px; font-size:24px; animation-duration:10.5s; animation-delay:0.6s;"><i class="bi bi-droplet-fill"></i></span>
        <span class="dental-float" style="left:48%; bottom:-40px; font-size:52px; animation-duration:6.8s; animation-delay:1.7s;"><i class="bi bi-hospital-fill"></i></span>
        <span class="dental-float" style="left:61%; bottom:-40px; font-size:29px; animation-duration:9.2s; animation-delay:0.4s;"><i class="bi bi-brightness-high-fill"></i></span>
        <span class="dental-float" style="left:74%; bottom:-40px; font-size:46px; animation-duration:7.2s; animation-delay:2.7s;"><i class="bi bi-patch-check-fill"></i></span>
        <span class="dental-float" style="left:86%; bottom:-40px; font-size:27px; animation-duration:9.8s; animation-delay:1.1s;"><i class="bi bi-suit-heart-fill"></i></span>
        <span class="dental-float" style="left:97%; bottom:-40px; font-size:38px; animation-duration:8.2s; animation-delay:2.2s;"><i class="bi bi-award-fill"></i></span>
        <span class="dental-float" style="left:3%; bottom:-40px; font-size:30px; animation-duration:7s; animation-delay:0.3s;"><i class="bi bi-activity"></i></span>
        <span class="dental-float" style="left:12%; bottom:-40px; font-size:50px; animation-duration:8.8s; animation-delay:1.8s;"><i class="bi bi-bag-heart-fill"></i></span>
        <span class="dental-float" style="left:25%; bottom:-40px; font-size:28px; animation-duration:6.2s; animation-delay:0.9s;"><i class="bi bi-balloon-heart-fill"></i></span>
        <span class="dental-float" style="left:38%; bottom:-40px; font-size:45px; animation-duration:9.3s; animation-delay:2.1s;"><i class="bi bi-calendar-heart-fill"></i></span>
        <span class="dental-float" style="left:52%; bottom:-40px; font-size:31px; animation-duration:7.8s; animation-delay:1.4s;"><i class="bi bi-chat-heart-fill"></i></span>
        <span class="dental-float" style="left:65%; bottom:-40px; font-size:54px; animation-duration:6.4s; animation-delay:2.9s;"><i class="bi bi-clipboard-heart-fill"></i></span>
        <span class="dental-float" style="left:78%; bottom:-40px; font-size:26px; animation-duration:9.6s; animation-delay:0.6s;"><i class="bi bi-file-medical-fill"></i></span>
        <span class="dental-float" style="left:90%; bottom:-40px; font-size:40px; animation-duration:7.4s; animation-delay:1.9s;"><i class="bi bi-gem"></i></span>
        <span class="dental-float" style="left:7%; bottom:-40px; font-size:36px; animation-duration:7.6s; animation-delay:1.3s;"><i class="bi bi-heart-fill"></i></span>
        <span class="dental-float" style="left:18%; bottom:-40px; font-size:58px; animation-duration:8.4s; animation-delay:0.2s;"><i class="bi bi-plus-lg"></i></span>
        <span class="dental-float" style="left:32%; bottom:-40px; font-size:33px; animation-duration:6.9s; animation-delay:2.4s;"><i class="bi bi-prescription2"></i></span>
        <span class="dental-float" style="left:45%; bottom:-40px; font-size:49px; animation-duration:9.1s; animation-delay:0.9s;"><i class="bi bi-shield-plus"></i></span>
        <span class="dental-float" style="left:58%; bottom:-40px; font-size:25px; animation-duration:7.1s; animation-delay:2.8s;"><i class="bi bi-star-fill"></i></span>
        <span class="dental-float" style="left:71%; bottom:-40px; font-size:55px; animation-duration:8.1s; animation-delay:1.6s;"><i class="bi bi-sun-fill"></i></span>
        <span class="dental-float" style="left:83%; bottom:-40px; font-size:31px; animation-duration:6.7s; animation-delay:3.1s;"><i class="bi bi-telephone-plus-fill"></i></span>
        <span class="dental-float" style="left:95%; bottom:-40px; font-size:47px; animation-duration:9.4s; animation-delay:0.1s;"><i class="bi bi-bag-plus-fill"></i></span>
        <span class="dental-float" style="left:6%; bottom:-40px; font-size:44px; animation-duration:7.3s; animation-delay:1s;"><i class="bi bi-emoji-laughing-fill"></i></span>
        <span class="dental-float" style="left:20%; bottom:-40px; font-size:30px; animation-duration:8.7s; animation-delay:2.6s;"><i class="bi bi-calendar-plus-fill"></i></span>
        <span class="dental-float" style="left:33%; bottom:-40px; font-size:52px; animation-duration:6.6s; animation-delay:0.4s;"><i class="bi bi-clipboard-check-fill"></i></span>
        <span class="dental-float" style="left:46%; bottom:-40px; font-size:28px; animation-duration:9.7s; animation-delay:1.5s;"><i class="bi bi-check-circle-fill"></i></span>
        <span class="dental-float" style="left:59%; bottom:-40px; font-size:46px; animation-duration:7.9s; animation-delay:3.2s;"><i class="bi bi-brightness-alt-high-fill"></i></span>
        <span class="dental-float" style="left:72%; bottom:-40px; font-size:32px; animation-duration:8.3s; animation-delay:0.8s;"><i class="bi bi-person-check-fill"></i></span>
        <span class="dental-float" style="left:84%; bottom:-40px; font-size:50px; animation-duration:6.3s; animation-delay:2.3s;"><i class="bi bi-patch-plus-fill"></i></span>
        <span class="dental-float" style="left:94%; bottom:-40px; font-size:29px; animation-duration:9.9s; animation-delay:1.2s;"><i class="bi bi-shield-fill-check"></i></span>
        <span class="dental-float" style="left:9%; bottom:-40px; font-size:41px; animation-duration:7.1s; animation-delay:0.7s;"><i class="bi bi-heart-pulse-fill"></i></span>
        <span class="dental-float" style="left:17%; bottom:-40px; font-size:27px; animation-duration:8.9s; animation-delay:1.9s;"><i class="bi bi-plus-circle-fill"></i></span>
        <span class="dental-float" style="left:29%; bottom:-40px; font-size:53px; animation-duration:7.3s; animation-delay:0.3s;"><i class="bi bi-shield-check"></i></span>
        <span class="dental-float" style="left:41%; bottom:-40px; font-size:32px; animation-duration:9.4s; animation-delay:2s;"><i class="bi bi-capsule"></i></span>
        <span class="dental-float" style="left:53%; bottom:-40px; font-size:47px; animation-duration:6.7s; animation-delay:1.1s;"><i class="bi bi-bandaid-fill"></i></span>
        <span class="dental-float" style="left:63%; bottom:-40px; font-size:28px; animation-duration:9s; animation-delay:2.5s;"><i class="bi bi-stars"></i></span>
        <span class="dental-float" style="left:75%; bottom:-40px; font-size:43px; animation-duration:7.7s; animation-delay:0.8s;"><i class="bi bi-calendar2-check-fill"></i></span>
        <span class="dental-float" style="left:87%; bottom:-40px; font-size:30px; animation-duration:8.6s; animation-delay:1.6s;"><i class="bi bi-emoji-smile-fill"></i></span>
        <span class="dental-float" style="left:93%; bottom:-40px; font-size:51px; animation-duration:7s; animation-delay:0.5s;"><i class="bi bi-hospital-fill"></i></span>
        <span class="dental-float" style="left:50%; bottom:-40px; font-size:26px; animation-duration:9.9s; animation-delay:3s;"><i class="bi bi-suit-heart-fill"></i></span>
    </div>

    <div class="container">

        <div class="row align-items-center">

            <div class="col-lg-6">

                <div class="hero-content">

                    <div class="hero-tag">

                        <i class="bi bi-heart-pulse-fill"></i>

                        ABALOS DENTAL CLINIC

                    </div>


                    <h1>

                        Better Care Starts With

                        <span>
                            Better Scheduling.
                        </span>

                    </h1>


                    <p class="hero-text">

                        Welcome to Abalos Dental Clinic's
                        appointment scheduling system.
                        Easily manage dental appointments,
                        patient information, and clinic
                        schedules in one organized platform.

                    </p>


                    <div class="hero-buttons">

                        <a
                            href="register.php"
                            class="primary-btn">

                            Register Now

                            <i class="bi bi-arrow-right"></i>

                        </a>


                        <a
                            href="#about"
                            class="secondary-btn">

                            Learn More

                            <i class="bi bi-chevron-down"></i>

                        </a>

                    </div>

                </div>

            </div>


            <div class="col-lg-6">

                <div class="hero-visual hero-wide">


                    <div class="main-card main-wide">

                        <div class="card-header">

                            <div class="card-title">

                                Clinic Overview

                            </div>

                            <div class="status">

                                SYSTEM ACTIVE

                            </div>

                        </div>


                        <div class="profile">
                             <div class="logo">
                <img src="dental-bg.jpg" alt="Abalos Dental Clinic Logo">
            </div>

                            <div>

                                <strong>
                                    Abalos Dental Clinic
                                </strong>

                                <span>
                                    Dental Appointment System
                                </span>

                            </div>

                        </div>


                        <div class="appointment">

                            <div class="appointment-top">

                                <span class="appointment-title">
                                    Appointment Management
                                </span>

                                <span class="appointment-time">
                                    ORGANIZED
                                </span>

                            </div>


                            <div class="progress">

                                <div
                                    class="progress-bar"
                                    style="width: 82%">
                                </div>

                            </div>


                            <div class="small-row">

                                <div class="small-icon">

                                    <i class="bi bi-calendar-check"></i>

                                </div>

                                <div>

                                    <strong>
                                        Easy Scheduling
                                    </strong>

                                    <span>
                                        Manage dental appointments
                                    </span>

                                </div>

                            </div>


                            <div class="small-row">

                                <div class="small-icon">

                                    <i class="bi bi-people"></i>

                                </div>

                                <div>

                                    <strong>
                                        Patient Records
                                    </strong>

                                    <span>
                                        Organized patient information
                                    </span>

                                </div>

                            </div>


                            <div class="small-row">

                                <div class="small-icon">

                                    <i class="bi bi-clock-history"></i>

                                </div>

                                <div>

                                    <strong>
                                        Clinic Schedule
                                    </strong>

                                    <span>
                                        Keep schedules organized
                                    </span>

                                </div>

                            </div>


                            <div class="small-row">

                                <div class="small-icon">

                                    <i class="bi bi-clock-fill"></i>

                                </div>

                                <div>

                                    <strong>
                                        Operating Hours
                                    </strong>

                                    <span>
                                        8:00 AM - 6:00 PM
                                    </span>

                                </div>

                            </div>

                        </div>

                        <div class="floating-card">

                            <div class="floating-icon">

                                <i class="bi bi-shield-check"></i>

                            </div>

                            <div>

                                <strong>
                                    Secure Access
                                </strong>

                                <span>
                                    Authorized users only
                                </span>

                            </div>

                        </div>

                    </div>


                </div>

            </div>

        </div>

    </div>

</section>



<!-- =========================================================
     STATS
========================================================= -->

<div class="container stats-area">

    <div class="stats">


        <div class="stat">

            <i class="bi bi-calendar2-check"></i>

            <div class="stat-number">
                Easy
            </div>

            <div class="stat-label">
                Appointment Management
            </div>

        </div>


        <div class="stat">

            <i class="bi bi-people"></i>

            <div class="stat-number">
                Organized
            </div>

            <div class="stat-label">
                Patient Information
            </div>

        </div>


        <div class="stat">

            <i class="bi bi-clock"></i>

            <div class="stat-number">
                Efficient
            </div>

            <div class="stat-label">
                Clinic Scheduling
            </div>

        </div>


        <div class="stat">

            <i class="bi bi-bar-chart-line"></i>

            <div class="stat-number">
                Reliable
            </div>

            <div class="stat-label">
                Clinic Management
            </div>

        </div>


    </div>

</div>



<!-- =========================================================
     ABOUT
========================================================= -->

<section class="section" id="about">

    <div class="container">

        <div class="section-heading">

            <div class="section-label">
                ABOUT THE CLINIC
            </div>

            <h2>
                A Better Way to Manage Dental Appointments
            </h2>

            <p>

                Abalos Dental Clinic provides a convenient
                and organized way to handle appointments
                and patient information.

            </p>

        </div>


        <div class="row g-4">


            <div class="col-12">

                <div class="about-card about-wide">

                    <div class="row g-4">

                        <div class="col-md-6">

                    <h3>
                        About Abalos Dental Clinic
                    </h3>

                    <p>

                        Abalos Dental Clinic is dedicated to
                        providing quality dental care while
                        keeping the appointment process
                        convenient and organized for patients.

                    </p>

                    <p>

                        Our appointment system helps organize
                        dental schedules and patient information
                        in one centralized platform.

                    </p>


                    <div class="about-feature">

                        <i class="bi bi-check-lg"></i>

                        <div>

                            <strong>
                                Organized Appointments
                            </strong>

                            <span>
                                Easily keep track of dental
                                appointment schedules.
                            </span>

                        </div>

                    </div>


                    <div class="about-feature">

                        <i class="bi bi-check-lg"></i>

                        <div>

                            <strong>
                                Convenient Access
                            </strong>

                            <span>
                                Manage your appointment information
                                through an easy-to-use system.
                            </span>

                        </div>

                    </div>

                        </div>

                        <div class="col-md-6">

                    <h3>
                        Why Choose Our System?
                    </h3>

                    <p>

                        Managing dental appointments becomes
                        easier when important information is
                        organized in one place.

                    </p>

                    <p>

                        Patients can register their account
                        and access the appointment system,
                        while authorized clinic personnel can
                        manage clinic information efficiently.

                    </p>


                    <div class="about-feature">

                        <i class="bi bi-calendar-check"></i>

                        <div>

                            <strong>
                                Simple Appointment Tracking
                            </strong>

                            <span>
                                View and manage appointment
                                information more conveniently.
                            </span>

                        </div>

                    </div>


                    <div class="about-feature">

                        <i class="bi bi-person-check"></i>

                        <div>

                            <strong>
                                Patient-Friendly
                            </strong>

                            <span>
                                A simple system designed to make
                                appointment management easier.
                            </span>

                        </div>

                    </div>

                        </div>

                    </div>

                </div>

            </div>


        </div>

    </div>

</section>



<!-- =========================================================
     SERVICES
========================================================= -->

<section class="section light-section" id="services">

    <div class="container">

        <div class="section-heading">

            <div class="section-label">
                OUR SERVICES
            </div>

            <h2>
                Dental Clinic Services
            </h2>

            <p>

                Explore the services and features available
                through Abalos Dental Clinic.

            </p>

        </div>


        <div class="row g-4">

            <div class="col-md-6 col-lg-4">
                <div class="service-card">
                    <div class="service-icon"><i class="bi bi-heart-pulse-fill"></i></div>
                    <h3>General Dentistry</h3>
                    <p>General dental care for maintaining healthy teeth and gums.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="service-card">
                    <div class="service-icon"><i class="bi bi-grid-3x3-gap-fill"></i></div>
                    <h3>Orthodontics (Braces)</h3>
                    <p>Consultation and treatment for teeth alignment using braces.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="service-card">
                    <div class="service-icon"><i class="bi bi-bandaid-fill"></i></div>
                    <h3>Oral Surgery</h3>
                    <p>Surgical dental care including tooth extraction or bunot when needed.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="service-card">
                    <div class="service-icon"><i class="bi bi-stars"></i></div>
                    <h3>Cosmetic</h3>
                    <p>Dental procedures focused on improving the appearance of your smile.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="service-card">
                    <div class="service-icon"><i class="bi bi-plus-circle-fill"></i></div>
                    <h3>Fillings or Pasta</h3>
                    <p>Restoration of teeth affected by cavities or minor damage.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="service-card">
                    <div class="service-icon"><i class="bi bi-heart-pulse-fill"></i></div>
                    <h3>Root Canal</h3>
                    <p>Treatment for an infected or inflamed tooth pulp.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="service-card">
                    <div class="service-icon"><i class="bi bi-stars"></i></div>
                    <h3>Oral Prophylaxis or Cleaning</h3>
                    <p>Professional cleaning to remove plaque and tartar from the teeth.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="service-card">
                    <div class="service-icon"><i class="bi bi-person-vcard-fill"></i></div>
                    <h3>Denture</h3>
                    <p>Dental prosthetic service for replacing missing teeth.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="service-card">
                    <div class="service-icon"><i class="bi bi-brightness-high-fill"></i></div>
                    <h3>Teeth Whitening</h3>
                    <p>Professional cosmetic treatment to improve tooth brightness.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="service-card">
                    <div class="service-icon"><i class="bi bi-shield-check"></i></div>
                    <h3>Crown</h3>
                    <p>A dental restoration used to cover and protect a damaged tooth.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="service-card">
                    <div class="service-icon"><i class="bi bi-link-45deg"></i></div>
                    <h3>Bridge</h3>
                    <p>A fixed dental restoration used to replace one or more missing teeth.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="service-card">
                    <div class="service-icon"><i class="bi bi-stars"></i></div>
                    <h3>Veneers</h3>
                    <p>Thin dental coverings designed to improve the appearance of front teeth.</p>
                </div>
            </div>

            <?php foreach ($frontExtraServices as $svc): ?>
            <div class="col-md-6 col-lg-4">
                <div class="service-card">
                    <div class="service-icon"><i class="bi bi-plus-circle-fill"></i></div>
                    <h3><?= htmlspecialchars($svc['service_name']) ?></h3>
                    <p><?= htmlspecialchars(trim($svc['description'] ?? '') !== '' ? $svc['description'] : 'Dental service available at Abalos Dental Clinic.') ?></p>
                </div>
            </div>
            <?php endforeach; ?>


        </div>

    </div>

</section>



<!-- =========================================================
     REGISTER CTA
========================================================= -->

<section class="register-section">

    <div class="container">

        <div class="register-box">

            <div class="register-content">

                <div class="register-label">

                    <i class="bi bi-person-plus-fill"></i>

                    PATIENT REGISTRATION

                </div>


                <h2>

                    Create Your Account for

                    <span>
                        Easier Appointments.
                    </span>

                </h2>


                <p>

                    Register your account to conveniently
                    manage your dental appointments and
                    patient information through the system.

                </p>


                <ul class="register-list">

                    <li>

                        <i class="bi bi-check-circle-fill"></i>

                        Easy account registration

                    </li>


                    <li>

                        <i class="bi bi-check-circle-fill"></i>

                        Convenient appointment management

                    </li>


                    <li>

                        <i class="bi bi-check-circle-fill"></i>

                        Organized patient information

                    </li>

                </ul>


                <a
                    href="register.php"
                    class="primary-btn">

                    Create Your Account

                    <i class="bi bi-arrow-right"></i>

                </a>

            </div>


            <div class="register-icon">

                <div class="register-icon-inner">

                    <div class="register-icon-box">

                        <i class="bi bi-person-plus-fill"></i>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>



<!-- =========================================================
     CONTACT
========================================================= -->

<section class="section light-section" id="contact">

    <div class="container">

        <div class="contact-box">

            <div class="row align-items-center">

                <div class="col-lg-7">

                    <h2>
                        Contact Abalos Dental Clinic
                    </h2>


                    <p>

                        For inquiries, clinic information,
                        and appointment-related concerns,
                        please contact Abalos Dental Clinic.

                    </p>


                    <div class="contact-info">


                        <div class="contact-item">

                            <i class="bi bi-geo-alt-fill"></i>

                            <div>

                                <strong style="font-size:13px; font-weight:800;">
                                    Clinic Location
                                </strong>

                                <span>
                                    Malolos Avenue,
                                    Caloocan City
                                </span>

                            </div>

                        </div>


                        <div class="contact-item">

                            <i class="bi bi-hospital"></i>

                            <div>

                                <strong style="font-size:13px; font-weight:800;">
                                    Clinic
                                </strong>

                                <span>
                                    Abalos Dental Clinic
                                </span>

                            </div>

                        </div>


                        <div class="contact-item">

                            <i class="bi bi-calendar-check"></i>

                            <div>

                                <strong style="font-size:13px; font-weight:800;">
                                    Appointment System
                                </strong>

                                <span>
                                    Dental Appointment Scheduling System
                                </span>

                            </div>

                        </div>


                    </div>

                </div>


                <div
                    class="col-lg-5 text-center position-relative">

                    <i
                        class="bi bi-heart-pulse-fill"
                        style="
                            color:#ffffff;
                            font-size:80px;
                        ">
                    </i>


                    <h4
                        style="
                            color:white;
                            font-weight:800;
                            margin-top:15px;
                        ">

                        Better Scheduling.
                        Better Care.

                    </h4>


                    <a
                        href="register.php"
                        class="primary-btn mt-3">

                        Register Now

                        <i class="bi bi-arrow-right"></i>

                    </a>

                </div>

            </div>

        </div>

    </div>

</section>



<!-- =========================================================
     FOOTER
========================================================= -->

<footer>

    <div class="container text-center">

        <div class="footer-brand">

            Abalos Dental Clinic

        </div>


        <div>

            Dental Appointment Scheduling System

        </div>


        <div style="margin-top:8px;">

            © 2026 Abalos Dental Clinic.
            All rights reserved.

        </div>

    </div>

</footer>



<!-- Bootstrap JS -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>


</body>

</html>