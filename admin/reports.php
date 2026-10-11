<?php

require_once __DIR__ . '/../functions.php';

requireLogin();

function reportValue($pdo, $sql, $params = [])
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() ?: 0;
    } catch (PDOException $e) {
        return 0;
    }
}

function reportRows($pdo, $sql, $params = [])
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

$period = $_GET['period'] ?? 'month';
if (!in_array($period, ['day', 'week', 'month'], true)) {
    $period = 'month';
}

$totalAppointments = (int) reportValue($pdo, 'SELECT COUNT(*) FROM appointments');
$completedAppointments = (int) reportValue($pdo, "SELECT COUNT(*) FROM appointments WHERE status = 'Completed'");
$cancelledAppointments = (int) reportValue($pdo, "SELECT COUNT(*) FROM appointments WHERE status = 'Cancelled'");
$pendingAppointments = (int) reportValue($pdo, "SELECT COUNT(*) FROM appointments WHERE status = 'Pending'");
$confirmedAppointments = (int) reportValue($pdo, "SELECT COUNT(*) FROM appointments WHERE status IN ('Confirmed', 'Approved')");

$totalPatients = (int) reportValue($pdo, 'SELECT COUNT(*) FROM patients');
$newPatients = (int) reportValue($pdo, "SELECT COUNT(*) FROM patients WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$activePatients = (int) reportValue($pdo, "
    SELECT COUNT(DISTINCT patient_id)
    FROM appointments
    WHERE status NOT IN ('Cancelled', 'No-Show')
");

$appointmentsByPeriod = [];
if ($period === 'day') {
    $appointmentsByPeriod = reportRows($pdo, "
        SELECT appointment_date AS period_label, COUNT(*) AS total
        FROM appointments
        GROUP BY appointment_date
        ORDER BY appointment_date DESC
        LIMIT 31
    ");
} elseif ($period === 'week') {
    $appointmentsByPeriod = reportRows($pdo, "
        SELECT CONCAT(YEAR(appointment_date), '-W', LPAD(WEEK(appointment_date, 1), 2, '0')) AS period_label,
               COUNT(*) AS total
        FROM appointments
        GROUP BY YEAR(appointment_date), WEEK(appointment_date, 1)
        ORDER BY YEAR(appointment_date) DESC, WEEK(appointment_date, 1) DESC
        LIMIT 12
    ");
} else {
    $appointmentsByPeriod = reportRows($pdo, "
        SELECT DATE_FORMAT(appointment_date, '%Y-%m') AS period_label, COUNT(*) AS total
        FROM appointments
        GROUP BY DATE_FORMAT(appointment_date, '%Y-%m')
        ORDER BY period_label DESC
        LIMIT 12
    ");
}

$appointmentsPerDentist = reportRows($pdo, "
    SELECT d.dentist_name,
           COUNT(a.id) AS total,
           SUM(a.status = 'Completed') AS completed
    FROM dentists d
    LEFT JOIN appointments a ON a.dentist_id = d.id
    GROUP BY d.id, d.dentist_name
    ORDER BY total DESC, d.dentist_name ASC
");

require_once __DIR__ . '/demand_forecast.php';

$historicalDemand = reportRows($pdo, "
    SELECT DAYNAME(appointment_date) AS day_name,
           DAYOFWEEK(appointment_date) AS day_number,
           COUNT(*) AS total
    FROM appointments
    WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
      AND appointment_date <= CURDATE()
      AND status NOT IN ('Cancelled', 'Canceled')
    GROUP BY DAYOFWEEK(appointment_date), DAYNAME(appointment_date)
    ORDER BY total DESC
");

$forecastDemand = [];
$forecastTotal = 0;

if (!empty($demandForecastResult['forecast_days'])) {
    foreach ($demandForecastResult['forecast_days'] as $fDay) {
        $forecastCount = (int) ($fDay['predicted_appointments'] ?? 0);
        $forecastDemand[] = [
            'date' => $fDay['date'],
            'day' => $fDay['day'],
            'ml_prediction' => (int) ($fDay['ml_prediction'] ?? 0),
            'actual_booked' => (int) ($fDay['actual_booked'] ?? 0),
            'demand_level' => $fDay['demand_level'] ?? 'Low',
            'total' => $forecastCount
        ];
        $forecastTotal += $forecastCount;
    }
}

$openForecastRows = array_values(array_filter($forecastDemand, function ($row) {
    return ($row['day'] ?? '') !== 'Sunday';
}));

$forecastAverage = count($openForecastRows) > 0
    ? array_sum(array_column($openForecastRows, 'total')) / count($openForecastRows)
    : 0;

$busyDays = array_filter($forecastDemand, function ($row) use ($forecastAverage) {
    return ($row['demand_level'] ?? '') === 'High' || ($row['total'] > 0 && $row['total'] >= max(1, ceil($forecastAverage)));
});

$lowDemandDays = array_filter($forecastDemand, function ($row) use ($forecastAverage) {
    return ($row['day'] ?? '') !== 'Sunday' && (($row['demand_level'] ?? '') !== 'High' && $row['total'] < $forecastAverage);
});

$displayForecast = $forecastDemand;

$aiPredictionCount = !empty($demandForecastResult['success']) ? count($forecastDemand) : 0;

/* Detailed Patient History for Patient Report */
$patientHistoryRows = reportRows($pdo, "
    SELECT
        p.id,
        p.patient_number,
        CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
        p.contact_number,
        p.email,
        COUNT(a.id) AS total_appointments,
        SUM(CASE WHEN a.status = 'Completed' THEN 1 ELSE 0 END) AS completed_count,
        SUM(CASE WHEN a.status IN ('Cancelled', 'Canceled', 'No-Show') THEN 1 ELSE 0 END) AS missed_or_cancelled,
        MAX(a.appointment_date) AS last_appointment_date,
        (
            SELECT a2.service_type
            FROM appointments a2
            WHERE a2.patient_id = p.id
            ORDER BY a2.appointment_date DESC, a2.start_time DESC
            LIMIT 1
        ) AS last_service
    FROM patients p
    LEFT JOIN appointments a ON a.patient_id = p.id
    GROUP BY p.id, p.patient_number, p.first_name, p.last_name, p.contact_number, p.email
    HAVING COUNT(a.id) > 0
    ORDER BY last_appointment_date DESC, total_appointments DESC
    LIMIT 50
");

/* Detailed Appointment Records for Reference */
$detailedAppointments = reportRows($pdo, "
    SELECT
        a.id,
        a.appointment_date,
        a.start_time,
        a.end_time,
        a.service_type,
        a.status,
        CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
        p.patient_number,
        p.contact_number,
        d.dentist_name
    FROM appointments a
    LEFT JOIN patients p ON p.id = a.patient_id
    LEFT JOIN dentists d ON d.id = a.dentist_id
    ORDER BY a.appointment_date DESC, a.start_time DESC
    LIMIT 50
");

/* Priority Rescheduling Candidates (No-Show, Cancelled, or Pending) matched with Low-Demand Open Days */
$rescheduleCandidates = reportRows($pdo, "
    SELECT
        a.id,
        a.appointment_date,
        a.start_time,
        a.service_type,
        a.status,
        CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
        p.contact_number,
        d.dentist_name
    FROM appointments a
    INNER JOIN patients p ON p.id = a.patient_id
    LEFT JOIN dentists d ON d.id = a.dentist_id
    WHERE a.status IN ('No-Show', 'Cancelled', 'Pending')
      AND a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    ORDER BY FIELD(a.status, 'Pending', 'No-Show', 'Cancelled'), a.appointment_date DESC
    LIMIT 8
");

$recommendedSlots = $openForecastRows;
usort($recommendedSlots, function ($a, $b) {
    return ((int)($a['total'] ?? 0)) <=> ((int)($b['total'] ?? 0));
});

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports | Abalos Dental Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/style.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22) !important; background-size: 300% 300% !important; animation: adminBgAnim 8s ease infinite !important; background-attachment: fixed !important; }
@keyframes adminBgAnim { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        .shared-sidebar { display: none !important; }
        .shared-topbar { left: 0 !important; right: 0 !important; }
        .reports-page { margin-left: 0 !important; width: 100% !important; padding: 110px 24px 40px !important; background: transparent !important; min-height: 100vh; }
        .reports-page > .mb-3, .reports-page > .alert { margin-left: 14px; margin-right: 14px; }
        .reports-head { margin: 0 14px 16px 14px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; }
        .reports-head h1 { color: #ffffff !important; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; margin: 0; }
        .reports-head p { margin: 4px 0 0; color: #C9E9DA; font-size: 13px; }
        .report-card { background: #ffffff !important; border: 1px solid #C9E9DA !important; border-radius: 16px !important; overflow: hidden !important; box-shadow: 0 8px 24px rgba(14,107,94,.08) !important; padding: 20px !important; height: 100%; margin-bottom: 0; }
        .report-card-head { padding: 18px 20px; background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%); border-bottom: 1px solid #C9E9DA; }
        .report-card-body { padding: 16px 20px 20px; }
        .report-card h2, .report-card h3 { color: #143f51 !important; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; letter-spacing: -0.02em; margin: 0; }
        .report-card h2 { font-size: 17px !important; display: flex; align-items: center; gap: 8px; }
        .report-card h2 i { color: #0E6B5E; font-size: 18px; }
        .report-card h3 { font-size: 15.5px; }
        .report-card .card-sub { margin: 3px 0 0 26px; color: #8aa0a8; font-size: 12px; }
        .report-number { color: #143f51; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-size: 25px; font-weight: 800; font-variant-numeric: tabular-nums; letter-spacing: -0.02em; }
        .report-label { color: #8aa0a8; font-size: 12.5px; font-weight: 600; }
        .stat-icon { width: 42px; height: 42px; border-radius: 12px; background: #EAF7F0; color: #0E6B5E; border: 1px solid #C9E9DA; display: inline-flex; align-items: center; justify-content: center; font-size: 19px; margin-bottom: 10px; }
        .report-table { margin-bottom: 0 !important; width: 100%; border-collapse: separate; border-spacing: 0; background: #ffffff; }
        .report-table thead tr { background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important; }
        .report-table thead th { background: transparent !important; color: #ffffff !important; font-size: 11.5px !important; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; white-space: nowrap; padding: 14px 16px !important; border-bottom: none !important; border-right: 1px solid rgba(255,255,255,.12) !important; }
        .report-table thead th:last-child { border-right: none !important; }
        .report-table tbody td { padding: 14px 16px !important; color: #4d6873 !important; font-size: 13.5px !important; font-family: "Inter", "DM Sans", system-ui, sans-serif; vertical-align: middle; background: #ffffff; border-bottom: 1px solid #E6F2EC !important; }
        .report-table tbody tr:nth-child(even) td { background: #F6FBF8 !important; }
        .report-table tbody tr:hover td { background: #EAF7F0 !important; }
        .demand-row { display: flex; justify-content: space-between; gap: 15px; padding: 12px 14px; background: #F6FBF8; border: 1px solid #E6F2EC; border-radius: 10px; margin-bottom: 8px; font-size: 13.5px; color: #4d6873; }
        .demand-row strong { color: #143f51; }
        .demand-high { border-left: 4px solid #D44B4B; }
        .demand-moderate { border-left: 4px solid #E8A800; }
        .demand-low { border-left: 4px solid #149A72; }
        .reports-page h1.h4 { color: #ffffff !important; }
        .reports-page .text-muted { color: #C9E9DA !important; }
        .report-card .text-muted, .accordion-body .text-muted { color: #6c858d !important; }
        .reports-page .form-select { font-size: 13.5px; border-radius: 10px; border: 1px solid #D1E7DD; }
        .reports-page .alert { font-size: 13.5px; border-radius: 12px; }
        .report-accordion .accordion-item { background: #ffffff !important; border: 1px solid #C9E9DA !important; border-radius: 16px !important; overflow: hidden !important; box-shadow: 0 8px 24px rgba(14,107,94,.08) !important; margin-bottom: 12px; }
        .report-accordion .accordion-item:last-child { margin-bottom: 0; }
        .report-accordion .accordion-button { background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%) !important; color: #143f51 !important; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; font-size: 16px; letter-spacing: -0.02em; padding: 18px 20px; box-shadow: none !important; }
        .report-accordion .accordion-button i { color: #0E6B5E; font-size: 18px; margin-right: 8px; }
        .report-accordion .accordion-button:not(.collapsed) { background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important; color: #ffffff !important; }
        .report-accordion .accordion-button:not(.collapsed) i { color: #ffffff !important; }
        .report-accordion .accordion-button:focus { box-shadow: none !important; border-color: #C9E9DA !important; }
        .report-accordion .accordion-button small { display: block; font-weight: 500; font-size: 12px; color: #8aa0a8; font-family: "Inter", "DM Sans", system-ui, sans-serif; }
        .report-accordion .accordion-button:not(.collapsed) small { color: #C9E9DA; }
        .report-accordion .accordion-body { padding: 16px 20px 20px !important; }
        @media (max-width: 991px) { .reports-page { padding: 25px 16px 40px !important; } }
        @media print {
            body { background: #ffffff !important; animation: none !important; }
            .shared-topbar, .no-print { display: none !important; }
            .reports-page { padding: 0 !important; }
            .reports-page h1.h4 { color: #143f51 !important; }
            .accordion-collapse { display: block !important; height: auto !important; visibility: visible !important; }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes.php'; ?>

<main class="reports-page">
    <?php showFlash(); ?>

    <a href="dashboard.php" class="btn btn-outline-secondary btn-sm mb-3 no-print">
        <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
    </a>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h4 mb-1">Reports & Analytics</h1>
            <p class="text-muted small mb-0">Historical clinic performance, patient & appointment records, and AI demand insights.</p>
        </div>
        <div class="d-flex align-items-center gap-2 no-print">
            <form method="GET" class="d-flex gap-2">
                <select name="period" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="day" <?= $period === 'day' ? 'selected' : '' ?>>Per Day</option>
                    <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>Per Week</option>
                    <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>Per Month</option>
                </select>
            </form>
            <button type="button" class="btn btn-sm btn-light fw-bold" onclick="window.print()">
                <i class="bi bi-printer-fill me-1"></i>Print Report
            </button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ([
            ['Total appointments', $totalAppointments, 'calendar2-check-fill'],
            ['Completed appointments', $completedAppointments, 'check-circle-fill'],
            ['Cancelled appointments', $cancelledAppointments, 'x-circle-fill'],
            ['Pending appointments', $pendingAppointments, 'hourglass-split'],
            ['Confirmed appointments', $confirmedAppointments, 'calendar-check-fill']
        ] as $metric): ?>
            <div class="col-xl col-md-4 col-sm-6">
                <div class="report-card">
                    <i class="bi bi-<?= $metric[2] ?> text-info"></i>
                    <div class="report-label mt-2"><?= e($metric[0]) ?></div>
                    <div class="report-number"><?= (int) $metric[1] ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="accordion report-accordion mb-4" id="reportsAccordion">
        <div class="accordion-item">
            <h2 class="accordion-header" id="headingAppointment">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAppointment" aria-expanded="true" aria-controls="collapseAppointment">
                    <i class="bi bi-calendar2-check-fill"></i>
                    <span>Appointment Records & Reference History<small class="mb-0">Appointments per <?= e($period) ?> and detailed patient visit log.</small></span>
                </button>
            </h2>
            <div id="collapseAppointment" class="accordion-collapse collapse show" aria-labelledby="headingAppointment" data-bs-parent="#reportsAccordion">
                <div class="accordion-body">
                    <h3 class="mb-2">Summary by <?= e(ucfirst($period)) ?></h3>
                    <div class="table-responsive mb-4">
                        <table class="table report-table">
                            <thead><tr><th>Period</th><th>Total appointments</th></tr></thead>
                            <tbody>
                            <?php if (empty($appointmentsByPeriod)): ?>
                                <tr><td colspan="2" class="text-center text-muted">No appointment data yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($appointmentsByPeriod as $row): ?>
                                    <tr><td><?= e($row['period_label']) ?></td><td><?= (int) $row['total'] ?></td></tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <h3 class="mb-2">Detailed Appointment Reference Log (Recent 50 Records)</h3>
                    <div class="table-responsive">
                        <table class="table report-table">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Patient</th>
                                    <th>Contact</th>
                                    <th>Dental Service</th>
                                    <th>Dentist</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($detailedAppointments)): ?>
                                <tr><td colspan="6" class="text-center text-muted">No appointment records found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($detailedAppointments as $ar): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e(date('M d, Y', strtotime($ar['appointment_date']))) ?></strong><br>
                                            <small class="text-muted"><?= e(date('h:i A', strtotime($ar['start_time']))) ?> – <?= e(date('h:i A', strtotime($ar['end_time'] ?: $ar['start_time']))) ?></small>
                                        </td>
                                        <td>
                                            <strong><?= e($ar['patient_name'] ?: 'Patient') ?></strong><br>
                                            <small class="text-muted"><?= e($ar['patient_number'] ?: '') ?></small>
                                        </td>
                                        <td><?= e($ar['contact_number'] ?: '-') ?></td>
                                        <td><?= e($ar['service_type'] ?: '-') ?></td>
                                        <td><?= e($ar['dentist_name'] ?: 'Not assigned') ?></td>
                                        <td><strong><?= e($ar['status']) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header" id="headingPatient">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapsePatient" aria-expanded="false" aria-controls="collapsePatient">
                    <i class="bi bi-people-fill"></i>
                    <span>Patient Report & Clinical Visit History<small class="mb-0">Total, new, and active patients with complete visit summary for clinical reference.</small></span>
                </button>
            </h2>
            <div id="collapsePatient" class="accordion-collapse collapse" aria-labelledby="headingPatient" data-bs-parent="#reportsAccordion">
                <div class="accordion-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-4"><div class="demand-row mb-0"><span>Total patients</span><strong><?= $totalPatients ?></strong></div></div>
                        <div class="col-md-4"><div class="demand-row mb-0"><span>New patients (last 30 days)</span><strong><?= $newPatients ?></strong></div></div>
                        <div class="col-md-4"><div class="demand-row mb-0"><span>Active patients</span><strong><?= $activePatients ?></strong></div></div>
                    </div>
                    <h3 class="mb-2">Patient Clinical Reference & Visit History</h3>
                    <div class="table-responsive">
                        <table class="table report-table">
                            <thead>
                                <tr>
                                    <th>Patient ID</th>
                                    <th>Patient Name</th>
                                    <th>Contact & Email</th>
                                    <th>Total Visits</th>
                                    <th>Completed</th>
                                    <th>Missed / Cancelled</th>
                                    <th>Last Visit & Service</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($patientHistoryRows)): ?>
                                <tr><td colspan="7" class="text-center text-muted">No patient history available.</td></tr>
                            <?php else: ?>
                                <?php foreach ($patientHistoryRows as $ph): ?>
                                    <tr>
                                        <td><strong><?= e($ph['patient_number'] ?: ('#' . $ph['id'])) ?></strong></td>
                                        <td><strong><?= e($ph['patient_name']) ?></strong></td>
                                        <td>
                                            <?= e($ph['contact_number'] ?: '-') ?><br>
                                            <small class="text-muted"><?= e($ph['email'] ?: '') ?></small>
                                        </td>
                                        <td><?= (int)$ph['total_appointments'] ?></td>
                                        <td><?= (int)$ph['completed_count'] ?></td>
                                        <td><?= (int)$ph['missed_or_cancelled'] ?></td>
                                        <td>
                                            <?php if (!empty($ph['last_appointment_date'])): ?>
                                                <strong><?= e(date('M d, Y', strtotime($ph['last_appointment_date']))) ?></strong><br>
                                                <small class="text-muted"><?= e($ph['last_service'] ?: '') ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header" id="headingDentist">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseDentist" aria-expanded="false" aria-controls="collapseDentist">
                    <i class="bi bi-person-badge-fill"></i>
                    <span>Dentist Report<small class="mb-0">Appointments and completed per dentist.</small></span>
                </button>
            </h2>
            <div id="collapseDentist" class="accordion-collapse collapse" aria-labelledby="headingDentist" data-bs-parent="#reportsAccordion">
                <div class="accordion-body">
                    <div class="table-responsive">
                        <table class="table report-table mb-0">
                            <thead><tr><th>Dentist</th><th>Appointments</th><th>Completed</th></tr></thead>
                            <tbody>
                            <?php if (empty($appointmentsPerDentist)): ?>
                                <tr><td colspan="3" class="text-center text-muted">No dentist data yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($appointmentsPerDentist as $row): ?>
                                    <tr><td><?= e($row['dentist_name']) ?></td><td><?= (int) $row['total'] ?></td><td><?= (int) $row['completed'] ?></td></tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header" id="headingHistorical">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseHistorical" aria-expanded="false" aria-controls="collapseHistorical">
                    <i class="bi bi-clock-history"></i>
                    <span>Historical Appointment Demand<small class="mb-0">Busiest weekdays in the last 90 days.</small></span>
                </button>
            </h2>
            <div id="collapseHistorical" class="accordion-collapse collapse" aria-labelledby="headingHistorical" data-bs-parent="#reportsAccordion">
                <div class="accordion-body">
                    <?php if (empty($historicalDemand)): ?>
                        <p class="text-muted small mb-0">No historical demand data yet.</p>
                    <?php else: ?>
                        <?php foreach (array_slice($historicalDemand, 0, 5) as $row): ?>
                            <div class="demand-row"><span><?= e($row['day_name']) ?></span><strong><?= (int) $row['total'] ?></strong></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <section class="report-card mb-4">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
            <div>
                <h2 class="mb-1">7-Day Demand Forecast (AI Linear Regression)</h2>
                <p class="text-muted small mb-0">Computed from historical appointment demand and upcoming scheduled bookings.</p>
            </div>
            <span class="report-number"><?= $forecastTotal ?></span>
        </div>

        <?php if ($aiPredictionCount === 0): ?>
            <div class="alert alert-light small">ML prediction records are not available yet; this forecast uses historical appointment demand.</div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-lg-7">
                <?php if (empty($displayForecast)): ?>
                    <p class="text-muted small mb-0">No forecasted appointments yet.</p>
                <?php else: ?>
                    <?php foreach ($displayForecast as $row): ?>
                        <?php
                        $dl = strtolower((string) ($row['demand_level'] ?? 'low'));
                        $level = $dl === 'high' ? 'high' : ($dl === 'medium' ? 'moderate' : 'low');
                        ?>
                        <div class="demand-row demand-<?= $level ?>">
                            <span>
                                <?= e($row['day']) ?>, <?= e(date('M d', strtotime($row['date']))) ?>
                                <?php if (($row['day'] ?? '') === 'Sunday' && (int) $row['total'] === 0): ?>
                                    <small class="text-muted ms-1">(Clinic Closed)</small>
                                <?php else: ?>
                                    <small class="text-muted ms-1">(AI: <?= (int) ($row['ml_prediction'] ?? 0) ?> · Booked: <?= (int) ($row['actual_booked'] ?? 0) ?>)</small>
                                <?php endif; ?>
                            </span>
                            <strong><?= (int) $row['total'] ?> appointments</strong>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="col-lg-5">
                <h3>Busiest Days</h3>
                <?php if (empty($busyDays)): ?><p class="text-muted small">No high-demand days yet.</p><?php endif; ?>
                <?php foreach ($busyDays as $row): ?><div class="demand-row demand-high"><span><?= e($row['day']) ?> (<?= e(date('M d', strtotime($row['date']))) ?>)</span><strong>High Demand (<?= (int) $row['total'] ?>)</strong></div><?php endforeach; ?>
                <h3 class="mt-3">Moderate / Low-Demand Operating Days</h3>
                <?php if (empty($lowDemandDays)): ?><p class="text-muted small">No low-demand days yet.</p><?php endif; ?>
                <?php foreach ($lowDemandDays as $row): ?><div class="demand-row demand-low"><span><?= e($row['day']) ?> (<?= e(date('M d', strtotime($row['date']))) ?>)</span><strong><?= e($row['demand_level'] ?? 'Low') ?> Demand (<?= (int) $row['total'] ?>)</strong></div><?php endforeach; ?>
            </div>
        </div>

        <!-- ACTIONABLE AI SCHEDULING & RESCHEDULING RECOMMENDATIONS -->
        <div class="mt-4 pt-3 border-top">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <h3 class="mb-0"><i class="bi bi-stars text-success me-1"></i>AI Scheduling & Rescheduling Recommendations</h3>
                <a href="appointments.php" class="btn btn-sm btn-outline-success no-print">
                    <i class="bi bi-calendar2-week me-1"></i>Manage & Reschedule in Appointments
                </a>
            </div>
            <p class="text-muted small mb-3">
                Based on the 7-day Linear Regression forecast, the AI recommends balancing clinic workload by assigning new walk-ins and rescheduling missed/cancelled/pending appointments to the lowest-demand operating days below.
            </p>
            <?php if (!empty($rescheduleCandidates) && !empty($recommendedSlots)): ?>
                <div class="table-responsive">
                    <table class="table report-table">
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Current Status & Original Date</th>
                                <th>Service & Dentist</th>
                                <th>AI-Recommended Reschedule Date</th>
                                <th class="no-print">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rescheduleCandidates as $idx => $rc): ?>
                                <?php $recSlot = $recommendedSlots[$idx % count($recommendedSlots)]; ?>
                                <tr>
                                    <td>
                                        <strong><?= e($rc['patient_name']) ?></strong><br>
                                        <small class="text-muted"><?= e($rc['contact_number'] ?: '') ?></small>
                                    </td>
                                    <td>
                                        <strong><?= e($rc['status']) ?></strong><br>
                                        <small class="text-muted"><?= e(date('M d, Y', strtotime($rc['appointment_date']))) ?> (<?= e(date('h:i A', strtotime($rc['start_time']))) ?>)</small>
                                    </td>
                                    <td>
                                        <?= e($rc['service_type']) ?><br>
                                        <small class="text-muted"><?= e($rc['dentist_name'] ?: 'Any Dentist') ?></small>
                                    </td>
                                    <td>
                                        <strong style="color:#0E6B5E;"><?= e($recSlot['day']) ?>, <?= e(date('M d, Y', strtotime($recSlot['date']))) ?></strong><br>
                                        <small class="text-muted"><?= e($recSlot['demand_level']) ?> Load (Forecast: <?= (int)$recSlot['total'] ?> appts, Booked: <?= (int)$recSlot['actual_booked'] ?>)</small>
                                    </td>
                                    <td class="no-print">
                                        <a href="appointments.php?search=<?= urlencode($rc['patient_name']) ?>" class="btn btn-sm btn-success">
                                            <i class="bi bi-calendar-event me-1"></i>Reschedule
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>