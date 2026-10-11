<?php

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
|
| appointments.dentist_id ay galing sa dentists table, hindi sa users table.
| Kaya kapag may nag-book ang patient, doon dapat tumutugma ang ID dito —
| pangalan muna (exact), tapos ang logged-in user ID bilang fallback.
|
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
|
| Mula sa Edit modal sa patient list. Ang Patient ID (patient_number) ay
| hindi kasama sa update — display lang ito sa form.
|
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

            /* PRG: i-redirect para maiwasan ang double submit */
            $_SESSION['patient_edit_flash'] =
                'Patient information has been updated successfully.';

            $redirectQuery = http_build_query($_GET);

            header(
                'Location: patients.php' .
                ($redirectQuery !== '' ? '?' . $redirectQuery : '')
            );
            exit;

        }

    }

}


$editFlash = trim($_SESSION['patient_edit_flash'] ?? '');
unset($_SESSION['patient_edit_flash']);


/*
|--------------------------------------------------------------------------
| PATIENT DETAILS VIEW
|--------------------------------------------------------------------------
*/

if ($selectedPatientId > 0) {

    /*
    |--------------------------------------------------------------------------
    | Get patient information
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | Patient not found
    |--------------------------------------------------------------------------
    */

    if (!$patient) {
        header('Location: patients.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Assigned dentist(s) ng patient (kung kanino siya nag-book)
    |--------------------------------------------------------------------------
    */

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

    $assignedStmt->execute([
        $selectedPatientId
    ]);

    $assignedDentists = $assignedStmt->fetchAll(PDO::FETCH_ASSOC);

    $assignedDentistLabel = '';

    foreach ($assignedDentists as $assignedDentist) {

        $assignedName = trim(
            (string) ($assignedDentist['dentist_name'] ?? '')
        );

        if ($assignedName === '') {
            continue;
        }

        if ((int) ($assignedDentist['id'] ?? 0) === $dentistId) {
            $assignedName .= ' (You)';
        }

        $assignedDentistLabel .= ($assignedDentistLabel === '')
            ? $assignedName
            : ', ' . $assignedName;

    }


    /*
    |--------------------------------------------------------------------------
    | Appointment history
    |--------------------------------------------------------------------------
    */

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

    $historyStmt->execute([
        $selectedPatientId,
        $dentistId
    ]);

    $appointmentHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | Next / upcoming appointment
    |--------------------------------------------------------------------------
    */

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

    $nextStmt->execute([
        $selectedPatientId,
        $dentistId
    ]);

    $nextAppointment = $nextStmt->fetch(PDO::FETCH_ASSOC);

    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>

        <meta charset="UTF-8">
        <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0"
        >

        <title>Patient Information | Dentist Portal</title>

        <link
            rel="stylesheet"
            href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        >

        <link
            rel="stylesheet"
            href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
        >

        <link
            rel="stylesheet"
            href="portal.css?v=5"
        >

    <!-- DENTIST SIDEBAR (dashboard parity) -->
    <link rel="stylesheet" href="dentist_sidebar.css">

        <style>

            .patient-detail-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 20px;
                margin-bottom: 20px;
                flex-wrap: wrap;
            }

            .patient-profile {
                display: flex;
                align-items: center;
                gap: 15px;
            }

            .patient-avatar {
                width: 58px;
                height: 58px;
                border-radius: 50%;
                background: #e8f4ff;
                color: #2374b8;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 24px;
                font-weight: 700;
            }

            .patient-profile h2 {
                margin: 0 0 4px;
                font-size: 22px;
            }

            .patient-number {
                color: #718096;
                font-size: 13px;
            }

            .back-btn {
                display: inline-flex;
                align-items: center;
                gap: 7px;
                text-decoration: none;
                padding: 9px 14px;
                border-radius: 8px;
                border: 1px solid #dbe5ef;
                color: #34495e;
                background: #fff;
                font-weight: 600;
            }

            .back-btn:hover {
                background: #f5f9fc;
            }

            .detail-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 20px;
                margin-bottom: 20px;
            }

            .info-list {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 16px;
            }

            .info-item {
                padding: 12px;
                background: #f8fbfd;
                border-radius: 8px;
            }

            .info-label {
                font-size: 12px;
                color: #7a8998;
                margin-bottom: 5px;
                font-weight: 600;
                text-transform: uppercase;
            }

            .info-value {
                font-size: 14px;
                color: #253746;
                font-weight: 600;
                word-break: break-word;
            }

            .appointment-highlight {
                border-left: 4px solid #3b82c4;
                background: #f5faff;
                padding: 16px;
                border-radius: 8px;
            }

            .appointment-highlight h3 {
                margin: 0 0 12px;
                font-size: 17px;
            }

            .appointment-date {
                font-size: 16px;
                font-weight: 700;
                margin-bottom: 5px;
            }

            .appointment-service {
                color: #667788;
                font-size: 14px;
            }

            .status-badge {
                display: inline-block;
                padding: 5px 10px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 700;
            }

            .status-pending {
                background: #fff4d6;
                color: #9a6700;
            }

            .status-confirmed {
                background: #e1f5e8;
                color: #18753c;
            }

            .status-completed {
                background: #e5eef9;
                color: #285c91;
            }

            .status-cancelled {
                background: #fde8e8;
                color: #b42318;
            }

            .status-default {
                background: #edf1f5;
                color: #536273;
            }

            .table-wrap {
                overflow-x: auto;
            }

            .empty-history {
                padding: 25px;
                text-align: center;
                color: #7a8998;
            }

            @media (max-width: 800px) {

                .detail-grid {
                    grid-template-columns: 1fr;
                }

                .info-list {
                    grid-template-columns: 1fr;
                }

            }

            /* TOPBAR date + time (tulad sa appointment page) */
            .topbar-today {
                display: flex;
                align-items: center;
                gap: 10px;
                color: #244f60;
                border-left: none;
                padding-left: 0;
            }
            .topbar-today i { font-size: 22px; }
            .topbar-today strong { display: block; font-size: 13px; font-weight: 800; line-height: 1.2; }
            .topbar-today small { display: block; font-size: 11px; opacity: .85; font-weight: 600; }
            .topbar-time {
                display: flex;
                align-items: center;
                gap: 10px;
                color: #244f60;
                border-left: 1px solid #e8eef2;
                padding-left: 16px;
            }
            .topbar-time i { font-size: 22px; }
            .topbar-time strong { display: block; font-size: 13px; font-weight: 800; line-height: 1.2; }
            .topbar-time small { display: block; font-size: 11px; opacity: .85; font-weight: 600; font-variant-numeric: tabular-nums; }

        </style>

    
</head>

    <body>

        <style>
            body{background:linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22)!important;background-size:300% 300%!important;animation:dentistDashBg 8s ease infinite!important;}
            .portal-main{background:transparent!important;}
            @keyframes dentistDashBg{0%{background-position:0% 50%;}50%{background-position:100% 50%;}100%{background-position:0% 50%;}}
        </style>

        <!-- SIDEBAR -->

        <?php
