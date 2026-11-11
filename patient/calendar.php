<?php
/**
 * ABALOS DENTAL CLINIC
 * PATIENT CALENDAR + DAY-BASED BOOKING
 *
 * Flow:
 * 1. Patient clicks a date.
 * 2. The system checks if the clinic is open.
 * 3. Patient selects dentist + service.
 * 4. The system shows available hourly slots.
 * 5. Patient clicks an available slot.
 * 6. Patient reviews and confirms the booking directly in the modal (no redirect to book_appointment.php form).
 * 7. The appointment is saved automatically as Pending and awaits confirmation.
 *
 * IMPORTANT:
 * - Uses the existing appointments and dentist_schedules tables.
 * - Uses clinic_business_hours + clinic_settings for clinic-wide rules.
 * - Does NOT declare e(), preventing the previous Cannot redeclare e() error.
 */

require_once __DIR__ . '/../config.php';

if (
    !isset($_SESSION['user_id']) ||
    strtolower(trim($_SESSION['role'] ?? '')) !== 'patient'
) {
    header('Location: ../login.php');
    exit;
}

if (!function_exists('calendar_escape')) {
    function calendar_escape($value)
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$userId = (int)($_SESSION['user_id'] ?? 0);

/* -------------------------------------------------------------
   PATIENT
------------------------------------------------------------- */
$stmt = $pdo->prepare("SELECT id FROM patients WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$patientId = (int)($stmt->fetchColumn() ?: 0);

if ($patientId <= 0 && !empty($_SESSION['email'])) {
    $stmt = $pdo->prepare("SELECT id FROM patients WHERE email = ? LIMIT 1");
    $stmt->execute([$_SESSION['email']]);
    $patientId = (int)($stmt->fetchColumn() ?: 0);
}
if ($patientId <= 0 && $userId > 0) {
    $stmt = $pdo->prepare("SELECT email, first_name, last_name, username FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u) {
        $uEmail = !empty($u['email']) ? $u['email'] : (strpos($u['username'] ?? '', '@') !== false ? $u['username'] : '');
        if ($uEmail) {
            $stmt = $pdo->prepare("SELECT id FROM patients WHERE email = ? LIMIT 1");
            $stmt->execute([$uEmail]);
            $patientId = (int)($stmt->fetchColumn() ?: 0);
        }
        if ($patientId <= 0) {
            require_once __DIR__ . '/../functions.php';
            $pNum = function_exists('generateShortPatientNumber') ? generateShortPatientNumber($pdo) : ('PAT-' . date('Y') . '-' . sprintf('%04d', $userId));
            $fn = !empty($u['first_name']) ? $u['first_name'] : 'Patient';
            $ln = !empty($u['last_name']) ? $u['last_name'] : '';
            $ins = $pdo->prepare("INSERT INTO patients (patient_number, first_name, last_name, email, user_id) VALUES (?, ?, ?, ?, ?)");
            $ins->execute([$pNum, $fn, $ln, $uEmail, $userId]);
            $patientId = (int)$pdo->lastInsertId();
        }
    }
}

/* -------------------------------------------------------------
   HANDLE DIRECT BOOKING FROM CALENDAR (AJAX POST)
------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'book_from_calendar') {
    header('Content-Type: application/json; charset=utf-8');

    if (!csrfValid()) {
        echo json_encode(['success' => false, 'message' => 'Security token invalid or session expired. Please refresh the page.']);
        exit;
    }

    if ($patientId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Patient record could not be found. Please contact clinic support.']);
        exit;
    }

    $dentistId = (int)($_POST['dentist_id'] ?? 0);
    $selectedDate = trim($_POST['appointment_date'] ?? '');
    $service = trim($_POST['service'] ?? '');
    $startTime = trim($_POST['start_time'] ?? '');
    $endTime = trim($_POST['end_time'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($dentistId <= 0 || $selectedDate === '' || $service === '' || $startTime === '') {
        echo json_encode(['success' => false, 'message' => 'Please provide all required appointment details.']);
        exit;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
        echo json_encode(['success' => false, 'message' => 'Invalid appointment date format.']);
        exit;
    }

    if ($selectedDate < date('Y-m-d')) {
        echo json_encode(['success' => false, 'message' => 'You cannot book an appointment on a past date.']);
        exit;
    }

    if ((int)date('w', strtotime($selectedDate)) === 0) {
        echo json_encode(['success' => false, 'message' => 'The clinic is closed on Sundays. Please choose another date.']);
        exit;
    }

    // Verify dentist
    $dentStmt = $pdo->prepare("SELECT id, dentist_name, rest_day FROM dentists WHERE id = ? AND status = 'Active' LIMIT 1");
    $dentStmt->execute([$dentistId]);
    $dentistRow = $dentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$dentistRow) {
        echo json_encode(['success' => false, 'message' => 'The selected dentist is currently unavailable.']);
        exit;
    }

    // Check dentist rest day
    $dayOfWeek = (int)date('w', strtotime($selectedDate));
    $restDay = function_exists('getDentistRestDay') ? getDentistRestDay($dentistId) : null;
    if ($restDay === null && isset($dentistRow['rest_day']) && $dentistRow['rest_day'] !== null && $dentistRow['rest_day'] !== '') {
        $restDay = (int)$dentistRow['rest_day'];
    }
    if ($restDay !== null && $dayOfWeek === (int)$restDay) {
        $restName = function_exists('dentistRestDayName') ? dentistRestDayName($restDay) : date('l', strtotime($selectedDate));
        echo json_encode(['success' => false, 'message' => $dentistRow['dentist_name'] . ' is on rest day every ' . $restName . '. Please choose another date or dentist.']);
        exit;
    }
    if (function_exists('isDentistDayOff') && isDentistDayOff($dentistId, $selectedDate)) {
        echo json_encode(['success' => false, 'message' => $dentistRow['dentist_name'] . ' is on rest day on ' . date('M d, Y', strtotime($selectedDate)) . '.']);
        exit;
    }

    // Calculate end time if missing
    if (strlen($startTime) === 5) {
        $startTime .= ':00';
    }
    if ($endTime === '' || strlen($endTime) < 5) {
        $durStmt = $pdo->prepare("SELECT duration_minutes FROM clinic_services WHERE service_name = ? AND status = 'Active' LIMIT 1");
        $durStmt->execute([$service]);
        $duration = (int)($durStmt->fetchColumn() ?: 60);
        $startDt = DateTime::createFromFormat('Y-m-d H:i:s', $selectedDate . ' ' . $startTime);
        if (!$startDt) {
            $startDt = DateTime::createFromFormat('Y-m-d H:i', $selectedDate . ' ' . substr($startTime, 0, 5));
        }
        $endDt = clone $startDt;
        $endDt->modify('+' . $duration . ' minutes');
        $endTime = $endDt->format('H:i:s');
    } else if (strlen($endTime) === 5) {
        $endTime .= ':00';
    }

    $startHm = substr($startTime, 0, 5);
    $endHm = substr($endTime, 0, 5);
    if ($startHm < '08:00' || $endHm > '18:00' || $startHm >= $endHm) {
        echo json_encode(['success' => false, 'message' => 'Appointments must fall within clinic operating hours (8:00 AM to 6:00 PM).']);
        exit;
    }

    if ($startHm >= '12:00' && $startHm < '13:00') {
        echo json_encode(['success' => false, 'message' => '12:00 PM - 1:00 PM is clinic lunch break. Please choose another slot.']);
        exit;
    }

    if ($selectedDate === date('Y-m-d') && $startHm <= date('H:i')) {
        echo json_encode(['success' => false, 'message' => 'You cannot book a time slot that has already passed today.']);
        exit;
    }

    // Check dentist conflict
    $conflictDentist = $pdo->prepare("
        SELECT id, start_time, end_time FROM appointments
        WHERE dentist_id = ?
          AND appointment_date = ?
          AND status NOT IN ('Cancelled', 'Canceled', 'No-Show')
          AND start_time < ?
          AND end_time > ?
        LIMIT 1
    ");
    $conflictDentist->execute([$dentistId, $selectedDate, $endTime, $startTime]);
    if ($conflictDentist->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Schedule Conflict: This dentist already has a booking during this time slot. Please choose another slot.']);
        exit;
    }

    // Check patient conflict
    $conflictPatient = $pdo->prepare("
        SELECT id, start_time, end_time FROM appointments
        WHERE patient_id = ?
          AND appointment_date = ?
          AND status NOT IN ('Cancelled', 'Canceled', 'No-Show')
          AND start_time < ?
          AND end_time > ?
        LIMIT 1
    ");
    $conflictPatient->execute([$patientId, $selectedDate, $endTime, $startTime]);
    if ($conflictPatient->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Schedule Conflict: You already have an active appointment scheduled during this time window.']);
        exit;
    }

    // Check patient daily maximum appointments (limit: 2 appointments per day per patient)
    $dailyPatientCountStmt = $pdo->prepare("
        SELECT COUNT(*) FROM appointments
        WHERE patient_id = ?
          AND appointment_date = ?
          AND status NOT IN ('Cancelled', 'Canceled', 'No-Show')
    ");
    $dailyPatientCountStmt->execute([$patientId, $selectedDate]);
    $dailyAppointmentsCount = (int)$dailyPatientCountStmt->fetchColumn();
    if ($dailyAppointmentsCount >= 2) {
        echo json_encode([
            'success' => false,
            'message' => 'Appointment Limit Reached: You already have 2 appointments scheduled on ' . date('M d, Y', strtotime($selectedDate)) . '. Each patient account is limited to a maximum of 2 appointments per day.'
        ]);
        exit;
    }

    try {
        $insStmt = $pdo->prepare("
            INSERT INTO appointments (
                patient_id, dentist_id, appointment_date, start_time, end_time, service_type, status, notes
            ) VALUES (
                ?, ?, ?, ?, ?, ?, 'Pending', ?
            )
        ");
        $insStmt->execute([
            $patientId,
            $dentistId,
            $selectedDate,
            $startTime,
            $endTime,
            $service,
            $notes
        ]);

        $newAppointmentId = (int)$pdo->lastInsertId();

        require_once __DIR__ . '/../functions.php';
        if (function_exists('createNotification')) {
            try {
                createNotification(
                    $patientId,
                    $newAppointmentId,
                    'Appointment Request Submitted',
                    'Your appointment request for ' . date('M d, Y', strtotime($selectedDate)) . ' at ' . date('h:i A', strtotime($startTime)) . ' has been submitted and is waiting for confirmation.',
                    'Confirmation'
                );
            } catch (Throwable $e) {}
        }

        echo json_encode([
            'success' => true,
            'message' => 'Your appointment has been submitted successfully!',
            'appointment_id' => $newAppointmentId,
            'date_display' => date('M d, Y', strtotime($selectedDate)),
            'time_display' => date('h:i A', strtotime($startTime)) . ' - ' . date('h:i A', strtotime($endTime))
        ]);
        exit;

    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Unable to save appointment. Error: ' . $e->getMessage()]);
        exit;
    }
}

/* -------------------------------------------------------------
   CLINIC SETTINGS
------------------------------------------------------------- */
$clinicSettings = [
    'clinic_name' => 'Abalos Dental Clinic',
    'slot_interval_minutes' => 30,
    'max_patients_per_day' => 10
];

try {
    $stmt = $pdo->query("
        SELECT clinic_name, slot_interval_minutes, max_patients_per_day
        FROM clinic_settings
        ORDER BY id ASC
        LIMIT 1
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $clinicSettings = array_merge($clinicSettings, $row);
    }
} catch (Throwable $e) {
    // SQL file has not been installed yet; page can still display.
}

$slotInterval = max(15, (int)$clinicSettings['slot_interval_minutes']);
$dailyCapacity = max(1, (int)$clinicSettings['max_patients_per_day']);

/* -------------------------------------------------------------
   CLINIC BUSINESS HOURS
   Monday-Saturday: 8:00 AM-6:00 PM
   Sunday: CLOSED
------------------------------------------------------------- */
$businessHours = [
    0 => ['day_name' => 'Sunday', 'is_open' => 0, 'open_time' => null, 'close_time' => null],
    1 => ['day_name' => 'Monday', 'is_open' => 1, 'open_time' => '08:00:00', 'close_time' => '18:00:00'],
    2 => ['day_name' => 'Tuesday', 'is_open' => 1, 'open_time' => '08:00:00', 'close_time' => '18:00:00'],
    3 => ['day_name' => 'Wednesday', 'is_open' => 1, 'open_time' => '08:00:00', 'close_time' => '18:00:00'],
    4 => ['day_name' => 'Thursday', 'is_open' => 1, 'open_time' => '08:00:00', 'close_time' => '18:00:00'],
    5 => ['day_name' => 'Friday', 'is_open' => 1, 'open_time' => '08:00:00', 'close_time' => '18:00:00'],
    6 => ['day_name' => 'Saturday', 'is_open' => 1, 'open_time' => '08:00:00', 'close_time' => '18:00:00']
];

/* -------------------------------------------------------------
   ACTIVE DENTISTS
------------------------------------------------------------- */
$dentists = [];

try {
    try {
        $stmt = $pdo->query("
            SELECT id, dentist_name, specialization, rest_day
            FROM dentists
            WHERE status = 'Active'
            ORDER BY dentist_name
        ");
        $dentists = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        /* Fallback for databases without rest_day column yet. */
        $stmt = $pdo->query("
            SELECT id, dentist_name, specialization
            FROM dentists
            WHERE status = 'Active'
            ORDER BY dentist_name
        ");
        $dentists = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $dentists = [];
}

/* -------------------------------------------------------------
   ACTIVE DENTAL SERVICES
------------------------------------------------------------- */
$services = [];
try {
    $stmt = $pdo->query("
        SELECT service_name, duration_minutes
        FROM clinic_services
        WHERE status = 'Active'
        ORDER BY service_name
    " );
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $services = [];
}

/* Fallback - dati mong select service na may pagpipilian */
if (empty($services)) {
    $services = [
        ['service_name' => 'General Dentistry', 'duration_minutes' => 60],
        ['service_name' => 'Orthodontics (Braces)', 'duration_minutes' => 60],
        ['service_name' => 'Oral Surgery (Tooth Extraction or Bunot)', 'duration_minutes' => 90],
        ['service_name' => 'Cosmetic', 'duration_minutes' => 120],
        ['service_name' => 'Fillings or Pasta', 'duration_minutes' => 60],
        ['service_name' => 'Root Canal', 'duration_minutes' => 120],
        ['service_name' => 'Oral Prophylaxis or Cleaning', 'duration_minutes' => 60],
        ['service_name' => 'Denture', 'duration_minutes' => 60],
        ['service_name' => 'Teeth Whitening', 'duration_minutes' => 90],
        ['service_name' => 'Crown', 'duration_minutes' => 90],
        ['service_name' => 'Bridge', 'duration_minutes' => 90],
        ['service_name' => 'Veneers', 'duration_minutes' => 120],
        ['service_name' => 'Dental Check-up', 'duration_minutes' => 30],
    ];
}

/* -------------------------------------------------------------
   PATIENT'S APPOINTMENTS FOR CALENDAR
------------------------------------------------------------- */
$events = [];

if ($patientId > 0) {
    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.appointment_date,
            a.start_time,
            a.end_time,
            a.service_type,
            a.status,
            d.dentist_name
        FROM appointments a
        LEFT JOIN dentists d ON d.id = a.dentist_id
        WHERE a.patient_id = ?
        AND a.status NOT IN ('Cancelled')
        ORDER BY a.appointment_date ASC, a.start_time ASC
    ");
    $stmt->execute([$patientId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $events[] = [
            'id' => (string)$a['id'],
            'title' => $a['service_type'],
            'start' => $a['appointment_date'] . 'T' . $a['start_time'],
            'end' => $a['appointment_date'] . 'T' . $a['end_time'],
            'backgroundColor' => '#0E6B5E',
            'borderColor' => '#0A4A41',
            'textColor' => '#ffffff',
            'extendedProps' => [
                'service' => $a['service_type'],
                'dentist' => $a['dentist_name'] ?? 'Not assigned',
                'status' => $a['status']
            ]
        ];
    }
}
$appointmentDates = [];
$todayStr = date('Y-m-d');
foreach ($events as $ev) {
    $d = substr($ev['start'], 0, 10);
    // Kapag tapos na ang date, hindi na dapat naka-highlight
    if ($d && $d >= $todayStr) $appointmentDates[] = $d;
}
$appointmentDates = array_values(array_unique($appointmentDates));

// Dates where current patient already reached the 2-appointment limit
$maxedDates = [];
if ($patientId > 0) {
    try {
        $maxStmt = $pdo->prepare("
            SELECT appointment_date, COUNT(*) as cnt
            FROM appointments
            WHERE patient_id = ?
              AND status NOT IN ('Cancelled', 'Canceled', 'No-Show')
              AND appointment_date >= ?
            GROUP BY appointment_date
            HAVING cnt >= 2
        ");
        $maxStmt->execute([$patientId, $todayStr]);
        $maxedDates = $maxStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $maxedDates = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Calendar | Abalos Dental</title>

<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet"
      href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap">
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
<link rel="stylesheet" href="patient.css?v=8">

<style>
body { font-family:"DM Sans",sans-serif; background:linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22)!important;background-size:300% 300%!important;animation:calendarBgMove 8s ease infinite!important; }
@keyframes calendarBgMove{0%{background-position:0% 50%;}50%{background-position:100% 50%;}100%{background-position:0% 50%;}}
.patient-main{background:transparent!important;}
.calendar-card {
    background: rgba(255,255,255,.96);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 2px solid #059669;
    border-radius: 20px;
    padding: 24px;
    box-shadow: 0 18px 45px rgba(5,150,105,.15), 0 6px 18px rgba(2,44,34,.12);
}
/* Guhit ng calendar — mas visible na border */
.fc-theme-standard td,
.fc-theme-standard th {
    border-color: #0E6B5E !important;
}
.fc-col-header-cell {
    background: #EAF7F0 !important;
    border: 1.5px solid #0E6B5E !important;
}
.fc-col-header-cell-cushion {
    color: #0A4A41 !important;
    font-weight: 800 !important;
}
/* Header kulay: Sunday pula (CLOSED), Mon-Sat green (OPEN) */
.fc-col-header-cell:first-child,
.fc-col-header-cell.fc-day-sun {
    background: #FDECEC !important;
}
.fc-col-header-cell:first-child .fc-col-header-cell-cushion,
.fc-col-header-cell.fc-day-sun .fc-col-header-cell-cushion {
    color: #B91C1C !important;
}
.fc-col-header-cell:not(:first-child) {
    background: #C7ECD4 !important;
}
.fc-col-header-cell:not(:first-child) .fc-col-header-cell-cushion {
    color: #0A4A41 !important;
}
.fc-daygrid-day {
    border: 1.5px solid #0E6B5E !important;
}
/* Mon-Sat box loob: soft mint green (hindi masakit sa mata). Sunday hindi kasama. */
.fc-daygrid-body td.fc-daygrid-day:not(:first-child):not(.fc-day-sun):not(.fc-day-closed-sunday):not(.fc-day-today):not(.has-appointment) {
    background: #E9F5EE;
}
.fc-daygrid-day-number {
    color: #143f51 !important;
    font-weight: 700 !important;
}
.fc-scrollgrid {
    border: 2px solid #0E6B5E !important;
    border-radius: 12px;
    overflow: hidden;
}
#calendar { min-height:500px; }

/* Sunday styling — strictly CLOSED & non-clickable */
.fc-day-sun,
.fc-day-closed-sunday,
.fc-day-sun .fc-daygrid-day-frame,
.fc-day-closed-sunday .fc-daygrid-day-frame,
.fc-daygrid-body td.fc-daygrid-day:first-child {
    background: #FFF5F5 !important;
    cursor: not-allowed !important;
}
.fc-day-sun:hover,
.fc-day-closed-sunday:hover,
.fc-day-sun:hover .fc-daygrid-day-frame,
.fc-day-closed-sunday:hover .fc-daygrid-day-frame {
    background: #FEE2E2 !important;
    cursor: not-allowed !important;
}
.fc-day-sun .fc-daygrid-day-number,
.fc-day-closed-sunday .fc-daygrid-day-number,
.fc-daygrid-body td.fc-daygrid-day:first-child .fc-daygrid-day-number {
    color: #DC2626 !important;
    font-weight: 700 !important;
    opacity: 0.8;
}
.fc-day-sun .fc-daygrid-day-top,
.fc-day-closed-sunday .fc-daygrid-day-top {
    flex-direction: row-reverse;
    justify-content: space-between;
    padding: 3px 6px;
}
.fc-day-sun .fc-daygrid-day-top::after,
.fc-day-closed-sunday .fc-daygrid-day-top::after {
    content: "CLOSED";
    font-size: 9px;
    font-weight: 800;
    color: #DC2626;
    background: #FEE2E2;
    border: 1px solid #FECACA;
    padding: 1px 5px;
    border-radius: 4px;
    letter-spacing: .4px;
    line-height: 1.2;
}

/* Cursor pointers for available days only */
.fc-daygrid-day:not(.fc-day-disabled):not(.fc-day-sun):not(.fc-day-closed-sunday):not(.fc-day-past) {
    cursor: pointer;
}
.fc-day-past,
.fc-day-past .fc-daygrid-day-frame {
    cursor: not-allowed !important;
}
.day-help {
    background: linear-gradient(135deg,#ecfdf5,#d1fae5);
    border:1px solid #6ee7b7;
    border-radius:14px;
    padding:14px 16px;
    color:#064e3b;
    font-size:12.5px;
    box-shadow:0 6px 18px rgba(5,150,105,.10);
}
.slot-grid {
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:10px;
}
.slot-btn {
    border:1px solid #bfe3ea;
    background:#fff;
    border-radius:10px;
    padding:12px 8px;
    color:#087f98;
    font-weight:700;
    font-size:12px;
    cursor:pointer;
}
.slot-btn:hover {
    background:#0E6B5E;
    color:#fff;
    border-color:#0E6B5E;
}
.slot-unavailable {
    border:1px solid #e7e7e7;
    background:#f6f6f6;
    color:#9aa4a8;
    border-radius:10px;
    padding:12px 8px;
    font-size:12px;
    text-align:center;
}
.fc .fc-button-primary {
    background: linear-gradient(135deg,#059669,#047857);
    border-color:#059669;
    border-radius:10px;
    font-weight:700;
    box-shadow:0 6px 14px rgba(5,150,105,.22);
}
.fc .fc-button-primary:hover {
    background: linear-gradient(135deg,#047857,#064e3b);
    border-color:#047857;
}
/* Kulay sa date na may appointment — green patient theme */
.fc-daygrid-day.has-appointment {
    background: linear-gradient(180deg, #EAF7F0 0%, #D9F2E6 100%) !important;
}
.fc-daygrid-day.has-appointment .fc-daygrid-day-frame {
    border: 1px solid #B9E4CF !important;
    border-radius: 10px;
}
.fc-daygrid-day.has-appointment .fc-daygrid-day-number {
    background: linear-gradient(135deg, #149A72, #0A4A41);
    color: #ffffff !important;
    border-radius: 8px;
    padding: 2px 8px !important;
    font-weight: 800;
    box-shadow: 0 4px 10px rgba(14,107,94,.22);
    border: 1px solid rgba(201,162,39,.35);
}
.fc-daygrid-day.has-appointment .fc-daygrid-day-top { opacity: 1; }
/* Date where patient already reached 2 appointments limit */
.fc-daygrid-day.has-max-appointments {
    background: #FFFBEB !important;
}
.fc-daygrid-day.has-max-appointments .fc-daygrid-day-frame {
    border: 1px dashed #F59E0B !important;
}
.fc-daygrid-day.has-max-appointments .fc-daygrid-day-top {
    flex-direction: row-reverse;
    justify-content: space-between;
    padding: 3px 6px;
}
.fc-daygrid-day.has-max-appointments .fc-daygrid-day-top::after {
    content: "2/2 LIMIT";
    font-size: 8.5px;
    font-weight: 800;
    color: #92400E;
    background: #FEF3C7;
    border: 1px solid #FDE68A;
    padding: 1px 4px;
    border-radius: 4px;
    line-height: 1.2;
    letter-spacing: .3px;
}
.fc-event {
    border-radius: 6px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    padding: 2px 6px !important;
    border: none !important;
}
.calendar-legend {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 16px;
    padding: 12px 16px;
    background: #ffffff;
    border: 1px solid #bbe8cd;
    border-radius: 14px;
    font-size: 12px;
    color: #064e3b;
    box-shadow:0 6px 16px rgba(5,150,105,.08);
}
.calendar-legend-dot {
    width: 14px;
    height: 14px;
    border-radius: 4px;
    display: inline-block;
    border: 1px solid rgba(0,0,0,.06);
}
.calendar-legend-dot.appointment { background: linear-gradient(135deg, #149A72, #0A4A41); }
.calendar-legend-dot.today { background: #ffffff; border: 1px solid #0E6B5E; }
.calendar-legend-dot.closed { background: #f6f6f6; border: 1px solid #e7e7e7; }
/* Day booking modal — system theme (teal gradient gaya ng profile-header-card/active menu) */
#dayBookingModal .modal-content { border:2px solid #0E6B5E !important; border-radius:18px !important; overflow:visible !important; box-shadow:0 20px 60px rgba(10,74,65,.25); }
#dayBookingModal .modal-header { background:linear-gradient(135deg,#149A72,#0A4A41); color:#fff; border-bottom:none; padding:18px 22px; border-top-left-radius:16px; border-top-right-radius:16px; }
#dayBookingModal .modal-title { color:#fff !important; font-family:"Plus Jakarta Sans",sans-serif; font-size:17px; margin:0; }
#dayBookingModal #selectedDateText { color:rgba(255,255,255,.88) !important; font-size:12.5px; margin-top:2px; }
#dayBookingModal .booking-modal-icon { width:46px; height:46px; border-radius:12px; background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.35); color:#fff; display:flex; align-items:center; justify-content:center; font-size:22px; flex:0 0 46px; }
#dayBookingModal .modal-body { background:#F6FBF8; padding:20px 22px 24px; }
#dayBookingModal .booking-modal-hint { background:#fff; border:1px solid #E6F2EC; border-left:4px solid #0E6B5E; border-radius:10px; padding:10px 12px; font-size:12px; color:#57727d; margin-bottom:14px; }
#dayBookingModal .form-label { color:#0A4A41; }
#dayBookingModal .form-select { border-radius:10px; border:1px solid #C9E9DA; background-color:#fff; }
#dayBookingModal .form-select:focus { border-color:#0E6B5E; box-shadow:0 0 0 3px rgba(20,154,114,.15); }
#dayBookingModal #slotSummary { background:#EAF7F0; border:1px solid #B9E4CF; color:#0A4A41; font-weight:600; border-radius:10px; padding:8px 12px; }
#dayBookingModal #slotSummary:empty { display:none; }
#dayBookingModal .slot-btn { border:1px solid #B9E4CF; background:#fff; color:#0A4A41; border-radius:12px; box-shadow:0 2px 8px rgba(10,74,65,.06); transition:.18s; cursor:pointer; }
#dayBookingModal .slot-btn:hover { background:linear-gradient(135deg,#149A72,#0A4A41); border-color:#0A4A41; color:#fff; transform:translateY(-2px); box-shadow:0 8px 20px rgba(10,74,65,.25); }
#dayBookingModal .slot-btn.slot-selected { background:linear-gradient(135deg,#149A72,#0A4A41) !important; border-color:#0A4A41 !important; color:#fff !important; font-weight:700 !important; box-shadow:0 6px 18px rgba(10,74,65,.3) !important; }
@media(max-width:767px) {
    .slot-grid { grid-template-columns:repeat(2,1fr); }
    .calendar-card { padding:14px; }
}
/* Tulad sa my appointments - minibar + dentist-style topbar */
.patient-minibar{display:flex;align-items:center;justify-content:center;gap:8px;background:#0A3B34;color:#C9A227;font-size:11px;font-weight:700;letter-spacing:.6px;padding:6px 12px;border-radius:10px 10px 0 0;}
.patient-minibar span{color:#ffffff;}
.patient-minibar i{font-size:13px;color:#ffffff;}
.patient-topbar{background:#ffffff!important;border-bottom:1px solid #e8eef2!important;min-height:78px!important;padding-top:10px!important;padding-bottom:10px!important;gap:16px!important;flex-wrap:wrap!important;align-items:center!important;}
.patient-topbar-right{display:flex!important;align-items:center!important;gap:16px!important;}
.patient-topbar-date{display:flex!important;align-items:center!important;gap:10px!important;color:#244f60!important;}
.patient-topbar-date i{font-size:20px!important;}
.patient-topbar-date strong{display:block!important;font-size:12px!important;font-weight:800!important;line-height:1.2!important;}
.patient-topbar-date small{display:block!important;font-size:11px!important;opacity:.85!important;font-weight:600!important;}
.patient-topbar-time{display:flex!important;align-items:center!important;gap:10px!important;color:#244f60!important;border-left:1px solid #e8eef2!important;padding-left:16px!important;}
.patient-topbar-time i{font-size:20px!important;}
.patient-topbar-time strong{display:block!important;font-size:12px!important;font-weight:800!important;line-height:1.2!important;}
.patient-topbar-time small{display:block!important;font-size:11px!important;opacity:.85!important;font-weight:600!important;font-variant-numeric:tabular-nums!important;}
</style>
</head>

<body>
<div class="patient-layout">

<?php require_once __DIR__ . '/patient_sidebar.php'; ?>

<main class="patient-main">

<div class="patient-minibar"><i class="bi bi-heart-pulse-fill"></i><span>Abalos Dental Clinic</span></div>

<header class="patient-topbar">
    <div>
        <h1 class="page-heading">Calendar</h1>
        <p class="page-small">
            Click a date to view available hours and appointment slots.
        </p>
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

    <div class="calendar-card">

        <div class="day-help mb-3">
            <i class="bi bi-info-circle me-1"></i>
            <strong>How booking works:</strong> Click a future date → select dentist and dental service → choose an available time → review the booking → confirm.
            Monday-Saturday: 8:00 AM-6:00 PM. Sunday: CLOSED. Each patient is limited to a maximum of 2 appointments per day.
        </div>

        <div id="calendar"></div>

        <div class="calendar-legend">
            <span style="font-weight:800; color:#143f51;"><i class="bi bi-info-circle me-1" style="color:#0E6B5E;"></i>Legend:</span>
            <span><span class="calendar-legend-dot appointment"></span> Your Appointment</span>
            <span><span class="calendar-legend-dot" style="background:#FEF3C7; border:1px solid #F59E0B;"></span> Limit Reached (2/2)</span>
            <span><span class="calendar-legend-dot today"></span> Available Slot</span>
            <span><span class="calendar-legend-dot" style="background:#FFF5F5; border:1px solid #FECACA;"></span> Sunday (Closed)</span>
            <span><span class="calendar-legend-dot closed"></span> Past Date</span>
        </div>

    </div>

</div>
</main>
</div>

<!-- DAY BOOKING MODAL -->
<div class="modal fade" id="dayBookingModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content">

<div class="modal-header">
    <div class="d-flex align-items-center gap-3">
        <div class="booking-modal-icon"><i class="bi bi-calendar-check"></i></div>
        <div>
            <h5 class="modal-title fw-bold">Choose Appointment Schedule</h5>
            <div id="selectedDateText" class="small"></div>
        </div>
    </div>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

    <div class="booking-modal-hint"><i class="bi bi-info-circle me-1" style="color:#0E6B5E;"></i>Select dentist &amp; service to see available hours. Mon–Sat 8:00 AM–6:00 PM.</div>

    <div id="dayMessage" class="alert d-none"></div>

    <!-- Booking form area -->
    <div id="dayBookingFormArea">
        <div class="row g-3 mb-3">

            <div class="col-md-6">
                <label class="form-label fw-bold small"><i class="bi bi-person-badge me-1" style="color:#0E6B5E;"></i>Dentist</label>
                <select id="slotDentist" class="form-select">
                    <option value="">Select Dentist</option>
                    <?php foreach ($dentists as $d): ?>
                        <?php
                        $restLabel = '';
                        if (array_key_exists('rest_day', $d) && $d['rest_day'] !== null && $d['rest_day'] !== '') {
                            $dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                            $restLabel = $dayNames[(int)$d['rest_day']] ?? '';
                        }
                        ?>
                        <option value="<?= (int)$d['id'] ?>">
                            <?= calendar_escape($d['dentist_name']) ?>
                            <?php if (!empty($d['specialization'])): ?>
                                - <?= calendar_escape($d['specialization']) ?>
                            <?php endif; ?>
                            <?php if ($restLabel !== ''): ?>
                                (Rest day: <?= calendar_escape($restLabel) ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label fw-bold small"><i class="bi bi-clipboard2-pulse me-1" style="color:#0E6B5E;"></i>Dental Service</label>
                <select id="slotService" class="form-select">
                    <option value="">Select Service</option>
                    <?php foreach ($services as $service): ?>
                        <option value="<?= calendar_escape($service['service_name']) ?>">
                            <?= calendar_escape($service['service_name']) ?>
                            (<?= (int)$service['duration_minutes'] ?> min)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

        </div>

        <div id="slotSummary" class="text-muted small mb-2"></div>

        <div id="slotGrid" class="slot-grid">
            <div class="text-muted small">
                Select a dentist and service to see available hours.
            </div>
        </div>

        <!-- Book appointment button -->
        <div id="bookingConfirmSection" style="display:none;" class="mt-3">
            <button type="button" id="submitBookingBtn" class="btn w-100 py-2 fw-bold text-white shadow-sm" style="background:linear-gradient(135deg,#149A72,#0A4A41); border-radius:10px; font-size:15px; border:none;">
                <i class="bi bi-calendar2-check-fill me-2"></i>Book Appointment
            </button>
        </div>
    </div>

    <!-- Booking success area -->
    <div id="bookingSuccessSection" style="display:none;" class="py-4 text-center">
        <div style="width:68px; height:68px; border-radius:50%; background:#d1fae5; color:#059669; display:inline-flex; align-items:center; justify-content:center; font-size:36px; margin-bottom:14px; box-shadow:0 8px 24px rgba(5,150,105,.22);">
            <i class="bi bi-check-lg"></i>
        </div>
        <h4 class="fw-bold mb-1" style="color:#065f46;">Appointment Booked Successfully!</h4>
        <p class="text-muted small mb-2" id="successMessageDetail">Your appointment has been submitted and is awaiting clinic confirmation.</p>
        <div class="mb-3">
            <span class="badge bg-warning text-dark px-3 py-2" style="font-size:12px; font-weight:700; border-radius:20px;">
                <i class="bi bi-hourglass-split me-1"></i>Status: Pending Confirmation
            </span>
        </div>
        <div class="d-flex justify-content-center gap-2 mt-4">
            <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal" onclick="window.location.reload();">
                <i class="bi bi-calendar3 me-1"></i>Return to Calendar
            </button>
            <a href="my_appointments.php" class="btn btn-success px-3" style="background:#0E6B5E; border-color:#0E6B5E;">
                <i class="bi bi-card-checklist me-1"></i>View in My Appointments
            </a>
        </div>
    </div>

</div>
</div>
</div>
</div>

<!-- APPOINTMENT LIMIT REACHED POPUP MODAL -->
<div class="modal fade" id="appointmentLimitModal" tabindex="-1" aria-labelledby="appointmentLimitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
        <div class="modal-content" style="border-radius: 24px; border: 1.5px solid #F59E0B; box-shadow: 0 25px 60px rgba(10,74,65,0.30); overflow: hidden; background: #ffffff;">
            
            <div class="modal-header border-0 pb-0 pt-3 px-4 d-flex justify-content-end">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="opacity: 0.55;"></button>
            </div>

            <div class="modal-body text-center px-4 pt-1 pb-4">
                <div style="width: 76px; height: 76px; margin: 0 auto 16px; border-radius: 50%; background: linear-gradient(135deg, #FEF3C7, #FDE68A); color: #B45309; display: flex; align-items: center; justify-content: center; font-size: 36px; box-shadow: 0 10px 25px rgba(245,158,11,.24); border: 2px solid #FCD34D;">
                    <i class="bi bi-calendar-x-fill"></i>
                </div>

                <h5 class="fw-bold mb-1" id="appointmentLimitModalLabel" style="color: #0A4A41; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 20px;">
                    Appointment Limit Reached
                </h5>

                <div class="mb-3">
                    <span id="limitModalDateBadge" class="badge" style="background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; font-size: 13px; font-weight: 700; padding: 6px 14px; border-radius: 20px;">
                        <i class="bi bi-calendar2-event me-1"></i> <span id="limitModalDateText">--</span>
                    </span>
                </div>

                <p class="mb-3 text-muted" id="limitModalMessage" style="font-size: 14px; line-height: 1.55; color: #4b636e !important;">
                    You already have 2 appointments scheduled on this date. Each patient account is limited to a maximum of 2 appointments per day.
                </p>

                <div style="background: #FFFBEB; border: 1px dashed #F59E0B; border-radius: 12px; padding: 10px 14px; font-size: 12.5px; color: #92400E; font-weight: 600; margin-bottom: 22px;">
                    <i class="bi bi-info-circle-fill me-1" style="color: #D97706;"></i> Please select another available date on the calendar.
                </div>

                <button type="button" class="btn w-100 py-2 fw-bold text-white shadow-sm" data-bs-dismiss="modal" style="background: linear-gradient(135deg, #149A72, #0A4A41); border: none; border-radius: 12px; font-size: 15px; letter-spacing: .2px; transition: .18s;">
                    <i class="bi bi-check2-circle me-1"></i> Okay, Got It
                </button>
            </div>

        </div>
    </div>
</div>

<!-- APPOINTMENT DETAILS POPUP MODAL -->
<div class="modal fade" id="appointmentDetailModal" tabindex="-1" aria-labelledby="appointmentDetailTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
        <div class="modal-content" style="border-radius: 24px; border: 1.5px solid #0E6B5E; box-shadow: 0 25px 60px rgba(10,74,65,0.30); overflow: hidden; background: #ffffff;">
            
            <div class="modal-header border-0 pb-0 pt-3 px-4 d-flex justify-content-end">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="opacity: 0.55;"></button>
            </div>

            <div class="modal-body text-center px-4 pt-1 pb-4">
                <div style="width: 72px; height: 72px; margin: 0 auto 16px; border-radius: 50%; background: linear-gradient(135deg, #D1FAE5, #A7F3D0); color: #047857; display: flex; align-items: center; justify-content: center; font-size: 34px; box-shadow: 0 10px 25px rgba(5,150,105,.20); border: 2px solid #6EE7B7;">
                    <i class="bi bi-calendar2-check-fill"></i>
                </div>

                <h5 class="fw-bold mb-1" id="appointmentDetailTitle" style="color: #0A4A41; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 20px;">
                    Appointment Details
                </h5>
                <p class="text-muted small mb-3" id="appointmentDetailDate">--</p>

                <div class="text-start p-3 mb-3" style="background: #F6FBF8; border: 1px solid #C9E9DA; border-radius: 14px;">
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2" style="border-bottom: 1px solid #E6F2EC;">
                        <span class="text-muted small fw-semibold"><i class="bi bi-clipboard2-pulse me-1 text-success"></i>Service</span>
                        <strong id="detailService" class="text-dark small">--</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2" style="border-bottom: 1px solid #E6F2EC;">
                        <span class="text-muted small fw-semibold"><i class="bi bi-person-badge me-1 text-success"></i>Dentist</span>
                        <strong id="detailDentist" class="text-dark small">--</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small fw-semibold"><i class="bi bi-clock-history me-1 text-success"></i>Status</span>
                        <span id="detailStatus" class="badge bg-warning text-dark px-2 py-1">--</span>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary w-50 py-2 fw-semibold" data-bs-dismiss="modal" style="border-radius: 12px; font-size: 14px;">
                        Close
                    </button>
                    <a href="my_appointments.php" class="btn w-50 py-2 fw-bold text-white shadow-sm" style="background: linear-gradient(135deg, #149A72, #0A4A41); border: none; border-radius: 12px; font-size: 14px;">
                        <i class="bi bi-eye-fill me-1"></i> View All
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

<script>
const calendarEvents = <?= json_encode(
    $events,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;
const appointmentDates = <?= json_encode($appointmentDates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const appointmentDateSet = new Set(appointmentDates);
const maxedDates = <?= json_encode($maxedDates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const maxedDateSet = new Set(maxedDates);
const todayStr = <?= json_encode($todayStr, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

const clinicSlotInterval = <?= (int)$slotInterval ?>;
const clinicDailyCapacity = <?= (int)$dailyCapacity ?>;

let selectedDate = '';
let selectedSlot = null;
let appointmentJustBooked = false;

const modalElement = document.getElementById('dayBookingModal');
const bookingModal = new bootstrap.Modal(modalElement);

const dentistSelect = document.getElementById('slotDentist');
const serviceSelect = document.getElementById('slotService');
const slotGrid = document.getElementById('slotGrid');
const slotSummary = document.getElementById('slotSummary');
const dayMessage = document.getElementById('dayMessage');
const bookingConfirmSection = document.getElementById('bookingConfirmSection');
const bookingSuccessSection = document.getElementById('bookingSuccessSection');
const dayBookingFormArea = document.getElementById('dayBookingFormArea');
const submitBookingBtn = document.getElementById('submitBookingBtn');

let limitModalInstance = null;
let detailModalInstance = null;

function showLimitModal(dateStr) {
    const modalEl = document.getElementById('appointmentLimitModal');
    if (!modalEl) {
        alert("Appointment Limit Reached: You already have 2 appointments scheduled on " + dateStr + ". Each patient is limited to a maximum of 2 appointments per day. Please select another date.");
        return;
    }

    let readableDate = dateStr;
    try {
        const parts = dateStr.split('-');
        if (parts.length === 3) {
            const d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
            readableDate = d.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        }
    } catch (e) {}

    const textEl = document.getElementById('limitModalDateText');
    if (textEl) textEl.textContent = readableDate;

    if (!limitModalInstance && typeof bootstrap !== 'undefined') {
        limitModalInstance = new bootstrap.Modal(modalEl);
    }
    if (limitModalInstance) {
        limitModalInstance.show();
    } else {
        alert("Appointment Limit Reached: You already have 2 appointments scheduled on " + dateStr + ". Each patient is limited to a maximum of 2 appointments per day. Please select another date.");
    }
}

function showAppointmentDetailModal(service, dentist, status, dateDisplay) {
    const modalEl = document.getElementById('appointmentDetailModal');
    if (!modalEl) {
        alert('Service: ' + service + '\nDentist: ' + dentist + '\nStatus: ' + status);
        return;
    }

    document.getElementById('detailService').textContent = service || 'General Service';
    document.getElementById('detailDentist').textContent = dentist || 'Assigned Dentist';

    const statusBadge = document.getElementById('detailStatus');
    if (statusBadge) {
        statusBadge.textContent = status || 'Pending';
        if (status === 'Confirmed') {
            statusBadge.className = 'badge bg-success text-white px-2 py-1';
        } else if (status === 'Pending') {
            statusBadge.className = 'badge bg-warning text-dark px-2 py-1';
        } else {
            statusBadge.className = 'badge bg-secondary text-white px-2 py-1';
        }
    }

    const dateEl = document.getElementById('appointmentDetailDate');
    if (dateEl) dateEl.textContent = dateDisplay || '';

    if (!detailModalInstance && typeof bootstrap !== 'undefined') {
        detailModalInstance = new bootstrap.Modal(modalEl);
    }
    if (detailModalInstance) {
        detailModalInstance.show();
    } else {
        alert('Service: ' + service + '\nDentist: ' + dentist + '\nStatus: ' + status);
    }
}

function showMessage(message, type = 'info') {
    dayMessage.className = 'alert alert-' + type;
    dayMessage.textContent = message;
    dayMessage.classList.remove('d-none');
}

function hideMessage() {
    dayMessage.classList.add('d-none');
}

function openDate(dateStr) {
    if (!dateStr) return;

    const dateObj = new Date(dateStr + 'T00:00:00');
    // Sunday (0) is closed — hindi clickable
    if (dateObj.getDay() === 0) {
        return;
    }

    if (maxedDateSet.has(dateStr)) {
        showLimitModal(dateStr);
        return;
    }

    selectedDate = dateStr;
    selectedSlot = null;
    appointmentJustBooked = false;
    document.getElementById('selectedDateText').textContent =
        dateObj.toLocaleDateString(undefined, {
            weekday:'long',
            year:'numeric',
            month:'long',
            day:'numeric'
        });

    dentistSelect.value = '';
    serviceSelect.value = '';
    slotGrid.innerHTML =
        '<div class="text-muted small">Select a dentist and service to see available hours.</div>';
    slotSummary.textContent = '';
    hideMessage();

    dayBookingFormArea.style.display = 'block';
    bookingConfirmSection.style.display = 'none';
    bookingSuccessSection.style.display = 'none';

    bookingModal.show();
}

async function loadSlots() {
    if (!selectedDate) return;

    selectedSlot = null;
    bookingConfirmSection.style.display = 'none';

    const dentistId = dentistSelect.value;
    const service = serviceSelect.value;

    if (!dentistId || !service) {
        slotGrid.innerHTML =
            '<div class="text-muted small">Select a dentist and service to see available hours.</div>';
        slotSummary.textContent = '';
        return;
    }

    slotGrid.innerHTML =
        '<div class="text-muted small">Checking available hours...</div>';

    slotSummary.textContent = '';
    hideMessage();

    const url =
        'get_available_slots.php?date=' +
        encodeURIComponent(selectedDate) +
        '&dentist_id=' +
        encodeURIComponent(dentistId) +
        '&service=' +
        encodeURIComponent(service);

    try {
        const response = await fetch(url, {
            headers: {'X-Requested-With':'XMLHttpRequest'}
        });

        const data = await response.json();

        if (!data.success) {
            slotGrid.innerHTML = '';
            showMessage(data.message || 'Unable to load available slots.', 'danger');
            return;
        }

        slotSummary.textContent = data.message || '';

        if (!data.slots || data.slots.length === 0) {
            slotGrid.innerHTML =
                '<div class="slot-unavailable">No available hours for this date.</div>';
            return;
        }

        slotGrid.innerHTML = '';

        data.slots.forEach(slot => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'slot-btn';
            button.textContent = slot.start_display + ' - ' + slot.end_display;

            button.addEventListener('click', function () {
                document.querySelectorAll('#slotGrid .slot-btn').forEach(b => b.classList.remove('slot-selected'));
                button.classList.add('slot-selected');

                selectedSlot = slot;

                bookingConfirmSection.style.display = 'block';
                bookingConfirmSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });

            slotGrid.appendChild(button);
        });

    } catch (error) {
        slotGrid.innerHTML = '';
        showMessage(
            'Unable to check the schedule. Please try again.',
            'danger'
        );
    }
}

dentistSelect.addEventListener('change', loadSlots);
serviceSelect.addEventListener('change', loadSlots);

submitBookingBtn.addEventListener('click', async function () {
    if (!selectedDate || !selectedSlot) {
        showMessage('Please choose an available time slot.', 'warning');
        return;
    }

    const dentistId = dentistSelect.value;
    const service = serviceSelect.value;

    if (!dentistId || !service) {
        showMessage('Please select a dentist and dental service.', 'danger');
        return;
    }

    const btn = this;
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Booking Appointment...';
    hideMessage();

    try {
        const formData = new FormData();
        formData.append('action', 'book_from_calendar');
        formData.append('csrf_token', <?= json_encode(csrfToken()) ?>);
        formData.append('appointment_date', selectedDate);
        formData.append('dentist_id', dentistId);
        formData.append('service', service);
        formData.append('start_time', selectedSlot.start);
        formData.append('end_time', selectedSlot.end || '');
        formData.append('notes', '');

        const res = await fetch('calendar.php', {
            method: 'POST',
            body: formData,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });

        const data = await res.json();
        if (data.success) {
            appointmentJustBooked = true;
            dayBookingFormArea.style.display = 'none';
            const dentistText = dentistSelect.options[dentistSelect.selectedIndex].text.replace(/\s*\(Rest day:.*?\)/, '').trim();
            document.getElementById('successMessageDetail').innerHTML = 
                'Your appointment request for <strong>' + data.date_display + ' (' + data.time_display + ')</strong> with <strong>' + dentistText + '</strong> has been submitted and is awaiting confirmation.';
            bookingSuccessSection.style.display = 'block';
        } else {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            showMessage(data.message || 'Unable to save appointment. Please try again.', 'danger');
        }
    } catch (e) {
        btn.disabled = false;
        btn.innerHTML = origHtml;
        showMessage('An unexpected error occurred. Please try again.', 'danger');
    }
});

modalElement.addEventListener('hidden.bs.modal', function () {
    if (appointmentJustBooked) {
        window.location.reload();
    }
});

document.addEventListener('DOMContentLoaded', function () {

    const calendarElement = document.getElementById('calendar');

    const calendar = new FullCalendar.Calendar(
        calendarElement,
        {
            initialView: 'dayGridMonth',
            height: 'auto',
            fixedWeekCount: false,

            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,listMonth'
            },

            events: calendarEvents,

            // Mark Sunday as closed and color dates with appointments
            dayCellClassNames: function(arg) {
                var classes = [];
                var dayOfWeek = arg.date.getDay();
                if (dayOfWeek === 0) {
                    classes.push('fc-day-closed-sunday');
                }
                var y = arg.date.getFullYear();
                var m = String(arg.date.getMonth()+1).padStart(2,'0');
                var d = String(arg.date.getDate()).padStart(2,'0');
                var localIso = y + '-' + m + '-' + d;
                if (localIso < todayStr) {
                    classes.push('fc-day-past');
                    return classes;
                }
                if (maxedDateSet.has(localIso)) {
                    classes.push('has-max-appointments');
                }
                if (appointmentDateSet.has(localIso)) {
                    classes.push('has-appointment');
                }
                return classes;
            },

            dateClick: function(info) {
                const clickedDate = info.dateStr;
                const dateObj = new Date(clickedDate + 'T00:00:00');
                const dayOfWeek = (info.date ? info.date.getDay() : dateObj.getDay());

                // Sunday (0) is strictly CLOSED — hindi clickable
                if (dayOfWeek === 0) {
                    return;
                }

                if (clickedDate < '<?= date('Y-m-d') ?>') {
                    return;
                }

                if (maxedDateSet.has(clickedDate)) {
                    showLimitModal(clickedDate);
                    return;
                }

                openDate(clickedDate);
            },

            eventClick: function(info) {
                const props = info.event.extendedProps || {};
                let dateDisplay = '';
                if (info.event.start) {
                    try {
                        dateDisplay = info.event.start.toLocaleDateString(undefined, {
                            weekday: 'short', month: 'short', day: 'numeric', year: 'numeric'
                        }) + ' at ' + info.event.start.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                    } catch (e) {}
                }
                showAppointmentDetailModal(props.service, props.dentist, props.status, dateDisplay);
            }
        }
    );

    calendar.render();
});
</script>

</body>
</html>
