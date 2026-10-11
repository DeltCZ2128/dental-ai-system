<?php
/**
 * ============================================================
 * ABALOS DENTAL CLINIC
 * GET AVAILABLE STARTING TIMES
 * ============================================================
 *
 * This file:
 * - Gets the selected dentist
 * - Gets the selected dental service
 * - Gets the service duration from clinic_services
 * - Generates STARTING TIME only
 * - Automatically calculates END TIME
 * - Checks appointment conflicts
 *
 * IMPORTANT:
 * Do NOT add function e() here.
 * config.php already contains e().
 * ============================================================
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');


/* ============================================================
   JSON RESPONSE
   ============================================================ */

function slot_json($success, $message = '', $extra = [])
{
    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message
            ],
            $extra
        )
    );

    exit;
}


/* ============================================================
   PATIENT LOGIN CHECK
   ============================================================ */

if (
    !isset($_SESSION['user_id']) ||
    strtolower(trim($_SESSION['role'] ?? '')) !== 'patient'
) {
    slot_json(
        false,
        'Unauthorized access.'
    );
}


/* ============================================================
   GET REQUEST VALUES
   ============================================================ */

$date = trim(
    $_GET['date'] ?? ''
);

$dentistId = (int)(
    $_GET['dentist_id'] ?? 0
);

$serviceId = (int)(
    $_GET['service_id'] ?? 0
);

$serviceNameFromRequest = trim(
    $_GET['service'] ?? ''
);


/* ============================================================
   VALIDATE DATE
   ============================================================ */

if (
    !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $date
    )
) {
    slot_json(
        false,
        'Invalid appointment date.'
    );
}


/* ============================================================
   PAST DATE CHECK
   ============================================================ */

if (
    $date < date('Y-m-d')
) {
    slot_json(
        false,
        'You cannot book an appointment on a past date.'
    );
}


/* ============================================================
   DENTIST + SERVICE CHECK
   ============================================================ */

if ($dentistId <= 0) {

    slot_json(
        false,
        'Please select a dentist.'
    );
}

if (
    $serviceId <= 0 &&
    $serviceNameFromRequest === ''
) {

    slot_json(
        false,
        'Please select a dental service.'
    );
}


/* ============================================================
   GET SELECTED DENTAL SERVICE
   ============================================================
   
   IMPORTANT FIX:
   
   We do NOT require:
   
       status = 'Active'
   
   because the Calendar already displays the services
   from clinic_services.
   
   The selected service is identified by ID whenever possible.
   
   This allows ALL existing dental services to work.
   ============================================================ */

$serviceRow = null;

try {

    /*
     * --------------------------------------------------------
     * METHOD 1:
     * Use service ID.
     * --------------------------------------------------------
     */

    if ($serviceId > 0) {

        $stmt = $pdo->prepare(
            "
            SELECT
                id,
                service_name,
                duration_minutes
            FROM clinic_services
            WHERE id = ?
            LIMIT 1
            "
        );

        $stmt->execute([
            $serviceId
        ]);

        $serviceRow =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );
    }


    /*
     * --------------------------------------------------------
     * METHOD 2:
     * If no service ID was sent, use service name.
     * --------------------------------------------------------
     */

    if (
        !$serviceRow &&
        $serviceNameFromRequest !== ''
    ) {

        $stmt = $pdo->prepare(
            "
            SELECT
                id,
                service_name,
                duration_minutes
            FROM clinic_services
            WHERE TRIM(service_name) = TRIM(?)
            LIMIT 1
            "
        );

        $stmt->execute([
            $serviceNameFromRequest
        ]);

        $serviceRow =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );
    }

} catch (Throwable $e) {

    slot_json(
        false,
        'Unable to read the selected dental service.'
    );
}


/* ============================================================
   SERVICE NOT FOUND
   ============================================================ */

if (!$serviceRow) {

    slot_json(
        false,
        'The selected dental service could not be found in the database.'
    );
}


/* ============================================================
   SERVICE DATA
   ============================================================ */

$serviceId = (int)(
    $serviceRow['id'] ?? 0
);

$serviceName = trim(
    $serviceRow['service_name'] ?? ''
);

$duration = (int)(
    $serviceRow['duration_minutes'] ?? 0
);


/* ============================================================
   VALIDATE SERVICE DATA
   ============================================================ */

if (
    $serviceId <= 0 ||
    $serviceName === ''
) {

    slot_json(
        false,
        'The selected dental service has invalid information.'
    );
}


/*
 * If no duration was saved,
 * use 30 minutes as the minimum.
 */
