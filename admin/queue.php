<?php

require_once __DIR__ . "/../functions.php";

requireLogin();

/* Walk-in QR: ensure service column exists for queue */
try {
    $pdo->exec("ALTER TABLE queue ADD COLUMN IF NOT EXISTS service_type VARCHAR(150) NOT NULL DEFAULT ''");
} catch (Throwable $e) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM queue LIKE 'service_type'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE queue ADD COLUMN service_type VARCHAR(150) NOT NULL DEFAULT ''");
        }
    } catch (Throwable $e2) {}
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

    $action = $_POST['action'] ?? '';

    /* QR Walk-in: Name + Dental Service lang, deretso pila */
    if ($action === 'qr_add') {
        $qrName = trim($_POST['qr_name'] ?? '');
        $qrService = trim($_POST['qr_service'] ?? '');
        $qrPriority = trim($_POST['qr_priority'] ?? 'Normal');
        if (!in_array($qrPriority, ['Normal', 'Emergency'], true)) {
            $qrPriority = 'Normal';
        }
        if ($qrName === '' || $qrService === '') {
            flash("Please enter walk-in name and dental service.", "danger");
            redirect("queue.php");
        }
        $parts = preg_split('/\s+/', $qrName);
        $wFirst = $parts[0] ?? $qrName;
        $wLast = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '-';
        $patientNumber = generateShortPatientNumber($pdo);
        $stmtP = $pdo->prepare("INSERT INTO patients (patient_number, first_name, last_name, contact_number, email) VALUES (?, ?, ?, ?, ?)");
        $stmtP->execute([$patientNumber, $wFirst, $wLast, '', '']);
        $newPatientId = (int)$pdo->lastInsertId();
        $date = date('Y-m-d');
        $queueNumber = generateQueueNumber($date);
        try {
            $stmt = $pdo->prepare("INSERT INTO queue (patient_id, queue_number, queue_date, priority, service_type) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$newPatientId, $queueNumber, $date, $qrPriority, $qrService]);
        } catch (Throwable $e) {
            $stmt = $pdo->prepare("INSERT INTO queue (patient_id, queue_number, queue_date, priority) VALUES (?, ?, ?, ?)");
            $stmt->execute([$newPatientId, $queueNumber, $date, $qrPriority]);
        }
        flash("Walk-in added to queue. Queue number: Q-" . str_pad($queueNumber, 3, '0', STR_PAD_LEFT) . " (" . $qrName . " - " . $qrService . ")");
        redirect("queue.php");
    }

    /* Pang-data public URL: i-save para ang QR ay public link (ma-open sa mobile data, hindi na kailangan ng WiFi) */
    if ($action === 'save_qr_public_url') {
        $publicUrl = trim($_POST['qr_public_url'] ?? '');
        // Tanggalin ang trailing slash, dapat https public link na nagtuturo sa walkin_qr.php
        $publicUrl = rtrim($publicUrl, '/');
        if ($publicUrl !== '' && !preg_match('#^https?://#i', $publicUrl)) {
            $publicUrl = 'https://' . $publicUrl;
        }
        // Kung base URL lang ang binigay (walang walkin_qr.php), dugtungan
        if ($publicUrl !== '' && stripos($publicUrl, 'walkin_qr.php') === false) {
            $publicUrl = rtrim($publicUrl, '/');
            // Kapag root ngrok URL lang (walang folder), idagdag ang project folder
            if (stripos($publicUrl, 'dental-ai-system-fixed') === false) {
                $publicUrl .= '/dental-ai-system-fixed/walkin_qr.php';
            } else {
                $publicUrl .= '/walkin_qr.php';
            }
        }
        if ($publicUrl === '' || $publicUrl === '/walkin_qr.php') {
            // Blank = remove public URL, revert to LAN
            @unlink(__DIR__ . '/../qr_public_url.txt');
            flash("Public QR link removed. System has reverted to LAN QR (non-expiring).");
        } else {
            file_put_contents(__DIR__ . '/../qr_public_url.txt', $publicUrl);
            flash("Public QR link saved! QR code can now be scanned over mobile data: " . $publicUrl);
        }
        redirect("queue.php");
    }

    /* One-click revert to LAN QR (non-expiring) */
    if ($action === 'use_lan_qr') {
        @unlink(__DIR__ . '/../qr_public_url.txt');
        flash("Reverted to LAN QR code (non-expiring). Scan using any device connected to the clinic Wi-Fi.");
        redirect("queue.php");
    }

    if ($action === 'add') {

        $patientId = (int)($_POST['patient_id'] ?? 0);
        $walkinFirst = trim($_POST['walkin_first_name'] ?? '');
        $walkinLast = trim($_POST['walkin_last_name'] ?? '');
        $walkinContact = trim($_POST['walkin_contact'] ?? '');
        $walkinAge = (int)($_POST['walkin_age'] ?? 0);
        $isSenior = isset($_POST['is_senior']) ? 1 : 0;
        $noCellphone = isset($_POST['no_cellphone']) ? 1 : 0;

        // Walk-in para sa walang cellphone / senior citizens / hindi pa registered - gumawa ng patient record kung wala napili
        if (!$patientId && $walkinFirst !== '' && $walkinLast !== '') {
            $patientNumber = generateShortPatientNumber($pdo);
            $stmtP = $pdo->prepare("INSERT INTO patients (patient_number, first_name, last_name, contact_number, email) VALUES (?, ?, ?, ?, ?)");
            $stmtP->execute([$patientNumber, $walkinFirst, $walkinLast, $walkinContact, '']);
            $patientId = (int)$pdo->lastInsertId();
            // Senior/No-cellphone ay para sa priority/info lang - hindi binabago ang ibang system
            if ($isSenior && ($_POST['priority'] ?? '') === 'Normal') {
                // Senior ay priority pa rin Normal unless Emergency - info lang
            }
        }

        if (!$patientId) {
            flash("Please select a patient or enter walk-in name.", "danger");
            redirect("queue.php");
        }

        $date = date('Y-m-d');

        $queueNumber =
            generateQueueNumber($date);

        $stmt = $pdo->prepare("
            INSERT INTO queue
            (
                patient_id,
                queue_number,
                queue_date,
                priority
            )
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([
            $patientId,
            $queueNumber,
            $date,
            $_POST['priority']
        ]);

        flash(
            "Patient added to queue. Queue number: Q-" .
            str_pad(
                $queueNumber,
                3,
                '0',
                STR_PAD_LEFT
            )
        );

        redirect("queue.php");
    }

    if ($action === 'status') {

        $status = $_POST['status'] ?? '';

        if (!in_array($status, ['Waiting', 'Serving', 'Completed'], true)) {
            flash("Invalid queue status.", "danger");
            redirect("queue.php");
        }

        $qRowStmt = $pdo->prepare("SELECT patient_id, queue_date FROM queue WHERE id = ? LIMIT 1");
        $qRowStmt->execute([(int) $_POST['id']]);
        $qRow = $qRowStmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            UPDATE queue
            SET status = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $status,
            (int) $_POST['id']
        ]);

        /* Sync: appointment ngayong araw ng patient, sumunod sa queue status. */
        if ($qRow && in_array($status, ['Serving', 'Completed'], true)) {
            try {
                $syncCol = $status === 'Serving' ? 'serving_at' : 'completed_at';
                $syncStmt = $pdo->prepare("
                    UPDATE appointments
                    SET status = ?, {$syncCol} = NOW()
                    WHERE patient_id = ?
                    AND appointment_date = ?
                    AND status IN ('Pending', 'Confirmed', 'Approved', 'Checked In', 'Serving')
                ");
                $syncStmt->execute([$status, (int) $qRow['patient_id'], $qRow['queue_date']]);
            } catch (PDOException $e) {
            }
        }

        if (!empty($_POST['ajax_popup'])) {
            // Do not set top flash banner when handled by popup
            redirect("queue.php");
        }

        $_SESSION['queue_updated_popup'] = true;

        redirect("queue.php");
    }
}

