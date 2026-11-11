<?php
/**
 * DENTIST PORTAL — PATIENTS DIRECTORY & CLINICAL TREATMENT HISTORY
 * 
 * Provides licensed dentists with:
 * - Patient directory of individuals assigned to the dentist
 * - Search by patient name, patient number (PAT-...), phone, or email
 * - Status filtering (Pending, Confirmed, Completed, Cancelled)
 * - 10-per-page pagination with clean clinical layout
 * - Patient details & full dental appointment history view (?patient_id=X)
 * - Quick appointment view modal & edit patient info modal
 */

require_once __DIR__ . '/../config.php';

/*
|--------------------------------------------------------------------------
| DENTIST AUTHENTICATION
|--------------------------------------------------------------------------
*/
if (
    !isset($_SESSION['user_id']) ||
    strtolower(trim($_SESSION['role'] ?? '')) !== 'dentist'
) {
    header('Location: ../login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| FIND DENTIST ID
|--------------------------------------------------------------------------
*/
$loggedInUserId = (int) $_SESSION['user_id'];
$dentistName = trim($_SESSION['full_name'] ?? '');

$dentistId = function_exists('resolveDentistId')
    ? resolveDentistId($loggedInUserId, $dentistName)
    : 0;

if ($dentistId <= 0) {
    $dentistLookup = $pdo->prepare("
        SELECT id
        FROM dentists
        WHERE dentist_name = ?
           OR id = ?
        ORDER BY (dentist_name = ?) DESC
        LIMIT 1
    ");

    $dentistLookup->execute([
        $dentistName,
        $loggedInUserId,
        $dentistName
    ]);

    $dentistId = (int) ($dentistLookup->fetchColumn() ?: 0);
}

// Fetch dentist record for specialization info
$dentistRecord = null;
if ($dentistId > 0) {
    try {
        $stD = $pdo->prepare("SELECT * FROM dentists WHERE id = ? LIMIT 1");
        $stD->execute([$dentistId]);
        $dentistRecord = $stD->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {}
}
$dentistSpecialization = $dentistRecord['specialization'] ?? 'General Dentistry';

/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/
function dentistPatientEscape($value)
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function getPatientInitials($name) {
    $words = preg_split('/\s+/', trim((string)$name));
    $initials = '';
    foreach (array_slice($words, 0, 2) as $w) {
        $initials .= strtoupper(substr($w, 0, 1));
    }
    return $initials ?: 'PT';
}

function computeAge($dob) {
    if (empty($dob) || $dob === '0000-00-00') return '';
    try {
        $b = new DateTime($dob);
        $now = new DateTime();
        $diff = $now->diff($b);
        return $diff->y . ' yrs old';
    } catch (Exception $e) {
        return '';
    }
}

/*
|--------------------------------------------------------------------------
| GET SELECTED PATIENT
|--------------------------------------------------------------------------
*/
$selectedPatientId = isset($_GET['patient_id'])
    ? (int) $_GET['patient_id']
    : 0;

/*
|--------------------------------------------------------------------------
| SEARCH AND FILTER
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

/*
|--------------------------------------------------------------------------
| EDIT PATIENT (POST)
|--------------------------------------------------------------------------
*/
$editError = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'edit_patient'
) {
    requireCsrf();

    $editId = (int) ($_POST['patient_id'] ?? 0);
    $editFirstName = trim($_POST['first_name'] ?? '');
    $editMiddleName = trim($_POST['middle_name'] ?? '');
    $editLastName = trim($_POST['last_name'] ?? '');
    $editContact = trim($_POST['contact_number'] ?? '');
    $editEmail = trim($_POST['email'] ?? '');

    if ($editId <= 0) {
        $editError = 'Invalid patient. Please try again.';
    } elseif ($editFirstName === '' || $editLastName === '') {
        $editError = 'First name and last name are required.';
    } elseif ($editEmail !== '' && !filter_var($editEmail, FILTER_VALIDATE_EMAIL)) {
        $editError = 'Please enter a valid email address.';
    } else {
        /* Dentist lang pwedeng mag-edit ng sarili niyang patient */
        $ownStmt = $pdo->prepare("
            SELECT 1
            FROM appointments
            WHERE patient_id = ?
              AND dentist_id = ?
            LIMIT 1
        ");

        $ownStmt->execute([
            $editId,
            $dentistId
        ]);

        if (!$ownStmt->fetchColumn()) {
            $editError = 'You are not allowed to edit this patient.';
        } else {
            $updateStmt = $pdo->prepare("
                UPDATE patients
                SET first_name = ?,
                    middle_name = ?,
                    last_name = ?,
                    contact_number = ?,
                    email = ?
                WHERE id = ?
            ");

            $updateStmt->execute([
                $editFirstName,
                $editMiddleName,
                $editLastName,
                $editContact,
                $editEmail,
                $editId
            ]);

            $_SESSION['patient_edit_flash'] = 'Patient information has been updated successfully.';
            $redirectQuery = http_build_query($_GET);
            header('Location: patients.php' . ($redirectQuery !== '' ? '?' . $redirectQuery : ''));
            exit;
        }
    }
}

$editFlash = trim($_SESSION['patient_edit_flash'] ?? '');
unset($_SESSION['patient_edit_flash']);

/*
|==========================================================================
| 1. PATIENT DETAILS VIEW (?patient_id=X)
|==========================================================================
*/
if ($selectedPatientId > 0) {

    $patientStmt = $pdo->prepare("
        SELECT DISTINCT
            p.id,
            p.patient_number,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.gender,
            p.birth_date,
            p.contact_number,
            COALESCE(
                NULLIF(TRIM(p.email), ''),
                NULLIF(
                    TRIM(
                        CASE WHEN u.role = 'patient'
                            THEN u.username
                        END
                    ),
                    ''
                )
            ) AS email,
            p.address
        FROM patients p
        INNER JOIN appointments a
            ON a.patient_id = p.id
        LEFT JOIN users u
            ON u.id = p.user_id
        WHERE p.id = ?
          AND a.dentist_id = ?
        LIMIT 1
    ");

    $patientStmt->execute([
        $selectedPatientId,
        $dentistId
    ]);

    $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        header('Location: patients.php');
        exit;
    }

    // Assigned dentists
    $assignedStmt = $pdo->prepare("
        SELECT DISTINCT
            d.id,
            d.dentist_name,
            d.specialization
        FROM appointments a
        LEFT JOIN dentists d
            ON d.id = a.dentist_id
        WHERE a.patient_id = ?
        ORDER BY d.dentist_name ASC
    ");
    $assignedStmt->execute([$selectedPatientId]);
    $assignedDentists = $assignedStmt->fetchAll(PDO::FETCH_ASSOC);

    $assignedDentistLabel = '';
    foreach ($assignedDentists as $assignedDentist) {
        $assignedName = trim((string) ($assignedDentist['dentist_name'] ?? ''));
        if ($assignedName === '') continue;
        if ((int) ($assignedDentist['id'] ?? 0) === $dentistId) {
            $assignedName .= ' (You)';
        }
        $assignedDentistLabel .= ($assignedDentistLabel === '') ? $assignedName : ', ' . $assignedName;
    }

    // Appointment history
    $historyStmt = $pdo->prepare("
        SELECT
            a.id,
            a.appointment_date,
            a.start_time,
            a.end_time,
            a.service_type,
            a.status,
            a.notes,
            a.created_at,
            d.dentist_name
        FROM appointments a
        LEFT JOIN dentists d
            ON d.id = a.dentist_id
        WHERE a.patient_id = ?
          AND a.dentist_id = ?
        ORDER BY a.appointment_date DESC, a.start_time DESC
    ");
    $historyStmt->execute([$selectedPatientId, $dentistId]);
    $appointmentHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

    // Next upcoming appointment
    $nextStmt = $pdo->prepare("
        SELECT
            id,
            appointment_date,
            start_time,
            end_time,
            service_type,
            status,
            notes
        FROM appointments
        WHERE patient_id = ?
          AND dentist_id = ?
          AND appointment_date >= CURDATE()
          AND status NOT IN ('Cancelled', 'Completed')
        ORDER BY appointment_date ASC, start_time ASC
        LIMIT 1
    ");
    $nextStmt->execute([$selectedPatientId, $dentistId]);
    $nextAppointment = $nextStmt->fetch(PDO::FETCH_ASSOC);

    $patientFullName = trim(
        $patient['first_name'] . ' ' .
        ($patient['middle_name'] ?? '') . ' ' .
        $patient['last_name']
    );
    $patientInitials = getPatientInitials($patientFullName);
    $patientAge = computeAge($patient['birth_date'] ?? '');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Patient Dental Record | <?= dentistPatientEscape($patientFullName) ?> | Dentist Portal</title>

        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap">
        <link rel="stylesheet" href="portal.css?v=5">
        <link rel="stylesheet" href="dentist_sidebar.css">

        <style>
            :root {
                --clinic-teal: #0E6B5E;
                --clinic-teal-dark: #0A4A41;
                --clinic-teal-deep: #0A3B34;
                --clinic-mint: #059669;
                --clinic-emerald: #10B981;
                --clinic-gold: #C9A227;
                --clinic-card-border: #E1EBE6;
            }

            body {
                font-family: "DM Sans", "Inter", system-ui, sans-serif;
                background: linear-gradient(135deg, #022c22, #059669, #065f46, #10b981, #022c22) !important;
                background-size: 300% 300% !important;
                animation: dentistDashBg 8s ease infinite !important;
                color: #143F51;
            }

            @keyframes dentistDashBg {
                0% { background-position: 0% 50%; }
                50% { background-position: 100% 50%; }
                100% { background-position: 0% 50%; }
            }

            .portal-main { background: transparent !important; }

            .clinic-minibar {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                background: var(--clinic-teal-deep);
                color: var(--clinic-gold);
                font-size: 11px;
                font-weight: 700;
                letter-spacing: .6px;
                padding: 6px 12px;
            }
            .clinic-minibar span { color: #ffffff; }

            .portal-topbar {
                background: #ffffff !important;
                border-bottom: 1px solid #E2ECE7 !important;
                height: auto !important;
                min-height: 90px !important;
                padding: 14px 28px !important;
                gap: 16px !important;
                flex-wrap: wrap !important;
                align-items: center !important;
            }

            .topbar-today, .topbar-time {
                display: flex;
                align-items: center;
                gap: 10px;
                color: #244f60;
                border-left: 1px solid #e8eef2;
                padding-left: 16px;
            }
            .topbar-today i, .topbar-time i { font-size: 22px; color: var(--clinic-teal); }
            .topbar-today strong, .topbar-time strong { display: block; font-size: 13px; font-weight: 800; line-height: 1.2; }
            .topbar-today small, .topbar-time small { display: block; font-size: 11px; opacity: .85; font-weight: 600; }

            /* PATIENT HERO HEADER */
            .patient-detail-hero {
                position: relative;
                overflow: hidden;
                background: linear-gradient(135deg, #0E6B5E 0%, #059669 55%, #0A4A41 100%);
                border: 1px solid rgba(255, 255, 255, 0.28);
                border-radius: 20px;
                padding: 24px 28px;
                color: #ffffff;
                width: 100%;
                margin-bottom: 22px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 20px;
                box-shadow: 0 16px 36px rgba(6, 59, 49, 0.22);
                flex-wrap: wrap;
            }

            .patient-detail-hero::before {
                content: "";
                position: absolute;
                width: 240px;
                height: 240px;
                top: -110px;
                right: -50px;
                border-radius: 50%;
                background: radial-gradient(circle, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0) 70%);
                pointer-events: none;
            }

            .patient-hero-left {
                display: flex;
                align-items: center;
                gap: 18px;
                position: relative;
                z-index: 2;
            }

            .patient-avatar-circle {
                width: 76px;
                height: 76px;
                border-radius: 50%;
                background: linear-gradient(135deg, #ecfdf5, #d1fae5);
                color: var(--clinic-teal);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 26px;
                font-weight: 800;
                border: 3px solid rgba(255, 255, 255, 0.85);
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.18);
                flex-shrink: 0;
            }

            .patient-hero-info h1 {
                font-family: "Plus Jakarta Sans", sans-serif;
                font-size: 22px;
                font-weight: 800;
                margin: 0 0 4px 0;
                color: #ffffff;
            }

            .patient-hero-meta {
                display: flex;
                align-items: center;
                gap: 8px;
                flex-wrap: wrap;
                margin-top: 6px;
            }

            .hero-meta-pill {
                font-size: 11.5px;
                font-weight: 700;
                padding: 4px 10px;
                border-radius: 8px;
                background: rgba(255, 255, 255, 0.18);
                border: 1px solid rgba(255, 255, 255, 0.28);
                color: #ffffff;
                display: inline-flex;
                align-items: center;
                gap: 5px;
            }

            .hero-meta-gold {
                background: rgba(201, 162, 39, 0.3);
                border-color: rgba(201, 162, 39, 0.6);
                color: #FEF3D6;
            }

            .btn-back-patients {
                background: #ffffff;
                color: var(--clinic-teal-dark);
                border: none;
                border-radius: 11px;
                font-weight: 700;
                font-size: 13px;
                padding: 9px 18px;
                display: inline-flex;
                align-items: center;
                gap: 7px;
                text-decoration: none;
                box-shadow: 0 6px 14px rgba(0, 0, 0, 0.15);
                transition: all .2s ease;
                position: relative;
                z-index: 2;
            }
            .btn-back-patients:hover {
                background: #F0FDF4;
                color: #047857;
                transform: translateY(-1px);
            }

            /* 2-COLUMN CLINICAL GRID */
            .clinical-details-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 22px;
                margin-bottom: 22px;
            }
            @media (max-width: 991px) {
                .clinical-details-grid { grid-template-columns: 1fr; }
            }

            .clinical-panel {
                background: #ffffff;
                border: 1px solid var(--clinic-card-border);
                border-radius: 18px;
                box-shadow: 0 10px 28px rgba(6, 59, 49, 0.07);
                overflow: hidden;
            }

            .clinical-panel-head {
                padding: 16px 22px;
                background: linear-gradient(135deg, #F7FAF9 0%, #EDF5F2 100%);
                border-bottom: 1px solid #E1EBE6;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
            }

            .clinical-panel-head h2 {
                font-family: "Plus Jakarta Sans", sans-serif;
                font-size: 15.5px;
                font-weight: 800;
                color: #143F51;
                margin: 0;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .clinical-panel-body { padding: 22px; }

            .demographics-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
            @media (max-width: 575px) {
                .demographics-grid { grid-template-columns: 1fr; }
            }

            .demo-card {
                background: #F8FBFA;
                border: 1px solid #E2EFE9;
                border-radius: 10px;
                padding: 11px 14px;
            }
            .demo-card.full { grid-column: 1 / -1; }
            .demo-label {
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .5px;
                color: #64748B;
                margin-bottom: 4px;
            }
            .demo-val {
                font-size: 13.5px;
                font-weight: 700;
                color: #143F51;
                word-break: break-word;
            }

            /* UPCOMING VISIT HIGHLIGHT CARD */
            .upcoming-visit-card {
                background: linear-gradient(135deg, #F0FDF4 0%, #DCFCE7 100%);
                border: 1px solid #BBF7D0;
                border-radius: 14px;
                padding: 18px;
            }
            .upcoming-time-box {
                font-family: "Plus Jakarta Sans", monospace;
                font-size: 16px;
                font-weight: 800;
                color: #166534;
                margin: 4px 0 10px 0;
            }
            .procedure-badge {
                display: inline-block;
                background: #ffffff;
                color: #15803D;
                border: 1px solid #86EFAC;
                padding: 4px 10px;
                border-radius: 8px;
                font-size: 12.5px;
                font-weight: 700;
                margin-bottom: 10px;
            }

            /* STATUS BADGES */
            .status-pill {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 999px;
                font-size: 11.5px;
                font-weight: 700;
                letter-spacing: .3px;
            }
            .status-pill.pending { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; }
            .status-pill.confirmed { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
            .status-pill.completed { background: #E0F2FE; color: #0369A1; border: 1px solid #BAE6FD; }
            .status-pill.cancelled { background: #FEE2E2; color: #B91C1C; border: 1px solid #FECACA; }
            .status-pill.default { background: #F1F5F9; color: #475569; border: 1px solid #E2E8F0; }

            /* HISTORY TABLE */
            .history-table-card {
                background: #ffffff;
                border: 1px solid var(--clinic-card-border);
                border-radius: 18px;
                box-shadow: 0 10px 28px rgba(6, 59, 49, 0.07);
                overflow: hidden;
            }
            .history-table {
                width: 100%;
                border-collapse: collapse;
                margin: 0;
            }
            .history-table th {
                background: #F7FAF9;
                color: #244F60;
                font-size: 12px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: .5px;
                padding: 13px 18px;
                border-bottom: 1px solid #E1EBE6;
                white-space: nowrap;
            }
            .history-table td {
                padding: 13px 18px;
                font-size: 13px;
                color: #244F60;
                border-bottom: 1px solid #EEF4F1;
                vertical-align: middle;
            }
            .history-table tbody tr:hover td {
                background: #F8FBFA;
            }
        </style>
    </head>
    <body>

    <?php
    $dentistActivePage = 'patients';
    include __DIR__ . '/dentist_sidebar.php';
    ?>

    <main class="portal-main">
        <div class="clinic-minibar">
            🦷 <span>Abalos Dental Clinic — Practitioner Clinical Chart</span>
        </div>

        <header class="portal-topbar">
            <div>
                <div class="portal-heading" style="font-family:'Plus Jakarta Sans',sans-serif; font-size:22px; font-weight:800; color:#143F51;">
                    Patient Clinical Chart & History
                </div>
                <div class="portal-subtitle" style="font-size:13px; color:#64748B;">
                    Comprehensive dental appointment records for <?= dentistPatientEscape($patientFullName) ?>
                </div>
            </div>

            <div style="display:flex; align-items:center; gap:16px;">
                <div class="topbar-today" style="border-left:none; padding-left:0;">
                    <i class="bi bi-calendar2-check-fill"></i>
                    <div>
                        <strong><?= htmlspecialchars(date('l')) ?></strong>
                        <small><?= htmlspecialchars(date('M d, Y')) ?></small>
                    </div>
                </div>

                <div class="topbar-time">
                    <i class="bi bi-clock-fill"></i>
                    <div>
                        <strong>Clinic Time</strong>
                        <small id="patientDetailClinicTime">--:--:--</small>
                    </div>
                </div>

                <div class="portal-user">
                    <?= htmlspecialchars($dentistName) ?>
                    <div class="portal-role">Attending Dentist</div>
                </div>
            </div>
        </header>

        <script>
        (function(){
            function tick(){
                var el = document.getElementById('patientDetailClinicTime');
                if(!el) return;
                var now = new Date();
                el.textContent = now.toLocaleTimeString('en-PH', {
                    timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
                });
            }
            tick(); setInterval(tick, 1000);
        })();
        </script>

        <div class="portal-content" style="padding: 26px 32px 40px 32px;">

            <!-- HERO HEADER -->
            <div class="patient-detail-hero">
                <div class="patient-hero-left">
                    <div class="patient-avatar-circle">
                        <?= htmlspecialchars($patientInitials) ?>
                    </div>
                    <div class="patient-hero-info">
                        <h1><?= dentistPatientEscape($patientFullName) ?></h1>
                        <div style="font-size:13px; opacity:.92;">
                            <i class="bi bi-person-vcard me-1"></i> Patient No: <strong><?= dentistPatientEscape($patient['patient_number']) ?></strong>
                        </div>
                        <div class="patient-hero-meta">
                            <span class="hero-meta-pill hero-meta-gold">
                                <i class="bi bi-gender-ambiguous"></i> <?= dentistPatientEscape($patient['gender'] ?: 'Unspecified') ?>
                            </span>
                            <?php if ($patientAge): ?>
                                <span class="hero-meta-pill">
                                    <i class="bi bi-cake2"></i> <?= htmlspecialchars($patientAge) ?>
                                </span>
                            <?php endif; ?>
                            <span class="hero-meta-pill">
                                <i class="bi bi-journal-medical"></i> <?= count($appointmentHistory) ?> Total Consultation<?= count($appointmentHistory) == 1 ? '' : 's' ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div>
                    <a href="patients.php" class="btn-back-patients">
                        <i class="bi bi-arrow-left"></i> Back to Patient Directory
                    </a>
                </div>
            </div>

            <!-- 2-COLUMN CLINICAL GRID -->
            <div class="clinical-details-grid">

                <!-- DEMOGRAPHICS & CONTACT -->
                <div class="clinical-panel">
                    <div class="clinical-panel-head">
                        <h2><i class="bi bi-person-lines-fill text-success"></i> Patient Clinical Demographics</h2>
                        <span class="badge bg-light text-secondary border">Verified Chart</span>
                    </div>
                    <div class="clinical-panel-body">
                        <div class="demographics-grid">
                            <div class="demo-card">
                                <div class="demo-label">Patient Record ID</div>
                                <div class="demo-val"><?= dentistPatientEscape($patient['patient_number']) ?></div>
                            </div>
                            <div class="demo-card">
                                <div class="demo-label">Attending Dentist</div>
                                <div class="demo-val"><?= dentistPatientEscape($assignedDentistLabel ?: 'Dr. ' . $dentistName) ?></div>
                            </div>
                            <div class="demo-card">
                                <div class="demo-label">Direct Phone / Mobile</div>
                                <div class="demo-val">
                                    <?php if (!empty($patient['contact_number'])): ?>
                                        <a href="tel:<?= dentistPatientEscape($patient['contact_number']) ?>" style="color:var(--clinic-teal); text-decoration:none;">
                                            <i class="bi bi-telephone-fill me-1"></i><?= dentistPatientEscape($patient['contact_number']) ?>
                                        </a>
                                    <?php else: ?>—<?php endif; ?>
                                </div>
                            </div>
                            <div class="demo-card">
                                <div class="demo-label">Email Address</div>
                                <div class="demo-val">
                                    <?php if (!empty($patient['email'])): ?>
                                        <a href="mailto:<?= dentistPatientEscape($patient['email']) ?>" style="color:var(--clinic-teal); text-decoration:none;">
                                            <i class="bi bi-envelope-fill me-1"></i><?= dentistPatientEscape($patient['email']) ?>
                                        </a>
                                    <?php else: ?>—<?php endif; ?>
                                </div>
                            </div>
                            <div class="demo-card">
                                <div class="demo-label">Birth Date</div>
                                <div class="demo-val">
                                    <?= !empty($patient['birth_date']) ? date('F d, Y', strtotime($patient['birth_date'])) : '—' ?>
                                </div>
                            </div>
                            <div class="demo-card">
                                <div class="demo-label">Gender</div>
                                <div class="demo-val"><?= dentistPatientEscape($patient['gender'] ?: '—') ?></div>
                            </div>
                            <div class="demo-card full">
                                <div class="demo-label">Registered Residential Address</div>
                                <div class="demo-val"><?= dentistPatientEscape($patient['address'] ?: '—') ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- NEXT SCHEDULED APPOINTMENT -->
                <div class="clinical-panel">
                    <div class="clinical-panel-head">
                        <h2><i class="bi bi-calendar-event text-success"></i> Next Scheduled Clinical Visit</h2>
                        <?php if ($nextAppointment): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Upcoming</span>
                        <?php else: ?>
                            <span class="badge bg-light text-muted border">None</span>
                        <?php endif; ?>
                    </div>
                    <div class="clinical-panel-body">
                        <?php if ($nextAppointment): ?>
                            <?php
                            $nStat = strtolower(trim($nextAppointment['status']));
                            $nClass = in_array($nStat, ['pending','confirmed','completed','cancelled']) ? $nStat : 'default';
                            ?>
                            <div class="upcoming-visit-card">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span style="font-size:12px; font-weight:700; color:#15803D; text-transform:uppercase; letter-spacing:.5px;">
                                        <i class="bi bi-calendar-check me-1"></i> <?= date('F d, Y (l)', strtotime($nextAppointment['appointment_date'])) ?>
                                    </span>
                                    <span class="status-pill <?= $nClass ?>">
                                        <?= dentistPatientEscape($nextAppointment['status']) ?>
                                    </span>
                                </div>

                                <div class="upcoming-time-box">
                                    <?= date('h:i A', strtotime($nextAppointment['start_time'])) ?> – <?= date('h:i A', strtotime($nextAppointment['end_time'])) ?>
                                </div>

                                <div>
                                    <span class="procedure-badge">
                                        <i class="bi bi-bandaid me-1"></i> <?= dentistPatientEscape($nextAppointment['service_type'] ?: 'Dental Consultation') ?>
                                    </span>
                                </div>

                                <?php if (!empty($nextAppointment['notes'])): ?>
                                    <div class="p-2 mt-2 rounded bg-white border" style="font-size:12px; color:#4A6068;">
                                        <strong>Patient Notes:</strong> <?= dentistPatientEscape($nextAppointment['notes']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="p-4 text-center" style="background:#FAFDFB; border:1px dashed #CFDFD7; border-radius:12px;">
                                <i class="bi bi-calendar-x text-muted fs-2 d-block mb-2"></i>
                                <strong style="font-size:13.5px; color:#143F51;">No Upcoming Appointments Scheduled</strong>
                                <p class="text-muted small m-0">This patient does not have any pending or confirmed bookings on your chairside roster.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- APPOINTMENT & TREATMENT HISTORY TABLE -->
            <div class="history-table-card">
                <div class="clinical-panel-head">
                    <h2><i class="bi bi-clock-history text-success"></i> Dental Treatment & Consultation History</h2>
                    <span class="badge rounded-pill bg-light text-dark border">
                        <?= count($appointmentHistory) ?> Record<?= count($appointmentHistory) == 1 ? '' : 's' ?>
                    </span>
                </div>

                <?php if (empty($appointmentHistory)): ?>
                    <div class="p-5 text-center text-muted">
                        <i class="bi bi-journal-x fs-1 d-block mb-2"></i>
                        <strong>No prior consultation history found for this patient.</strong>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Shift Time</th>
                                    <th>Dental Procedure / Service</th>
                                    <th>Attending Dentist</th>
                                    <th>Status</th>
                                    <th>Chairside Notes / Concerns</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($appointmentHistory as $h): ?>
                                    <?php
                                    $hStat = strtolower(trim($h['status']));
                                    $hClass = in_array($hStat, ['pending','confirmed','completed','cancelled']) ? $hStat : 'default';
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= date('M d, Y', strtotime($h['appointment_date'])) ?></strong>
                                            <div style="font-size:11px; color:#64748B;"><?= date('l', strtotime($h['appointment_date'])) ?></div>
                                        </td>
                                        <td>
                                            <span style="font-family:'Plus Jakarta Sans', monospace; font-size:12.5px; font-weight:700;">
                                                <?= date('h:i A', strtotime($h['start_time'])) ?> – <?= date('h:i A', strtotime($h['end_time'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border" style="font-size:12px; font-weight:700;">
                                                <i class="bi bi-check2 me-1 text-success"></i><?= dentistPatientEscape($h['service_type'] ?: 'General Consultation') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="font-weight:600; font-size:12.5px;">
                                                <?= dentistPatientEscape($h['dentist_name'] ?: 'Dr. ' . $dentistName) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-pill <?= $hClass ?>">
                                                <?= dentistPatientEscape($h['status']) ?>
                                            </span>
                                        </td>
                                        <td style="max-width: 280px; font-size:12px; color:#4A6068;">
                                            <?= dentistPatientEscape($h['notes'] ?: '—') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </main>

    <!-- LOGOUT MODAL -->
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
    .dentist-logout-overlay { position: fixed; inset: 0; background: rgba(10,59,52,.55); z-index: 3000; padding: 20px; align-items: center; justify-content: center; }
    .dentist-logout-box { background: #ffffff; border-radius: 18px; border: 1px solid #C9E9DA; box-shadow: 0 18px 45px rgba(0,0,0,.25); max-width: 440px; width: 100%; overflow: hidden; font-family: "DM Sans", system-ui, sans-serif; }
    .dentist-logout-head { display: flex; align-items: center; gap: 12px; padding: 18px 22px; background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%); border-bottom: 1px solid #C9E9DA; }
    .dentist-logout-head-icon { width: 38px; height: 38px; border-radius: 11px; background: linear-gradient(135deg, #0E6B5E, #0A4A41); color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 4px 10px rgba(14,107,94,.25); flex-shrink: 0; }
    .dentist-logout-head h5 { color: #143f51; font-family: "Plus Jakarta Sans", system-ui, sans-serif; font-weight: 800; font-size: 17px; margin: 0; flex: 1; }
    .dentist-logout-x { background: #ffffff; border: 1px solid #D1E7DD; color: #57727d; width: 30px; height: 30px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; }
    .dentist-logout-body { padding: 30px 28px 8px; text-align: center; }
    .dentist-logout-icon { width: 64px; height: 64px; border-radius: 18px; background: #FDECEC; color: #C0392B; border: 1px solid #F5C6C6; display: inline-flex; align-items: center; justify-content: center; font-size: 30px; margin-bottom: 14px; }
    .dentist-logout-body h5 { color: #143f51; font-family: "Plus Jakarta Sans", system-ui, sans-serif; font-weight: 800; font-size: 18px; margin: 0 0 8px; }
    .dentist-logout-body p { color: #8aa0a8; font-size: 13.5px; margin: 0; }
    .dentist-logout-foot { display: flex; gap: 12px; justify-content: center; padding: 18px 22px 22px; background: #F6FBF8; border-top: 1px solid #E6F2EC; margin-top: 20px; }
    .dentist-logout-foot .btn { border-radius: 10px; font-weight: 700; font-size: 14px; padding: 10px 26px; }
    .dentist-logout-foot .btn-light { background: #fff !important; border: 1px solid #D1E7DD !important; color: #57727d !important; }
    .dentist-logout-yes { background: linear-gradient(135deg, #E74C3C, #922B21) !important; border: none !important; color: #ffffff !important; box-shadow: 0 6px 14px rgba(192,57,43,.3) !important; }
    </style>

    <script>
    (function(){
        function openDentistLogout(e){ if(e) e.preventDefault(); var el=document.getElementById("dentistLogoutConfirm"); if(el) el.style.display="flex"; }
        function closeDentistLogout(){ var el=document.getElementById("dentistLogoutConfirm"); if(el) el.style.display="none"; }
        document.querySelectorAll('a[href="../logout.php"], a[href="logout.php"]').forEach(function(a){ a.addEventListener("click", openDentistLogout); });
        document.getElementById("dentistLogoutCancel")?.addEventListener("click", closeDentistLogout);
        document.getElementById("dentistLogoutClose")?.addEventListener("click", closeDentistLogout);
    })();
    </script>
    </body>
    </html>
    <?php
    exit;
}

/*
|==========================================================================
| 2. PATIENT DIRECTORY LIST VIEW (DEFAULT)
|==========================================================================
*/
$sql = "
    SELECT
        p.id,
        p.patient_number,
        p.first_name,
        p.middle_name,
        p.last_name,
        p.contact_number,

        COALESCE(
            NULLIF(TRIM(p.email), ''),
            NULLIF(
                TRIM(
                    CASE WHEN u.role = 'patient'
                        THEN u.username
                    END
                ),
                ''
            )
        ) AS email,

        (
            SELECT GROUP_CONCAT(
                DISTINCT dd.dentist_name
                ORDER BY dd.dentist_name
                SEPARATOR ', '
            )
            FROM appointments ad
            LEFT JOIN dentists dd
                ON dd.id = ad.dentist_id
            WHERE ad.patient_id = p.id
        ) AS assigned_dentists,

        (
            SELECT a2.appointment_date
            FROM appointments a2
            WHERE a2.patient_id = p.id
              AND a2.dentist_id = ?
              AND a2.appointment_date >= CURDATE()
              AND a2.status NOT IN ('Cancelled', 'Completed')
            ORDER BY a2.appointment_date ASC, a2.start_time ASC
            LIMIT 1
        ) AS next_appointment_date,

        (
            SELECT a3.start_time
            FROM appointments a3
            WHERE a3.patient_id = p.id
              AND a3.dentist_id = ?
              AND a3.appointment_date >= CURDATE()
              AND a3.status NOT IN ('Cancelled', 'Completed')
            ORDER BY a3.appointment_date ASC, a3.start_time ASC
            LIMIT 1
        ) AS next_appointment_time,

        (
            SELECT a4.status
            FROM appointments a4
            WHERE a4.patient_id = p.id
              AND a4.dentist_id = ?
              AND a4.appointment_date >= CURDATE()
              AND a4.status NOT IN ('Cancelled', 'Completed')
            ORDER BY a4.appointment_date ASC, a4.start_time ASC
            LIMIT 1
        ) AS next_status,

        (
            SELECT a6.service_type
            FROM appointments a6
            WHERE a6.patient_id = p.id
              AND a6.dentist_id = ?
              AND a6.appointment_date >= CURDATE()
              AND a6.status NOT IN ('Cancelled', 'Completed')
            ORDER BY a6.appointment_date ASC, a6.start_time ASC
            LIMIT 1
        ) AS next_service,

        (
            SELECT a7.end_time
            FROM appointments a7
            WHERE a7.patient_id = p.id
              AND a7.dentist_id = ?
              AND a7.appointment_date >= CURDATE()
              AND a7.status NOT IN ('Cancelled', 'Completed')
            ORDER BY a7.appointment_date ASC, a7.start_time ASC
            LIMIT 1
        ) AS next_end_time,

        (
            SELECT a8.created_at
            FROM appointments a8
            WHERE a8.patient_id = p.id
              AND a8.dentist_id = ?
              AND a8.appointment_date >= CURDATE()
              AND a8.status NOT IN ('Cancelled', 'Completed')
            ORDER BY a8.appointment_date ASC, a8.start_time ASC
            LIMIT 1
        ) AS next_created,

        (
            SELECT COUNT(*)
            FROM appointments a5
            WHERE a5.patient_id = p.id
              AND a5.dentist_id = ?
        ) AS appointment_count

    FROM patients p

    LEFT JOIN users u
        ON u.id = p.user_id

    WHERE EXISTS (
        SELECT 1
        FROM appointments ax
        WHERE ax.patient_id = p.id
          AND ax.dentist_id = ?
    )
";

$params = [
    $dentistId,
    $dentistId,
    $dentistId,
    $dentistId,
    $dentistId,
    $dentistId,
    $dentistId,
    $dentistId
];

$filterSql = '';
$filterParams = [];

$filterSqlBase = "
    FROM patients p

    LEFT JOIN users u
        ON u.id = p.user_id

    WHERE EXISTS (
        SELECT 1
        FROM appointments ax
        WHERE ax.patient_id = p.id
          AND ax.dentist_id = ?
    )
";
$countParams = [$dentistId];

if ($search !== '') {
    $filterSql .= "
        AND (
            p.first_name LIKE ?
            OR p.middle_name LIKE ?
            OR p.last_name LIKE ?
            OR p.patient_number LIKE ?
            OR p.contact_number LIKE ?
            OR p.email LIKE ?
            OR u.username LIKE ?
        )
    ";
    $searchValue = '%' . $search . '%';
    for ($i = 0; $i < 7; $i++) {
        $filterParams[] = $searchValue;
    }
}

if ($statusFilter !== '') {
    $filterSql .= "
        AND EXISTS (
            SELECT 1
            FROM appointments af
            WHERE af.patient_id = p.id
              AND af.dentist_id = ?
              AND af.status = ?
        )
    ";
    $filterParams[] = $dentistId;
    $filterParams[] = $statusFilter;
}

$sql .= $filterSql;
$params = array_merge($params, $filterParams);
$sql .= " ORDER BY p.last_name ASC, p.first_name ASC ";

$countSql = "
    SELECT COUNT(*)
    $filterSqlBase
    $filterSql
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute(array_merge($countParams, $filterParams));
$patientsTotal = (int) $countStmt->fetchColumn();

// Additional stats for the hero banner
$statUpcomingCount = 0;
$statCompletedCount = 0;
try {
    $stUp = $pdo->prepare("
        SELECT COUNT(DISTINCT patient_id) 
        FROM appointments 
        WHERE dentist_id = ? AND appointment_date >= CURDATE() 
        AND status NOT IN ('Cancelled', 'Rejected', 'Completed')
    ");
    $stUp->execute([$dentistId]);
    $statUpcomingCount = (int)$stUp->fetchColumn();

    $stComp = $pdo->prepare("
        SELECT COUNT(*) 
        FROM appointments 
        WHERE dentist_id = ? AND status = 'Completed'
    ");
    $stComp->execute([$dentistId]);
    $statCompletedCount = (int)$stComp->fetchColumn();
} catch (PDOException $e) {}

$perPage = 10;
$totalPages = max(1, (int) ceil($patientsTotal / $perPage));
$page = (int) ($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql .= " LIMIT " . (int) $perPage . " OFFSET " . (int) $offset . " ";

function patientPageUrl($targetPage)
{
    $query = $_GET;
    $query['page'] = max(1, (int) $targetPage);
    return 'patients.php?' . http_build_query($query);
}

$showingFrom = $patientsTotal > 0 ? $offset + 1 : 0;
$showingTo   = min($offset + $perPage, $patientsTotal);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Directory | Dentist Portal</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap">
    <link rel="stylesheet" href="portal.css?v=5">
    <link rel="stylesheet" href="dentist_sidebar.css">

    <style>
        :root {
            --clinic-teal: #0E6B5E;
            --clinic-teal-dark: #0A4A41;
            --clinic-teal-deep: #0A3B34;
            --clinic-mint: #059669;
            --clinic-emerald: #10B981;
            --clinic-gold: #C9A227;
            --clinic-gold-light: #F4E8C1;
            --clinic-card-border: #E1EBE6;
        }

        body {
            font-family: "DM Sans", "Inter", system-ui, sans-serif;
            background: linear-gradient(135deg, #022c22, #059669, #065f46, #10b981, #022c22) !important;
            background-size: 300% 300% !important;
            animation: dentistDashBg 8s ease infinite !important;
            color: #143F51;
            min-height: 100vh;
        }

        @keyframes dentistDashBg {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .portal-main { background: transparent !important; }

        .clinic-minibar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--clinic-teal-deep);
            color: var(--clinic-gold);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .6px;
            padding: 6px 12px;
        }
        .clinic-minibar span { color: #ffffff; }

        .portal-topbar {
            background: #ffffff !important;
            border-bottom: 1px solid #E2ECE7 !important;
            height: auto !important;
            min-height: 90px !important;
            padding: 14px 28px !important;
            gap: 16px !important;
            flex-wrap: wrap !important;
            align-items: center !important;
        }

        .topbar-today, .topbar-time {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #244f60;
            border-left: 1px solid #e8eef2;
            padding-left: 16px;
        }
        .topbar-today i, .topbar-time i { font-size: 22px; color: var(--clinic-teal); }
        .topbar-today strong, .topbar-time strong { display: block; font-size: 13px; font-weight: 800; line-height: 1.2; }
        .topbar-today small, .topbar-time small { display: block; font-size: 11px; opacity: .85; font-weight: 600; }

        /* HERO PATIENT DIRECTORY BANNER */
        .patient-hero-banner {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #0E6B5E 0%, #059669 55%, #0A4A41 100%);
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: 20px;
            padding: 24px 28px;
            color: #ffffff;
            width: 100%;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            box-shadow: 0 16px 36px rgba(6, 59, 49, 0.22);
            flex-wrap: wrap;
        }

        .patient-hero-banner::before {
            content: "";
            position: absolute;
            width: 250px;
            height: 250px;
            top: -120px;
            right: -60px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0) 70%);
            pointer-events: none;
        }

        .hero-banner-title h1 {
            font-family: "Plus Jakarta Sans", sans-serif;
            font-size: 22px;
            font-weight: 800;
            margin: 0 0 6px 0;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .hero-banner-title p {
            margin: 0;
            font-size: 13px;
            opacity: .92;
            max-width: 580px;
            line-height: 1.45;
        }

        .hero-stat-badges {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            position: relative;
            z-index: 2;
        }

        .hero-stat-box {
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: 12px;
            padding: 10px 16px;
            text-align: center;
            min-width: 110px;
        }

        .hero-stat-box strong {
            display: block;
            font-size: 20px;
            font-weight: 800;
            font-family: "Plus Jakarta Sans", monospace;
            color: #ffffff;
            line-height: 1.1;
        }

        .hero-stat-box span {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            opacity: .9;
            color: #E2F5ED;
        }

        /* TOOLBAR & SEARCH */
        .patient-toolbar-card {
            background: #ffffff;
            border: 1px solid var(--clinic-card-border);
            border-radius: 16px;
            padding: 16px 22px;
            margin-bottom: 22px;
            box-shadow: 0 6px 18px rgba(6, 59, 49, 0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .search-form-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            max-width: 650px;
            flex-wrap: wrap;
        }

        .search-input-group {
            position: relative;
            flex: 1;
            min-width: 220px;
        }

        .search-input-group i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #83979E;
            font-size: 14px;
        }

        .search-input-group input {
            width: 100%;
            padding: 9px 12px 9px 34px;
            border-radius: 10px;
            border: 1px solid #CFDFD7;
            font-size: 13.5px;
            color: #143F51;
            transition: all .2s ease;
        }
        .search-input-group input:focus {
            outline: none;
            border-color: var(--clinic-mint);
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.16);
        }

        .status-select-wrap select {
            padding: 9px 14px;
            border-radius: 10px;
            border: 1px solid #CFDFD7;
            font-size: 13.5px;
            color: #143F51;
            background: #ffffff;
            cursor: pointer;
        }
        .status-select-wrap select:focus {
            outline: none;
            border-color: var(--clinic-mint);
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.16);
        }

        .btn-search-clinic {
            background: linear-gradient(135deg, #0E6B5E, #059669);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13px;
            padding: 9px 18px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(14, 107, 94, 0.2);
            transition: all .2s ease;
        }
        .btn-search-clinic:hover {
            filter: brightness(1.08);
            transform: translateY(-1px);
        }

        .btn-clear-clinic {
            background: #ffffff;
            border: 1px solid #CFDFD7;
            color: #57727D;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13px;
            padding: 9px 14px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
        }
        .btn-clear-clinic:hover {
            background: #F4F7F6;
            color: #0E6B5E;
        }

        .patient-table-meta {
            font-size: 12.5px;
            font-weight: 700;
            color: #4A6068;
        }

        /* DIRECTORY TABLE CARD */
        .patient-table-card {
            background: #ffffff;
            border: 1px solid var(--clinic-card-border);
            border-radius: 18px;
            box-shadow: 0 10px 28px rgba(6, 59, 49, 0.07);
            overflow: hidden;
            width: 100%;
        }

        .patient-table-scroll {
            overflow-x: auto;
            max-height: 640px;
            scrollbar-width: thin;
            scrollbar-color: #10B981 #E6F7F0;
        }
        .patient-table-scroll::-webkit-scrollbar { width: 8px; height: 8px; }
        .patient-table-scroll::-webkit-scrollbar-track { background: #E6F7F0; }
        .patient-table-scroll::-webkit-scrollbar-thumb { background: #10B981; border-radius: 6px; }

        .patient-roster-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .patient-roster-table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: #F7FAF9;
            color: #143F51;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
            padding: 13px 18px;
            border-bottom: 2px solid #E1EBE6;
            white-space: nowrap;
        }

        .patient-roster-table tbody td {
            padding: 13px 18px;
            font-size: 13px;
            color: #244F60;
            border-bottom: 1px solid #EEF4F1;
            vertical-align: middle;
        }

        .patient-roster-table tbody tr:hover td {
            background: #F5FAF8 !important;
        }

        /* PATIENT CELL */
        .patient-cell-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .patient-avatar-sm {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ECFDF5, #D1FAE5);
            color: var(--clinic-teal);
            font-weight: 800;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #A7F3D0;
            flex-shrink: 0;
        }

        .patient-name-link {
            font-weight: 800;
            font-size: 13.5px;
            color: #143F51;
            text-decoration: none;
            display: block;
            transition: color .15s ease;
        }
        .patient-name-link:hover { color: var(--clinic-mint); }

        .patient-id-badge {
            font-size: 10.5px;
            font-weight: 700;
            color: #64748B;
            background: #F1F5F9;
            padding: 1px 7px;
            border-radius: 6px;
            display: inline-block;
            margin-top: 2px;
            font-family: monospace;
        }

        /* STATUS BADGES */
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: .3px;
            white-space: nowrap;
        }
        .status-pending { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; }
        .status-confirmed { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
        .status-completed { background: #E0F2FE; color: #0369A1; border: 1px solid #BAE6FD; }
        .status-cancelled { background: #FEE2E2; color: #B91C1C; border: 1px solid #FECACA; }
        .status-default { background: #F1F5F9; color: #475569; border: 1px solid #E2E8F0; }

        /* ACTION BUTTONS */
        .action-wrap {
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .btn-action-chart {
            background: #EAF7F0;
            color: var(--clinic-teal);
            border: 1px solid #C9E9DA;
            border-radius: 8px;
            font-weight: 700;
            font-size: 12px;
            padding: 6px 11px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            transition: all .15s ease;
        }
        .btn-action-chart:hover {
            background: var(--clinic-teal);
            color: #ffffff;
            border-color: var(--clinic-teal);
        }

        .btn-action-edit {
            background: #FFFBEB;
            color: #92400E;
            border: 1px solid #FDE68A;
            border-radius: 8px;
            font-weight: 700;
            font-size: 12px;
            padding: 6px 10px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
            transition: all .15s ease;
        }
        .btn-action-edit:hover {
            background: #F59E0B;
            color: #ffffff;
            border-color: #F59E0B;
        }

        .more-wrap {
            position: relative;
            display: inline-block;
        }

        .more-wrap summary {
            list-style: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            padding: 6px 8px;
            border-radius: 8px;
            background: #F1F5F9;
            color: #475569;
            font-size: 13px;
            border: 1px solid #E2E8F0;
        }
        .more-wrap summary::-webkit-details-marker { display: none; }
        .more-wrap summary:hover { background: #E2E8F0; }

        .more-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 6px);
            min-width: 220px;
            background: #fff;
            border: 1px solid #DCE5ED;
            border-radius: 10px;
            box-shadow: 0 12px 30px rgba(0,0,0,.15);
            padding: 6px;
            z-index: 100;
        }
        .more-menu a, .more-menu .more-info {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            border-radius: 7px;
            font-size: 12.5px;
            color: #34495E;
            text-decoration: none;
            font-weight: 600;
        }
        .more-menu a:hover { background: #F0FDF4; color: var(--clinic-teal); }
        .more-menu .more-info { color: #64748B; font-weight: 600; cursor: default; }
        .more-menu .more-divider { height: 1px; background: #EEF3F0; margin: 4px 0; }

        /* PAGINATION */
        .patient-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            padding: 14px 22px;
            border-top: 1px solid #E6F2EC;
            background: #F6FBF8;
        }

        .pagination-pills-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .patient-page-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border-radius: 9px;
            border: 1px solid #C9E9DA;
            background: #ffffff;
            color: #0E6B5E;
            font-size: 12.5px;
            font-weight: 800;
            text-decoration: none;
            transition: all .15s ease;
        }
        .patient-page-btn:hover:not(.disabled):not(.active) { background: #EAF7F0; }
        .patient-page-btn.active { background: #0E6B5E; border-color: #0E6B5E; color: #ffffff; }
        .patient-page-btn.disabled { opacity: .45; cursor: default; }
    </style>
</head>
<body>

<?php
$dentistActivePage = 'patients';
include __DIR__ . '/dentist_sidebar.php';
?>

<main class="portal-main">
    <div class="clinic-minibar">
        🦷 <span>Abalos Dental Clinic — Practitioner Patient Directory</span>
    </div>

    <header class="portal-topbar">
        <div>
            <div class="portal-heading" style="font-family:'Plus Jakarta Sans',sans-serif; font-size:22px; font-weight:800; color:#143F51;">
                Assigned Patients Directory
            </div>
            <div class="portal-subtitle" style="font-size:13px; color:#64748B;">
                View patient demographic records, chairside appointments, and clinical dental histories
            </div>
        </div>

        <div style="display:flex; align-items:center; gap:16px;">
            <div class="topbar-today" style="border-left:none; padding-left:0;">
                <i class="bi bi-calendar2-check-fill"></i>
                <div>
                    <strong><?= htmlspecialchars(date('l')) ?></strong>
                    <small><?= htmlspecialchars(date('M d, Y')) ?></small>
                </div>
            </div>

            <div class="topbar-time">
                <i class="bi bi-clock-fill"></i>
                <div>
                    <strong>Clinic Time</strong>
                    <small id="patientListClinicTime">--:--:--</small>
                </div>
            </div>

            <div class="portal-user">
                <?= htmlspecialchars($dentistName) ?>
                <div class="portal-role">Licensed Dentist</div>
            </div>
        </div>
    </header>

    <script>
    (function(){
        function tick(){
            var el = document.getElementById('patientListClinicTime');
            if(!el) return;
            var now = new Date();
            el.textContent = now.toLocaleTimeString('en-PH', {
                timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
            });
        }
        tick(); setInterval(tick, 1000);
    })();
    </script>

    <div class="portal-content" style="padding: 26px 32px 40px 32px;">

        <!-- Feedbacks -->
        <?php if ($editFlash !== ''): ?>
            <div class="alert alert-success d-flex align-items-center gap-2 mb-3" style="border-radius:12px; font-weight:600; font-size:13.5px; border-left:5px solid #059669;">
                <i class="bi bi-check-circle-fill text-success fs-5"></i>
                <div><?= dentistPatientEscape($editFlash) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($editError !== ''): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:12px; font-weight:600; font-size:13.5px; border-left:5px solid #DC2626;">
                <i class="bi bi-exclamation-triangle-fill text-danger fs-5"></i>
                <div><?= dentistPatientEscape($editError) ?></div>
            </div>
        <?php endif; ?>

        <!-- HERO DIRECTORY BANNER -->
        <div class="patient-hero-banner">
            <div class="hero-banner-title">
                <h1>
                    <i class="bi bi-people-fill text-warning"></i>
                    Dental Patient Records
                </h1>
                <p>
                    Manage your clinical roster, track upcoming chairside appointments, and review past dental procedures for your assigned patients.
                </p>
            </div>

            <div class="hero-stat-badges">
                <div class="hero-stat-box">
                    <strong><?= $patientsTotal ?></strong>
                    <span>Total Patients</span>
                </div>
                <div class="hero-stat-box">
                    <strong><?= $statUpcomingCount ?></strong>
                    <span>Upcoming Visits</span>
                </div>
                <div class="hero-stat-box">
                    <strong><?= $statCompletedCount ?></strong>
                    <span>Completed</span>
                </div>
            </div>
        </div>

        <!-- TOOLBAR & SEARCH FORM -->
        <div class="patient-toolbar-card">
            <form method="GET" action="patients.php" class="search-form-wrap">
                <div class="search-input-group">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search by patient name, ID (e.g. PAT-...), or phone" value="<?= dentistPatientEscape($search) ?>">
                </div>

                <div class="status-select-wrap">
                    <select name="status" id="patientStatusFilter" onchange="this.form.submit()" aria-label="Filter by appointment status">
                        <option value="">All Appointment Statuses</option>
                        <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Confirmed" <?= $statusFilter === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
                        <option value="Completed" <?= $statusFilter === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="Cancelled" <?= $statusFilter === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>

                <?php if ($search !== '' || $statusFilter !== ''): ?>
                    <a href="patients.php" class="btn-clear-clinic" title="Clear search and filter">
                        <i class="bi bi-x-circle"></i> Clear
                    </a>
                <?php endif; ?>
            </form>

            <div class="patient-table-meta">
                Showing <strong><?= $showingFrom ?>–<?= $showingTo ?></strong> of <strong><?= $patientsTotal ?></strong> patients
            </div>
        </div>

        <!-- PATIENT ROSTER TABLE CARD -->
        <div class="patient-table-card">
            <?php if (empty($patients)): ?>
                <div class="p-5 text-center text-muted">
                    <i class="bi bi-person-x fs-1 d-block mb-2 text-muted"></i>
                    <strong style="font-size:15px; color:#143F51;">No matching patients found</strong>
                    <p class="small text-muted m-0">Try adjusting your search criteria or clearing your status filters.</p>
                </div>
            <?php else: ?>
                <div class="patient-table-scroll" id="patientTableWrap">
                    <table class="patient-roster-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Patient Details</th>
                                <th>Contact Information</th>
                                <th>Assigned Dentist</th>
                                <th>Next Scheduled Visit</th>
                                <th>Status</th>
                                <th style="text-align: right; padding-right: 24px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rowNum = $offset; foreach ($patients as $p): $rowNum++; ?>
                                <?php
                                $pFullName = trim($p['first_name'] . ' ' . ($p['middle_name'] ?? '') . ' ' . $p['last_name']);
                                $pInitials = getPatientInitials($pFullName);
                                $pStatus = trim($p['next_status'] ?? '');
                                $displayStatus = $pStatus;
                                if ($displayStatus === '' && $statusFilter !== '') {
                                    $displayStatus = $statusFilter;
                                }
                                $statusLower = strtolower($displayStatus);

                                $statusClass = 'status-default';
                                if ($statusLower === 'pending') $statusClass = 'status-pending';
                                elseif ($statusLower === 'confirmed') $statusClass = 'status-confirmed';
                                elseif ($statusLower === 'completed') $statusClass = 'status-completed';
                                elseif ($statusLower === 'cancelled') $statusClass = 'status-cancelled';

                                $nextDateLabel = !empty($p['next_appointment_date']) ? date('M d, Y', strtotime($p['next_appointment_date'])) : '';
                                $nextTimeLabel = !empty($p['next_appointment_time']) ? date('h:i A', strtotime($p['next_appointment_time'])) : '';
                                $nextServiceLabel = trim((string)($p['next_service'] ?? ''));
                                $apptCount = (int) ($p['appointment_count'] ?? 0);
                                ?>
                                <tr>
                                    <td>
                                        <span style="font-weight:700; color:#64748B; font-size:12px;"><?= $rowNum ?></span>
                                    </td>

                                    <td>
                                        <div class="patient-cell-profile">
                                            <div class="patient-avatar-sm">
                                                <?= htmlspecialchars($pInitials) ?>
                                            </div>
                                            <div>
                                                <a href="patients.php?patient_id=<?= (int)$p['id'] ?>" class="patient-name-link" title="Open patient dental record">
                                                    <?= dentistPatientEscape($pFullName) ?>
                                                </a>
                                                <span class="patient-id-badge">
                                                    <?= dentistPatientEscape($p['patient_number']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </td>

                                    <td>
                                        <div>
                                            <?php if (!empty($p['contact_number'])): ?>
                                                <a href="tel:<?= dentistPatientEscape($p['contact_number']) ?>" style="color:#143F51; font-weight:600; text-decoration:none; font-size:12.5px;">
                                                    <i class="bi bi-telephone text-success me-1"></i><?= dentistPatientEscape($p['contact_number']) ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($p['email'])): ?>
                                            <div style="font-size:11.5px; color:#64748B; margin-top:2px;">
                                                <i class="bi bi-envelope me-1"></i><?= dentistPatientEscape($p['email']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <span style="font-weight:600; font-size:12.5px;">
                                            <?= dentistPatientEscape($p['assigned_dentists'] ?: 'Dr. ' . $dentistName) ?>
                                        </span>
                                        <div style="font-size:11px; color:#64748B;">
                                            <?= $apptCount ?> consultation<?= $apptCount == 1 ? '' : 's' ?> on file
                                        </div>
                                    </td>

                                    <td>
                                        <?php if ($nextDateLabel !== ''): ?>
                                            <div style="font-weight:700; font-size:12.5px; color:#143F51;">
                                                <i class="bi bi-calendar2-check text-success me-1"></i><?= htmlspecialchars($nextDateLabel) ?>
                                            </div>
                                            <div style="font-size:11.5px; color:#64748B; margin-top:2px;">
                                                <?= htmlspecialchars($nextTimeLabel) ?>
                                                <?php if ($nextServiceLabel !== ''): ?>
                                                    · <span class="badge bg-light text-dark border" style="font-size:10.5px;"><?= htmlspecialchars($nextServiceLabel) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color:#94A3B8; font-size:12px;">No upcoming schedule</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if (!empty($displayStatus)): ?>
                                            <span class="status-badge <?= $statusClass ?>">
                                                <?= dentistPatientEscape($displayStatus) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge status-default">No Active Schedule</span>
                                        <?php endif; ?>
                                    </td>

                                    <td style="text-align: right; padding-right: 20px;">
                                        <div class="action-wrap">
                                            <a href="patients.php?patient_id=<?= (int)$p['id'] ?>" class="btn-action-chart" title="View Dental Chart & History">
                                                <i class="bi bi-journal-medical"></i> Chart
                                            </a>

                                            <button type="button" class="btn-action-edit patient-edit-btn" title="Edit Patient Information"
                                                data-id="<?= (int)$p['id'] ?>"
                                                data-patient-id="<?= dentistPatientEscape($p['patient_number']) ?>"
                                                data-first="<?= dentistPatientEscape($p['first_name']) ?>"
                                                data-middle="<?= dentistPatientEscape($p['middle_name'] ?? '') ?>"
                                                data-last="<?= dentistPatientEscape($p['last_name']) ?>"
                                                data-contact="<?= dentistPatientEscape($p['contact_number'] ?? '') ?>"
                                                data-email="<?= dentistPatientEscape($p['email'] ?? '') ?>"
                                            >
                                                <i class="bi bi-pencil-square"></i> Edit
                                            </button>

                                            <details class="more-wrap">
                                                <summary title="Quick Options">
                                                    <i class="bi bi-three-dots"></i>
                                                </summary>
                                                <div class="more-menu">
                                                    <a href="patients.php?patient_id=<?= (int)$p['id'] ?>">
                                                        <i class="bi bi-clock-history"></i> Appointment History
                                                    </a>
                                                    <a href="appointments.php">
                                                        <i class="bi bi-calendar2-check"></i> View Appointments Roster
                                                    </a>
                                                    <div class="more-divider"></div>
                                                    <div class="more-info">
                                                        <i class="bi bi-hash"></i> Record: <?= dentistPatientEscape($p['patient_number']) ?>
                                                    </div>
                                                </div>
                                            </details>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- PAGINATION BAR -->
                <?php if ($totalPages > 1): ?>
                    <nav class="patient-pagination" aria-label="Patient roster pagination">
                        <div style="font-size:12.5px; color:#57727D; font-weight:700;">
                            Page <?= $page ?> of <?= $totalPages ?>
                        </div>

                        <div class="pagination-pills-wrap">
                            <?php if ($page > 1): ?>
                                <a class="patient-page-btn" href="<?= dentistPatientEscape(patientPageUrl($page - 1)) ?>">
                                    <i class="bi bi-chevron-left"></i> Prev
                                </a>
                            <?php else: ?>
                                <span class="patient-page-btn disabled"><i class="bi bi-chevron-left"></i> Prev</span>
                            <?php endif; ?>

                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <?php if ($p === $page): ?>
                                    <span class="patient-page-btn active"><?= $p ?></span>
                                <?php elseif ($p == 1 || $p == $totalPages || abs($p - $page) <= 2): ?>
                                    <a class="patient-page-btn" href="<?= dentistPatientEscape(patientPageUrl($p)) ?>"><?= $p ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a class="patient-page-btn" href="<?= dentistPatientEscape(patientPageUrl($page + 1)) ?>">
                                    Next <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="patient-page-btn disabled">Next <i class="bi bi-chevron-right"></i></span>
                            <?php endif; ?>
                        </div>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </div>

</main>

<!-- EDIT PATIENT MODAL -->
<div class="pedit-overlay" id="peditOverlay">
    <div class="pedit-box" role="dialog" aria-modal="true" aria-label="Edit Patient Information">
        <div class="pedit-head">
            <h5><i class="bi bi-pencil-square"></i> Edit Patient Information</h5>
            <button type="button" class="pedit-x" id="peditClose" aria-label="Close">✕</button>
        </div>

        <form method="POST" action="patients.php" id="peditForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_patient">
            <input type="hidden" name="patient_id" id="peditPatientId" value="">

            <div class="pedit-body">
                <div class="pedit-grid">
                    <div class="pedit-field full">
                        <label for="peditPatientNumber">Patient Number (Read-only)</label>
                        <input type="text" id="peditPatientNumber" disabled readonly value="">
                    </div>

                    <div class="pedit-field">
                        <label for="peditFirstName">First Name <span>*</span></label>
                        <input type="text" id="peditFirstName" name="first_name" maxlength="100" required value="">
                    </div>

                    <div class="pedit-field">
                        <label for="peditMiddleName">Middle Name</label>
                        <input type="text" id="peditMiddleName" name="middle_name" maxlength="100" value="">
                    </div>

                    <div class="pedit-field">
                        <label for="peditLastName">Last Name <span>*</span></label>
                        <input type="text" id="peditLastName" name="last_name" maxlength="100" required value="">
                    </div>

                    <div class="pedit-field">
                        <label for="peditContact">Contact Number</label>
                        <input type="text" id="peditContact" name="contact_number" maxlength="50" value="">
                    </div>

                    <div class="pedit-field full">
                        <label for="peditEmail">Email Address</label>
                        <input type="email" id="peditEmail" name="email" maxlength="255" value="">
                    </div>
                </div>

                <p class="pedit-note">
                    <i class="bi bi-shield-lock-fill"></i>
                    Patient ID is permanently assigned by the clinic and cannot be modified.
                </p>
            </div>

            <div class="pedit-foot">
                <button type="button" class="pedit-cancel" id="peditCancel">Cancel</button>
                <button type="submit" class="pedit-save">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<style>
.pedit-overlay{position:fixed;inset:0;background:rgba(10,59,52,.55);z-index:2500;padding:20px;display:none;align-items:center;justify-content:center}
.pedit-overlay.open{display:flex}
.pedit-box{background:#fff;border-radius:18px;max-width:520px;width:100%;overflow:hidden;box-shadow:0 18px 45px rgba(0,0,0,.25);animation:peditPop .18s ease;max-height:92vh;overflow-y:auto}
@keyframes peditPop{from{transform:scale(.96);opacity:0}to{transform:scale(1);opacity:1}}
.pedit-head{padding:18px 22px;background:linear-gradient(135deg,#0E6B5E,#059669);display:flex;align-items:center;gap:10px}
.pedit-head h5{margin:0;flex:1;color:#fff;font-weight:800;font-size:16px}
.pedit-x{background:#fff;border:1px solid #D1E7DD;color:#57727d;width:30px;height:30px;border-radius:9px;cursor:pointer;font-size:13px}
.pedit-x:hover{background:#EAF7F0;color:#0E6B5E}
.pedit-body{padding:18px 22px 6px}
.pedit-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 14px}
.pedit-field{display:flex;flex-direction:column;gap:5px}
.pedit-field.full{grid-column:1 / -1}
.pedit-field label{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#143f51}
.pedit-field label span{color:#C0392B}
.pedit-field input{width:100%;border:1px solid #D1E7DD;border-radius:9px;padding:9px 12px;font-family:inherit;font-size:13.5px;color:#244f60;background:#fff}
.pedit-field input:focus{outline:none;border-color:#059669;box-shadow:0 0 0 3px rgba(5,150,105,.14)}
.pedit-field input:disabled{background:#F1F5F4;color:#57727d;font-weight:700;cursor:not-allowed}
.pedit-note{font-size:11.5px;color:#64748B;margin:12px 0 0}
.pedit-foot{display:flex;justify-content:flex-end;gap:10px;padding:14px 22px 20px}
.pedit-cancel{background:#fff;border:1px solid #D1E7DD;border-radius:10px;font-weight:700;font-family:inherit;font-size:13.5px;padding:9px 20px;cursor:pointer;color:#57727d}
.pedit-cancel:hover{background:#EAF7F0;color:#0E6B5E}
.pedit-save{background:linear-gradient(135deg,#0E6B5E,#059669);border:none;border-radius:10px;font-weight:800;font-family:inherit;font-size:13.5px;padding:9px 22px;cursor:pointer;color:#fff;box-shadow:0 6px 14px rgba(14,107,94,.30)}
.pedit-save:hover{filter:brightness(1.08)}
</style>

<script>
// Edit modal logic
(function(){
    var overlay = document.getElementById('peditOverlay');
    if(!overlay) return;

    var form = document.getElementById('peditForm');
    var fPatientId = document.getElementById('peditPatientId');
    var fPatientNumber = document.getElementById('peditPatientNumber');
    var fFirst = document.getElementById('peditFirstName');
    var fMiddle = document.getElementById('peditMiddleName');
    var fLast = document.getElementById('peditLastName');
    var fContact = document.getElementById('peditContact');
    var fEmail = document.getElementById('peditEmail');

    function close(){ overlay.classList.remove('open'); }

    document.getElementById('peditClose')?.addEventListener('click', close);
    document.getElementById('peditCancel')?.addEventListener('click', close);
    overlay.addEventListener('click', function(e){ if(e.target === overlay) close(); });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape') close(); });

    document.querySelectorAll('.patient-edit-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            form.setAttribute('action', 'patients.php' + (window.location.search || ''));
            fPatientId.value = btn.dataset.id || '';
            fPatientNumber.value = btn.dataset.patientId || '';
            fFirst.value = btn.dataset.first || '';
            fMiddle.value = btn.dataset.middle || '';
            fLast.value = btn.dataset.last || '';
            fContact.value = btn.dataset.contact || '';
            fEmail.value = btn.dataset.email || '';
            overlay.classList.add('open');
            setTimeout(function(){ fFirst.focus(); }, 60);
        });
    });
})();

// Auto-submit status filter dropdown on change
(function () {
    var statusSelect = document.getElementById('patientStatusFilter');
    if (statusSelect && statusSelect.form) {
        statusSelect.addEventListener('change', function () {
            this.form.submit();
        });
    }
})();

// Close more dropdowns on outside click
(function () {
    document.addEventListener('click', function (e) {
        document.querySelectorAll('details.more-wrap[open]').forEach(function (d) {
            if (!d.contains(e.target)) d.removeAttribute('open');
        });
    });
})();
</script>

<!-- DENTIST LOGOUT CONFIRM MODAL -->
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
.dentist-logout-overlay { position: fixed; inset: 0; background: rgba(10,59,52,.55); z-index: 3000; padding: 20px; align-items: center; justify-content: center; }
.dentist-logout-box { background: #ffffff; border-radius: 18px; border: 1px solid #C9E9DA; box-shadow: 0 18px 45px rgba(0,0,0,.25); max-width: 440px; width: 100%; overflow: hidden; font-family: "DM Sans", system-ui, sans-serif; }
.dentist-logout-head { display: flex; align-items: center; gap: 12px; padding: 18px 22px; background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%); border-bottom: 1px solid #C9E9DA; }
.dentist-logout-head-icon { width: 38px; height: 38px; border-radius: 11px; background: linear-gradient(135deg, #0E6B5E, #0A4A41); color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 4px 10px rgba(14,107,94,.25); flex-shrink: 0; }
.dentist-logout-head h5 { color: #143f51; font-family: "Plus Jakarta Sans", system-ui, sans-serif; font-weight: 800; font-size: 17px; margin: 0; flex: 1; }
.dentist-logout-x { background: #ffffff; border: 1px solid #D1E7DD; color: #57727d; width: 30px; height: 30px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; }
.dentist-logout-body { padding: 30px 28px 8px; text-align: center; }
.dentist-logout-icon { width: 64px; height: 64px; border-radius: 18px; background: #FDECEC; color: #C0392B; border: 1px solid #F5C6C6; display: inline-flex; align-items: center; justify-content: center; font-size: 30px; margin-bottom: 14px; }
.dentist-logout-body h5 { color: #143f51; font-family: "Plus Jakarta Sans", system-ui, sans-serif; font-weight: 800; font-size: 18px; margin: 0 0 8px; }
.dentist-logout-body p { color: #8aa0a8; font-size: 13.5px; margin: 0; }
.dentist-logout-foot { display: flex; gap: 12px; justify-content: center; padding: 18px 22px 22px; background: #F6FBF8; border-top: 1px solid #E6F2EC; margin-top: 20px; }
.dentist-logout-foot .btn { border-radius: 10px; font-weight: 700; font-size: 14px; padding: 10px 26px; }
.dentist-logout-foot .btn-light { background: #fff !important; border: 1px solid #D1E7DD !important; color: #57727d !important; }
.dentist-logout-yes { background: linear-gradient(135deg, #E74C3C, #922B21) !important; border: none !important; color: #ffffff !important; box-shadow: 0 6px 14px rgba(192,57,43,.3) !important; }
</style>

<script>
(function(){
    function openDentistLogout(e){ if(e) e.preventDefault(); var el=document.getElementById("dentistLogoutConfirm"); if(el) el.style.display="flex"; }
    function closeDentistLogout(){ var el=document.getElementById("dentistLogoutConfirm"); if(el) el.style.display="none"; }
    document.querySelectorAll('a[href="../logout.php"], a[href="logout.php"]').forEach(function(a){ a.addEventListener("click", openDentistLogout); });
    document.getElementById("dentistLogoutCancel")?.addEventListener("click", closeDentistLogout);
    document.getElementById("dentistLogoutClose")?.addEventListener("click", closeDentistLogout);
})();
</script>
</body>
</html>
