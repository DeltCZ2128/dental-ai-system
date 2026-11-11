<?php

require_once __DIR__ . "/../functions.php";

requireLogin();


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

    /* Per-dentist weekly rest day: ''/null = no extra rest day, 0-6 = Sunday-Saturday. */
    if (!function_exists('adminDentistRestDayInput')) {
        function adminDentistRestDayInput()
        {
            $raw = trim($_POST['rest_day'] ?? '');
            if ($raw === '' || $raw === 'none') {
                return null;
            }
            $day = (int)$raw;
            return ($day >= 0 && $day <= 6) ? $day : null;
        }
    }

    if ($action === 'add') {

        $dentistName = trim($_POST['dentist_name'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $contactNumber = trim($_POST['contact_number'] ?? '');
        $loginEmail = trim($_POST['login_email'] ?? '');
        $loginPassword = (string)($_POST['login_password'] ?? '');

        if ($dentistName === '') {
            flash("Dentist name is required.", "danger");
            redirect("dentists.php");
        }

        ensureDentistUserIdColumn();

        // Kapag may login email + password, gumawa ng users account (role=dentist) at i-link.
        $linkedUserId = null;
        if ($loginEmail !== '' || $loginPassword !== '') {
            if (!filter_var($loginEmail, FILTER_VALIDATE_EMAIL)) {
                flash("Please enter a valid login email for the dentist account.", "danger");
                redirect("dentists.php");
            }
            if (strlen($loginPassword) < 6) {
                flash("Dentist login password must be at least 6 characters.", "danger");
                redirect("dentists.php");
            }
            $check = $pdo->prepare("SELECT id, role FROM users WHERE username = ? LIMIT 1");
            $check->execute([$loginEmail]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if (strtolower(trim($existing['role'] ?? '')) !== 'dentist') {
                    flash("That email is already used by a non-dentist account.", "danger");
                    redirect("dentists.php");
                }
                $linkedUserId = (int)$existing['id'];
                $pdo->prepare("UPDATE users SET full_name = ? WHERE id = ?")->execute([$dentistName, $linkedUserId]);
            } else {
                $ins = $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, 'dentist')");
                $ins->execute([$loginEmail, password_hash($loginPassword, PASSWORD_DEFAULT), $dentistName]);
                $linkedUserId = (int)$pdo->lastInsertId();
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO dentists
            (
                dentist_name,
                specialization,
                contact_number,
                rest_day,
                user_id
            )
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $dentistName,
            $specialization,
            $contactNumber,
            adminDentistRestDayInput(),
            $linkedUserId
        ]);

        flash($linkedUserId ? "Dentist added with login account." : "Dentist added.");

        redirect("dentists.php");
    }

    if ($action === 'edit') {

        ensureDentistUserIdColumn();

        $dentistId = (int) $_POST['dentist_id'];
        $dentistName = trim($_POST['dentist_name']);
        $loginEmail = trim($_POST['login_email'] ?? '');
        $loginPassword = (string)($_POST['login_password'] ?? '');

        $stmt = $pdo->prepare("
            UPDATE dentists
            SET dentist_name = ?, specialization = ?, contact_number = ?, rest_day = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $dentistName,
            trim($_POST['specialization']),
            trim($_POST['contact_number']),
            adminDentistRestDayInput(),
            $dentistId
        ]);

        // I-sync ang linked login account (kung mayroon), o gumawa kapag may email+password.
        try {
            $cur = $pdo->prepare("SELECT user_id FROM dentists WHERE id = ? LIMIT 1");
            $cur->execute([$dentistId]);
            $curUserId = (int)($cur->fetchColumn() ?: 0);

            if ($loginEmail !== '' || $loginPassword !== '') {
                if ($loginEmail !== '' && !filter_var($loginEmail, FILTER_VALIDATE_EMAIL)) {
                    flash("Dentist updated, but login email is invalid so account was not linked.", "danger");
                    redirect("dentists.php");
                }
                if ($loginPassword !== '' && strlen($loginPassword) < 6) {
                    flash("Dentist updated, but password must be at least 6 characters.", "danger");
                    redirect("dentists.php");
                }
                if ($loginEmail !== '') {
                    $check = $pdo->prepare("SELECT id, role FROM users WHERE username = ? LIMIT 1");
                    $check->execute([$loginEmail]);
                    $existing = $check->fetch(PDO::FETCH_ASSOC);
                    if ($existing && strtolower(trim($existing['role'] ?? '')) !== 'dentist') {
                        flash("Dentist updated, but that email belongs to a non-dentist account.", "danger");
                        redirect("dentists.php");
                    }
                    if ($existing) {
                        $linkId = (int)$existing['id'];
                        $pdo->prepare("UPDATE users SET full_name = ? WHERE id = ?")->execute([$dentistName, $linkId]);
                        if ($loginPassword !== '') {
                            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($loginPassword, PASSWORD_DEFAULT), $linkId]);
                        }
                        $pdo->prepare("UPDATE dentists SET user_id = ? WHERE id = ?")->execute([$linkId, $dentistId]);
                    } else {
                        if ($loginPassword === '') {
                            flash("Dentist updated, but a password is required to create a new login.", "danger");
                            redirect("dentists.php");
                        }
                        $ins = $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, 'dentist')");
                        $ins->execute([$loginEmail, password_hash($loginPassword, PASSWORD_DEFAULT), $dentistName]);
                        $pdo->prepare("UPDATE dentists SET user_id = ? WHERE id = ?")->execute([(int)$pdo->lastInsertId(), $dentistId]);
                    }
                } elseif ($curUserId > 0 && $loginPassword !== '') {
                    // May naka-link na: password reset lang.
                    $pdo->prepare("UPDATE users SET password = ?, full_name = ? WHERE id = ?")->execute([password_hash($loginPassword, PASSWORD_DEFAULT), $dentistName, $curUserId]);
                } elseif ($curUserId > 0) {
                    $pdo->prepare("UPDATE users SET full_name = ? WHERE id = ?")->execute([$dentistName, $curUserId]);
                }
            } elseif ($curUserId > 0) {
                $pdo->prepare("UPDATE users SET full_name = ? WHERE id = ?")->execute([$dentistName, $curUserId]);
            }
        } catch (Throwable $e) {
        }

        flash("Dentist information updated.");
        redirect("dentists.php");
    }

    if ($action === 'toggle_status') {

        $stmt = $pdo->prepare("
            UPDATE dentists
            SET status = CASE WHEN status = 'Active' THEN 'Inactive' ELSE 'Active' END
            WHERE id = ?
        ");

        $stmt->execute([(int) $_POST['dentist_id']]);

        flash("Dentist availability status updated.");
        redirect("dentists.php");
    }

    if ($action === 'schedule') {

        $scheduleDate = $_POST['schedule_date'] ?? '';
        $startTime = $_POST['start_time'] ?? '';
        $endTime = $_POST['end_time'] ?? '';

        if ($scheduleDate === '' || $startTime === '' || $endTime <= $startTime) {
            flash("Please provide a valid available date and time.", "danger");
            redirect("dentists.php");
        }

        $stmt = $pdo->prepare("
            INSERT INTO dentist_schedules
            (
                dentist_id,
                schedule_date,
                start_time,
                end_time
            )
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([
            $_POST['dentist_id'],
            $scheduleDate,
            $startTime,
            $endTime
        ]);

        flash("Dentist schedule added.");

        redirect("dentists.php");
    }

    if ($action === 'delete_schedule') {

        $stmt = $pdo->prepare("DELETE FROM dentist_schedules WHERE id = ?");
        $stmt->execute([(int) $_POST['schedule_id']]);

        flash("Dentist availability removed.");
        redirect("dentists.php");
    }

    /* Specific rest date (flexible: pwedeng ibang araw kada linggo). */
    if ($action === 'dayoff_add') {

        $dentistId = (int)($_POST['dentist_id'] ?? 0);
        $offDate = trim($_POST['off_date'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if ($dentistId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $offDate)) {
            flash("Please provide a valid rest date.", "danger");
            redirect("dentists.php");
        }

        if ($offDate < date('Y-m-d')) {
            flash("Rest day cannot be in the past.", "danger");
            redirect("dentists.php");
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO dentist_day_offs (dentist_id, off_date, reason)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE reason = VALUES(reason)
            ");
            $stmt->execute([$dentistId, $offDate, $reason]);

            $cStmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE dentist_id = ? AND appointment_date = ? AND status IN ('Confirmed', 'Pending')");
            $cStmt->execute([$dentistId, $offDate]);
            $affCount = (int)$cStmt->fetchColumn();

            if ($affCount > 0) {
                flash("Day-off/leave recorded for " . date('M d, Y (D)', strtotime($offDate)) . ". ⚠️ ATTENTION: There are {$affCount} scheduled appointment(s) affected! Please go to Appointments to reassign them to an available doctor.", "warning");
            } else {
                flash("Rest day added for " . date('M d, Y (D)', strtotime($offDate)) . ".");
            }
        } catch (Throwable $e) {
            flash("Unable to save rest day. (" . $e->getMessage() . ")", "danger");
        }

        redirect("dentists.php");
    }

    if ($action === 'mark_absent') {
        $dentistId = (int)($_POST['dentist_id'] ?? 0);
        $offDate = trim($_POST['off_date'] ?? date('Y-m-d'));
        $leaveType = trim($_POST['leave_type'] ?? 'Medical Emergency / Sick Leave');
        $notes = trim($_POST['absence_notes'] ?? '');
        $combinedReason = 'ABSENT: ' . $leaveType . ($notes !== '' ? ' (' . $notes . ')' : '');

        if ($dentistId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $offDate)) {
            flash("Please provide a valid absence date.", "danger");
            redirect("dentists.php");
        }

        if ($offDate < date('Y-m-d')) {
            flash("Absence date cannot be in the past.", "danger");
            redirect("dentists.php");
        }

        $dnStmt = $pdo->prepare("SELECT dentist_name FROM dentists WHERE id = ?");
        $dnStmt->execute([$dentistId]);
        $rawDentistName = $dnStmt->fetchColumn() ?: 'The dentist';
        $dentistName = preg_replace('/^Dr\.?\s+/i', '', $rawDentistName);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO dentist_day_offs (dentist_id, off_date, reason)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE reason = VALUES(reason)
            ");
            $stmt->execute([$dentistId, $offDate, $combinedReason]);

            $cStmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM appointments 
                WHERE dentist_id = ? 
                  AND appointment_date = ? 
                  AND status IN ('Confirmed', 'Pending')
            ");
            $cStmt->execute([$dentistId, $offDate]);
            $affCount = (int)$cStmt->fetchColumn();

            if ($affCount > 0) {
                flash("Dr. {$dentistName} has been marked ABSENT on " . date('M d, Y (l)', strtotime($offDate)) . ". ⚠️ ATTENTION: There are {$affCount} scheduled appointment(s) affected! Please use the 'Reassign' feature in Appointments to transfer them to an available doctor.", "danger");
            } else {
                flash("Dr. {$dentistName} has been marked ABSENT on " . date('M d, Y (l)', strtotime($offDate)) . ". The calendar has been blocked for new bookings.", "warning");
            }
        } catch (Throwable $e) {
            flash("Unable to record absence. (" . $e->getMessage() . ")", "danger");
        }

        redirect("dentists.php");
    }

    if ($action === 'dayoff_delete') {

        $stmt = $pdo->prepare("DELETE FROM dentist_day_offs WHERE id = ?");
        $stmt->execute([(int)($_POST['dayoff_id'] ?? 0)]);

        flash("Rest day removed. The dentist can now be booked on that date.");
        redirect("dentists.php");
    }
}