$dentistActivePage = 'patients';
include __DIR__ . '/dentist_sidebar.php';
?>


        <!-- MAIN -->

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
            </style>

            <!-- MINI CLINIC BAR (gaya sa appointment page) -->

            <div class="clinic-minibar">
                🦷 <span>Abalos Dental Clinic</span>
            </div>

            <header class="portal-topbar">

                <div>

                    <div class="portal-heading">
                        Patient Information
                    </div>

                    <div class="portal-subtitle">
                        View patient details and appointment history
                    </div>

                </div>

                <div style="display:flex;align-items:center;gap:16px;">

                    <div class="topbar-today">

                        <i class="bi bi-calendar-event-fill"></i>

                        <div>
                            <strong><?= dentistPatientEscape(date('l')) ?></strong>
                            <small><?= dentistPatientEscape(date('M d, Y')) ?></small>
                        </div>

                    </div>


                    <div class="topbar-time">

                        <i class="bi bi-clock-fill"></i>

                        <div>
                            <strong>Clinic Time</strong>
                            <small id="patientDetailClinicTime">--:--:--</small>
                        </div>

                    </div>

                </div>

            </header>

            <script>
            (function () {
                function tickPatientDetailClinicTime() {
                    try {
                        var el = document.getElementById('patientDetailClinicTime');
                        if (!el) return;
                        var now = new Date();
                        var timeStr = now.toLocaleTimeString('en-PH', {
                            timeZone: 'Asia/Manila',
                            hour: '2-digit',
                            minute: '2-digit',
                            second: '2-digit',
                            hour12: true
                        });
                        el.textContent = timeStr;
                    } catch (e) {}
                }
                tickPatientDetailClinicTime();
                setInterval(tickPatientDetailClinicTime, 1000);
            })();
            </script>


            <div class="portal-content">


                <!-- PATIENT HEADER -->

                <div class="patient-detail-header">

                    <div class="patient-profile">

                        <div class="patient-avatar">
                            <i class="bi bi-person-fill"></i>
                        </div>

                        <div>

                            <h2>
                                <?= dentistPatientEscape(
                                    trim(
                                        $patient['first_name'] . ' ' .
                                        ($patient['middle_name'] ?? '') . ' ' .
                                        $patient['last_name']
                                    )
                                ) ?>
                            </h2>

                            <div class="patient-number">
                                Patient No:
                                <?= dentistPatientEscape($patient['patient_number']) ?>
                            </div>

                        </div>

                    </div>


                    <a
                        href="patients.php"
                        class="back-btn"
                    >
                        <i class="bi bi-arrow-left"></i>
                        Back to Patients
                    </a>

                </div>


                <!-- BASIC INFORMATION + APPOINTMENT -->

                <div class="detail-grid">


                    <!-- BASIC PATIENT INFORMATION -->

                    <section class="portal-card">

                        <h2>
                            Basic Patient Information
                        </h2>

                        <div class="info-list">

                            <div class="info-item">

                                <div class="info-label">
                                    Patient Number
                                </div>

                                <div class="info-value">
                                    <?= dentistPatientEscape($patient['patient_number']) ?>
                                </div>

                            </div>


                            <div class="info-item">

                                <div class="info-label">
                                    Assigned Dentist
                                </div>

                                <div class="info-value">
                                    <?= dentistPatientEscape(
                                        $assignedDentistLabel
                                    ) ?: '—' ?>
                                </div>

                            </div>


                            <div class="info-item">

                                <div class="info-label">
                                    Gender
                                </div>

                                <div class="info-value">
                                    <?= dentistPatientEscape($patient['gender']) ?: '—' ?>
                                </div>

                            </div>


                            <div class="info-item">

                                <div class="info-label">
                                    Birth Date
                                </div>

                                <div class="info-value">

                                    <?php

                                    if (!empty($patient['birth_date'])) {
                                        echo dentistPatientEscape(
                                            date(
                                                'F d, Y',
                                                strtotime($patient['birth_date'])
                                            )
                                        );
                                    } else {
                                        echo '—';
                                    }

                                    ?>

                                </div>

                            </div>


                            <div class="info-item">

                                <div class="info-label">
                                    Contact Number
                                </div>

                                <div class="info-value">
                                    <?= dentistPatientEscape($patient['contact_number']) ?: '—' ?>
                                </div>

                            </div>


                            <div class="info-item">

                                <div class="info-label">
                                    Email
                                </div>

                                <div class="info-value">
                                    <?= dentistPatientEscape($patient['email']) ?: '—' ?>
                                </div>

                            </div>


                            <div class="info-item">

                                <div class="info-label">
                                    Address
                                </div>

                                <div class="info-value">
                                    <?= dentistPatientEscape($patient['address']) ?: '—' ?>
                                </div>

                            </div>

                        </div>

                    </section>


                    <!-- APPOINTMENT INFORMATION -->

                    <section class="portal-card">

                        <h2>
                            Appointment Information
                        </h2>

                        <?php if ($nextAppointment): ?>

                            <div class="appointment-highlight">

                                <h3>
                                    Next Appointment
                                </h3>

                                <div class="appointment-date">

                                    <?= dentistPatientEscape(
                                        date(
                                            'F d, Y',
                                            strtotime($nextAppointment['appointment_date'])
                                        )
                                    ) ?>

                                    <br>

                                    <?= dentistPatientEscape(
                                        date(
                                            'h:i A',
                                            strtotime($nextAppointment['start_time'])
                                        )
                                    ) ?>

                                    -
                                    <?= dentistPatientEscape(
                                        date(
                                            'h:i A',
                                            strtotime($nextAppointment['end_time'])
                                        )
                                    ) ?>

                                </div>


                                <div class="appointment-service">

                                    <strong>
                                        Service:
                                    </strong>

                                    <?= dentistPatientEscape(
                                        $nextAppointment['service_type']
                                    ) ?>

                                </div>


                                <br>


                                <span
                                    class="status-badge
                                    <?php
                                    $nextStatus = strtolower(
                                        trim($nextAppointment['status'])
                                    );

                                    if ($nextStatus === 'pending') {
                                        echo 'status-pending';
                                    } elseif ($nextStatus === 'confirmed') {
                                        echo 'status-confirmed';
                                    } elseif ($nextStatus === 'completed') {
                                        echo 'status-completed';
                                    } elseif ($nextStatus === 'cancelled') {
                                        echo 'status-cancelled';
                                    } else {
                                        echo 'status-default';
                                    }
                                    ?>"
                                >
                                    <?= dentistPatientEscape(
                                        $nextAppointment['status']
                                    ) ?>
                                </span>

                            </div>

                        <?php else: ?>

                            <div class="empty-history">
                                <i class="bi bi-calendar-x"></i>
                                <br><br>
                                No upcoming appointment.
                            </div>

                        <?php endif; ?>

                    </section>

                </div>


                <!-- APPOINTMENT HISTORY -->

                <section class="portal-card">

                    <h2>
                        Appointment History
                    </h2>

                    <?php if (empty($appointmentHistory)): ?>

                        <div class="empty-history">
                            No appointment history found.
                        </div>

                    <?php else: ?>

                        <div class="table-wrap">

                            <table class="portal-table">

                                <thead>

                                    <tr>

                                        <th>
                                            Date
                                        </th>

                                        <th>
                                            Time
                                        </th>

                                        <th>
                                            Service
                                        </th>

                                        <th>
                                            Dentist
                                        </th>

                                        <th>
                                            Status
                                        </th>

                                        <th>
                                            Notes
                                        </th>

                                    </tr>

                                </thead>


                                <tbody>

                                    <?php foreach (
                                        $appointmentHistory
                                        as $appointment
                                    ): ?>

                                        <?php

                                        $status = strtolower(
                                            trim($appointment['status'])
                                        );

                                        $statusClass = 'status-default';

                                        if ($status === 'pending') {
                                            $statusClass = 'status-pending';
                                        } elseif ($status === 'confirmed') {
                                            $statusClass = 'status-confirmed';
                                        } elseif ($status === 'completed') {
                                            $statusClass = 'status-completed';
                                        } elseif ($status === 'cancelled') {
                                            $statusClass = 'status-cancelled';
                                        }

                                        ?>

                                        <tr>

                                            <td>

                                                <?= dentistPatientEscape(
                                                    date(
                                                        'M d, Y',
                                                        strtotime(
                                                            $appointment['appointment_date']
                                                        )
                                                    )
                                                ) ?>

                                            </td>


                                            <td>

                                                <?= dentistPatientEscape(
                                                    date(
                                                        'h:i A',
                                                        strtotime(
                                                            $appointment['start_time']
                                                        )
                                                    )
                                                ) ?>

                                                -

                                                <?= dentistPatientEscape(
                                                    date(
                                                        'h:i A',
                                                        strtotime(
                                                            $appointment['end_time']
                                                        )
                                                    )
                                                ) ?>

                                            </td>


                                            <td>

                                                <?= dentistPatientEscape(
                                                    $appointment['service_type']
                                                ) ?>

                                            </td>


                                            <td>

                                                <?= dentistPatientEscape(
                                                    $appointment['dentist_name']
                                                ) ?: '—' ?>

                                            </td>


                                            <td>

                                                <span
                                                    class="status-badge <?= $statusClass ?>"
                                                >
                                                    <?= dentistPatientEscape(
                                                        $appointment['status']
                                                    ) ?>
                                                </span>

                                            </td>


                                            <td>

                                                <?= dentistPatientEscape(
                                                    $appointment['notes']
                                                ) ?: '—' ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </section>


            </div>

        </main>

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

    <?php

    exit;
}


