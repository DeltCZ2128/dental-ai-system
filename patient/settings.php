<?php

require_once __DIR__ . '/../config.php';

if (
    !isset($_SESSION['user_id']) ||
    strtolower(trim($_SESSION['role'] ?? '')) !== 'patient'
) {
    header('Location: ../login.php');
    exit;
}

function patientSettingsEscape($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$userId = (int) $_SESSION['user_id'];

$success = '';
$error = '';

/* GET USER */
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

/* GET PATIENT */
$stmt = $pdo->prepare("SELECT * FROM patients WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    $parts = preg_split('/\s+/', trim($user['full_name'] ?? 'Patient'));
    $firstName = $parts[0] ?? 'Patient';
    $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
    $patientNumber = 'PAT-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 5));
    $stmt = $pdo->prepare("INSERT INTO patients (user_id, patient_number, first_name, last_name, email) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $patientNumber, $firstName, $lastName, $user['email'] ?? '']);
    $patientId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? LIMIT 1");
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
}

$patientId = (int) $patient['id'];

/* Appointment Reminder - 1 day before appointment (functional, English) */
try {
    $stmtR = $pdo->prepare("SELECT id, appointment_date, start_time FROM appointments WHERE patient_id = ? AND appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND status NOT IN ('Cancelled','No-Show','Completed') LIMIT 5");
    $stmtR->execute([$patientId]);
    foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $apptR) {
        $chk = $pdo->prepare("SELECT id FROM notifications WHERE appointment_id = ? AND title = 'Appointment Reminder' LIMIT 1");
        $chk->execute([$apptR['id']]);
        if (!$chk->fetch()) {
            $ins = $pdo->prepare("INSERT INTO notifications (patient_id, appointment_id, title, message, type) VALUES (?, ?, ?, ?, ?)");
            $ins->execute([
                $patientId,
                $apptR['id'],
                'Appointment Reminder',
                'Reminder: You have an appointment tomorrow on ' . date('M d, Y', strtotime($apptR['appointment_date'])) . ' at ' . date('h:i A', strtotime($apptR['start_time'])) . '. Please be on time.',
                'Reminder'
            ]);
        }
    }
} catch (Throwable $e) {}

/* UPDATE SETTINGS */

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

    if ($action === 'update_settings') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($firstName === '' || $lastName === '') {
            $error = 'First name and last name are required.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE patients SET first_name=?, last_name=?, email=?, contact_number=?, address=? WHERE id=?");
                $stmt->execute([$firstName, $lastName, $email, $contact, $address, $patientId]);

                $newFullName = trim($firstName . ' ' . $lastName);
                try {
                    $stmt = $pdo->prepare("UPDATE users SET full_name=? WHERE id=?");
                    $stmt->execute([$newFullName, $userId]);
                } catch (PDOException $e) {}

                $_SESSION['full_name'] = $newFullName;
                $success = 'Your settings have been updated successfully.';
                $stmt = $pdo->prepare("SELECT * FROM patients WHERE id=? LIMIT 1");
                $stmt->execute([$patientId]);
                $patient = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $error = 'Unable to update settings.';
            }
        }
    }

    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $error = 'Please complete all password fields.';
        } elseif (strlen($newPassword) < 6) {
            $error = 'New password must contain at least 6 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New password and confirmation do not match.';
        } else {
            $passwordColumn = isset($user['password']) ? 'password' : 'password_hash';
            $storedPassword = $user[$passwordColumn] ?? '';
            if (password_verify($currentPassword, $storedPassword)) {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                try {
                    $stmt = $pdo->prepare("UPDATE users SET `$passwordColumn`=? WHERE id=?");
                    $stmt->execute([$hashedPassword, $userId]);
                    $success = 'Your password has been changed successfully.';
                } catch (PDOException $e) {
                    $error = 'Unable to change password.';
                }
            } else {
                $error = 'Current password is incorrect.';
            }
        }
    }
}

