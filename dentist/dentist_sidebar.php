<?php
/*
|--------------------------------------------------------------------------
| DENTIST SIDEBAR (shared)
|--------------------------------------------------------------------------
| Eksaktong kapareho ng dashboard sidebar (dentist_dashboard.php).
|
| Gamit sa page:
|     $dentistActivePage = 'appointments'; // | queue | patients |
|         // notifications | profile | settings | dashboard
|     include __DIR__ . '/dentist_sidebar.php';
|
| Naka-class na .portal-sidebar para gumana pa rin ang ?nosidebar=1.
|--------------------------------------------------------------------------
*/

$dentistActivePage = $dentistActivePage ?? '';
?>
<aside class="portal-sidebar sidebar patient-sidebar">

    <div class="patient-brand">

        <div class="brand-icon">
            <img
                src="../assets/dental-ai.jpg"
                onerror="this.onerror=null;this.src='../assets/dental-bg.jpg';"
                alt="Abalos Dental Clinic logo"
                width="44"
                height="44"
            >
        </div>

        <div class="brand-text">
            <div class="brand-title">Abalos Dental</div>
            <div class="brand-subtitle">Dentist Portal</div>
        </div>

    </div>

    <nav class="patient-menu">

        <div class="menu-label">MAIN</div>

        <a href="dentist_dashboard.php" class="<?= $dentistActivePage === 'dashboard' ? 'active' : '' ?>">
            <i class="bi bi-grid-fill"></i>
            <span>Dashboard</span>
        </a>

        <div class="menu-divider"></div>

        <div class="menu-label">MANAGEMENT</div>

        <a href="appointments.php" class="<?= $dentistActivePage === 'appointments' ? 'active' : '' ?>">
            <i class="bi bi-calendar2-check-fill"></i>
            <span>Appointments</span>
        </a>

        <a href="queue.php" class="<?= $dentistActivePage === 'queue' ? 'active' : '' ?>">
            <i class="bi bi-list-ol"></i>
            <span>Queue</span>
        </a>

        <a href="patients.php" class="<?= $dentistActivePage === 'patients' ? 'active' : '' ?>">
            <i class="bi bi-people-fill"></i>
            <span>Patients</span>
        </a>

        <a href="notifications.php" class="<?= $dentistActivePage === 'notifications' ? 'active' : '' ?>">
            <i class="bi bi-bell-fill"></i>
            <span>Notifications</span>
        </a>

        <a href="profile.php" class="<?= $dentistActivePage === 'profile' ? 'active' : '' ?>">
            <i class="bi bi-person-fill"></i>
            <span>Profile</span>
        </a>

        <a href="settings.php" class="<?= $dentistActivePage === 'settings' ? 'active' : '' ?>">
            <i class="bi bi-gear-fill"></i>
            <span>Settings</span>
        </a>

        <div class="menu-divider"></div>

        <div class="menu-label">ACCOUNT</div>

        <a href="../logout.php" class="logout-link">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

    <div class="dentist-tagline">
        <div class="dentist-tagline-tooth">🦷</div>
        <div class="dentist-tagline-text">Healthy Smiles.<br>Brighter Futures</div>
    </div>

</aside>
