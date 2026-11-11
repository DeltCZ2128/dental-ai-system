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

/* ==========================================================================
   TOP SUMMARY METRICS (Kept as user explicitly requested)
   ========================================================================== */
$totalAppointments = (int) reportValue($pdo, 'SELECT COUNT(*) FROM appointments');
$completedAppointments = (int) reportValue($pdo, "SELECT COUNT(*) FROM appointments WHERE status = 'Completed'");
$cancelledAppointments = (int) reportValue($pdo, "SELECT COUNT(*) FROM appointments WHERE status IN ('Cancelled', 'Canceled')");
$pendingAppointments = (int) reportValue($pdo, "SELECT COUNT(*) FROM appointments WHERE status = 'Pending'");
$confirmedAppointments = (int) reportValue($pdo, "SELECT COUNT(*) FROM appointments WHERE status IN ('Confirmed', 'Approved')");

/* ==========================================================================
   DATE RANGE & TAB FILTERING
   ========================================================================== */
$validTabs = ['appointment', 'patient', 'dentist', 'services', 'noshow', 'forecast'];
$activeTab = trim($_GET['tab'] ?? 'appointment');
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'appointment';
}

$tabNames = [
    'appointment' => '1. Appointment Report',
    'patient'     => '2. Patient Report',
    'dentist'     => '3. Dentist Performance Report',
    'services'    => '4. Services Report',
    'noshow'      => '5. No-Show Report',
    'forecast'    => '6. Historical & AI Demand Forecast Report'
];
$activeTabName = $tabNames[$activeTab] ?? '1. Appointment Report';

$rangePreset = trim($_GET['range'] ?? '');
$earliestDate = reportValue($pdo, "SELECT MIN(appointment_date) FROM appointments");
if (!$earliestDate || $earliestDate === '0000-00-00') {
    $earliestDate = date('Y-01-01');
}

if ($rangePreset === 'month') {
    $fromDate = date('Y-m-01');
    $toDate   = date('Y-m-d');
} elseif ($rangePreset === '30days') {
    $fromDate = date('Y-m-d', strtotime('-30 days'));
    $toDate   = date('Y-m-d');
} elseif ($rangePreset === 'year') {
    $fromDate = date('Y-01-01');
    $toDate   = date('Y-m-d');
} elseif ($rangePreset === 'all') {
    $fromDate = substr($earliestDate, 0, 10);
    $toDate   = date('Y-m-d', strtotime('+30 days'));
} else {
    $fromDate = trim($_GET['from'] ?? '');
    $toDate   = trim($_GET['to'] ?? '');

    if ($fromDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
        $fromDate = date('Y-m-01');
    }
    if ($toDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
        $toDate = date('Y-m-d');
    }
}

if ($fromDate > $toDate) {
    $temp = $fromDate;
    $fromDate = $toDate;
    $toDate = $temp;
}

$displayRangeText = date('M d, Y', strtotime($fromDate)) . ' – ' . date('M d, Y', strtotime($toDate));

/* ==========================================================================
   1. APPOINTMENT REPORT DATA
   ========================================================================== */
