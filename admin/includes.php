<?php

$currentPage = basename($_SERVER['PHP_SELF']);

$activeAdminPage =
    $_GET['page']
    ?? str_replace('.php', '', $currentPage);


/*
|--------------------------------------------------------------------------
| ACTIVE PAGE
|--------------------------------------------------------------------------
*/
function isActive($page)
{
    global $activeAdminPage;

    return $activeAdminPage === str_replace('.php', '', $page)
        ? 'active'
        : '';
}


/*
|--------------------------------------------------------------------------
| PAGE TITLES
|--------------------------------------------------------------------------
*/
$pageTitles = [

    'dashboard.php' => [
        'Dashboard',
        'Clinic overview and appointment management'
    ],

    'patients.php' => [
        'Patients',
        'View and manage registered dental patients.'
    ],

    'appointments.php' => [
        'Appointments',
        'Manage and schedule dental appointments'
    ],

    'appointment_concern.php' => [
        'Appointment Concerns',
        'View patient concerns about their appointments'
    ],

    'appointment_concerns.php' => [
        'Appointment Concerns',
        'View patient concerns about their appointments'
    ],

    'notifications.php' => [
        'Notifications',
        'Appointment activity for the clinic administrator'
    ],

    'dentists.php' => [
        'Dentist Schedules',
        'Manage registered dentists and schedules'
    ],

    'queue.php' => [
        'Queue',
        "Manage today's patient queue"
    ],

    'reports.php' => [
        'Reports',
        'Clinic performance and appointment analytics'
    ],

    'settings.php' => [
        'Settings',
        'Manage clinic information and preferences'
    ],

    'backup.php' => [
        'Backup',
        'Export and import the full database backup'
    ]

];


$pageTitle =
    $pageTitles[$currentPage][0]
    ?? 'Dashboard';


$pageSubtitle =
    $pageTitles[$currentPage][1]
    ?? 'Clinic overview and appointment management';


$username =
    $_SESSION['username']
    ?? 'System Administrator';

?>


<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside class="sidebar shared-sidebar">


    <!-- BRAND -->

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
                ADMIN PORTAL
            </div>

        </div>

    </div>


    <!-- NAVIGATION -->

    <div class="sidebar-nav">


        <div class="nav-title">
            MAIN
        </div>


        <!-- DASHBOARD -->

        <a
            href="dashboard.php"
            class="sidebar-link <?= isActive('dashboard.php') ?>"
        >

            <i class="bi bi-grid-1x2-fill"></i>

            <span>
                Dashboard
            </span>

        </a>


        <div class="nav-title" style="margin-top:14px;">
            MANAGEMENT
        </div>


        <!-- PATIENTS -->

        <a
            href="patients.php"
            class="sidebar-link <?= isActive('patients.php') ?>"
        >

            <i class="bi bi-people-fill"></i>

            <span>
                Patients
            </span>

        </a>


        <!-- APPOINTMENTS -->

        <a
            href="appointments.php"
            class="sidebar-link <?= isActive('appointments.php') ?>"
        >

            <i class="bi bi-calendar2-check-fill"></i>

            <span>
                Appointments
            </span>

        </a>


        <!-- APPOINTMENT CONCERNS -->

        <a
            href="appointment_concern.php"
            class="sidebar-link <?= isActive('appointment_concern.php') ?>"
        >

            <i class="bi bi-exclamation-circle-fill"></i>

            <span>
                Appointment Concerns
            </span>

        </a>


        <!-- NOTIFICATIONS -->

        <a
            href="notifications.php"
            class="sidebar-link <?= isActive('notifications.php') ?>"
        >

            <i class="bi bi-bell-fill"></i>

            <span>
                Notifications
            </span>

        </a>


        <!-- DENTISTS -->

        <a
            href="dentists.php"
            class="sidebar-link <?= isActive('dentists.php') ?>"
        >

            <i class="bi bi-person-workspace"></i>

            <span>
                Dentist Schedules
            </span>

        </a>


        <!-- QUEUE -->

        <a
            href="queue.php"
            class="sidebar-link <?= isActive('queue.php') ?>"
        >

            <i class="bi bi-list-ol"></i>

            <span>
                Queue
            </span>

        </a>


        <!-- REPORTS -->

        <a
            href="reports.php"
            class="sidebar-link <?= isActive('reports.php') ?>"
        >

            <i class="bi bi-bar-chart-fill"></i>

            <span>
                Reports
            </span>

        </a>


        <!-- SETTINGS -->

        <a
            href="settings.php"
            class="sidebar-link <?= isActive('settings.php') ?>"
        >

            <i class="bi bi-gear-fill"></i>

            <span>
                Settings
            </span>

        </a>


        <!-- BACKUP -->

        <a
            href="backup.php"
            class="sidebar-link <?= isActive('backup.php') ?>"
        >

            <i class="bi bi-database-fill-check"></i>

            <span>
                Backup
            </span>

        </a>


        <div class="nav-title" style="margin-top:14px;">
            ACCOUNT
        </div>

        <a
            href="../logout.php"
            class="sidebar-link"
        >

            <i class="bi bi-box-arrow-right"></i>

            <span>
                Logout
            </span>

        </a>


    </div>


