<?php
// Session initiator for authentic screenshot captures
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config.php';

$role = $_GET['role'] ?? 'admin';
$redirect = $_GET['redirect'] ?? '/dental-ai-system-fixed/admin/dashboard.php';

if ($role === 'dentist') {
    $_SESSION['user_id'] = 10;
    $_SESSION['role'] = 'dentist';
    $_SESSION['dentist_id'] = 1;
    $_SESSION['username'] = 'dentist@gmail.com';
    $_SESSION['full_name'] = 'Dr. Juan Dela Cruz';
} elseif ($role === 'patient') {
    $_SESSION['user_id'] = 4;
    $_SESSION['role'] = 'patient';
    $_SESSION['patient_id'] = 1;
    $_SESSION['username'] = 'roceltagudar1@gmail.com';
    $_SESSION['full_name'] = 'Rocel Tagudar';
} elseif ($role === 'admin') {
    $_SESSION['user_id'] = 9;
    $_SESSION['role'] = 'admin';
    $_SESSION['username'] = 'admin@dental.com';
    $_SESSION['full_name'] = 'System Administrator';
} else {
    session_destroy();
}

header("Location: " . $redirect);
exit;
