<?php

require_once __DIR__ . '/../config.php';

if (
    !isset($_SESSION['user_id']) ||
    strtolower(trim($_SESSION['role'] ?? '')) !== 'patient'
) {
    header('Location: ../login.php');
    exit;
}

function myAppointmentsEscape($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT id
    FROM patients
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$patientId = (int) ($stmt->fetchColumn() ?: 0);

/* Patient name for table display */
$patientFullName = '';
$patientNumber = '';
if ($patientId > 0) {
    $nameStmt = $pdo->prepare("SELECT first_name, last_name, patient_number FROM patients WHERE id = ? LIMIT 1");
    $nameStmt->execute([$patientId]);
    $pRow = $nameStmt->fetch(PDO::FETCH_ASSOC);
    if ($pRow) {
        $patientFullName = trim(($pRow['first_name'] ?? '') . ' ' . ($pRow['last_name'] ?? ''));
        $patientNumber = trim($pRow['patient_number'] ?? '');
    }
}
if ($patientFullName === '') {
    $patientFullName = $_SESSION['full_name'] ?? 'Patient';
}

/* ============================================================
   B: PATIENT CANCEL APPOINTMENT
   - Hide agad pag may nag-book, open lang pag nag-cancel
   - Only allow cancel for own Pending/Confirmed future appointments
   ============================================================ */
$cancelSuccess = '';
$cancelError = '';


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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($patientId > 0)) {
    $cancelAction = trim($_POST['action'] ?? '');
    if ($cancelAction === 'confirm_attendance') {
        $confirmId = (int)($_POST['appointment_id'] ?? 0);
        if ($confirmId <= 0) {
            $cancelError = 'Invalid appointment.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, appointment_date, start_time, status FROM appointments WHERE id = ? AND patient_id = ? LIMIT 1");
                $stmt->execute([$confirmId, $patientId]);
                $toConfirm = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$toConfirm) {
                    $cancelError = 'Appointment not found.';
                } elseif (!in_array($toConfirm['status'], ['Pending', 'Confirmed'], true)) {
                    $cancelError = 'Only Pending or Confirmed appointments can be confirmed.';
                } elseif ($toConfirm['appointment_date'] < date('Y-m-d')) {
                    $cancelError = 'Past appointments cannot be confirmed.';
                } else {
                    $checkStmt = $pdo->prepare("SELECT id FROM appointment_concerns WHERE appointment_id = ? AND patient_id = ? AND reason = 'Confirmation' LIMIT 1");
                    $checkStmt->execute([$confirmId, $patientId]);
                    if (!$checkStmt->fetch()) {
                        $insStmt = $pdo->prepare("INSERT INTO appointment_concerns (appointment_id, patient_id, reason, message, status) VALUES (?, ?, ?, ?, 'New')");
                        $insStmt->execute([$confirmId, $patientId, 'Confirmation', 'Patient confirmed attendance via My Appointments for appointment on ' . $toConfirm['appointment_date'] . ' at ' . date('h:i A', strtotime($toConfirm['start_time'])) . '.']);
                    }
                    $cancelSuccess = 'Thank you! Your attendance is confirmed. This counts as your reply.';
                }
            } catch (Throwable $e) {
                $cancelError = 'Unable to confirm attendance. Please try again.';
            }
        }
    } elseif ($cancelAction === 'request_reschedule') {
        $reschedId = (int)($_POST['appointment_id'] ?? 0);
        $prefDate = trim($_POST['preferred_date'] ?? '');
        $prefTime = trim($_POST['preferred_time'] ?? '');
        $reschedReason = trim($_POST['reschedule_reason'] ?? '');
        if ($reschedId <= 0) {
            $cancelError = 'Invalid appointment.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefDate) || $prefDate < date('Y-m-d')) {
            $cancelError = 'Preferred date must be today or later.';
        } elseif ((int)date('N', strtotime($prefDate)) === 7) {
            $cancelError = 'The clinic is closed on Sundays. Please select Monday to Saturday.';
        } elseif (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $prefTime)) {
            $cancelError = 'Preferred time is invalid.';
        } elseif (substr($prefTime, 0, 5) < '08:00' || substr($prefTime, 0, 5) > '17:30') {
            $cancelError = 'Preferred time must be within clinic operating hours (8:00 AM to 5:30 PM).';
        } elseif ($prefDate === date('Y-m-d') && substr($prefTime, 0, 5) <= date('H:i')) {
            $cancelError = 'Preferred time has already passed today. Please choose a later time or another date.';
        } elseif (mb_strlen($reschedReason) > 500) {
            $cancelError = 'Reason must not exceed 500 characters.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, appointment_date, start_time, status FROM appointments WHERE id = ? AND patient_id = ? LIMIT 1");
                $stmt->execute([$reschedId, $patientId]);
                $toResched = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$toResched) {
                    $cancelError = 'Appointment not found.';
                } elseif (!in_array($toResched['status'], ['Pending', 'Confirmed'], true)) {
                    $cancelError = 'Only Pending or Confirmed appointments can be rescheduled.';
                } elseif ($toResched['appointment_date'] < date('Y-m-d')) {
                    $cancelError = 'Past appointments cannot be rescheduled.';
                } else {
                    $prefTimeShort = substr($prefTime, 0, 5);
                    $insStmt = $pdo->prepare("INSERT INTO appointment_concerns (appointment_id, patient_id, reason, message, status) VALUES (?, ?, ?, ?, 'New')");
                    $insStmt->execute([
                        $reschedId,
                        $patientId,
                        'Reschedule Request',
                        'Patient requests reschedule of appointment on ' . $toResched['appointment_date'] . ' at ' . date('h:i A', strtotime($toResched['start_time'])) . ' to ' . $prefDate . ' at ' . date('h:i A', strtotime($prefTimeShort)) . '.' . ($reschedReason !== '' ? ' Reason: ' . $reschedReason : '')
                    ]);
                    $cancelSuccess = 'Reschedule request sent. It is now visible in the admin system.';
                }
            } catch (Throwable $e) {
                $cancelError = 'Unable to send reschedule request. Please try again.';
            }
        }
    } elseif ($cancelAction === 'log_email_click') {
        $emailId = (int)($_POST['appointment_id'] ?? 0);
        if ($emailId > 0) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM appointments WHERE id = ? AND patient_id = ? LIMIT 1");
                $stmt->execute([$emailId, $patientId]);
                if ($stmt->fetch()) {
                    $dupStmt = $pdo->prepare("SELECT id FROM appointment_concerns WHERE appointment_id = ? AND patient_id = ? AND reason = 'Email Request' AND DATE(created_at) = CURDATE() LIMIT 1");
                    $dupStmt->execute([$emailId, $patientId]);
                    if (!$dupStmt->fetch()) {
                        $insStmt = $pdo->prepare("INSERT INTO appointment_concerns (appointment_id, patient_id, reason, message, status) VALUES (?, ?, ?, ?, 'New')");
                        $insStmt->execute([$emailId, $patientId, 'Email Request', 'Patient emailed admin about reschedule via My Appointments (appointment #' . $emailId . ').']);
                    }
                }
            } catch (Throwable $e) {
            }
        }
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
    } elseif ($cancelAction === 'cancel_appointment') {
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        $cancelNotes = trim($_POST['cancel_notes'] ?? '');
        if ($appointmentId <= 0) {
            $cancelError = 'Invalid appointment.';
        } elseif (mb_strlen($cancelNotes) > 500) {
            $cancelError = 'Cancellation notes must not exceed 500 characters.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT id, appointment_date, start_time, status, notes
                    FROM appointments
                    WHERE id = ?
                    AND patient_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$appointmentId, $patientId]);
                $toCancel = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$toCancel) {
                    $cancelError = 'Appointment not found.';
                } elseif (!in_array($toCancel['status'], ['Pending', 'Confirmed'], true)) {
                    $cancelError = 'Only Pending or Confirmed appointments can be cancelled.';
                } elseif ($toCancel['appointment_date'] < date('Y-m-d')) {
                    $cancelError = 'Past appointments cannot be cancelled.';
                } else {
                    $newNotes = trim($toCancel['notes'] ?? '');
                    if ($cancelNotes !== '') {
                        $prefix = $newNotes !== '' ? $newNotes . "\n" : '';
                        $newNotes = $prefix . 'Cancelled by patient: ' . $cancelNotes;
                    } else {
                        $prefix = $newNotes !== '' ? $newNotes . "\n" : '';
                        $newNotes = $prefix . 'Cancelled by patient (no reason given).';
                    }
                    $stmt = $pdo->prepare("
                        UPDATE appointments
                        SET status = 'Cancelled', notes = ?
                        WHERE id = ?
                        AND patient_id = ?
                        AND status IN ('Pending', 'Confirmed')
                    ");
                    $stmt->execute([$newNotes, $appointmentId, $patientId]);

                    if ($stmt->rowCount() > 0) {
                        try {
                            $stmt2 = $pdo->prepare("
                                INSERT INTO notifications (patient_id, appointment_id, title, message, type)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmt2->execute([
                                $patientId,
                                $appointmentId,
                                'Appointment Cancelled',
                                'Your appointment on ' . $toCancel['appointment_date'] . ' at ' . date('h:i A', strtotime($toCancel['start_time'])) . ' has been cancelled. The slot is now available for other patients.' . ($cancelNotes !== '' ? ' Reason: ' . $cancelNotes : ''),
                                'Appointment'
                            ]);
                        } catch (Throwable $e) {
                            // ignore notification error
                        }
                        $cancelSuccess = 'Appointment cancelled successfully. The slot is now open for other patients.';
                    } else {
                        $cancelError = 'Unable to cancel appointment. Please try again.';
                    }
                }
            } catch (Throwable $e) {
                $cancelError = 'Unable to cancel appointment. Please try again.';
            }
        }
    }
}

