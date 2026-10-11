<?php

require_once __DIR__ . '/../config.php';

if (
    !isset($_SESSION['user_id']) ||
    strtolower(trim($_SESSION['role'] ?? '')) !== 'patient'
) {
    header('Location: ../login.php');
    exit;
}

function patientProfileEscape($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$userId = (int) $_SESSION['user_id'];

$success = '';
$error = '';

/* =========================
   GET USER
========================= */

$stmt = $pdo->prepare("
    SELECT *
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {

    session_destroy();

    header('Location: ../login.php');

    exit;
}

/* =========================
   GET PATIENT
========================= */

$stmt = $pdo->prepare("
    SELECT *
    FROM patients
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {

    $parts = preg_split(
        '/\s+/',
        trim(
            $user['full_name']
            ?? 'Patient'
        )
    );

    $firstName =
        $parts[0] ?? 'Patient';

    $lastName =
        count($parts) > 1
            ? implode(
                ' ',
                array_slice(
                    $parts,
                    1
                )
            )
            : '';

    $patientNumber =
        'PAT-' .
        date('Ymd') .
        '-' .
        strtoupper(
            substr(
                md5(
                    uniqid(
                        '',
                        true
                    )
                ),
                0,
                5
            )
        );

    $stmt = $pdo->prepare("
        INSERT INTO patients
        (
            user_id,
            patient_number,
            first_name,
            last_name,
            email
        )
        VALUES
        (?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $userId,
        $patientNumber,
        $firstName,
        $lastName,
        $user['email'] ?? ''
    ]);

    $patientId =
        (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare("
        SELECT *
        FROM patients
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $patientId
    ]);

    $patient =
        $stmt->fetch(PDO::FETCH_ASSOC);
}

$patientId =
    (int) $patient['id'];

/* =========================
   PROFILE PHOTO (file-based, no DB change)
   Stored as: uploads/profile_photos/patient_{userId}.jpg|png|webp
========================= */

function patientProfilePhotoDir()
{
    return __DIR__ . '/../uploads/profile_photos';
}

function patientProfilePhotoUrl($userId)
{
    $dir = patientProfilePhotoDir();
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    foreach ($allowed as $ext) {
        $file = $dir . '/patient_' . (int) $userId . '.' . $ext;

        if (is_file($file)) {
            return '../uploads/profile_photos/patient_'
                . (int) $userId . '.' . $ext
                . '?v=' . filemtime($file);
        }
    }

    return '';
}

/* =========================
   UPDATE PROFILE
========================= */


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

    $action =
        $_POST['action'] ?? '';

    /* =========================
       PROFILE
    ========================= */

    if ($action === 'update_profile') {

        $firstName =
            trim(
                $_POST['first_name']
                ?? ''
            );

        $lastName =
            trim(
                $_POST['last_name']
                ?? ''
            );

        $email =
            trim(
                $_POST['email']
                ?? ''
            );

        $contact =
            trim(
                $_POST['contact_number']
                ?? ''
            );

        $address =
            trim(
                $_POST['address']
                ?? ''
            );

        $birthDate =
            trim(
                $_POST['birth_date']
                ?? ''
            );

        $gender =
            trim(
                $_POST['gender']
                ?? ''
            );

        if (
            $firstName === '' ||
            $lastName === ''
        ) {

            $error =
                'First name and last name are required.';

        } elseif (
            $email !== '' &&
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $error =
                'Please enter a valid email address.';

        } else {

            try {

                /* =========================
                   UPDATE PATIENT
                ========================= */

                $stmt = $pdo->prepare("
                    UPDATE patients
                    SET
                        first_name = ?,
                        last_name = ?,
                        email = ?,
                        contact_number = ?,
                        address = ?,
                        birth_date = NULLIF(?, ''),
                        gender = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $firstName,
                    $lastName,
                    $email,
                    $contact,
                    $address,
                    $birthDate,
                    $gender,
                    $patientId
                ]);

                /* =========================
                   UPDATE USERS
                ========================= */

                $newFullName =
                    trim(
                        $firstName .
                        ' ' .
                        $lastName
                    );

                try {

                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET
                            full_name = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $newFullName,
                        $userId
                    ]);

                } catch (PDOException $e) {

                    /*
                     * Continue because patient
                     * table was already updated.
                     */
                }

                $_SESSION['full_name'] =
                    $newFullName;

                $_SESSION['user_name'] =
                    $newFullName;

                $success =
                    'Your profile has been updated successfully.';

            } catch (PDOException $e) {

                $error =
                    'Unable to update your profile. Please check your database structure.';
            }
        }
    }

    /* =========================
       CHANGE PASSWORD
    ========================= */

    if ($action === 'change_password') {

        $currentPassword =
            $_POST['current_password']
            ?? '';

        $newPassword =
            $_POST['new_password']
            ?? '';

        $confirmPassword =
            $_POST['confirm_password']
            ?? '';

        if (
            $currentPassword === '' ||
            $newPassword === '' ||
            $confirmPassword === ''
        ) {

            $error =
                'Please complete all password fields.';

        } elseif (
            strlen($newPassword) < 6
        ) {

            $error =
                'New password must contain at least 6 characters.';

        } elseif (
            $newPassword !== $confirmPassword
        ) {

            $error =
                'New password and confirmation password do not match.';

        } else {

            $passwordColumn =
                'password';

            if (
                !isset(
                    $user['password']
                )
            ) {

                $passwordColumn =
                    'password_hash';
            }

            $storedPassword =
                $user[$passwordColumn]
                ?? '';

            if (
                password_verify(
                    $currentPassword,
                    $storedPassword
                )
            ) {

                $hashedPassword =
                    password_hash(
                        $newPassword,
                        PASSWORD_DEFAULT
                    );

                try {

                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET `$passwordColumn` = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $hashedPassword,
                        $userId
                    ]);

                    $success =
                        'Your password has been changed successfully.';

                } catch (PDOException $e) {

                    $error =
                        'Unable to change password.';
                }

            } else {

                $error =
                    'Current password is incorrect.';
            }
        }
    }

    /* =========================
       UPLOAD PHOTO
       action=upload_photo, input name=profile_photo
       JPG/PNG/WEBP only, max 2MB.
    ========================= */

    if ($action === 'upload_photo') {

        if (!isset($_FILES['profile_photo'])) {
            $error = 'Please choose a photo to upload.';
        } else {
            $photo = $_FILES['profile_photo'];

            if (($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'Photo upload failed. Please try again.';
            } elseif (($photo['size'] ?? 0) <= 0) {
                $error = 'The selected photo is empty.';
            } elseif (($photo['size'] ?? 0) > 2 * 1024 * 1024) {
                $error = 'Photo must be 2MB or smaller.';
            } else {
                $check = @getimagesize($photo['tmp_name']);

                $allowedMimes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];

                if ($check === false || !isset($allowedMimes[$check['mime'] ?? ''])) {
                    $error = 'Only JPG, PNG, or WEBP photos are allowed.';
                } else {
                    $ext = $allowedMimes[$check['mime']];
                    $photoDir = patientProfilePhotoDir();

                    if (!is_dir($photoDir)) {
                        @mkdir($photoDir, 0755, true);
                    }

                    if (!is_dir($photoDir)) {
                        $error = 'Unable to save photo. Upload folder is missing.';
                    } else {
                        foreach (['jpg', 'jpeg', 'png', 'webp'] as $oldExt) {
                            $oldFile = $photoDir . '/patient_' . $userId . '.' . $oldExt;

                            if (is_file($oldFile)) {
                                @unlink($oldFile);
                            }
                        }

                        $target = $photoDir . '/patient_' . $userId . '.' . $ext;

                        if (@move_uploaded_file($photo['tmp_name'], $target)) {
                            $success = 'Profile photo updated successfully.';
                        } else {
                            $error = 'Unable to save photo. Please try again.';
                        }
                    }
                }
            }
        }
    }

    /* =========================
       REMOVE PHOTO
    ========================= */

    if ($action === 'remove_photo') {
        $photoDir = patientProfilePhotoDir();
        $removed = false;

        foreach (['jpg', 'jpeg', 'png', 'webp'] as $oldExt) {
            $oldFile = $photoDir . '/patient_' . $userId . '.' . $oldExt;

            if (is_file($oldFile) && @unlink($oldFile)) {
                $removed = true;
            }
        }

        if ($removed) {
            $success = 'Profile photo removed.';
        } else {
            $error = 'No profile photo to remove.';
        }
    }

    /* =========================
       REFRESH
    ========================= */

    $stmt = $pdo->prepare("
        SELECT *
        FROM patients
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $patientId
    ]);

    $patient =
        $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $userId
    ]);

    $user =
        $stmt->fetch(PDO::FETCH_ASSOC);
}

