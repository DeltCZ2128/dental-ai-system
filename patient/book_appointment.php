<?php

/**
 * ============================================================
 * ABALOS DENTAL CLINIC
 * PATIENT - BOOK APPOINTMENT
 * ============================================================
 *
 * This file:
 * - Displays the booking form
 * - Finds the logged-in patient
 * - Creates a patient record if needed
 * - Checks dentist availability
 * - Checks appointment conflicts
 * - Calculates automatic end time
 * - Saves appointment to the database
 * - Shows success/error message
 *
 * IMPORTANT:
 * The form submits to THIS SAME FILE.
 * This prevents the "book_appointments.php - Not Found" error.
 * ============================================================
 */

require_once __DIR__ . '/../config.php';


/* ============================================================
   AUTHENTICATION
   ============================================================ */

if (
    !isset($_SESSION['user_id']) ||
    strtolower(trim($_SESSION['role'] ?? '')) !== 'patient'
) {
    header('Location: ../login.php');
    exit;
}


/* ============================================================
   HELPER
   ============================================================ */

if (!function_exists('patient_booking_e')) {
    function patient_booking_e($value)
    {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}


/* ============================================================
   CURRENT USER
   ============================================================ */

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: ../login.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT *
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}


/* ============================================================
   GET TABLE COLUMNS
   ============================================================ */

function patientBookingTableColumns(PDO $pdo, string $table): array
{
    $allowedTables = [
        'users',
        'patients',
        'dentists',
        'appointments',
        'dentist_schedules'
    ];

    if (!in_array($table, $allowedTables, true)) {
        return [];
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");

        $columns = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['Field'])) {
                $columns[] = $row['Field'];
            }
        }

        return $columns;

    } catch (PDOException $e) {
        return [];
    }
}


$userColumns = patientBookingTableColumns($pdo, 'users');
$patientColumns = patientBookingTableColumns($pdo, 'patients');
$appointmentColumns = patientBookingTableColumns($pdo, 'appointments');


/* ============================================================
   USER EMAIL
   ============================================================ */

$userEmail = '';

if (in_array('email', $userColumns, true) && !empty($currentUser['email'])) {
    $userEmail = trim($currentUser['email']);
} elseif (in_array('username', $userColumns, true) && !empty($currentUser['username']) && strpos($currentUser['username'], '@') !== false) {
    $userEmail = trim($currentUser['username']);
}

$userContact = '';
if (in_array('contact_number', $userColumns, true) && !empty($currentUser['contact_number'])) {
    $userContact = trim($currentUser['contact_number']);
}


/* ============================================================
   GET USER NAME
   ============================================================ */

$fullName = '';

if (
    in_array('name', $userColumns, true) &&
    !empty($currentUser['name'])
) {
    $fullName = trim($currentUser['name']);

} elseif (
    in_array('full_name', $userColumns, true) &&
    !empty($currentUser['full_name'])
) {
    $fullName = trim($currentUser['full_name']);

} else {

    $first =
        $currentUser['first_name']
        ?? $currentUser['firstname']
        ?? '';

    $last =
        $currentUser['last_name']
        ?? $currentUser['lastname']
        ?? '';

    $fullName = trim(
        $first . ' ' . $last
    );
}

if ($fullName === '') {
    $fullName = 'Patient';
}


/* ============================================================
   SPLIT NAME
   ============================================================ */

$nameParts = preg_split(
    '/\s+/',
    trim($fullName)
);

$firstName = $nameParts[0] ?? 'Patient';

$lastName = '';

if (count($nameParts) > 1) {
    $lastName = implode(
        ' ',
        array_slice($nameParts, 1)
    );
}


/* ============================================================
   FIND EXISTING PATIENT
   ============================================================ */

$patient = null;


/*
 * 1. SEARCH BY users.patient_id
 */

if (
    in_array('patient_id', $userColumns, true) &&
    !empty($currentUser['patient_id'])
) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM patients
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        (int)$currentUser['patient_id']
    ]);

    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
}


/*
 * 2. SEARCH BY patients.user_id
 *
 * This is the preferred method because your
 * patient_dashboard.php also uses user_id.
 */

if (
    !$patient &&
    in_array('user_id', $patientColumns, true)
) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM patients
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $userId
    ]);

    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
}


/*
 * 3. SEARCH BY EMAIL
 */

if (
    !$patient &&
    $userEmail !== '' &&
    in_array('email', $patientColumns, true)
) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM patients
        WHERE email = ?
        LIMIT 1
    ");

    $stmt->execute([
        $userEmail
    ]);

    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
}


/*
 * 4. SEARCH BY NAME
 */

if (!$patient) {

    try {

        $stmt = $pdo->prepare("
            SELECT *
            FROM patients
            WHERE first_name = ?
            AND last_name = ?
            LIMIT 1
        ");

        $stmt->execute([
            $firstName,
            $lastName
        ]);

        $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {

        $patient = null;
    }
}


/* ============================================================
   CREATE PATIENT IF NONE EXISTS
   ============================================================ */

if (!$patient) {

    try {

        $patientNumber = generateShortPatientNumber($pdo);


        $insertColumns = [
            'patient_number',
            'first_name',
            'last_name'
        ];

        $insertValues = [
            $patientNumber,
            $firstName,
            $lastName
        ];


        /*
         * IMPORTANT:
         * Connect patient to logged-in user if user_id exists.
         */

        if (
            in_array('user_id', $patientColumns, true)
        ) {
            $insertColumns[] = 'user_id';
            $insertValues[] = $userId;
        }


        if (
            $userEmail !== '' &&
            in_array('email', $patientColumns, true)
        ) {
            $insertColumns[] = 'email';
            $insertValues[] = $userEmail;
        }

        if (
            $userContact !== '' &&
            in_array('contact_number', $patientColumns, true)
        ) {
            $insertColumns[] = 'contact_number';
            $insertValues[] = $userContact;
        }


        if (
            in_array('status', $patientColumns, true)
        ) {
            $insertColumns[] = 'status';
            $insertValues[] = 'Active';
        }


        if (
            in_array('date_registered', $patientColumns, true)
        ) {
            $insertColumns[] = 'date_registered';
            $insertValues[] = date('Y-m-d H:i:s');
        }


        $placeholders = implode(
            ', ',
            array_fill(
                0,
                count($insertColumns),
                '?'
            )
        );


        $columnList =
            '`' .
            implode(
                '`, `',
                $insertColumns
            ) .
            '`';


        $sql = "
            INSERT INTO patients
            ($columnList)
            VALUES
            ($placeholders)
        ";


        $stmt = $pdo->prepare($sql);

        $stmt->execute(
            $insertValues
        );


        $newPatientId =
            (int)$pdo->lastInsertId();


        /*
         * Connect users.patient_id if that column exists.
         */

        if (
            in_array('patient_id', $userColumns, true)
        ) {

            try {

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET patient_id = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $newPatientId,
                    $userId
                ]);

            } catch (PDOException $e) {
                // Continue.
            }
        }


        /*
         * Load newly created patient.
         */

        $stmt = $pdo->prepare("
            SELECT *
            FROM patients
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $newPatientId
        ]);

        $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {

        $patient = null;
    }
}