$appointments = [];

if ($patientId > 0) {

    $stmt = $pdo->prepare("
        SELECT
            a.*,
            d.dentist_name,
            d.specialization
        FROM appointments a
        LEFT JOIN dentists d
            ON d.id = a.dentist_id
        WHERE a.patient_id = ?
        ORDER BY
            a.appointment_date DESC,
            a.start_time DESC
    ");

    $stmt->execute([$patientId]);

    $appointments =
        $stmt->fetchAll(PDO::FETCH_ASSOC);
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

<title>My Appointments | Abalos Dental</title>

<link
rel="stylesheet"
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
>

<link
rel="stylesheet"
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

<link
rel="stylesheet"
href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
>

<link
rel="stylesheet"
href="patient.css?v=8"
>

<style>
/* My Appointments — White card ginawang proper green-themed table */
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
.table-responsive { overflow-x: auto; }
.patient-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: #ffffff;
}
.patient-table thead tr {
    background: linear-gradient(135deg, #0E6B5E, #0A4A41);
}
.patient-table th {
    font-size: 11.5px !important;
    font-family: "Inter",system-ui,sans-serif;
    font-weight: 700;
    letter-spacing: .6px;
    text-transform: uppercase;
    color: #ffffff !important;
    background: transparent !important;
    padding: 14px 16px !important;
    border-bottom: none !important;
    white-space: nowrap;
    border-right: 1px solid rgba(255,255,255,.12);
}
.patient-table th:last-child { border-right: none; }
.patient-table td {
    font-size: 13.5px !important;
    font-family: "Inter","DM Sans",system-ui,sans-serif;
    color: #4d6873;
    padding: 14px 16px !important;
    border-bottom: 1px solid #E6F2EC !important;
    vertical-align: middle;
    background: #ffffff;
}
.patient-table tbody tr:nth-child(even) td { background: #F6FBF8; }
.patient-table tbody tr:hover td { background: #EAF7F0; }
.patient-table td strong { font-size: 13.5px; color: #143f51; font-weight: 700; }
.patient-table td small { font-size: 11.5px !important; color: #8aa0a8 !important; }
.patient-table tbody tr:last-child td { border-bottom: none !important; }
.patient-table tbody tr:last-child td:first-child { border-bottom-left-radius: 16px; }
.patient-table tbody tr:last-child td:last-child { border-bottom-right-radius: 16px; }
.status-badge { font-size: 11.5px !important; padding: 6px 11px !important; font-family: "Inter",system-ui,sans-serif; border-radius: 20px; }
.patient-content .btn { font-size: 13px; font-family: "Inter",system-ui,sans-serif; }
.patient-content .btn-sm { font-size: 12.5px; }
.empty-state { padding: 40px 20px; background: #ffffff; }
.empty-state p { font-size: 14px; color: #8aa0a8; }
.page-heading { font-size: 24px; }
.page-small { font-size: 13px; }
/* Topbar tulad sa dentist appointment - maliit puti may date/time */
.patient-topbar{background:#ffffff!important;border-bottom:1px solid #e8eef2!important;min-height:78px!important;padding-top:10px!important;padding-bottom:10px!important;gap:16px!important;flex-wrap:wrap!important;align-items:center!important;}
.patient-topbar-right{display:flex!important;align-items:center!important;gap:16px!important;}
.patient-topbar-date{display:flex!important;align-items:center!important;gap:10px!important;color:#244f60!important;}
.patient-topbar-date i{font-size:20px!important;}
.patient-topbar-date strong{display:block!important;font-size:12px!important;font-weight:800!important;line-height:1.2!important;}
.patient-topbar-date small{display:block!important;font-size:11px!important;opacity:.85!important;font-weight:600!important;}
.patient-topbar-time{display:flex!important;align-items:center!important;gap:10px!important;color:#244f60!important;border-left:1px solid #e8eef2!important;padding-left:16px!important;}
.patient-topbar-time i{font-size:20px!important;}
.patient-topbar-time strong{display:block!important;font-size:12px!important;font-weight:800!important;line-height:1.2!important;}
.patient-topbar-time small{display:block!important;font-size:11px!important;opacity:.85!important;font-weight:600!important;font-variant-numeric:tabular-nums!important;}
/* Back to Dashboard — mas visible: solid green gaya ng patient theme */
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
/* Filters — status buttons + search, tulad sa notifications */
.appt-filter-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:0;margin-bottom:16px;}
.appt-filter-bar button{border:1px solid #dce5ed;background:#ffffff;border-radius:8px;padding:8px 14px;font-size:13px;font-weight:700;color:#244f60;cursor:pointer;transition:.2s;}
.appt-filter-bar button.active{background:#0E6B5E!important;border-color:#0E6B5E!important;color:#ffffff!important;}
.appt-search{margin-left:auto;position:relative;flex:1 1 200px;max-width:280px;}
.appt-search input{width:100%;height:40px;border:1px solid #dce5ed;border-radius:8px;padding:0 12px 0 36px;font-size:13px;color:#244f60;background:#fbfdfe;}
.appt-search input:focus{border-color:#0E6B5E;outline:none;box-shadow:0 0 0 3px rgba(14,107,94,.10);background:#fff;}
.appt-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8aa0a8;font-size:15px;}
.appt-no-match{padding:32px 20px;text-align:center;color:#8aa0a8;font-size:14px;}
/* Manage button — same style sa admin Change Status */
.manage-btn{background:#EAF7F0 !important;border:1.5px solid #0E6B5E !important;color:#0E6B5E !important;font-weight:800 !important;border-radius:999px !important;padding:6px 14px !important;white-space:nowrap;font-size:12.5px !important;}
.manage-btn:hover{background:#0E6B5E !important;color:#fff !important;}
/* View button */
.view-btn{background:#EBF2FF !important;border:1.5px solid #3B82F6 !important;color:#3B82F6 !important;font-weight:800 !important;border-radius:999px !important;padding:6px 14px !important;white-space:nowrap;font-size:12.5px !important;}
.view-btn:hover{background:#3B82F6 !important;color:#fff !important;}
/* Manage modal action grid */
.manage-action-grid{display:grid;grid-template-columns:1fr;gap:10px}
.manage-action-grid .ma-btn{border:none;border-radius:12px;padding:16px 10px;font-weight:800;font-size:14px;letter-spacing:.4px;color:#fff;cursor:pointer;display:flex;flex-direction:row;align-items:center;justify-content:center;gap:10px;transition:.15s;width:100%}
.manage-action-grid .ma-btn i{font-size:20px}
.manage-action-grid .ma-btn:hover{filter:brightness(.93);transform:translateY(-2px)}
.ma-coming{background:linear-gradient(135deg,#10B981,#047857)}
.ma-resched{background:linear-gradient(135deg,#F59E0B,#B45309)}
.ma-email{background:linear-gradient(135deg,#3B82F6,#1D4ED8)}
.ma-concern{background:linear-gradient(135deg,#A78BFA,#7C3AED)}
.ma-cancel{background:linear-gradient(135deg,#EF4444,#B91C1C)}
</style>

</head>

<body>

<style>
body{background:linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22)!important;background-size:300% 300%!important;animation:myApptBgMove 8s ease infinite!important;}
.patient-main{background:transparent!important;}
@keyframes myApptBgMove{0%{background-position:0% 50%;}50%{background-position:100% 50%;}100%{background-position:0% 50%;}}
</style>

<div class="patient-layout">

<?php require_once __DIR__ . '/patient_sidebar.php'; ?>

<main class="patient-main">

<style>
.patient-minibar{display:flex;align-items:center;justify-content:center;gap:8px;background:#0A3B34;color:#C9A227;font-size:11px;font-weight:700;letter-spacing:.6px;padding:6px 12px;border-radius:10px 10px 0 0;}
.patient-minibar span{color:#ffffff;}
.patient-minibar i{font-size:13px;color:#ffffff;}
</style>
<div class="patient-minibar"><i class="bi bi-heart-pulse-fill"></i><span>Abalos Dental Clinic</span></div>

<header class="patient-topbar">

<div>

<h1 class="page-heading">
My Appointments
</h1>

<p class="page-small">
View all your dental appointments
</p>

</div>

<div class="patient-topbar-right">
<div class="patient-topbar-date"><i class="bi bi-calendar-event-fill"></i><div><strong><?= htmlspecialchars(date('l')) ?></strong><small><?= htmlspecialchars(date('M d, Y')) ?></small></div></div>
<div class="patient-topbar-time"><i class="bi bi-clock-fill"></i><div><strong>Time</strong><small id="patientClinicTime">--:--:--</small></div></div>
<div class="profile-circle">
<i class="bi bi-person-fill"></i>
</div>
</div>

</header>
<script>(function(){function tick(){try{var el=document.getElementById('patientClinicTime');if(!el)return;el.textContent=new Date().toLocaleTimeString('en-PH',{timeZone:'Asia/Manila',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});}catch(e){}}tick();setInterval(tick,1000);})();</script>

<div class="patient-content">

<?php if (!empty($appointments)): ?>
<div class="appt-filter-bar" id="apptFilterBar">
<button type="button" data-filter="all" class="active">All</button>
<button type="button" data-filter="pending">Pending</button>
<button type="button" data-filter="confirmed">Confirmed</button>
<button type="button" data-filter="completed">Completed</button>
<button type="button" data-filter="cancelled">Cancelled</button>
<div class="appt-search"><i class="bi bi-search"></i><input type="text" id="apptSearch" placeholder="Search dentist, service..."></div>
</div>
<?php endif; ?>

<div class="patient-card">

<?php if ($cancelSuccess !== ''): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
<i class="bi bi-check-circle-fill me-1"></i> <?= myAppointmentsEscape($cancelSuccess) ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($cancelError !== ''): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
<i class="bi bi-exclamation-triangle-fill me-1"></i> <?= myAppointmentsEscape($cancelError) ?>
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="patient-card-header">

<h3>
<i class="bi bi-table"></i> Appointment History
</h3>

</div>

<?php if (empty($appointments)): ?>

<div class="empty-state">

<i class="bi bi-calendar-x"></i>

<p>
You don't have any appointments yet.
</p>

<a
href="book_appointment.php"
class="btn btn-primary btn-sm"
>
Book Appointment
</a>

</div>

<?php else: ?>

<div class="table-responsive">

<table class="patient-table">

<thead>

<tr>

<th style="width:40px;"></th>

<th>Patient Name</th>

<th>Time of Appointment</th>

<th>Dental Service</th>

<th>Assigned Dentist</th>

<th>Status</th>

<th style="min-width:110px;">Action</th>

</tr>

</thead>

<tbody>

<?php $apptRowNum = 0; ?>
<?php foreach ($appointments as $appointment): ?>

<?php

$apptRowNum++;

$statusClass = strtolower(
str_replace(
' ',
'-',
$appointment['status'] ?? ''
)
);

?>

<tr class="appt-row" data-status="<?= $statusClass ?>" data-search="<?= myAppointmentsEscape(strtolower(($appointment['appointment_date'] ?? '') . ' ' . ($appointment['dentist_name'] ?? '') . ' ' . ($appointment['service_type'] ?? '') . ' ' . ($appointment['status'] ?? ''))) ?>">

<td>

<strong><?= $apptRowNum ?></strong>

</td>

<td>

<strong>

<?= myAppointmentsEscape($patientFullName) ?>

</strong>

</td>

<td>

<?= myAppointmentsEscape(
date(
'M d, Y',
strtotime(
$appointment['appointment_date']
)
)
) ?>

<br>

<small class="text-muted">

<?= myAppointmentsEscape(
date(
'h:i A',
strtotime(
$appointment['start_time']
)
)
) ?>

</small>

</td>

<td>

<?= myAppointmentsEscape(
$appointment['service_type']
) ?>

</td>

<td>

<strong>

<?= myAppointmentsEscape(
$appointment['dentist_name']
?? 'Not assigned'
) ?>

</strong>

<br>

<small class="text-muted">

<?= myAppointmentsEscape(
$appointment['specialization']
?? ''
) ?>

</small>

</td>

<td>

<span
class="status-badge status-<?= $statusClass ?>"
>

<?= myAppointmentsEscape(
$appointment['status']
) ?>

</span>

</td>

<td>
<div class="d-flex gap-1 flex-wrap align-items-center">
<button
type="button"
class="btn btn-sm view-btn"
data-appointment-id="<?= (int)$appointment['id'] ?>"
data-patient="<?= myAppointmentsEscape($patientFullName) ?>"
data-patient-id="<?= myAppointmentsEscape($patientNumber) ?>"
data-date="<?= myAppointmentsEscape(date('M d, Y', strtotime($appointment['appointment_date']))) ?>"
data-time="<?= myAppointmentsEscape(date('h:i A', strtotime($appointment['start_time']))) ?>"
data-dentist="<?= myAppointmentsEscape($appointment['dentist_name'] ?? 'Not assigned') ?>"
data-specialization="<?= myAppointmentsEscape($appointment['specialization'] ?? '') ?>"
data-service="<?= myAppointmentsEscape($appointment['service_type']) ?>"
data-status="<?= myAppointmentsEscape($appointment['status']) ?>"
data-status-class="<?= $statusClass ?>"
data-booked="<?= myAppointmentsEscape(!empty($appointment['created_at']) ? date('M d, Y · h:i A', strtotime($appointment['created_at'])) : '') ?>"
data-notes="<?= myAppointmentsEscape($appointment['notes'] ?? '') ?>"
>
<i class="bi bi-eye"></i> View
</button>
<?php if (in_array($appointment['status'], ['Pending', 'Confirmed'], true) && $appointment['appointment_date'] >= date('Y-m-d')): ?>
<button
type="button"
class="btn btn-sm manage-btn"
data-appointment-id="<?= (int)$appointment['id'] ?>"
data-status="<?= myAppointmentsEscape($appointment['status']) ?>"
data-date="<?= myAppointmentsEscape($appointment['appointment_date']) ?>"
data-time="<?= myAppointmentsEscape(date('h:i A', strtotime($appointment['start_time']))) ?>"
data-dentist="<?= myAppointmentsEscape($appointment['dentist_name'] ?? 'Not assigned') ?>"
data-service="<?= myAppointmentsEscape($appointment['service_type']) ?>"
data-mailto="mailto:abalosdentalclinic123@gmail.com?subject=<?= rawurlencode('Reschedule Request - Appointment #' . (int)$appointment['id']) ?>&body=<?= rawurlencode("Hello Abalos Dental Clinic,\n\nI would like to reschedule my appointment.\n\nAppointment ID: " . (int)$appointment['id'] . "\nDate: " . $appointment['appointment_date'] . "\nTime: " . $appointment['start_time'] . "\n\nPreferred new date/time:\n\nThank you.") ?>"
>
Manage <i class="bi bi-chevron-down"></i>
</button>
<?php else: ?>
<?php endif; ?>
</div>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<div class="appt-no-match" id="apptNoMatch" style="display:none;">
<i class="bi bi-search me-1"></i>No appointments match your filter.
</div>

<?php endif; ?>

</div>

</div>

</main>
</div>

<!-- CANCEL MODAL WITH NOTES -->
<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content" style="border-radius:16px;">
<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="action" value="cancel_appointment">
<input type="hidden" name="appointment_id" id="cancelAppointmentId" value="">
<div class="modal-header">
<h5 class="modal-title fw-bold"><i class="bi bi-x-circle text-danger me-2"></i>Cancel Appointment</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<p class="text-muted small">The slot will be opened for other patients after cancellation. Please provide a reason (optional).</p>
<div class="mb-3">
<label class="form-label small fw-bold">Reason / Notes <span class="text-muted fw-normal">(optional, max 500 chars)</span></label>
<textarea name="cancel_notes" id="cancelNotes" class="form-control" rows="3" maxlength="500" placeholder="e.g., Emergency, schedule conflict..."></textarea>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep Appointment</button>
<button type="submit" class="btn btn-danger"><i class="bi bi-check-lg me-1"></i> Confirm Cancel</button>
</div>
</form>
</div>
</div>
</div>

<!-- RESCHEDULE MODAL -->
<div class="modal fade" id="rescheduleModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content" style="border-radius:16px;">
<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="action" value="request_reschedule">
<input type="hidden" name="appointment_id" id="rescheduleAppointmentId" value="">
<div class="modal-header">
<h5 class="modal-title fw-bold"><i class="bi bi-calendar2-week text-info me-2"></i>Request Reschedule</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<p class="text-muted small">Your request goes straight to the admin system. The clinic will confirm the new schedule.</p>
<div class="row g-3">
<div class="col-md-6">
<label class="form-label small fw-bold">Preferred date</label>
<input type="date" name="preferred_date" id="rescheduleDate" class="form-control" required min="<?= date('Y-m-d') ?>">
</div>
<div class="col-md-6">
<label class="form-label small fw-bold">Preferred time <span class="text-muted fw-normal">(8:00 AM – 5:30 PM)</span></label>
<input type="time" name="preferred_time" id="rescheduleTime" class="form-control" min="08:00" max="17:30" required>
</div>
</div>
<div class="mt-3">
<label class="form-label small fw-bold">Reason <span class="text-muted fw-normal">(optional, max 500 chars)</span></label>
<textarea name="reschedule_reason" id="rescheduleReason" class="form-control" rows="3" maxlength="500" placeholder="e.g., Work conflict, emergency..."></textarea>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
<button type="submit" class="btn btn-info text-white"><i class="bi bi-check-lg me-1"></i> Send Request</button>
</div>
</form>
</div>
</div>
</div>

<!-- MANAGE APPOINTMENT POPUP -->
<div class="modal fade" id="manageModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content" style="border-radius:16px;overflow:visible;">
<div class="modal-header" style="background:linear-gradient(135deg,#149A72,#0A4A41) !important;border-bottom:none !important;">
<h5 class="modal-title" style="color:#ffffff !important;font-weight:800;"><i class="bi bi-gear-fill"></i> Manage Appointment</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<p class="small mb-1"><strong id="manageDate"></strong> · <strong id="manageTime"></strong></p>
<p class="small mb-3 text-muted">Dentist: <strong id="manageDentist"></strong> · Service: <strong id="manageService"></strong></p>
<input type="hidden" id="manageAppointmentId" value="">
<input type="hidden" id="manageMailto" value="">
<div class="manage-action-grid">
<button type="button" class="ma-btn ma-coming" id="maComingBtn"><i class="bi bi-check2-all"></i> I'M COMING</button>
<button type="button" class="ma-btn ma-resched" id="maReschedBtn"><i class="bi bi-calendar2-week-fill"></i> RESCHEDULE</button>
<button type="button" class="ma-btn ma-email" id="maEmailBtn" style="display:none;"><i class="bi bi-envelope-fill"></i> EMAIL ADMIN</button>
<button type="button" class="ma-btn ma-concern" id="maConcernBtn" style="display:none;"><i class="bi bi-chat-dots-fill"></i> APPOINTMENT CONCERN</button>
<button type="button" class="ma-btn ma-cancel" id="maCancelBtn"><i class="bi bi-x-circle-fill"></i> CANCEL</button>
</div>
<!-- Hidden form para sa I'm Coming (direct submit) -->
<form method="POST" id="maComingForm" style="display:none;">
<?= csrfField() ?>
<input type="hidden" name="action" value="confirm_attendance">
<input type="hidden" name="appointment_id" id="maComingApptId" value="">
</form>
</div>
</div>
</div>
</div>

<!-- VIEW APPOINTMENT MODAL -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content" style="border-radius:16px;overflow:visible;">
<div class="modal-header" style="background:linear-gradient(135deg,#3B82F6,#1D4ED8) !important;border-bottom:none !important;">
<h5 class="modal-title" style="color:#ffffff !important;font-weight:800;"><i class="bi bi-eye-fill"></i> Appointment Details</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<div class="d-flex align-items-center gap-3 mb-3">
<div style="width:48px;height:48px;border-radius:14px;background:#EBF2FF;color:#3B82F6;display:inline-flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;">
<i class="bi bi-calendar2-check-fill"></i>
</div>
<div>
<div style="font-size:16px;font-weight:800;color:#0f172a;" id="viewApptPatientName">—</div>
<div style="font-size:12px;font-weight:600;color:#475569;margin-bottom:4px;" id="viewApptPatientId">—</div>
<span class="status-badge" id="viewApptStatus"></span>
</div>
</div>
<table style="width:100%;font-size:13.5px;color:#1e293b;border-collapse:collapse;">
<tr style="border-bottom:1px solid #edf2f7;">
<td style="padding:10px 0;font-weight:700;color:#0f172a;width:40%;"><i class="bi bi-heart-pulse me-1 text-primary"></i> Dental Service</td>
<td style="padding:10px 0;font-weight:600;color:#0f172a;" id="viewApptService">—</td>
</tr>
<tr style="border-bottom:1px solid #edf2f7;">
<td style="padding:10px 0;font-weight:700;color:#0f172a;"><i class="bi bi-person-badge me-1 text-primary"></i> Assigned Dentist</td>
<td style="padding:10px 0;font-weight:600;color:#0f172a;" id="viewApptDentist">—</td>
</tr>
<tr style="border-bottom:1px solid #edf2f7;">
<td style="padding:10px 0;font-weight:700;color:#0f172a;"><i class="bi bi-calendar-event me-1 text-primary"></i> Date</td>
<td style="padding:10px 0;font-weight:600;color:#0f172a;" id="viewApptDate">—</td>
</tr>
<tr style="border-bottom:1px solid #edf2f7;">
<td style="padding:10px 0;font-weight:700;color:#0f172a;"><i class="bi bi-clock me-1 text-primary"></i> Time</td>
<td style="padding:10px 0;font-weight:600;color:#0f172a;" id="viewApptTime">—</td>
</tr>
<tr style="border-bottom:1px solid #edf2f7;">
<td style="padding:10px 0;font-weight:700;color:#0f172a;"><i class="bi bi-bookmark-check me-1 text-primary"></i> Booked On</td>
<td style="padding:10px 0;font-weight:600;color:#0f172a;" id="viewApptBooked">—</td>
</tr>
<tr id="viewApptNotesRow">
<td style="padding:10px 0;font-weight:700;color:#0f172a;vertical-align:top;"><i class="bi bi-journal-text me-1 text-primary"></i> Notes</td>
<td style="padding:10px 0;white-space:pre-line;font-weight:600;color:#0f172a;" id="viewApptNotes">—</td>
</tr>
</table>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
</div>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* View button — ipakita ang appointment details sa modal */
document.querySelectorAll('.view-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    document.getElementById('viewApptPatientName').textContent = btn.getAttribute('data-patient') || '—';
    document.getElementById('viewApptPatientId').textContent = btn.getAttribute('data-patient-id') || '—';
    var statusEl = document.getElementById('viewApptStatus');
    statusEl.textContent = btn.getAttribute('data-status') || '';
    statusEl.className = 'status-badge status-' + (btn.getAttribute('data-status-class') || '');
    document.getElementById('viewApptService').textContent = btn.getAttribute('data-service') || '—';
    var spec = btn.getAttribute('data-specialization') || '';
    var dentist = btn.getAttribute('data-dentist') || '—';
    document.getElementById('viewApptDentist').textContent = spec !== '' ? dentist + ' (' + spec + ')' : dentist;
    document.getElementById('viewApptDate').textContent = btn.getAttribute('data-date') || '—';
    document.getElementById('viewApptTime').textContent = btn.getAttribute('data-time') || '—';
    document.getElementById('viewApptBooked').textContent = btn.getAttribute('data-booked') || '—';
    var notes = btn.getAttribute('data-notes') || '';
    var notesRow = document.getElementById('viewApptNotesRow');
    document.getElementById('viewApptNotes').textContent = notes !== '' ? notes : '—';
    notesRow.style.display = notes !== '' ? '' : 'none';
    new bootstrap.Modal(document.getElementById('viewModal')).show();
  });
});
</script>
<script>
/* Manage button — bubuksan ang manage modal */
var manageModalInstance = null;
document.querySelectorAll('.manage-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    var id = btn.getAttribute('data-appointment-id') || '';
    var st = (btn.getAttribute('data-status') || '').toLowerCase();
    document.getElementById('manageAppointmentId').value = id;
    document.getElementById('manageDate').textContent = btn.getAttribute('data-date') || '';
    document.getElementById('manageTime').textContent = btn.getAttribute('data-time') || '';
    document.getElementById('manageDentist').textContent = btn.getAttribute('data-dentist') || '';
    document.getElementById('manageService').textContent = btn.getAttribute('data-service') || '';
    document.getElementById('manageMailto').value = btn.getAttribute('data-mailto') || '';
    document.getElementById('maComingApptId').value = id;
    var comingBtn = document.getElementById('maComingBtn');
    if (comingBtn) comingBtn.style.display = (st === 'confirmed') ? '' : 'none';
    manageModalInstance = new bootstrap.Modal(document.getElementById('manageModal'));
    manageModalInstance.show();
  });
});

/* I'm Coming — direct submit */
document.getElementById('maComingBtn').addEventListener('click', function(){
  document.getElementById('maComingForm').submit();
});

/* Reschedule — isara manage modal, buksan reschedule modal */
document.getElementById('maReschedBtn').addEventListener('click', function(){
  var apptId = document.getElementById('manageAppointmentId').value;
  if (manageModalInstance) manageModalInstance.hide();
  document.getElementById('rescheduleAppointmentId').value = apptId;
  document.getElementById('rescheduleDate').value = '';
  document.getElementById('rescheduleTime').value = '';
  document.getElementById('rescheduleReason').value = '';
  new bootstrap.Modal(document.getElementById('rescheduleModal')).show();
});

/* Email Admin — buksan mailto, isara manage modal */
document.getElementById('maEmailBtn').addEventListener('click', function(){
  var apptId = document.getElementById('manageAppointmentId').value;
  var mailto = document.getElementById('manageMailto').value;
  /* log click via ajax */
  var formData = new FormData();
  formData.append('action', 'log_email_click');
  formData.append('appointment_id', apptId);
  var csrfEl = document.querySelector('input[name="csrf_token"]');
  if (csrfEl) formData.append('csrf_token', csrfEl.value);
  formData.append('ajax', '1');
  try { fetch(window.location.pathname, { method: 'POST', body: formData }); } catch (e) {}
  if (mailto) window.location.href = mailto;
  if (manageModalInstance) manageModalInstance.hide();
});

/* Appointment Concern — redirect sa appointment concern page */
document.getElementById('maConcernBtn').addEventListener('click', function(){
  var apptId = document.getElementById('manageAppointmentId').value;
  window.location.href = 'appointment_concern.php?appointment_id=' + encodeURIComponent(apptId);
});

/* Cancel — isara manage modal, buksan cancel modal */
document.getElementById('maCancelBtn').addEventListener('click', function(){
  var apptId = document.getElementById('manageAppointmentId').value;
  if (manageModalInstance) manageModalInstance.hide();
  document.getElementById('cancelAppointmentId').value = apptId;
  document.getElementById('cancelNotes').value = '';
  new bootstrap.Modal(document.getElementById('cancelModal')).show();
});

/* Filters — status + search, client-side lang */
(function(){
  var bar = document.getElementById('apptFilterBar');
  if (!bar) return;
  var btns = bar.querySelectorAll('button[data-filter]');
  var search = document.getElementById('apptSearch');
  var noMatch = document.getElementById('apptNoMatch');
  var active = 'all';
  function apply(){
    var q = (search && search.value ? search.value : '').toLowerCase().trim();
    var visible = 0;
    document.querySelectorAll('.appt-row').forEach(function(row){
      var okStatus = (active === 'all' || row.getAttribute('data-status') === active);
      var hay = row.getAttribute('data-search') || '';
      var okSearch = (q === '' || hay.indexOf(q) !== -1);
      var show = okStatus && okSearch;
      row.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    if (noMatch) noMatch.style.display = visible === 0 ? '' : 'none';
  }
  btns.forEach(function(b){
    b.addEventListener('click', function(){
      btns.forEach(function(x){ x.classList.remove('active'); });
      b.classList.add('active');
      active = b.getAttribute('data-filter') || 'all';
      apply();
    });
  });
  if (search) search.addEventListener('input', apply);
})();
</script>

</body>

</html>