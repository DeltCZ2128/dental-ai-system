<?php

require_once "../config.php";

$conn = $pdo;

/*
|--------------------------------------------------------------------------
| DENTIST DASHBOARD
|--------------------------------------------------------------------------
| Automatic based on logged-in dentist_id
|
| Features:
| 1. Today's Appointments
| 2. Upcoming Appointments
| 3. Appointment Calendar
| 4. Patient Information
| 5. Appointment Details
| 6. Notification Bell
| 7. Completed Appointments
| 8. Cancelled Appointments
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| CHECK LOGIN
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$dentist_id = function_exists('resolveDentistId')
    ? resolveDentistId((int)$_SESSION['user_id'], trim($_SESSION['full_name'] ?? ''))
    : 0;
if ($dentist_id <= 0) {
    // Lumaang fallback kapag wala pa ang helper (hindi dapat mangyari).
    $dentistLookup = $conn->prepare("SELECT id FROM dentists WHERE id = ? OR dentist_name = ? ORDER BY id = ? DESC LIMIT 1");
    $dentistLookup->execute([
        (int) $_SESSION['user_id'],
        trim($_SESSION['full_name'] ?? ''),
        (int) $_SESSION['user_id']
    ]);
    $dentist_id = (int) ($dentistLookup->fetchColumn() ?: 0);
}

/*
|--------------------------------------------------------------------------
| CHECK DENTIST ROLE
|--------------------------------------------------------------------------
*/

if (strtolower(trim($_SESSION['role'] ?? '')) !== 'dentist') {
    header("Location: ../login.php");
    exit();
}

