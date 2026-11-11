<?php

require_once __DIR__ . '/../config.php';

if (
    !isset($_SESSION['user_id']) ||
    strtolower(trim($_SESSION['role'] ?? '')) !== 'patient'
) {
    header('Location: ../login.php');
    exit;
}

function patientDashboardEscape($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$userId = (int) $_SESSION['user_id'];

/* =========================
   GET USER
========================= */

$stmt = $pdo->prepare("
    SELECT *
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

/* =========================
   GET PATIENT
========================= */

$stmt = $pdo->prepare("
    SELECT *
    FROM patients
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$patient = $stmt->fetch(PDO::FETCH_ASSOC);

/* Fallback search by email */
if (!$patient && !empty($user['email'])) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM patients
        WHERE email = ?
        LIMIT 1
    ");

    $stmt->execute([$user['email']]);

    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
}

/* Create patient record if missing */
if (!$patient) {

    $firstName = '';
    $lastName = '';

    if (!empty($user['first_name'])) {
        $firstName = trim($user['first_name']);
    }

    if (!empty($user['last_name'])) {
        $lastName = trim($user['last_name']);
    }

    if ($firstName === '' && !empty($user['full_name'])) {

        $parts = preg_split(
            '/\s+/',
            trim($user['full_name'])
        );

        $firstName = $parts[0] ?? 'Patient';

        if (count($parts) > 1) {
            $lastName = implode(
                ' ',
                array_slice($parts, 1)
            );
        }
    }

    if ($firstName === '') {
        $firstName = 'Patient';
    }

    $patientNumber = generateShortPatientNumber($pdo);

    try {

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

        $stmt = $pdo->prepare("
            SELECT *
            FROM patients
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$patientId]);

        $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $patient = null;
    }
}

$patientId = (int) ($patient['id'] ?? 0);

/* =========================
   SELF-CANCEL WITH NOTES (dashboard)
   - Status -> Cancelled, notes saved, slot reopens via get_available_slots.php
   ========================= */
$dashboardCancelSuccess = '';
$dashboardCancelError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $patientId > 0) {
    $cancelAction = trim($_POST['action'] ?? '');
    if ($cancelAction === 'confirm_attendance') {
        $confirmId = (int)($_POST['appointment_id'] ?? 0);
        if ($confirmId <= 0) {
            $dashboardCancelError = 'Invalid appointment.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, appointment_date, start_time, status FROM appointments WHERE id = ? AND patient_id = ? LIMIT 1");
                $stmt->execute([$confirmId, $patientId]);
                $toConfirm = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$toConfirm) {
                    $dashboardCancelError = 'Appointment not found.';
                } elseif (!in_array($toConfirm['status'], ['Pending', 'Confirmed'], true)) {
                    $dashboardCancelError = 'Only Pending or Confirmed appointments can be confirmed.';
                } elseif ($toConfirm['appointment_date'] < date('Y-m-d')) {
                    $dashboardCancelError = 'Past appointments cannot be confirmed.';
                } else {
                    $checkStmt = $pdo->prepare("SELECT id FROM appointment_concerns WHERE appointment_id = ? AND patient_id = ? AND reason = 'Confirmation' LIMIT 1");
                    $checkStmt->execute([$confirmId, $patientId]);
                    if (!$checkStmt->fetch()) {
                        $insStmt = $pdo->prepare("INSERT INTO appointment_concerns (appointment_id, patient_id, reason, message, status) VALUES (?, ?, ?, ?, 'New')");
                        $insStmt->execute([$confirmId, $patientId, 'Confirmation', 'Patient confirmed attendance via dashboard for appointment on ' . $toConfirm['appointment_date'] . ' at ' . date('h:i A', strtotime($toConfirm['start_time'])) . '.']);
                    }
                    $dashboardCancelSuccess = 'Thank you! Your attendance is confirmed. This counts as your reply.';
                }
            } catch (Throwable $e) {
                $dashboardCancelError = 'Unable to confirm attendance. Please try again.';
            }
        }
    } elseif ($cancelAction === 'request_reschedule') {
        $reschedId = (int)($_POST['appointment_id'] ?? 0);
        $prefDate = trim($_POST['preferred_date'] ?? '');
        $prefTime = trim($_POST['preferred_time'] ?? '');
        $reschedReason = trim($_POST['reschedule_reason'] ?? '');
        if ($reschedId <= 0) {
            $dashboardCancelError = 'Invalid appointment.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefDate) || $prefDate < date('Y-m-d')) {
            $dashboardCancelError = 'Preferred date must be today or later.';
        } elseif ((int)date('N', strtotime($prefDate)) === 7) {
            $dashboardCancelError = 'The clinic is closed on Sundays. Please select Monday to Saturday.';
        } elseif (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $prefTime)) {
            $dashboardCancelError = 'Preferred time is invalid.';
        } elseif (substr($prefTime, 0, 5) < '08:00' || substr($prefTime, 0, 5) > '17:30') {
            $dashboardCancelError = 'Preferred time must be within clinic operating hours (8:00 AM to 5:30 PM).';
        } elseif ($prefDate === date('Y-m-d') && substr($prefTime, 0, 5) <= date('H:i')) {
            $dashboardCancelError = 'Preferred time has already passed today. Please choose a later time or another date.';
        } elseif (mb_strlen($reschedReason) > 500) {
            $dashboardCancelError = 'Reason must not exceed 500 characters.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, appointment_date, start_time, end_time, status, rescheduled_at, dentist_id FROM appointments WHERE id = ? AND patient_id = ? LIMIT 1");
                $stmt->execute([$reschedId, $patientId]);
                $toResched = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$toResched) {
                    $dashboardCancelError = 'Appointment not found.';
                } elseif (!empty($toResched['rescheduled_at'])) {
                    $dashboardCancelError = 'This appointment has already been rescheduled and cannot be rescheduled again.';
                } elseif (!in_array($toResched['status'], ['Pending', 'Confirmed'], true)) {
                    $dashboardCancelError = 'Only Pending or Confirmed appointments can be rescheduled.';
                } elseif ($toResched['appointment_date'] < date('Y-m-d')) {
                    $dashboardCancelError = 'Past appointments cannot be rescheduled.';
                } else {
                    $origDuration = 3600;
                    if (!empty($toResched['start_time']) && !empty($toResched['end_time'])) {
                        $calcDur = strtotime($toResched['end_time']) - strtotime($toResched['start_time']);
                        if ($calcDur > 0) $origDuration = $calcDur;
                    }
                    $prefTimeShort = substr($prefTime, 0, 5);
                    $newStartTime = $prefTimeShort . ':00';
                    $newEndTime = date('H:i:s', strtotime($newStartTime) + $origDuration);
                    if ($newEndTime > '18:00:00') $newEndTime = '18:00:00';

                    require_once __DIR__ . '/../functions.php';
                    if (function_exists('appointmentConflict') && appointmentConflict((int)$toResched['dentist_id'], $prefDate, $newStartTime, $newEndTime, $reschedId)) {
                        $dashboardCancelError = 'The selected date and time has a conflict with an existing appointment. Please choose a different schedule.';
                    } else {
                        $oldDateFmt = date('M d, Y', strtotime($toResched['appointment_date']));
                        $oldTimeFmt = date('h:i A', strtotime($toResched['start_time']));
                        $newDateFmt = date('M d, Y', strtotime($prefDate));
                        $newTimeFmt = date('h:i A', strtotime($newStartTime));

                        $notesEntry = "\n[Rescheduled by patient on " . date('Y-m-d H:i:s') . " from {$oldDateFmt} {$oldTimeFmt} to {$newDateFmt} {$newTimeFmt}" . ($reschedReason !== '' ? " - Reason: {$reschedReason}" : "") . "]";

                        $upd = $pdo->prepare("
                            UPDATE appointments
                            SET appointment_date = ?,
                                start_time = ?,
                                end_time = ?,
                                status = 'Pending',
                                confirmed_at = NULL,
                                rescheduled_at = NOW(),
                                notes = CONCAT(COALESCE(notes, ''), ?)
                            WHERE id = ? AND patient_id = ?
                        ");
                        $upd->execute([$prefDate, $newStartTime, $newEndTime, $notesEntry, $reschedId, $patientId]);

                        try {
                            $notif = $pdo->prepare("
                                INSERT INTO notifications (patient_id, appointment_id, title, message, type)
                                VALUES (?, ?, ?, ?, 'Appointment')
                            ");
                            $notif->execute([
                                $patientId,
                                $reschedId,
                                'Appointment Rescheduled',
                                'Your appointment has been successfully rescheduled to ' . $newDateFmt . ' at ' . $newTimeFmt . '.'
                            ]);
                        } catch (Throwable $e) {}

                        try {
                            $insStmt = $pdo->prepare("INSERT INTO appointment_concerns (appointment_id, patient_id, reason, message, status) VALUES (?, ?, ?, ?, 'Resolved')");
                            $insStmt->execute([
                                $reschedId,
                                $patientId,
                                'Reschedule Request',
                                'Patient rescheduled appointment on ' . $toResched['appointment_date'] . ' at ' . $oldTimeFmt . ' to ' . $prefDate . ' at ' . $newTimeFmt . '.' . ($reschedReason !== '' ? ' Reason: ' . $reschedReason : '')
                            ]);
                        } catch (Throwable $e) {}

                        $dashboardCancelSuccess = 'Your appointment has been rescheduled to ' . $newDateFmt . ' at ' . $newTimeFmt . '.';
                    }
                }
            } catch (Throwable $e) {
                $dashboardCancelError = 'Unable to send reschedule request. Please try again.';
            }
        }
    } elseif ($cancelAction === 'log_email_click') {
        $emailId = (int)($_POST['appointment_id'] ?? 0);
        if ($emailId > 0) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM appointments WHERE id = ? AND patient_id = ? LIMIT 1");
                $stmt->execute([$emailId, $patientId]);
                if ($stmt->fetch()) {
                    $dupStmt = $pdo->prepare("SELECT id FROM appointment_concerns WHERE appointment_id = ? AND patient_id = ? AND reason = 'Email Request' AND DATE(created_at) = CURDATE() LIMIT 1");
                    $dupStmt->execute([$emailId, $patientId]);
                    if (!$dupStmt->fetch()) {
                        $insStmt = $pdo->prepare("INSERT INTO appointment_concerns (appointment_id, patient_id, reason, message, status) VALUES (?, ?, ?, ?, 'New')");
                        $insStmt->execute([$emailId, $patientId, 'Email Request', 'Patient emailed admin about reschedule via dashboard (appointment #' . $emailId . ').']);
                    }
                }
            } catch (Throwable $e) {
            }
        }
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
    } elseif ($cancelAction === 'cancel_appointment') {
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        $cancelNotes = trim($_POST['cancel_notes'] ?? '');
        if ($appointmentId <= 0) {
            $dashboardCancelError = 'Invalid appointment.';
        } elseif (mb_strlen($cancelNotes) > 500) {
            $dashboardCancelError = 'Cancellation notes must not exceed 500 characters.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, appointment_date, start_time, status, notes FROM appointments WHERE id = ? AND patient_id = ? LIMIT 1");
                $stmt->execute([$appointmentId, $patientId]);
                $toCancel = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$toCancel) {
                    $dashboardCancelError = 'Appointment not found.';
                } elseif (!in_array($toCancel['status'], ['Pending', 'Confirmed'], true)) {
                    $dashboardCancelError = 'Only Pending or Confirmed appointments can be cancelled.';
                } elseif ($toCancel['appointment_date'] < date('Y-m-d')) {
                    $dashboardCancelError = 'Past appointments cannot be cancelled.';
                } else {
                    $newNotes = trim($toCancel['notes'] ?? '');
                    if ($cancelNotes !== '') {
                        $prefix = $newNotes !== '' ? $newNotes . "\n" : '';
                        $newNotes = $prefix . 'Cancelled by patient: ' . $cancelNotes;
                    } else {
                        $prefix = $newNotes !== '' ? $newNotes . "\n" : '';
                        $newNotes = $prefix . 'Cancelled by patient (no reason given).';
                    }
                    $stmt = $pdo->prepare("UPDATE appointments SET status = 'Cancelled', notes = ?, cancelled_at = NOW() WHERE id = ? AND patient_id = ? AND status IN ('Pending','Confirmed')");
                    $stmt->execute([$newNotes, $appointmentId, $patientId]);
                    if ($stmt->rowCount() > 0) {
                        try {
                            $stmt2 = $pdo->prepare("INSERT INTO notifications (patient_id, appointment_id, title, message, type) VALUES (?, ?, ?, ?, ?)");
                            $stmt2->execute([$patientId, $appointmentId, 'Appointment Cancelled', 'Your appointment on ' . $toCancel['appointment_date'] . ' at ' . date('h:i A', strtotime($toCancel['start_time'])) . ' has been cancelled. The slot is now available for other patients.' . ($cancelNotes !== '' ? ' Reason: ' . $cancelNotes : ''), 'Appointment']);
                        } catch (Throwable $e) {}
                        $dashboardCancelSuccess = 'Appointment cancelled successfully. The slot is now open for other patients.';
                    } else {
                        $dashboardCancelError = 'Unable to cancel appointment. Please try again.';
                    }
                }
            } catch (Throwable $e) {
                $dashboardCancelError = 'Unable to cancel appointment. Please try again.';
            }
        }
    }
}