/*
|--------------------------------------------------------------------------
| PATIENT LIST
|--------------------------------------------------------------------------
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

/*
 * Base ng WHERE para sa COUNT(*). Ito ay parehong FROM/WHERE ng listahan
 * ngunit walang SELECT list (walang ang 7 subquery) at walang ORDER BY.
 * Kaya isang param lang ang kailangan — ang EXISTS sa dulo.
 */
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


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

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

    $filterParams[] = $searchValue;
    $filterParams[] = $searchValue;
    $filterParams[] = $searchValue;
    $filterParams[] = $searchValue;
    $filterParams[] = $searchValue;
    $filterParams[] = $searchValue;
    $filterParams[] = $searchValue;
}


/*
|--------------------------------------------------------------------------
| FILTER BY APPOINTMENT STATUS
|--------------------------------------------------------------------------
*/

if ($statusFilter !== '') {

    /*
     * Dati diretsong idinidikit sa $sql, kaya hindi ito makikita ng COUNT(*).
     * Inilipat ko sa $filterSql para pareho ang ginamit ng listahan at ng
     * bilang — kung hindi, magmamismatch ang "Showing X of Y" sa listahan.
     */
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


/*
|--------------------------------------------------------------------------
| ORDER
|--------------------------------------------------------------------------
*/

/*
 * Narito na natatag ang $filterSql (search + status filter). Dati ito ay
 * binubuo pero KAILANMAN hindi nakakabit dito — kaya ang search box ay
 * patay. Nakakabit ko bago ang ORDER BY dahil sa AFTER ng WHERE clause.
 */
$sql .= $filterSql;

$params = array_merge($params, $filterParams);

$sql .= "
    ORDER BY
        p.last_name ASC,
        p.first_name ASC
";


/*
|--------------------------------------------------------------------------
| TOTAL PATIENTS
|--------------------------------------------------------------------------
|
| Hiwalay na COUNT(*) para sa kabuuang bilang ("Showing X of Y") at para
| malaman kung ilang page ang mayroon.
|
*/

/*
 * Kabuuang bilang. ANG FROM/WHERE NAKAALAMAN SA $filterSqlBase — kaya
 * HUWAG na isulat muli ang FROM patients dito, kung hindi magiging dalawang
 * FROM clause ang makarating sa DB at mag-error ang query.
 *
 * $countParams (1 param) + $filterParams = parehong binding ng listahan.
 */
$countSql = "
    SELECT COUNT(*)
    $filterSqlBase
    $filterSql
";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute(array_merge($countParams, $filterParams));
$patientsTotal = (int) $countStmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| PAGINATION (10 patients kada page)
|--------------------------------------------------------------------------
|
| Dati: buong listahan ang kinukuha at ang scrollbar/slide ang naghahandle.
| Ngayon: LIMIT/OFFSET sa SQL — 10 rows kada page.
|
| NOTE: dapat ito ay AFTER ng COUNT(*) dahil kailangan ang $patientsTotal
| para malaman ang kabuuang bilang ng page.
|
*/

$perPage = 10;

$totalPages = max(1, (int) ceil($patientsTotal / $perPage));

$page = (int) ($_GET['page'] ?? 1);

if ($page < 1) {
    $page = 1;
}

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

/* Integer na ginamit — walang user input na dumadaan dito, kaya safe sa SQL. */
$sql .= " LIMIT " . (int) $perPage . " OFFSET " . (int) $offset . " ";


/*
 * URL ng ibang page — pinapanatili ang search, status filter, at nosidebar.
 */
function patientPageUrl($targetPage)
{
    $query = $_GET;

    $query['page'] = max(1, (int) $targetPage);

    return 'patients.php?' . http_build_query($query);
}

$showingFrom = $patientsTotal > 0 ? $offset + 1 : 0;
$showingTo   = min($offset + $perPage, $patientsTotal);



/*
|--------------------------------------------------------------------------
| EXECUTE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Patients | Dentist Portal</title>


    <!-- Bootstrap Icons -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- Google Fonts -->

    <link
        rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
    >


    <!-- Portal CSS -->

    <link
        rel="stylesheet"
        href="portal.css?v=5"
    >

    <!-- DENTIST SIDEBAR (dashboard parity) -->
    <link rel="stylesheet" href="dentist_sidebar.css">


    <style>

        .patient-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .patient-search {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            max-width: 550px;
        }

    .patient-search input,
    .patient-search select {
            border: 1px solid #dce5ed;
            border-radius: 8px;
            padding: 10px 12px;
            font-family: inherit;
            background: #fff;
            outline: none;
        }

        .patient-search input {
            flex: 1;
        }

        .patient-search input:focus,
        .patient-search select:focus {
            border-color: #65a9d9;
        }

        .search-btn {
            border: 0;
            border-radius: 8px;
            padding: 10px 16px;
            background: #3b82c4;
            color: #fff;
            cursor: pointer;
            font-weight: 600;
        }

        .search-btn:hover {
            opacity: 0.9;
        }

        .clear-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            padding: 10px 13px;
            border-radius: 8px;
            border: 1px solid #dce5ed;
            color: #566575;
            background: #fff;
            font-weight: 600;
        }

        .view-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            padding: 7px 12px;
            border-radius: 7px;
            background: #e8f4ff;
            color: #2374b8;
            font-size: 13px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            font-family: inherit;
        }

        .view-btn:hover {
            background: #d9edfc;
        }

        /* Patient table polish: darker text, sticky header, zebra, hover */
        .portal-table th{color:#102f3d !important}
        .portal-table td{color:#1f3a4a !important}
        .portal-table td .patient-id,.portal-table td small{color:#3f5a67 !important}
        .portal-table thead th{position:sticky;top:0;z-index:2;background:#fff}
        .portal-table tbody tr:nth-child(even) td{background:#F7FDFB}
        .portal-table tbody tr:hover td{background:#EAF7F0}
        .patient-name{color:#102f3d !important;font-weight:800 !important}

        .edit-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            padding: 7px 12px;
            border: none;
            border-radius: 7px;
            background: #eef7ee;
            color: #18753c;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .edit-btn:hover {
            background: #dcf0dc;
        }

        .action-cell {
            white-space: nowrap;
        }

        .action-wrap {
            display: inline-flex;
            align-items: center;
            gap: 8px;
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
            gap: 4px;
            padding: 7px 10px;
            border-radius: 7px;
            background: #eef1f5;
            color: #536273;
            font-size: 13px;
            font-weight: 700;
            user-select: none;
        }

        .more-wrap summary::-webkit-details-marker {
            display: none;
        }

        .more-wrap summary:hover {
            background: #e0e6ec;
        }

        .more-wrap[open] summary {
            background: #dbe3ea;
        }

        .more-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 6px);
            min-width: 230px;
            background: #fff;
            border: 1px solid #dce5ed;
            border-radius: 10px;
            box-shadow: 0 12px 30px rgba(0,0,0,.12);
            padding: 6px;
            z-index: 50;
        }

        .more-menu a,
        .more-menu .more-info {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 10px;
            border-radius: 7px;
            font-size: 13px;
            color: #34495e;
            text-decoration: none;
            font-weight: 600;
        }

        .more-menu a:hover {
            background: #f2f7fb;
        }

        .more-menu .more-info {
            color: #7a8998;
            font-weight: 600;
            cursor: default;
        }

        .more-menu .more-divider {
            height: 1px;
            background: #edf1f5;
            margin: 5px 4px;
        }

        .patient-email {
            word-break: break-all;
            color: #34495e;
        }

        .row-num {
            color: #7a8998;
            font-weight: 700;
        }

        .patient-name {
            font-weight: 700;
            color: #253746;
        }

        .patient-id {
            display: block;
            color: #8795a3;
            font-size: 11px;
            margin-top: 3px;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-pending {
            background: #fff4d6;
            color: #9a6700;
        }

        .status-confirmed {
            background: #e1f5e8;
            color: #18753c;
        }

        .status-completed {
            background: #e5eef9;
            color: #285c91;
        }

        .status-cancelled {
            background: #fde8e8;
            color: #b42318;
        }

        .status-default {
            background: #edf1f5;
            color: #536273;
        }

        .next-date {
            font-weight: 600;
        }

        .next-time {
            color: #7a8998;
            font-size: 12px;
            margin-top: 2px;
        }

        .empty-patients {
            text-align: center;
            padding: 45px 20px;
            color: #7a8998;
        }

        .empty-patients i {
            font-size: 36px;
            display: block;
            margin-bottom: 10px;
        }

        @media (max-width: 800px) {

            .patient-search {
                width: 100%;
                max-width: none;
                flex-wrap: wrap;
            }

            .patient-search input {
                min-width: 180px;
            }

        }

        /* ===== PAGINATION (10 patients kada page) ===== */
        .patient-pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 6px;
            padding: 14px 16px;
            border-top: 1px solid #E6F2EC;
            background: #F6FBF8;
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
            font-family: "Inter", system-ui, sans-serif;
            font-size: 12.5px;
            font-weight: 800;
            text-decoration: none;
        }
        .patient-page-btn:hover:not(.disabled):not(.active) { background: #EAF7F0; }
        .patient-page-btn.active { background: #0E6B5E; border-color: #0E6B5E; color: #ffffff; }
        .patient-page-btn.disabled { opacity: .45; cursor: default; }

        /* Green scrollbar slide: kaladkarin pataas/pababa (tulad sa image) */
        .patient-table-scroll {
            overflow: auto;
            max-height: 620px;
            scrollbar-width: thin;
            scrollbar-color: #149A72 #DFF2E9;
        }
        .patient-table-scroll::-webkit-scrollbar {
            width: 10px;
        }
        .patient-table-scroll::-webkit-scrollbar-track {
            background: #DFF2E9;
            border-radius: 8px;
        }
        .patient-table-scroll::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #149A72, #0A4A41);
            border-radius: 8px;
        }
        .patient-table-scroll::-webkit-scrollbar-thumb:hover {
            background: #0E6B5E;
        }
        /* TOPBAR date + time (tulad sa appointment page) */
        .topbar-today {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #244f60;
            border-left: none;
            padding-left: 0;
        }
        .topbar-today i { font-size: 22px; }
        .topbar-today strong { display: block; font-size: 13px; font-weight: 800; line-height: 1.2; }
        .topbar-today small { display: block; font-size: 11px; opacity: .85; font-weight: 600; }
        .topbar-time {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #244f60;
            border-left: 1px solid #e8eef2;
            padding-left: 16px;
        }
        .topbar-time i { font-size: 22px; }
        .topbar-time strong { display: block; font-size: 13px; font-weight: 800; line-height: 1.2; }
        .topbar-time small { display: block; font-size: 11px; opacity: .85; font-weight: 600; font-variant-numeric: tabular-nums; }

    </style>
<style>
/* ===== READABILITY + POLISH (patients page only, override muted text) ===== */
.portal-subtitle{color:#334554 !important;font-weight:600 !important}
.portal-main small{color:#3f5a67 !important}
.portal-main [style*="#8499a1"]{color:#334554 !important}
.portal-main [style*="#8ca0a8"]{color:#3f5a67 !important}
.portal-main [style*="#7f9aa4"]{color:#334554 !important}
.portal-main [style*="#7b94a0"]{color:#334554 !important}
.portal-main [style*="#91a5ac"]{color:#3f5a67 !important}
.portal-main [style*="#6b8a96"]{color:#334554 !important}
.portal-heading{color:#102f3d !important}
.portal-main table{border-collapse:collapse !important;width:100% !important;background:#fff !important;border-radius:14px !important;overflow:hidden !important;box-shadow:0 8px 24px rgba(14,107,94,.08) !important}
.portal-main table th{color:#102f3d !important;font-weight:800 !important;background:#EAF7F0 !important;padding:12px 14px !important;text-align:left !important;white-space:nowrap !important}
.portal-main table td{color:#1f3a4a !important;font-weight:600 !important;padding:12px 14px !important;border-bottom:1px solid #E6F2EC !important}
.portal-main table tbody tr:nth-child(even) td{background:#F7FDFB !important}
.portal-main table tbody tr:hover td{background:#EAF7F0 !important}
.portal-main .card,.portal-main table{background:#fff !important}
.portal-main a{color:#0E6B5E}
</style>


</head>


<body>

<style>
body{background:linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22)!important;background-size:300% 300%!important;animation:dentistDashBg 8s ease infinite!important;}
.portal-main{background:transparent!important;}
@keyframes dentistDashBg{0%{background-position:0% 50%;}50%{background-position:100% 50%;}100%{background-position:0% 50%;}}
</style>

    <!-- SIDEBAR -->

    <?php
$dentistActivePage = 'patients';
include __DIR__ . '/dentist_sidebar.php';
?>


    <!-- MAIN -->

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
        </style>

        <!-- MINI CLINIC BAR (gaya sa appointment page) -->

        <div class="clinic-minibar">
            🦷 <span>Abalos Dental Clinic</span>
        </div>


        <!-- TOPBAR -->

        <header class="portal-topbar">

            <div>

                <div class="portal-heading">
                    Patients
                </div>

                <div class="portal-subtitle">
                    Patients with appointments assigned to you
                </div>

            </div>


            <div style="display:flex;align-items:center;gap:16px;">

                <div class="topbar-today">

                    <i class="bi bi-calendar-event-fill"></i>

                    <div>
                        <strong><?= dentistPatientEscape(date('l')) ?></strong>
                        <small><?= dentistPatientEscape(date('M d, Y')) ?></small>
                    </div>

                </div>


                <div class="topbar-time">

                    <i class="bi bi-clock-fill"></i>

                    <div>
                        <strong>Clinic Time</strong>
                        <small id="patientListClinicTime">--:--:--</small>
                    </div>

                </div>

            </div>

        </header>

        <script>
        (function () {
            function tickPatientListClinicTime() {
                try {
                    var el = document.getElementById('patientListClinicTime');
                    if (!el) return;
                    var now = new Date();
                    var timeStr = now.toLocaleTimeString('en-PH', {
                        timeZone: 'Asia/Manila',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: true
                    });
                    el.textContent = timeStr;
                } catch (e) {}
            }
            tickPatientListClinicTime();
            setInterval(tickPatientListClinicTime, 1000);
        })();
        </script>


        <!-- CONTENT -->

        <div class="portal-content">

            <?php if ($editFlash !== ''): ?>
                <div class="edit-alert edit-alert-success">
                    <i class="bi bi-check-circle-fill"></i>
                    <?= dentistPatientEscape($editFlash) ?>
                </div>
            <?php endif; ?>

            <?php if ($editError !== ''): ?>
                <div class="edit-alert edit-alert-error">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?= dentistPatientEscape($editError) ?>
                </div>
            <?php endif; ?>

            <section class="portal-card">


                <!-- TOOLBAR -->

                <div class="patient-toolbar">


                    <form
                        method="GET"
                        action="patients.php"
                        class="patient-search"
                    >


                        <!-- SEARCH -->

                        <input
                            type="text"
                            name="search"
                            placeholder="Search Patient"
                            value="<?= dentistPatientEscape($search) ?>"
                        >


                        <!-- FILTER -->

                        <select name="status">

                            <option value="">
                                All Status
                            </option>

                            <option
                                value="Pending"
                                <?= $statusFilter === 'Pending' ? 'selected' : '' ?>
                            >
                                Pending
                            </option>

                            <option
                                value="Confirmed"
                                <?= $statusFilter === 'Confirmed' ? 'selected' : '' ?>
                            >
                                Confirmed
                            </option>

                            <option
                                value="Completed"
                                <?= $statusFilter === 'Completed' ? 'selected' : '' ?>
                            >
                                Completed
                            </option>

                            <option
                                value="Cancelled"
                                <?= $statusFilter === 'Cancelled' ? 'selected' : '' ?>
                            >
                                Cancelled
                            </option>

                        </select>


                        <button
                            type="submit"
                            class="search-btn"
                        >

                            <i class="bi bi-search"></i>

                            Search

                        </button>


                        <?php if (
                            $search !== '' ||
                            $statusFilter !== ''
                        ): ?>

                            <a
                                href="patients.php"
                                class="clear-btn"
                            >

                                <i class="bi bi-x-lg"></i>

                                Clear

                            </a>

                        <?php endif; ?>


                    </form>

                </div>


                <!-- PATIENT TABLE -->

                <h2>
                    Patient Information
                </h2>


                <?php if (empty($patients)): ?>


                    <div class="empty-patients">

                        <i class="bi bi-people"></i>

                        <strong>
                            No patients found
                        </strong>

                        <div>
                            No patients match your search or filter.
                        </div>

                    </div>


                <?php else: ?>


                    <div class="patient-table-scroll" id="patientTableWrap">

                        <table class="portal-table">


                            <thead>

                                <tr>

                                    <th>
                                        #
                                    </th>

                                    <th>
                                        Patient Name
                                    </th>

                                    <th>
                                        Contact Number
                                    </th>

                                    <th>
                                        Email
                                    </th>

                                    <th>
                                        Assigned Dentist
                                    </th>

                                    <th>
                                        Status
                                    </th>

                                    <th>
                                        Action
                                    </th>

                                </tr>

                            </thead>


                            <tbody>


                                <?php $rowNum = $offset; foreach ($patients as $patient): $rowNum++; ?>

                                    <?php

                                    $fullName = trim(
                                        $patient['first_name'] . ' ' .
                                        ($patient['middle_name'] ?? '') . ' ' .
                                        $patient['last_name']
                                    );

                                    $patientStatus = $patient['next_status'] ?? '';

                                    $statusLower = strtolower(
                                        trim($patientStatus)
                                    );

                                    $statusClass = 'status-default';

                                    if ($statusLower === 'pending') {
                                        $statusClass = 'status-pending';
                                    } elseif ($statusLower === 'confirmed') {
                                        $statusClass = 'status-confirmed';
                                    } elseif ($statusLower === 'completed') {
                                        $statusClass = 'status-completed';
                                    } elseif ($statusLower === 'cancelled') {
                                        $statusClass = 'status-cancelled';
                                    }

                                    $nextDateLabel = !empty($patient['next_appointment_date'])
                                        ? date('M d, Y', strtotime($patient['next_appointment_date']))
                                        : '';
                                    $nextTimeLabel = !empty($patient['next_appointment_time'])
                                        ? date('h:i A', strtotime($patient['next_appointment_time']))
                                        : '';
                                    $apptCountLabel = (int) ($patient['appointment_count'] ?? 0);

                                    ?>


                                    <tr>


                                        <!-- # -->

                                        <td>
                                            <span class="row-num"><?= $rowNum ?></span>
                                        </td>


                                        <!-- NAME -->

                                        <td>

                                            <div class="patient-name">

                                                <?= dentistPatientEscape(
                                                    $fullName
                                                ) ?>

                                            </div>

                                            <span class="patient-id">

                                                <?= dentistPatientEscape(
                                                    $patient['patient_number']
                                                ) ?>

                                            </span>

                                        </td>


                                        <!-- CONTACT -->

                                        <td>

                                            <?= dentistPatientEscape(
                                                $patient['contact_number']
                                            ) ?: '—' ?>

                                        </td>


                                        <!-- EMAIL -->

                                        <td>

                                            <span class="patient-email">
                                                <?= dentistPatientEscape(
                                                    $patient['email'] ?? ''
                                                ) ?: '—' ?>
                                            </span>

                                        </td>


                                        <!-- ASSIGNED DENTIST -->

                                        <td>

                                            <?= dentistPatientEscape(
                                                $patient['assigned_dentists'] ?? ''
                                            ) ?: '—' ?>

                                        </td>


                                        <!-- STATUS -->

                                        <td>


                                            <?php if (
                                                !empty($patientStatus)
                                            ): ?>

                                                <span
                                                    class="status-badge <?= $statusClass ?>"
                                                >

                                                    <?= dentistPatientEscape(
                                                        $patientStatus
                                                    ) ?>

                                                </span>

                                            <?php else: ?>

                                                <span
                                                    class="status-badge status-default"
                                                >
                                                    No Upcoming Appointment
                                                </span>

                                            <?php endif; ?>


                                        </td>


                                        <!-- ACTION -->

                                        <td class="action-cell">

                                            <div class="action-wrap">

                                                <button
                                                    type="button"
                                                    class="view-btn patient-view-btn"
                                                    title="View patient details"
                                                    data-patient="<?= dentistPatientEscape($fullName) ?>"
                                                    data-service="<?= dentistPatientEscape($patient['next_service'] ?? '') ?>"
                                                    data-dentist="<?= dentistPatientEscape($patient['assigned_dentists'] ?? '') ?>"
                                                    data-time="<?= dentistPatientEscape(!empty($patient['next_appointment_date']) ? date('M d, Y', strtotime($patient['next_appointment_date'])) . (!empty($patient['next_appointment_time']) ? ' ' . date('h:i A', strtotime($patient['next_appointment_time'])) . (!empty($patient['next_end_time']) ? ' - ' . date('h:i A', strtotime($patient['next_end_time'])) : '') : '') : '—') ?>"
                                                    data-booked="<?= dentistPatientEscape(!empty($patient['next_created']) ? date('M d, Y h:i A', strtotime($patient['next_created'])) : '—') ?>"
                                                >

                                                    <i class="bi bi-eye-fill"></i>

                                                    View

                                                </button>

                                                <button
                                                    type="button"
                                                    class="edit-btn patient-edit-btn"
                                                    title="Edit patient information"
                                                    data-id="<?= (int) $patient['id'] ?>"
                                                    data-patient-id="<?= dentistPatientEscape($patient['patient_number']) ?>"
                                                    data-first="<?= dentistPatientEscape($patient['first_name']) ?>"
                                                    data-middle="<?= dentistPatientEscape($patient['middle_name'] ?? '') ?>"
                                                    data-last="<?= dentistPatientEscape($patient['last_name']) ?>"
                                                    data-contact="<?= dentistPatientEscape($patient['contact_number'] ?? '') ?>"
                                                    data-email="<?= dentistPatientEscape($patient['email'] ?? '') ?>"
                                                >

                                                    <i class="bi bi-pencil-square"></i>

                                                    Edit

                                                </button>

                                                <details class="more-wrap">
                                                    <summary title="More actions">
                                                        <i class="bi bi-three-dots"></i>
                                                    </summary>
                                                    <div class="more-menu">
                                                        <a href="patients.php?patient_id=<?= (int)$patient['id'] ?>">
                                                            <i class="bi bi-clock-history"></i>
                                                            Appointment History
                                                        </a>
                                                        <a href="appointments.php">
                                                            <i class="bi bi-calendar2-check"></i>
                                                            View Appointments
                                                        </a>
                                                        <div class="more-divider"></div>
                                                        <div class="more-info">
                                                            <i class="bi bi-hash"></i>
                                                            <?= dentistPatientEscape($patient['patient_number']) ?>
                                                            · <?= $apptCountLabel ?> appointment<?= $apptCountLabel == 1 ? '' : 's' ?>
                                                        </div>
                                                        <?php if ($nextDateLabel !== ''): ?>
                                                            <div class="more-info">
                                                                <i class="bi bi-calendar-event"></i>
                                                                Next: <?= dentistPatientEscape($nextDateLabel) ?><?= $nextTimeLabel !== '' ? ' · ' . dentistPatientEscape($nextTimeLabel) : '' ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="more-info">
                                                                <i class="bi bi-calendar-x"></i>
                                                                No upcoming appointment
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </details>

                                            </div>

                                        </td>


                                    </tr>


                                <?php endforeach; ?>


                            </tbody>


                        </table>

                    </div>

                    <?php if ($totalPages > 1): ?>
                        <nav class="patient-pagination" aria-label="Patient pages">
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
                                <?php else: ?>
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
                        </nav>
                    <?php endif; ?>

                    <script>
                    /* Isang More dropdown lang ang bukas */
                    (function () {
                        document.addEventListener('click', function (e) {
                            document.querySelectorAll('details.more-wrap[open]').forEach(function (d) {
                                if (!d.contains(e.target)) d.removeAttribute('open');
                            });
                        });
                        document.addEventListener('toggle', function (e) {
                            if (e.target && e.target.matches && e.target.matches('details.more-wrap') && e.target.open) {
                                document.querySelectorAll('details.more-wrap[open]').forEach(function (d) {
                                    if (d !== e.target) d.removeAttribute('open');
                                });
                            }
                        }, true);
                    })();
                    </script>

                    <script>
                    /* Eksaktong 10 rows ang kita sa scroll box (header + 10 rows) */
                    (function () {
                        var wrap = document.getElementById('patientTableWrap');
                        if (!wrap) return;

                        function fit() {
                            var head = wrap.querySelector('.portal-table thead');
                            var firstRow = wrap.querySelector('.portal-table tbody tr');
                            if (!head || !firstRow) return;
                            var headH = head.getBoundingClientRect().height;
                            var rowH = firstRow.getBoundingClientRect().height;
                            if (rowH > 0) {
                                wrap.style.maxHeight = Math.ceil(headH + (rowH * 10) + 2) + 'px';
                            }
                        }

                        fit();
                        window.addEventListener('load', fit);
                        window.addEventListener('resize', fit);
                        if (document.fonts && document.fonts.ready) {
                            document.fonts.ready.then(function () { fit(); });
                        }
                    })();
                    </script>


                <?php endif; ?>


            </section>


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

<style>
.pview-overlay{position:fixed;inset:0;background:rgba(10,59,52,.55);z-index:2000;padding:20px;display:none;align-items:center;justify-content:center}
.pview-overlay.open{display:flex}
.pview-box{background:#fff;border-radius:18px;max-width:440px;width:100%;overflow:hidden;box-shadow:0 18px 45px rgba(0,0,0,.25);animation:pviewPop .18s ease}
@keyframes pviewPop{from{transform:scale(.96);opacity:0}to{transform:scale(1);opacity:1}}
.pview-head{padding:18px 22px;background:linear-gradient(135deg,#149A72,#0A4A41);border-bottom:none;display:flex;align-items:center;gap:10px}
.pview-head h5{margin:0;flex:1;color:#fff;font-weight:800;font-size:16px}
.pview-head h5 i{color:#C9E9DA}
.pview-x{background:#fff;border:1px solid #D1E7DD;color:#57727d;width:30px;height:30px;border-radius:9px;cursor:pointer;font-size:13px}
.pview-x:hover{background:#EAF7F0;color:#0E6B5E}
.pview-body{padding:18px 22px 20px;font-size:13.5px;color:#4d6873}
.pview-body .row-line{margin-bottom:12px;padding-bottom:12px;border-bottom:1px dashed #D6E7E1}
.pview-body .row-line:last-child{margin-bottom:0;padding-bottom:0;border-bottom:none}
.pview-body .row-line strong{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#143f51;margin-bottom:3px}
</style>

<!-- PATIENT VIEW MODAL: gaya sa appointment View -->
<div class="pview-overlay" id="pviewOverlay">
    <div class="pview-box">
        <div class="pview-head">
            <h5><i class="bi bi-eye-fill"></i> Patient Details</h5>
            <button type="button" class="pview-x" id="pviewClose" aria-label="Close">✕</button>
        </div>
        <div class="pview-body">
            <div class="row-line"><strong>Patient name:</strong> <span id="pviewPatient"></span></div>
            <div class="row-line"><strong>Dental service:</strong> <span id="pviewService"></span></div>
            <div class="row-line"><strong>Assigned dentist:</strong> <span id="pviewDentist"></span></div>
            <div class="row-line"><strong>Date and time of appointment:</strong> <span id="pviewTime"></span></div>
            <div class="row-line"><strong>Booked on:</strong> <span id="pviewBooked"></span></div>
        </div>
    </div>
</div>

<script>
/* Patient View modal: gaya sa appointment View */
(function(){
    var overlay = document.getElementById('pviewOverlay');
    if(!overlay) return;
    function close(){ overlay.classList.remove('open'); }
    document.getElementById('pviewClose').addEventListener('click', close);
    overlay.addEventListener('click', function(e){ if(e.target === overlay) close(); });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape') close(); });
    document.querySelectorAll('.patient-view-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            document.getElementById('pviewPatient').textContent = btn.dataset.patient || '';
            document.getElementById('pviewService').textContent = btn.dataset.service || '—';
            document.getElementById('pviewDentist').textContent = btn.dataset.dentist || '';
            document.getElementById('pviewTime').textContent = btn.dataset.time || '—';
            document.getElementById('pviewBooked').textContent = btn.dataset.booked || '—';
            overlay.classList.add('open');
        });
    });
})();
</script>

<style>
/* Alert banners sa patient list (pagkatapos mag-save / may error) */
.edit-alert{display:flex;align-items:center;gap:9px;margin:0 0 16px;padding:12px 16px;border-radius:11px;font-size:13.5px;font-weight:700}
.edit-alert-success{background:#EAF7F0;border:1px solid #C9E9DA;color:#0E6B5E}
.edit-alert-error{background:#FDECEC;border:1px solid #F5C6C6;color:#C0392B}

/* EDIT PATIENT MODAL */
.pedit-overlay{position:fixed;inset:0;background:rgba(10,59,52,.55);z-index:2000;padding:20px;display:none;align-items:center;justify-content:center}
.pedit-overlay.open{display:flex}
.pedit-box{background:#fff;border-radius:18px;max-width:520px;width:100%;overflow:hidden;box-shadow:0 18px 45px rgba(0,0,0,.25);animation:peditPop .18s ease;max-height:92vh;overflow-y:auto}
@keyframes peditPop{from{transform:scale(.96);opacity:0}to{transform:scale(1);opacity:1}}
.pedit-head{padding:18px 22px;background:linear-gradient(135deg,#149A72,#0A4A41);border-bottom:none;display:flex;align-items:center;gap:10px}
.pedit-head h5{margin:0;flex:1;color:#fff;font-weight:800;font-size:16px}
.pedit-head h5 i{color:#C9E9DA}
.pedit-x{background:#fff;border:1px solid #D1E7DD;color:#57727d;width:30px;height:30px;border-radius:9px;cursor:pointer;font-size:13px}
.pedit-x:hover{background:#EAF7F0;color:#0E6B5E}
.pedit-body{padding:18px 22px 6px}
.pedit-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 14px}
.pedit-field{display:flex;flex-direction:column;gap:5px}
.pedit-field.full{grid-column:1 / -1}
.pedit-field label{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#143f51}
.pedit-field label span{color:#C0392B}
.pedit-field input{width:100%;border:1px solid #D1E7DD;border-radius:9px;padding:10px 11px;font-family:inherit;font-size:13.5px;color:#244f60;background:#fff}
.pedit-field input:focus{outline:none;border-color:#149A72;box-shadow:0 0 0 3px rgba(20,154,114,.14)}
.pedit-field input:disabled{background:#F1F5F4;color:#57727d;font-weight:700;cursor:not-allowed}
.pedit-note{font-size:11.5px;color:#57727d;margin:12px 0 0}
.pedit-foot{display:flex;justify-content:flex-end;gap:10px;padding:14px 22px 20px}
.pedit-cancel{background:#fff;border:1px solid #D1E7DD;border-radius:10px;font-weight:700;font-family:inherit;font-size:13.5px;padding:10px 22px;cursor:pointer;color:#57727d}
.pedit-cancel:hover{background:#EAF7F0;color:#0E6B5E}
.pedit-save{background:linear-gradient(135deg,#149A72,#0A4A41);border:none;border-radius:10px;font-weight:800;font-family:inherit;font-size:13.5px;padding:10px 22px;cursor:pointer;color:#fff;box-shadow:0 6px 14px rgba(14,107,94,.30)}
.pedit-save:hover{filter:brightness(1.06)}
</style>

<!-- EDIT PATIENT MODAL: Patient ID (read-only) + pangalan + contact + email -->
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
                        <label for="peditPatientNumber">Patient ID</label>
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
                        <label for="peditEmail">Email</label>
                        <input type="email" id="peditEmail" name="email" maxlength="255" value="">
                    </div>

                </div>

                <p class="pedit-note">
                    <i class="bi bi-lock-fill"></i>
                    Ang Patient ID ay hindi pwedeng i-edit.
                </p>
            </div>

            <div class="pedit-foot">
                <button type="button" class="pedit-cancel" id="peditCancel">Cancel</button>
                <button type="submit" class="pedit-save">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
/* Edit Patient modal: pinupuno mula sa pinindot na Edit button */
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

    document.getElementById('peditClose').addEventListener('click', close);
    document.getElementById('peditCancel').addEventListener('click', close);
    overlay.addEventListener('click', function(e){ if(e.target === overlay) close(); });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape') close(); });

    /* Panatilihin ang search/filter pagkatapos mag-save */
    function buildAction(){
        form.setAttribute('action', 'patients.php' + (window.location.search || ''));
    }

    document.querySelectorAll('.patient-edit-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            buildAction();
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
</script>


</body>

</html>