$stmtR1Stats = $pdo->prepare("
    SELECT 
        COUNT(*) AS total,
        SUM(status = 'Completed') AS completed,
        SUM(status IN ('Cancelled', 'Canceled')) AS cancelled,
        SUM(status = 'No-Show') AS noshow
    FROM appointments
    WHERE appointment_date BETWEEN ? AND ?
");
$stmtR1Stats->execute([$fromDate, $toDate]);
$r1Stats = $stmtR1Stats->fetch(PDO::FETCH_ASSOC) ?: [];
$r1Total     = (int)($r1Stats['total'] ?? 0);
$r1Completed = (int)($r1Stats['completed'] ?? 0);
$r1Cancelled = (int)($r1Stats['cancelled'] ?? 0);
$r1NoShow    = (int)($r1Stats['noshow'] ?? 0);

$r1Appointments = reportRows($pdo, "
    SELECT 
        a.id,
        a.appointment_date,
        a.start_time,
        a.end_time,
        a.service_type,
        a.status,
        CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
        p.patient_number,
        d.dentist_name
    FROM appointments a
    LEFT JOIN patients p ON p.id = a.patient_id
    LEFT JOIN dentists d ON d.id = a.dentist_id
    WHERE a.appointment_date BETWEEN ? AND ?
    ORDER BY a.appointment_date DESC, a.start_time DESC
    LIMIT 500
", [$fromDate, $toDate]);

/* ==========================================================================
   2. PATIENT REPORT DATA (Age group excluded per user instruction)
   ========================================================================== */
$stmtNew = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE DATE(created_at) BETWEEN ? AND ?");
$stmtNew->execute([$fromDate, $toDate]);
$r2NewPatients = (int)$stmtNew->fetchColumn();

$stmtReturn = $pdo->prepare("
    SELECT COUNT(DISTINCT a.patient_id) 
    FROM appointments a
    WHERE a.appointment_date BETWEEN ? AND ?
      AND a.patient_id IN (
          SELECT a2.patient_id 
          FROM appointments a2 
          WHERE a2.appointment_date < a.appointment_date
      )
");
$stmtReturn->execute([$fromDate, $toDate]);
$r2ReturningPatients = (int)$stmtReturn->fetchColumn();

$stmtWalkin = $pdo->prepare("SELECT COUNT(DISTINCT patient_id) FROM queue WHERE queue_date BETWEEN ? AND ?");
$stmtWalkin->execute([$fromDate, $toDate]);
$r2WalkinPatients = (int)$stmtWalkin->fetchColumn();

$stmtTotalActivePatients = $pdo->prepare("
    SELECT COUNT(DISTINCT patient_id)
    FROM appointments
    WHERE appointment_date BETWEEN ? AND ?
");
$stmtTotalActivePatients->execute([$fromDate, $toDate]);
$r2TotalPatientsCount = (int)$stmtTotalActivePatients->fetchColumn();
if ($r2TotalPatientsCount <= 0) {
    $r2TotalPatientsCount = $r2NewPatients + $r2ReturningPatients + $r2WalkinPatients;
}

$patientSearchKeyword = trim($_GET['patient_search'] ?? '');
if ($patientSearchKeyword !== '') {
    $r2PatientsList = reportRows($pdo, "
        SELECT 
            p.id,
            p.patient_number,
            CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
            p.gender,
            p.contact_number,
            p.email,
            COUNT(a.id) AS total_visits,
            MAX(a.appointment_date) AS last_visit_date,
            (
                SELECT a3.service_type 
                FROM appointments a3 
                WHERE a3.patient_id = p.id 
                ORDER BY a3.appointment_date DESC, a3.start_time DESC 
                LIMIT 1
            ) AS last_service
        FROM patients p
        LEFT JOIN appointments a ON a.patient_id = p.id
        WHERE p.is_archived = 0 
          AND (
              CONCAT(p.first_name, ' ', p.last_name) LIKE ? 
              OR p.patient_number LIKE ? 
              OR p.contact_number LIKE ? 
              OR p.email LIKE ?
          )
        GROUP BY p.id, p.patient_number, p.first_name, p.last_name, p.gender, p.contact_number, p.email
        ORDER BY last_visit_date DESC, total_visits DESC
        LIMIT 250
    ", ["%$patientSearchKeyword%", "%$patientSearchKeyword%", "%$patientSearchKeyword%", "%$patientSearchKeyword%"]);
} else {
    $r2PatientsList = reportRows($pdo, "
        SELECT 
            p.id,
            p.patient_number,
            CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
            p.gender,
            p.contact_number,
            p.email,
            COUNT(a.id) AS total_visits,
            MAX(a.appointment_date) AS last_visit_date,
            (
                SELECT a3.service_type 
                FROM appointments a3 
                WHERE a3.patient_id = p.id 
                ORDER BY a3.appointment_date DESC, a3.start_time DESC 
                LIMIT 1
            ) AS last_service
        FROM patients p
        LEFT JOIN appointments a ON a.patient_id = p.id
        WHERE (a.appointment_date BETWEEN ? AND ? OR DATE(p.created_at) BETWEEN ? AND ?)
        GROUP BY p.id, p.patient_number, p.first_name, p.last_name, p.gender, p.contact_number, p.email
        ORDER BY last_visit_date DESC, total_visits DESC
        LIMIT 250
    ", [$fromDate, $toDate, $fromDate, $toDate]);

    if (empty($r2PatientsList)) {
        $r2PatientsList = reportRows($pdo, "
            SELECT 
                p.id,
                p.patient_number,
                CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                p.gender,
                p.contact_number,
                p.email,
                COUNT(a.id) AS total_visits,
                MAX(a.appointment_date) AS last_visit_date,
                (
                    SELECT a3.service_type 
                    FROM appointments a3 
                    WHERE a3.patient_id = p.id 
                    ORDER BY a3.appointment_date DESC, a3.start_time DESC 
                    LIMIT 1
                ) AS last_service
            FROM patients p
            LEFT JOIN appointments a ON a.patient_id = p.id
            WHERE p.is_archived = 0
            GROUP BY p.id, p.patient_number, p.first_name, p.last_name, p.gender, p.contact_number, p.email
            ORDER BY last_visit_date DESC, total_visits DESC
            LIMIT 100
        ");
    }
}

/* ==========================================================================
   3. DENTIST PERFORMANCE REPORT DATA
   ========================================================================== */
$r3Dentists = reportRows($pdo, "
    SELECT 
        d.id,
        d.dentist_name,
        d.specialization,
        COUNT(a.id) AS total_appts,
        SUM(CASE WHEN a.status = 'Completed' THEN 1 ELSE 0 END) AS completed_appts,
        SUM(CASE WHEN a.status IN ('Cancelled', 'Canceled') THEN 1 ELSE 0 END) AS cancelled_appts,
        SUM(CASE WHEN a.status = 'No-Show' THEN 1 ELSE 0 END) AS noshow_appts
    FROM dentists d
    LEFT JOIN appointments a ON a.dentist_id = d.id AND a.appointment_date BETWEEN ? AND ?
    GROUP BY d.id, d.dentist_name, d.specialization
    ORDER BY total_appts DESC, completed_appts DESC
", [$fromDate, $toDate]);

/* ==========================================================================
   4. SERVICES REPORT DATA
   ========================================================================== */
$r4ServicesRaw = reportRows($pdo, "
    SELECT 
        COALESCE(NULLIF(TRIM(a.service_type), ''), 'General Consultation') AS service_name,
        COUNT(*) AS total_performed
    FROM appointments a
    WHERE a.appointment_date BETWEEN ? AND ?
      AND a.status NOT IN ('Cancelled', 'Canceled')
    GROUP BY service_name
    ORDER BY total_performed DESC
", [$fromDate, $toDate]);

$r4GrandTotal = 0;
foreach ($r4ServicesRaw as $srv) {
    $r4GrandTotal += (int)$srv['total_performed'];
}

$r4Services = [];
foreach ($r4ServicesRaw as $srv) {
    $performed = (int)$srv['total_performed'];
    $pct = $r4GrandTotal > 0 ? round(($performed / $r4GrandTotal) * 100, 1) : 0;
    $r4Services[] = [
        'service_name'    => $srv['service_name'],
        'total_performed' => $performed,
        'percentage'      => $pct
    ];
}

/* ==========================================================================
   5. NO-SHOW REPORT DATA
   ========================================================================== */
$stmtNoShowStats = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_scheduled,
        SUM(status = 'No-Show') AS total_noshow
    FROM appointments
    WHERE appointment_date BETWEEN ? AND ?
");
$stmtNoShowStats->execute([$fromDate, $toDate]);
$r5Stats = $stmtNoShowStats->fetch(PDO::FETCH_ASSOC) ?: [];
$r5TotalScheduled = (int)($r5Stats['total_scheduled'] ?? 0);
$r5TotalNoShows   = (int)($r5Stats['total_noshow'] ?? 0);
$r5NoShowRate     = $r5TotalScheduled > 0 ? round(($r5TotalNoShows / $r5TotalScheduled) * 100, 1) : 0;

$r5TrendRows = reportRows($pdo, "
    SELECT 
        appointment_date,
        COUNT(*) AS noshow_count
    FROM appointments
    WHERE appointment_date BETWEEN ? AND ?
      AND status = 'No-Show'
    GROUP BY appointment_date
    ORDER BY appointment_date ASC
    LIMIT 60
", [$fromDate, $toDate]);

$r5NoShowPatients = reportRows($pdo, "
    SELECT 
        a.id,
        a.appointment_date,
        a.start_time,
        a.service_type,
        CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
        p.patient_number,
        d.dentist_name
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    LEFT JOIN dentists d ON d.id = a.dentist_id
    WHERE a.appointment_date BETWEEN ? AND ?
      AND a.status = 'No-Show'
    ORDER BY a.appointment_date DESC, a.start_time DESC
    LIMIT 150
", [$fromDate, $toDate]);

/* ==========================================================================
   6. HISTORICAL DEMAND & AI LINEAR REGRESSION FORECAST (Preserved)
   ========================================================================== */
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

require_once __DIR__ . '/demand_forecast.php';

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
    LIMIT 100
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
    <title>Reports & Analytics | Abalos Dental Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/style.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        body { 
            background: linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22) !important; 
            background-size: 300% 300% !important; 
            animation: adminBgAnim 8s ease infinite !important; 
            background-attachment: fixed !important; 
        }
        @keyframes adminBgAnim { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }

        .reports-page { 
            margin-left: 280px !important; 
            width: calc(100% - 280px) !important; 
            padding: 110px 24px 40px !important; 
            background: transparent !important; 
            min-height: 100vh; 
            box-sizing: border-box !important; 
        }

        .reports-head { 
            margin: 0 4px 18px 4px; 
            display: flex; 
            flex-wrap: wrap; 
            justify-content: space-between; 
            align-items: center; 
            gap: 14px; 
        }
        .reports-head h1 { 
            color: #ffffff !important; 
            font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; 
            font-size: 22px; 
            font-weight: 800; 
            letter-spacing: -0.02em; 
            margin: 0; 
        }
        .reports-head p { 
            margin: 4px 0 0; 
            color: #C9E9DA; 
            font-size: 13.5px; 
        }

        /* SUMMARY STAT CARDS (Top 5 Featured Cards - Kept as requested) */
        .stat-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg,#047857 0%,#059669 55%,#10B981 100%);
            border: 1px solid rgba(255,255,255,.28);
            border-radius: 14px;
            padding: 13px 15px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 3px;
            box-shadow: 0 16px 34px rgba(5,150,105,.35);
            color: #ffffff;
            transition: .25s ease;
            user-select: none;
            height: 100%;
        }
        .stat-card::before, .stat-card::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }
        .stat-card::before {
            width: 130px; height: 130px; top: -60px; right: -45px;
            background: radial-gradient(circle,rgba(255,255,255,.22),rgba(255,255,255,0) 70%);
        }
        .stat-card::after {
            width: 100px; height: 100px; bottom: -48px; left: -32px;
            background: radial-gradient(circle,rgba(255,255,255,.16),rgba(255,255,255,0) 70%);
        }
        .stat-card > * { position: relative; z-index: 1; }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 22px 42px rgba(5,150,105,.45);
            border-color: rgba(255,255,255,.62);
            color: #ffffff;
        }
        .stat-featured-head {
            display: flex; align-items: center; gap: 10px; width: 100%;
        }
        .stat-featured-icon {
            width: 34px; height: 34px; border-radius: 11px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0;
            background: rgba(255,255,255,.18);
            border: 1px solid rgba(255,255,255,.32);
            color: #ffffff;
            box-shadow: 0 8px 18px rgba(0,0,0,.14);
        }
        .stat-featured-head h3 {
            margin: 0; flex: 1; min-width: 0;
            color: rgba(255,255,255,.94);
            font-size: 13px; font-weight: 700;
            font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
        }
        .stat-number {
            color: #ffffff;
            font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
            font-size: 27px; font-weight: 800;
            letter-spacing: -0.02em; font-variant-numeric: tabular-nums;
            text-shadow: 0 6px 18px rgba(0,0,0,.22);
            margin-top: auto; padding-top: 4px; line-height: 1;
        }
        .stat-featured-sub {
            display: flex; align-items: center; gap: 8px;
            font-size: 11.5px; font-weight: 600;
            color: rgba(255,255,255,.86); margin-top: 3px;
        }
        .stat-featured-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #A7F3D0;
            box-shadow: 0 0 0 4px rgba(167,243,208,.25);
            animation: statDotPulse 1.9s ease-in-out infinite;
            flex-shrink: 0;
        }
        @keyframes statDotPulse {
            0%,100% { opacity: 1; transform: scale(1); }
            50% { opacity: .5; transform: scale(.8); }
        }

        /* CATEGORY NAVIGATION TABS */
        .report-nav-tabs {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #ffffff;
            padding: 8px 10px;
            border-radius: 14px;
            border: 1px solid #C9E9DA;
            box-shadow: 0 6px 20px rgba(14,107,94,.08);
            overflow-x: auto;
            white-space: nowrap;
        }
        .report-tab-btn {
            background: transparent;
            border: none;
            padding: 9px 16px;
            border-radius: 10px;
            color: #2a5563;
            font-size: 13.5px;
            font-weight: 700;
            font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all .18s ease;
            text-decoration: none;
        }
        .report-tab-btn i { font-size: 15px; color: #0E6B5E; }
        .report-tab-btn:hover {
            background: #EAF7F0;
            color: #0E6B5E;
        }
        .report-tab-btn.active {
            background: linear-gradient(135deg, #0E6B5E, #0A4A41);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(14,107,94,.28);
        }
        .report-tab-btn.active i { color: #A7F3D0; }

        /* REPORT SECTION CARD */
        .report-section-card {
            background: #ffffff;
            border: 1px solid #C9E9DA;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(14,107,94,.08);
            padding: 24px;
            margin-bottom: 24px;
        }
        .report-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1.5px solid #E6F2EC;
        }
        .report-section-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .report-title-icon {
            width: 42px; height: 42px; border-radius: 12px;
            background: #EAF7F0; color: #0E6B5E;
            border: 1.5px solid #C9E9DA;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 20px;
        }
        .report-section-title h2 {
            margin: 0; font-size: 18px; font-weight: 800;
            color: #143f51; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
        }
        .report-section-title p {
            margin: 2px 0 0; font-size: 12.5px; color: #64748B;
        }

        /* FILTER TOOLBAR */
        .report-filter-bar {
            background: #F6FBF8;
            border: 1px solid #D9EFE4;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .report-filter-inputs {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .report-filter-inputs label {
            font-size: 12.5px; font-weight: 700; color: #335561; margin-bottom: 0;
        }
        .report-filter-inputs input[type="date"] {
            border: 1.5px solid #CFDFD7;
            border-radius: 9px;
            padding: 6px 10px;
            font-size: 13px;
            font-weight: 600;
            color: #143f51;
            background: #ffffff;
            outline: none;
        }
        .report-filter-inputs input[type="date"]:focus {
            border-color: #0E6B5E;
            box-shadow: 0 0 0 3px rgba(14,107,94,.15);
        }
        .btn-generate-report {
            background: linear-gradient(135deg, #0E6B5E, #059669);
            color: #ffffff;
            border: none;
            padding: 7px 16px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 4px 10px rgba(14,107,94,.22);
            transition: all .16s ease;
        }
        .btn-generate-report:hover {
            filter: brightness(1.08);
            transform: translateY(-1px);
            color: #ffffff;
        }
        .quick-filter-pill {
            background: #ffffff;
            border: 1px solid #CFDFD7;
            color: #43626e;
            padding: 5px 11px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            transition: all .15s ease;
        }
        .quick-filter-pill:hover, .quick-filter-pill.active {
            background: #0E6B5E;
            border-color: #0E6B5E;
            color: #ffffff;
        }

        /* SECTION MINI STAT CARDS */
        .mini-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }
        .mini-stat-card {
            background: #F6FBF8;
            border: 1.5px solid #D9EFE4;
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all .2s ease;
        }
        .mini-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(14,107,94,.08);
            border-color: #B9E2CD;
        }
        .mini-stat-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 18px; flex-shrink: 0;
        }
        .mini-stat-icon.blue { background: #E0F2FE; color: #0284C7; }
        .mini-stat-icon.green { background: #DCFCE7; color: #16A34A; }
        .mini-stat-icon.red { background: #FEE2E2; color: #DC2626; }
        .mini-stat-icon.amber { background: #FEF3C7; color: #D97706; }
        .mini-stat-icon.purple { background: #EDE9FE; color: #7C3AED; }
        .mini-stat-info small {
            display: block; font-size: 11.5px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: .4px;
        }
        .mini-stat-info strong {
            display: block; font-size: 22px; font-weight: 800; color: #143f51; font-family: "Plus Jakarta Sans", sans-serif; line-height: 1.1; margin-top: 2px;
        }

        /* REPORT TABLES */
        .report-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #D9EFE4;
            margin-bottom: 0;
        }
        .report-table thead tr {
            background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important;
        }
        .report-table thead th {
            background: transparent !important;
            color: #ffffff !important;
            font-size: 11.5px !important;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .6px;
            padding: 13px 15px !important;
            border: none !important;
            border-right: 1px solid rgba(255,255,255,.12) !important;
            white-space: nowrap;
        }
        .report-table thead th:last-child { border-right: none !important; }
        .report-table tbody td {
            padding: 12px 15px !important;
            color: #3b5763 !important;
            font-size: 13px !important;
            font-family: "Inter", "DM Sans", system-ui, sans-serif;
            vertical-align: middle;
            border-bottom: 1px solid #E6F2EC !important;
        }
        .report-table tbody tr:nth-child(even) td { background: #F8FCFA !important; }
        .report-table tbody tr:hover td { background: #EAF7F0 !important; }

        /* STATUS BADGES */
        .badge-status {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 999px;
            font-size: 11px; font-weight: 800; letter-spacing: .3px;
            text-transform: uppercase;
        }
        .badge-status.completed { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
        .badge-status.confirmed { background: #E0F2FE; color: #0369A1; border: 1px solid #BAE6FD; }
        .badge-status.pending { background: #FEF3C7; color: #B45309; border: 1px solid #FDE68A; }
        .badge-status.cancelled { background: #FEE2E2; color: #B91C1C; border: 1px solid #FECACA; }
        .badge-status.noshow { background: #F1F5F9; color: #475569; border: 1px solid #CBD5E1; }

        /* CHARTS CONTAINER */
        .chart-box-card {
            background: #ffffff;
            border: 1.5px solid #D9EFE4;
            border-radius: 14px;
            padding: 18px 20px;
            height: 100%;
        }
        .chart-box-card h4 {
            font-size: 14.5px; font-weight: 800; color: #143f51; margin-bottom: 4px;
            font-family: "Plus Jakarta Sans", sans-serif;
        }
        .chart-box-card p {
            font-size: 12px; color: #64748B; margin-bottom: 14px;
        }
        .chart-canvas-wrap {
            position: relative; width: 100%; max-height: 280px; display: flex; align-items: center; justify-content: center;
        }

        /* PAGINATION */
        .report-pagination {
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap;
            gap: 10px; margin-top: 14px; padding: 10px 14px; background: #F6FBF8;
            border: 1px solid #D9EFE4; border-radius: 12px;
        }
        .report-pagination-info { font-size: 12px; font-weight: 600; color: #4d6873; }
        .report-pagination-info strong { color: #0E6B5E; font-weight: 800; }
        .report-page-nav { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; }
        .report-page-btn {
            min-width: 32px; height: 32px; padding: 0 9px; border-radius: 8px;
            border: 1px solid #C9E9DA; background: #ffffff; color: #143f51;
            font-size: 12px; font-weight: 700; display: inline-flex;
            align-items: center; justify-content: center; cursor: pointer;
            transition: all .16s ease; user-select: none; text-decoration: none;
        }
        .report-page-btn:hover:not(:disabled):not(.active) { background: #EAF7F0; border-color: #0E6B5E; color: #0E6B5E; }
        .report-page-btn.active { background: linear-gradient(135deg, #0E6B5E, #0A4A41); border-color: #0E6B5E; color: #ffffff; }
        .report-page-btn:disabled { opacity: .4; cursor: not-allowed; }

        /* DEMAND ROWS (Forecast & Historical) */
        .demand-row {
            display: flex; justify-content: space-between; align-items: center;
            gap: 14px; padding: 11px 14px; background: #F6FBF8;
            border: 1px solid #E6F2EC; border-radius: 10px; margin-bottom: 8px;
            font-size: 13px; color: #4d6873;
        }
        .demand-row strong { color: #143f51; }
        .demand-high { border-left: 4px solid #D44B4B; }
        .demand-moderate { border-left: 4px solid #E8A800; }
        .demand-low { border-left: 4px solid #149A72; }

        @media (max-width: 991px) {
            .reports-page { margin-left: 0 !important; width: 100% !important; padding: 95px 16px 40px !important; }
        }

        /* ------------------------------------------------------------------
           CLINIC PRINT STYLES (SCREEN VS PRINT)
           ------------------------------------------------------------------ */
        .clinic-print-header,
        .clinic-print-footer {
            display: none;
        }

        @media print {
            @page {
                size: portrait;
                margin: 12mm 10mm 12mm 10mm;
            }

            html, body {
                background: #ffffff !important;
                color: #0f172a !important;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
                font-size: 10pt !important;
                line-height: 1.35 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            /* Completely hide all web navigation, sidebars, buttons, and popups */
            html body header,
            html body header.topbar,
            html body .shared-topbar,
            html body .topbar,
            html body aside.sidebar,
            html body .shared-sidebar,
            html body aside,
            html body .mobile-menu,
            html body .admin-logout-overlay,
            .no-print,
            .reports-head,
            .screen-only-summary,
            .report-nav-tabs,
            .report-filter-bar,
            .report-pagination,
            .btn,
            button,
            a[href="dashboard.php"] {
                display: none !important;
                visibility: hidden !important;
                height: 0 !important;
                max-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                opacity: 0 !important;
            }

            .reports-page {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                background: transparent !important;
            }

            /* PRINT LETTERHEAD */
            .clinic-print-header {
                display: block !important;
                visibility: visible !important;
                margin-bottom: 14px !important;
                page-break-inside: avoid !important;
            }

            .cph-top {
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
                padding-bottom: 8px !important;
            }

            .cph-brand {
                display: flex !important;
                align-items: center !important;
                gap: 14px !important;
            }

            .cph-logo {
                width: 58px !important;
                height: 58px !important;
                object-fit: cover !important;
                border-radius: 8px !important;
                border: 1.5px solid #0E6B5E !important;
            }

            .cph-title-wrap {
                text-align: left !important;
            }

            .cph-clinic-title {
                font-family: 'Plus Jakarta Sans', system-ui, sans-serif !important;
                font-size: 19pt !important;
                font-weight: 800 !important;
                color: #0E6B5E !important;
                margin: 0 !important;
                line-height: 1.1 !important;
                letter-spacing: 0.5px !important;
            }

            .cph-report-title {
                font-family: 'Plus Jakarta Sans', system-ui, sans-serif !important;
                font-size: 13pt !important;
                font-weight: 700 !important;
                color: #143f51 !important;
                margin: 3px 0 0 !important;
            }

            .cph-clinic-sub {
                font-size: 8.5pt !important;
                color: #64748B !important;
                margin-top: 2px !important;
            }

            .cph-meta {
                text-align: right !important;
                font-size: 8.5pt !important;
                color: #334155 !important;
                line-height: 1.45 !important;
            }

            .cph-rule {
                height: 2.5px !important;
                background: #0E6B5E !important;
                margin: 8px 0 16px !important;
                border: none !important;
            }

            /* PRINT ONLY THE ACTIVE TAB PANE */
            .report-tab-pane.d-none {
                display: none !important;
                visibility: hidden !important;
                height: 0 !important;
            }

            .report-tab-pane:not(.d-none) {
                display: block !important;
                visibility: visible !important;
            }

            /* CARD & GRID FORMATTING */
            .report-section-card {
                box-shadow: none !important;
                border: 1px solid #CBD5E1 !important;
                border-radius: 8px !important;
                padding: 14px !important;
                margin-bottom: 14px !important;
                page-break-inside: auto !important;
                page-break-after: auto !important;
                background: #ffffff !important;
            }

            .report-section-card h2 {
                font-size: 12.5pt !important;
                font-weight: 800 !important;
                color: #0E6B5E !important;
                margin-bottom: 3px !important;
            }

            .report-section-card p {
                font-size: 8.5pt !important;
                color: #64748B !important;
                margin-bottom: 8px !important;
            }

            /* Retain flex grid layout on paper */
            .row,
            .row.g-2,
            .row.g-3,
            .row.g-4 {
                display: flex !important;
                flex-wrap: wrap !important;
                margin-left: -6px !important;
                margin-right: -6px !important;
                margin-bottom: 12px !important;
            }

            .row > * {
                box-sizing: border-box !important;
            }

            .col-md-3, .col-sm-6 {
                flex: 0 0 25% !important;
                max-width: 25% !important;
                padding-left: 6px !important;
                padding-right: 6px !important;
            }

            .col-md-4 {
                flex: 0 0 33.333% !important;
                max-width: 33.333% !important;
                padding-left: 6px !important;
                padding-right: 6px !important;
            }

            .col-md-6, .col-lg-6 {
                flex: 0 0 50% !important;
                max-width: 50% !important;
                padding-left: 6px !important;
                padding-right: 6px !important;
            }

            .col-lg-5 {
                flex: 0 0 37% !important;
                max-width: 37% !important;
                width: 37% !important;
                padding-left: 5px !important;
                padding-right: 5px !important;
                box-sizing: border-box !important;
            }

            .col-lg-7 {
                flex: 0 0 63% !important;
                max-width: 63% !important;
                width: 63% !important;
                padding-left: 5px !important;
                padding-right: 5px !important;
                box-sizing: border-box !important;
            }

            /* CHARTS CONTAINER IN PRINT (Prevent overflow into tables) */
            .chart-box-card {
                background: #ffffff !important;
                border: 1px solid #CBD5E1 !important;
                border-radius: 8px !important;
                padding: 10px 12px !important;
                box-shadow: none !important;
                overflow: hidden !important;
                page-break-inside: avoid !important;
                box-sizing: border-box !important;
                width: 100% !important;
            }

            .chart-box-card h4 {
                font-size: 10pt !important;
                font-weight: 700 !important;
                color: #143f51 !important;
                margin-bottom: 2px !important;
            }

            .chart-box-card p {
                font-size: 7.5pt !important;
                color: #64748B !important;
                margin-bottom: 6px !important;
            }

            .chart-canvas-wrap {
                position: relative !important;
                width: 100% !important;
                height: 210px !important;
                max-height: 210px !important;
                overflow: hidden !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                box-sizing: border-box !important;
            }

            .chart-canvas-wrap canvas {
                max-width: 100% !important;
                max-height: 200px !important;
                display: block !important;
                margin: 0 auto !important;
            }

            .stat-card {
                background: #F8FAFC !important;
                border: 1px solid #CBD5E1 !important;
                border-radius: 6px !important;
                box-shadow: none !important;
                padding: 8px 10px !important;
            }

            .stat-card .stat-number {
                font-size: 15pt !important;
                font-weight: 800 !important;
                color: #0E6B5E !important;
                margin: 2px 0 !important;
            }

            .stat-card h3 {
                font-size: 7.5pt !important;
                font-weight: 700 !important;
                color: #475569 !important;
            }

            /* CLEAN TABLES */
            .table-responsive {
                overflow: hidden !important;
                display: block !important;
                width: 100% !important;
            }

            .report-table {
                width: 100% !important;
                border-collapse: collapse !important;
                font-size: 8pt !important;
                table-layout: auto !important;
            }

            .report-table thead {
                display: table-header-group !important;
            }

            .report-table thead th {
                background: #F1F5F9 !important;
                color: #0F172A !important;
                font-weight: 700 !important;
                border-bottom: 2px solid #94A3B8 !important;
                border-top: 1px solid #E2E8F0 !important;
                padding: 5px 4px !important;
                font-size: 7pt !important;
                text-transform: uppercase !important;
                vertical-align: middle !important;
                line-height: 1.15 !important;
                white-space: normal !important;
            }

            .report-table tbody tr {
                display: table-row !important;
                page-break-inside: avoid !important;
                border-bottom: 1px solid #E2E8F0 !important;
            }

            .report-table tbody td {
                padding: 5px 4px !important;
                color: #1E293B !important;
                vertical-align: middle !important;
                font-size: 7.8pt !important;
                line-height: 1.2 !important;
            }

            .badge {
                border: 1px solid #CBD5E1 !important;
                padding: 1px 5px !important;
                font-size: 7.5pt !important;
                font-weight: 700 !important;
                border-radius: 4px !important;
                display: inline-block !important;
            }

            .badge-status {
                border: 1px solid #CBD5E1 !important;
                padding: 1px 5px !important;
                font-size: 7.5pt !important;
                font-weight: 700 !important;
                border-radius: 4px !important;
                display: inline-block !important;
                min-width: 20px !important;
                text-align: center !important;
            }

            /* SIGN-OFF FOOTER */
            .clinic-print-footer {
                display: block !important;
                visibility: visible !important;
                margin-top: 30px !important;
                page-break-inside: avoid !important;
            }

            .cpf-signatures {
                display: flex !important;
                justify-content: space-between !important;
                padding: 0 25px !important;
                margin-top: 25px !important;
            }

            .cpf-sign-col {
                width: 220px !important;
                text-align: center !important;
            }

            .cpf-label {
                font-size: 8pt !important;
                color: #64748B !important;
                margin-bottom: 38px !important;
                text-align: left !important;
            }

            .cpf-line {
                border-bottom: 1.5px solid #334155 !important;
                margin-bottom: 5px !important;
            }

            .cpf-name {
                font-size: 9.5pt !important;
                font-weight: 700 !important;
                color: #0F172A !important;
            }

            .cpf-role {
                font-size: 8pt !important;
                color: #64748B !important;
            }

            .cpf-footnote {
                display: flex !important;
                justify-content: space-between !important;
                border-top: 1px solid #E2E8F0 !important;
                margin-top: 20px !important;
                padding-top: 6px !important;
                font-size: 7.5pt !important;
                color: #94A3B8 !important;
            }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes.php'; ?>

<main class="reports-page">
    <?php showFlash(); ?>

    <!-- ====================================================================
         PRINT-ONLY CLINIC LETTERHEAD & HEADING (Official Dental Report)
         ==================================================================== -->
    <div class="clinic-print-header">
        <div class="cph-top">
            <div class="cph-brand">
                <img src="../assets/dental-bg.jpg" alt="Abalos Dental Clinic" class="cph-logo">
                <div class="cph-title-wrap">
                    <h1 class="cph-clinic-title">ABALOS DENTAL CLINIC</h1>
                    <h2 class="cph-report-title">Abalos Dental Clinic Report</h2>
                    <div class="cph-clinic-sub">Comprehensive Dental Healthcare &bull; Clinical Analytics & Management</div>
                </div>
            </div>
            <div class="cph-meta">
                <div><strong>Report Type:</strong> <?= e($activeTabName) ?></div>
                <div><strong>Coverage:</strong> <?= e($displayRangeText) ?></div>
                <div><strong>Date Printed:</strong> <?= date('F d, Y') ?> &bull; <?= date('h:i A') ?></div>
                <div><strong>Generated By:</strong> <?= e($_SESSION['admin_name'] ?? 'System Administrator') ?></div>
            </div>
        </div>
        <div class="cph-rule"></div>
    </div>

    <a href="dashboard.php" class="btn btn-outline-secondary btn-sm mb-3 no-print">
        <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
    </a>

    <!-- PAGE HEADER -->
    <div class="reports-head">
        <div>
            <h1><i class="bi bi-clipboard2-data-fill me-2" style="color:#A7F3D0;"></i>Reports & Analytics</h1>
            <p>Comprehensive clinical reports, patient statistics, dentist workloads, service distributions, and AI forecasting.</p>
        </div>
        <div class="d-flex align-items-center gap-2 no-print">
            <span class="badge" style="background:#EAF7F0; color:#0E6B5E; border:1px solid #C9E9DA; font-size:12.5px; padding:7px 14px;">
                <i class="bi bi-calendar3 me-1"></i>Range: <?= e($displayRangeText) ?>
            </span>
            <button type="button" class="btn btn-sm btn-light fw-bold shadow-sm" onclick="window.print()">
                <i class="bi bi-printer-fill me-1"></i>Print Report
            </button>
        </div>
    </div>

    <!-- TOP SUMMARY CARDS (Kept on screen as user requested; hidden during print so active report is prioritized) -->
    <div class="row g-3 mb-4 screen-only-summary">
        <?php foreach ([
            ['Total Appointments', $totalAppointments, 'calendar2-check-fill', 'All recorded bookings'],
            ['Completed Appointments', $completedAppointments, 'check-circle-fill', 'Finished treatments'],
            ['Cancelled Appointments', $cancelledAppointments, 'x-circle-fill', 'Cancelled or retracted'],
            ['Pending Appointments', $pendingAppointments, 'hourglass-split', 'Awaiting confirmation'],
            ['Confirmed Appointments', $confirmedAppointments, 'calendar-check-fill', 'Approved schedule']
        ] as $metric): ?>
            <div class="col-xl col-md-4 col-sm-6">
                <div class="stat-card">
                    <div class="stat-featured-head">
                        <span class="stat-featured-icon"><i class="bi bi-<?= $metric[2] ?>"></i></span>
                        <h3><?= e($metric[0]) ?></h3>
                    </div>
                    <div class="stat-number"><?= (int) $metric[1] ?></div>
                    <div class="stat-featured-sub">
                        <span class="stat-featured-dot"></span>
                        <?= e($metric[3]) ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- CATEGORY TABS NAVIGATION -->
    <div class="report-nav-tabs mb-4 no-print" id="reportNavTabs">
        <a href="?tab=appointment&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>" class="report-tab-btn <?= $activeTab === 'appointment' ? 'active' : '' ?>">
            <i class="bi bi-calendar-check-fill"></i> 1. Appointment Report
        </a>
        <a href="?tab=patient&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>" class="report-tab-btn <?= $activeTab === 'patient' ? 'active' : '' ?>">
            <i class="bi bi-people-fill"></i> 2. Patient Report
        </a>
        <a href="?tab=dentist&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>" class="report-tab-btn <?= $activeTab === 'dentist' ? 'active' : '' ?>">
            <i class="bi bi-person-badge-fill"></i> 3. Dentist Performance
        </a>
        <a href="?tab=services&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>" class="report-tab-btn <?= $activeTab === 'services' ? 'active' : '' ?>">
            <i class="bi bi-heart-pulse-fill"></i> 4. Services Report
        </a>
        <a href="?tab=noshow&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>" class="report-tab-btn <?= $activeTab === 'noshow' ? 'active' : '' ?>">
            <i class="bi bi-person-x-fill"></i> 5. No-Show Report
        </a>
        <a href="?tab=forecast&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>" class="report-tab-btn <?= $activeTab === 'forecast' ? 'active' : '' ?>">
            <i class="bi bi-graph-up-arrow"></i> 6. AI Demand Forecast
        </a>
    </div>

    <!-- ====================================================================
         1. APPOINTMENT REPORT TAB
         ==================================================================== -->
    <div class="report-tab-pane <?= $activeTab === 'appointment' ? '' : 'd-none' ?>" id="pane-appointment">
        <div class="report-section-card">
            <div class="report-section-header">
                <div class="report-section-title">
                    <span class="report-title-icon"><i class="bi bi-calendar2-check-fill"></i></span>
                    <div>
                        <h2>Appointment Report</h2>
                        <p>Summary of bookings, outcomes, and comprehensive appointments reference log.</p>
                    </div>
                </div>
                <div class="text-muted small">Period: <strong><?= e($displayRangeText) ?></strong></div>
            </div>

            <!-- FILTER BAR -->
            <form method="GET" class="report-filter-bar no-print">
                <input type="hidden" name="tab" value="appointment">
                <div class="report-filter-inputs">
                    <label><i class="bi bi-calendar-event me-1 text-success"></i>From:</label>
                    <input type="date" name="from" value="<?= e($fromDate) ?>" required>
                    <label class="ms-2">To:</label>
                    <input type="date" name="to" value="<?= e($toDate) ?>" required>
                    <button type="submit" class="btn-generate-report ms-2">
                        <i class="bi bi-funnel-fill"></i> Generate Report
                    </button>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <a href="?tab=appointment&range=month" class="quick-filter-pill <?= $rangePreset === 'month' ? 'active' : '' ?>">This Month</a>
                    <a href="?tab=appointment&range=30days" class="quick-filter-pill <?= $rangePreset === '30days' ? 'active' : '' ?>">Last 30 Days</a>
                    <a href="?tab=appointment&range=year" class="quick-filter-pill <?= $rangePreset === 'year' ? 'active' : '' ?>">This Year</a>
                    <a href="?tab=appointment&range=all" class="quick-filter-pill <?= $rangePreset === 'all' ? 'active' : '' ?>">All Time</a>
                </div>
            </form>

            <!-- STAT CARDS (Image Reference 1) -->
            <div class="mini-stat-grid">
                <div class="mini-stat-card">
                    <span class="mini-stat-icon blue"><i class="bi bi-calendar-range-fill"></i></span>
                    <div class="mini-stat-info">
                        <small>Total Appointments</small>
                        <strong><?= $r1Total ?></strong>
                    </div>
                </div>
                <div class="mini-stat-card">
                    <span class="mini-stat-icon green"><i class="bi bi-check-circle-fill"></i></span>
                    <div class="mini-stat-info">
                        <small>Completed</small>
                        <strong><?= $r1Completed ?></strong>
                    </div>
                </div>
                <div class="mini-stat-card">
                    <span class="mini-stat-icon red"><i class="bi bi-x-circle-fill"></i></span>
                    <div class="mini-stat-info">
                        <small>Cancelled</small>
                        <strong><?= $r1Cancelled ?></strong>
                    </div>
                </div>
                <div class="mini-stat-card">
                    <span class="mini-stat-icon amber"><i class="bi bi-person-x-fill"></i></span>
                    <div class="mini-stat-info">
                        <small>No-Show</small>
                        <strong><?= $r1NoShow ?></strong>
                    </div>
                </div>
            </div>

            <!-- APPOINTMENTS SUMMARY TABLE -->
            <h4 class="mb-3 fw-bold" style="color:#143f51; font-size:15px;"><i class="bi bi-table me-2 text-success"></i>Appointments Summary</h4>
            <div class="table-responsive">
                <table class="report-table" data-report-paginate="10">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Patient Name</th>
                            <th>Dental Service</th>
                            <th>Assigned Dentist</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($r1Appointments)): ?>
                            <tr data-empty-row="1"><td colspan="5" class="text-center text-muted p-4">No appointment records found for this period.</td></tr>
                        <?php else: ?>
                            <?php foreach ($r1Appointments as $ar): 
                                $st = strtolower(trim($ar['status']));
                                $badgeClass = 'pending';
                                if ($st === 'completed') $badgeClass = 'completed';
                                elseif ($st === 'confirmed' || $st === 'approved') $badgeClass = 'confirmed';
                                elseif ($st === 'cancelled' || $st === 'canceled') $badgeClass = 'cancelled';
                                elseif ($st === 'no-show') $badgeClass = 'noshow';
                            ?>
                                <tr>
                                    <td>
                                        <strong><?= e(date('M d, Y', strtotime($ar['appointment_date']))) ?></strong><br>
                                        <small class="text-muted"><?= e(date('h:i A', strtotime($ar['start_time']))) ?> – <?= e(date('h:i A', strtotime($ar['end_time'] ?: $ar['start_time']))) ?></small>
                                    </td>
                                    <td>
                                        <strong><?= e($ar['patient_name'] ?: 'Patient') ?></strong><br>
                                        <small class="text-muted"><?= e($ar['patient_number'] ?: '') ?></small>
                                    </td>
                                    <td><span class="fw-semibold"><?= e($ar['service_type'] ?: '-') ?></span></td>
                                    <td><?= e($ar['dentist_name'] ?: 'Not assigned') ?></td>
                                    <td><span class="badge-status <?= $badgeClass ?>"><?= e($ar['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>


    <!-- ====================================================================
         2. PATIENT REPORT TAB (No Age Group per user instruction)
         ==================================================================== -->
    <div class="report-tab-pane <?= $activeTab === 'patient' ? '' : 'd-none' ?>" id="pane-patient">
        <div class="report-section-card">
            <div class="report-section-header">
                <div class="report-section-title">
                    <span class="report-title-icon"><i class="bi bi-people-fill"></i></span>
                    <div>
                        <h2>Patient Report</h2>
                        <p>Patient categorization (New, Returning, Walk-ins) and clinical visit directory.</p>
                    </div>
                </div>
                <div class="text-muted small">Period: <strong><?= e($displayRangeText) ?></strong></div>
            </div>

            <!-- FILTER BAR -->
            <form method="GET" class="report-filter-bar no-print">
                <input type="hidden" name="tab" value="patient">
                <div class="report-filter-inputs">
                    <label><i class="bi bi-calendar-event me-1 text-success"></i>From:</label>
                    <input type="date" name="from" value="<?= e($fromDate) ?>" required>
                    <label class="ms-2">To:</label>
                    <input type="date" name="to" value="<?= e($toDate) ?>" required>
                    <button type="submit" class="btn-generate-report ms-2">
                        <i class="bi bi-funnel-fill"></i> Generate Report
                    </button>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <a href="?tab=patient&range=month" class="quick-filter-pill <?= $rangePreset === 'month' ? 'active' : '' ?>">This Month</a>
                    <a href="?tab=patient&range=30days" class="quick-filter-pill <?= $rangePreset === '30days' ? 'active' : '' ?>">Last 30 Days</a>
                    <a href="?tab=patient&range=year" class="quick-filter-pill <?= $rangePreset === 'year' ? 'active' : '' ?>">This Year</a>
                    <a href="?tab=patient&range=all" class="quick-filter-pill <?= $rangePreset === 'all' ? 'active' : '' ?>">All Time</a>
                </div>
            </form>

            <div class="row g-4 mb-4">
                <!-- CHART: PATIENTS BY TYPE (Donut Chart with Total In Center) -->
                <div class="col-lg-6">
                    <div class="chart-box-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <h4>Patients by Type</h4>
                                <p class="mb-0">Distribution of new registrants, returning consultations, and walk-in arrivals.</p>
                            </div>
                            <span class="badge" style="background:#EAF7F0; color:#0E6B5E; border:1px solid #C9E9DA; font-weight:800;">
                                Total: <?= $r2TotalPatientsCount ?>
                            </span>
                        </div>
                        <div class="chart-canvas-wrap" style="height: 250px;">
                            <canvas id="patientTypeChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- PATIENT STATS SUMMARY CARD -->
                <div class="col-lg-6">
                    <div class="chart-box-card d-flex flex-column justify-content-center">
                        <h4 class="mb-3">Patient Summary Metrics</h4>
                        <div class="d-flex flex-column gap-3">
                            <div class="demand-row mb-0" style="border-left: 4px solid #0EA5E9;">
                                <span><i class="bi bi-person-plus-fill me-2" style="color:#0EA5E9;"></i>New Patients</span>
                                <strong><?= $r2NewPatients ?> (<?= $r2TotalPatientsCount > 0 ? round(($r2NewPatients / $r2TotalPatientsCount)*100, 1) : 0 ?>%)</strong>
                            </div>
                            <div class="demand-row mb-0" style="border-left: 4px solid #10B981;">
                                <span><i class="bi bi-arrow-repeat me-2" style="color:#10B981;"></i>Returning Patients</span>
                                <strong><?= $r2ReturningPatients ?> (<?= $r2TotalPatientsCount > 0 ? round(($r2ReturningPatients / $r2TotalPatientsCount)*100, 1) : 0 ?>%)</strong>
                            </div>
                            <div class="demand-row mb-0" style="border-left: 4px solid #F59E0B;">
                                <span><i class="bi bi-qr-code-scan me-2" style="color:#F59E0B;"></i>Walk-ins (Queue System)</span>
                                <strong><?= $r2WalkinPatients ?> (<?= $r2TotalPatientsCount > 0 ? round(($r2WalkinPatients / $r2TotalPatientsCount)*100, 1) : 0 ?>%)</strong>
                            </div>
                        </div>
                        <div class="mt-3 p-3 rounded-3" style="background:#F6FBF8; border:1px solid #D9EFE4; font-size:12px; color:#5b747e;">
                            <i class="bi bi-info-circle-fill text-success me-1"></i>
                            Returning patients represent repeat clinical consultations, while walk-ins reflect patients queued directly at the clinic.
                        </div>
                    </div>
                </div>
            </div>

            <!-- SEARCH & PRINT PATIENT MEDICAL RECORD -->
            <div class="p-3 mb-4 rounded-3 no-print" style="background:#F6FBF8; border:1.5px solid #C9E9DA;">
                <div class="row g-2 align-items-center">
                    <div class="col-md-7">
                        <h5 class="mb-1 fw-bold" style="color:#0E6B5E; font-size:14.5px;">
                            <i class="bi bi-search me-1"></i>Search &amp; Print Patient Medical Record
                        </h5>
                        <p class="text-muted small mb-0">Search any registered patient by name or ID to generate and print their official clinical medical chart.</p>
                    </div>
                    <div class="col-md-5">
                        <form method="GET" class="d-flex gap-2 mb-0">
                            <input type="hidden" name="tab" value="patient">
                            <input type="hidden" name="from" value="<?= e($fromDate) ?>">
                            <input type="hidden" name="to" value="<?= e($toDate) ?>">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white border-end-0" style="border-color:#C9E9DA; color:#0E6B5E;"><i class="bi bi-person-search"></i></span>
                                <input type="text" name="patient_search" id="patientRecordSearchInput" class="form-control border-start-0" placeholder="Type patient name or ID..." value="<?= e($patientSearchKeyword) ?>" style="border-color:#C9E9DA;">
                                <?php if ($patientSearchKeyword !== ''): ?>
                                    <a href="?tab=patient&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>" class="btn btn-sm btn-outline-secondary" data-tip="Clear Search">Clear</a>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-sm btn-success" style="background:#0E6B5E; border-color:#0E6B5E;"><i class="bi bi-search"></i></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- PATIENT RECORDS TABLE -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0 fw-bold" style="color:#143f51; font-size:15px;"><i class="bi bi-person-lines-fill me-2 text-success"></i>Patient Directory &amp; Visit Log</h4>
                <small class="text-muted">Showing <?= count($r2PatientsList) ?> patient record<?= count($r2PatientsList) === 1 ? '' : 's' ?></small>
            </div>
            <div class="table-responsive">
                <table class="report-table" data-report-paginate="10">
                    <thead>
                        <tr>
                            <th>Patient ID</th>
                            <th>Name</th>
                            <th>Gender</th>
                            <th>Contact No.</th>
                            <th>Email</th>
                            <th>Total Visits</th>
                            <th>Last Visit &amp; Service</th>
                            <th class="no-print text-center" style="width:130px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($r2PatientsList)): ?>
                            <tr data-empty-row="1"><td colspan="8" class="text-center text-muted p-4">No patient records found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($r2PatientsList as $pl): ?>
                                <tr>
                                    <td><strong style="color:#0E6B5E;"><?= e($pl['patient_number'] ?: ('#' . $pl['id'])) ?></strong></td>
                                    <td><strong><?= e($pl['patient_name']) ?></strong></td>
                                    <td><?= e($pl['gender'] ?: '—') ?></td>
                                    <td><?= e($pl['contact_number'] ?: '—') ?></td>
                                    <td><small class="text-muted"><?= e($pl['email'] ?: '—') ?></small></td>
                                    <td><span class="badge bg-light text-dark border"><?= (int)$pl['total_visits'] ?></span></td>
                                    <td>
                                        <?php if (!empty($pl['last_visit_date'])): ?>
                                            <strong><?= e(date('M d, Y', strtotime($pl['last_visit_date']))) ?></strong><br>
                                            <small class="text-muted"><?= e($pl['last_service'] ?: 'Consultation') ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="no-print text-center">
                                        <a href="patient_record_print.php?id=<?= (int)$pl['id'] ?>" target="_blank" class="btn btn-sm text-white" style="background:linear-gradient(135deg,#0E6B5E,#0A4A41); font-size:11px; font-weight:700; border-radius:6px; padding:4px 9px; white-space:nowrap; box-shadow:0 2px 6px rgba(14,107,94,.2);" data-tip="Print Medical Record">
                                            <i class="bi bi-printer-fill me-1"></i>Print Record
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>


    <!-- ====================================================================
         3. DENTIST PERFORMANCE REPORT TAB
         ==================================================================== -->
    <div class="report-tab-pane <?= $activeTab === 'dentist' ? '' : 'd-none' ?>" id="pane-dentist">
        <div class="report-section-card">
            <div class="report-section-header">
                <div class="report-section-title">
                    <span class="report-title-icon"><i class="bi bi-person-badge-fill"></i></span>
                    <div>
                        <h2>Dentist Performance Report</h2>
                        <p>Workload comparison, completion rates, and cancellations per clinic dentist.</p>
                    </div>
                </div>
                <div class="text-muted small">Period: <strong><?= e($displayRangeText) ?></strong></div>
            </div>

            <!-- FILTER BAR -->
            <form method="GET" class="report-filter-bar no-print">
                <input type="hidden" name="tab" value="dentist">
                <div class="report-filter-inputs">
                    <label><i class="bi bi-calendar-event me-1 text-success"></i>From:</label>
                    <input type="date" name="from" value="<?= e($fromDate) ?>" required>
                    <label class="ms-2">To:</label>
                    <input type="date" name="to" value="<?= e($toDate) ?>" required>
                    <button type="submit" class="btn-generate-report ms-2">
                        <i class="bi bi-funnel-fill"></i> Generate Report
                    </button>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <a href="?tab=dentist&range=month" class="quick-filter-pill <?= $rangePreset === 'month' ? 'active' : '' ?>">This Month</a>
                    <a href="?tab=dentist&range=30days" class="quick-filter-pill <?= $rangePreset === '30days' ? 'active' : '' ?>">Last 30 Days</a>
                    <a href="?tab=dentist&range=year" class="quick-filter-pill <?= $rangePreset === 'year' ? 'active' : '' ?>">This Year</a>
                    <a href="?tab=dentist&range=all" class="quick-filter-pill <?= $rangePreset === 'all' ? 'active' : '' ?>">All Time</a>
                </div>
            </form>

            <div class="row g-4 mb-4">
                <!-- DENTIST PERFORMANCE TABLE -->
                <div class="col-lg-7">
                    <h4 class="mb-3 fw-bold" style="color:#143f51; font-size:15px;"><i class="bi bi-list-check me-2 text-success"></i>Dentist Consultation Summary</h4>
                    <div class="table-responsive">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Dentist</th>
                                    <th>Total<br>Appointments</th>
                                    <th>Completed</th>
                                    <th>Cancelled</th>
                                    <th>No-Show</th>
                                    <th>Completion<br>Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($r3Dentists)): ?>
                                    <tr><td colspan="6" class="text-center text-muted p-4">No dentist records found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($r3Dentists as $drow): 
                                        $tot = (int)$drow['total_appts'];
                                        $comp = (int)$drow['completed_appts'];
                                        $rate = $tot > 0 ? round(($comp / $tot) * 100, 1) : 0;
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?= e($drow['dentist_name']) ?></strong><br>
                                                <small class="text-muted"><?= e($drow['specialization'] ?: 'General Dentistry') ?></small>
                                            </td>
                                            <td><span class="badge bg-light text-dark border fw-bold"><?= $tot ?></span></td>
                                            <td><span class="badge-status completed"><?= $comp ?></span></td>
                                            <td><span class="badge-status cancelled"><?= (int)$drow['cancelled_appts'] ?></span></td>
                                            <td><span class="badge-status noshow"><?= (int)$drow['noshow_appts'] ?></span></td>
                                            <td><strong><?= $rate ?>%</strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- HORIZONTAL BAR CHART: APPOINTMENTS BY DENTIST -->
                <div class="col-lg-5">
                    <div class="chart-box-card">
                        <h4>Appointments by Dentist</h4>
                        <p>Workload comparison across dentists in the selected timeframe.</p>
                        <div class="chart-canvas-wrap" style="height: 250px;">
                            <canvas id="dentistBarChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- ====================================================================
         4. SERVICES REPORT TAB
         ==================================================================== -->
    <div class="report-tab-pane <?= $activeTab === 'services' ? '' : 'd-none' ?>" id="pane-services">
        <div class="report-section-card">
            <div class="report-section-header">
                <div class="report-section-title">
                    <span class="report-title-icon"><i class="bi bi-heart-pulse-fill"></i></span>
                    <div>
                        <h2>Services Report</h2>
                        <p>Analysis of most requested dental procedures and treatments.</p>
                    </div>
                </div>
                <div class="text-muted small">Period: <strong><?= e($displayRangeText) ?></strong></div>
            </div>

            <!-- FILTER BAR -->
            <form method="GET" class="report-filter-bar no-print">
                <input type="hidden" name="tab" value="services">
                <div class="report-filter-inputs">
                    <label><i class="bi bi-calendar-event me-1 text-success"></i>From:</label>
                    <input type="date" name="from" value="<?= e($fromDate) ?>" required>
                    <label class="ms-2">To:</label>
                    <input type="date" name="to" value="<?= e($toDate) ?>" required>
                    <button type="submit" class="btn-generate-report ms-2">
                        <i class="bi bi-funnel-fill"></i> Generate Report
                    </button>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <a href="?tab=services&range=month" class="quick-filter-pill <?= $rangePreset === 'month' ? 'active' : '' ?>">This Month</a>
                    <a href="?tab=services&range=30days" class="quick-filter-pill <?= $rangePreset === '30days' ? 'active' : '' ?>">Last 30 Days</a>
                    <a href="?tab=services&range=year" class="quick-filter-pill <?= $rangePreset === 'year' ? 'active' : '' ?>">This Year</a>
                    <a href="?tab=services&range=all" class="quick-filter-pill <?= $rangePreset === 'all' ? 'active' : '' ?>">All Time</a>
                </div>
            </form>

            <div class="row g-4 mb-4">
                <!-- SERVICES TABLE -->
                <div class="col-lg-7">
                    <h4 class="mb-3 fw-bold" style="color:#143f51; font-size:15px;"><i class="bi bi-pie-chart-fill me-2 text-success"></i>Dental Services Breakdown</h4>
                    <div class="table-responsive">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Dental Service</th>
                                    <th>Total Performed</th>
                                    <th>Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($r4Services)): ?>
                                    <tr><td colspan="3" class="text-center text-muted p-4">No dental service records found for this period.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($r4Services as $srow): ?>
                                        <tr>
                                            <td><strong><?= e($srow['service_name']) ?></strong></td>
                                            <td><span class="badge bg-light text-dark border fw-bold"><?= (int)$srow['total_performed'] ?></span></td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="progress flex-grow-1" style="height: 7px; background:#E2ECE7;">
                                                        <div class="progress-bar" style="width: <?= $srow['percentage'] ?>%; background: linear-gradient(90deg, #0E6B5E, #10B981);"></div>
                                                    </div>
                                                    <span class="fw-bold" style="min-width: 45px; text-align:right; font-size:12px;"><?= $srow['percentage'] ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- DONUT CHART: SERVICE DISTRIBUTION -->
                <div class="col-lg-5">
                    <div class="chart-box-card">
                        <h4>Service Distribution</h4>
                        <p>Visual proportion of treatments provided during the timeframe.</p>
                        <div class="chart-canvas-wrap" style="height: 250px;">
                            <canvas id="servicePieChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- ====================================================================
         5. NO-SHOW REPORT TAB
         ==================================================================== -->
    <div class="report-tab-pane <?= $activeTab === 'noshow' ? '' : 'd-none' ?>" id="pane-noshow">
        <div class="report-section-card">
            <div class="report-section-header">
                <div class="report-section-title">
                    <span class="report-title-icon"><i class="bi bi-person-x-fill"></i></span>
                    <div>
                        <h2>No-Show Report</h2>
                        <p>Missed appointment metrics, missed appointment trends, and patient no-show directory.</p>
                    </div>
                </div>
                <div class="text-muted small">Period: <strong><?= e($displayRangeText) ?></strong></div>
            </div>

            <!-- FILTER BAR -->
            <form method="GET" class="report-filter-bar no-print">
                <input type="hidden" name="tab" value="noshow">
                <div class="report-filter-inputs">
                    <label><i class="bi bi-calendar-event me-1 text-success"></i>From:</label>
                    <input type="date" name="from" value="<?= e($fromDate) ?>" required>
                    <label class="ms-2">To:</label>
                    <input type="date" name="to" value="<?= e($toDate) ?>" required>
                    <button type="submit" class="btn-generate-report ms-2">
                        <i class="bi bi-funnel-fill"></i> Generate Report
                    </button>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <a href="?tab=noshow&range=month" class="quick-filter-pill <?= $rangePreset === 'month' ? 'active' : '' ?>">This Month</a>
                    <a href="?tab=noshow&range=30days" class="quick-filter-pill <?= $rangePreset === '30days' ? 'active' : '' ?>">Last 30 Days</a>
                    <a href="?tab=noshow&range=year" class="quick-filter-pill <?= $rangePreset === 'year' ? 'active' : '' ?>">This Year</a>
                    <a href="?tab=noshow&range=all" class="quick-filter-pill <?= $rangePreset === 'all' ? 'active' : '' ?>">All Time</a>
                </div>
            </form>

            <div class="row g-4 mb-4">
                <!-- 3 STAT METRIC CARDS -->
                <div class="col-lg-4">
                    <div class="d-flex flex-column gap-3">
                        <div class="mini-stat-card">
                            <span class="mini-stat-icon blue"><i class="bi bi-calendar2-range-fill"></i></span>
                            <div class="mini-stat-info">
                                <small>Total Scheduled</small>
                                <strong><?= $r5TotalScheduled ?></strong>
                            </div>
                        </div>
                        <div class="mini-stat-card">
                            <span class="mini-stat-icon red"><i class="bi bi-person-x-fill"></i></span>
                            <div class="mini-stat-info">
                                <small>Total No-Shows</small>
                                <strong><?= $r5TotalNoShows ?></strong>
                            </div>
                        </div>
                        <div class="mini-stat-card">
                            <span class="mini-stat-icon amber"><i class="bi bi-percent"></i></span>
                            <div class="mini-stat-info">
                                <small>No-Show Rate</small>
                                <strong style="color: <?= $r5NoShowRate > 15 ? '#DC2626' : '#D97706' ?>;"><?= $r5NoShowRate ?>%</strong>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- NO-SHOW TREND CHART -->
                <div class="col-lg-8">
                    <div class="chart-box-card">
                        <h4>No-Show Trend Over Time</h4>
                        <p>Timeline of missed appointments across the selected period.</p>
                        <div class="chart-canvas-wrap" style="height: 220px;">
                            <canvas id="noshowBarChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- NO-SHOW PATIENTS TABLE -->
            <h4 class="mb-3 fw-bold" style="color:#143f51; font-size:15px;"><i class="bi bi-person-dash-fill me-2 text-danger"></i>No-Show Patients Directory</h4>
            <div class="table-responsive">
                <table class="report-table" data-report-paginate="10">
                    <thead>
                        <tr>
                            <th>Patient ID</th>
                            <th>Patient Name</th>
                            <th>Date & Time</th>
                            <th>Dental Service</th>
                            <th>Assigned Dentist</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($r5NoShowPatients)): ?>
                            <tr data-empty-row="1"><td colspan="5" class="text-center text-muted p-4">No missed appointments recorded for this timeframe.</td></tr>
                        <?php else: ?>
                            <?php foreach ($r5NoShowPatients as $np): ?>
                                <tr>
                                    <td><strong style="color:#0E6B5E;"><?= e($np['patient_number'] ?: ('#' . $np['id'])) ?></strong></td>
                                    <td><strong><?= e($np['patient_name']) ?></strong></td>
                                    <td>
                                        <strong><?= e(date('M d, Y', strtotime($np['appointment_date']))) ?></strong><br>
                                        <small class="text-muted"><?= e(date('h:i A', strtotime($np['start_time']))) ?></small>
                                    </td>
                                    <td><?= e($np['service_type'] ?: 'Consultation') ?></td>
                                    <td><?= e($np['dentist_name'] ?: 'Not assigned') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>


    <!-- ====================================================================
         6. HISTORICAL DEMAND & AI LINEAR REGRESSION FORECAST TAB (Capstone Core)
         ==================================================================== -->
    <div class="report-tab-pane <?= $activeTab === 'forecast' ? '' : 'd-none' ?>" id="pane-forecast">
        <div class="report-section-card">
            <div class="report-section-header">
                <div class="report-section-title">
                    <span class="report-title-icon"><i class="bi bi-graph-up-arrow"></i></span>
                    <div>
                        <h2>Historical Demand & 7-Day Demand Forecast</h2>
                        <p>AI Linear Regression forecast, busiest operating days, and smart rescheduling optimization.</p>
                    </div>
                </div>
                <span class="badge" style="background:rgba(14,107,94,.12); color:#0E6B5E; border:1px solid rgba(14,107,94,.25); font-size:13px; font-weight:800; padding:6px 14px;">
                    <?= (int) $forecastTotal ?> Total Forecasted
                </span>
            </div>

            <!-- HISTORICAL DEMAND (LAST 90 DAYS) -->
            <div class="row g-4 mb-4">
                <div class="col-lg-5">
                    <div class="chart-box-card">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-clock-history fs-5 text-success"></i>
                            <h4 class="mb-0">Historical Appointment Demand</h4>
                        </div>
                        <p>Busiest operating weekdays recorded over the last 90 clinic operating days.</p>
                        <?php if (empty($historicalDemand)): ?>
                            <p class="text-muted small">No historical demand data recorded yet.</p>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-2 mt-3">
                                <?php foreach ($historicalDemand as $hrow): ?>
                                    <div class="demand-row mb-0">
                                        <span><i class="bi bi-calendar-day me-2 text-success"></i><?= e($hrow['day_name']) ?></span>
                                        <strong><?= (int) $hrow['total'] ?> appointments</strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 7-DAY AI DEMAND FORECAST -->
                <div class="col-lg-7">
                    <div class="chart-box-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <h4 class="mb-0"><i class="bi bi-cpu-fill text-success me-1"></i>7-Day AI Demand Breakdown</h4>
                                <p class="mb-0">Predicted daily appointment load combined with confirmed bookings.</p>
                            </div>
                            <span class="fs-4 fw-bold" style="color:#0E6B5E; font-family:'Plus Jakarta Sans',sans-serif;"><?= (int) $forecastTotal ?></span>
                        </div>

                        <?php if ($aiPredictionCount === 0): ?>
                            <div class="alert alert-light small p-2 mt-2">ML model is computing from historical appointments.</div>
                        <?php endif; ?>

                        <div class="d-flex flex-column gap-2 mt-3">
                            <?php foreach ($displayForecast as $row): 
                                $dl = strtolower((string) ($row['demand_level'] ?? 'low'));
                                $level = $dl === 'high' ? 'high' : ($dl === 'medium' ? 'moderate' : 'low');
                            ?>
                                <div class="demand-row mb-0 demand-<?= $level ?>">
                                    <span>
                                        <strong><?= e($row['day']) ?></strong>, <?= e(date('M d', strtotime($row['date']))) ?>
                                        <?php if (($row['day'] ?? '') === 'Sunday' && (int) $row['total'] === 0): ?>
                                            <small class="text-muted ms-1">(Clinic Closed)</small>
                                        <?php else: ?>
                                            <small class="text-muted ms-1">(AI ML: <?= (int) ($row['ml_prediction'] ?? 0) ?> · Booked: <?= (int) ($row['actual_booked'] ?? 0) ?>)</small>
                                        <?php endif; ?>
                                    </span>
                                    <strong style="color: <?= $level === 'high' ? '#B91C1C' : ($level === 'moderate' ? '#B45309' : '#15803D') ?>;">
                                        <?= (int) $row['total'] ?> appointments (<?= ucfirst($level) ?>)
                                    </strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ACTIONABLE AI RESCHEDULING RECOMMENDATIONS -->
            <div class="mt-4 pt-3 border-top">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <h4 class="mb-0 fw-bold" style="color:#143f51; font-size:16px;">
                        <i class="bi bi-stars text-success me-1"></i>AI Workload Balancing & Rescheduling Recommendations
                    </h4>
                    <a href="appointments.php" class="btn btn-sm btn-outline-success no-print fw-bold">
                        <i class="bi bi-calendar2-week me-1"></i>Manage in Appointments
                    </a>
                </div>
                <p class="text-muted small mb-3">
                    Linear regression workload optimization: Recommends scheduling walk-ins and rescheduling missed/cancelled/pending appointments to the lowest-demand operating days.
                </p>
                <?php if (!empty($rescheduleCandidates) && !empty($recommendedSlots)): ?>
                    <div class="table-responsive">
                        <table class="report-table" data-report-paginate="10">
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
                                <?php foreach ($rescheduleCandidates as $idx => $rc): 
                                    $recSlot = $recommendedSlots[$idx % count($recommendedSlots)];
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($rc['patient_name']) ?></strong><br>
                                            <small class="text-muted"><?= e($rc['contact_number'] ?: '') ?></small>
                                        </td>
                                        <td>
                                            <span class="badge-status <?= strtolower($rc['status']) === 'no-show' ? 'noshow' : (strtolower($rc['status']) === 'cancelled' ? 'cancelled' : 'pending') ?>">
                                                <?= e($rc['status']) ?>
                                            </span><br>
                                            <small class="text-muted"><?= e(date('M d, Y', strtotime($rc['appointment_date']))) ?> (<?= e(date('h:i A', strtotime($rc['start_time']))) ?>)</small>
                                        </td>
                                        <td>
                                            <?= e($rc['service_type']) ?><br>
                                            <small class="text-muted"><?= e($rc['dentist_name'] ?: 'Any Dentist') ?></small>
                                        </td>
                                        <td>
                                            <strong style="color:#0E6B5E;"><?= e($recSlot['day']) ?>, <?= e(date('M d, Y', strtotime($recSlot['date']))) ?></strong><br>
                                            <small class="text-muted"><?= e($recSlot['demand_level']) ?> Load (Forecast: <?= (int)$recSlot['total'] ?>, Booked: <?= (int)$recSlot['actual_booked'] ?>)</small>
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
                <?php else: ?>
                    <div class="alert alert-light small">No pending or missed appointments require rescheduling at this time.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ====================================================================
         PRINT-ONLY OFFICIAL CLINICAL SIGN-OFF FOOTER
         ==================================================================== -->
    <div class="clinic-print-footer">
        <div class="cpf-signatures">
            <div class="cpf-sign-col">
                <div class="cpf-label">Prepared By:</div>
                <div class="cpf-line"></div>
                <div class="cpf-name"><?= e($_SESSION['admin_name'] ?? 'System Administrator') ?></div>
                <div class="cpf-role">Clinic Administrator / Staff</div>
            </div>
            <div class="cpf-sign-col">
                <div class="cpf-label">Verified & Approved By:</div>
                <div class="cpf-line"></div>
                <div class="cpf-name">Dr. Abalos, D.M.D.</div>
                <div class="cpf-role">Head Dentist / Clinic Director</div>
            </div>
        </div>
        <div class="cpf-footnote">
            <span>Abalos Dental Clinic &bull; Official Clinical Operations & Analytics Report</span>
            <span>Generated from Abalos Dental Management System</span>
        </div>
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- ====================================================================
     CLIENT-SIDE CHART.JS RENDERING & PAGINATION
     ==================================================================== -->
<script>
document.addEventListener("DOMContentLoaded", function () {
    /* ---------------------------------------------------------------
       1. CHART: Patients by Type (Donut)
       --------------------------------------------------------------- */
    const ctxPatient = document.getElementById("patientTypeChart");
    if (ctxPatient) {
        new Chart(ctxPatient, {
            type: "doughnut",
            data: {
                labels: ["New Patients", "Returning Patients", "Walk-ins"],
                datasets: [{
                    data: [<?= (int)$r2NewPatients ?>, <?= (int)$r2ReturningPatients ?>, <?= (int)$r2WalkinPatients ?>],
                    backgroundColor: ["#0EA5E9", "#10B981", "#F59E0B"],
                    borderWidth: 2,
                    borderColor: "#ffffff"
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: "bottom", labels: { boxWidth: 12, font: { family: "Inter", size: 12, weight: 600 } } }
                },
                cutout: "68%"
            }
        });
    }

    /* ---------------------------------------------------------------
       2. CHART: Appointments by Dentist (Horizontal Bar)
       --------------------------------------------------------------- */
    const ctxDentist = document.getElementById("dentistBarChart");
    if (ctxDentist) {
        const dentistLabels = <?= json_encode(array_column($r3Dentists, 'dentist_name')) ?>;
        const dentistTotals = <?= json_encode(array_map('intval', array_column($r3Dentists, 'total_appts'))) ?>;

        new Chart(ctxDentist, {
            type: "bar",
            data: {
                labels: dentistLabels,
                datasets: [{
                    label: "Appointments",
                    data: dentistTotals,
                    backgroundColor: "#0E6B5E",
                    borderRadius: 6
                }]
            },
            options: {
                indexAxis: "y",
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0, font: { family: "Inter", size: 9 } } },
                    y: { ticks: { font: { family: "Inter", weight: 600, size: 9.5 } } }
                }
            }
        });
    }

    /* ---------------------------------------------------------------
       3. CHART: Service Distribution (Pie with Percentage Labels)
       --------------------------------------------------------------- */
    const ctxService = document.getElementById("servicePieChart");
    if (ctxService) {
        const serviceLabels = <?= json_encode(array_column($r4Services, 'service_name')) ?>;
        const serviceCounts = <?= json_encode(array_map('intval', array_column($r4Services, 'total_performed'))) ?>;
        const baseColors = [
            "#0E6B5E", "#0EA5E9", "#F59E0B", "#8B5CF6", "#10B981", 
            "#EC4899", "#64748B", "#F97316", "#14B8A6", "#6366F1",
            "#D97706", "#2563EB", "#059669", "#DC2626", "#7C3AED",
            "#0284C7", "#475569", "#EA580C", "#0D9488", "#4F46E5"
        ];
        const serviceColors = serviceLabels.map((_, i) => baseColors[i % baseColors.length]);

        // Custom plugin to draw percentage directly on visible slices (>= 4.5%)
        const piePercentagePlugin = {
            id: 'piePercentageLabels',
            afterDatasetDraw(chart) {
                const { ctx, data } = chart;
                const meta = chart.getDatasetMeta(0);
                if (!meta || !meta.data) return;

                const total = data.datasets[0].data.reduce((a, b) => a + b, 0);
                if (total <= 0) return;

                ctx.save();
                ctx.font = "bold 10.5px 'Inter', system-ui, sans-serif";
                ctx.fillStyle = "#ffffff";
                ctx.textAlign = "center";
                ctx.textBaseline = "middle";
                ctx.shadowColor = "rgba(0, 0, 0, 0.6)";
                ctx.shadowBlur = 4;

                meta.data.forEach((arc, i) => {
                    const val = data.datasets[0].data[i];
                    if (!val || chart.getDataVisibility(i) === false) return;
                    const pct = (val / total) * 100;
                    if (pct >= 4.5) {
                        let center;
                        if (typeof arc.getCenterPoint === "function") {
                            center = arc.getCenterPoint();
                        } else {
                            const angle = (arc.startAngle + arc.endAngle) / 2;
                            const r = (arc.innerRadius + arc.outerRadius) / 2;
                            center = {
                                x: arc.x + Math.cos(angle) * r,
                                y: arc.y + Math.sin(angle) * r
                            };
                        }
                        const label = pct % 1 === 0 ? pct.toFixed(0) + "%" : pct.toFixed(1) + "%";
                        ctx.fillText(label, center.x, center.y);
                    }
                });
                ctx.restore();
            }
        };

        new Chart(ctxService, {
            type: "pie",
            data: {
                labels: serviceLabels,
                datasets: [{
                    data: serviceCounts,
                    backgroundColor: serviceColors,
                    borderWidth: 2,
                    borderColor: "#ffffff"
                }]
            },
            plugins: [piePercentagePlugin],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: { top: 4, bottom: 4, left: 4, right: 6 }
                },
                plugins: {
                    legend: {
                        position: "right",
                        labels: {
                            boxWidth: 10,
                            padding: 5,
                            font: { family: "Inter", size: 9.5, weight: 600 },
                            generateLabels: function(chart) {
                                const data = chart.data;
                                if (!data.labels.length || !data.datasets.length) return [];
                                const total = data.datasets[0].data.reduce((a, b) => a + b, 0);
                                return data.labels.map(function(label, i) {
                                    const val = data.datasets[0].data[i];
                                    const pct = total > 0 ? ((val / total) * 100).toFixed(1) : "0";
                                    return {
                                        text: `${label} (${pct}%)`,
                                        fillStyle: data.datasets[0].backgroundColor[i],
                                        strokeStyle: "#ffffff",
                                        lineWidth: 1,
                                        hidden: !chart.getDataVisibility(i),
                                        index: i
                                    };
                                });
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const val = context.parsed || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
                                return ` ${context.label}: ${val} (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    /* ---------------------------------------------------------------
       4. CHART: No-Show Trend (Bar)
       --------------------------------------------------------------- */
    const ctxNoShow = document.getElementById("noshowBarChart");
    if (ctxNoShow) {
        const trendDates = <?= json_encode(array_map(function($r){ return date('M d', strtotime($r['appointment_date'])); }, $r5TrendRows)) ?>;
        const trendCounts = <?= json_encode(array_map('intval', array_column($r5TrendRows, 'noshow_count'))) ?>;

        new Chart(ctxNoShow, {
            type: "bar",
            data: {
                labels: trendDates.length ? trendDates : ["No Data"],
                datasets: [{
                    label: "No-Shows",
                    data: trendCounts.length ? trendCounts : [0],
                    backgroundColor: "#EF4444",
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { ticks: { font: { family: "Inter", size: 11 } } },
                    y: { beginAtZero: true, ticks: { precision: 0, font: { family: "Inter" } } }
                }
            }
        });
    }

    /* ---------------------------------------------------------------
       5. CLIENT-SIDE PAGINATION FOR REPORT TABLES (10 per page)
       --------------------------------------------------------------- */
    document.querySelectorAll("table[data-report-paginate]").forEach(function (table) {
        const perPage = parseInt(table.getAttribute("data-report-paginate"), 10) || 10;
        const tbody = table.querySelector("tbody");
        if (!tbody) return;
        const rows = Array.from(tbody.querySelectorAll("tr:not([data-empty-row])"));
        const totalRows = rows.length;
        if (totalRows <= perPage) return;

        let currentPage = 1;
        const totalPages = Math.ceil(totalRows / perPage);

        const paginationWrap = document.createElement("div");
        paginationWrap.className = "report-pagination no-print";
        paginationWrap.innerHTML = `
            <div class="report-pagination-info">
                Showing <strong class="pg-from">1</strong> to <strong class="pg-to">${Math.min(perPage, totalRows)}</strong> of <strong>${totalRows}</strong> records
            </div>
            <div class="report-page-nav">
                <button type="button" class="report-page-btn pg-prev" disabled><i class="bi bi-chevron-left"></i></button>
                <span class="report-page-btn active pg-num">1</span>
                <button type="button" class="report-page-btn pg-next"><i class="bi bi-chevron-right"></i></button>
            </div>
        `;
        table.parentElement.parentElement.appendChild(paginationWrap);

        const pgFrom = paginationWrap.querySelector(".pg-from");
        const pgTo = paginationWrap.querySelector(".pg-to");
        const pgNum = paginationWrap.querySelector(".pg-num");
        const btnPrev = paginationWrap.querySelector(".pg-prev");
        const btnNext = paginationWrap.querySelector(".pg-next");

        function renderPage(page) {
            currentPage = page;
            const start = (page - 1) * perPage;
            const end = start + perPage;

            rows.forEach((r, idx) => {
                r.style.display = (idx >= start && idx < end) ? "" : "none";
            });

            pgFrom.textContent = start + 1;
            pgTo.textContent = Math.min(end, totalRows);
            pgNum.textContent = page + " / " + totalPages;

            btnPrev.disabled = page <= 1;
            btnNext.disabled = page >= totalPages;
        }

        btnPrev.addEventListener("click", () => { if (currentPage > 1) renderPage(currentPage - 1); });
        btnNext.addEventListener("click", () => { if (currentPage < totalPages) renderPage(currentPage + 1); });

        renderPage(1);
    });

    /* ---------------------------------------------------------------
       5b. LIVE PATIENT RECORD SEARCH FILTER
       --------------------------------------------------------------- */
    const patientSearchInput = document.getElementById("patientRecordSearchInput");
    if (patientSearchInput) {
        patientSearchInput.addEventListener("input", function() {
            const val = this.value.toLowerCase().trim();
            const table = document.querySelector("#pane-patient .report-table");
            if (!table) return;
            const rows = table.querySelectorAll("tbody tr:not([data-empty-row])");
            rows.forEach(r => {
                const text = r.textContent.toLowerCase();
                if (val === "" || text.indexOf(val) !== -1) {
                    r.style.display = "";
                } else {
                    r.style.display = "none";
                }
            });
        });
    }

    /* ---------------------------------------------------------------
       6. PRINT EVENT LISTENERS: Reveal all rows during print & restore
       --------------------------------------------------------------- */
    window.addEventListener("beforeprint", function () {
        document.querySelectorAll("table[data-report-paginate] tbody tr").forEach(function (r) {
            r.style.display = "";
        });
    });

    window.addEventListener("afterprint", function () {
        document.querySelectorAll("table[data-report-paginate]").forEach(function (table) {
            const perPage = parseInt(table.getAttribute("data-report-paginate"), 10) || 10;
            const tbody = table.querySelector("tbody");
            if (!tbody) return;
            const rows = Array.from(tbody.querySelectorAll("tr:not([data-empty-row])"));
            const pagWrap = table.parentElement.parentElement.querySelector(".report-pagination");
            if (pagWrap && rows.length > perPage) {
                const numText = pagWrap.querySelector(".pg-num") ? pagWrap.querySelector(".pg-num").textContent : "1";
                const cur = parseInt(numText.split("/")[0].trim(), 10) || 1;
                const start = (cur - 1) * perPage;
                const end = start + perPage;
                rows.forEach((r, idx) => {
                    r.style.display = (idx >= start && idx < end) ? "" : "none";
                });
            }
        });
    });
});
</script>

<!-- High-priority print overrides ensuring no web navigation or background elements leak -->
<style media="print">
    html body header,
    html body header.topbar,
    html body .shared-topbar,
    html body .topbar,
    html body aside,
    html body aside.sidebar,
    html body .shared-sidebar,
    html body .sidebar,
    html body .mobile-menu,
    html body .admin-logout-overlay,
    .no-print,
    .reports-head,
    .screen-only-summary,
    .report-nav-tabs,
    .report-filter-bar,
    .report-pagination,
    .btn,
    button,
    a[href="dashboard.php"] {
        display: none !important;
        visibility: hidden !important;
        height: 0 !important;
        max-height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        opacity: 0 !important;
        position: absolute !important;
        top: -9999px !important;
        left: -9999px !important;
    }
    .clinic-print-header,
    .clinic-print-footer {
        display: block !important;
        visibility: visible !important;
        position: static !important;
    }
    .report-tab-pane.d-none {
        display: none !important;
    }
    .report-tab-pane:not(.d-none) {
        display: block !important;
    }
    table.report-table tbody tr {
        display: table-row !important;
    }
</style>
</body>
</html>
