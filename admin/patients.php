<?php

require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$message = '';
$messageType = 'success';
$savedEdit = false;
$openArchiveModal = false;

if (!empty($_SESSION['patients_flash_msg'])) {
    $message = (string) $_SESSION['patients_flash_msg'];
    $messageType = (string) ($_SESSION['patients_flash_type'] ?? 'success');
    unset($_SESSION['patients_flash_msg'], $_SESSION['patients_flash_type']);
}

/* Auto-ensure soft-delete (archive) columns exist on patients table */
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM patients LIKE 'is_archived'");
    if ($colCheck && !$colCheck->fetch()) {
        $pdo->exec("ALTER TABLE patients ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN archived_at DATETIME NULL DEFAULT NULL");
    }
} catch (PDOException $e) {
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
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['patient_id'] ?? 0);
        if ($deleteId > 0) {
            try {
                $checkStmt = $pdo->prepare('SELECT id, is_archived FROM patients WHERE id = ? LIMIT 1');
                $checkStmt->execute([$deleteId]);
                $pRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
                if ($pRow) {
                    $archStmt = $pdo->prepare('UPDATE patients SET is_archived = 1, archived_at = NOW() WHERE id = ?');
                    $archStmt->execute([$deleteId]);
                    $message = 'Patient moved to Archived Patients. You can restore or permanently delete it anytime.';
                }
            } catch (PDOException $e) {
                $message = 'Unable to archive patient.';
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'restore') {
        $restoreId = (int) ($_POST['patient_id'] ?? 0);
        if ($restoreId > 0) {
            try {
                $resStmt = $pdo->prepare('UPDATE patients SET is_archived = 0, archived_at = NULL WHERE id = ?');
                $resStmt->execute([$restoreId]);
                $message = 'Patient restored to Active Patient List successfully.';
            } catch (PDOException $e) {
                $message = 'Unable to restore patient.';
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'permanent_delete') {
        $deleteId = (int) ($_POST['patient_id'] ?? 0);
        if ($deleteId > 0) {
            try {
                $checkStmt = $pdo->prepare('SELECT id FROM patients WHERE id = ? LIMIT 1');
                $checkStmt->execute([$deleteId]);
                if ($checkStmt->fetchColumn()) {
                    /* Walang FK ang ibang related tables kaya linisin muna bago burahin ang patient. */
                    foreach (['appointment_concerns', 'admin_notifications'] as $relatedTable) {
                        try {
                            $pdo->prepare("DELETE FROM {$relatedTable} WHERE patient_id = ?")->execute([$deleteId]);
                        } catch (PDOException $e) {
                        }
                    }
                    /* Appointments, queue, notifications, medical records: ON DELETE CASCADE. */
                    $delStmt = $pdo->prepare('DELETE FROM patients WHERE id = ?');
                    $delStmt->execute([$deleteId]);
                    $message = 'Patient permanently deleted.';
                }
            } catch (PDOException $e) {
                $message = 'Unable to delete patient. The record may be linked to other data.';
                $messageType = 'danger';
            }
        }
    } elseif ($firstName === '' || $lastName === '') {
        $message = 'First name and last name are required.';
        $messageType = 'danger';
    } else {
        try {
            if ($action === 'edit') {
                $stmt = $pdo->prepare("
                    UPDATE patients
                    SET first_name = ?, middle_name = ?, last_name = ?,
                        contact_number = ?, email = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $firstName,
                    $middleName,
                    $lastName,
                    $contactNumber,
                    $email,
                    (int) ($_POST['patient_id'] ?? 0)
                ]);
                $message = 'Patient information updated successfully.';
                $savedEdit = true;
            } else {
                $patientNumber = generateShortPatientNumber($pdo);
                $stmt = $pdo->prepare("
                    INSERT INTO patients
                    (patient_number, first_name, middle_name, last_name, contact_number, email)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $patientNumber,
                    $firstName,
                    $middleName,
                    $lastName,
                    $contactNumber,
                    $email
                ]);
                $message = 'Patient added successfully.';
            }
        } catch (PDOException $e) {
            $message = 'Unable to save patient information.';
            $messageType = 'danger';
        }
    }

    if ($message !== '') {
        $_SESSION['patients_flash_msg'] = $message;
        $_SESSION['patients_flash_type'] = $messageType;
    }
    $redirectParams = [];
    if (trim($_GET['search'] ?? '') !== '') {
        $redirectParams['search'] = trim($_GET['search']);
    }
    if ((int) ($_GET['page'] ?? 1) > 1) {
        $redirectParams['page'] = (int) $_GET['page'];
    }
    $redirectUrl = 'patients.php' . (!empty($redirectParams) ? '?' . http_build_query($redirectParams) : '');
    header('Location: ' . $redirectUrl);
    exit;
}

$search = trim($_GET['search'] ?? '');
$searchLike = '%' . $search . '%';

/* Pagination: 10 patients kada page */
$perPage = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));

/* Count total matching active patients */
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM patients p
    LEFT JOIN users u ON u.id = p.user_id
    WHERE (u.id IS NULL OR u.role = 'patient')
      AND COALESCE(p.is_archived, 0) = 0
      AND LOWER(TRIM(CONCAT(p.first_name,' ',p.last_name))) != 'admin admin'
      AND LOWER(TRIM(p.first_name)) != 'admin'
      AND (
        CONCAT(p.first_name, ' ', p.last_name) LIKE ?
        OR p.contact_number LIKE ?
        OR p.email LIKE ?
      )
");
$countStmt->execute([$searchLike, $searchLike, $searchLike]);
$totalPatients = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalPatients / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT p.*
    FROM patients p
    LEFT JOIN users u ON u.id = p.user_id
    WHERE (u.id IS NULL OR u.role = 'patient')
      AND COALESCE(p.is_archived, 0) = 0
      AND LOWER(TRIM(CONCAT(p.first_name,' ',p.last_name))) != 'admin admin'
      AND LOWER(TRIM(p.first_name)) != 'admin'
      AND (
        CONCAT(p.first_name, ' ', p.last_name) LIKE ?
        OR p.contact_number LIKE ?
        OR p.email LIKE ?
      )
    ORDER BY p.id ASC
    LIMIT " . (int) $perPage . " OFFSET " . (int) $offset . "