if ($duration <= 0) {
    $duration = 30;
}


/* ============================================================
   GET SELECTED DENTIST
   ============================================================ */

try {

    /*
     * IMPORTANT:
     * Do NOT require status = Active here either.
     * The dentist is already displayed by Calendar.
     */

    $stmt = $pdo->prepare(
        "
        SELECT
            id,
            dentist_name,
            specialization
        FROM dentists
        WHERE id = ?
        LIMIT 1
        "
    );

    $stmt->execute([
        $dentistId
    ]);

    $dentist =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

} catch (Throwable $e) {

    slot_json(
        false,
        'Unable to read the selected dentist.'
    );
}


/* ============================================================
   DENTIST NOT FOUND
   ============================================================ */

if (!$dentist) {

    slot_json(
        false,
        'The selected dentist could not be found.'
    );
}


/* ============================================================
   CLINIC SETTINGS
   ============================================================ */

$slotInterval = 30;
$dailyCapacity = 10;

try {

    $stmt = $pdo->query(
        "
        SELECT
            slot_interval_minutes,
            max_patients_per_day
        FROM clinic_settings
        ORDER BY id ASC
        LIMIT 1
        "
    );

    $settings =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

    if ($settings) {

        if (
            isset(
                $settings['slot_interval_minutes']
            )
        ) {

            $slotInterval =
                (int)$settings[
                    'slot_interval_minutes'
                ];
        }

        if (
            isset(
                $settings['max_patients_per_day']
            )
        ) {

            $dailyCapacity =
                (int)$settings[
                    'max_patients_per_day'
                ];
        }
    }

} catch (Throwable $e) {

    /*
     * Keep the default settings.
     */
}


/* ============================================================
   SAFE SETTINGS
   ============================================================ */

if ($slotInterval < 15) {
    $slotInterval = 30;
}

if ($dailyCapacity < 1) {
    $dailyCapacity = 10;
}


/* ============================================================
   CHECK DAY
   ============================================================ */

$dayOfWeek = (int)(
    date(
        'w',
        strtotime($date)
    )
);


/*
 * Sunday = 0
 * Clinic is closed every Sunday for all dentists.
 */

if ($dayOfWeek === 0) {

    slot_json(
        true,
        'The clinic is closed on Sunday.',
        [
            'slots' => [],
            'service_id' => $serviceId,
            'service_name' => $serviceName,
            'duration_minutes' => $duration
        ]
    );
}


/* ============================================================
   PER-DENTIST WEEKLY REST DAY
   Each dentist has his/her own rest day (0=Sunday..6=Saturday).
   No booking allowed when the chosen date falls on that day.
   ============================================================ */

$dentistRestDay = null;

if (function_exists('getDentistRestDay')) {
    $dentistRestDay = getDentistRestDay($dentistId);
}

if ($dentistRestDay !== null && $dayOfWeek === (int)$dentistRestDay) {

    $restDayName = function_exists('dentistRestDayName')
        ? dentistRestDayName($dentistRestDay)
        : date('l', strtotime($date));

    $dentistLabel = trim($dentist['dentist_name'] ?? 'This dentist');

    slot_json(
        true,
        $dentistLabel . ' is on rest day every ' . $restDayName . '. Please choose another dentist or another date.',
        [
            'slots' => [],
            'service_id' => $serviceId,
            'service_name' => $serviceName,
            'duration_minutes' => $duration
        ]
    );
}


/* ============================================================
   PER-DENTIST SPECIFIC REST DATE (flexible, isang petsa lang)
   Hal. ngayong Thursday rest, next week pwedeng Monday naman.
   ============================================================ */

$dayOffRow = function_exists('getDentistDayOff')
    ? getDentistDayOff($dentistId, $date)
    : null;

if ($dayOffRow !== null) {

    $dentistLabel = trim($dentist['dentist_name'] ?? 'This dentist');

    $prettyDate = date('M d, Y (l)', strtotime($date));

    slot_json(
        true,
        $dentistLabel . ' is on rest day on ' . $prettyDate . '. Please choose another dentist or another date.',
        [
            'slots' => [],
            'service_id' => $serviceId,
            'service_name' => $serviceName,
            'duration_minutes' => $duration
        ]
    );
}


/* ============================================================
   CLINIC HOURS
   ============================================================
   
   Monday-Saturday:
   8:00 AM - 6:00 PM
   ============================================================ */

$clinicOpen = '08:00:00';
$clinicClose = '18:00:00';


/* ============================================================
   DAILY PATIENT COUNT
   ============================================================ */

