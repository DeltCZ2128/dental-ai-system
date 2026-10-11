<?php

$currentPage = basename($_SERVER['PHP_SELF']);
$activeAdminPage = $_GET['page'] ?? str_replace('.php', '', $currentPage);

function isActive($page)
{
    global $activeAdminPage;
    return $activeAdminPage === str_replace('.php', '', $page) ? 'active' : '';
}

$pageTitles = [
    'dashboard.php' => ['Dashboard', 'Clinic overview and appointment management'],
    'patients.php' => ['Patients', ''],
    'appointments.php' => ['Appointments', 'Manage and schedule dental appointments'],
    'dentists.php' => ['Dentists', 'Manage registered dentists and schedules'],
    'queue.php' => ['Queue', "Manage today's patient queue"],
    'reports.php' => ['Reports', 'Clinic performance and appointment analytics'],
    'settings.php' => ['Settings', 'Manage clinic information and preferences']
];

$pageTitle = $pageTitles[$currentPage][0] ?? 'Dashboard';
$pageSubtitle = $pageTitles[$currentPage][1] ?? 'Clinic overview and appointment management';

$username = $_SESSION['username'] ?? 'System Administrator';

?>

<!-- SIDEBAR: same visual structure as dashboard.php -->
<aside class="sidebar shared-sidebar">

    <div class="sidebar-brand">

        <div class="brand-logo">
            <img
                src="../assets/dental-bg.jpg"
                alt="Abalos Dental Clinic logo"
                width="44"
                height="44"
            >
        </div>

        <div>
            <div class="brand-name">
                Abalos Dental
            </div>

            <div class="brand-sub">
                DENTAL CLINIC SYSTEM
            </div>
        </div>

    </div>

    <div class="sidebar-nav">

        <div class="nav-title">
            MAIN MENU
        </div>

        <a href="dashboard.php"
           class="sidebar-link <?= isActive('dashboard.php') ?>">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a href="patients.php"
           class="sidebar-link <?= isActive('patients.php') ?>">
            <i class="bi bi-people-fill"></i>
            <span>Patients</span>
        </a>

        <a href="appointments.php"
           class="sidebar-link <?= isActive('appointments.php') ?>">
            <i class="bi bi-calendar2-check-fill"></i>
            <span>Appointments</span>
        </a>

        <a href="dentists.php"
           class="sidebar-link <?= isActive('dentists.php') ?>">
            <i class="bi bi-person-workspace"></i>
            <span>Dentists</span>
        </a>

        <a href="queue.php"
           class="sidebar-link <?= isActive('queue.php') ?>">
            <i class="bi bi-list-ol"></i>
            <span>Queue</span>
        </a>

        <a href="reports.php"
           class="sidebar-link <?= isActive('reports.php') ?>">
            <i class="bi bi-bar-chart-fill"></i>
            <span>Reports</span>
        </a>

        <a href="notifications.php"
           class="sidebar-link <?= isActive('notifications.php') ?>">
            <i class="bi bi-bell-fill"></i>
            <span>Notifications</span>
        </a>

        <a href="settings.php"
           class="sidebar-link <?= isActive('settings.php') ?>">
            <i class="bi bi-gear-fill"></i>
            <span>Settings</span>
        </a>

    </div>

    <div class="sidebar-bottom">

        <a href="../logout.php" class="sidebar-link">
            <i class="bi bi-box-arrow-left"></i>
            <span>Logout</span>
        </a>

    </div>

</aside>

<!-- TOPBAR: same visual structure as dashboard.php -->
<header class="topbar shared-topbar">

    <div class="d-flex align-items-center gap-3">

        <div class="page-title">
            <h1><?= htmlspecialchars($pageTitle) ?></h1>
            <p><?= htmlspecialchars($pageSubtitle) ?></p>
        </div>

    </div>

    <div class="admin-area">

        <div class="admin-info">
            <strong><?= htmlspecialchars($username) ?></strong>
            <span>Clinic Administrator</span>
        </div>

        <div class="admin-avatar">
            <i class="bi bi-person-fill"></i>
        </div>

    </div>

</header>
