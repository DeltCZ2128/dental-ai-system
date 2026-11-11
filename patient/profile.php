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

/* Fallback by email if not linked by user_id */
if (!$patient && !empty($user['username']) && strpos((string)$user['username'], '@') !== false) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM patients
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([trim($user['username'])]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($patient && empty($patient['user_id'])) {
        $linkStmt = $pdo->prepare("UPDATE patients SET user_id = ? WHERE id = ?");
        $linkStmt->execute([$userId, (int)$patient['id']]);
        $patient['user_id'] = $userId;
    }
}

/* Auto-create patient record if none exists */
if (!$patient) {
    $parts = preg_split('/\s+/', trim($user['full_name'] ?? 'Patient'));
    $firstName = $parts[0] ?? 'Patient';
    $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
    $patientNumber = generateShortPatientNumber($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO patients (user_id, patient_number, first_name, last_name, email, contact_number)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $patientNumber,
        $firstName,
        $lastName,
        $user['email'] ?? ($user['username'] ?? ''),
        $user['contact_number'] ?? ''
    ]);

    $patientId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare("
        SELECT *
        FROM patients
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
}

$patientId = (int) $patient['id'];

/* Ensure patient has a valid patient_number */
if (empty($patient['patient_number'])) {
    $genPatientNumber = generateShortPatientNumber($pdo);
    try {
        $pUpd = $pdo->prepare("UPDATE patients SET patient_number = ? WHERE id = ?");
        $pUpd->execute([$genPatientNumber, $patientId]);
        $patient['patient_number'] = $genPatientNumber;
    } catch (Throwable $e) {}
}

/* =========================
   PROFILE PHOTO (file-based)
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
            return '../uploads/profile_photos/patient_' . (int) $userId . '.' . $ext . '?v=' . filemtime($file);
        }
    }

    return '';
}

/* =========================
   POST ACTION HANDLERS
========================= */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrf();

    $action = $_POST['action'] ?? '';

    /* --- UPDATE PROFILE --- */
    if ($action === 'update_profile') {
        $firstName = trim($_POST['first_name'] ?? '');
        $middleName = trim($_POST['middle_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $birthDate = trim($_POST['birth_date'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $dentalHistory = trim($_POST['dental_history'] ?? '');

        if ($firstName === '' || $lastName === '') {
            $error = 'First name and last name are required.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                /* Update patients table */
                $stmt = $pdo->prepare("
                    UPDATE patients
                    SET
                        first_name = ?,
                        middle_name = ?,
                        last_name = ?,
                        email = ?,
                        contact_number = ?,
                        address = ?,
                        birth_date = NULLIF(?, ''),
                        gender = ?,
                        dental_history = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $firstName,
                    $middleName,
                    $lastName,
                    $email,
                    $contact,
                    $address,
                    $birthDate,
                    $gender,
                    $dentalHistory,
                    $patientId
                ]);

                /* Update users table */
                $nameParts = array_filter([$firstName, $middleName, $lastName], function($v) {
                    return $v !== '';
                });
                $newFullName = implode(' ', $nameParts);

                try {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET full_name = ?, contact_number = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $newFullName,
                        $contact,
                        $userId
                    ]);
                } catch (PDOException $e) {
                    // Continue even if users table update hits partial constraints
                }

                $_SESSION['full_name'] = $newFullName;
                $_SESSION['user_name'] = $newFullName;

                $success = 'Your profile and dental information have been updated successfully.';

            } catch (PDOException $e) {
                $error = 'Unable to update your profile. Please check the provided information.';
            }
        }
    }

    /* --- CHANGE PASSWORD --- */
    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $error = 'Please complete all password fields.';
        } elseif (strlen($newPassword) < 6) {
            $error = 'New password must contain at least 6 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New password and confirmation password do not match.';
        } else {
            $passwordColumn = 'password';
            if (!isset($user['password'])) {
                $passwordColumn = 'password_hash';
            }

            $storedPassword = $user[$passwordColumn] ?? '';

            if (password_verify($currentPassword, $storedPassword)) {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

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

                    $success = 'Your password has been changed successfully.';

                } catch (PDOException $e) {
                    $error = 'Unable to change password. Please try again.';
                }
            } else {
                $error = 'The current password you entered is incorrect.';
            }
        }
    }

    /* --- UPLOAD PHOTO --- */
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
                    'image/png'  => 'png',
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
                        $error = 'Unable to save photo. Upload directory is missing.';
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

    /* --- REMOVE PHOTO --- */
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
            $success = 'Profile photo removed successfully.';
        } else {
            $error = 'No profile photo to remove.';
        }
    }

    /* Refresh data after mutation */
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? LIMIT 1");
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

