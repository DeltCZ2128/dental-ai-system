<?php
/**
 * Abalos Dental Clinic - Official Patient Dental & Medical Record Print Template
 * Clean, print-ready clinical chart and treatment summary for clinic admin & patients.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$patientId = (int) ($_GET['id'] ?? 0);
if ($patientId <= 0) {
    die('Invalid patient ID requested.');
}

// 1. Fetch Patient Info
$stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? LIMIT 1");
$stmt->execute([$patientId]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    die('Patient record not found in system database.');
}

// 2. Clinic Settings
$settings = getSetting();
$clinicName = !empty($settings['clinic_name']) ? $settings['clinic_name'] : 'Abalos Dental Clinic';
$clinicAddress = !empty($settings['address']) ? $settings['address'] : 'Dagupan City, Pangasinan';
$clinicContact = !empty($settings['contact_number']) ? $settings['contact_number'] : '(075) 522-0000';
$clinicEmail = !empty($settings['email']) ? $settings['email'] : 'contact@abalosdental.com';

// 3. Compute Age
$ageDisplay = '—';
if (!empty($patient['birth_date']) && $patient['birth_date'] !== '0000-00-00') {
    try {
        $bDate = new DateTime($patient['birth_date']);
        $now = new DateTime();
        $diff = $now->diff($bDate);
        $ageDisplay = $diff->y . ' years old (' . $bDate->format('M d, Y') . ')';
    } catch (Exception $e) {
        $ageDisplay = $patient['birth_date'];
    }
}

// 4. Fetch Appointment & Treatment History
$stmt = $pdo->prepare("
    SELECT a.*, d.dentist_name
    FROM appointments a
    LEFT JOIN dentists d ON d.id = a.dentist_id
    WHERE a.patient_id = ?
    ORDER BY a.appointment_date DESC, a.start_time DESC
");
$stmt->execute([$patientId]);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Fetch Clinical Medical Records (if any)
$stmt = $pdo->prepare("
    SELECT * FROM medical_records
    WHERE patient_id = ?
    ORDER BY record_date DESC, id DESC
");
$stmt->execute([$patientId]);
$medicalRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper for escaping
function prEscape($val) {
    return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8');
}

$patientFullName = trim(($patient['first_name'] ?? '') . ' ' . ($patient['middle_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));
if ($patientFullName === '') {
    $patientFullName = 'Unnamed Patient';
}
$patientNumber = !empty($patient['patient_number']) ? $patient['patient_number'] : ('PAT-' . str_pad($patient['id'], 5, '0', STR_PAD_LEFT));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Record - <?= prEscape($patientFullName) ?> (<?= prEscape($patientNumber) ?>) | <?= prEscape($clinicName) ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --brand-green: #0E6B5E;
            --brand-dark: #0A4A41;
            --brand-light: #EAF7F0;
            --brand-border: #C9E9DA;
            --text-main: #0F172A;
            --text-muted: #64748B;
            --bg-table-header: #F1F5F9;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: "Inter", system-ui, -apple-system, sans-serif;
            background: #F8FAFC;
            color: var(--text-main);
            font-size: 13.5px;
            line-height: 1.5;
            padding: 24px 16px;
        }

        /* -------------------------------------------------------------
           SCREEN ACTION BAR (HIDDEN IN PRINT)
           ------------------------------------------------------------- */
        .screen-toolbar {
            max-width: 900px;
            margin: 0 auto 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
            padding: 12px 20px;
            border-radius: 12px;
            border: 1px solid #E2E8F0;
            box-shadow: 0 4px 12px rgba(15,23,42,0.06);
        }

        .screen-toolbar-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--brand-dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .screen-toolbar-actions {
            display: flex;
            gap: 10px;
        }

        .btn-tb {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
            border: none;
        }

        .btn-tb-secondary {
            background: #F1F5F9;
            color: #475569;
            border: 1px solid #CBD5E1;
        }
        .btn-tb-secondary:hover {
            background: #E2E8F0;
            color: #1E293B;
        }

        .btn-tb-primary {
            background: linear-gradient(135deg, var(--brand-green), var(--brand-dark));
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(14,107,94,0.28);
        }
        .btn-tb-primary:hover {
            filter: brightness(0.92);
            color: #ffffff;
        }

        /* -------------------------------------------------------------
           PRINT SHEET CONTAINER
           ------------------------------------------------------------- */
        .record-sheet {
            max-width: 900px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            padding: 36px 42px;
        }

        /* -------------------------------------------------------------
           CLINIC LETTERHEAD
           ------------------------------------------------------------- */
        .clinic-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 16px;
            border-bottom: 2.5px solid var(--brand-green);
            margin-bottom: 22px;
        }

        .clinic-brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .clinic-logo {
            width: 64px;
            height: 64px;
            object-fit: cover;
            border-radius: 10px;
            border: 1.5px solid var(--brand-green);
        }

        .clinic-titles h1 {
            font-family: "Plus Jakarta Sans", sans-serif;
            font-size: 20px;
            font-weight: 800;
            color: var(--brand-green);
            letter-spacing: 0.5px;
            line-height: 1.15;
            margin-bottom: 3px;
        }

        .clinic-titles h2 {
            font-family: "Plus Jakarta Sans", sans-serif;
            font-size: 13.5px;
            font-weight: 700;
            color: #1E293B;
            margin-bottom: 4px;
        }

        .clinic-titles p {
            font-size: 11.5px;
            color: var(--text-muted);
            line-height: 1.35;
        }

        .record-meta-box {
            text-align: right;
            font-size: 11.5px;
            color: #334155;
            background: var(--brand-light);
            border: 1px solid var(--brand-border);
            padding: 8px 14px;
            border-radius: 8px;
            line-height: 1.5;
        }

        /* -------------------------------------------------------------
           SECTION TITLES & BOXES
           ------------------------------------------------------------- */
        .section-block {
            margin-bottom: 22px;
        }

        .section-title {
            font-family: "Plus Jakarta Sans", sans-serif;
            font-size: 12.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--brand-dark);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            border-bottom: 1.5px solid #E2E8F0;
            padding-bottom: 4px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            padding: 14px 18px;
        }

        .info-item {
            font-size: 12px;
        }

        .info-item .label {
            font-size: 10.5px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 2px;
        }

        .info-item .value {
            font-size: 13px;
            font-weight: 600;
            color: #0F172A;
            word-break: break-word;
        }

        .info-item.col-span-2 {
            grid-column: span 2;
        }

        .info-item.col-span-4 {
            grid-column: span 4;
        }

        /* -------------------------------------------------------------
           BACKGROUND / ALERT BOX
           ------------------------------------------------------------- */
        .history-alert-box {
            background: #F8FAFC;
            border-left: 4px solid var(--brand-green);
            border-top: 1px solid #E2E8F0;
            border-right: 1px solid #E2E8F0;
            border-bottom: 1px solid #E2E8F0;
            border-radius: 6px;
            padding: 12px 16px;
            font-size: 12.5px;
            color: #334155;
            line-height: 1.5;
        }

        /* -------------------------------------------------------------
           CLINICAL TABLE
           ------------------------------------------------------------- */
        .clinical-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            border: 1px solid #CBD5E1;
            border-radius: 8px;
            overflow: hidden;
        }

        .clinical-table thead th {
            background: var(--bg-table-header);
            color: #1E293B;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 9px 12px;
            border-bottom: 1.5px solid #CBD5E1;
            border-right: 1px solid #E2E8F0;
            text-align: left;
        }

        .clinical-table thead th:last-child {
            border-right: none;
        }

        .clinical-table tbody tr {
            border-bottom: 1px solid #E2E8F0;
        }

        .clinical-table tbody tr:last-child {
            border-bottom: none;
        }

        .clinical-table tbody td {
            padding: 8px 12px;
            border-right: 1px solid #E2E8F0;
            vertical-align: top;
            color: #1E293B;
        }

        .clinical-table tbody td:last-child {
            border-right: none;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-completed {
            background: #EAF7F0;
            color: #0E6B5E;
            border: 1px solid #C9E9DA;
        }
        .status-confirmed, .status-approved {
            background: #E0F2FE;
            color: #0369A1;
            border: 1px solid #BAE6FD;
        }
        .status-pending {
            background: #FEF3C7;
            color: #B45309;
            border: 1px solid #FDE68A;
        }
        .status-cancelled {
            background: #FEE2E2;
            color: #B91C1C;
            border: 1px solid #FECACA;
        }

        /* -------------------------------------------------------------
           SIGNATURE FOOTER
           ------------------------------------------------------------- */
        .signatures-grid {
            display: flex;
            justify-content: space-between;
            margin-top: 36px;
            padding: 0 20px;
            page-break-inside: avoid;
        }

        .sign-col {
            width: 250px;
            text-align: center;
        }

        .sign-caption {
            font-size: 11px;
            color: var(--text-muted);
            margin-bottom: 42px;
            text-align: left;
            font-weight: 600;
        }

        .sign-line {
            border-bottom: 1.5px solid #1E293B;
            margin-bottom: 5px;
        }

        .sign-name {
            font-size: 13px;
            font-weight: 800;
            color: #0F172A;
            line-height: 1.2;
        }

        .sign-role {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .confidential-footer {
            margin-top: 28px;
            border-top: 1px solid #E2E8F0;
            padding-top: 8px;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #94A3B8;
        }

        /* -------------------------------------------------------------
           PRINT MEDIA QUERIES (100% CLEAN PAPER FORMAT)
           ------------------------------------------------------------- */
        @media print {
            @page {
                size: portrait;
                margin: 12mm 10mm 12mm 10mm;
            }

            html, body {
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
                color: #000000 !important;
                font-size: 10.5pt !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .screen-toolbar {
                display: none !important;
            }

            .record-sheet {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                max-width: 100% !important;
                margin: 0 !important;
                border-radius: 0 !important;
            }

            .clinic-header {
                padding-bottom: 10px !important;
                margin-bottom: 16px !important;
                border-bottom: 2px solid var(--brand-green) !important;
            }

            .info-grid {
                background: #ffffff !important;
                border: 1px solid #CBD5E1 !important;
                padding: 10px 14px !important;
                gap: 8px !important;
            }

            .history-alert-box {
                background: #ffffff !important;
                border: 1px solid #CBD5E1 !important;
                border-left: 3.5px solid var(--brand-green) !important;
                padding: 8px 12px !important;
                font-size: 9.5pt !important;
            }

            .clinical-table {
                font-size: 9pt !important;
                border: 1px solid #CBD5E1 !important;
            }

            .clinical-table thead th {
                background: #F1F5F9 !important;
                padding: 6px 8px !important;
                border-bottom: 1.5px solid #94A3B8 !important;
            }

            .clinical-table tbody td {
                padding: 6px 8px !important;
            }

            .status-badge {
                border: 1px solid #CBD5E1 !important;
                padding: 2px 6px !important;
                font-size: 8pt !important;
            }

            .signatures-grid {
                margin-top: 30px !important;
                padding: 0 10px !important;
            }

            .sign-caption {
                margin-bottom: 36px !important;
            }
        }
    </style>
</head>
<body>

    <!-- SCREEN ONLY CONTROLS -->
    <div class="screen-toolbar no-print">
        <div class="screen-toolbar-title">
            <i class="bi bi-file-earmark-medical-fill" style="color:var(--brand-green); font-size:18px;"></i>
            <span>Patient Medical Record Preview</span>
        </div>
        <div class="screen-toolbar-actions">
            <a href="patients.php" class="btn-tb btn-tb-secondary">
                <i class="bi bi-arrow-left"></i> Back to Patients
            </a>
            <button type="button" class="btn-tb btn-tb-primary" onclick="window.print()">
                <i class="bi bi-printer-fill"></i> Print / Save PDF
            </button>
        </div>
    </div>

    <!-- MAIN RECORD SHEET (PRINT-READY) -->
    <div class="record-sheet">

        <!-- CLINIC LETTERHEAD -->
        <header class="clinic-header">
            <div class="clinic-brand">
                <img src="../assets/dental-bg.jpg" alt="Abalos Dental Logo" class="clinic-logo">
                <div class="clinic-titles">
                    <h1><?= prEscape($clinicName) ?></h1>
                    <h2>OFFICIAL PATIENT DENTAL &amp; MEDICAL RECORD</h2>
                    <p><?= prEscape($clinicAddress) ?> &bull; Contact: <?= prEscape($clinicContact) ?> &bull; <?= prEscape($clinicEmail) ?></p>
                </div>
            </div>
            <div class="record-meta-box">
                <div><strong>Patient ID:</strong> <?= prEscape($patientNumber) ?></div>
                <div><strong>Date Printed:</strong> <?= date('F d, Y') ?> &bull; <?= date('h:i A') ?></div>
                <div><strong>Generated By:</strong> <?= prEscape($_SESSION['admin_name'] ?? 'Clinic Administrator') ?></div>
            </div>
        </header>

        <!-- SECTION I: PATIENT DEMOGRAPHIC INFORMATION -->
        <section class="section-block">
            <h3 class="section-title"><i class="bi bi-person-fill"></i> I. Patient Demographics &amp; Personal Profile</h3>
            <div class="info-grid">
                <div class="info-item col-span-2">
                    <div class="label">Full Patient Name</div>
                    <div class="value" style="font-size:14px; color:var(--brand-dark);"><?= prEscape($patientFullName) ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Patient ID</div>
                    <div class="value"><?= prEscape($patientNumber) ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Registration Date</div>
                    <div class="value"><?= !empty($patient['created_at']) ? date('M d, Y', strtotime($patient['created_at'])) : '—' ?></div>
                </div>

                <div class="info-item">
                    <div class="label">Gender</div>
                    <div class="value"><?= !empty($patient['gender']) ? ucfirst(prEscape($patient['gender'])) : 'Not recorded' ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Age / Date of Birth</div>
                    <div class="value"><?= prEscape($ageDisplay) ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Contact Number</div>
                    <div class="value"><?= !empty($patient['contact_number']) ? prEscape($patient['contact_number']) : '—' ?></div>
                </div>
                <div class="info-item">
                    <div class="label">Email Address</div>
                    <div class="value"><?= !empty($patient['email']) ? prEscape($patient['email']) : '—' ?></div>
                </div>

                <div class="info-item col-span-4">
                    <div class="label">Residential Address</div>
                    <div class="value"><?= !empty($patient['address']) ? prEscape($patient['address']) : 'No residential address on file.' ?></div>
                </div>
            </div>
        </section>

        <!-- SECTION II: MEDICAL & DENTAL BACKGROUND -->
        <section class="section-block">
            <h3 class="section-title"><i class="bi bi-clipboard2-pulse-fill"></i> II. Medical Background &amp; Dental History</h3>
            <div class="history-alert-box">
                <?php if (!empty($patient['dental_history'])): ?>
                    <?= nl2br(prEscape($patient['dental_history'])) ?>
                <?php else: ?>
                    <em>No pre-existing dental history, known drug allergies, or chronic medical conditions declared by the patient upon clinic enrollment.</em>
                <?php endif; ?>
            </div>
        </section>

        <!-- SECTION III: DENTAL TREATMENT & CONSULTATION HISTORY -->
        <section class="section-block">
            <h3 class="section-title"><i class="bi bi-calendar2-check-fill"></i> III. Dental Consultation &amp; Treatment History</h3>
            <?php if (empty($appointments)): ?>
                <div class="history-alert-box text-muted">
                    <em>No dental consultations or clinical appointments recorded for this patient.</em>
                </div>
            <?php else: ?>
                <table class="clinical-table">
                    <thead>
                        <tr>
                            <th style="width:18%;">Date &amp; Shift Time</th>
                            <th style="width:26%;">Procedure / Service</th>
                            <th style="width:22%;">Attending Dentist</th>
                            <th style="width:14%;">Status</th>
                            <th style="width:20%;">Clinical Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($appointments as $apt): ?>
                            <?php 
                                $s = strtolower(trim($apt['status'] ?? ''));
                                $badgeClass = 'status-' . $s;
                            ?>
                            <tr>
                                <td>
                                    <strong><?= date('M d, Y', strtotime($apt['appointment_date'])) ?></strong><br>
                                    <span style="color:#64748B; font-size:11px;"><?= date('h:i A', strtotime($apt['start_time'])) ?><?= !empty($apt['end_time']) ? ' – ' . date('h:i A', strtotime($apt['end_time'])) : '' ?></span>
                                </td>
                                <td>
                                    <strong><?= prEscape($apt['service_type'] ?: 'General Dental Care') ?></strong>
                                </td>
                                <td>
                                    <?= !empty($apt['dentist_name']) ? 'Dr. ' . prEscape($apt['dentist_name']) : 'Attending Dentist' ?>
                                </td>
                                <td>
                                    <span class="status-badge <?= $badgeClass ?>"><?= prEscape($apt['status']) ?></span>
                                </td>
                                <td>
                                    <span style="font-size:11.5px; color:#475569;"><?= !empty($apt['notes']) ? prEscape($apt['notes']) : '—' ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <!-- SECTION IV: CLINICAL DIAGNOSIS & RECORDS (IF AVAILABLE) -->
        <?php if (!empty($medicalRecords)): ?>
            <section class="section-block">
                <h3 class="section-title"><i class="bi bi-journal-medical"></i> IV. Supplementary Clinical Diagnosis &amp; Findings</h3>
                <table class="clinical-table">
                    <thead>
                        <tr>
                            <th style="width:18%;">Record Date</th>
                            <th style="width:30%;">Clinical Diagnosis</th>
                            <th style="width:30%;">Treatment Performed</th>
                            <th style="width:22%;">Dentist Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($medicalRecords as $mr): ?>
                            <tr>
                                <td><strong><?= date('M d, Y', strtotime($mr['record_date'])) ?></strong></td>
                                <td><?= prEscape($mr['diagnosis']) ?></td>
                                <td><?= prEscape($mr['treatment']) ?></td>
                                <td><?= !empty($mr['notes']) ? prEscape($mr['notes']) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <!-- SECTION V: OFFICIAL CERTIFICATION & SIGN-OFF -->
        <section class="section-block">
            <div class="signatures-grid">
                <div class="sign-col">
                    <div class="sign-caption">Prepared &amp; Certified By:</div>
                    <div class="sign-line"></div>
                    <div class="sign-name"><?= prEscape($_SESSION['admin_name'] ?? 'System Administrator') ?></div>
                    <div class="sign-role">Clinic Administrator / Records Officer</div>
                </div>
                <div class="sign-col">
                    <div class="sign-caption">Noted &amp; Approved By:</div>
                    <div class="sign-line"></div>
                    <div class="sign-name">Dr. Abalos, D.M.D.</div>
                    <div class="sign-role">Licensed Head Dentist / Clinic Director<br>PRC License No: _________________</div>
                </div>
            </div>

            <div class="confidential-footer">
                <span>CONFIDENTIAL CLINICAL RECORD &bull; Abalos Dental Clinic Management System</span>
                <span>Protected under RA 10173 (Data Privacy Act) &bull; Page 1 of 1</span>
            </div>
        </section>

    </div>

    <!-- AUTO-TRIGGER PRINT DIALOG (Graceful with small delay) -->
    <script>
        window.addEventListener("load", function() {
            // Auto open print dialog if opened in a dedicated window/tab
            if (window.location.search.indexOf("autoprint=0") === -1) {
                setTimeout(function() {
                    window.print();
                }, 400);
            }
        });
    </script>
</body>
</html>