/* ============================================================
   PATIENT ID
   ============================================================ */

$patientId = $patient
    ? (int)($patient['id'] ?? 0)
    : 0;


/* ============================================================
   MESSAGES
   ============================================================ */

$successMessage = '';
$errorMessage = '';


/* ============================================================
   FORM VALUES
   ============================================================ */

$selectedDentistId = 0;
$selectedDate = '';
$selectedStartTime = '';
$selectedEndTime = '';
$selectedServices = [];
$notesValue = '';


/* ============================================================
   DENTAL SERVICE DURATIONS
   ============================================================ */

$serviceDurations = [];

/* Use the existing clinic_services table when available.
 * This makes every service that exists in your database selectable
 * and allows Calendar -> Book Appointment to use the exact same name.
 */
try {
    $stmt = $pdo->query("
        SELECT service_name, duration_minutes
        FROM clinic_services
        WHERE status = 'Active'
        ORDER BY service_name ASC
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $serviceRow) {
        $name = trim((string)($serviceRow['service_name'] ?? ''));
        if ($name !== '') {
            $serviceDurations[$name] = max(1, (int)$serviceRow['duration_minutes']);
        }
    }
} catch (Throwable $e) {
    // Keep the existing fallback durations if clinic_services is unavailable.
}

/* Fallback values matching the existing Book Appointment services. */
if (empty($serviceDurations)) {
    $serviceDurations = [
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
}


$selectedEndTime = '';
$selectedServices = [];
$notesValue = '';


/* ============================================================
   PREFILL FROM CALENDAR
   ============================================================
   When the patient comes from Calendar, keep the original
   Book Appointment form exactly as it is, but automatically
   fill the selected date, dentist, dental service, and
   starting time. End time is calculated from the service.
   ============================================================ */

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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    $selectedDentistId = (int)($_GET['dentist_id'] ?? 0);
    $selectedDate = trim($_GET['appointment_date'] ?? ($_GET['date'] ?? ''));
    $selectedStartTime = trim($_GET['start_time'] ?? ($_GET['time'] ?? ''));

    /* Support service=, service_type=, and service_type[]= */
    $calendarServices = [];

    if (isset($_GET['service_type'])) {
        $calendarServices = is_array($_GET['service_type'])
            ? $_GET['service_type']
            : [$_GET['service_type']];
    } elseif (isset($_GET['service'])) {
        $calendarServices = is_array($_GET['service'])
            ? $_GET['service']
            : [$_GET['service']];
    }

    foreach ($calendarServices as $calendarService) {
        $calendarService = trim((string)$calendarService);

        if ($calendarService === '') {
            continue;
        }

        /* Allow values such as "Crown (90 min)" from Calendar. */
        $calendarService = preg_replace('/\s*\(\s*\d+\s*min(?:utes?)?\s*\)$/i', '', $calendarService);
        $calendarService = trim($calendarService);

        /* First try an exact match. */
        if (isset($serviceDurations[$calendarService])) {
            if (!in_array($calendarService, $selectedServices, true)) {
                $selectedServices[] = $calendarService;
            }
            continue;
        }

        /*
         * If the Calendar sent a slightly different spelling/case,
         * match it against the actual service name from the database.
         */
        foreach ($serviceDurations as $dbServiceName => $duration) {
            if (strcasecmp(trim($dbServiceName), $calendarService) === 0) {
                if (!in_array($dbServiceName, $selectedServices, true)) {
                    $selectedServices[] = $dbServiceName;
                }
                break;
            }
        }
    }

    /* Automatically calculate the end time for the preselected service(s). */
    if ($selectedDate !== '' && $selectedStartTime !== '' && !empty($selectedServices)) {
        $prefillDuration = 0;

        foreach ($selectedServices as $prefillService) {
            $prefillDuration += $serviceDurations[$prefillService] ?? 30;
        }

        $prefillStart = DateTime::createFromFormat(
            'Y-m-d H:i',
            $selectedDate . ' ' . $selectedStartTime
        );

        if ($prefillStart) {
            $prefillEnd = clone $prefillStart;
            $prefillEnd->modify('+' . $prefillDuration . ' minutes');

            if ($prefillEnd->format('Y-m-d') === $selectedDate) {
                $selectedEndTime = $prefillEnd->format('H:i');
            }
        }
    }
}


