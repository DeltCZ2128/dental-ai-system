<?php

require_once __DIR__ . '/../functions.php';

requireAdmin();
ensureSettingsTable();

$pdo->exec("CREATE TABLE IF NOT EXISTS clinic_services (
    id INT NOT NULL AUTO_INCREMENT,
    service_name VARCHAR(150) NOT NULL,
    description TEXT,
    duration_minutes INT NOT NULL DEFAULT 30,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");


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

    try {
        if ($action === 'clinic') {
            $clinicName = trim($_POST['clinic_name'] ?? '');
            $openingTime = trim($_POST['opening_time'] ?? '08:00');
            $closingTime = trim($_POST['closing_time'] ?? '17:00');

            if ($clinicName === '' || $closingTime <= $openingTime) {
                flash('Please provide a clinic name and valid operating hours.', 'danger');
                redirect('settings.php');
            }

            $stmt = $pdo->prepare("UPDATE settings SET clinic_name = ?, address = ?, contact_number = ?, email = ?, opening_time = ?, closing_time = ? WHERE id = 1");
            $stmt->execute([
                $clinicName,
                trim($_POST['address'] ?? ''),
                trim($_POST['contact_number'] ?? ''),
                trim($_POST['email'] ?? ''),
                $openingTime,
                $closingTime
            ]);
            flash('Clinic information and operating hours updated.');
        }

        if ($action === 'service_save') {
            $serviceName = trim($_POST['service_name'] ?? '');
            $duration = max(1, (int) ($_POST['duration_minutes'] ?? 30));
            $serviceId = (int) ($_POST['service_id'] ?? 0);

            if ($serviceName === '') {
                flash('Service name is required.', 'danger');
                redirect('settings.php');
            }

            if ($serviceId > 0) {
                $stmt = $pdo->prepare("UPDATE clinic_services SET service_name = ?, description = ?, duration_minutes = ? WHERE id = ?");
                $stmt->execute([$serviceName, trim($_POST['description'] ?? ''), $duration, $serviceId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO clinic_services (service_name, description, duration_minutes) VALUES (?, ?, ?)");
                $stmt->execute([$serviceName, trim($_POST['description'] ?? ''), $duration]);
            }
            flash('Service saved.');
        }

        if ($action === 'service_status') {
            $stmt = $pdo->prepare("UPDATE clinic_services SET status = CASE WHEN status = 'Active' THEN 'Inactive' ELSE 'Active' END WHERE id = ?");
            $stmt->execute([(int) $_POST['service_id']]);
            flash('Service status updated.');
        }

        if ($action === 'user_save') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $username = trim($_POST['username'] ?? '');
            $fullName = trim($_POST['full_name'] ?? '');
            $role = $_POST['role'] ?? 'patient';
            $password = $_POST['password'] ?? '';

            if ($username === '' || $fullName === '' || !in_array($role, ['admin', 'patient', 'dentist'], true)) {
                flash('Username, full name, and valid role are required.', 'danger');
                redirect('settings.php');
            }

            if ($userId > 0) {
                if ($password !== '') {
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, full_name = ?, role = ?, password = ? WHERE id = ?");
                    $stmt->execute([$username, $fullName, $role, password_hash($password, PASSWORD_DEFAULT), $userId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, full_name = ?, role = ? WHERE id = ?");
                    $stmt->execute([$username, $fullName, $role, $userId]);
                }
                // Kapag dentist ang role, siguraduhing may dentists profile + link para gumana ang login.
                if ($role === 'dentist' && function_exists('ensureDentistProfileForUser')) {
                    ensureDentistProfileForUser($userId, $fullName);
                }
            } else {
                if ($password === '') {
                    flash('A password is required for a new user.', 'danger');
                    redirect('settings.php');
                }
                $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role]);
                if ($role === 'dentist' && function_exists('ensureDentistProfileForUser')) {
                    ensureDentistProfileForUser((int)$pdo->lastInsertId(), $fullName);
                }
            }
            flash('User account saved.');
        }
    } catch (PDOException $e) {
        flash('Unable to save settings. Please check for duplicate usernames or invalid data.', 'danger');
    }

    redirect('settings.php');
}

$settings = getSetting();
$services = $pdo->query('SELECT * FROM clinic_services ORDER BY service_name')->fetchAll(PDO::FETCH_ASSOC);

/* User Accounts Pagination: 10 per page */
$userPerPage = 10;
$totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalUserPages = max(1, (int) ceil($totalUsers / $userPerPage));
$userPage = max(1, min((int) ($_GET['user_page'] ?? ($_GET['page'] ?? 1)), $totalUserPages));
$userOffset = ($userPage - 1) * $userPerPage;

$userStmt = $pdo->prepare('SELECT id, username, full_name, role, created_at FROM users ORDER BY full_name LIMIT ? OFFSET ?');
$userStmt->bindValue(1, $userPerPage, PDO::PARAM_INT);
$userStmt->bindValue(2, $userOffset, PDO::PARAM_INT);
$userStmt->execute();
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

$isUsersTabActive = isset($_GET['user_page']);

$editService = null;
if (isset($_GET['edit_service'])) {
    $stmt = $pdo->prepare('SELECT * FROM clinic_services WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_GET['edit_service']]);
    $editService = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settings | Abalos Dental Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/style.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22) !important; background-size: 300% 300% !important; animation: adminBgAnim 8s ease infinite !important; background-attachment: fixed !important; }
@keyframes adminBgAnim { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        .settings-page { margin-left: 280px !important; width: calc(100% - 280px) !important; min-height: 100vh; padding: 110px 24px 40px !important; background: transparent !important; box-sizing: border-box !important; }
        .settings-page > a.btn, .settings-page > .alert { margin-left: 14px; margin-right: 14px; }
        .settings-page > .alert { font-size: 13.5px; border-radius: 12px; }
        .settings-page > .mb-4 { margin: 0 14px 16px 14px !important; }
        .settings-page > .mb-4 h1 { color: #ffffff !important; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; margin: 0; }
        .settings-page > .mb-4 p { color: #C9E9DA !important; font-size: 13px; margin: 4px 0 0; }
        .settings-page .row.g-4 { margin-left: 2px; margin-right: 2px; }
        .settings-card { background: #ffffff !important; border: 1px solid #C9E9DA !important; border-radius: 16px !important; box-shadow: 0 8px 24px rgba(14,107,94,.08) !important; padding: 20px !important; height: 100%; }
        section.settings-card { margin-bottom: 0 !important; }
        .settings-page > section.settings-card { margin: 0 14px 24px 14px !important; }
        .settings-card h2 { color: #143f51 !important; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-size: 17px !important; font-weight: 800; letter-spacing: -0.02em; margin: 0 0 14px; display: flex; align-items: center; gap: 8px; }
        .settings-card h2 i { color: #0E6B5E; font-size: 18px; }
        .settings-card .card-sub { margin: -10px 0 14px 26px; color: #8aa0a8; font-size: 12px; }
        .settings-table { margin-bottom: 0 !important; width: 100%; border-collapse: separate; border-spacing: 0; background: #ffffff; }
        .settings-table thead tr { background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important; }
        .settings-table thead th { background: transparent !important; color: #ffffff !important; font-size: 11.5px !important; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; white-space: nowrap; padding: 14px 16px !important; border-bottom: none !important; border-right: 1px solid rgba(255,255,255,.12) !important; font-family: "Inter", system-ui, sans-serif; }
        .settings-table thead th:last-child { border-right: none !important; }
        .settings-table tbody td { padding: 14px 16px !important; color: #4d6873 !important; font-size: 13.5px !important; font-family: "Inter", "DM Sans", system-ui, sans-serif; vertical-align: middle; background: #ffffff; border-bottom: 1px solid #E6F2EC !important; }
        .settings-table tbody tr:nth-child(even) td { background: #F6FBF8 !important; }
        .settings-table tbody tr:hover td { background: #EAF7F0 !important; }
        .settings-table tbody td strong { color: #143f51 !important; }
        .settings-page .btn-primary { background: linear-gradient(135deg, #149A72, #0A4A41) !important; border: none !important; border-radius: 10px; box-shadow: 0 6px 14px rgba(14,107,94,.22); font-weight: 700; }
        .settings-page .btn-primary:hover { background: linear-gradient(135deg, #0E6B5E, #083A32) !important; }
        .settings-page .btn-outline-primary { color: #0E6B5E !important; border-color: #C9E9DA !important; background: #fff !important; }
        .settings-page .btn-outline-primary:hover { background: #EAF7F0 !important; color: #0E6B5E !important; border-color: #0E6B5E !important; }
        .settings-page .btn-outline-secondary { color: #57727d !important; border-color: #D1E7DD !important; background: #fff !important; }
        .settings-page .btn-outline-secondary:hover { background: #EAF7F0 !important; color: #0E6B5E !important; border-color: #0E6B5E !important; }
        .settings-page .btn-light { background: #fff !important; border: 1px solid #D1E7DD !important; color: #57727d !important; }
        .settings-page .badge { border-radius: 20px; font-size: 11.5px !important; font-weight: 700; font-family: "Inter", system-ui, sans-serif; padding: 6px 11px !important; }
        .settings-page .badge.bg-success { background: #e7f8ef !important; color: #15945a !important; border: 1px solid #b9e8cd; }
        .settings-page .badge.bg-secondary { background: #eef3f4 !important; color: #607780 !important; border: 1px solid #d5e0e3; }
        .settings-page .badge.bg-info { background: #e6f4fb !important; color: #157ca0 !important; border: 1px solid #bfe3f2; }
        .settings-page .text-success { color: #0E6B5E !important; }
        .settings-page .form-control, .settings-page .form-select { font-size: 13.5px; font-family: "Inter", "DM Sans", system-ui, sans-serif; border-radius: 10px; border: 1px solid #D1E7DD; }
        .settings-page .form-control:focus, .settings-page .form-select:focus { border-color: #0E6B5E; box-shadow: 0 0 0 .15rem rgba(14,107,94,.12); }
        .settings-page .form-label { font-size: 12.5px; font-family: "Inter", system-ui, sans-serif; font-weight: 700; color: #4d6873; }
        .settings-page .modal-content { border-radius: 16px !important; border: 1px solid #C9E9DA !important; overflow: visible !important; }
        .settings-page .modal-header { background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%) !important; border-bottom: 1px solid #C9E9DA !important; border-top-left-radius: 15px !important; border-top-right-radius: 15px !important; }
        .settings-page .modal-footer { border-bottom-left-radius: 15px !important; border-bottom-right-radius: 15px !important; }
        .settings-page .modal-title { font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-size: 17px; font-weight: 800; color: #143f51; }
        .settings-table td.text-center { vertical-align: middle; }
        /* Accordion: Services at User Accounts lang */
        #settingsAccordion .accordion-item { background:#fff !important; border:1px solid #C9E9DA !important; border-radius:16px !important; overflow:hidden; box-shadow:0 8px 24px rgba(14,107,94,.08) !important; margin:0 14px 16px 14px !important; }
        #settingsAccordion .accordion-button { background:linear-gradient(135deg,#D9F2E6 0%,#EAF7F0 100%) !important; color:#143f51 !important; font-family:"Plus Jakarta Sans","Inter",system-ui,sans-serif; font-size:17px !important; font-weight:800; box-shadow:none !important; }
        #settingsAccordion .accordion-button:not(.collapsed) { background:linear-gradient(135deg,#D9F2E6 0%,#EAF7F0 100%) !important; color:#143f51 !important; }
        #settingsAccordion .accordion-button i { color:#0E6B5E; font-size:18px; margin-right:8px; }
        #settingsAccordion .accordion-body { padding:20px !important; }

        /* User Accounts Pagination */
        .history-pagination {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            background: #F6FBF8;
            border-top: 1px solid #E6F2EC;
            border-bottom-left-radius: 12px;
            border-bottom-right-radius: 12px;
            margin-top: 12px;
        }
        .pagination-info {
            font-size: 13px;
            color: #3F525B;
            font-family: "Inter", system-ui, sans-serif;
            font-weight: 600;
        }
        .page-notation {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .page-link-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            color: #143F51;
            background: #ffffff;
            border: 1px solid #C9E9DA;
            text-decoration: none;
            transition: all .15s ease;
        }
        .page-link-btn:hover {
            background: #EAF7F0;
            color: #0E6B5E;
            border-color: #0E6B5E;
        }
        .page-link-btn.active {
            background: #0E6B5E !important;
            color: #ffffff !important;
            border-color: #0E6B5E !important;
        }

        @media (max-width: 991px) { .settings-page { margin-left: 0 !important; width: 100% !important; padding: 100px 16px 40px !important; } .settings-page > section.settings-card { margin-left: 0 !important; margin-right: 0 !important; } .settings-page > a.btn, .settings-page > .alert, .settings-page > .mb-4 { margin-left: 0 !important; margin-right: 0 !important; } }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes.php'; ?>

<main class="settings-page">
    <?php showFlash(); ?>

    <a href="dashboard.php" class="btn btn-outline-secondary btn-sm mb-3" data-tip="Back to Dashboard">
        <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
    </a>

    <div class="mb-4">
        <h1 class="h4 mb-1">System Settings</h1>
        <p class="text-muted small mb-0">Manage clinic information, services, operating hours, and user accounts.</p>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <section class="settings-card">
                <h2><i class="bi bi-hospital"></i>Clinic Information</h2>
                <form method="POST">
<?= csrfField() ?>
                    <input type="hidden" name="action" value="clinic">
                    <label class="form-label">Clinic Name</label>
                    <input name="clinic_name" class="form-control mb-3" required value="<?= e($settings['clinic_name'] ?? '') ?>">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control mb-3" rows="2"><?= e($settings['address'] ?? '') ?></textarea>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Contact Number</label><input name="contact_number" class="form-control" value="<?= e($settings['contact_number'] ?? '') ?>"></div>
                        <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($settings['email'] ?? '') ?>"></div>
                        <div class="col-md-6"><label class="form-label">Opening Time</label><input type="time" name="opening_time" class="form-control" required value="<?= e(substr($settings['opening_time'] ?? '08:00:00', 0, 5)) ?>"></div>
                        <div class="col-md-6"><label class="form-label">Closing Time</label><input type="time" name="closing_time" class="form-control" required value="<?= e(substr($settings['closing_time'] ?? '17:00:00', 0, 5)) ?>"></div>
                    </div>
                    <button class="btn btn-primary mt-4" data-tip="Save Clinic Settings"><i class="bi bi-save"></i> Save Clinic Settings</button>
                </form>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="settings-card">
                <h2><i class="bi bi-gear-fill"></i>Basic System Settings</h2>
                <div class="d-flex justify-content-between align-items-center border-bottom py-3"><span class="small">Appointment statuses</span><strong class="small text-success">Enabled</strong></div>
                <div class="d-flex justify-content-between align-items-center border-bottom py-3"><span class="small">Queue management</span><strong class="small text-success">Enabled</strong></div>
                <div class="d-flex justify-content-between align-items-center py-3"><span class="small">Appointment notifications</span><strong class="small text-success">Enabled</strong></div>
            </section>
        </div>
    </div>

    <div class="accordion" id="settingsAccordion">
    <div class="accordion-item">
        <h2 class="accordion-header" id="headingServices">
            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseServices" aria-expanded="true" aria-controls="collapseServices">
                <i class="bi bi-card-list"></i>Services
            </button>
        </h2>
        <div id="collapseServices" class="accordion-collapse collapse show" aria-labelledby="headingServices" data-bs-parent="#settingsAccordion">
            <div class="accordion-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="small text-muted">Manage dental services.</span>
            <button class="btn btn-primary btn-sm" data-tip="Add Service" data-bs-toggle="modal" data-bs-target="#serviceModal"><i class="bi bi-plus-lg"></i> Add Service</button>
        </div>
        <div class="table-responsive">
            <table class="table settings-table mb-0">
                <thead><tr><th>Service</th><th>Description</th><th>Duration</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                <?php if (empty($services)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No services configured yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($services as $service): ?>
                        <tr>
                            <td><strong><?= e($service['service_name']) ?></strong></td>
                            <td><?= e($service['description']) ?></td>
                            <td><?= (int) $service['duration_minutes'] ?> minutes</td>
                            <td><span class="badge <?= $service['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>"><?= e($service['status']) ?></span></td>
                            <td class="text-nowrap">
                                <a href="settings.php?edit_service=<?= (int) $service['id'] ?>" class="btn btn-sm btn-outline-secondary" data-tip="Edit Service"><i class="bi bi-pencil"></i></a>
                                <form method="POST" class="d-inline">
<?= csrfField() ?><input type="hidden" name="action" value="service_status"><input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>"><button class="btn btn-sm btn-outline-primary" data-tip="Toggle Service Status"><i class="bi bi-power"></i></button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header" id="headingUsers">
            <button class="accordion-button <?= $isUsersTabActive ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapseUsers" aria-expanded="<?= $isUsersTabActive ? 'true' : 'false' ?>" aria-controls="collapseUsers">
                <i class="bi bi-people-fill"></i>User Accounts (<?= (int)$totalUsers ?>)
            </button>
        </h2>
        <div id="collapseUsers" class="accordion-collapse collapse <?= $isUsersTabActive ? 'show' : '' ?>" aria-labelledby="headingUsers" data-bs-parent="#settingsAccordion">
            <div class="accordion-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="small text-muted">Manage user accounts (10 accounts per page).</span>
            <button class="btn btn-primary btn-sm" data-tip="Add User" data-bs-toggle="modal" data-bs-target="#userModal"><i class="bi bi-person-plus"></i> Add User</button>
        </div>
        <div class="table-responsive">
            <table class="table settings-table mb-0">
                <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Date Created</th></tr></thead>
                <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="4" class="text-center text-muted">No user accounts found.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <tr><td><?= e($user['full_name']) ?></td><td><?= e($user['username']) ?></td><td><span class="badge bg-info text-dark"><?= e($user['role']) ?></span></td><td><?= e(date('M d, Y', strtotime($user['created_at']))) ?></td></tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalUserPages > 1): ?>
        <?php
        $uFrom = $totalUsers > 0 ? $userOffset + 1 : 0;
        $uTo = min($userOffset + $userPerPage, $totalUsers);
        $uStart = max(1, min($userPage - 2, $totalUserPages - 4));
        $uEnd = min($totalUserPages, $uStart + 4);
        $uStart = max(1, $uEnd - 4);
        ?>
        <div class="history-pagination">
            <span class="pagination-info">Showing <strong><?= (int) $uFrom ?></strong>-<strong><?= (int) $uTo ?></strong> of <strong><?= (int) $totalUsers ?></strong> accounts</span>
            <nav class="page-notation" aria-label="User accounts pages">
                <?php if ($userPage > 1): ?>
                    <a href="settings.php?user_page=<?= (int) ($userPage - 1) ?>#collapseUsers" class="page-link-btn" aria-label="Previous page"><i class="bi bi-chevron-left"></i></a>
                <?php endif; ?>
                <?php for ($p = $uStart; $p <= $uEnd; $p++): ?>
                    <a href="settings.php?user_page=<?= (int) $p ?>#collapseUsers" class="page-link-btn <?= $p === $userPage ? 'active' : '' ?>"><?= (int) $p ?></a>
                <?php endfor; ?>
                <?php if ($userPage < $totalUserPages): ?>
                    <a href="settings.php?user_page=<?= (int) ($userPage + 1) ?>#collapseUsers" class="page-link-btn" aria-label="Next page"><i class="bi bi-chevron-right"></i></a>
                <?php endif; ?>
            </nav>
        </div>
        <?php elseif ($totalUsers > 0): ?>
        <div class="history-pagination">
            <span class="pagination-info">Showing 1-<?= (int) $totalUsers ?> of <?= (int) $totalUsers ?> accounts</span>
        </div>
        <?php endif; ?>
            </div>
        </div>
    </div>
    </div>
</main>

<div class="modal fade" id="serviceModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><form method="POST">
<?= csrfField() ?>
        <input type="hidden" name="action" value="service_save">
        <?php if ($editService): ?><input type="hidden" name="service_id" value="<?= (int) $editService['id'] ?>"><?php endif; ?>
        <div class="modal-header"><h5 class="modal-title"><?= $editService ? 'Edit Service' : 'Add Service' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" data-tip="Close"></button></div>
        <div class="modal-body"><label class="form-label">Service Name</label><input name="service_name" class="form-control mb-3" required value="<?= e($editService['service_name'] ?? '') ?>"><label class="form-label">Description</label><textarea name="description" class="form-control mb-3" rows="2"><?= e($editService['description'] ?? '') ?></textarea><label class="form-label">Duration in minutes</label><input type="number" name="duration_minutes" class="form-control" min="1" value="<?= (int) ($editService['duration_minutes'] ?? 30) ?>"></div>
        <div class="modal-footer"><button class="btn btn-primary" data-tip="Save Service">Save Service</button></div>
    </form></div></div>
</div>

<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><form method="POST">
<?= csrfField() ?>
        <input type="hidden" name="action" value="user_save">
        <div class="modal-header"><h5 class="modal-title">Add User Account</h5><button type="button" class="btn-close" data-bs-dismiss="modal" data-tip="Close"></button></div>
        <div class="modal-body"><label class="form-label">Full Name</label><input name="full_name" class="form-control mb-3" required><label class="form-label">Username / Email</label><input name="username" class="form-control mb-3" required><label class="form-label">Role</label><select name="role" class="form-select mb-3"><option value="patient">Patient</option><option value="dentist">Dentist</option><option value="admin">Admin</option></select><label class="form-label">Password</label><input type="password" name="password" class="form-control" required minlength="6"></div>
        <div class="modal-footer"><button class="btn btn-primary" data-tip="Create User">Create User</button></div>
    </form></div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($editService): ?><script>new bootstrap.Modal(document.getElementById('serviceModal')).show();</script><?php endif; ?>
</body>
</html>