if (isset($_GET['delete'])) {

    $stmt = $pdo->prepare("
        DELETE FROM dentists
        WHERE id = ?
    ");

    $stmt->execute([
        (int)$_GET['delete']
    ]);

    flash("Dentist deleted.");

    redirect("dentists.php");
}

$dentists = $pdo->query("
    SELECT *
    FROM dentists
    ORDER BY dentist_name
")->fetchAll();

?>

<!DOCTYPE html>
<html>

<head>

<title>Dentists</title>

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
/* Dentists — ibinase sa system design (tulad ng Settings/Patients) */
body { background: linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22) !important; background-size: 300% 300% !important; animation: adminBgAnim 8s ease infinite !important; background-attachment: fixed !important; }
@keyframes adminBgAnim { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
.main { margin-left: 280px !important; width: calc(100% - 280px) !important; padding: 110px 24px 40px !important; background: transparent !important; min-height: 100vh; box-sizing: border-box !important; }
.main > a.btn, .main > .alert { margin-left: 14px; margin-right: 14px; }
.main > .alert { font-size: 13.5px; border-radius: 12px; }
.main > .d-flex.justify-content-between { margin: 0 14px 16px 14px !important; padding-top: 0 !important; flex-wrap: wrap; gap: 12px; align-items: center !important; }
.main > .d-flex.justify-content-between h2 { color: #ffffff !important; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; margin: 0; display: flex; align-items: center; gap: 8px; }
.main > .d-flex.justify-content-between h2 i { color: #ffffff; font-size: 18px; }
.main > .d-flex.justify-content-between .head-sub { margin: 4px 0 0; color: #C9E9DA; font-size: 13px; }
.main .card { background: #ffffff !important; border: 1px solid #C9E9DA !important; border-radius: 16px !important; box-shadow: 0 8px 24px rgba(14,107,94,.08) !important; padding: 20px !important; margin: 0 14px !important; overflow: hidden !important; }
.main .table-responsive { min-height: auto; }
.main .table { margin-bottom: 0 !important; width: 100%; border-collapse: separate; border-spacing: 0; background: #ffffff; }
.main .table thead tr { background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important; }
.main .table thead th { background: transparent !important; color: #ffffff !important; font-size: 11.5px !important; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; white-space: nowrap; padding: 14px 16px !important; border-bottom: none !important; border-right: 1px solid rgba(255,255,255,.12) !important; font-family: "Inter", system-ui, sans-serif; }
.main .table thead th:last-child { border-right: none !important; }
.main .table tbody td { padding: 14px 16px !important; color: #4d6873 !important; font-size: 13.5px !important; font-family: "Inter", "DM Sans", system-ui, sans-serif; vertical-align: middle; background: #ffffff; border-bottom: 1px solid #E6F2EC !important; }
.main .table tbody tr:nth-child(even) td { background: #F6FBF8 !important; }
.main .table tbody tr:hover td { background: #EAF7F0 !important; }
.main .table tbody td strong { color: #143f51 !important; }
.main .table tbody td small, .main .table tbody td .text-muted { color: #8aa0a8 !important; }
.main .badge { border-radius: 20px; font-size: 11.5px !important; font-weight: 700; font-family: "Inter", system-ui, sans-serif; padding: 6px 11px !important; }
.main .badge.bg-success { background: #e7f8ef !important; color: #15945a !important; border: 1px solid #b9e8cd; }
.main .badge.bg-secondary { background: #eef3f4 !important; color: #607780 !important; border: 1px solid #d5e0e3; }
.main .badge.bg-info { background: #e6f4fb !important; color: #157ca0 !important; border: 1px solid #bfe3f2; }
.main .badge.bg-warning { background: #fff3d9 !important; color: #b45309 !important; border: 1px solid #f5d48a; }
.main .btn { font-size: 13.5px; font-family: "Inter", system-ui, sans-serif; }
.main .btn-sm { font-size: 12.5px; }
.main .btn-primary { background: linear-gradient(135deg, #149A72, #0A4A41) !important; border: none !important; border-radius: 10px; box-shadow: 0 6px 14px rgba(14,107,94,.22); font-weight: 700; }
.main .btn-primary:hover { background: linear-gradient(135deg, #0E6B5E, #083A32) !important; }
.main .btn-outline-primary { color: #0E6B5E !important; border-color: #C9E9DA !important; background: #fff !important; }
.main .btn-outline-primary:hover { background: #EAF7F0 !important; color: #0E6B5E !important; border-color: #0E6B5E !important; }
.main .btn-outline-secondary { color: #57727d !important; border-color: #D1E7DD !important; background: #fff !important; }
.main .btn-outline-secondary:hover { background: #EAF7F0 !important; color: #0E6B5E !important; border-color: #0E6B5E !important; }
.main .btn-outline-warning { color: #b45309 !important; border-color: #f5d48a !important; background: #fff !important; }
.main .btn-outline-warning:hover { background: #fff3d9 !important; color: #b45309 !important; border-color: #b45309 !important; }
.main .btn-outline-success { color: #15945a !important; border-color: #b9e8cd !important; background: #fff !important; }
.main .btn-outline-success:hover { background: #e7f8ef !important; color: #15945a !important; border-color: #15945a !important; }
.main .btn-outline-danger { color: #C0392B !important; border-color: #F5C6C6 !important; background: #fff !important; }
.main .btn-outline-danger:hover { background: #C0392B !important; color: #fff !important; border-color: #C0392B !important; }
/* Tooltip inside table: palaging sa ITAAS ng button lumitaw para hindi mag-overflow sa ilalim ng card na nagiging sanhi ng violent shaking */
.main .table [data-tip]:hover::after,
.main .table tbody tr:last-child [data-tip]:hover::after {
    bottom: calc(100% + 8px) !important;
    top: auto !important;
}
.main .modal-content { border-radius: 16px !important; border: 1px solid #C9E9DA !important; overflow: visible !important; }
.main .modal-header, .modal-header { background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%) !important; border-bottom: 1px solid #C9E9DA !important; border-top-left-radius: 15px !important; border-top-right-radius: 15px !important; }
.main .modal-footer, .modal-footer { border-bottom-left-radius: 15px !important; border-bottom-right-radius: 15px !important; }
.modal-title, .modal-header h5 { font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-size: 17px; font-weight: 800; color: #143f51; }
.modal-body label, .modal-body .form-label { font-size: 12.5px; font-weight: 700; color: #4d6873; font-family: "Inter", system-ui, sans-serif; }
.modal-body .form-control, .modal-body .form-select { font-size: 13.5px; border-radius: 10px; border: 1px solid #D1E7DD; }
@media (max-width: 991px) { .main { margin-left: 0 !important; width: 100% !important; padding: 100px 16px 40px !important; } .main .card { margin-left: 0 !important; margin-right: 0 !important; } .main > a.btn, .main > .alert, .main > .d-flex.justify-content-between { margin-left: 0 !important; margin-right: 0 !important; } }
</style>

</head>

<body>

<?php include __DIR__ . "/includes.php"; ?>

<div class="main">

<?php showFlash(); ?>

<a href="dashboard.php" class="btn btn-outline-secondary btn-sm mb-3" data-tip="Back to Dashboard">
<i class="bi bi-arrow-left me-1"></i>Back to Dashboard
</a>

<div class="d-flex justify-content-end mb-4">

<button
class="btn btn-primary"
data-tip="Add Dentist"
data-bs-toggle="modal"
data-bs-target="#addDentist">

<i class="bi bi-person-plus-fill me-1"></i>Add Dentist

</button>

</div>

<div class="card">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
<thead class="table-light">
<tr>
<th>Dentist</th>
<th>Login Account</th>
<th>Contact</th>
<th>Weekly Rest Day</th>
<th>Rest Days (dates)</th>
<th>Available Schedules</th>
<th>Status</th>
<th class="text-end">Actions</th>
</tr>
</thead>
<tbody>

<?php foreach ($dentists as $d): ?>
<?php
$restName = '';
if (function_exists('dentistRestDayName') && array_key_exists('rest_day', $d)) {
    $restName = dentistRestDayName($d['rest_day']);
} elseif (array_key_exists('rest_day', $d) && $d['rest_day'] !== null && $d['rest_day'] !== '') {
    $dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    $restName = $dayNames[(int)$d['rest_day']] ?? '';
}
$dayOffs = [];
try {
    $offStmt = $pdo->prepare("SELECT * FROM dentist_day_offs WHERE dentist_id = ? AND off_date >= CURDATE() ORDER BY off_date ASC");
    $offStmt->execute([$d['id']]);
    $dayOffs = $offStmt->fetchAll();
} catch (Throwable $e) {
    $dayOffs = [];
}
$schedStmt = $pdo->prepare("SELECT * FROM dentist_schedules WHERE dentist_id = ? ORDER BY schedule_date");
$schedStmt->execute([$d['id']]);
$schedules = $schedStmt->fetchAll();
?>
<tr>

<td>
<strong><?= e($d['dentist_name']) ?></strong><br>
<small class="text-muted"><?= e($d['specialization']) ?></small>
</td>

<td class="small" style="min-width:190px;">
<?php
$linkedEmail = '';
try {
    if (!empty($d['user_id'])) {
        $luStmt = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
        $luStmt->execute([(int)$d['user_id']]);
        $linkedEmail = (string)($luStmt->fetchColumn() ?: '');
    }
    if ($linkedEmail === '') {
        $nmStmt = $pdo->prepare("SELECT username FROM users WHERE full_name = ? AND role = 'dentist' LIMIT 1");
        $nmStmt->execute([$d['dentist_name']]);
        $linkedEmail = (string)($nmStmt->fetchColumn() ?: '');
    }
} catch (Throwable $e) { $linkedEmail = ''; }
?>
<?php if ($linkedEmail !== ''): ?>
<span class="badge bg-success"><i class="bi bi-person-check-fill me-1"></i><?= e($linkedEmail) ?></span><br>
<small class="text-muted">Login enabled</small>
<?php else: ?>
<span class="badge bg-secondary">No login</span><br>
<small class="text-muted">Edit to assign</small>
<?php endif; ?>
</td>

<td class="small"><?= e($d['contact_number'] ?: 'Not provided') ?></td>

<td>
<?php if ($restName !== ''): ?>
<span class="badge bg-info text-dark"><?= e($restName) ?></span>
<?php else: ?>
<span class="text-muted small">None</span>
<?php endif; ?>
</td>

<td class="small" style="min-width:220px;">
<?php if (!empty($dayOffs)): ?>
<?php foreach ($dayOffs as $off): ?>
<div class="d-flex justify-content-between align-items-center border rounded px-2 py-1 mb-1" style="background:#fff8f0;">
<span>
<strong><?= e(date('M d, Y', strtotime($off['off_date']))) ?></strong>
<?php if (stripos($off['reason'] ?? '', 'ABSENT') !== false): ?>
<span class="badge bg-danger text-white ms-1">ABSENT</span>
<?php else: ?>
<span class="badge bg-warning text-dark ms-1">REST</span>
<?php endif; ?>
<?php
$affCount = 0;
try {
    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE dentist_id = ? AND appointment_date = ? AND status IN ('Confirmed', 'Pending')");
    $cStmt->execute([$d['id'], $off['off_date']]);
    $affCount = (int)$cStmt->fetchColumn();
} catch (Throwable $e) {}
?>
<?php if ($affCount > 0): ?>
<?php
$isOffToday = ($off['off_date'] === date('Y-m-d'));
$affReassignLink = $isOffToday
    ? 'appointments.php?dentist_id=' . (int)$d['id'] . '&tab=today#today'
    : 'appointments.php?dentist_id=' . (int)$d['id'] . '&udate=' . urlencode($off['off_date']) . '&tab=upcoming#upcoming';
?>
<a href="<?= e($affReassignLink) ?>" class="badge bg-danger text-white text-decoration-none ms-1" data-tip="Click to Reassign Appointments">
<i class="bi bi-exclamation-triangle-fill"></i> <?= $affCount ?> Affected (Reassign)
</a>
<?php endif; ?>
<?php if (!empty($off['reason'])): ?>
<br><small class="text-muted"><?= e($off['reason']) ?></small>
<?php endif; ?>
</span>
<form method="POST" class="js-delete-dayoff-form" data-date="<?= e(date('M d, Y', strtotime($off['off_date']))) ?>" data-dentist="<?= e($d['dentist_name']) ?>" data-type="<?= stripos($off['reason'] ?? '', 'ABSENT') !== false ? 'absence' : 'restday' ?>">
<?= csrfField() ?>
<input type="hidden" name="action" value="dayoff_delete">
<input type="hidden" name="dayoff_id" value="<?= (int)$off['id'] ?>">
<button type="button" class="btn btn-sm btn-outline-danger ms-2 js-dayoff-delete-trigger" title="Remove rest day" data-tip="Remove rest day"><i class="bi bi-trash"></i></button>
</form>
</div>
<?php endforeach; ?>
<?php else: ?>
<span class="text-muted">No rest days</span>
<?php endif; ?>
<div class="d-flex flex-wrap gap-1 mt-2">
<button type="button" class="btn btn-sm btn-outline-warning" data-tip="Add Rest Day" data-bs-toggle="modal" data-bs-target="#dayoff<?= $d['id'] ?>" style="font-size:11.5px; border-radius:6px;">
<i class="bi bi-moon"></i> Add Rest Day
</button>
<button type="button" class="btn btn-sm btn-danger text-white fw-bold" data-tip="Mark as Absent / Emergency Leave" data-bs-toggle="modal" data-bs-target="#absentModal<?= $d['id'] ?>" style="font-size:11.5px; border-radius:6px; box-shadow:0 2px 6px rgba(220,53,69,.25);">
<i class="bi bi-person-x-fill me-1"></i>Mark as Absent
</button>
</div>
</td>

<td class="small" style="min-width:250px;">
<div class="border rounded px-2 py-1 mb-1" style="background:#f3fbf7;border-color:#c9e9da !important;">
<div class="fw-bold" style="color:#0E6B5E;"><i class="bi bi-clock-history me-1"></i>Regular Shift (Mon–Sat<?= $restName !== '' ? ', except ' . e($restName) : '' ?>)</div>
<div class="text-muted">8:00 AM – 12:00 PM, 1:00 PM – 6:00 PM</div>
<div style="color:#b45309;font-size:11.5px;"><i class="bi bi-cup-hot me-1"></i>Lunch Break: 12:00 PM – 1:00 PM</div>
</div>
<?php if (!empty($schedules)): ?>
<div class="fw-semibold text-muted mt-1 mb-1" style="font-size:11px;text-transform:uppercase;">Custom Date Overrides:</div>
<?php foreach ($schedules as $s): ?>
<div class="d-flex justify-content-between align-items-center border rounded px-2 py-1 mb-1">
<span>
<strong><?= e(date('D, M d, Y', strtotime($s['schedule_date']))) ?></strong><br>
<small class="text-muted"><?= e(date('h:i A', strtotime($s['start_time']))) ?> - <?= e(date('h:i A', strtotime($s['end_time']))) ?></small>
</span>
<form method="POST" class="js-delete-dayoff-form" data-date="<?= e(date('D, M d, Y', strtotime($s['schedule_date']))) ?>" data-dentist="<?= e($d['dentist_name']) ?>" data-type="schedule">
<?= csrfField() ?>
<input type="hidden" name="action" value="delete_schedule">
<input type="hidden" name="schedule_id" value="<?= (int) $s['id'] ?>">
<button type="button" class="btn btn-sm btn-outline-danger ms-2 js-dayoff-delete-trigger" title="Remove availability" data-tip="Remove availability"><i class="bi bi-trash"></i></button>
</form>
</div>
<?php endforeach; ?>
<?php endif; ?>
<div>
<button class="btn btn-sm btn-outline-primary mt-1" data-tip="Add Custom Schedule" data-bs-toggle="modal" data-bs-target="#schedule<?= $d['id'] ?>">
<i class="bi bi-calendar-plus me-1"></i>Add Custom Schedule
</button>
</div>
</td>

<td>
<span class="badge <?= $d['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>">
<?= e($d['status']) ?>
</span>
</td>

<td class="text-end" style="min-width:150px;">
<div class="btn-group btn-group-sm" role="group">
<button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#edit<?= (int) $d['id'] ?>" title="Edit" data-tip="Edit Dentist"><i class="bi bi-pencil"></i></button>
<form method="POST" class="d-inline js-dentist-status-form" data-dentist-name="<?= e($d['dentist_name']) ?>" data-current-status="<?= e((string)$d['status']) ?>">
<?= csrfField() ?>
<input type="hidden" name="action" value="toggle_status">
<input type="hidden" name="dentist_id" value="<?= (int) $d['id'] ?>">
<button type="submit" class="btn <?= $d['status'] === 'Active' ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $d['status'] === 'Active' ? 'Deactivate' : 'Activate' ?>" data-tip="<?= $d['status'] === 'Active' ? 'Deactivate Dentist' : 'Activate Dentist' ?>"><i class="bi bi-power"></i></button>
</form>
<a href="dentists.php?delete=<?= (int) $d['id'] ?>" class="btn btn-outline-danger js-delete-dentist-link" data-dentist-name="<?= e($d['dentist_name']) ?>" title="Delete" data-tip="Delete Dentist"><i class="bi bi-trash"></i></a>
</div>
</td>

</tr>

<?php endforeach; ?>

</tbody>
</table>
</div>
</div>

<?php foreach ($dentists as $d): ?>

<!-- Edit Dentist Modal -->
<div class="modal fade" id="edit<?= (int) $d['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
<?= csrfField() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="dentist_id" value="<?= (int) $d['id'] ?>">
                <div class="modal-header"><h5 class="modal-title">Edit Dentist</h5><button type="button" class="btn-close" data-bs-dismiss="modal" data-tip="Close"></button></div>
                <div class="modal-body">
                    <label class="form-label">Dentist Name</label>
                    <input name="dentist_name" class="form-control mb-3" required value="<?= e($d['dentist_name']) ?>">
                    <label class="form-label">Specialization</label>
                    <input name="specialization" class="form-control mb-3" value="<?= e($d['specialization']) ?>">
                    <label class="form-label">Contact Number</label>
                    <input name="contact_number" class="form-control mb-3" value="<?= e($d['contact_number']) ?>">
                    <hr>
                    <label class="form-label">Login Email (dentist account)</label>
                    <?php
                    $editEmail = '';
                    try {
                        if (!empty($d['user_id'])) {
                            $eeStmt = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                            $eeStmt->execute([(int)$d['user_id']]);
                            $editEmail = (string)($eeStmt->fetchColumn() ?: '');
                        }
                    } catch (Throwable $e) {}
                    ?>
                    <input name="login_email" type="email" class="form-control mb-1" placeholder="dentist@example.com" value="<?= e($editEmail) ?>">
                    <small class="text-muted d-block mb-3">Leave blank to keep existing login credentials. Provide email + password to create or link an account.</small>
                    <label class="form-label">Password (leave blank to keep current)</label>
                    <input name="login_password" type="password" class="form-control mb-3" placeholder="New password (min. 6)" minlength="6" autocomplete="new-password">
                    <label class="form-label">Rest Day (weekly)</label>
                    <?php $currentRest = array_key_exists('rest_day', $d) ? $d['rest_day'] : null; ?>
                    <select name="rest_day" class="form-select">
                        <option value="" <?= ($currentRest === null || $currentRest === '') ? 'selected' : '' ?>>None</option>
                        <?php foreach (['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $num => $dayName): ?>
                            <option value="<?= $num ?>" <?= ((string)$currentRest === (string)$num) ? 'selected' : '' ?>><?= $dayName ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer"><button class="btn btn-primary" data-tip="Save Changes">Save Changes</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Schedule Modal -->

<div class="modal fade"
id="schedule<?= $d['id'] ?>">

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">
<?= csrfField() ?>

<input
type="hidden"
name="action"
value="schedule">

<input
type="hidden"
name="dentist_id"
value="<?= $d['id'] ?>">

<div class="modal-header">

<h5>
Add Schedule
</h5>

</div>

<div class="modal-body">

<label>Date</label>

<input
type="date"
name="schedule_date"
class="form-control mb-3"
required>

<label>Start</label>

<input
type="time"
name="start_time"
class="form-control mb-3"
required>

<label>End</label>

<input
type="time"
name="end_time"
class="form-control"
required>

</div>

<div class="modal-footer">

<button class="btn btn-primary">
Save
</button>

</div>

</form>

</div>

</div>

</div>

<!-- Rest Day (specific date) Modal -->
<div class="modal fade" id="dayoff<?= $d['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
<?= csrfField() ?>
                <input type="hidden" name="action" value="dayoff_add">
                <input type="hidden" name="dentist_id" value="<?= $d['id'] ?>">
                <div class="modal-header">
                    <h5>Add Rest Day - Dr. <?= e(preg_replace('/^Dr\.?\s+/i', '', $d['dentist_name'])) ?></h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" data-tip="Close"></button>
</div>
<div class="modal-body">
<label class="form-label">Rest date</label>
                    <input type="date" name="off_date" class="form-control mb-3" min="<?= date('Y-m-d') ?>" required>
                    <label class="form-label">Reason (optional)</label>
                    <input name="reason" class="form-control" placeholder="Reason">
                </div>
                <div class="modal-footer"><button class="btn btn-primary" data-tip="Save Rest Day">Save Rest Day</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Mark Absent Modal -->
<div class="modal fade" id="absentModal<?= $d['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px; overflow:hidden;">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="mark_absent">
                <input type="hidden" name="dentist_id" value="<?= (int)$d['id'] ?>">
                
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-bold" style="font-size:16px;">
                        <i class="bi bi-person-x-fill me-2"></i>Mark as Absent / Emergency Leave
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" data-tip="Close"></button>
                </div>
                
                <div class="modal-body p-4" style="background:#fff;">
                    <div class="p-3 mb-3 rounded-3" style="background:#FDF2F2; border:1px solid #F8D7DA;">
                        <div class="fw-bold" style="color:#B91C1C; font-size:14px;">
                            <i class="bi bi-person-badge-fill me-1"></i>Dr. <?= e(preg_replace('/^Dr\.?\s+/i', '', $d['dentist_name'])) ?>
                        </div>
                        <small class="text-muted"><?= e($d['specialization']) ?> &bull; Contact: <?= e($d['contact_number'] ?: 'Not provided') ?></small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">DATE OF ABSENCE / LEAVE</label>
                        <input type="date" name="off_date" class="form-control" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required>
                        <small class="text-muted" style="font-size:11.5px;">Default is set to Today (<?= date('M d, Y') ?>). You can pick a future date if advance notice was given.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">TYPE OF ABSENCE / EMERGENCY</label>
                        <select name="leave_type" class="form-select" required>
                            <option value="Medical Emergency / Sick Leave" selected>Medical Emergency / Sick Leave</option>
                            <option value="Personal / Family Emergency">Personal / Family Emergency</option>
                            <option value="Official Business / Dental Seminar">Official Business / Dental Seminar</option>
                            <option value="Unplanned Personal Leave">Unplanned Personal Leave</option>
                            <option value="Other Emergency">Other Emergency</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">ADMIN / RECEPTION NOTES (OPTIONAL)</label>
                        <input type="text" name="absence_notes" class="form-control" placeholder="e.g. Called reception via mobile phone at 7:30 AM with fever">
                    </div>

                    <div class="alert alert-warning py-2 px-3 small mb-0" style="border-radius:8px; font-size:12px;">
                        <i class="bi bi-exclamation-triangle-fill me-1 text-warning"></i>
                        <strong>Automatic Calendar Blocking:</strong> New patient bookings for this doctor will be locked on this date. If there are active appointments, the system will prompt you to reassign them to an available doctor.
                    </div>
                </div>

                <div class="modal-footer" style="background:#F8FAFC; border-top:1px solid #E2E8F0;">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger fw-bold" style="padding:8px 20px; border-radius:8px; box-shadow:0 4px 12px rgba(220,53,69,.3);">
                        <i class="bi bi-check2-circle me-1"></i>Confirm Absence
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endforeach; ?>

</div>

<!-- Dentist Modal -->

<div class="modal fade"
id="addDentist">

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">
<?= csrfField() ?>

<input
type="hidden"
name="action"
value="add">

<div class="modal-header">

<h5>Add Dentist</h5>

</div>

<div class="modal-body">

<label>Dentist Name</label>

<input
name="dentist_name"
class="form-control mb-3"
required>

<label>Specialization</label>

<input
name="specialization"
class="form-control mb-3">

<label>Contact Number</label>

<input
name="contact_number"
class="form-control mb-3">

<hr>
<p class="text-muted small mb-2"><i class="bi bi-person-lock me-1"></i>Dentist login account (optional — allows dentist to immediately log in to the portal).</p>

<label>Login Email</label>

<input
name="login_email"
type="email"
class="form-control mb-3"
placeholder="dentist@example.com">

<label>Login Password (min. 6)</label>

<input
name="login_password"
type="password"
class="form-control mb-3"
placeholder="Gumawa ng password"
minlength="6"
autocomplete="new-password">

<label>Rest Day (weekly)</label>

<select
name="rest_day"
class="form-select">

<option value="">None</option>
<option value="0">Sunday</option>
<option value="1">Monday</option>
<option value="2">Tuesday</option>
<option value="3">Wednesday</option>
<option value="4">Thursday</option>
<option value="5">Friday</option>
<option value="6">Saturday</option>

</select>

</div>

<div class="modal-footer">

<button class="btn btn-primary" data-tip="Save">
Save
</button>

</div>

</form>

</div>

</div>

</div>

<script src="../assets/bootstrap.bundle.min.js"></script>
<script>
if (typeof bootstrap === 'undefined') {
    document.write('<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"><\/script>');
}
</script>
<script>
/* Guaranteed modal trigger handler: ensures Add Rest Day, Mark as Absent, and other modals open smoothly even if CDN or Bootstrap data-api is delayed */
document.addEventListener('click', function(e) {
    var trigger = e.target.closest('[data-bs-toggle="modal"]');
    if (!trigger) return;
    var targetSel = trigger.getAttribute('data-bs-target') || trigger.getAttribute('href');
    if (!targetSel || !targetSel.startsWith('#')) return;
    var modalEl = document.querySelector(targetSel);
    if (!modalEl) return;

    e.preventDefault();
    if (window.bootstrap && window.bootstrap.Modal) {
        try {
            var m = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            m.show();
            return;
        } catch (err) {
            console.warn('Bootstrap modal error, using direct open fallback:', err);
        }
    }
    modalEl.classList.add('show');
    modalEl.style.display = 'block';
    modalEl.removeAttribute('aria-hidden');
    modalEl.setAttribute('aria-modal', 'true');
    document.body.classList.add('modal-open');
    if (!document.querySelector('.modal-backdrop')) {
        var bd = document.createElement('div');
        bd.className = 'modal-backdrop fade show';
        document.body.appendChild(bd);
    }
});

document.addEventListener('click', function(e) {
    var closeBtn = e.target.closest('[data-bs-dismiss="modal"]');
    if (!closeBtn) return;
    var modal = closeBtn.closest('.modal');
    if (!modal) return;
    e.preventDefault();
    if (window.bootstrap && window.bootstrap.Modal) {
        try {
            var m = bootstrap.Modal.getInstance(modal);
            if (m) { m.hide(); return; }
        } catch (err) {}
    }
    modal.classList.remove('show');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    modal.removeAttribute('aria-modal');
    document.body.classList.remove('modal-open');
    document.querySelectorAll('.modal-backdrop').forEach(function(b){ b.remove(); });
});
</script>

<!-- =========================================================
     DELETE DENTIST CONFIRM — same look as the patients modal
     ========================================================= -->
<div id="dentistDeleteConfirm" class="admin-logout-overlay" style="display:none;">
    <div class="admin-logout-box">
        <div class="admin-logout-head">
            <span class="admin-logout-head-icon"><i class="bi bi-trash"></i></span>
            <h5>Delete Dentist</h5>
            <button type="button" class="admin-logout-x" id="dentistDeleteClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="admin-logout-body">
            <div class="admin-logout-icon"><i class="bi bi-exclamation-circle-fill"></i></div>
            <h5>Delete this dentist?</h5>
            <p>You are about to delete <strong id="dentistDeleteName">this dentist</strong>. This also removes their schedules, appointments, and related records. This action cannot be undone.</p>
        </div>
        <div class="admin-logout-foot">
            <button type="button" class="btn admin-delete-btn-cancel" id="dentistDeleteCancel">Cancel</button>
            <button type="button" class="btn admin-delete-btn-danger" id="dentistDeleteYes"><i class="bi bi-trash me-1"></i>Yes, Delete</button>
        </div>
    </div>
</div>

<!-- =========================================================
     REMOVE REST DAY / LEAVE CONFIRM MODAL
     ========================================================= -->
<div id="dayoffDeleteConfirm" class="admin-logout-overlay" style="display:none;">
    <div class="admin-logout-box">
        <div class="admin-logout-head">
            <span class="admin-logout-head-icon" style="background:linear-gradient(135deg,#E74C3C,#922B21);"><i class="bi bi-trash3-fill"></i></span>
            <h5 id="dayoffDeleteHeadTitle">Remove Rest Day</h5>
            <button type="button" class="admin-logout-x" id="dayoffDeleteClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="admin-logout-body">
            <div class="admin-logout-icon" style="background:#FDECEC;color:#C0392B;border-color:#F5C6C6;">
                <i class="bi bi-calendar-x"></i>
            </div>
            <h5 id="dayoffDeleteSubTitle">Remove this schedule record?</h5>
            <p id="dayoffDeleteDesc">You are about to remove the rest day scheduled for <strong id="dayoffDeleteDate">this date</strong> for <strong id="dayoffDeleteDentist">Dr. Dentist</strong>. The dentist will be available for bookings again on this date.</p>
        </div>
        <div class="admin-logout-foot">
            <button type="button" class="btn admin-delete-btn-cancel" id="dayoffDeleteCancel">Cancel</button>
            <button type="button" class="btn admin-delete-btn-danger" id="dayoffDeleteYes"><i class="bi bi-trash me-1"></i>Yes, Remove</button>
        </div>
    </div>
</div>
<style>
.admin-logout-overlay { position: fixed; inset: 0; background: rgba(10,59,52,.55); z-index: 2000; padding: 20px; align-items: center; justify-content: center; }
.admin-logout-box { background: #ffffff; border-radius: 18px; border: 1px solid #C9E9DA; box-shadow: 0 18px 45px rgba(0,0,0,.25); max-width: 520px; width: 100%; overflow: hidden; font-family: "Inter", "DM Sans", system-ui, sans-serif; animation: adminLogoutPop .18s ease; }
@keyframes adminLogoutPop { from { transform: scale(.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.admin-logout-head { display: flex; align-items: center; gap: 12px; padding: 18px 22px; background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%); border-bottom: 1px solid #C9E9DA; }
.admin-logout-head-icon { width: 38px; height: 38px; border-radius: 11px; background: linear-gradient(135deg, #0E6B5E, #0A4A41); color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 4px 10px rgba(14,107,94,.25); flex-shrink: 0; }
.admin-logout-head h5 { color: #143f51; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; font-size: 17px; margin: 0; flex: 1; }
.admin-logout-x { background: #ffffff; border: 1px solid #D1E7DD; color: #57727d; width: 30px; height: 30px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; flex-shrink: 0; }
.admin-logout-x:hover { background: #EAF7F0; color: #0E6B5E; }
.admin-logout-body { padding: 34px 36px 8px; text-align: center; }
.admin-logout-icon { width: 72px; height: 72px; border-radius: 20px; background: #FDECEC; color: #C0392B; border: 1px solid #F5C6C6; display: inline-flex; align-items: center; justify-content: center; font-size: 34px; margin-bottom: 16px; }
.admin-logout-body h5 { color: #143f51; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; font-size: 18px; margin: 0 0 8px; }
.admin-logout-body p { color: #44585F; font-size: 14px; line-height: 1.65; font-weight: 500; margin: 0; }
.admin-logout-body p strong { color: #8E2018; font-weight: 800; }
.admin-logout-foot { display: flex; gap: 12px; justify-content: center; padding: 18px 22px 22px; background: #F6FBF8; border-top: 1px solid #E6F2EC; margin-top: 20px; }
.admin-logout-foot .btn { border-radius: 12px; font-weight: 800; font-size: 14.5px; padding: 12px 30px; letter-spacing: -.01em; transition: transform .15s ease, box-shadow .15s ease, filter .15s ease; }
.admin-logout-foot .btn:active { transform: translateY(1px); }

.admin-delete-btn-cancel { background: #ffffff !important; border: 1.5px solid #CFE3DA !important; color: #4A5F68 !important; box-shadow: 0 3px 8px rgba(10,74,65,.06); }
.admin-delete-btn-cancel:hover { background: linear-gradient(135deg, #EAF7F0 0%, #D9F2E6 100%) !important; border-color: #A8D5C4 !important; color: #0A4A41 !important; transform: translateY(-1px); box-shadow: 0 8px 18px rgba(10,74,65,.14) !important; }

.admin-delete-btn-danger { background: linear-gradient(135deg, #E74C3C 0%, #922B21 100%) !important; border: none !important; color: #ffffff !important; box-shadow: 0 6px 16px rgba(192,57,43,.32) !important; }
.admin-delete-btn-danger:hover { filter: brightness(.94); color: #ffffff !important; transform: translateY(-1px); box-shadow: 0 10px 22px rgba(192,57,43,.38) !important; }
.admin-delete-btn-danger:hover i.bi-trash { transform: rotate(-8deg) scale(1.12); }
.admin-delete-btn-danger i.bi-trash { display: inline-block; transition: transform .15s ease; }

.admin-delete-btn-danger.is-busy { pointer-events: none; opacity: .75; filter: saturate(.7); }
.admin-delete-btn-danger .spinner-border { width: 14px; height: 14px; border-width: 2px; border-top-color: #ffffff; }
</style>
<script>
(function()
{
    var pendingHref = null;

    function closeDentistDelete()
    {
        var overlay = document.getElementById("dentistDeleteConfirm");
        if (!overlay) return;
        overlay.style.display = "none";
        document.body.style.overflow = "";
        pendingHref = null;
    }

    function initDentistDeleteConfirm()
    {
        var overlay = document.getElementById("dentistDeleteConfirm");
        if (!overlay || overlay.dataset.bound === "1") return;
        overlay.dataset.bound = "1";

        var nameEl = document.getElementById("dentistDeleteName");

        document.querySelectorAll("a.js-delete-dentist-link").forEach(function(link)
        {
            link.addEventListener("click", function(e)
            {
                e.preventDefault();
                pendingHref = this.getAttribute("href");
                if (nameEl) {
                    nameEl.textContent = this.dataset.dentistName || "this dentist";
                }
                overlay.style.display = "flex";
                document.body.style.overflow = "hidden";
                var yes = document.getElementById("dentistDeleteYes");
                if (yes) {
                    yes.classList.remove("is-busy");
                    yes.innerHTML = '<i class="bi bi-trash me-1"></i>Yes, Delete';
                    yes.focus();
                }
            });
        });

        document.getElementById("dentistDeleteCancel").addEventListener("click", closeDentistDelete);
        document.getElementById("dentistDeleteClose").addEventListener("click", closeDentistDelete);
        overlay.addEventListener("click", function(e)
        {
            if (e.target === overlay) closeDentistDelete();
        });
        document.addEventListener("keydown", function(e)
        {
            if (overlay.style.display === "none") return;
            if (e.key === "Escape") closeDentistDelete();
        });
        document.getElementById("dentistDeleteYes").addEventListener("click", function()
        {
            if (!pendingHref) { closeDentistDelete(); return; }
            var href = pendingHref;
            pendingHref = null;

            this.classList.add("is-busy");
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Deleting…';

            window.location.href = href;
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initDentistDeleteConfirm);
    } else {
        initDentistDeleteConfirm();
    }
})();

(function()
{
    var pendingForm = null;

    function closeDayoffDelete()
    {
        var overlay = document.getElementById("dayoffDeleteConfirm");
        if (!overlay) return;
        overlay.style.display = "none";
        document.body.style.overflow = "";
        pendingForm = null;
    }

    function initDayoffDeleteConfirm()
    {
        var overlay = document.getElementById("dayoffDeleteConfirm");
        if (!overlay || overlay.dataset.bound === "1") return;
        overlay.dataset.bound = "1";

        var dateEl = document.getElementById("dayoffDeleteDate");
        var dentistEl = document.getElementById("dayoffDeleteDentist");
        var headTitleEl = document.getElementById("dayoffDeleteHeadTitle");
        var subTitleEl = document.getElementById("dayoffDeleteSubTitle");
        var descEl = document.getElementById("dayoffDeleteDesc");

        document.querySelectorAll(".js-dayoff-delete-trigger").forEach(function(btn)
        {
            btn.addEventListener("click", function(e)
            {
                e.preventDefault();
                pendingForm = this.closest("form");
                if (!pendingForm) return;

                var dDate = pendingForm.dataset.date || "this date";
                var dName = pendingForm.dataset.dentist || "this dentist";
                var dType = pendingForm.dataset.type || "restday";

                if (dateEl) dateEl.textContent = dDate;
                if (dentistEl) dentistEl.textContent = "Dr. " + dName;

                if (dType === "absence") {
                    if (headTitleEl) headTitleEl.textContent = "Remove Absence Record";
                    if (subTitleEl) subTitleEl.textContent = "Cancel absence / leave?";
                    if (descEl) descEl.innerHTML = 'You are about to remove the marked absence on <strong>' + dDate + '</strong> for <strong>Dr. ' + dName + '</strong>. Regular appointment slots will be reopened.';
                } else if (dType === "schedule") {
                    if (headTitleEl) headTitleEl.textContent = "Remove Custom Availability";
                    if (subTitleEl) subTitleEl.textContent = "Remove custom shift schedule?";
                    if (descEl) descEl.innerHTML = 'You are about to remove the custom shift on <strong>' + dDate + '</strong> for <strong>Dr. ' + dName + '</strong>.';
                } else {
                    if (headTitleEl) headTitleEl.textContent = "Remove Rest Day";
                    if (subTitleEl) subTitleEl.textContent = "Remove this rest day?";
                    if (descEl) descEl.innerHTML = 'You are about to remove the rest day scheduled for <strong>' + dDate + '</strong> for <strong>Dr. ' + dName + '</strong>. The dentist will be available for bookings again on this date.';
                }

                overlay.style.display = "flex";
                document.body.style.overflow = "hidden";
                var yes = document.getElementById("dayoffDeleteYes");
                if (yes) {
                    yes.classList.remove("is-busy");
                    yes.innerHTML = '<i class="bi bi-trash me-1"></i>Yes, Remove';
                    yes.focus();
                }
            });
        });

        document.getElementById("dayoffDeleteCancel").addEventListener("click", closeDayoffDelete);
        document.getElementById("dayoffDeleteClose").addEventListener("click", closeDayoffDelete);
        overlay.addEventListener("click", function(e)
        {
            if (e.target === overlay) closeDayoffDelete();
        });
        document.addEventListener("keydown", function(e)
        {
            if (overlay.style.display === "none") return;
            if (e.key === "Escape") closeDayoffDelete();
        });

        document.getElementById("dayoffDeleteYes").addEventListener("click", function()
        {
            if (!pendingForm) { closeDayoffDelete(); return; }
            var f = pendingForm;
            pendingForm = null;

            this.classList.add("is-busy");
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Removing…';

            f.submit();
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initDayoffDeleteConfirm);
    } else {
        initDayoffDeleteConfirm();
    }
})();
</script>

<!-- =========================================================
     DEACTIVATE / ACTIVATE DENTIST CONFIRM
     ========================================================= -->
<div id="dentistStatusConfirm" class="admin-logout-overlay" style="display:none;">
    <div class="admin-logout-box">
        <div class="admin-logout-head">
            <span class="admin-logout-head-icon" id="dentistStatusHeadIcon"><i class="bi bi-power"></i></span>
            <h5 id="dentistStatusHeadTitle">Update Dentist Status</h5>
            <button type="button" class="admin-logout-x" id="dentistStatusClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="admin-logout-body">
            <div class="admin-logout-icon" id="dentistStatusIcon"><i class="bi bi-exclamation-circle-fill"></i></div>
            <h5 id="dentistStatusQuestion">Are you sure?</h5>
            <p id="dentistStatusMessage">This will update the dentist's account status.</p>
        </div>
        <div class="admin-logout-foot">
            <button type="button" class="btn admin-delete-btn-cancel" id="dentistStatusCancel">Cancel</button>
            <button type="button" class="btn admin-delete-btn-danger" id="dentistStatusYes"><i class="bi bi-power me-1"></i>Yes</button>
        </div>
    </div>
</div>
<style>
/* Activate variant — green instead of red */
#dentistStatusConfirm.is-activate .admin-logout-body { color: #1B7A4B; }
#dentistStatusConfirm.is-activate .admin-logout-icon { background: #E7F8EF; color: #15945A; border-color: #B9E8CD; }
#dentistStatusConfirm.is-activate .admin-logout-body h5 { color: #12603C; }
#dentistStatusConfirm.is-activate #dentistStatusYes { background: linear-gradient(135deg, #27AE60 0%, #14663C 100%) !important; box-shadow: 0 6px 16px rgba(21,148,90,.32) !important; }
</style>
<script>
(function()
{
    var pendingForm = null;

    function closeDentistStatus()
    {
        var overlay = document.getElementById("dentistStatusConfirm");
        if (!overlay) return;
        overlay.style.display = "none";
        overlay.classList.remove("is-activate");
        document.body.style.overflow = "";
        pendingForm = null;
    }

    function initDentistStatusConfirm()
    {
        var overlay = document.getElementById("dentistStatusConfirm");
        if (!overlay || overlay.dataset.bound === "1") return;
        overlay.dataset.bound = "1";

        var headIcon = document.getElementById("dentistStatusHeadIcon");
        var headTitle = document.getElementById("dentistStatusHeadTitle");
        var question = document.getElementById("dentistStatusQuestion");
        var message = document.getElementById("dentistStatusMessage");
        var yesBtn = document.getElementById("dentistStatusYes");

        document.querySelectorAll("form.js-dentist-status-form").forEach(function(form)
        {
            form.addEventListener("submit", function(e)
            {
                e.preventDefault();

                var name = this.dataset.dentistName || "this dentist";
                var isActive = (this.dataset.currentStatus || "").toLowerCase() === "active";

                overlay.classList.toggle("is-activate", !isActive);

                if (headTitle) headTitle.textContent = isActive ? "Deactivate Dentist" : "Activate Dentist";
                if (headIcon) headIcon.innerHTML = '<i class="bi bi-power"></i>';
                if (question) question.textContent = isActive ? "Deactivate this dentist?" : "Activate this dentist?";

                if (message) {
                    message.innerHTML = isActive
                        ? 'You are about to deactivate <strong>' + name + '</strong>. They will no longer be available for booking new appointments. Their schedules and patient records will be kept.'
                        : 'You are about to activate <strong>' + name + '</strong>. They will be able to accept bookings again and appear on the schedule list.';
                }

                if (yesBtn) {
                    yesBtn.classList.remove("is-busy");
                    yesBtn.innerHTML = isActive
                        ? '<i class="bi bi-power me-1"></i>Yes, Deactivate'
                        : '<i class="bi bi-power me-1"></i>Yes, Activate';
                }

                pendingForm = this;

                overlay.style.display = "flex";
                document.body.style.overflow = "hidden";
                if (yesBtn) yesBtn.focus();
            });
        });

        document.getElementById("dentistStatusCancel").addEventListener("click", closeDentistStatus);
        document.getElementById("dentistStatusClose").addEventListener("click", closeDentistStatus);
        overlay.addEventListener("click", function(e)
        {
            if (e.target === overlay) closeDentistStatus();
        });
        document.addEventListener("keydown", function(e)
        {
            if (overlay.style.display === "none") return;
            if (e.key === "Escape") closeDentistStatus();
        });

        yesBtn.addEventListener("click", function()
        {
            if (!pendingForm) { closeDentistStatus(); return; }
            var form = pendingForm;
            pendingForm = null;

            this.classList.add("is-busy");
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Updating…';

            form.submit();
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initDentistStatusConfirm);
    } else {
        initDentistStatusConfirm();
    }
})();
</script>

</body>

</html>