$profilePhotoUrl = patientProfilePhotoUrl($userId);

$fullName =
    trim(
        ($patient['first_name'] ?? '') .
        ' ' .
        ($patient['last_name'] ?? '')
    );

if ($fullName === '') {
    $fullName =
        $user['full_name']
        ?? 'Patient';
}

$email =
    $patient['email']
    ?? ($user['email'] ?? '');

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
name="viewport"
content="width=device-width, initial-scale=1.0"
>

<title>My Profile | Abalos Dental</title>

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

<h1 class="page-heading">
My Profile
</h1>

<p class="page-small">
Manage your personal account information
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

<?php if ($success): ?>

<div class="alert alert-success">
<?= patientProfileEscape($success) ?>
</div>

<?php endif; ?>

<?php if ($error): ?>

<div class="alert alert-danger">
<?= patientProfileEscape($error) ?>
</div>

<?php endif; ?>

<!-- PROFILE HEADER -->

<div class="profile-header-card">

<div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">

<div class="d-flex align-items-center gap-3">

<div class="large-profile-circle" style="overflow:hidden;">
<?php if ($profilePhotoUrl !== ''): ?>
<img src="<?= patientProfileEscape($profilePhotoUrl) ?>" alt="Profile photo" style="width:100%;height:100%;object-fit:cover;display:block;">
<?php else: ?>
<i class="bi bi-person-fill"></i>
<?php endif; ?>
</div>

