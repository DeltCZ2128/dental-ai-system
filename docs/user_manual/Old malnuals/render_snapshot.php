<?php
// Snapshot helper for documentation images
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

$page = $_GET['page'] ?? 'login';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config.php';

// Set up authentic session based on requested page
if (strpos($page, 'admin_') === 0) {
    $_SESSION['user_id'] = 9; // System Administrator
    $_SESSION['role'] = 'admin';
    $_SESSION['username'] = 'admin@dental.com';
    $_SESSION['full_name'] = 'System Administrator';
} elseif (strpos($page, 'dentist_') === 0) {
    $_SESSION['user_id'] = 10; // Dr. Juan Dela Cruz
    $_SESSION['role'] = 'dentist';
    $_SESSION['dentist_id'] = 1;
    $_SESSION['username'] = 'dentist@gmail.com';
    $_SESSION['full_name'] = 'Dr. Juan Dela Cruz';
} elseif (strpos($page, 'patient_') === 0) {
    $_SESSION['user_id'] = 4; // Rocel Tagudar
    $_SESSION['role'] = 'patient';
    $_SESSION['patient_id'] = 1;
    $_SESSION['username'] = 'roceltagudar1@gmail.com';
    $_SESSION['full_name'] = 'Rocel Tagudar';
} else {
    // Public / auth pages
    unset($_SESSION['user_id'], $_SESSION['role']);
}

// Set base href to dental-ai-system-fixed so assets load properly
ob_start();

switch ($page) {
    case 'verify_otp':
        $_SESSION['pending_registration'] = [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria.santos@gmail.com',
            'contact_number' => '09171234567'
        ];
        require __DIR__ . '/../../verify_otp.php';
        break;
    case 'forgot_password':
        require __DIR__ . '/../../forgot_password.php';
        break;
    case 'walkin_qr':
        require __DIR__ . '/../../walkin_qr.php';
        break;
    case 'patient_concern':
        require __DIR__ . '/../../patient/appointment_concern.php';
        break;
    case 'patient_notifications':
        require __DIR__ . '/../../patient/notifications.php';
        break;
    case 'patient_profile':
        require __DIR__ . '/../../patient/profile.php';
        break;
    case 'dentist_settings':
        require __DIR__ . '/../../dentist/settings.php';
        break;
    case 'admin_settings':
        require __DIR__ . '/../../admin/settings.php';
        break;
    case 'admin_backup':
        require __DIR__ . '/../../admin/backup.php';
        break;
    case 'admin_concern':
        require __DIR__ . '/../../admin/appointment_concern.php';
        break;
    case 'admin_reports_forecast':
        $_GET['tab'] = 'forecast';
        require __DIR__ . '/../../admin/reports.php';
        break;
    default:
        require __DIR__ . '/../../login.php';
        break;
}

$html = ob_get_clean();

// Inject base tag so relative CSS/JS paths resolve to root
if (stripos($html, '<head>') !== false) {
    $html = preg_replace('/<head>/i', '<head><base href="/dental-ai-system-fixed/">', $html, 1);
}

echo $html;