/* =========================
   NAME
   ========================= */

$fullName = trim(
    ($patient['first_name'] ?? '') .
    ' ' .
    ($patient['last_name'] ?? '')
);

if ($fullName === '') {
    $fullName =
        $user['full_name'] ??
        $_SESSION['full_name'] ??
        'Patient';
}

/* =========================
   APPOINTMENT COUNTS
========================= */

$totalAppointments = 0;
$todayAppointments = 0;
$upcomingAppointments = 0;
$pendingAppointments = 0;
$confirmedAppointments = 0;
$completedAppointments = 0;
$noShowCancelledAppointments = 0;

if ($patientId > 0) {

    $stmt = $pdo->prepare("
        SELECT status, appointment_date, COUNT(*) AS total
        FROM appointments
        WHERE patient_id = ?
        GROUP BY status, appointment_date
    ");

    $stmt->execute([$patientId]);

    $todayDate = date('Y-m-d');

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {

        $count = (int) $row['total'];
        $apptDate = substr((string)($row['appointment_date'] ?? ''), 0, 10);
        $status = trim($row['status'] ?? '');

        $totalAppointments += $count;

        /* Pending card — all pending appointments regardless of date */
        if ($status === 'Pending') {
            $pendingAppointments += $count;
            if ($apptDate === $todayDate) {
                $todayAppointments += $count;
            }
            if ($apptDate > $todayDate) {
                $upcomingAppointments += $count;
            }
        }

        /* Confirmed card — confirmed/approved + active checked-in/late/serving regardless of date */
        if (
            $status === 'Confirmed' || $status === 'Approved'
            || $status === 'Checked In' || $status === 'Late' || $status === 'Serving'
        ) {
            $confirmedAppointments += $count;
            if ($apptDate === $todayDate) {
                $todayAppointments += $count;
            }
            if ($apptDate > $todayDate) {
                $upcomingAppointments += $count;
            }
        }

        /* Completed card — all completed appointments regardless of date */
        if ($status === 'Completed') {
            $completedAppointments += $count;
            if ($apptDate === $todayDate) {
                $todayAppointments += $count;
            }
        }

        /* No-Show & Cancelled card */
        if ($status === 'No-Show' || $status === 'Cancelled' || $status === 'Canceled') {
            $noShowCancelledAppointments += $count;
        }
    }
}

/* =========================
   UPCOMING APPOINTMENT
========================= */

$upcomingAppointment = null;

if ($patientId > 0) {

    $stmt = $pdo->prepare("
        SELECT
            a.*,
            d.dentist_name,
            d.specialization
        FROM appointments a
        LEFT JOIN dentists d
            ON d.id = a.dentist_id
        WHERE a.patient_id = ?
        AND a.appointment_date >= CURDATE()
        AND a.status NOT IN (
            'Cancelled',
            'No-Show',
            'Completed'
        )
        ORDER BY
            a.appointment_date ASC,
            a.start_time ASC
        LIMIT 1
    ");

    $stmt->execute([$patientId]);

    $upcomingAppointment =
        $stmt->fetch(PDO::FETCH_ASSOC);
}

/* =========================
   RECENT APPOINTMENTS
========================= */

$recentAppointments = [];

if ($patientId > 0) {

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
        LIMIT 5
    ");

    $stmt->execute([$patientId]);

    $recentAppointments =
        $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================
   CARD DETAIL LISTS (clickable summary cards — same page, no new tab)
   Similar to admin dashboard: clicking a summary card opens its detail panel inline.
========================= */

$detailAppointments = [];

$pDetailToday = date('Y-m-d');
$pDetailWeekStart = (new DateTime('monday this week'))->format('Y-m-d');
$pDetailWeekEnd = (new DateTime('sunday this week'))->format('Y-m-d');
$pDetailMonthPrefix = date('Y-m');

$pPendingSub = ['total' => 0, 'today' => 0, 'week' => 0, 'month' => 0];
$pConfirmedSub = ['total' => 0, 'today' => 0, 'week' => 0, 'month' => 0];
$pCompletedSub = ['total' => 0, 'today' => 0, 'week' => 0, 'month' => 0];

if ($patientId > 0) {
    try {
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
            LIMIT 200
        ");
        $stmt->execute([$patientId]);
        $detailAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($detailAppointments as $dRow) {
            $dStatus = strtolower(trim($dRow['status'] ?? ''));
            if ($dStatus === 'approved') {
                $dStatus = 'confirmed';
            } elseif ($dStatus === 'checked in' || $dStatus === 'late' || $dStatus === 'serving') {
                $dStatus = 'confirmed';
            }
            $dDate = substr((string)($dRow['appointment_date'] ?? ''), 0, 10);
            $inWeek = ($dDate >= $pDetailWeekStart && $dDate <= $pDetailWeekEnd);
            $inMonth = (substr($dDate, 0, 7) === $pDetailMonthPrefix);
            $isToday = ($dDate === $pDetailToday);

            if ($dStatus === 'pending') {
                $pPendingSub['total']++;
                if ($isToday) $pPendingSub['today']++;
                if ($inWeek) $pPendingSub['week']++;
                if ($inMonth) $pPendingSub['month']++;
            } elseif ($dStatus === 'confirmed') {
                $pConfirmedSub['total']++;
                if ($isToday) $pConfirmedSub['today']++;
                if ($inWeek) $pConfirmedSub['week']++;
                if ($inMonth) $pConfirmedSub['month']++;
            } elseif ($dStatus === 'completed') {
                $pCompletedSub['total']++;
                if ($isToday) $pCompletedSub['today']++;
                if ($inWeek) $pCompletedSub['week']++;
                if ($inMonth) $pCompletedSub['month']++;
            }
        }
    } catch (Throwable $pDetailError) {
        $detailAppointments = [];
    }
}

function patientCardStatusKey($status)
{
    $key = strtolower(trim((string)($status ?? '')));
    if ($key === 'approved') $key = 'confirmed';
    elseif ($key === 'canceled') $key = 'cancelled';
    elseif ($key === 'no show') $key = 'no-show';
    elseif ($key === 'checked in' || $key === 'late' || $key === 'serving') $key = 'confirmed';
    return $key;
}

/* =========================
   CHART DATA — My Dental Journey
   (karaniwan sa dental patient portal:
    1. Visit history, 2. Status breakdown)
========================= */

$pChartStatusLabels = ['Completed', 'Confirmed', 'Pending', 'Cancelled', 'No-Show'];
$pChartStatusData = [0, 0, 0, 0, 0];

$pChartMonthlyLabels = [];
$pChartMonthlyData = [];

$pCompletionRate = 0;
$pAttendanceRate = 0;

if ($patientId > 0) {
    try {
        // 1. Personal status distribution
        $stmt = $pdo->prepare("
            SELECT status, COUNT(*) AS total
            FROM appointments
            WHERE patient_id = ?
            GROUP BY status
        ");
        $stmt->execute([$patientId]);

        $pStatusMap = [
            'pending' => 0, 'confirmed' => 0, 'approved' => 0,
            'completed' => 0, 'cancelled' => 0, 'canceled' => 0,
            'no-show' => 0, 'no show' => 0,
        ];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = strtolower(trim($row['status'] ?? ''));
            if (array_key_exists($key, $pStatusMap)) {
                $pStatusMap[$key] = (int)$row['total'];
            }
        }
        $pChartStatusData = [
            (int)($pStatusMap['completed'] ?? 0),
            (int)(($pStatusMap['confirmed'] ?? 0) + ($pStatusMap['approved'] ?? 0)),
            (int)($pStatusMap['pending'] ?? 0),
            (int)(($pStatusMap['cancelled'] ?? 0) + ($pStatusMap['canceled'] ?? 0)),
            (int)(($pStatusMap['no-show'] ?? 0) + ($pStatusMap['no show'] ?? 0)),
        ];

        // Completion + attendance rate
        if ($totalAppointments > 0) {
            $pCompletionRate = (int)round(($pChartStatusData[0] / $totalAppointments) * 100);
            $attended = $pChartStatusData[0] + $pChartStatusData[1];
            $pAttendanceRate = (int)round(($attended / $totalAppointments) * 100);
        }

        // 2. My visit history — last 6 months
        $pMonthMap = [];
        $pMonthNames = [];
        for ($m = 5; $m >= 0; $m--) {
            $dt = new DateTime("-{$m} months");
            $key = $dt->format('Y-m');
            $pMonthMap[$key] = 0;
            $pMonthNames[$key] = $dt->format('M');
        }

        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT(appointment_date, '%Y-%m') AS ym, COUNT(*) AS total
            FROM appointments
            WHERE patient_id = ?
            AND appointment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY ym
            ORDER BY ym ASC
        ");
        $stmt->execute([$patientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (array_key_exists($row['ym'], $pMonthMap)) {
                $pMonthMap[$row['ym']] = (int)$row['total'];
            }
        }
        foreach ($pMonthMap as $ym => $total) {
            $pChartMonthlyLabels[] = $pMonthNames[$ym];
            $pChartMonthlyData[] = (int)$total;
        }
    } catch (Throwable $pChartError) {
        // Charts must never break the dashboard.
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

    <title>Patient Dashboard | Abalos Dental</title>

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
        href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
    >

    <link
        rel="stylesheet"
        href="patient.css?v=8"
    >

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

    <style>
        /* ===== Welcome Hero — Clinical Calm gradient banner ===== */
        .welcome-hero {
            background: linear-gradient(120deg, #149A72 0%, #0E6B5E 48%, #0A4A41 100%);
            border-radius: 20px;
            padding: 26px 28px;
            color: #fff;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            box-shadow: 0 18px 40px rgba(14,107,94,.22);
        }
        .welcome-hero::after {
            content: "";
            position: absolute;
            width: 210px;
            height: 210px;
            border-radius: 50%;
            background: rgba(255,255,255,.08);
            right: -65px;
            top: -95px;
        }
        .welcome-hero-text { position: relative; z-index: 2; }
        .welcome-hero-label {
            font-size: 11px; font-weight: 700; letter-spacing: 1px;
            opacity: .8; margin-bottom: 8px;
        }
        .welcome-hero h3 {
            font-family: "Fraunces","Plus Jakarta Sans","Inter",system-ui,sans-serif;
            font-size: 24px; font-weight: 800; letter-spacing: -0.02em;
            margin: 0 0 6px; color: #fff;
        }
        .welcome-hero p { color: #D9F2E6; font-size: 13.5px; margin: 0; }
        .welcome-hero-icon {
            position: relative; z-index: 2; flex-shrink: 0;
            width: 64px; height: 64px; border-radius: 18px;
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(201,162,39,.55);
            display: flex; align-items: center; justify-content: center;
            font-size: 30px; color: #fff;
        }
        @media (max-width: 576px) {
            .welcome-hero-icon { display: none; }
            .welcome-hero h3 { font-size: 19px; }
        }

        /* ===== My Dental Journey Charts — Dental Clinic Premium Style ===== */
        .journey-section { margin-bottom: 1.5rem; }
        .journey-title {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 14px; padding: 0 2px; flex-wrap: wrap; gap: 8px;
        }
        .journey-title h3 {
            color: #ffffff; font-family: "Fraunces","Plus Jakarta Sans","Inter",system-ui,sans-serif;
            font-size: 17px; font-weight: 800; letter-spacing: -0.02em; margin: 0;
            display: flex; align-items: center; gap: 10px;
        }
        .journey-icon {
            width: 34px; height: 34px; border-radius: 10px;
            background: linear-gradient(135deg, #149A72, #0A4A41); color: #fff;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 16px; box-shadow: 0 6px 14px rgba(14,107,94,.25);
        }
        .journey-sub { color: #9CC4B6; font-size: 12.5px; }
        .journey-grid { display: grid; grid-template-columns: 1fr; gap: 16px; align-items: start; }
        .journey-card {
            background: #fff; border: 1px solid #808080; border-radius: 18px;
            overflow: hidden; box-shadow: 0 4px 18px rgba(22,83,101,.05);
            transition: transform .2s ease, box-shadow .2s ease;
            display: flex; flex-direction: column;
        }
        .journey-card:hover { transform: translateY(-3px); box-shadow: 0 14px 32px rgba(14,107,94,.12); }
        .journey-top {
            padding: 16px 20px; border-bottom: 1px solid #808080;
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            background: linear-gradient(180deg, #fbfeff, #ffffff);
        }
        .journey-head { display: flex; align-items: center; gap: 12px; }
        .journey-badge {
            width: 42px; height: 42px; border-radius: 13px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 19px;
        }
        .jb-blue { background: linear-gradient(135deg,#D9F2E6,#B9E4CF); color: #0E6B5E; }
        .jb-green { background: linear-gradient(135deg,#022c22,#064e3b); color: #fff; }
        .journey-top h4 {
            color: #1c4454; font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif;
            font-size: 14.5px; font-weight: 800; margin: 0; letter-spacing: -0.01em;
        }
        .journey-top small { color: #8ca0a8; font-size: 11.5px; }
        .journey-pill {
            font-size: 11px; font-weight: 800; padding: 6px 13px; border-radius: 20px;
            white-space: nowrap; border: 1px solid #B9E4CF; background: #EAF7F0; color: #0E6B5E;
        }
        .journey-pill.pill-green { background: #022c22; color: #fff; border-color: #022c22; }
        .journey-body { padding: 18px 20px 20px; position: relative; height: 270px; }
        .journey-body canvas { width: 100% !important; height: 100% !important; }
        .journey-doughnut { height: 270px; }
        .journey-visits { height: 320px; padding: 10px 14px 12px; }
        .journey-visits-top { padding: 16px 20px; }
        .journey-visits-top .journey-badge { width: 42px; height: 42px; font-size: 19px; border-radius: 13px; }
        .journey-visits-top h4 { font-size: 14.5px; }
        .journey-visits-top small { font-size: 11.5px; }
        .journey-visits-top .journey-pill { font-size: 11px; padding: 6px 13px; }
        .journey-accordion .accordion-item.journey-card { background: #fff; border: 1px solid #808080; border-radius: 18px; overflow: hidden; }
        .journey-accordion .accordion-item.journey-card:first-of-type { border-top-left-radius: 18px; border-top-right-radius: 18px; }
        .journey-accordion .accordion-item.journey-card:last-of-type { border-bottom-left-radius: 18px; border-bottom-right-radius: 18px; }
        .journey-accordion .accordion-header { margin: 0; }
        .journey-accordion .accordion-button { box-shadow: none; }
        .journey-accordion .accordion-button.journey-top { width: 100%; text-align: left; }
        .journey-accordion .accordion-button::after { display: none; }
        .journey-accordion .accordion-button:not(.collapsed) { background: linear-gradient(180deg, #fbfeff, #ffffff); color: inherit; box-shadow: none; }
        .journey-accordion .accordion-button:focus { box-shadow: none; }
        .journey-accordion .accordion-body { padding: 0; }
        .journey-accordion .jt { display: block; color: #1c4454; font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif; font-size: 14.5px; font-weight: 800; letter-spacing: -0.01em; }
        .journey-accordion .jt-sm { display: block; color: #8ca0a8; font-size: 11.5px; }
        .journey-accordion .journey-visits-top .jt { font-size: 14.5px; }
        .journey-accordion .journey-visits-top .jt-sm { font-size: 11.5px; }
        .journey-chev { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; background: #EAF7F0; border: 1px solid #B9E4CF; color: #0E6B5E; font-size: 12px; transition: transform .2s ease; flex-shrink: 0; }
        .journey-accordion .accordion-button.collapsed .journey-chev { transform: rotate(-90deg); }
        .journey-center {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -58%); text-align: center; pointer-events: none;
        }
        .journey-center strong {
            display: block; font-family: "Fraunces","Plus Jakarta Sans",sans-serif;
            font-size: 30px; font-weight: 800; color: #143f51; line-height: 1;
        }
        .journey-center span {
            font-size: 10.5px; color: #8ca0a8; font-weight: 700;
            letter-spacing: .8px; text-transform: uppercase;
        }
        .progress-strip { padding: 0 20px 20px; }
        .progress-row { display: flex; justify-content: space-between; font-size: 12px; color: #5b7680; font-weight: 600; margin-bottom: 6px; }
        .progress-row strong { color: #143f51; }
        .progress-track { height: 9px; border-radius: 10px; background: #c9c9c9; overflow: hidden; }
        .progress-fill {
            height: 100%; border-radius: 10px;
            background: linear-gradient(90deg, #022c22, #064e3b);
            transition: width .6s ease;
        }
        @media (max-width: 991px) {
            .journey-grid { grid-template-columns: 1fr; }
            .journey-body, .journey-doughnut { height: 250px; }
            .journey-visits { height: 320px; }
        }

        /* Recent Appointments - laman pinalaki para mabasa */
        .patient-card h3 { font-size:19px; font-family:"Plus Jakarta Sans","Inter",system-ui,sans-serif; }
        .patient-table th { font-size:13px !important; font-family:"Inter",system-ui,sans-serif; }
        .patient-table td { font-size:14.5px !important; font-family:"Inter","DM Sans",system-ui,sans-serif; }
        .status-badge { font-size:13px !important; padding:7px 13px !important; font-family:"Inter",system-ui,sans-serif; }
        .patient-content .btn-sm { font-size:13px; font-family:"Inter",system-ui,sans-serif; }
        .empty-state { font-size:14.5px; }

        .appointment-concern-link {
            color: #d97706 !important;
        }

        .appointment-concern-link:hover {
            background: rgba(217, 119, 6, 0.08);
            color: #b45309 !important;
        }

        .concern-btn {
            white-space: nowrap;
            font-size:13.5px;
        }

        @media (max-width: 768px) {
            .patient-table {
                min-width: 900px;
            }
        }

    </style>

</head>

<body>

<style>
body{background:linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22)!important;background-size:300% 300%!important;}
.patient-main{background:transparent!important;}
</style>

<div class="patient-layout">

    <?php require_once __DIR__ . '/patient_sidebar.php'; ?>

    <!-- =========================
         MAIN
    ========================== -->

    <main class="patient-main">

        <header class="patient-topbar">

            <div>

                <h1 class="page-heading">
                    Dashboard
                </h1>

                <p class="page-small">
                    Welcome back to Abalos Dental Clinic
                </p>

            </div>

            <div class="patient-profile">

                <div>

                    <div class="profile-name">
                        <?= patientDashboardEscape($fullName) ?>
                    </div>

                    <div class="profile-role">
                        Patient
                    </div>

                </div>

                <div class="profile-circle">
                    <i class="bi bi-person-fill"></i>
                </div>

            </div>

        </header>

        <div class="patient-content">

            <?php if ($dashboardCancelSuccess !== ''): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-1"></i> <?= patientDashboardEscape($dashboardCancelSuccess) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if ($dashboardCancelError !== ''): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= patientDashboardEscape($dashboardCancelError) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php
            $isVerifiedBanner = false;
            if (isset($_GET['verified']) && $_GET['verified'] === '1') {
                $isVerifiedBanner = true;
            } elseif (!empty($_SESSION['just_verified'])) {
                $isVerifiedBanner = true;
                unset($_SESSION['just_verified']);
            }
            ?>
            <?php if ($isVerifiedBanner): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center mb-3" role="alert" style="background:#eaf7f0;border:1px solid #a7f3d0;color:#065f46;border-radius:14px;padding:14px 18px;">
                <i class="bi bi-patch-check-fill fs-4 text-success me-2"></i>
                <div class="flex-grow-1">
                    <strong>Account Verified Successfully!</strong> Welcome to Abalos Dental Clinic. Your patient account is now active and ready to book appointments.
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <!-- WELCOME HERO -->

            <div class="welcome-hero mb-4" id="welcomeHero">

                <div class="welcome-hero-text">

                    <div class="welcome-hero-label">
                        Abalos Dental Clinic · Patient Portal
                    </div>

                    <h3>
                        Hello,
                        <?= patientDashboardEscape($fullName) ?>!
                    </h3>

                    <p>
                        Here's an overview of your dental appointments.
                    </p>

                </div>

                <div class="welcome-hero-icon">
                    <i class="bi bi-heart-pulse-fill"></i>
                </div>

            </div>

            <!-- STATS (clickable summary cards — gaya ng admin dashboard) -->

            <div class="row g-3 mb-4" id="statsGrid">

                <div class="col-md-4 col-6">

                    <div class="stat-card stat-clickable" data-view="today" role="button" tabindex="0" title="View today's appointments">

                        <div class="pstat-head">
                            <span class="pstat-icon"><i class="bi bi-calendar2-day-fill"></i></span>
                            <span class="pstat-label">Today's Appointments</span>
                        </div>

                        <div class="pstat-num"><?= $todayAppointments ?></div>

                        <div class="pstat-sub">
                            <span class="pstat-dot"></span>
                            Scheduled today
                        </div>

                        <span class="stat-go"><i class="bi bi-chevron-right"></i></span>

                    </div>

                </div>

                <div class="col-md-4 col-6">

                    <div class="stat-card stat-clickable" data-view="upcoming" role="button" tabindex="0" title="View upcoming appointments">

                        <div class="pstat-head">

                            <span class="pstat-icon"><i class="bi bi-calendar2-plus-fill"></i></span>

                            <span class="pstat-label">Upcoming Appointments</span>

                        </div>

                        <div class="pstat-num">
                            <?= $upcomingAppointments ?>
                        </div>

                        <div class="pstat-sub">
                            <span class="pstat-dot"></span>
                            Future schedules
                        </div>

                        <span class="stat-go"><i class="bi bi-chevron-right"></i></span>

                    </div>

                </div>

                <div class="col-md-4 col-6">

                    <div class="stat-card stat-clickable" data-view="pending" role="button" tabindex="0" title="View pending appointments">

                        <div class="pstat-head">

                            <span class="pstat-icon"><i class="bi bi-hourglass-split"></i></span>

                            <span class="pstat-label">Pending Appointments</span>

                        </div>

                        <div class="pstat-num">
                            <?= $pendingAppointments ?>
                        </div>

                        <div class="pstat-sub">
                            <span class="pstat-dot"></span>
                            Awaiting confirmation
                        </div>

                        <span class="stat-go"><i class="bi bi-chevron-right"></i></span>

                    </div>

                </div>

                <div class="col-md-4 col-6">

                    <div class="stat-card stat-clickable" data-view="confirmed" role="button" tabindex="0" title="View confirmed appointments">

                        <div class="pstat-head">

                            <span class="pstat-icon"><i class="bi bi-check-circle-fill"></i></span>

                            <span class="pstat-label">Confirmed Appointments</span>

                        </div>

                        <div class="pstat-num">
                            <?= $confirmedAppointments ?>
                        </div>

                        <div class="pstat-sub">
                            <span class="pstat-dot"></span>
                            Confirmed by clinic
                        </div>

                        <span class="stat-go"><i class="bi bi-chevron-right"></i></span>

                    </div>

                </div>

                <div class="col-md-4 col-6">

                    <div class="stat-card stat-clickable" data-view="completed" role="button" tabindex="0" title="View completed appointments">

                        <div class="pstat-head">

                            <span class="pstat-icon"><i class="bi bi-clipboard2-check-fill"></i></span>

                            <span class="pstat-label">Completed Appointments</span>

                        </div>

                        <div class="pstat-num">
                            <?= $completedAppointments ?>
                        </div>

                        <div class="pstat-sub">
                            <span class="pstat-dot"></span>
                            Finished treatments
                        </div>

                        <span class="stat-go"><i class="bi bi-chevron-right"></i></span>

                    </div>

                </div>

                <div class="col-md-4 col-6">

                    <div class="stat-card stat-clickable" data-view="noshow-cancelled" role="button" tabindex="0" title="View no-show and cancelled appointments">

                        <div class="pstat-head">

                            <span class="pstat-icon"><i class="bi bi-calendar2-x-fill"></i></span>

                            <span class="pstat-label">No Show &amp; Cancelled</span>

                        </div>

                        <div class="pstat-num">
                            <?= $noShowCancelledAppointments ?>
                        </div>

                        <div class="pstat-sub">
                            <span class="pstat-dot"></span>
                            Missed or cancelled
                        </div>

                        <span class="stat-go"><i class="bi bi-chevron-right"></i></span>

                    </div>

                </div>

            </div>

            <!-- CLICKABLE CARD DETAIL VIEWS (same page, no new tab — gaya ng admin) -->
            <style>
                /* Patient summary cards — gaya ng admin dashboard (green gradient + radial glows) */
                #statsGrid .stat-card{position:relative;overflow:hidden;background:linear-gradient(135deg,#047857 0%,#059669 55%,#10B981 100%);border:1px solid rgba(255,255,255,.28);border-radius:14px;padding:12px 14px;display:flex;flex-direction:column;align-items:flex-start;gap:3px;box-shadow:0 16px 34px rgba(5,150,105,.35);color:#ffffff;height:100%;transition:.25s ease}
                #statsGrid .stat-card::before,#statsGrid .stat-card::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
                #statsGrid .stat-card::before{width:130px;height:130px;top:-60px;right:-45px;background:radial-gradient(circle,rgba(255,255,255,.22),rgba(255,255,255,0) 70%)}
                #statsGrid .stat-card::after{width:100px;height:100px;bottom:-48px;left:-32px;background:radial-gradient(circle,rgba(255,255,255,.16),rgba(255,255,255,0) 70%)}
                #statsGrid .stat-card>*{position:relative;z-index:1}
                #statsGrid .stat-card:hover{transform:translateY(-4px);box-shadow:0 22px 42px rgba(5,150,105,.45);border-color:rgba(255,255,255,.62)}
                .pstat-head{display:flex;align-items:center;gap:10px;width:100%}
                .pstat-icon{width:34px;height:34px;border-radius:11px;display:inline-flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.32);color:#ffffff;box-shadow:0 8px 18px rgba(0,0,0,.14)}
                .pstat-label{margin:0;flex:1;min-width:0;color:#ffffff;font-size:13px;font-weight:700}
                .pstat-num{color:#ffffff;font-family:"Plus Jakarta Sans","Inter",system-ui,sans-serif;font-size:27px;font-weight:800;letter-spacing:-0.02em;font-variant-numeric:tabular-nums;text-shadow:0 6px 18px rgba(0,0,0,.22);line-height:1;margin-top:auto;padding-top:4px;padding-right:48px}
                .pstat-sub{display:inline-flex;align-items:center;gap:8px;font-size:11.5px;font-weight:600;color:#ffffff;margin-top:3px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.35);border-radius:999px;padding:7px 14px}
                .pstat-dot{width:8px;height:8px;border-radius:50%;background:#A7F3D0;box-shadow:0 0 0 4px rgba(167,243,208,.25);animation:pstatDotPulse 1.9s ease-in-out infinite;flex-shrink:0}
                @keyframes pstatDotPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.8)}}
                .stat-clickable{cursor:pointer;position:relative;border:1px solid rgba(255,255,255,.28) !important;user-select:none;-webkit-tap-highlight-color:transparent}
                .stat-clickable:hover{border-color:rgba(255,255,255,.62) !important}
                .stat-clickable:focus-visible{outline:2px solid #ffffff;outline-offset:2px}
                #statsGrid .stat-card>.stat-go{position:absolute;right:14px;bottom:18px;width:26px;height:26px;border-radius:50%;background:rgba(255,255,255,.10);border:1.5px solid rgba(255,255,255,.55);color:#ffffff;display:inline-flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;z-index:2}
                .stat-clickable.active-card{border-color:#ffffff !important;box-shadow:0 22px 42px rgba(5,150,105,.46);transform:translateY(-3px)}
                #cardDetailWrap{margin-bottom:1.5rem}
                #detailNav{display:none;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:14px}
                .detail-back-btn{display:inline-flex;align-items:center;gap:7px;border:1px solid #B9E4CF;background:#EAF7F0;color:#0E6B5E;font-size:12.5px;font-weight:700;padding:8px 15px;border-radius:999px;cursor:pointer;transition:.2s;font-family:inherit;margin-bottom:12px}
                .detail-back-btn:hover{background:#0E6B5E;border-color:#0E6B5E;color:#fff}
                .detail-nav-card{display:flex;align-items:center;gap:9px;background:#F2F9F4;border:1px solid #e3eeea;border-radius:12px;padding:11px 12px;cursor:pointer;user-select:none;transition:.15s}
                .detail-nav-card:hover{border-color:#0E6B5E;transform:translateY(-2px);box-shadow:0 8px 20px rgba(14,107,94,.12)}
                .detail-nav-card.active-nav{border-color:#0E6B5E;border-width:2px;background:#EAF7F0}
                .detail-nav-card:focus-visible{outline:2px solid #0E6B5E;outline-offset:2px}
                .detail-nav-card .detail-nav-icon{width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;background:#D9F2E6;color:#0E6B5E}
                .detail-nav-card small{display:block;color:#8ca0a8;font-size:10px;font-weight:600;line-height:1.2}
                .detail-nav-card strong{display:block;color:#173f50;font-size:17px;font-weight:800;line-height:1.2}
                .card-detail{background:#fff;border:1px solid #808080;border-radius:18px;overflow:hidden;margin-bottom:16px;animation:cardDetailIn .25s ease}
                .detail-back-row{display:none;margin-bottom:12px}
                .detail-back-row.is-open{display:block}
                .detail-back-btn{display:inline-flex;align-items:center;gap:7px;border:1px solid #B9E4CF;background:#EAF7F0;color:#0E6B5E;font-size:12.5px;font-weight:700;padding:8px 15px;border-radius:999px;cursor:pointer;transition:.2s;font-family:inherit}
                .detail-back-btn:hover{background:#0E6B5E;border-color:#0E6B5E;color:#fff}
                .detail-back-btn i{font-size:13px}
                #cardDetailWrap .appointment-table th{background:#0A3B34;color:#ffffff;font-size:11.5px;letter-spacing:.4px;text-transform:uppercase}
                @keyframes cardDetailIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
                .card-top{padding:19px 21px;border-bottom:1px solid #808080;background:linear-gradient(180deg,#fbfeff,#fff);display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
                .card-top h3{color:#1c4454;font-size:16px;font-weight:800;margin:6px 0 4px;display:flex;align-items:center;gap:10px}
                .detail-crumb{font-size:11px;color:#8ca0a8;font-weight:700}
                .detail-sub{color:#8ca0a8;font-size:12.5px;margin:0}
                .detail-date-pill{display:inline-flex;align-items:center;gap:7px;background:#EAF7F0;border:1px solid #B9E4CF;color:#0E6B5E;font-size:12px;font-weight:700;border-radius:20px;padding:7px 13px;white-space:nowrap}
                .card-body-custom{padding:18px 21px 21px}
                .detail-mini-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px}
                .detail-mini{display:flex;align-items:center;gap:10px;background:#F8FCFD;border:1px solid #e3eeea;border-radius:12px;padding:11px 12px}
                .detail-mini-clickable{cursor:pointer;transition:.15s}
                .detail-mini-clickable:hover{border-color:#0E6B5E;transform:translateY(-2px)}
                .detail-mini-clickable.active-mini{border-color:#0E6B5E;border-width:2px;background:#EAF7F0}
                .detail-mini-icon{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;background:#D9F2E6;color:#0E6B5E}
                .detail-mini small{display:block;color:#8ca0a8;font-size:10.5px;font-weight:600}
                .detail-mini strong{display:block;color:#143f51;font-size:18px;font-weight:800}
                .detail-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
                .detail-tab{border:1px solid #e2e8f0;background:#fff;color:#244f60;font-size:12.5px;font-weight:700;padding:8px 15px;border-radius:999px;cursor:pointer;transition:.2s}
                .detail-tab:hover{border-color:#0E6B5E;color:#0E6B5E}
                .detail-tab.active{background:#0E6B5E;border-color:#0E6B5E;color:#fff}
                .table-wrapper{overflow-x:auto;border:1px solid #edf2f7;border-radius:12px}
                .appointment-table{width:100%;border-collapse:collapse;min-width:640px}
                .appointment-table th{background:#0A3B34;color:#ffffff;font-size:11px;text-align:left;padding:12px;font-weight:800;white-space:nowrap;letter-spacing:.4px;text-transform:uppercase}
                .appointment-table td{padding:12px;font-size:13px;color:#4d6873;border-bottom:1px solid #edf2f7}
                .appointment-table tr:last-child td{border-bottom:none}
                .appointment-table tbody tr:hover{background:#f8fcfd}
                .empty-state{text-align:center;padding:32px 12px;color:#91a5ac}
                @media(max-width:767px){#detailNav{grid-template-columns:repeat(2,1fr)}.detail-mini-grid{grid-template-columns:repeat(2,1fr)}}

                .today-modal-content{border:none;border-radius:18px;overflow:visible}
                .today-modal-header{background:linear-gradient(135deg,#047857 0%,#059669 55%,#10B981 100%);color:#fff;border:none;padding:18px 20px;border-top-left-radius:18px;border-top-right-radius:18px}
                .today-modal-header .modal-title{font-weight:800;font-size:17px}
                .today-modal-date{color:rgba(255,255,255,.75);font-size:12px}
                .today-modal-icon{width:42px;height:42px;border-radius:12px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.32);display:inline-flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
                .today-modal-body{padding:18px 20px}
                .today-empty{text-align:center;padding:32px 12px;color:#91a5ac}
                .today-empty i{font-size:36px;display:block;margin-bottom:10px}
                .today-empty p{margin:0;font-size:14px}
                .today-list{display:flex;flex-direction:column;gap:10px}
                .today-item{display:flex;align-items:center;gap:14px;background:#F8FCFD;border:1px solid #e3eeea;border-radius:12px;padding:12px 14px}
                .today-item-time{min-width:70px;font-weight:800;font-size:14px;color:#0E6B5E}
                .today-item-info{flex:1;min-width:0}
                .today-item-service{font-weight:700;font-size:13.5px;color:#143f51}
                .today-item-dentist{font-size:12px;color:#8ca0a8}
                /* Today's Appointments table (sa modal) */
                .today-table-wrapper{overflow-x:auto;border:1px solid #e3eeea;border-radius:12px}
                .today-modal-table{width:100%;border-collapse:collapse;min-width:860px}
                .today-modal-table th{background:#f8fcfd;color:#8ca0a7;font-size:11px;text-align:left;padding:12px;font-weight:800;white-space:nowrap}
                .today-modal-table td{padding:12px;font-size:13px;color:#4d6873;border-bottom:1px solid #edf2f7;vertical-align:middle}
                .today-modal-table tr:last-child td{border-bottom:none}
                /* View button */
                .today-view-btn{background:#EBF2FF !important;border:1.5px solid #3B82F6 !important;color:#3B82F6 !important;font-weight:800 !important;border-radius:999px !important;padding:6px 14px !important;white-space:nowrap;font-size:12.5px !important;cursor:pointer}
                .today-view-btn:hover{background:#3B82F6 !important;color:#fff !important}
            </style>

            <div id="cardDetailWrap">

                <div id="detailNav" style="display:none;">
                    <div class="detail-nav-card" data-goto="today" role="button" tabindex="0" title="Today's Appointments">
                        <span class="detail-nav-icon"><i class="bi bi-calendar2-day-fill"></i></span>
                        <span><small>Today</small><strong><?= $todayAppointments ?></strong></span>
                    </div>
                    <div class="detail-nav-card" data-goto="upcoming" role="button" tabindex="0" title="Upcoming Appointments">
                        <span class="detail-nav-icon"><i class="bi bi-calendar2-plus-fill"></i></span>
                        <span><small>Upcoming</small><strong><?= $upcomingAppointments ?></strong></span>
                    </div>
                    <div class="detail-nav-card" data-goto="pending" role="button" tabindex="0" title="Pending Appointments">
                        <span class="detail-nav-icon"><i class="bi bi-hourglass-split"></i></span>
                        <span><small>Pending</small><strong><?= $pendingAppointments ?></strong></span>
                    </div>
                    <div class="detail-nav-card" data-goto="confirmed" role="button" tabindex="0" title="Confirmed Appointments">
                        <span class="detail-nav-icon"><i class="bi bi-check-circle-fill"></i></span>
                        <span><small>Confirmed</small><strong><?= $confirmedAppointments ?></strong></span>
                    </div>
                    <div class="detail-nav-card" data-goto="completed" role="button" tabindex="0" title="Completed Appointments">
                        <span class="detail-nav-icon"><i class="bi bi-clipboard2-check-fill"></i></span>
                        <span><small>Completed</small><strong><?= $completedAppointments ?></strong></span>
                    </div>
                    <div class="detail-nav-card" data-goto="noshow-cancelled" role="button" tabindex="0" title="No Show & Cancelled">
                        <span class="detail-nav-icon"><i class="bi bi-calendar2-x-fill"></i></span>
                        <span><small>No Show &amp; Cancelled</small><strong><?= $noShowCancelledAppointments ?></strong></span>
                    </div>
                </div>

                <!-- TODAY -->
                <div class="card-detail" id="detail-today" style="display:none;">
                    <div class="card-top">
                        <div>
                            <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; Today's Appointments</div>
                            <h3><i class="bi bi-calendar2-day-fill"></i> Today's Appointments</h3>
                            <p class="detail-sub">View and manage all appointments scheduled for today.</p>
                        </div>
                        <span class="detail-date-pill"><i class="bi bi-calendar3"></i> <?= date('F j, Y') ?></span>
                    </div>
                    <div class="card-body-custom">
                        <div class="table-wrapper"><table class="appointment-table"><thead><tr><th>#</th><th>Date &amp; Time</th><th>Dentist</th><th>Service</th><th>Status</th></tr></thead><tbody>
                        <?php $tn=0; foreach ($detailAppointments as $a):
                            $dstr=substr((string)($a['appointment_date']??''),0,10);
                            if($dstr!==$pDetailToday) continue; $tn++;
                            $dt='—'; if($dstr!==''){ $ts=strtotime($dstr.' '.($a['start_time']??'')); if($ts) $dt=date('M d, Y h:i A',$ts); }
                            $dn=trim($a['dentist_name']??'')!==''?$a['dentist_name']:'Not assigned';
                            $st=trim($a['status']??'Pending'); $fk=patientCardStatusKey($st);
                            $badge='status-pending'; if($fk==='confirmed')$badge='status-confirmed'; elseif($fk==='completed')$badge='status-completed'; elseif($fk==='cancelled'||$fk==='no-show')$badge='status-cancelled';
                        ?>
                            <tr data-status="<?= patientDashboardEscape($fk) ?>">
                                <td><?= $tn ?></td><td><?= patientDashboardEscape($dt) ?></td><td><?= patientDashboardEscape($dn) ?></td><td><?= patientDashboardEscape($a['service_type'] ?? 'Dental Service') ?></td><td><span class="status-badge <?= $badge ?>"><?= patientDashboardEscape($st) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if($tn===0): ?><tr><td colspan="5"><div class="empty-state">No appointments scheduled for today.</div></td></tr><?php endif; ?>
                        </tbody></table></div>
                    </div>
                </div>

                <!-- UPCOMING -->
                <div class="card-detail" id="detail-upcoming" style="display:none;">
                    <div class="card-top">
                        <div>
                            <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; Upcoming Appointments</div>
                            <h3><i class="bi bi-calendar2-plus-fill"></i> Upcoming Appointments</h3>
                            <p class="detail-sub">View your upcoming scheduled appointments.</p>
                        </div>
                        <span class="detail-date-pill"><i class="bi bi-calendar3"></i> <?= date('F j, Y') ?></span>
                    </div>
                    <div class="card-body-custom">
                        <div class="table-wrapper"><table class="appointment-table"><thead><tr><th>#</th><th>Date &amp; Time</th><th>Dentist</th><th>Service</th><th>Status</th></tr></thead><tbody>
                        <?php $un=0; foreach ($detailAppointments as $a):
                            $dstr=substr((string)($a['appointment_date']??''),0,10);
                            if($dstr<=$pDetailToday) continue; $un++;
                            $dt='—'; if($dstr!==''){ $ts=strtotime($dstr.' '.($a['start_time']??'')); if($ts) $dt=date('M d, Y h:i A',$ts); }
                            $dn=trim($a['dentist_name']??'')!==''?$a['dentist_name']:'Not assigned';
                            $st=trim($a['status']??'Pending'); $fk=patientCardStatusKey($st);
                            $badge='status-pending'; if($fk==='confirmed')$badge='status-confirmed'; elseif($fk==='completed')$badge='status-completed'; elseif($fk==='cancelled'||$fk==='no-show')$badge='status-cancelled';
                        ?>
                            <tr data-status="<?= patientDashboardEscape($fk) ?>">
                                <td><?= $un ?></td><td><?= patientDashboardEscape($dt) ?></td><td><?= patientDashboardEscape($dn) ?></td><td><?= patientDashboardEscape($a['service_type'] ?? 'Dental Service') ?></td><td><span class="status-badge <?= $badge ?>"><?= patientDashboardEscape($st) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if($un===0): ?><tr><td colspan="5"><div class="empty-state">No upcoming appointments.</div></td></tr><?php endif; ?>
                        </tbody></table></div>
                    </div>
                </div>

                <!-- PENDING -->
                <div class="card-detail" id="detail-pending" style="display:none;">
                    <div class="card-top">
                        <div>
                            <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; Pending Appointments</div>
                            <h3><i class="bi bi-hourglass-split"></i> Pending Appointments</h3>
                            <p class="detail-sub">View your appointments awaiting clinic confirmation.</p>
                        </div>
                        <span class="detail-date-pill"><i class="bi bi-calendar3"></i> <?= date('F j, Y') ?></span>
                    </div>
                    <div class="card-body-custom">
                        <div class="table-wrapper"><table class="appointment-table"><thead><tr><th>#</th><th>Date &amp; Time</th><th>Dentist</th><th>Service</th><th>Status</th></tr></thead><tbody>
                        <?php $pn=0; foreach ($detailAppointments as $a): if(patientCardStatusKey($a['status']??'')!=='pending') continue; $pn++;
                            $dstr=substr((string)($a['appointment_date']??''),0,10);
                            $dt='—'; if($dstr!==''){ $ts=strtotime($dstr.' '.($a['start_time']??'')); if($ts) $dt=date('M d, Y h:i A',$ts); }
                            $dn=trim($a['dentist_name']??'')!==''?$a['dentist_name']:'Not assigned';
                        ?>
                            <tr data-status="pending">
                                <td><?= $pn ?></td><td><?= patientDashboardEscape($dt) ?></td><td><?= patientDashboardEscape($dn) ?></td><td><?= patientDashboardEscape($a['service_type'] ?? 'Dental Service') ?></td><td><span class="status-badge status-pending">Pending</span></td>
                            </tr>
                         <?php endforeach; ?>
                         <?php if($pn===0): ?><tr><td colspan="5"><div class="empty-state">No pending appointments.</div></td></tr><?php endif; ?>
                         </tbody></table></div>
                    </div>
                </div>

                <!-- CONFIRMED -->
                <div class="card-detail" id="detail-confirmed" style="display:none;">
                    <div class="card-top">
                        <div>
                            <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; Confirmed Appointments</div>
                            <h3><i class="bi bi-check-circle-fill"></i> Confirmed Appointments</h3>
                            <p class="detail-sub">View and manage all your confirmed appointments.</p>
                        </div>
                        <span class="detail-date-pill"><i class="bi bi-calendar3"></i> <?= date('F j, Y') ?></span>
                    </div>
                    <div class="card-body-custom">
                        <div class="detail-tabs" data-tab-group="confirmed">
                            <button type="button" class="detail-tab active" data-filter="all">All (<?= $pConfirmedSub['total'] ?>)</button>
                            <button type="button" class="detail-tab" data-filter="today">Today (<?= $pConfirmedSub['today'] ?>)</button>
                            <button type="button" class="detail-tab" data-filter="week">This Week (<?= $pConfirmedSub['week'] ?>)</button>
                            <button type="button" class="detail-tab" data-filter="month">This Month (<?= $pConfirmedSub['month'] ?>)</button>
                        </div>
                        <div class="table-wrapper"><table class="appointment-table"><thead><tr><th>#</th><th>Date &amp; Time</th><th>Dentist</th><th>Service</th><th>Status</th></tr></thead><tbody>
                        <?php $cn=0; foreach ($detailAppointments as $a): if(patientCardStatusKey($a['status']??'')!=='confirmed') continue; $cn++;
                            $dstr=substr((string)($a['appointment_date']??''),0,10);
                            $dt='—'; if($dstr!==''){ $ts=strtotime($dstr.' '.($a['start_time']??'')); if($ts) $dt=date('M d, Y h:i A',$ts); }
                            $dn=trim($a['dentist_name']??'')!==''?$a['dentist_name']:'Not assigned';
                            $inW=($dstr>=$pDetailWeekStart&&$dstr<=$pDetailWeekEnd); $inM=(substr($dstr,0,7)===$pDetailMonthPrefix);
                        ?>
                            <tr data-today="<?= $dstr===$pDetailToday?'1':'0' ?>" data-week="<?= $inW?'1':'0' ?>" data-month="<?= $inM?'1':'0' ?>">
                                <td><?= $cn ?></td><td><?= patientDashboardEscape($dt) ?></td><td><?= patientDashboardEscape($dn) ?></td><td><?= patientDashboardEscape($a['service_type'] ?? 'Dental Service') ?></td><td><span class="status-badge status-confirmed">Confirmed</span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if($cn===0): ?><tr><td colspan="5"><div class="empty-state">No confirmed appointments.</div></td></tr><?php endif; ?>
                        </tbody></table></div>
                    </div>
                </div>

                <!-- COMPLETED -->
                <div class="card-detail" id="detail-completed" style="display:none;">
                    <div class="card-top">
                        <div>
                            <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; Completed Appointments</div>
                            <h3><i class="bi bi-clipboard2-check-fill"></i> Completed Appointments</h3>
                            <p class="detail-sub">View records of your completed treatments and dental visits.</p>
                        </div>
                        <span class="detail-date-pill"><i class="bi bi-calendar3"></i> <?= date('F j, Y') ?></span>
                    </div>
                    <div class="card-body-custom">
                        <div class="detail-tabs" data-tab-group="completed">
                            <button type="button" class="detail-tab active" data-filter="all">All (<?= $pCompletedSub['total'] ?>)</button>
                            <button type="button" class="detail-tab" data-filter="today">Today (<?= $pCompletedSub['today'] ?>)</button>
                            <button type="button" class="detail-tab" data-filter="week">This Week (<?= $pCompletedSub['week'] ?>)</button>
                            <button type="button" class="detail-tab" data-filter="month">This Month (<?= $pCompletedSub['month'] ?>)</button>
                        </div>
                        <div class="table-wrapper"><table class="appointment-table"><thead><tr><th>#</th><th>Date &amp; Time</th><th>Dentist</th><th>Service</th><th>Status</th></tr></thead><tbody>
                        <?php $dn2=0; foreach ($detailAppointments as $a): if(patientCardStatusKey($a['status']??'')!=='completed') continue; $dn2++;
                            $dstr=substr((string)($a['appointment_date']??''),0,10);
                            $dt='—'; if($dstr!==''){ $ts=strtotime($dstr.' '.($a['start_time']??'')); if($ts) $dt=date('M d, Y h:i A',$ts); }
                            $dent=trim($a['dentist_name']??'')!==''?$a['dentist_name']:'Not assigned';
                            $inW=($dstr>=$pDetailWeekStart&&$dstr<=$pDetailWeekEnd); $inM=(substr($dstr,0,7)===$pDetailMonthPrefix);
                        ?>
                            <tr data-today="<?= $dstr===$pDetailToday?'1':'0' ?>" data-week="<?= $inW?'1':'0' ?>" data-month="<?= $inM?'1':'0' ?>">
                                <td><?= $dn2 ?></td><td><?= patientDashboardEscape($dt) ?></td><td><?= patientDashboardEscape($dent) ?></td><td><?= patientDashboardEscape($a['service_type'] ?? 'Dental Service') ?></td><td><span class="status-badge status-completed">Completed</span></td>
                            </tr>
                         <?php endforeach; ?>
                         <?php if($dn2===0): ?><tr><td colspan="5"><div class="empty-state">No completed appointments.</div></td></tr><?php endif; ?>
                         </tbody></table></div>
                    </div>
                </div>

                <!-- NO SHOW & CANCELLED -->
                <div class="card-detail" id="detail-noshow-cancelled" style="display:none;">
                    <div class="card-top">
                        <div>
                            <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; No Show &amp; Cancelled</div>
                            <h3><i class="bi bi-calendar2-x-fill"></i> No Show &amp; Cancelled</h3>
                            <p class="detail-sub">Records of your missed or cancelled appointments.</p>
                        </div>
                        <span class="detail-date-pill"><i class="bi bi-calendar3"></i> <?= date('F j, Y') ?></span>
                    </div>
                    <div class="card-body-custom">
                        <div class="table-wrapper"><table class="appointment-table"><thead><tr><th>#</th><th>Date &amp; Time</th><th>Dentist</th><th>Service</th><th>Status</th></tr></thead><tbody>
                        <?php $nc=0; foreach ($detailAppointments as $a):
                            $fk=patientCardStatusKey($a['status']??'');
                            if($fk!=='no-show' && $fk!=='cancelled') continue; $nc++;
                            $dstr=substr((string)($a['appointment_date']??''),0,10);
                            $dt='—'; if($dstr!==''){ $ts=strtotime($dstr.' '.($a['start_time']??'')); if($ts) $dt=date('M d, Y h:i A',$ts); }
                            $dn=trim($a['dentist_name']??'')!==''?$a['dentist_name']:'Not assigned';
                            $st=trim($a['status']??'');
                            $badge='status-cancelled';
                        ?>
                            <tr data-status="<?= patientDashboardEscape($fk) ?>">
                                <td><?= $nc ?></td><td><?= patientDashboardEscape($dt) ?></td><td><?= patientDashboardEscape($dn) ?></td><td><?= patientDashboardEscape($a['service_type'] ?? 'Dental Service') ?></td><td><span class="status-badge <?= $badge ?>"><?= patientDashboardEscape($st) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if($nc===0): ?><tr><td colspan="5"><div class="empty-state">No missed or cancelled appointments.</div></td></tr><?php endif; ?>
                        </tbody></table></div>
                    </div>
                </div>
            </div>

            <!-- MY DENTAL JOURNEY (CHARTS) -->

            <div class="journey-section">

                <div class="journey-title">
                    <h3>
                        <span class="journey-icon">
                            <i class="bi bi-activity"></i>
                        </span>
                        My Dental Journey
                    </h3>
                    <span class="journey-sub">Your personal visit record at Abalos Dental Clinic</span>
                </div>

                <div class="journey-grid accordion journey-accordion" id="journeyAccordion">

                    <!-- 1. VISIT HISTORY -->
                    <div class="accordion-item journey-card">
                        <h2 class="accordion-header">
                            <button class="accordion-button journey-top journey-visits-top" type="button" data-bs-toggle="collapse" data-bs-target="#collapseVisits" aria-expanded="true" aria-controls="collapseVisits">
                                <span class="journey-head">
                                    <span class="journey-badge jb-blue">
                                        <i class="bi bi-bar-chart-fill"></i>
                                    </span>
                                    <span>
                                        <span class="jt">My Visit History</span>
                                        <span class="jt-sm">Appointments · last 6 months</span>
                                    </span>
                                </span>
                                <span class="d-flex align-items-center gap-2">
                                    <span class="journey-pill" id="pVisitPill"><?= $totalAppointments ?> visits</span>
                                    <span class="journey-chev"><i class="bi bi-chevron-down"></i></span>
                                </span>
                            </button>
                        </h2>
                        <div id="collapseVisits" class="accordion-collapse collapse show" data-bs-parent="#journeyAccordion">
                            <div class="accordion-body">
                                <div class="journey-body journey-visits">
                                    <canvas id="pChartVisits"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. STATUS BREAKDOWN -->
                    <div class="accordion-item journey-card">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed journey-top journey-visits-top" type="button" data-bs-toggle="collapse" data-bs-target="#collapseStatus" aria-expanded="false" aria-controls="collapseStatus">
                                <span class="journey-head">
                                    <span class="journey-badge jb-green">
                                        <i class="bi bi-pie-chart-fill"></i>
                                    </span>
                                    <span>
                                        <span class="jt">Treatment Status</span>
                                        <span class="jt-sm">My appointment breakdown</span>
                                    </span>
                                </span>
                                <span class="d-flex align-items-center gap-2">
                                    <span class="journey-pill pill-green"><?= $pCompletionRate ?>% done</span>
                                    <span class="journey-chev"><i class="bi bi-chevron-down"></i></span>
                                </span>
                            </button>
                        </h2>
                        <div id="collapseStatus" class="accordion-collapse collapse" data-bs-parent="#journeyAccordion">
                            <div class="accordion-body">
                                <div class="journey-body journey-doughnut">
                                    <canvas id="pChartStatus"></canvas>
                                </div>
                                <div class="progress-strip">
                                    <div class="progress-row">
                                        <span>Treatment completion</span>
                                        <strong><?= $pCompletionRate ?>%</strong>
                                    </div>
                                    <div class="progress-track">
                                        <div class="progress-fill" style="width:<?= max(0, min(100, $pCompletionRate)) ?>%;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

            </div>

        </div>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>

/* =========================
   MY DENTAL JOURNEY CHARTS
========================= */
(function initPatientCharts(){
  if (typeof Chart === "undefined") return;

  Chart.defaults.font.family = "'Inter','DM Sans',system-ui,sans-serif";
  Chart.defaults.color = "#3d5965";
  Chart.defaults.font.size = 11;
  Chart.defaults.font.weight = 600;

  var tooltipStyle = {
    backgroundColor: "#0A3B34",
    titleColor: "#ffffff",
    bodyColor: "#D9F2E6",
    padding: 12,
    cornerRadius: 10,
    displayColors: true,
    boxPadding: 4,
    titleFont: { weight: "700", size: 12 }
  };

  var visitLabels = <?= json_encode($pChartMonthlyLabels) ?>;
  var visitData = <?= json_encode($pChartMonthlyData) ?>;
  var statusLabels = <?= json_encode($pChartStatusLabels) ?>;
  var statusData = <?= json_encode($pChartStatusData) ?>;
  var statusTotal = statusData.reduce(function(a,b){ return a + (parseInt(b,10)||0); }, 0);
  var pVisitsChart = null, pStatusChart = null;

  var visitsEl = document.getElementById("pChartVisits");
  if (visitsEl) {
    var vctx = visitsEl.getContext("2d");
    var vgrad = vctx.createLinearGradient(0, 0, 0, 260);
    vgrad.addColorStop(0, "#149A72");
    vgrad.addColorStop(1, "#0A4A41");
    pVisitsChart = new Chart(visitsEl, {
      type: "bar",
      data: {
        labels: visitLabels,
        datasets: [{
          label: "My visits",
          data: visitData,
          backgroundColor: vgrad,
          hoverBackgroundColor: "#0E6B5E",
          borderRadius: 10,
          borderSkipped: false,
          maxBarThickness: 46
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: 0 },
        plugins: {
          legend: { display: false },
          tooltip: Object.assign({}, tooltipStyle, {
            callbacks: { label: function(c){ return " " + c.parsed.y + " visit" + (c.parsed.y === 1 ? "" : "s"); } }
          })
        },
        scales: {
          y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, grid: { color: "#edf3f4" }, border: { display: false }, title: { display: true, text: "Number of Visits", color: "#0A4A41", font: { size: 10, weight: "700" }, padding: 2 } },
          x: { grid: { display: false }, border: { display: false }, title: { display: true, text: "Month", color: "#0A4A41", font: { size: 10, weight: "700" }, padding: 2 } }
        }
      }
    });
  }

  var statusSliceLabels = {
    id: "statusSliceLabels",
    afterDatasetsDraw: function(chart) {
      var meta = chart.getDatasetMeta(0);
      if (!meta || !meta.data) return;
      var ctx = chart.ctx;
      ctx.save();
      ctx.font = "700 11px Inter, system-ui, sans-serif";
      ctx.textBaseline = "middle";
      function roundBox(x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
      }
      var padX = 6, bh = 18, gap = 6;
      var items = [];
      meta.data.forEach(function(arc, i) {
        var val = parseInt(statusData[i], 10) || 0;
        if (!val || statusTotal <= 0) return;
        var pct = Math.round((val / statusTotal) * 100);
        var p = arc.getProps(["x", "y", "startAngle", "endAngle", "outerRadius"], true);
        var mid = (p.startAngle + p.endAngle) / 2;
        var cos = Math.cos(mid), sin = Math.sin(mid);
        var right = cos >= 0;
        var edgeX = p.x + cos * p.outerRadius;
        var edgeY = p.y + sin * p.outerRadius;
        var elbowX = p.x + cos * (p.outerRadius + 10);
        var elbowY = p.y + sin * (p.outerRadius + 10);
        var text = statusLabels[i] + " " + val + " \u00B7 " + pct + "%";
        var bw = ctx.measureText(text).width + padX * 2;
        var anchorX = p.x + cos * (p.outerRadius + 24);
        items.push({
          text: text, bw: bw, right: right,
          edgeX: edgeX, edgeY: edgeY,
          elbowX: elbowX, elbowY: elbowY,
          anchorX: anchorX, ly: elbowY
        });
      });
      var area = chart.chartArea;
      ["right", "left"].forEach(function(side) {
        var isRight = (side === "right");
        var col = items.filter(function(it) { return it.right === isRight; })
                       .sort(function(a, b) { return a.ly - b.ly; });
        if (!col.length) return;
        var k, j;
        for (k = 1; k < col.length; k++) {
          var need = col[k - 1].ly + bh + gap;
          if (col[k].ly < need) col[k].ly = need;
        }
        var bottom = area.bottom - 2 - bh / 2;
        var over = col[col.length - 1].ly - bottom;
        if (over > 0) {
          for (j = 0; j < col.length; j++) col[j].ly -= over;
          for (k = col.length - 2; k >= 0; k--) {
            var needUp = col[k + 1].ly - bh - gap;
            if (col[k].ly > needUp) col[k].ly = needUp;
          }
        }
        var top = area.top + 2 + bh / 2;
        if (col[0].ly < top) {
          var under = top - col[0].ly;
          for (j = 0; j < col.length; j++) col[j].ly += under;
        }
      });
      items.forEach(function(it) {
        var minBX = area.left + 2, maxBX = area.right - 2 - it.bw;
        if (minBX > maxBX) { minBX = (area.left + area.right - it.bw) / 2; maxBX = minBX; }
        var wantBX = it.right ? it.anchorX : it.anchorX - it.bw;
        it.bx = Math.min(Math.max(wantBX, minBX), maxBX);
      });
      items.forEach(function(it) {
        var bx = it.bx;
        var by = it.ly - bh / 2;
        var boxEdgeX = it.right ? bx : bx + it.bw;
        ctx.lineWidth = 1.25;
        ctx.strokeStyle = "#9CC4B6";
        ctx.beginPath();
        ctx.moveTo(it.edgeX, it.edgeY);
        ctx.lineTo(it.elbowX, it.elbowY);
        ctx.lineTo(boxEdgeX, it.ly);
        ctx.stroke();
        ctx.fillStyle = "#0E6B5E";
        ctx.beginPath();
        ctx.arc(it.edgeX, it.edgeY, 2.5, 0, Math.PI * 2);
        ctx.fill();
        roundBox(bx, by, it.bw, bh, 5);
        ctx.fillStyle = "#ffffff";
        ctx.fill();
        ctx.strokeStyle = "#B9E4CF";
        ctx.stroke();
        ctx.fillStyle = "#143f51";
        ctx.textAlign = "left";
        ctx.fillText(it.text, bx + padX, it.ly + 0.5);
      });
      var firstArc = meta.data[0];
      if (firstArc) {
        var cc = firstArc.getProps(["x", "y"], true);
        ctx.textAlign = "center";
        ctx.fillStyle = "#143f51";
        ctx.font = "800 26px Fraunces, 'Plus Jakarta Sans', Inter, system-ui, sans-serif";
        ctx.fillText(String(statusTotal), cc.x, cc.y - 4);
        ctx.fillStyle = "#143f51";
        ctx.font = "700 10px Inter, system-ui, sans-serif";
        ctx.fillText("Total", cc.x, cc.y + 18);
      }
      ctx.restore();
    }
  };

  var statusEl = document.getElementById("pChartStatus");
  if (statusEl) {
    pStatusChart = new Chart(statusEl, {
      type: "doughnut",
      plugins: [statusSliceLabels],
      data: {
        labels: statusLabels,
        datasets: [{
          data: statusData,
          backgroundColor: ["#022c22", "#064e3b", "#065f46", "#10b981", "#6ee7b7"],
          hoverBackgroundColor: ["#022c22", "#064e3b", "#047857", "#0d9d6c", "#34d399"],
          borderColor: "#ffffff",
          borderWidth: 4,
          hoverOffset: 8,
          borderRadius: 6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: "68%",
        layout: { padding: 24 },
        plugins: {
          legend: { position: "bottom", labels: { boxWidth: 11, boxHeight: 11, borderRadius: 4, useBorderRadius: true, padding: 12, font: { size: 11, weight: "600" } } },
          tooltip: Object.assign({}, tooltipStyle, {
            callbacks: {
              label: function(c){
                var pct = statusTotal > 0 ? Math.round((c.parsed / statusTotal) * 100) : 0;
                return " " + c.label + ": " + c.parsed + " (" + pct + "%)";
              }
            }
          })
        }
      }
    });
  }

  var colV = document.getElementById("collapseVisits");
  if (colV) colV.addEventListener("shown.bs.collapse", function(){ if (pVisitsChart) pVisitsChart.resize(); });
  var colS = document.getElementById("collapseStatus");
  if (colS) colS.addEventListener("shown.bs.collapse", function(){ if (pStatusChart) pStatusChart.resize(); });
})();
</script>
<script>
/* =====================================================
   CLICKABLE SUMMARY CARDS — gaya ng admin dashboard
   Same page, no new tab. Mini cards + tabs nagfi-filter.
===================================================== */
(function initPatientCardDetails(){
  var VIEWS = ["today","upcoming","pending","confirmed","completed","noshow-cancelled"];
  var KEY = "patientCardDetailView";
  var wrap = document.getElementById("cardDetailWrap");
  if (!wrap) return;
  var cards = Array.prototype.slice.call(document.querySelectorAll("#statsGrid .stat-clickable[data-view]"));
  var statsGrid = document.getElementById("statsGrid");
  var welcomeHero = document.getElementById("welcomeHero");

  function panels(){ return VIEWS.map(function(v){ return document.getElementById("detail-" + v); }).filter(Boolean); }
  function getNav(){ return document.getElementById("detailNav"); }

  function hidePanelsOnly(){
    panels().forEach(function(p){ p.style.display = "none"; });
    cards.forEach(function(c){ c.classList.remove("active-card"); });
    document.querySelectorAll(".detail-back-row").forEach(function(r){ r.classList.remove("is-open"); });
  }

  function showBackRowFor(panel){
    document.querySelectorAll(".detail-back-row").forEach(function(r){
      var match = r.getAttribute("data-for") === panel.id;
      r.classList.toggle("is-open", match);
    });
  }

  function closeAll(){
    hidePanelsOnly();
    if (statsGrid) statsGrid.style.display = "";
    if (welcomeHero) welcomeHero.style.display = "";
    var nav2 = getNav();
    if (nav2) {
      nav2.style.display = "none";
      nav2.querySelectorAll(".detail-nav-card").forEach(function(n){
        n.classList.remove("active-nav");
        n.style.display = "";
      });
    }
    try { sessionStorage.removeItem(KEY); } catch(e){}
    try { history.replaceState(null, "", window.location.pathname + window.location.search); } catch(e){}
    if (statsGrid && statsGrid.scrollIntoView) {
      setTimeout(function(){ statsGrid.scrollIntoView({ behavior: "smooth", block: "start" }); }, 80);
    }
  }

  // "Back to Dashboard" button placed above each detail card
  panels().forEach(function(panel){
    if (panel.querySelector(".detail-back-btn")) return;
    if (panel.parentNode && panel.parentNode.querySelector(".detail-back-row[data-for='" + panel.id + "']")) return;

    var backBtn = document.createElement("button");
    backBtn.type = "button";
    backBtn.className = "detail-back-btn";
    backBtn.innerHTML = '<i class="bi bi-arrow-left"></i> Back to Dashboard';
    backBtn.addEventListener("click", closeAll);

    var row = document.createElement("div");
    row.className = "detail-back-row";
    row.setAttribute("data-for", panel.id);
    row.appendChild(backBtn);

    if (panel.parentNode) panel.parentNode.insertBefore(row, panel);
  });

  document.querySelectorAll("[data-close-detail]").forEach(function(x){
    x.addEventListener("click", closeAll);
  });

  function show(view, scroll, fromNav){
    var panel = document.getElementById("detail-" + view);
    if (!panel) return;
    var alreadyOpen = panel.style.display !== "none";
    var nav = getNav();

    // Clicking the same card again toggles it closed
    if (alreadyOpen) {
      if (fromNav) {
        if (nav) {
          nav.querySelectorAll(".detail-nav-card").forEach(function(n){
            n.classList.toggle("active-nav", n.getAttribute("data-goto") === view);
          });
        }
        return;
      }
      closeAll();
      if (scroll !== false && statsGrid && statsGrid.scrollIntoView) {
        setTimeout(function(){ statsGrid.scrollIntoView({ behavior: "smooth", block: "start" }); }, 80);
      }
      return;
    }

    hidePanelsOnly();
    panel.style.display = "block";
    showBackRowFor(panel);
    if (statsGrid) statsGrid.style.display = "none";
    if (welcomeHero) welcomeHero.style.display = "none";
    cards.forEach(function(c){ c.classList.toggle("active-card", c.getAttribute("data-view") === view); });

    if (nav) {
      nav.style.display = "grid";
      nav.querySelectorAll(".detail-nav-card").forEach(function(n){
        var isCurrent = n.getAttribute("data-goto") === view;
        n.classList.toggle("active-nav", isCurrent);
        n.style.display = isCurrent ? "none" : "";
      });
    }

    try { sessionStorage.setItem(KEY, view); } catch(e){}
    try { history.replaceState(null, "", "#card-" + view); } catch(e){ window.location.hash = "card-" + view; }
    if (scroll !== false && wrap && wrap.scrollIntoView) {
      setTimeout(function(){ wrap.scrollIntoView({ behavior: "smooth", block: "start" }); }, 80);
    }
  }

  document.querySelectorAll("#detailNav .detail-nav-card").forEach(function(navCard){
    var goNav = function(){ show(navCard.getAttribute("data-goto"), true, true); };
    navCard.addEventListener("click", goNav);
    navCard.addEventListener("keydown", function(ev){
      if (ev.key === "Enter" || ev.key === " ") { ev.preventDefault(); goNav(); }
    });
  });

  cards.forEach(function(card){
    card.addEventListener("click", function(){ show(card.getAttribute("data-view")); });
    card.addEventListener("keydown", function(ev){
      if (ev.key === "Enter" || ev.key === " ") { ev.preventDefault(); show(card.getAttribute("data-view")); }
    });
  });

  function applyDetailFilter(card, group, filter){
    var f = (filter || "all").toLowerCase();
    var rows = Array.prototype.slice.call(card.querySelectorAll("tbody tr[data-status], tbody tr[data-today]"));
    rows.forEach(function(r){
      var keep = false;
      if (f === "all") keep = true;
      else if (group === "total") keep = (r.getAttribute("data-status") || "").toLowerCase() === f;
      else {
        if (f === "today") keep = r.getAttribute("data-today") === "1";
        else if (f === "week") keep = r.getAttribute("data-week") === "1";
        else if (f === "month") keep = r.getAttribute("data-month") === "1";
      }
      r.style.display = keep ? "" : "none";
    });
  }

  document.querySelectorAll(".card-detail").forEach(function(card){
    var tabWrap = card.querySelector(".detail-tabs");
    var miniWrap = card.querySelector(".detail-mini-grid[data-mini-group]");
    var group = "";
    if (tabWrap) group = tabWrap.getAttribute("data-tab-group") || "";
    else if (miniWrap) group = miniWrap.getAttribute("data-mini-group") || "";
    if (!group) return;
    function syncActive(filter){
      var f = (filter || "all").toLowerCase();
      if (tabWrap) tabWrap.querySelectorAll(".detail-tab").forEach(function(t){ t.classList.toggle("active", (t.getAttribute("data-filter") || "all").toLowerCase() === f); });
      if (miniWrap) miniWrap.querySelectorAll(".detail-mini-clickable").forEach(function(m){ m.classList.toggle("active-mini", (m.getAttribute("data-filter") || "all").toLowerCase() === f); });
    }
    if (tabWrap) tabWrap.querySelectorAll(".detail-tab").forEach(function(tab){
      tab.addEventListener("click", function(){ var f = tab.getAttribute("data-filter") || "all"; syncActive(f); applyDetailFilter(card, group, f); });
    });
    if (miniWrap) miniWrap.querySelectorAll(".detail-mini-clickable").forEach(function(mini){
      var go = function(){ var f = mini.getAttribute("data-filter") || "all"; syncActive(f); applyDetailFilter(card, group, f); };
      mini.addEventListener("click", go);
      mini.addEventListener("keydown", function(ev){ if (ev.key === "Enter" || ev.key === " ") { ev.preventDefault(); go(); } });
    });
    syncActive("all");
  });

  // Restore active view if hash or session is set, otherwise default closed
  var initialView = "";
  try {
    var hash = (window.location.hash || "").replace("#card-", "");
    if (VIEWS.indexOf(hash) !== -1) initialView = hash;
  } catch(e){}
  if (!initialView) {
    try {
      var saved = sessionStorage.getItem(KEY);
      if (saved && VIEWS.indexOf(saved) !== -1) initialView = saved;
    } catch(e){}
  }
  if (initialView) {
    setTimeout(function(){ show(initialView, false); }, 50);
  } else {
    closeAll();
  }
})();
</script>

<div class="modal fade" id="todayModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content today-modal-content">
      <div class="modal-header today-modal-header">
        <div class="d-flex align-items-center gap-3">
          <span class="today-modal-icon"><i class="bi bi-calendar2-day-fill"></i></span>
          <div>
            <h5 class="modal-title mb-0">Today's Appointments</h5>
            <small class="today-modal-date"><?= date('F d, Y') ?></small>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body today-modal-body">
        <?php
          $todayApptList = array_filter($detailAppointments, function($a) use ($pDetailToday) {
              return substr((string)($a['appointment_date'] ?? ''), 0, 10) === $pDetailToday;
          });
        ?>
        <?php if (empty($todayApptList)): ?>
          <div class="today-empty">
            <i class="bi bi-calendar2-x"></i>
            <p>No appointments scheduled for today.</p>
          </div>
        <?php else: ?>
          <div class="today-table-wrapper">
            <table class="today-modal-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Patient Name</th>
                  <th>Time of Appointment</th>
                  <th>Dental Service</th>
                  <th>Assigned Dentist</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php $ttn = 0; foreach ($todayApptList as $a): $ttn++;
                    $dt = '—';
                    if (!empty($a['start_time'])) {
                        $ts = strtotime($a['start_time']);
                        if ($ts) $dt = date('h:i A', $ts);
                    }
                    $dn = trim($a['dentist_name'] ?? '') !== '' ? $a['dentist_name'] : 'Not assigned';
                    $st = trim($a['status'] ?? 'Pending');
                    $fk = patientCardStatusKey($st);
                    $badge = 'status-pending';
                    if ($fk === 'confirmed') $badge = 'status-confirmed';
                    elseif ($fk === 'completed') $badge = 'status-completed';
                    elseif ($fk === 'cancelled' || $fk === 'no-show') $badge = 'status-cancelled';
                    $apptDateText = !empty($a['appointment_date']) ? date('M d, Y', strtotime($a['appointment_date'])) : '—';
                    $bookedText = !empty($a['created_at']) ? date('M d, Y · h:i A', strtotime($a['created_at'])) : '';
                ?>
                <tr>
                  <td><?= $ttn ?></td>
                  <td><?= patientDashboardEscape($fullName) ?></td>
                  <td><?= patientDashboardEscape($dt) ?></td>
                  <td><?= patientDashboardEscape($a['service_type'] ?? 'Dental Service') ?></td>
                  <td><?= patientDashboardEscape($dn) ?></td>
                  <td><span class="status-badge <?= $badge ?>"><?= patientDashboardEscape($st) ?></span></td>
                  <td>
                    <button
                      type="button"
                      class="today-view-btn"
                      data-patient="<?= patientDashboardEscape($fullName) ?>"
                      data-service="<?= patientDashboardEscape($a['service_type'] ?? 'Dental Service') ?>"
                      data-dentist="<?= patientDashboardEscape($dn) ?>"
                      data-date="<?= patientDashboardEscape($apptDateText) ?>"
                      data-time="<?= patientDashboardEscape($dt) ?>"
                      data-status="<?= patientDashboardEscape($st) ?>"
                      data-status-class="<?= $badge ?>"
                      data-booked="<?= patientDashboardEscape($bookedText) ?>"
                      data-notes="<?= patientDashboardEscape($a['notes'] ?? '') ?>"
                    ><i class="bi bi-eye"></i> View</button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- TODAY APPOINTMENT VIEW DETAILS MODAL -->
<div class="modal fade" id="todayViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;overflow:visible;">
      <div class="modal-header" style="background:linear-gradient(135deg,#3B82F6,#1D4ED8) !important;border-bottom:none !important;">
        <h5 class="modal-title" style="color:#ffffff !important;font-weight:800;"><i class="bi bi-eye-fill"></i> Appointment Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div style="width:48px;height:48px;border-radius:14px;background:#EBF2FF;color:#3B82F6;display:inline-flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;">
            <i class="bi bi-calendar2-check-fill"></i>
          </div>
          <div>
            <div style="font-size:16px;font-weight:800;color:#0f172a;" id="tvPatientName">—</div>
            <span class="status-badge" id="tvStatus"></span>
          </div>
        </div>
        <table style="width:100%;font-size:13.5px;color:#1e293b;border-collapse:collapse;">
          <tr style="border-bottom:1px solid #edf2f7;">
            <td style="padding:10px 0;font-weight:700;color:#0f172a;width:40%;"><i class="bi bi-heart-pulse me-1 text-primary"></i> Dental Service</td>
            <td style="padding:10px 0;font-weight:600;color:#0f172a;" id="tvService">—</td>
          </tr>
          <tr style="border-bottom:1px solid #edf2f7;">
            <td style="padding:10px 0;font-weight:700;color:#0f172a;"><i class="bi bi-person-badge me-1 text-primary"></i> Assigned Dentist</td>
            <td style="padding:10px 0;font-weight:600;color:#0f172a;" id="tvDentist">—</td>
          </tr>
          <tr style="border-bottom:1px solid #edf2f7;">
            <td style="padding:10px 0;font-weight:700;color:#0f172a;"><i class="bi bi-calendar-event me-1 text-primary"></i> Date</td>
            <td style="padding:10px 0;font-weight:600;color:#0f172a;" id="tvDate">—</td>
          </tr>
          <tr style="border-bottom:1px solid #edf2f7;">
            <td style="padding:10px 0;font-weight:700;color:#0f172a;"><i class="bi bi-clock me-1 text-primary"></i> Time</td>
            <td style="padding:10px 0;font-weight:600;color:#0f172a;" id="tvTime">—</td>
          </tr>
          <tr style="border-bottom:1px solid #edf2f7;">
            <td style="padding:10px 0;font-weight:700;color:#0f172a;"><i class="bi bi-bookmark-check me-1 text-primary"></i> Booked On</td>
            <td style="padding:10px 0;font-weight:600;color:#0f172a;" id="tvBooked">—</td>
          </tr>
          <tr id="tvNotesRow">
            <td style="padding:10px 0;font-weight:700;color:#0f172a;vertical-align:top;"><i class="bi bi-journal-text me-1 text-primary"></i> Notes</td>
            <td style="padding:10px 0;white-space:pre-line;font-weight:600;color:#0f172a;" id="tvNotes">—</td>
          </tr>
        </table>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
/* Today's Appointments — View button: buksan ang details modal */
document.querySelectorAll('.today-view-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    document.getElementById('tvPatientName').textContent = btn.getAttribute('data-patient') || '—';
    var statusEl = document.getElementById('tvStatus');
    statusEl.textContent = btn.getAttribute('data-status') || '';
    statusEl.className = 'status-badge ' + (btn.getAttribute('data-status-class') || '');
    document.getElementById('tvService').textContent = btn.getAttribute('data-service') || '—';
    document.getElementById('tvDentist').textContent = btn.getAttribute('data-dentist') || '—';
    document.getElementById('tvDate').textContent = btn.getAttribute('data-date') || '—';
    document.getElementById('tvTime').textContent = btn.getAttribute('data-time') || '—';
    document.getElementById('tvBooked').textContent = btn.getAttribute('data-booked') || '—';
    var notes = btn.getAttribute('data-notes') || '';
    document.getElementById('tvNotes').textContent = notes !== '' ? notes : '—';
    document.getElementById('tvNotesRow').style.display = notes !== '' ? '' : 'none';
    new bootstrap.Modal(document.getElementById('todayViewModal')).show();
  });
});
</script>

</body>

</html>