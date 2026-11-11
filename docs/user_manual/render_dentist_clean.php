<?php
// Session initiator for authentic screenshot captures with optional auto-scroll
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config.php';

$role = $_GET['role'] ?? 'dentist';
$page = $_GET['page'] ?? 'settings';
$scroll = $_GET['scroll'] ?? '';

if ($role === 'dentist') {
    $_SESSION['user_id'] = 10;
    $_SESSION['role'] = 'dentist';
    $_SESSION['dentist_id'] = 1;
    $_SESSION['username'] = 'dentist@gmail.com';
    $_SESSION['full_name'] = 'Dr. Juan Dela Cruz';
}

if ($page === 'settings') {
    // Buffer output of dentist/settings.php
    ob_start();
    chdir(__DIR__ . '/../../dentist');
    require __DIR__ . '/../../dentist/settings.php';
    $html = ob_get_clean();

    // Fix relative links if needed
    if ($scroll === 'restdays') {
        $inject = '<script>
        window.addEventListener("DOMContentLoaded", function() {
            setTimeout(function() {
                var panels = document.querySelectorAll(".settings-panel");
                if (panels.length >= 2) {
                    panels[1].scrollIntoView({behavior: "instant", block: "start"});
                }
            }, 300);
        });
        </script>';
        $html = str_replace('</body>', $inject . '</body>', $html);
    }
    echo $html;
    exit;
}
