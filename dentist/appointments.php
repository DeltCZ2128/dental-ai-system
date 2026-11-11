<?php

require_once __DIR__ . '/../config.php';

/*
|--------------------------------------------------------------------------
| DENTIST ACCESS
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
| HELPER
|--------------------------------------------------------------------------
*/

function dentistEscape($value)
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| GET LOGGED-IN DENTIST
|--------------------------------------------------------------------------
|
| Your dentists table:
|
| id | dentist_name
| 1  | Dr. Juan Dela Cruz
| 2  | Dr. Renz
|
| We match the logged-in dentist using the session full_name.
|
|--------------------------------------------------------------------------
*/

$sessionName = trim(
    $_SESSION['full_name'] ?? ''
);

$dentistId = function_exists('resolveDentistId')
    ? resolveDentistId((int)($_SESSION['user_id'] ?? 0), $sessionName)
    : 0;
$dentistName = $sessionName;

if ($dentistId > 0) {
    try {
        $nmStmt = $pdo->prepare("SELECT dentist_name FROM dentists WHERE id = ? LIMIT 1");
        $nmStmt->execute([$dentistId]);
        $foundName = $nmStmt->fetchColumn();
        if ($foundName) {
            $dentistName = $foundName;
        }
    } catch (Throwable $e) {}
}


/*
|--------------------------------------------------------------------------
| GET ALL DENTISTS (fallback list kapag hindi naka-resolve sa helper)
|--------------------------------------------------------------------------
*/