/* =========================
   COMPUTE VIEW VARIABLES
========================= */

$profilePhotoUrl = patientProfilePhotoUrl($userId);

$firstName = trim($patient['first_name'] ?? '');
$middleName = trim($patient['middle_name'] ?? '');
$lastName = trim($patient['last_name'] ?? '');

$nameParts = array_filter([$firstName, $middleName, $lastName], function($v) {
    return $v !== '';
});
$fullName = implode(' ', $nameParts);
if ($fullName === '') {
    $fullName = $user['full_name'] ?? 'Patient';
}

$patientNumber = trim($patient['patient_number'] ?? ('PAT-' . str_pad((string)$patientId, 5, '0', STR_PAD_LEFT)));
$email = trim($patient['email'] ?? ($user['email'] ?? ($user['username'] ?? '')));
$contactNumber = trim($patient['contact_number'] ?? ($user['contact_number'] ?? ''));
$address = trim($patient['address'] ?? '');
$gender = trim($patient['gender'] ?? '');
$birthDate = trim($patient['birth_date'] ?? '');
$dentalHistory = trim($patient['dental_history'] ?? '');

/* Compute Age & Birthday */
$calculatedAge = '';
$birthDateFormatted = '';
if ($birthDate !== '' && $birthDate !== '0000-00-00') {
    try {
        $bdate = new DateTime($birthDate);
        $today = new DateTime('today');
        $birthDateFormatted = $bdate->format('M d, Y');
        if ($bdate <= $today) {
            $diff = $bdate->diff($today);
            $calculatedAge = $diff->y . ' yrs old';
        }
    } catch (Throwable $e) {
        $calculatedAge = '';
    }
}

/* Member Since */
$memberSince = 'Active Member';
if (!empty($patient['created_at'])) {
    $memberSince = date('M Y', strtotime($patient['created_at']));
} elseif (!empty($user['created_at'])) {
    $memberSince = date('M Y', strtotime($user['created_at']));
}

/* Appointment Statistics */
$totalAppts = 0;
$upcomingAppts = 0;
$completedAppts = 0;

try {
    $statStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status IN ('Pending', 'Confirmed') AND appointment_date >= CURDATE() THEN 1 ELSE 0 END) AS upcoming,
            SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed
        FROM appointments
        WHERE patient_id = ?
    ");
    $statStmt->execute([$patientId]);
    $apptStats = $statStmt->fetch(PDO::FETCH_ASSOC);
    if ($apptStats) {
        $totalAppts = (int)($apptStats['total'] ?? 0);
        $upcomingAppts = (int)($apptStats['upcoming'] ?? 0);
        $completedAppts = (int)($apptStats['completed'] ?? 0);
    }
} catch (Throwable $e) {}