");
$stmt->execute([$searchLike, $searchLike, $searchLike]);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Fetch archived patients for the Archived Patients modal */
$archivedPatients = [];
try {
    $archListStmt = $pdo->query("
        SELECT p.*
        FROM patients p
        LEFT JOIN users u ON u.id = p.user_id
        WHERE (u.id IS NULL OR u.role = 'patient')
          AND COALESCE(p.is_archived, 0) = 1
          AND LOWER(TRIM(CONCAT(p.first_name,' ',p.last_name))) != 'admin admin'
          AND LOWER(TRIM(p.first_name)) != 'admin'
        ORDER BY p.archived_at DESC, p.id DESC
    ");
    if ($archListStmt) {
        $archivedPatients = $archListStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
}
$totalArchivedPatients = count($archivedPatients);

function patientPageUrl($pageNum, $searchTerm)
{
    $params = [];
    if ($searchTerm !== '') {
        $params['search'] = $searchTerm;
    }
    $params['page'] = max(1, (int) $pageNum);
    return 'patients.php?' . http_build_query($params);
}

$selectedPatient = null;
$history = [];
$viewId = (int) ($_GET['view'] ?? 0);

if ($viewId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM patients WHERE id = ? LIMIT 1');
    $stmt->execute([$viewId]);
    $selectedPatient = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedPatient) {
        $stmt = $pdo->prepare("
            SELECT a.*, d.dentist_name
            FROM appointments a
            LEFT JOIN dentists d ON d.id = a.dentist_id
            WHERE a.patient_id = ?
            ORDER BY a.appointment_date DESC, a.start_time DESC
        ");
        $stmt->execute([$viewId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$editPatient = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0 && empty($savedEdit)) {
    $stmt = $pdo->prepare('SELECT * FROM patients WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $editPatient = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function patientPageEscape($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patients | Abalos Dental Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css?v=2">
    <style>
        body { background: linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22) !important; background-size: 300% 300% !important; animation: adminBgAnim 8s ease infinite !important; background-attachment: fixed !important; }
        .shared-topbar { left: 280px !important; right: 0 !important; background:#ffffff!important;border-bottom:1px solid #e8eef2!important; }
        @media (max-width: 991px) { .shared-topbar { left: 0 !important; } }
        /* Tulad sa dentist appointment topbar - date at clinic time */
        .shared-topbar .admin-area{display:flex!important;align-items:center!important;gap:16px!important;}
        .admin-topbar-today{display:flex;align-items:center;gap:10px;color:#244f60;}
        .admin-topbar-today i{font-size:22px;}
        .admin-topbar-today strong{display:block;font-size:13px;font-weight:800;line-height:1.2;}
        .admin-topbar-today small{display:block;font-size:11px;opacity:.85;font-weight:600;}
        .admin-topbar-time{display:flex;align-items:center;gap:10px;color:#244f60;border-left:1px solid #e8eef2;padding-left:16px;}
        .admin-topbar-time i{font-size:22px;}
        .admin-topbar-time strong{display:block;font-size:13px;font-weight:800;line-height:1.2;}
        .admin-topbar-time small{display:block;font-size:11px;opacity:.85;font-weight:600;font-variant-numeric:tabular-nums;}
        .patients-page { margin-left: 280px !important; width: calc(100% - 280px) !important; padding: 110px 24px 40px !important; background: transparent !important; min-height: 100vh; box-sizing: border-box !important; }
        .patients-page > .mb-3 { margin-left: 14px; margin-right: 14px; }
        .patients-page > .alert { margin-left: 14px; margin-right: 14px; font-size: 13.5px; border-radius: 12px; }
        .patients-head { margin: 0 14px 16px 14px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; }
        .patients-head h1 { color: #ffffff !important; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; margin: 0; }
        .patients-head p { margin: 4px 0 0; color: #C9E9DA; font-size: 13px; }
        /* Patient panel — katulad ng patient My Appointments white card na table */
        .patient-panel {
            background: #ffffff !important;
            border: 1px solid #C9E9DA !important;
            border-radius: 16px !important;
            overflow: hidden !important;
            box-shadow: 0 8px 24px rgba(14,107,94,.08) !important;
            padding: 0 !important;
            min-height: auto !important;
            margin: 0 14px 24px 14px !important;
        }
        .patient-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 18px 20px;
            background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%);
            border-bottom: 1px solid #C9E9DA;
        }
        .patient-panel-header h2 {
            color: #143f51 !important;
            font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
            font-size: 17px !important;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .patient-panel-header h2 i { color: #0E6B5E; font-size: 18px; }
        .patient-panel-header p { margin: 3px 0 0 26px; color: #8aa0a8; font-size: 12px; }
        .patient-panel .table-responsive { min-height: auto; }
        .patient-panel form.row { padding: 16px 20px 0 20px; }
        /* Info rows inside selected patient panel */
        .patient-panel .row.g-3.small { padding: 0 20px; }
        .patient-panel .d-flex.justify-content-between.mb-3 { padding: 0 20px; margin-top: 16px; }
        .patient-panel h3.h6 { padding: 0 20px; color: #143f51 !important; font-weight: 800; }
        /* Table — gaya sa patient My Appointments */
        .patient-table { margin-bottom: 0 !important; width: 100%; border-collapse: separate; border-spacing: 0; background: #ffffff; }
        .patient-table thead tr { background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important; }
        .patient-table thead th {
            background: transparent !important;
            color: #ffffff !important;
            font-size: 11.5px !important;
            font-family: "Inter", system-ui, sans-serif;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            white-space: nowrap;
            padding: 14px 16px !important;
            border-bottom: none !important;
            border-right: 1px solid rgba(255,255,255,.12) !important;
        }
        .patient-table thead th:last-child { border-right: none !important; }
        .patient-table tbody td {
            padding: 14px 16px !important;
            color: #4d6873 !important;
            font-size: 13.5px !important;
            font-family: "Inter", "DM Sans", system-ui, sans-serif;
            vertical-align: middle;
            background: #ffffff;
            border-bottom: 1px solid #E6F2EC !important;
        }
        .patient-table tbody tr:nth-child(even) td { background: #F6FBF8 !important; }
        .patient-table tbody tr:hover td { background: #EAF7F0 !important; }
        .patient-table .patient-name { color: #143f51 !important; font-weight: 700; font-size: 13.5px !important; }
        .patient-status { background: linear-gradient(135deg, #149A72, #0A4A41) !important; color: #fff !important; border-radius: 20px; padding: 6px 11px !important; font-size: 11.5px !important; font-family: "Inter", system-ui, sans-serif; font-weight: 700; }
        .history-status { border-radius: 20px; padding: 6px 11px !important; font-size: 11.5px !important; font-family: "Inter", system-ui, sans-serif; font-weight: 700; background: #EAF7F0 !important; color: #0E6B5E !important; border: 1px solid #C9E9DA !important; }
        .history-status.pending { background: #0E6B5E !important; color: #fff !important; border-color: #0A4A41 !important; }
        .history-status.cancelled { background: #ef4444 !important; color: #fff !important; border-color: #dc2626 !important; }
        .patient-table tbody tr:last-child td:first-child { border-bottom-left-radius: 16px; }
        .patient-table tbody tr:last-child td:last-child { border-bottom-right-radius: 16px; }
        .patients-page .btn-primary { background: linear-gradient(135deg, #149A72, #0A4A41) !important; border: none !important; border-radius: 8px; box-shadow: 0 6px 14px rgba(14,107,94,.22); font-weight: 700; }
        .patients-page .btn-primary:hover { background: linear-gradient(135deg, #0E6B5E, #083A32) !important; }
        .patients-page .btn-outline-primary { color: #0E6B5E !important; border-color: #C9E9DA !important; background: #fff !important; }
        .patients-page .btn-outline-primary:hover { background: #EAF7F0 !important; color: #0E6B5E !important; border-color: #0E6B5E !important; }
        .patients-page .btn-outline-secondary { color: #57727d !important; border-color: #D1E7DD !important; background: #fff !important; }
        .patients-page .btn-outline-secondary:hover { background: #EAF7F0 !important; color: #0E6B5E !important; border-color: #0E6B5E !important; }
        .patients-page .btn-light { background: #fff !important; border: 1px solid #D1E7DD !important; color: #57727d !important; }
        .patients-page .table-responsive { min-height: auto; }
        /* Readable details */
        .patients-page .small,
        .patient-panel .small,
        .patient-panel .text-muted.small { font-size: 13.5px !important; font-family: "Inter", "DM Sans", system-ui, sans-serif; }
        .patients-page .row.g-3.small { font-size: 13.5px; }
        .patients-page .row.g-3.small strong { font-size: 11.5px; color: #8aa0a8; font-family: "Inter", system-ui, sans-serif; text-transform: uppercase; letter-spacing: .4px; }
        .patients-page .form-control { font-size: 13.5px; font-family: "Inter", "DM Sans", system-ui, sans-serif; border-radius: 10px; border: 1px solid #D1E7DD; }
        .patients-page .form-label { font-size: 12.5px; font-family: "Inter", system-ui, sans-serif; font-weight: 700; color: #4d6873; }
        .patients-page .alert { font-size: 13.5px; font-family: "Inter", system-ui, sans-serif; border-radius: 12px; }
        .patients-page .btn { font-size: 13.5px; }
        .patients-page .btn-sm { font-size: 12.5px; }
        .patients-page .modal-content { border-radius: 16px !important; border: 1px solid #C9E9DA !important; overflow: visible !important; }
        .patients-page .modal-header { background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%) !important; border-bottom: 1px solid #C9E9DA !important; border-top-left-radius: 15px !important; border-top-right-radius: 15px !important; }
        .patients-page .modal-footer { border-bottom-left-radius: 15px !important; border-bottom-right-radius: 15px !important; }
        .patients-page .modal-title { font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-size: 18px; font-weight: 800; color: #143f51; }
        .patient-table .text-muted { font-size: 13px !important; }
        /* Scroll slide — green scrollbar, 10 ang kita */
        .admin-patient-scroll {
            overflow: auto;
            max-height: 640px;
            scrollbar-width: thin;
            scrollbar-color: #149A72 #DFF2E9;
        }
        .admin-patient-scroll::-webkit-scrollbar {
            width: 10px;
        }
        .admin-patient-scroll::-webkit-scrollbar-track {
            background: #DFF2E9;
            border-radius: 8px;
        }
        .admin-patient-scroll::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #149A72, #0A4A41);
            border-radius: 8px;
        }
        .admin-patient-scroll::-webkit-scrollbar-thumb:hover {
            background: #0E6B5E;
        }
        .admin-patient-scroll thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important;
        }
        @media (max-width: 991px) { .patients-page { margin-left: 0 !important; width: 100% !important; padding: 100px 16px 40px !important; } .patient-panel { margin-left: 0 !important; margin-right: 0 !important; } }

        /* Pagination Styling */
        .history-pagination {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 14px 22px;
            background: #F6FBF8;
            border-top: 1px solid #E6F2EC;
            border-bottom-left-radius: 16px;
            border-bottom-right-radius: 16px;
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
            border-radius: 9px;
            border: 1px solid #D1E7DD;
            background: #fff;
            color: #0E6B5E !important;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            font-family: "Inter", system-ui, sans-serif;
            transition: all .2s ease;
        }
        .page-link-btn:hover:not(.disabled) {
            background: #EAF7F0;
            border-color: #0E6B5E;
            color: #0E6B5E !important;
        }
        .page-link-btn.active {
            background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important;
            border-color: #0A4A41 !important;
            color: #ffffff !important;
            box-shadow: 0 3px 8px rgba(14,107,94,.25);
        }
        .page-link-btn.disabled,
        .page-link-btn:disabled {
            opacity: .45;
            pointer-events: none;
            background: #f1f5f9;
            border-color: #e2e8f0;
            color: #94a3b8 !important;
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes.php'; ?>

<div class="patients-page">
    <div class="mb-3" style="display:block !important;">
        <a href="dashboard.php" class="btn btn-secondary btn-sm js-close-tab" style="display:inline-block !important;" data-tip="Back to Dashboard">
            <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
        </a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= patientPageEscape($messageType) ?>">
            <?= patientPageEscape($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($selectedPatient): ?>
        <div class="modal fade" id="patientViewModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-eye me-1" style="color:#0E6B5E;"></i>Patient Information</h5>
                        <a href="<?= patientPageEscape(patientPageUrl($page, $search)) ?>" class="btn-close" aria-label="Close"></a>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3 small mb-3">
                            <div class="col-md-3"><strong>Patient ID</strong><br><?= patientPageEscape($selectedPatient['patient_number'] ?? $selectedPatient['id']) ?></div>
                            <div class="col-md-3"><strong>Name</strong><br><?= patientPageEscape($selectedPatient['first_name'] . ' ' . $selectedPatient['middle_name'] . ' ' . $selectedPatient['last_name']) ?></div>
                            <div class="col-md-3"><strong>Contact</strong><br><?= patientPageEscape($selectedPatient['contact_number']) ?></div>
                            <div class="col-md-3"><strong>Email</strong><br><?= patientPageEscape($selectedPatient['email']) ?></div>
                        </div>
                        <h3 class="h6 mt-3">Appointment History</h3>
                        <?php if (empty($history)): ?>
                            <p class="text-muted small mb-0">No appointment history found.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table patient-table mb-0">
                                    <thead><tr><th>Date of Appointment</th><th>Time of Appointment</th><th>Assigned Dentist</th><th>Dental Service</th><th>Status</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($history as $appointment): ?>
                                        <tr>
                                            <td><?= patientPageEscape($appointment['appointment_date']) ?></td>
                                            <td><?= patientPageEscape(date('h:i A', strtotime($appointment['start_time']))) ?></td>
                                            <td><?= patientPageEscape($appointment['dentist_name']) ?></td>
                                            <td><?= patientPageEscape($appointment['service_type']) ?></td>
                                            <td><span class="history-status <?= strtolower(patientPageEscape($appointment['status'])) ?>"><?= patientPageEscape($appointment['status']) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <a href="dashboard.php" class="btn btn-secondary btn-sm js-close-tab" data-tip="Back to Dashboard"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>
                        <a href="<?= patientPageEscape(patientPageUrl($page, $search)) ?>" class="btn btn-light" data-tip="Close">Close</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="patient-panel">
        <div class="patient-panel-header">
            <div>
                <h2><i class="bi bi-people-fill"></i> Patient List</h2>
                <p>View and manage registered dental patients.</p>
            </div>
            <div>
                <button type="button" class="btn btn-sm patient-archive-header-btn" data-bs-toggle="modal" data-bs-target="#archivedPatientsModal" data-tip="View Archived / Deleted Patients">
                    <i class="bi bi-archive-fill me-1"></i> Archived Patients
                    <span class="archive-count-badge"><?= (int) $totalArchivedPatients ?></span>
                </button>
            </div>
        </div>

        <form method="GET" class="row g-2 mb-4">
            <div class="col-md-8"><input type="search" name="search" value="<?= patientPageEscape($search) ?>" class="form-control" placeholder="Search patient name, contact, or email"></div>
            <div class="col-auto"><button class="btn btn-outline-primary" data-tip="Search"><i class="bi bi-search"></i> Search</button></div>
            <?php if ($search !== ''): ?><div class="col-auto"><a href="patients.php" class="btn btn-light" data-tip="Clear">Clear</a></div><?php endif; ?>
        </form>

        <div class="table-responsive admin-patient-scroll" id="adminPatientListWrap">
            <table class="table patient-table align-middle" id="patientListTable">
                <thead><tr><th>#</th><th>Patient ID</th><th>Patient</th><th>Email</th><th>Contact</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                <?php if (empty($patients)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No patients found.</td></tr>
                <?php else: ?>
                    <?php foreach ($patients as $idx => $patient): ?>
                        <tr>
                            <td><?= ($offset + $idx + 1) ?></td>
                            <td><?= patientPageEscape($patient['patient_number'] ?? $patient['id']) ?></td>
                            <td class="patient-name"><?= patientPageEscape($patient['first_name'] . ' ' . $patient['last_name']) ?></td>
                            <td><?= patientPageEscape($patient['email'] !== '' ? $patient['email'] : '—') ?></td>
                            <td><?= patientPageEscape($patient['contact_number']) ?></td>
                            <td><span class="patient-status">Active</span></td>
                            <td class="text-nowrap">
                                <button
                                    type="button"
                                    class="btn btn-sm patient-manage-btn"
                                    data-tip="Manage Patient"
                                    data-patient-id="<?= (int) $patient['id'] ?>"
                                    data-patient-number="<?= patientPageEscape($patient['patient_number'] ?? $patient['id']) ?>"
                                    data-patient-name="<?= patientPageEscape(trim($patient['first_name'] . ' ' . $patient['last_name'])) ?>"
                                    data-view-url="<?= patientPageEscape('patients.php?' . http_build_query(array_filter(['view' => (int) $patient['id'], 'page' => $page, 'search' => $search !== '' ? $search : null]))) ?>"
                                    data-edit-url="<?= patientPageEscape('patients.php?' . http_build_query(array_filter(['edit' => (int) $patient['id'], 'page' => $page, 'search' => $search !== '' ? $search : null]))) ?>"
                                    style="cursor:pointer;">
                                    <i class="bi bi-sliders me-1"></i> Manage <i class="bi bi-chevron-down ms-1"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <?php
        $pFrom = $totalPatients > 0 ? $offset + 1 : 0;
        $pTo = min($offset + $perPage, $totalPatients);
        $pStart = max(1, min($page - 3, $totalPages - 6));
        $pEnd = min($totalPages, $pStart + 6);
        $pStart = max(1, $pEnd - 6);
        ?>
        <div class="history-pagination">
            <span class="pagination-info">Showing <strong><?= (int) $pFrom ?></strong>—<strong><?= (int) $pTo ?></strong> of <strong><?= (int) $totalPatients ?></strong> patient<?= $totalPatients === 1 ? '' : 's' ?></span>
            <nav class="page-notation" aria-label="Patient list pages">
                <?php if ($page > 1): ?>
                    <a href="<?= patientPageEscape(patientPageUrl($page - 1, $search)) ?>" class="page-link-btn" aria-label="Previous page"><i class="bi bi-chevron-left"></i></a>
                <?php endif; ?>
                <?php for ($p = $pStart; $p <= $pEnd; $p++): ?>
                    <a href="<?= patientPageEscape(patientPageUrl($p, $search)) ?>" class="page-link-btn <?= $p === $page ? 'active' : '' ?>"><?= (int) $p ?></a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="<?= patientPageEscape(patientPageUrl($page + 1, $search)) ?>" class="page-link-btn" aria-label="Next page"><i class="bi bi-chevron-right"></i></a>
                <?php endif; ?>
            </nav>
        </div>
        <?php elseif ($totalPatients > 0): ?>
        <div class="history-pagination">
            <span class="pagination-info">Showing 1—<?= (int) $totalPatients ?> of <?= (int) $totalPatients ?> patient<?= $totalPatients === 1 ? '' : 's' ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- =========================================================
     MANAGE PATIENT POPUP MODAL (View / Edit / Delete)
     ========================================================= -->
<style>
.patient-archive-header-btn {
    background: #ffffff !important;
    border: 1.5px solid #0E6B5E !important;
    color: #0E6B5E !important;
    font-weight: 800 !important;
    border-radius: 999px !important;
    padding: 7px 15px !important;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    box-shadow: 0 3px 10px rgba(14,107,94,.10);
    transition: all .15s ease;
}
.patient-archive-header-btn:hover {
    background: #0E6B5E !important;
    color: #ffffff !important;
}
.archive-count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    background: #0E6B5E;
    color: #ffffff;
    font-size: 11px;
    font-weight: 800;
    margin-left: 4px;
    transition: all .15s ease;
}
.patient-archive-header-btn:hover .archive-count-badge {
    background: #ffffff;
    color: #0E6B5E;
}
.pm-restore-btn {
    background: linear-gradient(135deg, #10B981, #047857) !important;
    color: #ffffff !important;
    border: none !important;
    border-radius: 8px !important;
    font-weight: 700 !important;
    padding: 6px 12px !important;
}
.pm-restore-btn:hover {
    filter: brightness(.93);
    color: #ffffff !important;
}
.pm-perm-delete-btn {
    background: #FFF1F2 !important;
    border: 1px solid #FECDD3 !important;
    color: #BE123C !important;
    border-radius: 8px !important;
    font-weight: 700 !important;
    padding: 6px 12px !important;
}
.pm-perm-delete-btn:hover {
    background: #E11D48 !important;
    border-color: #E11D48 !important;
    color: #ffffff !important;
}
.patient-manage-btn {
    background: #EAF7F0 !important;
    border: 1.5px solid #0E6B5E !important;
    color: #0E6B5E !important;
    font-weight: 800 !important;
    border-radius: 999px !important;
    padding: 6px 14px !important;
    white-space: nowrap;
    transition: all .15s ease;
}
.patient-manage-btn:hover {
    background: #0E6B5E !important;
    color: #ffffff !important;
}
.patient-manage-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
}
.patient-manage-grid .pm-btn {
    border: none;
    border-radius: 12px;
    padding: 15px 12px;
    font-weight: 800;
    font-size: 14px;
    letter-spacing: .4px;
    color: #ffffff !important;
    text-decoration: none !important;
    cursor: pointer;
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: .15s;
    width: 100%;
}
.patient-manage-grid .pm-btn i {
    font-size: 19px;
}
.patient-manage-grid .pm-btn:hover {
    filter: brightness(.93);
    transform: translateY(-2px);
    color: #ffffff !important;
}
.pm-view { background: linear-gradient(135deg, #10B981, #047857); }
.pm-edit { background: linear-gradient(135deg, #3B82F6, #1D4ED8); }
.pm-delete { background: linear-gradient(135deg, #EF4444, #B91C1C); }
#patientManageModal .modal-content,
#archivedPatientsModal .modal-content {
    overflow: visible !important;
}
.patient-manage-grid [data-tip]:hover::after {
    top: auto !important;
    bottom: calc(100% + 8px) !important;
    left: 50% !important;
    transform: translateX(-50%) !important;
    z-index: 99999 !important;
}
</style>

<!-- =========================================================
     ARCHIVED PATIENTS MODAL (Restore / Delete Permanently)
     ========================================================= -->
<div class="modal fade" id="archivedPatientsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:visible;">
            <div class="modal-header" style="background:linear-gradient(135deg,#149A72,#0A4A41) !important;border-bottom:none !important;">
                <h5 class="modal-title" style="color:#ffffff !important;font-weight:800;">
                    <i class="bi bi-archive-fill me-2"></i>Archived Patients (<?= (int) $totalArchivedPatients ?>)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" data-tip="Close"></button>
            </div>
            <div class="modal-body" style="padding:20px 22px;">
                <p class="text-muted small mb-3">
                    Deleted patients are safely stored here. You can <strong>Restore</strong> them back to the Active Patient List or <strong>Delete Permanently</strong>.
                </p>
                <?php if (empty($archivedPatients)): ?>
                    <div class="text-center py-5" style="background:#F6FBF8;border:1px dashed #C9E9DA;border-radius:14px;">
                        <i class="bi bi-archive" style="font-size:34px;color:#8aa0a8;"></i>
                        <p class="text-muted fw-semibold mt-2 mb-0">No archived patients found.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive" style="max-height:440px;overflow:auto;">
                        <table class="table patient-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Patient ID</th>
                                    <th>Patient</th>
                                    <th>Email</th>
                                    <th>Contact</th>
                                    <th>Deleted On</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($archivedPatients as $aIdx => $archPatient): ?>
                                    <tr>
                                        <td><?= ($aIdx + 1) ?></td>
                                        <td><?= patientPageEscape($archPatient['patient_number'] ?? $archPatient['id']) ?></td>
                                        <td class="patient-name"><?= patientPageEscape($archPatient['first_name'] . ' ' . $archPatient['last_name']) ?></td>
                                        <td><?= patientPageEscape(($archPatient['email'] ?? '') !== '' ? $archPatient['email'] : '—') ?></td>
                                        <td><?= patientPageEscape($archPatient['contact_number'] ?? '') ?></td>
                                        <td><?= !empty($archPatient['archived_at']) ? patientPageEscape(date('M d, Y h:i A', strtotime($archPatient['archived_at']))) : '—' ?></td>
                                        <td class="text-nowrap">
                                            <div class="d-inline-flex gap-2 flex-wrap">
                                                <form method="POST" class="d-inline m-0">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="restore">
                                                    <input type="hidden" name="patient_id" value="<?= (int) $archPatient['id'] ?>">
                                                    <button type="submit" class="btn btn-sm pm-restore-btn" data-tip="Restore Patient to Active List">
                                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Restore
                                                    </button>
                                                </form>
                                                <form method="POST" class="d-inline m-0 js-delete-patient-form" data-delete-mode="permanent" data-patient-name="<?= patientPageEscape(trim($archPatient['first_name'] . ' ' . $archPatient['last_name'])) ?>">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="permanent_delete">
                                                    <input type="hidden" name="patient_id" value="<?= (int) $archPatient['id'] ?>">
                                                    <button type="submit" class="btn btn-sm pm-perm-delete-btn" data-tip="Delete Permanently">
                                                        <i class="bi bi-trash3-fill me-1"></i> Delete Permanently
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer" style="background:#F6FBF8;border-top:1px solid #E6F2EC;">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-tip="Close">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="patientManageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:visible;">
            <div class="modal-header" style="background:linear-gradient(135deg,#149A72,#0A4A41) !important;border-bottom:none !important;">
                <h5 class="modal-title" style="color:#ffffff !important;font-weight:800;"><i class="bi bi-sliders me-2"></i>Manage Patient</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" data-tip="Close"></button>
            </div>
            <div class="modal-body" style="padding:20px 22px 22px;">
                <p class="small mb-1">Patient: <strong id="manageModalPatientName" style="color:#143f51;">—</strong></p>
                <p class="small mb-3 text-muted">Patient ID: <strong id="manageModalPatientNumber" style="color:#0E6B5E;">—</strong></p>
                <div class="patient-manage-grid">
                    <a href="#" id="manageModalViewBtn" class="pm-btn pm-view" data-tip="View Patient Details">
                        <i class="bi bi-eye-fill"></i> VIEW
                    </a>
                    <a href="#" id="manageModalEditBtn" class="pm-btn pm-edit" data-tip="Edit Patient">
                        <i class="bi bi-pencil-square"></i> EDIT
                    </a>
                    <form method="POST" id="manageModalDeleteForm" class="js-delete-patient-form m-0" data-delete-mode="archive" data-patient-name="">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="patient_id" id="manageModalDeleteId" value="">
                        <button type="submit" class="pm-btn pm-delete" data-tip="Delete / Archive Patient">
                            <i class="bi bi-trash-fill"></i> DELETE
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="patientModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
<?= csrfField() ?>
                <input type="hidden" name="action" value="<?= $editPatient ? 'edit' : 'add' ?>">
                <?php if ($editPatient): ?><input type="hidden" name="patient_id" value="<?= (int) $editPatient['id'] ?>"><?php endif; ?>
                <div class="modal-header" style="background:linear-gradient(135deg,#0E6B5E,#0A4A41);border-bottom:none;border-radius:14px 14px 0 0;padding:18px 22px;"><h5 class="modal-title" style="color:#fff;font-weight:800;"><i class="bi bi-pencil-square me-2"></i><?= $editPatient ? 'Edit Patient' : 'Add Patient' ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body" style="padding:22px;background:#F7FDFB;"><div class="row g-3">
                    <?php if ($editPatient): ?><div class="col-12"><label class="form-label">Patient ID</label><input class="form-control" value="<?= patientPageEscape($editPatient['patient_number'] ?? $editPatient['id']) ?>" disabled readonly></div><?php endif; ?>
                    <div class="col-md-4"><label class="form-label">First Name *</label><input name="first_name" class="form-control" required value="<?= patientPageEscape($editPatient['first_name'] ?? '') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Middle Name</label><input name="middle_name" class="form-control" value="<?= patientPageEscape($editPatient['middle_name'] ?? '') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Last Name *</label><input name="last_name" class="form-control" required value="<?= patientPageEscape($editPatient['last_name'] ?? '') ?>"></div>
                    <div class="col-md-6"><label class="form-label">Contact Number</label><input name="contact_number" class="form-control" value="<?= patientPageEscape($editPatient['contact_number'] ?? '') ?>"></div>
                    <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= patientPageEscape($editPatient['email'] ?? '') ?>"></div>
                </div></div>
                <div class="modal-footer" style="background:#F1FAF6;border-top:1px solid #CBECE0;border-radius:0 0 14px 14px;padding:14px 22px;"><button type="button" class="btn btn-light" data-bs-dismiss="modal" style="border:1px solid #D1E7DD;border-radius:10px;font-weight:700;" data-tip="Cancel">Cancel</button><button class="btn btn-primary" style="background:linear-gradient(135deg,#149A72,#0A4A41);border:none;border-radius:10px;font-weight:800;padding:9px 22px;box-shadow:0 6px 14px rgba(14,107,94,.30);" data-tip="<?= $editPatient ? 'Save Changes' : 'Add Patient' ?>"><?= $editPatient ? 'Save Changes' : 'Add Patient' ?></button></div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================
     DELETE PATIENT CONFIRM — same look as the logout modal
     ========================================================= -->
<div id="patientDeleteConfirm" class="admin-logout-overlay" style="display:none;">
    <div class="admin-logout-box">
        <div class="admin-logout-head">
            <span class="admin-logout-head-icon"><i class="bi bi-trash"></i></span>
            <h5 id="patientDeleteHeadTitle">Delete Patient</h5>
            <button type="button" class="admin-logout-x" id="patientDeleteClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="admin-logout-body">
            <div class="admin-logout-icon"><i class="bi bi-exclamation-circle-fill"></i></div>
            <h5 id="patientDeleteSubTitle">Delete this patient?</h5>
            <p id="patientDeleteDesc">You are about to move <strong id="patientDeleteName">this patient</strong> to <strong>Archived Patients</strong>. You can restore or permanently delete this record anytime from the Archived Patients bin.</p>
        </div>
        <div class="admin-logout-foot">
            <button type="button" class="btn btn-light admin-delete-btn-cancel" id="patientDeleteCancel">Cancel</button>
            <button type="button" class="btn admin-delete-btn-danger" id="patientDeleteYes"><i class="bi bi-trash me-1"></i>Yes, Delete</button>
        </div>
    </div>
</div>
<style>
.admin-logout-overlay { position: fixed; inset: 0; background: rgba(10,59,52,.55); z-index: 2000; padding: 20px; align-items: center; justify-content: center; }
.admin-logout-box { background: #ffffff; border-radius: 18px; border: 1px solid #C9E9DA; box-shadow: 0 18px 45px rgba(0,0,0,.25); max-width: 520px; width: 100%; overflow: hidden; font-family: "Inter", "DM Sans", system-ui, sans-serif; animation: adminLogoutPop .18s ease; }
@keyframes adminLogoutPop { from { transform: scale(.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.admin-logout-head { display: flex; align-items: center; gap: 12px; padding: 18px 22px; background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%); border-bottom: 1px solid #C9E9DA; }
.admin-logout-head-icon { width: 38px; height: 38px; border-radius: 11px; background: linear-gradient(135deg, #0E6B5E, #0A4A41); color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 4px 10px rgba(14,107,94,.25); flex-shrink: 0; }
.admin-logout-head h5 { color: #143f51; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; font-size: 17px; margin: 0; flex: 1; }
.admin-logout-x { background: #ffffff; border: 1px solid #D1E7DD; color: #57727d; width: 30px; height: 30px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; flex-shrink: 0; }
.admin-logout-x:hover { background: #EAF7F0; color: #0E6B5E; }
.admin-logout-body { padding: 34px 36px 8px; text-align: center; }
.admin-logout-icon { width: 72px; height: 72px; border-radius: 20px; background: #FDECEC; color: #C0392B; border: 1px solid #F5C6C6; display: inline-flex; align-items: center; justify-content: center; font-size: 34px; margin-bottom: 16px; }
.admin-logout-body h5 { color: #143f51; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; font-size: 18px; margin: 0 0 8px; }
.admin-logout-body p { color: #44585F; font-size: 14px; line-height: 1.65; font-weight: 500; margin: 0; }
.admin-logout-body p strong { color: #8E2018; font-weight: 800; }
.admin-logout-foot { display: flex; gap: 12px; justify-content: center; padding: 18px 22px 22px; background: #F6FBF8; border-top: 1px solid #E6F2EC; margin-top: 20px; }
.admin-logout-foot .btn { border-radius: 12px; font-weight: 800; font-size: 14.5px; padding: 12px 30px; letter-spacing: -.01em; transition: transform .15s ease, box-shadow .15s ease, filter .15s ease; }
.admin-logout-foot .btn:active { transform: translateY(1px); }

.admin-delete-btn-cancel { background: #ffffff !important; border: 1.5px solid #CFE3DA !important; color: #4A5F68 !important; box-shadow: 0 3px 8px rgba(10,74,65,.06); }
.admin-delete-btn-cancel:hover { background: linear-gradient(135deg, #EAF7F0 0%, #D9F2E6 100%) !important; border-color: #A8D5C4 !important; color: #0A4A41 !important; transform: translateY(-1px); box-shadow: 0 8px 18px rgba(10,74,65,.14) !important; }

.admin-delete-btn-danger { background: linear-gradient(135deg, #E74C3C 0%, #922B21 100%) !important; border: none !important; color: #ffffff !important; box-shadow: 0 6px 16px rgba(192,57,43,.32) !important; }
.admin-delete-btn-danger:hover { filter: brightness(.94); color: #ffffff !important; transform: translateY(-1px); box-shadow: 0 10px 22px rgba(192,57,43,.38) !important; }
.admin-delete-btn-danger:hover i.bi-trash { transform: rotate(-8deg) scale(1.12); }
.admin-delete-btn-danger i.bi-trash { display: inline-block; transition: transform .15s ease; }

.admin-delete-btn-danger.is-busy { pointer-events: none; opacity: .75; filter: saturate(.7); }
.admin-delete-btn-danger .spinner-border { width: 14px; height: 14px; border-width: 2px; border-top-color: #ffffff; }
</style>
<script>
(function()
{
    var pendingForm = null;

    function closePatientDelete()
    {
        var overlay = document.getElementById("patientDeleteConfirm");
        if (!overlay) return;
        overlay.style.display = "none";
        document.body.style.overflow = "";
        pendingForm = null;
    }

    function initPatientManageModal()
    {
        var manageModalEl = document.getElementById("patientManageModal");
        if (!manageModalEl) return;
        var nameEl = document.getElementById("manageModalPatientName");
        var numEl = document.getElementById("manageModalPatientNumber");
        var viewBtn = document.getElementById("manageModalViewBtn");
        var editBtn = document.getElementById("manageModalEditBtn");
        var delForm = document.getElementById("manageModalDeleteForm");
        var delIdInput = document.getElementById("manageModalDeleteId");

        document.querySelectorAll(".patient-manage-btn").forEach(function(btn)
        {
            btn.addEventListener("click", function()
            {
                var pId = btn.dataset.patientId || "";
                var pNum = btn.dataset.patientNumber || pId;
                var pName = btn.dataset.patientName || "this patient";
                var viewUrl = btn.dataset.viewUrl || "#";
                var editUrl = btn.dataset.editUrl || "#";

                if (nameEl) nameEl.textContent = pName;
                if (numEl) numEl.textContent = pNum;
                if (viewBtn) viewBtn.href = viewUrl;
                if (editBtn) editBtn.href = editUrl;
                if (delForm) delForm.dataset.patientName = pName;
                if (delIdInput) delIdInput.value = pId;

                bootstrap.Modal.getOrCreateInstance(manageModalEl).show();
            });
        });
    }

    function initPatientDeleteConfirm()
    {
        var overlay = document.getElementById("patientDeleteConfirm");
        if (!overlay || overlay.dataset.bound === "1") return;
        overlay.dataset.bound = "1";

        var headTitleEl = document.getElementById("patientDeleteHeadTitle");
        var subTitleEl = document.getElementById("patientDeleteSubTitle");
        var descEl = document.getElementById("patientDeleteDesc");
        var manageModalEl = document.getElementById("patientManageModal");
        var archiveModalEl = document.getElementById("archivedPatientsModal");

        document.querySelectorAll("form.js-delete-patient-form").forEach(function(form)
        {
            form.addEventListener("submit", function(e)
            {
                e.preventDefault();
                if (manageModalEl) {
                    try { bootstrap.Modal.getInstance(manageModalEl)?.hide(); } catch(err) {}
                }
                if (archiveModalEl) {
                    try { bootstrap.Modal.getInstance(archiveModalEl)?.hide(); } catch(err) {}
                }
                pendingForm = form;
                var pName = form.dataset.patientName || "this patient";
                var mode = form.dataset.deleteMode || "archive";

                if (mode === "permanent") {
                    if (headTitleEl) headTitleEl.textContent = "Delete Patient Permanently";
                    if (subTitleEl) subTitleEl.textContent = "Permanently delete this patient?";
                    if (descEl) {
                        descEl.innerHTML = 'You are about to permanently delete <strong id="patientDeleteName"></strong> together with all of their appointments and medical records. This action cannot be undone.';
                        var nEl = document.getElementById("patientDeleteName");
                        if (nEl) nEl.textContent = pName;
                    }
                } else {
                    if (headTitleEl) headTitleEl.textContent = "Delete / Archive Patient";
                    if (subTitleEl) subTitleEl.textContent = "Move this patient to Archive?";
                    if (descEl) {
                        descEl.innerHTML = 'You are about to move <strong id="patientDeleteName"></strong> to <strong>Archived Patients</strong>. You can restore or permanently delete this record anytime from the Archived Patients bin.';
                        var nEl2 = document.getElementById("patientDeleteName");
                        if (nEl2) nEl2.textContent = pName;
                    }
                }

                overlay.style.display = "flex";
                document.body.style.overflow = "hidden";
                var yes = document.getElementById("patientDeleteYes");
                if (yes) {
                    yes.classList.remove("is-busy");
                    yes.innerHTML = mode === "permanent"
                        ? '<i class="bi bi-trash me-1"></i>Yes, Delete Permanently'
                        : '<i class="bi bi-trash me-1"></i>Yes, Delete';
                    yes.focus();
                }
            });
        });

        document.getElementById("patientDeleteCancel").addEventListener("click", closePatientDelete);
        document.getElementById("patientDeleteClose").addEventListener("click", closePatientDelete);
        overlay.addEventListener("click", function(e)
        {
            if (e.target === overlay) closePatientDelete();
        });
        document.addEventListener("keydown", function(e)
        {
            if (overlay.style.display === "none") return;
            if (e.key === "Escape") closePatientDelete();
        });
        document.getElementById("patientDeleteYes").addEventListener("click", function()
        {
            if (!pendingForm) { closePatientDelete(); return; }
            var form = pendingForm;
            var yesBtn = this;
            pendingForm = null;

            yesBtn.classList.add("is-busy");
            yesBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Deleting…';

            form.submit();
        });
    }

    function initAllPatientActions()
    {
        initPatientManageModal();
        initPatientDeleteConfirm();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initAllPatientActions);
    } else {
        initAllPatientActions();
    }
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Back to Dashboard: isara ang browser tab, fallback sa Dashboard kung blocked */
document.querySelectorAll('a.js-close-tab').forEach(function(a){
    a.addEventListener('click', function(e){
        e.preventDefault();
        window.__goingToDashboard = true;
        var modalEl = document.getElementById('patientViewModal');
        if(modalEl) { try { bootstrap.Modal.getInstance(modalEl)?.hide(); } catch(err) {} }
        try { window.open('', '_self'); } catch(err) {}
        window.close();
        setTimeout(function(){ window.location.href = 'dashboard.php'; }, 300);
    });
});
</script>
<?php if ($editPatient): ?><script>new bootstrap.Modal(document.getElementById('patientModal')).show();document.getElementById('patientModal').addEventListener('hidden.bs.modal', function(){ var p = new URLSearchParams(window.location.search); p.delete('edit'); var s = p.toString(); history.replaceState(null,'', window.location.pathname + (s ? '?' + s : '')); });</script><?php endif; ?>
<?php if ($selectedPatient): ?><script>new bootstrap.Modal(document.getElementById('patientViewModal')).show();document.getElementById('patientViewModal').addEventListener('hidden.bs.modal', function(){ if(!window.__goingToDashboard){ var p = new URLSearchParams(window.location.search); p.delete('view'); var s = p.toString(); history.replaceState(null,'', window.location.pathname + (s ? '?' + s : '')); } });document.querySelectorAll('#patientViewModal a[href="dashboard.php"]').forEach(function(a){ a.addEventListener('click', function(){ window.__goingToDashboard = true; }); });</script><?php endif; ?>
<script>
/* Admin patient topbar tulad sa dentist appointment - date at clinic time */
(function(){
    function addTopbarDateTime(){
        var adminArea = document.querySelector('.shared-topbar .admin-area');
        if(!adminArea || document.getElementById('adminClinicTime')) return;
        var now = new Date();
        var days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var dayName = days[now.getDay()];
        var dateStr = months[now.getMonth()] + ' ' + String(now.getDate()).padStart(2,'0') + ', ' + now.getFullYear();
        var wrap = document.createElement('div');
        wrap.style.display = 'flex';
        wrap.style.alignItems = 'center';
        wrap.style.gap = '16px';
        wrap.innerHTML = '<div class="admin-topbar-today"><i class="bi bi-calendar-event-fill"></i><div><strong>' + dayName + '</strong><small>' + dateStr + '</small></div></div>' +
            '<div class="admin-topbar-time"><i class="bi bi-clock-fill"></i><div><strong>Clinic Time</strong><small id="adminClinicTime">--:--:--</small></div></div>';
        adminArea.insertBefore(wrap, adminArea.firstChild);
        function tick(){
            try{
                var el = document.getElementById('adminClinicTime');
                if(!el) return;
                el.textContent = new Date().toLocaleTimeString('en-PH',{timeZone:'Asia/Manila',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});
            }catch(e){}
        }
        tick(); setInterval(tick,1000);
    }
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',addTopbarDateTime);}else{addTopbarDateTime();}
})();
</script>
<script>
/* Scroll slide: eksaktong 10 rows ang kita sa patient table */
(function () {
    var wrap = document.getElementById('adminPatientListWrap');
    if (!wrap) return;
    function fit() {
        var table = document.getElementById('patientListTable');
        if (!table) return;
        var head = table.querySelector('thead');
        var rows = table.querySelectorAll('tbody tr');
        if (!rows.length) return;
        var n = Math.min(10, rows.length);
        var total = head ? head.getBoundingClientRect().height : 0;
        for (var i = 0; i < n; i++) {
            total += rows[i].getBoundingClientRect().height;
        }
        if (total > 0) {
            wrap.style.maxHeight = Math.ceil(total + 2) + 'px';
        }
    }
    fit();
    window.addEventListener('load', fit);
    window.addEventListener('resize', fit);
})();
</script>
</body>
</html>