/* ============================================================
   HANDLE BOOK APPOINTMENT
   ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = trim(
        $_POST['action'] ?? ''
    );


    if ($action === 'book_appointment') {


        /* ====================================================
           GET POST DATA
           ==================================================== */

        $selectedDentistId =
            (int)(
                $_POST['dentist_id'] ?? 0
            );


        $selectedDate =
            trim(
                $_POST['appointment_date'] ?? ''
            );


        $selectedStartTime =
            trim(
                $_POST['start_time'] ?? ''
            );


        $selectedEndTime =
            trim(
                $_POST['end_time'] ?? ''
            );


        $notesValue =
            trim(
                $_POST['notes'] ?? ''
            );


        /*
         * Services
         */

        $postedServices =
            $_POST['service_type'] ?? [];


        if (!is_array($postedServices)) {
            $postedServices = [
                $postedServices
            ];
        }


        $postedServices = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'trim',
                        $postedServices
                    ),
                    function ($service) {
                        return $service !== '';
                    }
                )
            )
        );


        /*
         * At least one service.
         */

        if (empty($postedServices)) {

            $errorMessage =
                'Please select at least one dental service.';

        } else {

            $selectedServices =
                $postedServices;
        }


        /* ====================================================
           CALCULATE DURATION
           ==================================================== */

        $appointmentDuration = 0;


        if (empty($errorMessage)) {

            foreach ($selectedServices as $service) {

                $appointmentDuration +=
                    $serviceDurations[$service]
                    ?? 30;
            }


            /*
             * Automatically calculate end time.
             */

            if (
                $selectedDate !== '' &&
                $selectedStartTime !== ''
            ) {

                $startDateTime =
                    DateTime::createFromFormat(
                        'Y-m-d H:i',
                        $selectedDate .
                        ' ' .
                        $selectedStartTime
                    );


                if (
                    !$startDateTime ||
                    DateTime::getLastErrors() !== false &&
                    (
                        DateTime::getLastErrors()['warning_count'] > 0 ||
                        DateTime::getLastErrors()['error_count'] > 0
                    )
                ) {

                    $errorMessage =
                        'Invalid appointment date or time.';

                } else {

                    $endDateTime =
                        clone $startDateTime;


                    $endDateTime->modify(
                        '+' .
                        $appointmentDuration .
                        ' minutes'
                    );


                    /*
                     * Do not allow appointment
                     * to go to the next day.
                     */

                    if (
                        $endDateTime->format('Y-m-d')
                        !==
                        $selectedDate
                    ) {

                        $errorMessage =
                            'The selected services are too long for the chosen start time. Please choose an earlier time.';

                    } else {

                        $selectedEndTime =
                            $endDateTime->format('H:i');
                    }
                }
            }
        }


        /* ====================================================
           BASIC VALIDATION
           ==================================================== */

        if (empty($errorMessage)) {

            if ($patientId <= 0) {

                $errorMessage =
                    'Your patient record could not be created. Please contact the clinic administrator.';

            } elseif (
                $selectedDentistId <= 0 ||
                $selectedDate === '' ||
                $selectedStartTime === '' ||
                $selectedEndTime === ''
            ) {

                $errorMessage =
                    'Please complete all required fields.';

            } elseif (
                !preg_match(
                    '/^\d{2}:\d{2}$/',
                    $selectedStartTime
                ) ||
                !preg_match(
                    '/^\d{2}:\d{2}$/',
                    $selectedEndTime
                )
            ) {

                $errorMessage =
                    'Please enter a valid appointment time.';

            } elseif (
                $selectedEndTime <= $selectedStartTime
            ) {

                $errorMessage =
                    'End time must be later than the start time.';

            } elseif (
                $selectedDate < date('Y-m-d')
            ) {

                $errorMessage =
                    'You cannot book an appointment on a past date.';

            } elseif (
                (int)date('N', strtotime($selectedDate)) === 7
            ) {

                $errorMessage =
                    'The clinic is closed on Sundays. Please select a date from Monday to Saturday.';

            } elseif (
                $selectedStartTime < '08:00' ||
                $selectedEndTime > '18:00'
            ) {

                $errorMessage =
                    'Appointments must fall within clinic operating hours (8:00 AM to 6:00 PM). Please select an earlier start time.';

            } elseif (
                $selectedStartTime >= '12:00' &&
                $selectedStartTime < '13:00'
            ) {

                $errorMessage =
                    '12:00 PM – 1:00 PM is the clinic lunch break. Please choose a morning (8:00 AM – 12:00 PM) or afternoon (1:00 PM – 6:00 PM) slot.';

            } elseif (
                $selectedDate === date('Y-m-d') &&
                $selectedStartTime <= date('H:i')
            ) {

                $errorMessage =
                    (date('H:i') >= '18:00')
                        ? 'The clinic is already closed for today. Please book an appointment for an upcoming date.'
                        : 'You cannot book a time slot that has already passed today. Please choose a later time or another date.';
            }
        }


        /* ====================================================
           CHECK DENTIST
           ==================================================== */

        if (empty($errorMessage)) {

            $stmt = $pdo->prepare("
                SELECT *
                FROM dentists
                WHERE id = ?
                AND status = 'Active'
                LIMIT 1
            ");

            $stmt->execute([
                $selectedDentistId
            ]);

            $dentist =
                $stmt->fetch(PDO::FETCH_ASSOC);


            if (!$dentist) {

                $errorMessage =
                    'The selected dentist is not available.';
            }
        }


        /* ====================================================
           CHECK PER-DENTIST REST DAY
           The clinic is closed on Sunday, and each dentist
           also has his/her own weekly rest day.
           ==================================================== */

        if (empty($errorMessage)) {

            $bookingDayOfWeek = (int)date('w', strtotime($selectedDate));

            if ($bookingDayOfWeek === 0) {

                $errorMessage =
                    'The clinic is closed on Sunday. Please choose another date.';

            } else {

                $restDay = function_exists('getDentistRestDay')
                    ? getDentistRestDay($selectedDentistId)
                    : null;

                /* Fallback when helper is unavailable: read column directly. */
                if ($restDay === null && isset($dentist['rest_day']) && $dentist['rest_day'] !== null && $dentist['rest_day'] !== '') {
                    $restDay = (int)$dentist['rest_day'];
                }

                if ($restDay !== null && $bookingDayOfWeek === (int)$restDay) {

                    $restName = function_exists('dentistRestDayName')
                        ? dentistRestDayName($restDay)
                        : date('l', strtotime($selectedDate));

                    $dentistLabel = trim($dentist['dentist_name'] ?? 'This dentist');

                    $errorMessage =
                        $dentistLabel . ' is on rest day every ' . $restName . '. Please choose another dentist or another date.';
                }

                /* Specific rest date (flexible: ibang araw kada linggo). */
                if (empty($errorMessage) && function_exists('isDentistDayOff') && isDentistDayOff($selectedDentistId, $selectedDate)) {
                    $dentistLabel = trim($dentist['dentist_name'] ?? 'This dentist');
                    $prettyDate = date('M d, Y (l)', strtotime($selectedDate));
                    $errorMessage =
                        $dentistLabel . ' is on rest day on ' . $prettyDate . '. Please choose another dentist or another date.';
                }
            }
        }


        /* ====================================================
           CHECK APPOINTMENT CONFLICT (DENTIST & PATIENT)
           ==================================================== */

        if (empty($errorMessage)) {

            /* 1) Check if the patient already has an overlapping appointment on that date */
            if ($patientId > 0) {
                $selfStmt = $pdo->prepare("
                    SELECT a.id, a.start_time, a.end_time, a.status, d.dentist_name
                    FROM appointments a
                    LEFT JOIN dentists d ON d.id = a.dentist_id
                    WHERE a.patient_id = ?
                      AND a.appointment_date = ?
                      AND a.status NOT IN ('Cancelled', 'Canceled', 'No-Show')
                      AND a.start_time < ?
                      AND a.end_time > ?
                    LIMIT 1
                ");
                $selfStmt->execute([
                    $patientId,
                    $selectedDate,
                    $selectedEndTime,
                    $selectedStartTime
                ]);
                $selfConflict = $selfStmt->fetch(PDO::FETCH_ASSOC);

                if ($selfConflict) {
                    $cStart = date('h:i A', strtotime($selfConflict['start_time']));
                    $cEnd = date('h:i A', strtotime($selfConflict['end_time']));
                    $cDentist = trim((string)($selfConflict['dentist_name'] ?? 'a dentist'));
                    $cStatus = trim((string)($selfConflict['status'] ?? 'Booked'));
                    $errorMessage = "Schedule Conflict: You already have a {$cStatus} appointment with {$cDentist} from {$cStart} to {$cEnd} on this date. Please choose another time.";
                }
            }

            /* 2) Check if the selected dentist is already booked during that time window */
            if (empty($errorMessage)) {
                $stmt = $pdo->prepare("
                    SELECT id, start_time, end_time, status
                    FROM appointments
                    WHERE dentist_id = ?
                    AND appointment_date = ?
                    AND status NOT IN ('Cancelled', 'Canceled', 'No-Show')
                    AND start_time < ?
                    AND end_time > ?
                    LIMIT 1
                ");

                $stmt->execute([
                    $selectedDentistId,
                    $selectedDate,
                    $selectedEndTime,
                    $selectedStartTime
                ]);

                $conflict = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($conflict) {
                    $cStart = date('h:i A', strtotime($conflict['start_time']));
                    $cEnd = date('h:i A', strtotime($conflict['end_time']));
                    $dLabel = trim($dentist['dentist_name'] ?? 'The selected dentist');
                    $errorMessage = "Schedule Conflict: {$dLabel} is already booked from {$cStart} to {$cEnd} on this date. Please choose another available time slot or another dentist.";
                }
            }

            /* 3) Check patient daily limit: maximum 2 appointments per day */
            if (empty($errorMessage)) {
                $dailyStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM appointments
                    WHERE patient_id = ?
                      AND appointment_date = ?
                      AND status NOT IN ('Cancelled', 'Canceled', 'No-Show')
                ");
                $dailyStmt->execute([$patientId, $selectedDate]);
                if ((int)$dailyStmt->fetchColumn() >= 2) {
                    $errorMessage = 'Appointment Limit Reached: You already have 2 appointments scheduled on ' . date('M d, Y', strtotime($selectedDate)) . '. Each patient is limited to a maximum of 2 appointments per day.';
                }
            }
        }


        /* ====================================================
           CHECK DENTIST SCHEDULE (PER-DATE CUSTOM OVERRIDE)
           ==================================================== */

        if (empty($errorMessage)) {

            $scheduleConfiguredForDate = false;
            $scheduleAvailable = true;

            try {

                /*
                 * Check if dentist has a custom schedule override for THIS specific date.
                 * When no custom override exists on this date, regular clinic shift (8 AM - 6 PM) applies.
                 */

                $stmt = $pdo->prepare("
                    SELECT id
                    FROM dentist_schedules
                    WHERE dentist_id = ?
                      AND schedule_date = ?
                    LIMIT 1
                ");

                $stmt->execute([
                    $selectedDentistId,
                    $selectedDate
                ]);

                $scheduleConfiguredForDate =
                    (bool)$stmt->fetch();

                if ($scheduleConfiguredForDate) {

                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM dentist_schedules
                        WHERE dentist_id = ?
                        AND schedule_date = ?
                        AND start_time <= ?
                        AND end_time >= ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $selectedDentistId,
                        $selectedDate,
                        $selectedStartTime,
                        $selectedEndTime
                    ]);

                    $scheduleAvailable =
                        (bool)$stmt->fetch();
                }

            } catch (PDOException $e) {

                $scheduleAvailable = true;
            }

            if (!$scheduleAvailable) {

                $errorMessage =
                    'The selected time falls outside this dentist\'s custom schedule for ' . date('M d, Y', strtotime($selectedDate)) . '. Please select an available slot.';
            }
        }


        /* ====================================================
           SAVE APPOINTMENT
           ==================================================== */

        if (empty($errorMessage)) {

            try {

                /*
                 * Build service text.
                 */

                $serviceType =
                    implode(
                        ' + ',
                        $selectedServices
                    );


                /*
                 * Check required appointment columns.
                 */

                $requiredAppointmentColumns = [
                    'patient_id',
                    'dentist_id',
                    'appointment_date',
                    'start_time',
                    'end_time',
                    'service_type',
                    'status',
                    'notes'
                ];


                foreach (
                    $requiredAppointmentColumns
                    as $requiredColumn
                ) {

                    if (
                        !in_array(
                            $requiredColumn,
                            $appointmentColumns,
                            true
                        )
                    ) {

                        throw new Exception(
                            "The appointments table is missing the '$requiredColumn' column."
                        );
                    }
                }


                /*
                 * Insert appointment.
                 */

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
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'Pending',
                        ?
                    )
                ");


                $stmt->execute([

                    $patientId,

                    $selectedDentistId,

                    $selectedDate,

                    $selectedStartTime,

                    $selectedEndTime,

                    $serviceType,

                    $notesValue
                ]);


                $appointmentId =
                    (int)$pdo->lastInsertId();


                /* =================================================
                   CREATE NOTIFICATION
                   ================================================= */

                if (
                    function_exists(
                        'createNotification'
                    )
                ) {

                    try {

                        createNotification(

                            $patientId,

                            $appointmentId,

                            'Appointment Request Submitted',

                            'Your appointment request for ' .
                            $selectedDate .
                            ' at ' .
                            date(
                                'h:i A',
                                strtotime(
                                    $selectedStartTime
                                )
                            ) .
                            ' has been submitted and is waiting for confirmation.',

                            'Confirmation'
                        );

                    } catch (Throwable $notificationError) {

                        /*
                         * Do not cancel the appointment
                         * if notification creation fails.
                         */
                    }
                }


                /* =================================================
                   SUCCESS
                   ================================================= */

                $successMessage =
                    'Your appointment has been submitted successfully! The clinic administrator can now see your booking.';


                /*
                 * Clear form values after successful booking.
                 */

                $selectedDentistId = 0;

                $selectedDate = '';

                $selectedStartTime = '';

                $selectedEndTime = '';

                $selectedServices = [];

                $notesValue = '';


            } catch (PDOException $e) {

                $errorMessage =
                    'Unable to save your appointment. Please try again. Database error: ' .
                    $e->getMessage();

            } catch (Exception $e) {

                $errorMessage =
                    $e->getMessage();
            }
        }
    }
}


