<?php
/**
 * DENTIST SETTINGS — CLINICAL PRACTICE & ACCOUNT CONFIGURATION
 * 
 * Provides licensed dental practitioners with tools to configure:
 * 1. Practitioner Identity & Contact Details
 * 2. Weekly Regular Rest Day & Operating Shift Availability
 * 3. Scheduled Clinical Day-Offs & Leaves (dentist_day_offs)
 * 4. Chairside Alerts, Notifications & Audio Chime Preferences
 * 5. Portal Password Security & Encrypted Session Management
 *
 * Layout: Full-width responsive panels arranged sequentially (1 to 5)
 * without artificial narrow center constraints.
 */

require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || strtolower(trim($_SESSION['role'] ?? '')) !== 'dentist') {
    header('Location: ../login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$success = '';
$error = '';

// Auto-migration & Schema safety check
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM dentists")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('rest_day', $colCheck, true)) {
        $pdo->exec("ALTER TABLE dentists ADD COLUMN rest_day TINYINT(1) NULL DEFAULT NULL AFTER status");
    }
    if (!in_array('contact_number', $colCheck, true)) {
        $pdo->exec("ALTER TABLE dentists ADD COLUMN contact_number VARCHAR(50) NULL DEFAULT NULL AFTER specialization");
    }
    if (!in_array('specialization', $colCheck, true)) {
        $pdo->exec("ALTER TABLE dentists ADD COLUMN specialization VARCHAR(150) NULL DEFAULT 'General Dentistry' AFTER dentist_name");
    }
} catch (PDOException $e) {
    // Graceful fallback
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `dentist_day_offs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `dentist_id` int(11) NOT NULL,
            `off_date` date NOT NULL,
            `reason` varchar(255) DEFAULT '',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_dentist_off_date` (`dentist_id`,`off_date`),
            KEY `idx_dentist_id` (`dentist_id`),
            KEY `idx_off_date` (`off_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
} catch (PDOException $e) {
    // Graceful fallback
}

// Fetch user account
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

// Helper to escape output
function dentistSettingEscape($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Helper for photo
function dentistSettingPhotoUrl($uid) {
    $dir = __DIR__ . '/../uploads/profile_photos';
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    foreach ($allowed as $ext) {
        $path = $dir . '/dentist_' . (int)$uid . '.' . $ext;
        if (file_exists($path)) {
            return '../uploads/profile_photos/dentist_' . (int)$uid . '.' . $ext . '?v=' . filemtime($path);
        }
    }
    return '';
}

// Resolve dentist entity
$dentist = null;
if (function_exists('resolveDentistId')) {
    $resolvedId = resolveDentistId($userId, trim($_SESSION['full_name'] ?? ''));
    if ($resolvedId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM dentists WHERE id = ? LIMIT 1");
        $stmt->execute([$resolvedId]);
        $dentist = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
if (!$dentist) {
    $stmt = $pdo->prepare("SELECT * FROM dentists WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $dentist = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$dentist) {
    $stmt = $pdo->prepare("SELECT * FROM dentists WHERE dentist_name = ? LIMIT 1");
    $stmt->execute([trim($_SESSION['full_name'] ?? '')]);
    $dentist = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$dentist) {
    $stmt = $pdo->prepare("SELECT * FROM dentists WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $dentist = $stmt->fetch(PDO::FETCH_ASSOC);
}

$dayNames = [
    0 => 'Sunday',
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
];

/*
|--------------------------------------------------------------------------
| POST ACTIONS HANDLER (CSRF PROTECTED)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    // 1. UPDATE PRACTITIONER & PRACTICE AVAILABILITY SETTINGS
    if ($action === 'update_practice_settings' || $action === 'update_settings') {
        $fullName = trim($_POST['full_name'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');
        $spec = trim($_POST['specialization'] ?? '');
        $restDayRaw = trim($_POST['rest_day'] ?? '');

        $restDayVal = null;
        if ($restDayRaw !== '' && $restDayRaw !== 'none') {
            $rdInt = (int)$restDayRaw;
            if ($rdInt >= 0 && $rdInt <= 6) {
                $restDayVal = $rdInt;
            }
        }

        if ($fullName === '') {
            $error = 'Dentist practitioner name is required.';
        } else {
            try {
                $pdo->prepare("UPDATE users SET full_name = ? WHERE id = ?")->execute([$fullName, $userId]);
                $_SESSION['full_name'] = $fullName;

                if ($dentist && !empty($dentist['id'])) {
                    $stmtUp = $pdo->prepare("
                        UPDATE dentists 
                        SET dentist_name = ?, contact_number = ?, specialization = ?, rest_day = ? 
                        WHERE id = ?
                    ");
                    $stmtUp->execute([$fullName, $contact, $spec, $restDayVal, $dentist['id']]);
                }
                $success = 'Clinical practice and practitioner settings have been successfully updated.';
            } catch (PDOException $e) {
                $error = 'Unable to update practitioner settings. Please try again.';
            }
        }
    }

    // 2. ADD CLINICAL DAY-OFF / LEAVE (dentist_day_offs)
    if ($action === 'add_dayoff') {
        if (!$dentist || empty($dentist['id'])) {
            $error = 'Unable to link dentist practitioner record to schedule a day-off.';
        } else {
            $offDate = trim($_POST['off_date'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            if ($reason === '') {
                $reason = 'Clinical Leave';
            }

            $today = date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $offDate)) {
                $error = 'Please provide a valid date for your scheduled day-off.';
            } elseif ($offDate < $today) {
                $error = 'Day-off date cannot be in the past.';
            } else {
                try {
                    $chk = $pdo->prepare("SELECT id FROM dentist_day_offs WHERE dentist_id = ? AND off_date = ? LIMIT 1");
                    $chk->execute([$dentist['id'], $offDate]);
                    if ($chk->fetch()) {
                        $error = 'A day-off is already registered for ' . date('M d, Y', strtotime($offDate)) . '.';
                    } else {
                        $ins = $pdo->prepare("INSERT INTO dentist_day_offs (dentist_id, off_date, reason) VALUES (?, ?, ?)");
                        $ins->execute([$dentist['id'], $offDate, $reason]);
                        $success = 'Scheduled day-off for ' . date('M d, Y (l)', strtotime($offDate)) . ' has been recorded. Appointment slots on this date are now blocked.';
                    }
                } catch (PDOException $e) {
                    $error = 'Unable to schedule day-off: ' . $e->getMessage();
                }
            }
        }
    }

    // 3. CANCEL / DELETE DAY-OFF
    if ($action === 'delete_dayoff') {
        $dayoffId = (int)($_POST['dayoff_id'] ?? 0);
        if ($dayoffId > 0 && $dentist && !empty($dentist['id'])) {
            try {
                $del = $pdo->prepare("DELETE FROM dentist_day_offs WHERE id = ? AND dentist_id = ?");
                $del->execute([$dayoffId, $dentist['id']]);
                $success = 'Scheduled day-off has been cancelled. Regular clinic slots for that date have been restored.';
            } catch (PDOException $e) {
                $error = 'Unable to cancel scheduled day-off.';
            }
        }
    }

    // 4. CHANGE ACCOUNT PASSWORD
    if ($action === 'change_password') {
        $cur = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $conf = $_POST['confirm_password'] ?? '';
        if ($cur === '' || $new === '' || $conf === '') {
            $error = 'Please fill out all password fields.';
        } elseif (strlen($new) < 6) {
            $error = 'New password must contain at least 6 characters.';
        } elseif ($new !== $conf) {
            $error = 'New password and confirmation do not match.';
        } else {
            $col = isset($user['password']) ? 'password' : 'password_hash';
            $stored = $user[$col] ?? '';
            if (password_verify($cur, $stored)) {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                try {
                    $pdo->prepare("UPDATE users SET `$col` = ? WHERE id = ?")->execute([$hash, $userId]);
                    $success = 'Your practitioner account password has been updated successfully.';
                } catch (PDOException $e) {
                    $error = 'Unable to update password.';
                }
            } else {
                $error = 'Current password is incorrect.';
            }
        }
    }
}

// Refresh User & Dentist data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($dentist && !empty($dentist['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM dentists WHERE id = ? LIMIT 1");
    $stmt->execute([$dentist['id']]);
    $dentist = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch upcoming day-offs
$upcomingDayOffs = [];
if ($dentist && !empty($dentist['id'])) {
    try {
        $stmtOff = $pdo->prepare("
            SELECT * FROM dentist_day_offs 
            WHERE dentist_id = ? AND off_date >= CURDATE() 
            ORDER BY off_date ASC
        ");
        $stmtOff->execute([$dentist['id']]);
        $upcomingDayOffs = $stmtOff->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $upcomingDayOffs = [];
    }
}

// Clinical stats snapshot for the hero banner
$statUpcomingCount = 0;
if ($dentist && !empty($dentist['id'])) {
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM appointments 
            WHERE dentist_id = ? AND appointment_date >= CURDATE() 
            AND status NOT IN ('Cancelled', 'Rejected', 'Completed')
        ");
        $st->execute([$dentist['id']]);
        $statUpcomingCount = (int)$st->fetchColumn();
    } catch (PDOException $e) {
        $statUpcomingCount = 0;
    }
}

$fullName = $user['full_name'] ?? 'Dentist';
$userEmail = $user['username'] ?? '';
$photoUrl = dentistSettingPhotoUrl($userId);
$currentRestDay = $dentist['rest_day'] ?? null;
$dentistSpec = !empty($dentist['specialization']) ? $dentist['specialization'] : 'General Dentistry';
$dentistContact = $dentist['contact_number'] ?? '';
$dentistCode = $dentist ? ('DENT-' . str_pad($dentist['id'], 3, '0', STR_PAD_LEFT)) : 'DENT-PRO';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Practice Settings | Dentist Portal</title>

    <!-- Bootstrap 5 & Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Fonts -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap">

    <!-- Portal Theme Stylesheet -->
    <link rel="stylesheet" href="portal.css?v=5">
    <link rel="stylesheet" href="dentist_sidebar.css">

    <style>
        :root {
            --clinic-teal: #0E6B5E;
            --clinic-teal-dark: #0A4A41;
            --clinic-teal-deep: #0A3B34;
            --clinic-mint: #059669;
            --clinic-emerald: #10B981;
            --clinic-gold: #C9A227;
            --clinic-gold-light: #F4E8C1;
            --clinic-bg: #F4F7F6;
            --card-border: #E1EBE6;
            --text-dark: #143F51;
            --text-muted: #64748B;
        }

        body {
            font-family: "DM Sans", "Inter", system-ui, sans-serif;
            background: linear-gradient(135deg, #022c22, #059669, #065f46, #10b981, #022c22) !important;
            background-size: 300% 300% !important;
            animation: dentistDashBg 8s ease infinite !important;
            color: var(--text-dark);
            min-height: 100vh;
        }

        @keyframes dentistDashBg {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .portal-main {
            background: transparent !important;
        }

        /* CLINIC MINIBAR & TOPBAR */
        .clinic-minibar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--clinic-teal-deep);
            color: var(--clinic-gold);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .6px;
            padding: 6px 12px;
        }

        .clinic-minibar span {
            color: #ffffff;
        }

        .portal-topbar {
            background: #ffffff !important;
            border-bottom: 1px solid #E2ECE7 !important;
            height: auto !important;
            min-height: 90px !important;
            padding: 14px 28px !important;
            gap: 16px !important;
            flex-wrap: wrap !important;
            align-items: center !important;
        }

        .topbar-today, .topbar-time {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #244f60;
            border-left: 1px solid #e8eef2;
            padding-left: 16px;
        }

        .topbar-today i, .topbar-time i {
            font-size: 22px;
            color: var(--clinic-teal);
        }

        .topbar-today strong, .topbar-time strong {
            display: block;
            font-size: 13px;
            font-weight: 800;
            line-height: 1.2;
        }

        .topbar-today small, .topbar-time small {
            display: block;
            font-size: 11px;
            opacity: .85;
            font-weight: 600;
        }

        /* HERO BANNER (NATURAL RESPONSIVE FULL WIDTH) */
        .dentist-hero-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #0E6B5E 0%, #059669 55%, #0A4A41 100%);
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: 20px;
            padding: 24px 28px;
            color: #ffffff;
            width: 100%;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            box-shadow: 0 16px 36px rgba(6, 59, 49, 0.22);
            flex-wrap: wrap;
        }

        .dentist-hero-card::before {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            top: -120px;
            right: -70px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0) 70%);
            pointer-events: none;
        }

        .dentist-hero-card::after {
            content: "";
            position: absolute;
            width: 200px;
            height: 200px;
            bottom: -90px;
            left: 20%;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(201, 162, 39, 0.22), rgba(201, 162, 39, 0) 70%);
            pointer-events: none;
        }

        .dentist-hero-left {
            display: flex;
            align-items: center;
            gap: 20px;
            position: relative;
            z-index: 2;
        }

        .dentist-avatar-wrap {
            position: relative;
            width: 82px;
            height: 82px;
            border-radius: 50%;
            border: 3px solid rgba(255, 255, 255, 0.85);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            background: #ffffff;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .dentist-avatar-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .dentist-avatar-fallback {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #10b981, #047857);
            color: #ffffff;
            font-size: 28px;
            font-weight: 800;
            letter-spacing: .5px;
        }

        .dentist-hero-details h1 {
            font-family: "Plus Jakarta Sans", system-ui, sans-serif;
            font-size: 22px;
            font-weight: 800;
            margin: 0 0 6px 0;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .hero-status-pill {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .4px;
            padding: 3px 10px;
            border-radius: 999px;
            background: rgba(16, 185, 129, 0.25);
            border: 1px solid rgba(110, 231, 183, 0.5);
            color: #ecfdf5;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .hero-pulse-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #34d399;
            box-shadow: 0 0 8px #34d399;
            animation: heroDotPulse 1.8s infinite;
        }

        @keyframes heroDotPulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.35); opacity: .7; }
        }

        .dentist-hero-badges {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 6px;
        }

        .hero-badge-item {
            font-size: 11.5px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.24);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .hero-badge-gold {
            background: rgba(201, 162, 39, 0.28);
            border-color: rgba(201, 162, 39, 0.6);
            color: #FDF3D0;
        }

        .dentist-hero-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 2;
            flex-wrap: wrap;
        }

        .dentist-hero-actions .btn {
            border-radius: 10px;
            font-weight: 700;
            font-size: 12.5px;
            padding: 8px 16px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all .2s ease;
        }

        .hero-btn-light {
            background: #ffffff;
            color: var(--clinic-teal-dark);
            border: none;
            box-shadow: 0 6px 14px rgba(0, 0, 0, 0.15);
        }

        .hero-btn-light:hover {
            background: #f0fdf4;
            color: #047857;
            transform: translateY(-1px);
        }

        .hero-btn-outline {
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.35);
        }

        .hero-btn-outline:hover {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.6);
        }

        /* SEQUENTIAL FULL-WIDTH STACK (NATURAL SAKTO-LANG CONTAINER) */
        .settings-stack-container {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 22px;
        }

        /* SETTINGS PANEL CARDS */
        .settings-panel {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 18px;
            box-shadow: 0 10px 28px rgba(6, 59, 49, 0.07);
            overflow: hidden;
            width: 100%;
            transition: box-shadow .2s ease, transform .2s ease;
        }

        .settings-panel:hover {
            box-shadow: 0 14px 34px rgba(6, 59, 49, 0.11);
        }

        .settings-panel-head {
            padding: 18px 26px;
            background: linear-gradient(135deg, #F7FAF9 0%, #EDF5F2 100%);
            border-bottom: 1px solid #E1EBE6;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .panel-title-wrap {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        /* STEP ORDER NUMBER BADGE */
        .panel-step-badge {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: #EAF5F0;
            color: var(--clinic-teal);
            font-weight: 800;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #C8E6D7;
            box-shadow: 0 2px 6px rgba(14, 107, 94, 0.12);
            flex-shrink: 0;
            font-family: "Plus Jakarta Sans", sans-serif;
        }

        .panel-icon-badge {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            background: linear-gradient(135deg, #0E6B5E, #059669);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            box-shadow: 0 4px 10px rgba(14, 107, 94, 0.25);
            flex-shrink: 0;
        }

        .panel-icon-gold {
            background: linear-gradient(135deg, #C9A227, #9B7812);
            box-shadow: 0 4px 10px rgba(201, 162, 39, 0.28);
        }

        .panel-icon-blue {
            background: linear-gradient(135deg, #0284c7, #0369a1);
            box-shadow: 0 4px 10px rgba(2, 132, 199, 0.25);
        }

        .panel-icon-purple {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            box-shadow: 0 4px 10px rgba(124, 58, 237, 0.25);
        }

        .panel-icon-dark {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            box-shadow: 0 4px 10px rgba(15, 23, 42, 0.25);
        }

        .settings-panel-head h2 {
            font-family: "Plus Jakarta Sans", system-ui, sans-serif;
            font-size: 16.5px;
            font-weight: 800;
            color: var(--text-dark);
            margin: 0;
        }

        .settings-panel-head p {
            font-size: 12px;
            color: var(--text-muted);
            margin: 2px 0 0 0;
        }

        .settings-panel-body {
            padding: 24px 26px;
        }

        /* CLINICAL SHIFT BREAKDOWN TILES */
        .shift-schedule-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-top: 14px;
            margin-bottom: 20px;
        }

        @media (max-width: 767px) {
            .shift-schedule-grid {
                grid-template-columns: 1fr;
            }
        }

        .shift-tile {
            background: #F8FBFA;
            border: 1px solid #D9EBE3;
            border-radius: 12px;
            padding: 14px 16px;
            text-align: center;
            position: relative;
        }

        .shift-tile.tile-lunch {
            background: #FFFBF2;
            border-color: #F4E4BC;
        }

        .shift-tile-badge {
            font-size: 10.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
            padding: 3px 10px;
            border-radius: 999px;
            display: inline-block;
            margin-bottom: 6px;
        }

        .badge-morning {
            background: #E0F2FE;
            color: #0369A1;
        }

        .badge-lunch {
            background: #FEF3C7;
            color: #92400E;
        }

        .badge-afternoon {
            background: #DCFCE7;
            color: #15803D;
        }

        .shift-tile-time {
            font-size: 13.5px;
            font-weight: 800;
            color: var(--text-dark);
            font-family: "Plus Jakarta Sans", monospace;
        }

        .shift-tile-sub {
            font-size: 11.5px;
            color: #64748B;
            margin-top: 3px;
        }

        /* FORM CONTROLS */
        .form-label {
            font-weight: 700;
            font-size: 12.5px;
            color: #244F60;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid #CFDFD7;
            padding: 9px 14px;
            font-size: 13.5px;
            color: #143F51;
            transition: all .2s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--clinic-mint);
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.18);
        }

        .input-hint {
            font-size: 11.5px;
            color: #6A7E88;
            margin-top: 5px;
        }

        .btn-clinic-save {
            background: linear-gradient(135deg, #0E6B5E 0%, #059669 100%);
            border: none;
            color: #ffffff;
            font-weight: 700;
            font-size: 13.5px;
            border-radius: 11px;
            padding: 10px 24px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 18px rgba(14, 107, 94, 0.24);
            transition: all .2s ease;
        }

        .btn-clinic-save:hover {
            filter: brightness(1.08);
            transform: translateY(-1px);
            color: #ffffff;
            box-shadow: 0 10px 22px rgba(14, 107, 94, 0.32);
        }

        /* DAY OFF TABLE / LIST */
        .dayoffs-list-wrap {
            border: 1px solid #E1EBE6;
            border-radius: 12px;
            overflow: hidden;
            margin-top: 14px;
        }

        .dayoff-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 18px;
            border-bottom: 1px solid #EDF4F1;
            background: #ffffff;
            transition: background .15s ease;
        }

        .dayoff-row:last-child {
            border-bottom: none;
        }

        .dayoff-row:hover {
            background: #F7FAF9;
        }

        .dayoff-date-badge {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .dayoff-cal-icon {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            background: #ECFDF5;
            color: var(--clinic-mint);
            border: 1px solid #D1FAE5;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
        }

        .dayoff-date-text {
            font-weight: 700;
            font-size: 13px;
            color: var(--text-dark);
        }

        .dayoff-reason-pill {
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 6px;
            background: #F1F5F9;
            color: #475569;
            border: 1px solid #E2E8F0;
            margin-left: 8px;
        }

        .btn-dayoff-del {
            background: #FEF2F2;
            border: 1px solid #FEE2E2;
            color: #DC2626;
            font-size: 11.5px;
            font-weight: 700;
            border-radius: 8px;
            padding: 5px 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all .15s ease;
        }

        .btn-dayoff-del:hover {
            background: #DC2626;
            color: #ffffff;
            border-color: #DC2626;
        }

        /* ALERT TOGGLE CARD BOXES */
        .alert-pref-card {
            background: #FAFDFB;
            border: 1px solid #E2ECE7;
            border-radius: 12px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            height: 100%;
            transition: border-color .2s ease;
        }

        .alert-pref-card:hover {
            border-color: #BBE0CE;
        }

        .toggle-item-info strong {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .toggle-item-info small {
            font-size: 11.5px;
            color: var(--text-muted);
            display: block;
            margin-top: 3px;
            line-height: 1.35;
        }

        .form-check-input {
            width: 44px;
            height: 22px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .form-check-input:checked {
            background-color: var(--clinic-mint);
            border-color: var(--clinic-mint);
        }

        /* PASSWORD WRAP & EYE TOGGLE */
        .password-input-wrap {
            position: relative;
        }

        .password-toggle-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #83979E;
            font-size: 15px;
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .password-toggle-btn:hover {
            color: var(--clinic-teal);
        }

        /* SECURITY BADGE CARD */
        .security-badge-box {
            background: linear-gradient(135deg, #F0FDF4 0%, #DCFCE7 100%);
            border: 1px solid #BBF7D0;
            border-radius: 12px;
            padding: 12px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .security-badge-icon {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: #ffffff;
            color: #15803D;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            box-shadow: 0 4px 10px rgba(22, 101, 52, 0.12);
            flex-shrink: 0;
        }

        .security-badge-text strong {
            display: block;
            font-size: 12.5px;
            font-weight: 800;
            color: #166534;
        }

        .security-badge-text small {
            display: block;
            font-size: 11px;
            color: #15803D;
            opacity: .9;
        }

        /* TOAST NOTIFICATION */
        #clinicToast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 2500;
            background: #0E6B5E;
            color: #ffffff;
            padding: 12px 20px;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 700;
            display: none;
            align-items: center;
            gap: 10px;
            box-shadow: 0 14px 34px rgba(10, 59, 52, 0.35);
            animation: toastSlideIn .25s ease;
        }

        @keyframes toastSlideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    </style>
</head>
<body>

<?php
$dentistActivePage = 'settings';
include __DIR__ . '/dentist_sidebar.php';
?>

<main class="portal-main">

    <!-- Clinic Branding Mini-Bar -->
    <div class="clinic-minibar">
        🦷 <span>Abalos Dental Clinic — Practitioner Administration Hub</span>
    </div>

    <!-- Portal Top Bar with Live Clinic Clock -->
    <header class="portal-topbar">
        <div>
            <div class="portal-heading" style="font-family:'Plus Jakarta Sans',sans-serif; font-size:22px; font-weight:800; color:#143F51;">
                Dentist Practice Settings
            </div>
            <div class="portal-subtitle" style="font-size:13px; color:#64748B;">
                Configure clinical availability, weekly rest days, patient notification alerts, and account credentials
            </div>
        </div>

        <div style="display:flex; align-items:center; gap:16px;">
            <div class="topbar-today" style="border-left:none; padding-left:0;">
                <i class="bi bi-calendar2-check-fill"></i>
                <div>
                    <strong><?= htmlspecialchars(date('l')) ?></strong>
                    <small><?= htmlspecialchars(date('M d, Y')) ?></small>
                </div>
            </div>

            <div class="topbar-time">
                <i class="bi bi-clock-fill"></i>
                <div>
                    <strong>Clinic Time</strong>
                    <small id="clinicTime">--:--:--</small>
                </div>
            </div>

            <div class="portal-user">
                <?= htmlspecialchars($fullName) ?>
                <div class="portal-role">Licensed Dentist</div>
            </div>
        </div>
    </header>

    <script>
    (function(){
        function tickClinicTime(){
            try {
                var el = document.getElementById('clinicTime');
                if(!el) return;
                var now = new Date();
                el.textContent = now.toLocaleTimeString('en-PH', {
                    timeZone: 'Asia/Manila',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: true
                });
            } catch(e){}
        }
        tickClinicTime();
        setInterval(tickClinicTime, 1000);
    })();
    </script>

    <div class="portal-content" style="padding: 26px 32px 40px 32px;">

        <!-- Alert Feedbacks -->
        <?php if ($success): ?>
            <div class="alert alert-success d-flex align-items-center gap-2 mb-3" style="width:100%; border-radius:12px; font-weight:600; font-size:13.5px; border-left:5px solid #059669;">
                <i class="bi bi-check-circle-fill text-success fs-5"></i>
                <div><?= htmlspecialchars($success) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="width:100%; border-radius:12px; font-weight:600; font-size:13.5px; border-left:5px solid #DC2626;">
                <i class="bi bi-exclamation-triangle-fill text-danger fs-5"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <!-- HERO BANNER (FULL WIDTH, SAKTO ANG SPACING) -->
        <div class="dentist-hero-card">
            <div class="dentist-hero-left">
                <div class="dentist-avatar-wrap">
                    <?php if (!empty($photoUrl)): ?>
                        <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Dr. <?= htmlspecialchars($fullName) ?>" class="dentist-avatar-img">
                    <?php else: ?>
                        <div class="dentist-avatar-fallback">
                            <?php
                            $words = preg_split('/\s+/', trim($fullName));
                            $initials = '';
                            foreach (array_slice($words, 0, 2) as $w) {
                                $initials .= strtoupper(substr($w, 0, 1));
                            }
                            echo htmlspecialchars($initials ?: 'DR');
                            ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="dentist-hero-details">
                    <h1>
                        <?= htmlspecialchars($fullName) ?>
                        <span class="hero-status-pill">
                            <span class="hero-pulse-dot"></span>
                            Active Practitioner
                        </span>
                    </h1>

                    <div style="font-size:13px; opacity:.9; margin-bottom:4px;">
                        <i class="bi bi-hospital me-1 text-warning"></i>
                        Abalos Dental Clinic — Main Practice
                    </div>

                    <div class="dentist-hero-badges">
                        <span class="hero-badge-item hero-badge-gold">
                            <i class="bi bi-award-fill"></i>
                            <?= htmlspecialchars($dentistSpec) ?>
                        </span>
                        <span class="hero-badge-item">
                            <i class="bi bi-person-vcard-fill"></i>
                            <?= htmlspecialchars($dentistCode) ?>
                        </span>
                        <span class="hero-badge-item">
                            <i class="bi bi-calendar-week-fill"></i>
                            Rest Day: <?= ($currentRestDay !== null && isset($dayNames[(int)$currentRestDay])) ? htmlspecialchars($dayNames[(int)$currentRestDay]) : 'None (Open)' ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="dentist-hero-actions">
                <a href="profile.php" class="btn hero-btn-light">
                    <i class="bi bi-person-circle"></i>
                    View Public Profile
                </a>
                <a href="appointments.php" class="btn hero-btn-outline">
                    <i class="bi bi-calendar2-week"></i>
                    Appointments (<?= $statUpcomingCount ?>)
                </a>
            </div>
        </div>

        <!-- ====================================================
             SEQUENTIAL FULL-WIDTH SETTINGS STACK (PAGSUNOD-SUNOD)
        ===================================================== -->
        <div class="settings-stack-container">

            <!-- ----------------------------------------------------
                 BOX 1: PRACTITIONER PROFILE & CONTACT DETAILS
            ----------------------------------------------------- -->
            <div class="settings-panel">
                <div class="settings-panel-head">
                    <div class="panel-title-wrap">
                        <span class="panel-step-badge">1</span>
                        <span class="panel-icon-badge panel-icon-blue">
                            <i class="bi bi-person-lines-fill"></i>
                        </span>
                        <div>
                            <h2>Practitioner Profile & Contact Details</h2>
                            <p>Manage your professional credentials, title, clinical specialty, and contact information</p>
                        </div>
                    </div>
                    <span class="badge rounded-pill bg-light text-secondary border px-3 py-1" style="font-size:11.5px; font-weight:700;">
                        Identity & Practice
                    </span>
                </div>

                <div class="settings-panel-body">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_practice_settings">
                        <input type="hidden" name="rest_day" value="<?= ($currentRestDay !== null && $currentRestDay !== '') ? (int)$currentRestDay : 'none' ?>">

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">
                                    <i class="bi bi-person-badge"></i>
                                    Dentist Full Name (with Title)
                                </label>
                                <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($fullName) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    <i class="bi bi-award"></i>
                                    Clinical Specialization
                                </label>
                                <select name="specialization" class="form-select">
                                    <?php
                                    $specialties = [
                                        'General Dentistry',
                                        'Orthodontics',
                                        'Endodontics',
                                        'Periodontics',
                                        'Prosthodontics',
                                        'Oral & Maxillofacial Surgery',
                                        'Pediatric Dentistry',
                                        'Cosmetic Dentistry'
                                    ];
                                    foreach ($specialties as $sp):
                                    ?>
                                        <option value="<?= htmlspecialchars($sp) ?>" <?= (strcasecmp($dentistSpec, $sp) === 0) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($sp) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    <i class="bi bi-telephone-fill"></i>
                                    Contact Number
                                </label>
                                <input type="text" name="contact_number" class="form-control" value="<?= htmlspecialchars($dentistContact) ?>" placeholder="0917xxxxxxx">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    <i class="bi bi-envelope-check-fill"></i>
                                    Registered Clinic Login Email
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted border-end-0">
                                        <i class="bi bi-shield-lock"></i>
                                    </span>
                                    <input type="email" class="form-control bg-light border-start-0" value="<?= htmlspecialchars($userEmail) ?>" disabled>
                                </div>
                                <div class="input-hint">
                                    System login identifier managed by Dental Clinic Administration.
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-clinic-save">
                                <i class="bi bi-check2"></i>
                                Save Practitioner Profile
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ----------------------------------------------------
                 BOX 2: CLINICAL AVAILABILITY & WEEKLY REST DAY
            ----------------------------------------------------- -->
            <div class="settings-panel">
                <div class="settings-panel-head">
                    <div class="panel-title-wrap">
                        <span class="panel-step-badge">2</span>
                        <span class="panel-icon-badge">
                            <i class="bi bi-calendar-range-fill"></i>
                        </span>
                        <div>
                            <h2>Clinical Availability & Weekly Rest Day</h2>
                            <p>Configure your regular weekly rest day and review standard clinic chair shifts</p>
                        </div>
                    </div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1" style="font-size:11.5px; font-weight:700;">
                        Live Booking Sync
                    </span>
                </div>

                <div class="settings-panel-body">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_practice_settings">
                        <input type="hidden" name="full_name" value="<?= htmlspecialchars($fullName) ?>">
                        <input type="hidden" name="contact_number" value="<?= htmlspecialchars($dentistContact) ?>">
                        <input type="hidden" name="specialization" value="<?= htmlspecialchars($dentistSpec) ?>">

                        <div class="row g-3 mb-3 align-items-center">
                            <div class="col-md-6">
                                <label class="form-label">
                                    <i class="bi bi-calendar2-day text-success"></i>
                                    Weekly Regular Rest Day
                                </label>
                                <select name="rest_day" class="form-select">
                                    <option value="none" <?= ($currentRestDay === null || $currentRestDay === '') ? 'selected' : '' ?>>
                                        None / Open Everyday (Monday – Saturday)
                                    </option>
                                    <?php foreach ($dayNames as $val => $name): ?>
                                        <option value="<?= $val ?>" <?= ($currentRestDay !== null && (int)$currentRestDay === $val) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($name) ?> <?= $val === 0 ? '(Standard Clinic Rest Day)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="input-hint">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Patients will not be permitted to book appointments on this selected day of the week.
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="p-3" style="background:#F6FAF8; border:1px solid #D6E8DF; border-radius:12px;">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <i class="bi bi-shield-check text-success fs-5"></i>
                                        <strong style="font-size:13px; color:#143F51;">Calendar Booking Synchronization</strong>
                                    </div>
                                    <div style="font-size:12px; color:#5A726A; line-height:1.45;">
                                        Updating your weekly rest day automatically updates the patient booking calendar so no double bookings or unavailable slots occur.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <label class="form-label">
                                <i class="bi bi-clock-history text-success"></i>
                                Standard Clinical Operating Hours
                            </label>
                            <div class="shift-schedule-grid">
                                <div class="shift-tile">
                                    <span class="shift-tile-badge badge-morning">Morning Shift</span>
                                    <div class="shift-tile-time">08:00 AM – 12:00 PM</div>
                                    <div class="shift-tile-sub">Consultations & Dental Prophylaxis</div>
                                </div>
                                <div class="shift-tile tile-lunch">
                                    <span class="shift-tile-badge badge-lunch">Mandatory Break</span>
                                    <div class="shift-tile-time">12:00 PM – 01:00 PM</div>
                                    <div class="shift-tile-sub">Sanitization & Doctor Lunch</div>
                                </div>
                                <div class="shift-tile">
                                    <span class="shift-tile-badge badge-afternoon">Afternoon Shift</span>
                                    <div class="shift-tile-time">01:00 PM – 06:00 PM</div>
                                    <div class="shift-tile-sub">Procedures, Surgery & Fillings</div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-clinic-save">
                                <i class="bi bi-check2-circle"></i>
                                Save Availability Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ----------------------------------------------------
                 BOX 3: SCHEDULED CLINICAL DAY-OFFS & LEAVES
            ----------------------------------------------------- -->
            <div class="settings-panel">
                <div class="settings-panel-head">
                    <div class="panel-title-wrap">
                        <span class="panel-step-badge">3</span>
                        <span class="panel-icon-badge panel-icon-gold">
                            <i class="bi bi-calendar-x-fill"></i>
                        </span>
                        <div>
                            <h2>Scheduled Day-Offs & Clinical Leaves</h2>
                            <p>Schedule temporary days off for conferences, seminars, emergency or personal leave</p>
                        </div>
                    </div>
                    <span class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-1" style="font-size:11.5px; font-weight:700;">
                        <?= count($upcomingDayOffs) ?> Filed
                    </span>
                </div>

                <div class="settings-panel-body">
                    <!-- Add Day-Off Form -->
                    <form method="POST" class="p-3 mb-3" style="background:#F7FAF9; border:1px solid #D9EBE3; border-radius:12px;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="add_dayoff">

                        <div class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label" style="font-size:12px;">
                                    <i class="bi bi-calendar-event"></i>
                                    Select Date
                                </label>
                                <input type="date" name="off_date" class="form-control form-control-sm" min="<?= date('Y-m-d') ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" style="font-size:12px;">
                                    <i class="bi bi-chat-left-text"></i>
                                    Reason / Note
                                </label>
                                <select name="reason" class="form-select form-select-sm">
                                    <option value="Continuing Education / Seminar">Continuing Education / Seminar</option>
                                    <option value="Personal Leave">Personal Leave</option>
                                    <option value="Medical Rest">Medical Rest</option>
                                    <option value="Emergency Leave">Emergency Leave</option>
                                    <option value="Official Business">Official Business</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <button type="submit" class="btn btn-clinic-save btn-sm w-100" style="padding:7px 10px; font-size:12.5px;">
                                    <i class="bi bi-plus-lg"></i>
                                    File Day-Off
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- List of Upcoming Day-offs -->
                    <label class="form-label">
                        <i class="bi bi-list-check text-success"></i>
                        Your Scheduled Day-Offs & Leaves
                    </label>

                    <?php if (empty($upcomingDayOffs)): ?>
                        <div class="p-4 text-center" style="background:#FAFDFB; border:1px dashed #CFDFD7; border-radius:12px;">
                            <i class="bi bi-calendar-check text-success fs-3 d-block mb-1"></i>
                            <strong style="font-size:13.5px; color:#143F51;">No Upcoming Day-Offs Filed</strong>
                            <p class="text-muted small m-0">You are currently available on all regular clinic days without scheduled leaves.</p>
                        </div>
                    <?php else: ?>
                        <div class="dayoffs-list-wrap">
                            <?php foreach ($upcomingDayOffs as $off): ?>
                                <div class="dayoff-row">
                                    <div class="dayoff-date-badge">
                                        <div class="dayoff-cal-icon">
                                            <i class="bi bi-calendar-x"></i>
                                        </div>
                                        <div>
                                            <div class="dayoff-date-text">
                                                <?= date('F d, Y (l)', strtotime($off['off_date'])) ?>
                                                <span class="dayoff-reason-pill"><?= htmlspecialchars($off['reason'] ?: 'Leave') ?></span>
                                            </div>
                                            <small class="text-muted" style="font-size:11px;">
                                                Registered: <?= date('M d, Y', strtotime($off['created_at'])) ?>
                                            </small>
                                        </div>
                                    </div>

                                    <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this scheduled day-off?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_dayoff">
                                        <input type="hidden" name="dayoff_id" value="<?= (int)$off['id'] ?>">
                                        <button type="submit" class="btn-dayoff-del">
                                            <i class="bi bi-trash3-fill"></i>
                                            Cancel Leave
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ----------------------------------------------------
                 BOX 4: CLINICAL ALERTS & AUDIO NOTIFICATIONS
            ----------------------------------------------------- -->
            <div class="settings-panel">
                <div class="settings-panel-head">
                    <div class="panel-title-wrap">
                        <span class="panel-step-badge">4</span>
                        <span class="panel-icon-badge panel-icon-purple">
                            <i class="bi bi-bell-fill"></i>
                        </span>
                        <div>
                            <h2>Clinical Alerts & Audio Notifications</h2>
                            <p>Configure real-time alerts for assigned patients, queue check-ins, and severe pain emergency flags</p>
                        </div>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-success" id="testChimeBtn" style="border-radius:8px; font-weight:700; font-size:11.5px;">
                        <i class="bi bi-volume-up-fill me-1"></i>
                        Test Chime
                    </button>
                </div>

                <div class="settings-panel-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="alert-pref-card">
                                <div class="toggle-item-info pe-2">
                                    <strong>
                                        <i class="bi bi-calendar2-plus text-success"></i>
                                        New Appointment Assigned
                                    </strong>
                                    <small>Audible chime & banner notification when an appointment is assigned to you.</small>
                                </div>
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input js-pref-toggle" type="checkbox" data-pref="alert_appt" checked role="switch">
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="alert-pref-card">
                                <div class="toggle-item-info pe-2">
                                    <strong>
                                        <i class="bi bi-person-walking text-primary"></i>
                                        Patient Queue Check-in
                                    </strong>
                                    <small>Alert when your scheduled patient arrives and checks in at the reception.</small>
                                </div>
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input js-pref-toggle" type="checkbox" data-pref="alert_queue" checked role="switch">
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="alert-pref-card">
                                <div class="toggle-item-info pe-2">
                                    <strong>
                                        <i class="bi bi-exclamation-octagon-fill text-danger"></i>
                                        Urgent Symptom & Pain Flag
                                    </strong>
                                    <small>High-priority alert for patients reporting severe dental pain or acute swelling.</small>
                                </div>
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input js-pref-toggle" type="checkbox" data-pref="alert_emergency" checked role="switch">
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="alert-pref-card">
                                <div class="toggle-item-info pe-2">
                                    <strong>
                                        <i class="bi bi-arrow-repeat text-warning"></i>
                                        Cancellation & Reschedule
                                    </strong>
                                    <small>Receive immediate notification when an appointment slot is adjusted.</small>
                                </div>
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input js-pref-toggle" type="checkbox" data-pref="alert_resched" checked role="switch">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <button type="button" class="btn btn-clinic-save btn-sm" id="saveAlertPrefsBtn">
                            <i class="bi bi-sliders"></i>
                            Save Alert Preferences
                        </button>
                    </div>
                </div>
            </div>

            <!-- ----------------------------------------------------
                 BOX 5: ACCOUNT PASSWORD & PORTAL SECURITY
            ----------------------------------------------------- -->
            <div class="settings-panel">
                <div class="settings-panel-head">
                    <div class="panel-title-wrap">
                        <span class="panel-step-badge">5</span>
                        <span class="panel-icon-badge panel-icon-dark">
                            <i class="bi bi-shield-lock-fill"></i>
                        </span>
                        <div>
                            <h2>Account Security & Portal Password</h2>
                            <p>Update your practitioner portal access credentials and review session encryption</p>
                        </div>
                    </div>
                    <span class="badge rounded-pill bg-light text-secondary border px-3 py-1" style="font-size:11.5px; font-weight:700;">
                        Encrypted Access
                    </span>
                </div>

                <div class="settings-panel-body">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="change_password">

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">
                                    <i class="bi bi-key-fill text-muted"></i>
                                    Current Password
                                </label>
                                <div class="password-input-wrap">
                                    <input type="password" name="current_password" id="current_pw" class="form-control" required>
                                    <button type="button" class="password-toggle-btn" data-toggle-pw="current_pw">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">
                                    <i class="bi bi-shield-plus text-success"></i>
                                    New Password
                                </label>
                                <div class="password-input-wrap">
                                    <input type="password" name="new_password" id="new_pw" class="form-control" minlength="6" required>
                                    <button type="button" class="password-toggle-btn" data-toggle-pw="new_pw">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="input-hint">Minimum 6 characters</div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">
                                    <i class="bi bi-shield-check text-success"></i>
                                    Confirm Password
                                </label>
                                <div class="password-input-wrap">
                                    <input type="password" name="confirm_password" id="conf_pw" class="form-control" minlength="6" required>
                                    <button type="button" class="password-toggle-btn" data-toggle-pw="conf_pw">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-4">
                            <div class="security-badge-box m-0">
                                <div class="security-badge-icon">
                                    <i class="bi bi-shield-fill-check"></i>
                                </div>
                                <div class="security-badge-text">
                                    <strong>Practitioner Session Protected</strong>
                                    <small>Password encrypted with PHP BCRYPT algorithm. TLS Session active.</small>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-clinic-save">
                                <i class="bi bi-lock-fill"></i>
                                Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>

    </div>

</main>

<!-- FLOATING TOAST NOTIFICATION -->
<div id="clinicToast">
    <i class="bi bi-check-circle-fill fs-5 text-warning"></i>
    <span id="clinicToastMsg">Settings saved successfully.</span>
</div>

<!-- DENTIST LOGOUT CONFIRM MODAL -->
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
            <p>You will need to log in again to access your dentist practitioner portal.</p>
        </div>
        <div class="dentist-logout-foot">
            <button type="button" class="btn btn-light" id="dentistLogoutCancel">Cancel</button>
            <a href="../logout.php" class="btn dentist-logout-yes" id="dentistLogoutYes"><i class="bi bi-box-arrow-right me-1"></i>Log Out</a>
        </div>
    </div>
</div>

<style>
.dentist-logout-overlay { position: fixed; inset: 0; background: rgba(10,59,52,.55); z-index: 3000; padding: 20px; align-items: center; justify-content: center; }
.dentist-logout-box { background: #ffffff; border-radius: 18px; border: 1px solid #C9E9DA; box-shadow: 0 18px 45px rgba(0,0,0,.25); max-width: 440px; width: 100%; overflow: hidden; font-family: "DM Sans", system-ui, sans-serif; animation: dentistLogoutPop .18s ease; }
@keyframes dentistLogoutPop { from { transform: scale(.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.dentist-logout-head { display: flex; align-items: center; gap: 12px; padding: 18px 22px; background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%); border-bottom: 1px solid #C9E9DA; }
.dentist-logout-head-icon { width: 38px; height: 38px; border-radius: 11px; background: linear-gradient(135deg, #0E6B5E, #0A4A41); color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 4px 10px rgba(14,107,94,.25); flex-shrink: 0; }
.dentist-logout-head h5 { color: #143f51; font-family: "Plus Jakarta Sans", system-ui, sans-serif; font-weight: 800; font-size: 17px; margin: 0; flex: 1; }
.dentist-logout-x { background: #ffffff; border: 1px solid #D1E7DD; color: #57727d; width: 30px; height: 30px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; flex-shrink: 0; }
.dentist-logout-x:hover { background: #EAF7F0; color: #0E6B5E; }
.dentist-logout-body { padding: 30px 28px 8px; text-align: center; }
.dentist-logout-icon { width: 64px; height: 64px; border-radius: 18px; background: #FDECEC; color: #C0392B; border: 1px solid #F5C6C6; display: inline-flex; align-items: center; justify-content: center; font-size: 30px; margin-bottom: 14px; }
.dentist-logout-body h5 { color: #143f51; font-family: "Plus Jakarta Sans", system-ui, sans-serif; font-weight: 800; font-size: 18px; margin: 0 0 8px; }
.dentist-logout-body p { color: #8aa0a8; font-size: 13.5px; margin: 0; }
.dentist-logout-foot { display: flex; gap: 12px; justify-content: center; padding: 18px 22px 22px; background: #F6FBF8; border-top: 1px solid #E6F2EC; margin-top: 20px; }
.dentist-logout-foot .btn { border-radius: 10px; font-weight: 700; font-size: 14px; padding: 10px 26px; }
.dentist-logout-foot .btn-light { background: #fff !important; border: 1px solid #D1E7DD !important; color: #57727d !important; }
.dentist-logout-foot .btn-light:hover { background: #EAF7F0 !important; color: #0E6B5E !important; }
.dentist-logout-yes { background: linear-gradient(135deg, #E74C3C, #922B21) !important; border: none !important; color: #ffffff !important; box-shadow: 0 6px 14px rgba(192,57,43,.3) !important; }
.dentist-logout-yes:hover { filter: brightness(.93); color: #ffffff !important; }
</style>

<script>
// Show/Hide Password Eye Toggle
document.querySelectorAll('[data-toggle-pw]').forEach(function(btn){
    btn.addEventListener('click', function(){
        var targetId = this.getAttribute('data-toggle-pw');
        var input = document.getElementById(targetId);
        if (!input) return;
        var icon = this.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            if (icon) {
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            }
        } else {
            input.type = 'password';
            if (icon) {
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }
    });
});

// Toast notification helper
function showClinicToast(msg) {
    var toast = document.getElementById('clinicToast');
    var msgEl = document.getElementById('clinicToastMsg');
    if (!toast || !msgEl) return;
    msgEl.textContent = msg;
    toast.style.display = 'flex';
    setTimeout(function(){
        toast.style.display = 'none';
    }, 3200);
}

// Gentle Dental Clinic Two-Tone Audio Chime using Web Audio API
function playClinicChime() {
    try {
        var AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        var ctx = new AudioContext();

        // Note 1: E5 (659.25 Hz)
        var osc1 = ctx.createOscillator();
        var gain1 = ctx.createGain();
        osc1.type = 'sine';
        osc1.frequency.setValueAtTime(659.25, ctx.currentTime);
        gain1.gain.setValueAtTime(0.2, ctx.currentTime);
        gain1.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
        osc1.connect(gain1);
        gain1.connect(ctx.destination);
        osc1.start(ctx.currentTime);
        osc1.stop(ctx.currentTime + 0.35);

        // Note 2: A5 (880.00 Hz) slightly delayed
        var osc2 = ctx.createOscillator();
        var gain2 = ctx.createGain();
        osc2.type = 'sine';
        osc2.frequency.setValueAtTime(880.0, ctx.currentTime + 0.12);
        gain2.gain.setValueAtTime(0.22, ctx.currentTime + 0.12);
        gain2.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.55);
        osc2.connect(gain2);
        gain2.connect(ctx.destination);
        osc2.start(ctx.currentTime + 0.12);
        osc2.stop(ctx.currentTime + 0.55);
    } catch(e) {
        console.warn('Audio chime unsupported or blocked:', e);
    }
}

document.getElementById('testChimeBtn')?.addEventListener('click', function(){
    playClinicChime();
    showClinicToast('Playing dental alert chime preview.');
});

// Load and Save Alert Preferences in LocalStorage
(function(){
    var storageKey = 'dentist_alert_prefs_<?= (int)$userId ?>';
    try {
        var saved = JSON.parse(localStorage.getItem(storageKey) || '{}');
        document.querySelectorAll('.js-pref-toggle').forEach(function(el){
            var pref = el.getAttribute('data-pref');
            if (pref in saved) {
                el.checked = !!saved[pref];
            }
        });
    } catch(e){}

    document.getElementById('saveAlertPrefsBtn')?.addEventListener('click', function(){
        try {
            var prefs = {};
            document.querySelectorAll('.js-pref-toggle').forEach(function(el){
                var pref = el.getAttribute('data-pref');
                prefs[pref] = el.checked;
            });
            localStorage.setItem(storageKey, JSON.stringify(prefs));
            playClinicChime();
            showClinicToast('Clinical alert preferences saved successfully.');
        } catch(e){
            showClinicToast('Unable to save preferences.');
        }
    });
})();

// Logout Modal logic
(function(){
    function openDentistLogout(e){
        if (e) e.preventDefault();
        var overlay = document.getElementById("dentistLogoutConfirm");
        if (!overlay) return;
        overlay.style.display = "flex";
        document.body.style.overflow = "hidden";
    }
    function closeDentistLogout(){
        var overlay = document.getElementById("dentistLogoutConfirm");
        if (!overlay) return;
        overlay.style.display = "none";
        document.body.style.overflow = "";
    }
    function initDentistLogout(){
        var overlay = document.getElementById("dentistLogoutConfirm");
        if (!overlay || overlay.dataset.bound === "1") return;
        overlay.dataset.bound = "1";
        document.querySelectorAll('a[href="../logout.php"], a[href="logout.php"]').forEach(function(a){
            if (a.closest("#dentistLogoutConfirm")) return;
            if (a.dataset.logoutBound === "1") return;
            a.dataset.logoutBound = "1";
            a.addEventListener("click", openDentistLogout);
        });
        document.getElementById("dentistLogoutCancel")?.addEventListener("click", closeDentistLogout);
        document.getElementById("dentistLogoutClose")?.addEventListener("click", closeDentistLogout);
        overlay.addEventListener("click", function(e){
            if (e.target === overlay) closeDentistLogout();
        });
        document.addEventListener("keydown", function(e){
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
