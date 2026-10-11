<?php
/*
|--------------------------------------------------------------------------
| SHARED PATIENT SIDEBAR
|--------------------------------------------------------------------------
| Ito ang iisang sidebar na gagamitin ng lahat ng patient pages.
| Huwag maglagay ng ibang sidebar sa bawat page.
|--------------------------------------------------------------------------
*/

$currentPage = basename($_SERVER['PHP_SELF']);

$upcomingConcernAppointment = null;
$sidebarDisplayName = trim((string)($_SESSION['full_name'] ?? ''));
$sidebarDisplayEmail = '';
$sidebarPatientNumber = '';

try {

    if (
        isset($_SESSION['user_id']) &&
        strtolower(trim($_SESSION['role'] ?? '')) === 'patient'
    ) {

        $sidebarUserId = (int) $_SESSION['user_id'];

        $sidebarUserStmt = $pdo->prepare("
            SELECT username, full_name, contact_number
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $sidebarUserStmt->execute([$sidebarUserId]);
        $sidebarUser = $sidebarUserStmt->fetch(PDO::FETCH_ASSOC);

        if ($sidebarUser) {
            if ($sidebarDisplayName === '' && !empty($sidebarUser['full_name'])) {
                $sidebarDisplayName = trim((string)$sidebarUser['full_name']);
            }
            if (!empty($sidebarUser['username']) && strpos((string)$sidebarUser['username'], '@') !== false) {
                $sidebarDisplayEmail = trim((string)$sidebarUser['username']);
            }
        }

        /* Get patient */
        $sidebarStmt = $pdo->prepare("
            SELECT id, patient_number, first_name, last_name, email
            FROM patients
            WHERE user_id = ?
            LIMIT 1
        ");

        $sidebarStmt->execute([$sidebarUserId]);

        $sidebarPatient = $sidebarStmt->fetch(PDO::FETCH_ASSOC);

        /* Fallback using email */
        if (!$sidebarPatient && $sidebarDisplayEmail !== '') {

            $sidebarStmt = $pdo->prepare("
                SELECT id, patient_number, first_name, last_name, email
                FROM patients
                WHERE email = ?
                LIMIT 1
            ");

            $sidebarStmt->execute([
                $sidebarDisplayEmail
            ]);

            $sidebarPatient =
                $sidebarStmt->fetch(PDO::FETCH_ASSOC);
        }

        /* Get next appointment */
        if ($sidebarPatient) {

            $sidebarPatientId =
                (int) $sidebarPatient['id'];

            $pFull = trim(($sidebarPatient['first_name'] ?? '') . ' ' . ($sidebarPatient['last_name'] ?? ''));
            if ($pFull !== '') {
                $sidebarDisplayName = $pFull;
            }
            if (!empty($sidebarPatient['email'])) {
                $sidebarDisplayEmail = trim((string)$sidebarPatient['email']);
            }
            if (!empty($sidebarPatient['patient_number'])) {
                $sidebarPatientNumber = trim((string)$sidebarPatient['patient_number']);
            }

            $sidebarStmt = $pdo->prepare("
                SELECT
                    id,
                    appointment_date,
                    start_time,
                    status
                FROM appointments
                WHERE patient_id = ?
                  AND appointment_date >= CURDATE()
                  AND status IN ('Pending', 'Confirmed')
                ORDER BY
                    appointment_date ASC,
                    start_time ASC
                LIMIT 1
            ");

            $sidebarStmt->execute([
                $sidebarPatientId
            ]);

            $upcomingConcernAppointment =
                $sidebarStmt->fetch(PDO::FETCH_ASSOC);
        }
    }

} catch (Throwable $e) {

    /*
     * Kapag may database issue,
     * huwag sirain ang sidebar.
     */
    $upcomingConcernAppointment = null;
}

if ($sidebarDisplayName === '') {
    $sidebarDisplayName = 'Patient';
}


/*
|--------------------------------------------------------------------------
| APPOINTMENT CONCERN LINK
|--------------------------------------------------------------------------
*/

$concernUrl = 'appointment_concern.php';

if ($upcomingConcernAppointment) {

    $concernUrl .=
        '?appointment_id=' .
        (int) $upcomingConcernAppointment['id'];
}




/*
|--------------------------------------------------------------------------
| ACTIVE PAGE
|--------------------------------------------------------------------------
*/

function patientSidebarActive($pages)
{
    global $currentPage;

    if (!is_array($pages)) {
        $pages = [$pages];
    }

    return in_array(
        $currentPage,
        $pages,
        true
    )
        ? 'active'
        : '';
}

?>

<aside class="patient-sidebar" id="patientSidebar">

    <!-- MOBILE CLOSE (X) — loob ng sidebar, mobile lang visible -->
    <button type="button" class="patient-sidebar-close" id="patientSidebarClose" aria-label="Close menu">
        <i class="bi bi-x-lg"></i>
    </button>

    <!-- BRAND -->

    <div class="patient-brand">

        <div class="brand-logo">
            <img src="../assets/dental-bg.jpg" alt="Abalos" style="width:100%;height:100%;object-fit:cover;display:block;border-radius:12px;">
        </div>

        <div class="brand-text">

            <div class="brand-title">
                Abalos Dental
            </div>

            <div class="brand-subtitle">
                Patient Portal
            </div>

        </div>

    </div>

    <!-- LOGGED-IN PATIENT IDENTITY CARD -->
    <div style="margin: 0 14px 14px; padding: 12px 14px; border-radius: 12px; background: rgba(255,255,255,0.10); border: 1px solid rgba(255,255,255,0.18); display: flex; align-items: center; gap: 10px;">
        <div style="width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, #34d399, #059669); color: #ffffff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 15px; flex-shrink: 0;">
            <?= htmlspecialchars(strtoupper(substr($sidebarDisplayName, 0, 1)), ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div style="min-width: 0; flex: 1;">
            <div style="font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.06em; color: #a7f3d0; font-weight: 700;">Logged in as</div>
            <div style="font-size: 13.5px; font-weight: 800; color: #ffffff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($sidebarDisplayName, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($sidebarDisplayName, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php if ($sidebarDisplayEmail !== ''): ?>
            <div style="font-size: 11px; color: #d1fae5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($sidebarDisplayEmail, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($sidebarDisplayEmail, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>
            <?php if ($sidebarPatientNumber !== ''): ?>
            <div style="font-size: 10.5px; color: #fde68a; font-weight: 700; margin-top: 2px;">
                ID: <?= htmlspecialchars($sidebarPatientNumber, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>
        </div>
    </div>


    <!-- MENU -->

    <nav class="patient-menu">

        <div class="patient-label">MAIN</div>

        <!-- DASHBOARD -->

        <a
            href="patient_dashboard.php"
            class="<?= patientSidebarActive('patient_dashboard.php') ?>"
        >

            <i class="bi bi-grid-1x2-fill"></i>

            <span>
                Dashboard
            </span>

        </a>


        <div class="patient-label patient-label-management">MANAGEMENT</div>

        <!-- MY APPOINTMENTS -->

        <a
            href="my_appointments.php"
            class="<?= patientSidebarActive([
                'my_appointments.php',
                'appointments.php',
                'appointments(1).php'
            ]) ?>"
        >

            <i class="bi bi-calendar-check"></i>

            <span>
                My Appointments
            </span>

        </a>


        <!-- CALENDAR -->

        <a
            href="calendar.php"
            class="<?= patientSidebarActive([
                'calendar.php',
                'calendar(1).php'
            ]) ?>"
        >

            <i class="bi bi-calendar3"></i>

            <span>
                Calendar
            </span>

        </a>


        <!-- NOTIFICATIONS -->

        <a
            href="notifications.php"
            class="<?= patientSidebarActive('notifications.php') ?>"
        >

            <i class="bi bi-bell"></i>

            <span>
                Notifications
            </span>

        </a>


        <!-- =====================================================
             APPOINTMENT CONCERN
             ITO ANG HINDI DAPAT MAWALA
        ====================================================== -->

        <a
            href="<?= htmlspecialchars(
                $concernUrl,
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
            class="<?= patientSidebarActive('appointment_concern.php') ?> appointment-concern-link"
        >

            <i class="bi bi-exclamation-circle"></i>

            <span>
                Appointment Concern
            </span>

        </a>


        <!-- MY PROFILE -->

        <a
            href="profile.php"
            class="<?= patientSidebarActive([
                'profile.php',
                'my_profile.php'
            ]) ?>"
        >

            <i class="bi bi-person-circle"></i>

            <span>
                My Profile
            </span>

        </a>

        <!-- SETTINGS -->

        <a
            href="settings.php"
            class="<?= patientSidebarActive('settings.php') ?>"
        >

            <i class="bi bi-gear-fill"></i>

            <span>
                Settings
            </span>

        </a>


        <!-- LOGOUT -->

        <div class="patient-sidebar-spacer"></div>

        <div class="patient-label">ACCOUNT</div>

        <a
            href="../logout.php"
            class="logout-link"
        >

            <i class="bi bi-box-arrow-right"></i>

            <span>
                Logout
            </span>

        </a>

    </nav>

