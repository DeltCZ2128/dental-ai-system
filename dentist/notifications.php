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
            'badge_label' => 'Cancelled',
            'icon' => 'bi-calendar-x-fill',
            'title' => 'Appointment Cancelled',
            'message' =>
                $fullName
                . ' cancelled an appointment scheduled for '
                . date('F j, Y', strtotime($appointmentDate))
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
                . ' today at '
                . date('h:i A', strtotime($startTime))
                . '.'
            : 'You have an appointment with '
                . $fullName
                . ' on '
                . date('F j, Y', strtotime($appointmentDate))
                . ' at '
                . date('h:i A', strtotime($startTime))
                . '.';

        $notifications[] = [
            'type' => 'upcoming',
            'badge_label' => 'Upcoming',
            'icon' => 'bi-alarm-fill',
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
                : 'booked a new appointment';

            $notifications[] = [
                'type' => 'new',
                'badge_label' => 'New Booking',
                'icon' => 'bi-calendar-plus-fill',
                'title' => 'New Appointment Assigned',
                'message' =>
                    $fullName
                    . ' ' . $newVerb . ' on '
                    . date('F j, Y', strtotime($appointmentDate))
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
            'badge_label' => 'Today',
            'icon' => 'bi-calendar-event-fill',
            'title' => "Today's Appointment Reminder",
            'message' =>
                $fullName
                . ' is scheduled for an appointment today at '
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
            $historyTitle = 'Appointment Completed';
            $historyMsg = $fullName . ' completed an appointment on '
                . date('F d, Y', strtotime($appointmentDate)) . '.';
            $historyIcon = 'bi-check-circle-fill';
            $historySub = 'completed';
            $historyBadge = 'Completed';
        } elseif ($statusLower === 'no-show' || $statusLower === 'no show') {
            $historyTitle = 'Patient No-Show';
            $historyMsg = $fullName . ' missed an appointment on '
                . date('F d, Y', strtotime($appointmentDate)) . '.';
            $historyIcon = 'bi-person-x-fill';
            $historySub = 'noshow';
            $historyBadge = 'No-Show';
        } else {
            $historyTitle = 'Past Appointment';
            $historyMsg = $fullName . ' had an appointment on '
                . date('F d, Y', strtotime($appointmentDate)) . '.';
            $historyIcon = 'bi-clock-history';
            $historySub = 'past';
            $historyBadge = 'Past';
        }

        $notifications[] = [
            'type' => 'history',
            'history_sub' => $historySub,
            'badge_label' => $historyBadge,
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
            a.appointment_date,
            a.start_time,
            a.end_time,
            a.service_type,
            a.status AS appt_status,
            a.notes AS appt_notes,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.patient_number,
            p.contact_number,
            p.email
        FROM dentist_notifications dn
        LEFT JOIN appointments a ON a.id = dn.appointment_id
        LEFT JOIN patients p ON p.id = COALESCE(dn.patient_id, a.patient_id)
        WHERE dn.dentist_id = ?
        ORDER BY dn.created_at DESC
        LIMIT 50
    ");
    $concernStmt->execute([$dentistId]);
    $concernNotifs = $concernStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($concernNotifs as $cn) {
        $cnAppointment = [
            'id' => (int)($cn['appointment_id'] ?? 0),
            'patient_id' => (int)($cn['patient_id'] ?? 0),
            'appointment_date' => $cn['appointment_date'] ?? '',
            'start_time' => $cn['start_time'] ?? '',
            'end_time' => $cn['end_time'] ?? '',
            'service_type' => $cn['service_type'] ?? 'General Consultation',
            'status' => $cn['appt_status'] ?? 'Concern Raised',
            'notes' => $cn['message'] ?: ($cn['appt_notes'] ?? ''),
            'created_at' => $cn['created_at'] ?? '',
            'patient_number' => $cn['patient_number'] ?? '',
            'first_name' => $cn['first_name'] ?? 'Patient',
            'middle_name' => $cn['middle_name'] ?? '',
            'last_name' => $cn['last_name'] ?? '',
            'contact_number' => $cn['contact_number'] ?? '',
            'email' => $cn['email'] ?? ''
        ];
        $notifications[] = [
            'type' => 'concern',
            'badge_label' => 'Concern',
            'icon' => 'bi-chat-dots-fill',
            'title' => $cn['title'] ?? 'Patient Concern',
            'message' => $cn['message'] ?? '',
            'appointment' => $cnAppointment,
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
$concernCount = 0;
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
        $notification['type'] === 'concern'
    ) {
        $concernCount++;
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
        /* ======================================================
           NOTIFICATION CENTER — FEED / INBOX LAYOUT
        ======================================================= */
        .notif-center-shell {
            background: #ffffff;
            border: 1px solid #C9E9DA;
            border-radius: 18px;
            box-shadow: 0 14px 36px rgba(6, 59, 49, 0.14);
            overflow: hidden;
        }

        /* Header Banner */
        .notif-center-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
            padding: 20px 24px;
            background: linear-gradient(135deg, #0E6B5E 0%, #0A4A41 100%);
            border-bottom: 1px solid rgba(255, 255, 255, 0.14);
        }

        .notif-head-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .notif-bell-badge {
            position: relative;
            width: 46px;
            height: 46px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.28);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 21px;
            flex-shrink: 0;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.16);
        }

        .notif-head-title {
            font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
            font-size: 19px;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.02em;
            margin: 0;
            line-height: 1.2;
        }

        .notif-head-sub {
            font-size: 13px;
            font-weight: 500;
            color: #D9F2E6;
            margin-top: 3px;
        }

        .notif-head-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .notif-counter-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 13px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        .notif-counter-pill.unread-pill {
            background: linear-gradient(135deg, #FDE68A, #F59E0B);
            border-color: rgba(255, 255, 255, 0.4);
            color: #451A03;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.28);
        }

        .btn-mark-all-read {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.38);
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.18s ease;
        }

        .btn-mark-all-read:hover {
            background: #ffffff;
            color: #0E6B5E;
            border-color: #ffffff;
        }

        /* Filter Tabs Strip */
        .notif-tabs-strip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            padding: 12px 24px;
            background: #F4FAF7;
            border-bottom: 1px solid #DDEEE5;
        }

        .filter-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-button {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            padding: 7px 14px;
            border-radius: 999px;
            border: 1px solid #CBE4D8;
            background: #ffffff;
            color: #244f60;
            font-size: 12.5px;
            font-weight: 700;
            transition: all 0.18s ease;
        }

        .filter-button i {
            font-size: 13px;
            color: #0E6B5E;
        }

        .filter-button .tab-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            border-radius: 999px;
            background: #EAF7F0;
            color: #0E6B5E;
            font-size: 11px;
            font-weight: 800;
        }

        .filter-button:hover {
            background: #EAF7F0;
            border-color: #0E6B5E;
            color: #0E6B5E;
        }

        .filter-button.active {
            background: linear-gradient(135deg, #0E6B5E, #0A4A41);
            border-color: #0E6B5E;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(14, 107, 94, 0.24);
        }

        .filter-button.active i {
            color: #ffffff;
        }

        .filter-button.active .tab-count {
            background: rgba(255, 255, 255, 0.22);
            color: #ffffff;
        }

        .history-subtabs {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            width: 100%;
            padding-top: 8px;
            margin-top: 4px;
            border-top: 1px dashed #CBE4D8;
        }

        /* Notification Feed List */
        .notif-feed-wrap {
            padding: 16px 22px 22px;
        }

        .notif-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .notif-card {
            position: relative;
            display: flex;
            align-items: center;
            gap: 14px;
            background: #ffffff;
            border: 1px solid #E2ECE7;
            border-radius: 14px;
            padding: 15px 18px 15px 22px;
            box-shadow: 0 3px 10px rgba(14, 107, 94, 0.04);
            cursor: pointer;
            transition: transform 0.16s ease, box-shadow 0.16s ease, background 0.16s ease, border-color 0.16s ease;
            overflow: hidden;
        }

        .notif-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 5px;
            background: #0E6B5E;
        }

        .notif-card.type-new::before { background: linear-gradient(180deg, #0EA5E9, #0284C7); }
        .notif-card.type-today::before { background: linear-gradient(180deg, #F59E0B, #D97706); }
        .notif-card.type-upcoming::before { background: linear-gradient(180deg, #10B981, #047857); }
        .notif-card.type-cancelled::before { background: linear-gradient(180deg, #EF4444, #B91C1C); }
        .notif-card.type-concern::before { background: linear-gradient(180deg, #8B5CF6, #6D28D9); }
        .notif-card.type-history::before { background: linear-gradient(180deg, #94A3B8, #475569); }

        .notif-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(14, 107, 94, 0.12);
            border-color: #0E6B5E;
            background: #F9FDFB;
        }

        .notif-card:hover .nt-title {
            color: #0E6B5E;
        }

        .notif-card.is-unread {
            background: #F2FAF6;
            border-color: #B9E2CD;
        }

        .notif-card.is-read {
            background: #ffffff;
        }

        .notif-card.selected {
            border-color: #0E6B5E;
            box-shadow: 0 0 0 2px rgba(14, 107, 94, 0.15);
        }

        /* Unread indicator dot */
        .notif-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #10B981;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.2);
            flex-shrink: 0;
            transition: opacity 0.2s ease;
        }

        .notif-card.is-read .notif-dot {
            background: #CBD5E1;
            box-shadow: none;
            opacity: 0.45;
        }

        /* Notification Icon Box */
        .nt-icon {
            width: 44px;
            height: 44px;
            flex-shrink: 0;
            border-radius: 12px;
            background: #EAF7F0;
            color: #0E6B5E;
            border: 1px solid #C9E9DA;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
        }

        .nt-icon.new { background: #E0F2FE; color: #0369A1; border-color: #BAE6FD; }
        .nt-icon.today { background: #FEF3C7; color: #B45309; border-color: #FDE68A; }
        .nt-icon.upcoming { background: #D1FAE5; color: #047857; border-color: #A7F3D0; }
        .nt-icon.cancelled { background: #FEE2E2; color: #B91C1C; border-color: #FECACA; }
        .nt-icon.concern { background: #EDE9FE; color: #6D28D9; border-color: #DDD6FE; }
        .nt-icon.history { background: #F1F5F9; color: #475569; border-color: #E2E8F0; }

        .notif-body {
            flex: 1;
            min-width: 0;
        }

        .notif-top {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 4px;
        }

        .nt-title {
            font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
            font-size: 14.5px;
            font-weight: 800;
            color: #143f51;
            margin: 0;
        }

        .notif-type-tag {
            display: inline-flex;
            align-items: center;
            padding: 2px 9px;
            border-radius: 999px;
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            background: #EAF7F0;
            color: #0E6B5E;
            border: 1px solid #C9E9DA;
        }

        .notif-type-tag.new { background: #E0F2FE; color: #0369A1; border-color: #BAE6FD; }
        .notif-type-tag.today { background: #FEF3C7; color: #B45309; border-color: #FDE68A; }
        .notif-type-tag.upcoming { background: #D1FAE5; color: #047857; border-color: #A7F3D0; }
        .notif-type-tag.cancelled { background: #FEE2E2; color: #B91C1C; border-color: #FECACA; }
        .notif-type-tag.concern { background: #EDE9FE; color: #6D28D9; border-color: #DDD6FE; }
        .notif-type-tag.history { background: #F1F5F9; color: #475569; border-color: #CBD5E1; }

        .new-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.5px;
            color: #92400E;
            background: #FEF3C7;
            border: 1px solid #F59E0B;
            border-radius: 999px;
            padding: 2px 8px;
        }

        .notif-card.is-read .new-badge {
            display: none;
        }

        .nt-msg {
            font-size: 13.5px;
            color: #415a64;
            line-height: 1.5;
            margin-bottom: 8px;
        }

        .notif-meta {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            color: #5b747e;
        }

        .notif-meta-item {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .notif-meta-item i {
            color: #0E6B5E;
            font-size: 12.5px;
        }

        /* Right Side: Timestamp + View Appointment Button */
        .notif-right {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: center;
            gap: 8px;
            flex-shrink: 0;
            margin-left: 8px;
        }

        .nt-time {
            white-space: nowrap;
            text-align: right;
            font-size: 12px;
            font-weight: 700;
            color: #4b6570;
            line-height: 1.25;
        }

        .nt-time small {
            display: block;
            color: #7c939d;
            font-size: 11px;
            font-weight: 600;
            margin-top: 2px;
        }

        .view-appointment {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 999px;
            border: 1.5px solid #0E6B5E;
            background: #EAF7F0;
            color: #0E6B5E;
            font-family: "Inter", "DM Sans", system-ui, sans-serif;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.18s ease;
            white-space: nowrap;
        }

        .view-appointment:hover {
            background: linear-gradient(135deg, #0E6B5E, #0A4A41);
            color: #ffffff;
            border-color: #0E6B5E;
            box-shadow: 0 4px 12px rgba(14, 107, 94, 0.24);
        }

        /* Pagination */
        .notif-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid #E6F2EC;
        }

        .notif-page-info {
            font-size: 12.5px;
            font-weight: 600;
            color: #5b747e;
        }

        .notif-page-info strong {
            color: #0E6B5E;
            font-weight: 800;
        }

        .notif-page-links {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .notif-page-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            min-width: 34px;
            height: 34px;
            justify-content: center;
            padding: 0 10px;
            border-radius: 9px;
            border: 1px solid #C9E9DA;
            background: #ffffff;
            color: #0E6B5E;
            font-family: "Inter", system-ui, sans-serif;
            font-size: 12.5px;
            font-weight: 800;
            text-decoration: none;
            transition: all 0.16s ease;
        }

        .notif-page-btn:hover:not(.disabled):not(.active) {
            background: #EAF7F0;
            border-color: #0E6B5E;
        }

        .notif-page-btn.active {
            background: linear-gradient(135deg, #0E6B5E, #0A4A41);
            border-color: #0E6B5E;
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(14, 107, 94, 0.22);
        }

        .notif-page-btn.disabled {
            opacity: 0.45;
            cursor: default;
        }

        /* Empty State */
        .notification-empty {
            text-align: center;
            padding: 50px 20px;
            color: #5b747e;
        }

        .notification-empty .empty-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            background: #EAF7F0;
            color: #0E6B5E;
            border: 1px solid #C9E9DA;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 12px;
        }

        /* ======================================================
           APPOINTMENT DETAILS POPUP MODAL
        ======================================================= */
        .nt-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(6, 40, 34, 0.62);
            backdrop-filter: blur(3px);
            z-index: 3000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .nt-modal-overlay.open {
            display: flex;
        }

        .nt-modal-box {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #C9E9DA;
            width: 100%;
            max-width: 580px;
            overflow: hidden;
            box-shadow: 0 24px 55px rgba(0, 0, 0, 0.30);
            animation: ntModalPop 0.18s ease-out;
        }

        @keyframes ntModalPop {
            from { transform: scale(0.96) translateY(6px); opacity: 0; }
            to { transform: scale(1) translateY(0); opacity: 1; }
        }

        .nt-modal-head {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 18px 22px;
            background: linear-gradient(135deg, #0E6B5E 0%, #0A4A41 100%);
            color: #ffffff;
        }

        .nt-modal-head .nt-icon {
            width: 42px;
            height: 42px;
            font-size: 18px;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #ffffff;
        }

        .nt-modal-head-text {
            flex: 1;
            min-width: 0;
        }

        .nt-modal-head h3 {
            margin: 0;
            font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
            font-size: 17px;
            font-weight: 800;
            color: #ffffff;
        }

        .nt-modal-head-sub {
            font-size: 12px;
            color: #D9F2E6;
            font-weight: 600;
            margin-top: 2px;
        }

        .nt-modal-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 800;
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.35);
            color: #ffffff;
            white-space: nowrap;
        }

        .nt-modal-close {
            border: 1px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 15px;
            flex-shrink: 0;
            transition: all 0.16s ease;
        }

        .nt-modal-close:hover {
            background: #ffffff;
            color: #0E6B5E;
        }

        .nt-modal-body {
            padding: 20px 22px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            max-height: 75vh;
            overflow-y: auto;
        }

        .nt-modal-banner {
            padding: 13px 16px;
            border-radius: 12px;
            background: #F2FAF6;
            border: 1px solid #C9E9DA;
            border-left: 4px solid #0E6B5E;
        }

        .nt-modal-banner-title {
            font-size: 13.5px;
            font-weight: 800;
            color: #0E6B5E;
            margin-bottom: 3px;
        }

        .nt-modal-msg {
            font-size: 13.5px;
            line-height: 1.55;
            color: #2c4752;
        }

        .nt-modal-details-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .nt-detail-box {
            padding: 11px 14px;
            border-radius: 12px;
            background: #F8FCFA;
            border: 1px solid #E2ECE7;
        }

        .nt-detail-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #6c858d;
            margin-bottom: 4px;
        }

        .nt-detail-label i {
            color: #0E6B5E;
            font-size: 12.5px;
        }

        .nt-detail-value {
            font-size: 13.5px;
            font-weight: 800;
            color: #143f51;
            word-break: break-word;
        }

        .nt-detail-sub {
            font-size: 12px;
            font-weight: 600;
            color: #5b747e;
            margin-top: 2px;
        }

        .nt-notes-box {
            padding: 12px 14px;
            border-radius: 12px;
            background: #F8FCFA;
            border: 1px solid #E2ECE7;
        }

        .nt-notes-text {
            font-size: 13px;
            color: #34495e;
            line-height: 1.55;
            white-space: pre-line;
        }

        .nt-modal-foot {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 10px;
            padding: 14px 22px;
            border-top: 1px solid #DDEEE5;
            background: #F6FBF8;
        }

        .nt-modal-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1.5px solid #0E6B5E;
            background: linear-gradient(135deg, #0E6B5E, #0A4A41);
            color: #ffffff;
            border-radius: 999px;
            padding: 8px 20px;
            font-family: "Inter", system-ui, sans-serif;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.16s ease;
        }

        .nt-modal-btn:hover {
            filter: brightness(1.08);
            box-shadow: 0 6px 14px rgba(14, 107, 94, 0.25);
        }

        @media (max-width: 768px) {
            .notif-card {
                flex-direction: column;
                align-items: flex-start;
            }
            .notif-right {
                width: 100%;
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                margin-left: 0;
                padding-top: 8px;
                border-top: 1px dashed #E2ECE7;
            }
            .nt-time {
                text-align: left;
            }
            .nt-modal-details-grid {
                grid-template-columns: 1fr;
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
                 NOTIFICATION CENTER SHELL
            ================================================== -->

            <section class="notif-center-shell">

                <!-- HEADER BANNER -->
                <div class="notif-center-head">
                    <div class="notif-head-left">
                        <span class="notif-bell-badge">
                            <i class="bi bi-bell-fill"></i>
                        </span>
                        <div>
                            <h2 class="notif-head-title">Notification Center</h2>
                            <div class="notif-head-sub">
                                Real-time appointment activity, schedule reminders, and patient concerns assigned to you
                            </div>
                        </div>
                    </div>

                    <div class="notif-head-actions">
                        <span class="notif-counter-pill unread-pill" id="unreadCountPill">
                            <i class="bi bi-envelope-exclamation-fill"></i>
                            <span id="unreadCountNum">0</span> Unread
                        </span>
                        <span class="notif-counter-pill">
                            <i class="bi bi-collection-fill"></i>
                            <?= (int) $allCount ?> Total
                        </span>
                    </div>
                </div>

                <!-- FILTER TABS -->
                <div class="notif-tabs-strip">
                    <div class="filter-buttons">
                        <a href="notifications.php?filter=All" class="filter-button <?= $filter === 'All' ? 'active' : '' ?>">
                            <i class="bi bi-inbox-fill"></i>
                            All
                            <span class="tab-count"><?= (int) $allCount ?></span>
                        </a>

                        <a href="notifications.php?filter=New" class="filter-button <?= $filter === 'New' ? 'active' : '' ?>">
                            <i class="bi bi-calendar-plus-fill"></i>
                            New
                            <span class="tab-count"><?= (int) $newCount ?></span>
                        </a>

                        <a href="notifications.php?filter=Upcoming" class="filter-button <?= $filter === 'Upcoming' ? 'active' : '' ?>">
                            <i class="bi bi-alarm-fill"></i>
                            Upcoming
                            <span class="tab-count"><?= (int) $upcomingCount ?></span>
                        </a>

                        <a href="notifications.php?filter=Cancelled" class="filter-button <?= $filter === 'Cancelled' ? 'active' : '' ?>">
                            <i class="bi bi-calendar-x-fill"></i>
                            Cancelled
                            <span class="tab-count"><?= (int) $cancelledCount ?></span>
                        </a>

                        <a href="notifications.php?filter=Concerns" class="filter-button <?= $filter === 'Concerns' ? 'active' : '' ?>">
                            <i class="bi bi-chat-dots-fill"></i>
                            Concerns
                            <span class="tab-count"><?= (int) $concernCount ?></span>
                        </a>

                        <a href="notifications.php?filter=History" class="filter-button <?= $filter === 'History' ? 'active' : '' ?>">
                            <i class="bi bi-clock-history"></i>
                            History
                            <span class="tab-count"><?= (int) $historyCount ?></span>
                        </a>
                    </div>

                    <?php if ($filter === 'History'): ?>
                        <div class="history-subtabs">
                            <a href="notifications.php?filter=History&htype=all" class="filter-button <?= $htype === 'all' ? 'active' : '' ?>">
                                All History <span class="tab-count"><?= (int) $historyCount ?></span>
                            </a>
                            <a href="notifications.php?filter=History&htype=completed" class="filter-button <?= $htype === 'completed' ? 'active' : '' ?>">
                                Completed <span class="tab-count"><?= (int) $historyCompletedCount ?></span>
                            </a>
                            <a href="notifications.php?filter=History&htype=noshow" class="filter-button <?= $htype === 'noshow' ? 'active' : '' ?>">
                                No-Show <span class="tab-count"><?= (int) $historyNoshowCount ?></span>
                            </a>
                            <a href="notifications.php?filter=History&htype=past" class="filter-button <?= $htype === 'past' ? 'active' : '' ?>">
                                Past <span class="tab-count"><?= (int) $historyPastCount ?></span>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- NOTIFICATION FEED LIST -->
                <div class="notif-feed-wrap">
                    <?php if (empty($filteredNotifications)): ?>
                        <div class="notification-empty">
                            <div class="empty-icon">
                                <i class="bi bi-bell-slash-fill"></i>
                            </div>
                            <div style="font-size:15px;font-weight:800;color:#143f51;margin-bottom:4px;">
                                No notifications found
                            </div>
                            <div style="font-size:13px;">
                                There are no notifications matching this filter right now.
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="notif-list">
                            <?php foreach ($notifPageItems as $notification): ?>
                                <?php
                                $appointment = is_array($notification['appointment'] ?? null) ? $notification['appointment'] : [];
                                $type = (string) ($notification['type'] ?? 'new');
                                $badgeLabel = (string) ($notification['badge_label'] ?? ucfirst($type));
                                $iconClass = (string) ($notification['icon'] ?? 'bi-bell-fill');

                                $patientName = trim(
                                    ($appointment['first_name'] ?? '')
                                    . ' '
                                    . ($appointment['middle_name'] ?? '')
                                    . ' '
                                    . ($appointment['last_name'] ?? '')
                                );
                                if ($patientName === '') {
                                    $patientName = 'Patient';
                                }

                                $patientNum = trim((string) ($appointment['patient_number'] ?? ''));
                                if ($patientNum === '' && !empty($appointment['patient_id'])) {
                                    $patientNum = '#' . (int) $appointment['patient_id'];
                                }

                                $apptId = (int) ($appointment['id'] ?? 0);
                                $serviceName = trim((string) ($appointment['service_type'] ?? ''));
                                if ($serviceName === '') {
                                    $serviceName = 'Dental Consultation';
                                }

                                $rawApptDate = trim((string) ($appointment['appointment_date'] ?? ''));
                                $formattedApptDate = ($rawApptDate !== '' && strtotime($rawApptDate) !== false)
                                    ? date('F d, Y', strtotime($rawApptDate))
                                    : 'Not specified';

                                $rawStartTime = trim((string) ($appointment['start_time'] ?? ''));
                                $rawEndTime = trim((string) ($appointment['end_time'] ?? ''));
                                if ($rawStartTime !== '' && strtotime($rawStartTime) !== false) {
                                    $formattedTimeRange = date('h:i A', strtotime($rawStartTime));
                                    if ($rawEndTime !== '' && strtotime($rawEndTime) !== false) {
                                        $formattedTimeRange .= ' – ' . date('h:i A', strtotime($rawEndTime));
                                    }
                                } else {
                                    $formattedTimeRange = 'Schedule pending';
                                }

                                $apptStatus = trim((string) ($appointment['status'] ?? 'Pending'));
                                $apptNotes = trim((string) ($appointment['notes'] ?? ''));
                                $contactNum = trim((string) ($appointment['contact_number'] ?? ''));
                                $emailAddr = trim((string) ($appointment['email'] ?? ''));

                                $rawNotifTime = (string) ($notification['date_time'] ?? '');
                                $notifTimeTop = ($rawNotifTime !== '' && strtotime($rawNotifTime) !== false)
                                    ? date('h:i A', strtotime($rawNotifTime))
                                    : '';
                                $notifDateSub = ($rawNotifTime !== '' && strtotime($rawNotifTime) !== false)
                                    ? date('M d, Y', strtotime($rawNotifTime))
                                    : '';

                                $notifKey = md5(
                                    $type
                                    . '|' . $apptId
                                    . '|' . ($notification['title'] ?? '')
                                    . '|' . $rawNotifTime
                                );

                                $isAlreadyRead = ($type === 'history') || (!empty($notification['is_read']));
                                ?>
                                <article
                                    class="notif-card type-<?= dentistNotificationEscape($type) ?> <?= $isAlreadyRead ? 'is-read' : 'is-unread' ?>"
                                    data-notif-key="<?= dentistNotificationEscape($notifKey) ?>"
                                    data-type="<?= dentistNotificationEscape($type) ?>"
                                    data-badge="<?= dentistNotificationEscape($badgeLabel) ?>"
                                    data-icon="<?= dentistNotificationEscape($iconClass) ?>"
                                    data-title="<?= dentistNotificationEscape($notification['title'] ?? 'Notification') ?>"
                                    data-message="<?= dentistNotificationEscape($notification['message'] ?? '') ?>"
                                    data-notif-time="<?= dentistNotificationEscape(trim($notifDateSub . ' · ' . $notifTimeTop, ' ·')) ?>"
                                    data-appt-id="<?= $apptId > 0 ? '#' . $apptId : '—' ?>"
                                    data-patient="<?= dentistNotificationEscape($patientName) ?>"
                                    data-patient-number="<?= dentistNotificationEscape($patientNum ?: '—') ?>"
                                    data-contact="<?= dentistNotificationEscape($contactNum ?: 'Not provided') ?>"
                                    data-email="<?= dentistNotificationEscape($emailAddr ?: 'Not provided') ?>"
                                    data-service="<?= dentistNotificationEscape($serviceName) ?>"
                                    data-dentist="<?= dentistNotificationEscape($dentistName ?: 'Assigned Dentist') ?>"
                                    data-date="<?= dentistNotificationEscape($formattedApptDate) ?>"
                                    data-timerange="<?= dentistNotificationEscape($formattedTimeRange) ?>"
                                    data-status="<?= dentistNotificationEscape($apptStatus) ?>"
                                    data-notes="<?= dentistNotificationEscape($apptNotes ?: 'No additional clinical notes or remarks recorded.') ?>"
                                    tabindex="0"
                                    role="button"
                                    title="Click to view appointment details"
                                >
                                    <span class="notif-dot" aria-hidden="true"></span>

                                    <div class="nt-icon <?= dentistNotificationEscape($type) ?>">
                                        <i class="bi <?= dentistNotificationEscape($iconClass) ?>"></i>
                                    </div>

                                    <div class="notif-body">
                                        <div class="notif-top">
                                            <h3 class="nt-title">
                                                <?= dentistNotificationEscape($notification['title'] ?? 'Notification') ?>
                                            </h3>
                                            <span class="notif-type-tag <?= dentistNotificationEscape($type) ?>">
                                                <?= dentistNotificationEscape($badgeLabel) ?>
                                            </span>
                                            <span class="new-badge">NEW</span>
                                        </div>

                                        <div class="nt-msg">
                                            <?= dentistNotificationEscape($notification['message'] ?? '') ?>
                                        </div>

                                        <div class="notif-meta">
                                            <span class="notif-meta-item">
                                                <i class="bi bi-person-fill"></i>
                                                <?= dentistNotificationEscape($patientName) ?>
                                                <?php if ($patientNum !== ''): ?>
                                                    <span style="color:#8aa0a8;">(<?= dentistNotificationEscape($patientNum) ?>)</span>
                                                <?php endif; ?>
                                            </span>
                                            <span class="notif-meta-item">
                                                <i class="bi bi-briefcase-fill"></i>
                                                <?= dentistNotificationEscape($serviceName) ?>
                                            </span>
                                            <span class="notif-meta-item">
                                                <i class="bi bi-calendar3"></i>
                                                <?= dentistNotificationEscape($formattedApptDate) ?> · <?= dentistNotificationEscape($formattedTimeRange) ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="notif-right">
                                        <div class="nt-time">
                                            <?= dentistNotificationEscape($notifTimeTop) ?>
                                            <small><?= dentistNotificationEscape($notifDateSub) ?></small>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <?php
                        $showStart = ($notifPage - 1) * $notifPerPage + 1;
                        $showEnd = min($notifTotal, $notifPage * $notifPerPage);
                        ?>
                        <nav class="notif-pagination" aria-label="Notification pages">
                            <div class="notif-page-info">
                                Showing <strong><?= (int) $showStart ?>–<?= (int) $showEnd ?></strong> of <strong><?= (int) $notifTotal ?></strong> notifications
                            </div>
                            <div class="notif-page-links">
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
                            </div>
                        </nav>
                    <?php endif; ?>
                </div>

            </section>

        </div>


        <!-- ==================================================
             APPOINTMENT DETAILS POPUP MODAL (NO REDIRECT)
        =================================================== -->
        <div class="nt-modal-overlay" id="ntModalOverlay">
            <div class="nt-modal-box" role="dialog" aria-modal="true" aria-labelledby="ntModalTitle">
                <div class="nt-modal-head">
                    <div class="nt-icon new" id="ntModalIcon">
                        <i class="bi bi-calendar2-check-fill" id="ntModalIconGlyph"></i>
                    </div>
                    <div class="nt-modal-head-text">
                        <h3 id="ntModalTitle">Appointment Details</h3>
                        <div class="nt-modal-head-sub" id="ntModalSub">Notification & Schedule Summary</div>
                    </div>
                    <span class="nt-modal-status-pill" id="ntModalStatus">Confirmed</span>
                    <button type="button" class="nt-modal-close" id="ntModalClose" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="nt-modal-body">
                    <div class="nt-modal-banner">
                        <div class="nt-modal-banner-title" id="ntModalNotifTitle">Notification</div>
                        <div class="nt-modal-msg" id="ntModalMsg"></div>
                    </div>

                    <div class="nt-modal-details-grid">
                        <div class="nt-detail-box">
                            <div class="nt-detail-label"><i class="bi bi-person-Vcard-fill bi-person-badge-fill"></i>Patient Information</div>
                            <div class="nt-detail-value" id="ntModalPatient">—</div>
                            <div class="nt-detail-sub">Patient ID: <span id="ntModalPatientNumber">—</span></div>
                        </div>

                        <div class="nt-detail-box">
                            <div class="nt-detail-label"><i class="bi bi-telephone-fill"></i>Contact Details</div>
                            <div class="nt-detail-value" id="ntModalContact">—</div>
                            <div class="nt-detail-sub" id="ntModalEmail">—</div>
                        </div>

                        <div class="nt-detail-box">
                            <div class="nt-detail-label"><i class="bi bi-heart-pulse-fill"></i>Dental Service</div>
                            <div class="nt-detail-value" id="ntModalService">—</div>
                            <div class="nt-detail-sub">Dentist: <span id="ntModalDentist">—</span></div>
                        </div>

                        <div class="nt-detail-box">
                            <div class="nt-detail-label"><i class="bi bi-calendar-event-fill"></i>Appointment Schedule</div>
                            <div class="nt-detail-value" id="ntModalDate">—</div>
                            <div class="nt-detail-sub" id="ntModalTime">—</div>
                        </div>
                    </div>

                    <div class="nt-notes-box">
                        <div class="nt-detail-label"><i class="bi bi-journal-text"></i>Notes / Remarks</div>
                        <div class="nt-notes-text" id="ntModalNotes">—</div>
                    </div>
                </div>

                <div class="nt-modal-foot">
                    <button type="button" class="nt-modal-btn" id="ntModalCancel">
                        <i class="bi bi-check2-circle"></i>
                        Close
                    </button>
                </div>
            </div>
        </div>


    </main>

<script>
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
        if(list.indexOf(key) === -1){
            list.push(key);
            setRead(list);
        }
    }

    var rows = Array.prototype.slice.call(document.querySelectorAll('.notif-list .notif-card'));
    var overlay = document.getElementById('ntModalOverlay');
    var unreadCountNum = document.getElementById('unreadCountNum');
    var unreadCountPill = document.getElementById('unreadCountPill');

    function updateUnreadCounter(){
        var unread = document.querySelectorAll('.notif-list .notif-card.is-unread').length;
        if(unreadCountNum){
            unreadCountNum.textContent = String(unread);
        }
        if(unreadCountPill){
            unreadCountPill.style.opacity = unread > 0 ? '1' : '0.65';
        }
    }

    function txt(id, val){
        var el = document.getElementById(id);
        if(el) el.textContent = val || '—';
    }

    function openAppointmentModal(row){
        if(!overlay || !row) return;
        var notifTitle = row.getAttribute('data-title') || 'Notification';
        var notifTime = row.getAttribute('data-notif-time') || '';
        var iconCls = row.getAttribute('data-icon') || 'bi-bell-fill';

        txt('ntModalTitle', 'Appointment Details');
        txt('ntModalSub', notifTime ? ('Notification received · ' + notifTime) : 'Appointment & Patient Summary');
        txt('ntModalNotifTitle', notifTitle);
        txt('ntModalMsg', row.getAttribute('data-message'));
        txt('ntModalPatient', row.getAttribute('data-patient'));
        txt('ntModalPatientNumber', row.getAttribute('data-patient-number'));
        txt('ntModalContact', row.getAttribute('data-contact'));
        txt('ntModalEmail', row.getAttribute('data-email'));
        txt('ntModalService', row.getAttribute('data-service'));
        txt('ntModalDentist', row.getAttribute('data-dentist'));
        txt('ntModalDate', row.getAttribute('data-date'));
        txt('ntModalTime', row.getAttribute('data-timerange'));
        txt('ntModalStatus', row.getAttribute('data-status') || 'Pending');
        txt('ntModalNotes', row.getAttribute('data-notes'));

        var iconGlyph = document.getElementById('ntModalIconGlyph');
        if(iconGlyph){
            iconGlyph.className = 'bi ' + iconCls;
        }
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(){
        if(!overlay) return;
        overlay.classList.remove('open');
        document.body.style.overflow = '';
    }

    if(overlay){
        overlay.addEventListener('click', function(e){
            if(e.target === overlay) closeModal();
        });
        document.addEventListener('keydown', function(e){
            if(e.key === 'Escape' && overlay.classList.contains('open')) closeModal();
        });
    }

    ['ntModalClose', 'ntModalCancel'].forEach(function(id){
        var b = document.getElementById(id);
        if(b) b.addEventListener('click', closeModal);
    });

    /* Initialize read state from localStorage */
    var readList = getRead();
    rows.forEach(function(row){
        var key = row.getAttribute('data-notif-key');
        if(key && readList.indexOf(key) !== -1){
            row.classList.remove('is-unread');
            row.classList.add('is-read');
        }
    });
    updateUnreadCounter();

    /* Click on notification card or View Appointment button opens Popup Modal (NO REDIRECT) */
    rows.forEach(function(row){
        function handleActivate(e){
            if(e) e.stopPropagation();
            var key = row.getAttribute('data-notif-key');
            rows.forEach(function(r){ r.classList.remove('selected'); });
            row.classList.add('selected');
            try { sessionStorage.setItem(SEL_KEY, key || ''); } catch(err){}

            if(key){
                row.classList.remove('is-unread');
                row.classList.add('is-read');
                markRead(key);
                updateUnreadCounter();
            }
            openAppointmentModal(row);
        }

        row.addEventListener('click', handleActivate);
        row.addEventListener('keydown', function(ev){
            if(ev.key === 'Enter' || ev.key === ' '){
                ev.preventDefault();
                handleActivate(ev);
            }
        });
    });

    try {
        var saved = sessionStorage.getItem(SEL_KEY);
        if(saved){
            var el = document.querySelector('.notif-list .notif-card[data-notif-key="' + saved + '"]');
            if(el){
                rows.forEach(function(r){ r.classList.remove('selected'); });
                el.classList.add('selected');
            }
        }
    } catch(err){}
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