<div>

<div class="profile-info-name">
<?= patientProfileEscape($fullName) ?>
</div>

<div class="profile-info-email">
<?= patientProfileEscape($email) ?>
</div>

</div>

</div>

<div class="d-flex align-items-center gap-2">
<form method="POST" enctype="multipart/form-data" class="d-inline">
<?= csrfField() ?>
<input type="hidden" name="action" value="upload_photo">
<input type="file" name="profile_photo" id="patientProfilePhotoInput" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="this.form.submit()">
<button type="button" class="btn btn-light btn-sm" onclick="document.getElementById('patientProfilePhotoInput').click()">
<i class="bi bi-camera-fill me-1"></i><?= $profilePhotoUrl !== '' ? 'Change Photo' : 'Add Photo' ?>
</button>
</form>
<?php if ($profilePhotoUrl !== ''): ?>
<button type="button" class="btn btn-outline-light btn-sm" data-bs-toggle="modal" data-bs-target="#removePhotoModal">
<i class="bi bi-trash me-1"></i>Remove
</button>
<?php endif; ?>
</div>

</div>

</div>

<!-- PERSONAL INFORMATION -->

<style>
/* Personal Information — premium green design (profile page lang) */
.pi-card{background:#ffffff;border:1px solid #C9E9DA;border-radius:16px;box-shadow:0 8px 24px rgba(14,107,94,.08);overflow:hidden;padding:0!important;}
.pi-card-header{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:18px 22px;background:linear-gradient(135deg,#D9F2E6 0%,#EAF7F0 100%);border-bottom:1px solid #C9E9DA;}
.pi-card-header h3{font-size:17px!important;font-family:"Plus Jakarta Sans","Inter",system-ui,sans-serif;font-weight:800;color:#143f51;margin:0!important;display:flex;align-items:center;gap:8px;}
.pi-card-header h3 i{color:#0E6B5E;font-size:18px;}
.pi-card-header small{display:block;font-size:11.5px;color:#5b757f;font-weight:600;}
.pi-body{padding:22px;}
.pi-section{display:flex;align-items:center;gap:7px;font-size:11.5px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;color:#0E6B5E;margin:20px 0 12px;}
.pi-section:first-child{margin-top:0;}
.pi-section i{font-size:15px;}
.pi-card .form-label{font-size:12.5px;font-weight:700;color:#244f60;}
.pi-card .form-control,.pi-card .form-select{border:1.5px solid #C9E9DA;border-radius:10px;font-size:14px;color:#244f60;background:#fbfdfe;}
.pi-card .form-control:focus,.pi-card .form-select:focus{border-color:#0E6B5E;box-shadow:0 0 0 3px rgba(14,107,94,.12);background:#ffffff;}
.pi-save{background:linear-gradient(135deg,#149A72,#0A4A41)!important;border:1px solid rgba(201,162,39,.35)!important;border-radius:10px!important;padding:10px 22px!important;font-weight:800!important;box-shadow:0 8px 20px rgba(14,107,94,.22)!important;}
.pi-save:hover{background:linear-gradient(135deg,#0E6B5E,#083A32)!important;transform:translateY(-1px);}
</style>

<div class="patient-card mb-4 pi-card">

<div class="pi-card-header">
<h3>
<i class="bi bi-person-vcard-fill"></i> Personal Information
</h3>
<small>Keep your details up to date</small>
</div>

<div class="pi-body">

<form method="POST">
<?= csrfField() ?>

<input
type="hidden"
name="action"
value="update_profile"
>

<div class="pi-section"><i class="bi bi-person-fill"></i>Name</div>

<div class="pi-section"><i class="bi bi-telephone-fill"></i>Contact</div>

<div class="row g-3">

<div class="col-md-6">

<label class="form-label">
Email
</label>

<input
type="text"
name="first_name"
class="form-control"
value="<?= patientProfileEscape(
$patient['first_name']
) ?>"
required
>

</div>

<div class="col-md-6">

<label class="form-label">
Last Name
</label>

<input
type="text"
name="last_name"
class="form-control"
value="<?= patientProfileEscape(
$patient['last_name']
) ?>"
required
>

</div>

<div class="col-md-6">

<label class="form-label">
Email
</label>

<input
type="email"
name="email"
class="form-control"
value="<?= patientProfileEscape(
$email
) ?>"
>

</div>

<div class="col-md-6">

<label class="form-label">
Contact Number
</label>

<input
type="text"
name="contact_number"
class="form-control"
value="<?= patientProfileEscape(
$patient['contact_number']
) ?>"
>

</div>

<div class="col-md-6">

<label class="form-label">
Birth Date
</label>

<input
type="date"
name="birth_date"
class="form-control"
value="<?= patientProfileEscape(
$patient['birth_date']
) ?>"
>

</div>

<div class="col-md-6">

<label class="form-label">
Gender
</label>

<select
name="gender"
class="form-select"
>

<option value="">
Select Gender
</option>

<option
value="Male"
<?= ($patient['gender'] ?? '') === 'Male'
    ? 'selected'
    : '' ?>
>
Male
</option>

<option
value="Female"
<?= ($patient['gender'] ?? '') === 'Female'
    ? 'selected'
    : '' ?>
>
Female
</option>

<option
value="Other"
<?= ($patient['gender'] ?? '') === 'Other'
    ? 'selected'
    : '' ?>
>
Other
</option>

</select>

</div>

<div class="col-12">

<label class="form-label">
Address
</label>

<textarea
name="address"
class="form-control"
rows="3"
><?= patientProfileEscape(
$patient['address']
) ?></textarea>

</div>

<div class="col-12">

<button
type="submit"
class="btn btn-primary"
>

<i class="bi bi-save me-1"></i>

Save Profile

</button>

</div>

</div>

</form>

</div>

<!-- Dental clinic profile - Change Password removed (karaniwang profile lang: Personal Information) -->

</div>

</main>

</div>

<!-- REMOVE PHOTO REMINDER — gaya ng logout confirm modal -->
<div class="modal fade" id="removePhotoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 600px; width: 94%;">
    <div class="modal-content patient-logout-modal-content">
      <div class="modal-header patient-logout-modal-header">
        <div class="patient-logout-icon-wrap">
          <i class="bi bi-trash"></i>
        </div>
        <div>
          <h5 class="modal-title patient-logout-title">Remove Photo</h5>
          <small class="patient-logout-subtitle">Abalos Dental — Patient Portal</small>
        </div>
        <button type="button" class="btn-close patient-logout-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body patient-logout-body">
        <p class="patient-logout-message">
          Are you sure you want to remove your profile photo?
        </p>
        <p class="patient-logout-hint">
          Your profile will use the default avatar after removal.
        </p>
      </div>
      <div class="modal-footer patient-logout-footer">
        <button type="button" class="btn patient-logout-cancel" data-bs-dismiss="modal">
          <i class="bi bi-x-lg me-1"></i> Cancel
        </button>
        <form method="POST" class="d-inline m-0">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="remove_photo">
          <button type="submit" class="btn patient-logout-confirm">
            <i class="bi bi-trash me-1"></i> Yes, Remove
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>