</aside>

<!-- MOBILE HAMBURGER + OVERLAY — isang beses lang dinefine dito, gagana sa lahat ng patient pages -->
<button type="button" class="patient-hamburger" id="patientHamburger" aria-label="Open menu">
    <i class="bi bi-list"></i>
</button>
<div class="patient-sidebar-overlay" id="patientSidebarOverlay"></div>

<!-- PATIENT LOGOUT CONFIRM MODAL — Green Patient Theme -->
<div class="modal fade" id="patientLogoutModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width: 600px; width: 94%;">
    <div class="modal-content patient-logout-modal-content">
      <div class="modal-header patient-logout-modal-header">
        <div class="patient-logout-icon-wrap">
          <i class="bi bi-box-arrow-right"></i>
        </div>
        <div>
          <h5 class="modal-title patient-logout-title">Confirm Logout</h5>
          <small class="patient-logout-subtitle">Abalos Dental — Patient Portal</small>
        </div>
        <button type="button" class="btn-close patient-logout-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body patient-logout-body">
        <p class="patient-logout-message">
          Are you sure you want to logout?
        </p>
        <p class="patient-logout-hint">
          You will need to sign in again to access your appointments.
        </p>
      </div>
      <div class="modal-footer patient-logout-footer">
        <button type="button" class="btn patient-logout-cancel" data-bs-dismiss="modal">
          <i class="bi bi-x-lg me-1"></i> Cancel
        </button>
        <a href="../logout.php" id="patientLogoutConfirmBtn" class="btn patient-logout-confirm">
          <i class="bi bi-box-arrow-right me-1"></i> Yes, Logout
        </a>
      </div>
    </div>
  </div>