try {

    $stmt = $pdo->prepare(
        "
        SELECT COUNT(*)
        FROM appointments
        WHERE appointment_date = ?
          AND status NOT IN (
              'Cancelled',
              'No-Show'
          )
        "
    );

    $stmt->execute([
        $date
    ]);

    $dayAppointmentCount =
        (int)$stmt->fetchColumn();

} catch (Throwable $e) {

    $dayAppointmentCount = 0;
}


/* ============================================================
   DAILY CAPACITY CHECK
   ============================================================ */

if (
    $dayAppointmentCount >=
    $dailyCapacity
) {

    slot_json(
        true,
        'This date has reached the clinic daily patient limit of ' .
        $dailyCapacity .
        '.',
        [
            'slots' => [],
            'service_id' => $serviceId,
            'service_name' => $serviceName,
            'duration_minutes' => $duration,
            'daily_capacity' => $dailyCapacity,
            'booked_today' => $dayAppointmentCount
        ]
    );
}


/* ============================================================
   EXISTING APPOINTMENTS FOR SELECTED DENTIST
   ============================================================ */

try {

    $stmt = $pdo->prepare(
        "
        SELECT
            start_time,
            end_time
        FROM appointments
        WHERE dentist_id = ?
          AND appointment_date = ?
          AND status NOT IN (
              'Cancelled',
              'No-Show'
          )
        ORDER BY start_time ASC
        "
    );

    $stmt->execute([
        $dentistId,
        $date
    ]);

    $booked =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

} catch (Throwable $e) {

    slot_json(
        false,
        'Unable to check existing appointments.'
    );
}


/* ============================================================
   EXISTING APPOINTMENTS FOR CURRENT PATIENT (any dentist)
   - If patient already has 10am-11am, hide that slot on calendar
   ============================================================ */

$patientBooked = [];

