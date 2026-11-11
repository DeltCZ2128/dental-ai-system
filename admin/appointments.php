<?php

require_once __DIR__ . "/../functions.php";

requireLogin();


/*
|--------------------------------------------------------------------------
| CROSS-SITE REQUEST FORGERY CHECK
|--------------------------------------------------------------------------
| Every request that changes data is compared with the token held in the
| session before any handler of this page runs. A submission that carries
| no token, or a token that does not match, is refused.
*/
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrf();
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    $action = $_POST['action'] ?? '';

    $allowedStatuses = [
        'Pending',
        'Confirmed',
        'Completed',
        'Cancelled',
        'Checked In',
        'Late',
        'Serving'
    ];

    if ($action === 'add') {

        $patientId = (int)$_POST['patient_id'];
        $dentistId = (int)$_POST['dentist_id'];
        $date = trim($_POST['appointment_date'] ?? '');
        $start = trim($_POST['start_time'] ?? '');
        $end = trim($_POST['end_time'] ?? '');

        if ($date === '' || $start === '' || $end === '' || $end <= $start) {
            flash("Please provide a valid appointment date and time range.", "danger");
            redirect("appointments.php");
        }

        if ($date < date('Y-m-d')) {
            flash("Cannot schedule an appointment on a past date.", "danger");
            redirect("appointments.php");
        }

        if (substr($start, 0, 5) < '08:00' || substr($end, 0, 5) > '18:00') {
            flash("Appointments must be scheduled within clinic operating hours (8:00 AM – 6:00 PM).", "danger");
            redirect("appointments.php");
        }

        if ($date === date('Y-m-d') && substr($start, 0, 5) <= date('H:i')) {
            flash("Cannot schedule an appointment for a time slot that has already passed today.", "danger");
            redirect("appointments.php");
        }

        $conflict = appointmentConflict(
            $dentistId,
            $date,
            $start,
            $end
        );

        if ($conflict) {

            flash(
                "Scheduling conflict detected. The dentist already has an appointment during this time.",
                "danger"
            );

            redirect("appointments.php");
        }

        /* Per-dentist rest day + Sunday closure (admin cannot book on rest day). */
        $adminDayOfWeek = (int)date('w', strtotime($date));
        if ($adminDayOfWeek === 0) {
            flash("The clinic is closed on Sunday. Please choose another date.", "danger");
            redirect("appointments.php");
        }
        $adminRestDay = function_exists('getDentistRestDay') ? getDentistRestDay($dentistId) : null;
        if ($adminRestDay !== null && $adminDayOfWeek === (int)$adminRestDay) {
            $adminRestName = function_exists('dentistRestDayName') ? dentistRestDayName($adminRestDay) : date('l', strtotime($date));
            flash("This dentist is on rest day every " . $adminRestName . ". Please choose another dentist or another date.", "danger");
            redirect("appointments.php");
        }
        if (function_exists('isDentistDayOff') && isDentistDayOff($dentistId, $date)) {
            flash("This dentist is on rest day on " . date('M d, Y (l)', strtotime($date)) . ". Please choose another dentist or another date.", "danger");
            redirect("appointments.php");
        }

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
            VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?)
        ");

        $stmt->execute([
            $patientId,
            $dentistId,
            $date,
            $start,
            $end,
            $_POST['service_type'],
            $_POST['notes']
        ]);

        $appointmentId =
            $pdo->lastInsertId();

        createNotification(
            $patientId,
            $appointmentId,
            "New Appointment Request",
            "Your dental appointment has been scheduled for $date at $start.",
            "Confirmation"
        );

        flash("Appointment successfully created.");

        redirect("appointments.php#today");
    }

    if ($action === 'status') {

        $id = (int)$_POST['id'];

        $status = $_POST['status'];

        $postTabRaw = trim($_POST['tab'] ?? '');
        $postTab = in_array($postTabRaw, ['history', 'today', 'upcoming'], true) ? $postTabRaw : 'today';

        if (!in_array($status, $allowedStatuses, true)) {
            flash("Invalid appointment status.", "danger");
            redirect("appointments.php#" . $postTab);
        }

        $appointmentStmt = $pdo->prepare("
            SELECT patient_id, appointment_date, start_time, end_time, status
            FROM appointments
            WHERE id = ?
            LIMIT 1
        ");
        $appointmentStmt->execute([$id]);
        $appointment = $appointmentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$appointment) {
            flash("Appointment not found.", "danger");
            redirect("appointments.php#" . $postTab);
        }

        $apptDate = substr((string)$appointment['appointment_date'], 0, 10);
        $apptStart = substr((string)$appointment['start_time'], 0, 5);
        $apptEnd = substr((string)($appointment['end_time'] ?: $appointment['start_time']), 0, 5);
        $nowDate = date('Y-m-d');
        $nowTime = date('H:i');

        /* Block approving/confirming an appointment that is outside operating hours or already past/closing */
        if ($status === 'Confirmed') {
            /* Block if assigned doctor is absent, on leave, or on rest day */
            $dentistCheckStmt = $pdo->prepare("
                SELECT a.dentist_id, a.appointment_date, d.dentist_name, d.status as dentist_status, d.rest_day,
                       ddo.id as dayoff_id, ddo.reason as dayoff_reason
                FROM appointments a
                JOIN dentists d ON d.id = a.dentist_id
                LEFT JOIN dentist_day_offs ddo 
                    ON ddo.dentist_id = a.dentist_id 
                    AND ddo.off_date = a.appointment_date
                WHERE a.id = ?
                LIMIT 1
            ");
            $dentistCheckStmt->execute([$id]);
            $dCheck = $dentistCheckStmt->fetch(PDO::FETCH_ASSOC);

            if ($dCheck) {
                $isAbsent = !empty($dCheck['dayoff_id']);
                $isInactive = ($dCheck['dentist_status'] ?? 'Active') === 'Inactive';
                $isRestDay = false;
                if (isset($dCheck['rest_day']) && $dCheck['rest_day'] !== null && $dCheck['rest_day'] !== '' && !empty($dCheck['appointment_date'])) {
                    $aptW = (int) date('w', strtotime($dCheck['appointment_date']));
                    if ($aptW === (int) $dCheck['rest_day']) {
                        $isRestDay = true;
                    }
                }

                if ($isAbsent || $isInactive || $isRestDay) {
                    $cleanDocName = preg_replace('/^Dr\.?\s+/i', '', $dCheck['dentist_name']);
                    $reasonTxt = $isAbsent ? (!empty($dCheck['dayoff_reason']) ? " ({$dCheck['dayoff_reason']})" : " (Doctor is marked ABSENT)") : ($isRestDay ? " (Doctor Weekly Rest Day)" : " (Doctor Inactive)");
                    flash("Cannot confirm appointment: Assigned doctor Dr. {$cleanDocName} is unavailable on " . date('M d, Y', strtotime($dCheck['appointment_date'])) . "{$reasonTxt}. Please reassign this appointment to an available doctor first.", "danger");
                    redirect("appointments.php#" . $postTab);
                }
            }

            if ($apptDate < $nowDate) {
                flash("Cannot confirm an appointment on a past date. Please reschedule the appointment instead.", "danger");
                redirect("appointments.php#" . $postTab);
            }
            if ($apptStart < '08:00' || $apptEnd > '18:00') {
                flash("Cannot confirm an appointment outside clinic operating hours (8:00 AM – 6:00 PM). Please reschedule it to a valid clinic time.", "danger");
                redirect("appointments.php#" . $postTab);
            }
            if ($apptDate === $nowDate && ($nowTime >= '18:00' || $apptEnd <= $nowTime)) {
                flash("Cannot approve this appointment because the clinic is already closing or its scheduled time slot (" . date('h:i A', strtotime($apptStart)) . " – " . date('h:i A', strtotime($apptEnd)) . ") has already passed. Please reschedule the appointment instead.", "danger");
                redirect("appointments.php#" . $postTab);
            }
        }

        if (in_array($status, ['Checked In', 'Late', 'Serving', 'Completed'], true) && $apptDate > $nowDate) {
            flash("Future appointments cannot be marked as {$status} before their scheduled date.", "danger");
            redirect("appointments.php#" . $postTab);
        }

        $statusStampCols = [
            'Confirmed' => 'confirmed_at',
            'Checked In' => 'checked_in_at',
            'Late' => 'checked_in_at',
            'Serving' => 'serving_at',
            'Completed' => 'completed_at',
            'Cancelled' => 'cancelled_at'
        ];
        $statusStampSql = isset($statusStampCols[$status]) ? ", {$statusStampCols[$status]} = NOW()" : "";

        $stmt = $pdo->prepare("
            UPDATE appointments
            SET status = ?{$statusStampSql}
            WHERE id = ?
        ");

        $stmt->execute([
            $status,
            $id
        ]);

        if ($appointment && in_array($status, ['Serving', 'Completed'], true)) {
            try {
                $qSyncStmt = $pdo->prepare("
                    UPDATE queue
                    SET status = ?
                    WHERE patient_id = ?
                      AND queue_date = ?
                ");
                $qSyncStmt->execute([
                    $status,
                    (int)$appointment['patient_id'],
                    $apptDate
                ]);
            } catch (PDOException $e) {
            }
        }

        if ($appointment) {
            $notificationTitles = [
                'Confirmed' => 'Appointment Confirmed',
                'Cancelled' => 'Appointment Cancelled',
                'Completed' => 'Appointment Completed',
                'Pending' => 'New Appointment Request',
                'Checked In' => 'Patient Checked In',
                'Late' => 'Late Check-In',
                'Serving' => 'Now Serving'
            ];

            $notificationMessages = [
                'Confirmed' => 'Your appointment on ' . $appointment['appointment_date'] . ' has been confirmed.',
                'Cancelled' => 'Your appointment on ' . $appointment['appointment_date'] . ' has been cancelled.',
                'Completed' => 'Your appointment on ' . $appointment['appointment_date'] . ' has been completed.',
                'Pending' => 'Your appointment request is waiting for confirmation.',
                'Checked In' => 'You have been checked in for your appointment on ' . $appointment['appointment_date'] . '.',
                'Late' => 'You were marked late for your appointment on ' . $appointment['appointment_date'] . '.',
                'Serving' => 'You are now being served for your appointment on ' . $appointment['appointment_date'] . '.'
            ];

            createNotification(
                (int) $appointment['patient_id'],
                $id,
                $notificationTitles[$status],
                $notificationMessages[$status],
                'Appointment'
            );
        }

        flash("Appointment status updated to {$status}.");

        $targetAnchor = in_array($status, ['Completed', 'Cancelled'], true)
            ? '#history'
            : ($apptDate === date('Y-m-d') ? '#today' : '#upcoming');
        redirect("appointments.php" . $targetAnchor);
    }

    if ($action === 'checkin') {

        $checkTab = in_array(($_POST['tab'] ?? ''), ['today', 'upcoming'], true) ? '#' . $_POST['tab'] : '#today';

        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            flash("Invalid appointment.", "danger");
            redirect("appointments.php" . $checkTab);
        }

        $checkStmt = $pdo->prepare("
            SELECT patient_id, appointment_date, start_time, end_time, status, service_type
            FROM appointments
            WHERE id = ?
            LIMIT 1
        ");
        $checkStmt->execute([$id]);
        $checkRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$checkRow) {
            flash("Appointment not found.", "danger");
            redirect("appointments.php" . $checkTab);
        }

        $curStatus = strtolower(trim($checkRow['status'] ?? ''));

        if (!in_array($curStatus, ['pending', 'confirmed'], true)) {
            flash("Only pending or confirmed appointments can be checked in.", "danger");
            redirect("appointments.php" . $checkTab);
        }

        if ($checkRow['appointment_date'] !== date('Y-m-d')) {
            flash("Only appointments scheduled for today can be checked in.", "danger");
            redirect("appointments.php" . $checkTab);
        }

        $nowHm = date('H:i');
        $chkStartHm = substr((string)$checkRow['start_time'], 0, 5);
        $chkEndHm = substr((string)($checkRow['end_time'] ?: $checkRow['start_time']), 0, 5);
        if ($nowHm < '08:00' || $nowHm >= '18:00' || $chkStartHm < '08:00' || $chkEndHm > '18:00') {
            flash("Cannot check in patient outside clinic operating hours (8:00 AM – 6:00 PM). Please reschedule the appointment.", "danger");
            redirect("appointments.php" . $checkTab);
        }

        $startTs = strtotime($checkRow['appointment_date'] . ' ' . $checkRow['start_time']);
        $newStatus = (time() > $startTs + 3600) ? 'Late' : 'Checked In';

        $updStmt = $pdo->prepare("UPDATE appointments SET status = ?, checked_in_at = NOW() WHERE id = ?");
        $updStmt->execute([$newStatus, $id]);

        /* Idagdag sa queue table (admin + dentist queue pages) kapag wala pa ngayong araw. */
        try {
            $qDate = date('Y-m-d');
            $qExists = $pdo->prepare("SELECT id FROM queue WHERE patient_id = ? AND queue_date = ? LIMIT 1");
            $qExists->execute([(int) $checkRow['patient_id'], $qDate]);
            if (!$qExists->fetchColumn()) {
                $qNum = generateQueueNumber($qDate);
                $qService = trim((string) ($checkRow['service_type'] ?? ''));
                try {
                    $qIns = $pdo->prepare("INSERT INTO queue (patient_id, queue_number, queue_date, priority, status, service_type) VALUES (?, ?, ?, 'Normal', 'Waiting', ?)");
                    $qIns->execute([(int) $checkRow['patient_id'], $qNum, $qDate, $qService]);
                } catch (PDOException $qe) {
                    $qIns = $pdo->prepare("INSERT INTO queue (patient_id, queue_number, queue_date, priority, status) VALUES (?, ?, ?, 'Normal', 'Waiting')");
                    $qIns->execute([(int) $checkRow['patient_id'], $qNum, $qDate]);
                }
            }
        } catch (PDOException $e) {
            /* Queue insert failure must not block check-in. */
        }

        createNotification(
            (int) $checkRow['patient_id'],
            $id,
            $newStatus === 'Late' ? 'Late Check-In' : 'Patient Checked In',
            $newStatus === 'Late'
                ? 'Patient arrived late for the appointment on ' . $checkRow['appointment_date'] . '.'
                : 'Patient checked in for the appointment on ' . $checkRow['appointment_date'] . '.',
            'Appointment'
        );

        flash("Patient checked in ({$newStatus}).");
        redirect("appointments.php" . $checkTab);
    }

    if ($action === 'remind') {

        $id = (int)($_POST['id'] ?? 0);
        $remindTab = ($_POST['tab'] ?? '') === 'history' ? 'history' : 'today';

        if ($id <= 0) {
            flash("Invalid appointment.", "danger");
            redirect("appointments.php#" . $remindTab);
        }

        $reminderStmt = $pdo->prepare("
            SELECT
                a.id,
                a.patient_id,
                a.appointment_date,
                a.start_time,
                a.service_type,
                a.status,
                CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                p.email AS patient_email
            FROM appointments a
            LEFT JOIN patients p ON p.id = a.patient_id
            WHERE a.id = ?
            LIMIT 1
        ");

        $reminderStmt->execute([$id]);
        $reminderAppointment = $reminderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$reminderAppointment) {
            flash("Appointment not found.", "danger");
            redirect("appointments.php#" . $remindTab);
        }

        $patientEmail = trim((string)($reminderAppointment['patient_email'] ?? ''));

        if ($patientEmail === '') {
            flash("Patient has no Gmail on record.", "danger");
            redirect("appointments.php#" . $remindTab);
        }

        $patientName = trim((string)($reminderAppointment['patient_name'] ?? 'Patient'));
        $appointmentDate = (string)$reminderAppointment['appointment_date'];
        $appointmentTime = date('h:i A', strtotime($reminderAppointment['start_time']));
        $serviceType = (string)($reminderAppointment['service_type'] ?? 'Dental appointment');

        $subject = 'Appointment Reminder - Abalos Dental Clinic';

        $message =
            "Hello " . $patientName . ",\n\n" .
            "This is a reminder from Abalos Dental Clinic.\n\n" .
            "Your appointment details:\n" .
            "Date: " . $appointmentDate . "\n" .
            "Time: " . $appointmentTime . "\n" .
            "Service: " . $serviceType . "\n" .
            "Status: " . (string)$reminderAppointment['status'] . "\n\n" .
            "Please arrive on time. If you cannot attend, contact the clinic.\n\n" .
            "Thank you.";

        list($sent, $sendError) = sendClinicGmail($patientEmail, $subject, $message);

        if ($sent) {
            createNotification(
                (int)$reminderAppointment['patient_id'],
                $id,
                'Appointment Reminder Sent',
                'Reminder sent to ' . $patientEmail . ' for appointment on ' . $appointmentDate . ' at ' . $appointmentTime . '.',
                'Appointment'
            );

            flash("Reminder sent to patient Gmail.");
        } else {
            flash("Could not send reminder: " . $sendError, "danger");
        }

        redirect("appointments.php#" . $remindTab);
    }

    if ($action === 'reschedule') {

        $id = (int) ($_POST['id'] ?? 0);
        $reschedTab = ($_POST['tab'] ?? '') === 'history' ? 'history' : 'today';
        $reAnchor = in_array(($_POST['tab'] ?? ''), ['today', 'upcoming'], true) ? '#' . $_POST['tab'] : ($reschedTab === 'history' ? '#history' : '#today');
        $date = trim($_POST['appointment_date'] ?? '');
        $start = trim($_POST['start_time'] ?? '');
        $end = trim($_POST['end_time'] ?? '');

        $appointmentStmt = $pdo->prepare("
            SELECT dentist_id, patient_id, status
            FROM appointments
            WHERE id = ?
            LIMIT 1
        ");
        $appointmentStmt->execute([$id]);
        $appointment = $appointmentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$appointment || $date === '' || $start === '' || $end <= $start) {
            flash("Please provide a valid date and time.", "danger");
            redirect("appointments.php" . $reAnchor);
        }

        if ($date < date('Y-m-d')) {
            flash("Cannot reschedule an appointment to a past date.", "danger");
            redirect("appointments.php" . $reAnchor);
        }

        if (substr($start, 0, 5) < '08:00' || substr($end, 0, 5) > '18:00') {
            flash("Rescheduled time must be within clinic operating hours (8:00 AM – 6:00 PM).", "danger");
            redirect("appointments.php" . $reAnchor);
        }

        if ($date === date('Y-m-d') && substr($start, 0, 5) <= date('H:i')) {
            flash("Cannot reschedule to a time slot that has already passed today.", "danger");
            redirect("appointments.php" . $reAnchor);
        }

        $conflict = appointmentConflict(
            (int) $appointment['dentist_id'],
            $date,
            $start,
            $end,
            $id
        );

        if ($conflict) {
            flash("Scheduling conflict detected. Please choose another time.", "danger");
            redirect("appointments.php" . $reAnchor);
        }

        /* Per-dentist rest day + Sunday closure on reschedule. */
        $reDayOfWeek = (int)date('w', strtotime($date));
        if ($reDayOfWeek === 0) {
            flash("The clinic is closed on Sunday. Please choose another date.", "danger");
            redirect("appointments.php" . $reAnchor);
        }
        $reRestDay = function_exists('getDentistRestDay') ? getDentistRestDay((int)$appointment['dentist_id']) : null;
        if ($reRestDay !== null && $reDayOfWeek === (int)$reRestDay) {
            $reRestName = function_exists('dentistRestDayName') ? dentistRestDayName($reRestDay) : date('l', strtotime($date));
            flash("This dentist is on rest day every " . $reRestName . ". Please choose another date.", "danger");
            redirect("appointments.php" . $reAnchor);
        }
        if (function_exists('isDentistDayOff') && isDentistDayOff((int)$appointment['dentist_id'], $date)) {
            flash("This dentist is on rest day on " . date('M d, Y (l)', strtotime($date)) . ". Please choose another date.", "danger");
            redirect("appointments.php" . $reAnchor);
        }

        $stmt = $pdo->prepare("
            UPDATE appointments
            SET appointment_date = ?, start_time = ?, end_time = ?, rescheduled_at = NOW(), status = 'Pending', confirmed_at = NULL
            WHERE id = ?
        ");
        $stmt->execute([$date, $start, $end, $id]);

        createNotification(
            (int) $appointment['patient_id'],
            $id,
            'Appointment Rescheduled',
            'Your appointment has been moved to ' . $date . ' at ' . $start . '.',
            'Appointment'
        );

        flash("Appointment rescheduled successfully.");
        if ($date === date('Y-m-d')) {
            redirect("appointments.php?view=" . $id . "#today");
        } else {
            redirect("appointments.php?udate=" . urlencode($date) . "&view=" . $id . "#upcoming");
        }
    }

    if ($action === 'reassign_dentist') {
        $id = (int) ($_POST['id'] ?? 0);
        $newDentistId = (int) ($_POST['new_dentist_id'] ?? 0);
        $reassignReason = trim($_POST['reassign_reason'] ?? 'Dentist Emergency Leave / Absence');
        if ($reassignReason === '') {
            $reassignReason = 'Dentist Emergency Leave / Absence';
        }
        $tab = in_array(($_POST['tab'] ?? ''), ['today', 'upcoming'], true) ? '#' . $_POST['tab'] : '#today';

        $appointmentStmt = $pdo->prepare("
            SELECT a.*, p.first_name, p.last_name, p.user_id as patient_user_id, d.dentist_name as old_dentist_name
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            LEFT JOIN dentists d ON d.id = a.dentist_id
            WHERE a.id = ?
            LIMIT 1
        ");
        $appointmentStmt->execute([$id]);
        $appointment = $appointmentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$appointment) {
            flash("Appointment record not found.", "danger");
            redirect("appointments.php" . $tab);
        }

        if ($newDentistId <= 0 || $newDentistId === (int)$appointment['dentist_id']) {
            flash("Please choose a different attending dentist to reassign this appointment.", "danger");
            redirect("appointments.php" . $tab);
        }

        $newDStmt = $pdo->prepare("SELECT id, dentist_name, user_id FROM dentists WHERE id = ? AND status = 'Active' LIMIT 1");
        $newDStmt->execute([$newDentistId]);
        $newDentist = $newDStmt->fetch(PDO::FETCH_ASSOC);

        if (!$newDentist) {
            flash("The selected dentist practitioner is currently inactive or not found.", "danger");
            redirect("appointments.php" . $tab);
        }

        $aptDate = $appointment['appointment_date'];
        $aptDayOfWeek = (int)date('w', strtotime($aptDate));
        $cleanNewName = preg_replace('/^Dr\.?\s+/i', '', (string)($newDentist['dentist_name'] ?? ''));
        $cleanOldName = preg_replace('/^Dr\.?\s+/i', '', (string)($appointment['old_dentist_name'] ?? ''));
        $dispNewDoctor = 'Dr. ' . $cleanNewName;
        $dispOldDoctor = $cleanOldName !== '' ? 'Dr. ' . $cleanOldName : 'previous dentist';

        $newRestDay = function_exists('getDentistRestDay') ? getDentistRestDay($newDentistId) : null;
        if ($newRestDay !== null && $aptDayOfWeek === (int)$newRestDay) {
            $restName = function_exists('dentistRestDayName') ? dentistRestDayName($newRestDay) : date('l', strtotime($aptDate));
            flash("{$dispNewDoctor} is on rest day every {$restName}. Please select another dentist.", "danger");
            redirect("appointments.php" . $tab);
        }
        if (function_exists('isDentistDayOff') && isDentistDayOff($newDentistId, $aptDate)) {
            flash("{$dispNewDoctor} is on scheduled leave on " . date('M d, Y', strtotime($aptDate)) . ". Please select another dentist.", "danger");
            redirect("appointments.php" . $tab);
        }

        $conflict = appointmentConflict($newDentistId, $aptDate, $appointment['start_time'], $appointment['end_time'], $id);
        if ($conflict) {
            flash("Conflict detected: {$dispNewDoctor} already has an active appointment at this time slot.", "danger");
            redirect("appointments.php" . $tab);
        }

        $noteAppend = " [Reassigned to {$dispNewDoctor} by Admin. Reason: " . $reassignReason . "]";
        $updateStmt = $pdo->prepare("
            UPDATE appointments
            SET dentist_id = ?,
                notes = CONCAT(COALESCE(notes, ''), ?)
            WHERE id = ?
        ");
        $updateStmt->execute([$newDentistId, $noteAppend, $id]);

        $patientNotifId = !empty($appointment['patient_id']) ? (int)$appointment['patient_id'] : (int)($appointment['patient_user_id'] ?? 0);
        if ($patientNotifId > 0 && function_exists('createNotification')) {
            $notifMsg = "Your dental appointment on " . date('M d, Y', strtotime($aptDate)) . " at " . date('h:i A', strtotime($appointment['start_time'])) . " has been reassigned to {$dispNewDoctor} due to " . $reassignReason . ". Your scheduled time remains unchanged.";
            try {
                createNotification(
                    $patientNotifId,
                    (int) $id,
                    'Appointment Reassigned',
                    $notifMsg,
                    'Appointment'
                );
            } catch (Throwable $e) {}
        }

        if (!empty($newDentist['id'])) {
            $dentMsg = "Reassigned Patient: " . $appointment['first_name'] . " " . $appointment['last_name'] . " (" . $appointment['service_type'] . ") at " . date('h:i A', strtotime($appointment['start_time'])) . " on " . date('M d, Y', strtotime($aptDate)) . " (Transferred from {$dispOldDoctor}).";
            try {
                $pdo->prepare("INSERT INTO dentist_notifications (dentist_id, message, created_at) VALUES (?, ?, NOW())")->execute([$newDentist['id'], $dentMsg]);
            } catch (Throwable $e) {}
        }

        flash("Appointment successfully reassigned to {$dispNewDoctor}! Patient and dentist notifications sent.", "success");
        redirect("appointments.php" . $tab);
    }
}

$patients = $pdo->query("
    SELECT id, patient_number, first_name, last_name
    FROM patients
    ORDER BY last_name
")->fetchAll();

$dentists = $pdo->query("
    SELECT id, dentist_name, rest_day
    FROM dentists
    WHERE status = 'Active'
    ORDER BY dentist_name
")->fetchAll();

try {
    $allDentistDayOffs = $pdo->query("
        SELECT id, dentist_id, off_date, reason
        FROM dentist_day_offs
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $allDentistDayOffs = [];
}

/* Isang page, dalawang section: Pending sa taas, Appointment History sa ilalim (may pagination). */
$historyStatuses = ['Completed', 'No-Show', 'Cancelled'];

$search = trim($_GET['search'] ?? '');
$historyStatus = trim($_GET['hstatus'] ?? '');
if (!in_array($historyStatus, $historyStatuses, true)) {
    $historyStatus = '';
}
$todayStatuses = ['Pending', 'Confirmed', 'Checked In', 'Late', 'Serving'];
$todayStatus = trim($_GET['tstatus'] ?? '');
if (!in_array($todayStatus, $todayStatuses, true)) {
    $todayStatus = '';
}
/* Upcoming date filter: isang araw mula bukas pataas. */
$upcomingDateFilter = trim($_GET['udate'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $upcomingDateFilter) || $upcomingDateFilter < date('Y-m-d', strtotime('+1 day'))) {
    $upcomingDateFilter = '';
}
/* Upcoming status filter: ibalik katabi ng date filter (Pending/Confirmed/Approved lang ang laman ng Upcoming). */
$upcomingStatuses = ['Pending', 'Confirmed', 'Approved'];
$upcomingStatus = trim($_GET['ustatus'] ?? '');
if (!in_array($upcomingStatus, $upcomingStatuses, true)) {
    $upcomingStatus = '';
}
$dentistFilter = (int) ($_GET['dentist_id'] ?? 0);
$historyPage = max(1, (int) ($_GET['hpage'] ?? 1));
$historyPerPage = 10;

/* Preview flag: itinatago muna ang Pending Appointments section.
   Gawing true para ibalik ang Pending section. */
$showPendingSection = false;

/* Shared filters (search + dentist) para sa parehong section. */
$baseWhere = [];
$baseParams = [];

if ($search !== '') {
    $baseWhere[] = "(
        CONCAT(p.first_name, ' ', p.last_name) LIKE ?
        OR p.patient_number LIKE ?
        OR a.service_type LIKE ?
        OR d.dentist_name LIKE ?
    )";
    $searchLike = '%' . $search . '%';
    array_push($baseParams, $searchLike, $searchLike, $searchLike, $searchLike);
}

if ($dentistFilter > 0) {
    $baseWhere[] = 'a.dentist_id = ?';
    $baseParams[] = $dentistFilter;
}

$baseSql = empty($baseWhere) ? '' : ' AND ' . implode(' AND ', $baseWhere);

/* AUTO NO-SHOW & RESTORE: unified through markClosedAppointmentsAsNoShow() */
try {
    date_default_timezone_set('Asia/Manila');
    if (function_exists('markClosedAppointmentsAsNoShow')) {
        markClosedAppointmentsAsNoShow();
    }
} catch (Throwable $e) {
}

/* AUTO-ALIGN: queue Serving/Completed · appointment status (active lang). */
try {
    $alignStmt = $pdo->prepare("
        UPDATE appointments a
        INNER JOIN queue q
            ON q.patient_id = a.patient_id
            AND q.queue_date = a.appointment_date
        SET a.status = q.status,
            a.serving_at = CASE WHEN q.status = 'Serving' THEN NOW() ELSE a.serving_at END,
            a.completed_at = CASE WHEN q.status = 'Completed' THEN NOW() ELSE a.completed_at END
        WHERE q.status IN ('Serving', 'Completed')
        AND a.status IN ('Pending', 'Confirmed', 'Approved', 'Checked In')
    ");
    $alignStmt->execute();
} catch (PDOException $e) {
}

$listSelect = "
    SELECT
        a.*,
        CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
        p.patient_number,
        d.dentist_name,
        d.status AS dentist_status,
        d.rest_day,
        ddo.id AS dayoff_id,
        ddo.reason AS dayoff_reason
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    JOIN dentists d ON d.id = a.dentist_id
    LEFT JOIN dentist_day_offs ddo
        ON ddo.dentist_id = a.dentist_id
        AND ddo.off_date = a.appointment_date
";

if (!function_exists('isApptDentistAbsent')) {
    function isApptDentistAbsent($apptRow)
    {
        if (!empty($apptRow['dayoff_id'])) {
            return true;
        }
        if (($apptRow['dentist_status'] ?? 'Active') === 'Inactive') {
            return true;
        }
        if (isset($apptRow['rest_day']) && $apptRow['rest_day'] !== null && $apptRow['rest_day'] !== '' && !empty($apptRow['appointment_date'])) {
            $aptW = (int) date('w', strtotime($apptRow['appointment_date']));
            if ($aptW === (int) $apptRow['rest_day']) {
                return true;
            }
        }
        return false;
    }
}

$countJoin = "
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    JOIN dentists d ON d.id = a.dentist_id
";

/* PENDING (taas): Active Pending appointments (today & future). Ang mga lumagpas sa araw ay napupunta sa Appointment History. */
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) $countJoin WHERE a.status = 'Pending' AND a.appointment_date >= CURDATE() $baseSql");
    $countStmt->execute($baseParams);
    $pendingCount = (int) $countStmt->fetchColumn();
} catch (PDOException $e) {
    $pendingCount = 0;
}

try {
    $pendingStmt = $pdo->prepare("
        $listSelect
        WHERE a.status = 'Pending' AND a.appointment_date >= CURDATE() $baseSql
        ORDER BY a.appointment_date ASC, a.start_time ASC
        LIMIT 200
    ");
    $pendingStmt->execute($baseParams);
    $pendingAppointments = $pendingStmt->fetchAll();
} catch (PDOException $e) {
    $pendingAppointments = [];
}

/* UPCOMING & TODAY: Today includes all statuses for today's schedule;
   Future 7 days include active upcoming appointments (Pending, Confirmed, Approved). */
$upcomingGroups = [];
$upcomingTotal = 0;

try {
    $upcomingStmt = $pdo->prepare("
        $listSelect
        WHERE (
            (
                a.appointment_date = CURDATE()
                AND a.status NOT IN ('No-Show', 'Completed', 'Cancelled', 'Canceled')
            )
            OR (
                a.appointment_date > CURDATE()
                AND a.status IN ('Pending', 'Confirmed', 'Approved')
            )
        )
        $baseSql
        ORDER BY a.appointment_date ASC, a.start_time ASC
        LIMIT 500
    ");
    $upcomingStmt->execute($baseParams);

    foreach ($upcomingStmt->fetchAll(PDO::FETCH_ASSOC) as $upcomingRow) {
        $upcomingDate = substr((string) ($upcomingRow['appointment_date'] ?? ''), 0, 10);

        if ($upcomingDate === '') {
            continue;
        }

        $upcomingGroups[$upcomingDate][] = $upcomingRow;
        $upcomingTotal++;
    }
} catch (PDOException $e) {
    $upcomingGroups = [];
    $upcomingTotal = 0;
}

/* SUMMARY CARDS: Today's / Upcoming / Pending / Completed.
   Sumusunod sa search + dentist filters para tumutugma sa sections. */
$todayKeySummary = date('Y-m-d');
$summaryToday = count($upcomingGroups[$todayKeySummary] ?? []);
$summaryUpcoming = 0;
foreach ($upcomingGroups as $sumDay => $sumRows) {
    if ($sumDay !== $todayKeySummary) { $summaryUpcoming += count($sumRows); }
}
try {
    $completedStmt = $pdo->prepare("SELECT COUNT(*) $countJoin WHERE a.status = 'Completed' $baseSql");
    $completedStmt->execute($baseParams);
    $summaryCompleted = (int) $completedStmt->fetchColumn();
} catch (PDOException $e) {
    $summaryCompleted = 0;
}

function upcomingDayLabel($dateStr)
{
    if ($dateStr === date('Y-m-d')) {
        return 'Today';
    }

    if ($dateStr === date('Y-m-d', strtotime('+1 day'))) {
        return 'Tomorrow';
    }

    $ts = strtotime($dateStr);

    return $ts ? date('l', $ts) : $dateStr;
}

function upcomingDaySub($dateStr)
{
    $ts = strtotime($dateStr);

    return $ts ? date('M d, Y', $ts) : $dateStr;
}

/* HISTORY (ilalim): finished or past appointments only (do not duplicate active upcoming Confirmed appointments). */
$historyNameSearch = trim($_GET['hsearch'] ?? '');
if ($historyStatus !== '') {
    if ($historyStatus === 'Confirmed' || $historyStatus === 'Pending') {
        $historyWhere = "a.status = ? AND a.appointment_date < CURDATE()";
    } else {
        $historyWhere = 'a.status = ?';
    }
    $historyParams = [$historyStatus];
} else {
    $historyWhere = "(a.status IN ('Completed', 'Cancelled', 'No-Show') OR (a.appointment_date < CURDATE() AND a.status IN ('Pending', 'Confirmed', 'Checked In', 'Late', 'Serving')))";
    $historyParams = [];
}
if ($historyNameSearch !== '') {
    $historyWhere .= " AND CONCAT(p.first_name, ' ', p.last_name) LIKE ?";
    $historyParams[] = '%' . $historyNameSearch . '%';
}
$historyParams = array_merge($historyParams, $baseParams);

try {
    $totalStmt = $pdo->prepare("SELECT COUNT(*) $countJoin WHERE $historyWhere $baseSql");
    $totalStmt->execute($historyParams);
    $historyTotal = (int) $totalStmt->fetchColumn();
} catch (PDOException $e) {
    $historyTotal = 0;
}

$historyCounts = [];
try {
    $hcStmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN a.status = 'Completed' THEN 1 ELSE 0 END) AS count_completed,
            SUM(CASE WHEN a.status = 'No-Show' THEN 1 ELSE 0 END) AS count_noshow,
            SUM(CASE WHEN a.status IN ('Cancelled', 'Canceled') THEN 1 ELSE 0 END) AS count_cancelled,
            SUM(CASE WHEN a.status = 'Pending' AND a.appointment_date < CURDATE() THEN 1 ELSE 0 END) AS count_pending,
            SUM(CASE WHEN a.status IN ('Confirmed', 'Approved') AND a.appointment_date < CURDATE() THEN 1 ELSE 0 END) AS count_confirmed,
            COUNT(*) AS count_all
        $countJoin
        WHERE (a.status IN ('Completed', 'Cancelled', 'Canceled', 'No-Show') OR (a.appointment_date < CURDATE() AND a.status IN ('Pending', 'Confirmed', 'Approved', 'Checked In', 'Late', 'Serving')))
        $baseSql
    ");
    $hcStmt->execute($baseParams);
    $historyCounts = $hcStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $historyCounts = [];
}

$historyTotalPages = max(1, (int) ceil($historyTotal / $historyPerPage));
if ($historyPage > $historyTotalPages) {
    $historyPage = $historyTotalPages;
}
$historyOffset = ($historyPage - 1) * $historyPerPage;

try {
    $historyStmt = $pdo->prepare("
        $listSelect
        WHERE $historyWhere $baseSql
        ORDER BY a.appointment_date DESC, a.start_time DESC
        LIMIT $historyPerPage OFFSET $historyOffset
    ");
    $historyStmt->execute($historyParams);
    $historyAppointments = $historyStmt->fetchAll();
} catch (PDOException $e) {
    $historyAppointments = [];
}

/* Query string na nagpapanatili ng filters (para sa pagination at view links). */
$filterParams = [];
if ($search !== '') {
    $filterParams['search'] = $search;
}
if ($dentistFilter > 0) {
    $filterParams['dentist_id'] = $dentistFilter;
}
if ($historyStatus !== '') {
    $filterParams['hstatus'] = $historyStatus;
}
if ($historyNameSearch !== '') {
    $filterParams['hsearch'] = $historyNameSearch;
}
if ($todayStatus !== '') {
    $filterParams['tstatus'] = $todayStatus;
}
if ($upcomingDateFilter !== '') {
    $filterParams['udate'] = $upcomingDateFilter;
}
if ($upcomingStatus !== '') {
    $filterParams['ustatus'] = $upcomingStatus;
}
$keptQs = empty($filterParams) ? '' : http_build_query($filterParams) . '&';

$initialTab = trim($_GET['tab'] ?? '');
if (!in_array($initialTab, ['today', 'upcoming', 'history', 'all'], true)) {
    if (isset($_GET['hstatus']) || isset($_GET['hpage']) || isset($_GET['hsearch'])) {
        $initialTab = 'history';
    } elseif (isset($_GET['ustatus']) || isset($_GET['upage']) || isset($_GET['udate'])) {
        $initialTab = 'upcoming';
    } elseif (isset($_GET['tstatus']) || isset($_GET['tpage'])) {
        $initialTab = 'today';
    } else {
        $initialTab = 'all';
    }
}
$totalAllAppts = (int) $summaryToday + (int) ($summaryUpcoming ?? 0) + (int) $historyTotal;

$viewAppointment = null;
$viewId = (int) ($_GET['view'] ?? 0);
if ($viewId > 0) {
    $stmt = $pdo->prepare("
        SELECT a.*, CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
               p.patient_number, p.contact_number, p.email AS patient_email,
               d.dentist_name
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
        JOIN dentists d ON d.id = a.dentist_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$viewId]);
    $viewAppointment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

?>

<!DOCTYPE html>
<html>

<head>

<title>Appointments</title>

<meta
name="viewport"
content="width=device-width,initial-scale=1">

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet">

<link
rel="stylesheet"
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<link
rel="stylesheet"
href="../assets/style.css?v=2">

<link
href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap"
rel="stylesheet">

<style>
/* Appointments - laman pinalaki para mabasa, dental clinic style */
.main h2 { font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; }
.main .table thead th { font-size: 13px !important; font-family: "Inter",system-ui,sans-serif; letter-spacing: .4px; }
.main .table tbody td { font-size: 14.5px !important; font-family: "Inter","DM Sans",system-ui,sans-serif; vertical-align: middle; }
.main .table tbody td strong { font-size: 14.5px; color: #244e5e; }
.main .table tbody td small { font-size: 13px !important; color: #4A5F68; font-family: "Inter",system-ui,sans-serif; }
.main .badge { font-size: 13px !important; padding: 7px 13px !important; font-family: "Inter",system-ui,sans-serif; }
.main .form-control, .main .form-select { font-size: 14.5px !important; font-family: "Inter","DM Sans",system-ui,sans-serif; }
.main .form-select-sm { font-size: 13.5px !important; }
.main label, .main .form-label { font-size: 13.5px !important; font-family: "Inter",system-ui,sans-serif; }
.main .btn { font-size: 14px !important; font-family: "Inter",system-ui,sans-serif; }
.main .btn-sm { font-size: 13px; padding: 7px 13px; }
.main .alert { font-size: 14px !important; font-family: "Inter",system-ui,sans-serif; }
.main .card .card-body { font-size: 14.5px; }
.modal-title, .modal-header h5 { font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif; font-size: 18px; font-weight: 800; }
.modal-body label { font-size: 13.5px; font-family: "Inter",system-ui,sans-serif; }
.modal-body .form-control, .modal-body .form-select { font-size: 14.5px; }
/* Admin Appointments — katulad sa Patients page ng admin (full-width panel) */
body { background: linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22) !important; background-size: 300% 300% !important; animation: adminApptBg 8s ease infinite !important; background-attachment: fixed !important; }
@keyframes adminApptBg { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
/* Topbar para sa Appointments page */
.shared-topbar {
    left: 280px !important;
    right: 0 !important;
    height: 75px;
    padding: 0 22px;
    gap: 14px;
    box-shadow: 0 4px 14px rgba(14,107,94,.10);
}
.appt-topbar-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
.appt-topbar-brand { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
/* Square na 38px box na kinak-cut ang orange banner ng dental-bg.jpg —
   nananatili lang ang tooth graphic, malinis kahit maliit. */
.appt-topbar-logo {
    width: 38px;
    height: 38px;
    border-radius: 11px;
    object-fit: cover;
    object-position: center top;
    background: #ffffff;
    border: 1px solid #C9E9DA;
    box-shadow: 0 4px 10px rgba(14,107,94,.14);
    flex-shrink: 0;
}
.appt-topbar-clinic { display: flex; flex-direction: column; line-height: 1.15; }
.appt-topbar-clinic strong {
    color: #143f51;
    font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif;
    font-size: 14px;
    font-weight: 800;
    letter-spacing: -0.01em;
    white-space: nowrap;
}
.appt-topbar-clinic span {
    color: #0E6B5E;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .07em;
    text-transform: uppercase;
    white-space: nowrap;
}
.appt-topbar-divider {
    width: 1px;
    height: 26px;
    background: #E1EDEA;
    flex-shrink: 0;
}
.appt-topbar-left + .page-title h1 { font-size: 18px !important; }
.appt-topbar-left + .page-title p { display: none; }
@media (max-width: 991px) {
    .shared-topbar { left: 0 !important; padding: 0 14px; }
}
@media (max-width: 767px) {
    .appt-topbar-left + .page-title { display: none !important; }
    .appt-topbar-clinic span { display: none; }
}
.main { background: transparent !important; margin-left: 280px !important; width: calc(100% - 280px) !important; padding: 92px 24px 40px !important; display: block !important; box-sizing: border-box !important; min-height: 100vh; }
@media (max-width: 991px) {
    .main { margin-left: 0 !important; width: 100% !important; padding: 92px 16px 40px !important; }
}
.main > a.btn[href*="dashboard.php"] { margin-left: 14px !important; margin-right: 14px !important; margin-bottom: 16px !important; }
.main > .alert { margin-left: 14px !important; margin-right: 14px !important; box-sizing: border-box !important; font-size: 13.5px !important; border-radius: 12px !important; }
.appointments-page-head { margin: 0 14px 16px 14px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; }
.appointments-page-head h1 { color: #ffffff !important; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; margin: 0; }
.appointments-page-head p { margin: 4px 0 0; color: #C9E9DA; font-size: 13px; }
.appt-count-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 26px; height: 26px; padding: 0 8px; border-radius: 20px; font-size: 12.5px; font-weight: 800; background: #0E6B5E; color: #fff; vertical-align: middle; font-family: "Inter",system-ui,sans-serif; }
.history-pagination { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 22px; background: #F6FBF8; border-top: 1px solid #E6F2EC; }
.pagination-info { font-size: 13px; color: #3F525B; font-family: "Inter",system-ui,sans-serif; }
.page-notation { display: flex; align-items: center; gap: 6px; }
.page-link-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 34px; padding: 0 10px; border-radius: 9px; border: 1px solid #D1E7DD; background: #fff; color: #0E6B5E !important; font-size: 13px; font-weight: 700; text-decoration: none; font-family: "Inter",system-ui,sans-serif; }
.page-link-btn:hover { background: #EAF7F0; border-color: #0E6B5E; }
.page-link-btn.active { background: linear-gradient(135deg, #149A72, #0A4A41) !important; color: #fff !important; border-color: transparent; }
.main .empty-state { padding: 28px 16px; display: flex; flex-direction: column; align-items: center; gap: 6px; }
.main .empty-state .empty-icon { width: 52px; height: 52px; border-radius: 14px; background: #EAF7F0; color: #0E6B5E; display: flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 4px; }
.main .empty-state strong { color: #143f51; font-size: 15px; }
.main .empty-state span { color: #4A5F68; font-size: 13px; }
.main .card {
    background: #ffffff !important;
    border: 1px solid #C9E9DA !important;
    border-radius: 16px !important;
    overflow: hidden !important;
    box-shadow: 0 8px 24px rgba(14,107,94,.08) !important;
    padding: 0 !important;
    min-height: auto !important;
    margin: 0 14px 24px 14px !important;
    position: relative;
}
.main .card-body { padding: 0 !important; }
.appointments-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 18px 22px;
    background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%);
    border-bottom: 1px solid #C9E9DA;
}
.appointments-header h2 {
    font-size: 17px !important;
    color: #143f51 !important;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 800;
}
.appointments-header h2 i { color: #0E6B5E; font-size: 18px; }
.appointments-header .header-subtitle {
    font-size: 12px;
    color: #4A5F68;
    margin: 4px 0 0 26px;
    font-weight: 500;
}
.appointments-header .btn-primary {
    background: linear-gradient(135deg, #149A72, #0A4A41) !important;
    border: none !important;
    border-radius: 10px !important;
    box-shadow: 0 6px 14px rgba(14,107,94,.22) !important;
    font-weight: 700 !important;
    padding: 9px 16px !important;
    white-space: nowrap;
}
.appointments-filter-bar {
    padding: 16px 22px;
    background: #ffffff;
    border-bottom: 1px solid #E6F2EC;
}
.appointments-filter-bar .form-control,
.appointments-filter-bar .form-select {
    border-radius: 10px !important;
    border: 1px solid #D1E7DD !important;
    background-color: #F6FBF8 !important;
    font-size: 14px !important;
}
.appointments-filter-bar .form-select {
    cursor: pointer !important;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%230E6B5E' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e") !important;
    background-repeat: no-repeat !important;
    background-position: right 14px center !important;
    background-size: 14px 10px !important;
    padding-right: 36px !important;
}
.appointments-filter-bar .form-control:focus,
.appointments-filter-bar .form-select:focus {
    border-color: #0E6B5E !important;
    box-shadow: 0 0 0 3px rgba(14,107,94,.12) !important;
    background-color: #ffffff !important;
}
.main .table { margin-bottom: 0 !important; width: 100%; border-collapse: separate; border-spacing: 0; }
.main .table thead tr { background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important; }
.main .table thead th {
    background: transparent !important;
    color: #ffffff !important;
    font-size: 11.5px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: .6px !important;
    padding: 14px 16px !important;
    border-bottom: none !important;
    border-right: 1px solid rgba(255,255,255,.12) !important;
    white-space: nowrap;
}
.main .table thead th:last-child { border-right: none !important; }
.main .table tbody td {
    padding: 14px 16px !important;
    font-size: 13.5px !important;
    color: #2F3E45 !important;
    background: #ffffff;
    border-bottom: 1px solid #E6F2EC !important;
    vertical-align: middle;
}
.main .table tbody tr:nth-child(even) td { background: #F6FBF8 !important; }
.main .table tbody tr:hover td { background: #EAF7F0 !important; }
.main .table tbody td strong { color: #0F3546 !important; font-size: 13.5px !important; font-weight: 700 !important; }
.main .table tbody td small { color: #4A5F68 !important; font-size: 11.5px !important; }
.main .table tbody tr:last-child td:first-child { border-bottom-left-radius: 16px; }
.main .table tbody tr:last-child td:last-child { border-bottom-right-radius: 16px; }
.main .badge.bg-secondary { background: linear-gradient(135deg, #149A72, #0A4A41) !important; color: #fff !important; border-radius: 20px; padding: 6px 11px !important; font-size: 11.5px !important; }
/* Status badge — katulad sa patient My Appointments */
.status-badge { display: inline-block; border-radius: 20px; padding: 6px 11px !important; font-size: 11.5px !important; font-family: "Inter",system-ui,sans-serif; font-weight: 700; }
.status-pending { background: #fff3d9 !important; color: #c98609 !important; }
.status-confirmed { background: #e3f8ec !important; color: #17844d !important; }
.status-completed { background: #e6f4fb !important; color: #157ca0 !important; }
.status-cancelled { background: #fdecec !important; color: #b43b3b !important; }
.status-no-show { background: #f1e8e8 !important; color: #8b4141 !important; }
.status-checked-in { background: #d9f2e6 !important; color: #0E6B5E !important; }
.status-late { background: #fde8c8 !important; color: #9a4a00 !important; }
.status-serving { background: #e0f2f7 !important; color: #0b6e8f !important; }
/* Buttons — katulad sa Patients page ng admin */
.main .btn-primary { background: linear-gradient(135deg, #149A72, #0A4A41) !important; border: none !important; border-radius: 8px !important; box-shadow: 0 6px 14px rgba(14,107,94,.22) !important; font-weight: 700 !important; }
.main .btn-primary:hover { background: linear-gradient(135deg, #0E6B5E, #083A32) !important; }
.main .btn-outline-primary { color: #0E6B5E !important; border-color: #C9E9DA !important; background: #fff !important; }
.main .btn-outline-primary:hover { background: #EAF7F0 !important; color: #0E6B5E !important; border-color: #0E6B5E !important; }
.main .btn { font-size: 13.5px !important; }
.main .btn-sm { font-size: 12.5px !important; }
.main .modal-content { border-radius: 16px !important; border: 1px solid #C9E9DA !important; overflow: visible !important; }
.main .modal-header { background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%) !important; border-bottom: 1px solid #C9E9DA !important; border-top-left-radius: 15px !important; border-top-right-radius: 15px !important; }
.main .modal-footer { border-bottom-left-radius: 15px !important; border-bottom-right-radius: 15px !important; }
.main .modal-header h5 { color: #143f51 !important; }
/* Highlight ng clinick na notification — para hindi na maghanap si admin */
.main .table tbody tr.highlight-row td { background: #FFF7E0 !important; border-top: 2px solid #D9BE6A !important; border-bottom: 2px solid #D9BE6A !important; animation: highlightPulse 1.2s ease-in-out 2; }
.main .table tbody tr.highlight-row td:first-child { border-left: 4px solid #C98609 !important; }
.main .table tbody tr.highlight-row td:last-child { border-right: 2px solid #D9BE6A !important; }
@keyframes highlightPulse { 0%,100% { filter: brightness(1); } 50% { filter: brightness(1.06); } }
/* Upcoming day groups — Today una, tapos Tomorrow, tapos susunod na araw */
.upcoming-day + .upcoming-day { border-top: 2px solid #E6F2EC; }
.upcoming-day-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; padding: 16px 20px 8px 20px; background: #fff; }
.upcoming-day-pill { display: inline-flex; align-items: center; gap: 8px; background: #EAF7F0; border: 1px solid #C9E9DA; color: #0E6B5E; font-weight: 800; font-size: 13px; border-radius: 999px; padding: 7px 14px; }
.upcoming-day-pill.today { background: linear-gradient(135deg, #149A72, #0A4A41); color: #fff; border-color: #0A4A41; box-shadow: 0 6px 14px rgba(14,107,94,.22); }
.upcoming-day-count { color: #4A5F68; font-size: 12px; font-weight: 700; }
/* Scrollable na buong Upcoming list + sticky header kada araw */
.upcoming-scroll { max-height: 560px; overflow: auto; -webkit-overflow-scrolling: touch; }
.upcoming-scroll .patient-table { margin-bottom: 0 !important; width: 100%; }
.upcoming-scroll .patient-table thead th {
    position: sticky;
    top: 0;
    z-index: 3;
    background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important;
    border-right: 1px solid rgba(255,255,255,.12) !important;
}
.upcoming-scroll::-webkit-scrollbar { width: 10px; }
.upcoming-scroll::-webkit-scrollbar-track { background: #F1F7F4; border-radius: 8px; }
.upcoming-scroll::-webkit-scrollbar-thumb { background: #9CC9BB; border-radius: 8px; border: 2px solid #F1F7F4; }
.upcoming-scroll::-webkit-scrollbar-thumb:hover { background: #149A72; }
/* Sticky header para sa scrollable na Upcoming table */
.upcoming-day .table-responsive > table > thead th {
    position: sticky;
    top: 0;
    z-index: 3;
    background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important;
    border-right: 1px solid rgba(255,255,255,.12) !important;
}
/* SUMMARY CARDS — 4 cards sa taas: Today's / Upcoming (7 days) / Pending / Completed */
/* SUMMARY CARDS — 4 cards sa itaas. Design na kopya ng admin Dashboard
   (.stat-card sa dashboard.php): green gradient, radial glows sa
   ::before/::after, translucent icon square, malaking numero, pulsing
   dot sa subtitle, at chevron button sa bottom-right. */
.appt-summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin: 0 14px 20px 14px; }
.appt-summary-card { position: relative; overflow: hidden; background: linear-gradient(135deg,#047857 0%,#059669 55%,#10B981 100%); border: 1px solid rgba(255,255,255,.28); border-radius: 14px; padding: 12px 14px; display: flex; flex-direction: column; align-items: flex-start; gap: 3px; box-shadow: 0 16px 34px rgba(5,150,105,.35); color: #ffffff; text-decoration: none !important; transition: .25s ease; user-select: none; -webkit-tap-highlight-color: transparent; }
.appt-summary-card::before,
.appt-summary-card::after { content: ""; position: absolute; border-radius: 50%; pointer-events: none; }
.appt-summary-card::before { width: 130px; height: 130px; top: -60px; right: -45px; background: radial-gradient(circle,rgba(255,255,255,.22),rgba(255,255,255,0) 70%); }
.appt-summary-card::after { width: 100px; height: 100px; bottom: -48px; left: -32px; background: radial-gradient(circle,rgba(255,255,255,.16),rgba(255,255,255,0) 70%); }
.appt-summary-card > * { position: relative; z-index: 1; }
a.appt-summary-card:hover { transform: translateY(-4px); box-shadow: 0 22px 42px rgba(5,150,105,.45); border-color: rgba(255,255,255,.62); color: #ffffff; }
a.appt-summary-card:focus-visible { outline: 2px solid #ffffff; outline-offset: 2px; }
.appt-summary-head { display: flex; align-items: center; gap: 10px; width: 100%; }
.appt-summary-icon { width: 34px; height: 34px; border-radius: 11px; display: inline-flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.32); color: #ffffff; box-shadow: 0 8px 18px rgba(0,0,0,.14); }
.appt-summary-label { margin: 0; flex: 1; min-width: 0; color: rgba(255,255,255,.92); font-size: 13px; font-weight: 700; }
.appt-summary-num { color: #ffffff; font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif; font-size: 27px; font-weight: 800; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; text-shadow: 0 6px 18px rgba(0,0,0,.22); line-height: 1; margin-top: auto; padding-top: 4px; }
.appt-summary-sub { display: flex; align-items: center; gap: 8px; font-size: 11.5px; font-weight: 600; color: rgba(255,255,255,.86); margin-top: 3px; }
.appt-summary-dot { width: 8px; height: 8px; border-radius: 50%; background: #A7F3D0; box-shadow: 0 0 0 4px rgba(167,243,208,.25); animation: apptSummaryDotPulse 1.9s ease-in-out infinite; flex-shrink: 0; }
@keyframes apptSummaryDotPulse { 0%,100% { opacity: 1; transform: scale(1); } 50% { opacity: .5; transform: scale(.8); } }
.appt-summary-go { position: absolute; right: 12px; bottom: 12px; width: 22px; height: 22px; border-radius: 50%; background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.35); color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; flex-shrink: 0; z-index: 2; }
@media (max-width: 1100px) { .appt-summary-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 991px) { .appt-summary-grid { gap: 14px; } }
@media (max-width: 575px) { .appt-summary-grid { grid-template-columns: 1fr; margin-left: 0; margin-right: 0; } }
/* APPOINTMENT CARDS — bawat section ay HIWALAY na card (sariling container,
   border, rounded corners, at spacing). Hindi gumagamit ng Bootstrap na
   .accordion-item para hindi sila mapagsama sa iisang box. */
.main .card.patient-card.appt-card {
    background: #ffffff !important;
    border: 1px solid #C9E9DA !important;
    border-radius: 16px !important;
    box-shadow: 0 8px 24px rgba(14,107,94,.08) !important;
    overflow: hidden !important;
    /* Sariling margin para may espasyo sa gilid AT sa pagitan ng bawat card */
    margin: 0 14px 24px 14px !important;
}
.main .card.patient-card.appt-card:last-of-type { margin-bottom: 24px !important; }
@media (max-width: 575px) {
    .main .card.patient-card.appt-card { margin-left: 0 !important; margin-right: 0 !important; }
}
.appt-card-header { margin: 0; padding: 0; }
.appt-card-toggle {
    display: block;
    width: 100%;
    text-align: left;
    background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%);
    color: #143f51 !important;
    padding: 18px 22px;
    border: none !important;
    box-shadow: none !important;
    font-family: "Inter", system-ui, sans-serif;
    cursor: pointer;
}
.appt-card-toggle:hover { background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%); }
.appt-card-toggle:focus,
.appt-card-toggle:focus-visible { box-shadow: none !important; border-color: transparent !important; outline: none; }
.appt-card-toggle::after { display: none !important; }
.appt-acc-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.appt-acc-title { font-size: 17px; font-weight: 800; display: inline-flex; align-items: center; gap: 8px; }
.appt-acc-title i { color: #0E6B5E; font-size: 18px; }
.appt-acc-sub { font-size: 12px; color: #4A5F68; margin: 4px 0 0 26px; font-weight: 500; display: block; }
.appt-acc-chevron { color: #0E6B5E; font-size: 18px; transition: transform .22s ease; flex-shrink: 0; }
/* Bootstrap Collapse nagre-toggle ng .collapsed sa trigger button */
.appt-card-toggle.collapsed .appt-acc-chevron { transform: rotate(-90deg); }
.appt-card-body { padding: 0 !important; }
.appt-card-collapse { border-top: 1px solid #C9E9DA; }

/* Category Tabs Bar */
.appt-category-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    background: #ffffff;
    padding: 10px 14px;
    border-radius: 14px;
    border: 1px solid #C9E9DA;
    box-shadow: 0 4px 16px rgba(14,107,94,.08);
    margin: 0 14px 20px 14px;
    align-items: center;
    box-sizing: border-box;
}
.appt-cat-btn {
    width: 100%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: #F4FAF7;
    border: 1.5px solid #C9E9DA;
    color: #143f51;
    font-weight: 700;
    font-size: 13.5px;
    padding: 10px 16px;
    border-radius: 10px;
    cursor: pointer;
    transition: all .2s ease;
    text-decoration: none;
    font-family: "Inter", system-ui, sans-serif;
    white-space: nowrap;
    box-sizing: border-box;
}
.appt-cat-btn:hover {
    background: #EAF7F0;
    border-color: #0E6B5E;
    color: #0E6B5E;
    transform: translateY(-1px);
}
.appt-cat-btn.active {
    background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important;
    border-color: #0A4A41 !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(14,107,94,.28);
}
.appt-cat-btn .cat-badge {
    display: inline-block;
    padding: 2px 8px;
    font-size: 11px;
    font-weight: 800;
    border-radius: 20px;
    background: rgba(14,107,94,.12);
    color: #0E6B5E;
}
.appt-cat-btn.active .cat-badge {
    background: rgba(255,255,255,.24);
    color: #ffffff;
}
@media (max-width: 1100px) {
    .appt-category-bar {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 991px) {
    .appt-category-bar {
        gap: 10px;
    }
}
@media (max-width: 575px) {
    .appt-category-bar {
        grid-template-columns: 1fr;
        margin-left: 0;
        margin-right: 0;
    }
}
</style>

</head>

<body>

<?php include __DIR__ . "/includes.php"; ?>

<div class="main">

<?php showFlash(); ?>

<div class="d-flex gap-2 flex-wrap mb-3" style="margin-left:14px;margin-right:14px;">
<?php if (($_GET['from'] ?? '') === 'notifications'): ?>
<a href="notifications.php" class="btn mb-0" data-tip="Back to Notifications">
<i class="bi bi-arrow-left-circle-fill me-1"></i>Back to Notifications
</a>
<?php else: ?>
<a href="dashboard.php" class="btn mb-0" data-tip="Back to Dashboard">
<i class="bi bi-arrow-left-circle-fill me-1"></i>Back to Dashboard
</a>
<?php endif; ?>
</div>

<!-- SUMMARY CARDS: Today's / Upcoming (7 days) / Pending / Completed -->
<div class="appt-summary-grid">
<a href="#today" class="appt-summary-card" data-tip="View Today's Appointments" onclick="selectAdminApptCategory('today', true)">
<span class="appt-summary-head">
<span class="appt-summary-icon"><i class="bi bi-calendar-event-fill"></i></span>
<span class="appt-summary-label">Today's Appointments</span>
</span>
<span class="appt-summary-num"><?= (int) $summaryToday ?></span>
<span class="appt-summary-sub"><span class="appt-summary-dot"></span>Scheduled for today</span>
<span class="appt-summary-go"><i class="bi bi-chevron-right"></i></span>
</a>
<a href="#upcoming" class="appt-summary-card" data-tip="View Upcoming Appointments (next 7 days)" onclick="selectAdminApptCategory('upcoming', true)">
<span class="appt-summary-head">
<span class="appt-summary-icon"><i class="bi bi-calendar2-week-fill"></i></span>
<span class="appt-summary-label">Upcoming Appointments</span>
</span>
<span class="appt-summary-num"><?= (int) ($summaryUpcoming ?? 0) ?></span>
<span class="appt-summary-sub"><span class="appt-summary-dot"></span>Next 7 days</span>
<span class="appt-summary-go"><i class="bi bi-chevron-right"></i></span>
</a>
<a href="appointments.php?ustatus=Pending#upcoming" class="appt-summary-card" data-tip="View Pending Appointments">
<span class="appt-summary-head">
<span class="appt-summary-icon"><i class="bi bi-hourglass-split"></i></span>
<span class="appt-summary-label">Pending Appointments</span>
</span>
<span class="appt-summary-num"><?= (int) $pendingCount ?></span>
<span class="appt-summary-sub"><span class="appt-summary-dot"></span>Awaiting confirmation</span>
<span class="appt-summary-go"><i class="bi bi-chevron-right"></i></span>
</a>
<a href="appointments.php?hstatus=Completed#history" class="appt-summary-card" data-tip="View Completed Appointments">
<span class="appt-summary-head">
<span class="appt-summary-icon"><i class="bi bi-check-circle-fill"></i></span>
<span class="appt-summary-label">Completed Appointments</span>
</span>
<span class="appt-summary-num"><?= (int) $summaryCompleted ?></span>
<span class="appt-summary-sub"><span class="appt-summary-dot"></span>Finished treatments</span>
<span class="appt-summary-go"><i class="bi bi-chevron-right"></i></span>
</a>
</div>

<!-- Category Tabs Bar: Today's Appointments / Upcoming Appointments / Appointment History / All -->
<div class="appt-category-bar" id="apptCategoryBar">
    <button type="button" class="appt-cat-btn <?= $initialTab === 'today' ? 'active' : '' ?>" data-tab="today" onclick="selectAdminApptCategory('today', true)">
        <i class="bi bi-calendar2-day-fill" style="color:#059669;"></i>
        <span>Today's Appointments</span>
        <span class="cat-badge"><?= (int) $summaryToday ?></span>
    </button>
    <button type="button" class="appt-cat-btn <?= $initialTab === 'upcoming' ? 'active' : '' ?>" data-tab="upcoming" onclick="selectAdminApptCategory('upcoming', true)">
        <i class="bi bi-calendar2-plus-fill" style="color:#0284c7;"></i>
        <span>Upcoming Appointments</span>
        <span class="cat-badge"><?= (int) ($summaryUpcoming ?? 0) ?></span>
    </button>
    <button type="button" class="appt-cat-btn <?= $initialTab === 'history' ? 'active' : '' ?>" data-tab="history" onclick="selectAdminApptCategory('history', true)">
        <i class="bi bi-clock-history" style="color:#64748b;"></i>
        <span>Appointment History</span>
        <span class="cat-badge"><?= (int) $historyTotal ?></span>
    </button>
    <button type="button" class="appt-cat-btn <?= $initialTab === 'all' ? 'active' : '' ?>" data-tab="all" onclick="selectAdminApptCategory('all', true)">
        <i class="bi bi-collection-fill" style="color:#0E6B5E;"></i>
        <span>All</span>
        <span class="cat-badge"><?= (int) $totalAllAppts ?></span>
    </button>
</div>

<?php
$todayKey = date('Y-m-d');
$todayAppts = $upcomingGroups[$todayKey] ?? [];
if ($todayStatus !== '') {
    $todayAppts = array_values(array_filter($todayAppts, function ($r) use ($todayStatus) {
        return strcasecmp(trim($r['status'] ?? ''), $todayStatus) === 0;
    }));
}

/* Pagination para sa Today's Appointment — 10 kada page para hindi na mahirap mag-scroll. */
$todayTotal = count($todayAppts);
$todayPerPage = 10;
$todayPage = max(1, (int) ($_GET['tpage'] ?? 1));
$todayTotalPages = max(1, (int) ceil($todayTotal / $todayPerPage));
if ($todayPage > $todayTotalPages) {
    $todayPage = $todayTotalPages;
}
$todayOffset = ($todayPage - 1) * $todayPerPage;
$todayPaginatedAppts = array_slice($todayAppts, $todayOffset, $todayPerPage);
?>
<div class="card patient-card appt-card" id="today" style="scroll-margin-top: 110px;<?= ($initialTab !== 'today' && $initialTab !== 'all') ? ' display: none;' : '' ?>">
<h2 class="appt-card-header" id="heading-today">
<button class="appt-card-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-today" aria-expanded="true" aria-controls="collapse-today">
    <span class="appt-acc-head">
        <span>
            <span class="appt-acc-title"><i class="bi bi-calendar-event-fill"></i> Today's Appointment <span class="appt-count-badge"><?= (int) $todayTotal ?></span></span>
            <span class="appt-acc-sub">Scheduled for today · <?= e(date('F d, Y')) ?><?= $todayStatus !== '' ? ' · ' . e($todayStatus) : '' ?></span>
        </span>
        <i class="bi bi-chevron-down appt-acc-chevron"></i>
    </span>
</button>
</h2>
<div id="collapse-today" class="collapse show appt-card-collapse">
<div class="appt-card-body">
<div class="appointments-filter-bar">
<form method="GET" action="appointments.php#today" class="row g-2 mb-0" onsubmit="this.action='appointments.php?tab=today#today';">
    <input type="hidden" name="tab" value="today">
    <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?= e($search) ?>"><?php endif; ?>
    <?php if ($dentistFilter > 0): ?><input type="hidden" name="dentist_id" value="<?= (int) $dentistFilter ?>"><?php endif; ?>
    <?php if ($historyStatus !== ''): ?><input type="hidden" name="hstatus" value="<?= e($historyStatus) ?>"><?php endif; ?>
    <?php if ($upcomingDateFilter !== ''): ?><input type="hidden" name="udate" value="<?= e($upcomingDateFilter) ?>"><?php endif; ?>
    <?php if ($upcomingStatus !== ''): ?><input type="hidden" name="ustatus" value="<?= e($upcomingStatus) ?>"><?php endif; ?>
    <div class="col-md-4">
        <select name="tstatus" class="form-select" onchange="this.form.submit()">
            <option value="">All statuses</option>
            <?php foreach ($todayStatuses as $ts): ?>
            <option value="<?= e($ts) ?>" <?= $todayStatus === $ts ? 'selected' : '' ?>><?= e($ts) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($todayStatus !== ''): ?>
    <div class="col-auto"><a href="appointments.php<?= ($search !== '' || $dentistFilter > 0 || $historyStatus !== '' || $upcomingDateFilter !== '' || $upcomingStatus !== '' ? '?' . http_build_query(array_filter(['search' => $search !== '' ? $search : null, 'dentist_id' => $dentistFilter > 0 ? $dentistFilter : null, 'hstatus' => $historyStatus !== '' ? $historyStatus : null, 'udate' => $upcomingDateFilter !== '' ? $upcomingDateFilter : null, 'ustatus' => $upcomingStatus !== '' ? $upcomingStatus : null])) : '') ?>#today" class="btn btn-light">Clear</a></div>
    <?php endif; ?>
</form>
</div>

<div class="card-body" style="padding:0;">

<?php if (empty($todayAppts)): ?>
<div class="empty-state" style="padding: 30px 20px;">
<div class="empty-icon"><i class="bi bi-calendar-event"></i></div>
<strong>No appointments for today<?= $todayStatus !== '' ? ' with status ' . e($todayStatus) : '' ?></strong>
<span>Today's bookings will appear here.</span>
</div>
<?php else: ?>

<div class="table-responsive" style="max-height:560px;overflow:auto;">
<table class="table patient-table">

<thead>
<tr>
<th>#</th>
<th>Patient name</th>
<th>Time of appointment</th>
<th>Dental service</th>
<th>Assigned dentist</th>
<th>Status</th>
<th>Action</th>
</tr>
</thead>

<tbody>

<?php foreach ($todayPaginatedAppts as $idx => $a): ?>

<tr id="appt-<?= (int) $a['id'] ?>" class="<?= ((int)($_GET['view'] ?? 0) === (int)$a['id']) ? 'highlight-row' : '' ?>">

<td><?= (int) $todayOffset + $idx + 1 ?></td>

<td>
<strong>
<?= e($a['patient_name']) ?>
</strong>
<br>
<small>
<?= e($a['patient_number']) ?>
</small>
</td>

<td style="white-space:nowrap;">
<strong><?= e(date('M d, Y', strtotime($a['appointment_date']))) ?></strong>
<br>
<small style="color:#3F525B; font-size:12.5px;">
<?= e(date("h:i A", strtotime($a['start_time']))) ?>
-
<?= e(date("h:i A", strtotime($a['end_time']))) ?>
</small>
</td>

<td>
<?= e($a['service_type']) ?>
</td>

<td>
<?= e($a['dentist_name']) ?>
</td>

<td>
<?php
$todayStatusClass = strtolower(str_replace(' ', '-', $a['status'] ?? ''));
?>
<span class="status-badge status-<?= $todayStatusClass ?>">
<?= e($a['status']) ?>
</span>
</td>

<td>
<button
type="button"
class="btn btn-sm btn-outline-primary history-view-btn"
data-tip="View Details"
data-patient="<?= e(trim(($a['patient_name'] ?? '') . '')) ?>"
data-dentist="<?= e($a['dentist_name'] ?? '') ?>"
data-dentist-id="<?= (int)($a['dentist_id'] ?? 0) ?>"
data-date="<?= e($a['appointment_date'] ?? '') ?>"
data-start="<?= e(substr($a['start_time'] ?? '', 0, 5)) ?>"
data-end="<?= e(substr($a['end_time'] ?? '', 0, 5)) ?>"
data-service="<?= e($a['service_type'] ?? '') ?>"
data-booked="<?= e(!empty($a['created_at']) ? date('M d, Y h:i A', strtotime($a['created_at'])) : '-') ?>"
data-status="<?= e($a['status'] ?? '') ?>"
data-confirmed="<?= e(!empty($a['confirmed_at']) ? date('M d, Y h:i A', strtotime($a['confirmed_at'])) : '') ?>"
data-cancelled="<?= e(!empty($a['cancelled_at']) ? date('M d, Y h:i A', strtotime($a['cancelled_at'])) : ($a['status'] === 'Cancelled' && !empty($a['created_at']) ? date('M d, Y h:i A', strtotime($a['created_at'])) : '')) ?>"
data-checkedin="<?= e(!empty($a['checked_in_at']) ? date('M d, Y h:i A', strtotime($a['checked_in_at'])) : '') ?>"
data-serving="<?= e(!empty($a['serving_at']) ? date('M d, Y h:i A', strtotime($a['serving_at'])) : '') ?>"
data-completed="<?= e(!empty($a['completed_at']) ? date('M d, Y h:i A', strtotime($a['completed_at'])) : '') ?>"
data-rescheduled="<?= e(!empty($a['rescheduled_at']) ? date('M d, Y h:i A', strtotime($a['rescheduled_at'])) : '') ?>"
title="View details">
<i class="bi bi-eye me-1"></i> View
</button>
<?php
$todayStatusClass = strtolower(str_replace(' ', '-', $a['status'] ?? ''));
?>
<button
type="button"
class="btn btn-sm status-action-btn"
data-tip="Change Status"
data-appt-id="<?= (int) $a['id'] ?>"
data-patient="<?= e(trim(($a['patient_name'] ?? '') . ' (' . ($a['patient_number'] ?? '') . ')')) ?>"
data-dentist="<?= e($a['dentist_name'] ?? '') ?>"
data-dentist-id="<?= (int)($a['dentist_id'] ?? 0) ?>"
data-service="<?= e($a['service_type'] ?? '') ?>"
data-status="<?= e($a['status'] ?? '') ?>"
data-rescheduled="<?= !empty($a['rescheduled_at']) ? '1' : '0' ?>"
data-date="<?= e(substr($a['appointment_date'] ?? '', 0, 10)) ?>"
data-start="<?= e(substr($a['start_time'] ?? '', 0, 5)) ?>"
data-end="<?= e(substr($a['end_time'] ?? '', 0, 5)) ?>"
data-dentist-absent="<?= isApptDentistAbsent($a) ? '1' : '0' ?>"
data-absent-reason="<?= e(!empty($a['dayoff_reason']) ? $a['dayoff_reason'] : ((isset($a['rest_day']) && (int)date('w', strtotime($a['appointment_date'])) === (int)$a['rest_day']) ? 'Weekly Rest Day' : '')) ?>"
data-tab="today"
title="Change status"
style="cursor:pointer;">
Change Status <i class="bi bi-chevron-down"></i>
</button>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>
</div>

<?php if ($todayTotalPages > 1): ?>
<?php
$tdFrom = $todayTotal > 0 ? $todayOffset + 1 : 0;
$tdTo = min($todayOffset + $todayPerPage, $todayTotal);
$tdStart = max(1, min($todayPage - 3, $todayTotalPages - 6));
$tdEnd = min($todayTotalPages, $tdStart + 6);
$tdStart = max(1, $tdEnd - 6);
?>
<div class="history-pagination">
<span class="pagination-info">Showing <?= (int) $tdFrom ?>—<?= (int) $tdTo ?> of <?= (int) $todayTotal ?> appointment<?= $todayTotal === 1 ? '' : 's' ?> today</span>
<nav class="page-notation" aria-label="Today's appointment pages">
<?php if ($todayPage > 1): ?>
<a href="appointments.php?<?= $keptQs ?>tpage=<?= (int) ($todayPage - 1) ?>#today" class="page-link-btn" aria-label="Previous page"><i class="bi bi-chevron-left"></i></a>
<?php endif; ?>
<?php for ($tp = $tdStart; $tp <= $tdEnd; $tp++): ?>
<a href="appointments.php?<?= $keptQs ?>tpage=<?= (int) $tp ?>#today" class="page-link-btn <?= $tp === $todayPage ? 'active' : '' ?>"><?= (int) $tp ?></a>
<?php endfor; ?>
<?php if ($todayPage < $todayTotalPages): ?>
<a href="appointments.php?<?= $keptQs ?>tpage=<?= (int) ($todayPage + 1) ?>#today" class="page-link-btn" aria-label="Next page"><i class="bi bi-chevron-right"></i></a>
<?php endif; ?>
</nav>
</div>
<?php elseif ($todayTotal > 0): ?>
<div class="history-pagination">
<span class="pagination-info">Showing 1—<?= (int) $todayTotal ?> of <?= (int) $todayTotal ?> appointment<?= $todayTotal === 1 ? '' : 's' ?> today</span>
</div>
<?php endif; ?>

<?php endif; ?>

</div>

</div>

</div>

</div>

<div class="card patient-card appt-card" id="upcoming" style="scroll-margin-top: 110px;<?= ($initialTab !== 'upcoming' && $initialTab !== 'all') ? ' display: none;' : '' ?>">
<?php
$todayKeyUp = date('Y-m-d');
$futureGroups = [];
foreach ($upcomingGroups as $ugDay => $ugRows) {
    if ($ugDay !== $todayKeyUp) { $futureGroups[$ugDay] = $ugRows; }
}
ksort($futureGroups);
/* Status filter muna (ibinalik), tapos date options + date filter — para magsama ang dalawa. */
if ($upcomingStatus !== '') {
    foreach ($futureGroups as $fgDay => $fgRows) {
        $filtered = array_values(array_filter($fgRows, function ($r) use ($upcomingStatus) {
            return strcasecmp(trim($r['status'] ?? ''), $upcomingStatus) === 0;
        }));
        if (empty($filtered)) {
            unset($futureGroups[$fgDay]);
        } else {
            $futureGroups[$fgDay] = $filtered;
        }
    }
}
/* Date options para sa filter — kukunin mula sa mga available na araw na may upcoming appointments */
$upcomingDateOptions = [];
foreach ($futureGroups as $optDay => $optRows) {
    $upcomingDateOptions[$optDay] = count($optRows);
}
/* I-filter sa piniling araw kung may udate. */
if ($upcomingDateFilter !== '') {
    $futureGroups = isset($futureGroups[$upcomingDateFilter]) ? [$upcomingDateFilter => $futureGroups[$upcomingDateFilter]] : [];
}
$futureTotal = 0;
foreach ($futureGroups as $fgRows) { $futureTotal += count($fgRows); }

/* Pagination para sa Upcoming Appointments — 10 lists kada page */
$allUpcomingAppts = [];
foreach ($futureGroups as $ugDay => $ugRows) {
    foreach ($ugRows as $ugR) {
        $allUpcomingAppts[] = $ugR;
    }
}
$upcomingTotal = count($allUpcomingAppts);
$upcomingPerPage = 10;
$upcomingPage = max(1, (int) ($_GET['upage'] ?? 1));
$upcomingTotalPages = max(1, (int) ceil($upcomingTotal / $upcomingPerPage));
if ($upcomingPage > $upcomingTotalPages) {
    $upcomingPage = $upcomingTotalPages;
}
$upcomingOffset = ($upcomingPage - 1) * $upcomingPerPage;
$upcomingPaginatedAppts = array_slice($allUpcomingAppts, $upcomingOffset, $upcomingPerPage);

/* I-group ang 10 appointments sa kasalukuyang page ayon sa araw */
$upcomingPaginatedGroups = [];
foreach ($upcomingPaginatedAppts as $ugR) {
    $pDay = substr((string) ($ugR['appointment_date'] ?? ''), 0, 10);
    if ($pDay === '') { continue; }
    $upcomingPaginatedGroups[$pDay][] = $ugR;
}
?>
<h2 class="appt-card-header" id="heading-upcoming">
<button class="appt-card-toggle<?= $initialTab === 'upcoming' ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-upcoming" aria-expanded="<?= $initialTab === 'upcoming' ? 'true' : 'false' ?>" aria-controls="collapse-upcoming">
    <span class="appt-acc-head">
        <span>
            <span class="appt-acc-title"><i class="bi bi-calendar2-week-fill"></i> Upcoming Appointments <span class="appt-count-badge"><?= (int) $futureTotal ?></span></span>
            <span class="appt-acc-sub"><?= $upcomingDateFilter !== '' ? 'Showing ' . e(upcomingDayLabel($upcomingDateFilter)) . ' · ' . e(upcomingDaySub($upcomingDateFilter)) : "Upcoming scheduled dental appointments — today's appointments are in Today's Appointment above" ?></span>
        </span>
        <span style="margin:0 auto;display:inline-flex;align-items:center;gap:8px;background:#EAF7F0;border:1px solid #C9E9DA;color:#0E6B5E;font-weight:800;font-size:13.5px;border-radius:999px;padding:8px 18px;white-space:nowrap;"><i class="bi bi-calendar-event"></i>Upcoming Schedule</span>
        <i class="bi bi-chevron-down appt-acc-chevron"></i>
    </span>
</button>
</h2>
<div id="collapse-upcoming" class="collapse<?= $initialTab === 'upcoming' ? ' show' : '' ?> appt-card-collapse">
<div class="appt-card-body">
<div class="appointments-filter-bar">
<form method="GET" action="appointments.php#upcoming" class="row g-2 mb-0" onsubmit="this.action='appointments.php?tab=upcoming#upcoming';">
    <input type="hidden" name="tab" value="upcoming">
    <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?= e($search) ?>"><?php endif; ?>
    <?php if ($dentistFilter > 0): ?><input type="hidden" name="dentist_id" value="<?= (int) $dentistFilter ?>"><?php endif; ?>
    <?php if ($historyStatus !== ''): ?><input type="hidden" name="hstatus" value="<?= e($historyStatus) ?>"><?php endif; ?>
    <?php if ($todayStatus !== ''): ?><input type="hidden" name="tstatus" value="<?= e($todayStatus) ?>"><?php endif; ?>
    <div class="col-md-4">
        <select name="udate" class="form-select" onchange="this.form.submit()">
            <option value="">All upcoming dates</option>
            <?php foreach ($upcomingDateOptions as $optDay => $optCount): ?>
            <option value="<?= e($optDay) ?>" <?= $upcomingDateFilter === $optDay ? 'selected' : '' ?>>
                <?= e(upcomingDayLabel($optDay)) ?> · <?= e(upcomingDaySub($optDay)) ?> (<?= (int) $optCount ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4">
        <select name="ustatus" class="form-select" onchange="this.form.submit()">
            <option value="">All statuses</option>
            <?php foreach ($upcomingStatuses as $us): ?>
            <option value="<?= e($us) ?>" <?= $upcomingStatus === $us ? 'selected' : '' ?>><?= e($us) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($upcomingDateFilter !== '' || $upcomingStatus !== ''): ?>
    <div class="col-auto"><a href="appointments.php<?= ($search !== '' || $dentistFilter > 0 || $historyStatus !== '' || $todayStatus !== '' ? '?' . http_build_query(array_filter(['search' => $search !== '' ? $search : null, 'dentist_id' => $dentistFilter > 0 ? $dentistFilter : null, 'hstatus' => $historyStatus !== '' ? $historyStatus : null, 'tstatus' => $todayStatus !== '' ? $todayStatus : null])) : '') ?>#upcoming" class="btn btn-light">Clear</a></div>
    <?php endif; ?>
</form>
</div>

<div class="card-body" style="padding:0;">

<?php if (empty($futureGroups)): ?>
<div class="empty-state" style="padding: 30px 20px;">
<div class="empty-icon"><i class="bi bi-calendar2-check"></i></div>
<strong><?= $upcomingDateFilter !== '' ? 'No appointments for ' . e(upcomingDaySub($upcomingDateFilter)) : 'No upcoming appointments for the next 7 days' ?></strong>
<span><?= $upcomingDateFilter !== '' ? 'Try another date.' : 'Future bookings will appear here.' ?></span>
</div>
<?php else: ?>

<?php $upcomingRowNo = $upcomingOffset; ?>

<div class="upcoming-scroll">

<?php foreach ($upcomingPaginatedGroups as $upcomingDay => $dayAppointments): ?>
<?php
$isTodayGroup = ($upcomingDay === date('Y-m-d'));
$upcomingDayTotal = count($futureGroups[$upcomingDay] ?? []);
?>

<div class="upcoming-day">

<div class="upcoming-day-head">
<span class="upcoming-day-pill <?= $isTodayGroup ? 'today' : '' ?>"><i class="bi bi-calendar-event"></i> <?= e(upcomingDayLabel($upcomingDay)) ?> · <?= e(upcomingDaySub($upcomingDay)) ?></span>
<span class="upcoming-day-count"><?= (int) $upcomingDayTotal ?> appointment<?= $upcomingDayTotal === 1 ? '' : 's' ?></span>
</div>

<table class="table patient-table">

<thead>
<tr>
<th>#</th>
<th>Patient name</th>
<th>Time of appointment</th>
<th>Dental service</th>
<th>Assigned dentist</th>
<th>Status</th>
<th>Action</th>
</tr>
</thead>

<tbody>

<?php foreach ($dayAppointments as $a): ?>
<?php $upcomingRowNo++; ?>

<tr id="appt-<?= (int) $a['id'] ?>" class="<?= ((int)($_GET['view'] ?? 0) === (int)$a['id']) ? 'highlight-row' : '' ?>">

<td><?= (int) $upcomingRowNo ?></td>

<td>
<strong>
<?= e($a['patient_name']) ?>
</strong>
<br>
<small>
<?= e($a['patient_number']) ?>
</small>
</td>

<td style="white-space:nowrap;">
<strong><?= e(date('M d, Y', strtotime($a['appointment_date']))) ?></strong>
<br>
<small style="color:#3F525B; font-size:12.5px;">
<?= e(date("h:i A", strtotime($a['start_time']))) ?>
-
<?= e(date("h:i A", strtotime($a['end_time']))) ?>
</small>
</td>

<td>
<?= e($a['service_type']) ?>
</td>

<td>
<?= e($a['dentist_name']) ?>
</td>

<td>
<?php
$upcomingStatusClass = strtolower(str_replace(' ', '-', $a['status'] ?? ''));
$upcomingStatusClass = $upcomingStatusClass === 'no-show' ? 'no-show' : $upcomingStatusClass;
?>
<span class="status-badge status-<?= $upcomingStatusClass ?>">
<?= e($a['status']) ?>
</span>
</td>

<td>
<button
type="button"
class="btn btn-sm btn-outline-primary upcoming-view-btn"
data-tip="View Details"
data-patient="<?= e(trim(($a['patient_name'] ?? ''))) ?>"
data-service="<?= e($a['service_type'] ?? '') ?>"
data-dentist="<?= e($a['dentist_name'] ?? '') ?>"
data-dentist-id="<?= (int)($a['dentist_id'] ?? 0) ?>"
data-time="<?= e(date('M d, Y', strtotime($a['appointment_date'])) . ' ' . date('h:i A', strtotime($a['start_time'])) . ' - ' . date('h:i A', strtotime($a['end_time']))) ?>"
data-booked="<?= e(!empty($a['created_at']) ? date('M d, Y h:i A', strtotime($a['created_at'])) : '—') ?>"
title="View details">
<i class="bi bi-eye me-1"></i> View
</button>
<button
type="button"
class="btn btn-sm status-action-btn"
data-tip="Change Status"
data-appt-id="<?= (int) $a['id'] ?>"
data-patient="<?= e(trim(($a['patient_name'] ?? '') . ' (' . ($a['patient_number'] ?? '') . ')')) ?>"
data-dentist="<?= e($a['dentist_name'] ?? '') ?>"
data-dentist-id="<?= (int)($a['dentist_id'] ?? 0) ?>"
data-service="<?= e($a['service_type'] ?? '') ?>"
data-status="<?= e($a['status'] ?? '') ?>"
data-rescheduled="<?= !empty($a['rescheduled_at']) ? '1' : '0' ?>"
data-date="<?= e(substr($a['appointment_date'] ?? '', 0, 10)) ?>"
data-start="<?= e(substr($a['start_time'] ?? '', 0, 5)) ?>"
data-end="<?= e(substr($a['end_time'] ?? '', 0, 5)) ?>"
data-dentist-absent="<?= isApptDentistAbsent($a) ? '1' : '0' ?>"
data-absent-reason="<?= e(!empty($a['dayoff_reason']) ? $a['dayoff_reason'] : ((isset($a['rest_day']) && (int)date('w', strtotime($a['appointment_date'])) === (int)$a['rest_day']) ? 'Weekly Rest Day' : '')) ?>"
data-tab="upcoming"
title="Change status"
style="cursor:pointer;">
Change Status <i class="bi bi-chevron-down"></i>
</button>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php endforeach; ?>

</div>

<?php if ($upcomingTotalPages > 1): ?>
<?php
$upFrom = $upcomingTotal > 0 ? $upcomingOffset + 1 : 0;
$upTo = min($upcomingOffset + $upcomingPerPage, $upcomingTotal);
$upStart = max(1, min($upcomingPage - 3, $upcomingTotalPages - 6));
$upEnd = min($upcomingTotalPages, $upStart + 6);
$upStart = max(1, $upEnd - 6);
?>
<div class="history-pagination">
<span class="pagination-info">Showing <?= (int) $upFrom ?>—<?= (int) $upTo ?> of <?= (int) $upcomingTotal ?> upcoming appointment<?= $upcomingTotal === 1 ? '' : 's' ?></span>
<nav class="page-notation" aria-label="Upcoming appointment pages">
<?php if ($upcomingPage > 1): ?>
<a href="appointments.php?<?= $keptQs ?>upage=<?= (int) ($upcomingPage - 1) ?>#upcoming" class="page-link-btn" aria-label="Previous page"><i class="bi bi-chevron-left"></i></a>
<?php endif; ?>
<?php for ($up = $upStart; $up <= $upEnd; $up++): ?>
<a href="appointments.php?<?= $keptQs ?>upage=<?= (int) $up ?>#upcoming" class="page-link-btn <?= $up === $upcomingPage ? 'active' : '' ?>"><?= (int) $up ?></a>
<?php endfor; ?>
<?php if ($upcomingPage < $upcomingTotalPages): ?>
<a href="appointments.php?<?= $keptQs ?>upage=<?= (int) ($upcomingPage + 1) ?>#upcoming" class="page-link-btn" aria-label="Next page"><i class="bi bi-chevron-right"></i></a>
<?php endif; ?>
</nav>
</div>
<?php elseif ($upcomingTotal > 0): ?>
<div class="history-pagination">
<span class="pagination-info">Showing 1—<?= (int) $upcomingTotal ?> of <?= (int) $upcomingTotal ?> upcoming appointment<?= $upcomingTotal === 1 ? '' : 's' ?></span>
</div>
<?php endif; ?>

<?php endif; ?>

</div>

</div>

</div>

</div>

<?php if ($showPendingSection): ?>
<div class="card patient-card appt-card" id="pending" style="scroll-margin-top: 110px;">
<h2 class="appt-card-header" id="heading-pending">
<button class="appt-card-toggle collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-pending" aria-expanded="false" aria-controls="collapse-pending">
    <span class="appt-acc-head">
        <span>
            <span class="appt-acc-title"><i class="bi bi-hourglass-split"></i> Pending Appointments <span class="appt-count-badge"><?= (int) $pendingCount ?></span></span>
            <span class="appt-acc-sub">New requests waiting for confirmation</span>
        </span>
        <i class="bi bi-chevron-down appt-acc-chevron"></i>
    </span>
</button>
</h2>
<div id="collapse-pending" class="collapse appt-card-collapse">
<div class="appt-card-body">
<div class="appointments-filter-bar">
<form method="GET" action="appointments.php#pending" class="row g-2 mb-0">

    <?php if ($historyStatus !== ''): ?>
    <input type="hidden" name="hstatus" value="<?= e($historyStatus) ?>">
    <?php endif; ?>

    <div class="col-md-5">
        <input
            type="search"
            name="search"
            value="<?= e($search) ?>"
            class="form-control"
            placeholder="Search patient, number, or service">
    </div>

    <div class="col-md-3">
        <select name="dentist_id" class="form-select">
            <option value="0">All Dentists</option>
            <?php foreach ($dentists as $dentist): ?>
                <option value="<?= (int) $dentist['id'] ?>" <?= $dentistFilter === (int) $dentist['id'] ? 'selected' : '' ?>>
                    <?= e($dentist['dentist_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-auto">
<button class="btn btn-outline-primary" data-tip="Search">
    <i class="bi bi-search"></i> Search
</button>
    </div>

    <?php if ($search !== '' || $dentistFilter > 0): ?>
        <div class="col-auto">
            <a href="appointments.php<?= $historyStatus !== '' ? '?hstatus=' . urlencode($historyStatus) . '#pending' : '#pending' ?>" class="btn btn-light">Clear</a>
        </div>
    <?php endif; ?>

</form>
</div>

<div class="card-body" style="padding:0;">

<div class="table-responsive">

<table class="table patient-table">

<thead>

<tr>

<th>Patient</th>
<th>Dentist</th>
<th>Date of Appointment</th>
<th>Time of Appointment</th>
<th>Service</th>
<th>Status</th>
<th>Action</th>

</tr>

</thead>

<tbody>

<?php if (empty($pendingAppointments)): ?>
<tr>
<td colspan="7" class="text-center py-4">
<div class="empty-state">
<div class="empty-icon"><i class="bi bi-calendar-x"></i></div>
<strong>No pending appointments</strong>
<span>New patient requests will appear here.</span>
</div>
</td>
</tr>
<?php endif; ?>

<?php foreach ($pendingAppointments as $a): ?>

<tr id="appt-<?= (int)$a['id'] ?>" class="<?= ((int)($_GET['view'] ?? 0) === (int)$a['id']) ? 'highlight-row' : '' ?>">

<td>

<strong>
<?= e($a['patient_name']) ?>
</strong>

<br>

<small>
<?= e($a['patient_number']) ?>
</small>

</td>

<td>
<?= e($a['dentist_name']) ?>
</td>

<td>
<?= e($a['appointment_date']) ?>
</td>

<td>

<?= date(
    "h:i A",
    strtotime($a['start_time'])
) ?>

-

<?= date(
    "h:i A",
    strtotime($a['end_time'])
) ?>

</td>

<td>
<?= e($a['service_type']) ?>
</td>

<td>

<?php
$statusClass = strtolower(str_replace(' ', '-', $a['status'] ?? ''));
$statusClass = $statusClass === 'no-show' ? 'no-show' : $statusClass;
?>

<span class="status-badge status-<?= $statusClass ?>">
<?= e($a['status']) ?>
</span>

</td>

<td>

<form method="POST"
class="d-inline">
<?= csrfField() ?>

<input
type="hidden"
name="action"
value="status">

<input
type="hidden"
name="id"
value="<?= $a['id'] ?>">

<input type="hidden" name="tab" value="pending">

<select
name="status"
onchange="this.form.submit()"
class="form-select form-select-sm">

<?php

$statuses = [
    'Pending',
    'Confirmed',
    'Completed',
    'Cancelled'
];

?>

<?php foreach ($statuses as $status): ?>

<option
value="<?= $status ?>"
<?= $a['status'] === $status
    ? 'selected'
    : '' ?>>

<?= $status ?>

</option>

<?php endforeach; ?>

</select>

</form>

<a
href="appointments.php?<?= $keptQs ?>view=<?= (int) $a['id'] ?>#appt-<?= (int) $a['id'] ?>"
class="btn btn-sm btn-outline-primary">
    <i class="bi bi-eye me-1"></i> View
</a>

<form method="POST" class="d-inline" title="Send Gmail reminder">
<?= csrfField() ?>
<input type="hidden" name="action" value="remind">
<input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
<input type="hidden" name="tab" value="pending">
<button type="submit" class="btn btn-sm btn-outline-success" data-tip="Send Gmail Reminder">
    <i class="bi bi-envelope"></i>
</button>
</form>

<?php if (empty($a['rescheduled_at'])): ?>
<button
type="button"
class="btn btn-sm btn-outline-secondary reschedule-button"
data-tip="Reschedule"
data-id="<?= (int) $a['id'] ?>"
data-date="<?= e($a['appointment_date']) ?>"
data-start="<?= e(substr($a['start_time'], 0, 5)) ?>"
data-end="<?= e(substr($a['end_time'], 0, 5)) ?>"
data-section="pending"
data-bs-toggle="modal"
data-bs-target="#rescheduleAppointment">
    <i class="bi bi-calendar2-week"></i>
</button>
<?php else: ?>
<span class="badge bg-warning text-dark" data-tip="Already rescheduled on <?= e(date('M d, Y h:i A', strtotime($a['rescheduled_at']))) ?>" style="font-size:11px;font-weight:700;padding:6px 10px;border-radius:8px;">
    <i class="bi bi-calendar2-week me-1"></i>Rescheduled
</span>
<?php endif; ?>

<a
href="no_show.php?appointment_id=<?= (int) $a['id'] ?>"
class="btn btn-sm btn-dark"
title="No-Show"
data-tip="Mark as No-Show">

No-Show

</a>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

</div>

</div>

</div>
<?php endif; ?>

<!-- APPOINTMENT HISTORY (ilalim, may pagination) -->

<div class="card patient-card appt-card" id="history" style="scroll-margin-top: 110px;<?= ($initialTab !== 'history' && $initialTab !== 'all') ? ' display: none;' : '' ?>">
<h2 class="appt-card-header" id="heading-history">
<button class="appt-card-toggle<?= $initialTab === 'history' ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-history" aria-expanded="<?= $initialTab === 'history' ? 'true' : 'false' ?>" aria-controls="collapse-history">
    <span class="appt-acc-head">
        <span>
            <span class="appt-acc-title"><i class="bi bi-clock-history"></i> Appointment History <span class="appt-count-badge"><?= (int) $historyTotal ?></span></span>
            <span class="appt-acc-sub">Past &amp; finished appointments (Completed, No-Show, Cancelled)</span>
        </span>
        <i class="bi bi-chevron-down appt-acc-chevron"></i>
    </span>
</button>
</h2>
<div id="collapse-history" class="collapse<?= $initialTab === 'history' ? ' show' : '' ?> appt-card-collapse">
<div class="appt-card-body">
<div class="appointments-filter-bar">
    <!-- Quick Status Filter Pills -->
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <span class="small fw-bold text-muted me-1"><i class="bi bi-funnel me-1"></i>Status:</span>
        <a href="appointments.php?<?= http_build_query(array_filter(['search' => $search !== '' ? $search : null, 'dentist_id' => $dentistFilter > 0 ? $dentistFilter : null, 'hsearch' => $historyNameSearch !== '' ? $historyNameSearch : null, 'tab' => 'history'])) ?>#history" 
           class="btn btn-sm <?= $historyStatus === '' ? 'btn-primary' : 'btn-outline-secondary' ?>" 
           style="border-radius:20px;font-weight:700;padding:5px 13px;<?= $historyStatus === '' ? 'background:linear-gradient(135deg,#149A72,#0A4A41) !important;color:#fff !important;border:none !important;' : 'background:#fff;border-color:#D1E7DD;color:#0E6B5E;' ?>">
            All <span class="badge ms-1" style="background:<?= $historyStatus === '' ? 'rgba(255,255,255,.25)' : '#EAF7F0' ?>;color:<?= $historyStatus === '' ? '#fff' : '#0E6B5E' ?>;border-radius:10px;font-size:11px;"><?= (int)($historyCounts['count_all'] ?? $historyTotal) ?></span>
        </a>
        <a href="appointments.php?<?= http_build_query(array_filter(['search' => $search !== '' ? $search : null, 'dentist_id' => $dentistFilter > 0 ? $dentistFilter : null, 'hsearch' => $historyNameSearch !== '' ? $historyNameSearch : null, 'hstatus' => 'Completed', 'tab' => 'history'])) ?>#history" 
           class="btn btn-sm <?= $historyStatus === 'Completed' ? 'btn-primary' : 'btn-outline-secondary' ?>" 
           style="border-radius:20px;font-weight:700;padding:5px 13px;<?= $historyStatus === 'Completed' ? 'background:linear-gradient(135deg,#149A72,#0A4A41) !important;color:#fff !important;border:none !important;' : 'background:#fff;border-color:#D1E7DD;color:#157ca0;' ?>">
            <i class="bi bi-check-circle-fill me-1" style="color:<?= $historyStatus === 'Completed' ? '#A7F3D0' : '#157ca0' ?>;"></i>Completed <span class="badge ms-1" style="background:<?= $historyStatus === 'Completed' ? 'rgba(255,255,255,.25)' : '#e6f4fb' ?>;color:<?= $historyStatus === 'Completed' ? '#fff' : '#157ca0' ?>;border-radius:10px;font-size:11px;"><?= (int)($historyCounts['count_completed'] ?? 0) ?></span>
        </a>
        <a href="appointments.php?<?= http_build_query(array_filter(['search' => $search !== '' ? $search : null, 'dentist_id' => $dentistFilter > 0 ? $dentistFilter : null, 'hsearch' => $historyNameSearch !== '' ? $historyNameSearch : null, 'hstatus' => 'No-Show', 'tab' => 'history'])) ?>#history" 
           class="btn btn-sm <?= $historyStatus === 'No-Show' ? 'btn-primary' : 'btn-outline-secondary' ?>" 
           style="border-radius:20px;font-weight:700;padding:5px 13px;<?= $historyStatus === 'No-Show' ? 'background:linear-gradient(135deg,#149A72,#0A4A41) !important;color:#fff !important;border:none !important;' : 'background:#fff;border-color:#D1E7DD;color:#8b4141;' ?>">
            <i class="bi bi-person-x-fill me-1" style="color:<?= $historyStatus === 'No-Show' ? '#fca5a5' : '#8b4141' ?>;"></i>No-Show <span class="badge ms-1" style="background:<?= $historyStatus === 'No-Show' ? 'rgba(255,255,255,.25)' : '#f1e8e8' ?>;color:<?= $historyStatus === 'No-Show' ? '#fff' : '#8b4141' ?>;border-radius:10px;font-size:11px;"><?= (int)($historyCounts['count_noshow'] ?? 0) ?></span>
        </a>
        <a href="appointments.php?<?= http_build_query(array_filter(['search' => $search !== '' ? $search : null, 'dentist_id' => $dentistFilter > 0 ? $dentistFilter : null, 'hsearch' => $historyNameSearch !== '' ? $historyNameSearch : null, 'hstatus' => 'Cancelled', 'tab' => 'history'])) ?>#history" 
           class="btn btn-sm <?= $historyStatus === 'Cancelled' ? 'btn-primary' : 'btn-outline-secondary' ?>" 
           style="border-radius:20px;font-weight:700;padding:5px 13px;<?= $historyStatus === 'Cancelled' ? 'background:linear-gradient(135deg,#149A72,#0A4A41) !important;color:#fff !important;border:none !important;' : 'background:#fff;border-color:#D1E7DD;color:#b43b3b;' ?>">
            <i class="bi bi-x-circle-fill me-1" style="color:<?= $historyStatus === 'Cancelled' ? '#fca5a5' : '#b43b3b' ?>;"></i>Cancelled <span class="badge ms-1" style="background:<?= $historyStatus === 'Cancelled' ? 'rgba(255,255,255,.25)' : '#fdecec' ?>;color:<?= $historyStatus === 'Cancelled' ? '#fff' : '#b43b3b' ?>;border-radius:10px;font-size:11px;"><?= (int)($historyCounts['count_cancelled'] ?? 0) ?></span>
        </a>
    </div>

    <form method="GET" action="appointments.php#history" class="row g-2 mb-0" onsubmit="this.action='appointments.php?tab=history#history';">
        <?php if ($search !== ''): ?>
        <input type="hidden" name="search" value="<?= e($search) ?>">
        <?php endif; ?>

        <?php if ($dentistFilter > 0): ?>
        <input type="hidden" name="dentist_id" value="<?= (int) $dentistFilter ?>">
        <?php endif; ?>

        <?php if ($historyStatus !== ''): ?>
        <input type="hidden" name="hstatus" value="<?= e($historyStatus) ?>">
        <?php endif; ?>

        <input type="hidden" name="tab" value="history">

        <div class="col-md-6 col-lg-5">
            <div class="input-group">
                <span class="input-group-text" style="background:#F6FBF8;border:1px solid #D1E7DD;border-right:none;border-radius:10px 0 0 10px;color:#0E6B5E;"><i class="bi bi-search"></i></span>
                <input type="search" name="hsearch" value="<?= e($historyNameSearch) ?>" class="form-control" placeholder="Search patient name..." style="border-left:none;border-radius:0 10px 10px 0 !important;">
            </div>
        </div>

        <?php if ($historyNameSearch !== ''): ?>
            <div class="col-auto">
                <a href="appointments.php?<?= http_build_query(array_filter(['search' => $search !== '' ? $search : null, 'dentist_id' => $dentistFilter > 0 ? $dentistFilter : null, 'hstatus' => $historyStatus !== '' ? $historyStatus : null, 'tab' => 'history'])) ?>#history" class="btn btn-light" style="border:1px solid #D1E7DD;border-radius:10px;font-weight:600;color:#57727d;padding:9px 16px;"><i class="bi bi-x-circle me-1"></i>Clear Search</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<div class="card-body" style="padding:0;">

<div class="table-responsive">

<table class="table patient-table">

<thead>

<tr>

<th>#</th>
<th>Patient name</th>
<th>Time of appointment</th>
<th>Dental service</th>
<th>Assigned dentist</th>
<th>Status</th>
<th>Action</th>

</tr>

</thead>

<tbody>

<?php if (empty($historyAppointments)): ?>

<tr>
<td colspan="7" class="text-center py-4">
<div class="empty-state">
<div class="empty-icon"><i class="bi bi-clock-history"></i></div>
<strong>No appointment history yet</strong>
<span>Finished appointments will appear here.</span>
</div>
</td>
</tr>

<?php endif; ?>

<?php foreach ($historyAppointments as $idx => $a): ?>

<tr id="appt-<?= (int)$a['id'] ?>" class="<?= ((int)($_GET['view'] ?? 0) === (int)$a['id']) ? 'highlight-row' : '' ?>">

<td><?= (int) $historyOffset + $idx + 1 ?></td>

<td>

<strong>
<?= e($a['patient_name']) ?>
</strong>

<br>

<small>
<?= e($a['patient_number']) ?>
</small>

</td>

<td style="white-space:nowrap;">
<strong><?= e(date('M d, Y', strtotime($a['appointment_date']))) ?></strong>
<br>
<small style="color:#3F525B; font-size:12.5px;">
<?= e(date("h:i A", strtotime($a['start_time']))) ?>
-
<?= e(date("h:i A", strtotime($a['end_time']))) ?>
</small>
</td>

<td>
<?= e($a['service_type']) ?>
</td>

<td>
<?= e($a['dentist_name']) ?>
</td>

<td>

<?php
$statusClass = strtolower(str_replace(' ', '-', $a['status'] ?? ''));
$statusClass = $statusClass === 'no-show' ? 'no-show' : $statusClass;
?>

<span class="status-badge status-<?= $statusClass ?>">
<?= e($a['status']) ?>
</span>

</td>

<td>

<button
type="button"
class="btn btn-sm btn-outline-primary history-view-btn"
data-tip="View Details"
data-patient="<?= e(trim(($a['patient_name'] ?? '') . '')) ?>"
data-dentist="<?= e($a['dentist_name'] ?? '') ?>"
data-dentist-id="<?= (int)($a['dentist_id'] ?? 0) ?>"
data-date="<?= e($a['appointment_date'] ?? '') ?>"
data-start="<?= e(substr($a['start_time'] ?? '', 0, 5)) ?>"
data-end="<?= e(substr($a['end_time'] ?? '', 0, 5)) ?>"
data-service="<?= e($a['service_type'] ?? '') ?>"
data-booked="<?= e(!empty($a['created_at']) ? date('M d, Y h:i A', strtotime($a['created_at'])) : '-') ?>"
data-status="<?= e($a['status'] ?? '') ?>"
data-confirmed="<?= e(!empty($a['confirmed_at']) ? date('M d, Y h:i A', strtotime($a['confirmed_at'])) : '') ?>"
data-cancelled="<?= e(!empty($a['cancelled_at']) ? date('M d, Y h:i A', strtotime($a['cancelled_at'])) : ($a['status'] === 'Cancelled' && !empty($a['created_at']) ? date('M d, Y h:i A', strtotime($a['created_at'])) : '')) ?>"
data-checkedin="<?= e(!empty($a['checked_in_at']) ? date('M d, Y h:i A', strtotime($a['checked_in_at'])) : '') ?>"
data-serving="<?= e(!empty($a['serving_at']) ? date('M d, Y h:i A', strtotime($a['serving_at'])) : '') ?>"
data-completed="<?= e(!empty($a['completed_at']) ? date('M d, Y h:i A', strtotime($a['completed_at'])) : '') ?>"
data-rescheduled="<?= e(!empty($a['rescheduled_at']) ? date('M d, Y h:i A', strtotime($a['rescheduled_at'])) : '') ?>"
title="View details">
    <i class="bi bi-eye me-1"></i> View
</button>
<?php if ($a['status'] === 'Pending' && empty($a['rescheduled_at'])): ?>
<button
type="button"
class="btn btn-sm btn-outline-warning reschedule-button ms-1"
data-bs-toggle="modal"
data-bs-target="#rescheduleModal"
data-id="<?= (int)$a['id'] ?>"
data-date="<?= e($a['appointment_date']) ?>"
data-start="<?= e(substr($a['start_time'], 0, 5)) ?>"
data-end="<?= e(substr($a['end_time'], 0, 5)) ?>"
data-section="history"
data-tip="Reschedule"
title="Reschedule appointment">
    <i class="bi bi-calendar2-week me-1"></i> Reschedule
</button>
<?php endif; ?>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php if ($historyTotalPages > 1): ?>
<?php
$histFrom = $historyTotal > 0 ? $historyOffset + 1 : 0;
$histTo = min($historyOffset + $historyPerPage, $historyTotal);
$histStart = max(1, min($historyPage - 3, $historyTotalPages - 6));
$histEnd = min($historyTotalPages, $histStart + 6);
$histStart = max(1, $histEnd - 6);
?>
<div class="history-pagination">
<span class="pagination-info">Showing <?= (int) $histFrom ?>—<?= (int) $histTo ?> of <?= (int) $historyTotal ?></span>
<nav class="page-notation" aria-label="Appointment history pages">
<?php if ($historyPage > 1): ?>
<a href="appointments.php?<?= $keptQs ?>hpage=<?= (int) ($historyPage - 1) ?>#history" class="page-link-btn" aria-label="Previous page"><i class="bi bi-chevron-left"></i></a>
<?php endif; ?>
<?php for ($hp = $histStart; $hp <= $histEnd; $hp++): ?>
<a href="appointments.php?<?= $keptQs ?>hpage=<?= (int) $hp ?>#history" class="page-link-btn <?= $hp === $historyPage ? 'active' : '' ?>"><?= (int) $hp ?></a>
<?php endfor; ?>
<?php if ($historyPage < $historyTotalPages): ?>
<a href="appointments.php?<?= $keptQs ?>hpage=<?= (int) ($historyPage + 1) ?>#history" class="page-link-btn" aria-label="Next page"><i class="bi bi-chevron-right"></i></a>
<?php endif; ?>
</nav>
</div>
<?php elseif ($historyTotal > 0): ?>
<div class="history-pagination">
<span class="pagination-info">Showing <?= (int) $historyTotal > 0 ? $historyOffset + 1 : 0 ?>—<?= (int) min($historyOffset + $historyPerPage, $historyTotal) ?> of <?= (int) $historyTotal ?></span>
</div>
<?php endif; ?>

</div>

</div>

</div>

</div>

<!-- RESCHEDULE APPOINTMENT -->

<div
class="modal fade"
id="rescheduleAppointment"
tabindex="-1">

<div class="modal-dialog">

<div class="modal-content">

<form method="POST">
<?= csrfField() ?>

<input type="hidden" name="action" value="reschedule">
<input type="hidden" name="id" id="rescheduleId">
<input type="hidden" name="tab" id="rescheduleTab" value="pending">

<div class="modal-header">
    <h5 class="modal-title">Reschedule Appointment</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" data-tip="Close"></button>
</div>

<div class="modal-body">

    <label class="form-label">Date</label>
    <input type="date" name="appointment_date" id="rescheduleDate" class="form-control mb-3" required>

    <label class="form-label">Start Time</label>
    <input type="time" name="start_time" id="rescheduleStart" class="form-control mb-3" required>

    <label class="form-label">End Time</label>
    <input type="time" name="end_time" id="rescheduleEnd" class="form-control" required>

</div>

<div class="modal-footer">
    <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-tip="Cancel">Cancel</button>
    <button class="btn btn-primary" data-tip="Save Schedule">Save Schedule</button>
</div>

</form>

</div>

</div>

</div>

<!-- UPCOMING VIEW POPUP: vertical list, label sa taas at value sa ibaba -->
<style>
.uv-row{padding:13px 2px;border-bottom:1px dashed #C9D9D1}
.uv-row:first-child{padding-top:2px}
.uv-row:last-child{border-bottom:none;padding-bottom:2px}
.uv-label{
    font-family:"Inter",system-ui,sans-serif;
    font-size:10.5px;
    font-weight:800;
    color:#1F3540;
    text-transform:uppercase;
    letter-spacing:.05em;
    margin-bottom:3px;
}
.uv-value{
    font-family:"Inter","DM Sans",system-ui,sans-serif;
    font-size:13.5px;
    font-weight:600;
    color:#4d6873;
    line-height:1.5;
    word-break:break-word;
}
</style>

<div
class="modal fade"
id="upcomingViewModal"
tabindex="-1">

<div class="modal-dialog modal-dialog-centered">

<div class="modal-content" style="border-radius:16px;overflow:visible;">

<div class="modal-header" style="background:linear-gradient(135deg,#149A72,#0A4A41) !important;border-bottom:none !important;padding:16px 22px;">
    <h5 class="modal-title" style="color:#ffffff !important;font-family:'Plus Jakarta Sans','Inter',system-ui,sans-serif;font-size:16px;font-weight:800;">Appointment Details</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" data-tip="Close"></button>
</div>

<div class="modal-body" style="padding:20px 22px;">

    <div class="uv-row">
        <div class="uv-label">Patient name</div>
        <div class="uv-value" id="upcomingViewPatient">—</div>
    </div>

    <div class="uv-row">
        <div class="uv-label">Dental service</div>
        <div class="uv-value" id="upcomingViewService">—</div>
    </div>

    <div class="uv-row">
        <div class="uv-label">Assigned dentist</div>
        <div class="uv-value" id="upcomingViewDentist">—</div>
    </div>

    <div class="uv-row">
        <div class="uv-label">Date and time of appointment</div>
        <div class="uv-value" id="upcomingViewTime">—</div>
    </div>

    <div class="uv-row">
        <div class="uv-label">Booked on</div>
        <div class="uv-value" id="upcomingViewBooked">—</div>
    </div>

</div>

<div class="modal-footer" style="background:#F6FBF8;border-top:1px solid #E6F2EC;padding:13px 22px;">
    <button type="button" class="btn btn-light" data-bs-dismiss="modal" style="border:1px solid #D1E7DD;border-radius:10px;font-weight:700;color:#57727d;" data-tip="Close">Close</button>
</div>

</div>

</div>

</div>

<script>
/* Upcoming View popup: vertical list, label sa taas at value sa ibaba */
document.querySelectorAll('.upcoming-view-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
        var val = function(v){ return (v && v.trim() !== '') ? v : '—'; };

        document.getElementById('upcomingViewPatient').textContent = val(btn.dataset.patient);
        document.getElementById('upcomingViewService').textContent = val(btn.dataset.service);
        document.getElementById('upcomingViewDentist').textContent = val(btn.dataset.dentist);
        document.getElementById('upcomingViewTime').textContent = val(btn.dataset.time);
        document.getElementById('upcomingViewBooked').textContent = val(btn.dataset.booked);
        new bootstrap.Modal(document.getElementById('upcomingViewModal')).show();
    });
});
</script>

<!-- HISTORY VIEW POPUP: details + status timeline -->
<div
class="modal fade"
id="historyViewModal"
tabindex="-1">

<div class="modal-dialog modal-lg modal-dialog-centered">

<div class="modal-content" style="border-radius:16px;overflow:visible;">

<div class="modal-header" style="background:linear-gradient(135deg,#149A72,#0A4A41) !important;border-bottom:none !important;">
    <h5 class="modal-title" style="color:#ffffff !important;font-weight:800;"><i class="bi bi-eye-fill"></i> Appointment Details</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" data-tip="Close"></button>
</div>

<div class="modal-body" style="padding:20px 22px;">
    <div class="d-flex align-items-center gap-3 p-3 mb-3" style="background:linear-gradient(135deg,#EAF7F0,#F7FDFB);border:1px solid #C9E9DA;border-radius:14px;">
        <span id="historyViewAvatar" style="display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#0E6B5E,#0A4A41);color:#fff;font-weight:800;font-size:19px;flex-shrink:0;">P</span>
        <div class="min-w-0">
            <div id="historyViewPatient" style="font-weight:800;color:#143f51;font-size:16.5px;line-height:1.25;"></div>
            <div><span id="historyViewStatusBadge" class="status-badge" style="margin-top:5px;"></span></div>
        </div>
    </div>
    <div class="row g-2 mb-3">
        <div class="col-6 d-flex align-items-center gap-2 p-2" style="background:#F7FDFB;border:1px solid #E6F2EC;border-radius:11px;"><i class="bi bi-person-badge-fill" style="color:#0E6B5E;font-size:17px;"></i><div><small class="d-block text-muted" style="font-size:10.5px;font-weight:700;">ASSIGNED DENTIST</small><span id="historyViewDentist" style="font-weight:700;color:#143f51;font-size:13px;"></span></div></div>
        <div class="col-6 d-flex align-items-center gap-2 p-2" style="background:#F7FDFB;border:1px solid #E6F2EC;border-radius:11px;"><i class="bi bi-calendar-event-fill" style="color:#0E6B5E;font-size:17px;"></i><div><small class="d-block text-muted" style="font-size:10.5px;font-weight:700;">DATE AND TIME OF APPOINTMENT</small><span id="historyViewDateTime" style="font-weight:700;color:#143f51;font-size:13px;"></span></div></div>
        <div class="col-6 d-flex align-items-center gap-2 p-2" style="background:#F7FDFB;border:1px solid #E6F2EC;border-radius:11px;"><i class="bi bi-tooth-fill" style="color:#0E6B5E;font-size:17px;"></i><div><small class="d-block text-muted" style="font-size:10.5px;font-weight:700;">DENTAL SERVICE</small><span id="historyViewService" style="font-weight:700;color:#143f51;font-size:13px;"></span></div></div>
        <div class="col-6 d-flex align-items-center gap-2 p-2" style="background:#F7FDFB;border:1px solid #E6F2EC;border-radius:11px;"><i class="bi bi-calendar-check-fill" style="color:#0E6B5E;font-size:17px;"></i><div><small class="d-block text-muted" style="font-size:10.5px;font-weight:700;">BOOKED ON</small><span id="historyViewBooked" style="font-weight:700;color:#143f51;font-size:13px;"></span></div></div>
    </div>
    <div class="p-3" style="background:#fff;border:1px solid #C9E9DA;border-radius:14px;">
        <div style="font-weight:800;color:#143f51;font-size:13.5px;margin-bottom:10px;"><i class="bi bi-clock-history me-1" style="color:#0E6B5E;"></i>Status Timeline</div>
        <div id="historyViewTimeline" style="display:flex;flex-direction:column;gap:0;"></div>
    </div>
</div>
<div class="modal-footer" style="background:#F1FAF6;border-top:1px solid #CBECE0;border-radius:0 0 16px 16px;padding:13px 22px;">
    <button type="button" class="btn btn-light" data-bs-dismiss="modal" style="border:1px solid #D1E7DD;border-radius:10px;font-weight:700;" data-tip="Close">Close</button>
</div>

</div>

</div>

</div>

<script>
/* History View popup: details + timeline, hindi na tumatalon sa itaas */
function fmtHistDate(ds) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ds || '');
    if (!m) return (ds || '—');
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10) + ', ' + m[1];
}
function fmtHistTime(t) {
    var m = /^(\d{1,2}):(\d{2})/.exec(t || '');
    if (!m) return '';
    var h = parseInt(m[1], 10);
    var ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + m[2] + ' ' + ap;
}
document.querySelectorAll('.history-view-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
        var patient = btn.dataset.patient || '';
        document.getElementById('historyViewPatient').textContent = patient;
        var initials = patient.trim().split(/\s+/).map(function(w){ return w.charAt(0); }).join('').substring(0,2).toUpperCase() || 'P';
        document.getElementById('historyViewAvatar').textContent = initials;
        var status = btn.dataset.status || '';
        var badge = document.getElementById('historyViewStatusBadge');
        badge.textContent = status;
        badge.className = 'status-badge status-' + status.toLowerCase().replace(/ /g, '-');
        document.getElementById('historyViewDentist').textContent = btn.dataset.dentist || '—';
        var apptDateTime = fmtHistDate(btn.dataset.date);
        var apptStart = fmtHistTime(btn.dataset.start);
        var apptEnd = fmtHistTime(btn.dataset.end);
        if (apptStart !== '') {
            apptDateTime += ' · ' + apptStart + (apptEnd !== '' ? ' - ' + apptEnd : '');
        }
        document.getElementById('historyViewDateTime').textContent = apptDateTime;
        document.getElementById('historyViewBooked').textContent = btn.dataset.booked || '—';
        document.getElementById('historyViewService').textContent = btn.dataset.service || '—';
        var tl = [
            ['bi-clock-fill', 'Booked (Pending)', btn.dataset.booked !== '-' ? btn.dataset.booked : ''],
            ['bi-check2-all', 'Confirmed', btn.dataset.confirmed],
            ['bi-x-circle-fill', 'Cancelled', btn.dataset.cancelled],
            ['bi-box-arrow-in-right', 'Checked In', btn.dataset.checkedin],
            ['bi-play-fill', 'Serving started', btn.dataset.serving],
            ['bi-check-circle-fill', 'Completed', btn.dataset.completed],
            ['bi-calendar2-week-fill', 'Rescheduled', btn.dataset.rescheduled]
        ];
        var wrap = document.getElementById('historyViewTimeline');
        wrap.innerHTML = '';
        tl.forEach(function(row, idx){
            var done = !!row[2];
            var item = document.createElement('div');
            item.style.cssText = 'display:flex;gap:11px;position:relative;padding-bottom:' + (idx === tl.length - 1 ? '0' : '14px');
            item.innerHTML =
                '<div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0;">' +
                    '<span style="display:inline-flex;align-items:center;justify-content:center;width:27px;height:27px;border-radius:50%;background:' + (done ? 'linear-gradient(135deg,#149A72,#0A4A41)' : '#E6EDEF') + ';color:' + (done ? '#fff' : '#9AAFB8') + ';font-size:13px;"><i class="bi ' + row[0] + '"></i></span>' +
                    (idx === tl.length - 1 ? '' : '<span style="width:2px;flex:1;min-height:8px;background:' + (done ? '#B9E4CF' : '#E6EDEF') + ';border-radius:2px;margin-top:2px;"></span>') +
                '</div>' +
                '<div style="padding-top:3px;"><div style="font-weight:800;color:#143f51;font-size:13px;">' + row[1] + '</div>' +
                '<div style="font-size:12px;color:' + (done ? '#4d6873' : '#9AAFB8') + ';font-weight:' + (done ? '600' : '400') + ';">' + (done ? row[2] : '—') + '</div></div>';
            wrap.appendChild(item);
        });
        new bootstrap.Modal(document.getElementById('historyViewModal')).show();
    });
});
</script>

<!-- STATUS ACTION POPUP: Confirm / Complete / Cancel / Reschedule (Today box) -->
<style>
.status-action-btn{background:#EAF7F0 !important;border:1.5px solid #0E6B5E !important;color:#0E6B5E !important;font-weight:800 !important;border-radius:999px !important;padding:6px 14px !important;white-space:nowrap}
.status-action-btn:hover{background:#0E6B5E !important;color:#fff !important}
.status-action-grid{display:grid;grid-template-columns:1fr;gap:10px}
.status-action-grid .sa-btn{border:none;border-radius:12px;padding:16px 10px;font-weight:800;font-size:14px;letter-spacing:.4px;color:#fff;cursor:pointer;display:flex;flex-direction:row;align-items:center;justify-content:center;gap:10px;transition:.15s;width:100%}
.status-action-grid .sa-btn i{font-size:20px}
.status-action-grid .sa-btn:hover{filter:brightness(.93);transform:translateY(-2px)}
.sa-confirm{background:linear-gradient(135deg,#10B981,#047857)}
.sa-complete{background:linear-gradient(135deg,#3B82F6,#1D4ED8)}
.sa-cancel{background:linear-gradient(135deg,#EF4444,#B91C1C)}
.sa-resched{background:linear-gradient(135deg,#F59E0B,#B45309)}
.sa-checkin{background:linear-gradient(135deg,#149A72,#0A4A41)}
#statusActionReschedForm{background:transparent;border:none;padding:0}
.resched-old{background:#FFFDF4;border:1px solid #E3D3A0;border-radius:12px;padding:12px 14px;margin-bottom:12px}
.resched-old-title{font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#8A6D1B;display:flex;align-items:center;gap:6px;margin-bottom:4px}
.resched-old-patient{font-weight:800;color:#143f51;font-size:14px}
.resched-old-datetime{font-size:12.5px;font-weight:600;color:#57727d;margin-top:2px}
#statusActionModal .modal-content,
#reschedPopupModal .modal-content,
#upcomingViewModal .modal-content,
#historyViewModal .modal-content {
    overflow: visible !important;
}
.status-action-grid [data-tip]:hover::after {
    top: auto !important;
    bottom: calc(100% + 8px) !important;
    left: 50% !important;
    transform: translateX(-50%) !important;
    z-index: 99999 !important;
}
</style>

<div
class="modal fade"
id="statusActionModal"
tabindex="-1">

<div class="modal-dialog modal-dialog-centered">

<div class="modal-content" style="border-radius:16px;overflow:visible;">

<div class="modal-header" style="background:linear-gradient(135deg,#149A72,#0A4A41) !important;border-bottom:none !important;">
    <h5 class="modal-title" style="color:#ffffff !important;font-weight:800;"><i class="bi bi-lightning-charge-fill"></i> Update Status</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" data-tip="Close"></button>
</div>

<div class="modal-body">
    <p class="small mb-2">Patient: <strong id="statusActionPatient"></strong></p>
    <p class="small mb-3 text-muted">Current Status: <strong id="statusActionCurrentStatus">—</strong></p>
    <div id="statusActionNotice" class="alert alert-warning py-2 px-3 small mb-3" style="display:none;border-radius:10px;"></div>
    <div class="status-action-grid">
        <form method="POST" id="statusActionConfirmWrap">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="tab" value="today" class="status-action-tab">
            <input type="hidden" name="id" class="status-action-id" value="">
            <input type="hidden" name="status" value="Confirmed">
            <button type="submit" class="sa-btn sa-confirm" data-tip="Confirm appointment"><i class="bi bi-check2-all"></i>CONFIRM</button>
        </form>
        <form method="POST" id="statusActionCheckinWrap">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="checkin">
            <input type="hidden" name="tab" value="today" class="status-action-tab">
            <input type="hidden" name="id" class="status-action-id" value="">
            <button type="submit" class="sa-btn sa-checkin" data-tip="Check In Patient"><i class="bi bi-box-arrow-in-right"></i>Check In</button>
        </form>
        <form method="POST" id="statusActionCompleteWrap">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="tab" value="today" class="status-action-tab">
            <input type="hidden" name="id" class="status-action-id" value="">
            <input type="hidden" name="status" value="Completed">
            <button type="submit" class="sa-btn sa-complete" data-tip="Complete appointment"><i class="bi bi-check-circle-fill"></i>COMPLETE</button>
        </form>
        <button type="button" class="sa-btn sa-resched" id="statusActionReschedBtn" data-tip="Reschedule appointment"><i class="bi bi-calendar2-week-fill"></i>RESCHEDULE</button>
        <button type="button" class="sa-btn sa-reassign" id="statusActionReassignBtn" data-tip="Reassign to another available dentist" style="display:none;background:linear-gradient(135deg,#0E6B5E,#0A4A41);"><i class="bi bi-arrow-left-right"></i>REASSIGN DENTIST</button>
        <form method="POST" id="statusActionCancelWrap">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="tab" value="today" class="status-action-tab">
            <input type="hidden" name="id" class="status-action-id" value="">
            <input type="hidden" name="status" value="Cancelled">
            <button type="submit" class="sa-btn sa-cancel" data-tip="Cancel appointment"><i class="bi bi-x-circle-fill"></i>CANCEL</button>
        </form>
    </div>
</div>

</div>

</div>

</div>

<!-- DENTIST ABSENT WARNING POPUP -->
<div class="modal fade" id="dentistAbsentWarningModal" tabindex="-1" style="z-index: 10600;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-center p-4" style="border-radius:18px; border:none; box-shadow:0 15px 40px rgba(0,0,0,0.25);">
            <div class="mb-3">
                <div style="width:72px; height:72px; margin:0 auto; background:#FEF3C7; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#D97706; font-size:36px;">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
            </div>
            <h4 class="fw-bold mb-2 text-dark">Cannot Confirm Appointment</h4>
            <div class="p-3 my-3 text-start" style="background:#FFFBEB; border:1px solid #FDE68A; border-radius:12px; font-size:13.5px; color:#92400E;">
                <div class="mb-1"><strong>Doctor:</strong> <span id="warnAbsentDoctorName">—</span> <span class="badge bg-danger ms-1">ABSENT / ON LEAVE</span></div>
                <div class="mb-1"><strong>Reason:</strong> <span id="warnAbsentReason">Emergency Leave / Absence</span></div>
                <div><strong>Patient:</strong> <span id="warnAbsentPatientName">—</span></div>
            </div>
            <p class="text-muted mb-4" style="font-size:14px; line-height:1.5;">
                This appointment cannot be confirmed while the attending doctor is absent or on leave. Please <strong>reassign the appointment</strong> to an available duty dentist first.
            </p>
            <div class="d-flex flex-column gap-2">
                <button type="button" class="btn text-white py-2 px-3 fw-bold" id="btnReassignFromAbsentWarning" style="background:linear-gradient(135deg,#0E6B5E,#0A4A41); border:none; border-radius:10px; box-shadow:0 4px 12px rgba(14,107,94,.25);">
                    <i class="bi bi-arrow-left-right me-1"></i> Reassign Duty Dentist Now
                </button>
                <button type="button" class="btn btn-light py-2" data-bs-dismiss="modal" style="border-radius:10px;">
                    Cancel / Keep Pending
                </button>
            </div>
        </div>
    </div>
</div>

<!-- RESCHEDULE POPUP -->
<div
class="modal fade"
id="reschedPopupModal"
tabindex="-1">

<div class="modal-dialog modal-dialog-centered">

<div class="modal-content" style="border-radius:16px;overflow:visible;">

<div class="modal-header" style="background:linear-gradient(135deg,#149A72,#0A4A41) !important;border-bottom:none !important;">
    <h5 class="modal-title" style="color:#ffffff !important;font-weight:800;"><i class="bi bi-calendar2-week-fill"></i> Reschedule Appointment</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" data-tip="Close"></button>
</div>

<div class="modal-body">
    <form method="POST" id="statusActionReschedForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reschedule">
        <input type="hidden" name="tab" value="today" class="status-action-tab">
        <input type="hidden" name="id" class="status-action-id" value="">
        <div class="resched-old">
            <div class="resched-old-title"><i class="bi bi-clock-history"></i> Old Appointment</div>
            <div class="resched-old-patient" id="statusActionOldPatient">—</div>
            <div class="resched-old-datetime" id="statusActionOldDateTime">—</div>
            <div class="resched-old-datetime" id="statusActionOldDentist">—</div>
            <div class="resched-old-datetime" id="statusActionOldService">—</div>
        </div>
        <label class="form-label">Date</label>
        <input type="date" name="appointment_date" id="statusActionDate" class="form-control mb-3" min="<?= date('Y-m-d') ?>" required>
        <label class="form-label">Start Time <small class="text-muted fw-normal">(8:00 AM – 5:30 PM)</small></label>
        <input type="time" name="start_time" id="statusActionStart" class="form-control mb-3" min="08:00" max="17:30" required>
        <label class="form-label">End Time <small class="text-muted fw-normal">(until 6:00 PM)</small></label>
        <input type="time" name="end_time" id="statusActionEnd" class="form-control" min="08:30" max="18:00" required>
        <button type="submit" class="btn btn-primary w-100 mt-3" data-tip="Save New Schedule">Save New Schedule</button>
    </form>
</div>

</div>

</div>

</div>

<!-- REASSIGN DENTIST POPUP -->
<div class="modal fade" id="reassignPopupModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:visible;">
            <div class="modal-header" style="background:linear-gradient(135deg,#0E6B5E,#0A4A41) !important;border-bottom:none !important;">
                <h5 class="modal-title" style="color:#ffffff !important;font-weight:800;"><i class="bi bi-arrow-left-right me-1"></i> Reassign Dentist</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" data-tip="Close"></button>
            </div>
            <div class="modal-body" style="padding:22px;">
                <form method="POST" id="statusActionReassignForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reassign_dentist">
                    <input type="hidden" name="tab" value="today" class="status-action-tab">
                    <input type="hidden" name="id" class="status-action-id" value="">
                    
                    <div class="resched-old mb-3">
                        <div class="resched-old-title"><i class="bi bi-person-fill"></i> Appointment Summary</div>
                        <div class="resched-old-patient" id="reassignPatientName">—</div>
                        <div class="resched-old-datetime" id="reassignDateTime">—</div>
                        <div class="resched-old-datetime" id="reassignOldDentist">—</div>
                        <div class="resched-old-datetime" id="reassignService">—</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">SELECT NEW ATTENDING DENTIST</label>
                        <select name="new_dentist_id" id="reassignDentistSelect" class="form-select" required>
                            <option value="">— Select an available dentist —</option>
                            <?php foreach ($dentists as $d): ?>
                                <option value="<?= (int)$d['id'] ?>">Dr. <?= e(preg_replace('/^Dr\.?\s+/i', '', $d['dentist_name'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="reassignDentistHelp" class="mt-1">
                            <small class="text-muted" style="font-size:11.5px;">The system automatically verifies that the selected doctor is on-duty and conflict-free.</small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">REASSIGNMENT REASON (INCLUDED IN PATIENT NOTIFICATION)</label>
                        <input type="text" name="reassign_reason" class="form-control" value="emergency medical leave of original dentist" placeholder="e.g. Doctor emergency leave, schedule adjustment">
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" id="reassignSubmitBtn" class="btn text-white fw-bold" style="background:linear-gradient(135deg,#0E6B5E,#0A4A41); border:none; padding:8px 20px; border-radius:8px; box-shadow:0 4px 12px rgba(14,107,94,.25);">
                            <i class="bi bi-check2-circle me-1"></i>Confirm Reassignment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
var DENTIST_ROSTER = <?= json_encode(array_values(array_map(function($d) {
    return [
        'id' => (int)$d['id'],
        'dentist_name' => (string)$d['dentist_name'],
        'rest_day' => ($d['rest_day'] !== null && $d['rest_day'] !== '') ? (int)$d['rest_day'] : null
    ];
}, $dentists)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

var DENTIST_DAY_OFFS = <?= json_encode(array_values(array_map(function($o) {
    return [
        'dentist_id' => (int)$o['dentist_id'],
        'off_date' => (string)$o['off_date'],
        'reason' => (string)($o['reason'] ?? '')
    ];
}, $allDentistDayOffs)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function updateReassignDentistOptions(apptDate, curDentistId) {
    var selectEl = document.getElementById('reassignDentistSelect');
    var helpEl = document.getElementById('reassignDentistHelp');
    var submitBtn = document.getElementById('reassignSubmitBtn');
    if (!selectEl) return;

    selectEl.innerHTML = '';

    var dayOfWeek = -1;
    if (apptDate && /^\d{4}-\d{2}-\d{2}$/.test(apptDate)) {
        var parts = apptDate.split('-');
        var dt = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        dayOfWeek = dt.getDay();
    }

    var available = [];

    (DENTIST_ROSTER || []).forEach(function(d){
        var dId = parseInt(d.id, 10);
        // Exclude current dentist
        if (curDentistId && dId === curDentistId) {
            return;
        }
        // Exclude dentist if regular weekly rest day matches appointment date
        if (d.rest_day !== null && d.rest_day !== undefined && d.rest_day === dayOfWeek) {
            return;
        }
        // Exclude dentist if marked absent / on leave in dentist_day_offs for this date
        var isAbsent = (DENTIST_DAY_OFFS || []).some(function(doff){
            return parseInt(doff.dentist_id, 10) === dId && doff.off_date === apptDate;
        });
        if (isAbsent) {
            return;
        }

        available.push(d);
    });

    if (available.length > 0) {
        var defaultOpt = document.createElement('option');
        defaultOpt.value = '';
        defaultOpt.textContent = '— Select an available dentist (' + available.length + ' on-duty) —';
        selectEl.appendChild(defaultOpt);

        available.forEach(function(d){
            var opt = document.createElement('option');
            opt.value = d.id;
            var cleanName = (d.dentist_name || '').replace(/^Dr\.?\s+/i, '');
            opt.textContent = 'Dr. ' + cleanName;
            selectEl.appendChild(opt);
        });

        if (submitBtn) submitBtn.disabled = false;
        if (helpEl) {
            var dateFmt = fmtOldApptDate(apptDate);
            helpEl.innerHTML = '<small class="text-muted" style="font-size:11.5px;"><i class="bi bi-shield-check text-success me-1"></i>Showing ' + available.length + ' on-duty doctor(s) on <strong>' + (dateFmt || apptDate) + '</strong> (doctors on rest day or absent have been excluded).</small>';
        }
    } else {
        var noOpt = document.createElement('option');
        noOpt.value = '';
        noOpt.textContent = '— No available dentists on this date —';
        selectEl.appendChild(noOpt);

        if (submitBtn) submitBtn.disabled = true;
        if (helpEl) {
            var dateFmt = fmtOldApptDate(apptDate);
            helpEl.innerHTML = '<div class="alert alert-warning py-2 px-3 small mt-1 mb-0 border-0" style="background:#fff3cd;color:#856404;border-radius:6px;"><i class="bi bi-exclamation-triangle-fill me-1"></i> No other dentists are on duty on <strong>' + (dateFmt || apptDate) + '</strong> (all other doctors are either off-duty, absent, or on rest day). Please reschedule the appointment instead.</div>';
        }
    }
}

/* Status action popup: show only contextually valid buttons for current status & clinic hours */
var activeStatusActionBtn = null;
function fmtOldApptDate(ds) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ds || '');
    if (!m) return (ds || '—');
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10) + ', ' + m[1];
}
function fmtOldApptTime(t) {
    var m = /^(\d{1,2}):(\d{2})/.exec(t || '');
    if (!m) return '';
    var h = parseInt(m[1], 10);
    var ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + m[2] + ' ' + ap;
}
document.querySelectorAll('.status-action-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
        activeStatusActionBtn = btn;
        var rawStatus = (btn.dataset.status || 'Pending').trim();
        var st = rawStatus.toLowerCase();
        var apptDate = (btn.dataset.date || '').trim();
        var apptStart = (btn.dataset.start || '').trim();
        var apptEnd = (btn.dataset.end || apptStart).trim();

        var reModalEl = document.getElementById('reassignPopupModal');
        if (reModalEl) {
            reModalEl.dataset.apptDate = apptDate;
            reModalEl.dataset.dentistId = btn.dataset.dentistId || '0';
        }

        document.getElementById('statusActionPatient').textContent = btn.dataset.patient || '';
        var curStEl = document.getElementById('statusActionCurrentStatus');
        if (curStEl) curStEl.textContent = rawStatus;

        var op = document.getElementById('statusActionOldPatient');
        if (op) op.textContent = btn.dataset.patient || '—';
        var odt = document.getElementById('statusActionOldDateTime');
        if (odt) {
            var od = fmtOldApptDate(apptDate);
            var os = fmtOldApptTime(apptStart);
            var oe = fmtOldApptTime(apptEnd);
            odt.textContent = od + (os !== '' ? ' · ' + os + (oe !== '' ? ' - ' + oe : '') : '');
        }
        var odent = document.getElementById('statusActionOldDentist');
        if (odent) odent.textContent = 'Assigned Dentist: ' + (btn.dataset.dentist || '—');
        var osvc = document.getElementById('statusActionOldService');
        if (osvc) osvc.textContent = 'Dental Service: ' + (btn.dataset.service || '—');
        document.querySelectorAll('.status-action-id').forEach(function(inp){ inp.value = btn.dataset.apptId || ''; });
        document.querySelectorAll('.status-action-tab').forEach(function(inp){ inp.value = btn.dataset.tab || 'today'; });

        var now = new Date();
        var todayStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
        var nowHm = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');

        var isToday = (apptDate === todayStr);
        var isPastDate = (apptDate !== '' && apptDate < todayStr);
        var outsideHours = (apptStart !== '' && apptStart < '08:00') || (apptEnd !== '' && apptEnd > '18:00');
        var isSlotPassedToday = isToday && ((apptEnd !== '' && apptEnd <= nowHm) || nowHm >= '18:00');
        var cannotApproveOrCheckin = isPastDate || outsideHours || isSlotPassedToday;

        var confirmWrap = document.getElementById('statusActionConfirmWrap');
        var checkinWrap = document.getElementById('statusActionCheckinWrap');
        var completeWrap = document.getElementById('statusActionCompleteWrap');
        var reschedBtn = document.getElementById('statusActionReschedBtn');
        var reassignBtn = document.getElementById('statusActionReassignBtn');
        var cancelWrap = document.getElementById('statusActionCancelWrap');
        var noticeEl = document.getElementById('statusActionNotice');

        var showConfirm = false;
        var showCheckin = false;
        var showComplete = false;
        var showResched = false;
        var showCancel = false;
        var isDentistAbsent = (btn.dataset.dentistAbsent === '1');
        var showReassign = isDentistAbsent && (st === 'pending' || st === 'confirmed' || st === 'approved');
        var noticeMsg = '';

        if (st === 'pending') {
            if (cannotApproveOrCheckin) {
                noticeMsg = 'This appointment time has already passed or is outside clinic operating hours (8:00 AM – 6:00 PM). Please reschedule or cancel.';
            } else {
                showConfirm = true;
            }
            showResched = true;
            showCancel = true;
        } else if (st === 'confirmed' || st === 'approved') {
            if (isToday) {
                if (cannotApproveOrCheckin) {
                    noticeMsg = 'Clinic hours for this slot have passed. You may reschedule or cancel.';
                } else {
                    showCheckin = true;
                }
            }
            showResched = true;
            showCancel = true;
        } else if (st === 'checked in' || st === 'late' || st === 'serving') {
            showComplete = true;
        } else {
            showResched = true;
        }

        if (btn.dataset.rescheduled === '1') {
            showResched = false;
        }

        if (isDentistAbsent && (st === 'pending' || st === 'confirmed' || st === 'approved')) {
            var absentReason = (btn.dataset.absentReason || '').trim();
            var absentAlert = '⚠️ Assigned dentist is marked ABSENT on this date' + (absentReason !== '' ? ' (' + absentReason + ')' : '') + '. Please reassign this appointment to an available doctor.';
            noticeMsg = noticeMsg !== '' ? noticeMsg + ' ' + absentAlert : absentAlert;
        }

        if (confirmWrap) confirmWrap.style.display = showConfirm ? '' : 'none';
        if (checkinWrap) checkinWrap.style.display = showCheckin ? '' : 'none';
        if (completeWrap) completeWrap.style.display = showComplete ? '' : 'none';
        if (reschedBtn) reschedBtn.style.display = showResched ? '' : 'none';
        if (reassignBtn) reassignBtn.style.display = showReassign ? '' : 'none';
        if (cancelWrap) cancelWrap.style.display = showCancel ? '' : 'none';
        if (noticeEl) {
            noticeEl.innerHTML = noticeMsg;
            noticeEl.style.display = noticeMsg !== '' ? '' : 'none';
        }

        var d = document.getElementById('statusActionDate');
        var s = document.getElementById('statusActionStart');
        var e2 = document.getElementById('statusActionEnd');
        if (d) {
            if (apptDate && apptDate > todayStr) {
                d.value = apptDate;
            } else {
                var tmrw = new Date();
                tmrw.setDate(tmrw.getDate() + 1);
                var tmrwStr = tmrw.getFullYear() + '-' + String(tmrw.getMonth() + 1).padStart(2, '0') + '-' + String(tmrw.getDate()).padStart(2, '0');
                d.value = tmrwStr;
            }
        }
        if (s && apptStart) s.value = apptStart;
        if (e2 && apptEnd) e2.value = apptEnd;
        new bootstrap.Modal(document.getElementById('statusActionModal')).show();
    });
});
document.getElementById('statusActionReschedBtn').addEventListener('click', function(){
    var statusModalEl = document.getElementById('statusActionModal');
    var statusModal = bootstrap.Modal.getInstance(statusModalEl);
    if (statusModal) statusModal.hide();
    var reschedModalEl = document.getElementById('reschedPopupModal');
    if (reschedModalEl) new bootstrap.Modal(reschedModalEl).show();
});
var reassignActionBtn = document.getElementById('statusActionReassignBtn');
if (reassignActionBtn) {
    reassignActionBtn.addEventListener('click', function(){
        var statusModalEl = document.getElementById('statusActionModal');
        var statusModal = bootstrap.Modal.getInstance(statusModalEl);
        if (statusModal) statusModal.hide();
        var reModalEl = document.getElementById('reassignPopupModal');
        if (reModalEl) {
            var patName = document.getElementById('statusActionPatient') ? document.getElementById('statusActionPatient').textContent : '—';
            var oldDent = document.getElementById('statusActionOldDentist') ? document.getElementById('statusActionOldDentist').textContent : '—';
            var oldDate = document.getElementById('statusActionOldDateTime') ? document.getElementById('statusActionOldDateTime').textContent : '—';
            var oldSvc = document.getElementById('statusActionOldService') ? document.getElementById('statusActionOldService').textContent : '—';

            var rp = document.getElementById('reassignPatientName');
            var rd = document.getElementById('reassignDateTime');
            var ro = document.getElementById('reassignOldDentist');
            var rs = document.getElementById('reassignService');

            if (rp) rp.textContent = patName;
            if (rd) rd.textContent = oldDate;
            if (ro) ro.textContent = oldDent;
            if (rs) rs.textContent = oldSvc;

            var targetDate = (reModalEl.dataset.apptDate || '').trim();
            var targetDentId = parseInt(reModalEl.dataset.dentistId || '0', 10);
            updateReassignDentistOptions(targetDate, targetDentId);

            new bootstrap.Modal(reModalEl).show();
        }
    });
}

var statusConfirmForm = document.getElementById('statusActionConfirmWrap');
if (statusConfirmForm) {
    statusConfirmForm.addEventListener('submit', function(e) {
        if (activeStatusActionBtn && activeStatusActionBtn.dataset.dentistAbsent === '1') {
            e.preventDefault();

            // Hide statusActionModal
            var statusModalEl = document.getElementById('statusActionModal');
            var statusModal = bootstrap.Modal.getInstance(statusModalEl);
            if (statusModal) statusModal.hide();

            // Populate warning modal
            var docName = activeStatusActionBtn.dataset.dentist || 'Assigned Doctor';
            docName = docName.replace(/^Dr\.?\s+/i, '');
            var absReason = (activeStatusActionBtn.dataset.absentReason || 'Emergency Leave / Absence').trim();
            var patName = activeStatusActionBtn.dataset.patient || 'Patient';

            var warnDoc = document.getElementById('warnAbsentDoctorName');
            if (warnDoc) warnDoc.textContent = 'Dr. ' + docName;
            var warnRsn = document.getElementById('warnAbsentReason');
            if (warnRsn) warnRsn.textContent = absReason;
            var warnPat = document.getElementById('warnAbsentPatientName');
            if (warnPat) warnPat.textContent = patName;

            var warnModalEl = document.getElementById('dentistAbsentWarningModal');
            if (warnModalEl) {
                new bootstrap.Modal(warnModalEl).show();
            }
            return false;
        }
    });
}

var btnReassignWarning = document.getElementById('btnReassignFromAbsentWarning');
if (btnReassignWarning) {
    btnReassignWarning.addEventListener('click', function() {
        var warnModalEl = document.getElementById('dentistAbsentWarningModal');
        var warnModal = bootstrap.Modal.getInstance(warnModalEl);
        if (warnModal) warnModal.hide();

        var reassignBtn = document.getElementById('statusActionReassignBtn');
        if (reassignBtn) {
            reassignBtn.click();
        }
    });
}
</script>

<!-- ADD APPOINTMENT -->

<div
class="modal fade"
id="addAppointment">

<div class="modal-dialog modal-lg">

<div class="modal-content">

<form method="POST">
<?= csrfField() ?>

<input
type="hidden"
name="action"
value="add">

<div class="modal-header">

<h5>
New Appointment
</h5>

</div>

<div class="modal-body">

<div class="row">

<div class="col-md-6 mb-3">

<label>Patient</label>



<select
name="patient_id"
class="form-select"
required>

<option value="">
Select Patient
</option>

<?php foreach ($patients as $p): ?>

<option value="<?= $p['id'] ?>">

<?= e(
    $p['patient_number'] .
    ' - ' .
    $p['first_name'] .
    ' ' .
    $p['last_name']
) ?>

</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-md-6 mb-3">

<label>Dentist</label>

<select
name="dentist_id"
class="form-select"
required>

<option value="">
Select Dentist
</option>

<?php foreach ($dentists as $d): ?>

<option value="<?= $d['id'] ?>">

<?= e($d['dentist_name']) ?>

</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-md-4 mb-3">

<label>Date</label>

<input
type="date"
name="appointment_date"
class="form-control"
min="<?= date('Y-m-d') ?>"
required>

</div>

<div class="col-md-4 mb-3">

<label>Start Time <small class="text-muted">(8:00 AM – 5:30 PM)</small></label>

<input
type="time"
name="start_time"
class="form-control"
min="08:00"
max="17:30"
required>

</div>

<div class="col-md-4 mb-3">

<label>End Time <small class="text-muted">(until 6:00 PM)</small></label>

<input
type="time"
name="end_time"
class="form-control"
min="08:30"
max="18:00"
required>

</div>

<div class="col-md-12 mb-3">

<label>Service</label>

<select
name="service_type"
class="form-select"
required>

<option>Dental Check-up</option>
<option>Cleaning / Oral Prophylaxis</option>
<option>Tooth Extraction</option>
<option>Dental Filling</option>
<option>Orthodontics</option>
<option>Emergency Dental Care</option>

</select>

</div>

<div class="col-12">

<label>Notes</label>

<textarea
name="notes"
class="form-control"></textarea>

</div>

</div>

</div>

<div class="modal-footer">

<button class="btn btn-primary" data-tip="Save Appointment">

Save Appointment

</button>

</div>

</form>

</div>

</div>

</div>

<script>
/* Maliit na topbar: isinasingit ang logo + pangalan ng clinic sa tabi ng
   page title na galing sa includes.php. Parehong pattern ang ginamit ng
   Patients page (nag-i-inject ng date/clinic time sa .admin-area). */
(function(){
    function addTopbarBrand(){
        var topbar = document.querySelector('.shared-topbar');
        if(!topbar || topbar.querySelector('.appt-topbar-brand')) return;

        var pageTitle = topbar.querySelector('.page-title');
        if(!pageTitle) return;

        var left = document.createElement('div');
        left.className = 'appt-topbar-left';

        var brand = document.createElement('div');
        brand.className = 'appt-topbar-brand';
        brand.innerHTML =
            '<img src="../assets/dental-bg.jpg" alt="Abalos Dental Clinic logo" class="appt-topbar-logo" width="38" height="38">' +
            '<span class="appt-topbar-clinic">' +
                '<strong>Abalos Dental Clinic</strong>' +
                '<span>Admin Portal</span>' +
            '</span>';

        var divider = document.createElement('span');
        divider.className = 'appt-topbar-divider';

        left.appendChild(brand);
        left.appendChild(divider);

        /* Ang .page-title ay nasa loob ng .d-flex wrapper, hindi direct child
           ng header — kaya ilagay ang brand sa parent na pareho ng pageTitle. */
        var anchor = pageTitle.parentNode || topbar;
        anchor.insertBefore(left, pageTitle);
    }

    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', addTopbarBrand);
    }else{
        addTopbarBrand();
    }
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>

<script>
function selectAdminApptCategory(cat, scroll) {
    if (!cat) cat = 'all';
    var todayCard = document.getElementById('today');
    var upcomingCard = document.getElementById('upcoming');
    var historyCard = document.getElementById('history');

    // Update active tab buttons
    document.querySelectorAll('#apptCategoryBar .appt-cat-btn').forEach(function(b) {
        b.classList.toggle('active', b.getAttribute('data-tab') === cat);
    });

    function expandCollapse(cardId) {
        var col = document.getElementById('collapse-' + cardId);
        if (col && !col.classList.contains('show')) {
            var inst = bootstrap.Collapse.getOrCreateInstance(col, { toggle: false });
            inst.show();
        }
    }

    if (cat === 'today') {
        if (todayCard) todayCard.style.display = '';
        if (upcomingCard) upcomingCard.style.display = 'none';
        if (historyCard) historyCard.style.display = 'none';
        expandCollapse('today');
        if (scroll && todayCard) {
            todayCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    } else if (cat === 'upcoming') {
        if (todayCard) todayCard.style.display = 'none';
        if (upcomingCard) upcomingCard.style.display = '';
        if (historyCard) historyCard.style.display = 'none';
        expandCollapse('upcoming');
        if (scroll && upcomingCard) {
            upcomingCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    } else if (cat === 'history') {
        if (todayCard) todayCard.style.display = 'none';
        if (upcomingCard) upcomingCard.style.display = 'none';
        if (historyCard) historyCard.style.display = '';
        expandCollapse('history');
        if (scroll && historyCard) {
            historyCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    } else { // 'all'
        if (todayCard) todayCard.style.display = '';
        if (upcomingCard) upcomingCard.style.display = '';
        if (historyCard) historyCard.style.display = '';
        if (scroll && todayCard) {
            todayCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    if (scroll && window.location.hash !== '#' + cat) {
        try {
            history.replaceState(null, '', '#' + cat);
        } catch (e) {}
    }
}

(function () {
    var validTabs = ['today', 'upcoming', 'history', 'all'];

    function syncFromHash() {
        var rawHash = (window.location.hash || '').replace(/^#/, '');
        var params = new URLSearchParams(window.location.search);
        var qTab = params.get('tab');

        if (!rawHash) {
            if (qTab && validTabs.indexOf(qTab) !== -1) {
                rawHash = qTab;
            } else if (params.has('hstatus') || params.has('hsearch') || params.has('hpage')) {
                rawHash = 'history';
            } else if (params.has('tstatus') || params.has('tpage')) {
                rawHash = 'today';
            } else if (params.has('ustatus') || params.has('udate') || params.has('upage')) {
                rawHash = 'upcoming';
            }
        }

        if (!rawHash) return;

        if (validTabs.indexOf(rawHash) !== -1) {
            selectAdminApptCategory(rawHash, false);
            var cardEl = document.getElementById(rawHash);
            if (cardEl && (params.has('hstatus') || params.has('hsearch') || params.has('hpage') || window.location.hash === '#' + rawHash)) {
                setTimeout(function() {
                    try { cardEl.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch(e){}
                }, 120);
            }
            return;
        }

        if (rawHash.indexOf('appt-') === 0) {
            var row = document.getElementById(rawHash);
            var card = row ? row.closest('.appt-card') : null;
            if (card && card.id) {
                selectAdminApptCategory(card.id, false);
                var col = document.getElementById('collapse-' + card.id);
                if (col) {
                    var inst = bootstrap.Collapse.getOrCreateInstance(col, { toggle: false });
                    inst.show();
                }
                setTimeout(function() {
                    try { row.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch(e){}
                }, 150);
            }
        }
    }

    window.addEventListener('hashchange', syncFromHash);
    if (window.location.hash || window.location.search) {
        syncFromHash();
    }
})();
</script>

<script>
document.querySelectorAll('.reschedule-button').forEach(function (button) {
    button.addEventListener('click', function () {
        document.getElementById('rescheduleId').value = button.dataset.id;
        document.getElementById('rescheduleDate').value = button.dataset.date;
        document.getElementById('rescheduleStart').value = button.dataset.start;
        document.getElementById('rescheduleEnd').value = button.dataset.end;
        document.getElementById('rescheduleTab').value = button.dataset.section === 'history' ? 'history' : 'pending';
    });
});
// Auto-scroll sa clinick na notification
(function(){
    var hl = document.querySelector('tr.highlight-row');
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

</div>

</body>

</html>