$dentistListStmt = $pdo->query("
    SELECT
        id,
        dentist_name
    FROM dentists
    ORDER BY id ASC
");

$dentistList = $dentistListStmt->fetchAll(
    PDO::FETCH_ASSOC
);


/*
|--------------------------------------------------------------------------
| NORMALIZE NAME
|--------------------------------------------------------------------------
|
| Example:
|
| Juan Dela Cruz
| Dr. Juan Dela Cruz
|
| will be treated as the same name.
|
|--------------------------------------------------------------------------
*/

function normalizeDentistName($name)
{
    $name = strtolower(trim((string)$name));

    $name = preg_replace(
        '/^dr\.?\s*/i',
        '',
        $name
    );

    $name = preg_replace(
        '/\s+/',
        ' ',
        $name
    );

    return trim($name);
}


$normalizedSessionName =
    normalizeDentistName($sessionName);


/*
|--------------------------------------------------------------------------
| FIND DENTIST (fallback lang kapag hindi naka-resolve sa helper)
|--------------------------------------------------------------------------
*/

if ($dentistId === 0) {
foreach ($dentistList as $dentist) {

    $databaseDentistName =
        normalizeDentistName(
            $dentist['dentist_name'] ?? ''
        );

    if (
        $databaseDentistName !== '' &&
        $databaseDentistName === $normalizedSessionName
    ) {

        $dentistId =
            (int)$dentist['id'];

        $dentistName =
            $dentist['dentist_name'];

        break;
    }
}
}


/*
|--------------------------------------------------------------------------
| FALLBACK
|--------------------------------------------------------------------------
|
| If users.id is also the same as dentists.id.
|
|--------------------------------------------------------------------------
*/

if ($dentistId === 0) {

    $fallbackStmt = $pdo->prepare("
        SELECT
            id,
            dentist_name
        FROM dentists
        WHERE id = ?
        LIMIT 1
    ");

    $fallbackStmt->execute([
        (int)$_SESSION['user_id']
    ]);

    $fallbackDentist =
        $fallbackStmt->fetch(
            PDO::FETCH_ASSOC
        );

    if ($fallbackDentist) {

        $dentistId =
            (int)$fallbackDentist['id'];

        $dentistName =
            $fallbackDentist['dentist_name'];
    }
}


/*
|--------------------------------------------------------------------------
| MOVED FROM DASHBOARD — STATUS UPDATES
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'appointment_status') {

    $appointmentStatus = trim($_POST['status'] ?? '');
    $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
    $statusSaved = false;

    if (
        $appointmentId > 0 &&
        in_array($appointmentStatus, ['Pending', 'Confirmed', 'Completed', 'Cancelled'], true)
    ) {
        $chkStmt = $pdo->prepare("
            SELECT appointment_date, start_time, end_time
            FROM appointments
            WHERE id = ? AND dentist_id = ?
            LIMIT 1
        ");
        $chkStmt->execute([$appointmentId, $dentistId]);
        $chkAppt = $chkStmt->fetch(PDO::FETCH_ASSOC);

        if ($chkAppt) {
            $aDate = substr((string)$chkAppt['appointment_date'], 0, 10);
            $aStart = substr((string)$chkAppt['start_time'], 0, 5);
            $aEnd = substr((string)($chkAppt['end_time'] ?: $chkAppt['start_time']), 0, 5);
            $nowDate = date('Y-m-d');
            $nowHm = date('H:i');

            $validTransition = true;
            if ($appointmentStatus === 'Confirmed') {
                if ($aDate < $nowDate || $aStart < '08:00' || $aEnd > '18:00') {
                    $validTransition = false;
                } elseif ($aDate === $nowDate && ($nowHm >= '18:00' || $aEnd <= $nowHm)) {
                    $validTransition = false;
                }
            } elseif ($appointmentStatus === 'Completed' && $aDate > $nowDate) {
                $validTransition = false;
            }

            if ($validTransition) {
                $statusStampCols = [
                    'Confirmed' => 'confirmed_at',
                    'Completed' => 'completed_at',
                    'Cancelled' => 'cancelled_at'
                ];
                $statusStampSql = isset($statusStampCols[$appointmentStatus]) ? ", {$statusStampCols[$appointmentStatus]} = NOW()" : "";
                $statusStmt = $pdo->prepare("
                    UPDATE appointments
                    SET status = ?{$statusStampSql}
                    WHERE id = ?
                    AND dentist_id = ?
                ");
                $statusStmt->execute([$appointmentStatus, $appointmentId, $dentistId]);
                $statusSaved = $statusStmt->rowCount() > 0;

                if ($statusSaved && $appointmentStatus === 'Completed') {
                    try {
                        $pStmt = $pdo->prepare("SELECT patient_id FROM appointments WHERE id = ? LIMIT 1");
                        $pStmt->execute([$appointmentId]);
                        $pid = (int)$pStmt->fetchColumn();
                        if ($pid > 0) {
                            $qSync = $pdo->prepare("UPDATE queue SET status = 'Completed' WHERE patient_id = ? AND queue_date = ?");
                            $qSync->execute([$pid, $aDate]);
                        }
                    } catch (PDOException $e) {
                    }
                }
            }
        }
    }

    header('Location: appointments.php?' . ($statusSaved ? 'saved=1' : 'save_failed=1') . '#today');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reschedule') {

    $reschedId = (int) ($_POST['appointment_id'] ?? 0);
    $newDate = trim($_POST['appointment_date'] ?? '');
    $newStart = trim($_POST['start_time'] ?? '');
    $newEnd = trim($_POST['end_time'] ?? '');

    $newStartHm = substr($newStart, 0, 5);
    $newEndHm = substr($newEnd, 0, 5);
    $nowDate = date('Y-m-d');
    $nowHm = date('H:i');

    $reschedOk = $reschedId > 0
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)
        && $newDate >= $nowDate
        && (int)date('w', strtotime($newDate)) !== 0
        && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $newStart)
        && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $newEnd)
        && $newStartHm >= '08:00'
        && $newEndHm <= '18:00'
        && ($newDate > $nowDate || $newStartHm > $nowHm)
        && strtotime($newDate . ' ' . $newStart) < strtotime($newDate . ' ' . $newEnd);

    if ($reschedOk) {
        $rsStmt = $pdo->prepare("
            UPDATE appointments
            SET appointment_date = ?, start_time = ?, end_time = ?, rescheduled_at = NOW()
            WHERE id = ?
            AND dentist_id = ?
        ");
        $rsStmt->execute([$newDate, $newStart, $newEnd, $reschedId, $dentistId]);
        $reschedOk = $rsStmt->rowCount() > 0;
    }

    header('Location: appointments.php?' . ($reschedOk ? 'saved=1' : 'save_failed=1') . '#today');
    exit;
}


/*
|--------------------------------------------------------------------------
| GET ONLY THIS DENTIST'S APPOINTMENTS
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| No fallback to ALL appointments.
|
| Dentist only sees appointments where:
|
| appointments.dentist_id
| =
| logged-in dentist id
|
|--------------------------------------------------------------------------
*/

$appointments = [];

if ($dentistId > 0) {

    $appointmentStmt = $pdo->prepare("
        SELECT

            a.id,

            a.patient_id,

            a.dentist_id,

            a.appointment_date,

            a.start_time,

            a.end_time,

            a.service_type,

            a.status,

            a.notes,

            a.created_at,

            a.confirmed_at,

            a.checked_in_at,

            a.serving_at,

            a.completed_at,

            a.cancelled_at,

            a.rescheduled_at,

            CONCAT(
                COALESCE(p.first_name, ''),
                ' ',
                COALESCE(p.last_name, '')
            ) AS patient_name

        FROM appointments a

        LEFT JOIN patients p
            ON p.id = a.patient_id

        WHERE a.dentist_id = ?

        ORDER BY
            a.appointment_date DESC,
            a.start_time DESC
    ");

    $appointmentStmt->execute([
        $dentistId
    ]);

    $appointments =
        $appointmentStmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}


/*
|--------------------------------------------------------------------------
| MOVED FROM DASHBOARD — TODAY / QUEUE / UPCOMING
|--------------------------------------------------------------------------
*/

$todayDate = date('Y-m-d');

$todayAppointments = [];
$upcomingAppointments = [];

try {
    $todayStmt = $pdo->prepare("
        SELECT
            a.id,
            a.appointment_date,
            a.start_time AS appointment_time,
            a.end_time,
            a.service_type,
            a.notes,
            a.status,
            a.created_at,
            p.id AS patient_id,
            CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
            p.email AS patient_email
        FROM appointments a
        INNER JOIN patients p
            ON a.patient_id = p.id
        WHERE a.dentist_id = ?
        AND a.appointment_date = ?
        AND a.status NOT IN ('Completed', 'No-Show', 'Cancelled', 'Canceled')
        ORDER BY a.start_time ASC
    ");
    $todayStmt->execute([$dentistId, $todayDate]);
    $todayAppointments = $todayStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $todayAppointments = [];
}

try {
    $upcomingStmt = $pdo->prepare("
        SELECT
            a.id,
            a.appointment_date,
            a.start_time AS appointment_time,
            a.end_time,
            a.service_type,
            a.notes,
            a.status,
            a.created_at,
            p.id AS patient_id,
            CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
            p.email AS patient_email
        FROM appointments a
        INNER JOIN patients p
            ON a.patient_id = p.id
        WHERE a.dentist_id = ?
        AND a.appointment_date > ?
        AND a.status NOT IN ('Completed', 'No-Show', 'Cancelled', 'Canceled')
        ORDER BY a.appointment_date ASC,
                 a.start_time ASC
        LIMIT 10
    ");
    $upcomingStmt->execute([$dentistId, $todayDate]);
    $upcomingAppointments = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $upcomingAppointments = [];
}

$todayCount = count($todayAppointments);
$upcomingCount = count($upcomingAppointments);

/*
|--------------------------------------------------------------------------
| TOP SUMMARY COUNTS (reuses already-fetched $appointments, no extra query)
|--------------------------------------------------------------------------
*/

$completedCountAppt = 0;
$cancelledCountAppt = 0;
$noShowCountAppt = 0;

/*
 * Listahan para sa summary cards. Ginagamit ang $appointments na na-fetch
 * na sa itaas (lahat ng petsa, walang dagdag na query) para pareho ang
 * bilang at ang ipinapakita.
 */

$completedListAppt = [];
$noShowListAppt = [];
$cancelledListAppt = [];

foreach (($appointments ?? []) as $aRow) {
    $st = strtolower(trim($aRow['status'] ?? ''));

    if ($st === 'completed') {
        $completedCountAppt++;
        $completedListAppt[] = $aRow;
    } elseif ($st === 'cancelled' || $st === 'canceled') {
        $cancelledCountAppt++;
        $cancelledListAppt[] = $aRow;
    } elseif ($st === 'no-show' || $st === 'no show') {
        $noShowCountAppt++;
        $noShowListAppt[] = $aRow;
    }
}

$cancelNoShowCount = $cancelledCountAppt + $noShowCountAppt;

/* Pinagsama ang No-Show at Cancelled sa iisang listahan para sa card */
$cancelNoShowListAppt = array_merge($noShowListAppt, $cancelledListAppt);


/*
|--------------------------------------------------------------------------
| FILTER PARAMS (status + date range, GET only, safe defaults)
|--------------------------------------------------------------------------
*/

$filterStatus = trim($_GET['status'] ?? '');
$filterFrom = trim($_GET['date_from'] ?? '');
$filterTo = trim($_GET['date_to'] ?? '');

$allowedFilterStatus = ['Pending', 'Confirmed', 'Completed', 'Cancelled', 'No-Show'];

if ($filterStatus !== '' && !in_array($filterStatus, $allowedFilterStatus, true)) {
    $filterStatus = '';
}

if ($filterFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = '';
}

if ($filterTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = '';
}


/*
|--------------------------------------------------------------------------
| FILTERED TODAY LIST (middle column respects ?status= filter)
|--------------------------------------------------------------------------
*/

$filteredTodayAppointments = $todayAppointments;

if ($filterStatus !== '') {
    $filteredTodayAppointments = array_values(array_filter(
        $filteredTodayAppointments,
        function ($r) use ($filterStatus) {
            return strcasecmp(trim($r['status'] ?? ''), $filterStatus) === 0;
        }
    ));
}

/* Pagination: 10 rows kada page (Today) */
$todayPage = apptPageSlice($filteredTodayAppointments, 'today');
$todayPageItems = $todayPage['items'];


/*
|--------------------------------------------------------------------------
| APPLY DATE RANGE TO UPCOMING LIST (reuse, no extra query)
|--------------------------------------------------------------------------
*/

if ($filterFrom !== '' || $filterTo !== '') {
    $upcomingAppointments = array_values(array_filter(
        $upcomingAppointments,
        function ($r) use ($filterFrom, $filterTo) {
            $d = substr(trim($r['appointment_date'] ?? ''), 0, 10);

            if ($filterFrom !== '' && $d < $filterFrom) {
                return false;
            }

            if ($filterTo !== '' && $d > $filterTo) {
                return false;
            }

            return true;
        }
    ));

    $upcomingCount = count($upcomingAppointments);
}

if ($filterStatus !== '') {
    $upcomingAppointments = array_values(array_filter(
        $upcomingAppointments,
        function ($r) use ($filterStatus) {
            return strcasecmp(trim($r['status'] ?? ''), $filterStatus) === 0;
        }
    ));

    $upcomingCount = count($upcomingAppointments);
}


/*
|--------------------------------------------------------------------------
| PAGINATION HELPERS (10 rows per page, hiwalay na page kada box)
|--------------------------------------------------------------------------
*/

function apptPageUrl($params = [])
{
    $query = array_merge($_GET, $params);

    foreach ($query as $k => $v) {
        if ($v === null || $v === '') {
            unset($query[$k]);
        }
    }

    return 'appointments.php?' . http_build_query($query);
}

function apptPageSlice($items, $pageParam, $perPage = 10)
{
    $total = count($items);

    $totalPages = max(1, (int) ceil($total / $perPage));

    $page = (int) ($_GET[$pageParam] ?? 1);

    if ($page < 1) {
        $page = 1;
    }

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    return [
        'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
        'page' => $page,
        'totalPages' => $totalPages,
        'total' => $total,
    ];
}

function apptPaginationHtml($page, $param)
{
    $anchor = ['today' => 'today', 'up' => 'upcoming', 'hist' => 'history'][$param] ?? 'today';

    $cur = $page['page'];
    $last = $page['totalPages'];

    ob_start();
    ?>
    <nav class="appt-pagination" aria-label="Pages">
        <?php if ($cur > 1): ?>
            <a class="appt-page-btn" href="<?= dentistEscape(apptPageUrl([$param => $cur - 1])) ?>#<?= $anchor ?>">
                <i class="bi bi-chevron-left"></i> Prev
            </a>
        <?php else: ?>
            <span class="appt-page-btn disabled"><i class="bi bi-chevron-left"></i> Prev</span>
        <?php endif; ?>

        <?php for ($p = 1; $p <= $last; $p++): ?>
            <?php if ($p === $cur): ?>
                <span class="appt-page-btn active"><?= $p ?></span>
            <?php else: ?>
                <a class="appt-page-btn" href="<?= dentistEscape(apptPageUrl([$param => $p])) ?>#<?= $anchor ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($cur < $last): ?>
            <a class="appt-page-btn" href="<?= dentistEscape(apptPageUrl([$param => $cur + 1])) ?>#<?= $anchor ?>">
                Next <i class="bi bi-chevron-right"></i>
            </a>
        <?php else: ?>
            <span class="appt-page-btn disabled">Next <i class="bi bi-chevron-right"></i></span>
        <?php endif; ?>
    </nav>
    <?php
    return ob_get_clean();
}


/*
|--------------------------------------------------------------------------
| HIGHLIGHTED APPOINTMENT MULA SA NOTIFICATION (?highlight=id)
| Kahit wala sa Today/Upcoming list (past/cancelled/beyond-10), ipakita pa rin.
|--------------------------------------------------------------------------
*/

$highlightedAppt = null;
$highlightIdParam = (int) ($_GET['highlight'] ?? 0);
if ($highlightIdParam > 0 && $dentistId > 0) {
    try {
        $hlStmt = $pdo->prepare("
            SELECT
                a.id,
                a.appointment_date,
                a.start_time AS appointment_time,
                a.end_time,
                a.service_type,
                a.notes,
                a.status,
                a.created_at,
                CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')) AS patient_name
            FROM appointments a
            LEFT JOIN patients p ON p.id = a.patient_id
            WHERE a.id = ? AND a.dentist_id = ?
            LIMIT 1
        ");
        $hlStmt->execute([$highlightIdParam, $dentistId]);
        $highlightedAppt = $hlStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $highlightedAppt = null;
    }
}


/*
|--------------------------------------------------------------------------
| CALENDAR MAP (viewed month via ?m=YYYY-MM, date => count + statuses)
|--------------------------------------------------------------------------
*/

$calToday = date('Y-m-d');

$calViewParam = trim($_GET['m'] ?? '');

if (preg_match('/^(\d{4})-(\d{2})$/', $calViewParam, $calMatch)) {
    $reqYear = (int) $calMatch[1];
    $reqMonth = (int) $calMatch[2];

    if ($reqYear >= 2000 && $reqYear <= 2100 && $reqMonth >= 1 && $reqMonth <= 12) {
        $calYear = $reqYear;
        $calMonth = $reqMonth;
    } else {
        $calYear = (int) date('Y');
        $calMonth = (int) date('m');
    }
} else {
    $calYear = (int) date('Y');
    $calMonth = (int) date('m');
}

$calViewKey = sprintf('%04d-%02d', $calYear, $calMonth);
$calViewLabel = date('F Y', strtotime($calViewKey . '-01'));

try {
    $calPrevKey = date('Y-m', strtotime($calViewKey . '-01 -1 month'));
    $calNextKey = date('Y-m', strtotime($calViewKey . '-01 +1 month'));
} catch (Throwable $e) {
    $calPrevKey = $calViewKey;
    $calNextKey = $calViewKey;
}

function apptCalMonthUrl($monthKey)
{
    $params = $_GET;
    $params['m'] = $monthKey;

    return 'appointments.php?' . http_build_query($params);
}

$calendarMap = [];

foreach (($appointments ?? []) as $aRow) {
    $d = substr(trim($aRow['appointment_date'] ?? ''), 0, 10);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        continue;
    }

    if (substr($d, 0, 7) !== sprintf('%04d-%02d', $calYear, $calMonth)) {
        continue;
    }

    if (!isset($calendarMap[$d])) {
        $calendarMap[$d] = ['count' => 0, 'statuses' => []];
    }

    $calendarMap[$d]['count']++;

    $st = strtolower(trim($aRow['status'] ?? ''));
    $calendarMap[$d]['statuses'][$st] = true;
}


function apptCalDotClass($statuses)
{
    if (isset($statuses['pending'])) {
        return 'pending';
    }

    if (isset($statuses['confirmed'])) {
        return 'confirmed';
    }

    if (isset($statuses['completed'])) {
        return 'completed';
    }

    if (isset($statuses['cancelled']) || isset($statuses['canceled'])) {
        return 'cancelled';
    }

    if (isset($statuses['no-show']) || isset($statuses['no show'])) {
        return 'noshow';
    }

    return 'default';
}


function apptDurationMins($start, $end)
{
    $s = strtotime((string) $start);
    $e = strtotime((string) $end);

    if ($s === false || $e === false || $e <= $s) {
        return 0;
    }

    return (int) round(($e - $s) / 60);
}


/*
|--------------------------------------------------------------------------
| FORMATTERS
|--------------------------------------------------------------------------
*/

function appointmentDate($date)
{
    if (!$date) {
        return '';
    }

    $time = strtotime($date);

    if ($time === false) {
        return '';
    }

    return date('M d, Y', $time);
}


function appointmentTime($time)
{
    if (!$time) {
        return '';
    }

    $timestamp = strtotime($time);

    if ($timestamp === false) {
        return '';
    }

    return date('h:i A', $timestamp);
}


/*
|--------------------------------------------------------------------------
| FIRST APPOINTMENT
|--------------------------------------------------------------------------
|
| Ito ang unang appointment na ipapakita sa details panel.
|
|--------------------------------------------------------------------------
*/

$firstAppointment =
    !empty($appointments)
        ? $appointments[0]
        : null;

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
        Appointments | Dentist Portal
    </title>


    <!-- BOOTSTRAP ICONS -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- FONTS -->

    <link
        rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
    >


    <!-- YOUR PORTAL CSS -->

    <link
        rel="stylesheet"
        href="portal.css?v=5"
    >

    <!-- DENTIST SIDEBAR (dashboard parity) -->
    <link rel="stylesheet" href="dentist_sidebar.css">


    <style>

        /*
        |--------------------------------------------------------------------------
        | APPOINTMENT DETAILS
        |--------------------------------------------------------------------------
        */

        .appointment-details {

            background: #ffffff;

            border:
                1px solid #bce5eb;

            border-radius: 12px;

            padding: 20px;

            margin-bottom: 24px;

            box-shadow:
                0 4px 12px
                rgba(
                    11,
                    127,
                    152,
                    0.05
                );

        }


        .appointment-details h3 {

            margin:
                0 0 18px 0;

            padding-bottom:
                10px;

            border-bottom:
                1px dashed #bce5eb;

            color:
                #0b7f98;

            font-family:
                "Plus Jakarta Sans",
                sans-serif;

            font-size:
                16px;

            display:
                flex;

            align-items:
                center;

            gap:
                8px;

        }


        .details-grid {

            display:
                grid;

            grid-template-columns:
                repeat(
                    4,
                    1fr
                );

            gap:
                20px;

        }


        .detail-item label {

            display:
                block;

            margin-bottom:
                5px;

            color:
                #64748b;

            font-size:
                10px;

            font-weight:
                700;

            text-transform:
                uppercase;

        }


        .detail-item .value {

            color:
                #1e293b;

            font-size:
                15px;

            font-weight:
                600;

        }


        /*
        |--------------------------------------------------------------------------
        | CLICKABLE TABLE ROW
        |--------------------------------------------------------------------------
        */

        .clickable-row {

            cursor:
                pointer;

            transition:
                background-color .15s ease;

        }


        .clickable-row:hover {

            background-color:
                #eaf9fc !important;

        }


        .clickable-row.selected {

            background-color:
                #eaf9fc !important;

            box-shadow:
                inset 3px 0 0 #0E6B5E;

        }


        /*
        |--------------------------------------------------------------------------
        | EMPTY
        |--------------------------------------------------------------------------
        */

        .empty-appointments {

            text-align:
                center;

            padding:
                50px 20px;

            color:
                #64748b;

        }


        .empty-appointments i {

            display:
                block;

            margin-bottom:
                12px;

            color:
                #bce5eb;

            font-size:
                40px;

        }


        .empty-appointments strong {

            display:
                block;

            margin-bottom:
                5px;

            color:
                #334155;

            font-size:
                15px;

        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVE
        |--------------------------------------------------------------------------
        */

        @media (
            max-width: 950px
        ) {

            .details-grid {

                grid-template-columns:
                    repeat(
                        2,
                        1fr
                    );

            }

        }


        @media (
            max-width: 600px
        ) {

            .details-grid {

                grid-template-columns:
                    1fr;

            }

        }


        /*
        |--------------------------------------------------------------------------
        | ADMIN APPOINTMENTS LOOK — overrides (portal.css hindi ginalaw)
        |--------------------------------------------------------------------------
        */

        .portal-card {

            background:
                #ffffff !important;

            border:
                1px solid #C9E9DA !important;

            border-radius:
                16px !important;

            box-shadow:
                0 8px 24px
                rgba(14,107,94,.08) !important;

            padding:
                20px !important;

        }


        .portal-card h2 {

            color:
                #143f51 !important;

            font-family:
                "Plus Jakarta Sans", "Inter",
                system-ui, sans-serif !important;

            font-size:
                17px !important;

            font-weight:
                800 !important;

            letter-spacing:
                -0.02em !important;

            margin:
                0 0 14px 0 !important;

            display:
                flex;

            align-items:
                center;

            gap:
                8px;

        }


        .portal-card h2 i {

            color:
                #0E6B5E !important;

            font-size:
                18px;

        }


        .portal-table {

            border-collapse:
                separate !important;

            border-spacing:
                0 !important;

            background:
                #ffffff;

        }


        .portal-table thead tr {

            background:
                linear-gradient(135deg, #0E6B5E, #0A4A41) !important;

        }


        .portal-table th {

            background:
                transparent !important;

            color:
                #ffffff !important;

            font-size:
                11.5px !important;

            font-weight:
                700 !important;

            text-transform:
                uppercase !important;

            letter-spacing:
                .6px !important;

            white-space:
                nowrap;

            padding:
                14px 16px !important;

            border-bottom:
                none !important;

            border-right:
                1px solid rgba(255,255,255,.12) !important;

            font-family:
                "Inter", system-ui, sans-serif !important;

        }


        .portal-table th:last-child {

            border-right:
                none !important;

        }


        .portal-table td {

            color:
                #4d6873 !important;

            font-size:
                13.5px !important;

            font-family:
                "Inter", "DM Sans",
                system-ui, sans-serif !important;

            padding:
                14px 16px !important;

            background:
                #ffffff;

            border-bottom:
                1px solid #E6F2EC !important;

            vertical-align:
                middle;

        }


        .portal-table tbody tr:nth-child(even) td {

            background:
                #F6FBF8 !important;

        }


        .portal-table tbody tr:hover td {

            background:
                #EAF7F0 !important;

        }


        .portal-table td strong {

            color:
                #143f51 !important;

        }

        /* ===== # column: maliit na index sa unahan ng bawat row ===== */
        .portal-table .col-num,
        .portal-table td.col-num {
            width: 46px;
            min-width: 46px;
            text-align: center;
            color: #8499a1 !important;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
        }
        .portal-table td.col-num span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 8px;
            background: #EFF6F3;
            font-size: 11.5px;
            line-height: 1;
        }


        .portal-status {

            border-radius:
                20px !important;

            padding:
                6px 11px !important;

            font-size:
                11.5px !important;

            font-family:
                "Inter", system-ui, sans-serif !important;

            font-weight:
                700 !important;

            border:
                1px solid transparent;

        }


        .portal-status.pending {

            background:
                #fff3d9 !important;

            color:
                #b45309 !important;

            border-color:
                #f5d48a !important;

        }


        .portal-status.confirmed {

            background:
                #e3f8ec !important;

            color:
                #17844d !important;

            border-color:
                #b9e8cd !important;

        }


        .portal-status.completed {

            background:
                #e6f4fb !important;

            color:
                #157ca0 !important;

            border-color:
                #bfe3f2 !important;

        }


        .portal-status.cancelled {

            background:
                #fdecec !important;

            color:
                #b43b3b !important;

            border-color:
                #f5c6c6 !important;

        }


        .portal-status.no-show {

            background:
                #f1e8e8 !important;

            color:
                #8b4141 !important;

            border-color:
                #e3c9c9 !important;

        }


        .portal-status.checked-in {

            background:
                #d9f2e6 !important;

            color:
                #0E6B5E !important;

            border-color:
                #b9e4cf !important;

        }


        .portal-status.serving {

            background:
                #e0f2f7 !important;

            color:
                #0b6e8f !important;

            border-color:
                #bfe3f2 !important;

        }


        .portal-status.late {

            background:
                #fde8c8 !important;

            color:
                #9a4a00 !important;

            border-color:
                #f5d48a !important;

        }


        .appointment-details {

            background:
                #ffffff !important;

            border:
                1px solid #C9E9DA !important;

            border-radius:
                16px !important;

            box-shadow:
                0 8px 24px
                rgba(14,107,94,.08) !important;

            padding:
                20px !important;

        }


        .appointment-details h3 {

            color:
                #143f51 !important;

            border-bottom:
                1px solid #E6F2EC !important;

        }


        .appointment-details h3 i {

            color:
                #0E6B5E !important;

        }


        .detail-item .value {

            color:
                #143f51 !important;

        }


        .clickable-row:hover {

            background-color:
                #EAF7F0 !important;

        }


        .clickable-row.selected {

            background-color:
                #EAF7F0 !important;

            box-shadow:
                inset 4px 0 0 #0E6B5E !important;

        }


        .empty-appointments i {

            display:
                inline-flex !important;

            align-items:
                center;

            justify-content:
                center;

            width:
                52px;

            height:
                52px;

            border-radius:
                14px;

            background:
                #EAF7F0 !important;

            color:
                #0E6B5E !important;

            border:
                1px solid #C9E9DA;

            font-size:
                24px !important;

        }


        .empty-appointments strong {

            color:
                #426471 !important;

        }


        /*
        |--------------------------------------------------------------------------
        | MOVED FROM DASHBOARD — ACCORDION TABS + DETAILS MODAL
        |--------------------------------------------------------------------------
        */

        .section-tab .section-tab-head {

            cursor:
                pointer;

            user-select:
                none;

            background:
                linear-gradient(135deg, #0E6B5E, #0A4A41);

            margin:
                -20px -20px 14px -20px;

            padding:
                14px 20px;

            border-radius:
                16px 16px 0 0;

        }


        .section-tab .section-tab-head h2 {

            color:
                #ffffff !important;

        }


        .section-tab .section-tab-head h2 i {

            color:
                #ffffff !important;

        }


        .section-tab .section-tab-head:hover h2 {

            color:
                #ffffff !important;

        }


        .section-tab .section-tab-head .section-count {

            background:
                #ffffff !important;

            color:
                #0E6B5E !important;

            border-color:
                #ffffff !important;

        }


        .section-tab .tab-chevron {

            font-size:
                18px;

            color:
                #C9A227;

            transition:
                transform .25s ease;

            margin-left:
                auto;

        }


        .section-tab.open .tab-chevron {

            transform:
                rotate(180deg);

        }


        .section-tab .section-tab-body {

            display:
                none;

            padding-top:
                6px;

        }


        .section-tab.open .section-tab-body {

            display:
                block;

        }


        /* ===== ACCORDION sa .appt-panel (Today / Upcoming / History) ===== */

        .appt-panel.section-tab > .appt-panel-head {

            cursor:
                pointer;

            user-select:
                none;

            transition:
                filter .18s ease;

        }

        .appt-panel.section-tab > .appt-panel-head:hover {

            filter:
                brightness(1.06);

        }

        /* Hindi na kailangan ang negative margin ng .section-tab-head — */
        /* ang .appt-panel ay walang padding sa labas. */
        .appt-panel.section-tab > .appt-panel-head {

            margin:
                0;

            border-radius:
                0;

            padding:
                15px 18px;

        }

        .appt-panel.section-tab > .appt-panel-head .tab-chevron {

            font-size:
                16px;

            color:
                #C9A227;

            flex-shrink:
                0;

            margin-left:
                auto;

            transition:
                transform .25s ease;

        }

        .appt-panel.section-tab.open > .appt-panel-head .tab-chevron {

            transform:
                rotate(180deg);

        }

        /* Chevron hanggang tabi ng count pill. */
        .appt-panel.section-tab > .appt-panel-head .section-count {

            margin-left:
                auto;

        }

        .appt-panel.section-tab > .appt-panel-head .tab-chevron {

            margin-left:
                10px;

        }

        .appt-panel.section-tab > .appt-panel-body {

            display:
                none;

        }

        .appt-panel.section-tab.open > .appt-panel-body {

            display:
                block;

        }

        /* Sarado: wag na ang divider sa ilalim ng header. */
        .appt-panel.section-tab:not(.open) > .appt-panel-head {

            border-bottom:
                none !important;

        }


        .section-count {

            display:
                inline-flex;

            align-items:
                center;

            justify-content:
                center;

            min-width:
                26px;

            height:
                26px;

            padding:
                0 8px;

            border-radius:
                20px;

            background:
                #EAF7F0;

            color:
                #0E6B5E;

            border:
                1px solid #C9E9DA;

            font-size:
                12px;

            font-weight:
                800;

        }


        .moved-empty {

            text-align:
                center;

            padding:
                30px 20px;

            color:
                #8aa0a8;

            font-size:
                13.5px;

        }


        .moved-actions {

            display:
                flex;

            align-items:
                center;

            gap:
                7px;

            flex-wrap:
                wrap;

        }


        .moved-view-btn {

            display:
                inline-flex;

            align-items:
                center;

            gap:
                5px;

            border:
                1px solid #C9E9DA;

            background:
                #EAF7F0;

            color:
                #0E6B5E;

            border-radius:
                9px;

            padding:
                7px 11px;

            font-family:
                "Inter", system-ui, sans-serif;

            font-size:
                12px;

            font-weight:
                700;

            cursor:
                pointer;

        }


        .moved-view-btn:hover {

            background:
                #0E6B5E;

            color:
                #ffffff;

        }




        .moved-select {

            border:
                1px solid #D1E7DD;

            border-radius:
                9px;

            padding:
                7px 10px;

            font-size:
                12.5px;

            font-family:
                "Inter", system-ui, sans-serif;

            color:
                #4d6873;

            background:
                #ffffff;

        }


        .priority-badge {

            display:
                inline-block;

            border-radius:
                20px;

            padding:
                6px 11px;

            font-size:
                11.5px;

            font-weight:
                700;

            border:
                1px solid transparent;

        }


        .priority-badge.emergency {

            background:
                #FDECEC;

            color:
                #C0392B;

            border-color:
                #F5C6C6;

        }


        .priority-badge.normal {

            background:
                #E6F4FB;

            color:
                #157ca0;

            border-color:
                #BFE3F2;

        }


        .appt-modal-overlay {

            position:
                fixed;

            inset:
                0;

            background:
                rgba(10, 59, 52, .55);

            z-index:
                2000;

            display:
                none;

            align-items:
                center;

            justify-content:
                center;

            padding:
                20px;

        }


        .appt-modal-overlay.open {

            display:
                flex;

        }


        .appt-modal-box {

            background:
                #ffffff;

            border-radius:
                16px;

            border:
                1px solid #C9E9DA;

            max-width:
                480px;

            width:
                100%;

            overflow:
                visible;

            box-shadow:
                0 18px 45px rgba(0, 0, 0, .25);

        }


        #histDetailsOverlay .appt-modal-box {

            max-width:
                800px;

        }


        .appt-modal-head {

            display:
                flex;

            align-items:
                center;

            justify-content:
                space-between;

            padding:
                16px 20px;

            background:
                linear-gradient(135deg, #149A72 0%, #0A4A41 100%);

            border-bottom:
                none;

        }


        .appt-modal-head h5 {

            margin:
                0;

            color:
                #ffffff;

            font-family:
                "Plus Jakarta Sans", sans-serif;

            font-size:
                16px;

            font-weight:
                800;

        }


        .appt-modal-x {

            border:
                1px solid #D1E7DD;

            background:
                #ffffff;

            color:
                #57727d;

            width:
                30px;

            height:
                30px;

            border-radius:
                9px;

            cursor:
                pointer;

        }


        .appt-modal-x:hover {

            background:
                #EAF7F0;

            color:
                #0E6B5E;

        }


        .appt-modal-body {

            padding:
                18px 20px 20px;

            font-size:
                13.5px;

            color:
                #4d6873;

        }


        .appt-modal-body .row-line {

            margin-bottom:
                12px;

            padding-bottom:
                12px;

            border-bottom:
                1px dashed #D6E7E1;

        }

        .appt-modal-body .row-line:last-child {

            margin-bottom:
                0;

            padding-bottom:
                0;

            border-bottom:
                none;

        }

        .appt-modal-body .row-line strong {

            display:
                block;

            font-size:
                11px;

            text-transform:
                uppercase;

            letter-spacing:
                .5px;

            margin-bottom:
                3px;

        }


        .appt-modal-body strong {

            color:
                #143f51;

        }


        /*
        |--------------------------------------------------------------------------
        | NEW: TOP SUMMARY CARDS + 3-COLUMN LAYOUT + CALENDAR + ACTIONS + FILTER
        | Consistent: #0E6B5E / #0A4A41 / #C9A227, rounded 16px, Bootstrap Icons
        |--------------------------------------------------------------------------
        */

        .appt-top-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }

        .appt-top-card {
            background: #ffffff;
            border: 1px solid #C9E9DA;
            border-radius: 16px;
            padding: 16px 18px;
            box-shadow: 0 8px 24px rgba(14,107,94,.08);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .appt-top-icon {
            width: 44px;
            height: 44px;
            border-radius: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .appt-top-icon.today { background: linear-gradient(135deg,#D9F2E6,#B9E4CF); color: #0E6B5E; }
        .appt-top-icon.upcoming { background: linear-gradient(135deg,#FFF3D9,#F5D48A); color: #b45309; }
        .appt-top-icon.completed { background: linear-gradient(135deg,#E6F4FB,#BFE3F2); color: #157ca0; }
        .appt-top-icon.cancelled { background: linear-gradient(135deg,#FDECEC,#F5C6C6); color: #b43b3b; }

        .appt-top-num {
            font-family: "Plus Jakarta Sans", sans-serif;
            font-size: 22px;
            font-weight: 800;
            color: #143f51;
            line-height: 1;
        }

        .appt-top-label {
            font-size: 11.5px;
            font-weight: 700;
            color: #8499a1;
            margin-top: 3px;
        }

        .appt-main-grid {
            display: grid;
            grid-template-columns: 1fr 1.4fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
            align-items: start;
        }

        .appt-panel {
            background: #ffffff;
            border: 1px solid #C9E9DA;
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(14,107,94,.08);
            overflow: hidden;
        }

        .appt-panel-head {
            padding: 15px 18px;
            border-bottom: 1px solid #0A4A41;
            background: linear-gradient(135deg, #0E6B5E, #0A4A41);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .appt-panel-head h3 {
            margin: 0;
            font-family: "Plus Jakarta Sans", sans-serif;
            font-size: 14.5px;
            font-weight: 800;
            color: #ffffff;
        }

        .appt-panel-head small {
            display: block;
            font-size: 11px;
            color: rgba(255,255,255,.82);
            font-weight: 600;
        }

        .appt-panel-body { padding: 10px 12px; }

        .mini-cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
            margin-top: 6px;
        }

        .mini-cal-dow {
            text-align: center;
            font-size: 9px;
            font-weight: 700;
            color: #8499a1;
            padding: 2px 0;
        }

        .mini-cal-day {
            aspect-ratio: 1;
            border: none;
            border-radius: 50%;
            font-size: 11px;
            font-weight: 600;
            color: #4d6873;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1px;
            background: transparent;
            min-height: 26px;
            max-width: 32px;
            margin: 0 auto;
            width: 100%;
        }

        .mini-cal-day.empty { border: none; background: transparent; }
        .mini-cal-day.today { background: linear-gradient(135deg,#149A72,#0A4A41); color: #fff; font-weight: 800; }
        .mini-cal-day.has-appt { background: transparent; border: none; color: #0E6B5E; font-weight: 800; }
        .mini-cal-day.has-appt.today { background: linear-gradient(135deg,#149A72,#0A4A41); color: #fff; }
        .mini-cal-dot { width: 4px; height: 4px; border-radius: 50%; margin-top: 1px; }
        .mini-cal-dot.pending { background: #b45309; }
        .mini-cal-dot.confirmed { background: #17844d; }
        .mini-cal-dot.completed { background: #157ca0; }
        .mini-cal-dot.cancelled { background: #b43b3b; }
        .mini-cal-dot.noshow { background: #6b7280; }
        .mini-cal-dot.default { background: #0E6B5E; }

        .appt-legend { display: flex; flex-wrap: wrap; gap: 6px 10px; margin-top: 10px; font-size: 10px; color: #57727d; font-weight: 600; }
        .appt-legend span { display: inline-flex; align-items: center; gap: 5px; }

        .appt-list-item {
            border: 1px solid #E6F2EC;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 10px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            background: #ffffff;
        }

        .appt-list-item:last-child { margin-bottom: 0; }

        .appt-time {
            flex-shrink: 0;
            min-width: 78px;
            text-align: center;
            background: #EAF7F0;
            border: 1px solid #C9E9DA;
            border-radius: 10px;
            padding: 8px 6px;
            color: #0E6B5E;
            font-weight: 800;
            font-size: 12px;
        }

        .appt-list-name { font-weight: 800; color: #143f51; font-size: 13.5px; }
        .appt-list-meta { font-size: 12px; color: #57727d; margin-top: 3px; }

        .quick-action-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            text-decoration: none;
            border: 1px solid #C9E9DA;
            background: #F6FBF8;
            color: #143f51;
            border-radius: 12px;
            padding: 11px 13px;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 9px;
        }

        .quick-action-btn:hover { background: #EAF7F0; color: #0E6B5E; }
        .quick-action-btn i { font-size: 17px; color: #0E6B5E; }

        .filter-label { font-size: 12px; font-weight: 700; color: #334554; margin: 10px 0 5px; display: block; }
        .filter-input {
            width: 100%;
            border: 1px solid #D1E7DD;
            border-radius: 9px;
            padding: 8px 10px;
            font-size: 12.5px;
            font-family: "Inter", system-ui, sans-serif;
            color: #4d6873;
            background: #ffffff;
        }

        .filter-btn {
            width: 100%;
            margin-top: 12px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #149A72, #0A4A41);
            color: #ffffff;
            font-weight: 800;
            font-size: 13px;
            padding: 10px;
            cursor: pointer;
        }

        .filter-clear {
            display: block;
            text-align: center;
            margin-top: 8px;
            font-size: 12px;
            font-weight: 700;
            color: #0E6B5E;
        }

        @media (max-width: 1100px) {
            .appt-top-cards { grid-template-columns: repeat(2, 1fr); }
            .appt-main-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 600px) {
            .appt-top-cards { grid-template-columns: 1fr; }
        }


        /*
        |--------------------------------------------------------------------------
        | QUEUE LOOK PER REQUEST (appointment page only, portal.css untouched)
        | Taas: white | Body: green (katulad ng queue page)
        |--------------------------------------------------------------------------
        */

        body {
            background: linear-gradient(135deg, #022c22, #059669, #065f46, #10b981, #022c22) !important;
            background-size: 300% 300% !important;
            animation: dentistDashBg 8s ease infinite !important;
        }

        @keyframes dentistDashBg {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .portal-main {
            background: transparent !important;
        }

        .portal-content {
            background: transparent !important;
            padding-top: 16px !important;
        }

        .sumcards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin: 0 0 18px;
            align-items: stretch;
        }

        .sumcard {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg,#047857 0%,#059669 55%,#10B981 100%);
            border: 1px solid rgba(255,255,255,.28);
            border-radius: 14px;
            padding: 12px 14px;
            box-shadow: 0 16px 34px rgba(5,150,105,.35);
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 3px;
            height: 100%;
            min-height: 96px;
            box-sizing: border-box;
            color: #ffffff;
            transition: .25s ease;
        }
        .sumcard::before,
        .sumcard::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }
        .sumcard::before {
            width: 130px;
            height: 130px;
            top: -60px;
            right: -45px;
            background: radial-gradient(circle,rgba(255,255,255,.22),rgba(255,255,255,0) 70%);
        }
        .sumcard::after {
            width: 100px;
            height: 100px;
            bottom: -48px;
            left: -32px;
            background: radial-gradient(circle,rgba(255,255,255,.16),rgba(255,255,255,0) 70%);
        }
        .sumcard > * {
            position: relative;
            z-index: 1;
        }
        .sumcard:hover {
            transform: translateY(-3px);
            box-shadow: 0 22px 42px rgba(5,150,105,.45);
        }

        .sumcard-head {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
        }

        .sumcard-icon {
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

        .sumcard-num {
            font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif;
            font-size: 27px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1;
            margin-top: auto;
            padding-top: 4px;
            font-variant-numeric: tabular-nums;
            text-shadow: 0 6px 18px rgba(0,0,0,.22);
        }

        .sumcard-label {
            font-size: 13px;
            font-weight: 700;
            color: rgba(255,255,255,.92);
            flex: 1;
            min-width: 0;
        }

        .sumcard-sub {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11.5px;
            font-weight: 600;
            color: rgba(255,255,255,.86);
            margin-top: 3px;
        }
        .sumcard-sub::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #A7F3D0;
            box-shadow: 0 0 0 4px rgba(167,243,208,.25);
            animation: sumcardDotPulse 1.9s ease-in-out infinite;
            flex-shrink: 0;
        }
        @keyframes sumcardDotPulse {
            0%,100% { opacity: 1; transform: scale(1); }
            50% { opacity: .5; transform: scale(.8); }
        }

        /* Panel ng listahan — nasa labas ng .sumcards grid para hindi masira ang cards */
        .sumcard-list-panel {
            background: #ffffff;
            border: 1px solid #C9E9DA;
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(14,107,94,.08);
            margin: -6px 0 18px;
            overflow: hidden;
        }

        .sumcard-list-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 20px;
            background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%);
            border-bottom: 1px solid #C9E9DA;
        }

        .sumcard-list-head h4 {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            color: #143f51;
            font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: -.02em;
        }

        .sumcard-list-head h4 i {
            color: #0E6B5E;
            font-size: 16px;
        }

        .sumcard-list-head small {
            color: #57727d;
            font-size: 12px;
            font-weight: 600;
        }

        .sumcard-list-scroll {
            max-height: 320px;
            overflow: auto;
            scrollbar-width: thin;
        }

        .sumcard-list-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .sumcard-list-table thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #F6FBF8;
            color: #57727d;
            font-family: "Inter", system-ui, sans-serif;
            font-size: 10.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
            text-align: left;
            padding: 10px 16px;
            border-bottom: 1px solid #E6F2EC;
            white-space: nowrap;
        }

        .sumcard-list-table tbody td {
            padding: 11px 16px;
            border-bottom: 1px solid #F1F7F4;
            color: #4d6873;
            font-family: "Inter", "DM Sans", system-ui, sans-serif;
            font-size: 12.5px;
            vertical-align: middle;
        }

        .sumcard-list-table tbody tr:last-child td { border-bottom: none; }
        .sumcard-list-table tbody tr:hover td { background: #F6FBF8; }
        .sumcard-list-table tbody td strong { color: #143f51; font-weight: 700; }
        .sumcard-list-table .sumlist-when { white-space: nowrap; color: #57727d; }

        .sumcard-list-table .sumlist-badge {
            display: inline-block;
            border-radius: 20px;
            padding: 4px 11px;
            font-size: 11px;
            font-weight: 700;
            font-family: "Inter", system-ui, sans-serif;
            white-space: nowrap;
        }

        .sumlist-badge.completed { background: #E6F4FB; color: #157ca0; border: 1px solid #BFE3F2; }
        .sumlist-badge.no-show   { background: #fff3d9; color: #c98609; border: 1px solid #F5D48A; }
        .sumlist-badge.cancelled { background: #FDECEC; color: #b43b3b; border: 1px solid #F5C6C6; }

        .sumcard-list-empty {
            padding: 28px 20px;
            text-align: center;
            color: #8aa0a8;
            font-size: 13px;
        }

        @media (max-width: 1100px) {
            .sumcards { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 600px) {
            .sumcards { grid-template-columns: 1fr; }
        }

        .portal-topbar {
            background: #ffffff !important;
            border-bottom: 1px solid #e8eef2 !important;
            height: auto !important;
            min-height: 90px !important;
            padding-top: 12px !important;
            padding-bottom: 12px !important;
            gap: 16px !important;
            flex-wrap: wrap !important;
            align-items: center !important;
        }

        .portal-topbar > div:first-child {
            justify-content: center !important;
        }

        .portal-topbar > div:last-child {
            display: flex !important;
            align-items: center !important;
            align-self: center !important;
        }

        .topbar-today,
        .topbar-time {
            align-items: center !important;
            align-self: center !important;
        }

        .portal-topbar > div:first-child {
            display: flex !important;
            flex-direction: column !important;
            align-items: flex-start !important;
            gap: 2px !important;
            min-width: 0 !important;
        }

        .portal-topbar .topbar-back {
            margin-bottom: 4px !important;
        }

        .portal-topbar .portal-heading {
            line-height: 1.2 !important;
        }

        .portal-topbar .portal-subtitle {
            margin-top: 2px !important;
        }

        .portal-topbar > div:last-child {
            flex-wrap: wrap !important;
            justify-content: flex-end !important;
            row-gap: 8px !important;
        }

        .portal-heading {
            color: #153f50 !important;
        }

        .portal-subtitle {
            color: #8499a1 !important;
        }

        .portal-user,
        .portal-role {
            color: #244f60 !important;
        }

        .topbar-today {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #244f60;
            border-left: 1px solid #e8eef2;
            padding-left: 16px;
        }

        .topbar-today i {
            font-size: 22px;
        }

        .topbar-today strong {
            display: block;
            font-size: 13px;
            font-weight: 800;
            line-height: 1.2;
        }

        .topbar-today small {
            display: block;
            font-size: 11px;
            opacity: .85;
            font-weight: 600;
        }

        .topbar-time {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #244f60;
            border-left: 1px solid #e8eef2;
            padding-left: 16px;
        }

        .topbar-time i {
            font-size: 22px;
        }

        .topbar-time strong {
            display: block;
            font-size: 13px;
            font-weight: 800;
            line-height: 1.2;
        }

        .topbar-time small {
            display: block;
            font-size: 11px;
            opacity: .85;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }

        .topbar-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: linear-gradient(135deg,#149A72,#0A4A41);
            border: 1.5px solid #C9A227;
            color: #ffffff;
            font-weight: 700;
            font-size: 12px;
            padding: 7px 14px;
            border-radius: 10px;
            text-decoration: none;
            margin-bottom: 6px;
            box-shadow: 0 8px 20px rgba(201,162,39,.35);
            font-family: 'Plus Jakarta Sans','Inter',system-ui,sans-serif;
        }

        .topbar-back:hover {
            filter: brightness(.93);
            color: #ffffff;
        }

        .cal-white .appt-panel-head {
            background: #ffffff !important;
            border-bottom: 1px solid #E6F2EC !important;
        }

        .cal-white .appt-panel-head h3 {
            color: #143f51 !important;
        }

        .cal-white .appt-panel-head small {
            color: #8499a1 !important;
        }

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

        .month-subbar {
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: 8px;
            background: #ffffff;
            border-bottom: 1px solid #E6F2EC;
            color: #0E6B5E;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: .3px;
            padding: 8px 12px;
        }

        .cal-white .appt-panel-head {
            position: static !important;
            flex-direction: column !important;
            align-items: stretch !important;
            gap: 4px !important;
            text-align: left !important;
        }

        .appt-panel.cal-white {
            max-width: calc(25% - 11px) !important;
            min-width: 250px !important;
        }

        @media (max-width: 900px) {
            .appt-panel.cal-white {
                max-width: 100% !important;
            }
        }

        .cal-head-left {
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
        }

        .cal-head-month-below {
            text-align: center !important;
            font-family: "Plus Jakarta Sans", sans-serif !important;
            font-size: 14.5px !important;
            font-weight: 800 !important;
            color: #143f51 !important;
        }

        .cal-head-month-nav {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 14px !important;
            font-family: "Plus Jakarta Sans", sans-serif !important;
            font-size: 14.5px !important;
            font-weight: 800 !important;
            color: #143f51 !important;
        }

        .cal-nav-arrow {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 28px !important;
            height: 28px !important;
            border-radius: 50% !important;
            border: 1px solid #C9E9DA !important;
            background: #EAF7F0 !important;
            color: #0E6B5E !important;
            text-decoration: none !important;
            font-size: 14px !important;
        }

        .cal-nav-arrow:hover {
            background: #0E6B5E !important;
            color: #ffffff !important;
        }

        .month-subbar i {
            font-size: 15px;
        }

    </style>
<style>
/* ===== READABILITY (appointments page only, darker muted text) ===== */
.portal-subtitle{color:#334554 !important;font-weight:600 !important}
.portal-main small{color:#3f5a67 !important}
.portal-main [style*="#8499a1"]{color:#334554 !important}
.portal-main [style*="#8ca0a8"]{color:#3f5a67 !important}
.portal-main [style*="#7f9aa4"]{color:#334554 !important}
.portal-main .appt-list-meta{color:#334554 !important}
.portal-main .appt-legend{color:#334554 !important}
.portal-heading{color:#102f3d !important}
</style>


</head>


<body>


<!--
|--------------------------------------------------------------------------
| SIDEBAR
|--------------------------------------------------------------------------
-->

<?php
$dentistActivePage = 'appointments';
include __DIR__ . '/dentist_sidebar.php';
?>



<!--
|--------------------------------------------------------------------------
| MAIN CONTENT
|--------------------------------------------------------------------------
-->

<main class="portal-main">


    <!-- MINI CLINIC BAR -->

    <div class="clinic-minibar">
        🦷 <span>Abalos Dental Clinic</span>
    </div>

    <!-- TOP BAR -->

    <header class="portal-topbar">


        <div>

            <div class="portal-heading">
                Appointments
            </div>


            <div class="portal-subtitle">
                Patient appointments assigned to you
            </div>

        </div>


        <div style="display:flex;align-items:center;gap:16px;">

            <div class="topbar-today" style="border-left:none;padding-left:0;">

                <i class="bi bi-calendar-event-fill"></i>

                <div>
                    <strong><?= dentistEscape(date('l')) ?></strong>
                    <small><?= dentistEscape(date('M d, Y')) ?></small>
                </div>

            </div>


            <div class="topbar-time">

                <i class="bi bi-clock-fill"></i>

                <div>
                    <strong>Clinic Time</strong>
                    <small id="clinicTime">--:--:--</small>
                </div>

            </div>

        </div>


    </header>

    <script>
    (function () {
        function tickClinicTime() {
            try {
                var el = document.getElementById('clinicTime');
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
        tickClinicTime();
        setInterval(tickClinicTime, 1000);
})();
</script>


<!-- DENTIST LOGOUT CONFIRM — "Are you sure you want to log out?" -->




    <!-- CONTENT -->

    <div class="portal-content">

        <div style="margin:0 0 8px;text-align:left;display:flex;gap:8px;flex-wrap:wrap;">
            <?php if (($_GET['from'] ?? '') === 'notifications'): ?>
            <a href="notifications.php" data-tip="Back to Notifications" style="display:inline-flex;align-items:center;gap:6px;background:#ffffff;border:1.5px solid #0E6B5E;color:#0E6B5E;font-weight:700;font-size:13px;padding:9px 16px;border-radius:10px;text-decoration:none;box-shadow:0 4px 12px rgba(14,107,94,.15);font-family:'Plus Jakarta Sans','Inter',system-ui,sans-serif;">
                <i class="bi bi-bell-fill"></i>Back to Notifications
            </a>
            <?php endif; ?>
        </div>

        <?php if (!empty($highlightedAppt)): ?>
        <div id="notifHighlight" data-appointment-id="<?= (int)$highlightedAppt['id'] ?>" style="margin:0 0 14px;background:#EAF7F0;border:2px solid #0E6B5E;border-radius:14px;padding:14px 16px;box-shadow:0 10px 26px rgba(14,107,94,.18);">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <span style="display:inline-flex;align-items:center;gap:6px;background:#0E6B5E;color:#fff;font-size:11px;font-weight:800;letter-spacing:.5px;padding:5px 11px;border-radius:999px;"><i class="bi bi-bell-fill"></i>FROM NOTIFICATION</span>
                <strong style="color:#143f51;font-size:14.5px;"><?= dentistEscape(trim($highlightedAppt['patient_name'] ?? 'Unknown Patient')) ?> · <?= dentistEscape($highlightedAppt['service_type'] ?? '') ?></strong>
                <span style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:#0E6B5E;background:#fff;border:1px solid #0E6B5E;border-radius:999px;padding:5px 12px;"><i class="bi bi-calendar-event-fill"></i><?= dentistEscape(date('M d, Y', strtotime($highlightedAppt['appointment_date']))) ?> · <?= dentistEscape(date('h:i A', strtotime($highlightedAppt['appointment_time']))) ?> · <?= dentistEscape($highlightedAppt['status'] ?? '') ?></span>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['saved'])): ?>
        <div style="margin:0 0 12px;background:#EAF7F0;border:1.5px solid #0E6B5E;border-radius:12px;padding:11px 16px;color:#0A4A41;font-size:13px;font-weight:700;"><i class="bi bi-check-circle-fill"></i> Appointment status updated successfully.</div>
        <?php endif; ?>
        <?php if (isset($_GET['save_failed'])): ?>
        <div style="margin:0 0 12px;background:#FDECEC;border:1.5px solid #b42318;border-radius:12px;padding:11px 16px;color:#7a271a;font-size:13px;font-weight:700;"><i class="bi bi-exclamation-triangle-fill"></i> Failed to save status — please try again or contact the administrator.</div>
        <?php endif; ?>


        <!-- NEW SUMMARY CARDS (4) -->

        <div class="sumcards">

            <div class="sumcard">
                <div class="sumcard-head">
                    <div class="sumcard-icon"><i class="bi bi-calendar-event-fill"></i></div>
                    <div class="sumcard-label">Today's Appointment</div>
                </div>
                <div class="sumcard-num"><?= (int) $todayCount ?></div>
                <div class="sumcard-sub">Scheduled for today</div>
            </div>

            <div class="sumcard">
                <div class="sumcard-head">
                    <div class="sumcard-icon"><i class="bi bi-calendar2-week-fill"></i></div>
                    <div class="sumcard-label">Upcoming Appointment</div>
                </div>
                <div class="sumcard-num"><?= (int) $upcomingCount ?></div>
                <div class="sumcard-sub">Scheduled for upcoming days</div>
            </div>

            <div class="sumcard">
                <div class="sumcard-head">
                    <div class="sumcard-icon"><i class="bi bi-check-circle-fill"></i></div>
                    <div class="sumcard-label">Completed</div>
                </div>
                <div class="sumcard-num"><?= (int) $completedCountAppt ?></div>
                <div class="sumcard-sub">Successfully attended</div>
            </div>

            <div class="sumcard">
                <div class="sumcard-head">
                    <div class="sumcard-icon"><i class="bi bi-x-circle-fill"></i></div>
                    <div class="sumcard-label">Cancelled / No-Show</div>
                </div>
                <div class="sumcard-num"><?= (int) $cancelNoShowCount ?></div>
                <div class="sumcard-sub">Cancelled / Missed</div>
            </div>

        </div>


        <!-- LISTAHAN NG COMPLETED (lahat ng petsa) -->

        <div class="sumcard-list-panel" id="sumListCompleted" style="display:none;">

            <div class="sumcard-list-head">
                <h4><i class="bi bi-check-circle-fill"></i> Completed Appointments</h4>
                <small><?= (int) $completedCountAppt ?> record</small>
            </div>

            <?php if (empty($completedListAppt)): ?>

            <div class="sumcard-list-empty">No completed appointments yet.</div>

            <?php else: ?>

            <div class="sumcard-list-scroll">
                <table class="sumcard-list-table">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Service</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($completedListAppt as $rowComp): ?>
                        <?php
                        $compName = trim((string)($rowComp['patient_name'] ?? ''));
                        if ($compName === '') { $compName = 'Unknown Patient'; }
                        $compDate = trim((string)($rowComp['appointment_date'] ?? ''));
                        $compStart = trim((string)($rowComp['start_time'] ?? ''));
                        ?>
                        <tr>
                            <td><strong><?= dentistEscape($compName) ?></strong></td>
                            <td class="sumlist-when"><?= $compDate !== '' ? dentistEscape(date('M d, Y', strtotime($compDate))) : '—' ?></td>
                            <td class="sumlist-when"><?= $compStart !== '' ? dentistEscape(date('h:i A', strtotime($compStart))) : '—' ?></td>
                            <td><?= dentistEscape($rowComp['service_type'] ?? '—') ?></td>
                            <td><span class="sumlist-badge completed"><?= dentistEscape($rowComp['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php endif; ?>

        </div>


        <!-- LISTAHAN NG NO-SHOW + CANCELLED (lahat ng petsa) -->

        <div class="sumcard-list-panel" id="sumListNoShow" style="display:none;">

            <div class="sumcard-list-head">
                <h4><i class="bi bi-x-circle-fill"></i> Cancelled / No-Show</h4>
                <small>
                    <?= (int) $noShowCountAppt ?> no-show
                    <?= $cancelledCountAppt > 0 ? ' &middot; ' . (int) $cancelledCountAppt . ' cancelled' : '' ?>
                </small>
            </div>

            <?php if (empty($cancelNoShowListAppt)): ?>

            <div class="sumcard-list-empty">No cancelled or no-show appointments yet.</div>

            <?php else: ?>

            <div class="sumcard-list-scroll">
                <table class="sumcard-list-table">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Service</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($cancelNoShowListAppt as $rowNS): ?>
                        <?php
                        $nsName = trim((string)($rowNS['patient_name'] ?? ''));
                        if ($nsName === '') { $nsName = 'Unknown Patient'; }
                        $nsDate = trim((string)($rowNS['appointment_date'] ?? ''));
                        $nsStart = trim((string)($rowNS['start_time'] ?? ''));
                        $nsStatus = strtolower(trim((string)($rowNS['status'] ?? '')));
                        $nsBadge = ($nsStatus === 'no-show' || $nsStatus === 'no show') ? 'no-show' : 'cancelled';
                        ?>
                        <tr>
                            <td><strong><?= dentistEscape($nsName) ?></strong></td>
                            <td class="sumlist-when"><?= $nsDate !== '' ? dentistEscape(date('M d, Y', strtotime($nsDate))) : '—' ?></td>
                            <td class="sumlist-when"><?= $nsStart !== '' ? dentistEscape(date('h:i A', strtotime($nsStart))) : '—' ?></td>
                            <td><?= dentistEscape($rowNS['service_type'] ?? '—') ?></td>
                            <td><span class="sumlist-badge <?= $nsBadge ?>"><?= dentistEscape($rowNS['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php endif; ?>

        </div>


        <!-- TODAY + CALENDAR ROW: Calendar=1 card kaliwa, Today=3 cards kanan -->
        <style>
            .today-cal-row {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 14px;
                margin-bottom: 18px;
                align-items: stretch;
            }
            .today-cal-row #today {
                grid-column: span 4;
                margin-bottom: 0 !important;
                min-width: 0;
                order: 2;
                height: 100%;
            }
            .today-cal-row #apptCalendar {
                display: none !important;
            }
            .today-cal-row .appt-panel{margin-bottom:0 !important;height:100%;box-sizing:border-box}
            #upcoming.appt-panel{width:auto;margin:0 0 24px 0;box-sizing:border-box}
            /* Alis ang mga sariling override ng #today — para eksaktong
               kopyahin ang itsura at sukat ng #upcoming at #history. */
            /* ===== ADMIN PARITY: bawat section ay hiwalay na card =====
               Itutulad ang itsura ng admin/appointments.php:
               sariling border, rounded corners, gradient header, at spacing. */
            #today.appt-panel.section-tab,
            #upcoming.appt-panel.section-tab,
            #history.appt-panel.section-tab {
                background: #ffffff !important;
                border: 1px solid #C9E9DA !important;
                border-radius: 16px !important;
                box-shadow: 0 8px 24px rgba(14,107,94,.08) !important;
                overflow: hidden !important;
                margin: 0 0 24px 0 !important;
            }
            .appt-panel.section-tab > .appt-panel-head {
                background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%) !important;
                border-bottom: 1px solid #C9E9DA !important;
                padding: 18px 22px !important;
                display: flex !important;
                align-items: center !important;
                gap: 12px !important;
            }
            .appt-panel.section-tab > .appt-panel-head h3 {
                font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif !important;
                font-size: 17px !important;
                font-weight: 800 !important;
                color: #143f51 !important;
                margin: 0 !important;
                display: flex !important;
                align-items: center !important;
                gap: 8px !important;
            }
            .appt-panel.section-tab > .appt-panel-head small {
                display: block !important;
                font-size: 12px !important;
                font-weight: 500 !important;
                color: #4A5F68 !important;
                margin: 6px 0 0 26px !important;
            }
            /* Pareho ang icon ng tatlo — gradient na #D9F2E6 -> #B9E4CF */
            .appt-panel.section-tab > .appt-panel-head .appt-top-icon {
                background: linear-gradient(135deg,#D9F2E6,#B9E4CF) !important;
                color: #0E6B5E !important;
                border-radius: 12px;
            }
            .appt-panel.section-tab > .appt-panel-head .section-count {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                min-width: 26px !important;
                height: 26px !important;
                padding: 0 8px !important;
                border-radius: 20px !important;
                background: #0E6B5E !important;
                color: #fff !important;
                font-size: 12.5px !important;
                font-weight: 800 !important;
            }
            .appt-panel.section-tab > .appt-panel-head .tab-chevron {
                color: #0E6B5E !important;
                font-size: 18px !important;
                transition: transform .22s ease;
            }
            .appt-panel.section-tab:not(.open) > .appt-panel-head .tab-chevron {
                transform: rotate(-90deg);
            }
            .appt-panel.section-tab > .appt-panel-body {
                border-top: 1px solid #C9E9DA;
            }
            /* Change Status button — pareho sa admin */
            .moved-actions .status-action-btn {
                background: #EAF7F0 !important;
                border: 1.5px solid #0E6B5E !important;
                color: #0E6B5E !important;
                font-weight: 800 !important;
                border-radius: 999px !important;
                padding: 6px 14px !important;
                font-size: 12px !important;
                white-space: nowrap;
            }
            .moved-actions .status-action-btn:hover {
                background: #0E6B5E !important;
                color: #fff !important;
            }
            .moved-actions {
                display: flex !important;
                align-items: center !important;
                gap: 8px !important;
                flex-wrap: wrap !important;
            }
            @media (max-width: 575px) {
                #today.appt-panel.section-tab,
                #upcoming.appt-panel.section-tab,
                #history.appt-panel.section-tab {
                    margin-left: 0 !important;
                    margin-right: 0 !important;
                }
            }
            @media (max-width: 1100px) {
                .today-cal-row { grid-template-columns: 1fr; }
                .today-cal-row #today,
                .today-cal-row #apptCalendar { grid-column: span 1; order: initial; }
            }
        </style>
        <div class="today-cal-row">

        <section class="appt-panel section-tab<?= isset($_GET['up']) || isset($_GET['hist']) ? '' : ' open' ?>" id="today">

            <div class="appt-panel-head appt-acc-head">
                <div class="appt-top-icon today"><i class="bi bi-calendar-event-fill"></i></div>
                <div style="flex:1;min-width:0;">
                    <h3>Today's Appointment</h3>
                    <small><?= dentistEscape(date('M d, Y')) ?> · <?= count($filteredTodayAppointments) ?> shown</small>
                </div>
                <span class="section-count"><?= (int) $todayCount ?></span>
                <i class="bi bi-chevron-down tab-chevron"></i>
            </div>

            <div class="appt-panel-body" style="padding:0;">
                <?php if (empty($filteredTodayAppointments)): ?>
                    <div class="moved-empty">No appointments for today<?= $filterStatus !== '' ? ' with status ' . dentistEscape($filterStatus) : '' ?>.</div>
                <?php else: ?>
                    <div class="today-scroll" style="overflow:auto;max-height:560px;">
                        <table class="portal-table today-table">
                            <thead>
                                <tr>
                                    <th class="col-num">#</th>
                                    <th>Patient Name</th>
                                    <th>Time of appointment</th>
                                    <th>Dental Service</th>
                                    <th>Assigned dentist</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $todayRowNum = (($todayPage['page'] - 1) * 10) + 1; foreach ($todayPageItems as $todayAppt): ?>
                                    <?php
                                    $todayPatientName = trim($todayAppt['patient_name'] ?? '');
                                    if ($todayPatientName === '') {
                                        $todayPatientName = 'Unknown Patient';
                                    }
                                    ?>
                                    <tr data-appointment-id="<?= (int) $todayAppt['id'] ?>">
                                        <td class="col-num"><span><?= $todayRowNum++ ?></span></td>
                                        <td><strong><?= dentistEscape($todayPatientName) ?></strong></td>
                                        <td>
                                            <?= dentistEscape(date('h:i A', strtotime($todayAppt['appointment_time']))) ?>
                                            -
                                            <?= dentistEscape(date('h:i A', strtotime($todayAppt['end_time']))) ?>
                                        </td>
                                        <td><?= dentistEscape($todayAppt['service_type']) ?></td>
                                        <td><?= dentistEscape($dentistName ?? '') ?></td>
                                        <td><span class="portal-status <?= dentistEscape(strtolower($todayAppt['status'])) ?>"><?= dentistEscape($todayAppt['status'] ?? '') ?></span></td>
                                        <td>
                                            <div class="moved-actions">
                                                <button
                                                    type="button"
                                                    class="moved-view-btn"
                                                    data-tip="View Details — <?= dentistEscape($todayAppt['patient_name']) ?>"
                                                    data-patient="<?= dentistEscape($todayAppt['patient_name']) ?>"
                                                    data-service="<?= dentistEscape($todayAppt['service_type']) ?>"
                                                    data-dentist="<?= dentistEscape($dentistName ?? '') ?>"
                                                    data-time="<?= dentistEscape(date('M d, Y', strtotime($todayAppt['appointment_date'])) . ' ' . date('h:i A', strtotime($todayAppt['appointment_time'])) . ' - ' . date('h:i A', strtotime($todayAppt['end_time']))) ?>"
                                                    data-booked="<?= !empty($todayAppt['created_at']) ? dentistEscape(date('M d, Y h:i A', strtotime($todayAppt['created_at']))) : '—' ?>"
                                                    data-status="<?= dentistEscape($todayAppt['status']) ?>"
data-notes="<?= dentistEscape($todayAppt['notes'] ?? '') ?>"
>
<i class="bi bi-eye"></i> View
</button>
<button
type="button"
class="status-action-btn"
data-tip="Change Status"
data-appt-id="<?= (int) $todayAppt['id'] ?>"
data-patient="<?= dentistEscape($todayPatientName) ?>"
data-service="<?= dentistEscape($todayAppt['service_type']) ?>"
data-dentist="<?= dentistEscape($dentistName ?? '') ?>"
data-status="<?= dentistEscape($todayAppt['status']) ?>"
data-date="<?= dentistEscape(substr((string) ($todayAppt['appointment_date'] ?? ''), 0, 10)) ?>"
data-start="<?= dentistEscape(substr((string) ($todayAppt['appointment_time'] ?? ''), 0, 5)) ?>"
data-end="<?= dentistEscape(substr((string) ($todayAppt['end_time'] ?? ''), 0, 5)) ?>"
style="cursor:pointer;">
Change Status <i class="bi bi-chevron-down"></i>
</button>
</div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($todayPage['totalPages'] > 1): ?>
                        <?= apptPaginationHtml($todayPage, 'today') ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </section>


        <!-- APPOINTMENT CALENDAR (ordinary) - kaliwa -->

        <div class="appt-panel cal-white" id="apptCalendar" style="margin-bottom:0;">
            <div class="appt-panel-head">
                <div class="cal-head-left">
                    <div class="appt-top-icon today" style="width:38px;height:38px;font-size:17px;"><i class="bi bi-calendar3"></i></div>
                    <h3>Appointment Calendar</h3>
                </div>
                <div class="cal-head-month-nav">
                    <a class="cal-nav-arrow" href="<?= dentistEscape(apptCalMonthUrl($calPrevKey)) ?>" aria-label="Previous month" data-tip="Previous month"><i class="bi bi-chevron-left"></i></a>
                    <span><?= dentistEscape($calViewLabel) ?></span>
                    <a class="cal-nav-arrow" href="<?= dentistEscape(apptCalMonthUrl($calNextKey)) ?>" aria-label="Next month" data-tip="Next month"><i class="bi bi-chevron-right"></i></a>
                </div>
            </div>
            <div class="appt-panel-body">
                <?php
                $ordFirstWeekday = (int) date('w', strtotime($calYear . '-' . sprintf('%02d', $calMonth) . '-01'));
                $ordDaysInMonth = (int) date('t', strtotime($calYear . '-' . sprintf('%02d', $calMonth) . '-01'));
                ?>
                <div class="mini-cal-grid">
                    <?php foreach (['S','M','T','W','T','F','S'] as $dow): ?>
                        <div class="mini-cal-dow"><?= $dow ?></div>
                    <?php endforeach; ?>
                    <?php for ($b = 0; $b < $ordFirstWeekday; $b++): ?>
                        <div class="mini-cal-day empty"></div>
                    <?php endfor; ?>
                    <?php for ($day = 1; $day <= $ordDaysInMonth; $day++): ?>
                        <?php
                        $dateKey = sprintf('%04d-%02d-%02d', $calYear, $calMonth, $day);
                        $isToday = ($dateKey === $calToday);
                        $hasAppt = isset($calendarMap[$dateKey]);
                        $dotClass = $hasAppt ? apptCalDotClass($calendarMap[$dateKey]['statuses']) : '';
                        ?>
                        <div class="mini-cal-day<?= $isToday ? ' today' : '' ?><?= $hasAppt && !$isToday ? ' has-appt' : '' ?>" title="<?= $hasAppt ? ((int) $calendarMap[$dateKey]['count'] . ' appointment(s)') : 'No appointment' ?>">
                            <?= $day ?>
                            <?php if ($hasAppt): ?>
                                <span class="mini-cal-dot <?= dentistEscape($dotClass) ?>"></span>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
                <div class="appt-legend">
                    <span><span class="mini-cal-dot pending"></span>Pending</span>
                    <span><span class="mini-cal-dot confirmed"></span>Confirmed</span>
                    <span><span class="mini-cal-dot completed"></span>Completed</span>
                    <span><span class="mini-cal-dot cancelled"></span>Cancelled</span>
                    <span><span class="mini-cal-dot noshow"></span>No-Show</span>
                </div>
            </div>
        </div>

        </div>

        <!-- UPCOMING APPOINTMENTS (below Today; auto-move to Today pag araw na nila) -->
        <?php
        $filteredUpcomingAppointments = $upcomingAppointments;
        if ($filterStatus !== '') {
            $filteredUpcomingAppointments = array_values(array_filter($filteredUpcomingAppointments, function ($r) use ($filterStatus) {
                return strcasecmp(trim($r['status'] ?? ''), $filterStatus) === 0;
            }));
        }

        /* Pagination: 10 rows kada page (Upcoming) */
        $upPage = apptPageSlice($filteredUpcomingAppointments, 'up');
        $upPageItems = $upPage['items'];
        ?>
        <section class="appt-panel section-tab<?= isset($_GET['up']) || isset($_GET['hist']) ? ' open' : '' ?>" id="upcoming">
            <div class="appt-panel-head appt-acc-head">
                <div class="appt-top-icon upcoming"><i class="bi bi-calendar2-week-fill"></i></div>
                <div style="flex:1;min-width:0;">
                    <h3>Upcoming Appointments</h3>
                    <small>After today · <?= count($filteredUpcomingAppointments) ?> shown<?= $filterStatus !== '' ? ' with status ' . dentistEscape($filterStatus) : '' ?></small>
                </div>
                <span class="section-count"><?= (int) $upcomingCount ?></span>
                <i class="bi bi-chevron-down tab-chevron"></i>
            </div>
            <div class="appt-panel-body" style="padding:0;">
                <?php if (empty($filteredUpcomingAppointments)): ?>
                    <div class="moved-empty">No upcoming appointments<?= $filterStatus !== '' ? ' with status ' . dentistEscape($filterStatus) : '' ?>.</div>
                <?php else: ?>
                    <div style="overflow:auto;max-height:420px;">
                        <table class="portal-table">
                            <thead>
                                <tr>
                                    <th class="col-num">#</th>
                                    <th>Patient Name</th>
                                    <th>Time of appointment</th>
                                    <th>Dental Service</th>
                                    <th>Assigned dentist</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $upRowNum = (($upPage['page'] - 1) * 10) + 1; foreach ($upPageItems as $upAppt): ?>
                                    <?php
                                    $upPatientName = trim($upAppt['patient_name'] ?? '');
                                    if ($upPatientName === '') { $upPatientName = 'Unknown Patient'; }
                                    ?>
                                    <tr data-appointment-id="<?= (int) $upAppt['id'] ?>">
                                        <td class="col-num"><span><?= $upRowNum++ ?></span></td>
                                        <td><strong><?= dentistEscape($upPatientName) ?></strong></td>
                                        <td><?= dentistEscape(date('M d, Y', strtotime($upAppt['appointment_date'])) . ' ' . date('h:i A', strtotime($upAppt['appointment_time'])) . (!empty($upAppt['end_time']) ? ' - ' . date('h:i A', strtotime($upAppt['end_time'])) : '')) ?></td>
                                        <td><?= dentistEscape($upAppt['service_type']) ?></td>
                                        <td><?= dentistEscape($dentistName ?? '') ?></td>
                                        <td><span class="portal-status <?= dentistEscape(strtolower($upAppt['status'])) ?>"><?= dentistEscape($upAppt['status']) ?></span></td>
                                        <td>
                                            <div class="moved-actions">
                                                <button
                                                    type="button"
                                                    class="moved-view-btn"
                                                    data-tip="View Details — <?= dentistEscape($upPatientName) ?>"
                                                    data-patient="<?= dentistEscape($upPatientName) ?>"
                                                    data-service="<?= dentistEscape($upAppt['service_type']) ?>"
                                                    data-dentist="<?= dentistEscape($dentistName ?? '') ?>"
                                                    data-time="<?= dentistEscape(date('M d, Y h:i A', strtotime(($upAppt['appointment_date'] ?? '') . ' ' . ($upAppt['appointment_time'] ?? '')))) ?>"
                                                    data-booked="<?= !empty($upAppt['created_at']) ? dentistEscape(date('M d, Y h:i A', strtotime($upAppt['created_at']))) : '—' ?>"
                                                    data-status="<?= dentistEscape($upAppt['status']) ?>"
data-notes="<?= dentistEscape($upAppt['notes'] ?? '') ?>"
>
<i class="bi bi-eye"></i> View
</button>
</div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($upPage['totalPages'] > 1): ?>
                        <?= apptPaginationHtml($upPage, 'up') ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
        <?php
        $historyAppointments = array_values(array_filter(($appointments ?? []), function ($r) use ($todayDate) {
            $d = substr(trim($r['appointment_date'] ?? ''), 0, 10);
            $st = strtolower(trim($r['status'] ?? ''));
            return $d < $todayDate || in_array($st, ['completed', 'cancelled', 'canceled', 'no-show', 'no show'], true);
        }));
        usort($historyAppointments, function ($x, $y) {
            $dx = substr(trim($x['appointment_date'] ?? ''), 0, 10);
            $dy = substr(trim($y['appointment_date'] ?? ''), 0, 10);
            if ($dx === $dy) { return strcmp($x['start_time'] ?? '', $y['start_time'] ?? ''); }
            return strcmp($dy, $dx);
        });

        /* Pagination: 10 rows kada page (History) */
        $histPage = apptPageSlice($historyAppointments, 'hist');
        $histPageItems = $histPage['items'];
        ?>
        <section class="appt-panel section-tab<?= isset($_GET['hist']) ? ' open' : '' ?>" id="history">
            <div class="appt-panel-head appt-acc-head">
                <div class="appt-top-icon completed"><i class="bi bi-clock-history"></i></div>
                <div style="flex:1;min-width:0;">
                    <h3>Appointment History</h3>
                    <small>Past and finished appointments · <?= count($historyAppointments) ?> shown</small>
                </div>
                <span class="section-count"><?= count($historyAppointments) ?></span>
                <i class="bi bi-chevron-down tab-chevron"></i>
            </div>
            <div class="appt-panel-body" style="padding:0;">
                <?php if (empty($historyAppointments)): ?>
                    <div class="moved-empty">No appointment history yet.</div>
                <?php else: ?>
                    <div style="overflow:auto;max-height:420px;">
                        <table class="portal-table">
                            <thead>
                                <tr>
                                    <th class="col-num">#</th>
                                    <th>Patient Name</th>
                                    <th>Time of appointment</th>
                                    <th>Dental Service</th>
                                    <th>Assigned dentist</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $histRowNum = (($histPage['page'] - 1) * 10) + 1; foreach ($histPageItems as $histAppt): ?>
                                    <?php
                                    $histPatientName = trim($histAppt['patient_name'] ?? '');
                                    if ($histPatientName === '') { $histPatientName = 'Unknown Patient'; }
                                    ?>
                                    <tr data-appointment-id="<?= (int) $histAppt['id'] ?>">
                                        <td class="col-num"><span><?= $histRowNum++ ?></span></td>
                                        <td><strong><?= dentistEscape($histPatientName) ?></strong></td>
                                        <td><?= dentistEscape(date('M d, Y', strtotime($histAppt['appointment_date'])) . ' ' . date('h:i A', strtotime($histAppt['start_time'])) . (!empty($histAppt['end_time']) ? ' - ' . date('h:i A', strtotime($histAppt['end_time'])) : '')) ?></td>
                                        <td><?= dentistEscape($histAppt['service_type']) ?></td>
                                        <td><?= dentistEscape($dentistName ?? '') ?></td>
                                        <td><span class="portal-status <?= dentistEscape(strtolower($histAppt['status'])) ?>"><?= dentistEscape($histAppt['status']) ?></span></td>
                                        <td>
                                            <div class="moved-actions">
                                                <button
                                                    type="button"
                                                    class="moved-view-btn hist-view-btn"
                                                    data-tip="View Details — <?= dentistEscape($histPatientName) ?>"
                                                    data-service="<?= dentistEscape($histAppt['service_type']) ?>"
                                                    data-dentist="<?= dentistEscape($dentistName ?? '') ?>"
                                                    data-time="<?= dentistEscape(date('M d, Y', strtotime($histAppt['appointment_date'])) . ' ' . date('h:i A', strtotime($histAppt['start_time'])) . (!empty($histAppt['end_time']) ? ' - ' . date('h:i A', strtotime($histAppt['end_time'])) : '')) ?>"
                                                    data-booked="<?= !empty($histAppt['created_at']) ? dentistEscape(date('M d, Y h:i A', strtotime($histAppt['created_at']))) : '—' ?>"
                                                    data-status="<?= dentistEscape($histAppt['status']) ?>"
                                                    data-notes="<?= dentistEscape($histAppt['notes'] ?? '') ?>"
                                                    data-confirmed="<?= dentistEscape(!empty($histAppt['confirmed_at']) ? date('M d, Y h:i A', strtotime($histAppt['confirmed_at'])) : '') ?>"
                                                    data-cancelled="<?= dentistEscape(!empty($histAppt['cancelled_at']) ? date('M d, Y h:i A', strtotime($histAppt['cancelled_at'])) : ($histAppt['status'] === 'Cancelled' && !empty($histAppt['created_at']) ? date('M d, Y h:i A', strtotime($histAppt['created_at'])) : '')) ?>"
                                                    data-checkedin="<?= dentistEscape(!empty($histAppt['checked_in_at']) ? date('M d, Y h:i A', strtotime($histAppt['checked_in_at'])) : '') ?>"
                                                    data-serving="<?= dentistEscape(!empty($histAppt['serving_at']) ? date('M d, Y h:i A', strtotime($histAppt['serving_at'])) : '') ?>"
                                                    data-completed="<?= dentistEscape(!empty($histAppt['completed_at']) ? date('M d, Y h:i A', strtotime($histAppt['completed_at'])) : '') ?>"
data-rescheduled="<?= dentistEscape(!empty($histAppt['rescheduled_at']) ? date('M d, Y h:i A', strtotime($histAppt['rescheduled_at'])) : '') ?>"
>
<i class="bi bi-eye"></i> View
</button>
</div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($histPage['totalPages'] > 1): ?>
                        <?= apptPaginationHtml($histPage, 'hist') ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
        <style>
            /* ===== PARE-PAREHONG SUKAT ng panel head (Today / Upcoming / History) =====
               Dati: #today walang sariling h3/small overrides kaya iba ang taas nito.
               Ngayon: iisang set ng rules para sa tatlo — padding, min-height,
               font-size, at icon — para eksaktong magkapantay ang itsura. */
            #today.appt-panel.section-tab > .appt-panel-head,
            #upcoming.appt-panel.section-tab > .appt-panel-head,
            #history.appt-panel.section-tab > .appt-panel-head {
                min-height: 0;
                box-sizing: border-box;
                padding: 14px 22px !important;
                gap: 12px !important;
            }
            #today.appt-panel.section-tab > .appt-panel-head .appt-top-icon,
            #upcoming.appt-panel.section-tab > .appt-panel-head .appt-top-icon,
            #history.appt-panel.section-tab > .appt-panel-head .appt-top-icon {
                width: 38px !important;
                height: 38px !important;
                font-size: 17px !important;
                flex-shrink: 0;
                border-radius: 12px;
            }
            #today.appt-panel.section-tab > .appt-panel-head h3,
            #upcoming.appt-panel.section-tab > .appt-panel-head h3,
            #history.appt-panel.section-tab > .appt-panel-head h3 {
                font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif !important;
                font-size: 17px !important;
                font-weight: 800 !important;
                line-height: 1.25 !important;
                letter-spacing: .1px !important;
                color: #143f51 !important;
                margin: 0 !important;
            }
            #today.appt-panel.section-tab > .appt-panel-head small,
            #upcoming.appt-panel.section-tab > .appt-panel-head small,
            #history.appt-panel.section-tab > .appt-panel-head small {
                display: block !important;
                font-size: 12px !important;
                font-weight: 600 !important;
                line-height: 1.3 !important;
                color: #334554 !important;
                margin: 3px 0 0 0 !important;
            }
            #today.appt-panel.section-tab > .appt-panel-head .section-count,
            #upcoming.appt-panel.section-tab > .appt-panel-head .section-count,
            #history.appt-panel.section-tab > .appt-panel-head .section-count {
                min-width: 26px !important;
                height: 26px !important;
                padding: 0 8px !important;
                font-size: 12.5px !important;
                flex-shrink: 0;
            }
            #today.appt-panel.section-tab > .appt-panel-head .tab-chevron,
            #upcoming.appt-panel.section-tab > .appt-panel-head .tab-chevron,
            #history.appt-panel.section-tab > .appt-panel-head .tab-chevron {
                font-size: 18px !important;
                flex-shrink: 0;
            }
        </style>
        <style>
            tr[data-appointment-id].selected td{background:#EAF7F0 !important;box-shadow:inset 0 3px 0 #0E6B5E, inset 0 -3px 0 #0E6B5E}
            tr[data-appointment-id].selected td:first-child{box-shadow:inset 4px 0 0 #0E6B5E, inset 0 3px 0 #0E6B5E, inset 0 -3px 0 #0E6B5E}
            .today-scroll{scrollbar-width:thin}
            /* ===== PAGINATION (10 rows kada page) ===== */
            .appt-pagination {
                display: flex;
                align-items: center;
                justify-content: center;
                flex-wrap: wrap;
                gap: 6px;
                padding: 14px 16px;
                border-top: 1px solid #E6F2EC;
                background: #F6FBF8;
            }
            .appt-page-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 4px;
                min-width: 34px;
                height: 34px;
                padding: 0 10px;
                border-radius: 9px;
                border: 1px solid #C9E9DA;
                background: #ffffff;
                color: #0E6B5E;
                font-family: "Inter", system-ui, sans-serif;
                font-size: 12.5px;
                font-weight: 800;
                text-decoration: none;
            }
            .appt-page-btn:hover:not(.disabled):not(.active) { background: #EAF7F0; }
            .appt-page-btn.active { background: #0E6B5E; border-color: #0E6B5E; color: #ffffff; }
            .appt-page-btn.disabled { opacity: .45; cursor: default; }
            #upcoming .appt-panel-head h3{font-weight:800 !important;color:#143f51 !important;font-size:17px !important;letter-spacing:.1px}
            #upcoming .appt-panel-head small{font-weight:700 !important;color:#334554 !important}
            #history .appt-panel-head h3{font-weight:800 !important;color:#143f51 !important;font-size:17px !important;letter-spacing:.1px}
            #history .appt-panel-head small{font-weight:700 !important;color:#334554 !important}
            #upcoming div[style*="max-height:420px"]{scrollbar-width:thin}
        </style>


        <!-- HIDDEN PER REQUEST: Top Summary Cards (old) -->

        <?php if (false): ?>

        <!-- TOP SUMMARY CARDS: quick overview sa isang tingin -->

        <div class="appt-top-cards">

            <div class="appt-top-card">
                <div class="appt-top-icon today"><i class="bi bi-calendar-event-fill"></i></div>
                <div>
                    <div class="appt-top-num"><?= (int) $todayCount ?></div>
                    <div class="appt-top-label">Today's Appointment</div>
                </div>
            </div>

            <div class="appt-top-card">
                <div class="appt-top-icon upcoming"><i class="bi bi-calendar2-week-fill"></i></div>
                <div>
                    <div class="appt-top-num"><?= (int) $upcomingCount ?></div>
                    <div class="appt-top-label">Upcoming Appointment</div>
                </div>
            </div>

            <div class="appt-top-card">
                <div class="appt-top-icon completed"><i class="bi bi-check-circle-fill"></i></div>
                <div>
                    <div class="appt-top-num"><?= (int) $completedCountAppt ?></div>
                    <div class="appt-top-label">Completed</div>
                </div>
            </div>

            <div class="appt-top-card">
                <div class="appt-top-icon cancelled"><i class="bi bi-x-circle-fill"></i></div>
                <div>
                    <div class="appt-top-num"><?= (int) $cancelNoShowCount ?></div>
                    <div class="appt-top-label">Cancelled / No-Show</div>
                </div>
            </div>

        </div>

        <?php endif; /* /HIDDEN: Top Summary Cards */ ?>


        <!-- HIDDEN PER REQUEST: Calendar, Appointment List, Quick Actions, Filter, Today, Upcoming, Details -->

        <?php if (false): ?>

        <!-- MAIN GRID: kaliwa calendar | gitna appointment list | kanan actions + filter -->

        <div class="appt-main-grid">

            <!-- KALIWA: CALENDAR -->

            <div class="appt-panel">
                <div class="appt-panel-head">
                    <div class="appt-top-icon today" style="width:38px;height:38px;font-size:17px;"><i class="bi bi-calendar3"></i></div>
                    <div>
                        <h3>Calendar</h3>
                        <small><?= dentistEscape(date('F Y')) ?> · may tuldok pag may appointment</small>
                    </div>
                </div>
                <div class="appt-panel-body">
                    <?php
                    $firstDay = (int) date('Y-m-01');
                    $firstWeekday = (int) date('w', strtotime($calYear . '-' . sprintf('%02d', $calMonth) . '-01'));
                    $daysInMonth = (int) date('t', strtotime($calYear . '-' . sprintf('%02d', $calMonth) . '-01'));
                    ?>
                    <div class="mini-cal-grid">
                        <?php foreach (['S','M','T','W','T','F','S'] as $dow): ?>
                            <div class="mini-cal-dow"><?= $dow ?></div>
                        <?php endforeach; ?>
                        <?php for ($b = 0; $b < $firstWeekday; $b++): ?>
                            <div class="mini-cal-day empty"></div>
                        <?php endfor; ?>
                        <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                            <?php
                            $dateKey = sprintf('%04d-%02d-%02d', $calYear, $calMonth, $day);
                            $isToday = ($dateKey === $calToday);
                            $hasAppt = isset($calendarMap[$dateKey]);
                            $dotClass = $hasAppt ? apptCalDotClass($calendarMap[$dateKey]['statuses']) : '';
                            ?>
                            <div class="mini-cal-day<?= $isToday ? ' today' : '' ?><?= $hasAppt && !$isToday ? ' has-appt' : '' ?>" title="<?= $hasAppt ? ((int) $calendarMap[$dateKey]['count'] . ' appointment(s)') : 'No appointment' ?>">
                                <?= $day ?>
                                <?php if ($hasAppt): ?>
                                    <span class="mini-cal-dot <?= dentistEscape($dotClass) ?>"></span>
        <?php endif; ?>

                            </div>
                        <?php endfor; ?>
                    </div>
                    <div class="appt-legend">
                        <span><span class="mini-cal-dot pending"></span>Pending</span>
                        <span><span class="mini-cal-dot confirmed"></span>Confirmed</span>
                        <span><span class="mini-cal-dot completed"></span>Completed</span>
                        <span><span class="mini-cal-dot cancelled"></span>Cancelled</span>
                        <span><span class="mini-cal-dot noshow"></span>No-Show</span>
                    </div>
                </div>
            </div>


            <!-- GITNA: APPOINTMENT LIST NGAYONG ARAW -->

            <div class="appt-panel">
                <div class="appt-panel-head">
                    <div class="appt-top-icon upcoming" style="width:38px;height:38px;font-size:17px;"><i class="bi bi-list-check"></i></div>
                    <div>
                        <h3>Appointment List</h3>
                        <small>Today · <?= dentistEscape(date('M d, Y')) ?> · <?= count($filteredTodayAppointments) ?> shown</small>
                    </div>
                </div>
                <div class="appt-panel-body">
                    <?php if (empty($filteredTodayAppointments)): ?>
                        <div class="moved-empty">No appointments for today<?= $filterStatus !== '' ? ' with status ' . dentistEscape($filterStatus) : '' ?>.</div>
                    <?php else: ?>
                        <?php foreach ($filteredTodayAppointments as $tAppt): ?>
                            <?php
                            $dur = apptDurationMins($tAppt['appointment_time'] ?? '', $tAppt['end_time'] ?? '');
                            $stLower = strtolower(trim($tAppt['status'] ?? ''));
                            ?>
                            <div class="appt-list-item">
                                <div class="appt-time">
                                    <?= dentistEscape(date('h:i A', strtotime($tAppt['appointment_time']))) ?>
                                    <div style="font-size:10px;font-weight:700;opacity:.8;"><?= dentistEscape(date('h:i A', strtotime($tAppt['end_time']))) ?></div>
                                </div>
                                <div style="flex:1;min-width:0;">
                                    <div class="appt-list-name"><?= dentistEscape($tAppt['patient_name'] ?? 'Unknown') ?></div>
                                    <div class="appt-list-meta">
                                        <i class="bi bi-briefcase-fill"></i> <?= dentistEscape($tAppt['service_type'] ?? '') ?>
                                        <?php if ($dur > 0): ?> · <?= (int) $dur ?> mins<?php endif; ?>
                                    </div>
                                    <div class="appt-list-meta">
                                        <i class="bi bi-person-fill"></i> <?= dentistEscape($dentistName ?? '') ?>
                                    </div>
                                    <div style="margin-top:6px;">
                                        <span class="portal-status <?= dentistEscape($stLower) ?>"><?= dentistEscape($tAppt['status'] ?? '') ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>


            <!-- KANAN: QUICK ACTIONS + FILTER -->

            <div>
                <div class="appt-panel" style="margin-bottom:16px;">
                    <div class="appt-panel-head">
                        <div class="appt-top-icon completed" style="width:38px;height:38px;font-size:17px;"><i class="bi bi-lightning-charge-fill"></i></div>
                        <div>
                            <h3>Quick Actions</h3>
                            <small>Mabilisang access</small>
                        </div>
                    </div>
                    <div class="appt-panel-body">
                        <a class="quick-action-btn" href="queue.php"><i class="bi bi-list-ol"></i>View Schedule / Queue</a>
                        <a class="quick-action-btn" href="patients.php"><i class="bi bi-people-fill"></i>Patient Information</a>
                        <a class="quick-action-btn" href="notifications.php"><i class="bi bi-bell-fill"></i>Notifications</a>
                    </div>
                </div>

                <div class="appt-panel">
                    <div class="appt-panel-head">
                        <div class="appt-top-icon cancelled" style="width:38px;height:38px;font-size:17px;"><i class="bi bi-funnel-fill"></i></div>
                        <div>
                            <h3>Filter Appointment</h3>
                            <small>Status o date range</small>
                        </div>
                    </div>
                    <div class="appt-panel-body">
                        <form method="GET" action="appointments.php">
                            <label class="filter-label" for="fStatus">Status</label>
                            <select class="filter-input" id="fStatus" name="status">
                                <option value="">All statuses</option>
                                <?php foreach ($allowedFilterStatus as $opt): ?>
                                    <option value="<?= dentistEscape($opt) ?>" <?= $filterStatus === $opt ? 'selected' : '' ?>><?= dentistEscape($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="filter-label" for="fFrom">Date from</label>
                            <input class="filter-input" type="date" id="fFrom" name="date_from" value="<?= dentistEscape($filterFrom) ?>">
                            <label class="filter-label" for="fTo">Date to</label>
                            <input class="filter-input" type="date" id="fTo" name="date_to" value="<?= dentistEscape($filterTo) ?>">
                            <button class="filter-btn" type="submit" data-tip="Apply Filter"><i class="bi bi-search"></i> Apply Filter</button>
                            <?php if ($filterStatus !== '' || $filterFrom !== '' || $filterTo !== ''): ?>
                                <a class="filter-clear" href="appointments.php" data-tip="Clear filter">Clear filter</a>
                            <?php endif; ?>
                        </form>
                        <div style="font-size:11px;color:#8499a1;margin-top:10px;">Filters apply immediately to the list and lower summary tables.</div>
                    </div>
                </div>
            </div>

        </div>



        <!--
        |--------------------------------------------------------------------------
        | SELECTED APPOINTMENT DETAILS
        |--------------------------------------------------------------------------
        -->

        <?php if ($firstAppointment): ?>


            <section
                class="appointment-details"
                id="appointmentDetails"
            >


                <h3>

                    <i class="bi bi-calendar-event-fill"></i>

                    Appointment Details

                </h3>


                <div class="details-grid">


                    <!-- PATIENT NAME -->

                    <div class="detail-item">

                        <label>
                            Patient Name
                        </label>


                        <div
                            class="value"
                            id="viewPatient"
                        >

                            <?= dentistEscape(
                                trim(
                                    $firstAppointment[
                                        'patient_name'
                                    ] ?? ''
                                )
                                ?: 'Unknown Patient'
                            ) ?>

                        </div>

                    </div>


                    <!-- APPOINTMENT TIME -->

                    <div class="detail-item">

                        <label>
                            Appointment Time
                        </label>


                        <div
                            class="value"
                            id="viewTime"
                        >

                            <?= dentistEscape(
                                appointmentTime(
                                    $firstAppointment[
                                        'start_time'
                                    ]
                                )
                                .
                                ' - ' .
                                appointmentTime(
                                    $firstAppointment[
                                        'end_time'
                                    ]
                                )
                            ) ?>

                        </div>

                    </div>


                    <!-- SERVICE -->

                    <div class="detail-item">

                        <label>
                            Dental Service
                        </label>


                        <div
                            class="value"
                            id="viewService"
                        >

                            <?= dentistEscape(
                                $firstAppointment[
                                    'service_type'
                                ]
                                ?? ''
                            ) ?>

                        </div>

                    </div>


                    <!-- STATUS -->

                    <div class="detail-item">

                        <label>
                            Appointment Status
                        </label>


                        <div
                            class="value"
                            id="viewStatus"
                        >

                            <?php

                            $status =
                                $firstAppointment[
                                    'status'
                                ]
                                ?? 'Pending';

                            ?>


                            <span
                                class="portal-status <?= dentistEscape(
                                    strtolower(
                                        $status
                                    )
                                ) ?>"
                            >

                                <?= dentistEscape(
                                    $status
                                ) ?>

                            </span>

                        </div>

                    </div>


                </div>


            </section>


        <?php endif; ?>

        <?php endif; /* /HIDDEN: Calendar, List, Actions, Filter, Today, Upcoming, Details */ ?>



        <!--
        |--------------------------------------------------------------------------
        | ASSIGNED APPOINTMENTS — REMOVED (nilipat na sa Today/Queue/Upcoming)
        |--------------------------------------------------------------------------
        -->

        <?php if (false): ?>
        <section class="portal-card" id="assigned" style="margin-top:20px;">


            <h2>
                <i class="bi bi-calendar2-check-fill"></i>
                Assigned Appointments
            </h2>


            <?php if (
                empty($appointments)
            ): ?>


                <div class="empty-appointments">

                    <i class="bi bi-calendar-x"></i>


                    <strong>
                        No appointments assigned to you.
                    </strong>


                    <span>
                        When a patient books an appointment
                        with you, it will automatically appear here.
                    </span>

                </div>


            <?php else: ?>


                <div
                    style="overflow-x:auto"
                >


                    <table
                        class="portal-table"
                    >


                        <thead>

                            <tr>

                                <th>
                                    Date
                                </th>

                                <th>
                                    Time
                                </th>

                                <th>
                                    Patient
                                </th>

                                <th>
                                    Service
                                </th>

                                <th>
                                    Status
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                            <?php foreach (
                                $appointments
                                as $appointment
                            ): ?>


                                <?php

                                $patientName =
                                    trim(
                                        $appointment[
                                            'patient_name'
                                        ] ?? ''
                                    );

                                if (
                                    $patientName === ''
                                ) {
                                    $patientName =
                                        'Unknown Patient';
                                }


                                $startTime =
                                    appointmentTime(
                                        $appointment[
                                            'start_time'
                                        ] ?? ''
                                    );


                                $endTime =
                                    appointmentTime(
                                        $appointment[
                                            'end_time'
                                        ] ?? ''
                                    );


                                $time =
                                    $startTime .
                                    ' - ' .
                                    $endTime;


                                $service =
                                    trim(
                                        $appointment[
                                            'service_type'
                                        ] ?? ''
                                    );


                                if (
                                    $service === ''
                                ) {
                                    $service =
                                        'Dental Service';
                                }


                                $status =
                                    trim(
                                        $appointment[
                                            'status'
                                        ] ?? ''
                                    );


                                if (
                                    $status === ''
                                ) {
                                    $status =
                                        'Pending';
                                }


                                $date =
                                    appointmentDate(
                                        $appointment[
                                            'appointment_date'
                                        ] ?? ''
                                    );

                                ?>


                                <tr
                                    class="clickable-row"
                                    data-appointment-id="<?= (int)($appointment['id'] ?? 0) ?>"
                                    data-patient="<?= dentistEscape(
                                        $patientName
                                    ) ?>"
                                    data-time="<?= dentistEscape(
                                        $time
                                    ) ?>"
                                    data-service="<?= dentistEscape(
                                        $service
                                    ) ?>"
                                    data-status="<?= dentistEscape(
                                        $status
                                    ) ?>"
                                >


                                    <td>

                                        <?= dentistEscape(
                                            $date
                                        ) ?>

                                    </td>


                                    <td>

                                        <?= dentistEscape(
                                            $time
                                        ) ?>

                                    </td>


                                    <td>

                                        <strong>

                                            <?= dentistEscape(
                                                $patientName
                                            ) ?>

                                        </strong>

                                    </td>


                                    <td>

                                        <?= dentistEscape(
                                            $service
                                        ) ?>

                                    </td>


                                    <td>

                                        <span
                                            class="portal-status <?= dentistEscape(
                                                strtolower(
                                                    $status
                                                )
                                            ) ?>"
                                        >

                                            <?= dentistEscape(
                                                $status
                                            ) ?>

                                        </span>

                                    </td>


                                </tr>


                            <?php endforeach; ?>


                        </tbody>


                    </table>


                </div>


            <?php endif; ?>


        </section>
        <?php endif; /* /REMOVED Assigned Appointments */ ?>


    </div>


</main>




<!--
|--------------------------------------------------------------------------
| MOVED FROM DASHBOARD — APPOINTMENT DETAILS MODAL
|--------------------------------------------------------------------------
-->

<div
    class="appt-modal-overlay"
    id="apptDetailsOverlay"
>

    <div class="appt-modal-box">

        <div class="appt-modal-head">

            <h5>Appointment Details</h5>

            <button
                type="button"
                class="appt-modal-x"
                id="apptDetailsClose"
                aria-label="Close"
                data-tip="Close"
            >
                ✕
            </button>

        </div>

        <div class="appt-modal-body">

            <div class="row-line"><strong>Patient name:</strong> <span id="detailsPatient"></span></div>
            <div class="row-line"><strong>Dental service:</strong> <span id="detailsService"></span></div>
            <div class="row-line"><strong>Assigned dentist:</strong> <span id="detailsDentist"></span></div>
            <div class="row-line"><strong>Date and time of appointment:</strong> <span id="detailsTime"></span></div>
            <div class="row-line" style="margin-bottom:0;"><strong>Booked on:</strong> <span id="detailsBooked"></span></div>

        </div>

    </div>

</div>

<!-- HISTORY VIEW MODAL: gaya sa admin (details + status timeline) -->
<div
    class="appt-modal-overlay"
    id="histDetailsOverlay"
>

    <div class="appt-modal-box">

        <div class="appt-modal-head">

            <h5>Appointment Details</h5>

            <button
                type="button"
                class="appt-modal-x"
                id="histDetailsClose"
                aria-label="Close"
                data-tip="Close"
            >
                ✕
            </button>

        </div>

        <div class="appt-modal-body">

            <div class="hist-head-card">
                <span id="histAvatar">P</span>
                <div class="hist-head-text">
                    <div id="histPatient"></div>
                    <div><span id="histStatusBadge" class="portal-status"></span></div>
                </div>
            </div>
            <div class="hist-info-grid">
                <div class="hist-info-box"><i class="bi bi-person-badge-fill"></i><div><small>ASSIGNED DENTIST</small><span id="histDentist"></span></div></div>
                <div class="hist-info-box"><i class="bi bi-calendar-event-fill"></i><div><small>DATE AND TIME</small><span id="histTime"></span></div></div>
                <div class="hist-info-box"><i class="bi bi-tooth-fill"></i><div><small>DENTAL SERVICE</small><span id="histService"></span></div></div>
                <div class="hist-info-box"><i class="bi bi-bookmark-check-fill"></i><div><small>BOOKED ON</small><span id="histBooked"></span></div></div>
            </div>
            <div class="hist-timeline-card">
                <div class="hist-timeline-title"><i class="bi bi-clock-history"></i>Status Timeline</div>
                <div id="histTimeline"></div>
            </div>

        </div>

        <div class="appt-modal-foot">
            <button type="button" id="histDetailsCloseBtn" data-tip="Close">Close</button>
        </div>

    </div>

</div>

<style>
.hist-head-card{display:flex;align-items:center;gap:14px;padding:14px 16px;margin-bottom:14px;background:linear-gradient(135deg,#EAF7F0,#F7FDFB);border:1px solid #C9E9DA;border-radius:14px}
#histAvatar{display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#0E6B5E,#0A4A41);color:#fff;font-weight:800;font-size:19px;flex-shrink:0}
.hist-head-text{min-width:0}
#histPatient{font-weight:800;color:#143f51;font-size:16.5px;line-height:1.25}
#histStatusBadge{margin-top:5px;display:inline-block}
.hist-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px}
.hist-info-box{display:flex;align-items:center;gap:10px;padding:10px 12px;background:#F7FDFB;border:1px solid #E6F2EC;border-radius:11px;min-width:0}
.hist-info-box i{color:#0E6B5E;font-size:19px;flex-shrink:0}
.hist-info-box small{display:block;font-size:10.5px;font-weight:700;letter-spacing:.4px;color:#8aa0a8}
.hist-info-box span{font-weight:700;color:#143f51;font-size:13px;word-break:break-word}
.hist-timeline-card{padding:14px 16px;background:#fff;border:1px solid #C9E9DA;border-radius:14px}
.hist-timeline-title{font-weight:800;color:#143f51;font-size:13.5px;margin-bottom:10px}
.hist-timeline-title i{color:#0E6B5E;margin-right:4px}
.hist-tl-item{display:flex;gap:11px;position:relative}
.hist-tl-dotcol{display:flex;flex-direction:column;align-items:center;flex-shrink:0}
.hist-tl-dot{display:inline-flex;align-items:center;justify-content:center;width:27px;height:27px;border-radius:50%;font-size:13px}
.hist-tl-dot.done{background:linear-gradient(135deg,#149A72,#0A4A41);color:#fff}
.hist-tl-dot.todo{background:#E6EDEF;color:#9AAFB8}
.hist-tl-line{width:2px;flex:1;min-height:8px;border-radius:2px;margin-top:2px}
.hist-tl-line.done{background:#B9E4CF}
.hist-tl-line.todo{background:#E6EDEF}
.hist-tl-label{padding-top:3px;font-weight:800;color:#143f51;font-size:13px}
.hist-tl-time{font-size:12px}
.hist-tl-time.done{color:#4d6873;font-weight:600}
.hist-tl-time.todo{color:#9AAFB8}
.appt-modal-foot{padding:13px 22px;background:#F1FAF6;border-top:1px solid #CBECE0;text-align:right}
.appt-modal-foot button{border:1px solid #D1E7DD;background:#fff;border-radius:10px;font-weight:700;padding:9px 22px;cursor:pointer;color:#57727d;font-family:inherit;font-size:13.5px}
.appt-modal-foot button:hover{background:#EAF7F0;color:#0E6B5E}
@media (max-width:560px){.hist-info-grid{grid-template-columns:1fr}}
</style>

<script>
/* History View modal: gaya sa admin timeline */
(function(){
    var overlay = document.getElementById('histDetailsOverlay');
    if(!overlay) return;
    function close(){ overlay.classList.remove('open'); }
    document.getElementById('histDetailsClose').addEventListener('click', close);
    var closeBtn = document.getElementById('histDetailsCloseBtn');
    if(closeBtn) closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', function(e){ if(e.target === overlay) close(); });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape') close(); });
    document.querySelectorAll('.hist-view-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            var patient = btn.dataset.patient || '';
            document.getElementById('histPatient').textContent = patient;
            document.getElementById('histAvatar').textContent = patient.trim().split(/\s+/).map(function(w){ return w.charAt(0); }).join('').substring(0,2).toUpperCase() || 'P';
            var status = btn.dataset.status || '';
            var badge = document.getElementById('histStatusBadge');
            badge.textContent = status;
            badge.className = 'portal-status ' + status.toLowerCase().replace(/ /g, '-');
            document.getElementById('histService').textContent = btn.dataset.service || '—';
            document.getElementById('histDentist').textContent = btn.dataset.dentist || '—';
            document.getElementById('histTime').textContent = btn.dataset.time || '—';
            document.getElementById('histBooked').textContent = btn.dataset.booked || '—';
            var tl = [
                ['bi-check2-all', 'Confirmed', btn.dataset.confirmed],
                ['bi-x-circle-fill', 'Cancelled', btn.dataset.cancelled],
                ['bi-box-arrow-in-right', 'Checked In', btn.dataset.checkedin],
                ['bi-play-fill', 'Serving started', btn.dataset.serving],
                ['bi-check-circle-fill', 'Completed', btn.dataset.completed],
                ['bi-calendar2-week-fill', 'Rescheduled', btn.dataset.rescheduled]
            ];
            var wrap = document.getElementById('histTimeline');
            wrap.innerHTML = '';
            tl.forEach(function(row, idx){
                var done = !!row[2];
                var item = document.createElement('div');
                item.className = 'hist-tl-item';
                item.style.paddingBottom = (idx === tl.length - 1 ? '0' : '14px');
                item.innerHTML =
                    '<div class="hist-tl-dotcol">' +
                        '<span class="hist-tl-dot ' + (done ? 'done' : 'todo') + '"><i class="bi ' + row[0] + '"></i></span>' +
                        (idx === tl.length - 1 ? '' : '<span class="hist-tl-line ' + (done ? 'done' : 'todo') + '"></span>') +
                    '</div>' +
                    '<div><div class="hist-tl-label">' + row[1] + '</div>' +
                    '<div class="hist-tl-time ' + (done ? 'done' : 'todo') + '">' + (done ? row[2] : '—') + '</div></div>';
                wrap.appendChild(item);
            });
            overlay.classList.add('open');
        });
    });
})();
</script>

<style>
.status-action-btn{cursor:pointer;border:none;font-size:12px}
.status-action-btn i{font-size:10px;margin-left:2px}
.status-action-overlay{position:fixed;inset:0;background:rgba(10,59,52,.55);z-index:2000;padding:20px;display:none;align-items:center;justify-content:center}
.status-action-overlay.open{display:flex}
.status-action-box{background:#fff;border-radius:18px;max-width:440px;width:100%;overflow:visible !important;box-shadow:0 18px 45px rgba(0,0,0,.25);animation:statusActionPop .18s ease}
@keyframes statusActionPop{from{transform:scale(.96);opacity:0}to{transform:scale(1);opacity:1}}
.status-action-head{padding:18px 22px;background:linear-gradient(135deg,#D9F2E6,#EAF7F0);border-bottom:1px solid #C9E9DA;border-top-left-radius:17px;border-top-right-radius:17px;display:flex;align-items:center;gap:10px}
.status-action-head h5{margin:0;flex:1;color:#143f51;font-weight:800;font-size:16px}
.status-action-body{padding:20px 22px;border-bottom-left-radius:17px;border-bottom-right-radius:17px}
.status-action-patient{font-size:13px;font-weight:700;color:#244f60;margin-bottom:14px}
.status-action-grid{display:grid;grid-template-columns:1fr;gap:10px}
.status-action-grid button{border:none;border-radius:12px;padding:16px 10px;font-weight:800;font-size:14px;letter-spacing:.4px;color:#fff;cursor:pointer;display:flex;flex-direction:row;align-items:center;justify-content:center;gap:10px;transition:.15s}
.status-action-grid button i{font-size:20px}
.status-action-grid button:hover{filter:brightness(.93);transform:translateY(-2px)}
.status-action-grid [data-tip]:hover::after,
.status-action-resched-form [data-tip]:hover::after {
    top: auto !important;
    bottom: calc(100% + 8px) !important;
    left: 50% !important;
    transform: translateX(-50%) !important;
    z-index: 99999 !important;
}
.status-action-head [data-tip]:hover::after {
    top: calc(100% + 8px) !important;
    bottom: auto !important;
    left: auto !important;
    right: 0 !important;
    transform: none !important;
    z-index: 99999 !important;
}
.status-action-confirm{background:linear-gradient(135deg,#10B981,#047857)}
.status-action-complete{background:linear-gradient(135deg,#3B82F6,#1D4ED8)}
.status-action-cancel{background:linear-gradient(135deg,#EF4444,#B91C1C)}
.status-action-resched{background:linear-gradient(135deg,#F59E0B,#B45309)}
.status-action-resched-form{display:none;margin-top:14px;background:#F7FDFB;border:1px solid #CBECE0;border-radius:12px;padding:14px}
.status-action-resched-form.open{display:block}
.status-action-resched-form label{font-size:11.5px;font-weight:800;color:#244f60;display:block;margin:8px 0 4px}
.status-action-resched-form label:first-child{margin-top:0}
.status-action-resched-form input{width:100%;border:1px solid #D1E7DD;border-radius:9px;padding:9px 11px;font-family:inherit;font-size:13px}
.status-action-save{width:100%;margin-top:12px;border:none;border-radius:10px;padding:12px;background:linear-gradient(135deg,#0E6B5E,#0A4A41);color:#fff;font-weight:800;font-size:13.5px;cursor:pointer}
</style>

<!-- STATUS ACTION POPUP: Confirm / Complete / Cancel / Reschedule -->
<div class="status-action-overlay" id="statusActionOverlay">
    <div class="status-action-box">
        <div class="status-action-head">
            <h5><i class="bi bi-lightning-charge-fill"></i> Update Status</h5>
            <button type="button" class="appt-modal-x" id="statusActionClose" aria-label="Close" data-tip="Close">✕</button>
        </div>
        <div class="status-action-body">
            <div class="status-action-patient">Patient: <span id="statusActionPatient"></span></div>
            <div id="statusActionNotice" style="display:none;background:#FFF7E0;border:1px solid #E3D3A0;color:#8A6D1B;border-radius:10px;padding:8px 12px;font-size:12px;font-weight:600;margin-bottom:12px;"></div>
            <div class="status-action-grid">
                <form method="POST" id="statusActionConfirmWrap">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="appointment_status">
                    <input type="hidden" name="appointment_id" class="status-action-id" value="">
                    <input type="hidden" name="status" value="Confirmed">
                    <button type="submit" class="status-action-confirm" style="width:100%;" data-tip="Confirm appointment"><i class="bi bi-check2-all"></i>CONFIRM</button>
                </form>
                <form method="POST" id="statusActionCompleteWrap">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="appointment_status">
                    <input type="hidden" name="appointment_id" class="status-action-id" value="">
                    <input type="hidden" name="status" value="Completed">
                    <button type="submit" class="status-action-complete" style="width:100%;" data-tip="Complete appointment"><i class="bi bi-check-circle-fill"></i>COMPLETE</button>
                </form>
                <button type="button" class="status-action-resched" id="statusActionReschedBtn" data-tip="Reschedule appointment"><i class="bi bi-calendar2-week-fill"></i>RESCHEDULE</button>
                <form method="POST" id="statusActionCancelWrap">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="appointment_status">
                    <input type="hidden" name="appointment_id" class="status-action-id" value="">
                    <input type="hidden" name="status" value="Cancelled">
                    <button type="submit" class="status-action-cancel" style="width:100%;" data-tip="Cancel appointment"><i class="bi bi-x-circle-fill"></i>CANCEL</button>
                </form>
            </div>
            <form method="POST" class="status-action-resched-form" id="statusActionReschedForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reschedule">
                <input type="hidden" name="appointment_id" class="status-action-id" value="">
                <label>New Date</label>
                <input type="date" name="appointment_date" id="statusActionDate" min="<?= date('Y-m-d') ?>" required>
                <label>Start Time (8:00 AM – 5:30 PM)</label>
                <input type="time" name="start_time" id="statusActionStart" min="08:00" max="17:30" required>
                <label>End Time (until 6:00 PM)</label>
                <input type="time" name="end_time" id="statusActionEnd" min="08:30" max="18:00" required>
                <button type="submit" class="status-action-save" data-tip="Save new schedule"><i class="bi bi-save-fill"></i> Save New Schedule</button>
            </form>
        </div>
    </div>
</div>

<script>
/* Status action popup: show only contextually valid buttons for current status & clinic hours */
(function(){
    var overlay = document.getElementById('statusActionOverlay');
    if(!overlay) return;
    var closeBtn = document.getElementById('statusActionClose');
    function close(){ overlay.classList.remove('open'); var f=document.getElementById('statusActionReschedForm'); if(f) f.classList.remove('open'); }
    if(closeBtn) closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', function(e){ if(e.target === overlay) close(); });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape') close(); });
    var reschedBtn = document.getElementById('statusActionReschedBtn');
    if(reschedBtn) reschedBtn.addEventListener('click', function(){
        var f = document.getElementById('statusActionReschedForm');
        if(f) f.classList.toggle('open');
    });
    document.querySelectorAll('.status-action-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            var st = (btn.dataset.status || 'Pending').trim().toLowerCase();
            var apptDate = (btn.dataset.date || '').trim();
            var apptStart = (btn.dataset.start || '').trim();
            var apptEnd = (btn.dataset.end || apptStart).trim();

            document.getElementById('statusActionPatient').textContent = (btn.dataset.patient || '') + ' (' + (btn.dataset.status || 'Pending') + ')';
            overlay.querySelectorAll('.status-action-id').forEach(function(inp){ inp.value = btn.dataset.apptId || ''; });

            var now = new Date();
            var todayStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
            var nowHm = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
            var isToday = (apptDate === todayStr);
            var isPastDate = (apptDate !== '' && apptDate < todayStr);
            var outsideHours = (apptStart !== '' && apptStart < '08:00') || (apptEnd !== '' && apptEnd > '18:00');
            var isSlotPassedToday = isToday && ((apptEnd !== '' && apptEnd <= nowHm) || nowHm >= '18:00');
            var cannotApprove = isPastDate || outsideHours || isSlotPassedToday;

            var confirmWrap = document.getElementById('statusActionConfirmWrap');
            var completeWrap = document.getElementById('statusActionCompleteWrap');
            var cancelWrap = document.getElementById('statusActionCancelWrap');
            var noticeEl = document.getElementById('statusActionNotice');

            var showConfirm = false;
            var showComplete = false;
            var showResched = false;
            var showCancel = false;
            var noticeMsg = '';

            if (st === 'pending') {
                if (cannotApprove) {
                    noticeMsg = 'Scheduled time has passed or is outside clinic operating hours (8:00 AM – 6:00 PM). Please reschedule or cancel.';
                } else {
                    showConfirm = true;
                }
                showResched = true;
                showCancel = true;
            } else if (st === 'confirmed' || st === 'approved') {
                if (isToday) showComplete = true;
                showResched = true;
                showCancel = true;
            } else if (st === 'checked in' || st === 'late' || st === 'serving') {
                showComplete = true;
            } else {
                showResched = true;
            }

            if(confirmWrap) confirmWrap.style.display = showConfirm ? '' : 'none';
            if(completeWrap) completeWrap.style.display = showComplete ? '' : 'none';
            if(reschedBtn) reschedBtn.style.display = showResched ? '' : 'none';
            if(cancelWrap) cancelWrap.style.display = showCancel ? '' : 'none';
            if(noticeEl){
                noticeEl.textContent = noticeMsg;
                noticeEl.style.display = noticeMsg !== '' ? '' : 'none';
            }

            var d = document.getElementById('statusActionDate');
            var s = document.getElementById('statusActionStart');
            var e2 = document.getElementById('statusActionEnd');
            if(d && apptDate) d.value = apptDate >= todayStr ? apptDate : todayStr;
            if(s && apptStart) s.value = apptStart;
            if(e2 && apptEnd) e2.value = apptEnd;
            var f = document.getElementById('statusActionReschedForm');
            if(f) f.classList.remove('open');
            overlay.classList.add('open');
        });
    });
})();
</script>

<script>
/* Accordion tabs: puwedeng sabay-sabay na bukas (para hindi nasasara ang
   box pag may pinindot na pagination link). Nakasulat ang state sa
   sessionStorage para maibalik pag na-reload ang page. */
(function(){
    var KEY = 'dentistApptOpenTabs';
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.section-tab'));

    function save(){
        try {
            sessionStorage.setItem(KEY, JSON.stringify(
                tabs.filter(function(t){ return t.classList.contains('open'); })
                     .map(function(t){ return t.id; })
            ));
        } catch(e){}
    }

    function restore(){
        try {
            var saved = JSON.parse(sessionStorage.getItem(KEY) || 'null');
            if(!saved || !saved.length) return false;
            tabs.forEach(function(t){ t.classList.toggle('open', saved.indexOf(t.id) !== -1); });
            return true;
        } catch(e){ return false; }
    }

    restore();

    tabs.forEach(function(tab){
        var head = tab.querySelector('.section-tab-head') || tab.querySelector('.appt-panel-head');
        if(!head) return;
        head.addEventListener('click', function(e){
            if(e.target.closest('a, button, select, form')) return;
            tab.classList.toggle('open');
            save();
        });
    });
})();


/* Details modal: punan mula sa pinindot na View button */
(function(){
    var overlay = document.getElementById('apptDetailsOverlay');
    if(!overlay) return;

    var closeBtn = document.getElementById('apptDetailsClose');

    function closeModal(){
        overlay.classList.remove('open');
    }

    document.querySelectorAll('.moved-view-btn[data-patient]:not(.hist-view-btn)').forEach(function(button){
        button.addEventListener('click', function(){
            document.getElementById('detailsPatient').textContent = button.dataset.patient || '';
            document.getElementById('detailsService').textContent = button.dataset.service || '';
            document.getElementById('detailsDentist').textContent = button.dataset.dentist || '';
            document.getElementById('detailsTime').textContent = button.dataset.time || '';
            document.getElementById('detailsBooked').textContent = button.dataset.booked || '—';
            overlay.classList.add('open');
        });
    });

    if(closeBtn) closeBtn.addEventListener('click', closeModal);

    overlay.addEventListener('click', function(e){
        if(e.target === overlay) closeModal();
    });

    document.addEventListener('keydown', function(e){
        if(e.key === 'Escape') closeModal();
    });
})();

</script>


<!--
|--------------------------------------------------------------------------
| CLICK APPOINTMENT
|--------------------------------------------------------------------------
*/

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        const rows =
            document.querySelectorAll(
                '.clickable-row'
            );


        const patient =
            document.getElementById(
                'viewPatient'
            );


        const time =
            document.getElementById(
                'viewTime'
            );


        const service =
            document.getElementById(
                'viewService'
            );


        const status =
            document.getElementById(
                'viewStatus'
            );


        rows.forEach(
            function (row, index) {


                row.addEventListener(
                    'click',
                    function () {


                        /*
                        |--------------------------------------------------------------------------
                        | PATIENT
                        |--------------------------------------------------------------------------
                        */

                        if (patient) {

                            patient.textContent =
                                row.dataset.patient
                                || 'Unknown Patient';

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | TIME
                        |--------------------------------------------------------------------------
                        */

                        if (time) {

                            time.textContent =
                                row.dataset.time
                                || 'Time not available';

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | SERVICE
                        |--------------------------------------------------------------------------
                        */

                        if (service) {

                            service.textContent =
                                row.dataset.service
                                || 'Dental Service';

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | STATUS
                        |--------------------------------------------------------------------------
                        */

                        if (status) {

                            const statusText =
                                row.dataset.status
                                || 'Pending';


                            status.innerHTML =
                                '';


                            const badge =
                                document.createElement(
                                    'span'
                                );


                            badge.className =
                                'portal-status ' +
                                statusText.toLowerCase();


                            badge.textContent =
                                statusText;


                            status.appendChild(
                                badge
                            );

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | SELECTED ROW
                        |--------------------------------------------------------------------------
                        */

                        rows.forEach(
                            function (otherRow) {

                                otherRow.classList.remove(
                                    'selected'
                                );

                            }
                        );


                        row.classList.add(
                            'selected'
                        );

                    }
                );


            }
        );


        /*
        |--------------------------------------------------------------------------
        | HIGHLIGHT MULA SA NOTIFICATION VIEW — hanapin sa Today at Upcoming
        |--------------------------------------------------------------------------
        */

        var highlightId = null;

        try {
            highlightId =
                new URLSearchParams(
                    window.location.search
                ).get('highlight');
        } catch (e) {}

        var highlighted = false;

        if (highlightId) {
            var targetRow = document.querySelector(
                'tr[data-appointment-id="' + highlightId + '"]'
            );
            if (targetRow) {
                document.querySelectorAll('tr[data-appointment-id].selected').forEach(function(t){
                    t.classList.remove('selected');
                });
                targetRow.classList.add('selected');
                try {
                    targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } catch (e) {}
                var viewBtn2 = targetRow.querySelector('.moved-view-btn[data-patient]');
                if (viewBtn2) { try { viewBtn2.click(); } catch (e) {} }
                highlighted = true;
                var movedTab = targetRow.closest('.section-tab');
                if (movedTab) {
                    document.querySelectorAll('.section-tab').forEach(function(t){
                        t.classList.remove('open');
                    });
                    movedTab.classList.add('open');
                }
            }
        }

        if (!highlighted) {
            var hlPanel = document.getElementById('notifHighlight');
            if (highlightId && hlPanel) {
                try { hlPanel.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
                highlighted = true;
            }
        }

        if (!highlighted && !highlightId && rows.length > 0) {

            rows[0].classList.add(
                'selected'
            );

        }

    }
);

</script>




<script>
/* SUMMARY CARD LIST — buksan/takpan ang Completed at Cancelled/No-Show listahan */
(function () {
    var toggles = document.querySelectorAll("[data-sum-toggle]");

    if (!toggles.length) return;

    toggles.forEach(function (btn) {
        btn.addEventListener("click", function () {
            var targetId = this.getAttribute("data-sum-toggle");

            if (!targetId) return;

            /* Isang panel lang ang bukas sa isang pagkakataon. */
            toggles.forEach(function (other) {
                if (other === this) return;

                var otherId = other.getAttribute("data-sum-toggle");

                if (!otherId) return;

                var otherPanel = document.getElementById(otherId);

                if (otherPanel) otherPanel.style.display = "none";
                other.setAttribute("aria-expanded", "false");
            }.bind(this));

            var panel = document.getElementById(targetId);

            if (!panel) return;

            var isOpen = panel.style.display !== "none";

            panel.style.display = isOpen ? "none" : "block";
            this.setAttribute("aria-expanded", isOpen ? "false" : "true");
        });
    });
})();
</script>
<div id="dentistLogoutConfirm" class="dentist-logout-overlay" style="display:none;">
    <div class="dentist-logout-box">
        <div class="dentist-logout-head">
            <span class="dentist-logout-head-icon"><i class="bi bi-box-arrow-right"></i></span>
            <h5>Log Out</h5>
            <button type="button" class="dentist-logout-x" id="dentistLogoutClose" aria-label="Close" data-tip="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="dentist-logout-body">
            <div class="dentist-logout-icon"><i class="bi bi-exclamation-circle-fill"></i></div>
            <h5>Are you sure you want to log out?</h5>
            <p>You will need to log in again to access the dentist account.</p>
        </div>
        <div class="dentist-logout-foot">
            <button type="button" class="btn btn-light" id="dentistLogoutCancel" data-tip="Cancel">Cancel</button>
            <a href="../logout.php" class="btn dentist-logout-yes" id="dentistLogoutYes" data-tip="Log Out"><i class="bi bi-box-arrow-right me-1"></i>Log Out</a>
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