/* ============================================================
   DEFAULT DENTISTS
   ============================================================ */

/*
 * These are only added if they do not already exist.
 */

$defaultDentists = [

    [
        'Dr. Juan Dela Cruz',
        'General Dentistry'
    ],

    [
        'Dr. Renz',
        'General Dentistry'
    ]
];


foreach ($defaultDentists as $defaultDentist) {

    try {

        $stmt = $pdo->prepare("
            SELECT id
            FROM dentists
            WHERE dentist_name = ?
            LIMIT 1
        ");

        $stmt->execute([
            $defaultDentist[0]
        ]);


        if (!$stmt->fetch()) {

            $stmt = $pdo->prepare("
                INSERT INTO dentists
                (
                    dentist_name,
                    specialization,
                    status
                )
                VALUES
                (
                    ?,
                    ?,
                    'Active'
                )
            ");

            $stmt->execute([
                $defaultDentist[0],
                $defaultDentist[1]
            ]);
        }

    } catch (PDOException $e) {

        /*
         * Do not stop the page if default dentist
         * already exists or cannot be inserted.
         */
    }
}


/* ============================================================
   GET ACTIVE DENTISTS
   ============================================================ */

$dentists = [];

try {

    try {
        $stmt = $pdo->query("
            SELECT
                id,
                dentist_name,
                specialization,
                rest_day
            FROM dentists
            WHERE status = 'Active'
            ORDER BY dentist_name ASC
        ");

        $dentists =
            $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $stmt = $pdo->query("
            SELECT
                id,
                dentist_name,
                specialization
            FROM dentists
            WHERE status = 'Active'
            ORDER BY dentist_name ASC
        ");

        $dentists =
            $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {

    $dentists = [];
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
        Book Appointment - Abalos Dental
    </title>


    <!-- BOOTSTRAP -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- BOOTSTRAP ICONS -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <!-- GOOGLE FONTS -->

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
        rel="stylesheet"
    >


    <!-- EXISTING STYLE -->

    <link
        rel="stylesheet"
        href="../assets/style.css"
    >

    <link
        rel="stylesheet"
        href="patient.css?v=8"
    >


    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }


        body {
            font-family: "DM Sans", sans-serif;
            background: #0A3B34;
            color: #173b4d;
        }


        .appointment-wrapper {
            min-height: 100vh;
            padding: 40px 20px;
        }


        .appointment-container {
            max-width: 900px;
            margin: 0 auto;
        }


        .appointment-card {
            background: #fff;
            border: 1px solid #e0ecef;
            border-radius: 18px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(22, 83, 101, .06);
        }


        .page-header {
            margin-bottom: 25px;
        }


        .page-header h2 {
            color: #153f50;
            font-family: "Plus Jakarta Sans", sans-serif;
            font-size: 23px;
            font-weight: 800;
            margin-bottom: 6px;
        }


        .page-header p {
            color: #8499a1;
            font-size: 11px;
            margin: 0;
        }


        .patient-info {
            background: #f7fcfd;
            border: 1px solid #e1edef;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 22px;
        }


        .patient-label {
            color: #8996a9;
            font-size: 10px;
            margin-bottom: 3px;
        }


        .patient-name {
            color: #244f60;
            font-size: 14px;
            font-weight: 700;
        }


        .patient-email {
            color: #8996a9;
            font-size: 10px;
            margin-top: 2px;
        }


        .form-label {
            font-size: 11px;
            font-weight: 700;
            color: #4d6873;
            margin-bottom: 7px;
        }


        .form-control,
        .form-select {
            font-size: 12px;
            border-radius: 9px;
            padding: 10px 12px;
            border: 1px solid #dce8eb;
        }


        .form-control:focus,
        .form-select:focus {
            border-color: #0E6B5E;
            box-shadow: 0 0 0 3px rgba(11, 159, 189, .10);
        }


        textarea.form-control {
            resize: vertical;
        }


        .submit-btn {
            background: #0E6B5E;
            border: none;
            color: #fff;
            border-radius: 9px;
            padding: 11px 20px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }


        .submit-btn:hover {
            background: #087f98;
            color: #fff;
        }


        .back-btn {
            color: #57727d;
            border: 1px solid #dce8eb;
            background: #fff;
            border-radius: 9px;
            padding: 10px 17px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
        }


        .back-btn:hover {
            background: #f5fafb;
            color: #0E6B5E;
        }


        /* =====================================================
           MULTI SERVICE SELECT
           ===================================================== */

        .multi-service-select {
            position: relative;
            width: 100%;
        }


        .multi-service-toggle {
            width: 100%;
            min-height: 42px;
            background-color: #fff;
            border: 1px solid #dce8eb;
            padding-right: 38px;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            color: #4d6873;
        }


        .multi-service-toggle:focus {
            border-color: #0E6B5E;
            box-shadow: 0 0 0 .25rem rgba(11, 159, 189, .10);
        }


        .multi-service-menu {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            z-index: 1080;
            max-height: 250px;
            overflow-y: auto;
            padding: 6px;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .12);
        }


        .multi-service-select.open .multi-service-menu {
            display: block;
        }


        .multi-service-option {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            width: 100%;
            margin: 0;
            padding: 9px 10px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            line-height: 1.35;
            color: #4d6873;
            font-weight: 500;
        }


        .multi-service-option:hover {
            background: #f4f9fb;
        }


        .multi-service-option input {
            flex: 0 0 auto;
            margin-top: 2px;
            width: 16px;
            height: 16px;
            cursor: pointer;
        }


        .multi-service-option span {
            flex: 1 1 auto;
        }


        .multi-service-placeholder,
        .multi-service-count {
            pointer-events: none;
        }


        .multi-service-count {
            overflow: hidden;
            text-overflow: ellipsis;
        }


        .multi-service-count.has-selection {
            color: #4d6873;
        }


        .appointment-duration-text {
            color: #8499a1;
            font-size: 10px;
            margin-top: 5px;
        }


        .alert {
            font-size: 11px;
            border-radius: 10px;
        }


        .success-box {
            border-left: 4px solid #198754;
        }


        .error-box {
            border-left: 4px solid #dc3545;
        }


        @media (max-width: 767px) {

            .appointment-wrapper {
                padding: 20px 12px;
            }

            .appointment-card {
                padding: 20px;
            }

            .page-header h2 {
                font-size: 20px;
            }

            .d-flex.justify-content-between {
                gap: 12px;
            }

        }

    </style>

</head>


<body>

<div class="patient-layout">

<?php require_once __DIR__ . '/patient_sidebar.php'; ?>

<main class="patient-main">

<div class="patient-content">

<div class="appointment-wrapper" style="min-height:auto; padding:0;">

    <div class="appointment-container">

        <div class="appointment-card">


            <!-- ==================================================
                 HEADER
                 ================================================== -->

            <div class="page-header">

                <h2>

                    <i class="bi bi-calendar-plus"></i>

                    Book Appointment

                </h2>

                <p>
                    Fill out the appointment information below.
                </p>

            </div>


            <!-- ==================================================
                 SUCCESS MESSAGE
                 ================================================== -->

            <?php if ($successMessage !== ''): ?>

                <div class="alert alert-success success-box">

                    <i class="bi bi-check-circle-fill me-1"></i>

                    <strong>Appointment Submitted!</strong>

                    <br>

                    <?= patient_booking_e($successMessage) ?>

                    <div class="mt-3">

                        <a
                            href="my_appointments.php"
                            class="btn btn-success btn-sm"
                        >
                            <i class="bi bi-clipboard2-check"></i>
                            View My Appointments
                        </a>

                        <a
                            href="patient_dashboard.php"
                            class="btn btn-outline-success btn-sm"
                        >
                            <i class="bi bi-house"></i>
                            Dashboard
                        </a>

                    </div>

                </div>

            <?php endif; ?>


            <!-- ==================================================
                 ERROR MESSAGE
                 ================================================== -->

            <?php if ($errorMessage !== ''): ?>

                <div class="alert alert-danger error-box">

                    <i class="bi bi-exclamation-circle-fill me-1"></i>

                    <?= patient_booking_e($errorMessage) ?>

                </div>

            <?php endif; ?>


            <!-- ==================================================
                 PATIENT INFORMATION
                 ================================================== -->

            <div class="patient-info">

                <div class="patient-label">
                    Patient
                </div>

                <div class="patient-name">
                    <?= patient_booking_e($fullName) ?>
                </div>

                <?php if ($userEmail !== ''): ?>

                    <div class="patient-email">
                        <?= patient_booking_e($userEmail) ?>
                    </div>

                <?php endif; ?>

            </div>


            <!-- ==================================================
                 BOOKING FORM
                 ================================================== -->

            <!--
                IMPORTANT:
                action="" means the form submits to this SAME file.
                This fixes the previous:
                book_appointments.php Not Found error.
            -->

            <form
                method="POST"
                action=""
                id="appointmentForm"
                autocomplete="off"
            >
<?= csrfField() ?>

                <input
                    type="hidden"
                    name="action"
                    value="book_appointment"
                >


                <!-- =================================================
                     PATIENT NAME
                     ================================================= -->

                <div class="mb-3">

                    <label class="form-label">
                        Patient Name *
                    </label>

                    <input
                        type="text"
                        name="patient_name"
                        class="form-control"
                        value="<?= patient_booking_e($fullName) ?>"
                        placeholder="Patient name"
                        readonly
                        required
                    >

                </div>


                <div class="row g-3">


                    <!-- =================================================
                         DENTIST
                         ================================================= -->

                    <div class="col-md-6">

                        <label class="form-label">
                            Dentist *
                        </label>

                        <select
                            name="dentist_id"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Select Dentist
                            </option>


                            <?php foreach ($dentists as $d): ?>

                                <option
                                    value="<?= (int)$d['id'] ?>"
                                    <?= (
                                        $selectedDentistId ==
                                        (int)$d['id']
                                    )
                                        ? 'selected'
                                        : ''
                                    ?>
                                >

                                    <?= patient_booking_e(
                                        $d['dentist_name']
                                    ) ?>

                                    <?php if (
                                        !empty(
                                            $d['specialization']
                                        )
                                    ): ?>

                                        -
                                        <?= patient_booking_e(
                                            $d['specialization']
                                        ) ?>

                                    <?php endif; ?>

                                    <?php
                                    if (array_key_exists('rest_day', $d) && $d['rest_day'] !== null && $d['rest_day'] !== ''):
                                        $bookDayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                                        $bookRest = $bookDayNames[(int)$d['rest_day']] ?? '';
                                        if ($bookRest !== ''):
                                    ?>
                                        (Rest day: <?= patient_booking_e($bookRest) ?>)
                                    <?php
                                        endif;
                                    endif;
                                    ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- =================================================
                         DATE
                         ================================================= -->

                    <div class="col-md-6">

                        <label class="form-label">
                            Appointment Date *
                        </label>

                        <input
                            type="date"
                            name="appointment_date"
                            class="form-control"
                            min="<?= date('Y-m-d') ?>"
                            value="<?= patient_booking_e($selectedDate) ?>"
                            required
                        >

                    </div>


                    <!-- =================================================
                         START TIME
                         ================================================= -->

                    <div class="col-md-6">

                        <label class="form-label">
                            Appointment Time * <small class="text-muted fw-normal">(8:00 AM – 6:00 PM | Lunch: 12–1 PM)</small>
                        </label>

                        <input
                            type="time"
                            name="start_time"
                            class="form-control"
                            min="08:00"
                            max="17:00"
                            value="<?= patient_booking_e($selectedStartTime) ?>"
                            readonly
                            required
                            style="background-color:#f8fcf9;cursor:pointer;"
                            title="Select an available time slot below"
                        >
                        <div class="form-text">Pick from the available time slots below.</div>

                    </div>


                    <!-- =================================================
                         END TIME
                         ================================================= -->

                    <div class="col-md-6">

                        <label class="form-label">
                            End Time
                        </label>

                        <input
                            type="time"
                            name="end_time"
                            class="form-control appointment-end-time"
                            value="<?= patient_booking_e($selectedEndTime) ?>"
                            readonly
                            required
                        >

                        <div class="form-text appointment-duration-text">
                            Select a service to calculate the end time.
                        </div>

                    </div>

                    <!-- =================================================
                         INTERACTIVE AVAILABLE TIME SLOTS PICKER
                         ================================================= -->
                    <div class="col-md-12">
                        <div id="availableSlotsPanel" style="background:#f4fbf7;border:1px solid #c9e9da;border-radius:12px;padding:14px 16px;">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <div class="fw-bold" style="color:#0E6B5E;font-size:13.5px;">
                                    <i class="bi bi-clock-history me-1"></i>Available Time Slots
                                </div>
                                <span class="badge" style="background:#fff3d9;color:#b45309;border:1px solid #f5d48a;font-size:11.5px;">
                                    <i class="bi bi-cup-hot me-1"></i>Clinic Lunch Break: 12:00 PM – 1:00 PM
                                </span>
                            </div>
                            <div id="availableSlotsStatus" class="small text-muted mb-2">
                                Select a dentist, date, and dental service to view available 1-hour slots.
                            </div>
                            <div id="availableSlotsGrid" class="d-flex flex-wrap gap-2"></div>
                        </div>
                    </div>


                    <!-- =================================================
                         DENTAL SERVICES
                         ================================================= -->

                    <div class="col-md-12">

                        <label class="form-label">
                            Dental Service *
                        </label>


                        <div class="multi-service-select">


                            <button
                                type="button"
                                class="form-select text-start multi-service-toggle"
                                aria-expanded="false"
                            >

                                <span class="multi-service-placeholder">
                                    Select one or more services
                                </span>

                                <span class="multi-service-count d-none"></span>

                            </button>


                            <div class="multi-service-menu">


                                <!-- GENERAL DENTISTRY -->

                                <label class="multi-service-option">

                                    <input
                                        type="checkbox"
                                        name="service_type[]"
                                        value="General Dentistry"
                                        <?= in_array(
                                            'General Dentistry',
                                            $selectedServices,
                                            true
                                        )
                                            ? 'checked'
                                            : ''
                                        ?>
                                    >

                                    <span>
                                        General Dentistry
                                        (20–60 minutes)
                                    </span>

                                </label>


                                <!-- ORTHODONTICS -->

                                <label class="multi-service-option">

                                    <input
                                        type="checkbox"
                                        name="service_type[]"
                                        value="Orthodontics (Braces)"
                                        <?= in_array(
                                            'Orthodontics (Braces)',
                                            $selectedServices,
                                            true
                                        )
                                            ? 'checked'
                                            : ''
                                        ?>
                                    >

                                    <span>
                                        Orthodontics (Braces)
                                        (30–60 minutes)
                                    </span>

                                </label>


                                <!-- ORAL SURGERY -->

                                <label class="multi-service-option">

                                    <input
                                        type="checkbox"
                                        name="service_type[]"
                                        value="Oral Surgery (Tooth Extraction or Bunot)"
                                        <?= in_array(
                                            'Oral Surgery (Tooth Extraction or Bunot)',
                                            $selectedServices,
                                            true
                                        )
                                            ? 'checked'
                                            : ''
                                        ?>
                                    >

                                    <span>
                                        Oral Surgery
                                        (Tooth Extraction or Bunot)
                                        (30–90 minutes)
                                    </span>

                                </label>


                                <!-- COSMETIC -->

                                <label class="multi-service-option">

                                    <input
                                        type="checkbox"
                                        name="service_type[]"
                                        value="Cosmetic"
                                        <?= in_array(
                                            'Cosmetic',
                                            $selectedServices,
                                            true
                                        )
                                            ? 'checked'
                                            : ''
                                        ?>
                                    >

                                    <span>
                                        Cosmetic
                                        (30–120 minutes)
                                    </span>

                                </label>


                                <!-- FILLINGS -->

                                <label class="multi-service-option">

                                    <input
                                        type="checkbox"
                                        name="service_type[]"
                                        value="Fillings or Pasta"
                                        <?= in_array(
                                            'Fillings or Pasta',
                                            $selectedServices,
                                            true
                                        )
                                            ? 'checked'
                                            : ''
                                        ?>
                                    >

                                    <span>
                                        Fillings or Pasta
                                        (30–60 minutes)
                                    </span>

                                </label>


                                <!-- ROOT CANAL -->

                                <label class="multi-service-option">

                                    <input
                                        type="checkbox"
                                        name="service_type[]"
                                        value="Root Canal"
                                        <?= in_array(
                                            'Root Canal',
                                            $selectedServices,
                                            true
                                        )
                                            ? 'checked'
                                            : ''
                                        ?>
                                    >

                                    <span>
                                        Root Canal
                                        (60–120 minutes)
                                    </span>

                                </label>


                                <!-- PROPHYLAXIS -->

                                <label class="multi-service-option">

                                    <input
                                        type="checkbox"
                                        name="service_type[]"
                                        value="Oral Prophylaxis or Cleaning"
                                        <?= in_array(
                                            'Oral Prophylaxis or Cleaning',
                                            $selectedServices,
                                            true
                                        )
                                            ? 'checked'
                                            : ''
                                        ?>
                                    >

                                    <span>
                                        Oral Prophylaxis or Cleaning
                                        (30–60 minutes)
                                    </span>

                                </label>


                                <!-- DENTURE -->

                                <label class="multi-service-option">

                                    <input
                                        type="checkbox"
                                        name="service_type[]"
                                        value="Denture"
                                        <?= in_array(
                                            'Denture',
                                            $selectedServices,
                                            true
                                        )
                                            ? 'checked'
                                            : ''
                                        ?>
                                    >

                                    <span>
                                        Denture
                                        (30–60 minutes per visit)
                                    </span>

                                </label>


                                <!-- TEETH WHITENING -->

                                <label class="multi-service-option">

                                    <input
                                        type="checkbox"
                                        name="service_type[]"
                                        value="Teeth Whitening"
                                        <?= in_array(
                                            'Teeth Whitening',
                                            $selectedServices,
                                            true
                                        )
                                            ? 'checked'
                                            : ''
                                        ?>
                                    >

                                    <span>
                                        Teeth Whitening
                                        (60–90 minutes)
                                    </span>

                                </label>


                                <!-- CROWN -->

                                <label class="multi-service-option">

                                    <input
                                        type="checkbox"
                                        name="service_type[]"
                                        value="Crown"
                                        <?= in_array(
                                            'Crown',
                                            $selectedServices,
                                            true
                                        )
                                            ? 'checked'
                                            : ''
                                        ?>
                                    >

                                    <span>
                                        Crown
                                        (60–90 minutes per visit)
                                    </span>

                                </label>


                                <!-- BRIDGE -->

                                <label class="multi-service-option">

                                    <input
                                        type="checkbox"
                                        name="service_type[]"
                                        value="Bridge"
                                        <?= in_array(
                                            'Bridge',
                                            $selectedServices,
                                            true
                                        )
                                            ? 'checked'
                                            : ''
                                        ?>
                                    >

                                    <span>
                                        Bridge
                                        (60–90 minutes per visit)
                                    </span>

                                </label>


                                <!-- VENEERS -->

                                <label class="multi-service-option">

                                    <input
                                        type="checkbox"
                                        name="service_type[]"
                                        value="Veneers"
                                        <?= in_array(
                                            'Veneers',
                                            $selectedServices,
                                            true
                                        )
                                            ? 'checked'
                                            : ''
                                        ?>
                                    >

                                    <span>
                                        Veneers
                                        (60–120 minutes per visit)
                                    </span>

                                </label>


                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         NOTES
                         ================================================= -->

                    <div class="col-md-12">

                        <label class="form-label">
                            Notes / Reason for Visit
                        </label>

                        <textarea
                            name="notes"
                            class="form-control"
                            rows="4"
                            placeholder="Enter any additional information..."
                        ><?= patient_booking_e($notesValue) ?></textarea>

                    </div>


                </div>


                <!-- ==================================================
                     BUTTONS
                     ================================================== -->

                <div class="d-flex justify-content-between align-items-center mt-4">


                    <a
                        href="patient_dashboard.php"
                        class="back-btn"
                    >

                        <i class="bi bi-arrow-left"></i>

                        Back to Dashboard

                    </a>


                    <button
                        type="submit"
                        class="submit-btn"
                        id="submitAppointmentBtn"
                    >

                        <i class="bi bi-calendar-check"></i>

                        Submit Appointment

                    </button>


                </div>


            </form>

        </div>

    </div>

</div>


<script>

/* ============================================================
   MULTIPLE DENTAL SERVICE SELECTOR
   ============================================================ */

document.addEventListener(
    'DOMContentLoaded',
    function () {


        const container =
            document.querySelector(
                '.multi-service-select'
            );


        if (!container) {
            return;
        }


        const toggle =
            container.querySelector(
                '.multi-service-toggle'
            );


        const placeholder =
            container.querySelector(
                '.multi-service-placeholder'
            );


        const countText =
            container.querySelector(
                '.multi-service-count'
            );


        const boxes =
            container.querySelectorAll(
                'input[name="service_type[]"]'
            );


        const form =
            document.querySelector(
                '#appointmentForm'
            );


        const startTimeInput =
            form.querySelector(
                'input[name="start_time"]'
            );


        const endTimeInput =
            form.querySelector(
                '.appointment-end-time'
            );


        const durationText =
            form.querySelector(
                '.appointment-duration-text'
            );


        const appointmentDateInput =
            form.querySelector(
                'input[name="appointment_date"]'
            );


        const submitButton =
            document.querySelector(
                '#submitAppointmentBtn'
            );


        const serviceDurations = {

            'General Dentistry': 60,

            'Orthodontics (Braces)': 60,

            'Oral Surgery (Tooth Extraction or Bunot)': 90,

            'Cosmetic': 120,

            'Fillings or Pasta': 60,

            'Root Canal': 120,

            'Oral Prophylaxis or Cleaning': 60,

            'Denture': 60,

            'Teeth Whitening': 90,

            'Crown': 90,

            'Bridge': 90,

            'Veneers': 120,

            'Dental Check-up': 30
        };


        /* ========================================================
           CALCULATE END TIME
           ======================================================== */

        function calculateEndTime(
            totalDuration
        ) {

            if (
                !startTimeInput ||
                !startTimeInput.value
            ) {
                return '';
            }


            const parts =
                startTimeInput.value.split(':');


            if (parts.length !== 2) {
                return '';
            }


            const hours =
                parseInt(
                    parts[0],
                    10
                );


            const minutes =
                parseInt(
                    parts[1],
                    10
                );


            if (
                isNaN(hours) ||
                isNaN(minutes)
            ) {
                return '';
            }


            const totalMinutes =
                (hours * 60) +
                minutes +
                totalDuration;


            const endHours =
                Math.floor(
                    totalMinutes / 60
                );


            const endMinutes =
                totalMinutes % 60;


            if (endHours >= 24) {
                return '';
            }


            return (
                String(endHours)
                    .padStart(2, '0')
                +
                ':' +
                String(endMinutes)
                    .padStart(2, '0')
            );
        }


        /* ========================================================
           UPDATE DISPLAY
           ======================================================== */

        function updateDisplay() {

            const selected =
                Array
                    .from(boxes)
                    .filter(
                        function (box) {
                            return box.checked;
                        }
                    );


            /* No service selected */

            if (selected.length === 0) {

                placeholder.classList.remove(
                    'd-none'
                );

                countText.classList.add(
                    'd-none'
                );

                countText.textContent = '';


                if (endTimeInput) {
                    endTimeInput.value = '';
                }


                if (durationText) {

                    durationText.textContent =
                        'Select a service to calculate the end time.';
                }


                return;
            }


            placeholder.classList.add(
                'd-none'
            );


            countText.classList.remove(
                'd-none'
            );


            countText.classList.add(
                'has-selection'
            );


            const names =
                selected.map(
                    function (box) {
                        return box.value;
                    }
                );


            const totalDuration =
                names.reduce(
                    function (
                        total,
                        name
                    ) {

                        return (
                            total +
                            (
                                serviceDurations[name]
                                || 30
                            )
                        );

                    },
                    0
                );


            /* Calculate end time */

            if (
                endTimeInput &&
                startTimeInput &&
                startTimeInput.value
            ) {

                const endTime =
                    calculateEndTime(
                        totalDuration
                    );


                endTimeInput.value =
                    endTime;
            }


            /* Duration text */

            if (durationText) {

                durationText.textContent =
                    'Estimated duration: ' +
                    totalDuration +
                    ' minutes.';
            }


            /* Selected service text */

            if (names.length === 1) {

                countText.textContent =
                    names[0];

            } else {

                countText.textContent =
                    names.length +
                    ' services selected';
            }
        }


        /* ========================================================
           OPEN / CLOSE SERVICE MENU
           ======================================================== */

        toggle.addEventListener(
            'click',
            function (event) {

                event.stopPropagation();


                const isOpen =
                    container.classList.contains(
                        'open'
                    );


                document
                    .querySelectorAll(
                        '.multi-service-select.open'
                    )
                    .forEach(
                        function (openContainer) {

                            openContainer
                                .classList
                                .remove('open');


                            const openToggle =
                                openContainer.querySelector(
                                    '.multi-service-toggle'
                                );


                            if (openToggle) {

                                openToggle.setAttribute(
                                    'aria-expanded',
                                    'false'
                                );
                            }
                        }
                    );


                if (!isOpen) {

                    container
                        .classList
                        .add('open');


                    toggle.setAttribute(
                        'aria-expanded',
                        'true'
                    );
                }
            }
        );


        /* ========================================================
           SERVICE CHECKBOX CHANGE
           ======================================================== */

        const dentistSelect =
            form.querySelector(
                'select[name="dentist_id"]'
            );

        const slotsStatus =
            document.getElementById(
                'availableSlotsStatus'
            );

        const slotsGrid =
            document.getElementById(
                'availableSlotsGrid'
            );

        function fetchAvailableSlots() {
            if (!dentistSelect || !appointmentDateInput || !slotsGrid || !slotsStatus) {
                return;
            }

            const dId = dentistSelect.value;
            const dDate = appointmentDateInput.value;
            const selectedBoxes = Array.from(boxes).filter(function (b) { return b.checked; });

            if (!dId || !dDate || selectedBoxes.length === 0) {
                slotsStatus.textContent = 'Select a dentist, date, and at least one dental service to view available 1-hour slots.';
                slotsGrid.innerHTML = '';
                return;
            }

            const firstService = selectedBoxes[0].value;
            slotsStatus.textContent = 'Checking available slots...';
            slotsGrid.innerHTML = '';

            const url = 'get_available_slots.php?dentist_id=' + encodeURIComponent(dId)
                + '&date=' + encodeURIComponent(dDate)
                + '&service=' + encodeURIComponent(firstService);

            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.success) {
                        slotsStatus.textContent = (data && data.message) ? data.message : 'Unable to load available slots.';
                        return;
                    }
                    slotsStatus.textContent = data.message || '';
                    const slots = Array.isArray(data.slots) ? data.slots : [];
                    if (slots.length === 0) {
                        return;
                    }
                    slots.forEach(function (slot) {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        const isCurrent = startTimeInput && startTimeInput.value && startTimeInput.value.slice(0, 5) === slot.start;
                        btn.className = 'btn btn-sm ' + (isCurrent ? 'btn-success' : 'btn-outline-success');
                        btn.style.borderRadius = '8px';
                        btn.style.fontWeight = '600';
                        btn.textContent = slot.start_display + ' - ' + slot.end_display;
                        btn.addEventListener('click', function () {
                            if (startTimeInput) {
                                startTimeInput.value = slot.start;
                                updateDisplay();
                            }
                            Array.from(slotsGrid.querySelectorAll('button')).forEach(function (b) {
                                b.className = 'btn btn-sm btn-outline-success';
                            });
                            btn.className = 'btn btn-sm btn-success';
                        });
                        slotsGrid.appendChild(btn);
                    });
                })
                .catch(function () {
                    slotsStatus.textContent = 'Unable to load available slots right now.';
                });
        }

        boxes.forEach(
            function (box) {

                box.addEventListener(
                    'change',
                    function () {
                        updateDisplay();
                        fetchAvailableSlots();
                    }
                );
            }
        );


        /* ========================================================
           START TIME CHANGE
           ======================================================== */

        if (startTimeInput) {

            startTimeInput.addEventListener(
                'input',
                updateDisplay
            );


            startTimeInput.addEventListener(
                'change',
                updateDisplay
            );
        }


        /* ========================================================
           DATE & DENTIST CHANGE
           ======================================================== */

        if (appointmentDateInput) {

            appointmentDateInput.addEventListener(
                'change',
                fetchAvailableSlots
            );
        }

        if (dentistSelect) {

            dentistSelect.addEventListener(
                'change',
                fetchAvailableSlots
            );
        }


        /* ========================================================
           PREVENT MENU FROM CLOSING
           ======================================================== */

        const menu =
            container.querySelector(
                '.multi-service-menu'
            );


        if (menu) {

            menu.addEventListener(
                'click',
                function (event) {
                    event.stopPropagation();
                }
            );
        }


        /* ========================================================
           CLICK OUTSIDE
           ======================================================== */

        document.addEventListener(
            'click',
            function () {

                document
                    .querySelectorAll(
                        '.multi-service-select.open'
                    )
                    .forEach(
                        function (openContainer) {

                            openContainer
                                .classList
                                .remove('open');


                            const openToggle =
                                openContainer.querySelector(
                                    '.multi-service-toggle'
                                );


                            if (openToggle) {

                                openToggle.setAttribute(
                                    'aria-expanded',
                                    'false'
                                );
                            }
                        }
                    );
            }
        );


        /* ========================================================
           FORM VALIDATION
           ======================================================== */

        if (form) {

            form.addEventListener(
                'submit',
                function (event) {

                    const hasSelectedService =
                        Array
                            .from(boxes)
                            .some(
                                function (box) {
                                    return box.checked;
                                }
                            );


                    if (!hasSelectedService) {

                        event.preventDefault();


                        alert(
                            'Please select at least one dental service.'
                        );


                        toggle.click();


                        toggle.focus();


                        return;
                    }


                    /*
                     * Prevent double-clicking the button.
                     */

                    if (submitButton) {

                        submitButton.disabled =
                            true;


                        submitButton.innerHTML =
                            '<i class="bi bi-hourglass-split"></i> Submitting...';
                    }
                }
            );
        }


        /* ========================================================
           INITIAL DISPLAY
           ======================================================== */

        updateDisplay();
        fetchAvailableSlots();

    }
);

</script>


</div>
</div>
</main>
</div>

</body>

</html>