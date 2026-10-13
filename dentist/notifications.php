<?php

require_once __DIR__ . '/../config.php';


/*
|--------------------------------------------------------------------------
| DENTIST AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    strtolower(trim($_SESSION['role'] ?? '')) !== 'dentist'
) {
    header('Location: ../login.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| GET LOGGED-IN DENTIST
|--------------------------------------------------------------------------
|
| The appointments table uses dentist_id.
| We match the logged-in dentist with the dentists table.
|
*/

$loggedInUserId = (int) $_SESSION['user_id'];

$dentistName = trim(
    $_SESSION['full_name'] ?? ''
);


$dentistId = function_exists('resolveDentistId')
    ? resolveDentistId($loggedInUserId, $dentistName)
    : 0;

if ($dentistId <= 0) {
$dentistLookup = $pdo->prepare("
    SELECT id
    FROM dentists
    WHERE id = ?
       OR dentist_name = ?
    ORDER BY (id = ?) DESC
    LIMIT 1
");


$dentistLookup->execute([
    $loggedInUserId,
    $dentistName,
    $loggedInUserId
]);


$dentistId = (int) (
    $dentistLookup->fetchColumn() ?: 0
);
}


/*
|--------------------------------------------------------------------------
| HELPER FUNCTION
|--------------------------------------------------------------------------
*/

function dentistNotificationEscape($value)
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| CURRENT DATE AND TIME
|--------------------------------------------------------------------------
*/

$today = date('Y-m-d');

$currentDateTime = date('Y-m-d H:i:s');


/*
|--------------------------------------------------------------------------
| FILTER
|--------------------------------------------------------------------------
*/

$filter = trim(
    $_GET['filter'] ?? 'All'
);


$allowedFilters = [
    'All',
    'Upcoming',
    'Cancelled',
    'New',
    'Concerns',
    'History'
];


if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'All';
}

$notifNoSidebarSuffix = (($_GET['nosidebar'] ?? '') === '1') ? '&nosidebar=1' : '';


/*
|--------------------------------------------------------------------------
| GET APPOINTMENTS
|--------------------------------------------------------------------------
|
| Only appointments assigned to the logged-in dentist.
|
*/