$dentist = [
    'name' => $_SESSION['full_name'] ?? 'Dentist',
    'email' => $_SESSION['username'] ?? ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'queue_status') {

    $queueStatus = $_POST['status'] ?? '';
    $queueId = (int) ($_POST['queue_id'] ?? 0);

    if (!in_array($queueStatus, ['Waiting', 'Serving', 'Completed'], true)) {
        header('Location: dentist_dashboard.php');
        exit();
    }

    $stmt = $conn->prepare("
        UPDATE queue q
        INNER JOIN appointments a
            ON a.patient_id = q.patient_id
            AND a.dentist_id = ?
            AND a.appointment_date = q.queue_date
        SET q.status = ?
        WHERE q.id = ?
    ");
    $stmt->execute([$dentist_id, $queueStatus, $queueId]);

    header('Location: dentist_dashboard.php#queue');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'appointment_status') {

    $appointmentStatus = $_POST['status'] ?? '';
    $appointmentId = (int) ($_POST['appointment_id'] ?? 0);

    if (!in_array($appointmentStatus, ['Pending', 'Confirmed', 'Completed', 'Cancelled'], true)) {
        header('Location: dentist_dashboard.php#today');
        exit();
    }

    $dashStampCols = [
        'Confirmed' => 'confirmed_at',
        'Completed' => 'completed_at',
        'Cancelled' => 'cancelled_at'
    ];
    $dashStampSql = isset($dashStampCols[$appointmentStatus]) ? ", {$dashStampCols[$appointmentStatus]} = NOW()" : "";
    $stmt = $conn->prepare("
        UPDATE appointments
        SET status = ?{$dashStampSql}
        WHERE id = ?
        AND dentist_id = ?
    ");
    $stmt->execute([$appointmentStatus, $appointmentId, $dentist_id]);
    $dashSaved = $stmt->rowCount() > 0;

    header('Location: dentist_dashboard.php?' . ($dashSaved ? 'saved=1' : 'save_failed=1') . '#today');
    exit();
}


/*
|--------------------------------------------------------------------------
| CURRENT DATE
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Asia/Manila');

$today = date("Y-m-d");


/*
|--------------------------------------------------------------------------
| ALL ASSIGNED APPOINTMENTS
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare("
        SELECT
            a.id,
            a.appointment_date,
            a.start_time AS appointment_time,
            a.end_time,
            a.service_type,
            a.status,
            a.notes,
            CONCAT(p.first_name, ' ', p.last_name) AS patient_name
        FROM appointments a
        INNER JOIN patients p
            ON a.patient_id = p.id
        WHERE a.dentist_id = ?
        ORDER BY a.appointment_date DESC, a.start_time DESC
    ");
    $stmt->execute([$dentist_id]);
    $allAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $allAppointments = [];
}


/*
|--------------------------------------------------------------------------
| TODAY'S APPOINTMENTS
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare("
        SELECT
            a.id,
            a.appointment_date,
            a.start_time AS appointment_time,
            a.end_time,
            a.service_type,
            a.notes,
            a.status,

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

    $stmt->execute([
        $dentist_id,
        $today
    ]);

    $todayAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $todayAppointments = [];
}


/*
|--------------------------------------------------------------------------
| UPCOMING APPOINTMENTS
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare("
        SELECT
            a.id,
            a.appointment_date,
            a.start_time AS appointment_time,
            a.status,

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

    $stmt->execute([
        $dentist_id,
        $today
    ]);

    $upcomingAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $upcomingAppointments = [];
}


/*
|--------------------------------------------------------------------------
| COMPLETED APPOINTMENTS
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare("
        SELECT
            a.id,
            a.appointment_date,
            a.start_time AS appointment_time,
            a.status,

            CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
            p.email AS patient_email

        FROM appointments a

        INNER JOIN patients p
            ON a.patient_id = p.id

        WHERE a.dentist_id = ?
        AND a.status = 'Completed'

        ORDER BY a.appointment_date DESC,
                 a.start_time DESC

        LIMIT 10
    ");

    $stmt->execute([$dentist_id]);

    $completedAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $completedAppointments = [];
}


/*
|--------------------------------------------------------------------------
| CANCELLED APPOINTMENTS
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare("
        SELECT
            a.id,
            a.appointment_date,
            a.start_time AS appointment_time,
            a.status,

            CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
            p.email AS patient_email

        FROM appointments a

        INNER JOIN patients p
            ON a.patient_id = p.id

        WHERE a.dentist_id = ?
        AND a.status = 'Cancelled'

        ORDER BY a.appointment_date DESC,
                 a.start_time DESC

        LIMIT 10
    ");

    $stmt->execute([$dentist_id]);

    $cancelledAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $cancelledAppointments = [];
}


/*
|--------------------------------------------------------------------------
| PATIENT INFORMATION
|--------------------------------------------------------------------------
|
| Get patients who have appointments with this dentist.
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare("
        SELECT DISTINCT
            u.id,
            u.full_name AS name,
            u.username AS email

        FROM users u

        INNER JOIN appointments a
            ON a.patient_id = u.id

        WHERE a.dentist_id = ?

        ORDER BY u.full_name ASC
    ");

    $stmt->execute([$dentist_id]);

    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $patients = [];
}


/*
|--------------------------------------------------------------------------
| NOTIFICATIONS
|--------------------------------------------------------------------------
|
| Pending/approved appointments assigned to this dentist.
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare("
        SELECT
            a.id,
            a.appointment_date,
            a.start_time AS appointment_time,
            a.status,
            u.full_name AS patient_name

        FROM appointments a

        INNER JOIN users u
            ON a.patient_id = u.id

        WHERE a.dentist_id = ?

        AND a.status IN ('Approved', 'Pending')

        AND a.appointment_date >= ?

        ORDER BY a.appointment_date ASC,
                 a.start_time ASC

        LIMIT 10
    ");

    $stmt->execute([
        $dentist_id,
        $today
    ]);

    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $notifications = [];
}


/*
|--------------------------------------------------------------------------
| CALENDAR APPOINTMENTS
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare("
        SELECT
            a.id,
            a.appointment_date,
            a.start_time AS appointment_time,
            a.status,
            u.full_name AS patient_name

        FROM appointments a

        INNER JOIN users u
            ON a.patient_id = u.id

        WHERE a.dentist_id = ?

        ORDER BY a.appointment_date ASC,
                 a.start_time ASC
    ");

    $stmt->execute([$dentist_id]);

    $calendarAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $calendarAppointments = [];
}


/*
|--------------------------------------------------------------------------
| PATIENT QUEUE
|--------------------------------------------------------------------------
*/

try {

    $stmt = $conn->prepare("
        SELECT
            q.queue_number,
            q.priority,
            q.status,
            p.first_name,
            p.last_name
        FROM queue q
        INNER JOIN patients p
            ON q.patient_id = p.id
        WHERE q.queue_date = ?
        AND q.status NOT IN ('Completed', 'Cancelled')
        AND EXISTS (
            SELECT 1
            FROM appointments a
            WHERE a.patient_id = q.patient_id
            AND a.dentist_id = ?
            AND a.appointment_date = q.queue_date
        )
        ORDER BY q.queue_number ASC
    ");

    $stmt->execute([
        $today,
        $dentist_id
    ]);

    $patientQueue = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $patientQueue = [];
}


/*
|--------------------------------------------------------------------------
| COUNTS
|--------------------------------------------------------------------------
*/

$todayCount = count($todayAppointments);
$upcomingCount = count($upcomingAppointments);
$completedCount = count($completedAppointments);
$cancelledCount = count($cancelledAppointments);
$notificationCount = count($notifications);
$queueCount = count($patientQueue);

try {
    $stmt = $conn->prepare("
        SELECT
            SUM(status = 'Pending') AS pending_count,
            SUM(status IN ('Confirmed', 'Approved')) AS confirmed_count
        FROM appointments
        WHERE dentist_id = ?
    ");
    $stmt->execute([$dentist_id]);
    $appointmentSummary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $completedStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM appointments
        WHERE dentist_id = ?
        AND status = 'Completed'
    ");
    $completedStmt->execute([$dentist_id]);
    $completedCount = (int) $completedStmt->fetchColumn();

    $noShowStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM appointments
        WHERE dentist_id = ?
        AND status = 'No-Show'
    ");
    $noShowStmt->execute([$dentist_id]);
    $noShowCount = (int) $noShowStmt->fetchColumn();
} catch (PDOException $e) {
    $appointmentSummary = [];
    if (!isset($noShowCount)) {
        $noShowCount = 0;
    }
}

$pendingCount = (int) ($appointmentSummary['pending_count'] ?? 0);
$confirmedCount = (int) ($appointmentSummary['confirmed_count'] ?? 0);
if (!isset($noShowCount)) {
    $noShowCount = 0;
}

/*
|--------------------------------------------------------------------------
| CHART DATA — My Week Ahead + Treatment Outcomes
| Aggregated from already-fetched $allAppointments (no extra queries).
|--------------------------------------------------------------------------
*/

$dWeekLabels = [];
$dWeekData = [];

$dStatusLabels = ['Completed', 'Confirmed', 'Pending', 'Cancelled', 'No-Show'];
$dStatusData = [0, 0, 0, 0, 0];

try {
    $dWeekMap = [];
    $dWeekNames = [];

    for ($i = 0; $i < 7; $i++) {
        $dt = new DateTime("+{$i} days");
        $key = $dt->format('Y-m-d');
        $dWeekMap[$key] = 0;
        $dWeekNames[$key] = $dt->format('D');
    }

    $dStatusMap = [
        'completed' => 0, 'confirmed' => 0, 'approved' => 0,
        'pending' => 0, 'cancelled' => 0, 'canceled' => 0,
        'no-show' => 0, 'no show' => 0,
    ];

    foreach ($allAppointments as $apptRow) {
        $st = strtolower(trim($apptRow['status'] ?? ''));

        if (array_key_exists($st, $dStatusMap)) {
            $dStatusMap[$st]++;
        }

        $ad = $apptRow['appointment_date'] ?? '';

        if (
            array_key_exists($ad, $dWeekMap) &&
            !in_array($st, ['cancelled', 'canceled'], true)
        ) {
            $dWeekMap[$ad]++;
        }
    }

    foreach ($dWeekMap as $k => $v) {
        $dWeekLabels[] = $dWeekNames[$k];
        $dWeekData[] = (int) $v;
    }

    $dStatusData = [
        (int) ($dStatusMap['completed'] ?? 0),
        (int) (($dStatusMap['confirmed'] ?? 0) + ($dStatusMap['approved'] ?? 0)),
        (int) ($dStatusMap['pending'] ?? 0),
        (int) (($dStatusMap['cancelled'] ?? 0) + ($dStatusMap['canceled'] ?? 0)),
        (int) (($dStatusMap['no-show'] ?? 0) + ($dStatusMap['no show'] ?? 0)),
    ];
} catch (Throwable $dChartError) {
    // Charts must never break the dashboard.
}

/*
|--------------------------------------------------------------------------
| TOP SERVICES THIS WEEK (Mon-Sun ng kasalukuyang linggo)
| Aggregated from already-fetched $allAppointments (no extra queries).
|--------------------------------------------------------------------------
*/

$topServicesWeek = [];

try {
    $weekStart = new DateTime('monday this week');
    $weekEnd = new DateTime('sunday this week');
    $weekStartStr = $weekStart->format('Y-m-d');
    $weekEndStr = $weekEnd->format('Y-m-d');

    $serviceCounts = [];

    foreach (($allAppointments ?? []) as $apptRow) {
        $st = strtolower(trim($apptRow['status'] ?? ''));

        if ($st === 'cancelled' || $st === 'canceled') {
            continue;
        }

        $ad = substr(trim($apptRow['appointment_date'] ?? ''), 0, 10);

        if ($ad < $weekStartStr || $ad > $weekEndStr) {
            continue;
        }

        $svc = trim($apptRow['service_type'] ?? '');

        if ($svc === '') {
            $svc = 'General Service';
        }

        if (!isset($serviceCounts[$svc])) {
            $serviceCounts[$svc] = 0;
        }

        $serviceCounts[$svc]++;
    }

    arsort($serviceCounts);

    $topServicesWeek = array_slice($serviceCounts, 0, 5, true);
} catch (Throwable $topSvcError) {
    $topServicesWeek = [];
}

/*
|--------------------------------------------------------------------------
| CLICKABLE CARD DETAIL LISTS (same page, no new tab — gaya ng admin)
| Derived mula sa $allAppointments para laging tugma ang bilang.
|--------------------------------------------------------------------------
*/

$dTodayStr = $today;
$dWeekStartStr = (new DateTime('monday this week'))->format('Y-m-d');
$dWeekEndStr = (new DateTime('sunday this week'))->format('Y-m-d');
$dMonthStartStr = date('Y-m-01');
$dMonthEndStr = date('Y-m-t');
$dMonthPrefix = date('Y-m');

$dTodayList = [];
$dUpcomingList = [];
$dCompletedList = [];
$dPendingList = [];
$dConfirmedList = [];
$dNoshowList = [];

$dTodaySub = ['total' => 0, 'confirmed' => 0, 'pending' => 0, 'completed' => 0];
$dUpcomingSub = ['total' => 0, 'week' => 0, 'month' => 0, 'beyond' => 0];
$dCompletedSub = ['total' => 0, 'today' => 0, 'week' => 0, 'month' => 0];
$dPendingSub = ['total' => 0, 'today' => 0, 'week' => 0, 'month' => 0];
$dConfirmedSub = ['total' => 0, 'today' => 0, 'week' => 0, 'month' => 0];
$dNoshowSub = ['total' => 0, 'today' => 0, 'week' => 0, 'month' => 0];

foreach (($allAppointments ?? []) as $dRow) {
    $dSt = strtolower(trim($dRow['status'] ?? ''));
    $dDate = substr(trim($dRow['appointment_date'] ?? ''), 0, 10);
    $inWeek = ($dDate >= $dWeekStartStr && $dDate <= $dWeekEndStr);
    $inMonth = (substr($dDate, 0, 7) === $dMonthPrefix);
    $isToday = ($dDate === $dTodayStr);

    if ($isToday) {
        $dTodayList[] = $dRow;
        $dTodaySub['total']++;
        if ($dSt === 'confirmed' || $dSt === 'approved') $dTodaySub['confirmed']++;
        elseif ($dSt === 'pending') $dTodaySub['pending']++;
        elseif ($dSt === 'completed') $dTodaySub['completed']++;
    }

    if ($dDate > $dTodayStr && !in_array($dSt, ['cancelled', 'canceled', 'completed'], true)) {
        $dUpcomingList[] = $dRow;
    }

    if ($dSt === 'completed') {
        $dCompletedList[] = $dRow;
        $dCompletedSub['total']++;
        if ($isToday) $dCompletedSub['today']++;
        if ($inWeek) $dCompletedSub['week']++;
        if ($inMonth) $dCompletedSub['month']++;
    } elseif ($dSt === 'pending') {
        $dPendingList[] = $dRow;
        $dPendingSub['total']++;
        if ($isToday) $dPendingSub['today']++;
        if ($inWeek) $dPendingSub['week']++;
        if ($inMonth) $dPendingSub['month']++;
    } elseif ($dSt === 'confirmed' || $dSt === 'approved') {
        $dConfirmedList[] = $dRow;
        $dConfirmedSub['total']++;
        if ($isToday) $dConfirmedSub['today']++;
        if ($inWeek) $dConfirmedSub['week']++;
        if ($inMonth) $dConfirmedSub['month']++;
    } elseif ($dSt === 'no-show' || $dSt === 'no show') {
        $dNoshowList[] = $dRow;
        $dNoshowSub['total']++;
        if ($isToday) $dNoshowSub['today']++;
        if ($inWeek) $dNoshowSub['week']++;
        if ($inMonth) $dNoshowSub['month']++;
    }
}

foreach ($dUpcomingList as $uRow) {
    $uDate = substr(trim($uRow['appointment_date'] ?? ''), 0, 10);
    $dUpcomingSub['total']++;
    if ($uDate >= $dWeekStartStr && $uDate <= $dWeekEndStr) $dUpcomingSub['week']++;
    if (substr($uDate, 0, 7) === $dMonthPrefix) $dUpcomingSub['month']++;
    if ($uDate > $dMonthEndStr) $dUpcomingSub['beyond']++;
}

/* $allAppointments ay naka-DESC (date DESC, time DESC) kaya naka-pabaligtad ang
   $dUpcomingList. Ayusin: ASC by date, then by time — mas malapit muna ang unang
   lumabas, gaya ng dentist/appointments.php. */
usort($dUpcomingList, function ($x, $y) {
    $xd = substr(trim($x['appointment_date'] ?? ''), 0, 10);
    $yd = substr(trim($y['appointment_date'] ?? ''), 0, 10);
    if ($xd !== $yd) return strcmp($xd, $yd);
    return strcmp(
        trim($x['appointment_time'] ?? ''),
        trim($y['appointment_time'] ?? '')
    );
});

/* Card counts = detail totals para walang mismatch (lalo na ang upcoming na naka-LIMIT 10 dati). */
$todayCount = count($dTodayList);
$upcomingCount = count($dUpcomingList);
$completedCount = count($dCompletedList);
$pendingCount = count($dPendingList);
$confirmedCount = count($dConfirmedList);
$noShowCount = count($dNoshowList);

function dentistCardStatusKey($status)
{
    $key = strtolower(trim((string)($status ?? '')));
    if ($key === 'approved') $key = 'confirmed';
    elseif ($key === 'canceled') $key = 'cancelled';
    elseif ($key === 'no show') $key = 'no-show';
    return $key;
}

function dentistCardEscape($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function dentistCardInitials($name)
{
    $parts = preg_split('/\s+/', trim((string)($name ?? '')));
    $out = '';
    foreach ($parts as $word) {
        if ($word === '') continue;
        $out .= strtoupper(substr($word, 0, 1));
        if (strlen($out) >= 2) break;
    }
    if ($out === '') $out = 'P';
    return $out;
}

/*
|--------------------------------------------------------------------------
| SHARED RECORD POPUP — ginagamit ng Upcoming/Completed/Pending/Confirmed/No-Show
| Parehong itsura sa "Today's Appointments" popup: header + back button +
| filter tabs + table (#, Patient, Time, Service, Dentist, Status, View).
|--------------------------------------------------------------------------
*/

function dentistRecordPopup(array $cfg)
{
    global $dTodayStr, $dWeekStartStr, $dWeekEndStr, $dMonthPrefix, $dMonthEndStr;

    $view       = $cfg['view'];
    $title      = $cfg['title'];
    $sub        = $cfg['sub'];
    $icon       = $cfg['icon'];
    $list       = $cfg['list'];
    $emptyMsg   = $cfg['empty'];
    $emptyIcon  = $cfg['empty_icon'];
    $headLabel  = $cfg['head_label'];
    $tabs       = $cfg['tabs'];          // [ ['filter'=>'week','label'=>'This Week','count'=>N], ... ]
    $statusKey  = $cfg['status_key'];    // completed | pending | confirmed | no-show | upcoming
    $showTabs   = array_key_exists('show_tabs',  $cfg) ? (bool)$cfg['show_tabs']  : true;
    $showDate   = array_key_exists('show_date',  $cfg) ? (bool)$cfg['show_date']  : true;
    $showHeader = array_key_exists('show_header',$cfg) ? (bool)$cfg['show_header']: true;
    $showBack   = array_key_exists('show_back',  $cfg) ? (bool)$cfg['show_back']  : true;
$maxRows   = isset($cfg['max_rows']) ? (int)$cfg['max_rows'] : 0;
    $headNote  = isset($cfg['head_note']) ? $cfg['head_note'] : date('M d, Y') . ' · ' . count($list) . ' showing';

    $dentistName = 'Dr. ' . trim((string)($_SESSION['full_name'] ?? 'Dentist'));
    ?>
    <div id="dRecord-<?= $view ?>" class="d-modal-overlay d-record-overlay">
        <div class="d-modal-box d-record-box<?= $showHeader ? '' : ' d-record-box-plain' ?>" role="dialog" aria-modal="true" aria-label="<?= dentistCardEscape($title) ?>">
            <?php if ($showHeader): ?>
            <div class="d-modal-head">
                <span class="d-modal-head-icon"><i class="bi <?= $icon ?>"></i></span>
                <div>
                    <h4><?= dentistCardEscape($title) ?></h4>
                    <small><?= dentistCardEscape($sub) ?></small>
                </div>
                <button type="button" class="d-modal-x" aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>
            <?php else: ?>
            <button type="button" class="d-modal-x d-record-x-float" aria-label="Close"><i class="bi bi-x-lg"></i></button>
            <?php endif; ?>
            <div class="d-record-body">
                <div class="card-record">
                    <?php if ($showBack): ?>
                    <button type="button" class="detail-back-btn card-record-back"><i class="bi bi-arrow-left"></i> Back to Dashboard</button>
                    <?php endif; ?>
                    <?php if ($showTabs): ?>
                    <div class="detail-tabs" data-tab-group="<?= $view ?>">
                        <?php foreach ($tabs as $tab): ?>
                        <button type="button" class="detail-tab<?= $tab['filter'] === 'all' ? ' active' : '' ?>" data-filter="<?= $tab['filter'] ?>"><?= $tab['label'] ?> (<?= (int)$tab['count'] ?>)</button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="d-tbl-wrap">
                    <?php if (empty($list)): ?>
                        <div class="empty-state"><i class="bi <?= $emptyIcon ?>"></i><p><?= dentistCardEscape($emptyMsg) ?></p></div>
                    <?php else: $n = 1; ?>
                        <div class="d-tbl-head">
                            <span class="d-tbl-head-icon"><i class="bi <?= $icon ?>"></i></span>
                            <div class="d-tbl-head-txt">
                                <strong><?= dentistCardEscape($headLabel) ?></strong>
                                <small><?= dentistCardEscape($headNote) ?></small>
                            </div>
                            <span class="d-tbl-head-count"><?= count($list) ?></span>
                        </div>
                        <div class="d-tbl-scroll<?= $maxRows > 0 ? ' d-tbl-scroll-v' : '' ?>"<?= $maxRows > 0 ? ' style="max-height:' . (int)($maxRows * 46 + 4) . 'px"' : '' ?>>
                        <table class="d-table">
                            <thead>
                                <tr>
                                    <th class="d-col-num">#</th>
                                    <th>Patient Name</th>
                                    <th>Time of Appointment</th>
                                    <th>Dental Service</th>
                                    <th>Assigned Dentist</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                        <?php
                        foreach ($list as $a):
                            $pn = trim(($a['patient_name'] ?? '')) !== '' ? $a['patient_name'] : 'Patient';
                            $dstr = substr((string)($a['appointment_date'] ?? ''), 0, 10);
                            $ts = 0;
                            if ($dstr !== '') $ts = strtotime($dstr . ' ' . ($a['appointment_time'] ?? ''));

                            $tStart = !empty($a['appointment_time']) ? date('h:i A', strtotime($a['appointment_time'])) : '—';
                            $tEnd   = !empty($a['end_time']) ? date('h:i A', strtotime($a['end_time'])) : '';
                            $tmFull = (($showDate && $ts) ? date('M d, Y', $ts) . ' ' : '') . $tStart . ($tEnd !== '' ? ' – ' . $tEnd : '');

                            $st = trim($a['status'] ?? '');
                            if ($st === '') $st = ucfirst(str_replace('-', ' ', $statusKey));
                            $fk = $st !== '' ? dentistCardStatusKey($st) : $statusKey;

                            $inW     = ($dstr >= $dWeekStartStr && $dstr <= $dWeekEndStr);
                            $inM     = (substr($dstr, 0, 7) === $dMonthPrefix);
                            $beyond  = ($dstr > $dMonthEndStr);
                        ?><tr class="d-tbl-row"
                                data-today="<?= $dstr === $dTodayStr ? '1' : '0' ?>"
                                data-week="<?= $inW ? '1' : '0' ?>"
                                data-month="<?= $inM ? '1' : '0' ?>"
                                data-beyond="<?= $beyond ? '1' : '0' ?>"
                                data-status="<?= dentistCardEscape($fk) ?>">
                            <td class="d-col-num"><span><?= $n++ ?></span></td>
                            <td><strong><?= dentistCardEscape($pn) ?></strong></td>
                            <td class="d-tbl-when"><?= dentistCardEscape($tmFull) ?></td>
                            <td><?= dentistCardEscape($a['service_type'] ?? '—') ?></td>
                            <td><?= dentistCardEscape($dentistName) ?></td>
                            <td><span class="status <?= dentistCardEscape($fk) ?>"><?= dentistCardEscape($st) ?></span></td>
                            <td>
                                <button type="button" class="d-view-btn"
                                    data-d-patient="<?= dentistCardEscape($pn) ?>"
                                    data-d-service="<?= dentistCardEscape($a['service_type'] ?? '—') ?>"
                                    data-d-dentist="<?= dentistCardEscape($dentistName) ?>"
                                    data-d-time="<?= dentistCardEscape($tmFull) ?>"
                                    data-d-status="<?= dentistCardEscape($st) ?>"
                                    data-d-notes="<?= dentistCardEscape($a['notes'] ?? '') ?>"
                                    data-tip="View Details"><i class="bi bi-eye"></i> View</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Dentist Dashboard</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >
    <link rel="stylesheet" href="dentist_sidebar.css">

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: "Inter","DM Sans",system-ui,sans-serif;
        }

        body {
            background: #f4f7fb;
            color: #1f2937;
        }

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;

            width: 255px;
            height: 100vh;

            background: #0f766e;
            color: white;

            padding: 25px 15px;

            overflow-y: auto;
        }

        .sidebar h2 {
            text-align: center;
            margin-bottom: 30px;
            font-size: 22px;
        }

        .dentist-name {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,.2);
        }

        .dentist-name strong {
            display: block;
            font-size: 17px;
            margin-bottom: 5px;
        }

        .dentist-name span {
            font-size: 13px;
            opacity: .8;
        }

        .sidebar a {
            display: block;

            color: white;
            text-decoration: none;

            padding: 13px 15px;

            margin-bottom: 8px;

            border-radius: 8px;

            transition: .2s;
        }

        .sidebar a:hover {
            background: rgba(255,255,255,.15);
        }

        .main {
            margin-left: 255px;
            min-height: 100vh;
        }

        .topbar {
            height: 75px;

            background: white;

            display: flex;
            justify-content: space-between;
            align-items: center;

            padding: 0 30px;

            box-shadow: 0 2px 10px rgba(0,0,0,.06);
        }

        .topbar h1 {
            font-size: 25px;
        }

        .notification {
            position: relative;
            cursor: pointer;
            font-size: 25px;
        }

        .notification-count {
            position: absolute;

            top: -7px;
            right: -10px;

            background: #dc2626;
            color: white;

            width: 20px;
            height: 20px;

            border-radius: 50%;

            font-size: 11px;

            display: flex;
            align-items: center;
            justify-content: center;
        }

        .content {
            padding: 30px;
        }

        .cards {
            display: grid;

            grid-template-columns:
                repeat(3, minmax(0, 1fr));

            gap: 20px;

            margin-bottom: 30px;
        }

        .card {
            background: white;

            padding: 25px;

            border-radius: 12px;

            box-shadow: 0 3px 15px rgba(0,0,0,.06);
        }

        .card h3 {
            font-size: 14px;
            color: #6b7280;

            margin-bottom: 10px;
        }

        .card .number {
            font-size: 30px;
            font-weight: bold;
        }

        .section {
            background: white;

            border-radius: 12px;

            padding: 25px;

            margin-bottom: 25px;

            box-shadow: 0 3px 15px rgba(0,0,0,.06);
        }

        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: center;

            margin-bottom: 20px;
        }

        .section-title h2 {
            font-size: 20px;
        }

        /* Accordion tabs: Today's Appointments / Today's Queue / Upcoming */
        .section-tab .section-title {
            cursor: pointer;
            user-select: none;
            margin-bottom: 0;
        }
        .section-tab .section-title:hover h2 {
            color: #0E6B5E;
        }
        .section-tab .tab-chevron {
            font-size: 18px;
            color: #0E6B5E;
            transition: transform .25s ease;
        }
        .section-tab.open .tab-chevron {
            transform: rotate(180deg);
        }
        .section-tab .section-body {
            display: none;
            padding-top: 20px;
        }
        .section-tab.open .section-body {
            display: block;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f8fafc;

            color: #64748b;

            font-size: 13px;

            text-align: left;

            padding: 13px;
        }

        td {
            padding: 14px 13px;

            border-bottom: 1px solid #edf2f7;

            font-size: 14px;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .status {
            display: inline-block;

            padding: 6px 10px;

            border-radius: 20px;

            font-size: 12px;

            font-weight: bold;
        }

        .approved {
            background: #dcfce7;
            color: #166534;
        }

        .pending {
            background: #fef3c7;
            color: #92400e;
        }

        .completed {
            background: #dbeafe;
            color: #1e40af;
        }

        .cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .confirmed {
            background: #dcfce7;
            color: #166534;
        }

        .no-show {
            background: #fee2e2;
            color: #991b1b;
        }

        .calendar {
            display: grid;

            grid-template-columns:
                repeat(7, 1fr);

            gap: 8px;
        }

        .calendar-header {
            text-align: center;

            padding: 10px;

            background: #f1f5f9;

            font-weight: bold;

            font-size: 13px;
        }

        .calendar-day {
            min-height: 100px;

            border: 1px solid #e2e8f0;

            border-radius: 8px;

            padding: 8px;

            background: white;
        }

        .calendar-date {
            font-weight: bold;
            margin-bottom: 7px;
        }

        .calendar-event {
            background: #ccfbf1;

            color: #115e59;

            padding: 5px;

            border-radius: 5px;

            margin-bottom: 4px;

            font-size: 11px;
        }

        .patient-grid {
            display: grid;

            grid-template-columns:
                repeat(3, minmax(0, 1fr));

            gap: 15px;
        }

        .patient-card {
            border: 1px solid #e2e8f0;

            padding: 18px;

            border-radius: 10px;
        }

        .patient-card h3 {
            margin-bottom: 7px;
        }

        .patient-card p {
            color: #64748b;

            font-size: 13px;
        }

        .empty {
            text-align: center;

            padding: 30px;

            color: #94a3b8;
        }

        @media(max-width: 1000px) {

            .cards {
                grid-template-columns:
                    repeat(2, 1fr);
            }

            .patient-grid {
                grid-template-columns:
                    repeat(2, 1fr);
            }

        }

        @media(max-width: 700px) {

            .sidebar {
                position: relative;

                width: 100%;

                height: auto;
            }

            .main {
                margin-left: 0;
            }

            .cards {
                grid-template-columns: 1fr;
            }

            .patient-grid {
                grid-template-columns: 1fr;
            }

            .calendar {
                overflow-x: auto;

                min-width: 700px;
            }

            .section {
                overflow-x: auto;
            }

        }

        /* Match the patient and admin dashboard visual system. */
        :root {
            --clinic-blue: #0E6B5E;
            --clinic-ink: #173b4d;
            --clinic-muted: #8499a1;
            --clinic-line: #808080;
            --clinic-bg: #0A3B34;
        }

        html {
            scroll-behavior: smooth;
        }

        .section,
        #dashboard {
            scroll-margin-top: 96px;
        }

        /* The dashboard is a summary page; detailed modules have their own pages. */
        #appointments,
        #calendar,
        #patients,
        #completed,
        #cancelled {
            display: none;
        }

        body {
            font-family: "DM Sans", sans-serif;
            background: var(--clinic-bg);
            color: var(--clinic-ink);
        }

        .sidebar {
            width: 255px;
            background: linear-gradient(180deg, #0A3B34 0%, #0E6B5E 100%);
            border-right: 1px solid var(--clinic-line);
            padding: 25px 17px;
        }

        .sidebar h2 {
            text-align: left;
            color: #143f51;
            font-family: "Plus Jakarta Sans", sans-serif;
            font-size: 15px;
            font-weight: 800;
            padding: 5px 10px 28px;
            margin-bottom: 27px;
            border-bottom: 1px solid #808080;
        }

        .dentist-name {
            text-align: left;
            margin: -12px 10px 18px;
            padding: 0 0 16px;
            border-bottom: 1px solid #808080;
        }

        .dentist-name strong {
            color: #244f60;
            font-size: 13px;
            font-family:"Inter",system-ui,sans-serif;
        }

        .dentist-name span {
            color: var(--clinic-muted);
            font-size: 11px;
            font-family:"Inter",system-ui,sans-serif;
        }

        .sidebar a {
            color: #C9D9D1;
            font-size: 14px;
            font-family:"Inter","DM Sans",system-ui,sans-serif;
            letter-spacing:-0.01em;
            font-weight: 600;
            padding: 12px 13px;
            border-radius: 10px;
            margin-bottom: 4px;
        }

        .sidebar a:hover {
            background: #eaf9fc;
            color: var(--clinic-blue);
        }

        .main {
            margin-left: 255px;
            width: calc(100% - 255px);
        }

        .topbar {
            height: 78px;
            border-bottom: 1px solid #e2edf0;
            padding: 0 38px;
            box-shadow: none;
        }

        .topbar h1 {
            color: #153f50;
            font-family: "Plus Jakarta Sans", sans-serif;
            font-size: 22px;
            font-weight: 800;
        }

        .content {
            padding: 32px 38px 45px;
        }

        .cards {
            gap: 16px;
            margin-bottom: 25px;
        }

        .card,
        .section {
            border: 1px solid #808080;
            border-radius: 15px;
            box-shadow: none;
        }

        .card {
            padding: 21px;
            transition: .2s;
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(22, 83, 101, .07);
        }

        .card h3 {
            color: #244f60;
            font-size: 12px;
            font-family:"Inter",system-ui,sans-serif;
            margin-bottom: 4px;
        }

        .card .number {
            color: #0f2f3d;
            font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif;
            font-size: 24px;
            letter-spacing:-0.02em; font-variant-numeric:tabular-nums;
        }

        .section {
            padding: 0;
            margin-bottom: 25px;
        }

        .section-title {
            padding: 19px 21px;
            margin-bottom: 0;
            border-bottom: 1px solid #808080;
        }

        .section-title h2 {
            color: #244e5e;
            font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif;
            font-size: 15.5px;
            letter-spacing:-0.015em;
            font-weight: 800;
        }

        .section-title span {
            color: var(--clinic-muted);
            font-size: 10px;
        }

        .section > table,
        .section > .empty,
        .section > .patient-grid,
        .section > #calendarContainer {
            margin: 0 21px 21px;
        }

        th {
            background: #f8fcfd;
            color: #8ca0a7;
            font-size: 11.5px;
            font-family:"Inter",system-ui,sans-serif;
            padding: 13px;
        }

        td {
            color: #57727d;
            padding: 14px 13px;
            border-bottom: 1px solid #808080;
            font-size: 13px;
            font-family:"Inter","DM Sans",system-ui,sans-serif;
        }

        td strong {
            color: #244f60;
        }

        td a,
        .patient-card a {
            color: var(--clinic-blue);
            font-size: 10px;
            font-weight: 700;
        }

        .status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-family:"Inter",system-ui,sans-serif;
        }

        .calendar-header {
            background: #eaf9fc;
            color: #57727d;
            font-size: 10px;
        }

        .calendar-day {
            border: 1px solid #808080;
            border-radius: 10px;
            background: #ffffff;
        }

        .calendar-event {
            background: #e4f7fa;
            color: #087d95;
        }

        .patient-grid {
            gap: 16px;
        }

        .patient-card {
            border: 1px solid #808080;
            border-radius: 12px;
            padding: 18px;
        }

        .patient-card h3 {
            color: #244f60;
            font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif;
            font-size: 14.5px;
        }

        .patient-card p,
        .empty {
            color: var(--clinic-muted);
            font-size: 13px;
            font-family:"Inter",system-ui,sans-serif;
        }

        @media(max-width: 700px) {
            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
            }

            .main {
                margin-left: 0;
                width: 100%;
            }

            .topbar,
            .content {
                padding-left: 20px;
                padding-right: 20px;
            }
        }

        .patient-sidebar {
            overflow-y: auto;
        }

        .patient-brand {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 5px 10px 28px;
            border-bottom: 1px solid #808080;
        }

        .brand-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--clinic-blue);
            border: 1px solid rgba(201,162,39,.55);
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 7px 18px rgba(14, 107, 94, .18);
            flex-shrink: 0;
        }

        .brand-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .brand-title {
            color: #ffffff;
            font-family: "Fraunces","Plus Jakarta Sans","Inter",system-ui,sans-serif;
            font-size: 16px;
            letter-spacing:-0.02em;
            font-weight: 800;
            line-height: 1.2;
        }

        .brand-subtitle,
        .page-small {
            color: #9CC4B6;
            font-size: 11px;
            font-family:"Inter",system-ui,sans-serif;
            letter-spacing: .8px;
            margin-top: 4px;
        }

        .patient-menu {
            margin-top: 27px;
        }

        .menu-label {
            color: #8FAE9F;
            font-size: 11px;
            font-family:"Inter",system-ui,sans-serif;
            font-weight: 700;
            letter-spacing: 1.2px;
            padding: 0 12px;
            margin-bottom: 10px;
        }

        .patient-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .patient-menu a i {
            width: 19px;
            text-align: center;
            font-size: 15px;
        }

        .patient-menu a.active {
            background: linear-gradient(135deg, #C9A227, #A8861B);
            color: #0A3B34;
            box-shadow: 0 8px 20px rgba(201,162,39,.35);
        }

        .patient-menu a.active i {
            color: #0A3B34;
        }

        .menu-divider {
            height: 1px;
            background: #808080;
            margin: 18px 10px;
        }

        .logout-link:hover {
            background: rgba(255,255,255,.15) !important;
            color: white !important;
        }

        .dentist-tagline {
            margin-top: 18px;
            padding: 14px 12px 4px;
            border-top: 1px solid rgba(255,255,255,.15);
            text-align: center;
        }

        .dentist-tagline-tooth {
            font-size: 26px;
            line-height: 1;
            margin-bottom: 6px;
        }

        .dentist-tagline-text {
            color: #C9A227;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .4px;
            line-height: 1.5;
        }

        .patient-topbar {
            display: flex;
            align-items: center;
        }

        .page-heading {
            color: #153f50;
            font-family: "Fraunces","Plus Jakarta Sans",sans-serif;
            font-size: 22px;
            font-weight: 800;
            margin: 0;
        }

        .patient-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .profile-circle {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            background: #e4f7fa;
            color: var(--clinic-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
        }

        .profile-name {
            display: block;
            color: #244f60;
            font-size: 13px;
            font-family:"Inter",system-ui,sans-serif;
            font-weight: 700;
            text-align: right;
        }

        .profile-role {
            color: #8ca0a8;
            font-size: 11px;
            font-family:"Inter",system-ui,sans-serif;
            text-align: right;
            margin-top: 2px;
        }

        .patient-topbar .notification {
            color: var(--clinic-blue);
            font-size: 17px;
        }

        /* Fix: Settings button must stay visible and not move when other buttons are clicked */
        .patient-menu a[href="settings.php"] {
            display: flex !important;
            visibility: visible !important;
            opacity: 1 !important;
            position: relative;
            transform: none !important;
        }
        .patient-menu a { position: relative; }

        /* Sync with admin/patient button system */
        .card {
            background: #ffffff;
            border: 1px solid #808080;
            border-radius: 15px;
            padding: 21px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            transition: .2s;
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(22,83,101,.07);
        }

        .card[data-card] {
            cursor: pointer;
        }

        .card[data-card]:focus-visible {
            outline: 2px solid #149A72;
            outline-offset: 2px;
        }

        a.card {
            display: flex;
            text-decoration: none;
            color: inherit;
        }

        .card h3 {
            color: #244f60;
            font-size: 12px;
            font-family:"Inter",system-ui,sans-serif;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .card .number {
            color: #0f2f3d;
            font-family: "Fraunces","Plus Jakarta Sans","Inter",system-ui,sans-serif;
            font-size: 24px;
            letter-spacing:-0.02em; font-variant-numeric:tabular-nums;
            font-weight: 800;
        }

        /* ===== Welcome Hero — Clinical Calm gradient banner ===== */
        .d-hero {
            background: linear-gradient(120deg, #149A72 0%, #0E6B5E 48%, #0A4A41 100%);
            border-radius: 20px;
            padding: 26px 28px;
            color: #fff;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            box-shadow: 0 18px 40px rgba(14,107,94,.22);
            margin-bottom: 25px;
        }
        .d-hero::after {
            content: "";
            position: absolute;
            width: 210px;
            height: 210px;
            border-radius: 50%;
            background: rgba(255,255,255,.08);
            right: -65px;
            top: -95px;
        }
        .d-hero-text { position: relative; z-index: 2; }
        .d-hero-label {
            font-size: 11px; font-weight: 700; letter-spacing: 1px;
            opacity: .8; margin-bottom: 8px;
            font-family: "Inter",system-ui,sans-serif;
        }
        .d-hero h2 {
            font-family: "Fraunces","Plus Jakarta Sans","Inter",system-ui,sans-serif;
            font-size: 24px; font-weight: 800; letter-spacing: -0.02em;
            margin: 0 0 6px; color: #fff;
        }
        .d-hero p { color: #D9F2E6; font-size: 13.5px; margin: 0; }
        .d-hero-icon {
            position: relative; z-index: 2; flex-shrink: 0;
            width: 64px; height: 64px; border-radius: 18px;
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(201,162,39,.55);
            display: flex; align-items: center; justify-content: center;
            font-size: 30px; color: #fff;
        }

        /* ===== Clinical charts ===== */
        .d-charts {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 16px;
            margin-bottom: 25px;
        }
        .d-chart-card {
            background: #fff;
            border: 1px solid #808080;
            border-radius: 18px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 18px rgba(22,83,101,.05);
        }
        .d-chart-wide { grid-column: 1 / -1; }
        .d-chart-top {
            padding: 16px 20px;
            border-bottom: 1px solid #808080;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            background: linear-gradient(180deg, #FDFEFB, #ffffff);
        }
        .d-chart-head { display: flex; align-items: center; gap: 12px; }
        .d-chart-icon {
            width: 42px; height: 42px; border-radius: 13px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 19px;
        }
        .d-icon-blue { background: linear-gradient(135deg,#D9F2E6,#B9E4CF); color: #0E6B5E; }
        .d-icon-green { background: linear-gradient(135deg,#e7f8ef,#c8ecd9); color: #15945a; }
        .d-chart-top h4 {
            color: #1c4454;
            font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif;
            font-size: 14.5px; font-weight: 800; margin: 0;
        }
        .d-chart-top small { color: #8ca0a8; font-size: 11.5px; }
        .d-chart-pill {
            font-size: 11px; font-weight: 800; padding: 6px 13px;
            border-radius: 20px; white-space: nowrap;
            background: linear-gradient(135deg, #149A72, #0A4A41);
            color: #fff; box-shadow: 0 4px 12px rgba(14,107,94,.3);
        }
        .d-chart-body { padding: 18px 20px 20px; position: relative; height: 270px; }
        .d-chart-body canvas { width: 100% !important; height: 100% !important; }
        .d-chart-doughnut { height: 340px; }
        .d-chart-center {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -58%); text-align: center; pointer-events: none;
        }
        .d-chart-center strong {
            display: block; font-family: "Fraunces","Plus Jakarta Sans",sans-serif;
            font-size: 30px; font-weight: 800; color: #143f51; line-height: 1;
        }
        .d-chart-center span {
            font-size: 10.5px; color: #8ca0a8; font-weight: 700;
            letter-spacing: .8px; text-transform: uppercase;
        }
        /* Dynamic insight chip sa ilalim ng header ng bawat chart card */
        .d-chart-note {
            display: flex; align-items: flex-start; gap: 9px;
            margin: 12px 20px 0; padding: 11px 13px;
            background: linear-gradient(135deg, #F1FAF6, #E7F7F0);
            border: 1px solid #CBECE0; border-radius: 11px;
            font-size: 12.5px; line-height: 1.6; color: #4d6873;
        }
        .d-chart-note i { color: #0E6B5E; font-size: 13.5px; margin-top: 3px; flex-shrink: 0; }
        .d-chart-note strong { color: #0E6B5E; font-weight: 800; }
        .d-chart-card.d-acc:not(.open) .d-chart-note { display: none; }
        /* Accordion: My Week Ahead / Treatment Outcomes — isa lang bukas */
        .d-charts.d-acc-wrap { grid-template-columns: 1fr; }
        .d-chart-card.d-acc .d-chart-top { cursor: pointer; user-select: none; }
        .d-chart-card.d-acc .d-chart-top:hover h4 { color: #0E6B5E; }
        .d-acc-chevron { font-size: 18px; color: #0E6B5E; transition: transform .25s ease; flex-shrink: 0; }
        .d-chart-card.open .d-acc-chevron { transform: rotate(180deg); }
        .d-chart-card.d-acc:not(.open) .d-chart-body { display: none; }
        /* My Week Ahead + Treatment Outcomes: kasing-lapad ng 3 summary cards (full row) */
        .d-charts.d-acc-wrap > .d-chart-card {
            width: 100%;
            max-width: 100%;
            justify-self: stretch;
        }
        @media (max-width: 1000px) {
            .d-charts.d-acc-wrap > .d-chart-card { max-width: none; }
        }
        @media (max-width: 991px) {
            .d-charts { grid-template-columns: 1fr; }
            .d-chart-wide { grid-column: auto; }
            .d-hero-icon { display: none; }
        }

        .btn-primary {
            background: #0E6B5E !important;
            border-color: #0E6B5E !important;
        }

        .btn-primary:hover {
            background: #0A4A41 !important;
            border-color: #0A4A41 !important;
        }

    </style>

</head>

<body>

<style>
body{background:linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22)!important;background-size:300% 300%!important;animation:dentistDashBg 8s ease infinite!important;}
.main{background:transparent!important;}
@keyframes dentistDashBg{0%{background-position:0% 50%;}50%{background-position:100% 50%;}100%{background-position:0% 50%;}}
</style>


<!-- ==========================================================
     SIDEBAR
========================================================== -->

<?php
$dentistActivePage = 'dashboard';
include __DIR__ . '/dentist_sidebar.php';
?>


<!-- ==========================================================
     MAIN
========================================================== -->

<div class="main">


    <!-- TOP BAR -->

    <header class="topbar patient-topbar">

        <div>
            <div class="page-heading">Dentist Dashboard</div>
            <div class="page-small">Abalos Dental Clinic</div>
        </div>

        <div class="patient-profile" id="profile">

            <div class="profile-circle">
                <i class="bi bi-person-fill"></i>
            </div>

            <div>
                <div class="profile-name">
                    <?= htmlspecialchars($dentist['name']) ?>
                </div>
                <div class="profile-role">Dentist</div>
            </div>

        </div>

    </header>


    <div class="content" id="dashboard">

        <?php if (isset($_GET['saved'])): ?>
        <div style="margin:0 0 14px;background:#EAF7F0;border:1.5px solid #0E6B5E;border-radius:12px;padding:11px 16px;color:#0A4A41;font-size:13px;font-weight:700;"><i class="bi bi-check-circle-fill"></i> Appointment status updated successfully.</div>
        <?php endif; ?>
        <?php if (isset($_GET['save_failed'])): ?>
        <div style="margin:0 0 14px;background:#FDECEC;border:1.5px solid #b42318;border-radius:12px;padding:11px 16px;color:#7a271a;font-size:13px;font-weight:700;"><i class="bi bi-exclamation-triangle-fill"></i> Failed to save status — please try again or contact the administrator.</div>
        <?php endif; ?>


        <!-- ==================================================
             WELCOME HERO
        ================================================== -->

        <div class="d-hero" id="welcomeHero">

            <div class="d-hero-text">

                <div class="d-hero-label">
                    Abalos Dental Clinic · Dentist Portal
                </div>

                <h2>
                    Welcome back,
                    <?= htmlspecialchars($dentist['name']) ?>!
                </h2>

                <p>
                    You have <?= (int) $todayCount ?> appointment<?= $todayCount == 1 ? '' : 's' ?> today
                    and <?= (int) $queueCount ?> patient<?= $queueCount == 1 ? '' : 's' ?> in queue.
                </p>

            </div>

            <div class="d-hero-icon">
                <i class="bi bi-heart-pulse-fill"></i>
            </div>

        </div>


        <!-- ==================================================
             DASHBOARD CARDS
        ================================================== -->

        <div class="cards" id="statsGrid">

            <div class="card stat-clickable stat-featured" data-view="today" role="button" tabindex="0" aria-label="View today's appointments" title="View today's appointments">

                <div class="stat-featured-head">
                    <span class="stat-featured-icon"><i class="bi bi-calendar-event-fill"></i></span>
                    <h3>Today's Appointments</h3>
                </div>

                <div class="number">
                    <?= $todayCount ?>
                </div>

                <div class="stat-featured-sub">
                    <span class="stat-featured-dot"></span>
                    Scheduled for today · <?= htmlspecialchars(date('M d, Y')) ?>
                </div>

                <span class="stat-go"><i class="bi bi-chevron-right"></i></span>


            </div>


            <div class="card stat-clickable stat-featured" data-view="upcoming" role="button" tabindex="0" aria-label="View upcoming appointments" title="View upcoming appointments">

                <div class="stat-featured-head">
                    <span class="stat-featured-icon"><i class="bi bi-calendar2-week-fill"></i></span>
                    <h3>Upcoming Appointments</h3>
                </div>

                <div class="number">
                    <?= $upcomingCount ?>
                </div>

                <div class="stat-featured-sub">
                    <span class="stat-featured-dot"></span>
                    Scheduled for upcoming days
                </div>

                <span class="stat-go"><i class="bi bi-chevron-right"></i></span>

            </div>


            <div class="card stat-clickable stat-featured" data-view="completed" role="button" tabindex="0" aria-label="View completed appointments" title="View completed appointments">

                <div class="stat-featured-head">
                    <span class="stat-featured-icon"><i class="bi bi-check-circle-fill"></i></span>
                    <h3>Completed Appointments</h3>
                </div>

                <div class="number">
                    <?= $completedCount ?>
                </div>

                <div class="stat-featured-sub">
                    <span class="stat-featured-dot"></span>
                    Successfully attended
                </div>

                <span class="stat-go"><i class="bi bi-chevron-right"></i></span>

            </div>


            <div class="card stat-clickable stat-featured" data-view="pending" role="button" tabindex="0" aria-label="View pending appointments" title="View pending appointments">

                <div class="stat-featured-head">
                    <span class="stat-featured-icon"><i class="bi bi-hourglass-split"></i></span>
                    <h3>Pending Appointments</h3>
                </div>

                <div class="number">
                    <?= $pendingCount ?>
                </div>

                <div class="stat-featured-sub">
                    <span class="stat-featured-dot"></span>
                    Waiting for confirmation
                </div>

                <span class="stat-go"><i class="bi bi-chevron-right"></i></span>

            </div>


            <div class="card stat-clickable stat-featured" data-view="confirmed" role="button" tabindex="0" aria-label="View confirmed appointments" title="View confirmed appointments">

                <div class="stat-featured-head">
                    <span class="stat-featured-icon"><i class="bi bi-calendar2-check-fill"></i></span>
                    <h3>Confirmed Appointments</h3>
                </div>

                <div class="number">
                    <?= $confirmedCount ?>
                </div>

                <div class="stat-featured-sub">
                    <span class="stat-featured-dot"></span>
                    Confirmed appointments
                </div>

                <span class="stat-go"><i class="bi bi-chevron-right"></i></span>

            </div>


            <div class="card stat-clickable stat-featured" data-view="noshow" role="button" tabindex="0" aria-label="View no-show patients" title="View no-show patients">

                <div class="stat-featured-head">
                    <span class="stat-featured-icon"><i class="bi bi-person-x-fill"></i></span>
                    <h3>No-Show Patients</h3>
                </div>

                <div class="number">
                    <?= (int) ($noShowCount ?? 0) ?>
                </div>

                <div class="stat-featured-sub">
                    <span class="stat-featured-dot"></span>
                    Missed scheduled appointments
                </div>

                <span class="stat-go"><i class="bi bi-chevron-right"></i></span>

            </div>

        </div>

        <!-- CLICKABLE CARD DETAIL VIEWS (same page, no new tab — gaya ng admin) -->
        <style>
            .stat-clickable{cursor:pointer;position:relative;user-select:none;-webkit-tap-highlight-color:transparent}
            .stat-clickable:hover{border-color:#0E6B5E !important}
            .stat-clickable:focus-visible{outline:2px solid #0E6B5E;outline-offset:2px}
            .stat-go{position:absolute;right:14px;bottom:14px;width:26px;height:26px;border-radius:50%;background:#EAF7F0;color:#0E6B5E;display:inline-flex;align-items:center;justify-content:center;font-size:13px}
            .stat-clickable.active-card{border-width:2px !important;box-shadow:0 12px 30px rgba(14,107,94,.14);transform:translateY(-3px)}
            .stat-clickable.active-card[data-view="today"]{border-color:#2563eb !important}
            .stat-clickable.active-card[data-view="upcoming"]{border-color:#0891b2 !important}
            .stat-clickable.active-card[data-view="completed"]{border-color:#7c3aed !important}
            .stat-clickable.active-card[data-view="pending"]{border-color:#d97706 !important}
            .stat-clickable.active-card[data-view="confirmed"]{border-color:#059669 !important}
            .stat-clickable.active-card[data-view="noshow"]{border-color:#e11d48 !important}
            #cardDetailWrap{margin-bottom:25px}
            #detailNav{display:none;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:14px}
            .detail-back-row{display:none;margin:0 0 14px}
            .detail-back-row.is-open{display:block}
            .detail-back-btn{display:inline-flex;align-items:center;gap:7px;border:1px solid #B9E4CF;background:#EAF7F0;color:#0E6B5E;font-size:12.5px;font-weight:700;padding:8px 15px;border-radius:999px;cursor:pointer;transition:.2s;font-family:inherit}
            .detail-back-btn:hover{background:#0E6B5E;border-color:#0E6B5E;color:#fff}
            .detail-back-btn i{font-size:13px}
            .detail-nav-card{display:flex;align-items:center;gap:9px;background:#F2F9F4;border:1px solid #e3eeea;border-radius:12px;padding:11px 12px;cursor:pointer;user-select:none;transition:.15s}
            .detail-nav-card:hover{border-color:#0E6B5E;transform:translateY(-2px);box-shadow:0 8px 20px rgba(14,107,94,.12)}
            .detail-nav-card.active-nav{border-color:#0E6B5E;border-width:2px;background:#EAF7F0}
            .detail-nav-card small{display:block;color:#8ca0a8;font-size:10px;font-weight:600;line-height:1.2}
            .detail-nav-card strong{display:block;color:#173f50;font-size:16px;font-weight:800;line-height:1.2}
            .detail-nav-icon{width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;background:#D9F2E6;color:#0E6B5E}
            .card-detail{background:#fff;border:1px solid #808080;border-radius:18px;overflow:hidden;margin-bottom:16px;animation:cardDetailIn .25s ease}
            @keyframes cardDetailIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
            .card-top{padding:19px 21px;border-bottom:1px solid #808080;background:linear-gradient(180deg,#FDFEFB,#fff);display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
            .card-top h3{color:#1c4454;font-size:16px;font-weight:800;margin:6px 0 4px;display:flex;align-items:center;gap:10px}
            .detail-crumb{font-size:11px;color:#8ca0a8;font-weight:700}
            .detail-sub{color:#8ca0a8;font-size:12.5px;margin:0}
            .detail-date-pill{display:inline-flex;align-items:center;gap:7px;background:#EAF7F0;border:1px solid #B9E4CF;color:#0E6B5E;font-size:12px;font-weight:700;border-radius:20px;padding:7px 13px;white-space:nowrap}
            .card-body-custom{padding:18px 21px 21px}
            .detail-mini-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px}
            /* ===== MINI TILES: emerald glass ===== */
            .detail-mini{position:relative;overflow:hidden;display:flex;align-items:center;gap:11px;background:linear-gradient(160deg,#F7FDFB,#EAF8F1);border:1px solid #CBECE0;border-radius:14px;padding:12px 13px 12px 17px}
            .detail-mini::before{content:"";position:absolute;left:0;top:0;bottom:0;width:5px;background:linear-gradient(180deg,#149A72,#0A4A41)}
            .detail-mini-clickable{cursor:pointer;transition:.15s}
            .detail-mini-clickable:hover{border-color:#0E6B5E;transform:translateY(-2px);box-shadow:0 10px 22px rgba(14,107,94,.14)}
            .detail-mini-clickable.active-mini{border-color:#0E6B5E;background:linear-gradient(160deg,#E9F9F1,#D5F2E5);box-shadow:0 10px 24px rgba(14,107,94,.18),inset 0 0 0 1px #0E6B5E}
            .detail-mini-icon{width:36px;height:36px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;background:linear-gradient(135deg,#149A72,#0A4A41);color:#fff;box-shadow:0 6px 14px rgba(14,107,94,.26)}
            .detail-mini small{display:block;color:#7f9aa4;font-size:10px;font-weight:800;letter-spacing:.5px;text-transform:uppercase}
            .detail-mini strong{display:block;color:#0E6B5E;font-size:19px;font-weight:800;line-height:1.15;font-variant-numeric:tabular-nums}
            /* ===== PROGRESS BAR ===== */
            .detail-progress{background:linear-gradient(160deg,#F7FDFB,#EDF9F3);border:1px solid #CBECE0;border-radius:14px;padding:12px 15px;margin-bottom:13px}
            .detail-progress-info{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:12px;font-weight:700;color:#4d6873;margin-bottom:8px}
            .detail-progress-info span{display:inline-flex;align-items:center;gap:7px}
            .detail-progress-info span i{color:#0E6B5E;font-size:13px}
            .detail-progress-info strong{color:#0E6B5E;font-size:13.5px;font-weight:800;font-variant-numeric:tabular-nums}
            .detail-progress-track{height:9px;border-radius:999px;background:#DDEEE7;overflow:hidden}
            .detail-progress-track span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#149A72,#0E6B5E);box-shadow:0 2px 8px rgba(14,107,94,.35);transition:width .5s ease}
            /* ===== TABS ===== */
            .detail-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
            .detail-tab{border:1px solid #D6E7E1;background:#fff;color:#244f60;font-size:12.5px;font-weight:700;padding:8px 15px;border-radius:999px;cursor:pointer;transition:.2s;font-family:inherit}
            .detail-tab:hover{border-color:#0E6B5E;color:#0E6B5E;background:#F1FAF6}
            .detail-tab.active{background:linear-gradient(135deg,#149A72,#0A4A41);border-color:#0A4A41;color:#fff;box-shadow:0 6px 14px rgba(14,107,94,.28)}
            /* ===== TIMELINE APPOINTMENT CARDS ===== */
            .d-tl{position:relative;display:flex;flex-direction:column;gap:12px;padding:4px 2px 2px}
            .d-tl::before{content:"";position:absolute;left:11px;top:18px;bottom:18px;width:2px;background:linear-gradient(180deg,#B9E4CF,#EAF7F0);border-radius:2px}
            .d-tl:has(.empty-state)::before{display:none}
            .d-tl-item{display:grid;grid-template-columns:24px 1fr;gap:13px;align-items:center}
            .d-tl-dot{width:14px;height:14px;border-radius:50%;background:#fff;border:3px solid #149A72;box-shadow:0 0 0 4px rgba(20,154,114,.16);z-index:1;justify-self:center;transition:.2s}
            .d-tl-card{display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:linear-gradient(160deg,#FDFEFE,#F5FAF9);border:1px solid #E3EEEA;border-left:4px solid var(--tl-accent,#149A72);border-radius:14px;padding:13px 15px;transition:.2s}
            .d-tl-card:hover{border-color:#B9E4CF;transform:translateX(4px);box-shadow:0 12px 26px rgba(14,107,94,.12)}
            .d-tl-item:hover .d-tl-dot{transform:scale(1.18)}
            .d-tl-time{min-width:92px;display:flex;flex-direction:column;line-height:1.25;padding-right:13px;border-right:1px dashed #D6E7E1}
            .d-tl-time strong{color:#143f51;font-size:13.5px;font-weight:800;font-variant-numeric:tabular-nums}
            .d-tl-time small{color:#8ca0a8;font-size:11px;font-weight:600}
            .d-tl-avatar{width:40px;height:40px;border-radius:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:#0E6B5E;background:linear-gradient(135deg,#D9F2E6,#B9E4CF);border:1px solid #B9E4CF;letter-spacing:.3px}
            .d-tl-info{flex:1;min-width:170px;display:flex;flex-direction:column;gap:3px}
            .d-tl-info strong{color:#143f51;font-size:14.5px;font-weight:800;line-height:1.25}
            .d-tl-info span{display:inline-flex;align-items:center;gap:6px;color:#7f9aa4;font-size:12.5px;font-weight:600}
            .d-tl-info span i{color:#0E6B5E;font-size:12px}
            .d-tl-num{margin-left:auto;width:26px;height:26px;border-radius:8px;background:#EFF6F3;color:#7f9aa4;font-size:11.5px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
            /* status accents sa timeline */
            .d-tl-item[data-status="pending"]{--tl-accent:#F5A623}
            .d-tl-item[data-status="confirmed"]{--tl-accent:#0E9F8F}
            .d-tl-item[data-status="completed"]{--tl-accent:#2563EB}
            .d-tl-item[data-status="cancelled"]{--tl-accent:#E15B5B}
            .d-tl-item[data-status="no-show"]{--tl-accent:#E11D48}
            .d-tl-item[data-status="pending"] .d-tl-dot{border-color:#F5A623;box-shadow:0 0 0 4px rgba(245,166,35,.18)}
            .d-tl-item[data-status="confirmed"] .d-tl-dot{border-color:#0E9F8F;box-shadow:0 0 0 4px rgba(14,159,143,.18)}
            .d-tl-item[data-status="completed"] .d-tl-dot{border-color:#2563EB;box-shadow:0 0 0 4px rgba(37,99,235,.16)}
            .d-tl-item[data-status="cancelled"] .d-tl-dot{border-color:#E15B5B;box-shadow:0 0 0 4px rgba(225,91,91,.16)}
            .d-tl-item[data-status="no-show"] .d-tl-dot{border-color:#E11D48;box-shadow:0 0 0 4px rgba(225,29,72,.16)}
            .status{display:inline-flex;align-items:center;gap:6px;padding:6px 13px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap;letter-spacing:.2px}
            .status.confirmed,.status.approved{background:#DCFCE7;color:#166534}
            .status.pending{background:#FEF3C7;color:#92400E}
            .status.completed{background:#DBEAFE;color:#1E40AF}
            .status.cancelled{background:#FEE2E2;color:#991B1B}
            .status.no-show{background:#FFE4E6;color:#9F1239}
            .empty-state{text-align:center;padding:34px 12px;color:#91a5ac}
            .empty-state i{display:block;font-size:30px;color:#B9E4CF;margin-bottom:10px}
            .empty-state p{margin:0;font-size:13.5px;font-weight:600}
            @media(max-width:1000px){#detailNav{grid-template-columns:repeat(3,1fr)}}
            @media(max-width:700px){#detailNav{grid-template-columns:repeat(2,1fr)}.detail-mini-grid{grid-template-columns:repeat(2,1fr)}.d-tl-time{border-right:none;min-width:0;flex-direction:row;gap:6px;align-items:baseline}.d-tl-card{gap:10px}}

            /* ===== FEATURED CARD: Today's Appointments (emerald gradient) ===== */
            .card.stat-featured{
                position:relative;overflow:hidden;gap:6px;padding:22px;
                background:linear-gradient(135deg,#0E6B5E 0%,#0F8A72 55%,#10B981 100%);
                border:1px solid rgba(255,255,255,.28)!important;
                border-radius:16px;
                box-shadow:0 16px 34px rgba(10,74,65,.34);
                transition:.25s;
            }
            .card.stat-featured::before,
            .card.stat-featured::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
            .card.stat-featured::before{width:170px;height:170px;top:-78px;right:-58px;background:radial-gradient(circle,rgba(255,255,255,.22),rgba(255,255,255,0) 70%)}
            .card.stat-featured::after{width:130px;height:130px;bottom:-60px;left:-40px;background:radial-gradient(circle,rgba(255,255,255,.16),rgba(255,255,255,0) 70%)}
            .card.stat-featured>*{position:relative;z-index:1}
            .stat-featured-head{display:flex;align-items:center;justify-content:space-between;gap:10px}
            .stat-featured-icon{width:44px;height:44px;border-radius:14px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.32);display:inline-flex;align-items:center;justify-content:center;font-size:20px;color:#ffffff;box-shadow:0 8px 18px rgba(0,0,0,.14)}
            .stat-featured-pill{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);color:#EAFFF6;font-size:10.5px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;padding:6px 11px;border-radius:999px;white-space:nowrap}
            .card.stat-featured h3{color:rgba(255,255,255,.92);font-size:12.5px;font-weight:700;letter-spacing:.2px;margin:8px 0 2px}
            .card.stat-featured .number{color:#ffffff;font-family:"Plus Jakarta Sans","Inter",system-ui,sans-serif;font-size:42px;line-height:1;font-weight:800;letter-spacing:-.03em;font-variant-numeric:tabular-nums;text-shadow:0 6px 18px rgba(0,0,0,.22)}
            .stat-featured-sub{display:flex;align-items:center;gap:8px;font-size:11.5px;font-weight:600;color:rgba(255,255,255,.86);margin-top:3px}
            .stat-featured-dot{width:8px;height:8px;border-radius:50%;background:#BBF7D0;box-shadow:0 0 0 4px rgba(187,245,208,.22);animation:statDotPulse 1.9s ease-in-out infinite;flex-shrink:0}
            @keyframes statDotPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.8)}}
            .card.stat-featured .stat-go{position:absolute;right:16px;bottom:16px;z-index:2;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.35);color:#ffffff}
            .card.stat-featured:hover{transform:translateY(-4px);box-shadow:0 22px 42px rgba(10,74,65,.42);border-color:rgba(255,255,255,.62)!important}
            .card.stat-featured.active-card{border-color:#ffffff!important;box-shadow:0 22px 42px rgba(10,74,65,.46);transform:translateY(-3px)}
        </style>
        <style>
            /* ===== TODAY'S APPOINTMENTS interior refresh (scoped lang sa #detail-today) ===== */
            #detail-today{border-color:#CBECE0;box-shadow:0 14px 34px rgba(14,107,94,.10)}
            #detail-today .card-top{background:linear-gradient(135deg,#0E6B5E 0%,#0F8A72 60%,#10B981 100%);border-bottom:none;color:#fff}
            #detail-today .detail-crumb{color:#ffffff;font-weight:800}
            #detail-today .card-top h3{color:#fff;font-weight:800}
            #detail-today .card-top h3 i{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.32);width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:16px}
            #detail-today .detail-sub{color:#ffffff;font-weight:700}
            #detail-today .detail-date-pill{background:#fff;border-color:#fff;color:#0E6B5E;box-shadow:0 6px 14px rgba(0,0,0,.16);font-weight:800}
            #detail-today .card-body-custom{background:linear-gradient(180deg,#F7FDFB 0%,#fff 120px)}
            #detail-today .detail-mini{padding:13px 13px 13px 18px;border-radius:15px;background:#fff;box-shadow:0 6px 16px rgba(14,107,94,.07);position:relative}
            #detail-today .detail-mini::before{width:6px}
            #detail-today .detail-mini[data-filter="all"]::before{background:linear-gradient(180deg,#2563EB,#1E40AF)}
            #detail-today .detail-mini[data-filter="confirmed"]::before{background:linear-gradient(180deg,#10B981,#0E6B5E)}
            #detail-today .detail-mini[data-filter="pending"]::before{background:linear-gradient(180deg,#FBBF24,#D97706)}
            #detail-today .detail-mini[data-filter="completed"]::before{background:linear-gradient(180deg,#818CF8,#4F46E5)}
            #detail-today .detail-mini[data-filter="all"] .detail-mini-icon{background:linear-gradient(135deg,#2563EB,#1E40AF);box-shadow:0 6px 14px rgba(37,99,235,.30)}
            #detail-today .detail-mini[data-filter="confirmed"] .detail-mini-icon{background:linear-gradient(135deg,#10B981,#0E6B5E)}
            #detail-today .detail-mini[data-filter="pending"] .detail-mini-icon{background:linear-gradient(135deg,#FBBF24,#D97706);box-shadow:0 6px 14px rgba(217,119,6,.30)}
            #detail-today .detail-mini[data-filter="completed"] .detail-mini-icon{background:linear-gradient(135deg,#818CF8,#4F46E5);box-shadow:0 6px 14px rgba(79,70,229,.30)}
            #detail-today .detail-mini strong{font-size:21px}
            #detail-today .detail-progress{border-radius:15px;background:#fff;box-shadow:0 6px 16px rgba(14,107,94,.07)}
            #detail-today .detail-progress-track{height:10px}
            .card-detail .status, .d-record-overlay .status{box-shadow:inset 0 0 0 1px rgba(0,0,0,.05)}
            /* ===== TODAY: TABLE VIEW ===== */
            .card-detail tr.d-tbl-row, .d-record-overlay tr.d-tbl-row{display:table-row;gap:0;align-items:stretch}
            .card-detail .d-tbl-wrap, .d-record-overlay .d-tbl-wrap{border:1px solid #C9E9DA;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(14,107,94,.08);overflow:hidden}
            .card-detail .d-tbl-scroll, .d-record-overlay .d-tbl-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
            .card-detail .d-table, .d-record-overlay .d-table{width:100%;border-collapse:collapse;min-width:760px}
            /* ===== HEAD BAR (icon + title + count) ===== */
            .card-detail .d-tbl-head, .d-record-overlay .d-tbl-head{display:flex;align-items:center;justify-content:space-between;gap:11px;padding:13px 16px;border-bottom:1px solid #E6F2EC;background:#fff}
            .card-detail .d-tbl-head-icon, .d-record-overlay .d-tbl-head-icon{width:38px;height:38px;border-radius:11px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:17px;background:#EAF7F0;color:#0E6B5E}
            .card-detail .d-tbl-head-txt, .d-record-overlay .d-tbl-head-txt{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
            .card-detail .d-tbl-head-count, .d-record-overlay .d-tbl-head-count{min-width:30px;height:30px;padding:0 8px;border-radius:999px;display:flex;align-items:center;justify-content:center;background:#EAF7F0;border:1px solid #B9E4CF;color:#0E6B5E;font-size:13px;font-weight:800;font-variant-numeric:tabular-nums;flex-shrink:0}
            /* ===== THEAD: solid emerald ===== */
            .card-detail .d-table thead th, .d-record-overlay .d-table thead th{background:#0A4A41;color:#fff;font-size:10.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;text-align:left;padding:11px 14px;border-bottom:none;white-space:nowrap}
            .card-detail .d-table tbody td, .d-record-overlay .d-table tbody td{padding:13px 14px;border-bottom:1px solid #E6F2EC;font-size:13.5px;color:#4d6873;vertical-align:middle;background:#fff}
            .card-detail .d-table tbody tr:nth-child(even) td, .d-record-overlay .d-table tbody tr:nth-child(even) td{background:#F6FBF8}
            .card-detail .d-table tbody tr:hover td, .d-record-overlay .d-table tbody tr:hover td{background:#EAF7F0}
            .card-detail .d-table tbody tr:last-child td, .d-record-overlay .d-table tbody tr:last-child td{border-bottom:none}
            .card-detail .d-table tbody td strong, .d-record-overlay .d-table tbody td strong{color:#143f51}
            /* # column: badge */
            .card-detail .d-table .d-col-num, .d-record-overlay .d-table .d-col-num{width:46px;min-width:46px;text-align:center;font-weight:800;font-variant-numeric:tabular-nums;color:#8499a1}
            .card-detail .d-table td.d-col-num span, .d-record-overlay .d-table td.d-col-num span{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:8px;background:#EFF6F3;color:#7f9aa4;font-size:11.5px;line-height:1}
            .card-detail .d-tbl-when, .d-record-overlay .d-tbl-when{white-space:nowrap;font-variant-numeric:tabular-nums}
            /* View button */
            .card-detail .d-view-btn, .d-record-overlay .d-view-btn{display:inline-flex;align-items:center;gap:6px;background:#EAF7F0;border:1px solid #B9E4CF;color:#0E6B5E;font-size:11.5px;font-weight:800;padding:7px 13px;border-radius:9px;cursor:pointer;transition:.18s;font-family:inherit;white-space:nowrap}
            .card-detail .d-view-btn:hover, .d-record-overlay .d-view-btn:hover{background:#0E6B5E;border-color:#0E6B5E;color:#fff}
            .card-detail .d-view-btn i, .d-record-overlay .d-view-btn i{font-size:11.5px}
            .card-detail .empty-state, .d-record-overlay .empty-state{background:linear-gradient(160deg,#F7FDFB,#EDF9F3);border:1px dashed #B9E4CF;border-radius:15px;padding:38px 14px}
            #detail-today .detail-mini{padding-bottom:16px}
            #detail-today .detail-mini-foot{display:inline-block;font-style:normal;font-size:10.5px;font-weight:800;letter-spacing:.2px;margin-top:5px;line-height:1;padding:5px 9px;border-radius:999px;border:1px solid transparent}
            #detail-today .detail-mini[data-filter="all"] .detail-mini-foot{background:#DBEAFE;color:#1E40AF;border-color:#BFDBFE}
            #detail-today .detail-mini[data-filter="confirmed"] .detail-mini-foot{background:#D1FAE5;color:#065F46;border-color:#A7F3D0}
            #detail-today .detail-mini[data-filter="pending"] .detail-mini-foot{background:#FEF3C7;color:#92400E;border-color:#FDE68A}
            #detail-today .detail-mini[data-filter="completed"] .detail-mini-foot{background:#E0E7FF;color:#3730A3;border-color:#C7D2FE}
            #detail-today .detail-mini::after{content:"";position:absolute;left:0;right:0;bottom:0;height:5px;border-radius:0 0 14px 14px}
            #detail-today .detail-mini[data-filter="all"]::after{background:linear-gradient(90deg,#2563EB,#1E40AF)}
            #detail-today .detail-mini[data-filter="confirmed"]::after{background:linear-gradient(90deg,#10B981,#0E6B5E)}
            #detail-today .detail-mini[data-filter="pending"]::after{background:linear-gradient(90deg,#FBBF24,#D97706)}
            #detail-today .detail-mini[data-filter="completed"]::after{background:linear-gradient(90deg,#818CF8,#4F46E5)}
            /* ===== UPCOMING interior gaya ng Today (scoped lang sa #detail-upcoming) ===== */
            #detail-upcoming{border-color:#B9E4CF;box-shadow:0 14px 34px rgba(8,145,178,.10)}
            #detail-upcoming .card-top{background:linear-gradient(135deg,#0E7490 0%,#0891B2 60%,#22D3EE 100%);border-bottom:none;color:#fff}
            #detail-upcoming .detail-crumb{color:rgba(255,255,255,.78)}
            #detail-upcoming .card-top h3{color:#fff}
            #detail-upcoming .card-top h3 i{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.32);width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:16px}
            #detail-upcoming .detail-sub{color:rgba(255,255,255,.85)}
            #detail-upcoming .detail-date-pill{background:#fff;border-color:#fff;color:#0E7490;box-shadow:0 6px 14px rgba(0,0,0,.16)}
            #detail-upcoming .card-body-custom{background:linear-gradient(180deg,#F2FAFD 0%,#fff 120px)}
            #detail-upcoming .detail-mini{padding:13px 13px 16px 18px;border-radius:15px;background:#fff;box-shadow:0 6px 16px rgba(8,145,178,.08)}
            #detail-upcoming .detail-mini::before{width:6px}
            #detail-upcoming .detail-mini[data-filter="all"]::before{background:linear-gradient(180deg,#0891B2,#0E7490)}
            #detail-upcoming .detail-mini[data-filter="week"]::before{background:linear-gradient(180deg,#10B981,#0E6B5E)}
            #detail-upcoming .detail-mini[data-filter="month"]::before{background:linear-gradient(180deg,#818CF8,#4F46E5)}
            #detail-upcoming .detail-mini[data-filter="beyond"]::before{background:linear-gradient(180deg,#FBBF24,#D97706)}
            #detail-upcoming .detail-mini[data-filter="all"] .detail-mini-icon{background:linear-gradient(135deg,#0891B2,#0E7490);box-shadow:0 6px 14px rgba(8,145,178,.30)}
            #detail-upcoming .detail-mini[data-filter="week"] .detail-mini-icon{background:linear-gradient(135deg,#10B981,#0E6B5E)}
            #detail-upcoming .detail-mini[data-filter="month"] .detail-mini-icon{background:linear-gradient(135deg,#818CF8,#4F46E5);box-shadow:0 6px 14px rgba(79,70,229,.30)}
            #detail-upcoming .detail-mini[data-filter="beyond"] .detail-mini-icon{background:linear-gradient(135deg,#FBBF24,#D97706);box-shadow:0 6px 14px rgba(217,119,6,.30)}
            #detail-upcoming .detail-mini strong{font-size:21px}
            #detail-upcoming .detail-progress{border-radius:15px;background:#fff;box-shadow:0 6px 16px rgba(8,145,178,.08)}
            #detail-upcoming .detail-progress-track{height:10px}
            #detail-upcoming .d-tl-card{padding:14px 16px;border-radius:16px;background:#fff;box-shadow:0 4px 14px rgba(20,68,84,.06)}
            #detail-upcoming .d-tl-avatar{width:44px;height:44px;border-radius:13px;font-size:15px}
            #detail-upcoming .d-tl-time strong{font-size:14px}
            #detail-upcoming .d-tl-info strong{font-size:15px}
            #detail-upcoming .status{box-shadow:inset 0 0 0 1px rgba(0,0,0,.05)}
            #detail-upcoming .empty-state{background:linear-gradient(160deg,#F2FAFD,#E8F6FB);border:1px dashed #9EDCEB;border-radius:15px;padding:38px 14px}
            #detail-upcoming .detail-mini-foot{display:inline-block;font-style:normal;font-size:10.5px;font-weight:800;letter-spacing:.2px;margin-top:5px;line-height:1;padding:5px 9px;border-radius:999px;border:1px solid transparent}
            #detail-upcoming .detail-mini[data-filter="all"] .detail-mini-foot{background:#CFFAFE;color:#0E7490;border-color:#A5F3FC}
            #detail-upcoming .detail-mini[data-filter="week"] .detail-mini-foot{background:#D1FAE5;color:#065F46;border-color:#A7F3D0}
            #detail-upcoming .detail-mini[data-filter="month"] .detail-mini-foot{background:#E0E7FF;color:#3730A3;border-color:#C7D2FE}
            #detail-upcoming .detail-mini[data-filter="beyond"] .detail-mini-foot{background:#FEF3C7;color:#92400E;border-color:#FDE68A}
            #detail-upcoming .detail-mini::after{content:"";position:absolute;left:0;right:0;bottom:0;height:5px;border-radius:0 0 14px 14px}
            #detail-upcoming .detail-mini[data-filter="all"]::after{background:linear-gradient(90deg,#0891B2,#0E7490)}
            #detail-upcoming .detail-mini[data-filter="week"]::after{background:linear-gradient(90deg,#10B981,#0E6B5E)}
            #detail-upcoming .detail-mini[data-filter="month"]::after{background:linear-gradient(90deg,#818CF8,#4F46E5)}
            #detail-upcoming .detail-mini[data-filter="beyond"]::after{background:linear-gradient(90deg,#FBBF24,#D97706)}
        </style>
        <style>
            /* ===== TOP SUMMARY CARDS — parepareho ang kulay (emerald) =====
               Dati may 6 na magkaibang kulay (blue/cyan/purple/amber/emerald/rose),
               kaya magmukhang batik at hindi magkakab Eligible ang isang sistema.
               Ngayon: iisang gradient, iisang shadow, iisang dot para sa lahat. */
            #statsGrid .card.stat-featured{background:linear-gradient(135deg,#047857 0%,#059669 55%,#10B981 100%);box-shadow:0 16px 34px rgba(5,150,105,.35)}
            #statsGrid .card.stat-featured:hover{box-shadow:0 22px 42px rgba(5,150,105,.45)}
            #statsGrid .card.stat-featured .stat-featured-dot{background:#A7F3D0;box-shadow:0 0 0 4px rgba(167,243,208,.25)}
            /* ===== Compact size (scoped lang sa #statsGrid) =====
               Maliit ang box, malaki ang titik — pinagsama ang dating padding
               para mailagyan ng malinis na pangalan. Icon sa kaliwa, pangalan
               sa tabi nito (puwedeng 2 linya), bilang sa ilalim. */
            #statsGrid{gap:10px;margin-bottom:18px;align-items:stretch}
            /* flex column + stretch para pantay-pantay ang lahat ng card:
               ang pangalan ang maaaring umabot sa 2 linya, pero dahil may
               margin-top:auto ang .number, laging nasa BABA ito at magkaiba
               lang ang card ng hindi. */
            #statsGrid .card.stat-featured{padding:11px 11px 10px;gap:4px;border-radius:13px;align-items:flex-start;flex-direction:column;height:100%}
            #statsGrid .stat-featured-head{gap:8px;align-items:center;flex-wrap:nowrap;width:100%}
            #statsGrid .stat-featured-icon{width:30px;height:30px;border-radius:9px;font-size:14px;flex-shrink:0}
            /* Pangalan: sa tabi ng icon, may hawan na pwedeng pumunta sa 2 linya.
               min-height ang 1 linya para hindi ma-"jump" ang card kapag 1 linya lang. */
            #statsGrid .card.stat-featured h3{font-size:14px;line-height:1.2;margin:0;flex:1;min-width:0;letter-spacing:0;min-height:2.4em;display:flex;align-items:center}
            /* ITO ang humihila ng bilang sa ibaba — pinapantay ang baseline. */
            #statsGrid .card.stat-featured .number{font-size:32px;margin-top:auto;padding-top:4px}
            #statsGrid .stat-featured-sub{font-size:12px;line-height:1.35;width:100%}
            #statsGrid .card.stat-featured .stat-go{right:9px;bottom:9px;width:20px;height:20px;font-size:10px}
            /* ===== Today: pill-style sub (pareho ang taas, hindi na may extra row) ===== */
            #statsGrid .card.stat-featured[data-view="today"] .stat-featured-sub{background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.30);border-radius:999px;padding:5px 10px;display:inline-flex;width:fit-content;margin-top:4px}
            /* ===== TODAY CARD: record view ay POPUP (hindi na loob ng card) ===== */
            /* ===== POPUP BOX ===== */
.d-record-overlay{padding:18px;z-index:1900}
.d-record-box{max-width:940px}
/* Lahat ng card ay may 1200px popup box (7-column table, hindi cramped). */
#dTodayRecord .d-record-box,
#dRecord-upcoming .d-record-box,
#dRecord-pending .d-record-box,
#dRecord-noshow .d-record-box,
#dRecord-confirmed .d-record-box,
#dRecord-completed .d-record-box{max-width:1200px}
@media(max-width:1000px){#dTodayRecord .d-record-box,#dRecord-upcoming .d-record-box,#dRecord-pending .d-record-box,#dRecord-noshow .d-record-box,#dRecord-confirmed .d-record-box,#dRecord-completed .d-record-box{max-width:100%}}
#dRecord-upcoming .d-table,
#dRecord-pending .d-table,
#dRecord-noshow .d-table,
#dRecord-confirmed .d-table,
#dRecord-completed .d-table{min-width:1000px}
            /* Vertical scroll: 10 rows lang ang nakikita, resto ay i-scroll pababa/pataas. */
            /* ===== PAGINATION (JS, 10 rows kada page) — kapalit ng scroll bar ===== */
            .d-pagination{
                display:flex;align-items:center;justify-content:center;flex-wrap:wrap;
                gap:6px;padding:13px 16px;border-top:1px solid #E6F2EC;background:#F6FBF8;
            }
            .d-page-btn{
                display:inline-flex;align-items:center;justify-content:center;gap:4px;
                min-width:34px;height:34px;padding:0 10px;border-radius:9px;
                border:1px solid #C9E9DA;background:#fff;color:#0E6B5E;
                font-family:"Inter",system-ui,sans-serif;font-size:12.5px;font-weight:800;
                text-decoration:none;cursor:pointer;
            }
            .d-page-btn:hover:not(.is-disabled):not(.is-active){background:#EAF7F0}
            .d-page-btn.is-active{background:#0E6B5E;border-color:#0E6B5E;color:#fff}
            .d-page-btn.is-disabled{opacity:.45;cursor:default;pointer-events:none}
            .d-pagination-info{font-size:12px;font-weight:700;color:#57727d;padding:0 6px}

            /* Pagination = hiwalay na state sa tab filter (inline display).
               !important para kahit may inline style ang tab filter,
               ang pagination parin ang may huling salita. */
            .d-tbl-row.d-pg-hidden,
            .d-tl-item.d-pg-hidden{display:none !important}

            .d-record-overlay .d-tbl-scroll-v{overflow-y:auto;overscroll-behavior:contain}
            .d-record-overlay .d-tbl-scroll-v::-webkit-scrollbar{width:10px;height:10px}
            .d-record-overlay .d-tbl-scroll-v::-webkit-scrollbar-track{background:#F1F7F4;border-radius:8px}
            .d-record-overlay .d-tbl-scroll-v::-webkit-scrollbar-thumb{background:linear-gradient(180deg,#149A72,#0A4A41);border-radius:8px;border:2px solid #F1F7F4}
            .d-record-overlay .d-tbl-scroll-v::-webkit-scrollbar-thumb:hover{background:linear-gradient(180deg,#0E6B5E,#0A4A41)}
            .d-record-overlay .d-tbl-scroll-v{scrollbar-width:thin;scrollbar-color:#0E6B5E #F1F7F4}
            /* Walang green header (hal. Upcoming): X button ay lumulaplay sa labas ng box. */
            .d-record-box-plain{position:relative}
            .d-record-box-plain .d-record-x-float{position:absolute;top:12px;right:12px;z-index:2;width:32px;height:32px;border-radius:9px;border:1px solid #C9E9DA;background:#ffffff;color:#0E6B5E;box-shadow:0 6px 14px rgba(14,107,94,.18);font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.18s}
            .d-record-box-plain .d-record-x-float:hover{background:#0E6B5E;border-color:#0E6B5E;color:#fff}
            .d-record-box-plain .d-record-body{padding:14px 14px 14px}
            .d-record-box-plain .d-tbl-head{border-radius:16px 16px 0 0}
.d-record-overlay .d-modal-head{padding:16px 18px}
.d-record-overlay .d-modal-head small{margin-top:2px}
.d-record-body{padding:16px 18px 18px}
.card-record-back{margin:0 0 14px}
.d-view-btn{font-size:11.5px !important;padding:7px 13px !important;border-radius:9px !important}
.d-view-btn i{font-size:11.5px !important}
@media(max-width:1000px){.d-record-box{max-width:100%}}
            .d-record-overlay .status.confirmed,.d-record-overlay .status.approved{background:#DCFCE7;color:#166534}
            .d-record-overlay .status.pending{background:#FEF3C7;color:#92400E}
            .d-record-overlay .status.completed{background:#DBEAFE;color:#1E40AF}
            .d-record-overlay .status.cancelled{background:#FEE2E2;color:#991B1B}
            .d-record-overlay .status.no-show{background:#FFE4E6;color:#9F1239}
            .d-record-overlay .status{display:inline-block;padding:6px 10px;border-radius:20px;font-size:12px;font-weight:bold}
            @media(max-width:1000px){.d-modal-box.d-record-box{max-width:100%}}
            /* Hover tooltip sa action buttons (CSS-only, data-tip attribute) */
            [data-tip]{position:relative}
            [data-tip]:hover::after{content:attr(data-tip);position:absolute;top:calc(100% + 8px);left:50%;transform:translateX(-50%);background:#0A3B34;color:#fff;font-size:11px;font-weight:700;font-family:"Inter",system-ui,sans-serif;padding:6px 12px;border-radius:8px;white-space:nowrap;pointer-events:none;z-index:99999;box-shadow:0 8px 20px rgba(0,0,0,.35)}
            .d-modal-box{width:100%;max-width:480px;background:#fff;border-radius:18px;overflow:visible !important;box-shadow:0 24px 60px rgba(0,0,0,.35);animation:dModalIn .2s ease}
            .d-modal-head{display:flex;align-items:center;gap:11px;padding:16px 18px;background:linear-gradient(135deg,#0E6B5E,#0A4A41);border-top-left-radius:17px;border-top-right-radius:17px}
            .d-modal-foot{border-bottom-left-radius:17px;border-bottom-right-radius:17px}
            .d-modal-body [data-tip]:hover::after, .d-modal-foot [data-tip]:hover::after, .d-modal-box [data-tip]:not(.d-modal-x):hover::after{top:auto !important;bottom:calc(100% + 8px) !important;left:50% !important;transform:translateX(-50%) !important;z-index:99999 !important}
            .d-modal-head [data-tip]:hover::after, .d-modal-x[data-tip]:hover::after{top:calc(100% + 8px) !important;bottom:auto !important;left:auto !important;right:0 !important;transform:none !important;z-index:99999 !important}
            .d-modal-head-icon{width:38px;height:38px;border-radius:11px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:17px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.30);color:#fff}
            .d-modal-head h4{margin:0;color:#fff;font-size:15px;font-weight:800;line-height:1.25}
            .d-modal-head small{display:block;color:rgba(255,255,255,.78);font-size:11px;font-weight:700;margin-top:2px}
            .d-modal-x{margin-left:auto;width:30px;height:30px;border-radius:9px;border:1px solid rgba(255,255,255,.30);background:rgba(255,255,255,.12);color:#fff;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.18s;flex-shrink:0}
            .d-modal-x:hover{background:#fff;color:#0E6B5E}
            .d-modal-body{padding:18px;display:flex;flex-direction:column;gap:11px}
            .d-modal-row{display:flex;align-items:flex-start;gap:12px;padding:11px 13px;background:#F7FDFB;border:1px solid #E3EEEA;border-radius:12px}
            .d-modal-row label{flex:0 0 118px;font-size:10.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#8499a1;line-height:1.5}
            .d-modal-row .value{flex:1;min-width:0;font-size:13.5px;font-weight:700;color:#143f51;line-height:1.5;word-break:break-word}
            .d-modal-row .value.is-empty{color:#A3B4BB;font-weight:600;font-style:italic}
            .d-modal-foot{padding:0 18px 18px;display:flex;justify-content:flex-end}
            .d-modal-btn{background:linear-gradient(135deg,#149A72,#0A4A41);border:1.5px solid #0E6B5E;color:#fff;font-size:12.5px;font-weight:800;padding:9px 18px;border-radius:10px;cursor:pointer;font-family:inherit;transition:.18s}
            .d-modal-btn:hover{filter:brightness(1.1)}
            @media(max-width:520px){.d-modal-row{flex-direction:column;gap:3px}.d-modal-row label{flex:none}}
            .d-modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(10,59,52,.65);backdrop-filter:blur(4px);z-index:99999;display:none;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}
            .d-modal-overlay.open{display:flex !important}
            .detail-close{width:32px;height:32px;border-radius:9px;border:1px solid #B9E4CF;background:#fff;color:#0E6B5E;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px;transition:.18s}
            .detail-close:hover{background:#0E6B5E;border-color:#0E6B5E;color:#fff}
        </style>

        <div id="cardDetailWrap">
            <div id="detailNav">
                <div class="detail-nav-card" data-goto="today" role="button" tabindex="0" title="Today's Appointments"><div class="detail-nav-icon"><i class="bi bi-calendar-event-fill"></i></div><div><small>Today</small><strong><?= (int)$todayCount ?></strong></div></div>
                <div class="detail-nav-card" data-goto="upcoming" role="button" tabindex="0" title="Upcoming Appointments"><div class="detail-nav-icon"><i class="bi bi-calendar2-week-fill"></i></div><div><small>Upcoming</small><strong><?= (int)$upcomingCount ?></strong></div></div>
                <div class="detail-nav-card" data-goto="completed" role="button" tabindex="0" title="Completed Appointments"><div class="detail-nav-icon"><i class="bi bi-check-circle-fill"></i></div><div><small>Completed</small><strong><?= (int)$completedCount ?></strong></div></div>
                <div class="detail-nav-card" data-goto="pending" role="button" tabindex="0" title="Pending Appointments"><div class="detail-nav-icon"><i class="bi bi-hourglass-split"></i></div><div><small>Pending</small><strong><?= (int)$pendingCount ?></strong></div></div>
                <div class="detail-nav-card" data-goto="confirmed" role="button" tabindex="0" title="Confirmed Appointments"><div class="detail-nav-icon"><i class="bi bi-calendar2-check-fill"></i></div><div><small>Confirmed</small><strong><?= (int)$confirmedCount ?></strong></div></div>
                <div class="detail-nav-card" data-goto="noshow" role="button" tabindex="0" title="No-Show Patients"><div class="detail-nav-icon"><i class="bi bi-person-x-fill"></i></div><div><small>No-Show</small><strong><?= (int)($noShowCount ?? 0) ?></strong></div></div>
            </div>

            <!-- TODAY -->
            <div class="card-detail" id="detail-today" style="display:none;">
                <div class="card-top">
                    <div>
                        <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; Today's Appointments</div>
                        <h3><i class="bi bi-calendar-event-fill"></i> Today's Appointments</h3>
                        <p class="detail-sub">View and manage all appointments scheduled for today.</p>
                    </div>
                    <span class="detail-date-pill"><i class="bi bi-calendar3"></i> <?= dentistCardEscape(date('F j, Y')) ?></span>
                </div>
                <div class="card-body-custom">
                    <div class="detail-mini-grid" data-mini-group="today">
                        <div class="detail-mini detail-mini-clickable active-mini" data-filter="all" role="button" tabindex="0">
                            <div class="detail-mini-icon"><i class="bi bi-calendar-event-fill"></i></div>
                            <div><small>Total Appointments</small><strong><?= (int)$dTodaySub['total'] ?></strong><em class="detail-mini-foot">All today</em></div>
                        </div>
                        <div class="detail-mini detail-mini-clickable" data-filter="confirmed" role="button" tabindex="0">
                            <div class="detail-mini-icon"><i class="bi bi-patch-check-fill"></i></div>
                            <div><small>Confirmed</small><strong><?= (int)$dTodaySub['confirmed'] ?></strong><em class="detail-mini-foot">Confirmed</em></div>
                        </div>
                        <div class="detail-mini detail-mini-clickable" data-filter="pending" role="button" tabindex="0">
                            <div class="detail-mini-icon"><i class="bi bi-hourglass-split"></i></div>
                            <div><small>Pending</small><strong><?= (int)$dTodaySub['pending'] ?></strong><em class="detail-mini-foot">Pending</em></div>
                        </div>
                        <div class="detail-mini detail-mini-clickable" data-filter="completed" role="button" tabindex="0">
                            <div class="detail-mini-icon"><i class="bi bi-check-circle-fill"></i></div>
                            <div><small>Completed</small><strong><?= (int)$dTodaySub['completed'] ?></strong><em class="detail-mini-foot">Completed</em></div>
                        </div>
                    </div>
                    <div class="detail-tabs" data-tab-group="today">
                        <button type="button" class="detail-tab active" data-filter="all">All (<?= (int)$dTodaySub['total'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="confirmed">Confirmed (<?= (int)$dTodaySub['confirmed'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="pending">Pending (<?= (int)$dTodaySub['pending'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="completed">Completed (<?= (int)$dTodaySub['completed'] ?>)</button>
                    </div>
                    <div class="d-tbl-wrap">
                    <?php if (empty($dTodayList)): ?>
                        <div class="empty-state"><i class="bi bi-calendar-x"></i><p>No appointments for today.</p></div>
                    <?php else: $n=1; ?>
                        <div class="d-tbl-head">
                            <span class="d-tbl-head-icon"><i class="bi bi-calendar-event-fill"></i></span>
                            <div class="d-tbl-head-txt">
                                <strong>Today's Appointments</strong>
                                <small><?= dentistCardEscape(date('M d, Y')) ?> · <?= count($dTodayList) ?> showing</small>
                            </div>
                            <span class="d-tbl-head-count"><?= count($dTodayList) ?></span>
                        </div>
                        <div class="d-tbl-scroll">
                        <table class="d-table">
                            <thead>
                                <tr>
                                    <th class="d-col-num">#</th>
                                    <th>Patient Name</th>
                                    <th>Time of Appointment</th>
                                    <th>Dental Service</th>
                                    <th>Assigned Dentist</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                        <?php
                        $dAssignedDentist = 'Dr. ' . trim((string)($dentist['name'] ?? 'Dentist'));
                        foreach ($dTodayList as $a):
                            $pn=trim(($a['patient_name']??''))!==''?$a['patient_name']:'Patient';
                            $tStart=!empty($a['appointment_time'])?date('h:i A',strtotime($a['appointment_time'])):'—';
                            $tEnd=!empty($a['end_time'])?date('h:i A',strtotime($a['end_time'])):'';
                            $tmFull=date('M d, Y').' '.$tStart.($tEnd!==''?' – '.$tEnd:'');
                            $st=trim($a['status']??'Pending'); $fk=dentistCardStatusKey($st);
                        ?><tr class="d-tbl-row" data-status="<?= dentistCardEscape($fk) ?>">
                            <td class="d-col-num"><span><?= $n++ ?></span></td>
                            <td><strong><?= dentistCardEscape($pn) ?></strong></td>
                            <td class="d-tbl-when"><?= dentistCardEscape($tmFull) ?></td>
                            <td><?= dentistCardEscape($a['service_type']??'—') ?></td>
                            <td><?= dentistCardEscape($dAssignedDentist) ?></td>
                            <td><span class="status <?= dentistCardEscape($fk) ?>"><?= dentistCardEscape($st) ?></span></td>
                            <td>
                                <button type="button" class="d-view-btn"
                                    data-d-patient="<?= dentistCardEscape($pn) ?>"
                                    data-d-service="<?= dentistCardEscape($a['service_type']??'—') ?>"
                                    data-d-dentist="<?= dentistCardEscape($dAssignedDentist) ?>"
                                    data-d-time="<?= dentistCardEscape($tmFull) ?>"
                                    data-d-status="<?= dentistCardEscape($st) ?>"
                                    data-d-notes="<?= dentistCardEscape($a['notes']??'') ?>"
                                    data-tip="View Details"><i class="bi bi-eye"></i> View</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- UPCOMING -->
            <div class="card-detail" id="detail-upcoming" style="display:none;">
                <div class="card-top">
                    <div>
                        <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; Upcoming Appointments</div>
                        <h3><i class="bi bi-calendar2-week-fill"></i> Upcoming Appointments</h3>
                        <p class="detail-sub">View upcoming scheduled patients and appointments.</p>
                    </div>
                    <span class="detail-date-pill"><i class="bi bi-calendar3"></i> After today</span>
                </div>
                <div class="card-body-custom">
                    <div class="detail-mini-grid" data-mini-group="upcoming">
                        <div class="detail-mini detail-mini-clickable" data-filter="all" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-calendar2-week-fill"></i></div><div><small>Total Upcoming</small><strong><?= (int)$dUpcomingSub['total'] ?></strong><em class="detail-mini-foot">All upcoming</em></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="week" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-calendar-week-fill"></i></div><div><small>This Week</small><strong><?= (int)$dUpcomingSub['week'] ?></strong><em class="detail-mini-foot">This week</em></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="month" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-calendar-month-fill"></i></div><div><small>This Month</small><strong><?= (int)$dUpcomingSub['month'] ?></strong><em class="detail-mini-foot">This month</em></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="beyond" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-arrow-right-circle-fill"></i></div><div><small>Beyond Month</small><strong><?= (int)$dUpcomingSub['beyond'] ?></strong><em class="detail-mini-foot">Beyond month</em></div></div>
                    </div>
                    <?php $dpTotal=(int)($dUpcomingSub['total']??0); $dpVal=(int)($dUpcomingSub['week']??0); $dpPct=$dpTotal>0?(int)round($dpVal/$dpTotal*100):0; ?>
                    <div class="detail-progress">
                        <div class="detail-progress-info"><span><i class="bi bi-calendar-week-fill"></i> Within 7 days</span><strong><?= $dpPct ?>% · <?= $dpVal ?>/<?= $dpTotal ?></strong></div>
                        <div class="detail-progress-track"><span style="width:<?= $dpPct ?>%"></span></div>
                    </div>
                    <div class="detail-tabs" data-tab-group="upcoming">
                        <button type="button" class="detail-tab active" data-filter="all">All (<?= (int)$dUpcomingSub['total'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="week">This Week (<?= (int)$dUpcomingSub['week'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="month">This Month (<?= (int)$dUpcomingSub['month'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="beyond">Beyond Month (<?= (int)$dUpcomingSub['beyond'] ?>)</button>
                    </div>
                    <div class="d-tl">
                    <?php if (empty($dUpcomingList)): ?><div class="empty-state"><i class="bi bi-calendar2-week"></i><p>No upcoming appointments.</p></div>
                    <?php else: $n=1; foreach ($dUpcomingList as $a):
                        $pn=trim(($a['patient_name']??''))!==''?$a['patient_name']:'Patient';
                        $dstr=substr((string)($a['appointment_date']??''),0,10);
                        $ts=0; if($dstr!==''){ $ts=strtotime($dstr.' '.($a['appointment_time']??'')); }
                        $st=trim($a['status']??'Pending');
                        $inW=($dstr>=$dWeekStartStr&&$dstr<=$dWeekEndStr); $inM=(substr($dstr,0,7)===$dMonthPrefix); $beyond=($dstr>$dMonthEndStr);
                    ?><div class="d-tl-item" data-week="<?= $inW?'1':'0' ?>" data-month="<?= $inM?'1':'0' ?>" data-beyond="<?= $beyond?'1':'0' ?>" data-status="<?= dentistCardEscape(dentistCardStatusKey($st)) ?>">
                        <span class="d-tl-dot"></span>
                        <div class="d-tl-card">
                            <div class="d-tl-time"><?= $ts ? '<strong>'.dentistCardEscape(date('M d',$ts)).'</strong><small>'.dentistCardEscape(date('h:i A',$ts)).'</small>' : '<strong>—</strong>' ?></div>
                            <div class="d-tl-avatar"><?= dentistCardEscape(dentistCardInitials($pn)) ?></div>
                            <div class="d-tl-info"><strong><?= dentistCardEscape($pn) ?></strong><span><i class="bi bi-capsule"></i> <?= dentistCardEscape($a['service_type']??'—') ?></span></div>
                            <span class="status <?= dentistCardEscape(dentistCardStatusKey($st)) ?>"><?= dentistCardEscape($st) ?></span>
                            <span class="d-tl-num"><?= $n++ ?></span>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>

            <!-- COMPLETED -->
            <div class="card-detail" id="detail-completed" style="display:none;">
                <div class="card-top">
                    <div>
                        <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; Completed Appointments</div>
                        <h3><i class="bi bi-check-circle-fill"></i> Completed Appointments</h3>
                        <p class="detail-sub">View records of all completed treatments and appointments.</p>
                    </div>
                    <span class="detail-date-pill"><i class="bi bi-calendar3"></i> <?= dentistCardEscape(date('F j, Y')) ?></span>
                </div>
                <div class="card-body-custom">
                    <div class="detail-mini-grid" data-mini-group="completed">
                        <div class="detail-mini detail-mini-clickable" data-filter="all" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-check-circle-fill"></i></div><div><small>Total Completed</small><strong><?= (int)$dCompletedSub['total'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="today" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-sun-fill"></i></div><div><small>Today</small><strong><?= (int)$dCompletedSub['today'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="week" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-calendar-week-fill"></i></div><div><small>This Week</small><strong><?= (int)$dCompletedSub['week'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="month" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-calendar-month-fill"></i></div><div><small>This Month</small><strong><?= (int)$dCompletedSub['month'] ?></strong></div></div>
                    </div>
                    <?php $dpTotal=(int)($dCompletedSub['total']??0); $dpVal=(int)($dCompletedSub['month']??0); $dpPct=$dpTotal>0?(int)round($dpVal/$dpTotal*100):0; ?>
                    <div class="detail-progress">
                        <div class="detail-progress-info"><span><i class="bi bi-calendar-check-fill"></i> Completed this month</span><strong><?= $dpPct ?>% · <?= $dpVal ?>/<?= $dpTotal ?></strong></div>
                        <div class="detail-progress-track"><span style="width:<?= $dpPct ?>%"></span></div>
                    </div>
                    <div class="detail-tabs" data-tab-group="completed">
                        <button type="button" class="detail-tab active" data-filter="all">All (<?= (int)$dCompletedSub['total'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="today">Today (<?= (int)$dCompletedSub['today'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="week">This Week (<?= (int)$dCompletedSub['week'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="month">This Month (<?= (int)$dCompletedSub['month'] ?>)</button>
                    </div>
                    <div class="d-tl">
                    <?php if (empty($dCompletedList)): ?><div class="empty-state"><i class="bi bi-check2-circle"></i><p>No completed appointments.</p></div>
                    <?php else: $n=1; foreach ($dCompletedList as $a):
                        $pn=trim(($a['patient_name']??''))!==''?$a['patient_name']:'Patient';
                        $dstr=substr((string)($a['appointment_date']??''),0,10);
                        $ts=0; if($dstr!==''){ $ts=strtotime($dstr.' '.($a['appointment_time']??'')); }
                        $inW=($dstr>=$dWeekStartStr&&$dstr<=$dWeekEndStr); $inM=(substr($dstr,0,7)===$dMonthPrefix);
                    ?><div class="d-tl-item" data-today="<?= $dstr===$dTodayStr?'1':'0' ?>" data-week="<?= $inW?'1':'0' ?>" data-month="<?= $inM?'1':'0' ?>" data-status="completed">
                        <span class="d-tl-dot"></span>
                        <div class="d-tl-card">
                            <div class="d-tl-time"><?= $ts ? '<strong>'.dentistCardEscape(date('M d',$ts)).'</strong><small>'.dentistCardEscape(date('h:i A',$ts)).'</small>' : '<strong>—</strong>' ?></div>
                            <div class="d-tl-avatar"><?= dentistCardEscape(dentistCardInitials($pn)) ?></div>
                            <div class="d-tl-info"><strong><?= dentistCardEscape($pn) ?></strong><span><i class="bi bi-capsule"></i> <?= dentistCardEscape($a['service_type']??'—') ?></span></div>
                            <span class="status completed">Completed</span>
                            <span class="d-tl-num"><?= $n++ ?></span>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>

            <!-- PENDING -->
            <div class="card-detail" id="detail-pending" style="display:none;">
                <div class="card-top">
                    <div>
                        <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; Pending Appointments</div>
                        <h3><i class="bi bi-hourglass-split"></i> Pending Appointments</h3>
                        <p class="detail-sub">View and manage all pending appointment requests.</p>
                    </div>
                    <span class="detail-date-pill"><i class="bi bi-calendar3"></i> <?= dentistCardEscape(date('F j, Y')) ?></span>
                </div>
                <div class="card-body-custom">
                    <div class="detail-mini-grid" data-mini-group="pending">
                        <div class="detail-mini detail-mini-clickable" data-filter="all" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-hourglass-split"></i></div><div><small>Total Pending</small><strong><?= (int)$dPendingSub['total'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="today" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-sun-fill"></i></div><div><small>Today</small><strong><?= (int)$dPendingSub['today'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="week" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-calendar-week-fill"></i></div><div><small>This Week</small><strong><?= (int)$dPendingSub['week'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="month" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-calendar-month-fill"></i></div><div><small>This Month</small><strong><?= (int)$dPendingSub['month'] ?></strong></div></div>
                    </div>
                    <?php $dpTotal=(int)($dPendingSub['total']??0); $dpVal=(int)($dPendingSub['week']??0); $dpPct=$dpTotal>0?(int)round($dpVal/$dpTotal*100):0; ?>
                    <div class="detail-progress">
                        <div class="detail-progress-info"><span><i class="bi bi-hourglass-split"></i> Scheduled this week</span><strong><?= $dpPct ?>% · <?= $dpVal ?>/<?= $dpTotal ?></strong></div>
                        <div class="detail-progress-track"><span style="width:<?= $dpPct ?>%"></span></div>
                    </div>
                    <div class="detail-tabs" data-tab-group="pending">
                        <button type="button" class="detail-tab active" data-filter="all">All (<?= (int)$dPendingSub['total'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="today">Today (<?= (int)$dPendingSub['today'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="week">This Week (<?= (int)$dPendingSub['week'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="month">This Month (<?= (int)$dPendingSub['month'] ?>)</button>
                    </div>
                    <div class="d-tl">
                    <?php if (empty($dPendingList)): ?><div class="empty-state"><i class="bi bi-hourglass"></i><p>No pending appointments.</p></div>
                    <?php else: $n=1; foreach ($dPendingList as $a):
                        $pn=trim(($a['patient_name']??''))!==''?$a['patient_name']:'Patient';
                        $dstr=substr((string)($a['appointment_date']??''),0,10);
                        $ts=0; if($dstr!==''){ $ts=strtotime($dstr.' '.($a['appointment_time']??'')); }
                        $inW=($dstr>=$dWeekStartStr&&$dstr<=$dWeekEndStr); $inM=(substr($dstr,0,7)===$dMonthPrefix);
                    ?><div class="d-tl-item" data-today="<?= $dstr===$dTodayStr?'1':'0' ?>" data-week="<?= $inW?'1':'0' ?>" data-month="<?= $inM?'1':'0' ?>" data-status="pending">
                        <span class="d-tl-dot"></span>
                        <div class="d-tl-card">
                            <div class="d-tl-time"><?= $ts ? '<strong>'.dentistCardEscape(date('M d',$ts)).'</strong><small>'.dentistCardEscape(date('h:i A',$ts)).'</small>' : '<strong>—</strong>' ?></div>
                            <div class="d-tl-avatar"><?= dentistCardEscape(dentistCardInitials($pn)) ?></div>
                            <div class="d-tl-info"><strong><?= dentistCardEscape($pn) ?></strong><span><i class="bi bi-capsule"></i> <?= dentistCardEscape($a['service_type']??'—') ?></span></div>
                            <span class="status pending">Pending</span>
                            <span class="d-tl-num"><?= $n++ ?></span>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>

            <!-- CONFIRMED -->
            <div class="card-detail" id="detail-confirmed" style="display:none;">
                <div class="card-top">
                    <div>
                        <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; Confirmed Appointments</div>
                        <h3><i class="bi bi-calendar2-check-fill"></i> Confirmed Appointments</h3>
                        <p class="detail-sub">View and manage all confirmed appointments assigned to you.</p>
                    </div>
                    <span class="detail-date-pill"><i class="bi bi-calendar3"></i> <?= dentistCardEscape(date('F j, Y')) ?></span>
                </div>
                <div class="card-body-custom">
                    <div class="detail-mini-grid" data-mini-group="confirmed">
                        <div class="detail-mini detail-mini-clickable" data-filter="all" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-calendar2-check-fill"></i></div><div><small>Total Confirmed</small><strong><?= (int)$dConfirmedSub['total'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="today" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-sun-fill"></i></div><div><small>Today</small><strong><?= (int)$dConfirmedSub['today'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="week" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-calendar-week-fill"></i></div><div><small>This Week</small><strong><?= (int)$dConfirmedSub['week'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="month" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-calendar-month-fill"></i></div><div><small>This Month</small><strong><?= (int)$dConfirmedSub['month'] ?></strong></div></div>
                    </div>
                    <?php $dpTotal=(int)($dConfirmedSub['total']??0); $dpVal=(int)($dConfirmedSub['week']??0); $dpPct=$dpTotal>0?(int)round($dpVal/$dpTotal*100):0; ?>
                    <div class="detail-progress">
                        <div class="detail-progress-info"><span><i class="bi bi-calendar2-check-fill"></i> Scheduled this week</span><strong><?= $dpPct ?>% · <?= $dpVal ?>/<?= $dpTotal ?></strong></div>
                        <div class="detail-progress-track"><span style="width:<?= $dpPct ?>%"></span></div>
                    </div>
                    <div class="detail-tabs" data-tab-group="confirmed">
                        <button type="button" class="detail-tab active" data-filter="all">All (<?= (int)$dConfirmedSub['total'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="today">Today (<?= (int)$dConfirmedSub['today'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="week">This Week (<?= (int)$dConfirmedSub['week'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="month">This Month (<?= (int)$dConfirmedSub['month'] ?>)</button>
                    </div>
                    <div class="d-tl">
                    <?php if (empty($dConfirmedList)): ?><div class="empty-state"><i class="bi bi-calendar2-check"></i><p>No confirmed appointments.</p></div>
                    <?php else: $n=1; foreach ($dConfirmedList as $a):
                        $pn=trim(($a['patient_name']??''))!==''?$a['patient_name']:'Patient';
                        $dstr=substr((string)($a['appointment_date']??''),0,10);
                        $ts=0; if($dstr!==''){ $ts=strtotime($dstr.' '.($a['appointment_time']??'')); }
                        $inW=($dstr>=$dWeekStartStr&&$dstr<=$dWeekEndStr); $inM=(substr($dstr,0,7)===$dMonthPrefix);
                    ?><div class="d-tl-item" data-today="<?= $dstr===$dTodayStr?'1':'0' ?>" data-week="<?= $inW?'1':'0' ?>" data-month="<?= $inM?'1':'0' ?>" data-status="confirmed">
                        <span class="d-tl-dot"></span>
                        <div class="d-tl-card">
                            <div class="d-tl-time"><?= $ts ? '<strong>'.dentistCardEscape(date('M d',$ts)).'</strong><small>'.dentistCardEscape(date('h:i A',$ts)).'</small>' : '<strong>—</strong>' ?></div>
                            <div class="d-tl-avatar"><?= dentistCardEscape(dentistCardInitials($pn)) ?></div>
                            <div class="d-tl-info"><strong><?= dentistCardEscape($pn) ?></strong><span><i class="bi bi-capsule"></i> <?= dentistCardEscape($a['service_type']??'—') ?></span></div>
                            <span class="status confirmed">Confirmed</span>
                            <span class="d-tl-num"><?= $n++ ?></span>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>

            <!-- NOSHOW -->
            <div class="card-detail" id="detail-noshow" style="display:none;">
                <div class="card-top">
                    <div>
                        <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; No-Show Patients</div>
                        <h3><i class="bi bi-person-x-fill"></i> No-Show Patients</h3>
                        <p class="detail-sub">Records of patients who missed their scheduled appointments.</p>
                    </div>
                    <span class="detail-date-pill"><i class="bi bi-calendar3"></i> <?= dentistCardEscape(date('F j, Y')) ?></span>
                </div>
                <div class="card-body-custom">
                    <div class="detail-mini-grid" data-mini-group="noshow">
                        <div class="detail-mini detail-mini-clickable" data-filter="all" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-person-x-fill"></i></div><div><small>Total No-Show</small><strong><?= (int)$dNoshowSub['total'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="today" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-sun-fill"></i></div><div><small>Today</small><strong><?= (int)$dNoshowSub['today'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="week" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-calendar-week-fill"></i></div><div><small>This Week</small><strong><?= (int)$dNoshowSub['week'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="month" role="button" tabindex="0"><div class="detail-mini-icon"><i class="bi bi-calendar-month-fill"></i></div><div><small>This Month</small><strong><?= (int)$dNoshowSub['month'] ?></strong></div></div>
                    </div>
                    <?php $dpTotal=(int)($dNoshowSub['total']??0); $dpVal=(int)($dNoshowSub['week']??0); $dpPct=$dpTotal>0?(int)round($dpVal/$dpTotal*100):0; ?>
                    <div class="detail-progress">
                        <div class="detail-progress-info"><span><i class="bi bi-person-x-fill"></i> Recorded this week</span><strong><?= $dpPct ?>% · <?= $dpVal ?>/<?= $dpTotal ?></strong></div>
                        <div class="detail-progress-track"><span style="width:<?= $dpPct ?>%"></span></div>
                    </div>
                    <div class="detail-tabs" data-tab-group="noshow">
                        <button type="button" class="detail-tab active" data-filter="all">All (<?= (int)$dNoshowSub['total'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="today">Today (<?= (int)$dNoshowSub['today'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="week">This Week (<?= (int)$dNoshowSub['week'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="month">This Month (<?= (int)$dNoshowSub['month'] ?>)</button>
                    </div>
                    <div class="d-tl">
                    <?php if (empty($dNoshowList)): ?><div class="empty-state"><i class="bi bi-person-x"></i><p>No no-show records.</p></div>
                    <?php else: $n=1; foreach ($dNoshowList as $a):
                        $pn=trim(($a['patient_name']??''))!==''?$a['patient_name']:'Patient';
                        $dstr=substr((string)($a['appointment_date']??''),0,10);
                        $ts=0; if($dstr!==''){ $ts=strtotime($dstr.' '.($a['appointment_time']??'')); }
                        $inW=($dstr>=$dWeekStartStr&&$dstr<=$dWeekEndStr); $inM=(substr($dstr,0,7)===$dMonthPrefix);
                    ?><div class="d-tl-item" data-today="<?= $dstr===$dTodayStr?'1':'0' ?>" data-week="<?= $inW?'1':'0' ?>" data-month="<?= $inM?'1':'0' ?>" data-status="no-show">
                        <span class="d-tl-dot"></span>
                        <div class="d-tl-card">
                            <div class="d-tl-time"><?= $ts ? '<strong>'.dentistCardEscape(date('M d',$ts)).'</strong><small>'.dentistCardEscape(date('h:i A',$ts)).'</small>' : '<strong>—</strong>' ?></div>
                            <div class="d-tl-avatar"><?= dentistCardEscape(dentistCardInitials($pn)) ?></div>
                            <div class="d-tl-info"><strong><?= dentistCardEscape($pn) ?></strong><span><i class="bi bi-capsule"></i> <?= dentistCardEscape($a['service_type']??'—') ?></span></div>
                            <span class="status no-show">No-Show</span>
                            <span class="d-tl-num"><?= $n++ ?></span>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
        </div>


        <!-- MOVED: Today's Appointments section is now in appointments.php -->


        <!-- MOVED: Today's Queue section is now in appointments.php -->


        <!-- MOVED: Upcoming Appointments section is now in appointments.php -->


        <!-- ==================================================
             MY CLINICAL CHARTS
        ================================================== -->

        <div class="d-charts d-acc-wrap">

            <div class="d-chart-card d-chart-wide d-acc open">

                <div class="d-chart-top">

                    <div class="d-chart-head">

                        <div class="d-chart-icon d-icon-blue">
                            <i class="bi bi-bar-chart-fill"></i>
                        </div>

                        <div>
                            <h4>My Week Ahead</h4>
                            <small>Scheduled patients · next 7 days</small>
                        </div>

                    </div>

                    <span style="display:flex;align-items:center;gap:8px;"><span class="d-chart-pill" id="dWeekPill">0 patients</span><i class="bi bi-chevron-down d-acc-chevron"></i></span>

                </div>

                <p class="d-chart-note" id="dWeekInsight"><i class="bi bi-lightbulb-fill"></i><span>Binabago ayon sa iyong kasalukuyang schedule.</span></p>

                <div class="d-chart-body">
                    <canvas id="dChartWeek"></canvas>
                </div>

            </div>

            <div class="d-chart-card d-acc">

                <div class="d-chart-top">

                    <div class="d-chart-head">

                        <div class="d-chart-icon d-icon-green">
                            <i class="bi bi-pie-chart-fill"></i>
                        </div>

                        <div>
                            <h4>Treatment Outcomes</h4>
                            <small>My appointment breakdown</small>
                        </div>

                    </div>

                    <i class="bi bi-chevron-down d-acc-chevron"></i>

                </div>

                <p class="d-chart-note" id="dStatusInsight"><i class="bi bi-lightbulb-fill"></i><span>Binabago ayon sa hati ng iyong appointments.</span></p>

                <div class="d-chart-body d-chart-doughnut">
                    <canvas id="dChartStatus"></canvas>
                    <div class="d-chart-center">
                        <strong><?= (int) count($allAppointments) ?></strong>
                        <span>Total</span>
                    </div>
                </div>

            </div>

        </div>


        <!-- ==================================================
             TOP SERVICES THIS WEEK
        ================================================== -->

        <style>
            .d-topsvc {
                width: 100%;
                max-width: 100%;
                margin-bottom: 25px;
            }
            @media (max-width: 1000px) {
                .d-topsvc { max-width: none; }
            }
            .d-topsvc-row {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 10px 0;
                border-bottom: 1px solid #eef3f5;
            }
            .d-topsvc-row:last-child { border-bottom: none; }
            .d-topsvc-rank {
                width: 30px; height: 30px; border-radius: 9px; flex-shrink: 0;
                display: inline-flex; align-items: center; justify-content: center;
                background: #EAF7F0; color: #0E6B5E;
                font-family: "Plus Jakarta Sans", sans-serif;
                font-size: 13px; font-weight: 800;
            }
            .d-topsvc-row:first-child .d-topsvc-rank {
                background: linear-gradient(135deg, #149A72, #0A4A41);
                color: #ffffff;
            }
            .d-topsvc-name {
                flex: 1; min-width: 0;
                font-size: 13.5px; font-weight: 700; color: #1c4454;
                white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            }
            .d-topsvc-bar {
                flex: 1; min-width: 60px; height: 8px; border-radius: 6px;
                background: #eef3f5; overflow: hidden;
            }
            .d-topsvc-bar span {
                display: block; height: 100%; border-radius: 6px;
                background: linear-gradient(90deg, #149A72, #0A4A41);
            }
            .d-topsvc-count {
                font-family: "Plus Jakarta Sans", sans-serif;
                font-size: 13px; font-weight: 800; color: #0E6B5E;
                white-space: nowrap;
            }
        </style>

        <div class="d-chart-card d-acc open d-topsvc" id="dTopSvc">

            <div class="d-chart-top">

                <div class="d-chart-head">

                    <div class="d-chart-icon d-icon-blue">
                        <i class="bi bi-award-fill"></i>
                    </div>

                    <div>
                        <h4>Top Services This Week</h4>
                        <small>Mon-Sun · most booked services</small>
                    </div>

                </div>

                <span style="display:flex;align-items:center;gap:8px;"><span class="d-chart-pill"><?= (int) array_sum($topServicesWeek) ?> booked</span><i class="bi bi-chevron-down d-acc-chevron"></i></span>

            </div>

            <div class="d-chart-body" style="padding:8px 20px 14px;height:auto;">
                <?php if (empty($topServicesWeek)): ?>
                    <div class="empty" style="padding:18px 0;">No services scheduled this week.</div>
                <?php else: ?>
                    <?php $svcMax = max($topServicesWeek); $svcRank = 0; ?>
                    <?php foreach ($topServicesWeek as $svcName => $svcCount): ?>
                        <?php $svcRank++; $svcPct = $svcMax > 0 ? round(($svcCount / $svcMax) * 100) : 0; ?>
                        <div class="d-topsvc-row">
                            <span class="d-topsvc-rank"><?= (int) $svcRank ?></span>
                            <span class="d-topsvc-name"><?= htmlspecialchars($svcName) ?></span>
                            <span class="d-topsvc-bar"><span style="width:<?= (int) $svcPct ?>%;"></span></span>
                            <span class="d-topsvc-count"><?= (int) $svcCount ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>


        <!-- ==================================================
             ALL ASSIGNED APPOINTMENTS
        ================================================== -->

        <div class="section" id="appointments">

            <div class="section-title">
                <h2>Appointments</h2>
                <span>All appointments assigned to you</span>
            </div>

            <?php if (empty($allAppointments)): ?>
                <div class="empty">No appointments assigned to you.</div>
            <?php else: ?>
                <table>
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
                    <?php foreach ($allAppointments as $appointment): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($appointment['patient_name']) ?></strong></td>
                            <td><?= date('M d, Y', strtotime($appointment['appointment_date'])) ?></td>
                            <td>
                                <?= date('h:i A', strtotime($appointment['appointment_time'])) ?> -
                                <?= date('h:i A', strtotime($appointment['end_time'])) ?>
                            </td>
                            <td><?= htmlspecialchars($appointment['service_type']) ?></td>
                            <td>
                                <span class="status <?= strtolower($appointment['status']) ?>">
                                    <?= htmlspecialchars($appointment['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        </div>


        <!-- ==================================================
             APPOINTMENT CALENDAR
        ================================================== -->

        <div class="section" id="calendar">

            <div class="section-title">

                <h2>
                    Appointment Calendar
                </h2>

            </div>

            <div id="calendarContainer"></div>

        </div>


        <!-- ==================================================
             PATIENT INFORMATION
        ================================================== -->

        <div class="section" id="patients">

            <div class="section-title">

                <h2>
                    Patient Information
                </h2>

            </div>


            <?php if (empty($patients)): ?>

                <div class="empty">
                    No patients found.
                </div>

            <?php else: ?>

                <div class="patient-grid">

                    <?php foreach ($patients as $patient): ?>

                        <div class="patient-card">

                            <h3>
                                <?= htmlspecialchars($patient['name']) ?>
                            </h3>

                            <p>
                                <?= htmlspecialchars($patient['email']) ?>
                            </p>

                            <br>

                            <a href="patients.php">

                                View Patient

                            </a>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>


        <!-- ==================================================
             COMPLETED
        ================================================== -->

        <div class="section" id="completed">

            <div class="section-title">

                <h2>
                    Completed Appointments
                </h2>

            </div>


            <?php if (empty($completedAppointments)): ?>

                <div class="empty">
                    No completed appointments.
                </div>

            <?php else: ?>

                <table>

                    <thead>

                        <tr>

                            <th>
                                Patient
                            </th>

                            <th>
                                Date
                            </th>

                            <th>
                                Time
                            </th>

                            <th>
                                Status
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($completedAppointments as $appointment): ?>

                        <tr>

                            <td>
                                <?= htmlspecialchars($appointment['patient_name']) ?>
                            </td>

                            <td>
                                <?= date(
                                    "M d, Y",
                                    strtotime($appointment['appointment_date'])
                                ) ?>
                            </td>

                            <td>
                                <?= date(
                                    "h:i A",
                                    strtotime($appointment['appointment_time'])
                                ) ?>
                            </td>

                            <td>

                                <span class="status completed">
                                    Completed
                                </span>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </div>


        <!-- ==================================================
             CANCELLED
        ================================================== -->

        <div class="section" id="cancelled">

            <div class="section-title">

                <h2>
                    Cancelled Appointments
                </h2>

            </div>


            <?php if (empty($cancelledAppointments)): ?>

                <div class="empty">
                    No cancelled appointments.
                </div>

            <?php else: ?>

                <table>

                    <thead>

                        <tr>

                            <th>
                                Patient
                            </th>

                            <th>
                                Date
                            </th>

                            <th>
                                Time
                            </th>

                            <th>
                                Status
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($cancelledAppointments as $appointment): ?>

                        <tr>

                            <td>
                                <?= htmlspecialchars($appointment['patient_name']) ?>
                            </td>

                            <td>
                                <?= date(
                                    "M d, Y",
                                    strtotime($appointment['appointment_date'])
                                ) ?>
                            </td>

                            <td>
                                <?= date(
                                    "h:i A",
                                    strtotime($appointment['appointment_time'])
                                ) ?>
                            </td>

                            <td>

                                <span class="status cancelled">
                                    Cancelled
                                </span>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </div>


    </div>

</div>

<div class="modal fade" id="appointmentDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Appointment Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2"><strong>Patient:</strong> <span id="detailsPatient"></span></div>
                <div class="mb-2"><strong>Service:</strong> <span id="detailsService"></span></div>
                <div class="mb-2"><strong>Time:</strong> <span id="detailsTime"></span></div>
                <div class="mb-2"><strong>Status:</strong> <span id="detailsStatus"></span></div>
                <div><strong>Notes:</strong> <span id="detailsNotes"></span></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

/*
|--------------------------------------------------------------------------
| CALENDAR DATA
|--------------------------------------------------------------------------
*/

const appointments = <?= json_encode($calendarAppointments) ?>;


/*
|--------------------------------------------------------------------------
| SIMPLE APPOINTMENT CALENDAR
|--------------------------------------------------------------------------
*/

function createCalendar() {

    const container =
        document.getElementById("calendarContainer");

    const now = new Date();

    const year = now.getFullYear();

    const month = now.getMonth();

    const monthName =
        now.toLocaleString("default", {
            month: "long"
        });

    const firstDay =
        new Date(year, month, 1).getDay();

    const daysInMonth =
        new Date(year, month + 1, 0).getDate();


    let html = "";

    html += `
        <h3 style="margin-bottom:15px;">
            ${monthName} ${year}
        </h3>
    `;


    html += `
        <div class="calendar">
    `;


    const days = [
        "Sun",
        "Mon",
        "Tue",
        "Wed",
        "Thu",
        "Fri",
        "Sat"
    ];


    days.forEach(day => {

        html += `
            <div class="calendar-header">
                ${day}
            </div>
        `;

    });


    for (let i = 0; i < firstDay; i++) {

        html += `
            <div class="calendar-day"></div>
        `;

    }


    for (let day = 1; day <= daysInMonth; day++) {

        const dateString =
            year +
            "-" +
            String(month + 1).padStart(2, "0") +
            "-" +
            String(day).padStart(2, "0");


        const dayAppointments =
            appointments.filter(
                appointment =>
                    appointment.appointment_date === dateString
            );


        html += `
            <div class="calendar-day">

                <div class="calendar-date">
                    ${day}
                </div>
        `;


        dayAppointments.forEach(appointment => {

            let time = appointment.appointment_time;

            if (time) {

                time = time.substring(0, 5);

            }

            html += `
                <div class="calendar-event">

                    <strong>
                        ${escapeHtml(appointment.patient_name)}
                    </strong>

                    <br>

                    ${time}

                    <br>

                    ${escapeHtml(appointment.status)}

                </div>
            `;

        });


        html += `
            </div>
        `;

    }


    html += `
        </div>
    `;


    container.innerHTML = html;

}


/*
|--------------------------------------------------------------------------
| ESCAPE HTML
|--------------------------------------------------------------------------
*/

function escapeHtml(text) {

    const div =
        document.createElement("div");

    div.textContent =
        text ?? "";

    return div.innerHTML;

}


document.querySelectorAll('.appointment-details-button').forEach(function (button) {
    button.addEventListener('click', function () {
        document.getElementById('detailsPatient').textContent = button.dataset.patient || '';
        document.getElementById('detailsService').textContent = button.dataset.service || '';
        document.getElementById('detailsTime').textContent = button.dataset.time || '';
        document.getElementById('detailsStatus').textContent = button.dataset.status || '';
        document.getElementById('detailsNotes').textContent = button.dataset.notes || 'None';
    });
});


document.querySelectorAll('.patient-menu a[href^="#"]').forEach(function (link) {
    link.addEventListener('click', function (event) {
        const target = document.querySelector(link.getAttribute('href'));

        if (!target) {
            return;
        }

        event.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });

        document.querySelectorAll('.patient-menu a[href^="#"]').forEach(function (item) {
            item.classList.remove('active');
        });
        link.classList.add('active');
    });
});


/* Ang dashboard ay laging may sidebar — ang mga bubukas na page (?nosidebar=1) lang ang walang sidebar */


createCalendar();


/* =========================
   MY CLINICAL CHARTS
========================= */
(function initDentistCharts(){
    if (typeof Chart === "undefined") return;

    Chart.defaults.font.family = "'Inter','DM Sans',system-ui,sans-serif";
    Chart.defaults.color = "#7e969f";
    Chart.defaults.font.size = 11;
    Chart.defaults.font.weight = 600;

    var tooltipStyle = {
        backgroundColor: "#0A3B34",
        titleColor: "#ffffff",
        bodyColor: "#D9F2E6",
        padding: 12,
        cornerRadius: 10,
        displayColors: true,
        boxPadding: 4,
        titleFont: { weight: "700", size: 12 }
    };

    var weekLabels = <?= json_encode($dWeekLabels) ?>;
    var weekData = <?= json_encode($dWeekData) ?>;
    var statusLabels = <?= json_encode($dStatusLabels) ?>;
    var statusData = <?= json_encode($dStatusData) ?>;

    var weekTotal = weekData.reduce(function(a,b){ return a + (parseInt(b,10)||0); }, 0);
    var weekPill = document.getElementById("dWeekPill");
    if (weekPill) { weekPill.textContent = weekTotal + " patient" + (weekTotal === 1 ? "" : "s"); }

    var statusTotal = statusData.reduce(function(a,b){ return a + (parseInt(b,10)||0); }, 0);

    /* ============ Dynamic insights (My Week Ahead + Treatment Outcomes) ============ */
    var dDayName = { Sun:"Sunday", Mon:"Monday", Tue:"Tuesday", Wed:"Wednesday", Thu:"Thursday", Fri:"Friday", Sat:"Saturday" };

    var weekNote = document.getElementById("dWeekInsight");
    if (weekNote && weekNote.querySelector("span")) {
        var wSpan = weekNote.querySelector("span");
        if (weekTotal === 0) {
            wSpan.innerHTML = "No appointments scheduled for the next 7 days — your calendar is clear.";
        } else {
            var wRanked = weekData.map(function(v, i){
                return { n: dDayName[weekLabels[i]] || weekLabels[i], v: parseInt(v, 10) || 0 };
            }).filter(function(x){ return x.v > 0; }).sort(function(a, b){ return b.v - a.v; });
            var wTop = wRanked.slice(0, 2).map(function(x){ return x.n + " (" + x.v + ")"; });
            wSpan.innerHTML = "<strong>" + weekTotal + " patient" + (weekTotal === 1 ? "" : "s") + "</strong> scheduled for the next 7 days"
                + (wTop.length ? " — busiest on <strong>" + wTop.join("</strong> and <strong>") + "</strong>." : ".");
        }
    }

    var statusNote = document.getElementById("dStatusInsight");
    if (statusNote && statusNote.querySelector("span")) {
        var sSpan = statusNote.querySelector("span");
        if (statusTotal === 0) {
            sSpan.innerHTML = "No appointments recorded yet — the breakdown of your appointments by status will appear here.";
        } else {
            var sRanked = statusData.map(function(v, i){
                return { n: statusLabels[i], v: parseInt(v, 10) || 0 };
            }).filter(function(x){ return x.v > 0; }).sort(function(a, b){ return b.v - a.v; });
            var sParts = sRanked.slice(0, 3).map(function(x){
                return "<strong>" + Math.round(x.v / statusTotal * 100) + "% " + x.n + "</strong> (" + x.v + ")";
            });
            sSpan.innerHTML = "Out of <strong>" + statusTotal + "</strong> total appointment" + (statusTotal === 1 ? "" : "s") + ": " + sParts.join(", ")
                + (sRanked.length > 3 ? ", and others." : ".");
        }
    }

    var weekEl = document.getElementById("dChartWeek");
    if (weekEl) {
        var wctx = weekEl.getContext("2d");
        var wgrad = wctx.createLinearGradient(0, 0, 0, 260);
        wgrad.addColorStop(0, "#149A72");
        wgrad.addColorStop(1, "#0A4A41");
        window.dChartWeek = new Chart(weekEl, {
            type: "bar",
            data: {
                labels: weekLabels,
                datasets: [{
                    label: "Scheduled patients",
                    data: weekData,
                    backgroundColor: wgrad,
                    hoverBackgroundColor: "#0E6B5E",
                    borderRadius: 10,
                    borderSkipped: false,
                    maxBarThickness: 52
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: Object.assign({}, tooltipStyle, {
                        callbacks: { label: function(c){ return " " + c.parsed.y + " patient" + (c.parsed.y === 1 ? "" : "s"); } }
                    })
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, grid: { color: "#edf3f4" }, border: { display: false }, title: { display: true, text: "No. of Scheduled Patients", color: "#57727d", font: { size: 11, weight: "700" } } },
                    x: { grid: { display: false }, border: { display: false }, title: { display: true, text: "Day (Next 7 Days)", color: "#57727d", font: { size: 11, weight: "700" } } }
                }
            }
        });
    }

    var statusEl = document.getElementById("dChartStatus");
    /* Inline plugin: % sa loob + callout labels sa labas na may leader lines. Walang bagong CDN. */
    var dStatusPctPlugin = {
        id: "dStatusPct",
        afterDraw: function(chart) {
            if (!chart || !chart.canvas || chart.canvas.id !== "dChartStatus") return;
            var meta = chart.getDatasetMeta(0);
            if (!meta || !meta.data || !meta.data.length) return;
            var dataset = chart.data.datasets[0];
            var vals = dataset ? dataset.data || [] : [];
            var names = chart.data.labels || [];
            var sliceColors = (dataset && dataset.backgroundColor) || [];
            var total = vals.reduce(function(a, b){ return a + (parseInt(b, 10) || 0); }, 0);
            if (!total) return;
            var ctx = chart.ctx;
            /* --- 1) % sa loob (lahat ng may laman, >=3% lang para kasya) --- */
            ctx.save();
            ctx.font = "700 11px Inter, sans-serif";
            ctx.fillStyle = "#ffffff";
            ctx.textAlign = "center";
            ctx.textBaseline = "middle";
            ctx.shadowColor = "rgba(0,0,0,0.35)";
            ctx.shadowBlur = 3;
            meta.data.forEach(function(arc, i){
                var v = parseInt(vals[i], 10) || 0;
                if (!v) return;
                var pct = (v / total) * 100;
                if (pct < 3) return;
                var p = arc.getProps(["x", "y", "startAngle", "endAngle", "outerRadius", "innerRadius"], true);
                var mid = (p.startAngle + p.endAngle) / 2;
                var r = (p.outerRadius + p.innerRadius) / 2;
                var x = p.x + Math.cos(mid) * r;
                var y = p.y + Math.sin(mid) * r;
                ctx.fillText(Math.round(pct) + "%", x, y);
            });
            ctx.restore();
            /* --- 2) Callout labels sa labas na may leader lines (lahat ng may laman) --- */
            var items = [];
            meta.data.forEach(function(arc, i){
                var v = parseInt(vals[i], 10) || 0;
                if (!v) return;
                var pct = (v / total) * 100;
                var p = arc.getProps(["x", "y", "startAngle", "endAngle", "outerRadius"], true);
                // Kahit maliit na slice (kahit 1 pasyente) lagyan pa rin ng callout.
                if ((p.endAngle - p.startAngle) <= 0) return;
                var mid = (p.startAngle + p.endAngle) / 2;
                var cos = Math.cos(mid), sin = Math.sin(mid);
                var isRight = cos >= 0;
                var ox = p.x + cos * p.outerRadius;
                var oy = p.y + sin * p.outerRadius;
                var ex = p.x + cos * (p.outerRadius + 14);
                var ey = p.y + sin * (p.outerRadius + 14);
                var ax = ex + (isRight ? 16 : -16);
                var text = (names[i] || "") + " " + v + " (" + Math.round(pct) + "%)";
                items.push({ ox: ox, oy: oy, ex: ex, ey: ey, ax: ax, ay: ey, right: isRight, text: text, color: sliceColors[i % sliceColors.length] || "#0E6B5E" });
            });
            if (!items.length) return;
            // Iwasan ang pagdidikit ng labels + huwag palampasin sa legend sa baba.
            var safeArea = chart.chartArea || null;
            var minAy = safeArea ? safeArea.top + 12 : 0;
            var maxAy = safeArea ? safeArea.bottom - 14 : 9999;
            ["right", "left"].forEach(function(side){
                var wantRight = (side === "right");
                var sideItems = items.filter(function(it){ return it.right === wantRight; }).sort(function(a, b){ return a.ay - b.ay; });
                // Clamp muna sa safe area bago mag-ayos ng dikit.
                sideItems.forEach(function(it){
                    if (it.ay < minAy) it.ay = minAy;
                    if (it.ay > maxAy) it.ay = maxAy;
                });
                for (var k = 1; k < sideItems.length; k++) {
                    if (sideItems[k].ay - sideItems[k - 1].ay < 30) {
                        sideItems[k].ay = sideItems[k - 1].ay + 30;
                    }
                }
                // Kapag lumampas sa baba (hal. Pending sa ilalim), hilahin pataas lahat.
                for (var m = sideItems.length - 1; m >= 0; m--) {
                    if (sideItems[m].ay > maxAy) sideItems[m].ay = maxAy - (sideItems.length - 1 - m) * 30;
                }
                for (var n = 1; n < sideItems.length; n++) {
                    if (sideItems[n].ay - sideItems[n - 1].ay < 30) {
                        sideItems[n].ay = sideItems[n - 1].ay + 30;
                        if (sideItems[n].ay > maxAy) sideItems[n].ay = maxAy;
                    }
                }
            });
            ctx.save();
            ctx.font = "700 13px Inter, sans-serif";
            ctx.textBaseline = "middle";
            ctx.shadowColor = "transparent";
            ctx.shadowBlur = 0;
            items.forEach(function(it){
                // Leader line: slice -> elbow -> label.
                ctx.strokeStyle = it.color;
                ctx.lineWidth = 1.2;
                ctx.beginPath();
                ctx.moveTo(it.ox, it.oy);
                ctx.lineTo(it.ex, it.ey);
                ctx.lineTo(it.ax, it.ay);
                ctx.stroke();
                // Tuldok sa pinanggalingan sa slice.
                ctx.fillStyle = it.color;
                ctx.beginPath();
                ctx.arc(it.ox, it.oy, 2.5, 0, Math.PI * 2);
                ctx.fill();
                // Label na may puting background para kita.
                var w = ctx.measureText(it.text).width;
                var padX = 10, padH = 4;
                var bx = it.right ? it.ax : it.ax - w - padX * 2;
                var by = it.ay - 12;
                var bh = 24;
                ctx.fillStyle = "#ffffff";
                ctx.strokeStyle = it.color;
                ctx.lineWidth = 1;
                ctx.beginPath();
                if (ctx.roundRect) { ctx.roundRect(bx, by, w + padX * 2, bh, 5); } else { ctx.rect(bx, by, w + padX * 2, bh); }
                ctx.fill();
                ctx.stroke();
                ctx.fillStyle = "#143f51";
                ctx.textAlign = it.right ? "left" : "right";
                ctx.fillText(it.text, it.right ? it.ax + padX : it.ax - padX, it.ay + 0.5);
            });
            ctx.restore();
        }
    };
    if (statusEl) {
        window.dChartStatus = new Chart(statusEl, {
            type: "doughnut",
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusData,
                    backgroundColor: ["#1cab71", "#0E6B5E", "#f5a623", "#e15b5b", "#7a6ff0"],
                    hoverBackgroundColor: ["#149a64", "#098aa3", "#e0951a", "#cf4f4f", "#6a5fde"],
                    borderColor: "#ffffff",
                    borderWidth: 4,
                    hoverOffset: 8,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: "68%",
                layout: { padding: { left: 100, right: 100, top: 30, bottom: 12 } },
                plugins: {
                    legend: { position: "bottom", labels: { boxWidth: 11, boxHeight: 11, borderRadius: 4, useBorderRadius: true, padding: 12, font: { size: 11, weight: "600" } } },
                    tooltip: Object.assign({}, tooltipStyle, {
                        callbacks: {
                            label: function(c){
                                var pct = statusTotal > 0 ? Math.round((c.parsed / statusTotal) * 100) : 0;
                                return " " + c.label + ": " + c.parsed + " (" + pct + "%)";
                            }
                        }
                    })
                }
            },
            plugins: [dStatusPctPlugin]
        });
    }
})();

</script>

<script>
/* Accordion tabs: isa lang bukas — pag pinindot ang isa, sasara ang iba */
(function(){
    var tabs = document.querySelectorAll('.section-tab');
    tabs.forEach(function(tab){
        var title = tab.querySelector('.section-title');
        if(!title) return;
        title.addEventListener('click', function(e){
            if(e.target.closest('a, button, select, form')) return;
            var isOpen = tab.classList.contains('open');
            tabs.forEach(function(t){ t.classList.remove('open'); });
            if(!isOpen) tab.classList.add('open');
        });
    });
})();

/* Accordion charts: isa lang bukas — i-resize ang chart pag binuksan */
(function(){
    var cards = document.querySelectorAll('.d-chart-card.d-acc');
    cards.forEach(function(card){
        var head = card.querySelector('.d-chart-top');
        if(!head) return;
        head.addEventListener('click', function(){
            var isOpen = card.classList.contains('open');
            cards.forEach(function(c){ c.classList.remove('open'); });
            if(!isOpen) {
                card.classList.add('open');
                setTimeout(function(){
                    try {
                        if (card.querySelector('#dChartWeek') && window.dChartWeek) window.dChartWeek.resize();
                        if (card.querySelector('#dChartStatus') && window.dChartStatus) window.dChartStatus.resize();
                    } catch(e){}
                }, 60);
            }
        });
    });
})();
</script>

<script>
/* CLICKABLE SUMMARY CARDS — gaya ng admin dashboard (same page, no new tab) */
(function initDentistCardDetails(){
    var VIEWS = ["today","upcoming","completed","pending","confirmed","noshow"];
    var KEY = "dentistCardDetailView";
    var wrap = document.getElementById("cardDetailWrap");
    if (!wrap) return;
    var cards = Array.prototype.slice.call(document.querySelectorAll(".stat-clickable[data-view]"));
    var statsGrid = document.getElementById("statsGrid");
    var welcomeHero = document.getElementById("welcomeHero");

    function panels(){
        return VIEWS.map(function(v){ return document.getElementById("detail-" + v); }).filter(Boolean);
    }
    function getNav(){
        return document.getElementById("detailNav");
    }
    function hidePanelsOnly(){
        panels().forEach(function(p){ p.style.display = "none"; });
        cards.forEach(function(c){ c.classList.remove("active-card"); });
        document.querySelectorAll(".detail-back-row").forEach(function(r){ r.classList.remove("is-open"); });
    }
    function showBackRowFor(panel){
        document.querySelectorAll(".detail-back-row").forEach(function(r){
            var match = r.getAttribute("data-for") === panel.id;
            r.classList.toggle("is-open", match);
        });
    }

    function show(view, scroll, fromNav){
        var panel = document.getElementById("detail-" + view);
        if (!panel) return;
        var alreadyOpen = panel.style.display !== "none";
        var nav = getNav();

        // Pinindot ulit ang parehong summary card: toggle (isara) para bumalik sa summary cards view.
        // Galing sa loob na nav at bukas na: stay lang.
        if (alreadyOpen) {
            if (fromNav) {
                if (nav) {
                    nav.querySelectorAll(".detail-nav-card").forEach(function(n){
                        n.classList.toggle("active-nav", n.getAttribute("data-goto") === view);
                    });
                }
                return;
            }
            closeAll();
            if (scroll !== false && statsGrid && statsGrid.scrollIntoView) {
                setTimeout(function(){ statsGrid.scrollIntoView({ behavior: "smooth", block: "start" }); }, 80);
            }
            return;
        }

        // 1. Itago lahat ng panels
        hidePanelsOnly();
        // 2. Ipakita ang napiling panel at ang Back button row nito
        panel.style.display = "block";
        showBackRowFor(panel);
        // 3. Pawalain ang summary cards + welcome banner
        if (statsGrid) statsGrid.style.display = "none";
        if (welcomeHero) welcomeHero.style.display = "none";
        cards.forEach(function(c){ c.classList.toggle("active-card", c.getAttribute("data-view") === view); });

        // 4. Loob na nav: lahat ng summary cards maliban sa kasalukuyang bukas
        if (nav) {
            nav.style.display = "grid";
            nav.querySelectorAll(".detail-nav-card").forEach(function(n){
                var isCurrent = n.getAttribute("data-goto") === view;
                n.classList.toggle("active-nav", isCurrent);
                n.style.display = isCurrent ? "none" : "";
            });
        }

        try { sessionStorage.setItem(KEY, view); } catch(e){}
        try { history.replaceState(null, "", "#card-" + view); } catch(e){ window.location.hash = "card-" + view; }
        if (scroll !== false && wrap && wrap.scrollIntoView) {
            setTimeout(function(){ wrap.scrollIntoView({ behavior: "smooth", block: "start" }); }, 80);
        }
    }

    function closeAll(){
        hidePanelsOnly();
        if (statsGrid) statsGrid.style.display = "";
        if (welcomeHero) welcomeHero.style.display = "";
        var nav2 = getNav();
        if (nav2) {
            nav2.style.display = "none";
            nav2.querySelectorAll(".detail-nav-card").forEach(function(n){
                n.classList.remove("active-nav");
                n.style.display = "";
            });
        }
        try { sessionStorage.removeItem(KEY); } catch(e){}
        try { history.replaceState(null, "", window.location.pathname + window.location.search); } catch(e){}
        if (statsGrid && statsGrid.scrollIntoView) {
            setTimeout(function(){ statsGrid.scrollIntoView({ behavior: "smooth", block: "start" }); }, 80);
        }
    }

    // "Back to Dashboard" — NASA LABAS ng card box, sa itaas nito (gaya ng Admin Dashboard)
    document.querySelectorAll(".card-detail").forEach(function(panel){
        if (panel.querySelector(".detail-back-btn")) return;
        if (panel.parentNode && panel.parentNode.querySelector(".detail-back-row[data-for='" + panel.id + "']")) return;

        var backBtn = document.createElement("button");
        backBtn.type = "button";
        backBtn.className = "detail-back-btn";
        backBtn.innerHTML = '<i class="bi bi-arrow-left"></i> Back to Dashboard';
        backBtn.addEventListener("click", closeAll);

        var row = document.createElement("div");
        row.className = "detail-back-row";
        row.setAttribute("data-for", panel.id);
        row.appendChild(backBtn);

        if (panel.parentNode) panel.parentNode.insertBefore(row, panel);
    });

    // Close buttons (X button sa header o anumang data-close-detail)
    document.querySelectorAll(".detail-close, [data-close-detail]").forEach(function(btn){
        btn.addEventListener("click", closeAll);
    });

    // Loob na nav cards (switch view nang hindi nag-close)
    document.querySelectorAll("#detailNav .detail-nav-card").forEach(function(navCard){
        var goNav = function(){ show(navCard.getAttribute("data-goto"), true, true); };
        navCard.addEventListener("click", goNav);
        navCard.addEventListener("keydown", function(ev){
            if (ev.key === "Enter" || ev.key === " ") { ev.preventDefault(); goNav(); }
        });
    });

    // Labas na summary cards sa statsGrid
    cards.forEach(function(card){
        card.addEventListener("click", function(){ show(card.getAttribute("data-view")); });
        card.addEventListener("keydown", function(ev){
            if (ev.key === "Enter" || ev.key === " ") { ev.preventDefault(); show(card.getAttribute("data-view")); }
        });
    });

    // ESC key closes detail view
    document.addEventListener("keydown", function(e){
        if (e.key !== "Escape") return;
        var det = document.getElementById("dApptDetails");
        if (det && det.classList.contains("open")) return;
        var logout = document.getElementById("dentistLogoutConfirm");
        if (logout && logout.style.display !== "none") return;
        closeAll();
    });
    function applyDetailFilter(card, group, filter){
        var f = (filter || "all").toLowerCase();
        var rows = Array.prototype.slice.call(card.querySelectorAll(".d-tl-item, .d-tbl-row"));
        rows.forEach(function(r){
            var keep = false;
            if (f === "all") keep = true;
            else if (group === "today") keep = (r.getAttribute("data-status") || "").toLowerCase() === f;
            else if (group === "upcoming") {
                if (f === "week") keep = r.getAttribute("data-week") === "1";
                else if (f === "month") keep = r.getAttribute("data-month") === "1";
                else if (f === "beyond") keep = r.getAttribute("data-beyond") === "1";
            } else {
                if (f === "today") keep = r.getAttribute("data-today") === "1";
                else if (f === "week") keep = r.getAttribute("data-week") === "1";
                else if (f === "month") keep = r.getAttribute("data-month") === "1";
            }
            r.style.display = keep ? "" : "none";
        });
    }
    document.querySelectorAll(".card-detail, .card-record").forEach(function(card){
        var tabWrap = card.querySelector(".detail-tabs");
        var miniWrap = card.querySelector(".detail-mini-grid[data-mini-group]");
        var group = "";
        if (tabWrap) group = tabWrap.getAttribute("data-tab-group") || "";
        else if (miniWrap) group = miniWrap.getAttribute("data-mini-group") || "";
        if (!group) return;
        function syncActive(filter){
            var f = (filter || "all").toLowerCase();
            if (tabWrap) tabWrap.querySelectorAll(".detail-tab").forEach(function(t){ t.classList.toggle("active", (t.getAttribute("data-filter") || "all").toLowerCase() === f); });
            if (miniWrap) miniWrap.querySelectorAll(".detail-mini-clickable").forEach(function(m){ m.classList.toggle("active-mini", (m.getAttribute("data-filter") || "all").toLowerCase() === f); });
        }
        if (tabWrap) tabWrap.querySelectorAll(".detail-tab").forEach(function(tab){
            tab.addEventListener("click", function(ev){ ev.stopPropagation(); var f = tab.getAttribute("data-filter") || "all"; syncActive(f); applyDetailFilter(card, group, f); });
        });
        if (miniWrap) miniWrap.querySelectorAll(".detail-mini-clickable").forEach(function(mini){
            var go = function(ev){ if (ev) ev.stopPropagation(); var f = mini.getAttribute("data-filter") || "all"; syncActive(f); applyDetailFilter(card, group, f); };
            mini.addEventListener("click", go);
            mini.addEventListener("keydown", function(ev){ if (ev.key === "Enter" || ev.key === " ") { ev.preventDefault(); go(ev); } });
        });
        syncActive("all");
    });
    /* ===== PAGINATION (JS): 10 rows kada page, kapalit ng scroll bar =====
       Lahat ng rows nananatili sa DOM; nagtatago lang ang lampag na 10.
       Naka-sync sa tab filtering — pag nagpalit ng tab, nire-paginate ulit. */
    (function(){
        var PER_PAGE = 10;

        /* DALAWAANG HIWALAY NA ESTADO — ito ang sanhi ng dating bug:
           - inline style.display  -> pagkakatago ng TAB FILTER (All/Week/Month)
           - .d-pg-hidden class     -> pagkakatago ng PAGINATION (10 per page)
           Dati ginamit ang inline style para sa dalawa, kaya nawawala ang
           pagination pag pumili ng page (10 rows lang ang nakikita = 1 page). */

        function allRows(tbody){
            return Array.prototype.slice.call(
                tbody.querySelectorAll(".d-tbl-row, .d-tl-item")
            );
        }

        /* Rows na tugma sa tab filter. IMPORTANTE: sinusuri lang ang inline
           style.display — HINDI ang .d-pg-hidden. Kasi yung class na yan ang
           sarili nating pagtatago (pagination), at kung sakaling ito ang
           titingnan, mawawala ang lahat ng row na nasa page 2+ at
           mawawala na rin ang mga button na 2, 3, ... */
        function tabRows(tbody){
            return allRows(tbody).filter(function(r){
                return r.style.display !== "none";
            });
        }

        function renumber(tbody){
            tabRows(tbody).forEach(function(r, i){
                var cell = r.querySelector(".d-col-num span") || r.querySelector(".d-tl-num");
                if (cell) cell.textContent = String(i + 1);
            });
        }

        function makePager(tbody){
            var scroll = tbody.closest(".d-tbl-scroll, .d-tl");
            if (!scroll) scroll = tbody;
            var host = scroll.parentNode;
            var old = host.querySelector(".d-pagination");
            if (old) old.parentNode.removeChild(old);

            var totalRows = allRows(tbody).length;
            if (totalRows <= PER_PAGE) return;   // isang page lang = walang pagination

            var nav = document.createElement("nav");
            nav.className = "d-pagination";
            nav.setAttribute("aria-label", "Pages");
            host.appendChild(nav);

            var state = 1;
            var totalPages = 1;

            function build(){
                while (nav.firstChild) nav.removeChild(nav.firstChild);

                function add(label, page, opts){
                    opts = opts || {};
                    var b = document.createElement("button");
                    b.type = "button";
                    b.className = "d-page-btn" + (opts.active ? " is-active" : "") + (opts.disabled ? " is-disabled" : "");
                    b.innerHTML = label;
                    if (opts.disabled) {
                        b.setAttribute("disabled", "disabled");
                    } else {
                        b.addEventListener("click", function(ev){
                            ev.stopPropagation();
                            go(page);
                        });
                    }
                    nav.appendChild(b);
                }

                add('<i class="bi bi-chevron-left"></i> Prev', state - 1, { disabled: state === 1 });

                for (var p = 1; p <= totalPages; p++){
                    add(String(p), p, { active: p === state });
                }

                add('Next <i class="bi bi-chevron-right"></i>', state + 1, { disabled: state === totalPages });
            }

            function go(page){
                var rows = tabRows(tbody);
                totalPages = Math.max(1, Math.ceil(rows.length / PER_PAGE));
                state = Math.min(Math.max(1, page), totalPages);

                rows.forEach(function(r, i){
                    var onPage = i >= (state - 1) * PER_PAGE && i < state * PER_PAGE;
                    r.classList.toggle("d-pg-hidden", !onPage);
                });

                renumber(tbody);
                build();
            }

            go(1);

            /* Kapag may pinindot na tab, bumalik sa una at magsimula ulit. */
            var card = tbody.closest(".card-record") || tbody.closest(".card-detail");
            if (card){
                card.querySelectorAll(".detail-tab, .detail-mini-clickable").forEach(function(t){
                    t.addEventListener("click", function(){
                        setTimeout(function(){
                            /* Alisin muna ang dating hidden marker bago
                               basahin ang bagong tapos na filter ng tab. */
                            allRows(tbody).forEach(function(r){ r.classList.remove("d-pg-hidden"); });
                            go(1);
                        }, 0);
                    });
                });
            }
        }

        function initAll(){
            /* Table (pop-up record) */
            document.querySelectorAll(".d-tbl-scroll").forEach(function(w){
                var tbody = w.querySelector("tbody");
                if (tbody) makePager(tbody);
            });

            /* Timeline (.d-tl) — Pending / Confirmed / No-Show / Upcoming card */
            document.querySelectorAll(".d-tl").forEach(function(w){
                if (w.querySelector(".d-tl-item")) makePager(w);
            });
        }

        initAll();
    })();

    /* Hindi dapat maisara ang popup pag pinindot ang View / tabs / scroll / pagination sa loob ng record. */
    document.querySelectorAll(".card-record").forEach(function(rec){
        rec.addEventListener("click", function(ev){ ev.stopPropagation(); });
    });
    // DEFAULT: lahat ng detail nakatago pag load — bubukas lang pag pinindot ang summary card.
    try { sessionStorage.removeItem(KEY); } catch(e){}
    try {
        if (window.location.hash && window.location.hash.indexOf("#card-") === 0) {
            history.replaceState(null, "", window.location.pathname + window.location.search);
        }
    } catch(e){}
    closeAll();
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

<!-- TODAY APPOINTMENT DETAILS MODAL — pinupuno mula sa "View" button sa table -->
<div id="dApptDetails" class="d-modal-overlay">
    <div class="d-modal-box" role="dialog" aria-modal="true" aria-labelledby="dApptDetailsTitle">
        <div class="d-modal-head">
            <span class="d-modal-head-icon"><i class="bi bi-calendar-event-fill"></i></span>
            <div>
                <h4 id="dApptDetailsTitle">Appointment Details</h4>
                <small>Buod ng naka-schedule na appointment</small>
            </div>
            <button type="button" class="d-modal-x" id="dApptDetailsClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="d-modal-body">
            <div class="d-modal-row"><label>Patient Name</label><div class="value" id="dDetPatient">—</div></div>
            <div class="d-modal-row"><label>Time of Appointment</label><div class="value" id="dDetTime">—</div></div>
            <div class="d-modal-row"><label>Dental Service</label><div class="value" id="dDetService">—</div></div>
            <div class="d-modal-row"><label>Assigned Dentist</label><div class="value" id="dDetDentist">—</div></div>
            <div class="d-modal-row"><label>Status</label><div class="value" id="dDetStatus">—</div></div>
            <div class="d-modal-row"><label>Notes</label><div class="value" id="dDetNotes">—</div></div>
        </div>
        <div class="d-modal-foot">
            <button type="button" class="d-modal-btn" id="dApptDetailsCancel">Close</button>
        </div>
    </div>
</div>

<script>
/* TODAY APPOINTMENT DETAILS MODAL — View button sa #detail-today table */
(function(){
    var overlay = document.getElementById("dApptDetails");
    if(!overlay) return;
    var lastBtn = null;
    function setVal(id, val){
        var el = document.getElementById(id);
        if(!el) return;
        var v = (val || "").trim();
        el.textContent = v !== "" ? v : "—";
        el.classList.toggle("is-empty", v === "");
    }
    function close(){ overlay.classList.remove("open"); if(lastBtn) lastBtn.focus(); }
    document.querySelectorAll(".d-view-btn").forEach(function(btn){
        btn.addEventListener("click", function(){
            lastBtn = btn;
            setVal("dDetPatient", btn.dataset.dPatient);
            setVal("dDetTime", btn.dataset.dTime);
            setVal("dDetService", btn.dataset.dService);
            setVal("dDetDentist", btn.dataset.dDentist);
            setVal("dDetStatus", btn.dataset.dStatus);
            setVal("dDetNotes", btn.dataset.dNotes);
            overlay.classList.add("open");
        });
    });
    document.getElementById("dApptDetailsClose").addEventListener("click", close);
    document.getElementById("dApptDetailsCancel").addEventListener("click", close);
    overlay.addEventListener("click", function(e){ if(e.target === overlay) close(); });
    document.addEventListener("keydown", function(e){ if(e.key === "Escape") close(); });
})();
</script>
</html>
