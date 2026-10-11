<?php
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || strtolower(trim($_SESSION['role'] ?? '')) !== 'dentist') {
    header('Location: ../login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$success = '';
$error = '';

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

// Dentist info — naka-link sa users.id kapag may user_id, pangalan bilang fallback.
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
    $stmt = $pdo->prepare("SELECT * FROM dentists WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $dentist = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$dentist) {
    $stmt = $pdo->prepare("SELECT * FROM dentists WHERE dentist_name = ? LIMIT 1");
    $stmt->execute([trim($_SESSION['full_name'] ?? '')]);
    $dentist = $stmt->fetch(PDO::FETCH_ASSOC);
}


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
    $action = $_POST['action'] ?? '';
    if ($action === 'update_settings') {
        $fullName = trim($_POST['full_name'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');
        $spec = trim($_POST['specialization'] ?? '');
        if ($fullName === '') {
            $error = 'Full name is required.';
        } else {
            try {
                $pdo->prepare("UPDATE users SET full_name=? WHERE id=?")->execute([$fullName, $userId]);
                $_SESSION['full_name'] = $fullName;
                if ($dentist) {
                    $pdo->prepare("UPDATE dentists SET dentist_name=?, contact_number=?, specialization=? WHERE id=?")->execute([$fullName, $contact, $spec, $dentist['id']]);
                }
                $success = 'Your settings have been updated successfully.';
            } catch (PDOException $e) { $error = 'Unable to update settings.'; }
        }
    }
    if ($action === 'change_password') {
        $cur = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $conf = $_POST['confirm_password'] ?? '';
        if ($cur === '' || $new === '' || $conf === '') { $error = 'Please complete all password fields.'; }
        elseif (strlen($new) < 6) { $error = 'New password must contain at least 6 characters.'; }
        elseif ($new !== $conf) { $error = 'New password and confirmation do not match.'; }
        else {
            $col = isset($user['password']) ? 'password' : 'password_hash';
            $stored = $user[$col] ?? '';
            if (password_verify($cur, $stored)) {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                try { $pdo->prepare("UPDATE users SET `$col`=? WHERE id=?")->execute([$hash, $userId]); $success = 'Your password has been changed successfully.'; } catch (PDOException $e) { $error = 'Unable to change password.'; }
            } else { $error = 'Current password is incorrect.'; }
        }
    }
}

// Refresh
$stmt = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1"); $stmt->execute([$userId]); $user = $stmt->fetch(PDO::FETCH_ASSOC);
if ($dentist) { $stmt = $pdo->prepare("SELECT * FROM dentists WHERE id=? LIMIT 1"); $stmt->execute([$dentist['id']]); $dentist = $stmt->fetch(PDO::FETCH_ASSOC); }
$fullName = $user['full_name'] ?? 'Dentist';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings | Dentist Portal</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap">
<link rel="stylesheet" href="portal.css?v=5">

    <!-- DENTIST SIDEBAR (dashboard parity) -->
    <link rel="stylesheet" href="dentist_sidebar.css">
<style>
body{font-family:"Inter","DM Sans",system-ui,sans-serif;}
.portal-card h3{font-family:"Plus Jakarta Sans","Inter",system-ui,sans-serif;}
.settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start;}
.settings-grid .portal-card{margin:0;height:100%;}
.settings-grid .btn-primary{background:#059669!important;border-color:#059669!important;box-shadow:0 8px 20px rgba(5,150,105,.20);}
.settings-grid .btn-primary:hover{background:#047857!important;border-color:#047857!important;}
@media(max-width:991px){.settings-grid{grid-template-columns:1fr;}}
</style>

</head>
<body>

<style>
body{background:linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22)!important;background-size:300% 300%!important;animation:dentistDashBg 8s ease infinite!important;}
.portal-main{background:transparent!important;}
@keyframes dentistDashBg{0%{background-position:0% 50%;}50%{background-position:100% 50%;}100%{background-position:0% 50%;}}
</style>

<?php
$dentistActivePage = 'settings';
include __DIR__ . '/dentist_sidebar.php';
?>
<main class="portal-main">
<style>
.clinic-minibar {
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
}
.clinic-minibar span {
color: #ffffff;
}
.portal-topbar{background:#ffffff!important;border-bottom:1px solid #e8eef2!important;height:auto!important;min-height:90px!important;padding-top:12px!important;padding-bottom:12px!important;gap:16px!important;flex-wrap:wrap!important;align-items:center!important;}
.topbar-today{display:flex;align-items:center;gap:10px;color:#244f60;border-left:1px solid #e8eef2;padding-left:16px;}
.topbar-today i{font-size:22px;}
.topbar-today strong{display:block;font-size:13px;font-weight:800;line-height:1.2;}
.topbar-today small{display:block;font-size:11px;opacity:.85;font-weight:600;}
.topbar-time{display:flex;align-items:center;gap:10px;color:#244f60;border-left:1px solid #e8eef2;padding-left:16px;}
.topbar-time i{font-size:22px;}
.topbar-time strong{display:block;font-size:13px;font-weight:800;line-height:1.2;}
.topbar-time small{display:block;font-size:11px;opacity:.85;font-weight:600;font-variant-numeric:tabular-nums;}
</style>
<div class="clinic-minibar">🦷 <span>Abalos Dental Clinic</span></div>
<header class="portal-topbar"><div><div class="portal-heading">Settings</div><div class="portal-subtitle">Manage dentist account and preferences</div></div><div style="display:flex;align-items:center;gap:16px;"><div class="topbar-today" style="border-left:none;padding-left:0;"><i class="bi bi-calendar-event-fill"></i><div><strong><?= htmlspecialchars(date('l')) ?></strong><small><?= htmlspecialchars(date('M d, Y')) ?></small></div></div><div class="topbar-time"><i class="bi bi-clock-fill"></i><div><strong>Clinic Time</strong><small id="clinicTime">--:--:--</small></div></div><div class="portal-user"><?= htmlspecialchars($fullName) ?><div class="portal-role">Dentist</div></div></div></header>
<script>(function(){function tickClinicTime(){try{var el=document.getElementById('clinicTime');if(!el)return;var now=new Date();el.textContent=now.toLocaleTimeString('en-PH',{timeZone:'Asia/Manila',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});}catch(e){}}tickClinicTime();setInterval(tickClinicTime,1000);})();</script>
<div class="portal-content">
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="settings-grid">

<div class="portal-card">
<h2>Account Settings</h2>
<p style="color:#7b8997; font-size:13.5px; margin-bottom:18px;">Basic account information — common in dental clinic systems.</p>
<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="action" value="update_settings">
<div class="row g-3">
<div class="col-md-6"><label class="form-label">Full Name</label><input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" required></div>
<div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled><small class="text-muted" style="font-size:12px;">Registered email (not editable)</small></div>
<div class="col-md-6"><label class="form-label">Contact Number</label><input type="text" name="contact_number" class="form-control" value="<?= htmlspecialchars($dentist['contact_number'] ?? '') ?>" placeholder="09xx"></div>
<div class="col-md-6"><label class="form-label">Specialization</label><input type="text" name="specialization" class="form-control" value="<?= htmlspecialchars($dentist['specialization'] ?? '') ?>" placeholder="General Dentistry"></div>
<div class="col-12"><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Settings</button></div>
</div>
</form>
</div>

<div class="portal-card">
<h2><i class="bi bi-shield-check me-2" style="color:#198754;"></i> Privacy & Security</h2>
<p style="color:#7b8997; font-size:13px; margin-bottom:16px;">Manage your password and account security.</p>
<h5 style="font-size:16px; font-weight:700; color:#244e5e;" class="mb-3">Change Password</h5>
<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="action" value="change_password">
<div class="row g-3 align-items-center">
<div class="col-md-7">
<div class="row g-3">
<div class="col-12"><label class="form-label">Current Password</label><input type="password" name="current_password" class="form-control" required></div>
<div class="col-12"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control" minlength="6" required></div>
<div class="col-12"><label class="form-label">Confirm New Password</label><input type="password" name="confirm_password" class="form-control" minlength="6" required></div>
<div class="col-12"><button type="submit" class="btn btn-primary"><i class="bi bi-shield-lock"></i> Change Password</button></div>
</div>
</div>
<div class="col-md-5 text-center">
<div style="width:140px;height:140px;margin:0 auto;border-radius:28px;background:linear-gradient(135deg,#ecfdf5,#d1fae5);border:1px solid #bbe8cd;display:flex;align-items:center;justify-content:center;font-size:65px;color:#059669;box-shadow:0 12px 30px rgba(5,150,105,.15);"><i class="bi bi-shield-lock-fill"></i></div>
<div style="margin-top:12px;color:#047857;font-weight:800;font-size:13px;">Secure Account</div>
<div style="color:#6b8a96;font-size:12px;">Protect your dentist access</div>
</div>
</div>
</form>
</div>

<div class="portal-card">
<h2><i class="bi bi-bell-fill me-2" style="color:#0E6B5E;"></i> Notification Preferences</h2>
<p class="text-muted small mb-3">Common dental clinic system — choose how you are notified.</p>
<div class="d-flex justify-content-between align-items-center py-2 border-bottom"><div><strong style="font-size:14.5px;">Appointment Assignments</strong><br><small class="text-muted">New appointments assigned to you</small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" checked role="switch" style="cursor:pointer;"><label class="form-check-label small">Enabled</label></div></div>
<div class="d-flex justify-content-between align-items-center py-2"><div><strong style="font-size:14.5px;">Queue Updates</strong><br><small class="text-muted">Patient queue changes</small></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" checked role="switch" style="cursor:pointer;"><label class="form-check-label small">Enabled</label></div></div>
</div>

<div class="portal-card">
<h2><i class="bi bi-gear-fill me-2" style="color:#6c757d;"></i> Preferences</h2>
<div class="row g-3">
<div class="col-md-6"><label class="form-label">Language</label><select class="form-select" style="cursor:pointer;"><option>English (Default)</option><option>Filipino</option></select></div>
<div class="col-md-6"><label class="form-label">Timezone</label><select class="form-select" style="cursor:pointer;"><option>Asia/Manila (GMT+8)</option><option>UTC</option></select></div>
</div>
<small class="text-muted d-block mt-3" style="font-size:12.5px;">Common dental clinic system setting — clickable UI only (no system change).</small>
</div>

</div>

</div>
        <?php if (($_GET['nosidebar'] ?? '') === '1'): ?>
        <style>.portal-sidebar{display:none!important;}.portal-main{margin-left:0!important;width:100%!important;}</style>
        <script>
        function autoCloseSubTab(){
            try{ if(window.opener && !window.opener.closed){ window.opener.focus(); } }catch(e){}
            /* Hindi na nagsasara ang tab — same-tab navigation. */
            if (!window.opener || window.opener.closed) {
                window.location.href='dentist_dashboard.php';
            }
            return false;
        }
        </script>
        <?php endif; ?>
</main>
<script>
/* Itago ang sidebar kapag binuksan mula sa dashboard menu (?nosidebar=1) */
(function(){
    try {
        if (new URLSearchParams(window.location.search).get('nosidebar') === '1') {
            document.querySelectorAll('.portal-sidebar').forEach(function(el){ el.style.display = 'none'; });
            document.querySelectorAll('.portal-main').forEach(function(el){ el.style.marginLeft = '0'; el.style.width = '100%'; });
        }
    } catch(e){}
})();
</script>
<!-- DENTIST LOGOUT CONFIRM — "Are you sure you want to log out?" -->
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
            <p>You will need to log in again to access the dentist account.</p>
        </div>
        <div class="dentist-logout-foot">
            <button type="button" class="btn btn-light" id="dentistLogoutCancel">Cancel</button>
            <a href="../logout.php" class="btn dentist-logout-yes" id="dentistLogoutYes"><i class="bi bi-box-arrow-right me-1"></i>Log Out</a>
        </div>
    </div>
</div>
<style>
.dentist-logout-overlay { position: fixed; inset: 0; background: rgba(10,59,52,.55); z-index: 2000; padding: 20px; align-items: center; justify-content: center; }
.dentist-logout-box { background: #ffffff; border-radius: 18px; border: 1px solid #C9E9DA; box-shadow: 0 18px 45px rgba(0,0,0,.25); max-width: 440px; width: 100%; overflow: hidden; font-family: "Inter", "DM Sans", system-ui, sans-serif; animation: dentistLogoutPop .18s ease; }
@keyframes dentistLogoutPop { from { transform: scale(.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.dentist-logout-head { display: flex; align-items: center; gap: 12px; padding: 18px 22px; background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%); border-bottom: 1px solid #C9E9DA; }
.dentist-logout-head-icon { width: 38px; height: 38px; border-radius: 11px; background: linear-gradient(135deg, #0E6B5E, #0A4A41); color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 4px 10px rgba(14,107,94,.25); flex-shrink: 0; }
.dentist-logout-head h5 { color: #143f51; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; font-size: 17px; margin: 0; flex: 1; }
.dentist-logout-x { background: #ffffff; border: 1px solid #D1E7DD; color: #57727d; width: 30px; height: 30px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; flex-shrink: 0; }
.dentist-logout-x:hover { background: #EAF7F0; color: #0E6B5E; }
.dentist-logout-body { padding: 30px 28px 8px; text-align: center; }
.dentist-logout-icon { width: 64px; height: 64px; border-radius: 18px; background: #FDECEC; color: #C0392B; border: 1px solid #F5C6C6; display: inline-flex; align-items: center; justify-content: center; font-size: 30px; margin-bottom: 14px; }
.dentist-logout-body h5 { color: #143f51; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; font-size: 18px; margin: 0 0 8px; }
.dentist-logout-body p { color: #8aa0a8; font-size: 13.5px; margin: 0; }
.dentist-logout-foot { display: flex; gap: 12px; justify-content: center; padding: 18px 22px 22px; background: #F6FBF8; border-top: 1px solid #E6F2EC; margin-top: 20px; }
.dentist-logout-foot .btn { border-radius: 10px; font-weight: 700; font-size: 14px; padding: 10px 26px; }
.dentist-logout-foot .btn-light { background: #fff !important; border: 1px solid #D1E7DD !important; color: #57727d !important; }
.dentist-logout-foot .btn-light:hover { background: #EAF7F0 !important; color: #0E6B5E !important; }
.dentist-logout-yes { background: linear-gradient(135deg, #E74C3C, #922B21) !important; border: none !important; color: #ffffff !important; box-shadow: 0 6px 14px rgba(192,57,43,.3) !important; }
.dentist-logout-yes:hover { filter: brightness(.93); color: #ffffff !important; }
</style>
<script>
(function()
{
    function openDentistLogout(e)
    {
        if (e) e.preventDefault();
        var overlay = document.getElementById("dentistLogoutConfirm");
        if (!overlay) return;
        overlay.style.display = "flex";
        document.body.style.overflow = "hidden";
    }
    function closeDentistLogout()
    {
        var overlay = document.getElementById("dentistLogoutConfirm");
        if (!overlay) return;
        overlay.style.display = "none";
        document.body.style.overflow = "";
    }
    function initDentistLogout()
    {
        var overlay = document.getElementById("dentistLogoutConfirm");
        if (!overlay || overlay.dataset.bound === "1") return;
        overlay.dataset.bound = "1";
        document.querySelectorAll('a[href="../logout.php"], a[href="logout.php"]').forEach(function(a)
        {
            if (a.closest("#dentistLogoutConfirm")) return;
            if (a.dataset.logoutBound === "1") return;
            a.dataset.logoutBound = "1";
            a.addEventListener("click", openDentistLogout);
        });
        document.getElementById("dentistLogoutCancel").addEventListener("click", closeDentistLogout);
        document.getElementById("dentistLogoutClose").addEventListener("click", closeDentistLogout);
        overlay.addEventListener("click", function(e)
        {
            if (e.target === overlay) closeDentistLogout();
        });
        document.addEventListener("keydown", function(e)
        {
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