$appointmentStmt = $pdo->prepare("
    SELECT
        a.id,
        a.patient_id,
        a.appointment_date,
        a.start_time,
        a.end_time,
        a.service_type,
        a.status,
        a.notes,
        a.created_at,

        p.patient_number,
        p.first_name,
        p.middle_name,
        p.last_name,
        p.contact_number,
        p.email

    FROM appointments a

    INNER JOIN patients p
        ON p.id = a.patient_id

    WHERE a.dentist_id = ?

    ORDER BY
        a.appointment_date DESC,
        a.start_time DESC
");


$appointmentStmt->execute([
    $dentistId
]);


$appointments = $appointmentStmt->fetchAll(
    PDO::FETCH_ASSOC
);


/*
|--------------------------------------------------------------------------
| CREATE NOTIFICATIONS
|--------------------------------------------------------------------------
|
| We generate notifications from existing appointment data.
|
*/

$notifications = [];


foreach ($appointments as $appointment) {

    $appointmentDate = $appointment['appointment_date'];
    $startTime = $appointment['start_time'];
    $endTime = $appointment['end_time'];

    $appointmentDateTime =
        $appointmentDate . ' ' . $startTime;


    $fullName = trim(
        $appointment['first_name']
        . ' '
        . ($appointment['middle_name'] ?? '')
        . ' '
        . $appointment['last_name']
    );


    /*
    |--------------------------------------------------------------------------
    | STATUS
    |--------------------------------------------------------------------------
    */

    $status = trim(
        $appointment['status'] ?? ''
    );


    $statusLower = strtolower(
        $status
    );


    /*
    |--------------------------------------------------------------------------
    | 1. CANCELLED APPOINTMENT
    |--------------------------------------------------------------------------
    */

    if ($statusLower === 'cancelled') {

        $notifications[] = [
            'type' => 'cancelled',
            'icon' => 'bi-calendar-x',
            'emoji' => '⚠️',
            'title' => 'Appointment Cancelled',
            'message' =>
                $fullName
                . ' cancelled an appointment scheduled for '
                . date('F j', strtotime($appointmentDate))
                . ' at '
                . date('h:i A', strtotime($startTime))
                . '.',
            'appointment' => $appointment,
            'date_time' => $appointmentDateTime,
            'priority' => 1
        ];

    }


    /*
    |--------------------------------------------------------------------------
    | 2. UPCOMING APPOINTMENT
    |--------------------------------------------------------------------------
    */

    elseif (
        $appointmentDateTime >= $currentDateTime &&
        in_array(
            $statusLower,
            ['pending', 'confirmed'],
            true
        )
    ) {

        $upcomingMsg = ($appointmentDate === $today)
            ? 'You have an appointment with '
                . $fullName
                . ' at '
                . date('h:i A', strtotime($startTime))
                . '.'
            : 'You have an appointment with '
                . $fullName
                . ' on '
                . date('F j', strtotime($appointmentDate))
                . ' at '
                . date('h:i A', strtotime($startTime))
                . '.';

        $notifications[] = [
            'type' => 'upcoming',
            'icon' => 'bi-alarm',
            'emoji' => '⏰',
            'title' => 'Upcoming Appointment',
            'message' => $upcomingMsg,
            'appointment' => $appointment,
            'date_time' => $appointmentDateTime,
            'priority' => 2
        ];

    }


    /*
    |--------------------------------------------------------------------------
    | 3. NEW APPOINTMENT
    |--------------------------------------------------------------------------
    |
    | An appointment created within the last 24 hours
    | is treated as a new appointment.
    |
    */

    $createdAt = $appointment['created_at'] ?? '';


    if (!empty($createdAt)) {

        $createdTimestamp = strtotime(
            $createdAt
        );


        if (
            $createdTimestamp !== false &&
            $createdTimestamp >= strtotime('-24 hours')
        ) {

            $newVerb = in_array(
                $statusLower,
                ['confirmed', 'approved'],
                true
            )
                ? 'has an approved appointment'
                : 'has a new appointment';

            $notifications[] = [
                'type' => 'new',
                'icon' => 'bi-calendar-plus',
                'emoji' => '🦷',
                'title' => 'New Appointment',
                'message' =>
                    $fullName
                    . ' ' . $newVerb . ' on '
                    . date('F j', strtotime($appointmentDate))
                    . ' at '
                    . date('h:i A', strtotime($startTime))
                    . '.',
                'appointment' => $appointment,
                'date_time' => $createdAt,
                'priority' => 0
            ];
        }
    }


    /*
    |--------------------------------------------------------------------------
    | 4. TODAY'S APPOINTMENT
    |--------------------------------------------------------------------------
    */

    if (
        $appointmentDate === $today &&
        in_array(
            $statusLower,
            ['pending', 'confirmed'],
            true
        )
    ) {

        $notifications[] = [
            'type' => 'today',
            'icon' => 'bi-calendar-event',
            'emoji' => '📅',
            'title' => "Today's Appointment",
            'message' =>
                $fullName
                . ' has an appointment today at '
                . date(
                    'h:i A',
                    strtotime($startTime)
                )
                . '.',
            'appointment' => $appointment,
            'date_time' => $appointmentDateTime,
            'priority' => 1
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | 5. HISTORY — past appointments (hindi nawawala pag lumipas)
    |--------------------------------------------------------------------------
    */

    if (
        $appointmentDate < $today ||
        in_array(
            $statusLower,
            ['completed', 'no-show', 'no show'],
            true
        )
    ) {

        if ($statusLower === 'completed') {
            $historyTitle = 'Appointment completed';
            $historyMsg = $fullName . ' completed an appointment on '
                . date('F d, Y', strtotime($appointmentDate)) . '.';
            $historyIcon = 'bi-check-circle';
            $historySub = 'completed';
        } elseif ($statusLower === 'no-show' || $statusLower === 'no show') {
            $historyTitle = 'Patient did not show up';
            $historyMsg = $fullName . ' missed an appointment on '
                . date('F d, Y', strtotime($appointmentDate)) . '.';
            $historyIcon = 'bi-person-x';
            $historySub = 'noshow';
        } else {
            $historyTitle = 'Past appointment';
            $historyMsg = $fullName . ' had an appointment on '
                . date('F d, Y', strtotime($appointmentDate)) . '.';
            $historyIcon = 'bi-clock-history';
            $historySub = 'past';
        }

        $notifications[] = [
            'type' => 'history',
            'history_sub' => $historySub,
            'icon' => $historyIcon,
            'title' => $historyTitle,
            'message' => $historyMsg,
            'appointment' => $appointment,
            'date_time' => $appointmentDateTime,
            'priority' => 3
        ];
    }
}


/*
|--------------------------------------------------------------------------
| PATIENT CONCERN NOTIFICATIONS (mula sa dentist_notifications table)
|--------------------------------------------------------------------------
*/

try {
    $concernStmt = $pdo->prepare("
        SELECT
            dn.id,
            dn.appointment_id,
            dn.patient_id,
            dn.title,
            dn.message,
            dn.type,
            dn.is_read,
            dn.created_at,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.patient_number
        FROM dentist_notifications dn
        LEFT JOIN patients p ON p.id = dn.patient_id
        WHERE dn.dentist_id = ?
        ORDER BY dn.created_at DESC
        LIMIT 50
    ");
    $concernStmt->execute([$dentistId]);
    $concernNotifs = $concernStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($concernNotifs as $cn) {
        $cnName = trim(
            ($cn['first_name'] ?? '')
            . ' ' . ($cn['middle_name'] ?? '')
            . ' ' . ($cn['last_name'] ?? '')
        );
        $notifications[] = [
            'type' => 'concern',
            'icon' => 'bi-chat-dots',
            'emoji' => '💬',
            'title' => $cn['title'] ?? 'Patient Concern',
            'message' => $cn['message'] ?? '',
            'appointment' => null,
            'date_time' => $cn['created_at'] ?? date('Y-m-d H:i:s'),
            'priority' => 0,
            'is_read' => (int)($cn['is_read'] ?? 0),
            'concern_id' => (int)($cn['id'] ?? 0)
        ];
    }
} catch (Throwable $e) {
    // dentist_notifications table might not exist yet — skip silently
}


/*
|--------------------------------------------------------------------------
| SORT NOTIFICATIONS
|--------------------------------------------------------------------------
*/

usort(
    $notifications,
    function ($a, $b) {

        $dateA = strtotime(
            $a['date_time']
        );

        $dateB = strtotime(
            $b['date_time']
        );


        if ($a['priority'] !== $b['priority']) {
            return $a['priority'] <=> $b['priority'];
        }


        return $dateB <=> $dateA;
    }
);


/*
|--------------------------------------------------------------------------
| APPLY FILTER
|--------------------------------------------------------------------------
*/

$filteredNotifications = [];

$htype = trim($_GET['htype'] ?? 'all');
$allowedHtypes = ['all', 'completed', 'noshow', 'past'];
if (!in_array($htype, $allowedHtypes, true)) {
    $htype = 'all';
}


foreach ($notifications as $notification) {

    if ($filter === 'All') {

        $filteredNotifications[] = $notification;

    } elseif (
        $filter === 'Upcoming' &&
        in_array(
            $notification['type'],
            ['upcoming', 'today'],
            true
        )
    ) {

        $filteredNotifications[] = $notification;

    } elseif (
        $filter === 'Cancelled' &&
        $notification['type'] === 'cancelled'
    ) {

        $filteredNotifications[] = $notification;

    } elseif (
        $filter === 'New' &&
        $notification['type'] === 'new'
    ) {

        $filteredNotifications[] = $notification;

    } elseif (
        $filter === 'Concerns' &&
        $notification['type'] === 'concern'
    ) {

        $filteredNotifications[] = $notification;

    } elseif (
        $filter === 'History' &&
        $notification['type'] === 'history'
    ) {

        $sub = $notification['history_sub'] ?? 'past';
        if ($htype === 'all' || $sub === $htype) {
            $filteredNotifications[] = $notification;
        }
    }
}


/*
|--------------------------------------------------------------------------
| PAGINATION (10 items per page)
|--------------------------------------------------------------------------
*/

$notifPerPage = 10;

$notifTotal = count($filteredNotifications);

$notifTotalPages = max(1, (int) ceil($notifTotal / $notifPerPage));

$notifPage = (int) ($_GET['page'] ?? 1);

if ($notifPage < 1) {
    $notifPage = 1;
}

if ($notifPage > $notifTotalPages) {
    $notifPage = $notifTotalPages;
}

$notifPageItems = array_slice(
    $filteredNotifications,
    ($notifPage - 1) * $notifPerPage,
    $notifPerPage
);

/*
 * URL ng ibang page: pinapanatili ang kasalukuyang filter at htype.
 */
function notifPageUrl($page)
{
    $params = $_GET;

    $params['page'] = max(1, (int) $page);

    return 'notifications.php?' . http_build_query($params);
}


/*
|--------------------------------------------------------------------------
| COUNTS
|--------------------------------------------------------------------------
*/

$allCount = count(
    $notifications
);


$upcomingCount = 0;
$cancelledCount = 0;
$newCount = 0;
$historyCount = 0;
$historyCompletedCount = 0;
$historyNoshowCount = 0;
$historyPastCount = 0;


foreach ($notifications as $notification) {

    if (
        in_array(
            $notification['type'],
            ['upcoming', 'today'],
            true
        )
    ) {
        $upcomingCount++;
    }


    if (
        $notification['type'] === 'cancelled'
    ) {
        $cancelledCount++;
    }


    if (
        $notification['type'] === 'new'
    ) {
        $newCount++;
    }


    if (
        $notification['type'] === 'history'
    ) {
        $historyCount++;
        $sub = $notification['history_sub'] ?? 'past';
        if ($sub === 'completed') { $historyCompletedCount++; }
        elseif ($sub === 'noshow') { $historyNoshowCount++; }
        else { $historyPastCount++; }
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

    <title>
        Notifications | Dentist Portal
    </title>


    <!-- ======================================================
         BOOTSTRAP ICONS
    ======================================================= -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- ======================================================
         GOOGLE FONTS
    ======================================================= -->

    <link
        rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
    >


    <!-- ======================================================
         PORTAL CSS
    ======================================================= -->

    <link
        rel="stylesheet"
        href="portal.css?v=5"
    >

    <!-- DENTIST SIDEBAR (dashboard parity) -->
    <link rel="stylesheet" href="dentist_sidebar.css">


    <style>

        /*
        |--------------------------------------------------------------------------
        | NOTIFICATION SUMMARY
        |--------------------------------------------------------------------------
        */

        .notification-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        @media (max-width: 1200px) {
            .notification-summary {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        @media (max-width: 800px) {
            .notification-summary {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 520px) {
            .notification-summary {
                grid-template-columns: 1fr;
            }
        }

        /* ===== SUMMARY CARD: Matching Dentist Dashboard Summary Cards ===== */
        .notification-summary-card {
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            gap: 6px;
            padding: 22px;
            background: linear-gradient(135deg, #0E6B5E 0%, #0F8A72 55%, #10B981 100%);
            border: 1px solid rgba(255,255,255,.28) !important;
            border-radius: 16px;
            box-shadow: 0 16px 34px rgba(10,74,65,.34);
            color: #ffffff;
            text-decoration: none !important;
            cursor: pointer;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
            transition: .25s ease;
        }

        .notification-summary-card::before,
        .notification-summary-card::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }

        .notification-summary-card::before {
            width: 170px;
            height: 170px;
            top: -78px;
            right: -58px;
            background: radial-gradient(circle, rgba(255,255,255,.22), rgba(255,255,255,0) 70%);
        }

        .notification-summary-card::after {
            width: 130px;
            height: 130px;
            bottom: -60px;
            left: -40px;
            background: radial-gradient(circle, rgba(255,255,255,.16), rgba(255,255,255,0) 70%);
        }

        .notification-summary-card > * {
            position: relative;
            z-index: 1;
        }

        .notification-summary-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 22px 42px rgba(10,74,65,.42);
            border-color: rgba(255,255,255,.62) !important;
            color: #ffffff;
        }

        .notification-summary-card.active-card {
            border-color: #ffffff !important;
            border-width: 2px !important;
            box-shadow: 0 22px 44px rgba(10,74,65,.46);
            transform: translateY(-3px);
        }

        .notification-summary-head,
        .stat-featured-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            width: 100%;
        }

        .notification-summary-icon,
        .stat-featured-icon {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            background: rgba(255,255,255,.18);
            border: 1px solid rgba(255,255,255,.32);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #ffffff;
            box-shadow: 0 8px 18px rgba(0,0,0,.14);
            flex-shrink: 0;
        }

        .notification-summary-label,
        .notification-summary-card h3 {
            color: rgba(255,255,255,.92);
            font-size: 13.5px;
            font-weight: 700;
            letter-spacing: .2px;
            margin: 0;
            flex: 1;
        }

        .notification-summary-number,
        .notification-summary-card .number {
            color: #ffffff;
            font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif;
            font-size: 40px;
            line-height: 1;
            font-weight: 800;
            letter-spacing: -.03em;
            font-variant-numeric: tabular-nums;
            text-shadow: 0 6px 18px rgba(0,0,0,.22);
            margin: 10px 0 6px;
        }

        .notification-summary-sub,
        .stat-featured-sub {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11.5px;
            font-weight: 600;
            color: rgba(255,255,255,.86);
            margin-top: 2px;
        }

        .stat-featured-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #BBF7D0;
            box-shadow: 0 0 0 4px rgba(187,245,208,.22);
            animation: statDotPulse 1.9s ease-in-out infinite;
            flex-shrink: 0;
            display: inline-block;
        }

        @keyframes statDotPulse {
            0%,100% { opacity: 1; transform: scale(1); }
            50% { opacity: .5; transform: scale(.8); }
        }

        .notification-summary-card .stat-go {
            position: absolute;
            right: 16px;
            bottom: 16px;
            z-index: 2;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgba(255,255,255,.2);
            border: 1px solid rgba(255,255,255,.35);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            transition: .2s ease;
        }

        .notification-summary-card:hover .stat-go {
            background: rgba(255,255,255,.35);
            transform: translateX(2px);
        }


        /*
        |--------------------------------------------------------------------------
        | FILTER
        |--------------------------------------------------------------------------
        */

        .notification-filter {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;

            flex-wrap: wrap;

            background:
                linear-gradient(135deg, #0E6B5E, #0A4A41);

            border-bottom:
                1px solid #C9E9DA;

            padding:
                24px 26px;

            margin:
                -20px -20px 22px -20px;

            border-radius:
                16px 16px 0 0;
        }


        .notification-filter-title {

            font-size:
                21px;

            font-weight:
                800;

            color:
                #ffffff;

            font-family:
                "Plus Jakarta Sans",
                "Inter", system-ui, sans-serif;

            letter-spacing:
                -.02em;
        }


        .notification-filter-sub {

            font-size:
                13.5px;

            font-weight:
                600;

            color:
                #D9F2E6;

            margin-top:
                6px;
        }


        .filter-buttons {

            display: flex;

            gap: 8px;

            flex-wrap: wrap;
        }


        .filter-button {

            text-decoration: none;

            padding: 9px 17px;

            border-radius: 999px;

            border: 1px solid rgba(255,255,255,.45);

            background: rgba(255,255,255,.12);

            color: #ffffff;

            font-size: 13px;

            font-weight: 600;
        }


        .filter-button:hover {

            background: rgba(255,255,255,.28);
        }


        .filter-button.active {

            background: #ffffff;

            border-color: #ffffff;

            color: #0E6B5E;

            font-weight: 800;
        }


        /*
        |--------------------------------------------------------------------------
        | NOTIFICATION ITEM
        |--------------------------------------------------------------------------
        */

        .notification-list {

            display: flex;

            flex-direction: column;

            gap: 12px;
        }


        .notification-item {

            display: flex;

            align-items: flex-start;

            gap: 14px;

            padding: 17px;

            border: 1px solid #e4ebf1;

            border-radius: 10px;

            background: #ffffff;

            transition: 0.2s ease;
        }


        .notification-item:hover {

            background: #f9fcfe;

            border-color: #cbdce9;
        }

        /* Click-highlight: gaya ng appointments selected row */
        .notification-item {
            cursor: pointer;
        }
        .notification-item.selected {
            background: #EAF7F0 !important;
            border-color: #0E6B5E !important;
            box-shadow: inset 4px 0 0 #0E6B5E !important;
        }
        /* NEW + click highlight para sa notification cards */
        .new-badge{display:inline-block;margin-left:8px;font-size:10px;font-weight:800;letter-spacing:.6px;color:#92400E;background:#FDE68A;border:1px solid #F5C518;border-radius:999px;padding:2px 8px;vertical-align:middle}
        .notif-card{cursor:pointer}
        .notif-card.is-new .new-badge{display:inline-block}
        .notif-card.is-read .new-badge{display:none}


        .notification-icon {

            width: 43px;

            height: 43px;

            flex-shrink: 0;

            border-radius: 10px;

            background: #eaf5ff;

            color: #2675b9;

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 19px;
        }


        .notification-icon.cancelled {

            background: #fdecec;

            color: #b42318;
        }


        .notification-icon.today {

            background: #fff5dc;

            color: #9a6700;
        }


        .notification-icon.new {

            background: #eaf5ff;

            color: #1769aa;
        }


        .notification-body {

            flex: 1;

            min-width: 0;
        }


        .notification-title {

            font-size: 14px;

            font-weight: 800;

            color: #0f2f3d;

            margin-bottom: 4px;
        }


        .notification-message {

            font-size: 13px;

            color: #34495e;

            line-height: 1.5;

            margin-bottom: 7px;
        }


        .notification-time {

            font-size: 11px;

            color: #4b5e6b;
        }


        .notification-action {

            flex-shrink: 0;
        }


        .view-appointment {

            display: inline-flex;

            align-items: center;

            gap: 5px;

            text-decoration: none;

            padding: 7px 11px;

            border-radius: 7px;

            background: #edf6ff;

            color: #2675b9;

            font-size: 12px;

            font-weight: 700;
        }


        .view-appointment:hover {

            background: #dcefff;
        }


        /*
        |--------------------------------------------------------------------------
        | NOTIFICATION LIST TABLE
        |--------------------------------------------------------------------------
        */

        .notif-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .notif-card {
            position: relative;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            background: #ffffff;
            border: 1px solid #e4ebf1;
            border-radius: 14px;
            padding: 15px 16px 15px 21px;
            box-shadow: 0 6px 18px rgba(14,107,94,.06);
            cursor: pointer;
            transition: transform .18s ease, box-shadow .18s ease, background .18s ease, border-color .18s ease;
            overflow: hidden;
        }

        .notif-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 5px;
            background: linear-gradient(180deg,#149A72,#0A4A41);
        }

        .notif-card.type-cancelled::before { background: linear-gradient(180deg,#F87171,#B42318); }
        .notif-card.type-today::before { background: linear-gradient(180deg,#FBBF24,#D97706); }
        .notif-card.type-new::before,
        .notif-card.type-upcoming::before { background: linear-gradient(180deg,#60A5FA,#1769AA); }
        .notif-card.type-history::before { background: linear-gradient(180deg,#B0BEC9,#4B5E6B); }
        .notif-card.type-concern::before { background: linear-gradient(180deg,#A78BFA,#7C3AED); }

        .notif-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(14,107,94,.12);
            border-color: #C9E9DA;
        }

        .notif-card:focus-visible {
            outline: 2px solid #0E6B5E;
            outline-offset: 2px;
        }

        .notif-card.is-new {
            background: linear-gradient(135deg,#FFFBEB 0%,#FEF3C7 100%);
            border-color: #F59E0B;
            box-shadow: 0 8px 24px rgba(217,119,6,.20);
        }

        .notif-card.is-new:hover {
            box-shadow: 0 12px 30px rgba(217,119,6,.28);
            border-color: #D97706;
        }

        .notif-card.is-new .nt-title {
            color: #7c2d12;
        }

        .notif-card.selected {
            background: #EAF7F0;
            border-color: #0E6B5E;
        }

        .notif-card.is-read {
            background: #ffffff;
        }

        .notif-card.is-read::before {
            background: #e4ebf1;
        }

        .notif-dot {
            display: none;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #D97706;
            box-shadow: 0 0 0 4px rgba(217,119,6,.18);
            margin-top: 16px;
            flex-shrink: 0;
        }

        .notif-card.is-new .notif-dot {
            display: block;
            background: #D97706;
            box-shadow: 0 0 0 4px rgba(217,119,6,.22);
            animation: notifDotPulse 1.9s ease-in-out infinite;
        }

        @keyframes notifDotPulse {
            0%,100% { opacity: 1; transform: scale(1); }
            50% { opacity: .5; transform: scale(.8); }
        }

        .notif-card.is-read .notif-dot {
            display: none;
        }

        .notif-body {
            flex: 1;
            min-width: 0;
        }

        .notif-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .notif-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .notif-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            color: #0E6B5E;
            background: #EAF7F0;
            border: 1px solid #C9E9DA;
            border-radius: 999px;
            padding: 5px 11px;
        }

        .notif-chip i {
            font-size: 12px;
        }

        .notif-card .view-appointment {
            flex-shrink: 0;
            align-self: center;
        }

        .nt-icon {
            width: 42px;
            height: 42px;
            flex-shrink: 0;
            border-radius: 10px;
            background: #eaf5ff;
            color: #2675b9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
        }

        .nt-icon.cancelled { background: #fdecec; color: #b42318; }
        .nt-icon.today { background: #fff5dc; color: #9a6700; }
        .nt-icon.new { background: #eaf5ff; color: #1769aa; }
        .nt-icon.upcoming { background: #eaf5ff; color: #2675b9; }
        .nt-icon.history { background: #eef2f5; color: #4b5e6b; }

        .nt-emoji {
            font-size: 24px;
            line-height: 1;
        }


        /*
        |--------------------------------------------------------------------------
        | PAGINATION
        |--------------------------------------------------------------------------
        */

        .notif-pagination {

            display:
                flex;

            align-items:
                center;

            justify-content:
                center;

            flex-wrap:
                wrap;

            gap:
                6px;

            margin-top:
                18px;

        }

        .notif-page-btn {

            display:
                inline-flex;

            align-items:
                center;

            gap:
                4px;

            min-width:
                34px;

            height:
                34px;

            justify-content:
                center;

            padding:
                0 10px;

            border-radius:
                9px;

            border:
                1px solid #C9E9DA;

            background:
                #ffffff;

            color:
                #0E6B5E;

            font-family:
                "Inter", system-ui, sans-serif;

            font-size:
                12.5px;

            font-weight:
                800;

            text-decoration:
                none;

        }

        .notif-page-btn:hover:not(.disabled):not(.active) {

            background:
                #EAF7F0;

        }

        .notif-page-btn.active {

            background:
                #0E6B5E;

            border-color:
                #0E6B5E;

            color:
                #ffffff;

        }

        .notif-page-btn.disabled {

            opacity:
                .45;

            cursor:
                default;

        }

        .nt-title {
            font-size: 14px;
            font-weight: 800;
            color: #0f2f3d;
            margin-bottom: 3px;
            flex: 1;
            min-width: 0;
        }

        .nt-msg {
            font-size: 12.5px;
            color: #34495e;
            line-height: 1.5;
        }

        .nt-time { white-space: nowrap; text-align: right; flex-shrink: 0; color: #0f2f3d; font-weight: 600; font-size: 12.5px; }
        .nt-time small { display: block; color: #4b5e6b; font-weight: 600; margin-top: 3px; }


        /*
        |--------------------------------------------------------------------------
        | NOTIFICATION PREVIEW MODAL
        |--------------------------------------------------------------------------
        */

        .nt-modal-overlay {

            position:
                fixed;

            inset:
                0;

            background:
                rgba(10, 59, 52, .55);

            z-index:
                3000;

            display:
                none;

            align-items:
                center;

            justify-content:
                center;

            padding:
                20px;

        }

        .nt-modal-overlay.open {

            display:
                flex;

        }

        .nt-modal-box {

            background:
                #ffffff;

            border-radius:
                16px;

            border:
                1px solid #C9E9DA;

            width:
                100%;

            max-width:
                520px;

            overflow:
                hidden;

            box-shadow:
                0 18px 45px rgba(0, 0, 0, .25);

        }

        .nt-modal-head {

            display:
                flex;

            align-items:
                center;

            gap:
                10px;

            padding:
                16px 20px;

            background:
                linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%);

            border-bottom:
                1px solid #C9E9DA;

        }

        .nt-modal-head .nt-icon {

            width:
                38px;

            height:
                38px;

            font-size:
                17px;

        }

        .nt-modal-head h3 {

            margin:
                0;

            font-family:
                "Plus Jakarta Sans", "Inter",
                system-ui, sans-serif;

            font-size:
                16px;

            font-weight:
                800;

            color:
                #143f51;

            flex:
                1;

            min-width:
                0;

        }

        .nt-modal-close {

            border:
                none;

            background:
                transparent;

            color:
                #0E6B5E;

            font-size:
                20px;

            line-height:
                1;

            cursor:
                pointer;

            padding:
                4px 6px;

            border-radius:
                8px;

            flex-shrink:
                0;

        }

        .nt-modal-close:hover {

            background:
                #EAF7F0;

        }

        .nt-modal-body {

            padding:
                16px 18px;

            display:
                flex;

            flex-direction:
                column;

            gap:
                10px;

        }

        .nt-modal-msg {

            font-size:
                13.5px;

            line-height:
                1.6;

            color:
                #34495e;

        }

        .nt-modal-grid {

            display:
                grid;

            grid-template-columns:
                1fr;

            gap:
                8px;

        }

        .nt-modal-grid .notif-chip {

            justify-content:
                flex-start;

            font-weight:
                600;

            overflow:
                hidden;

            text-overflow:
                ellipsis;

            white-space:
                nowrap;

        }

        .nt-modal-foot {

            display:
                flex;

            justify-content:
                flex-end;

            gap:
                8px;

            padding:
                12px 18px;

            border-top:
                1px solid #C9E9DA;

            background:
                #F6FBF8;

        }

        .nt-modal-btn {

            display:
                inline-flex;

            align-items:
                center;

            gap:
                6px;

            border:
                1.5px solid #0E6B5E;

            background:
                #EAF7F0;

            color:
                #0E6B5E;

            border-radius:
                999px;

            padding:
                7px 15px;

            font-family:
                "Inter", system-ui, sans-serif;

            font-size:
                12.5px;

            font-weight:
                800;

            cursor:
                pointer;

            text-decoration:
                none;

        }

        .nt-modal-btn.primary {

            background:
                #0E6B5E;

            color:
                #ffffff;

        }

        .nt-modal-btn:hover {

            background:
                #0E6B5E;

            color:
                #ffffff;

        }


        /*
        |--------------------------------------------------------------------------
        | EMPTY STATE
        |--------------------------------------------------------------------------
        */

        .notification-empty {

            text-align: center;

            padding: 45px 20px;

            color: #4b5e6b;
        }


        .notification-empty i {

            font-size: 38px;

            display: block;

            margin-bottom: 10px;
        }


        /*
        |--------------------------------------------------------------------------
        | STATUS
        |--------------------------------------------------------------------------
        */

        .notification-status {

            display: inline-block;

            margin-left: 7px;

            padding: 4px 8px;

            border-radius: 20px;

            font-size: 10px;

            font-weight: 700;
        }


        .notification-status.pending {

            background: #fff4d6;

            color: #9a6700;
        }


        .notification-status.confirmed {

            background: #e1f5e8;

            color: #18753c;
        }


        .notification-status.cancelled {

            background: #fde8e8;

            color: #b42318;
        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 1100px) {

            .notification-summary {

                grid-template-columns: repeat(2, 1fr);
            }

        }

        @media (max-width: 900px) {

            .notification-summary {

                grid-template-columns: 1fr;
            }

        }


        @media (max-width: 650px) {

            .notification-item {

                flex-direction: column;
            }


            .notification-action {

                width: 100%;
            }


            .view-appointment {

                width: 100%;

                justify-content: center;
            }

        }

    </style>


</head>


<body>

<style>
body{background:linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22)!important;background-size:300% 300%!important;animation:dentistDashBg 8s ease infinite!important;}
.portal-main{background:transparent!important;}
@keyframes dentistDashBg{0%{background-position:0% 50%;}50%{background-position:100% 50%;}100%{background-position:0% 50%;}}
</style>


    <!-- ======================================================
         SIDEBAR
    ======================================================= -->

    <?php
$dentistActivePage = 'notifications';
include __DIR__ . '/dentist_sidebar.php';
?>



    <!-- ======================================================
         MAIN CONTENT
    ======================================================= -->

    <main class="portal-main">

        <style>
            .clinic-minibar {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                background: #0A3B34;
                color: #C9A227;
                font-size: 11px;
                font-weight: 700;
                letter-spacing: .6px;
                padding: 6px 12px;
            }
            .clinic-minibar span {
                color: #ffffff;
            }
            /* TOPBAR date + time (tulad sa appointment page) */
            .topbar-today {
                display: flex;
                align-items: center;
                gap: 10px;
                color: #244f60;
                border-left: none;
                padding-left: 0;
            }
            .topbar-today i { font-size: 22px; }
            .topbar-today strong { display: block; font-size: 13px; font-weight: 800; line-height: 1.2; }
            .topbar-today small { display: block; font-size: 11px; opacity: .85; font-weight: 600; }
            .topbar-time {
                display: flex;
                align-items: center;
                gap: 10px;
                color: #244f60;
                border-left: 1px solid #e8eef2;
                padding-left: 16px;
            }
            .topbar-time i { font-size: 22px; }
            .topbar-time strong { display: block; font-size: 13px; font-weight: 800; line-height: 1.2; }
            .topbar-time small { display: block; font-size: 11px; opacity: .85; font-weight: 600; font-variant-numeric: tabular-nums; }
        </style>

        <!-- MINI CLINIC BAR (gaya sa appointment page) -->

        <div class="clinic-minibar">
            🦷 <span>Abalos Dental Clinic</span>
        </div>


        <!-- TOPBAR -->

        <header class="portal-topbar">


            <div>

                <div class="portal-heading">

                    Notifications

                </div>


                <div class="portal-subtitle">

                    Important appointment updates assigned to you

                </div>

            </div>


            <div style="display:flex;align-items:center;gap:16px;">

                <div class="topbar-today">

                    <i class="bi bi-calendar-event-fill"></i>

                    <div>
                        <strong><?= dentistNotificationEscape(date('l')) ?></strong>
                        <small><?= dentistNotificationEscape(date('M d, Y')) ?></small>
                    </div>

                </div>


                <div class="topbar-time">

                    <i class="bi bi-clock-fill"></i>

                    <div>
                        <strong>Clinic Time</strong>
                        <small id="notifClinicTime">--:--:--</small>
                    </div>

                </div>

            </div>


        </header>

        <script>
        (function () {
            function tickNotifClinicTime() {
                try {
                    var el = document.getElementById('notifClinicTime');
                    if (!el) return;
                    var now = new Date();
                    var timeStr = now.toLocaleTimeString('en-PH', {
                        timeZone: 'Asia/Manila',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: true
                    });
                    el.textContent = timeStr;
                } catch (e) {}
            }
            tickNotifClinicTime();
            setInterval(tickNotifClinicTime, 1000);
        })();
        </script>



        <!-- ==================================================
             PAGE CONTENT
        =================================================== -->

        <div class="portal-content">

            <!-- =================================================
                 SUMMARY
            ================================================== -->

            <div class="notification-summary">

                <!-- ALL -->
                <a href="notifications.php?filter=All<?= $notifNoSidebarSuffix ?>" class="card stat-clickable stat-featured notification-summary-card <?= $filter === 'All' ? 'active-card' : '' ?>" role="button" tabindex="0" aria-label="View all notifications" title="View all notifications">
                    <div class="stat-featured-head">
                        <span class="stat-featured-icon"><i class="bi bi-bell-fill"></i></span>
                        <h3>Total Notifications</h3>
                    </div>
                    <div class="number">
                        <?= $allCount ?>
                    </div>
                    <div class="stat-featured-sub">
                        <span class="stat-featured-dot"></span>
                        All notifications
                    </div>
                    <span class="stat-go"><i class="bi bi-chevron-right"></i></span>
                </a>

                <!-- UPCOMING -->
                <a href="notifications.php?filter=Upcoming<?= $notifNoSidebarSuffix ?>" class="card stat-clickable stat-featured notification-summary-card <?= $filter === 'Upcoming' ? 'active-card' : '' ?>" role="button" tabindex="0" aria-label="View upcoming appointments" title="View upcoming appointments">
                    <div class="stat-featured-head">
                        <span class="stat-featured-icon"><i class="bi bi-alarm-fill"></i></span>
                        <h3>Upcoming</h3>
                    </div>
                    <div class="number">
                        <?= $upcomingCount ?>
                    </div>
                    <div class="stat-featured-sub">
                        <span class="stat-featured-dot"></span>
                        Upcoming appointments
                    </div>
                    <span class="stat-go"><i class="bi bi-chevron-right"></i></span>
                </a>

                <!-- NEW -->
                <a href="notifications.php?filter=New<?= $notifNoSidebarSuffix ?>" class="card stat-clickable stat-featured notification-summary-card <?= $filter === 'New' ? 'active-card' : '' ?>" role="button" tabindex="0" aria-label="View new appointments" title="View new appointments">
                    <div class="stat-featured-head">
                        <span class="stat-featured-icon"><i class="bi bi-calendar-plus-fill"></i></span>
                        <h3>New Appointments</h3>
                    </div>
                    <div class="number">
                        <?= $newCount ?>
                    </div>
                    <div class="stat-featured-sub">
                        <span class="stat-featured-dot"></span>
                        Newly assigned appointments
                    </div>
                    <span class="stat-go"><i class="bi bi-chevron-right"></i></span>
                </a>

                <!-- CANCELLED -->
                <a href="notifications.php?filter=Cancelled<?= $notifNoSidebarSuffix ?>" class="card stat-clickable stat-featured notification-summary-card <?= $filter === 'Cancelled' ? 'active-card' : '' ?>" role="button" tabindex="0" aria-label="View cancelled appointments" title="View cancelled appointments">
                    <div class="stat-featured-head">
                        <span class="stat-featured-icon"><i class="bi bi-x-circle-fill"></i></span>
                        <h3>Cancelled</h3>
                    </div>
                    <div class="number">
                        <?= $cancelledCount ?>
                    </div>
                    <div class="stat-featured-sub">
                        <span class="stat-featured-dot"></span>
                        Cancelled appointments
                    </div>
                    <span class="stat-go"><i class="bi bi-chevron-right"></i></span>
                </a>

                <!-- HISTORY -->
                <a href="notifications.php?filter=History<?= $notifNoSidebarSuffix ?>" class="card stat-clickable stat-featured notification-summary-card <?= $filter === 'History' ? 'active-card' : '' ?>" role="button" tabindex="0" aria-label="View history notifications" title="View history notifications">
                    <div class="stat-featured-head">
                        <span class="stat-featured-icon"><i class="bi bi-clock-history"></i></span>
                        <h3>History</h3>
                    </div>
                    <div class="number">
                        <?= $historyCount ?>
                    </div>
                    <div class="stat-featured-sub">
                        <span class="stat-featured-dot"></span>
                        Past notifications
                    </div>
                    <span class="stat-go"><i class="bi bi-chevron-right"></i></span>
                </a>

            </div>



            <!-- =================================================
                 NOTIFICATION CARD
            ================================================== -->

            <section class="portal-card">


                <!-- FILTER -->

                <div class="notification-filter">


                    <div>

                        <div class="notification-filter-title">

                            Notification List

                        </div>

                        <div class="notification-filter-sub">

                            Important appointment updates assigned to you

                        </div>

                    </div>


                    <div class="filter-buttons">


                        <a
                            href="notifications.php?filter=All<?= $notifNoSidebarSuffix ?>"
                            class="filter-button
                            <?= $filter === 'All' ? 'active' : '' ?>"
                        >

                            All

                        </a>


                        <a
                            href="notifications.php?filter=Upcoming<?= $notifNoSidebarSuffix ?>"
                            class="filter-button
                            <?= $filter === 'Upcoming' ? 'active' : '' ?>"
                        >

                            Upcoming

                        </a>


                        <a
                            href="notifications.php?filter=New<?= $notifNoSidebarSuffix ?>"
                            class="filter-button
                            <?= $filter === 'New' ? 'active' : '' ?>"
                        >

                            New

                        </a>


                        <a
                            href="notifications.php?filter=Cancelled<?= $notifNoSidebarSuffix ?>"
                            class="filter-button
                            <?= $filter === 'Cancelled' ? 'active' : '' ?>"
                        >

                            Cancelled

                        </a>


                        <a
                            href="notifications.php?filter=Concerns<?= $notifNoSidebarSuffix ?>"
                            class="filter-button
                            <?= $filter === 'Concerns' ? 'active' : '' ?>"
                        >

                            Concerns

                        </a>


                        <a
                            href="notifications.php?filter=History<?= $notifNoSidebarSuffix ?>"
                            class="filter-button
                            <?= $filter === 'History' ? 'active' : '' ?>"
                        >

                            History

                        </a>


                    </div>

                    <?php if ($filter === 'History'): ?>
                    <div class="filter-buttons" style="margin-top:10px;">
                        <a href="notifications.php?filter=History&htype=all<?= $notifNoSidebarSuffix ?>" class="filter-button <?= $htype === 'all' ? 'active' : '' ?>">All History (<?= (int)$historyCount ?>)</a>
                        <a href="notifications.php?filter=History&htype=completed<?= $notifNoSidebarSuffix ?>" class="filter-button <?= $htype === 'completed' ? 'active' : '' ?>">Completed (<?= (int)$historyCompletedCount ?>)</a>
                        <a href="notifications.php?filter=History&htype=noshow<?= $notifNoSidebarSuffix ?>" class="filter-button <?= $htype === 'noshow' ? 'active' : '' ?>">No-Show (<?= (int)$historyNoshowCount ?>)</a>
                        <a href="notifications.php?filter=History&htype=past<?= $notifNoSidebarSuffix ?>" class="filter-button <?= $htype === 'past' ? 'active' : '' ?>">Past (<?= (int)$historyPastCount ?>)</a>
                    </div>
                    <?php endif; ?>


                </div>



                <!-- =================================================
                     NOTIFICATION LIST TABLE
                ================================================== -->

                <?php if (
                    empty($filteredNotifications)
                ): ?>


                    <div class="notification-empty">

                        <i class="bi bi-bell-slash"></i>


                        <strong>

                            No notifications yet.

                        </strong>


                        <div>

                            There are no notifications
                            matching this filter.

                        </div>

                    </div>


                <?php else: ?>


                    <div class="notif-list">

                        <?php foreach (
                            $notifPageItems
                            as $notification
                        ): ?>


                            <?php

                            $appointment =
                                $notification['appointment'];

                            $type =
                                $notification['type'];

                            $patientName =
                                trim(
                                    $appointment['first_name']
                                    . ' '
                                    . (
                                        $appointment['middle_name']
                                        ?? ''
                                    )
                                    . ' '
                                    . $appointment['last_name']
                                );

                            $notifKey = md5(
                                ($type ?? '')
                                . '|' . ($appointment['id'] ?? 0)
                                . '|' . ($notification['date_time'] ?? '')
                            );

                            $isNewRow = ($type === 'new');

                            ?>


                            <article
                                class="notif-card type-<?= dentistNotificationEscape($type) ?><?= $isNewRow ? ' is-new' : '' ?>"
                                data-notif-key="<?= dentistNotificationEscape($notifKey) ?>"
                                data-href="appointments.php?highlight=<?= (int)($appointment['id'] ?? 0) ?>&from=notifications<?= (($_GET['nosidebar'] ?? '') === '1' ? '&nosidebar=1' : '') ?>"
                                data-title="<?= dentistNotificationEscape($notification['title']) ?>"
                                data-message="<?= dentistNotificationEscape($notification['message']) ?>"
                                data-patient="<?= dentistNotificationEscape($patientName) ?>"
                                data-service="<?= dentistNotificationEscape($appointment['service_type'] ?? '') ?>"
                                data-date="<?= dentistNotificationEscape(date('F d, Y', strtotime($appointment['appointment_date']))) ?>"
                                data-timerange="<?= dentistNotificationEscape(date('h:i A', strtotime($appointment['start_time'])) . (!empty($appointment['end_time']) ? ' - ' . date('h:i A', strtotime($appointment['end_time'])) : '')) ?>"
                                data-status="<?= dentistNotificationEscape($appointment['status'] ?? '') ?>"
                                tabindex="0"
                            >

                                <span class="notif-dot" aria-hidden="true"></span>

                                <div class="nt-icon <?= dentistNotificationEscape($type) ?>">
                                    <?php if (!empty($notification['emoji'])): ?>
                                        <span class="nt-emoji"><?= dentistNotificationEscape($notification['emoji']) ?></span>
                                    <?php else: ?>
                                        <i class="bi <?= dentistNotificationEscape($notification['icon']) ?>"></i>
                                    <?php endif; ?>
                                </div>

                                <div class="notif-body">

                                    <div class="notif-top">
                                        <div class="nt-title">
                                            <?= dentistNotificationEscape($notification['title']) ?>
                                            <?php if ($isNewRow): ?><span class="new-badge">NEW</span><?php endif; ?>
                                        </div>
                                        <div class="nt-time">
                                            <?= dentistNotificationEscape(date('h:i A', strtotime($notification['date_time']))) ?>
                                            <small><?= dentistNotificationEscape(date('M d, Y', strtotime($notification['date_time']))) ?></small>
                                        </div>
                                    </div>

                                    <div class="nt-msg">
                                        <?= dentistNotificationEscape($notification['message']) ?>
                                    </div>

                                    <div class="notif-meta">
                                        <span class="notif-chip">
                                            <i class="bi bi-person-fill"></i>
                                            <?= dentistNotificationEscape($patientName) ?>
                                        </span>
                                        <span class="notif-chip">
                                            <i class="bi bi-briefcase-fill"></i>
                                            <?= dentistNotificationEscape($appointment['service_type']) ?>
                                        </span>
                                        <span class="notif-chip">
                                            <i class="bi bi-calendar-event"></i>
                                            <?= dentistNotificationEscape(date('F d, Y', strtotime($appointment['appointment_date']))) ?>
                                            ·
                                            <?= dentistNotificationEscape(date('h:i A', strtotime($appointment['start_time']))) ?>
                                            <?php if (!empty($appointment['end_time'])): ?>
                                                - <?= dentistNotificationEscape(date('h:i A', strtotime($appointment['end_time']))) ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>

                                </div>

                                <a
                                    href="appointments.php?highlight=<?= (int)($appointment['id'] ?? 0) ?>&from=notifications<?= (($_GET['nosidebar'] ?? '') === '1' ? '&nosidebar=1' : '') ?>"
                                    class="view-appointment"
                                >

                                    <i class="bi bi-eye"></i>

                                    View Appointment

                                </a>

                            </article>


                        <?php endforeach; ?>

                    </div>


                    <?php if ($notifTotalPages > 1): ?>
                        <nav class="notif-pagination" aria-label="Notification pages">
                            <?php if ($notifPage > 1): ?>
                                <a class="notif-page-btn" href="<?= dentistNotificationEscape(notifPageUrl($notifPage - 1)) ?>">
                                    <i class="bi bi-chevron-left"></i> Prev
                                </a>
                            <?php else: ?>
                                <span class="notif-page-btn disabled"><i class="bi bi-chevron-left"></i> Prev</span>
                            <?php endif; ?>

                            <?php for ($notifP = 1; $notifP <= $notifTotalPages; $notifP++): ?>
                                <?php if ($notifP === $notifPage): ?>
                                    <span class="notif-page-btn active"><?= $notifP ?></span>
                                <?php else: ?>
                                    <a class="notif-page-btn" href="<?= dentistNotificationEscape(notifPageUrl($notifP)) ?>"><?= $notifP ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($notifPage < $notifTotalPages): ?>
                                <a class="notif-page-btn" href="<?= dentistNotificationEscape(notifPageUrl($notifPage + 1)) ?>">
                                    Next <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="notif-page-btn disabled">Next <i class="bi bi-chevron-right"></i></span>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>


                <?php endif; ?>


            </section>


        </div>


        <div class="nt-modal-overlay" id="ntModalOverlay">
            <div class="nt-modal-box" role="dialog" aria-modal="true">
                <div class="nt-modal-head">
                    <div class="nt-icon new" id="ntModalIcon"><i class="bi bi-bell-fill"></i></div>
                    <h3 id="ntModalTitle">Notification</h3>
                    <button type="button" class="nt-modal-close" id="ntModalClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="nt-modal-body">
                    <div class="nt-modal-msg" id="ntModalMsg"></div>
                    <div class="nt-modal-grid">
                        <span class="notif-chip"><i class="bi bi-person-fill"></i><span id="ntModalPatient">&mdash;</span></span>
                        <span class="notif-chip"><i class="bi bi-briefcase-fill"></i><span id="ntModalService">&mdash;</span></span>
                        <span class="notif-chip"><i class="bi bi-calendar-event"></i><span id="ntModalDate">&mdash;</span></span>
                        <span class="notif-chip"><i class="bi bi-clock-fill"></i><span id="ntModalTime">&mdash;</span></span>
                    </div>
                    <div class="notif-meta">
                        <span class="notif-chip"><i class="bi bi-flag-fill"></i><span id="ntModalStatus">&mdash;</span></span>
                    </div>
                </div>
                <div class="nt-modal-foot">
                    <button type="button" class="nt-modal-btn" id="ntModalCancel">Close</button>
                    <a class="nt-modal-btn primary" id="ntModalGo" href="#"><i class="bi bi-calendar-event"></i>Open Appointment</a>
                </div>
            </div>
        </div>


        <?php if (($_GET['nosidebar'] ?? '') === '1'): ?>
        <style>.portal-sidebar{display:none!important;}.portal-main{margin-left:0!important;width:100%!important;}</style>
        <script>
        function autoCloseSubTab(){
            try{ if(window.opener && !window.opener.closed){ window.opener.focus(); } }catch(e){}
            /* Hindi na nagsasara ang tab — same-tab navigation. */
            if (!window.opener || window.opener.closed) {
                window.location.href='dentist_dashboard.php';
            }
            return false;
        }
        </script>
        <?php endif; ?>
    </main>

<script>
/* Notif highlight: NEW cards naka-highlight, click = select + mark as read ( nawawala ang NEW ).
   Pag nag-View, naka-highlight muna at naaalala pagbalik via sessionStorage. */
(function(){
    var READ_KEY = 'dentistNotifRead';
    var SEL_KEY = 'dentistNotifHighlight';
    function getRead(){
        try { return JSON.parse(localStorage.getItem(READ_KEY) || '[]'); } catch(e){ return []; }
    }
    function setRead(list){
        try { localStorage.setItem(READ_KEY, JSON.stringify(list)); } catch(e){}
    }
    function markRead(key){
        if(!key) return;
        var list = getRead();
        if(list.indexOf(key) === -1){ list.push(key); setRead(list); }
    }
    var rows = document.querySelectorAll('.notif-list .notif-card');

    /* ---- Modal (click = popup, hindi na umaalis sa page) ---- */
    var overlay = document.getElementById('ntModalOverlay');
    function txt(id, val){
        var el = document.getElementById(id);
        if(el) el.textContent = val || '—';
    }
    function openModal(row){
        if(!overlay) return;
        txt('ntModalTitle', row.getAttribute('data-title'));
        txt('ntModalMsg', row.getAttribute('data-message'));
        txt('ntModalPatient', row.getAttribute('data-patient'));
        txt('ntModalService', row.getAttribute('data-service'));
        txt('ntModalDate', row.getAttribute('data-date'));
        txt('ntModalTime', row.getAttribute('data-timerange'));
        txt('ntModalStatus', row.getAttribute('data-status'));
        var go = document.getElementById('ntModalGo');
        if(go){
            var href = row.getAttribute('data-href');
            go.setAttribute('href', href || '#');
            go.style.display = (href && href.indexOf('highlight=0') === -1) ? '' : 'none';
        }
        var icon = document.getElementById('ntModalIcon');
        if(icon){
            var cls = (row.className.match(/type-([a-z\-]+)/) || [])[1];
            icon.className = 'nt-icon ' + (cls || 'new');
        }
        overlay.classList.add('open');
    }
    function closeModal(){ if(overlay) overlay.classList.remove('open'); }
    if(overlay){
        overlay.addEventListener('click', function(e){ if(e.target === overlay) closeModal(); });
        document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeModal(); });
    }
    ['ntModalClose','ntModalCancel'].forEach(function(id){
        var b = document.getElementById(id);
        if(b) b.addEventListener('click', closeModal);
    });

    var readList = getRead();
    rows.forEach(function(row){
        var key = row.getAttribute('data-notif-key');
        if(key && readList.indexOf(key) !== -1){
            row.classList.remove('is-new');
            row.classList.add('is-read');
        }
    });
    rows.forEach(function(row){
        row.addEventListener('click', function(e){
            var key = row.getAttribute('data-notif-key');
            var isLink = e.target.closest('a, button');
            rows.forEach(function(r){ r.classList.remove('selected'); });
            row.classList.add('selected');
            try { sessionStorage.setItem(SEL_KEY, key || ''); } catch(err) {}
            /* Click = nabasa na: tanggalin ang NEW highlight */
            if(key){
                row.classList.remove('is-new');
                row.classList.add('is-read');
                markRead(key);
            }
            var href = row.getAttribute('data-href');
            if(href && href !== 'appointments.php?highlight=0&from=notifications'){
                openModal(row);
                return;
            }
            if(isLink){ return; }
            try { row.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch(err) {}
        });
        row.addEventListener('keydown', function(ev){
            if(ev.key === 'Enter' || ev.key === ' '){ ev.preventDefault(); row.click(); }
        });
    });
    /* Pagbalik galing View: i-highlight ang huling biniew */
    try {
        var saved = sessionStorage.getItem(SEL_KEY);
        if(saved){
            var el = document.querySelector('.notif-list .notif-card[data-notif-key="' + saved + '"]');
            if(el){
                rows.forEach(function(r){ r.classList.remove('selected'); });
                el.classList.add('selected');
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    } catch(err) {}
})();
</script>
<script>
/* Itago ang sidebar kapag binuksan mula sa dashboard menu (?nosidebar=1) */
(function(){
    try {
        if (new URLSearchParams(window.location.search).get('nosidebar') === '1') {
            document.querySelectorAll('.portal-sidebar').forEach(function(el){ el.style.display = 'none'; });
            document.querySelectorAll('.portal-main').forEach(function(el){ el.style.marginLeft = '0'; el.style.width = '100%'; });
        }
    } catch(e){}
})();
</script>


<!-- DENTIST LOGOUT CONFIRM — "Are you sure you want to log out?" -->
<div id="dentistLogoutConfirm" class="dentist-logout-overlay" style="display:none;">
    <div class="dentist-logout-box">
        <div class="dentist-logout-head">
            <span class="dentist-logout-head-icon"><i class="bi bi-box-arrow-right"></i></span>
            <h5>Log Out</h5>
            <button type="button" class="dentist-logout-x" id="dentistLogoutClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="dentist-logout-body">
            <div class="dentist-logout-icon"><i class="bi bi-exclamation-circle-fill"></i></div>
            <h5>Are you sure you want to log out?</h5>
            <p>You will need to log in again to access the dentist account.</p>
        </div>
        <div class="dentist-logout-foot">
            <button type="button" class="btn btn-light" id="dentistLogoutCancel">Cancel</button>
            <a href="../logout.php" class="btn dentist-logout-yes" id="dentistLogoutYes"><i class="bi bi-box-arrow-right me-1"></i>Log Out</a>
        </div>
    </div>
</div>
<style>
.dentist-logout-overlay { position: fixed; inset: 0; background: rgba(10,59,52,.55); z-index: 2000; padding: 20px; align-items: center; justify-content: center; }
.dentist-logout-box { background: #ffffff; border-radius: 18px; border: 1px solid #C9E9DA; box-shadow: 0 18px 45px rgba(0,0,0,.25); max-width: 440px; width: 100%; overflow: hidden; font-family: "Inter", "DM Sans", system-ui, sans-serif; animation: dentistLogoutPop .18s ease; }
@keyframes dentistLogoutPop { from { transform: scale(.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.dentist-logout-head { display: flex; align-items: center; gap: 12px; padding: 18px 22px; background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%); border-bottom: 1px solid #C9E9DA; }
.dentist-logout-head-icon { width: 38px; height: 38px; border-radius: 11px; background: linear-gradient(135deg, #0E6B5E, #0A4A41); color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 4px 10px rgba(14,107,94,.25); flex-shrink: 0; }
.dentist-logout-head h5 { color: #143f51; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; font-size: 17px; margin: 0; flex: 1; }
.dentist-logout-x { background: #ffffff; border: 1px solid #D1E7DD; color: #57727d; width: 30px; height: 30px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; flex-shrink: 0; }
.dentist-logout-x:hover { background: #EAF7F0; color: #0E6B5E; }
.dentist-logout-body { padding: 30px 28px 8px; text-align: center; }
.dentist-logout-icon { width: 64px; height: 64px; border-radius: 18px; background: #FDECEC; color: #C0392B; border: 1px solid #F5C6C6; display: inline-flex; align-items: center; justify-content: center; font-size: 30px; margin-bottom: 14px; }
.dentist-logout-body h5 { color: #143f51; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; font-size: 18px; margin: 0 0 8px; }
.dentist-logout-body p { color: #8aa0a8; font-size: 13.5px; margin: 0; }
.dentist-logout-foot { display: flex; gap: 12px; justify-content: center; padding: 18px 22px 22px; background: #F6FBF8; border-top: 1px solid #E6F2EC; margin-top: 20px; }
.dentist-logout-foot .btn { border-radius: 10px; font-weight: 700; font-size: 14px; padding: 10px 26px; }
.dentist-logout-foot .btn-light { background: #fff !important; border: 1px solid #D1E7DD !important; color: #57727d !important; }
.dentist-logout-foot .btn-light:hover { background: #EAF7F0 !important; color: #0E6B5E !important; }
.dentist-logout-yes { background: linear-gradient(135deg, #E74C3C, #922B21) !important; border: none !important; color: #ffffff !important; box-shadow: 0 6px 14px rgba(192,57,43,.3) !important; }
.dentist-logout-yes:hover { filter: brightness(.93); color: #ffffff !important; }
</style>
<script>
(function()
{
    function openDentistLogout(e)
    {
        if (e) e.preventDefault();
        var overlay = document.getElementById("dentistLogoutConfirm");
        if (!overlay) return;
        overlay.style.display = "flex";
        document.body.style.overflow = "hidden";
    }
    function closeDentistLogout()
    {
        var overlay = document.getElementById("dentistLogoutConfirm");
        if (!overlay) return;
        overlay.style.display = "none";
        document.body.style.overflow = "";
    }
    function initDentistLogout()
    {
        var overlay = document.getElementById("dentistLogoutConfirm");
        if (!overlay || overlay.dataset.bound === "1") return;
        overlay.dataset.bound = "1";
        document.querySelectorAll('a[href="../logout.php"], a[href="logout.php"]').forEach(function(a)
        {
            if (a.closest("#dentistLogoutConfirm")) return;
            if (a.dataset.logoutBound === "1") return;
            a.dataset.logoutBound = "1";
            a.addEventListener("click", openDentistLogout);
        });
        document.getElementById("dentistLogoutCancel").addEventListener("click", closeDentistLogout);
        document.getElementById("dentistLogoutClose").addEventListener("click", closeDentistLogout);
        overlay.addEventListener("click", function(e)
        {
            if (e.target === overlay) closeDentistLogout();
        });
        document.addEventListener("keydown", function(e)
        {
            if (e.key === "Escape") closeDentistLogout();
        });
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initDentistLogout);
    } else {
        initDentistLogout();
    }
})();
</script>
</body>

</html>
