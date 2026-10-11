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
 * 6. The selected date/time is sent to book_appointment.php.
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
.fc-col-header-cell:first-child {
    background: #FDECEC !important;
}
.fc-col-header-cell:first-child .fc-col-header-cell-cushion {
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
.fc-daygrid-body td.fc-daygrid-day:not(:first-child):not(.fc-day-today):not(.has-appointment) {
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
.fc-daygrid-day:not(.fc-day-disabled) { cursor:pointer; }
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
#dayBookingModal .slot-btn { border:1px solid #B9E4CF; background:#fff; color:#0A4A41; border-radius:12px; box-shadow:0 2px 8px rgba(10,74,65,.06); transition:.18s; }
#dayBookingModal .slot-btn:hover { background:linear-gradient(135deg,#149A72,#0A4A41); border-color:#0A4A41; color:#fff; transform:translateY(-2px); box-shadow:0 8px 20px rgba(10,74,65,.25); }
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
            Monday-Saturday: 8:00 AM-6:00 PM. Sunday: CLOSED. Each dentist also has his/her own weekly rest day.
        </div>

        <div id="calendar"></div>

        <div class="calendar-legend">
            <span style="font-weight:800; color:#143f51;"><i class="bi bi-info-circle me-1" style="color:#0E6B5E;"></i>Legend:</span>
            <span><span class="calendar-legend-dot appointment"></span> May appointment</span>
            <span><span class="calendar-legend-dot today"></span> Today / Available</span>
            <span><span class="calendar-legend-dot closed"></span> Closed / Past</span>
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
const todayStr = <?= json_encode($todayStr, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

const clinicSlotInterval = <?= (int)$slotInterval ?>;
const clinicDailyCapacity = <?= (int)$dailyCapacity ?>;

let selectedDate = '';

const modalElement = document.getElementById('dayBookingModal');
const bookingModal = new bootstrap.Modal(modalElement);

const dentistSelect = document.getElementById('slotDentist');
const serviceSelect = document.getElementById('slotService');
const slotGrid = document.getElementById('slotGrid');
const slotSummary = document.getElementById('slotSummary');
const dayMessage = document.getElementById('dayMessage');

function showMessage(message, type = 'info') {
    dayMessage.className = 'alert alert-' + type;
    dayMessage.textContent = message;
    dayMessage.classList.remove('d-none');
}

function hideMessage() {
    dayMessage.classList.add('d-none');
}

function openDate(dateStr) {
    selectedDate = dateStr;

    const dateObj = new Date(dateStr + 'T00:00:00');
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

    bookingModal.show();
}

async function loadSlots() {

    if (!selectedDate) return;

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

        slotSummary.textContent =
            data.message || '';

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

            button.textContent =
                slot.start_display + ' - ' + slot.end_display;

            button.addEventListener('click', function () {

                const params = new URLSearchParams({
                    appointment_date: selectedDate,
                    start_time: slot.start,
                    dentist_id: dentistId,
                    service: service
                });

                window.location.href =
                    'book_appointment.php?' + params.toString();
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

            // Kulayan ang date na may appointment — future/today lang, hindi na kapag tapos na
            dayCellClassNames: function(arg) {
                var y = arg.date.getFullYear();
                var m = String(arg.date.getMonth()+1).padStart(2,'0');
                var d = String(arg.date.getDate()).padStart(2,'0');
                var localIso = y + '-' + m + '-' + d;
                if (localIso < todayStr) return [];
                var iso = arg.date.toISOString().slice(0,10);
                // arg.date is local midnight, toISOString may shift; use dateStr fallback if available
                if (arg.dateStr && appointmentDateSet.has(arg.dateStr)) return ['has-appointment'];
                if (appointmentDateSet.has(iso)) return ['has-appointment'];
                if (appointmentDateSet.has(localIso)) return ['has-appointment'];
                return [];
            },

            dateClick: function(info) {

                const clickedDate = info.dateStr;

                if (clickedDate < '<?= date('Y-m-d') ?>') {
                    return;
                }

                openDate(clickedDate);
            },

            eventClick: function(info) {

                const props = info.event.extendedProps;

                alert(
                    'Service: ' + props.service +
                    '\nDentist: ' + props.dentist +
                    '\nStatus: ' + props.status
                );
            }
        }
    );

    calendar.render();
});
</script>

</body>
</html>
