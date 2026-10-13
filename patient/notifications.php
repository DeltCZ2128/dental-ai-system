<?php

require_once __DIR__ . '/../config.php';

/*
|--------------------------------------------------------------------------
| PATIENT NOTIFICATIONS
|--------------------------------------------------------------------------
| Displays notifications belonging only to the logged-in patient.
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| LOGIN CHECK
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| ROLE CHECK
|--------------------------------------------------------------------------
*/

if (isset($_SESSION['role']) && $_SESSION['role'] !== 'patient') {
    header("Location: ../login.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| GET PATIENT
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.patient_number,
        p.first_name,
        p.last_name,
        u.username,
        u.full_name
    FROM patients p
    LEFT JOIN users u
        ON u.id = p.user_id
    WHERE p.user_id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    die("
        <div style='
            font-family: Arial;
            padding: 40px;
            text-align: center;
        '>
            <h2>Patient profile not found.</h2>
            <p>Your account is not yet connected to a patient record.</p>
            <a href='../logout.php'>Logout</a>
        </div>
    ");
}

$patientId = (int) $patient['id'];

/*
|--------------------------------------------------------------------------
| GET NOTIFICATIONS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        n.*,
        a.appointment_date,
        a.start_time,
        a.end_time,
        a.service_type,
        d.dentist_name
    FROM notifications n
    LEFT JOIN appointments a
        ON a.id = n.appointment_id
    LEFT JOIN dentists d
        ON d.id = a.dentist_id
    WHERE n.patient_id = ?
    ORDER BY n.created_at DESC
");

$stmt->execute([$patientId]);

$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| NOTIFICATION COUNT
|--------------------------------------------------------------------------
*/

$notificationCount = count($notifications);

/*
|--------------------------------------------------------------------------
| HELPER FOR NOTIFICATION ICON
|--------------------------------------------------------------------------
*/

function notificationIcon($type)
{
    switch (strtolower(trim($type))) {

        case 'confirmation':
        case 'appointment':
            return 'bi-calendar-check';

        case 'reminder':
            return 'bi-alarm';

        case 'cancelled':
            return 'bi-calendar-x';

        case 'reschedule':
            return 'bi-calendar2-week';

        case 'system':
            return 'bi-info-circle';

        default:
            return 'bi-bell';
    }
}

/*
|--------------------------------------------------------------------------
| HELPER FOR NOTIFICATION STYLE
|--------------------------------------------------------------------------
*/

function notificationClass($type)
{
    switch (strtolower(trim($type))) {

        case 'confirmation':
            return 'notification-confirmed';

        case 'reminder':
            return 'notification-reminder';

        case 'appointment':
            return 'notification-appointment';

        case 'cancelled':
            return 'notification-cancelled';

        case 'reschedule':
            return 'notification-reschedule';

        default:
            return 'notification-default';
    }
}

/*
|--------------------------------------------------------------------------
| HELPER FOR NOTIFICATION COLOR CATEGORY
|--------------------------------------------------------------------------
| Green-Confirmed, Blue-Completed, Orange-Reschedule,
| Yellow-Reminder Sent, Red-Cancelled, Dark Gray-No-Show
|--------------------------------------------------------------------------
*/

function notificationColorKey($title, $type)
{
    $t = strtolower(trim(($title ?? '') . ' ' . ($type ?? '')));

    if (
        strpos($t, 'no-show') !== false ||
        strpos($t, 'no show') !== false
    ) {
        return 'noshow';
    }

    if (strpos($t, 'cancel') !== false) {
        return 'cancelled';
    }

    if (strpos($t, 'resched') !== false) {
        return 'reschedule';
    }

    if (strpos($t, 'remind') !== false) {
        return 'reminder';
    }

    if (strpos($t, 'complet') !== false) {
        return 'completed';
    }

    if (strpos($t, 'confirm') !== false) {
        return 'confirmed';
    }

    return 'other';
}

/*
|--------------------------------------------------------------------------
| CATEGORY COUNTS FOR FILTER
|--------------------------------------------------------------------------
*/

$categoryCounts = [
    'confirmed' => 0,
    'completed' => 0,
    'reschedule' => 0,
    'reminder' => 0,
    'cancelled' => 0,
    'noshow' => 0,
    'other' => 0,
];

foreach ($notifications as $countNotif) {
    $key = notificationColorKey(
        $countNotif['title'] ?? '',
        $countNotif['type'] ?? ''
    );
    if (isset($categoryCounts[$key])) {
        $categoryCounts[$key]++;
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

    <title>Notifications | Abalos Dental</title>

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

    <link
        rel="stylesheet"
        href="patient.css?v=8"
    >

    <style>
/* Notifications — itulad sa My Appointments: green-themed card/table */
.patient-card {
    padding: 0 !important;
    overflow: hidden;
    border: 1px solid #C9E9DA !important;
    border-radius: 16px !important;
    box-shadow: 0 8px 24px rgba(14,107,94,.08);
    background: #ffffff;
}
.patient-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 18px 20px;
    background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%);
    border-bottom: 1px solid #C9E9DA;
}
.patient-card-header h3 {
    font-size: 17px !important;
    font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif;
    font-weight: 800;
    color: #143f51;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.patient-card-header h3 i { color: #0E6B5E; font-size: 18px; }
/* Back to Dashboard — solid green gaya ng My Appointments */
#patientBackToDashboard {
    background: linear-gradient(135deg, #149A72, #0A4A41) !important;
    color: #ffffff !important;
    border: 1px solid rgba(201,162,39,.35) !important;
    border-radius: 10px !important;
    padding: 9px 18px !important;
    font-size: 13.5px !important;
    font-weight: 800 !important;
    font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif !important;
    letter-spacing: -.01em;
    box-shadow: 0 8px 20px rgba(14,107,94,.22) !important;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: .2s !important;
}
#patientBackToDashboard:hover {
    background: linear-gradient(135deg, #0E6B5E, #083A32) !important;
    color: #ffffff !important;
    transform: translateY(-1px);
    box-shadow: 0 12px 26px rgba(14,107,94,.28) !important;
}
#patientBackToDashboard i { font-size: 15px; }
    </style>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #0A3B34;
            font-family: "DM Sans", sans-serif;
            color: #263238;
        }

        /*
        |--------------------------------------------------------------------------
        | SIDEBAR
        |--------------------------------------------------------------------------
        */

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 250px;
            height: 100vh;
            background: #ffffff;
            border-right: 1px solid #e7eef3;
            padding: 25px 16px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
        }

        .brand {
            padding: 5px 12px 25px;
            border-bottom: 1px solid #edf2f5;
            margin-bottom: 20px;
        }

        .brand-title {
            font-family: "Plus Jakarta Sans", sans-serif;
            font-weight: 800;
            font-size: 20px;
            color: #0E6B5E;
        }

        .brand-subtitle {
            font-size: 12px;
            color: #7c8b95;
            margin-top: 3px;
        }

        .nav-menu {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 10px;
            text-decoration: none;
            color: #687780;
            font-size: 14px;
            font-weight: 500;
            transition: .2s;
        }

        .nav-link i {
            font-size: 18px;
        }

        .nav-link:hover {
            background: #eefafd;
            color: #0E6B5E;
        }

        .nav-link.active {
            background: #e6f8fc;
            color: #0E6B5E;
            font-weight: 700;
        }

        .notification-badge {
            margin-left: auto;
            background: #dc3545;
            color: white;
            min-width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            padding: 0 6px;
        }

        .logout-link {
            margin-top: auto;
            color: #dc3545;
        }

        .logout-link:hover {
            background: #fff1f2;
            color: #dc3545;
        }

        /*
        |--------------------------------------------------------------------------
        | MAIN
        |--------------------------------------------------------------------------
        */

        .main {
            margin-left: 250px;
            min-height: 100vh;
            padding: 30px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            background:#ffffff;
            border-bottom:1px solid #e8eef2;
            border-radius:12px;
            padding:12px 18px;
            gap:16px;
            flex-wrap:wrap;
        }
/* Tulad sa my appointments - minibar + dentist-style date/time */
.patient-minibar{display:flex;align-items:center;justify-content:center;gap:8px;background:#0A3B34;color:#C9A227;font-size:11px;font-weight:700;letter-spacing:.6px;padding:6px 12px;border-radius:10px;margin-bottom:12px;}
.patient-minibar span{color:#ffffff;}
.patient-minibar i{font-size:13px;color:#ffffff;}
.topbar-right{display:flex;align-items:center;gap:16px;}
.topbar-date{display:flex;align-items:center;gap:10px;color:#244f60;}
.topbar-date i{font-size:20px;}
.topbar-date strong{display:block;font-size:12px;font-weight:800;line-height:1.2;}
.topbar-date small{display:block;font-size:11px;opacity:.85;font-weight:600;}
.topbar-time{display:flex;align-items:center;gap:10px;color:#244f60;border-left:1px solid #e8eef2;padding-left:16px;}
.topbar-time i{font-size:20px;}
.topbar-time strong{display:block;font-size:12px;font-weight:800;line-height:1.2;}
.topbar-time small{display:block;font-size:11px;opacity:.85;font-weight:600;font-variant-numeric:tabular-nums;}
/* Summary cards — Exact match with Patient Dashboard (#statsGrid .stat-card) */
.notification-summary,
#statsGrid.notification-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 22px;
    align-items: stretch;
}
@media (max-width: 992px) {
    .notification-summary,
    #statsGrid.notification-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 576px) {
    .notification-summary,
    #statsGrid.notification-summary {
        grid-template-columns: 1fr;
    }
}

.notification-summary .stat-card,
.notification-summary-card {
    position: relative;
    overflow: hidden;
    background: linear-gradient(135deg, #047857 0%, #059669 55%, #10B981 100%) !important;
    border: 1px solid rgba(255, 255, 255, .28) !important;
    border-radius: 14px !important;
    padding: 12px 14px !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: flex-start !important;
    gap: 3px !important;
    box-shadow: 0 16px 34px rgba(5, 150, 105, .35) !important;
    color: #ffffff !important;
    height: 100% !important;
    cursor: pointer;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
    transition: .25s ease;
    text-decoration: none !important;
}

.notification-summary .stat-card::before,
.notification-summary .stat-card::after,
.notification-summary-card::before,
.notification-summary-card::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
}

.notification-summary .stat-card::before,
.notification-summary-card::before {
    width: 130px;
    height: 130px;
    top: -60px;
    right: -45px;
    background: radial-gradient(circle, rgba(255, 255, 255, .22), rgba(255, 255, 255, 0) 70%);
}

.notification-summary .stat-card::after,
.notification-summary-card::after {
    width: 100px;
    height: 100px;
    bottom: -48px;
    left: -32px;
    background: radial-gradient(circle, rgba(255, 255, 255, .16), rgba(255, 255, 255, 0) 70%);
}

.notification-summary .stat-card > *,
.notification-summary-card > * {
    position: relative;
    z-index: 1;
}

.notification-summary .stat-card:hover,
.notification-summary-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 22px 42px rgba(5, 150, 105, .45) !important;
    border-color: rgba(255, 255, 255, .62) !important;
}

.notification-summary .stat-card.active-card,
.notification-summary-card.active-card {
    border-color: #ffffff !important;
    border-width: 2px !important;
    box-shadow: 0 22px 42px rgba(5, 150, 105, .46) !important;
    transform: translateY(-3px);
}

.pstat-head {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
}

.pstat-icon {
    width: 34px;
    height: 34px;
    border-radius: 11px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
    background: rgba(255, 255, 255, .18);
    border: 1px solid rgba(255, 255, 255, .32);
    color: #ffffff;
    box-shadow: 0 8px 18px rgba(0, 0, 0, .14);
}

.pstat-label {
    margin: 0;
    flex: 1;
    min-width: 0;
    color: #ffffff;
    font-size: 13px;
    font-weight: 700;
}

.pstat-num {
    color: #ffffff;
    font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
    font-size: 27px;
    font-weight: 800;
    letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
    text-shadow: 0 6px 18px rgba(0, 0, 0, .22);
    line-height: 1;
    margin-top: auto;
    padding-top: 4px;
    padding-right: 48px;
}

.pstat-sub {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 11.5px;
    font-weight: 600;
    color: #ffffff;
    margin-top: 3px;
    background: rgba(255, 255, 255, .14);
    border: 1px solid rgba(255, 255, 255, .35);
    border-radius: 999px;
    padding: 7px 14px;
}

.pstat-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #A7F3D0;
    box-shadow: 0 0 0 4px rgba(167, 243, 208, .25);
    animation: pstatDotPulse 1.9s ease-in-out infinite;
    flex-shrink: 0;
}

@keyframes pstatDotPulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: .5; transform: scale(.8); }
}

