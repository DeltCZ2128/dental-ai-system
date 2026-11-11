<?php

require_once __DIR__ . "/../functions.php";

requireAdmin();


/*
|--------------------------------------------------------------------------
| CREATE ADMIN NOTIFICATIONS TABLE
|--------------------------------------------------------------------------
*/

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
        email_sent_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

        INDEX idx_appointment_id (appointment_id),
        INDEX idx_concern_id (concern_id),
        INDEX idx_patient_id (patient_id),
        INDEX idx_is_read (is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");


/*
|--------------------------------------------------------------------------
| EMAIL SENT TRACKING
|--------------------------------------------------------------------------
|
| Ginagamit ang email_sent_at para malaman kung naipadala na ang reschedule /
| cancel options sa patient. Kapag may laman na ito, hindi na ipinapakita ang
| "Email" button sa listahan — bagaman ang pagdagdag ng column ay maaaring
| hindi pa nasa ibang install, kaya ini-check din ang bawat pag-load.
|
*/

$emailSentColumnExists = $pdo->query("
    SHOW COLUMNS FROM admin_notifications LIKE 'email_sent_at'
")->fetchColumn();

if (!$emailSentColumnExists) {
    $pdo->exec("
        ALTER TABLE admin_notifications
        ADD COLUMN email_sent_at TIMESTAMP NULL DEFAULT NULL
    ");
}


/*
|--------------------------------------------------------------------------
| MARK AS READ
|--------------------------------------------------------------------------
*/


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

    if (
        ($_POST['action'] ?? '') === 'read'
    ) {

        $notificationId =
            (int)($_POST['notification_id'] ?? 0);


        if ($notificationId > 0) {

            $stmt = $pdo->prepare("
                UPDATE admin_notifications
                SET is_read = 1
                WHERE id = ?
            ");

            $stmt->execute([
                $notificationId
            ]);

            // Mark related patient concern as Reviewed - para mag-function ang concern flow
            try {
                $stmt = $pdo->prepare("
                    UPDATE appointment_concerns
                    SET status = 'Reviewed'
                    WHERE id = (
                        SELECT concern_id FROM admin_notifications WHERE id = ?
                    )
                ");
                $stmt->execute([$notificationId]);
            } catch (Throwable $e) {
                // keep admin notification as read even if concern update fails
            }

        }


        redirect(
            "appointment_concern.php"
        );
    }
}


/*
|--------------------------------------------------------------------------
| MARK ALL AS READ
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'read_all'
) {

    $pdo->exec("
        UPDATE admin_notifications
        SET is_read = 1
        WHERE is_read = 0
    ");

    // Mark all related concerns as Reviewed
    try {
        $pdo->exec("
            UPDATE appointment_concerns
            SET status = 'Reviewed'
            WHERE id IN (
                SELECT concern_id FROM admin_notifications WHERE type = 'Appointment Concern' AND concern_id IS NOT NULL
            )
        ");
    } catch (Throwable $e) {
        // keep notifications as read
    }

    redirect(
        "appointment_concern.php"
    );
}


/*
|--------------------------------------------------------------------------
| CONCERN EMAIL — SUBJECT + BODY BUILDER
|--------------------------------------------------------------------------
| Ginagamit ng dalawang lugar: ang preview modal (para makita ng admin ang
| eksaktong laman) at ang send handler (para ito ang ipinapadala). Kaya
| isang function lang ang nagbu-build — walang magkapalit na teksto.
*/

function buildConcernEmailSubject()
{
    return 'Your Appointment Has Been Cancelled - Abalos Dental Clinic';
}

function buildConcernEmailBody(array $concern)
{
    $patientName = trim((string)($concern['patient_name'] ?? ''));

    if ($patientName === '') {
        $patientName = 'Patient';
    }

    $appointmentDate = (string)($concern['appointment_date'] ?? '');
    $appointmentDateLabel = $appointmentDate !== ''
        ? date('M d, Y', strtotime($appointmentDate))
        : '—';

    $startTime = trim((string)($concern['start_time'] ?? ''));
    $endTime = trim((string)($concern['end_time'] ?? ''));

    $appointmentTimeLabel = $startTime !== ''
        ? date('h:i A', strtotime($startTime)) . ($endTime !== '' ? ' - ' . date('h:i A', strtotime($endTime)) : '')
        : '—';

    $serviceType = (string)($concern['service_type'] ?? 'Dental appointment');

    $dentistName = trim((string)($concern['dentist_name'] ?? ''));

    if ($dentistName === '') {
        $dentistName = '—';
    }

    $concernTitle = trim((string)($concern['title'] ?? ''));
    $concernMessage = trim((string)($concern['message'] ?? ''));

    return
        "Hello " . $patientName . ",\n\n" .
        "Thank you for contacting Abalos Dental Clinic about your appointment.\n\n" .
        "Your appointment has been CANCELLED as requested. The details below are no longer active.\n\n" .
        "CANCELLED APPOINTMENT\n" .
        "Date: " . $appointmentDateLabel . "\n" .
        "Time: " . $appointmentTimeLabel . "\n" .
        "Service: " . $serviceType . "\n" .
        "Dentist: " . $dentistName . "\n\n" .
        "YOUR CONCERN\n" .
        ($concernTitle !== '' ? $concernTitle . "\n" : "") .
        ($concernMessage !== '' ? $concernMessage . "\n" : "") . "\n" .
        "WOULD YOU LIKE TO RESCHEDULE?\n\n" .
        "If you would still like to be seen by our dentist, simply reply to this email\n" .
        "with your preferred new date and time, and we will book a new slot for you.\n\n" .
        "If we do not hear back, we will consider this cancellation final.\n\n" .
        "We hope to see you again soon.\n\n" .
        "Thank you,\n" .
        "Abalos Dental Clinic";
}


/*
|--------------------------------------------------------------------------
| SEND CONCERN EMAIL TO PATIENT
|--------------------------------------------------------------------------
| Tinatanong ng patient kung gusto pa niyang i-reschedule o i-cancel ang
| appointment niya. Hinahawakan ng admin: pinapakita muna ang buong laman
| ng mensahe sa preview modal bago ipadala sa Gmail ng patient.
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'send_concern_email'
) {

    $notifId = (int)($_POST['notification_id'] ?? 0);

    if ($notifId <= 0) {
        flash("Invalid concern.", "danger");
        redirect("appointment_concern.php");
    }

    $emailStmt = $pdo->prepare("
        SELECT
            an.id,
            an.appointment_id,
            an.patient_id,
            an.title,
            an.message,
            CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
            p.email AS patient_email,
            a.appointment_date,
            a.start_time,
            a.end_time,
            a.service_type,
            d.dentist_name
        FROM admin_notifications an
        LEFT JOIN patients p ON p.id = an.patient_id
        LEFT JOIN appointments a ON a.id = an.appointment_id
        LEFT JOIN dentists d ON d.id = a.dentist_id
        WHERE an.id = ?
        LIMIT 1
    ");

    $emailStmt->execute([$notifId]);
    $concernEmail = $emailStmt->fetch(PDO::FETCH_ASSOC);

    if (!$concernEmail) {
        flash("Concern not found.", "danger");
        redirect("appointment_concern.php");
    }

    $toEmail = trim((string)($concernEmail['patient_email'] ?? ''));

    if ($toEmail === '') {
        flash("This patient has no email address on record.", "danger");
        redirect("appointment_concern.php");
    }

    $subject = buildConcernEmailSubject();
    $body = buildConcernEmailBody($concernEmail);

    list($sent, $sendError) = sendClinicGmail($toEmail, $subject, $body);

    if ($sent) {
        try {
            $apptIdForNote = (int)($concernEmail['appointment_id'] ?? 0);

            // Itala na naipadala ang email — para mawala na ang "Email" button.
            $sentMark = $pdo->prepare("
                UPDATE admin_notifications
                SET email_sent_at = NOW()
                WHERE id = ?
            ");
            $sentMark->execute([$notifId]);

            createNotification(
                (int)$concernEmail['patient_id'],
                $apptIdForNote > 0 ? $apptIdForNote : null,
                'Reschedule / Cancel Options Sent',
                'Sent reschedule and cancel options to ' . $toEmail . '.',
                'Appointment'
            );
        } catch (Throwable $e) {
            // Email ay na-send na — huwag guluhin ang admin dahil lang hindi na-save ang notification.
        }

        flash("Email sent to " . $toEmail . ".");
    } else {
        flash("Could not send email: " . $sendError, "danger");
    }

    redirect("appointment_concern.php");
}


/*
|--------------------------------------------------------------------------
| UNREAD COUNT
|--------------------------------------------------------------------------
*/

$unreadCount = (int)$pdo
    ->query("
        SELECT COUNT(*)
        FROM admin_notifications
        WHERE is_read = 0
    ")
    ->fetchColumn();


/*
|--------------------------------------------------------------------------
| NOTIFICATIONS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT

        an.*,

        CONCAT(
            p.first_name,
            ' ',
            p.last_name
        ) AS patient_name,

        p.patient_number,

        p.email AS patient_email,

        a.appointment_date,
        a.start_time,
        a.end_time,
        a.service_type,

        d.dentist_name

    FROM admin_notifications an

    LEFT JOIN patients p
        ON p.id = an.patient_id

    LEFT JOIN appointments a
        ON a.id = an.appointment_id

    LEFT JOIN dentists d
        ON d.id = a.dentist_id

    WHERE an.type = 'Appointment Concern'

    ORDER BY
        an.is_read ASC,
        an.created_at DESC
");

$notificationsAll =
    $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| SCROLL LIST — 10 CONCERNS PER VIEW
|--------------------------------------------------------------------------
| Wala nang page navigation. Isang table lang ang naglalaman ng lahat ng
| concerns at nag-ce-scroll ito. Ang JS ang nagsusukat ng taas ng bawat row
| (suma ng header + 10 rows) kaya eksaktong 10 lang ang nakikita sa isang
| pagkakataon, kahit anong lapad ng screen.
*/

$concernTotal = count($notificationsAll);

$highlightId = (int)($_GET['highlight'] ?? 0);

/* Isang listahan — lahat ng concerns, walang slicing. */
$notifications = $notificationsAll;

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
    Appointment Concerns | Abalos Dental
</title>


<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

<style>

body {
    background: linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22) !important;
    background-size: 300% 300% !important;
    animation: concernBgAnim 8s ease infinite !important;
    background-attachment: fixed !important;
    font-family: "Inter","DM Sans",system-ui,sans-serif;
}

@keyframes concernBgAnim { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }

/* TOPBAR — kapag Concerns page: puti, title + subtitle sa kaliwa,
   date + clinic time + admin info sa kanan. */
.shared-topbar {
    left: 280px !important;
    right: 0 !important;
    background: #ffffff !important;
    border-bottom: 1px solid #e8eef2 !important;
}
@media (max-width: 991px) {
    .shared-topbar { left: 0 !important; }
}
.shared-topbar .admin-area { display: flex !important; align-items: center !important; gap: 16px !important; }
.admin-topbar-today { display: flex; align-items: center; gap: 10px; color: #244f60; }
.admin-topbar-today i { font-size: 22px; }
.admin-topbar-today strong { display: block; font-size: 13px; font-weight: 800; line-height: 1.2; }
.admin-topbar-today small { display: block; font-size: 11px; opacity: .85; font-weight: 600; }
.admin-topbar-time { display: flex; align-items: center; gap: 10px; color: #244f60; border-left: 1px solid #e8eef2; padding-left: 16px; }
.admin-topbar-time i { font-size: 22px; }
.admin-topbar-time strong { display: block; font-size: 13px; font-weight: 800; line-height: 1.2; }
.admin-topbar-time small { display: block; font-size: 11px; opacity: .85; font-weight: 600; font-variant-numeric: tabular-nums; }

.concern-wrapper {
    padding: 110px 24px 40px !important;
    max-width: calc(100% - 280px) !important;
    width: calc(100% - 280px) !important;
    margin-left: 280px !important;
    min-height: 100vh;
    background: transparent !important;
    box-sizing: border-box !important;
}
@media (max-width: 991px) {
    .concern-wrapper {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        padding: 110px 16px 40px !important;
    }
}

.concern-wrapper > a.btn[href*="dashboard.php"] {
    margin-left: 14px !important;
    margin-bottom: 16px !important;
}

/* Panel card + header — kopya ng .patient-panel sa Patients page */
.concern-card,
.patient-card {
    background: #ffffff !important;
    border: 1px solid #C9E9DA !important;
    border-radius: 16px !important;
    overflow: hidden !important;
    box-shadow: 0 8px 24px rgba(14,107,94,.08) !important;
    width: 100% !important;
    min-height: auto !important;
    margin: 0 14px 24px 14px !important;
    padding: 0 !important;
}

.concern-header,
.patient-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 18px 20px;
    background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%);
    border-bottom: 1px solid #C9E9DA;
}

.concern-header h2,
.patient-card-header h3 {
    color: #143f51 !important;
    font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif;
    font-size: 17px !important;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.concern-header h2 i,
.patient-card-header h3 i { color: #0E6B5E; font-size: 18px; }

.concern-header p,
.patient-card-header p {
    color: #8aa0a8;
    font-size: 12px;
    margin: 3px 0 0 26px;
    font-weight: 500;
}

.patient-card-header .btn-outline-secondary {
    background: #ffffff !important;
    color: #0E6B5E !important;
    border: 1px solid #ffffff !important;
    border-radius: 10px !important;
    font-weight: 800 !important;
    padding: 8px 16px !important;
    box-shadow: 0 6px 14px rgba(0,0,0,.18) !important;
}

.patient-card-header .btn-outline-secondary:hover {
    background: #EAF7F0 !important;
    color: #0A4A41 !important;
    border-color: #C9E9DA !important;
}

.concern-header .btn-outline-secondary {
    background: #ffffff !important;
    border: 1px solid #D1E7DD !important;
    color: #57727d !important;
    border-radius: 10px !important;
    font-weight: 700 !important;
}

.concern-header .btn-outline-secondary:hover {
    background: #EAF7F0 !important;
    color: #0E6B5E !important;
    border-color: #0E6B5E !important;
}

/* Table — katulad ng Patients page */
.patient-table {
    margin-bottom: 0 !important;
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: #ffffff;
}

.patient-table thead tr {
    background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important;
}

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
.patient-table tbody td strong { color: #143f51 !important; font-size: 13.5px !important; }
.patient-table tbody tr:last-child td:first-child { border-bottom-left-radius: 16px; }
.patient-table tbody tr:last-child td:last-child { border-bottom-right-radius: 16px; }

/* Scroll slide — green scrollbar + sticky header (pareho ng Patients page).
   Ang max-height ay hindi nakatakda sa CSS: dinadalhan ng JS ang taas ng
   header + 10 rows kaya eksaktong 10 concerns lang ang nakikita. */
.admin-concern-scroll {
    overflow: auto;
    max-height: 640px;
    min-height: auto;
    scrollbar-width: thin;
    scrollbar-color: #149A72 #DFF2E9;
    /* Slide feel: magnetic na pag-scroll sa bawat row */
    scroll-snap-type: y proximity;
    -webkit-overflow-scrolling: touch;
}
.admin-concern-scroll.is-measured { max-height: none; }
.admin-concern-scroll .patient-table tbody tr { scroll-snap-align: start; }
.admin-concern-scroll::-webkit-scrollbar { width: 10px; }
.admin-concern-scroll::-webkit-scrollbar-track { background: #DFF2E9; border-radius: 8px; }
.admin-concern-scroll::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #149A72, #0A4A41); border-radius: 8px; }
.admin-concern-scroll::-webkit-scrollbar-thumb:hover { background: #0E6B5E; }
.admin-concern-scroll thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important;
}

/* Mga dating inline style — inilipat sa CSS para linisin ang markup */
.concern-patnum { color: #8aa0a8; font-size: 11.5px; }
.concern-time { color: #647b85; font-size: 12.5px; }
.concern-nowrap { white-space: nowrap; }
.concern-cell { max-width: 260px; }
.concern-msg-title { font-weight: 700; color: #143f51; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.concern-msg-body { color: #647b85; font-size: 12.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 260px; }

/* Mark color: hindi pa nababasa = may kulay, nabasa na = white */
.concern-row[data-is-read="0"] td {
    background: #fffbeb !important;
}
.concern-row[data-is-read="0"] td:first-child {
    border-left: 4px solid #f59e0b !important;
}
.concern-row[data-is-read="0"]:hover td {
    background: #fef3c7 !important;
}
.concern-row[data-is-read="1"] td {
    background: #ffffff !important;
}
.concern-row[data-is-read="1"]:nth-child(even) td {
    background: #F6FBF8 !important;
}
.concern-row[data-is-read="1"]:hover td {
    background: #EAF7F0 !important;
}
/* Highlight ng clinick na notification — para hindi na maghanap si admin */
.concern-row.highlight-row td {
    background: #FFF7E0 !important;
    border-top: 2px solid #C98609 !important;
    border-bottom: 2px solid #C98609 !important;
    animation: concernPulse 1.2s ease-in-out 2;
}
.concern-row.highlight-row td:first-child { border-left: 5px solid #C98609 !important; }
.concern-row.highlight-row td:last-child { border-right: 2px solid #C98609 !important; }
@keyframes concernPulse { 0%,100% { filter: brightness(1); } 50% { filter: brightness(1.07); } }

.concern-status {
    display: inline-block;
    border-radius: 20px;
    padding: 6px 11px !important;
    font-size: 11.5px !important;
    font-family: "Inter",system-ui,sans-serif;
    font-weight: 700;
}

.concern-status.new { background: #fff3d9 !important; color: #c98609 !important; }
.concern-status.read { background: #e3f8ec !important; color: #17844d !important; }

/* Naipadala na ang email — kapalit ng "Email" button ang green na "Sent" */
.concern-sent-badge { display: inline-flex; align-items: center; background: #e3f8ec; color: #17844d; border: 1px solid #b9e6cf; border-radius: 8px; padding: 6px 12px; font-size: 12px; font-weight: 800; font-family: "Inter",system-ui,sans-serif; white-space: nowrap; }
.concern-sent-badge small { font-weight: 600; opacity: .8; margin-left: 4px; }

/* Pagination — parehong style ng Appointments page */
.history-pagination { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 22px; background: #F6FBF8; border-top: 1px solid #E6F2EC; }
.pagination-info { font-size: 13px; color: #6b8791; font-family: "Inter",system-ui,sans-serif; }
.page-notation { display: flex; align-items: center; gap: 6px; }
.page-link-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 34px; padding: 0 10px; border-radius: 9px; border: 1px solid #D1E7DD; background: #fff; color: #0E6B5E !important; font-size: 13px; font-weight: 700; text-decoration: none; font-family: "Inter",system-ui,sans-serif; }
.page-link-btn:hover { background: #EAF7F0; border-color: #0E6B5E; }
.page-link-btn.active { background: linear-gradient(135deg, #149A72, #0A4A41) !important; color: #fff !important; border-color: transparent; }
/* Empty state — katulad sa patient My Appointments */
.empty-state { text-align: center; padding: 40px 20px; background: #ffffff; color: #91a5ac; }
.empty-state i { font-size: 38px; color: #b8dfe6; display: block; margin-bottom: 10px; }
.empty-state p { font-size: 14px; color: #8aa0a8; line-height: 1.6; }
/* Status badge — katulad sa patient */
.status-badge { display: inline-block; border-radius: 20px; padding: 6px 11px !important; font-size: 11.5px !important; font-family: "Inter",system-ui,sans-serif; font-weight: 700; }

</style>

<style>
/* Admin Back to Dashboard — katulad sa patient (green) */
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
    box-shadow: 0 8px 20px rgba(14,107,94,.22) !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
}
a.btn[href="dashboard.php"]:hover,
a.btn[href*="dashboard.php"]:hover {
    background: linear-gradient(135deg, #0E6B5E, #083A32) !important;
    color: #ffffff !important;
    transform: translateY(-1px);
    box-shadow: 0 12px 26px rgba(14,107,94,.28) !important;
}
</style>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">

<!-- Base styles ng admin portal — kailangan para tama ang topbar
     (.topbar, .page-title, .admin-info, .admin-avatar). -->
<link rel="stylesheet" href="../assets/style.css?v=2">
</head>


<body style="background:#f6fbf8;">

<?php include __DIR__ . '/includes.php'; ?>

<div class="concern-wrapper">

    <div class="d-flex gap-2 flex-wrap mb-3">
    <a href="dashboard.php" class="btn mb-0">
        <i class="bi bi-arrow-left-circle-fill me-1"></i>Back to Dashboard
    </a>
    <?php if ((int)($_GET['highlight'] ?? 0) > 0): ?>
    <a href="notifications.php" class="btn mb-0" style="background:#ffffff !important; color:#0E6B5E !important; border:1.5px solid #0E6B5E !important;">
        <i class="bi bi-bell-fill me-1"></i>Back to Notifications
    </a>
    <?php endif; ?>
    </div>

    <div class="patient-card">

        <div class="patient-card-header">
            <div>
                <h3><i class="bi bi-exclamation-circle-fill"></i> Appointment Concerns</h3>
                <p>View patient concerns about their appointments</p>
            </div>
            <?php if ($unreadCount > 0): ?>
            <form method="POST" class="mb-0">
<?= csrfField() ?>
                <input type="hidden" name="action" value="read_all">
                <button type="submit" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-check2-all me-1"></i> Mark All as Read
                </button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (empty($notifications)): ?>

        <div class="empty-state">
            <i class="bi bi-bell-slash"></i>
            <p>No notifications yet.<br><small style="color:#8aa0a8; font-size:12px;">Patient concerns will appear here.</small></p>
        </div>

        <?php else: ?>

        <div class="table-responsive admin-concern-scroll">
            <table class="table patient-table align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Patient name</th>
                        <th>Time of appointment</th>
                        <th>Dental service</th>
                        <th>Assigned dentist</th>
                        <th>Appointment concern</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($notifications as $idx => $notification): ?>
                    <tr id="concern-<?= (int)$notification['id'] ?>" class="concern-row <?= ($highlightId > 0 && $highlightId === (int)$notification['id']) ? 'highlight-row' : '' ?>" style="cursor:pointer;" title="Click to view full concern"
                        data-id="<?= (int)$notification['id'] ?>"
                        data-is-read="<?= (int)$notification['is_read'] ?>"
                        data-title="<?= e($notification['title']) ?>"
                        data-message="<?= e($notification['message']) ?>"
                        data-patient="<?= e(($notification['patient_name'] ?? 'Unknown Patient') . ' (' . ($notification['patient_number'] ?? '') . ')') ?>"
                        data-date="<?= e($notification['appointment_date'] ? date('M d, Y', strtotime($notification['appointment_date'])) : '—') ?>"
                        data-time="<?= e($notification['start_time'] ? date('h:i A', strtotime($notification['start_time'])) : '—') ?>"
                        data-end-time="<?= e(!empty($notification['end_time']) ? date('h:i A', strtotime($notification['end_time'])) : '—') ?>"
                        data-dentist="<?= e($notification['dentist_name'] ?? '—') ?>"
                        data-service="<?= e($notification['service_type'] ?? '—') ?>"
                        data-created="<?= e(date('M d, Y h:i A', strtotime($notification['created_at']))) ?>">
                        <td><?= (int) $idx + 1 ?></td>
                        <td>
                            <strong><?= e($notification['patient_name'] ?? 'Unknown Patient') ?></strong><br>
                            <small class="concern-patnum"><?= e($notification['patient_number'] ?? '') ?></small>
                        </td>
                        <td class="concern-nowrap">
                            <?php
                            $conApptDate = trim((string)($notification['appointment_date'] ?? ''));
                            $conStartTime = trim((string)($notification['start_time'] ?? ''));
                            $conEndTime = trim((string)($notification['end_time'] ?? ''));
                            ?>
                            <?php if ($conApptDate !== ''): ?>
                                <strong><?= e(date('M d, Y', strtotime($conApptDate))) ?></strong>
                                <?php if ($conStartTime !== ''): ?>
                                    <br>
                                    <small class="concern-time">
                                        <?= e(date('h:i A', strtotime($conStartTime))) ?>
                                        <?php if ($conEndTime !== ''): ?>
                                            - <?= e(date('h:i A', strtotime($conEndTime))) ?>
                                        <?php endif; ?>
                                    </small>
                                <?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?= e($notification['service_type'] ?? '—') ?></td>
                        <td><?= e($notification['dentist_name'] ?? '—') ?></td>
                        <td class="concern-cell">
                            <div class="concern-msg-title" title="<?= e($notification['title']) ?>"><?= e($notification['title']) ?></div>
                            <div class="concern-msg-body" title="<?= e($notification['message']) ?>"><?= e($notification['message']) ?></div>
                        </td>
                        <td class="concern-status-cell">
                            <?php if ((int)$notification['is_read'] === 0): ?>
                                <span class="concern-status new">UNREAD</span>
                            <?php else: ?>
                                <span class="concern-status read">READ</span>
                            <?php endif; ?>
                        </td>
                        <td class="concern-action-cell">
                            <div class="d-flex align-items-center gap-1 flex-wrap">
                            <span class="concern-read-slot">
                            <?php if ((int)$notification['is_read'] === 0): ?>
                            <form method="POST" class="d-inline concern-read-form">
<?= csrfField() ?>
                                <input type="hidden" name="action" value="read">
                                <input type="hidden" name="notification_id" value="<?= (int)$notification['id'] ?>">
                                <button type="submit" class="btn btn-sm" style="background: linear-gradient(135deg, #149A72, #0A4A41); color:#fff; border:none; border-radius:8px; padding:6px 12px; font-size:12px; font-weight:700;">
                                    <i class="bi bi-check2 me-1"></i> Read
                                </button>
                            </form>
                            <?php else: ?>
                                <span style="color:#17844d; font-size:12px; font-weight:700;"><i class="bi bi-check2-all me-1"></i>Read</span>
                            <?php endif; ?>
                            </span>
                            <?php $concernMail = trim((string)($notification['patient_email'] ?? '')); ?>
                            <?php $concernSentAt = trim((string)($notification['email_sent_at'] ?? '')); ?>
                            <?php if ($concernMail === ''): ?>
                                <button
                                    type="button"
                                    class="btn btn-sm"
                                    style="background:#F1F5F4; color:#8aa0a8; border:1.5px dashed #C6D5D0; border-radius:8px; padding:6px 12px; font-size:12px; font-weight:700; cursor:not-allowed;"
                                    disabled
                                    title="Patient has no email address on record"
                                    data-tip="No Email On Record">
                                    <i class="bi bi-envelope me-1"></i> No Email
                                </button>
                            <?php elseif ($concernSentAt === ''): ?>
                                <button
                                    type="button"
                                    class="btn btn-sm js-concern-email-btn"
                                    style="background:#EAF7F0; color:#0E6B5E; border:1.5px solid #0E6B5E; border-radius:8px; padding:6px 12px; font-size:12px; font-weight:800;"
                                    data-notification-id="<?= (int)$notification['id'] ?>"
                                    data-to="<?= e($concernMail) ?>"
                                    data-subject="<?= e(buildConcernEmailSubject()) ?>"
                                    data-body="<?= e(buildConcernEmailBody($notification)) ?>"
                                    data-tip="Send Reschedule or Cancel Options"
                                    title="Email patient">
                                    <i class="bi bi-envelope me-1"></i> Email
                                </button>
                            <?php else: ?>
                                <span
                                    class="concern-sent-badge"
                                    data-tip="Email sent <?= e(date('M d, Y h:i A', strtotime($concernSentAt))) ?>"
                                    title="Email sent <?= e(date('M d, Y h:i A', strtotime($concernSentAt))) ?>">
                                    <i class="bi bi-check2-circle me-1"></i> Sent
                                    <small><?= e(date('M d, g:i A', strtotime($concernSentAt))) ?></small>
                                </span>
                                <button
                                    type="button"
                                    class="btn btn-sm js-concern-resend-btn"
                                    style="background:#ffffff; color:#0E6B5E; border:1.5px solid #A8D5C4; border-radius:8px; padding:6px 12px; font-size:12px; font-weight:700;"
                                    data-notification-id="<?= (int)$notification['id'] ?>"
                                    data-tip="Resend email automatically"
                                    title="Resend email to patient">
                                    <i class="bi bi-arrow-repeat"></i> Resend
                                </button>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($concernTotal > 0): ?>
        <div class="history-pagination">
            <span class="pagination-info">
                Showing all <?= (int) $concernTotal ?> concern<?= $concernTotal === 1 ? '' : 's' ?>
            </span>
            <?php if ($concernTotal > 10): ?>
            <span class="pagination-info" style="display:inline-flex;align-items:center;gap:7px;">
                <i class="bi bi-mouse"></i> Scroll down — 10 shown at a time
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>

    </div>

</div>

<!-- Concern View Modal — Click row to read -->
<div class="modal fade" id="concernViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width:640px;">
    <div class="modal-content" style="border-radius:18px; overflow:visible; border:1px solid #C9E9DA; box-shadow:0 20px 50px rgba(10,74,65,.22);">
      <div class="modal-header" style="background: linear-gradient(135deg, #0E6B5E, #0A4A41); color:#fff; border:none; padding:18px 22px;">
        <h5 class="modal-title" style="font-weight:800; font-family:'Plus Jakarta Sans',sans-serif; font-size:16.5px; display:flex; align-items:center; gap:9px;">
          <i class="bi bi-exclamation-circle-fill"></i> Appointment Concern
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body" style="padding:22px; background:#F7FDFB;">

        <!-- PATIENT -->
        <div style="display:flex; align-items:center; gap:13px; background:#fff; border:1px solid #E6F2EC; border-radius:14px; padding:14px 16px; margin-bottom:20px;">
          <span id="concernModalAvatar" style="display:inline-flex; align-items:center; justify-content:center; width:44px; height:44px; border-radius:50%; background:linear-gradient(135deg,#149A72,#0A4A41); color:#fff; font-weight:800; font-size:16px; flex-shrink:0;">P</span>
          <div style="min-width:0;">
            <div style="font-size:10.5px; font-weight:800; color:#4A5F68; letter-spacing:.05em; text-transform:uppercase;">Patient</div>
            <div id="concernModalPatient" style="font-weight:800; color:#0F3546; font-size:15.5px; line-height:1.3; word-break:break-word;"></div>
          </div>
        </div>

        <!-- APPOINTMENT -->
        <div style="font-size:10.5px; font-weight:800; color:#0A4A41; letter-spacing:.05em; text-transform:uppercase; margin-bottom:9px;">
          <i class="bi bi-calendar-event me-1"></i>Appointment
        </div>
        <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; margin-bottom:20px;">
          <div style="background:#fff; border:1px solid #E6F2EC; border-radius:12px; padding:11px 14px;">
            <div style="font-size:10px; font-weight:800; color:#4A5F68; letter-spacing:.04em; text-transform:uppercase; margin-bottom:3px;">Date</div>
            <div id="concernModalDate" style="font-weight:700; color:#0F3546; font-size:13.5px;">—</div>
          </div>
          <div style="background:#fff; border:1px solid #E6F2EC; border-radius:12px; padding:11px 14px;">
            <div style="font-size:10px; font-weight:800; color:#4A5F68; letter-spacing:.04em; text-transform:uppercase; margin-bottom:3px;">Time</div>
            <div id="concernModalTime" style="font-weight:700; color:#0F3546; font-size:13.5px;">—</div>
          </div>
          <div style="background:#fff; border:1px solid #E6F2EC; border-radius:12px; padding:11px 14px;">
            <div style="font-size:10px; font-weight:800; color:#4A5F68; letter-spacing:.04em; text-transform:uppercase; margin-bottom:3px;">Dental service</div>
            <div id="concernModalService" style="font-weight:700; color:#0F3546; font-size:13.5px;">—</div>
          </div>
          <div style="background:#fff; border:1px solid #E6F2EC; border-radius:12px; padding:11px 14px;">
            <div style="font-size:10px; font-weight:800; color:#4A5F68; letter-spacing:.04em; text-transform:uppercase; margin-bottom:3px;">Assigned dentist</div>
            <div id="concernModalDentist" style="font-weight:700; color:#0F3546; font-size:13.5px;">—</div>
          </div>
        </div>

        <!-- CONCERN -->
        <div style="font-size:10.5px; font-weight:800; color:#0A4A41; letter-spacing:.05em; text-transform:uppercase; margin-bottom:9px;">
          <i class="bi bi-chat-left-quote me-1"></i>Patient's Concern
        </div>
        <div style="background:#fff; border:1px solid #E6F2EC; border-left:4px solid #0E6B5E; border-radius:12px; padding:16px 18px; margin-bottom:18px;">
          <div id="concernModalTitle" style="font-weight:800; color:#0F3546; font-size:14.5px; line-height:1.45; margin-bottom:9px;"></div>
          <div id="concernModalMessage" style="color:#2F3E45; font-size:13.5px; line-height:1.75; white-space:pre-wrap; word-break:break-word;"></div>
        </div>

        <!-- SUBMITTED -->
        <div style="display:flex; align-items:center; gap:7px; justify-content:flex-end; font-size:11.5px; color:#4A5F68; font-weight:600;">
          <i class="bi bi-clock"></i> Submitted <span id="concernModalCreated" style="font-weight:700;"></span>
        </div>

      </div>
      <div class="modal-footer" style="background:#F6FBF8; border-top:1px solid #E6F2EC; padding:12px 22px;">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal" style="border:1.5px solid #CFE3DA; color:#4A5F68; border-radius:10px; font-weight:800; padding:9px 24px;">Close</button>
      </div>
    </div>
  </div>
</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>
<script>
(function(){
    function closeAdminTab(){
        try{window.close();}catch(e){}
        setTimeout(function(){
            try{if(!window.closed){window.open('', '_self');window.close();}}catch(e2){}
            setTimeout(function(){if(!window.closed) window.location.href="dashboard.php";},150);
        },200);
    }
    function bindClose(el){
        if(!el || el.dataset.closeBound==="1") return;
        el.dataset.closeBound="1";
        el.addEventListener("click", function(e){
            if(window.location.hash==="#notab"){e.preventDefault();closeAdminTab();}
        });
    }
    function init(){
        var backs=document.querySelectorAll('a[href="dashboard.php"],a[href*="dashboard.php"]');
        backs.forEach(function(b){
            if(!b.textContent||b.textContent.toLowerCase().indexOf("back to dashboard")===-1) return;
            bindClose(b);
            var icon=b.querySelector("i.bi-arrow-left");
            if(icon){icon.classList.remove("bi-arrow-left");icon.classList.add("bi-arrow-left-circle-fill");}
        });
        document.addEventListener("click", function(ev){
            var t=ev.target.closest('a[href*="dashboard.php"]');
            if(!t||!t.textContent||t.textContent.toLowerCase().indexOf("back to dashboard")===-1) return;
            if(window.location.hash==="#notab"){ev.preventDefault();closeAdminTab();}
        });
    }
    if(document.readyState==="loading") document.addEventListener("DOMContentLoaded", init);
    else init();
})();
</script>
<script>
// Click row to read full concern — admin mababasa ang concern
(function(){
    function initConcernRows(){
        var modalEl = document.getElementById("concernViewModal");
        if (!modalEl) return;
        var modal = null;
        try { modal = new bootstrap.Modal(modalEl); } catch(e) { modal = null; }
        document.querySelectorAll(".concern-row").forEach(function(row){
            row.addEventListener("click", function(e){
                // Huwag mag-open kapag pinindot ang Read button/form
                if (e.target.closest("form, button, a")) return;
                var title = row.getAttribute("data-title") || "";
                var message = row.getAttribute("data-message") || "";
                var patient = row.getAttribute("data-patient") || "";
                var date = row.getAttribute("data-date") || "";
                var time = row.getAttribute("data-time") || "";
                var endTime = row.getAttribute("data-end-time") || "";
                var dentist = row.getAttribute("data-dentist") || "";
                var service = row.getAttribute("data-service") || "";
                var created = row.getAttribute("data-created") || "";
                var notifId = row.getAttribute("data-id") || "";
                var isRead = row.getAttribute("data-is-read") || "1";

                var avatarEl = document.getElementById("concernModalAvatar");
                var pEl = document.getElementById("concernModalPatient");
                var dateEl = document.getElementById("concernModalDate");
                var timeEl = document.getElementById("concernModalTime");
                var serviceEl = document.getElementById("concernModalService");
                var dentistEl = document.getElementById("concernModalDentist");
                var tEl = document.getElementById("concernModalTitle");
                var msgEl = document.getElementById("concernModalMessage");
                var cEl = document.getElementById("concernModalCreated");

                // Avatar: initials ng pangalan ng patient
                if (avatarEl) {
                    var initials = patient
                        .replace(/\(.*\)/, "")
                        .trim()
                        .split(/\s+/)
                        .filter(Boolean)
                        .slice(0, 2)
                        .map(function(w){ return w.charAt(0).toUpperCase(); })
                        .join("") || "P";
                    avatarEl.textContent = initials;
                }

                if (pEl) pEl.textContent = patient || "—";
                if (dateEl) dateEl.textContent = (date && date !== "—") ? date : "—";
                if (timeEl) {
                    timeEl.textContent = (time && time !== "—")
                        ? time + ((endTime && endTime !== "—") ? " - " + endTime : "")
                        : "—";
                }
                if (serviceEl) serviceEl.textContent = (service && service !== "—") ? service : "—";
                if (dentistEl) dentistEl.textContent = (dentist && dentist !== "—") ? dentist : "—";
                if (tEl) tEl.textContent = title || "—";
                if (msgEl) msgEl.textContent = message || "—";
                if (cEl) cEl.textContent = created || "—";
                if (modal) modal.show();
                // Automated: kapag nabasa ni admin (click row), automatic Read
                if (isRead === "0" && notifId) {
                    row.setAttribute("data-is-read", "1");
                    var statusCell = row.querySelector(".concern-status-cell");
                    if (statusCell) statusCell.innerHTML = '<span class="concern-status read">READ</span>';
                    var actionCell = row.querySelector(".concern-read-slot");
                    if (actionCell) actionCell.innerHTML = '<span style="color:#17844d; font-size:12px; font-weight:700;"><i class="bi bi-check2-all me-1"></i>Read</span>';
                    var fd = new FormData();
                    fd.append("action", "read");
                    fd.append("notification_id", notifId);
                    var csrfEl = document.querySelector('input[name="csrf_token"]');
                    if (csrfEl) fd.append("csrf_token", csrfEl.value);
                    try { fetch(window.location.pathname, { method: "POST", body: fd }); } catch(err) {}
                }
            });
        });
    }
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", initConcernRows);
    else initConcernRows();
})();
// Eksaktong 10 concerns lang ang nakikita — sukatin ang taas ng header at
// row, tapos itakda ang max-height ng scroll box. Dahil sinusukat ito ng
// browser, umaangkop sa anong lapad ng screen (70px row sa desktop, ~110px
// sa mobile) at palaging 10 rows ang nakikita.
(function(){
    var ROWS_PER_VIEW = 10;

    function sizeConcernScroll(){
        var box = document.querySelector('.admin-concern-scroll');
        if (!box) return;

        var rows = box.querySelectorAll('table tbody tr');
        var head = box.querySelector('table thead th');

        // Walang rows (empty state) — ibaba ang box.
        if (!rows.length) {
            box.style.maxHeight = '';
            box.classList.remove('is-measured');
            return;
        }

        var headH = head ? head.getBoundingClientRect().height : 0;
        var rowH = rows[0].getBoundingClientRect().height;

        // Hindi makipaghatid sa browser (jsdom / old browser) — leave sa CSS.
        if (!rowH) return;

        var visible = Math.min(rows.length, ROWS_PER_VIEW);
        box.style.maxHeight = Math.ceil(headH + rowH * visible) + 'px';
        box.classList.add('is-measured');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sizeConcernScroll);
    } else {
        sizeConcernScroll();
    }

    // Muling sukatin pag natapos mag-load ang fonts (mag-iiba ang row height),
    // at sa bawat resize ng window.
    window.addEventListener('load', sizeConcernScroll);
    window.addEventListener('resize', sizeConcernScroll);
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(sizeConcernScroll).catch(function(){});
    }
})();

// Auto-scroll sa clinick na notification mula sa Notifications page
(function(){
    var hl = document.querySelector('.concern-row.highlight-row');
    if (hl) { setTimeout(function(){ try { hl.scrollIntoView({behavior:'smooth', block:'center'}); } catch(e){} }, 300); }
})();
// Dalhin ang #notab hash pabalik sa Notifications (para mag-close ang tab sa dulo)
(function(){
    document.querySelectorAll('a[href="notifications.php"]').forEach(function(a){
        a.addEventListener('click', function(){
            try {
                if (window.location.hash && a.getAttribute('href').indexOf('#') === -1) {
                    a.href = a.getAttribute('href') + window.location.hash;
                }
            } catch(e) {}
        });
    });
})();
</script>

<!-- =========================================================
     CONCERN EMAIL PREVIEW — basahin muna bago ipadala
     ========================================================= -->
<div id="concernEmailPreview" class="admin-logout-overlay" style="display:none;">
    <div class="admin-logout-box" style="max-width:600px;">
        <div class="admin-logout-head">
            <span class="admin-logout-head-icon"><i class="bi bi-envelope"></i></span>
            <h5>Send Email to Patient</h5>
            <button type="button" class="admin-logout-x" id="concernEmailClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="admin-logout-body" style="text-align:left;">
            <div style="display:flex; gap:14px; align-items:flex-start; background:#FDECEC; border:1px solid #F5C6C6; border-radius:12px; padding:14px 16px; margin-bottom:18px;">
                <i class="bi bi-info-circle-fill" style="color:#C0392B; font-size:19px; flex-shrink:0;"></i>
                <p style="color:#7A2B24; font-size:13.5px; line-height:1.6; font-weight:600; margin:0;">
                    Please review the complete message before sending. The patient will be asked whether they
                    <strong> still wish to reschedule</strong> or <strong>cancel</strong> the appointment.
                </p>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:11px; font-weight:800; color:#57727d; letter-spacing:.04em; text-transform:uppercase; margin-bottom:5px;">Recipient (To)</label>
                <div id="concernEmailTo" style="font-weight:800; color:#143f51; font-size:14px; background:#F7FDFB; border:1px solid #E6F2EC; border-radius:10px; padding:11px 14px; word-break:break-all;"></div>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:11px; font-weight:800; color:#57727d; letter-spacing:.04em; text-transform:uppercase; margin-bottom:5px;">Subject</label>
                <div id="concernEmailSubject" style="font-weight:700; color:#143f51; font-size:13.5px; background:#F7FDFB; border:1px solid #E6F2EC; border-radius:10px; padding:11px 14px;"></div>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:11px; font-weight:800; color:#57727d; letter-spacing:.04em; text-transform:uppercase; margin-bottom:5px;">
                    Reply-To Address
                </label>
                <div style="display:flex; align-items:center; gap:10px; background:#EAF7F0; border:1px solid #C9E9DA; border-radius:10px; padding:11px 14px;">
                    <i class="bi bi-reply-all-fill" style="color:#0E6B5E; font-size:16px; flex-shrink:0;"></i>
                    <div>
                        <div id="concernEmailReplyTo" style="font-weight:800; color:#0A4A41; font-size:13.5px; word-break:break-all;"><?= e(CLINIC_SMTP_FROM_NAME . ' <' . CLINIC_SMTP_USER . '>') ?></div>
                        <div style="color:#4d6873; font-size:12px; font-weight:600; margin-top:2px;">The patient's reply will be sent here when they reply to the email.</div>
                    </div>
                </div>
            </div>

            <div>
                <label style="display:block; font-size:11px; font-weight:800; color:#57727d; letter-spacing:.04em; text-transform:uppercase; margin-bottom:5px;">Message Content</label>
                <pre id="concernEmailBody" style="font-family:'Inter','DM Sans',system-ui,sans-serif; font-size:13px; line-height:1.65; color:#3B4F58; font-weight:500; background:#F7FDFB; border:1px solid #E6F2EC; border-radius:12px; padding:16px 18px; max-height:280px; overflow:auto; margin:0; white-space:pre-wrap; word-break:break-word;"></pre>
            </div>
        </div>
        <div class="admin-logout-foot">
            <button type="button" class="btn admin-delete-btn-cancel" id="concernEmailCancel">Cancel</button>
            <button type="button" class="btn admin-send-email-btn" id="concernEmailSend"><i class="bi bi-send me-1"></i>Send Email</button>
        </div>
    </div>
</div>

<form method="POST" id="concernEmailForm" style="display:none;">
<?= csrfField() ?>
    <input type="hidden" name="action" value="send_concern_email">
    <input type="hidden" name="notification_id" id="concernEmailNotifId" value="">
</form>

<style>
.admin-logout-overlay { position: fixed; inset: 0; background: rgba(10,59,52,.55); z-index: 2000; padding: 20px; align-items: center; justify-content: center; }
.admin-logout-box { background: #ffffff; border-radius: 18px; border: 1px solid #C9E9DA; box-shadow: 0 18px 45px rgba(0,0,0,.25); max-width: 440px; width: 100%; overflow: hidden; font-family: "Inter", "DM Sans", system-ui, sans-serif; animation: adminLogoutPop .18s ease; }
@keyframes adminLogoutPop { from { transform: scale(.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.admin-logout-head { display: flex; align-items: center; gap: 12px; padding: 18px 22px; background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%); border-bottom: 1px solid #C9E9DA; }
.admin-logout-head-icon { width: 38px; height: 38px; border-radius: 11px; background: linear-gradient(135deg, #0E6B5E, #0A4A41); color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 4px 10px rgba(14,107,94,.25); flex-shrink: 0; }
.admin-logout-head h5 { color: #143f51; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; font-size: 17px; margin: 0; flex: 1; }
.admin-logout-x { background: #ffffff; border: 1px solid #D1E7DD; color: #57727d; width: 30px; height: 30px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; flex-shrink: 0; }
.admin-logout-x:hover { background: #EAF7F0; color: #0E6B5E; }
.admin-logout-body { padding: 24px 26px 8px; }
.admin-logout-foot { display: flex; gap: 12px; justify-content: center; padding: 18px 22px 22px; background: #F6FBF8; border-top: 1px solid #E6F2EC; margin-top: 20px; }
.admin-logout-foot .btn { border-radius: 12px; font-weight: 800; font-size: 14.5px; padding: 12px 30px; letter-spacing: -.01em; transition: transform .15s ease, box-shadow .15s ease, filter .15s ease; }
.admin-logout-foot .btn:active { transform: translateY(1px); }

.admin-delete-btn-cancel { background: #ffffff !important; border: 1.5px solid #CFE3DA !important; color: #4A5F68 !important; box-shadow: 0 3px 8px rgba(10,74,65,.06); }
.admin-delete-btn-cancel:hover { background: linear-gradient(135deg, #EAF7F0 0%, #D9F2E6 100%) !important; border-color: #A8D5C4 !important; color: #0A4A41 !important; transform: translateY(-1px); box-shadow: 0 8px 18px rgba(10,74,65,.14) !important; }

.admin-send-email-btn { background: linear-gradient(135deg, #149A72 0%, #0A4A41 100%) !important; border: none !important; color: #ffffff !important; box-shadow: 0 6px 16px rgba(14,107,94,.32) !important; }
.admin-send-email-btn:hover { filter: brightness(1.08); color: #ffffff !important; transform: translateY(-1px); box-shadow: 0 10px 22px rgba(14,107,94,.38) !important; }
.admin-send-email-btn.is-busy { pointer-events: none; opacity: .75; filter: saturate(.7); }
.admin-send-email-btn .spinner-border { width: 14px; height: 14px; border-width: 2px; border-top-color: #ffffff; }
</style>

<script>
(function()
{
    function closeConcernEmail()
    {
        var overlay = document.getElementById("concernEmailPreview");
        if (!overlay) return;
        overlay.style.display = "none";
        document.body.style.overflow = "";
    }

    function initConcernEmailPreview()
    {
        var overlay = document.getElementById("concernEmailPreview");
        if (!overlay || overlay.dataset.bound === "1") return;
        overlay.dataset.bound = "1";

        var toEl = document.getElementById("concernEmailTo");
        var subjectEl = document.getElementById("concernEmailSubject");
        var bodyEl = document.getElementById("concernEmailBody");
        var notifEl = document.getElementById("concernEmailNotifId");
        var form = document.getElementById("concernEmailForm");

        document.querySelectorAll(".js-concern-email-btn").forEach(function(btn)
        {
            btn.addEventListener("click", function(e)
            {
                // Huwag buksan ang concern view modal pag pindot ang Email button
                e.preventDefault();
                e.stopPropagation();

                if (toEl) toEl.textContent = this.dataset.to || '—';
                if (subjectEl) subjectEl.textContent = this.dataset.subject || '—';
                if (bodyEl) bodyEl.textContent = this.dataset.body || '';
                if (notifEl) notifEl.value = this.dataset.notificationId || '';

                overlay.style.display = "flex";
                document.body.style.overflow = "hidden";

                var send = document.getElementById("concernEmailSend");
                if (send) {
                    send.classList.remove("is-busy");
                    send.innerHTML = '<i class="bi bi-send me-1"></i>Send Email';
                    send.focus();
                }
            });
        });

        document.querySelectorAll(".js-concern-resend-btn").forEach(function(btn)
        {
            btn.addEventListener("click", function(e)
            {
                e.preventDefault();
                e.stopPropagation();

                var nId = this.dataset.notificationId || "";
                if (!form || !notifEl || nId === "") return;

                notifEl.value = nId;
                this.disabled = true;
                this.style.pointerEvents = "none";
                this.style.opacity = "0.75";
                this.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Sending…';

                form.submit();
            });
        });

        document.getElementById("concernEmailCancel").addEventListener("click", closeConcernEmail);
        document.getElementById("concernEmailClose").addEventListener("click", closeConcernEmail);
        overlay.addEventListener("click", function(e)
        {
            if (e.target === overlay) closeConcernEmail();
        });
        document.addEventListener("keydown", function(e)
        {
            if (overlay.style.display === "none") return;
            if (e.key === "Escape") closeConcernEmail();
        });

        document.getElementById("concernEmailSend").addEventListener("click", function()
        {
            if (!form || !notifEl || notifEl.value === "") { closeConcernEmail(); return; }

            this.classList.add("is-busy");
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Sending…';

            form.submit();
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initConcernEmailPreview);
    } else {
        initConcernEmailPreview();
    }
})();
</script>

<script>
/* Topbar: date + live clinic time — parehong pattern ng Patients page.
   Isinasingit sa .admin-area na galing sa includes.php. */
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
                if (!el) return;
                el.textContent = new Date().toLocaleTimeString('en-PH',{timeZone:'Asia/Manila',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});
            }catch(e){}
        }
        tick(); setInterval(tick,1000);
    }
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',addTopbarDateTime);}else{addTopbarDateTime();}
})();
</script>
</body>

</html>
