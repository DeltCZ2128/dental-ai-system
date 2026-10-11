<?php

require_once __DIR__ . '/../config.php';

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if (($_SESSION['role'] ?? '') !== 'patient') {
    die("Access denied.");
}

/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function concern_e($value)
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| CREATE / MIGRATE APPOINTMENT CONCERNS TABLE
|--------------------------------------------------------------------------
| appointment_id ginawang NULLABLE para pwede ang General Concern
| kahit walang active appointment si patient.
|--------------------------------------------------------------------------
*/

try {

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS appointment_concerns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            appointment_id INT NULL,
            patient_id INT NOT NULL,
            reason VARCHAR(255) NOT NULL,
            message TEXT NULL,
            status ENUM('New','Reviewed') NOT NULL DEFAULT 'New',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_appointment_id (appointment_id),
            INDEX idx_patient_id (patient_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Migration para sa mga lumang table na NOT NULL ang appointment_id
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM appointment_concerns LIKE 'appointment_id'");
        $col = $colStmt->fetch(PDO::FETCH_ASSOC);
        if ($col && stripos($col['Null'] ?? '', 'NO') !== false) {
            $pdo->exec("ALTER TABLE appointment_concerns MODIFY appointment_id INT NULL");
        }
    } catch (Throwable $e) {
    }

} catch (PDOException $e) {

    die("Unable to initialize appointment concern table.");

}

/*
|--------------------------------------------------------------------------
| CREATE ADMIN NOTIFICATIONS TABLE
|--------------------------------------------------------------------------
*/

try {

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            appointment_id INT NULL,
            concern_id INT NULL,
            patient_id INT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'Appointment Concern',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_appointment_id (appointment_id),
            INDEX idx_concern_id (concern_id),
            INDEX idx_patient_id (patient_id),
            INDEX idx_is_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

} catch (PDOException $e) {

    die("Unable to initialize admin notification table.");

}

/*
|--------------------------------------------------------------------------
| CREATE DENTIST NOTIFICATIONS TABLE
|--------------------------------------------------------------------------
*/

try {

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dentist_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            appointment_id INT NULL,
            dentist_id INT NULL,
            patient_id INT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'Appointment Concern',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_appointment_id (appointment_id),
            INDEX idx_dentist_id (dentist_id),
            INDEX idx_patient_id (patient_id),
            INDEX idx_is_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

} catch (PDOException $e) {

    die("Unable to initialize dentist notification table.");

}

/*
|--------------------------------------------------------------------------
| PATIENT INFORMATION
|--------------------------------------------------------------------------
*/