/* Recent Appointments (up to 4) */
$recentAppointments = [];
try {
    $recentStmt = $pdo->prepare("
        SELECT
            a.id,
            a.appointment_date,
            a.start_time,
            a.service_type,
            a.status,
            COALESCE(d.dentist_name, 'Assigned Dentist') AS dentist_name
        FROM appointments a
        LEFT JOIN dentists d ON d.id = a.dentist_id
        WHERE a.patient_id = ?
        ORDER BY a.appointment_date DESC, a.start_time DESC
        LIMIT 4
    ");
    $recentStmt->execute([$patientId]);
    $recentAppointments = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Abalos Dental Portal</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap">
    <link rel="stylesheet" href="patient.css?v=9">

    <style>
        /* Topbar & Minibar */
        .patient-minibar {
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
            border-radius: 10px 10px 0 0;
        }
        .patient-minibar span { color: #ffffff; }
        .patient-minibar i { font-size: 13px; color: #ffffff; }

        .patient-topbar-right { display: flex; align-items: center; gap: 16px; }
        .patient-topbar-date { display: flex; align-items: center; gap: 10px; color: #244f60; }
        .patient-topbar-date i { font-size: 20px; }
        .patient-topbar-date strong { display: block; font-size: 12px; font-weight: 800; line-height: 1.2; }
        .patient-topbar-date small { display: block; font-size: 11px; opacity: .85; font-weight: 600; }
        .patient-topbar-time { display: flex; align-items: center; gap: 10px; color: #244f60; border-left: 1px solid #e8eef2; padding-left: 16px; }
        .patient-topbar-time i { font-size: 20px; }
        .patient-topbar-time strong { display: block; font-size: 12px; font-weight: 800; line-height: 1.2; }
        .patient-topbar-time small { display: block; font-size: 11px; opacity: .85; font-weight: 600; font-variant-numeric: tabular-nums; }

        /* Patient Identity Hero Card */
        .patient-hero-card {
            background: linear-gradient(135deg, #0A4A41 0%, #0E6B5E 60%, #149A72 100%);
            border-radius: 18px;
            padding: 26px 28px;
            color: #ffffff;
            box-shadow: 0 10px 28px rgba(10, 74, 65, 0.20);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(201, 162, 39, 0.28);
            margin-bottom: 24px;
        }
        .patient-hero-card::after {
            content: '';
            position: absolute;
            right: -40px;
            bottom: -40px;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, rgba(201, 162, 39, 0.16) 0%, rgba(255,255,255,0) 70%);
            pointer-events: none;
        }

        .patient-avatar-wrap {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            background: #ffffff;
            color: #0E6B5E;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
            font-weight: 800;
            overflow: hidden;
            flex-shrink: 0;
            border: 3.5px solid rgba(255, 255, 255, 0.85);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
        }
        .patient-avatar-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .patient-hero-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 6px;
        }
        .hero-badge-id {
            background: rgba(201, 162, 39, 0.22);
            border: 1px solid #C9A227;
            color: #FFE68A;
            font-size: 11.5px;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 20px;
            letter-spacing: .5px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .hero-badge-active {
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.35);
            color: #ffffff;
            font-size: 11.5px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .patient-hero-name {
            font-family: "Plus Jakarta Sans", sans-serif;
            font-size: 23px;
            font-weight: 800;
            line-height: 1.25;
            color: #ffffff;
            margin-bottom: 6px;
        }

        .patient-hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            font-size: 12.5px;
            color: rgba(255, 255, 255, 0.88);
        }
        .patient-hero-meta span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .patient-hero-meta i {
            color: #FFE68A;
            font-size: 13.5px;
        }

        /* Stat Snapshot Cards */
        .patient-stat-card {
            background: #ffffff;
            border: 1px solid #C9E9DA;
            border-radius: 14px;
            padding: 16px 18px;
            box-shadow: 0 4px 16px rgba(14, 107, 94, 0.05);
            display: flex;
            align-items: center;
            gap: 14px;
            height: 100%;
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .patient-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(14, 107, 94, 0.10);
            border-color: #0E6B5E;
        }
        .stat-icon-box {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }
        .stat-icon-teal   { background: #EAF7F0; color: #0E6B5E; }
        .stat-icon-gold   { background: #FFF8E6; color: #C9A227; }
        .stat-icon-blue   { background: #EBF5FF; color: #1E66D5; }
        .stat-icon-green  { background: #E7F9EE; color: #15945A; }
        .patient-stat-label {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .6px;
            text-transform: uppercase;
            color: #6C8691;
            margin-bottom: 2px;
        }
        .patient-stat-val {
            font-family: "Plus Jakarta Sans", sans-serif;
            font-size: 20px;
            font-weight: 800;
            color: #143F51;
            line-height: 1.2;
        }
        .patient-stat-sub {
            font-size: 11px;
            color: #8AA0A8;
            font-weight: 600;
        }

        /* Patient Portal Content Cards */
        .profile-card {
            background: #ffffff;
            border: 1px solid #C9E9DA;
            border-radius: 16px;
            box-shadow: 0 6px 20px rgba(14, 107, 94, 0.06);
            overflow: hidden;
            margin-bottom: 22px;
        }
        .profile-card-header {
            padding: 16px 22px;
            background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%);
            border-bottom: 1px solid #C9E9DA;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .profile-card-header h3 {
            font-size: 16.5px;
            font-family: "Plus Jakarta Sans", sans-serif;
            font-weight: 800;
            color: #143F51;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 9px;
        }
        .profile-card-header h3 i {
            color: #0E6B5E;
            font-size: 18px;
        }
        .profile-card-header small {
            display: block;
            font-size: 11.5px;
            color: #5B757F;
            font-weight: 600;
        }
        .profile-card-body {
            padding: 22px;
        }

        /* Section divider tags */
        .profile-section-tag {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: .7px;
            text-transform: uppercase;
            color: #0E6B5E;
            margin: 22px 0 14px;
            padding-bottom: 8px;
            border-bottom: 1px dashed #D1EADE;
        }
        .profile-section-tag:first-of-type {
            margin-top: 0;
        }
        .profile-section-tag i {
            font-size: 15px;
            color: #0E6B5E;
        }

        /* Input Controls */
        .profile-card .form-label {
            font-size: 12.5px;
            font-weight: 700;
            color: #244F60;
            margin-bottom: 6px;
        }
        .profile-card .form-control,
        .profile-card .form-select {
            border: 1.5px solid #C9E9DA;
            border-radius: 10px;
            font-size: 13.5px;
            color: #244F60;
            background: #FBDFE;
            padding: 9px 12px;
            transition: all .15s ease;
        }
        .profile-card .form-control:focus,
        .profile-card .form-select:focus {
            border-color: #0E6B5E;
            box-shadow: 0 0 0 3px rgba(14, 107, 94, 0.12);
            background: #ffffff;
        }
        .profile-card .form-control[readonly],
        .profile-card .form-control:disabled {
            background: #F1F6F4;
            color: #52717D;
            border-color: #D3E7DE;
            cursor: not-allowed;
            font-weight: 600;
        }

        /* Dental history notice callout */
        .dental-notice-box {
            background: #F0FBF6;
            border: 1px solid #BCE9D3;
            border-left: 4px solid #0E6B5E;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 14px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .dental-notice-box i {
            font-size: 18px;
            color: #0E6B5E;
            margin-top: 1px;
            flex-shrink: 0;
        }
        .dental-notice-box strong {
            display: block;
            font-size: 12.5px;
            color: #143F51;
            font-weight: 800;
            margin-bottom: 2px;
        }
        .dental-notice-box p {
            margin: 0;
            font-size: 12px;
            color: #4D6873;
            line-height: 1.45;
        }

        /* Primary action button */
        .btn-patient-save {
            background: linear-gradient(135deg, #149A72 0%, #0A4A41 100%) !important;
            border: 1px solid rgba(201, 162, 39, 0.4) !important;
            color: #ffffff !important;
            border-radius: 10px !important;
            padding: 11px 26px !important;
            font-size: 14px !important;
            font-weight: 800 !important;
            box-shadow: 0 6px 18px rgba(14, 107, 94, 0.22) !important;
            transition: all .2s ease !important;
        }
        .btn-patient-save:hover {
            background: linear-gradient(135deg, #0E6B5E 0%, #083A32 100%) !important;
            transform: translateY(-1px);
            box-shadow: 0 8px 22px rgba(14, 107, 94, 0.28) !important;
        }

        /* Recent Visit items */
        .recent-appt-item {
            padding: 12px 14px;
            border: 1px solid #E2EFE8;
            border-radius: 12px;
            background: #FBDFE;
            margin-bottom: 10px;
            transition: all .15s ease;
        }
        .recent-appt-item:hover {
            background: #ffffff;
            border-color: #0E6B5E;
            box-shadow: 0 4px 12px rgba(14, 107, 94, 0.08);
        }
        .recent-appt-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 4px;
        }
        .recent-appt-service {
            font-size: 13.5px;
            font-weight: 800;
            color: #143F51;
        }
        .recent-appt-dentist {
            font-size: 12px;
            color: #5B757F;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .recent-appt-datetime {
            font-size: 11.5px;
            color: #8AA0A8;
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 4px;
        }

        /* Clinic Info Card */
        .clinic-info-card {
            background: linear-gradient(135deg, #143F51 0%, #0A2633 100%);
            border-radius: 16px;
            color: #ffffff;
            padding: 20px;
            border: 1px solid rgba(201, 162, 39, 0.25);
            box-shadow: 0 6px 20px rgba(10, 38, 51, 0.2);
        }
        .clinic-info-card h4 {
            font-size: 15px;
            font-weight: 800;
            color: #FFE68A;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
        }
        .clinic-info-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 12.5px;
            color: #E0EBF0;
            margin-bottom: 8px;
        }
        .clinic-info-item i {
            color: #34D399;
            font-size: 14px;
            margin-top: 2px;
            flex-shrink: 0;
        }
    </style>
</head>
<body>

<div class="patient-layout">

    <?php require_once __DIR__ . '/patient_sidebar.php'; ?>

    <main class="patient-main">

        <!-- Top minibar with clinic name -->
        <div class="patient-minibar">
            <i class="bi bi-heart-pulse-fill"></i>
            <span>Abalos Dental Clinic • Patient Profile</span>
        </div>

        <!-- Topbar with page title and Manila real-time clock -->
        <header class="patient-topbar">
            <div>
                <h1 class="page-heading">My Profile</h1>
                <p class="page-small">Manage your personal demographics, dental history, and account security</p>
            </div>

            <div class="patient-topbar-right">
                <div class="patient-topbar-date">
                    <i class="bi bi-calendar-event-fill text-teal"></i>
                    <div>
                        <strong><?= htmlspecialchars(date('l')) ?></strong>
                        <small><?= htmlspecialchars(date('M d, Y')) ?></small>
                    </div>
                </div>
                <div class="patient-topbar-time">
                    <i class="bi bi-clock-fill text-teal"></i>
                    <div>
                        <strong>Clinic Time</strong>
                        <small id="patientClinicTime">--:--:--</small>
                    </div>
                </div>
                <div class="profile-circle" title="<?= patientProfileEscape($fullName) ?>">
                    <i class="bi bi-person-fill"></i>
                </div>
            </div>
        </header>

        <script>
            (function(){
                function tick(){
                    try {
                        var el = document.getElementById('patientClinicTime');
                        if (!el) return;
                        el.textContent = new Date().toLocaleTimeString('en-PH', {
                            timeZone: 'Asia/Manila',
                            hour: '2-digit',
                            minute: '2-digit',
                            second: '2-digit',
                            hour12: true
                        });
                    } catch(e){}
                }
                tick();
                setInterval(tick, 1000);
            })();
        </script>

        <div class="patient-content">

            <!-- Alerts -->
            <?php if ($success): ?>
                <div class="alert alert-success d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-check-circle-fill fs-5 text-success"></i>
                    <div><?= patientProfileEscape($success) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-exclamation-triangle-fill fs-5 text-danger"></i>
                    <div><?= patientProfileEscape($error) ?></div>
                </div>
            <?php endif; ?>

            <!-- PATIENT IDENTITY HERO CARD -->
            <div class="patient-hero-card">
                <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                    <div class="d-flex align-items-center gap-3">
                        <div class="patient-avatar-wrap">
                            <?php if ($profilePhotoUrl !== ''): ?>
                                <img src="<?= patientProfileEscape($profilePhotoUrl) ?>" alt="Profile photo" class="patient-avatar-img">
                            <?php else: ?>
                                <i class="bi bi-person-fill"></i>
                            <?php endif; ?>
                        </div>

                        <div>
                            <div class="patient-hero-badges">
                                <span class="hero-badge-id">
                                    <i class="bi bi-person-badge"></i> ID: <?= patientProfileEscape($patientNumber) ?>
                                </span>
                                <span class="hero-badge-active">
                                    <i class="bi bi-check2-circle"></i> Active Patient
                                </span>
                                <?php if ($calculatedAge !== ''): ?>
                                    <span class="hero-badge-active">
                                        <i class="bi bi-hourglass-split"></i> <?= patientProfileEscape($calculatedAge) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="patient-hero-name">
                                <?= patientProfileEscape($fullName) ?>
                            </div>

                            <div class="patient-hero-meta">
                                <?php if ($email !== ''): ?>
                                    <span><i class="bi bi-envelope-fill"></i> <?= patientProfileEscape($email) ?></span>
                                <?php endif; ?>
                                <?php if ($contactNumber !== ''): ?>
                                    <span><i class="bi bi-telephone-fill"></i> <?= patientProfileEscape($contactNumber) ?></span>
                                <?php endif; ?>
                                <span><i class="bi bi-calendar3"></i> Patient since <?= patientProfileEscape($memberSince) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Photo Management Actions -->
                    <div class="d-flex align-items-center gap-2">
                        <form method="POST" enctype="multipart/form-data" class="d-inline m-0">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="upload_photo">
                            <input type="file" name="profile_photo" id="patientProfilePhotoInput" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="this.form.submit()">
                            <button type="button" class="btn btn-light btn-sm fw-bold shadow-sm" onclick="document.getElementById('patientProfilePhotoInput').click()">
                                <i class="bi bi-camera-fill me-1 text-teal"></i> <?= $profilePhotoUrl !== '' ? 'Change Photo' : 'Upload Photo' ?>
                            </button>
                        </form>

                        <?php if ($profilePhotoUrl !== ''): ?>
                            <button type="button" class="btn btn-outline-light btn-sm" data-bs-toggle="modal" data-bs-target="#removePhotoModal">
                                <i class="bi bi-trash me-1"></i> Remove
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- SNAPSHOT STATS ROW -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="patient-stat-card">
                        <div class="stat-icon-box stat-icon-teal">
                            <i class="bi bi-person-badge-fill"></i>
                        </div>
                        <div>
                            <div class="patient-stat-label">Patient Number</div>
                            <div class="patient-stat-val" style="font-size: 16px; letter-spacing: .4px;">
                                <?= patientProfileEscape($patientNumber) ?>
                            </div>
                            <div class="patient-stat-sub">Official clinic record</div>
                        </div>
                    </div>
                </div>

                <div class="col-sm-6 col-lg-3">
                    <div class="patient-stat-card">
                        <div class="stat-icon-box stat-icon-blue">
                            <i class="bi bi-calendar-check-fill"></i>
                        </div>
                        <div>
                            <div class="patient-stat-label">Total Appointments</div>
                            <div class="patient-stat-val"><?= $totalAppts ?></div>
                            <div class="patient-stat-sub">All-time clinic visits</div>
                        </div>
                    </div>
                </div>

                <div class="col-sm-6 col-lg-3">
                    <div class="patient-stat-card">
                        <div class="stat-icon-box stat-icon-gold">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div>
                            <div class="patient-stat-label">Upcoming Visits</div>
                            <div class="patient-stat-val"><?= $upcomingAppts ?></div>
                            <div class="patient-stat-sub">Active scheduled slots</div>
                        </div>
                    </div>
                </div>

                <div class="col-sm-6 col-lg-3">
                    <div class="patient-stat-card">
                        <div class="stat-icon-box stat-icon-green">
                            <i class="bi bi-shield-fill-check"></i>
                        </div>
                        <div>
                            <div class="patient-stat-label">Completed Visits</div>
                            <div class="patient-stat-val"><?= $completedAppts ?></div>
                            <div class="patient-stat-sub">Finished dental treatments</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MAIN TWO-COLUMN WORKSPACE -->
            <div class="row g-4">

                <!-- LEFT COLUMN: Patient Info & Medical History Form -->
                <div class="col-lg-8">
                    <div class="profile-card">
                        <div class="profile-card-header">
                            <div>
                                <h3><i class="bi bi-file-earmark-medical-fill"></i> Patient Information & Dental History</h3>
                                <small>Keep your personal information and health background current for safe dental treatments</small>
                            </div>
                        </div>

                        <div class="profile-card-body">
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="update_profile">

                                <!-- Section 1: Demographics -->
                                <div class="profile-section-tag">
                                    <i class="bi bi-person-fill"></i> Personal Demographics
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">
                                            First Name <span class="text-danger">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            name="first_name"
                                            class="form-control"
                                            value="<?= patientProfileEscape($firstName) ?>"
                                            placeholder="Enter first name"
                                            required
                                        >
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">
                                            Middle Name
                                        </label>
                                        <input
                                            type="text"
                                            name="middle_name"
                                            class="form-control"
                                            value="<?= patientProfileEscape($middleName) ?>"
                                            placeholder="Optional middle name"
                                        >
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">
                                            Last Name <span class="text-danger">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            name="last_name"
                                            class="form-control"
                                            value="<?= patientProfileEscape($lastName) ?>"
                                            placeholder="Enter last name"
                                            required
                                        >
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">
                                            Date of Birth
                                        </label>
                                        <input
                                            type="date"
                                            name="birth_date"
                                            class="form-control"
                                            value="<?= patientProfileEscape($birthDate) ?>"
                                        >
                                        <?php if ($calculatedAge !== ''): ?>
                                            <div class="form-text text-muted" style="font-size:11px;">
                                                <i class="bi bi-info-circle me-1"></i>Current Age: <strong><?= patientProfileEscape($calculatedAge) ?></strong>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">
                                            Gender
                                        </label>
                                        <select name="gender" class="form-select">
                                            <option value="">Select Gender</option>
                                            <option value="Male" <?= $gender === 'Male' ? 'selected' : '' ?>>Male</option>
                                            <option value="Female" <?= $gender === 'Female' ? 'selected' : '' ?>>Female</option>
                                            <option value="Other" <?= $gender === 'Other' ? 'selected' : '' ?>>Other</option>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">
                                            Patient ID
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light text-muted border-end-0">
                                                <i class="bi bi-lock-fill"></i>
                                            </span>
                                            <input
                                                type="text"
                                                class="form-control border-start-0"
                                                value="<?= patientProfileEscape($patientNumber) ?>"
                                                readonly
                                                title="Official clinic-issued Patient ID (read-only)"
                                            >
                                        </div>
                                    </div>
                                </div>

                                <!-- Section 2: Contact & Address -->
                                <div class="profile-section-tag">
                                    <i class="bi bi-telephone-fill"></i> Contact & Residential Details
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">
                                            Email Address
                                        </label>
                                        <input
                                            type="email"
                                            name="email"
                                            class="form-control"
                                            value="<?= patientProfileEscape($email) ?>"
                                            placeholder="patient@example.com"
                                        >
                                        <div class="form-text text-muted" style="font-size:11px;">
                                            Used for appointment notifications and confirmation alerts
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">
                                            Contact / Mobile Number
                                        </label>
                                        <input
                                            type="text"
                                            name="contact_number"
                                            class="form-control"
                                            value="<?= patientProfileEscape($contactNumber) ?>"
                                            placeholder="09XXXXXXXXX"
                                        >
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">
                                            Home / Residential Address
                                        </label>
                                        <textarea
                                            name="address"
                                            class="form-control"
                                            rows="2"
                                            placeholder="House / Unit No., Street, Barangay, City, Province"
                                        ><?= patientProfileEscape($address) ?></textarea>
                                    </div>
                                </div>

                                <!-- Section 3: Dental & Medical Background -->
                                <div class="profile-section-tag">
                                    <i class="bi bi-heart-pulse-fill"></i> Dental Background & Medical History
                                </div>

                                <div class="dental-notice-box">
                                    <i class="bi bi-shield-fill-exclamation"></i>
                                    <div>
                                        <strong>Why is this important for dental care?</strong>
                                        <p>Dentists check your medical history before administering local anesthesia, dental extractions, and prescribing antibiotics to avoid dangerous allergic reactions and bleeding complications.</p>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">
                                            Medical Conditions, Allergies & Dental Notes
                                        </label>
                                        <textarea
                                            name="dental_history"
                                            class="form-control"
                                            rows="4"
                                            placeholder="Please list any of the following:&#10;• Known Allergies (e.g., Penicillin, Amoxicillin, Latex, Local Anesthetics)&#10;• Chronic Conditions (e.g., Hypertension, Diabetes, Asthma, Heart disease, Bleeding tendency)&#10;• Maintenance medications or blood thinners&#10;• Previous dental surgeries, braces history, or wisdom tooth issues"
                                        ><?= patientProfileEscape($dentalHistory) ?></textarea>
                                        <div class="form-text text-muted" style="font-size:11.5px;">
                                            This information is kept strictly confidential and accessible only to your attending dentist.
                                        </div>
                                    </div>

                                    <div class="col-12 mt-4 pt-2">
                                        <button type="submit" class="btn btn-patient-save">
                                            <i class="bi bi-check2-circle me-1"></i> Save Profile Changes
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN: Appointments Summary & Account Security -->
                <div class="col-lg-4">

                    <!-- Recent Dental Visits -->
                    <div class="profile-card">
                        <div class="profile-card-header">
                            <div>
                                <h3><i class="bi bi-calendar2-heart-fill"></i> Recent Visits</h3>
                                <small>Your latest clinic appointments</small>
                            </div>
                        </div>

                        <div class="profile-card-body p-3">
                            <?php if (!empty($recentAppointments)): ?>
                                <?php foreach ($recentAppointments as $recent): ?>
                                    <?php
                                    $recentDate = !empty($recent['appointment_date']) ? date('M d, Y', strtotime($recent['appointment_date'])) : '—';
                                    $recentTime = !empty($recent['start_time']) ? date('h:i A', strtotime($recent['start_time'])) : '';
                                    $st = trim($recent['status'] ?? 'Pending');

                                    $statusClass = 'status-pending';
                                    if ($st === 'Confirmed') $statusClass = 'status-confirmed';
                                    elseif ($st === 'Completed') $statusClass = 'status-completed';
                                    elseif (in_array($st, ['Cancelled', 'Canceled'], true)) $statusClass = 'status-cancelled';
                                    elseif ($st === 'No-Show') $statusClass = 'status-no-show';
                                    ?>
                                    <div class="recent-appt-item">
                                        <div class="recent-appt-top">
                                            <span class="recent-appt-service">
                                                <?= patientProfileEscape($recent['service_type'] ?: 'Dental Service') ?>
                                            </span>
                                            <span class="status-badge <?= $statusClass ?>" style="padding: 2px 8px; font-size: 11px;">
                                                <?= patientProfileEscape($st) ?>
                                            </span>
                                        </div>
                                        <div class="recent-appt-dentist">
                                            <i class="bi bi-person-fill text-teal"></i>
                                            <?= patientProfileEscape($recent['dentist_name']) ?>
                                        </div>
                                        <div class="recent-appt-datetime">
                                            <i class="bi bi-clock-fill"></i>
                                            <?= patientProfileEscape($recentDate) ?><?= $recentTime !== '' ? ' • ' . patientProfileEscape($recentTime) : '' ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <div class="mt-3">
                                    <a href="my_appointments.php" class="btn btn-outline-primary btn-sm w-100 fw-bold">
                                        <i class="bi bi-calendar-range me-1"></i> View All Appointments
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="bi bi-calendar2-x text-teal" style="font-size: 32px; opacity: .5; display:block; margin-bottom:8px;"></i>
                                    <div style="font-size: 13px; font-weight: 700; color: #244F60;">No appointment records yet</div>
                                    <p class="small text-muted mb-3" style="font-size: 11.5px;">Schedule your first dental checkup with us.</p>
                                    <a href="calendar.php" class="btn btn-primary btn-sm fw-bold">
                                        <i class="bi bi-calendar-plus me-1"></i> Book Appointment
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Account Security (Change Password) -->
                    <div class="profile-card">
                        <div class="profile-card-header">
                            <div>
                                <h3><i class="bi bi-shield-lock-fill"></i> Account Security</h3>
                                <small>Update your login password</small>
                            </div>
                        </div>

                        <div class="profile-card-body">
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="change_password">

                                <div class="mb-3">
                                    <label class="form-label">Current Password</label>
                                    <input
                                        type="password"
                                        name="current_password"
                                        class="form-control"
                                        placeholder="Enter current password"
                                        required
                                    >
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">New Password</label>
                                    <input
                                        type="password"
                                        name="new_password"
                                        class="form-control"
                                        placeholder="Minimum 6 characters"
                                        minlength="6"
                                        required
                                    >
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Confirm New Password</label>
                                    <input
                                        type="password"
                                        name="confirm_password"
                                        class="form-control"
                                        placeholder="Repeat new password"
                                        minlength="6"
                                        required
                                    >
                                </div>

                                <button type="submit" class="btn btn-outline-primary btn-sm w-100 fw-bold py-2">
                                    <i class="bi bi-key-fill me-1"></i> Update Password
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Clinic Help & Assistance -->
                    <div class="clinic-info-card">
                        <h4><i class="bi bi-hospital-fill"></i> Abalos Dental Clinic</h4>
                        <div class="clinic-info-item">
                            <i class="bi bi-clock-history"></i>
                            <div>
                                <strong>Operating Hours:</strong><br>
                                Monday – Saturday (8:00 AM – 5:00 PM)
                            </div>
                        </div>
                        <div class="clinic-info-item">
                            <i class="bi bi-geo-alt-fill"></i>
                            <div>
                                <strong>Walk-in Patients:</strong><br>
                                Priority queuing is provided for urgent dental pain and emergency checkups.
                            </div>
                        </div>
                        <div class="clinic-info-item">
                            <i class="bi bi-question-circle-fill"></i>
                            <div>
                                <strong>Need to adjust a schedule?</strong><br>
                                You can reschedule or leave a note via <a href="appointment_concern.php" class="text-warning text-decoration-underline">Appointment Concern</a>.
                            </div>
                        </div>
                    </div>

                </div>

            </div>

        </div>

    </main>

</div>

<!-- REMOVE PHOTO CONFIRMATION MODAL -->
<div class="modal fade" id="removePhotoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 500px; width: 92%;">
        <div class="modal-content patient-logout-modal-content">
            <div class="modal-header patient-logout-modal-header">
                <div class="patient-logout-icon-wrap">
                    <i class="bi bi-trash"></i>
                </div>
                <div>
                    <h5 class="modal-title patient-logout-title">Remove Profile Photo</h5>
                    <small class="patient-logout-subtitle">Abalos Dental — Patient Portal</small>
                </div>
                <button type="button" class="btn-close patient-logout-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body patient-logout-body">
                <p class="patient-logout-message">
                    Are you sure you want to remove your profile photo?
                </p>
                <p class="patient-logout-hint">
                    Your avatar will revert to the default clinic profile icon after removal.
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