.notification-summary .stat-card > .stat-go,
.notification-summary-card > .stat-go {
    position: absolute;
    right: 14px;
    bottom: 18px;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: rgba(255, 255, 255, .10);
    border: 1.5px solid rgba(255, 255, 255, .55);
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    flex-shrink: 0;
    z-index: 2;
    transition: .2s ease;
}

.notification-summary .stat-card:hover > .stat-go,
.notification-summary-card:hover > .stat-go {
    background: rgba(255, 255, 255, .25);
    transform: translateX(2px);
}

        .page-title {
            font-family: "Plus Jakarta Sans", sans-serif;
            font-size: 27px;
            font-weight: 800;
            margin: 0;
            color: #263238;
        }

        .welcome-text {
            color: #7c8b95;
            margin-top: 5px;
            font-size: 14px;
        }

        /*
        |--------------------------------------------------------------------------
        | NOTIFICATION CARD
        |--------------------------------------------------------------------------
        */

        .notification-card {
            background: rgba(255,255,255,.96);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 2px solid #059669;
            border-radius: 16px;
            padding: 17px;
            margin-bottom: 12px;
            transition: 0.2s ease;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            box-shadow: 0 12px 30px rgba(5,150,105,.12);
        }

        .notification-card:hover {
            background: #f9fcfe;
            border-color: #047857;
            box-shadow: 0 14px 34px rgba(5,150,105,.18);
        }

        /* Accordion: click ang header para bumukas ang detalye, isa lang bukas */
        .notification-card .notification-header {
            cursor: pointer;
            user-select: none;
        }
        .notification-card .notification-header:hover .notification-title {
            color: #0E6B5E;
        }
        .notification-card .tab-chevron {
            font-size: 16px;
            color: #0E6B5E;
            transition: transform .25s ease;
            margin-left: 8px;
        }
        .notification-card.open .tab-chevron {
            transform: rotate(180deg);
        }
        .notification-card .notification-details {
            display: none;
        }
        .notification-card.open .notification-details {
            display: block;
        }

        /*
        |--------------------------------------------------------------------------
        | CATEGORY COLORS — madaling ma-identify kada type
        |--------------------------------------------------------------------------
        */

        .notification-card[data-category="confirmed"] { border-left: 5px solid #198754; }
        .notification-card[data-category="confirmed"] .notification-icon { background: #e7f8ef; color: #198754; }
        .notification-card[data-category="confirmed"] .notification-type { background: #e7f8ef; color: #198754; }

        .notification-card[data-category="completed"] { border-left: 5px solid #0d6efd; }
        .notification-card[data-category="completed"] .notification-icon { background: #e7f0fe; color: #0d6efd; }
        .notification-card[data-category="completed"] .notification-type { background: #e7f0fe; color: #0d6efd; }

        .notification-card[data-category="reschedule"] { border-left: 5px solid #fd7e14; }
        .notification-card[data-category="reschedule"] .notification-icon { background: #fff3e8; color: #fd7e14; }
        .notification-card[data-category="reschedule"] .notification-type { background: #fff3e8; color: #e8590c; }

        .notification-card[data-category="reminder"] { border-left: 5px solid #d39e00; }
        .notification-card[data-category="reminder"] .notification-icon { background: #fff8dd; color: #b78a00; }
        .notification-card[data-category="reminder"] .notification-type { background: #fff8dd; color: #856404; }

        .notification-card[data-category="cancelled"] { border-left: 5px solid #dc3545; }
        .notification-card[data-category="cancelled"] .notification-icon { background: #fdebed; color: #dc3545; }
        .notification-card[data-category="cancelled"] .notification-type { background: #fdebed; color: #dc3545; }

        .notification-card[data-category="noshow"] { border-left: 5px solid #495057; }
        .notification-card[data-category="noshow"] .notification-icon { background: #e9ecef; color: #495057; }
        .notification-card[data-category="noshow"] .notification-type { background: #e9ecef; color: #343a40; }

        /*
        |--------------------------------------------------------------------------
        | FILTER BAR
        |--------------------------------------------------------------------------
        */

        .notif-filter {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
        }
        /* Filter nakatago: All lang ang kita, click para bumuka pababa */
        .notif-filter-wrap { margin-bottom: 20px; position:relative; display:inline-block; }
        #notifFilterToggle { border:1px solid #dce5ed; background:#ffffff; color:#244f60; border-radius:8px; padding:8px 14px; font-size:13px; font-weight:600; cursor:pointer; transition:.2s; }
        #notifFilterToggle .count { opacity:.65; font-weight:600; }
        #notifFilterToggle .toggle-chevron { margin-left:8px; font-size:14px; transition:transform .25s ease; display:inline-block; }
        #notifFilterToggle.open .toggle-chevron { transform:rotate(180deg); }
        #notifFilterOptions { display:none; }
        #notifFilterOptions.open { display:flex; flex-direction:column; gap:6px; position:absolute; top:calc(100% + 8px); left:0; z-index:50; background:#fff; border:1px solid #e4ebf1; border-radius:10px; padding:8px; box-shadow:0 12px 30px rgba(0,0,0,.12); min-width:230px; margin-top:0; }
        #notifFilterOptions.open button { width:100%; text-align:left; padding:8px 14px; font-size:13px; border-radius:8px; }
        .notif-filter button {
            border: 1px solid #dce5ed;
            background: #ffffff;
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: .2s;
        }
        .notif-filter button .count {
            opacity: .65;
            font-weight: 600;
        }
        .notif-filter button[data-filter="all"] { background: #ffffff; border-color: #dce5ed; color: #244f60; }
        .notif-filter button[data-filter="confirmed"] { background: #ffffff; border-color: #dce5ed; color: #244f60; }
        .notif-filter button[data-filter="completed"] { background: #ffffff; border-color: #dce5ed; color: #244f60; }
        .notif-filter button[data-filter="reschedule"] { background: #ffffff; border-color: #dce5ed; color: #244f60; }
        .notif-filter button[data-filter="reminder"] { background: #ffffff; border-color: #dce5ed; color: #244f60; }
        .notif-filter button[data-filter="cancelled"] { background: #ffffff; border-color: #dce5ed; color: #244f60; }
        .notif-filter button[data-filter="noshow"] { background: #ffffff; border-color: #dce5ed; color: #244f60; }
        .notif-filter button[data-filter="other"] { background: #ffffff; border-color: #dce5ed; color: #244f60; }
        .notif-filter button.active { background: #3b82c4!important; border-color: #3b82c4!important; color: #ffffff!important; }
        .notif-filter button[data-filter="all"].active { background: #263238; border-color: #263238; color: #fff; }
        .notif-filter button[data-filter="confirmed"].active { background: #198754; border-color: #198754; color: #fff; }
        .notif-filter button[data-filter="completed"].active { background: #0d6efd; border-color: #0d6efd; color: #fff; }
        .notif-filter button[data-filter="reschedule"].active { background: #fd7e14; border-color: #fd7e14; color: #fff; }
        .notif-filter button[data-filter="reminder"].active { background: #d39e00; border-color: #d39e00; color: #fff; }
        .notif-filter button[data-filter="cancelled"].active { background: #dc3545; border-color: #dc3545; color: #fff; }
        .notif-filter button[data-filter="noshow"].active { background: #495057; border-color: #495057; color: #fff; }
        .notif-filter button[data-filter="other"].active { background: #6c757d; border-color: #6c757d; color: #fff; }

        .notification-content {
            display: flex;
            gap: 15px;
            align-items: flex-start;
        }

        .notification-icon {
            width: 48px;
            height: 48px;
            min-width: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 21px;
        }

        .notification-confirmed .notification-icon {
            background: #e7f8ef;
            color: #198754;
        }

        .notification-reminder .notification-icon {
            background: #fff4d6;
            color: #d39e00;
        }

        .notification-appointment .notification-icon {
            background: #e8f7fb;
            color: #0E6B5E;
        }

        .notification-cancelled .notification-icon {
            background: #fdebed;
            color: #dc3545;
        }

        .notification-reschedule .notification-icon {
            background: #eeeafd;
            color: #6f42c1;
        }

        .notification-default .notification-icon {
            background: #edf2f5;
            color: #6c757d;
        }

        .notification-body {
            flex: 1;
            min-width: 0;
        }

        .notification-header {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            align-items: flex-start;
        }

        .notification-title {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: #263238;
        }

        .notification-date {
            color: #8a989f;
            font-size: 12px;
            white-space: nowrap;
        }

        .notification-message {
            margin: 7px 0 10px;
            color: #66747c;
            font-size: 14px;
            line-height: 1.6;
        }

        .notification-type {
            display: inline-block;
            padding: 4px 10px;
            background: #f1f5f7;
            color: #66747c;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        /*
        |--------------------------------------------------------------------------
        | APPOINTMENT DETAILS
        |--------------------------------------------------------------------------
        */

        .appointment-info {
            background: #f8fbfc;
            border-radius: 10px;
            padding: 10px 12px;
            margin-top: 10px;
            font-size: 13px;
            color: #5f6e76;
        }

        .appointment-info i {
            color: #0E6B5E;
            margin-right: 5px;
        }

        /*
        |--------------------------------------------------------------------------
        | EMPTY STATE
        |--------------------------------------------------------------------------
        */

        .empty-box {
            background: #ffffff;
            border: 1px solid #e7eef3;
            border-radius: 15px;
            padding: 70px 25px;
            text-align: center;
        }

        .empty-box i {
            font-size: 55px;
            color: #b7c4ca;
        }

        .empty-box h5 {
            margin-top: 18px;
            font-weight: 700;
        }

        .empty-box p {
            color: #8a989f;
            margin-bottom: 0;
        }

        /*
        |--------------------------------------------------------------------------
        | MOBILE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 900px) {

            .sidebar {
                width: 220px;
            }

            .main {
                margin-left: 220px;
                padding: 20px;
            }

        }

        @media (max-width: 700px) {

            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
                border-right: none;
                border-bottom: 1px solid #e7eef3;
            }

            .logout-link {
                margin-top: 15px;
            }

            .main {
                margin-left: 0;
                padding: 20px;
            }

            .notification-header {
                flex-direction: column;
                gap: 5px;
            }

            .notification-date {
                white-space: normal;
            }

        }

    </style>

</head>

<body>

<!-- ================================================================
     SIDEBAR
================================================================ -->

<?php require_once __DIR__ . '/patient_sidebar.php'; ?>

<div class="patient-layout">

<main class="patient-main">

    <style>
    body{background:linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22)!important;background-size:300% 300%!important;animation:myApptBgMove 8s ease infinite!important;}
    .patient-main{background:transparent!important;}
    @keyframes myApptBgMove{0%{background-position:0% 50%;}50%{background-position:100% 50%;}100%{background-position:0% 50%;}}
    .patient-minibar{display:flex;align-items:center;justify-content:center;gap:8px;background:#0A3B34;color:#C9A227;font-size:11px;font-weight:700;letter-spacing:.6px;padding:6px 12px;border-radius:10px 10px 0 0;margin-bottom:0!important;}
    .patient-minibar span{color:#ffffff;}
    .patient-minibar i{font-size:13px;color:#ffffff;}
    /* Pinagdikit na header: minibar (top) + topbar (bottom) — walang agwat, walang round sa topbar */
    .patient-topbar{background:#ffffff!important;border-bottom:1px solid #e8eef2!important;min-height:78px!important;padding-top:10px!important;padding-bottom:10px!important;gap:16px!important;flex-wrap:wrap!important;align-items:center!important;margin-top:0!important;border-radius:0!important;}
    .patient-topbar-right{display:flex!important;align-items:center!important;gap:16px!important;}
    .patient-topbar-date{display:flex!important;align-items:center!important;gap:10px!important;color:#244f60!important;}
    .patient-topbar-date i{font-size:20px!important;}
    .patient-topbar-date strong{display:block!important;font-size:12px!important;font-weight:800!important;line-height:1.2!important;}
    .patient-topbar-date small{display:block!important;font-size:11px!important;opacity:.85!important;font-weight:600!important;}
    .patient-topbar-time{display:flex!important;align-items:center!important;gap:10px!important;color:#244f60!important;border-left:1px solid #e8eef2!important;padding-left:16px!important;}
    .patient-topbar-time i{font-size:20px!important;}
    .patient-topbar-time strong{display:block!important;font-size:12px!important;font-weight:800!important;line-height:1.2!important;}
    .patient-topbar-time small{display:block!important;font-size:11px!important;opacity:.85!important;font-weight:600!important;font-variant-numeric:tabular-nums!important;}
    .page-heading{font-size:24px;}
    .page-small{font-size:13px;}
    .profile-circle{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#059669,#047857);color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
    </style>

    <div class="patient-minibar"><i class="bi bi-heart-pulse-fill"></i><span>Abalos Dental Clinic</span></div>

    <header class="patient-topbar">

        <div>

            <h1 class="page-heading">
                Notifications
            </h1>

            <p class="page-small">
                Stay updated with your appointments and clinic announcements.
            </p>

        </div>

        <div class="patient-topbar-right">
            <div class="patient-topbar-date"><i class="bi bi-calendar-event-fill"></i><div><strong><?= htmlspecialchars(date('l')) ?></strong><small><?= htmlspecialchars(date('M d, Y')) ?></small></div></div>
            <div class="patient-topbar-time"><i class="bi bi-clock-fill"></i><div><strong>Time</strong><small id="patientClinicTime">--:--:--</small></div></div>
            <div class="profile-circle"><i class="bi bi-person-fill"></i></div>
        </div>

    </header>
<script>(function(){function tick(){try{var el=document.getElementById('patientClinicTime');if(!el)return;el.textContent=new Date().toLocaleTimeString('en-PH',{timeZone:'Asia/Manila',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});}catch(e){}}tick();setInterval(tick,1000);})();</script>

    <div class="patient-content">

    <div class="notification-summary" id="statsGrid">
        <div class="stat-card stat-clickable notification-summary-card active-card" data-filter="all" role="button" tabindex="0" title="View all notifications">
            <div class="pstat-head">
                <span class="pstat-icon"><i class="bi bi-bell-fill"></i></span>
                <span class="pstat-label">Total Notifications</span>
            </div>
            <div class="pstat-num"><?= (int)$notificationCount ?></div>
            <div class="pstat-sub">
                <span class="pstat-dot"></span>
                All notifications
            </div>
            <span class="stat-go"><i class="bi bi-chevron-right"></i></span>
        </div>

        <div class="stat-card stat-clickable notification-summary-card" data-filter="confirmed" role="button" tabindex="0" title="View confirmed appointments">
            <div class="pstat-head">
                <span class="pstat-icon"><i class="bi bi-check-circle-fill"></i></span>
                <span class="pstat-label">Confirmed Appointments</span>
            </div>
            <div class="pstat-num"><?= (int)($categoryCounts['confirmed'] ?? 0) ?></div>
            <div class="pstat-sub">
                <span class="pstat-dot"></span>
                Confirmed by clinic
            </div>
            <span class="stat-go"><i class="bi bi-chevron-right"></i></span>
        </div>

        <div class="stat-card stat-clickable notification-summary-card" data-filter="cancelled" role="button" tabindex="0" title="View cancelled appointments">
            <div class="pstat-head">
                <span class="pstat-icon"><i class="bi bi-x-circle-fill"></i></span>
                <span class="pstat-label">Cancelled Appointments</span>
            </div>
            <div class="pstat-num"><?= (int)($categoryCounts['cancelled'] ?? 0) ?></div>
            <div class="pstat-sub">
                <span class="pstat-dot"></span>
                Cancelled appointments
            </div>
            <span class="stat-go"><i class="bi bi-chevron-right"></i></span>
        </div>

        <div class="stat-card stat-clickable notification-summary-card" data-filter="noshow" role="button" tabindex="0" title="View missed appointments">
            <div class="pstat-head">
                <span class="pstat-icon"><i class="bi bi-calendar2-x-fill"></i></span>
                <span class="pstat-label">No-Show Appointments</span>
            </div>
            <div class="pstat-num"><?= (int)($categoryCounts['noshow'] ?? 0) ?></div>
            <div class="pstat-sub">
                <span class="pstat-dot"></span>
                Missed appointments
            </div>
            <span class="stat-go"><i class="bi bi-chevron-right"></i></span>
        </div>
    </div>


    <!-- ============================================================
         FILTER BAR
    ============================================================= -->

    <?php if (!empty($notifications)): ?>

        <div class="notif-filter-wrap">
            <button type="button" id="notifFilterToggle" data-filter="all" class="active">
                <i class="bi bi-funnel me-1"></i>All <span class="count">(<?= $notificationCount ?>)</span>
                <i class="bi bi-chevron-down toggle-chevron"></i>
            </button>

            <div class="notif-filter" id="notifFilterOptions" style="margin-bottom:0;">

            <button type="button" data-filter="confirmed">
                Confirmed <span class="count">(<?= (int) $categoryCounts['confirmed'] ?>)</span>
            </button>

            <button type="button" data-filter="completed">
                Completed <span class="count">(<?= (int) $categoryCounts['completed'] ?>)</span>
            </button>

            <button type="button" data-filter="reschedule">
                Reschedule <span class="count">(<?= (int) $categoryCounts['reschedule'] ?>)</span>
            </button>

            <button type="button" data-filter="reminder">
                Reminder <span class="count">(<?= (int) $categoryCounts['reminder'] ?>)</span>
            </button>

            <button type="button" data-filter="cancelled">
                Cancelled <span class="count">(<?= (int) $categoryCounts['cancelled'] ?>)</span>
            </button>

            <button type="button" data-filter="noshow">
                No-Show <span class="count">(<?= (int) $categoryCounts['noshow'] ?>)</span>
            </button>

            </div>
        </div>

    <?php endif; ?>


    <!-- ============================================================
         NOTIFICATIONS
    ============================================================= -->

        <?php if (!empty($notifications)): ?>

        <style>
        /* Notif table — premium green design */
        .notif-table-wrap{background:#ffffff;border:1px solid #C9E9DA;border-radius:16px;overflow:hidden;box-shadow:0 8px 24px rgba(14,107,94,.08);}
        .notif-table{margin-bottom:0!important;width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;background:#ffffff;}
        .notif-table thead tr{background:linear-gradient(135deg,#0E6B5E,#0A4A41)!important;}
        .notif-table thead th{position:sticky;top:0;z-index:2;background:transparent!important;color:#ffffff!important;font-size:11.5px!important;font-weight:700;text-transform:uppercase;letter-spacing:.8px;padding:15px 18px!important;border:none!important;border-right:1px solid rgba(255,255,255,.12)!important;white-space:nowrap;font-family:"Inter",system-ui,sans-serif;}
        .notif-table thead th:last-child{border-right:none!important;}
        .notif-table thead th:nth-child(1){width:46%;}
        .notif-table thead th:nth-child(2){width:27%;padding-left:46px!important;}
        .notif-table thead th:nth-child(3){width:27%;}
        .notif-table tbody td:nth-child(2){padding-left:46px!important;}
        .notif-table tbody td{padding:16px 18px!important;font-size:13.5px!important;color:#4d6873!important;vertical-align:top;background:#ffffff;border-bottom:1px solid #E6F2EC!important;word-wrap:break-word;overflow-wrap:break-word;word-break:break-word;overflow:hidden;max-width:0;font-family:"Inter","DM Sans",system-ui,sans-serif;transition:background .2s ease;}
        .notif-table tbody tr:nth-child(even) td{background:#F6FBF8!important;}
        .notif-table tbody tr:hover td{background:#EAF7F0!important;}
        .notif-table tbody tr:last-child td{border-bottom:none!important;}
        .notif-table tbody tr:last-child td:first-child{border-bottom-left-radius:16px;}
        .notif-table tbody tr:last-child td:last-child{border-bottom-right-radius:16px;}
        /* Left category bar + icon */
        .notif-table tbody td:first-child{box-shadow:inset 4px 0 0 #6c757d;}
        .notification-row[data-category="confirmed"] td:first-child{box-shadow:inset 4px 0 0 #198754;}
        .notification-row[data-category="completed"] td:first-child{box-shadow:inset 4px 0 0 #0d6efd;}
        .notification-row[data-category="reschedule"] td:first-child{box-shadow:inset 4px 0 0 #fd7e14;}
        .notification-row[data-category="reminder"] td:first-child{box-shadow:inset 4px 0 0 #d39e00;}
        .notification-row[data-category="cancelled"] td:first-child{box-shadow:inset 4px 0 0 #dc3545;}
        .notification-row[data-category="noshow"] td:first-child{box-shadow:inset 4px 0 0 #495057;}
        .notif-main{display:flex;gap:13px;align-items:flex-start;min-width:0;overflow:hidden;}
        .notif-icon{width:42px;height:42px;min-width:42px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:19px;background:#edf2f5;color:#6c757d;}
        .notification-row[data-category="confirmed"] .notif-icon{background:#e7f8ef;color:#198754;}
        .notification-row[data-category="completed"] .notif-icon{background:#e7f0fe;color:#0d6efd;}
        .notification-row[data-category="reschedule"] .notif-icon{background:#fff3e8;color:#e8590c;}
        .notification-row[data-category="reminder"] .notif-icon{background:#fff8dd;color:#856404;}
        .notification-row[data-category="cancelled"] .notif-icon{background:#fdebed;color:#dc3545;}
        .notification-row[data-category="noshow"] .notif-icon{background:#e9ecef;color:#343a40;}
        .notif-text{flex:1;min-width:0;overflow:hidden;}
        .notif-table .notif-title{font-weight:800;color:#143f51;font-size:14px;letter-spacing:-.01em;overflow-wrap:break-word;word-break:break-word;}
        .notif-table .notif-msg{color:#4d6873;font-size:12.5px;line-height:1.6;margin-top:4px;overflow-wrap:break-word;word-break:break-word;}
        .notif-chip{display:inline-flex;align-items:center;gap:6px;margin-top:8px;background:#EAF7F0;border:1px solid #C9E9DA;color:#0E6B5E;border-radius:20px;padding:4px 11px;font-size:11.5px;font-weight:700;}
        .notif-chip i{font-size:13px;}
        .notif-table .notif-time{font-weight:800;color:#143f51;white-space:nowrap;font-size:13px;}
        .notif-table .notif-time i{color:#0E6B5E;margin-right:5px;}
        .notif-table .notif-time small{display:block;font-weight:600;color:#8aa0a8;font-size:11.5px;margin-top:3px;}
        .notif-table .notification-type{display:inline-block;font-size:11px!important;font-weight:800!important;font-family:"Inter",system-ui,sans-serif;border-radius:20px;padding:6px 13px!important;letter-spacing:.3px;background:#edf2f5;color:#6c757d;border:1px solid transparent;white-space:nowrap;}
        .notification-row[data-category="confirmed"] .notification-type{background:#e7f8ef;color:#146c43;border-color:#bfe6cf;}
        .notification-row[data-category="completed"] .notification-type{background:#e7f0fe;color:#0d6efd;border-color:#c2d9fd;}
        .notification-row[data-category="reschedule"] .notification-type{background:#fff3e8;color:#e8590c;border-color:#ffd8b5;}
        .notification-row[data-category="reminder"] .notification-type{background:#fff8dd;color:#856404;border-color:#f0df9a;}
        .notification-row[data-category="cancelled"] .notification-type{background:#fdebed;color:#dc3545;border-color:#f5c2c7;}
        .notification-row[data-category="noshow"] .notification-type{background:#e9ecef;color:#343a40;border-color:#ced4da;}
        .notif-count{display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:28px;padding:0 9px;border-radius:20px;background:#0E6B5E;color:#fff;font-size:12.5px;font-weight:800;font-family:"Inter",system-ui,sans-serif;}
        @media(max-width:768px){
            .notif-table thead{display:none;}
            .notif-table,.notif-table tbody,.notif-table tr,.notif-table td{display:block;width:100%!important;}
            .notif-table tbody tr{border-bottom:1px solid #E6F2EC;}
            .notif-table tbody td{border-bottom:none!important;}
        }
        </style>
        <div class="patient-card">

        <div class="patient-card-header">
            <h3><i class="bi bi-bell-fill"></i>Notifications</h3>
            <span class="notif-count"><?= (int)$notificationCount ?></span>
        </div>

        <div class="table-responsive notif-table-wrap" style="border:none;border-radius:0;box-shadow:none;">
        <table class="table notif-table mb-0">
        <thead><tr><th>Notification</th><th>Date &amp; Time</th><th>Type</th></tr></thead>
        <tbody>
        <?php foreach ($notifications as $n): ?>

            <?php

            $type = $n['type'] ?? 'System';

            $colorKey = notificationColorKey($n['title'] ?? '', $type);

            $notifIcons = [
                'confirmed' => 'bi-check-circle-fill',
                'completed' => 'bi-flag-fill',
                'reschedule' => 'bi-calendar-week-fill',
                'reminder' => 'bi-alarm-fill',
                'cancelled' => 'bi-x-circle-fill',
                'noshow' => 'bi-person-x-fill',
                'other' => 'bi-info-circle-fill',
            ];

            $notifIcon = $notifIcons[$colorKey] ?? 'bi-info-circle-fill';

            ?>

            <tr class="notification-row" data-category="<?= e($colorKey) ?>">

                <td>
                    <div class="notif-main">
                        <div class="notif-icon"><i class="bi <?= $notifIcon ?>"></i></div>
                        <div class="notif-text">
                            <div class="notif-title"><?= e($n['title']) ?></div>
                            <div class="notif-msg"><?= e($n['message']) ?></div>
                            <?php if (!empty($n['appointment_date'])): ?>
                                <span class="notif-chip"><i class="bi bi-calendar-event"></i><?= e(date("F d, Y", strtotime($n['appointment_date']))) ?><?php if (!empty($n['start_time'])): ?> · <?= e(date("h:i A", strtotime($n['start_time']))) ?><?php endif; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>

                <td class="notif-time">
                    <i class="bi bi-clock"></i><?= e(date("h:i A", strtotime($n['created_at']))) ?>
                    <small><?= e(date("M d, Y", strtotime($n['created_at']))) ?></small>
                </td>

                <td>
                    <?php $notifLabel = ($colorKey === 'noshow') ? 'No Show' : ucfirst($colorKey); ?>
                    <span class="notification-type"><?= e($notifLabel) ?></span>
                </td>

            </tr>

        <?php endforeach; ?>
        </tbody>
        </table>
        </div>
        </div>


        <div class="empty-state" id="notifNoMatch" style="display:none;">

            <i class="bi bi-funnel"></i>

            <h5>
                No notifications found
            </h5>

            <p>
                Walang notification sa napiling filter.
            </p>

        </div>


    <?php else: ?>

        <div class="patient-card">
        <div class="empty-state">

            <i class="bi bi-bell-slash"></i>

            <h5>
                No notifications
            </h5>

            <p>
                You don't have any notifications yet.
            </p>

        </div>
        </div>

    <?php endif; ?>

    </div>

</main>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>
/* Table filter - 3 columns */
(function(){
    var rows = document.querySelectorAll('.notification-row');

    /* Filter: nakatago — All lang kita, click All para bumuka */
    var filterToggle = document.getElementById('notifFilterToggle');
    var filterOptions = document.getElementById('notifFilterOptions');
    var filterBtns = document.querySelectorAll('.notif-filter-wrap button[data-filter]');
    var optionBtns = document.querySelectorAll('#notifFilterOptions button[data-filter]');
    var noMatch = document.getElementById('notifNoMatch');
    function applyNotifFilter(f) {
        var visible = 0;
        rows.forEach(function(row){
            var show = (f === 'all' || row.getAttribute('data-category') === f);
            row.style.display = show ? '' : 'none';
            if(show) visible++;
        });
        if(noMatch) noMatch.style.display = visible === 0 ? '' : 'none';
    }
    var summaryCards = document.querySelectorAll('.notification-summary-card[data-filter]');

    function syncSummaryActive(f) {
        summaryCards.forEach(function(c){
            c.classList.toggle('active-card', c.getAttribute('data-filter') === f);
        });
    }

    summaryCards.forEach(function(card){
        card.addEventListener('click', function(){
            var f = card.getAttribute('data-filter');
            syncSummaryActive(f);
            filterBtns.forEach(function(b){ b.classList.remove('active'); });
            var targetBtn = document.querySelector('#notifFilterOptions button[data-filter="' + f + '"]');
            if (targetBtn) {
                targetBtn.classList.add('active');
            } else if (f === 'all' && filterToggle) {
                filterToggle.classList.add('active');
            }
            applyNotifFilter(f);
            if (filterToggle && f !== 'all') {
                var label = card.querySelector('.pstat-label') ? card.querySelector('.pstat-label').textContent : f;
                filterToggle.innerHTML = '<i class="bi bi-funnel me-1"></i>' + label + ' <i class="bi bi-chevron-down toggle-chevron"></i>';
            } else if (filterToggle) {
                filterToggle.innerHTML = toggleAllLabel;
            }
        });
    });

    if(filterToggle && filterOptions) {
        var toggleAllLabel = filterToggle.innerHTML;
        filterToggle.addEventListener('click', function(){
            var isOpen = filterOptions.classList.contains('open');
            if(isOpen) {
                filterOptions.classList.remove('open');
                filterToggle.classList.remove('open');
            } else {
                filterOptions.classList.add('open');
                filterToggle.classList.add('open');
            }
            filterBtns.forEach(function(b){ b.classList.remove('active'); });
            filterToggle.classList.add('active');
            filterToggle.innerHTML = toggleAllLabel;
            syncSummaryActive('all');
            applyNotifFilter('all');
        });
    }
    optionBtns.forEach(function(btn){
        btn.addEventListener('click', function(){
            filterBtns.forEach(function(b){ b.classList.remove('active'); });
            btn.classList.add('active');
            var f = btn.getAttribute('data-filter');
            syncSummaryActive(f);
            applyNotifFilter(f);
            /* Auto-close: bumabalik pataas pag nakapili na */
            if(filterOptions) filterOptions.classList.remove('open');
            if(filterToggle) {
                filterToggle.classList.remove('open');
                filterToggle.innerHTML = '<i class="bi bi-funnel me-1"></i>' + btn.innerHTML + ' <i class="bi bi-chevron-down toggle-chevron"></i>';
            }
        });
    });
})();
</script>

</body>

</html>