<?php

require_once __DIR__ . '/../config.php';

if (
    !isset($_SESSION['user_id']) ||
    strtolower(trim($_SESSION['role'] ?? '')) !== 'patient'
) {
    header('Location: ../login.php');
    exit;
}

function bookSchedulingEscape($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT *
    FROM patients
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$userId]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $parts = preg_split(
        '/\s+/',
        trim($user['full_name'] ?? 'Patient')
    );

    $firstName = $parts[0] ?? 'Patient';

    $lastName = count($parts) > 1
        ? implode(' ', array_slice($parts, 1))
        : '';

    $patientNumber = generateShortPatientNumber($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO patients
        (
            user_id,
            patient_number,
            first_name,
            last_name,
            email
        )
        VALUES
        (?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $userId,
        $patientNumber,
        $firstName,
        $lastName,
        $user['email'] ?? ''
    ]);

    $patientId = (int) $pdo->lastInsertId();

} else {

    $patientId = (int) $patient['id'];
}

/* =========================
   DENTISTS
========================= */

try {
    $stmt = $pdo->query("
        SELECT
            id,
            dentist_name,
            specialization,
            rest_day
        FROM dentists
        WHERE status = 'Active'
        ORDER BY dentist_name
    ");

    $dentists = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $stmt = $pdo->query("
        SELECT
            id,
            dentist_name,
            specialization
        FROM dentists
        WHERE status = 'Active'
        ORDER BY dentist_name
    ");

    $dentists = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================
   SERVICES
========================= */

$services = [
    'General Dentistry',
    'Orthodontics (Braces)',
    'Oral Surgery (Tooth Extraction or Bunot)',
    'Cosmetic',
    'Fillings or Pasta',
    'Root Canal',
    'Oral Prophylaxis or Cleaning',
    'Denture',
    'Teeth Whitening',
    'Crown',
    'Bridge',
    'Veneers',
    'Dental Check-up'
];

$success = '';
$error = '';

/* =========================
   BOOK SCHEDULE
========================= */


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

    $dentistId = (int) ($_POST['dentist_id'] ?? 0);

    $date = trim($_POST['appointment_date'] ?? '');

    $time = trim($_POST['start_time'] ?? '');

    $service = trim($_POST['service_type'] ?? '');

    $notes = trim($_POST['notes'] ?? '');

    if (
        $dentistId <= 0 ||
        $date === '' ||
        $time === '' ||
        $service === ''
    ) {

        $error =
            'Please complete all required fields.';

    } elseif ($date < date('Y-m-d')) {

        $error =
            'You cannot select a past date.';

    } else {

        /* =========================
           DURATIONS
        ========================= */

        $durationMap = [
            'General Dentistry' => 60,
            'Orthodontics (Braces)' => 60,
            'Oral Surgery (Tooth Extraction or Bunot)' => 90,
            'Cosmetic' => 120,
            'Fillings or Pasta' => 60,
            'Root Canal' => 120,
            'Oral Prophylaxis or Cleaning' => 60,
            'Denture' => 60,
            'Teeth Whitening' => 90,
            'Crown' => 90,
            'Bridge' => 90,
            'Veneers' => 120,
            'Dental Check-up' => 30
        ];

        $duration =
            $durationMap[$service] ?? 30;

        $startDateTime =
            DateTime::createFromFormat(
                'Y-m-d H:i',
                $date . ' ' . $time
            );

        if (!$startDateTime) {

            $error =
                'Invalid date or time.';

        } else {

            $endDateTime =
                clone $startDateTime;

            $endDateTime->modify(
                '+' . $duration . ' minutes'
            );

            $endTime =
                $endDateTime->format('H:i');

            /* =========================
               CHECK DENTIST
            ========================= */

            $stmt = $pdo->prepare("
                SELECT id
                FROM dentists
                WHERE id = ?
                AND status = 'Active'
                LIMIT 1
            ");

            $stmt->execute([$dentistId]);

            if (!$stmt->fetch()) {

                $error =
                    'Selected dentist is not available.';

            } else {

                /* =========================
                   PER-DENTIST REST DAY + SUNDAY CLOSURE
                ========================= */

                $schedDayOfWeek = (int)date('w', strtotime($date));

                if ($schedDayOfWeek === 0) {

                    $error =
                        'The clinic is closed on Sunday. Please choose another date.';

                } else {

                    $schedRestDay = function_exists('getDentistRestDay')
                        ? getDentistRestDay($dentistId)
                        : null;

                    if ($schedRestDay !== null && $schedDayOfWeek === (int)$schedRestDay) {

                        $schedRestName = function_exists('dentistRestDayName')
                            ? dentistRestDayName($schedRestDay)
                            : date('l', strtotime($date));

                        $error =
                            'This dentist is on rest day every ' . $schedRestName . '. Please choose another dentist or another date.';
                    }

                    /* Specific rest date (flexible: ibang araw kada linggo). */
                    if ($error === '' && function_exists('isDentistDayOff') && isDentistDayOff($dentistId, $date)) {
                        $error =
                            'This dentist is on rest day on ' . date('M d, Y (l)', strtotime($date)) . '. Please choose another dentist or another date.';
                    }
                }

                if ($error === '') {

                /* =========================
                   CONFLICT
                ========================= */

                $stmt = $pdo->prepare("
                    SELECT id
                    FROM appointments
                    WHERE dentist_id = ?
                    AND appointment_date = ?
                    AND status NOT IN ('Cancelled', 'No-Show')
                    AND start_time < ?
                    AND end_time > ?
                    LIMIT 1
                ");

                $stmt->execute([
                    $dentistId,
                    $date,
                    $endTime,
                    $time
                ]);

                if ($stmt->fetch()) {

                    $error =
                        'That schedule is already occupied. Please choose another time.';

                } else {

                    // Check patient daily limit: maximum 2 appointments per day
                    $dailyLimitStmt = $pdo->prepare("
                        SELECT COUNT(*) FROM appointments
                        WHERE patient_id = ?
                          AND appointment_date = ?
                          AND status NOT IN ('Cancelled', 'Canceled', 'No-Show')
                    ");
                    $dailyLimitStmt->execute([$patientId, $date]);
                    if ((int)$dailyLimitStmt->fetchColumn() >= 2) {
                        $error = 'Appointment Limit Reached: You already have 2 appointments scheduled on ' . date('M d, Y', strtotime($date)) . '. Each patient is limited to a maximum of 2 appointments per day.';
                    } else {

                        try {

                            $stmt = $pdo->prepare("
                                INSERT INTO appointments
                                (
                                patient_id,
                                dentist_id,
                                appointment_date,
                                start_time,
                                end_time,
                                service_type,
                                status,
                                notes
                            )
                            VALUES
                            (?, ?, ?, ?, ?, ?, 'Pending', ?)
                        ");

                        $stmt->execute([
                            $patientId,
                            $dentistId,
                            $date,
                            $time,
                            $endTime,
                            $service,
                            $notes
                        ]);

                        $success =
                            'Your schedule request has been submitted successfully.';

                    } catch (PDOException $e) {

                        $error =
                            'Unable to save your schedule.';
                    }
                    }
                }
                } // end if ($error === '') - rest day passed
            }
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

<title>Book Scheduling | Abalos Dental</title>

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

<link
    rel="stylesheet"
    href="patient.css?v=8"
>

</head>

<body>

<div class="patient-layout">

<?php require_once __DIR__ . '/patient_sidebar.php'; ?>

<main class="patient-main">

<header class="patient-topbar">

<div>

<h1 class="page-heading">
Book Scheduling
</h1>

<p class="page-small">
Choose your preferred dental schedule
</p>

</div>

<div class="profile-circle">
<i class="bi bi-person-fill"></i>
</div>

</header>

<div class="patient-content">

<div class="patient-card">

<h3 class="mb-4">
Schedule Appointment
</h3>

<?php if ($success): ?>

<div class="alert alert-success">
<?= bookSchedulingEscape($success) ?>
</div>

<?php endif; ?>

<?php if ($error): ?>

<div class="alert alert-danger">
<?= bookSchedulingEscape($error) ?>
</div>

<?php endif; ?>

<form method="POST">
<?= csrfField() ?>

<div class="row g-3">

<div class="col-md-6">

<label class="form-label">
Dentist
</label>

<select
name="dentist_id"
class="form-select"
required
>

<option value="">
Select Dentist
</option>

<?php foreach ($dentists as $dentist): ?>

<option
value="<?= (int) $dentist['id'] ?>"
>

<?= bookSchedulingEscape(
$dentist['dentist_name']
) ?>

<?php if (!empty($dentist['specialization'])): ?>

-
<?= bookSchedulingEscape(
$dentist['specialization']
) ?>

<?php endif; ?>

<?php
if (array_key_exists('rest_day', $dentist) && $dentist['rest_day'] !== null && $dentist['rest_day'] !== ''):
    $schedDayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    $schedRest = $schedDayNames[(int)$dentist['rest_day']] ?? '';
    if ($schedRest !== ''):
?>
(Rest day: <?= bookSchedulingEscape($schedRest) ?>)
<?php
    endif;
endif;
?>

</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-md-6">

<label class="form-label">
Date
</label>

<input
type="date"
name="appointment_date"
class="form-control"
min="<?= date('Y-m-d') ?>"
required
>

</div>

<div class="col-md-4">

<label class="form-label">
Preferred Time
</label>

<input
type="time"
name="start_time"
id="startTimeInput"
class="form-control"
required
>

</div>

<div class="col-md-4">

<label class="form-label">
End Time
</label>

<input
type="time"
id="endTimeDisplay"
class="form-control"
readonly
style="background:#f8fcfd;"
placeholder="Auto"
>

<small class="text-muted" style="font-size:12px;">Auto-calculated</small>

</div>

<div class="col-md-4">

<label class="form-label">
Dental Service
</label>

<select
name="service_type"
class="form-select"
required
>

<option value="">
Select Service
</option>

<?php foreach ($services as $service): ?>

<option value="<?= bookSchedulingEscape($service) ?>">
<?= bookSchedulingEscape($service) ?>
</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-12">

<label class="form-label">
Notes
</label>

<textarea
name="notes"
id="notesField"
class="form-control"
rows="4"
placeholder="Tell us your dental concern..."
></textarea>

</div>

<div class="col-12">
<label class="form-label">Available Time Slots <small class="text-muted">(click to select)</small></label>
<div id="slotContainer" class="d-flex flex-wrap gap-2 p-3 border rounded-3" style="background:#ffffff; min-height:70px;">
<small class="text-muted">Select dentist, service and date first</small>
</div>
<small class="text-muted" id="slotMessage" style="font-size:12.5px;"></small>
</div>

<div class="col-12">

<button
type="button"
id="openConfirm"
class="btn btn-primary"
>

<i class="bi bi-calendar-check me-1"></i>

Review & Submit Schedule

</button>
<a href="my_appointments.php" class="btn btn-outline-primary ms-2"><i class="bi bi-card-list"></i> View My Schedules</a>

</div>

</div>

</form>

</div>
</div>

</main>

</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmScheduleModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Confirm Schedule</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body" id="confirmBody" style="font-size:14.5px;"></div>
<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Edit</button><button type="button" id="confirmSubmit" class="btn btn-primary">Confirm & Submit</button></div>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const dentistSelect = document.querySelector('select[name="dentist_id"]');
const dateInput = document.querySelector('input[name="appointment_date"]');
const serviceSelect = document.querySelector('select[name="service_type"]');
const timeInput = document.querySelector('input[name="start_time"]');
const slotContainer = document.getElementById('slotContainer');
const slotMessage = document.getElementById('slotMessage');
const notesField = document.getElementById('notesField');
const endTimeDisplay = document.getElementById('endTimeDisplay');

const durationMap = {
    'General Dentistry': 60, 'Orthodontics (Braces)': 60, 'Oral Surgery (Tooth Extraction or Bunot)': 90,
    'Cosmetic': 120, 'Fillings or Pasta': 60, 'Root Canal': 120, 'Oral Prophylaxis or Cleaning': 60,
    'Denture': 60, 'Teeth Whitening': 90, 'Crown': 90, 'Bridge': 90, 'Veneers': 120, 'Dental Check-up': 30
};
function updateEndTime(){
    const s = serviceSelect.value;
    const t = timeInput.value;
    if(s && t){
        const d = durationMap[s] || 30;
        const [h,m] = t.split(':').map(Number);
        if(!isNaN(h) && !isNaN(m)){
            const end = new Date(0,0,0,h,m + d);
            const eh = String(end.getHours()).padStart(2,'0');
            const em = String(end.getMinutes()).padStart(2,'0');
            endTimeDisplay.value = eh + ':' + em;
            return;
        }
    }
    endTimeDisplay.value = '';
}
serviceSelect.addEventListener('change', ()=>{ updateEndTime(); fetchSlots(); });
timeInput.addEventListener('change', ()=>{ updateEndTime(); });
timeInput.addEventListener('input', updateEndTime);
dateInput.addEventListener('change', fetchSlots);
dentistSelect.addEventListener('change', fetchSlots);

async function fetchSlots(){
    const dentistId = dentistSelect.value;
    const date = dateInput.value;
    const service = serviceSelect.value;
    if(!dentistId || !date || !service){
        slotContainer.innerHTML = '<small class="text-muted">Select dentist, service and date first</small>';
        slotMessage.textContent = '';
        return;
    }
    slotContainer.innerHTML = '<small class="text-muted">Loading slots...</small>';
    try{
        const url = `get_available_slots.php?dentist_id=${encodeURIComponent(dentistId)}&date=${encodeURIComponent(date)}&service=${encodeURIComponent(service)}`;
        const res = await fetch(url);
        const data = await res.json();
        slotMessage.textContent = data.message || '';
        if(data.slots && data.slots.length){
            slotContainer.innerHTML = '';
            data.slots.forEach(s=>{
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-sm btn-outline-primary';
                btn.textContent = s.start_display + ' - ' + s.end_display;
                btn.title = s.start + ' - ' + s.end;
                btn.addEventListener('click', ()=>{
                    timeInput.value = s.start;
                    updateEndTime();
                    document.querySelectorAll('#slotContainer .btn').forEach(x=>x.classList.remove('btn-primary'));
                    btn.classList.remove('btn-outline-primary');
                    btn.classList.add('btn-primary');
                });
                slotContainer.appendChild(btn);
            });
        } else {
            slotContainer.innerHTML = '<small class="text-muted">No available slots for this date/dentist</small>';
        }
    } catch(e){
        slotContainer.innerHTML = '<small class="text-danger">Unable to load slots</small>';
    }
}

const openConfirm = document.getElementById('openConfirm');
const confirmBody = document.getElementById('confirmBody');
const confirmSubmit = document.getElementById('confirmSubmit');
const form = document.querySelector('form[method="POST"]');
openConfirm.addEventListener('click', ()=>{
    if(!dentistSelect.value || !dateInput.value || !timeInput.value || !serviceSelect.value){
        alert('Please complete Dentist, Date, Time and Service first.');
        return;
    }
    const dentistText = dentistSelect.options[dentistSelect.selectedIndex].text;
    const endVal = endTimeDisplay.value || 'auto';
    confirmBody.innerHTML = `
        <div><strong>Dentist:</strong> ${dentistText}</div>
        <div><strong>Date:</strong> ${dateInput.value}</div>
        <div><strong>Time:</strong> ${timeInput.value} - ${endVal}</div>
        <div><strong>Service:</strong> ${serviceSelect.value}</div>
        <div><strong>Notes:</strong> ${notesField.value || '—'}</div>
        <hr><small class="text-muted">End time auto-calculated from service duration.</small>
    `;
    new bootstrap.Modal(document.getElementById('confirmScheduleModal')).show();
});
confirmSubmit.addEventListener('click', ()=>{ form.submit(); });
</script>

</body>

</html>