$patients = $pdo->query("
    SELECT id, patient_number, first_name, last_name
    FROM patients
    ORDER BY last_name
")->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        q.*,
        CONCAT(
            p.first_name,
            ' ',
            p.last_name
        ) AS patient_name,
        COALESCE(
            NULLIF(TRIM(q.service_type), ''),
            (
                SELECT a1.service_type
                FROM appointments a1
                WHERE a1.patient_id = q.patient_id
                  AND a1.appointment_date = q.queue_date
                ORDER BY a1.start_time ASC, a1.id ASC
                LIMIT 1
            )
        ) AS resolved_service_type,
        (
            SELECT d.dentist_name
            FROM appointments a2
            INNER JOIN dentists d ON d.id = a2.dentist_id
            WHERE a2.patient_id = q.patient_id
              AND a2.appointment_date = q.queue_date
            ORDER BY a2.start_time ASC, a2.id ASC
            LIMIT 1
        ) AS dentist_name,
        (
            SELECT COUNT(*)
            FROM appointments a3
            WHERE a3.patient_id = q.patient_id
              AND a3.appointment_date = q.queue_date
              AND a3.status NOT IN ('Cancelled', 'Canceled')
        ) AS has_appointment
    FROM queue q

    JOIN patients p
        ON p.id = q.patient_id

    WHERE q.queue_date = ?
    AND q.status IN ('Waiting', 'Serving', 'Completed')

    ORDER BY
        (q.status = 'Completed') ASC,
        q.priority = 'Emergency' DESC,
        q.queue_number ASC
");

$stmt->execute([
    date('Y-m-d')
]);

$queues = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html>

<head>

<title>Queue Management</title>

<meta
name="viewport"
content="width=device-width,initial-scale=1">

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet">

<link
rel="stylesheet"
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<link
rel="stylesheet"
href="../assets/style.css?v=2">

<link
href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap"
rel="stylesheet">

<style>
/* Queue — gaya ng Reports page: green gradient bg, white cards, green table header */
body { background: linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22) !important; background-size: 300% 300% !important; animation: adminBgAnim 8s ease infinite !important; background-attachment: fixed !important; }
@keyframes adminBgAnim { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
.queue-page { margin-left: 280px !important; width: calc(100% - 280px) !important; padding: 110px 24px 40px !important; background: transparent !important; min-height: 100vh; box-sizing: border-box !important; }
.queue-page > .mb-3, .queue-page > .alert { margin-left: 14px; margin-right: 14px; }
.queue-head { margin: 0 14px 16px 14px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; }
.queue-head h1 { color: #ffffff !important; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; margin: 0; }
.queue-head p { margin: 4px 0 0; color: #C9E9DA; font-size: 13px; }
.queue-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.queue-actions .btn { font-size: 13px; font-weight: 700; border-radius: 10px; padding: 9px 16px; display: inline-flex; align-items: center; gap: 6px; font-family: "Inter", system-ui, sans-serif; }
.queue-actions .btn-dark { background: #143f51 !important; border-color: #143f51 !important; color: #fff !important; }
.queue-actions .btn-success { background: linear-gradient(135deg, #149A72, #0A4A41) !important; border-color: #0E6B5E !important; color: #fff !important; }
.queue-actions .btn-primary { background: linear-gradient(135deg, #C9A227, #A8861B) !important; border-color: #C9A227 !important; color: #0A3B34 !important; font-weight: 800; }
.queue-card { background: #ffffff !important; border: 1px solid #C9E9DA !important; border-radius: 16px !important; overflow: hidden !important; box-shadow: 0 8px 24px rgba(14,107,94,.08) !important; padding: 20px !important; margin: 0 14px; }
.queue-card-head { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
.queue-card-head h2 { color: #143f51 !important; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; letter-spacing: -0.02em; font-size: 17px !important; margin: 0; display: flex; align-items: center; gap: 8px; }
.queue-card-head h2 i { color: #0E6B5E; font-size: 18px; }
.queue-card .card-sub { margin: 3px 0 14px 26px; color: #8aa0a8; font-size: 12px; }
.queue-table { margin-bottom: 0 !important; width: 100%; border-collapse: separate; border-spacing: 0; background: #ffffff; }
.queue-table thead tr { background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important; }
.queue-table thead th { background: transparent !important; color: #ffffff !important; font-size: 11.5px !important; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; white-space: nowrap; padding: 14px 16px !important; border-bottom: none !important; border-right: 1px solid rgba(255,255,255,.12) !important; font-family: "Inter", system-ui, sans-serif; }
.queue-table thead th:last-child { border-right: none !important; }
.queue-table tbody td { padding: 14px 16px !important; color: #4d6873 !important; font-size: 13.5px !important; font-family: "Inter", "DM Sans", system-ui, sans-serif; vertical-align: middle; background: #ffffff; border-bottom: 1px solid #E6F2EC !important; }
.queue-table tbody tr:nth-child(even) td { background: #F6FBF8 !important; }
.queue-table tbody tr:hover td { background: #EAF7F0 !important; }
.queue-table .queue-number { font-size: 15px; font-weight: 800; color: #0E6B5E; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; letter-spacing: -0.01em; }
.queue-table .badge { font-size: 12px !important; padding: 6px 12px !important; border-radius: 20px; font-weight: 700; font-family: "Inter", system-ui, sans-serif; }
.queue-table .badge.bg-danger { background: #FDECEC !important; color: #C0392B !important; border: 1px solid #F5C6C6; }
.queue-table .badge.bg-primary { background: #E6F4FB !important; color: #157ca0 !important; border: 1px solid #BFE3F2; }
.queue-table .form-select-sm { font-size: 13px !important; border-radius: 9px; border: 1px solid #D1E7DD; padding: 7px 12px; }
.queue-empty { text-align: center; padding: 35px 20px; color: #8aa0a8; }
.queue-empty i { display: inline-flex; align-items: center; justify-content: center; width: 50px; height: 50px; border-radius: 14px; background: #EAF7F0; color: #0E6B5E; font-size: 22px; margin-bottom: 10px; }
.queue-page .alert { font-size: 13.5px; border-radius: 12px; }
.modal-content { border-radius: 16px !important; border: 1px solid #C9E9DA !important; overflow: visible !important; }
.modal-header { background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%) !important; border-bottom: 1px solid #C9E9DA !important; border-top-left-radius: 15px !important; border-top-right-radius: 15px !important; }
.modal-footer { border-bottom-left-radius: 15px !important; border-bottom-right-radius: 15px !important; }
.modal-title, .modal-header h5 { font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-size: 17px; font-weight: 800; color: #143f51; margin: 0; }
.modal-body label { font-size: 13px; font-weight: 600; color: #426471; font-family: "Inter", system-ui, sans-serif; }
.modal-body .form-control, .modal-body .form-select { font-size: 14px; border-radius: 10px; }

/* Patient QR Modal specific modern styling */
#patientQrModal .modal-dialog { max-width: 420px !important; margin: 1.75rem auto !important; }
#patientQrModal .modal-content { border-radius: 20px !important; border: 1.5px solid #0E6B5E !important; overflow: hidden !important; box-shadow: 0 20px 60px rgba(10, 74, 65, 0.25) !important; background: #ffffff !important; }
#patientQrModal .modal-header { background: linear-gradient(135deg, #0A4A41 0%, #0E6B5E 100%) !important; border-bottom: none !important; border-radius: 0 !important; padding: 16px 22px !important; }
#patientQrModal .modal-title, #patientQrModal .modal-header h5 { color: #ffffff !important; font-size: 16px !important; font-weight: 800 !important; margin: 0 !important; }
#patientQrModal .btn-close { filter: invert(1) grayscale(100%) brightness(200%) !important; opacity: 0.9 !important; }
#patientQrModal .qr-display-card { background: #ffffff; border-radius: 18px; padding: 26px; border: 1.5px solid #D1EADE; box-shadow: 0 8px 24px rgba(10, 74, 65, 0.08); display: inline-block; margin: 0 auto; }
#patientQrModal #patientQrBoxLan { width: 240px; height: 240px; margin: 0 auto; overflow: hidden; position: relative; display: flex; align-items: center; justify-content: center; }
#patientQrModal #patientQrBoxLan canvas, #patientQrModal #patientQrBoxLan img { border-radius: 8px; max-width: 240px; max-height: 240px; }
@media (max-width: 991px) { .queue-page { margin-left: 0 !important; width: 100% !important; padding: 100px 16px 40px !important; } .queue-card { margin: 0; } .queue-head { margin: 0 0 16px 0; } }
</style>

</head>

<body>

<?php include __DIR__ . "/includes.php"; ?>

<main class="queue-page">

<?php showFlash(); ?>

<a href="dashboard.php" class="btn btn-outline-secondary btn-sm mb-3" data-tip="Back to Dashboard">
<i class="bi bi-arrow-left me-1"></i>Back to Dashboard
</a>

<div class="queue-head">

<div>
<h1>Queue Management</h1>
<p class="mb-0">Today's patient queue — scheduled appointment check-ins, walk-ins, and scanned QR patients.</p>
</div>

<div class="queue-actions">
<button
class="btn btn-dark"
data-tip="Patient QR"
data-bs-toggle="modal"
data-bs-target="#patientQrModal">

<i class="bi bi-qr-code me-1"></i>Patient QR

</button>

<button
class="btn btn-primary"
data-tip="Walk-in Patient"
data-bs-toggle="modal"
data-bs-target="#addQueue">

<i class="bi bi-person-plus-fill me-1"></i>Add Walk-in Patient

</button>
</div>

</div>

<section class="queue-card">

<div class="queue-card-head">
<h2><i class="bi bi-list-ol"></i>Today's Queue</h2>
</div>
<p class="card-sub">Emergency patients are listed first, then by queue number. Checked-in scheduled patients and walk-in patients are both managed here.</p>

<div class="table-responsive">

<table class="table queue-table">

<thead>

<tr>

<th>Queue</th>
<th>Patient</th>
<th>Type</th>
<th>Dental Service</th>
<th>Dentist</th>
<th>Priority</th>
<th>Status</th>
<th>Action</th>

</tr>

</thead>

<tbody>

<?php if (empty($queues)): ?>

<tr>
    <td colspan="8" class="text-center">
        <div class="queue-empty">
            <i class="bi bi-inbox"></i>
            <div class="fw-bold" style="color:#426471;">No patients in today's queue.</div>
            <div class="small">Check in a scheduled patient, add a walk-in patient, or scan a QR to get started.</div>
        </div>
    </td>
</tr>

<?php else: ?>

<?php foreach ($queues as $q): ?>

<tr>

<td>

<strong class="queue-number">

Q-<?= str_pad(
    $q['queue_number'],
    3,
    '0',
    STR_PAD_LEFT
) ?>

</strong>

</td>

<td>
<?= e($q['patient_name']) ?>
</td>

<td>
<?php if (!empty($q['has_appointment']) && (int)$q['has_appointment'] > 0): ?>
<span class="badge" style="background:#EAF7F0 !important;color:#0E6B5E !important;border:1px solid #B6E2CE;">
<i class="bi bi-calendar-check me-1"></i>Scheduled Appointment
</span>
<?php else: ?>
<span class="badge" style="background:#FFF8E7 !important;color:#9A6B00 !important;border:1px solid #F3D999;">
<i class="bi bi-person-walking me-1"></i>Walk-in
</span>
<?php endif; ?>
</td>

<td>
<?php $dispService = $q['resolved_service_type'] ?? ($q['service_type'] ?? ''); ?>
<?php if (!empty($dispService)): ?>
<?= e($dispService) ?>
<?php else: ?>
<span class="text-muted">-</span>
<?php endif; ?>
</td>

<td>
<?= e($q['dentist_name'] ?: 'Not assigned') ?>
</td>

<td>

<?php if ($q['priority'] === 'Emergency'): ?>

<span class="badge bg-danger">
Emergency
</span>

<?php else: ?>

<span class="badge bg-primary">
Normal
</span>

<?php endif; ?>

</td>

<td>
<?= e($q['status']) ?>
</td>

<td>

<?php if ($q['status'] === 'Waiting'): ?>
<form method="POST" class="d-inline js-queue-status-form">
<?= csrfField() ?>
<input type="hidden" name="action" value="status">
<input type="hidden" name="id" value="<?= $q['id'] ?>">
<input type="hidden" name="status" value="Serving">
<button type="submit" class="btn btn-sm btn-success" data-tip="Start Serving"><i class="bi bi-play-fill"></i> Start Serving</button>
</form>
<?php elseif ($q['status'] === 'Serving'): ?>
<form method="POST" class="d-inline js-queue-status-form">
<?= csrfField() ?>
<input type="hidden" name="action" value="status">
<input type="hidden" name="id" value="<?= $q['id'] ?>">
<input type="hidden" name="status" value="Completed">
<button type="submit" class="btn btn-sm btn-primary" data-tip="Complete"><i class="bi bi-check-lg"></i> Complete</button>
</form>
<?php else: ?>
<span class="text-muted small">Done</span>
<?php endif; ?>

</td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>

</div>

</section>

</main>

<div class="modal fade"
id="addQueue">

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">
<?= csrfField() ?>

<input
type="hidden"
name="action"
value="add">

<div class="modal-header">

<h5>
Walk-in Patient
</h5>

</div>

<div class="modal-body">

<div class="alert alert-info" style="font-size:13px; font-family:'Inter',system-ui,sans-serif;">
<i class="bi bi-info-circle-fill"></i> For <strong>walk-in patients</strong> — please enter the patient details below.
</div>

<input type="hidden" name="patient_id" value="">

<div class="p-3 mb-3" style="background:#f8fcfd; border:1px solid #e0ecef; border-radius:10px;">
<div style="font-weight:700; color:#244e5e; font-size:13.5px; margin-bottom:10px; font-family:'Inter',system-ui,sans-serif;"><i class="bi bi-person-plus-fill" style="color:#0b9fbd;"></i> Walk-in Details</div>
<div class="row g-2">
<div class="col-md-6"><label>First Name *</label><input type="text" name="walkin_first_name" class="form-control" placeholder="John"></div>
<div class="col-md-6"><label>Last Name *</label><input type="text" name="walkin_last_name" class="form-control" placeholder="Doe"></div>
<div class="col-md-4"><label>Age</label><input type="number" name="walkin_age" class="form-control" placeholder="65" min="0" max="120"></div>
<div class="col-md-8"><label>Contact Number <small class="text-muted">(optional)</small></label><input type="text" name="walkin_contact" class="form-control" placeholder="09xx or leave blank"></div>
</div>
</div>

<label style="font-weight:600;">Priority</label>
<select name="priority" class="form-select">
<option value="Normal">Normal</option>
<option value="Emergency">Emergency</option>
</select>

</div>

<div class="modal-footer">

<button class="btn btn-primary" data-tip="Add to Queue">
Add to Queue
</button>

</div>

</form>

</div>

</div>

</div>

<script
src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>

<!-- QR Walk-in Modal: scan QR -> fill-up form (Name + Dental Services) -> deretso pila -->
<div class="modal fade" id="qrWalkin" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<form method="POST" id="qrWalkinForm">
<?= csrfField() ?>
<input type="hidden" name="action" value="qr_add">
<div class="modal-header">
<h5><i class="bi bi-qr-code-scan me-1"></i>Walk-in QR Scan</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" data-tip="Close"></button>
</div>
<div class="modal-body">
<div class="alert alert-success" style="font-size:13px;">
<i class="bi bi-qr-code me-1"></i> Scan the walk-in patient's QR code. The intake form (Name + Dental Service) will open, adding them directly to the queue upon submission.
</div>
<div id="qrReader" style="width:100%; min-height:220px; border:1px dashed #0b9fbd; border-radius:10px; overflow:hidden;"></div>
<div class="d-flex gap-2 mt-2">
<button type="button" class="btn btn-sm btn-outline-primary" id="qrStartBtn" data-tip="Start Camera"><i class="bi bi-camera-video me-1"></i>Start Camera</button>
<button type="button" class="btn btn-sm btn-outline-secondary" id="qrStopBtn" data-tip="Stop">Stop</button>
<button type="button" class="btn btn-sm btn-link" id="qrShowFormBtn" data-tip="Skip scan / manual fill-up">Skip scan / manual fill-up</button>
</div>
<div class="mt-2">
<label style="font-size:12.5px;">QR content (auto-filled once scanned)</label>
<input type="text" id="qrRawInput" class="form-control form-control-sm" placeholder='e.g. {"name":"Juan Dela Cruz","service":"Dental Check-up"} or Juan Dela Cruz|Cleaning'>
</div>
<hr>
<div id="qrFillForm">
<div class="mb-3">
<label style="font-weight:600;">Name *</label>
<input type="text" name="qr_name" id="qrNameInput" class="form-control" placeholder="Juan Dela Cruz" required>
</div>
<div class="mb-3">
<label style="font-weight:600;">Dental Service *</label>
<select name="qr_service" id="qrServiceInput" class="form-select" required>
<option value="">-- Select service --</option>
<option>General Dentistry</option>
<option>Orthodontics (Braces)</option>
<option>Oral Surgery (Tooth Extraction)</option>
<option>Cosmetic</option>
<option>Fillings</option>
<option>Root Canal</option>
<option>Oral Prophylaxis (Cleaning)</option>
<option>Denture</option>
<option>Teeth Whitening</option>
<option>Crown</option>
<option>Bridge</option>
<option>Veneers</option>
<option>Dental Check-up</option>
</select>
</div>
<input type="hidden" name="qr_priority" value="Normal">
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-tip="Close">Close</button>
<button type="submit" class="btn btn-success" data-tip="Add to Queue"><i class="bi bi-plus-circle me-1"></i>Add to Queue</button>
</div>
</form>
</div>
</div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function(){
  var html5Qr = null;
  var readerId = "qrReader";
  function parseQrContent(text){
    text = (text || "").trim();
    if(!text) return;
    document.getElementById("qrRawInput").value = text;
    var name = "", service = "";
    try {
      var obj = JSON.parse(text);
      name = obj.name || obj.full_name || obj.patient || "";
      service = obj.service || obj.service_type || obj.dental_service || "";
    } catch(e) {
      if(text.indexOf("|") !== -1){
        var parts = text.split("|");
        name = (parts[0]||"").trim();
        service = (parts[1]||"").trim();
      } else {
        name = text;
      }
    }
    if(name) document.getElementById("qrNameInput").value = name;
    if(service){
      var sel = document.getElementById("qrServiceInput");
      var matched = false;
      for(var i=0;i<sel.options.length;i++){
        if(sel.options[i].text.toLowerCase() === service.toLowerCase() || sel.options[i].value.toLowerCase() === service.toLowerCase()){
          sel.selectedIndex = i; matched = true; break;
        }
      }
      if(!matched){
        for(var j=0;j<sel.options.length;j++){
          if(service && sel.options[j].text.toLowerCase().indexOf(service.toLowerCase()) !== -1){
            sel.selectedIndex = j; break;
          }
        }
      }
    }
    document.getElementById("qrFillForm").scrollIntoView({behavior:"smooth", block:"center"});
  }
  document.getElementById("qrStartBtn").addEventListener("click", function(){
    if(typeof Html5Qrcode === "undefined"){ alert("QR scanner library failed to load. Please use manual entry."); return; }
    if(html5Qr){ try{ html5Qr.clear(); }catch(e){} }
    html5Qr = new Html5Qrcode(readerId);
    html5Qr.start({facingMode:"environment"}, {fps:10, qrbox:{width:220, height:220}},
      function(decoded){ parseQrContent(decoded); try{ html5Qr.stop(); }catch(e){} },
      function(err){}
    ).catch(function(err){ alert("Unable to access camera: " + err); });
  });
  document.getElementById("qrStopBtn").addEventListener("click", function(){
    if(html5Qr){ try{ html5Qr.stop(); }catch(e){} }
  });
  document.getElementById("qrRawInput").addEventListener("change", function(){ parseQrContent(this.value); });
  document.getElementById("qrShowFormBtn").addEventListener("click", function(){
    document.getElementById("qrFillForm").scrollIntoView({behavior:"smooth", block:"center"});
    document.getElementById("qrNameInput").focus();
  });
  document.getElementById("qrWalkin").addEventListener("hidden.bs.modal", function(){
    if(html5Qr){ try{ html5Qr.stop(); html5Qr.clear(); }catch(e){} html5Qr = null; }
  });
})();
</script>

<!-- Patient QR: ipapa-scan sa cellphone ng patient para mag-fill-up at deretso pila -->
<?php
$qrScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$qrHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
// Port: isama kapag hindi standard (hal. :8080 sa XAMPP) para gumana sa cellphone
$qrPort = $_SERVER['SERVER_PORT'] ?? (($qrScheme === 'https') ? 443 : 80);
$qrPort = (int)$qrPort;
$qrPortSuffix = '';
if (($qrScheme === 'http' && $qrPort !== 80 && $qrPort !== 0) || ($qrScheme === 'https' && $qrPort !== 443 && $qrPort !== 0)) {
    $qrPortSuffix = ':' . $qrPort;
}
$qrBase = rtrim(str_replace('\\','/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if ($qrBase === '/' || $qrBase === '\\' || $qrBase === '.') $qrBase = '';
// Hulaan ang LAN IP para ma-scan ng cellphone (localhost hindi mabubuksan ng phone)
$qrLanIp = '';
try {
    $qrLanIp = gethostbyname(gethostname());
    if ($qrLanIp === '127.0.0.1' || $qrLanIp === '127.0.0.0' || $qrLanIp === gethostname()) {
        $qrLanIp = $_SERVER['SERVER_ADDR'] ?? '';
    }
} catch (Throwable $e) { $qrLanIp = ''; }
$qrLocalUrl = $qrScheme . '://' . $qrHost . $qrBase . '/walkin_qr.php';
$qrLanUrl = ($qrLanIp !== '' && $qrLanIp !== '127.0.0.1') ? ($qrScheme . '://' . $qrLanIp . $qrPortSuffix . $qrBase . '/walkin_qr.php') : '';
if ($qrLanIp === '' || $qrLanIp === '127.0.0.1') { $qrLanUrl = $qrScheme . '://192.168.1.15' . $qrPortSuffix . $qrBase . '/walkin_qr.php'; }
// QR ay hindi localhost: laging LAN IP ang default para ma-open sa cellphone
$qrDefaultUrl = ($qrLanUrl !== '' && stripos($qrLanUrl, 'localhost') === false && stripos($qrLanUrl, '127.0.0.1') === false) ? $qrLanUrl : $qrLocalUrl;
// Pang-data: kung may naka-save na public URL (hal. ngrok https), yun ang gamitin — gumagana sa mobile data, hindi na kailangan ng WiFi
$qrPublicUrl = '';
$qrPublicFile = __DIR__ . '/../qr_public_url.txt';
if (is_file($qrPublicFile)) {
    $qrPublicUrl = trim((string)@file_get_contents($qrPublicFile));
}
// PERMANENT QR: kapag may PERMANENT_QR_DOMAIN sa config.php, ito ang QR 1 —
// hindi nag-e-expire, kahit anong WiFi/mobile data, Android + iPhone.
if (function_exists('ensurePermanentQrTunnelRunning')) {
    ensurePermanentQrTunnelRunning(true);
}
$qrPermanent = function_exists('permanentQrUrl') ? permanentQrUrl() : '';
$qrHasPermanent = ($qrPermanent !== '');
$qrSystemPermanentUrl = ($qrHasPermanent && defined('PERMANENT_QR_DOMAIN'))
    ? ('https://' . rtrim(preg_replace('#^https?://#i', '', trim((string)PERMANENT_QR_DOMAIN)), '/') . '/dental-ai-system-fixed/login.php')
    : '';
// Expired check: ang trycloudflare.com quick tunnel ay temporary — nag-e-expire pag namatay ang tunnel.
// NOTE: walang network check dito para mabilis mag-load ang page; pattern check lang.
$qrIsTempTunnel = ($qrPublicUrl !== '' && stripos($qrPublicUrl, 'trycloudflare.com') !== false);
// Main QR: permanent > public save > LAN
if ($qrHasPermanent) {
    $qrDefaultUrl = $qrPermanent;
} elseif ($qrPublicUrl !== '') {
    $qrDefaultUrl = $qrPublicUrl;
}
$qrIsPublic = ($qrPublicUrl !== '');
$qrIsExpired = false; // hindi na auto-check; user ang mag-test via Open button
?>
<div class="modal fade" id="patientQrModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title"><i class="bi bi-qr-code me-2"></i>Walk-in Patient QR</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" data-tip="Close"></button>
</div>
<div class="modal-body text-center py-4 px-4" style="background:#F6FBF8;">
<?php $qrMain1 = $qrHasPermanent ? $qrPermanent : ($qrLanUrl !== '' ? $qrLanUrl : $qrDefaultUrl); ?>
<div class="qr-display-card">
  <div id="patientQrBoxLan">
    <img src="https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=<?= urlencode($qrMain1) ?>" alt="Walk-in QR" style="width:240px;height:240px;border-radius:8px;background:#fff;display:block;">
  </div>
</div>
<div class="mt-2 text-start" style="display:none;">
<input type="hidden" id="patientQrCustom" value="<?= htmlspecialchars($qrDefaultUrl) ?>">
<input type="hidden" id="patientQrLanValue" value="<?= htmlspecialchars($qrMain1) ?>">
<input type="hidden" id="patientQrPublicValue" value="<?= htmlspecialchars($qrPublicUrl) ?>">
</div>
</div>
</div>
</div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function(){
  function drawQr(boxId, url, size){
    var box = document.getElementById(boxId);
    if(!box || !url) return;
    box.innerHTML = '';
    try {
      if(typeof QRCode !== 'undefined'){
        new QRCode(box, { text: url, width: size, height: size, correctLevel: QRCode.CorrectLevel.H });
        setTimeout(function(){
          var canvas = box.querySelector('canvas');
          var img = box.querySelector('img');
          if (canvas && img) {
            if (canvas.style.display === 'none') {
              img.style.display = 'block';
              img.style.borderRadius = '8px';
            } else {
              img.style.display = 'none';
              canvas.style.borderRadius = '8px';
            }
          }
        }, 50);
      } else { throw new Error('no lib'); }
    } catch(e) {
      var img = document.createElement('img');
      img.src = 'https://api.qrserver.com/v1/create-qr-code/?size='+size+'x'+size+'&data=' + encodeURIComponent(url);
      img.style.cssText = 'width:'+size+'px;height:'+size+'px;border-radius:8px;background:#fff;display:block;';
      box.appendChild(img);
    }
  }
  function copyText(v, msg){
    if(navigator.clipboard){ navigator.clipboard.writeText(v).then(function(){ alert(msg || 'Link copied! Ipa-scan sa Android o iPhone, parehong gagana.'); }); }
    else { prompt('Copy link:', v); }
  }
  function downloadBox(boxId, filename, fallbackUrl){
    var box = document.getElementById(boxId);
    if(!box) return;
    var img = box.querySelector('img');
    var canvas = box.querySelector('canvas');
    if(img && img.src && img.src.indexOf('data:') === 0){ var a=document.createElement('a'); a.href=img.src; a.download=filename; document.body.appendChild(a); a.click(); a.remove(); }
    else if(canvas){ var a2=document.createElement('a'); a2.href=canvas.toDataURL('image/png'); a2.download=filename; document.body.appendChild(a2); a2.click(); a2.remove(); }
    else { window.open('https://api.qrserver.com/v1/create-qr-code/?size=500x500&data='+encodeURIComponent(fallbackUrl), '_blank'); }
  }
  function renderAll(){
    var lanInput = document.getElementById('patientQrLanValue');
    var lan = lanInput ? lanInput.value.trim() : '';
    if(lan){
      var urlEl = document.getElementById('patientQrUrlLan');
      if(urlEl) urlEl.textContent = lan;
      drawQr('patientQrBoxLan', lan, 240);
    }
  }
  var qrModal = document.getElementById('patientQrModal');
  if(qrModal) qrModal.addEventListener('shown.bs.modal', renderAll);
  var cpLan = document.getElementById('patientQrCopyLan');
  if(cpLan) cpLan.addEventListener('click', function(){ copyText(document.getElementById('patientQrLanValue').value.trim(), 'Permanent Walk-in QR link copied!'); });
  var dlLan = document.getElementById('patientQrDownloadLan');
  if(dlLan) dlLan.addEventListener('click', function(){ downloadBox('patientQrBoxLan', 'walkin-qr-permanent.png', document.getElementById('patientQrLanValue').value.trim()); });
  var prLan = document.getElementById('patientQrPrintLan');
  if(prLan) prLan.addEventListener('click', function(){ window.print(); });
  var cpSys = document.getElementById('copySystemUrlBtn');
  if(cpSys) cpSys.addEventListener('click', function(){ copyText(document.getElementById('systemPermanentUrlText').textContent.trim(), 'System link copied! Maaari mo na itong buksan sa ibang device.'); });
  var cp = document.getElementById('patientQrCopyPublic');
  if(cp) cp.addEventListener('click', function(){ copyText(document.getElementById('patientQrPublicValue').value.trim()); });
})();
</script>

<?php
$showQueueUpdatedPopup = !empty($_SESSION['queue_updated_popup']);
unset($_SESSION['queue_updated_popup']);
?>
<!-- Queue Status Loading + "Queue updated." Popup -->
<div id="queueStatusPopup" class="queue-status-popup-overlay" style="display:none;" role="dialog" aria-modal="true">
  <div class="queue-status-popup-card">
    <button type="button" class="queue-status-popup-close" id="queueStatusPopupClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>

    <!-- Loading State -->
    <div id="queuePopupLoading" class="queue-popup-state">
      <div class="queue-popup-spinner" role="status" aria-hidden="true"></div>
      <h5 class="queue-popup-title">Updating queue...</h5>
      <p class="queue-popup-sub">Please wait a moment.</p>
    </div>

    <!-- Updated State -->
    <div id="queuePopupSuccess" class="queue-popup-state" style="display:none;">
      <div class="queue-popup-check"><i class="bi bi-check-lg"></i></div>
      <h5 class="queue-popup-title">Queue updated.</h5>
      <button type="button" class="btn queue-popup-ok-btn mt-2" id="queueStatusPopupOk">OK</button>
    </div>
  </div>
</div>

<style>
.queue-status-popup-overlay {
  position: fixed;
  inset: 0;
  background: rgba(2, 44, 34, 0.55);
  backdrop-filter: blur(3px);
  z-index: 2060;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.queue-status-popup-card {
  position: relative;
  background: #ffffff;
  border: 1.5px solid #C9E9DA;
  border-radius: 18px;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.28);
  max-width: 360px;
  width: 100%;
  padding: 30px 26px 26px;
  text-align: center;
  font-family: "Inter", "Plus Jakarta Sans", system-ui, sans-serif;
  animation: queuePopupIn 0.18s ease-out;
}
@keyframes queuePopupIn {
  from { transform: scale(0.94); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}
.queue-status-popup-close {
  position: absolute;
  top: 12px;
  right: 12px;
  width: 30px;
  height: 30px;
  border-radius: 9px;
  border: 1px solid #D1E7DD;
  background: #ffffff;
  color: #57727d;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  cursor: pointer;
}
.queue-status-popup-close:hover {
  background: #EAF7F0;
  color: #0E6B5E;
}
.queue-popup-spinner {
  width: 54px;
  height: 54px;
  margin: 4px auto 16px;
  border: 4px solid #D9F2E6;
  border-top-color: #0E6B5E;
  border-radius: 50%;
  animation: queueSpin 0.75s linear infinite;
}
@keyframes queueSpin {
  to { transform: rotate(360deg); }
}
.queue-popup-check {
  width: 58px;
  height: 58px;
  margin: 4px auto 14px;
  border-radius: 50%;
  background: linear-gradient(135deg, #EAF7F0 0%, #D1F2E2 100%);
  border: 2px solid #86EFAC;
  color: #0E6B5E;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 30px;
  animation: queuePopupIn 0.2s ease-out;
}
.queue-popup-title {
  color: #143f51;
  font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
  font-weight: 800;
  font-size: 18px;
  margin: 0 0 6px;
}
.queue-popup-sub {
  color: #7c959f;
  font-size: 13px;
  margin: 0;
}
.queue-popup-ok-btn {
  background: linear-gradient(135deg, #149A72, #0A4A41) !important;
  color: #ffffff !important;
  border: none !important;
  border-radius: 10px;
  font-size: 13.5px;
  font-weight: 700;
  padding: 8px 28px;
  box-shadow: 0 6px 14px rgba(14, 107, 94, 0.25);
}
.queue-popup-ok-btn:hover {
  filter: brightness(0.95);
}
</style>

<script>
(function(){
  var overlay = document.getElementById('queueStatusPopup');
  var loadingBox = document.getElementById('queuePopupLoading');
  var successBox = document.getElementById('queuePopupSuccess');
  var closeBtn = document.getElementById('queueStatusPopupClose');
  var okBtn = document.getElementById('queueStatusPopupOk');
  var autoCloseTimer = null;

  function openLoadingPopup() {
    if (!overlay) return;
    if (autoCloseTimer) { clearTimeout(autoCloseTimer); autoCloseTimer = null; }
    loadingBox.style.display = 'block';
    successBox.style.display = 'none';
    overlay.style.display = 'flex';
  }

  function showUpdatedState() {
    if (!overlay) return;
    loadingBox.style.display = 'none';
    successBox.style.display = 'block';
    if (autoCloseTimer) clearTimeout(autoCloseTimer);
    autoCloseTimer = setTimeout(closePopup, 1400);
  }

  function closePopup() {
    if (!overlay) return;
    if (autoCloseTimer) { clearTimeout(autoCloseTimer); autoCloseTimer = null; }
    overlay.style.display = 'none';
  }

  if (closeBtn) closeBtn.addEventListener('click', closePopup);
  if (okBtn) okBtn.addEventListener('click', closePopup);
  if (overlay) {
    overlay.addEventListener('click', function(e){
      if (e.target === overlay && successBox.style.display !== 'none') {
        closePopup();
      }
    });
  }

  function bindQueueStatusForms() {
    document.querySelectorAll('form.js-queue-status-form').forEach(function(form){
      if (form.dataset.popupBound === '1') return;
      form.dataset.popupBound = '1';

      form.addEventListener('submit', function(e){
        e.preventDefault();
        openLoadingPopup();

        var fd = new FormData(form);
        fd.append('ajax_popup', '1');
        var minDelay = new Promise(function(resolve){ setTimeout(resolve, 850); });

        var req = fetch(window.location.href, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        }).then(function(res){ return res.text(); });

        Promise.all([req, minDelay]).then(function(results){
          var html = results[0] || '';
          try {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var newTbody = doc.querySelector('.queue-table tbody');
            var curTbody = document.querySelector('.queue-table tbody');
            if (newTbody && curTbody) {
              curTbody.innerHTML = newTbody.innerHTML;
              bindQueueStatusForms();
            } else {
              window.location.reload();
              return;
            }
          } catch (err) {
            window.location.reload();
            return;
          }
          showUpdatedState();
        }).catch(function(){
          form.submit();
        });
      });
    });
  }

  bindQueueStatusForms();

  <?php if ($showQueueUpdatedPopup): ?>
  openLoadingPopup();
  setTimeout(showUpdatedState, 750);
  <?php endif; ?>
})();
</script>

</body>

</html>