</aside>

<style>
/* Admin Back to Dashboard — katulad sa patient account (green visible) */
a.btn[href="dashboard.php"],
a.btn[href="./dashboard.php"],
a.btn[href*="dashboard.php"] {
    background: linear-gradient(135deg, #149A72, #0A4A41) !important;
    color: #ffffff !important;
    border: 1px solid rgba(201,162,39,.35) !important;
    border-radius: 10px !important;
    padding: 9px 18px !important;
    font-size: 13.5px !important;
    font-weight: 800 !important;
    font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif !important;
    letter-spacing: -.01em !important;
    box-shadow: 0 8px 20px rgba(14,107,94,.22) !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    text-decoration: none !important;
    transition: .2s !important;
}
a.btn[href="dashboard.php"]:hover,
a.btn[href="./dashboard.php"]:hover,
a.btn[href*="dashboard.php"]:hover {
    background: linear-gradient(135deg, #0E6B5E, #083A32) !important;
    color: #ffffff !important;
    transform: translateY(-1px);
    box-shadow: 0 12px 26px rgba(14,107,94,.28) !important;
}
/* Huwag madamay ang sidebar link (hindi btn) */
.sidebar a[href*="dashboard.php"] { background: none !important; box-shadow: none !important; }
</style>

<!-- =========================================================
     TOPBAR
========================================================= -->

<header class="topbar shared-topbar">


    <div class="d-flex align-items-center gap-3">

        <div class="page-title">

            <h1>
                <?= htmlspecialchars($pageTitle) ?>
            </h1>

            <p>
                <?= htmlspecialchars($pageSubtitle) ?>
            </p>

        </div>

    </div>


    <div class="admin-area">

        <div class="admin-info">

            <strong>
                <?= htmlspecialchars($username) ?>
            </strong>

            <span>
                Clinic Administrator
            </span>

        </div>


        <div class="admin-avatar">

            <i class="bi bi-person-fill"></i>

        </div>

    </div>


</header>

<script>
(function()
{
    function closeAdminTab()
    {
        try { window.close(); } catch (e) {}
        setTimeout(function()
        {
            try
            {
                if (!window.closed)
                {
                    window.open('', '_self');
                    window.close();
                }
            } catch (e2) {}
            setTimeout(function()
            {
                if (!window.closed)
                {
                    window.location.href = "dashboard.php";
                }
            }, 150);
        }, 200);
    }

    function bindAdminClose(el)
    {
        if (!el || el.dataset.closeBound === "1") return;
        el.dataset.closeBound = "1";
        el.addEventListener("click", function(e)
        {
            if (window.location.hash === "#notab")
            {
                e.preventDefault();
                closeAdminTab();
            }
        });
    }

    function applyAdminNewTabView()
    {
        if (window.location.hash !== "#notab") return;

        var sidebar = document.querySelector(".shared-sidebar");
        if (sidebar) {
            sidebar.style.display = "none";
        }
        var candidates = document.querySelectorAll(
            ".patients-page, .main, .settings-page, .notifications-page, main, .main-content, .content"
        );
        candidates.forEach(function(el) {
            el.style.marginLeft = "0";
        });
        document.body.style.marginLeft = "0";

        // Hanapin lahat ng Back to Dashboard links (static) — gawing close-tab
        var backs = document.querySelectorAll('a[href="dashboard.php"], a[href="./dashboard.php"], a[href*="dashboard.php"]');
        backs.forEach(function(b)
        {
            if (b.textContent && b.textContent.toLowerCase().indexOf("back to dashboard") !== -1)
            {
                bindAdminClose(b);
            }
        });

        // Kung walang Back button, mag-inject ng isa sa taas ng content
        var hasBack = false;
        backs.forEach(function(b){
            if (b.textContent && b.textContent.toLowerCase().indexOf("back to dashboard") !== -1) hasBack = true;
        });
        if (!hasBack)
        {
            var target = document.querySelector(".patients-page, .main-content, .content, .patient-panel, main");
            if (target)
            {
                var a = document.createElement("a");
                a.href = "dashboard.php";
                a.className = "btn mb-3";
                a.innerHTML = '<i class="bi bi-arrow-left-circle-fill me-1"></i>Back to Dashboard';
                a.style.display = "inline-block";
                target.prepend(a);
                bindAdminClose(a);
            }
        }

        // Delegation fallback
        document.addEventListener("click", function(ev)
        {
            var t = ev.target.closest('a[href*="dashboard.php"]');
            if (!t) return;
            if (!t.textContent || t.textContent.toLowerCase().indexOf("back to dashboard") === -1) return;
            if (window.location.hash === "#notab")
            {
                ev.preventDefault();
                closeAdminTab();
            }
        });
    }

    // Gawing kapareho ng patient: palitan icon ng Back to Dashboard
    function styleAdminBackIcons() {
        var backs = document.querySelectorAll('a[href="dashboard.php"], a[href="./dashboard.php"], a[href*="dashboard.php"]');
        backs.forEach(function(b){
            if (!b.textContent || b.textContent.toLowerCase().indexOf("back to dashboard") === -1) return;
            // Palitan arrow-left ng circle-fill kung meron
            var icon = b.querySelector("i.bi-arrow-left");
            if (icon && !b.querySelector(".bi-arrow-left-circle-fill")) {
                icon.classList.remove("bi-arrow-left");
                icon.classList.add("bi-arrow-left-circle-fill");
            }
            // Siguraduhin may btn class para ma-apply ang green style
            if (!b.classList.contains("btn")) b.classList.add("btn");
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function(){
            applyAdminNewTabView();
            styleAdminBackIcons();
        });
    } else {
        applyAdminNewTabView();
        styleAdminBackIcons();
    }
})();
</script>

<!-- =========================================================
     LOGOUT CONFIRM — "Are you sure you want to log out?"
========================================================= -->
<div id="adminLogoutConfirm" class="admin-logout-overlay" style="display:none;">
    <div class="admin-logout-box">
        <div class="admin-logout-head">
            <span class="admin-logout-head-icon"><i class="bi bi-box-arrow-right"></i></span>
            <h5>Log Out</h5>
            <button type="button" class="admin-logout-x" id="adminLogoutClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="admin-logout-body">
            <div class="admin-logout-icon"><i class="bi bi-exclamation-circle-fill"></i></div>
            <h5>Are you sure you want to log out?</h5>
            <p>You will need to log in again to access the admin account.</p>
        </div>
        <div class="admin-logout-foot">
            <button type="button" class="btn btn-light" id="adminLogoutCancel">Cancel</button>
            <a href="../logout.php" class="btn admin-logout-yes" id="adminLogoutYes"><i class="bi bi-box-arrow-right me-1"></i>Log Out</a>
        </div>
    </div>
</div>
<style>
.admin-logout-overlay { position: fixed; inset: 0; background: rgba(10,59,52,.55); z-index: 2000; padding: 20px; align-items: center; justify-content: center; }
.admin-logout-box { background: #ffffff; border-radius: 18px; border: 1px solid #C9E9DA; box-shadow: 0 18px 45px rgba(0,0,0,.25); max-width: 440px; width: 100%; overflow: hidden; font-family: "Inter", "DM Sans", system-ui, sans-serif; animation: adminLogoutPop .18s ease; }
@keyframes adminLogoutPop { from { transform: scale(.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.admin-logout-head { display: flex; align-items: center; gap: 12px; padding: 18px 22px; background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%); border-bottom: 1px solid #C9E9DA; }
.admin-logout-head-icon { width: 38px; height: 38px; border-radius: 11px; background: linear-gradient(135deg, #0E6B5E, #0A4A41); color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 4px 10px rgba(14,107,94,.25); flex-shrink: 0; }
.admin-logout-head h5 { color: #143f51; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; font-size: 17px; margin: 0; flex: 1; }
.admin-logout-x { background: #ffffff; border: 1px solid #D1E7DD; color: #57727d; width: 30px; height: 30px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; flex-shrink: 0; }
.admin-logout-x:hover { background: #EAF7F0; color: #0E6B5E; }
.admin-logout-body { padding: 30px 28px 8px; text-align: center; }
.admin-logout-icon { width: 64px; height: 64px; border-radius: 18px; background: #FDECEC; color: #C0392B; border: 1px solid #F5C6C6; display: inline-flex; align-items: center; justify-content: center; font-size: 30px; margin-bottom: 14px; }
.admin-logout-body h5 { color: #143f51; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; font-size: 18px; margin: 0 0 8px; }
.admin-logout-body p { color: #8aa0a8; font-size: 13.5px; margin: 0; }
.admin-logout-foot { display: flex; gap: 12px; justify-content: center; padding: 18px 22px 22px; background: #F6FBF8; border-top: 1px solid #E6F2EC; margin-top: 20px; }
.admin-logout-foot .btn { border-radius: 10px; font-weight: 700; font-size: 14px; padding: 10px 26px; }
.admin-logout-foot .btn-light { background: #fff !important; border: 1px solid #D1E7DD !important; color: #57727d !important; }
.admin-logout-foot .btn-light:hover { background: #EAF7F0 !important; color: #0E6B5E !important; }
.admin-logout-yes { background: linear-gradient(135deg, #E74C3C, #922B21) !important; border: none !important; color: #ffffff !important; box-shadow: 0 6px 14px rgba(192,57,43,.3) !important; }
.admin-logout-yes:hover { filter: brightness(.93); color: #ffffff !important; }
</style>
<script>
(function()
{
    function openAdminLogout(e)
    {
        if (e) e.preventDefault();
        var overlay = document.getElementById("adminLogoutConfirm");
        if (!overlay) return;
        overlay.style.display = "flex";
        document.body.style.overflow = "hidden";
    }

    function closeAdminLogout()
    {
        var overlay = document.getElementById("adminLogoutConfirm");
        if (!overlay) return;
        overlay.style.display = "none";
        document.body.style.overflow = "";
    }

    function initAdminLogout()
    {
        var overlay = document.getElementById("adminLogoutConfirm");
        if (!overlay || overlay.dataset.bound === "1") return;
        overlay.dataset.bound = "1";

        document.querySelectorAll('a[href="../logout.php"], a[href="logout.php"]').forEach(function(a)
        {
            if (a.closest("#adminLogoutConfirm")) return;
            if (a.dataset.logoutBound === "1") return;
            a.dataset.logoutBound = "1";
            a.addEventListener("click", openAdminLogout);
        });

        document.getElementById("adminLogoutCancel").addEventListener("click", closeAdminLogout);
        document.getElementById("adminLogoutClose").addEventListener("click", closeAdminLogout);
        overlay.addEventListener("click", function(e)
        {
            if (e.target === overlay) closeAdminLogout();
        });
        document.addEventListener("keydown", function(e)
        {
            if (e.key === "Escape") closeAdminLogout();
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initAdminLogout);
    } else {
        initAdminLogout();
    }
})();
</script>