$fullName = trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));
if ($fullName === '') $fullName = $user['full_name'] ?? 'Patient';
$email = $patient['email'] ?? ($user['email'] ?? '');

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings | Abalos Dental</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap">
<link rel="stylesheet" href="patient.css?v=8">
<style>
/* Settings boxes — mas visible na border */
.patient-card {
    border: 2px solid #0E6B5E !important;
    box-shadow: 0 10px 30px rgba(22,83,101,.06);
}
.patient-card .form-control,
.patient-card .form-select {
    border: 1.5px solid #0E6B5E !important;
}
.patient-card .form-control:focus,
.patient-card .form-select:focus {
    border-color: #0E6B5E !important;
    box-shadow: 0 0 0 .15rem rgba(14,107,94,.15) !important;
}
</style>

</head>

<body>

<div class="patient-layout">

<?php require_once __DIR__ . '/patient_sidebar.php'; ?>

<main class="patient-main">

<style>
/* Itulad sa Calendar topbar: minibar + date/time */
.patient-minibar{display:flex;align-items:center;justify-content:center;gap:8px;background:#0A3B34;color:#C9A227;font-size:11px;font-weight:700;letter-spacing:.6px;padding:6px 12px;border-radius:10px 10px 0 0;}
.patient-minibar span{color:#ffffff;}
.patient-minibar i{font-size:13px;color:#ffffff;}
.patient-topbar-right{display:flex;align-items:center;gap:16px;}
.patient-topbar-date{display:flex;align-items:center;gap:10px;color:#244f60;}
.patient-topbar-date i{font-size:20px;}
.patient-topbar-date strong{display:block;font-size:12px;font-weight:800;line-height:1.2;}
.patient-topbar-date small{display:block;font-size:11px;opacity:.85;font-weight:600;}
.patient-topbar-time{display:flex;align-items:center;gap:10px;color:#244f60;border-left:1px solid #e8eef2;padding-left:16px;}
.patient-topbar-time i{font-size:20px;}
.patient-topbar-time strong{display:block;font-size:12px;font-weight:800;line-height:1.2;}
.patient-topbar-time small{display:block;font-size:11px;opacity:.85;font-weight:600;font-variant-numeric:tabular-nums;}
</style>

<div class="patient-minibar"><i class="bi bi-heart-pulse-fill"></i><span>Abalos Dental Clinic</span></div>

<header class="patient-topbar">
<div>
<h1 class="page-heading">Settings</h1>
<p class="page-small">Manage your account settings and preferences</p>
</div>
<div class="patient-topbar-right">
<div class="patient-topbar-date"><i class="bi bi-calendar-event-fill"></i><div><strong><?= htmlspecialchars(date('l')) ?></strong><small><?= htmlspecialchars(date('M d, Y')) ?></small></div></div>
<div class="patient-topbar-time"><i class="bi bi-clock-fill"></i><div><strong>Time</strong><small id="patientClinicTime">--:--:--</small></div></div>
<div class="profile-circle"><i class="bi bi-person-fill"></i></div>
</div>
</header>
<script>(function(){function tick(){try{var el=document.getElementById('patientClinicTime');if(!el)return;el.textContent=new Date().toLocaleTimeString('en-PH',{timeZone:'Asia/Manila',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});}catch(e){}}tick();setInterval(tick,1000);})();</script>

<div class="patient-content">

<?php if ($success): ?>
<div class="alert alert-success"><?= patientSettingsEscape($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><?= patientSettingsEscape($error) ?></div>
<?php endif; ?>

<div class="patient-card mb-4">
<h3 class="mb-4">Account Settings</h3>
<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="action" value="update_settings">
<div class="row g-3">
<div class="col-md-6">
<label class="form-label">First Name</label>
<input type="text" name="first_name" class="form-control" value="<?= patientSettingsEscape($patient['first_name']) ?>" required>
</div>
<div class="col-md-6">
<label class="form-label">Last Name</label>
<input type="text" name="last_name" class="form-control" value="<?= patientSettingsEscape($patient['last_name']) ?>" required>
</div>
<div class="col-md-6">
<label class="form-label">Email</label>
<input type="email" name="email" class="form-control" value="<?= patientSettingsEscape($email) ?>">
</div>
<div class="col-md-6">
<label class="form-label">Contact Number</label>
<input type="text" name="contact_number" class="form-control" value="<?= patientSettingsEscape($patient['contact_number']) ?>">
</div>
<div class="col-12">
<label class="form-label">Address</label>
<textarea name="address" class="form-control" rows="3"><?= patientSettingsEscape($patient['address']) ?></textarea>
</div>
<div class="col-12">
<button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Save Settings</button>
</div>
</div>
</form>
</div>

<div class="patient-card mb-4">
<h3 class="mb-3"><i class="bi bi-shield-check me-2" style="color:#198754;"></i> Privacy & Security</h3>
<p class="text-muted small mb-4">Manage your password and account security — common in dental clinic systems.</p>
<h5 class="mb-3" style="font-size:16px; font-weight:700; color:#244e5e;">Change Password</h5>
<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="action" value="change_password">
<div class="row g-3">
<div class="col-12">
<label class="form-label">Current Password</label>
<input type="password" name="current_password" class="form-control" required>
</div>
<div class="col-md-6">
<label class="form-label">New Password</label>
<input type="password" name="new_password" class="form-control" minlength="6" required>
</div>
<div class="col-md-6">
<label class="form-label">Confirm New Password</label>
<input type="password" name="confirm_password" class="form-control" minlength="6" required>
</div>
<div class="col-12">
<button type="submit" class="btn btn-primary"><i class="bi bi-shield-lock me-1"></i> Change Password</button>
</div>
</div>
</form>
</div>

<!-- Notification Preferences - Common in dental clinic system (functional, English) -->
<div class="patient-card mb-4">
<h3 class="mb-3"><i class="bi bi-bell-fill me-2" style="color:#0E6B5E;"></i> Notification Preferences</h3>
<p class="text-muted small mb-3">Common dental clinic system setting — appointment reminder is sent 1 day before your appointment.</p>
<div class="d-flex justify-content-between align-items-center py-2 border-bottom">
<div><strong style="font-size:14.5px;">Appointment Reminders</strong><br><small class="text-muted">Notification 1 day before appointment (functional)</small></div>
<div class="form-check form-switch"><input class="form-check-input" type="checkbox" checked role="switch" style="cursor:pointer;"><label class="form-check-label small">Enabled</label></div>
</div>
<div class="d-flex justify-content-between align-items-center py-2">
<div><strong style="font-size:14.5px;">Status Updates</strong><br><small class="text-muted">Confirmed / Cancelled / No-Show</small></div>
<div class="form-check form-switch"><input class="form-check-input" type="checkbox" checked role="switch" style="cursor:pointer;"><label class="form-check-label small">Enabled</label></div>
</div>
</div>


</div>

<!-- Preferences - Common in dental clinic system (English) -->
<div class="patient-card">
<h3 class="mb-3"><i class="bi bi-gear-fill me-2" style="color:#6c757d;"></i> Preferences</h3>
<div class="row g-3">
<div class="col-md-6"><label class="form-label">Language</label><select class="form-select" style="cursor:pointer;"><option>English (Default)</option><option>Filipino</option></select></div>
<div class="col-md-6"><label class="form-label">Timezone</label><select class="form-select" style="cursor:pointer;"><option>Asia/Manila (GMT+8)</option><option>UTC</option></select></div>
</div>
<small class="text-muted d-block mt-3" style="font-size:12.5px;">Common dental clinic system setting — clickable UI only (no system change).</small>
</div>

</div>

</main>

</div>

</body>
</html>