$userId = (int)($_SESSION['user_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT
        id,
        first_name,
        last_name,
        email,
        patient_number
    FROM patients
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {

    die("Patient record not found.");

}

$patientId = (int)$patient['id'];

$fullName = trim(
    ($patient['first_name'] ?? '') .
    ' ' .
    ($patient['last_name'] ?? '')
);

/*
|--------------------------------------------------------------------------
| LOAD ALL PATIENT APPOINTMENTS (para sa dropdown)
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        a.*,
        d.dentist_name
    FROM appointments a
    LEFT JOIN dentists d
        ON d.id = a.dentist_id
    WHERE a.patient_id = ?
    ORDER BY
        a.appointment_date DESC,
        a.start_time DESC
    LIMIT 50
");
$stmt->execute([$patientId]);
$myAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Hanapin ang pinaka-malapit na active appointment (default selection) */
$defaultAppointmentId = 0;
foreach ($myAppointments as $row) {
    if (
        in_array($row['status'] ?? '', ['Pending', 'Confirmed'], true) &&
        ($row['appointment_date'] ?? '') >= date('Y-m-d')
    ) {
        // kunin ang pinaka-una sa chronological order
        $defaultAppointmentId = (int)$row['id'];
    }
}
// Dahil DESC ang order, baliktarin: kunin ang pinakahuling match = pinakamalapit na future
if ($defaultAppointmentId === 0) {
    $stmt = $pdo->prepare("
        SELECT id FROM appointments
        WHERE patient_id = ?
        AND status IN ('Pending','Confirmed')
        AND appointment_date >= CURDATE()
        ORDER BY appointment_date ASC, start_time ASC
        LIMIT 1
    ");
    $stmt->execute([$patientId]);
    $defaultAppointmentId = (int)($stmt->fetchColumn() ?: 0);
}

/* Aling appointment ang kasalukuyang pinipili */
$requestedAppointmentId = (int)($_GET['appointment_id'] ?? $_POST['appointment_id'] ?? 0);
if ($requestedAppointmentId <= 0) {
    $requestedAppointmentId = $defaultAppointmentId;
}

/* Kunin ang details ng napiling appointment (kung meron) */
$appointment = null;
if ($requestedAppointmentId > 0) {
    $stmt = $pdo->prepare("
        SELECT a.*, d.dentist_name
        FROM appointments a
        LEFT JOIN dentists d ON d.id = a.dentist_id
        WHERE a.id = ? AND a.patient_id = ?
        LIMIT 1
    ");
    $stmt->execute([$requestedAppointmentId, $patientId]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($appointment) {
        $appointmentId = (int)$appointment['id'];
    } else {
        $appointmentId = 0;
        $appointment = null;
    }
} else {
    $appointmentId = 0; // General concern
}

/* Chat history: lahat ng concerns ng patient (o per appointment kung may pinili) */
if ($appointmentId > 0) {
    $stmt = $pdo->prepare("
        SELECT * FROM appointment_concerns
        WHERE appointment_id = ? AND patient_id = ?
        ORDER BY created_at ASC, id ASC
    ");
    $stmt->execute([$appointmentId, $patientId]);
} else {
    $stmt = $pdo->prepare("
        SELECT * FROM appointment_concerns
        WHERE patient_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 20
    ");
    $stmt->execute([$patientId]);
}
$allConcerns = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($appointmentId <= 0) {
    // Ipakita oldest-first para chat-like pa rin
    $allConcerns = array_reverse($allConcerns);
}

/*
|--------------------------------------------------------------------------
| FORM
|--------------------------------------------------------------------------
*/

$errorMessage = '';
$successMessage = '';

$validReasons = [
    'Traffic / Transportation Problem',
    'Emergency',
    'Personal / Family Matter',
    'Feeling Unwell',
    'Work / School Conflict',
    'Forgot the Appointment',
    'Reschedule Request',
    'Cancellation Request',
    'Payment / Billing Inquiry',
    'Other Dental Concern',
    'General Inquiry',
    'Other'
];


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

    $postedAppointmentId = (int)($_POST['appointment_id'] ?? 0);

    // Validate na pagmamay-ari ni patient ang appointment (0 = General)
    $validAppointment = null;
    if ($postedAppointmentId > 0) {
        $stmt = $pdo->prepare("
            SELECT a.*, d.dentist_name
            FROM appointments a
            LEFT JOIN dentists d ON d.id = a.dentist_id
            WHERE a.id = ? AND a.patient_id = ?
            LIMIT 1
        ");
        $stmt->execute([$postedAppointmentId, $patientId]);
        $validAppointment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$validAppointment) {
            $errorMessage = 'Invalid appointment selected.';
        }
    }

    $reason = trim($_POST['reason'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($errorMessage === '') {
        if (!in_array($reason, $validReasons, true)) {
            $errorMessage = 'Please select a valid reason.';
        } elseif ($message === '') {
            $errorMessage = 'Please type your concern below para maipadala kay admin.';
        } elseif (mb_strlen($message) < 3) {
            $errorMessage = 'Your concern message is too short. Please explain further.';
        } elseif (mb_strlen($message) > 1000) {
            $errorMessage = 'Your explanation must not exceed 1000 characters.';
        } elseif ($reason === 'Other' && mb_strlen($message) < 5) {
            $errorMessage = 'Please explain your concern when selecting Other.';
        }
    }

    if ($errorMessage === '') {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO appointment_concerns
                (appointment_id, patient_id, reason, message, status)
                VALUES (?, ?, ?, ?, 'New')
            ");
            $stmt->execute([
                $postedAppointmentId > 0 ? $postedAppointmentId : null,
                $patientId,
                $reason,
                $message
            ]);

            $concernId = (int)$pdo->lastInsertId();

            // Admin notification message
            if ($validAppointment) {
                $apptLabel =
                    date('F d, Y', strtotime($validAppointment['appointment_date'])) .
                    ' at ' .
                    date('h:i A', strtotime($validAppointment['start_time']));
            } else {
                $apptLabel = 'General Concern (no specific appointment)';
            }

            $adminTitle = 'New Appointment Concern';
            $adminMessage =
                $fullName .
                ' submitted an appointment concern (' . $apptLabel . '). Reason: ' . $reason .
                '. Message: ' . mb_substr($message, 0, 200);

            $stmt = $pdo->prepare("
                INSERT INTO admin_notifications
                (appointment_id, concern_id, patient_id, title, message, type, is_read)
                VALUES (?, ?, ?, ?, ?, 'Appointment Concern', 0)
            ");
            $stmt->execute([
                $postedAppointmentId > 0 ? $postedAppointmentId : null,
                $concernId,
                $patientId,
                $adminTitle,
                $adminMessage
            ]);

            // Dentist notification (kung may assigned dentist sa appointment)
            if ($validAppointment && !empty($validAppointment['dentist_id'])) {
                $dentistId = (int)$validAppointment['dentist_id'];
                $dentistTitle = 'New Patient Concern';
                $dentistMessage =
                    $fullName .
                    ' submitted an appointment concern (' . $apptLabel . '). Reason: ' . $reason .
                    '. Message: ' . mb_substr($message, 0, 200);

                $stmt = $pdo->prepare("
                    INSERT INTO dentist_notifications
                    (appointment_id, dentist_id, patient_id, title, message, type, is_read)
                    VALUES (?, ?, ?, ?, ?, 'Appointment Concern', 0)
                ");
                $stmt->execute([
                    $postedAppointmentId > 0 ? $postedAppointmentId : null,
                    $dentistId,
                    $patientId,
                    $dentistTitle,
                    $dentistMessage
                ]);
            }

            $pdo->commit();

            if ($validAppointment && !empty($validAppointment['dentist_id'])) {
                $successMessage = 'Your concern has been sent to the clinic administrator and your assigned dentist.';
            } else {
                $successMessage = 'Your concern has been sent to the clinic administrator.';
            }
            $_POST['message'] = '';
            $_POST['reason'] = '';

            // Refresh selection + history
            $requestedAppointmentId = $postedAppointmentId;
            $appointmentId = $postedAppointmentId;
            $appointment = $validAppointment;

            if ($appointmentId > 0) {
                $stmt = $pdo->prepare("
                    SELECT * FROM appointment_concerns
                    WHERE appointment_id = ? AND patient_id = ?
                    ORDER BY created_at ASC, id ASC
                ");
                $stmt->execute([$appointmentId, $patientId]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT * FROM appointment_concerns
                    WHERE patient_id = ?
                    ORDER BY created_at ASC, id ASC
                    LIMIT 50
                ");
                $stmt->execute([$patientId]);
            }
            $allConcerns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = 'Unable to send your concern. Please try again.';
        }
    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Appointment Concern | Abalos Dental
</title>


<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

<link
    rel="stylesheet"
    href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
>


<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #0A3B34;
    color: #173b4d;
    font-family: 'DM Sans', sans-serif;
}


/* SIDEBAR */

.patient-sidebar {
    width: 245px;
    min-height: 100vh;
    background: #ffffff;
    border-right: 1px solid #e4edf0;
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    z-index: 100;
}

.patient-brand {
    height: 86px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid #edf2f4;
}

.brand-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: #e5f7fb;
    color: #0E6B5E;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

.brand-title {
    font-size: 15px;
    font-weight: 800;
}

.brand-subtitle {
    color: #8aa0aa;
    font-size: 8px;
    font-weight: 700;
    letter-spacing: .8px;
}

.patient-menu {
    padding: 20px 13px;
}

.menu-label {
    padding: 0 12px 9px;
    color: #9aadb5;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 1px;
}

.patient-menu a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 13px;
    margin-bottom: 4px;
    border-radius: 10px;
    color: #69808b;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
}

.patient-menu a:hover,
.patient-menu a.active {
    background: #e6f7fb;
    color: #0E6B5E;
}

.patient-menu a i {
    width: 19px;
    text-align: center;
}

.menu-divider {
    height: 1px;
    background: #edf2f4;
    margin: 15px 8px;
}

.logout-link {
    color: #dc6d6d !important;
}


/* MAIN */

.patient-main {
    margin-left: 245px;
    width: calc(100% - 245px);
    min-height: 100vh;
}


/* TOPBAR */

.patient-topbar {
    height: 86px;
    background: white;
    border-bottom: 1px solid #e4edf0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 32px;
}

.page-heading {
    margin: 0;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 22px;
    font-weight: 800;
}

.page-small {
    color: #8ca1aa;
    font-size: 11px;
    margin-top: 3px;
}

.patient-profile {
    display: flex;
    align-items: center;
    gap: 10px;
}

.profile-circle {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: #e6f7fb;
    color: #0E6B5E;
    display: flex;
    align-items: center;
    justify-content: center;
}

.profile-name {
    font-size: 12px;
    font-weight: 700;
}

.profile-role {
    color: #94a7af;
    font-size: 9px;
}

/* Itulad sa Calendar topbar: minibar + date/time */
.patient-minibar{display:flex;align-items:center;justify-content:center;gap:8px;background:#0A3B34;color:#C9A227;font-size:11px;font-weight:700;letter-spacing:.6px;padding:6px 12px;border-radius:10px 10px 0 0;}
.patient-minibar span{color:#ffffff;}
.patient-minibar i{font-size:13px;color:#ffffff;}
.patient-topbar-right{display:flex;align-items:center;gap:16px;}
.patient-topbar-date{display:flex;align-items:center;gap:10px;color:#244f60;}
.patient-topbar-date i{font-size:20px;}
.patient-topbar-date strong{display:block;font-size:12px;font-weight:800;line-height:1.2;}
.patient-topbar-date small{display:block;font-size:11px;opacity:.85;font-weight:600;}
.patient-topbar-time{display:flex;align-items:center;gap:10px;color:#244f60;border-left:1px solid #e8eef2;padding-left:16px;}
.patient-topbar-time i{font-size:20px;}
.patient-topbar-time strong{display:block;font-size:12px;font-weight:800;line-height:1.2;}
.patient-topbar-time small{display:block;font-size:11px;opacity:.85;font-weight:600;font-variant-numeric:tabular-nums;}


/* CONTENT */

.patient-content {
    padding: 30px;
}

.concern-card {
    max-width: 850px;
    margin: 0 auto;
    background: white;
    border: 2px solid #0E6B5E;
    border-radius: 20px;
    padding: 30px;
    box-shadow: 0 10px 35px rgba(30,70,80,.08);
}

.concern-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 25px;
}

.concern-icon {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    background: #fff6e8;
    color: #e88a00;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
}

.concern-title {
    margin: 0;
    font-size: 24px;
    font-weight: 700;
}

.concern-subtitle {
    color: #6e828c;
    font-size: 13px;
    margin-top: 3px;
}


/* APPOINTMENT */

.appointment-details {
    background: #f8fafb;
    border: 2px solid #0E6B5E;
    border-radius: 13px;
    padding: 22px;
    margin-bottom: 24px;
}

.details-title {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 17px;
}

.detail-label {
    color: #75909b;
    font-size: 10px;
    margin-bottom: 4px;
}

.detail-value {
    color: #173b4d;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 17px;
}


/* FORM */

.form-label {
    color: #496773;
    font-size: 12px;
    font-weight: 700;
}

.form-control,
.form-select {
    border: 1.5px solid #0E6B5E;
    border-radius: 10px;
    font-size: 12px;
    padding: 11px 12px;
}

.form-control:focus,
.form-select:focus {
    border-color: #0E6B5E;
    box-shadow: 0 0 0 .15rem rgba(11,159,189,.12);
}

textarea {
    min-height: 120px;
    resize: vertical;
}

.btn-submit {
    background: #0E6B5E;
    border: none;
    color: white;
    border-radius: 10px;
    padding: 11px 20px;
    font-size: 12px;
    font-weight: 700;
}

.btn-submit:hover {
    background: #078aa5;
    color: white;
}


/* EXISTING */

.existing-concern {
    background: #fff3c9;
    border-radius: 13px;
    padding: 17px;
    margin-top: 20px;
}

.existing-title {
    color: #795600;
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 8px;
}

.existing-row {
    color: #755d19;
    font-size: 10px;
    margin-top: 6px;
}

.status-new {
    color: #a66b00;
    font-weight: 700;
}

.status-reviewed {
    color: #16805b;
}

.chat-history {
    max-height: 340px;
    overflow-y: auto;
    padding: 16px;
    background: #f8fafb;
    border: 2px solid #0E6B5E;
    border-radius: 13px;
    margin-bottom: 20px;
}
.chat-bubble {
    max-width: 78%;
    padding: 12px 14px;
    border-radius: 16px;
    margin-bottom: 10px;
    font-size: 13px;
    line-height: 1.5;
    box-shadow: 0 2px 8px rgba(0,0,0,.04);
}
.chat-bubble.patient {
    background: linear-gradient(135deg, #0E6B5E, #0A4A41);
    color: #ffffff;
    margin-left: auto;
    border-bottom-right-radius: 4px;
}
.chat-bubble .chat-reason {
    font-weight: 700;
    font-size: 11px;
    opacity: .9;
    margin-bottom: 4px;
    letter-spacing: .3px;
}
.chat-bubble .chat-meta {
    font-size: 10px;
    opacity: .7;
    margin-top: 6px;
    text-align: right;
}
.chat-input-wrap {
    background: #ffffff;
    border: 2px solid #0E6B5E;
    border-radius: 13px;
    padding: 16px;
}

#patientBackToDashboard {
    background: linear-gradient(135deg, #149A72, #0A4A41) !important;
    color: #ffffff !important;
    border: 1px solid rgba(201,162,39,.35) !important;
    border-radius: 10px !important;
    padding: 9px 18px !important;
    font-size: 13.5px !important;
    font-weight: 800 !important;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
}
#patientBackToDashboard:hover {
    background: linear-gradient(135deg, #0E6B5E, #083A32) !important;
    color: #ffffff !important;
}


@media (max-width: 800px) {

    .patient-sidebar {
        width: 70px;
    }

    .brand-title,
    .brand-subtitle,
    .patient-menu span,
    .menu-label {
        display: none;
    }

    .patient-brand {
        justify-content: center;
    }

    .patient-menu a {
        justify-content: center;
    }

    .patient-main {
        margin-left: 70px;
        width: calc(100% - 70px);
    }

    .patient-content {
        padding: 15px;
    }

    .concern-card {
        padding: 20px;
    }

}

</style>

</head>


<body>


<!-- =========================================================
     PATIENT SIDEBAR
========================================================= -->

<?php require_once __DIR__ . '/patient_sidebar.php'; ?>


<!-- =========================================================
     MAIN
========================================================= -->

<main class="patient-main">

    <div class="patient-minibar"><i class="bi bi-heart-pulse-fill"></i><span>Abalos Dental Clinic</span></div>

    <header class="patient-topbar">

        <div>

            <h1 class="page-heading">
                Appointment Concern
            </h1>

            <div class="page-small">
                I-type ang concern mo dito — ipapadala ito sa clinic administrator.
            </div>

        </div>


        <div class="patient-topbar-right">

            <div class="patient-topbar-date"><i class="bi bi-calendar-event-fill"></i><div><strong><?= htmlspecialchars(date('l')) ?></strong><small><?= htmlspecialchars(date('M d, Y')) ?></small></div></div>

            <div class="patient-topbar-time"><i class="bi bi-clock-fill"></i><div><strong>Time</strong><small id="patientClinicTime">--:--:--</small></div></div>

            <div class="profile-circle">
                <i class="bi bi-person-fill"></i>
            </div>

        </div>

    </header>
<script>(function(){function tick(){try{var el=document.getElementById('patientClinicTime');if(!el)return;el.textContent=new Date().toLocaleTimeString('en-PH',{timeZone:'Asia/Manila',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});}catch(e){}}tick();setInterval(tick,1000);})();</script>


    <div class="patient-content">


        <div class="concern-card">


            <div class="concern-header">

                <div class="concern-icon">
                    <i class="bi bi-exclamation-circle-fill"></i>
                </div>

                <div>

                    <h2 class="concern-title">
                        Appointment Concern
                    </h2>

                    <div class="concern-subtitle">
                        Pumili ng appointment (o General Concern), piliin ang dahilan, at i-type ang mensahe mo kay admin.
                    </div>

                </div>

            </div>


            <?php if ($successMessage !== ''): ?>

                <div class="alert alert-success">

                    <i class="bi bi-check-circle-fill me-1"></i>

                    <?= concern_e($successMessage) ?>

                </div>

            <?php endif; ?>


            <?php if ($errorMessage !== ''): ?>

                <div class="alert alert-danger">

                    <i class="bi bi-exclamation-triangle-fill me-1"></i>

                    <?= concern_e($errorMessage) ?>

                </div>

            <?php endif; ?>


            <?php if ($appointment && $appointmentId > 0): ?>
            <div class="appointment-details">

                <div class="details-title">
                    Appointment Details
                </div>


                <div class="row">

                    <div class="col-md-6">

                        <div class="detail-label">
                            Date
                        </div>

                        <div class="detail-value">

                            <?= concern_e(
                                date(
                                    'F d, Y',
                                    strtotime(
                                        $appointment['appointment_date']
                                    )
                                )
                            ) ?>

                        </div>

                    </div>


                    <div class="col-md-6">

                        <div class="detail-label">
                            Time
                        </div>

                        <div class="detail-value">

                            <?= concern_e(
                                date(
                                    'h:i A',
                                    strtotime(
                                        $appointment['start_time']
                                    )
                                )
                            ) ?>

                        </div>

                    </div>


                    <div class="col-md-6">

                        <div class="detail-label">
                            Dentist
                        </div>

                        <div class="detail-value">

                            <?= concern_e(
                                $appointment['dentist_name']
                                ?? 'Not assigned'
                            ) ?>

                        </div>

                    </div>


                    <div class="col-md-6">

                        <div class="detail-label">
                            Service
                        </div>

                        <div class="detail-value">

                            <?= concern_e(
                                $appointment['service_type']
                                ?? 'Dental Appointment'
                            ) ?>

                        </div>

                    </div>


                    <div class="col-md-6">

                        <div class="detail-label">
                            Appointment Status
                        </div>

                        <div class="detail-value">

                            <?= concern_e(
                                $appointment['status']
                            ) ?>

                        </div>

                    </div>

                </div>

            </div>
            <?php elseif (empty($myAppointments)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle-fill me-1"></i>
                Wala ka pang appointment. Pwede ka pa rin mag-send ng <strong>General Concern</strong> sa ibaba — hal. magtatanong tungkol sa services, schedule, o presyo.
            </div>
            <?php endif; ?>


            <!-- Chat history -->
            <?php if (!empty($allConcerns)): ?>
            <div class="chat-history" id="chatHistory">
                <?php foreach ($allConcerns as $c): ?>
                <div class="chat-bubble patient">
                    <div class="chat-reason">
                        <i class="bi bi-chat-dots me-1"></i><?= concern_e($c['reason']) ?>
                        — <span class="<?= ($c['status'] ?? 'New') === 'Reviewed' ? 'status-reviewed' : 'status-new' ?>"><?= concern_e($c['status']) ?></span>
                    </div>
                    <div><?= nl2br(concern_e($c['message'] ?: 'No additional message.')) ?></div>
                    <div class="chat-meta"><?= concern_e(date('M d, Y h:i A', strtotime($c['created_at']))) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center py-3 mb-3" style="background:#f8fafb; border:1px dashed #dce5e9; border-radius:13px; color:#8aa0a8; font-size:13px;">
                <i class="bi bi-chat me-1"></i> No messages yet. Start a conversation with the admin.
            </div>
            <?php endif; ?>

            <!-- =====================================================
                 INPUT FORM — dito nagta-type si patient ng concern
            ====================================================== -->
            <div class="chat-input-wrap">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div style="width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg, #0E6B5E, #0A4A41); color:#fff; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="bi bi-send-fill"></i></div>
                    <div>
                        <div style="font-weight:800; color:#143f51; font-size:14px;">Message to Admin</div>
                        <div style="font-size:11px; color:#8aa0a8;">I-type ang concern mo — parang nagme-message ka kay admin</div>
                    </div>
                </div>

                <form method="POST" action="">
<?= csrfField() ?>

                    <div class="mb-3">
                        <label for="appointment_id" class="form-label">Related Appointment</label>
                        <select name="appointment_id" id="appointment_id" class="form-select" onchange="this.form.method='GET'; this.form.querySelector('[name=reason]') && null; window.location.href='appointment_concern.php?appointment_id='+this.value;">
                            <option value="0" <?= ($requestedAppointmentId <= 0) ? 'selected' : '' ?>>General Concern (walang specific appointment)</option>
                            <?php foreach ($myAppointments as $a): ?>
                                <option value="<?= (int)$a['id'] ?>" <?= ($requestedAppointmentId === (int)$a['id']) ? 'selected' : '' ?>>
                                    <?= concern_e(date('M d, Y', strtotime($a['appointment_date']))) ?>
                                    <?= concern_e(date('h:i A', strtotime($a['start_time']))) ?>
                                    — <?= concern_e($a['service_type'] ?? 'Appointment') ?>
                                    (<?= concern_e($a['status'] ?? '') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted mt-1" style="font-size:10px;">Pumili kung tungkol saan ang concern mo. Kung wala kang appointment, piliin ang General Concern.</div>
                    </div>

                    <div class="mb-3">
                        <label for="reason" class="form-label">Reason / Dahilan *</label>
                        <select name="reason" id="reason" class="form-select" required>
                            <option value="">— Select a reason —</option>
                            <?php foreach ($validReasons as $validReason): ?>
                                <option value="<?= concern_e($validReason) ?>" <?= (($_POST['reason'] ?? '') === $validReason) ? 'selected' : '' ?>><?= concern_e($validReason) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="message" class="form-label">Your Concern / Mensahe *</label>
                        <textarea name="message" id="message" class="form-control" maxlength="1000" required placeholder="Hal. Doc, hindi po ako makakapunta sa appointment ko bukas dahil may emergency... Pakiresched po sana..." rows="4"><?= concern_e($_POST['message'] ?? '') ?></textarea>
                        <div class="d-flex justify-content-between mt-1">
                            <div class="text-muted" style="font-size:10px;">I-type dito ang concern mo. Required ito.</div>
                            <div class="text-muted" style="font-size:10px;"><span id="charCount">0</span>/1000</div>
                        </div>
                    </div>

                    <!-- hidden na totoong isusubmit (para hindi madamay ang onchange GET) -->
                    <input type="hidden" id="submitAppointmentId" name="appointment_id_submit" value="<?= $requestedAppointmentId ?>">

                    <div class="d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-submit" style="background:linear-gradient(135deg, #0E6B5E, #0A4A41);"><i class="bi bi-send-fill me-1"></i> Send to Admin</button>
                    </div>
                </form>
            </div>
            <script>
            // Auto-scroll chat to latest message
            (function(){ var h=document.getElementById('chatHistory'); if(h) h.scrollTop = h.scrollHeight; })();
            // Character counter
            (function(){
                var ta = document.getElementById('message');
                var cc = document.getElementById('charCount');
                if(!ta || !cc) return;
                function upd(){ cc.textContent = ta.value.length; }
                ta.addEventListener('input', upd); upd();
            })();
            // Ayusin ang appointment_id bago mag-submit (dahil ang select ay pang GET-navigation)
            (function(){
                var sel = document.getElementById('appointment_id');
                var hidden = document.getElementById('submitAppointmentId');
                var form = sel ? sel.closest('form') : null;
                if(!form) return;
                form.addEventListener('submit', function(){
                    // tanggalin ang name ng select para hindi magdoble, gamitin ang hidden
                    sel.removeAttribute('name');
                    hidden.setAttribute('name', 'appointment_id');
                });
            })();
            </script>


        </div>

    </div>

</main>


</body>

</html>