try {
    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($currentUserId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM patients WHERE user_id = ? LIMIT 1");
        $stmt->execute([$currentUserId]);
        $currentPatientId = (int)($stmt->fetchColumn() ?: 0);

        if ($currentPatientId > 0) {
            $stmt = $pdo->prepare(
                "
                SELECT start_time, end_time
                FROM appointments
                WHERE patient_id = ?
                  AND appointment_date = ?
                  AND status NOT IN ('Cancelled', 'No-Show')
                ORDER BY start_time ASC
                "
            );
            $stmt->execute([$currentPatientId, $date]);
            $patientBooked = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {
    $patientBooked = [];
}


/* ============================================================
   TIME CONVERSION
   ============================================================ */

function slot_time_to_minutes($time)
{
    $time = substr(
        (string)$time,
        0,
        5
    );

    $parts = explode(
        ':',
        $time
    );

    $hours = (int)(
        $parts[0] ?? 0
    );

    $minutes = (int)(
        $parts[1] ?? 0
    );

    return (
        $hours * 60
    ) + $minutes;
}


/* ============================================================
   MINUTES TO TIME
   ============================================================ */

function slot_minutes_to_time($minutes)
{
    $hours = floor(
        $minutes / 60
    );

    $minutesOnly =
        $minutes % 60;

    return sprintf(
        '%02d:%02d',
        $hours,
        $minutesOnly
    );
}


/* ============================================================
   DISPLAY TIME
   ============================================================ */

function slot_display_time($time)
{
    return date(
        'h:i A',
        strtotime($time)
    );
}


/* ============================================================
   CLINIC TIME
   ============================================================ */

$clinicStart =
    slot_time_to_minutes(
        $clinicOpen
    );

$clinicEnd =
    slot_time_to_minutes(
        $clinicClose
    );


/* ============================================================
   GENERATE STARTING TIMES
   ============================================================
   
   IMPORTANT:
   
    1-HOUR SLOTS ONLY (hal. 8-9AM, 9-10AM, 10-11AM).

    Calendar buttons (fixed 1 hour each):

    08:00 AM - 09:00 AM
    09:00 AM - 10:00 AM
    10:00 AM - 11:00 AM

    Internally, booking still saves the real service
    duration and overlap is still checked with it,
    so longer services correctly hide the hours
    they occupy.
    ============================================================ */

$slots = [];

/* Fixed 1-hour step (tig-iisang oras lang ang slots). */
$hourlyStep = 60;
$isToday = ($date === date('Y-m-d'));
$nowMinutes = ((int)date('G') * 60) + (int)date('i');

/* Check if dentist has custom schedule windows specifically on this date */
$customDateWindows = [];
try {
    $csStmt = $pdo->prepare("
        SELECT start_time, end_time
        FROM dentist_schedules
        WHERE dentist_id = ?
          AND schedule_date = ?
        ORDER BY start_time ASC
    ");
    $csStmt->execute([$dentistId, $date]);
    $customDateWindows = $csStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $customDateWindows = [];
}


for (
    $start = $clinicStart;

    (
        $start + $duration
    ) <= $clinicEnd;

    $start += $hourlyStep
) {
    if ($isToday && $start <= $nowMinutes) {
        continue;
    }

    /* Skip 12:00 PM - 1:00 PM clinic lunch break */
    if ($start >= 720 && $start < 780) {
        continue;
    }

    /*
     * --------------------------------------------------------
     * SERVICE-BASED END (overlap check only)
     * --------------------------------------------------------
     */

    $end =
        $start +
        $duration;

    /* If custom schedule windows exist for this specific date, require slot to fit within one */
    if (!empty($customDateWindows)) {
        $fitsCustomWindow = false;
        foreach ($customDateWindows as $cw) {
            $cwStart = slot_time_to_minutes($cw['start_time']);
            $cwEnd = slot_time_to_minutes($cw['end_time']);
            if ($start >= $cwStart && $end <= $cwEnd) {
                $fitsCustomWindow = true;
                break;
            }
        }
        if (!$fitsCustomWindow) {
            continue;
        }
    }


    /*
     * --------------------------------------------------------
     * HOURLY DISPLAY (what the patient picks: 8-9AM)
     * --------------------------------------------------------
     */

    $displayEnd =
        $start +
        $hourlyStep;


    $startTime =
        slot_minutes_to_time(
            $start
        );

    $endTime =
        slot_minutes_to_time(
            $displayEnd
        );


    /* ========================================================
       CHECK APPOINTMENT CONFLICT (dentist + patient's own)
       ======================================================== */

    $conflict = false;

    // 1) Dentist is already booked
    foreach (
        $booked as $appointment
    ) {

        $bookedStart =
            slot_time_to_minutes(
                $appointment[
                    'start_time'
                ]
            );

        $bookedEnd =
            slot_time_to_minutes(
                $appointment[
                    'end_time'
                ]
            );


        /*
         * Appointment overlap:
         *
         * New Start < Existing End
         * AND
         * New End > Existing Start
         */

        if (
            $start < $bookedEnd &&
            $end > $bookedStart
        ) {

            $conflict = true;

            break;
        }
    }

    // 2) Patient already has an appointment at that time (any dentist) - hide slot
    if (!$conflict && !empty($patientBooked)) {
        foreach ($patientBooked as $appointment) {
            $bookedStart = slot_time_to_minutes($appointment['start_time']);
            $bookedEnd = slot_time_to_minutes($appointment['end_time']);
            if ($start < $bookedEnd && $end > $bookedStart) {
                $conflict = true;
                break;
            }
        }
    }


    /* ========================================================
       SKIP CONFLICTED TIME
       ======================================================== */

    if ($conflict) {
        continue;
    }


    /* ========================================================
       ADD STARTING TIME
       ======================================================== */

    $slots[] = [

        /*
         * Actual start time
         */
        'start' =>
            $startTime,

        /*
         * Automatically calculated end time
         */
        'end' =>
            $endTime,

        /*
         * What the patient sees
         */
        'start_display' =>
            slot_display_time(
                $startTime
            ),

        /*
         * Used by review/booking
         */
        'end_display' =>
            slot_display_time(
                $endTime
            )
    ];
}


/* ============================================================
   RESULT MESSAGE
   ============================================================ */

$slotCount =
    count($slots);

$message =
    $slotCount .
    ' available 1-hour slot' .
    (
        $slotCount === 1
            ? ''
            : 's'
    ) .
    ' for ' .
    $dentist['dentist_name'] .
    '.';


/* ============================================================
   RETURN DATA
   ============================================================ */

slot_json(
    true,
    $message,
    [

        /*
         * Available starting times
         */
        'slots' =>
            $slots,

        /*
         * Selected service
         */
        'service_id' =>
            $serviceId,

        'service_name' =>
            $serviceName,

        /*
         * Service duration
         */
        'duration_minutes' =>
            $duration,

        /*
         * Selected dentist
         */
        'dentist_id' =>
            $dentistId,

        'dentist_name' =>
            $dentist['dentist_name'],

        /*
         * Clinic information
         */
        'clinic_open' =>
            '08:00:00',

        'clinic_close' =>
            '18:00:00',

        /*
         * Capacity information
         */
        'daily_capacity' =>
            $dailyCapacity,

        'booked_today' =>
            $dayAppointmentCount
    ]
);