</div>

<style>

.patient-sidebar {
    width: 255px;
    min-width: 255px;
    min-height: 100vh;
    background: linear-gradient(180deg, #0A3B34, #0E6B5E);
    border-right: 1px solid #808080;
    padding: 25px 17px;
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    z-index: 10;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}

.patient-brand {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 5px 10px 28px;
    border-bottom: 1px solid #808080;
    height: auto;
}

.brand-logo {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 7px 18px rgba(14,107,94,.18);
    flex-shrink: 0;
}

.brand-text {
    width: auto;
}

.brand-title {
    color: #fff;
    font-family: "Fraunces", "Plus Jakarta Sans", sans-serif;
    font-size: 17px;
    font-weight: 800;
    line-height: 1.2;
}

.brand-subtitle {
    color: #9CC4B6;
    font-size: 11px;
    letter-spacing: .8px;
    margin-top: 4px;
}

.patient-menu {
    display: flex;
    flex-direction: column;
    padding: 0;
    margin-top: 27px;
    height: auto;
    flex: 1;
}

.patient-label {
    color: #8FAE9F;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.2px;
    padding: 0 12px;
    margin: 0 0 10px;
}

.patient-label-management {
    margin-top: 14px;
}

.patient-menu a {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: auto;
    padding: 12px 13px;
    margin-bottom: 4px;
    border-radius: 10px;
    color: #C9D9D1;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    transition: .2s;
}

.patient-menu a i {
    width: 19px;
    min-width: 19px;
    text-align: center;
    font-size: 17px;
}

.patient-menu a:hover {
    background: rgba(255,255,255,.12);
    color: #fff;
}

.patient-menu a.active {
    background: linear-gradient(135deg, #C9A227, #A8861B);
    color: #0A3B34;
    font-weight: 700;
    box-shadow: 0 8px 20px rgba(201,162,39,.35);
}

.patient-menu a.active i {
    color: #0A3B34;
}


/* Appointment Concern */

.patient-menu a.appointment-concern-link {
    color: #ffffff;
}

.patient-menu a.appointment-concern-link i {
    color: #ffffff;
}

.patient-menu a.appointment-concern-link:hover {
    background: rgba(255,255,255,.14);
    color: #ffffff;
    border-color: rgba(255,255,255,.18);
}

.patient-menu a.appointment-concern-link:hover i {
    color: #ffffff;
}

.patient-menu a.appointment-concern-link.active {
    background: #ffffff;
    color: #0A4A41;
    font-weight: 700;
}

.patient-menu a.appointment-concern-link.active i {
    color: #0E6B5E;
}


/* Spacer */

.patient-sidebar-spacer {
    flex: 1;
}


/* Back to Dashboard (inside sidebar) */

.patient-menu a.sidebar-back-link {
    background: rgba(255,255,255,.12);
    color: #ffffff;
    border: 1px dashed rgba(201,162,39,.7);
    margin-bottom: 12px;
}

.patient-menu a.sidebar-back-link i {
    color: #ffffff;
}

.patient-menu a.sidebar-back-link:hover {
    background: #ffffff;
    color: #0A4A41;
    border-color: #ffffff;
}

.patient-menu a.sidebar-back-link:hover i {
    color: #0E6B5E;
}

.patient-menu a.sidebar-back-link i {
    color: inherit;
}


/* Back to Dashboard (injected / static) — mas visible green */
#patientBackToDashboard {
    background: linear-gradient(135deg, #149A72, #0A4A41) !important;
    color: #ffffff !important;
    border: 1px solid rgba(201,162,39,.35) !important;
    border-radius: 10px !important;
    padding: 9px 18px !important;
    font-size: 13.5px !important;
    font-weight: 800 !important;
    font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif !important;
    letter-spacing: -.01em;
    box-shadow: 0 8px 20px rgba(14,107,94,.22) !important;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
#patientBackToDashboard:hover {
    background: linear-gradient(135deg, #0E6B5E, #083A32) !important;
    color: #ffffff !important;
    transform: translateY(-1px);
    box-shadow: 0 12px 26px rgba(14,107,94,.28) !important;
}

/* Logout - tulad sa dentist */

.patient-menu a.logout-link {
    color: #C9D9D1;
    margin-top: 10px;
}

.patient-menu a.logout-link i {
    color: #C9D9D1;
}

.patient-menu a.logout-link:hover {
    background: rgba(255,255,255,.12);
    color: #fff;
}

.patient-menu a.logout-link:hover i {
    color: #fff;
}


/* Patient Logout Modal — Green Theme */
.patient-logout-modal-content {
    border-radius: 20px;
    border: 1px solid #C9E9DA;
    overflow: hidden;
    box-shadow: 0 20px 50px rgba(14,107,94,.25);
    min-height: 260px;
}
.patient-logout-modal-header {
    background: #D9F2E6;
    border-bottom: 1px solid #C9E9DA;
    padding: 26px 28px;
    display: flex;
    align-items: center;
    gap: 16px;
    position: relative;
}
.patient-logout-icon-wrap {
    width: 58px;
    height: 58px;
    min-width: 58px;
    border-radius: 14px;
    background: linear-gradient(135deg, #149A72, #0A4A41);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    box-shadow: 0 6px 14px rgba(14,107,94,.22);
    border: 1px solid rgba(201,162,39,.35);
}
.patient-logout-title {
    font-family: "Plus Jakarta Sans", sans-serif;
    font-weight: 800;
    font-size: 20px;
    color: #143f51;
    margin: 0;
    line-height: 1.1;
}
.patient-logout-subtitle {
    color: #7FA59C;
    font-size: 12px;
    letter-spacing: .6px;
    font-weight: 600;
}
.patient-logout-close {
    margin-left: auto;
}
.patient-logout-body {
    padding: 26px 30px 14px 30px;
    background: #FFFFFF;
}
.patient-logout-message {
    font-family: "Plus Jakarta Sans", sans-serif;
    font-size: 19px;
    font-weight: 700;
    color: #143f51;
    margin: 0 0 10px 0;
}
.patient-logout-hint {
    font-size: 14.5px;
    color: #8aa0a8;
    margin: 0;
    line-height: 1.6;
}
.patient-logout-footer {
    background: #F6FBF8;
    border-top: 1px solid #E6F2EC;
    padding: 18px 28px;
    gap: 12px;
    display: flex;
    justify-content: flex-end;
}
.patient-logout-cancel {
    background: #FFFFFF;
    border: 1px solid #D1E7DD;
    color: #57727d;
    border-radius: 10px;
    padding: 12px 24px;
    font-size: 15px;
    font-weight: 700;
}
.patient-logout-cancel:hover {
    background: #EAF7F0;
    color: #0E6B5E;
    border-color: #0E6B5E;
}
.patient-logout-confirm {
    background: linear-gradient(135deg, #149A72, #0A4A41);
    color: #fff;
    border: none;
    border-radius: 10px;
    padding: 12px 26px;
    font-size: 15px;
    font-weight: 700;
    box-shadow: 0 8px 20px rgba(14,107,94,.28);
}
.patient-logout-confirm:hover {
    background: linear-gradient(135deg, #0E6B5E, #083A32);
    color: #fff;
}

/* Mobile */

/* Hamburger + overlay — desktop nakatago */
.patient-hamburger {
    display: none;
    position: fixed;
    top: 14px;
    left: 14px;
    z-index: 2000;
    width: 44px;
    height: 44px;
    border-radius: 12px;
    border: 1px solid rgba(201,162,39,.55);
    background: linear-gradient(135deg, #149A72, #0A4A41);
    color: #ffffff;
    font-size: 22px;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 8px 20px rgba(8,58,50,.35);
    pointer-events: auto;
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
}

.patient-sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1040;
    background: rgba(8,45,38,.55);
}

.patient-sidebar-close {
    display: none;
    position: absolute;
    top: 12px;
    right: 12px;
    width: 34px;
    height: 34px;
    border-radius: 9px;
    border: 1px solid rgba(255,255,255,.3);
    background: rgba(255,255,255,.14);
    color: #ffffff;
    font-size: 15px;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

@media (max-width: 991px) {

    .patient-hamburger {
        display: inline-flex;
    }

    .patient-sidebar {
        width: 265px !important;
        min-width: 265px !important;
        transform: translateX(-105%) !important;
        transition: transform .28s ease !important;
        box-shadow: 8px 0 32px rgba(8,58,50,.45) !important;
        z-index: 1500 !important;
    }

    .patient-sidebar.open {
        transform: translateX(0) !important;
        display: flex !important;
        pointer-events: auto !important;
    }

    .patient-sidebar-overlay.show {
        display: block;
        z-index: 1400;
    }

    .patient-sidebar-close {
        display: inline-flex;
    }

    /* Kahit may sariling margin ang bawat page, sa mobile tanggalin para hindi matakpan */
    .patient-main,
    main.patient-main,
    main.main {
        margin-left: 0 !important;
        width: 100% !important;
    }

}

</style>

<script>
/*
|--------------------------------------------------------------------------
| NEW TAB VIEW (#notab) — kagaya ng admin dashboard
| Kapag binuksan ang sidebar link sa new tab:
| - itinatago ang sidebar
| - nilalapad ang main content
| - naglalagay ng "Back to Dashboard" button
| - pag pinindot ang Back to Dashboard, isasara ang new tab
|--------------------------------------------------------------------------
*/
(function()
{
    function closePatientTab()
    {
        try { window.close(); } catch (e) {}

        // Fallback kung blocked ni browser ang window.close()
        // (hal. hindi na-consider na script-opened)
        setTimeout(function()
        {
            try
            {
                if (!window.closed)
                {
                    // trick para payagan ang close sa ibang browser
                    window.open('', '_self');
                    window.close();
                }
            } catch (e2) {}

            setTimeout(function()
            {
                if (!window.closed)
                {
                    window.location.href = "patient_dashboard.php";
                }
            }, 150);
        }, 200);
    }

    function bindCloseHandler(el)
    {
        if (!el || el.dataset.closeBound === "1") return;
        el.dataset.closeBound = "1";
        el.addEventListener("click", function(e)
        {
            // Tanging sa new tab view (#notab) lang isasara
            if (window.location.hash === "#notab")
            {
                e.preventDefault();
                closePatientTab();
            }
        });
    }

    function applyNewTabView()
    {
        // MOBILE: huwag itago ang sidebar — off-canvas hamburger ang gamit.
        // Burahin ang inline hide kung meron, tapos exit.
        if (window.innerWidth <= 991) {
            var _sb = document.querySelector(".patient-sidebar");
            if (_sb) { _sb.style.display = ""; }
            return;
        }

        if (window.location.hash !== "#notab") {
            return;
        }

        var sidebar = document.querySelector(".patient-sidebar");

        if (sidebar) {
            sidebar.style.display = "none";
        }

        var main = document.querySelector(".patient-main, main.main, main");

        if (main) {
            main.style.marginLeft = "0";
            main.style.width = "100%";
        }

        var content = document.querySelector(".patient-content");
        var backBtn = document.getElementById("patientBackToDashboard");

        if (content && !backBtn) {
            var back = document.createElement("a");
            back.id = "patientBackToDashboard";
            back.href = "patient_dashboard.php";
            back.className = "btn mb-3";
            back.innerHTML = '<i class="bi bi-arrow-left-circle-fill me-1"></i>Back to Dashboard';
            content.prepend(back);
            backBtn = back;
        }

        // Isara ang tab pag pinindot ang Back to Dashboard (static man o injected)
        if (backBtn) bindCloseHandler(backBtn);

        // Pati yung "Back to Dashboard" sa loob ng sidebar (kung visible pa)
        var sidebarBack = document.querySelector(".sidebar-back-link");
        if (sidebarBack) bindCloseHandler(sidebarBack);

        // Delegation fallback — kahit may ibang Back button sa page na may kaparehong id/class
        document.addEventListener("click", function(ev)
        {
            var t = ev.target.closest("#patientBackToDashboard, .sidebar-back-link");
            if (!t) return;
            if (window.location.hash === "#notab")
            {
                ev.preventDefault();
                closePatientTab();
            }
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", applyNewTabView);
    } else {
        applyNewTabView();
    }
})();
</script>

<script>
/*
|--------------------------------------------------------------------------
| PATIENT MOBILE SIDEBAR (hamburger / off-canvas)
| Sa cellphone nakatago ang sidebar — bubukas via hamburger button.
| Sa mobile, same-tab ang navigation para hindi dumami ang tabs.
|--------------------------------------------------------------------------
*/
(function()
{
    function isMobileView()
    {
        return window.innerWidth <= 991;
    }

    function getSidebar()
    {
        return document.getElementById("patientSidebar")
            || document.querySelector(".patient-sidebar");
    }

    function openSidebar()
    {
        var sidebar = getSidebar();
        var overlay = document.getElementById("patientSidebarOverlay");
        if (sidebar) sidebar.classList.add("open");
        if (overlay) overlay.classList.add("show");
        try { document.body.style.overflow = "hidden"; } catch (e) {}
    }

    function closeSidebar()
    {
        var sidebar = getSidebar();
        var overlay = document.getElementById("patientSidebarOverlay");
        if (sidebar) sidebar.classList.remove("open");
        if (overlay) overlay.classList.remove("show");
        try { document.body.style.overflow = ""; } catch (e) {}
    }

    function forceSameTabOnMobile()
    {
        if (!isMobileView()) return;
        var sidebar = getSidebar();
        if (!sidebar) return;
        var links = sidebar.querySelectorAll('a[target="_blank"]');
        for (var i = 0; i < links.length; i++) {
            links[i].removeAttribute("target");
            links[i].removeAttribute("rel");
        }
        // Tanggalin din ang #notab sa mobile — dahilan kaya parang "ayaw mapindot"
        // (same-document hash navigation = walang reload, walang visible response).
        var allLinks = sidebar.querySelectorAll('a[href*="#notab"]');
        for (var j = 0; j < allLinks.length; j++) {
            var h = allLinks[j].getAttribute("href");
            if (h) allLinks[j].setAttribute("href", h.replace(/#notab$/, ""));
        }
    }

    function initPatientMobileSidebar()
    {
        var hamburger = document.getElementById("patientHamburger");
        var overlay = document.getElementById("patientSidebarOverlay");
        var closeBtn = document.getElementById("patientSidebarClose");
        var sidebar = getSidebar();

        if (hamburger && !hamburger.dataset.bound) {
            hamburger.dataset.bound = "1";
            hamburger.addEventListener("click", function(e)
            {
                e.preventDefault();
                e.stopPropagation();
                if (sidebar && sidebar.classList.contains("open")) {
                    closeSidebar();
                } else {
                    forceSameTabOnMobile();
                    openSidebar();
                }
            });
        }

        if (overlay && !overlay.dataset.bound) {
            overlay.dataset.bound = "1";
            overlay.addEventListener("click", closeSidebar);
        }

        if (closeBtn && !closeBtn.dataset.bound) {
            closeBtn.dataset.bound = "1";
            closeBtn.addEventListener("click", function(e)
            {
                e.preventDefault();
                closeSidebar();
            });
        }

        // Pag pinindot ang menu link sa mobile, isara ang sidebar (tapos lilipat ng page)
        if (sidebar && !sidebar.dataset.linkBound) {
            sidebar.dataset.linkBound = "1";
            sidebar.addEventListener("click", function(ev)
            {
                var a = ev.target.closest("a");
                if (!a) return;
                if (isMobileView()) closeSidebar();
            });
        }

        forceSameTabOnMobile();

        // Pag nag-resize papuntang desktop, siguraduhing sarado ang mobile overlay
        window.addEventListener("resize", function()
        {
            if (!isMobileView()) closeSidebar();
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initPatientMobileSidebar);
    } else {
        initPatientMobileSidebar();
    }
})();
</script>

<script>
/*
|--------------------------------------------------------------------------
| PATIENT LOGOUT CONFIRMATION
| Hindi dapat deretso logout — may "Are you sure you want to logout?"
|--------------------------------------------------------------------------
*/
(function()
{
    function initPatientLogoutConfirm()
    {
        var logoutLink = document.querySelector(".patient-sidebar a.logout-link, a.logout-link[href*='logout.php']");
        var modalEl = document.getElementById("patientLogoutModal");
        if (!logoutLink || !modalEl) return;

        // Iwas double bind
        if (logoutLink.dataset.logoutBound === "1") return;
        logoutLink.dataset.logoutBound = "1";

        logoutLink.addEventListener("click", function(e)
        {
            e.preventDefault();
            // Gamitin ang Bootstrap modal kung available
            if (window.bootstrap && bootstrap.Modal)
            {
                var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
            }
            else
            {
                // Fallback kung walang Bootstrap JS sa page
                if (confirm("Are you sure you want to logout?"))
                {
                    window.location.href = logoutLink.getAttribute("href");
                }
            }
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initPatientLogoutConfirm);
    } else {
        initPatientLogoutConfirm();
    }
})();
</script>