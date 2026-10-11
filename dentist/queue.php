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
| FIND DENTIST ID
|--------------------------------------------------------------------------
|
| appointments.dentist_id uses the ID from the dentists table.
| We first try the logged-in user ID, then dentist name.
|
*/

$loggedInUserId = (int) $_SESSION['user_id'];
$dentistName = trim($_SESSION['full_name'] ?? '');

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

$dentistId = (int) ($dentistLookup->fetchColumn() ?: 0);
}


/*
|--------------------------------------------------------------------------
| TODAY
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Asia/Manila');

$today = date('Y-m-d');


/*
|--------------------------------------------------------------------------
| AUTO-GENERATE TODAY'S QUEUE FROM APPROVED/CONFIRMED APPOINTMENTS
|--------------------------------------------------------------------------
| - No fake records: only today's appointments with status Approved/Confirmed
|   for this dentist_id create a queue row (once per patient per day).
| - Queue number follows appointment start_time order.
*/

if ($dentistId > 0) {

    try {

        $apptStmt = $pdo->prepare("
            SELECT patient_id, start_time
            FROM appointments
            WHERE dentist_id = ?
              AND appointment_date = ?
              AND status IN ('Approved', 'Confirmed', 'Checked In', 'Late')
            ORDER BY start_time ASC, id ASC
        ");

        $apptStmt->execute([$dentistId, $today]);

        $todayAppts = $apptStmt->fetchAll(PDO::FETCH_ASSOC);

        $seenPatients = [];

        foreach ($todayAppts as $aRow) {

            $pid = (int) ($aRow['patient_id'] ?? 0);

            if ($pid <= 0 || isset($seenPatients[$pid])) {
                continue;
            }

            $seenPatients[$pid] = true;

            $existsStmt = $pdo->prepare("
                SELECT id
                FROM queue
                WHERE patient_id = ?
                  AND queue_date = ?
                LIMIT 1
            ");

            $existsStmt->execute([$pid, $today]);

            if ($existsStmt->fetchColumn()) {
                continue;
            }

            $maxStmt = $pdo->prepare("
                SELECT COALESCE(MAX(queue_number), 0)
                FROM queue
                WHERE queue_date = ?
            ");

            $maxStmt->execute([$today]);

            $nextNumber = (int) $maxStmt->fetchColumn() + 1;

            $insertStmt = $pdo->prepare("
                INSERT INTO queue (patient_id, queue_number, queue_date, priority, status)
                VALUES (?, ?, ?, 'Normal', 'Waiting')
            ");

            $insertStmt->execute([$pid, $nextNumber, $today]);
        }

    } catch (PDOException $e) {

        /*
        | Auto-generation must never break the page.
        */

    }
}


/*
|--------------------------------------------------------------------------
| HELPER FUNCTION
|--------------------------------------------------------------------------
*/

function dentistQueueEscape($value)
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| POST ACTION
|--------------------------------------------------------------------------
|
| Allows:
| Waiting
| Serving
| Completed
|
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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $queueId = (int) ($_POST['queue_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');

    $allowedStatuses = [
        'Waiting',
        'Serving',
        'Completed'
    ];


    if (
        $queueId > 0 &&
        in_array($newStatus, $allowedStatuses, true)
    ) {

        /*
        |--------------------------------------------------------------------------
        | Update only the dentist's own queue
        |--------------------------------------------------------------------------
        */

        $updateStmt = $pdo->prepare("
            UPDATE queue q

            INNER JOIN appointments a
                ON a.patient_id = q.patient_id
                AND a.dentist_id = ?
                AND a.appointment_date = q.queue_date
                AND a.status IN ('Approved', 'Confirmed', 'Checked In', 'Late', 'Serving')

            SET q.status = ?

            WHERE q.id = ?
              AND q.queue_date = ?
        ");

        $updateStmt->execute([
            $dentistId,
            $newStatus,
            $queueId,
            $today
        ]);

        if (in_array($newStatus, ['Serving', 'Completed'], true)) {
            try {
                $qPatientStmt = $pdo->prepare("SELECT patient_id, queue_date FROM queue WHERE id = ? LIMIT 1");
                $qPatientStmt->execute([$queueId]);
                $qPatientRow = $qPatientStmt->fetch(PDO::FETCH_ASSOC);
                if ($qPatientRow) {
                    $stampCol = $newStatus === 'Serving' ? 'serving_at' : 'completed_at';
                    $syncApptStmt = $pdo->prepare("
                        UPDATE appointments
                        SET status = ?, {$stampCol} = NOW()
                        WHERE patient_id = ?
                          AND dentist_id = ?
                          AND appointment_date = ?
                          AND status IN ('Approved', 'Confirmed', 'Checked In', 'Late', 'Serving')
                    ");
                    $syncApptStmt->execute([
                        $newStatus,
                        (int)$qPatientRow['patient_id'],
                        $dentistId,
                        $qPatientRow['queue_date']
                    ]);
                }
            } catch (PDOException $e) {
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Redirect after POST (preserve filter + nosidebar so summary + list
    | stay in sync at hindi lilitaw ang sidebar)
    |--------------------------------------------------------------------------
    */

    $redirectFilter = trim($_POST['filter'] ?? ($_GET['filter'] ?? 'All'));

    if (!in_array($redirectFilter, ['All', 'Waiting', 'Serving', 'Completed'], true)) {
        $redirectFilter = 'All';
    }

    $redirectNoSidebar = (($_GET['nosidebar'] ?? '') === '1') ? '&nosidebar=1' : '';

    header('Location: queue.php?filter=' . urlencode($redirectFilter) . $redirectNoSidebar);
    exit;
}


/*
|--------------------------------------------------------------------------
| FILTER
|--------------------------------------------------------------------------
*/

$filter = trim($_GET['filter'] ?? 'All');

$allowedFilters = [
    'All',
    'Waiting',
    'Serving',
    'Completed'
];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'All';
}

/*
|--------------------------------------------------------------------------
| NOSIDEBAR SUFFIX (para hindi lumitaw ang sidebar pag pinindot ang
| Waiting / Serving / Completed / All buttons, view at close links)
|--------------------------------------------------------------------------
*/

$noSidebarSuffix = (($_GET['nosidebar'] ?? '') === '1') ? '&nosidebar=1' : '';


/*
|--------------------------------------------------------------------------
| VIEW PATIENT
|--------------------------------------------------------------------------
*/

$viewQueueId = (int) ($_GET['view'] ?? 0);

$selectedQueue = null;
$selectedPatient = null;
$selectedAppointment = null;


/*
|--------------------------------------------------------------------------
| GET SELECTED PATIENT DETAILS
|--------------------------------------------------------------------------
*/

if ($viewQueueId > 0) {

    /*
    |--------------------------------------------------------------------------
    | Queue + Patient
    |--------------------------------------------------------------------------
    */

    $patientStmt = $pdo->prepare("
        SELECT
            q.id AS queue_id,
            q.queue_number,
            q.queue_date,
            q.priority,
            q.status AS queue_status,

            p.id AS patient_id,
            p.patient_number,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.gender,
            p.birth_date,
            p.contact_number,
            p.email,
            p.address

        FROM queue q

        INNER JOIN patients p
            ON p.id = q.patient_id

        WHERE q.id = ?
          AND q.queue_date = ?

          AND EXISTS (
              SELECT 1
              FROM appointments a
              WHERE a.patient_id = q.patient_id
                AND a.dentist_id = ?
                AND a.appointment_date = q.queue_date
                AND a.status IN ('Approved', 'Confirmed', 'Checked In', 'Late', 'Serving', 'Completed')
          )

        LIMIT 1
    ");

    $patientStmt->execute([
        $viewQueueId,
        $today,
        $dentistId
    ]);

    $details = $patientStmt->fetch(PDO::FETCH_ASSOC);


    if ($details) {

        $selectedQueue = [
            'id' => $details['queue_id'],
            'queue_number' => $details['queue_number'],
            'queue_date' => $details['queue_date'],
            'priority' => $details['priority'],
            'status' => $details['queue_status']
        ];


        $selectedPatient = [
            'id' => $details['patient_id'],
            'patient_number' => $details['patient_number'],
            'first_name' => $details['first_name'],
            'middle_name' => $details['middle_name'],
            'last_name' => $details['last_name'],
            'gender' => $details['gender'],
            'birth_date' => $details['birth_date'],
            'contact_number' => $details['contact_number'],
            'email' => $details['email'],
            'address' => $details['address']
        ];


        /*
        |--------------------------------------------------------------------------
        | Appointment Information
        |--------------------------------------------------------------------------
        */

        $appointmentStmt = $pdo->prepare("
            SELECT
                id,
                appointment_date,
                start_time,
                end_time,
                service_type,
                status,
                notes

            FROM appointments

            WHERE patient_id = ?
              AND dentist_id = ?
              AND appointment_date = ?

            ORDER BY start_time ASC

            LIMIT 1
        ");

        $appointmentStmt->execute([
            $details['patient_id'],
            $dentistId,
            $today
        ]);

        $selectedAppointment = $appointmentStmt->fetch(PDO::FETCH_ASSOC);
    }
}


/*
|--------------------------------------------------------------------------
| QUEUE SUMMARY
|--------------------------------------------------------------------------
*/

$summaryStmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN q.status = 'Waiting' THEN 1 ELSE 0 END) AS waiting_count,
        SUM(CASE WHEN q.status = 'Serving' THEN 1 ELSE 0 END) AS serving_count,
        SUM(CASE WHEN q.status = 'Completed' THEN 1 ELSE 0 END) AS completed_count,
        COUNT(*) AS total_count

    FROM queue q

    WHERE q.queue_date = ?

      AND EXISTS (
          SELECT 1
          FROM appointments a
          WHERE a.patient_id = q.patient_id
            AND a.dentist_id = ?
            AND a.appointment_date = q.queue_date
            AND a.status IN ('Approved', 'Confirmed', 'Checked In', 'Late', 'Serving', 'Completed')
      )
");

$summaryStmt->execute([
    $today,
    $dentistId
]);

$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$waitingCount = (int) ($summary['waiting_count'] ?? 0);
$servingCount = (int) ($summary['serving_count'] ?? 0);
$completedCount = (int) ($summary['completed_count'] ?? 0);
$totalCount = (int) ($summary['total_count'] ?? ($waitingCount + $servingCount + $completedCount));


/*
|--------------------------------------------------------------------------
| QUEUE LIST
|--------------------------------------------------------------------------
*/

$queueSql = "
    SELECT
        q.id,
        q.queue_number,
        q.patient_id,
        q.queue_date,
        q.priority,
        q.status,
        p.patient_number,

        CONCAT(
            p.first_name,
            ' ',
            CASE
                WHEN p.middle_name IS NOT NULL
                     AND p.middle_name <> ''
                THEN CONCAT(p.middle_name, ' ')
                ELSE ''
            END,
            p.last_name
        ) AS patient_name,

        (
            SELECT a1.start_time
            FROM appointments a1
            WHERE a1.patient_id = q.patient_id
              AND a1.dentist_id = ?
              AND a1.appointment_date = q.queue_date
            ORDER BY a1.start_time ASC
            LIMIT 1
        ) AS appointment_start_time,

        (
            SELECT a2.end_time
            FROM appointments a2
            WHERE a2.patient_id = q.patient_id
              AND a2.dentist_id = ?
              AND a2.appointment_date = q.queue_date
            ORDER BY a2.start_time ASC
            LIMIT 1
        ) AS appointment_end_time,

        (
            SELECT a3.service_type
            FROM appointments a3
            WHERE a3.patient_id = q.patient_id
              AND a3.dentist_id = ?
              AND a3.appointment_date = q.queue_date
            ORDER BY a3.start_time ASC
            LIMIT 1
        ) AS service_type,

        (
            SELECT a4.created_at
            FROM appointments a4
            WHERE a4.patient_id = q.patient_id
              AND a4.dentist_id = ?
              AND a4.appointment_date = q.queue_date
            ORDER BY a4.start_time ASC
            LIMIT 1
        ) AS booked_at

    FROM queue q

    INNER JOIN patients p
        ON p.id = q.patient_id

    WHERE q.queue_date = ?

      AND EXISTS (
          SELECT 1
          FROM appointments ax
          WHERE ax.patient_id = q.patient_id
            AND ax.dentist_id = ?
            AND ax.appointment_date = q.queue_date
            AND ax.status IN ('Approved', 'Confirmed', 'Checked In', 'Late', 'Serving', 'Completed')
      )
";


/*
|--------------------------------------------------------------------------
| QUERY PARAMETERS
|--------------------------------------------------------------------------
*/

$queueParams = [
    $dentistId,
    $dentistId,
    $dentistId,
    $dentistId,
    $today,
    $dentistId
];


/*
|--------------------------------------------------------------------------
| FILTER BY STATUS
|--------------------------------------------------------------------------
*/

if ($filter !== 'All') {

    $queueSql .= "
        AND q.status = ?
    ";

    $queueParams[] = $filter;
}


/*
|--------------------------------------------------------------------------
| ORDER QUEUE
|--------------------------------------------------------------------------
*/

$queueSql .= "
    ORDER BY
        CASE q.status
            WHEN 'Serving' THEN 1
            WHEN 'Waiting' THEN 2
            WHEN 'Completed' THEN 3
            ELSE 4
        END,
        q.queue_number ASC
";


/*
|--------------------------------------------------------------------------
| EXECUTE QUEUE QUERY
|--------------------------------------------------------------------------
*/

$queueStmt = $pdo->prepare($queueSql);
$queueStmt->execute($queueParams);

$queues = $queueStmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Queue | Dentist Portal</title>


    <!-- Bootstrap Icons -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- Google Fonts -->

    <link
        rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
    >


    <!-- Existing Portal Style -->

    <link
        rel="stylesheet"
        href="portal.css?v=5"
    >

    <!-- DENTIST SIDEBAR (dashboard parity) -->
    <link rel="stylesheet" href="dentist_sidebar.css">


    <style>

        /*
        |--------------------------------------------------------------------------
        | QUEUE PAGE
        |--------------------------------------------------------------------------
        */

        .queue-summary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }


        .queue-summary-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg,#047857 0%,#059669 55%,#10B981 100%);
            border: 1px solid rgba(255,255,255,.28);
            border-radius: 14px;
            padding: 12px 14px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 3px;
            box-shadow: 0 16px 34px rgba(5,150,105,.35);
            text-decoration: none;
            color: #ffffff;
            cursor: pointer;
            transition: .25s ease;
        }
        .queue-summary-card::before,
        .queue-summary-card::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }
        .queue-summary-card::before {
            width: 130px;
            height: 130px;
            top: -60px;
            right: -45px;
            background: radial-gradient(circle,rgba(255,255,255,.22),rgba(255,255,255,0) 70%);
        }
        .queue-summary-card::after {
            width: 100px;
            height: 100px;
            bottom: -48px;
            left: -32px;
            background: radial-gradient(circle,rgba(255,255,255,.16),rgba(255,255,255,0) 70%);
        }
        .queue-summary-card > * {
            position: relative;
            z-index: 1;
        }
        a.queue-summary-card:hover{transform:translateY(-4px);box-shadow:0 22px 42px rgba(5,150,105,.45);border-color:rgba(255,255,255,.62)}
        a.queue-summary-card.active{border-color:#ffffff;box-shadow:0 22px 42px rgba(5,150,105,.46);transform:translateY(-3px)}


        .queue-summary-head {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
        }


        .queue-summary-icon {
            width: 34px;
            height: 34px;
            border-radius: 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
            background: rgba(255,255,255,.18);
            color: #ffffff;
            border: 1px solid rgba(255,255,255,.32);
            box-shadow: 0 8px 18px rgba(0,0,0,.14);
        }


        .queue-summary-label {
            color: rgba(255,255,255,.92);
            font-size: 13px;
            font-weight: 700;
            flex: 1;
            min-width: 0;
        }


        .queue-summary-number {
            font-size: 27px;
            font-weight: 800;
            color: #ffffff;
            font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif;
            line-height: 1;
            margin-top: auto;
            padding-top: 4px;
            font-variant-numeric: tabular-nums;
            text-shadow: 0 6px 18px rgba(0,0,0,.22);
        }

        .queue-summary-sub {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 600;
            color: rgba(255,255,255,.86);
            margin-top: 3px;
        }
        .queue-summary-sub::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #A7F3D0;
            box-shadow: 0 0 0 4px rgba(167,243,208,.25);
            animation: queueDotPulse 1.9s ease-in-out infinite;
            flex-shrink: 0;
        }
        @keyframes queueDotPulse {
            0%,100% { opacity: 1; transform: scale(1); }
            50% { opacity: .5; transform: scale(.8); }
        }

        @media (max-width: 1100px) {
            .queue-summary { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 600px) {
            .queue-summary { grid-template-columns: 1fr; }
        }


        /*
        |--------------------------------------------------------------------------
        | FILTER
        |--------------------------------------------------------------------------
        */

        .queue-filter {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }


        .queue-filter-title {
            font-size: 18px;
            font-weight: 700;
            color: #253746;
        }


        .filter-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }


        .filter-button {
            text-decoration: none;
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid #dce5ed;
            background: #ffffff;
            color: #596978;
            font-size: 13px;
            font-weight: 600;
        }


        .filter-button:hover {
            background: #f4f8fb;
        }


        .filter-button.active {
            background: #3b82c4;
            border-color: #3b82c4;
            color: #ffffff;
        }


        /*
        |--------------------------------------------------------------------------
        | PATIENT NAME
        |--------------------------------------------------------------------------
        */

        .queue-patient-name {
            font-weight: 700;
            color: #253746;
        }


        .queue-patient-number {
            display: block;
            margin-top: 3px;
            font-size: 11px;
            color: #8795a3;
        }


        /*
        |--------------------------------------------------------------------------
        | TIME
        |--------------------------------------------------------------------------
        */

        .queue-time {
            font-weight: 600;
            color: #34495e;
        }


        .queue-service {
            color: #566575;
        }


        /*
        |--------------------------------------------------------------------------
        | STATUS
        |--------------------------------------------------------------------------
        */

        .queue-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }


        .queue-status.waiting {
            background: #fff4d6;
            color: #9a6700;
        }


        .queue-status.serving {
            background: #e1f0ff;
            color: #1769aa;
        }


        .queue-status.completed {
            background: #e1f5e8;
            color: #18753c;
        }


        /*
        |--------------------------------------------------------------------------
        | ACTIONS
        |--------------------------------------------------------------------------
        */

        .queue-actions {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-wrap: wrap;
        }


        .queue-action {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border: 0;
            border-radius: 7px;
            padding: 7px 11px;
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
        }


        .queue-action.view {
            background: #edf6ff;
            color: #2675b9;
        }


        .queue-action.start {
            background: #eaf5ff;
            color: #1769aa;
        }


        .queue-action.complete {
            background: #e8f7ed;
            color: #18753c;
        }


        .queue-action:hover {
            opacity: 0.85;
        }


        /*
        |--------------------------------------------------------------------------
        | PATIENT DETAILS
        |--------------------------------------------------------------------------
        */

        .patient-detail-card {
            margin-top: 20px;
        }


        .patient-detail-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }


        .patient-title {
            display: flex;
            align-items: center;
            gap: 13px;
        }


        .patient-avatar {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: #eaf5ff;
            color: #2675b9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 23px;
        }


        .patient-title h2 {
            margin: 0 0 3px;
            font-size: 20px;
        }


        .patient-title small {
            color: #7c8b99;
        }


        .close-detail {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 7px;
            border: 1px solid #dce5ed;
            color: #596978;
            font-size: 13px;
            font-weight: 600;
        }


        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }


        .detail-section {
            background: #f8fbfd;
            border-radius: 10px;
            padding: 17px;
        }


        .detail-section h3 {
            margin: 0 0 15px;
            font-size: 16px;
            color: #2c4050;
        }


        .detail-list {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }


        .detail-item {
            background: #ffffff;
            border-radius: 7px;
            padding: 10px;
        }


        .detail-label {
            color: #8996a3;
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 4px;
        }


        .detail-value {
            color: #293d4d;
            font-size: 13px;
            font-weight: 600;
            word-break: break-word;
        }


        /*
        |--------------------------------------------------------------------------
        | EMPTY STATE
        |--------------------------------------------------------------------------
        */

        .queue-empty {
            text-align: center;
            padding: 45px 20px;
            color: #7b8997;
        }


        .queue-empty i {
            font-size: 38px;
            display: block;
            margin-bottom: 10px;
        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 900px) {

            .queue-summary {
                grid-template-columns: repeat(2, 1fr);
            }


            .detail-grid {
                grid-template-columns: 1fr;
            }

        }


        @media (max-width: 650px) {

            .detail-list {
                grid-template-columns: 1fr;
            }

        }


        /*
        |--------------------------------------------------------------------------
        | DENTIST APPOINTMENTS LOOK — overrides (portal.css hindi ginalaw)
        |--------------------------------------------------------------------------
        */

        .queue-summary-card {
            background: linear-gradient(135deg,#047857 0%,#059669 55%,#10B981 100%) !important;
            border: 1px solid rgba(255,255,255,.28) !important;
            border-radius: 14px !important;
            box-shadow: 0 16px 34px rgba(5,150,105,.35) !important;
            padding: 12px 14px !important;
        }


        .queue-summary-icon {
            background: rgba(255,255,255,.18) !important;
            color: #ffffff !important;
            border: 1px solid rgba(255,255,255,.32);
            border-radius: 11px !important;
        }


        .queue-summary-label {
            color: rgba(255,255,255,.92) !important;
            font-family: "Inter", system-ui, sans-serif !important;
            font-weight: 700 !important;
        }


        .queue-summary-number {
            color: #ffffff !important;
            font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif !important;
            letter-spacing: -0.02em;
            text-shadow: 0 6px 18px rgba(0,0,0,.22);
        }

        .queue-summary-sub {
            color: rgba(255,255,255,.86) !important;
        }


        .queue-filter-title {
            color: #143f51 !important;
            font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif !important;
            font-size: 17px !important;
            font-weight: 800 !important;
            letter-spacing: -0.02em !important;
            display: flex;
            align-items: center;
            gap: 8px;
        }


        .queue-filter-title::before {
            content: "\F26A";
            font-family: "bootstrap-icons";
            color: #0E6B5E;
            font-size: 18px;
            font-weight: 400;
        }


        .filter-button {
            border: 1px solid #D1E7DD !important;
            border-radius: 9px !important;
            color: #57727d !important;
            font-family: "Inter", system-ui, sans-serif !important;
        }


        .filter-button:hover {
            background: #EAF7F0 !important;
            color: #0E6B5E !important;
        }


        .filter-button.active {
            background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important;
            border-color: #0E6B5E !important;
            color: #ffffff !important;
        }


        .portal-card {
            background: #ffffff !important;
            border: 1px solid #C9E9DA !important;
            border-radius: 16px !important;
            box-shadow: 0 8px 24px rgba(14,107,94,.08) !important;
            padding: 20px !important;
        }


        .portal-table {
            border-collapse: separate !important;
            border-spacing: 0 !important;
            background: #ffffff;
        }


        .portal-table thead tr {
            background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important;
        }


        .portal-table th {
            background: transparent !important;
            color: #ffffff !important;
            font-size: 11.5px !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: .6px !important;
            white-space: nowrap;
            padding: 14px 16px !important;
            border-bottom: none !important;
            border-right: 1px solid rgba(255,255,255,.12) !important;
            font-family: "Inter", system-ui, sans-serif !important;
        }


        .portal-table th:last-child {
            border-right: none !important;
        }


        .portal-table td {
            color: #4d6873 !important;
            font-size: 13.5px !important;
            font-family: "Inter", "DM Sans", system-ui, sans-serif !important;
            padding: 14px 16px !important;
            background: #ffffff;
            border-bottom: 1px solid #E6F2EC !important;
            vertical-align: middle;
        }


        .portal-table tbody tr:nth-child(even) td {
            background: #F6FBF8 !important;
        }


        .portal-table tbody tr:hover td {
            background: #EAF7F0 !important;
        }


        .queue-patient-name {
            color: #143f51 !important;
        }


        .queue-patient-number {
            color: #8aa0a8 !important;
        }


        .queue-time {
            color: #143f51 !important;
        }


        .queue-service {
            color: #4d6873 !important;
        }


        .queue-status {
            font-size: 11.5px !important;
            font-family: "Inter", system-ui, sans-serif !important;
            padding: 6px 11px !important;
            border: 1px solid transparent;
        }


        .queue-status.waiting {
            background: #fff3d9 !important;
            color: #b45309 !important;
            border-color: #f5d48a !important;
        }


        .queue-status.serving {
            background: #e6f4fb !important;
            color: #157ca0 !important;
            border-color: #bfe3f2 !important;
        }


        .queue-status.completed {
            background: #e7f8ef !important;
            color: #15945a !important;
            border-color: #b9e8cd !important;
        }


        .queue-action {
            border-radius: 9px !important;
            font-family: "Inter", system-ui, sans-serif !important;
        }


        .queue-action.view {
            background: #EAF7F0 !important;
            color: #0E6B5E !important;
        }


        .queue-action:hover {
            opacity: 1 !important;
            filter: brightness(.95);
        }


        .detail-section {
            border: 1px solid #E6F2EC;
        }


        .detail-section h3 {
            color: #143f51 !important;
            font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif !important;
            font-weight: 800 !important;
        }


        .detail-value {
            color: #143f51 !important;
        }


        .queue-empty {
            color: #8aa0a8 !important;
            font-family: "Inter", "DM Sans", system-ui, sans-serif !important;
        }


        .queue-empty i {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: #EAF7F0 !important;
            color: #0E6B5E !important;
            border: 1px solid #C9E9DA;
            font-size: 24px !important;
        }


        .queue-empty strong {
            color: #426471 !important;
        }

    </style>


</head>


<body>

<style>
body{background:linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22)!important;background-size:300% 300%!important;animation:dentistDashBg 8s ease infinite!important;}
.portal-main{background:transparent!important;}
@keyframes dentistDashBg{0%{background-position:0% 50%;}50%{background-position:100% 50%;}100%{background-position:0% 50%;}}
</style>


    <!-- =========================================================
         SIDEBAR
    ========================================================== -->

    <?php
$dentistActivePage = 'queue';
include __DIR__ . '/dentist_sidebar.php';
?>



    <!-- =========================================================
         MAIN CONTENT
    ========================================================== -->

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


        <!-- TOPBAR (katulad ng appointment page: date + time, walang dentist name) -->

        <header class="portal-topbar">

            <div>

                <div class="portal-heading">
                    Today's Queue
                </div>

                <div class="portal-subtitle">
                    Live queue of patients waiting to be served
                </div>

            </div>


            <div style="display:flex;align-items:center;gap:16px;">

                <div class="topbar-today">

                    <i class="bi bi-calendar-event-fill"></i>

                    <div>
                        <strong><?= dentistQueueEscape(date('l')) ?></strong>
                        <small><?= dentistQueueEscape(date('M d, Y')) ?></small>
                    </div>

                </div>


                <div class="topbar-time">

                    <i class="bi bi-clock-fill"></i>

                    <div>
                        <strong>Clinic Time</strong>
                        <small id="queueClinicTime">--:--:--</small>
                    </div>

                </div>

            </div>

        </header>

        <script>
        (function () {
            function tickQueueClinicTime() {
                try {
                    var el = document.getElementById('queueClinicTime');
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
            tickQueueClinicTime();
            setInterval(tickQueueClinicTime, 1000);
        })();
        </script>



        <!-- CONTENT -->

        <div class="portal-content">

            <!-- =================================================
                 QUEUE SUMMARY
            ================================================== -->

            <div class="queue-summary">


                <!-- WAITING -->

                <a class="queue-summary-card<?= $filter === 'Waiting' ? ' active' : '' ?>" href="queue.php?filter=Waiting<?= $noSidebarSuffix ?>" title="Show waiting patients only">

                    <div class="queue-summary-head">

                        <div class="queue-summary-icon">

                            <i class="bi bi-hourglass-split"></i>

                        </div>

                        <div class="queue-summary-label">
                            Waiting
                        </div>

                    </div>

                    <div class="queue-summary-number">
                        <?= $waitingCount ?>
                    </div>

                    <div class="queue-summary-sub">
                        Patients waiting
                    </div>

                </a>



                <!-- SERVING -->

                <a class="queue-summary-card<?= $filter === 'Serving' ? ' active' : '' ?>" href="queue.php?filter=Serving<?= $noSidebarSuffix ?>" title="Show serving patients only">

                    <div class="queue-summary-head">

                        <div class="queue-summary-icon">

                            <i class="bi bi-person-workspace"></i>

                        </div>

                        <div class="queue-summary-label">
                            Serving
                        </div>

                    </div>

                    <div class="queue-summary-number">
                        <?= $servingCount ?>
                    </div>

                    <div class="queue-summary-sub">
                        Currently in treatment
                    </div>

                </a>



                <!-- COMPLETED -->

                <a class="queue-summary-card<?= $filter === 'Completed' ? ' active' : '' ?>" href="queue.php?filter=Completed<?= $noSidebarSuffix ?>" title="Show completed patients only">

                    <div class="queue-summary-head">

                        <div class="queue-summary-icon">

                            <i class="bi bi-check-circle"></i>

                        </div>

                        <div class="queue-summary-label">
                            Completed
                        </div>

                    </div>

                    <div class="queue-summary-number">
                        <?= $completedCount ?>
                    </div>

                    <div class="queue-summary-sub">
                        Today's completed
                    </div>

                </a>



                <!-- TOTAL -->

                <a class="queue-summary-card<?= $filter === 'All' ? ' active' : '' ?>" href="queue.php?filter=All<?= $noSidebarSuffix ?>" title="Show all patients in queue">

                    <div class="queue-summary-head">

                        <div class="queue-summary-icon">

                            <i class="bi bi-people-fill"></i>

                        </div>

                        <div class="queue-summary-label">
                            Total
                        </div>

                    </div>

                    <div class="queue-summary-number">
                        <?= $totalCount ?>
                    </div>

                    <div class="queue-summary-sub">
                        Total in queue
                    </div>

                </a>


            </div>



            <!-- =================================================
                 QUEUE LIST
            ================================================== -->

            <section class="portal-card">


                <!-- FILTER -->

                <div class="queue-filter">


                    <div class="queue-filter-title">
                        Queue List
                    </div>


                    <div class="filter-buttons">


                        <a
                            href="queue.php?filter=All<?= $noSidebarSuffix ?>"
                            class="filter-button <?= $filter === 'All' ? 'active' : '' ?>"
                        >
                            All
                        </a>


                        <a
                            href="queue.php?filter=Waiting<?= $noSidebarSuffix ?>"
                            class="filter-button <?= $filter === 'Waiting' ? 'active' : '' ?>"
                        >
                            Waiting
                        </a>


                        <a
                            href="queue.php?filter=Serving<?= $noSidebarSuffix ?>"
                            class="filter-button <?= $filter === 'Serving' ? 'active' : '' ?>"
                        >
                            Serving
                        </a>


                        <a
                            href="queue.php?filter=Completed<?= $noSidebarSuffix ?>"
                            class="filter-button <?= $filter === 'Completed' ? 'active' : '' ?>"
                        >
                            Completed
                        </a>


                    </div>

                </div>



                <!-- QUEUE TABLE -->

                <?php if (empty($queues)): ?>


                    <div class="queue-empty">

                        <i class="bi bi-list-check"></i>

                        <strong>
                            No patients in queue.
                        </strong>

                        <div>
                            No approved/confirmed appointments for today<?= $filter !== 'All' ? ' with status ' . dentistQueueEscape($filter) : '' ?>.
                        </div>

                    </div>


                <?php else: ?>


                    <div style="overflow-x:auto;">

                        <table class="portal-table">


                            <thead>

                                <tr>

                                    <th>
                                        Queue Number
                                    </th>

                                    <th>
                                        Patient Name
                                    </th>

                                    <th>
                                        Appointment Time
                                    </th>

                                    <th>
                                        Dental Service
                                    </th>

                                    <th>
                                        Status
                                    </th>

                                    <th>
                                        Action
                                    </th>

                                </tr>

                            </thead>


                            <tbody>


                                <?php foreach ($queues as $queue): ?>


                                    <?php

                                    $status = strtolower(
                                        trim($queue['status'] ?? '')
                                    );

                                    ?>


                                    <tr>


                                        <!-- QUEUE NUMBER -->

                                        <td>

                                            <strong>
                                                #<?= (int) $queue['queue_number'] ?>
                                            </strong>

                                        </td>



                                        <!-- PATIENT -->

                                        <td>

                                            <div class="queue-patient-name">

                                                <?= dentistQueueEscape(
                                                    $queue['patient_name']
                                                ) ?>

                                            </div>


                                            <span class="queue-patient-number">

                                                Patient ID:
                                                <?= (int) $queue['patient_id'] ?>

                                                <?php if (!empty($queue['patient_number'])): ?>

                                                    · <?= dentistQueueEscape($queue['patient_number']) ?>

                                                <?php endif; ?>

                                            </span>

                                        </td>



                                        <!-- APPOINTMENT TIME -->

                                        <td>

                                            <?php if (
                                                !empty(
                                                    $queue['appointment_start_time']
                                                )
                                            ): ?>


                                                <div class="queue-time">

                                                    <?= dentistQueueEscape(
                                                        date(
                                                            'h:i A',
                                                            strtotime(
                                                                $queue[
                                                                    'appointment_start_time'
                                                                ]
                                                            )
                                                        )
                                                    ) ?>


                                                    <?php if (
                                                        !empty(
                                                            $queue[
                                                                'appointment_end_time'
                                                            ]
                                                        )
                                                    ): ?>

                                                        -
                                                        <?= dentistQueueEscape(
                                                            date(
                                                                'h:i A',
                                                                strtotime(
                                                                    $queue[
                                                                        'appointment_end_time'
                                                                    ]
                                                                )
                                                            )
                                                        ) ?>

                                                    <?php endif; ?>

                                                </div>


                                            <?php else: ?>


                                                <span>
                                                    —
                                                </span>


                                            <?php endif; ?>

                                        </td>



                                        <!-- SERVICE -->

                                        <td>

                                            <div class="queue-service">

                                                <?= dentistQueueEscape(
                                                    $queue['service_type']
                                                ) ?: '—' ?>

                                            </div>

                                        </td>



                                        <!-- STATUS -->

                                        <td>

                                            <span
                                                class="queue-status <?= dentistQueueEscape($status) ?>"
                                            >

                                                <?= dentistQueueEscape(
                                                    $queue['status']
                                                ) ?>

                                            </span>

                                        </td>



                                        <!-- ACTION -->

                                        <td>

                                            <div class="queue-actions">


                                                <!-- VIEW -->

                                                <button
                                                    type="button"
                                                    class="queue-action view queue-view-btn"
                                                    data-patient="<?= dentistQueueEscape(trim(($queue['patient_name'] ?? '') . '')) ?>"
                                                    data-service="<?= dentistQueueEscape($queue['service_type'] ?? '') ?>"
                                                    data-dentist="<?= dentistQueueEscape($dentistName ?? '') ?>"
                                                    data-time="<?= dentistQueueEscape(date('M d, Y', strtotime($queue['queue_date'])) . ' ' . date('h:i A', strtotime($queue['appointment_start_time'])) . ' - ' . date('h:i A', strtotime($queue['appointment_end_time']))) ?>"
                                                    data-booked="<?= !empty($queue['booked_at']) ? dentistQueueEscape(date('M d, Y h:i A', strtotime($queue['booked_at']))) : '—' ?>"
                                                >

                                                    <i class="bi bi-eye"></i>

                                                    View

                                                </button>



                                                <!-- COMPLETE -->

                                                <?php if (
                                                    $queue['status'] === 'Serving'
                                                ): ?>

                                                    <form
                                                        method="POST"
                                                        style="display:inline;"
                                                    >
<?= csrfField() ?>

                                                        <input
                                                            type="hidden"
                                                            name="queue_id"
                                                            value="<?= (int) $queue['id'] ?>"
                                                        >


                                                        <input
                                                            type="hidden"
                                                            name="status"
                                                            value="Completed"
                                                        >


                                                        <input
                                                            type="hidden"
                                                            name="filter"
                                                            value="<?= dentistQueueEscape($filter) ?>"
                                                        >


                                                        <button
                                                            type="submit"
                                                            class="queue-action complete"
                                                        >

                                                            <i class="bi bi-check-lg"></i>

                                                            Complete

                                                        </button>

                                                    </form>

                                                <?php endif; ?>


                                            </div>

                                        </td>


                                    </tr>


                                <?php endforeach; ?>


                            </tbody>


                        </table>

                    </div>


                <?php endif; ?>


            </section>



            <!-- =================================================
                 PATIENT DETAILS
            ================================================== -->

            <?php if (
                $selectedPatient &&
                $selectedQueue
            ): ?>


                <section class="portal-card patient-detail-card">


                    <!-- DETAIL HEADER -->

                    <div class="patient-detail-header">


                        <div class="patient-title">


                            <div class="patient-avatar">

                                <i class="bi bi-person-fill"></i>

                            </div>


                            <div>

                                <h2>

                                    <?= dentistQueueEscape(
                                        trim(
                                            $selectedPatient['first_name']
                                            . ' '
                                            . ($selectedPatient['middle_name'] ?? '')
                                            . ' '
                                            . $selectedPatient['last_name']
                                        )
                                    ) ?>

                                </h2>


                                <small>

                                    Patient No:
                                    <?= dentistQueueEscape(
                                        $selectedPatient['patient_number']
                                    ) ?>

                                </small>

                            </div>


                        </div>


                        <a
                            href="queue.php?filter=<?= urlencode($filter) ?><?= $noSidebarSuffix ?>"
                            class="close-detail"
                        >

                            <i class="bi bi-x-lg"></i>

                            Close

                        </a>


                    </div>



                    <!-- DETAILS -->

                    <div class="detail-grid">


                        <!-- =====================================
                             PATIENT INFORMATION
                        ====================================== -->

                        <div class="detail-section">


                            <h3>
                                Patient Information
                            </h3>


                            <div class="detail-list">


                                <div class="detail-item">

                                    <div class="detail-label">
                                        Patient Number
                                    </div>

                                    <div class="detail-value">

                                        <?= dentistQueueEscape(
                                            $selectedPatient['patient_number']
                                        ) ?>

                                    </div>

                                </div>



                                <div class="detail-item">

                                    <div class="detail-label">
                                        Gender
                                    </div>

                                    <div class="detail-value">

                                        <?= dentistQueueEscape(
                                            $selectedPatient['gender']
                                        ) ?: '—' ?>

                                    </div>

                                </div>



                                <div class="detail-item">

                                    <div class="detail-label">
                                        Birth Date
                                    </div>

                                    <div class="detail-value">

                                        <?php if (
                                            !empty(
                                                $selectedPatient['birth_date']
                                            )
                                        ): ?>

                                            <?= dentistQueueEscape(
                                                date(
                                                    'F d, Y',
                                                    strtotime(
                                                        $selectedPatient[
                                                            'birth_date'
                                                        ]
                                                    )
                                                )
                                            ) ?>

                                        <?php else: ?>

                                            —

                                        <?php endif; ?>

                                    </div>

                                </div>



                                <div class="detail-item">

                                    <div class="detail-label">
                                        Contact Number
                                    </div>

                                    <div class="detail-value">

                                        <?= dentistQueueEscape(
                                            $selectedPatient['contact_number']
                                        ) ?: '—' ?>

                                    </div>

                                </div>



                                <div class="detail-item">

                                    <div class="detail-label">
                                        Email
                                    </div>

                                    <div class="detail-value">

                                        <?= dentistQueueEscape(
                                            $selectedPatient['email']
                                        ) ?: '—' ?>

                                    </div>

                                </div>



                                <div class="detail-item">

                                    <div class="detail-label">
                                        Address
                                    </div>

                                    <div class="detail-value">

                                        <?= dentistQueueEscape(
                                            $selectedPatient['address']
                                        ) ?: '—' ?>

                                    </div>

                                </div>


                            </div>

                        </div>



                        <!-- =====================================
                             APPOINTMENT INFORMATION
                        ====================================== -->

                        <div class="detail-section">


                            <h3>
                                Appointment Information
                            </h3>


                            <?php if ($selectedAppointment): ?>


                                <div class="detail-list">


                                    <div class="detail-item">

                                        <div class="detail-label">
                                            Date
                                        </div>

                                        <div class="detail-value">

                                            <?= dentistQueueEscape(
                                                date(
                                                    'F d, Y',
                                                    strtotime(
                                                        $selectedAppointment[
                                                            'appointment_date'
                                                        ]
                                                    )
                                                )
                                            ) ?>

                                        </div>

                                    </div>



                                    <div class="detail-item">

                                        <div class="detail-label">
                                            Time
                                        </div>

                                        <div class="detail-value">

                                            <?= dentistQueueEscape(
                                                date(
                                                    'h:i A',
                                                    strtotime(
                                                        $selectedAppointment[
                                                            'start_time'
                                                        ]
                                                    )
                                                )
                                            ) ?>


                                            -

                                            <?= dentistQueueEscape(
                                                date(
                                                    'h:i A',
                                                    strtotime(
                                                        $selectedAppointment[
                                                            'end_time'
                                                        ]
                                                    )
                                                )
                                            ) ?>

                                        </div>

                                    </div>



                                    <div class="detail-item">

                                        <div class="detail-label">
                                            Dental Service
                                        </div>

                                        <div class="detail-value">

                                            <?= dentistQueueEscape(
                                                $selectedAppointment[
                                                    'service_type'
                                                ]
                                            ) ?>

                                        </div>

                                    </div>



                                    <div class="detail-item">

                                        <div class="detail-label">
                                            Appointment Status
                                        </div>

                                        <div class="detail-value">

                                            <?= dentistQueueEscape(
                                                $selectedAppointment['status']
                                            ) ?>

                                        </div>

                                    </div>



                                    <div class="detail-item">

                                        <div class="detail-label">
                                            Queue Number
                                        </div>

                                        <div class="detail-value">

                                            #
                                            <?= (int) $selectedQueue[
                                                'queue_number'
                                            ] ?>

                                        </div>

                                    </div>



                                    <div class="detail-item">

                                        <div class="detail-label">
                                            Queue Status
                                        </div>

                                        <div class="detail-value">

                                            <?= dentistQueueEscape(
                                                $selectedQueue['status']
                                            ) ?>

                                        </div>

                                    </div>



                                    <div class="detail-item">

                                        <div class="detail-label">
                                            Priority
                                        </div>

                                        <div class="detail-value">

                                            <?= dentistQueueEscape(
                                                $selectedQueue['priority']
                                            ) ?: '—' ?>

                                        </div>

                                    </div>



                                    <div class="detail-item">

                                        <div class="detail-label">
                                            Notes
                                        </div>

                                        <div class="detail-value">

                                            <?= dentistQueueEscape(
                                                $selectedAppointment['notes']
                                            ) ?: '—' ?>

                                        </div>

                                    </div>


                                </div>


                            <?php else: ?>


                                <div class="queue-empty">

                                    <i class="bi bi-calendar-x"></i>

                                    No appointment information found.

                                </div>


                            <?php endif; ?>


                        </div>


                    </div>


                </section>


            <?php endif; ?>


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

<style>
.qview-overlay{position:fixed;inset:0;background:rgba(10,59,52,.55);z-index:2000;padding:20px;display:none;align-items:center;justify-content:center}
.qview-overlay.open{display:flex}
.qview-box{background:#fff;border-radius:18px;max-width:440px;width:100%;overflow:hidden;box-shadow:0 18px 45px rgba(0,0,0,.25);animation:qviewPop .18s ease}
@keyframes qviewPop{from{transform:scale(.96);opacity:0}to{transform:scale(1);opacity:1}}
.qview-head{padding:18px 22px;background:linear-gradient(135deg,#D9F2E6,#EAF7F0);border-bottom:1px solid #C9E9DA;display:flex;align-items:center;gap:10px}
.qview-head h5{margin:0;flex:1;color:#143f51;font-weight:800;font-size:16px}
.qview-x{background:#fff;border:1px solid #D1E7DD;color:#57727d;width:30px;height:30px;border-radius:9px;cursor:pointer;font-size:13px}
.qview-x:hover{background:#EAF7F0;color:#0E6B5E}
.qview-body{padding:18px 22px 20px;font-size:13.5px;color:#4d6873}
.qview-body .row-line{margin-bottom:12px;padding-bottom:12px;border-bottom:1px dashed #D6E7E1}
.qview-body .row-line:last-child{margin-bottom:0;padding-bottom:0;border-bottom:none}
.qview-body .row-line strong{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#143f51;margin-bottom:3px}
</style>

<!-- QUEUE VIEW MODAL: gaya sa appointment View -->
<div class="qview-overlay" id="qviewOverlay">
    <div class="qview-box">
        <div class="qview-head">
            <h5><i class="bi bi-eye-fill"></i> Queue Details</h5>
            <button type="button" class="qview-x" id="qviewClose" aria-label="Close">✕</button>
        </div>
        <div class="qview-body">
            <div class="row-line"><strong>Patient name:</strong> <span id="qviewPatient"></span></div>
            <div class="row-line"><strong>Dental service:</strong> <span id="qviewService"></span></div>
            <div class="row-line"><strong>Assigned dentist:</strong> <span id="qviewDentist"></span></div>
            <div class="row-line"><strong>Date and time of appointment:</strong> <span id="qviewTime"></span></div>
            <div class="row-line"><strong>Booked on:</strong> <span id="qviewBooked"></span></div>
        </div>
    </div>
</div>

<script>
/* Queue View modal: gaya sa appointment View */
(function(){
    var overlay = document.getElementById('qviewOverlay');
    if(!overlay) return;
    function close(){ overlay.classList.remove('open'); }
    document.getElementById('qviewClose').addEventListener('click', close);
    overlay.addEventListener('click', function(e){ if(e.target === overlay) close(); });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape') close(); });
    document.querySelectorAll('.queue-view-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            document.getElementById('qviewPatient').textContent = btn.dataset.patient || '';
            document.getElementById('qviewService').textContent = btn.dataset.service || '—';
            document.getElementById('qviewDentist').textContent = btn.dataset.dentist || '';
            document.getElementById('qviewTime').textContent = btn.dataset.time || '';
            document.getElementById('qviewBooked').textContent = btn.dataset.booked || '—';
            overlay.classList.add('open');
        });
    });
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
