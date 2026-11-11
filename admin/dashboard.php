<?php

require_once __DIR__ . '/../config.php';

/*
|--------------------------------------------------------------------------
| ABALOS DENTAL CLINIC
| ADMIN DASHBOARD
|--------------------------------------------------------------------------
| Main Functions:
| - Dashboard statistics
| - Recent appointments
| - Appointment status monitoring
| - Queue count
| - 7-day appointment demand forecast
| - Linear Regression
| - Existing active appointments included in demand
| - Automatic dashboard refresh
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/*
|--------------------------------------------------------------------------
| ADMIN ACCESS
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    strtolower(trim($_SESSION['role'] ?? '')) !== 'admin'
) {
    header('Location: ../login.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| SAFE ESCAPE FUNCTION
|--------------------------------------------------------------------------
| Do NOT use function e() because config.php already contains e().
|--------------------------------------------------------------------------
*/

function adminDashboardEscape($value)
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| USER INFORMATION
|--------------------------------------------------------------------------
*/

$username =
    $_SESSION['full_name']
    ?? $_SESSION['username']
    ?? 'System Administrator';


/*
|--------------------------------------------------------------------------
| DEFAULT DASHBOARD VALUES
|--------------------------------------------------------------------------
*/

$dashboardCounts = [
    'today'     => 0,
    'pending'   => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'queue'     => 0
];


/*
|--------------------------------------------------------------------------
| FORECAST VARIABLES
|--------------------------------------------------------------------------
*/

$forecastData = [];

$historicalDays = 0;
$historicalAppointments = 0;

$forecastAverage = 0;
$highestForecast = 0;

$highestDemandDate = null;

$modelStatus = 'Not Trained';

$slope = 0;
$intercept = 0;

$forecastError = null;


/*
|--------------------------------------------------------------------------
| RECENT APPOINTMENTS
|--------------------------------------------------------------------------
*/

$recentAppointments = [];


/*
|--------------------------------------------------------------------------
| UPCOMING APPOINTMENT COUNTS
|--------------------------------------------------------------------------
*/

$upcomingBookedByDate = [];


/*
|--------------------------------------------------------------------------
| DEMAND LEVEL
|--------------------------------------------------------------------------
*/

function getDemandLevel($prediction, $average)
{
    $prediction = (float)$prediction;
    $average = (float)$average;

    if ($prediction <= 0) {
        return 'Low';
    }

    if ($average <= 0) {
        if ($prediction >= 4) {
            return 'High';
        }

        if ($prediction >= 2) {
            return 'Medium';
        }

        return 'Low';
    }

    if ($prediction >= ($average * 1.10)) {
        return 'High';
    }

    if ($prediction >= ($average * 0.78)) {
        return 'Medium';
    }

    return 'Low';
}


/*
|--------------------------------------------------------------------------
| DEMAND CSS CLASS
|--------------------------------------------------------------------------
*/

function getDemandClass($level)
{
    switch ($level) {

        case 'High':
            return 'demand-high';

        case 'Medium':
            return 'demand-medium';

        default:
            return 'demand-low';
    }
}


/*
|--------------------------------------------------------------------------
| DEMAND ICON
|--------------------------------------------------------------------------
*/

function getDemandIcon($level)
{
    switch ($level) {

        case 'High':
            return 'bi bi-fire';

        case 'Medium':
            return 'bi bi-graph-up-arrow';

        default:
            return 'bi bi-graph-down-arrow';
    }
}


/*
|--------------------------------------------------------------------------
| STATUS BADGE
|--------------------------------------------------------------------------
*/

function getStatusBadgeClass($status)
{
    $statusLower = strtolower(trim($status));

    switch ($statusLower) {

        case 'pending':
            return 'badge-pending';

        case 'confirmed':
        case 'approved':
            return 'badge-confirmed';

        case 'completed':
            return 'badge-completed';

        case 'cancelled':
        case 'canceled':
            return 'badge-cancelled';

        case 'no-show':
        case 'no show':
            return 'badge-noshow';

        default:
            return 'badge-default';
    }
}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

try {


    /*
    |--------------------------------------------------------------------------
    | 1. DASHBOARD COUNTS
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->query("
        SELECT

            COALESCE(
                SUM(
                    CASE
                        WHEN appointment_date = CURDATE()
                        AND status NOT IN ('No-Show', 'Completed', 'Cancelled', 'Canceled')
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS today_count,

            COALESCE(
                SUM(
                    CASE
                        WHEN status = 'Pending'
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS pending_count,

            COALESCE(
                SUM(
                    CASE
                        WHEN status IN ('Confirmed', 'Approved')
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS confirmed_count,

            COALESCE(
                SUM(
                    CASE
                        WHEN status = 'Completed'
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS completed_count,

            COALESCE(
                SUM(
                    CASE
                        WHEN status IN ('Cancelled', 'Canceled')
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS cancelled_count

        FROM appointments
    ");

    $counts = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$counts) {
        $counts = [];
    }


    $dashboardCounts['today'] =
        (int)($counts['today_count'] ?? 0);

    $dashboardCounts['pending'] =
        (int)($counts['pending_count'] ?? 0);

    $dashboardCounts['confirmed'] =
        (int)($counts['confirmed_count'] ?? 0);

    $dashboardCounts['completed'] =
        (int)($counts['completed_count'] ?? 0);

    $dashboardCounts['cancelled'] =
        (int)($counts['cancelled_count'] ?? 0);


    /*
    |--------------------------------------------------------------------------
    | 2. CURRENT QUEUE
    |--------------------------------------------------------------------------
    */

    try {

        $stmt = $pdo->query("
            SELECT COUNT(*)
            FROM queue
            WHERE queue_date = CURDATE()
            AND status NOT IN ('Completed', 'Cancelled')
        ");

        $dashboardCounts['queue'] =
            (int)$stmt->fetchColumn();

    } catch (PDOException $queueError) {

        /*
        | Queue table may not exist yet.
        | Dashboard will simply show 0.
        */

        $dashboardCounts['queue'] = 0;
    }


    /*
    |--------------------------------------------------------------------------
    | 3. RECENT APPOINTMENTS
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | Uses:
    | appointment_date
    | start_time
    | end_time
    | service_type
    |
    | These are the same columns used by patient booking.
    |--------------------------------------------------------------------------
    */

    try {

        $stmt = $pdo->query("
            SELECT

                a.id,

                a.patient_id,

                a.dentist_id,

                a.appointment_date,

                a.start_time,

                a.end_time,

                a.service_type,

                a.status,

                a.notes,

                CONCAT(
                    COALESCE(p.first_name, ''),
                    CASE
                        WHEN
                            p.first_name IS NOT NULL
                            AND p.last_name IS NOT NULL
                            AND p.last_name <> ''
                        THEN ' '
                        ELSE ''
                    END,
                    COALESCE(p.last_name, '')
                ) AS patient_name,

                d.dentist_name

            FROM appointments a

            LEFT JOIN patients p
                ON p.id = a.patient_id

            LEFT JOIN dentists d
                ON d.id = a.dentist_id

            ORDER BY
                a.appointment_date DESC,
                a.start_time DESC,
                a.id DESC

            LIMIT 10
        ");

        $recentAppointments =
            $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $recentError) {

        $recentAppointments = [];

        $forecastError =
            'Recent appointments error: ' .
            $recentError->getMessage();
    }


    /*
    |--------------------------------------------------------------------------
    | 4. UPCOMING ACTIVE APPOINTMENTS
    |--------------------------------------------------------------------------
    |
    | These are actual bookings already made by patients.
    |
    | They are used together with the Linear Regression forecast
    | so the demand display reacts to real bookings.
    |--------------------------------------------------------------------------
    */

    try {

        $stmt = $pdo->query("
            SELECT

                appointment_date,
                COUNT(*) AS booked_count

            FROM appointments

            WHERE appointment_date >= CURDATE()

            AND appointment_date <=
                DATE_ADD(CURDATE(), INTERVAL 7 DAY)

            AND status NOT IN (
                'Cancelled',
                'Canceled',
                'No-Show',
                'Completed'
            )

            GROUP BY appointment_date

            ORDER BY appointment_date ASC
        ");

        $upcomingRows =
            $stmt->fetchAll(PDO::FETCH_ASSOC);


        foreach ($upcomingRows as $row) {

            $date =
                $row['appointment_date'];

            $upcomingBookedByDate[$date] =
                (int)$row['booked_count'];
        }

    } catch (PDOException $upcomingError) {

        $upcomingBookedByDate = [];
    }


    /*
    |--------------------------------------------------------------------------
    | 4B. CARD DETAIL LISTS (inline views, same page - no new tab)
    |--------------------------------------------------------------------------
    */

    $todayList = [];
    $pendingList = [];
    $confirmedList = [];
    $completedList = [];
    $queueList = [];

    $pendingConcernMap = [];

    // 'completed' ay tinanggal sa Today view — hindi na kasama ang tapos na appointment.
    $todaySub = ['total' => 0, 'confirmed' => 0, 'pending' => 0];
    $pendingSub = ['total' => 0, 'new' => 0, 'for_confirmation' => 0, 'reschedule' => 0];
    $confirmedSub = ['total' => 0, 'today' => 0, 'week' => 0, 'month' => 0];
    $completedSub = ['total' => 0, 'today' => 0, 'week' => 0, 'month' => 0];
    $queueSub = ['total' => 0, 'waiting' => 0, 'in_consultation' => 0, 'completed_today' => 0];

    $apptJoinSelect = "
            SELECT
                a.id,
                a.patient_id,
                a.dentist_id,
                a.appointment_date,
                a.start_time,
                a.end_time,
                a.service_type,
                a.status,
                a.notes,
                a.created_at,
                CONCAT(
                    COALESCE(p.first_name, ''),
                    CASE
                        WHEN p.first_name IS NOT NULL
                        AND p.last_name IS NOT NULL
                        AND p.last_name <> ''
                        THEN ' '
                        ELSE ''
                    END,
                    COALESCE(p.last_name, '')
                ) AS patient_name,
                d.dentist_name
            FROM appointments a
            LEFT JOIN patients p ON p.id = a.patient_id
            LEFT JOIN dentists d ON d.id = a.dentist_id
    ";

    try {
        // TODAY — hindi na kasama ang mga tapos na (No-Show / Completed / Cancelled).
        // Nasa kanilang sariling detail view o summary card ang mga ito.
        $stmt = $pdo->query($apptJoinSelect . "
            WHERE a.appointment_date = CURDATE()
            AND a.status NOT IN ('No-Show', 'Completed', 'Cancelled', 'Canceled')
            ORDER BY a.start_time ASC, a.id ASC
        ");
        $todayList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($todayList as $t) {
            $todaySub['total']++;
            $s = strtolower(trim($t['status'] ?? ''));
            if ($s === 'confirmed' || $s === 'approved') $todaySub['confirmed']++;
            elseif ($s === 'pending') $todaySub['pending']++;
        }

        // PENDING
        $stmt = $pdo->query($apptJoinSelect . "
            WHERE a.status = 'Pending'
            ORDER BY a.appointment_date ASC, a.start_time ASC
            LIMIT 200
        ");
        $pendingList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($pendingList)) {
            $pids = array_column($pendingList, 'id');
            $placeholders = implode(',', array_fill(0, count($pids), '?'));
            $cstmt = $pdo->prepare("
                SELECT appointment_id, reason, message
                FROM appointment_concerns
                WHERE appointment_id IN ($placeholders)
            ");
            $cstmt->execute(array_map('intval', $pids));
            foreach ($cstmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $pendingConcernMap[(int)$c['appointment_id']] = $c;
            }
        }

        foreach ($pendingList as &$p) {
            $p['_request_type'] = 'For Confirmation';
            $cid = (int)($p['id'] ?? 0);
            $concern = $pendingConcernMap[$cid] ?? null;
            $hay = strtolower(($concern['reason'] ?? '') . ' ' . ($concern['message'] ?? '') . ' ' . ($p['notes'] ?? ''));
            if ($concern && (strpos($hay, 'resched') !== false || strpos($hay, 're-sched') !== false)) {
                $p['_request_type'] = 'Reschedule';
            } elseif (!empty($p['created_at']) && substr((string)$p['created_at'], 0, 10) === date('Y-m-d')) {
                $p['_request_type'] = 'New';
            }
        }
        unset($p);

        $pendingSub['total'] = count($pendingList);
        foreach ($pendingList as $p) {
            if (($p['_request_type'] ?? '') === 'New') $pendingSub['new']++;
            elseif (($p['_request_type'] ?? '') === 'Reschedule') $pendingSub['reschedule']++;
            else $pendingSub['for_confirmation']++;
        }

        // CONFIRMED
        $stmt = $pdo->query($apptJoinSelect . "
            WHERE a.status IN ('Confirmed', 'Approved')
            ORDER BY a.appointment_date ASC, a.start_time ASC
            LIMIT 200
        ");
        $confirmedList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $confirmedSub['total'] = count($confirmedList);
        $weekStart = (new DateTime('monday this week'))->format('Y-m-d');
        $weekEnd = (new DateTime('sunday this week'))->format('Y-m-d');
        $monthPrefix = date('Y-m');
        foreach ($confirmedList as $c) {
            $d = substr((string)($c['appointment_date'] ?? ''), 0, 10);
            if ($d === date('Y-m-d')) $confirmedSub['today']++;
            if ($d >= $weekStart && $d <= $weekEnd) $confirmedSub['week']++;
            if (substr($d, 0, 7) === $monthPrefix) $confirmedSub['month']++;
        }

        // COMPLETED
        $stmt = $pdo->query($apptJoinSelect . "
            WHERE a.status = 'Completed'
            ORDER BY a.appointment_date DESC, a.start_time DESC
            LIMIT 200
        ");
        $completedList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $completedSub['total'] = count($completedList);
        foreach ($completedList as $c) {
            $d = substr((string)($c['appointment_date'] ?? ''), 0, 10);
            if ($d === date('Y-m-d')) $completedSub['today']++;
            if ($d >= $weekStart && $d <= $weekEnd) $completedSub['week']++;
            if (substr($d, 0, 7) === $monthPrefix) $completedSub['month']++;
        }

        // QUEUE (today)
        try {
            $stmt = $pdo->query("
                SELECT
                    q.id, q.patient_id, q.queue_number, q.queue_date,
                    q.priority, q.status, q.created_at,
                    CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.last_name,'')) AS patient_name,
                    (
                        SELECT a2.service_type FROM appointments a2
                        WHERE a2.patient_id = q.patient_id
                        AND a2.appointment_date = CURDATE()
                        ORDER BY a2.start_time ASC LIMIT 1
                    ) AS service_type,
                    (
                        SELECT d2.dentist_name FROM appointments a2
                        LEFT JOIN dentists d2 ON d2.id = a2.dentist_id
                        WHERE a2.patient_id = q.patient_id
                        AND a2.appointment_date = CURDATE()
                        ORDER BY a2.start_time ASC LIMIT 1
                    ) AS dentist_name
                FROM queue q
                LEFT JOIN patients p ON p.id = q.patient_id
                WHERE q.queue_date = CURDATE()
                ORDER BY q.queue_number ASC, q.id ASC
            ");
            $queueList = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $qe) {
            $queueList = [];
        }

        foreach ($queueList as $q) {
            $s = strtolower(trim($q['status'] ?? ''));
            if ($s === 'completed') { $queueSub['completed_today']++; continue; }
            if ($s === 'cancelled' || $s === 'canceled') continue;
            $queueSub['total']++;
            if (strpos($s, 'consult') !== false) $queueSub['in_consultation']++;
            else $queueSub['waiting']++;
        }
    } catch (PDOException $detailError) {
        // Detail lists must never break the dashboard.
    }


    /*
    |--------------------------------------------------------------------------
    | 5. MACHINE LEARNING
    |--------------------------------------------------------------------------
    | Linear Regression Appointment Demand Forecast
    |--------------------------------------------------------------------------
    */

    $historyDays = 90;


    /*
    |--------------------------------------------------------------------------
    | 5A. GET HISTORICAL APPOINTMENTS
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT

            appointment_date,

            COUNT(*) AS appointment_count

        FROM appointments

        WHERE appointment_date >=
            DATE_SUB(CURDATE(), INTERVAL :historyDays DAY)

        AND appointment_date <= CURDATE()

        AND status NOT IN (
            'Cancelled',
            'Canceled'
        )

        GROUP BY appointment_date

        ORDER BY appointment_date ASC
    ");

    $stmt->bindValue(
        ':historyDays',
        $historyDays,
        PDO::PARAM_INT
    );

    $stmt->execute();

    $historicalRows =
        $stmt->fetchAll(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | 5B. CREATE DATE MAP & ACTIVE WINDOW
    |--------------------------------------------------------------------------
    */

    $historicalMap = [];
    $earliestActiveDate = null;

    foreach ($historicalRows as $row) {

        $date =
            $row['appointment_date'];

        $historicalMap[$date] =
            (int)$row['appointment_count'];

        if ($earliestActiveDate === null || $date < $earliestActiveDate) {
            $earliestActiveDate = $date;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | 5C. CREATE ACTIVE HISTORICAL DATASET & WEEKDAY SEASONALITY
    |--------------------------------------------------------------------------
    */

    $xValues = [];
    $yValues = [];
    $weekdaySums = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];
    $weekdayCounts = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];

    $startDate =
        new DateTime("-{$historyDays} days");

    $today =
        new DateTime("today");

    if ($earliestActiveDate !== null) {
        $earliestDt = new DateTime($earliestActiveDate);
        $minWindowDt = (clone $today)->modify('-13 days');
        $effectiveStart = $earliestDt < $minWindowDt ? $earliestDt : $minWindowDt;
        if ($effectiveStart > $startDate) {
            $startDate = $effectiveStart;
        }
    }

    $x = 1;

    while ($startDate <= $today) {

        $date =
            $startDate->format('Y-m-d');
        $dow =
            (int)$startDate->format('w');

        $count =
            $historicalMap[$date] ?? 0;

        if ($dow !== 0) {
            $xValues[] =
                $x;

            $yValues[] =
                $count;

            $x++;
        }

        $weekdaySums[$dow] += $count;
        $weekdayCounts[$dow]++;

        $historicalDays++;

        $historicalAppointments +=
            $count;

        $startDate->modify('+1 day');
    }


    /*
    |--------------------------------------------------------------------------
    | 5D. LINEAR REGRESSION
    |--------------------------------------------------------------------------
    */

    $numberOfDataPoints =
        count($xValues);


    if ($numberOfDataPoints >= 2) {


        /*
        | Calculate X mean
        */

        $xMean =
            array_sum($xValues) /
            $numberOfDataPoints;


        /*
        | Calculate Y mean
        */

        $yMean =
            array_sum($yValues) /
            $numberOfDataPoints;


        /*
        | Calculate slope
        */

        $numerator = 0;

        $denominator = 0;


        for (
            $i = 0;
            $i < $numberOfDataPoints;
            $i++
        ) {

            $xDifference =
                $xValues[$i] -
                $xMean;

            $yDifference =
                $yValues[$i] -
                $yMean;

            $numerator +=
                $xDifference *
                $yDifference;

            $denominator +=
                $xDifference *
                $xDifference;
        }


        if ($denominator != 0) {

            $slope =
                $numerator /
                $denominator;

        } else {

            $slope = 0;
        }


        /*
        | Calculate intercept
        */

        $intercept =
            $yMean -
            ($slope * $xMean);


        $modelStatus =
            'Linear Regression';


        /*
        |--------------------------------------------------------------------------
        | HISTORICAL AVERAGE (OPERATING DAYS)
        |--------------------------------------------------------------------------
        */

        $historicalAverage =
            $numberOfDataPoints > 0
                ? array_sum($yValues) /
                  $numberOfDataPoints
                : 0;


        /*
        |--------------------------------------------------------------------------
        | 5E. FORECAST NEXT 7 DAYS
        |--------------------------------------------------------------------------
        */

        $forecastData = [];
        $futureOperatingOffset = 1;


        for (
            $futureDay = 1;
            $futureDay <= 7;
            $futureDay++
        ) {

            /*
            | Forecast date
            */

            $forecastDate =
                new DateTime(
                    "+{$futureDay} days"
                );


            $dateString =
                $forecastDate->format(
                    'Y-m-d'
                );


            $dayName =
                $forecastDate->format(
                    'l'
                );

            $dow =
                (int)$forecastDate->format('w');

            if ($dow === 0) {
                // Clinic is closed on Sundays
                $regressionPrediction = 0;
            } else {
                $futureX =
                    $numberOfDataPoints +
                    $futureOperatingOffset;
                $futureOperatingOffset++;

                $rawRegression =
                    max(
                        0,
                        ($slope * $futureX) + $intercept
                    );

                $dowAvg =
                    $weekdayCounts[$dow] > 0
                        ? ($weekdaySums[$dow] / $weekdayCounts[$dow])
                        : $historicalAverage;

                $blended =
                    ($rawRegression * 0.5) +
                    ($dowAvg * 0.5);

                if ($historicalAppointments > 0 && $blended < 1) {
                    $blended = max($dowAvg, $historicalAverage, 1);
                }

                $regressionPrediction =
                    (int)round($blended);
            }


            /*
            |--------------------------------------------------------------------------
            | ACTUAL BOOKED APPOINTMENTS
            |--------------------------------------------------------------------------
            */

            $actualBooked =
                $upcomingBookedByDate[
                    $dateString
                ] ?? 0;


            /*
            | Combined expected demand
            */

            $totalExpectedDemand =
                $regressionPrediction +
                $actualBooked;


            /*
            | Save forecast (demand level refined below across the 7-day window)
            */

            $forecastData[] = [

                'date' =>
                    $dateString,

                'formatted_date' =>
                    $forecastDate->format(
                        'M d, Y'
                    ),

                'day_name' =>
                    $dayName,

                /*
                | ML prediction only
                */

                'ml_prediction' =>
                    (int)$regressionPrediction,

                /*
                | Already booked appointments
                */

                'actual_booked' =>
                    (int)$actualBooked,

                /*
                | Final displayed demand
                */

                'prediction' =>
                    (int)$totalExpectedDemand,

                'demand_level' =>
                    'Medium',

                'demand_class' =>
                    'demand-medium',

                'demand_icon' =>
                    'bi bi-graph-up-arrow'
            ];
        }

        $nonZeroPreds = array_values(array_filter(
            array_column($forecastData, 'prediction'),
            function ($v) { return $v > 0; }
        ));
        $operatingForecastAvg = !empty($nonZeroPreds)
            ? (array_sum($nonZeroPreds) / count($nonZeroPreds))
            : $historicalAverage;
        $demandBaseline = max($historicalAverage, $operatingForecastAvg);

        foreach ($forecastData as &$fRow) {
            $lvl = getDemandLevel($fRow['prediction'], $demandBaseline);
            $fRow['demand_level'] = $lvl;
            $fRow['demand_class'] = getDemandClass($lvl);
            $fRow['demand_icon'] = getDemandIcon($lvl);
        }
        unset($fRow);


        /*
        |--------------------------------------------------------------------------
        | 5F. FORECAST SUMMARY
        |--------------------------------------------------------------------------
        */

        if (!empty($forecastData)) {


            $forecastValues =
                array_column(
                    $forecastData,
                    'prediction'
                );


            $forecastAverage =
                array_sum(
                    $forecastValues
                ) /
                count($forecastValues);


            $highestForecast =
                max(
                    $forecastValues
                );


            foreach (
                $forecastData
                as $forecast
            ) {

                if (
                    $forecast['prediction']
                    ==
                    $highestForecast
                ) {

                    $highestDemandDate =
                        $forecast;

                    break;
                }
            }
        }
    }


} catch (PDOException $e) {

    $forecastError =
        $e->getMessage();
}


/*
|--------------------------------------------------------------------------
| FINAL FORECAST TOTAL
|--------------------------------------------------------------------------
*/

$forecastTotal =
    !empty($forecastData)
        ? array_sum(
            array_column(
                $forecastData,
                'prediction'
            )
        )
        : 0;


/*
|--------------------------------------------------------------------------
| FINAL DEMAND LEVEL
|--------------------------------------------------------------------------
*/

$overallHistoricalAverage =
    $historicalDays > 0
        ? $historicalAppointments /
          $historicalDays
        : 0;


$overallDemandLevel =
    getDemandLevel(
        $forecastAverage,
        $overallHistoricalAverage
    );


$overallDemandClass =
    getDemandClass(
        $overallDemandLevel
    );


/*
|--------------------------------------------------------------------------
| UPCOMING TOTAL
|--------------------------------------------------------------------------
*/

$totalUpcomingBooked =
    array_sum(
        $upcomingBookedByDate
    );


/*
|--------------------------------------------------------------------------
| CHART DATA (Admin Dashboard Analytics)
|--------------------------------------------------------------------------
| - Status distribution (doughnut)
| - Last 14 days trend (line)
| - Monthly volume last 6 months (bar)
| Forecast chart uses $forecastData directly.
|--------------------------------------------------------------------------
*/

$chartStatusLabels = ['Pending', 'Confirmed', 'Completed', 'Cancelled', 'No-Show'];
$chartStatusData = [0, 0, 0, 0, 0];

$chartTrendLabels = [];
$chartTrendData = [];

$chartMonthlyLabels = [];
$chartMonthlyData = [];

try {

    /*
    | 1. STATUS DISTRIBUTION
    */
    $stmt = $pdo->query("
        SELECT status, COUNT(*) AS total
        FROM appointments
        GROUP BY status
    ");
    $statusRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusMap = [
        'pending' => 0,
        'confirmed' => 0,
        'approved' => 0,
        'completed' => 0,
        'cancelled' => 0,
        'canceled' => 0,
        'no-show' => 0,
        'no show' => 0,
    ];

    foreach ($statusRows as $row) {
        $key = strtolower(trim($row['status'] ?? ''));
        if (array_key_exists($key, $statusMap)) {
            $statusMap[$key] = (int)$row['total'];
        }
    }

    $chartStatusData = [
        (int)($statusMap['pending'] ?? 0),
        (int)(($statusMap['confirmed'] ?? 0) + ($statusMap['approved'] ?? 0)),
        (int)($statusMap['completed'] ?? 0),
        (int)(($statusMap['cancelled'] ?? 0) + ($statusMap['canceled'] ?? 0)),
        (int)(($statusMap['no-show'] ?? 0) + ($statusMap['no show'] ?? 0)),
    ];

    /*
    | 2. LAST 14 DAYS TREND (including today)
    */
    $trendMap = [];
    $trendStart = new DateTime("-13 days");
    $trendToday = new DateTime("today");

    $tmp = clone $trendStart;
    while ($tmp <= $trendToday) {
        $d = $tmp->format('Y-m-d');
        $trendMap[$d] = 0;
        $tmp->modify('+1 day');
    }

    $stmt = $pdo->query("
        SELECT appointment_date, COUNT(*) AS total
        FROM appointments
        WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
        AND appointment_date <= CURDATE()
        AND status NOT IN ('Cancelled', 'Canceled')
        GROUP BY appointment_date
        ORDER BY appointment_date ASC
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $d = $row['appointment_date'];
        if (array_key_exists($d, $trendMap)) {
            $trendMap[$d] = (int)$row['total'];
        }
    }

    foreach ($trendMap as $dateStr => $total) {
        $dt = new DateTime($dateStr);
        $chartTrendLabels[] = $dt->format('M d');
        $chartTrendData[] = (int)$total;
    }

    /*
    | 3. MONTHLY VOLUME (Last 6 months)
    */
    $monthMap = [];
    $monthLabels = [];
    for ($m = 5; $m >= 0; $m--) {
        $dt = new DateTime("-{$m} months");
        $key = $dt->format('Y-m');
        $monthMap[$key] = 0;
        $monthLabels[$key] = $dt->format('M Y');
    }

    $stmt = $pdo->query("
        SELECT DATE_FORMAT(appointment_date, '%Y-%m') AS ym, COUNT(*) AS total
        FROM appointments
        WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY ym
        ORDER BY ym ASC
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ym = $row['ym'];
        if (array_key_exists($ym, $monthMap)) {
            $monthMap[$ym] = (int)$row['total'];
        }
    }

    foreach ($monthMap as $ym => $total) {
        $chartMonthlyLabels[] = $monthLabels[$ym];
        $chartMonthlyData[] = (int)$total;
    }

} catch (Throwable $chartError) {
    // Charts must never break the dashboard. Defaults above are used.
}

/*
| Forecast chart arrays (from Linear Regression $forecastData)
*/
$chartForecastLabels = [];
$chartForecastML = [];
$chartForecastBooked = [];
$chartForecastTotalArr = [];

foreach ($forecastData as $f) {
    $dt = DateTime::createFromFormat('Y-m-d', $f['date'] ?? '');
    $chartForecastLabels[] = $dt ? $dt->format('M d') : ($f['day_name'] ?? '');
    $chartForecastML[] = (int)($f['ml_prediction'] ?? 0);
    $chartForecastBooked[] = (int)($f['actual_booked'] ?? 0);
    $chartForecastTotalArr[] = (int)($f['prediction'] ?? 0);
}


?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0">

<title>
    Dashboard | Abalos Dental Clinic
</title>


<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet">


<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">


<link
    href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=Inter:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap"
    rel="stylesheet">


<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>


<style>

/* =========================================================
   DENTAL CLINIC DESIGN TOKENS â€” "Clinical Calm"
   Teal = hygiene & trust | Navy = clinical text
   Rounded geometry = friendly, low-anxiety UI
========================================================= */

:root {
    --clinic-teal: #059669;
    --clinic-teal-dark: #022c22;
    --clinic-teal-deep: #022c22;
    --clinic-navy: #143f51;
    --clinic-border: #808080;
    --clinic-card-radius: 18px;
    --clinic-shadow: 0 4px 18px rgba(22,83,101,.06);
    --clinic-shadow-hover: 0 14px 32px rgba(5,150,105,.13);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html {
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    text-rendering: optimizeLegibility;
}

body {
    font-family: "Inter", "DM Sans", system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    background: linear-gradient(135deg, #022c22, #064e3b, #065f46, #064e3b, #022c22);
    background-attachment: fixed;
    color: #173b4d;
    font-weight: 400;
    letter-spacing: -0.01em;
    line-height: 1.5;
}

a {
    text-decoration: none;
}

button {
    font-family: inherit;
}

/* Dental clinic system - clean label typography */
.nav-title,
.forecast-label,
.forecast-meta-box small,
.model-info-title,
.live-status,
.welcome-label,
.brand-sub {
    font-family: "Inter", "DM Sans", system-ui, sans-serif;
}


/* =========================================================
   LAYOUT
========================================================= */

.dashboard-wrapper {
    min-height: 100vh;
    display: flex;
}


/* =========================================================
   SIDEBAR
========================================================= */

.sidebar {
    width: 280px;
    min-height: 100vh;

    background: linear-gradient(180deg, #022c22 0%, #059669 100%);

    border-right: 1px solid #808080;

    padding: 25px 17px;

    position: fixed;

    left: 0;
    top: 0;
    bottom: 0;

    z-index: 1000;

    overflow-y: auto;
}

.sidebar-brand {
    display: flex;

    align-items: center;

    gap: 11px;

    padding: 5px 10px 28px;

    border-bottom:
        1px solid #808080;
}

.brand-logo {
    width: 44px;
    height: 44px;

    border-radius: 12px;

    background: #059669;

    border: 1px solid rgba(201,162,39,.55);

    overflow: hidden;

    display: flex;

    align-items: center;
    justify-content: center;

    box-shadow:
        0 7px 18px
        rgba(5,150,105,.18);
}

.brand-logo img {
    width: 100%;
    height: 100%;

    object-fit: cover;

    display: block;
}

.brand-name {
    color: #ffffff;

    font-family:
        "Fraunces", "Plus Jakarta Sans", "Inter",
        system-ui, sans-serif;

    font-size: 16px;

    font-weight: 800;
    letter-spacing: -0.02em;

    line-height: 1.2;
}

.brand-sub {
    color: #9CC4B6;

    font-size: 10px;

    letter-spacing: .8px;

    margin-top: 4px;
}


/* =========================================================
   NAVIGATION
========================================================= */

.sidebar-nav {
    margin-top: 27px;
}

.nav-title {
    color: #8FAE9F;

    font-size: 11px;

    font-weight: 700;

    letter-spacing: 1.2px;

    padding: 0 12px;

    margin-bottom: 10px;
}

.sidebar-link {
    display: flex;

    align-items: center;

    gap: 12px;

    color: #C9D9D1;

    font-family: "Inter", "DM Sans", system-ui, sans-serif;
    font-size: 14px;

    font-weight: 600;

    padding: 13px 14px;

    border-radius: 11px;

    margin-bottom: 5px;

    transition: .2s;

    width: 100%;
    letter-spacing: -0.01em;
}

.sidebar-link i {
    width: 20px;

    text-align: center;

    font-size: 17px;

    flex-shrink: 0;
}

.sidebar-link:hover {
    background: rgba(255,255,255,.10);

    color: #ffffff;
}

.sidebar-link.active {
    background: linear-gradient(135deg, #C9A227, #A8861B);

    color: #022c22;

    box-shadow:
        0 8px 20px
        rgba(201,162,39,.35);
}

.sidebar-link.active i {
    color: #022c22;
}


/* =========================================================
   SIDEBAR BOTTOM
========================================================= */

.sidebar-bottom {
    margin-top: 45px;

    border-top:
        1px solid #808080;

    padding-top: 18px;
}

/* Tagline — gaya ng dentist sidebar */
.sidebar-tagline {
    margin-top: 18px;

    padding: 14px 12px 4px;

    border-top: 1px solid rgba(255,255,255,.15);

    text-align: center;
}

.sidebar-tagline-tooth {
    font-size: 26px;

    line-height: 1;

    margin-bottom: 6px;
}

.sidebar-tagline-text {
    color: #C9A227;

    font-size: 11px;

    font-weight: 700;

    letter-spacing: .4px;

    line-height: 1.5;
}


/* =========================================================
   MAIN CONTENT
========================================================= */

.main-content {
    margin-left: 280px;

    width:
        calc(100% - 280px);

    min-height: 100vh;
}


/* =========================================================
   TOPBAR
========================================================= */

.topbar {
    height: 78px;

    background: #ffffff;

    border-bottom:
        1px solid #808080;

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding: 0 38px;
}

.page-title h1 {
    color: #153f50;

    font-family:
        "Fraunces", "Plus Jakarta Sans", "Inter",
        system-ui, sans-serif;

    font-size: 24px;

    font-weight: 800;
    letter-spacing: -0.025em;

    margin: 0;
}

.page-title p {
    color: #8499a1;

    font-size: 13px;

    margin: 5px 0 0;
}

.admin-area {
    display: flex;

    align-items: center;

    gap: 12px;
}

.admin-info {
    text-align: right;
}

.admin-info strong {
    display: block;

    color: #244f60;

    font-size: 13px;

    font-weight: 700;
}

.admin-info span {
    color: #8ca0a8;

    font-size: 11px;
}

.admin-avatar {
    width: 38px;
    height: 38px;

    border-radius: 11px;

    background: #d1fae5;

    color: #059669;

    display: flex;

    align-items: center;
    justify-content: center;

    font-size: 17px;
}


/* =========================================================
   CONTENT
========================================================= */

.content {
    padding: 32px 38px 45px;
}


/* =========================================================
   WELCOME
========================================================= */

.welcome-box {
    background:
        linear-gradient(
            120deg,
            #10b981 0%,
            #059669 48%,
            #022c22 100%
        );

    border-radius: 20px;

    padding: 30px 32px;

    color: white;

    position: relative;

    overflow: hidden;

    margin-bottom: 25px;

    box-shadow:
        0 18px 40px
        rgba(5,150,105,.22);
}

.welcome-box::after {
    content: "";

    position: absolute;

    width: 210px;
    height: 210px;

    border-radius: 50%;

    background:
        rgba(255,255,255,.08);

    right: -65px;
    top: -95px;
}

.welcome-box::before {
    content: "";

    position: absolute;

    width: 120px;
    height: 120px;

    border-radius: 50%;

    background:
        rgba(255,255,255,.06);

    right: 120px;
    bottom: -80px;
}

.welcome-content {
    position: relative;

    z-index: 2;
}

.welcome-label {
    font-size: 11px;

    font-weight: 700;

    letter-spacing: 1px;

    opacity: .8;

    margin-bottom: 8px;
}

.welcome-box h2 {
    font-family:
        "Fraunces", "Plus Jakarta Sans", "Inter",
        system-ui, sans-serif;

    font-size: 25px;

    font-weight: 800;
    letter-spacing: -0.02em;

    margin-bottom: 7px;
}

.welcome-box p {
    color: #d1fae5;

    font-size: 13.5px;

    margin: 0;
}


/* =========================================================
   STATS
========================================================= */

.stats-grid {
    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 16px;

    margin-bottom: 25px;
}

.stat-card {
    position: relative;
    overflow: hidden;

    background:
        linear-gradient(135deg,#047857 0%,#059669 55%,#10B981 100%);

    border:
        1px solid rgba(255,255,255,.28);

    border-radius: 14px;

    padding: 12px 14px;

    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 3px;

    box-shadow:
        0 16px 34px
        rgba(5,150,105,.35);

    color: #ffffff;

    transition: .25s ease;
}

.stat-card::before,
.stat-card::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
}

.stat-card::before {
    width: 130px;
    height: 130px;
    top: -60px;
    right: -45px;
    background: radial-gradient(circle,rgba(255,255,255,.22),rgba(255,255,255,0) 70%);
}

.stat-card::after {
    width: 100px;
    height: 100px;
    bottom: -48px;
    left: -32px;
    background: radial-gradient(circle,rgba(255,255,255,.16),rgba(255,255,255,0) 70%);
}

.stat-card > * {
    position: relative;
    z-index: 1;
}

.stat-card:hover {
    transform:
        translateY(-4px);

    box-shadow:
        0 22px 42px
        rgba(5,150,105,.45);

    border-color: rgba(255,255,255,.62);
}

.stat-featured-head {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
}

.stat-featured-icon {
    width: 34px;
    height: 34px;

    border-radius: 11px;

    display: inline-flex;

    align-items: center;
    justify-content: center;

    font-size: 16px;

    flex-shrink: 0;

    background: rgba(255,255,255,.18);
    border: 1px solid rgba(255,255,255,.32);
    color: #ffffff;
    box-shadow: 0 8px 18px rgba(0,0,0,.14);
}

.stat-featured-head h3 {
    margin: 0;
    flex: 1;
    min-width: 0;

    color: rgba(255,255,255,.92);

    font-size: 13px;
    font-weight: 700;
}

.stat-number {
    color: #ffffff;

    font-family:
        "Plus Jakarta Sans", "Inter",
        system-ui, sans-serif;

    font-size: 27px;

    font-weight: 800;
    letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
    text-shadow: 0 6px 18px rgba(0,0,0,.22);

    line-height: 1;
    margin-top: auto;
    padding-top: 4px;
}

.stat-number.is-text {
    font-size: 15px;
    letter-spacing: 0;
}

.stat-featured-sub {
    display: flex;
    align-items: center;
    gap: 8px;

    font-size: 11.5px;
    font-weight: 600;
    color: rgba(255,255,255,.86);
    margin-top: 3px;
}

.stat-featured-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #A7F3D0;
    box-shadow: 0 0 0 4px rgba(167,243,208,.25);
    animation: statDotPulse 1.9s ease-in-out infinite;
    flex-shrink: 0;
}

@keyframes statDotPulse {
    0%,100% { opacity: 1; transform: scale(1); }
    50% { opacity: .5; transform: scale(.8); }
}


/* =========================================================
   DASHBOARD CARDS
========================================================= */

.stat-clickable{cursor:pointer;position:relative;border:1px solid rgba(255,255,255,.28) !important;user-select:none;-webkit-tap-highlight-color:transparent}
.stat-clickable:hover{border-color:rgba(255,255,255,.62) !important}
.stat-clickable:focus-visible{outline:2px solid #ffffff;outline-offset:2px}
.stat-go{position:absolute;right:12px;bottom:12px;width:22px;height:22px;border-radius:50%;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.35);color:#ffffff;display:inline-flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;z-index:2}
.stat-clickable.active-card{border-color:#ffffff !important;box-shadow:0 22px 42px rgba(5,150,105,.46);transform:translateY(-3px)}
#cardDetailWrap{margin-bottom:25px}
#detailNav{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:14px}
/* Wrapper ng "Back to Dashboard" — nasa labas ng card box, sa itaas nito.
   Kasama ang hide/show sa panel kasi nasa labas na ang button (hindi na
   saklaw ng .card-detail ang display toggle ng JS). */
.detail-back-row{display:none;margin:0 0 14px}
.detail-back-row.is-open{display:block}
.detail-back-btn{display:inline-flex;align-items:center;gap:7px;border:1px solid #B9E4CF;background:#EAF7F0;color:#059669;font-size:12.5px;font-weight:700;padding:8px 15px;border-radius:999px;cursor:pointer;transition:.2s;font-family:inherit}
.detail-back-btn:hover{background:#059669;border-color:#059669;color:#fff}
.detail-nav-card{display:flex;align-items:center;gap:9px;background:#F2F9F4;border:1px solid #e3eeea;border-radius:12px;padding:11px 12px;cursor:pointer;user-select:none;transition:.15s;-webkit-tap-highlight-color:transparent}
.detail-nav-card:hover{border-color:#059669;transform:translateY(-2px);box-shadow:0 8px 20px rgba(5,150,105,.12)}
.detail-nav-card.active-nav{border-color:#059669;border-width:2px;background:#EAF7F0;box-shadow:0 8px 20px rgba(5,150,105,.14)}
.detail-nav-card:focus-visible{outline:2px solid #059669;outline-offset:2px}
.detail-nav-card .detail-nav-icon{width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.detail-nav-card small{display:block;color:#8ca0a8;font-size:10px;font-weight:600;line-height:1.2}
.detail-nav-card strong{display:block;color:#173f50;font-family:"Fraunces","Plus Jakarta Sans","Inter",system-ui,sans-serif;font-size:17px;font-weight:800;line-height:1.2}
@media (max-width:991px){#detailNav{grid-template-columns:repeat(3,1fr)}}
@media (max-width:576px){#detailNav{grid-template-columns:repeat(2,1fr)}}
.card-detail{margin-bottom:18px;animation:cardDetailIn .25s ease}
@keyframes cardDetailIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.card-detail .card-top{align-items:flex-start;gap:12px}
/* Mapaginawang kulay: #8ca0a0 ay halos hindi makikita sa tinted card-top. */
.detail-crumb{color:#57727d;font-size:11.5px;font-weight:600;margin-bottom:6px}
.card-detail .card-top h3{display:flex;align-items:center;gap:10px;font-size:17px}
.detail-title-icon{width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.icon-today{background:#dbeafe;color:#2563eb}
.icon-amber{background:#fef3c7;color:#b45309}
.icon-teal{background:#ccfbf1;color:#0f766e}
.icon-pink{background:#fce7f3;color:#db2777}
.icon-purple{background:#ede9fe;color:#6d28d9}
.detail-sub{color:#57727d;font-size:12.5px;margin:4px 0 0}
.detail-date-pill{display:inline-flex;align-items:center;gap:6px;background:#F2F9F4;border:1px solid #808080;border-radius:9px;padding:7px 12px;font-size:12px;font-weight:600;color:#315868;white-space:nowrap}
.detail-close{width:30px;height:30px;border-radius:9px;border:1px solid #808080;background:#fff;color:#607a84;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;font-size:13px}
.detail-close:hover{background:#EAF7F0;color:#059669;border-color:#059669}
.detail-mini-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:14px}
.detail-mini{display:flex;align-items:center;gap:10px;background:#F2F9F4;border:1px solid #e3eeea;border-radius:12px;padding:13px 14px}
.detail-mini-clickable{cursor:pointer;user-select:none;-webkit-tap-highlight-color:transparent;transition:.15s}
.detail-mini-clickable:hover{border-color:#059669;transform:translateY(-2px);box-shadow:0 8px 20px rgba(5,150,105,.12)}
.detail-mini-clickable.active-mini{border-color:#059669;border-width:2px;background:#EAF7F0}
.detail-mini-clickable:focus-visible{outline:2px solid #059669;outline-offset:2px}
.detail-mini small{display:block;color:#8ca0a8;font-size:11px;font-weight:600}
.detail-mini strong{display:block;color:#173f50;font-family:"Fraunces","Plus Jakarta Sans","Inter",system-ui,sans-serif;font-size:21px;font-weight:800;line-height:1.2}
.detail-mini-note{display:block;color:#8ca0a8;font-size:11px}
.detail-mini-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.detail-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.detail-tab{border:1px solid #dce7e2;background:#fff;color:#5b7680;font-size:12px;font-weight:700;padding:7px 14px;border-radius:20px;cursor:pointer;transition:.15s}
.detail-tab:hover{border-color:#059669;color:#059669}
.detail-tab.active{background:#022c22;border-color:#022c22;color:#fff}
.detail-chart-title{color:#244e5e;font-family:"Fraunces","Plus Jakarta Sans","Inter",system-ui,sans-serif;font-size:14px;font-weight:800;margin:0 0 12px}
.detail-forecast-chart{height:260px;margin-bottom:14px}
.detail-forecast-chart canvas{width:100% !important;height:100% !important}
.detail-forecast-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
@media (max-width:767px){.detail-mini-grid,.detail-forecast-stats{grid-template-columns:1fr 1fr}.card-detail .card-top{flex-direction:column}}
.dashboard-card {
    background: #ffffff;

    border:
        1px solid #808080;

    border-radius: 15px;

    overflow: hidden;

    height: 100%;
}

.card-top {
    padding: 19px 21px;

    border-bottom:
        1px solid #808080;

    display: flex;

    align-items: center;

    justify-content: space-between;
}

/*
 * Header ng mga DETAIL view (today/pending/confirmed/completed/queue/forecast).
 * Wala ito dati — minana lang ang #ffffff ng .dashboard-card, kaya white-on-white
 * ang buong card at nalilito kung saan nagsisimula ang content. Ang maliit na
 * emerald tint ang pinili para hindi matingin at magkatugma sa .detail-mini.
 */
.card-detail .card-top {
    background: linear-gradient(135deg, #EAF7F0 0%, #F7FDFB 100%);
    border-bottom: 1px solid #C9E9DA;
}

.card-top h3 {
    color: #244e5e;

    font-family:
        "Fraunces", "Plus Jakarta Sans", "Inter",
        system-ui, sans-serif;

    font-size: 15.5px;

    font-weight: 800;
    letter-spacing: -0.015em;

    margin: 0;
}

.card-top a {
    color: #059669;

    font-family: "Inter", "DM Sans", system-ui, sans-serif;
    font-size: 13px;

    font-weight: 700;
    letter-spacing: -0.01em;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 20px;
    background: #EAF7F0;
    border: 1px solid #808080;
    transition: .2s;
}

.card-top a:hover {
    background: #059669;
    color: #ffffff;
    border-color: #059669;
}

.card-body-custom {
    padding: 21px;
}


/* =========================================================
   STATUS
========================================================= */

.status-grid {
    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 12px;
}

.status-box {
    text-align: center;

    background: #F2F9F4;

    border-radius: 11px;

    padding: 17px 10px;
}

.status-box strong {
    display: block;

    color: #173f50;

    font-family:
        "Fraunces", "Plus Jakarta Sans", "Inter",
        system-ui, sans-serif;

    font-size: 22px;

    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

.status-box span {
    display: block;

    color: #879aa2;

    font-size: 11.5px;

    margin-top: 4px;
}

.status-dot {
    width: 7px;
    height: 7px;

    border-radius: 50%;

    display: inline-block;

    margin-right: 4px;
}

.dot-orange {
    background: #059669;
}

.dot-green {
    background: #022c22;
}

.dot-red {
    background: #10b981;
}


/* =========================================================
   FORECAST
========================================================= */

.forecast-label {
    color: #059669;

    font-size: 12px;

    font-weight: 700;
}

.forecast-summary {
    display: flex;

    flex-direction: row;

    flex-wrap: nowrap;

    align-items: center;

    gap: 10px;

    background: #F2F9F4;

    border:
        1px solid #808080;

    border-radius: 13px;

    padding: 10px 12px;

    margin-bottom: 10px;
}

.forecast-number {
    min-width: 60px;

    height: 42px;

    border-radius: 10px;

    display: flex;

    align-items: center;
    justify-content: center;

    background: #d1fae5;

    color: #059669;

    font-size: 14px;

    font-weight: 800;

    flex-shrink: 0;
}

.forecast-summary strong {
    display: block;

    color: #244e5e;

    font-size: 13px;

    margin-bottom: 3px;
}

.forecast-summary span {
    display: block;

    color: #91a4ab;

    font-size: 11.5px;
}

.forecast-meta {
    display: flex;

    flex-direction: row;

    flex-wrap: nowrap;

    justify-content: space-between;

    gap: 8px;

    margin-bottom: 10px;
}

.forecast-meta-box {
    flex: 1;

    background: #F2F9F4;

    border-radius: 10px;

    padding: 8px 10px;
}

.forecast-meta-box small {
    display: block;

    color: #8da0a7;

    font-size: 10px;

    margin-bottom: 4px;
}

.forecast-meta-box strong {
    color: #254f60;

    font-size: 13.5px;
}


/* =========================================================
   DEMAND DAYS
========================================================= */

.demand-days {
    display: flex;

    flex-direction: column;

    gap: 9px;
}

.demand-day {
    display: flex;

    flex-direction: row;

    flex-wrap: nowrap;

    align-items: center;

    justify-content: space-between;

    gap: 10px;

    background: #F2F9F4;

    border:
        1px solid #808080;

    border-radius: 11px;

    padding: 11px 13px;
}

.demand-date {
    display: flex;

    align-items: center;

    gap: 9px;
}

.demand-date-icon {
    width: 30px;
    height: 30px;

    border-radius: 9px;

    background: #d1fae5;

    color: #059669;

    display: flex;

    align-items: center;
    justify-content: center;
}

.demand-date strong {
    display: block;

    color: #315868;

    font-size: 12.5px;
}

.demand-date span {
    display: block;

    color: #94a6ad;

    font-size: 11px;

    margin-top: 2px;
}

.demand-right {
    text-align: right;
}

.demand-count {
    display: block;

    color: #244e5e;

    font-size: 13px;

    font-weight: 800;
}

.demand-booked {
    display: block;

    color: #059669;

    font-size: 11px;

    margin-top: 2px;
}

.demand-badge {
    display: inline-flex;

    align-items: center;

    gap: 4px;

    border-radius: 20px;

    padding: 4px 7px;

    margin-top: 3px;

    font-size: 10px;

    font-weight: 800;
}

.demand-high {
    background: #fff0ed;

    color: #db5b4f;
}

.demand-medium {
    background: #fff5df;

    color: #d38a10;
}

.demand-low {
    background: #eaf8ef;

    color: #198754;
}


/* =========================================================
   MODEL INFO
========================================================= */

.model-info {
    margin-top: 15px;

    background: #EFF7F0;

    border:
        1px solid #808080;

    border-radius: 11px;

    padding: 12px 14px;
}

.model-info-title {
    display: flex;

    align-items: center;

    gap: 6px;

    color: #0b849f;

    font-size: 11px;

    font-weight: 800;

    margin-bottom: 7px;
}

.model-info-row {
    display: flex;

    justify-content: space-between;

    color: #708b95;

    font-size: 11px;

    margin-top: 4px;
}

.model-info-row strong {
    color: #315868;
}


/* =========================================================
   PLANNING BOX
========================================================= */

.planning-box {
    margin-top: 15px;

    border-radius: 12px;

    padding: 14px;

    display: flex;

    gap: 10px;

    background: #fff8e9;

    border:
        1px solid #f7e4b8;
}

.planning-box i {
    color: #e59b12;

    font-size: 17px;
}

.planning-box strong {
    display: block;

    color: #765516;

    font-size: 12.5px;

    margin-bottom: 3px;
}

.planning-box span {
    color: #917b53;

    font-size: 11.5px;

    line-height: 1.5;
}


/* =========================================================
   RECENT APPOINTMENTS
========================================================= */

.recent-card {
    margin-top: 20px;
}

.table-wrapper {
    overflow-x: auto;
}

.appointment-table {
    width: 100%;

    border-collapse: collapse;
}

.appointment-table th {
    color: #8b9ea5;

    font-size: 11.5px;

    font-weight: 700;

    text-transform: uppercase;

    letter-spacing: .4px;

    padding: 13px 15px;

    border-bottom:
        1px solid #808080;

    white-space: nowrap;
}

.appointment-table td {
    color: #4d6873;

    font-size: 13px;

    padding: 14px 15px;

    border-bottom:
        1px solid #808080;

    white-space: nowrap;
}

.appointment-table tr:last-child td {
    border-bottom: none;
}

.patient-cell {
    display: flex;

    align-items: center;

    gap: 9px;
}

.patient-avatar {
    width: 30px;
    height: 30px;

    border-radius: 9px;

    background: #d1fae5;

    color: #059669;

    display: flex;

    align-items: center;
    justify-content: center;

    flex-shrink: 0;
}

.patient-cell strong {
    display: block;

    color: #315868;

    font-size: 13px;
}

.patient-cell span {
    color: #9aabb1;

    font-size: 11px;
}


/* =========================================================
   STATUS BADGES
========================================================= */

.status-badge {
    display: inline-block;

    border-radius: 20px;

    padding: 5px 9px;

    font-size: 11px;

    font-weight: 700;
}

.badge-pending {
    background: #fff3d9;
    color: #c98609;
}

.badge-confirmed {
    background: #e3f8ec;
    color: #17844d;
}

.badge-completed {
    background: #e6f4fb;
    color: #157ca0;
}

.badge-cancelled {
    background: #ffe8e8;
    color: #d65b5b;
}

.badge-noshow {
    background: #f3e9e9;
    color: #a54848;
}

.badge-default {
    background: #edf4f6;
    color: #607a84;
}


/* =========================================================
   EMPTY STATE
========================================================= */

.empty-state {
    text-align: center;

    padding: 35px 20px;
}

.empty-icon {
    width: 50px;
    height: 50px;

    border-radius: 14px;

    background: #EAF7F0;

    color: #059669;

    display: flex;

    align-items: center;
    justify-content: center;

    margin: 0 auto 12px;

    font-size: 21px;
}

.empty-state strong {
    display: block;

    color: #426471;

    font-size: 13.5px;

    margin-bottom: 4px;
}

.empty-state span {
    color: #94a7ae;

    font-size: 11.5px;
}


/* =========================================================
   LIVE UPDATE INDICATOR
========================================================= */

.live-status {
    display: inline-flex;

    align-items: center;

    gap: 5px;

    color: #7e969f;

    font-size: 11px;
}

.live-dot {
    width: 6px;
    height: 6px;

    border-radius: 50%;

    background: #059669;

    animation:
        livePulse 1.5s infinite;
}

@keyframes livePulse {

    0% {
        opacity: 1;
    }

    50% {
        opacity: .35;
    }

    100% {
        opacity: 1;
    }
}


/* =========================================================
   FOOTER
========================================================= */

/* =========================================================
   DENTAL CLINIC BUTTONS - enlarged, clinic typography
   Applies to any Bootstrap .btn inside dashboard
========================================================= */

.content .btn,
.dashboard-card .btn {
    font-family: "Inter", "DM Sans", system-ui, sans-serif;
    font-size: 13.5px;
    font-weight: 600;
    letter-spacing: -0.01em;
    padding: 9px 18px;
    border-radius: 10px;
    line-height: 1.4;
}

.content .btn-sm,
.dashboard-card .btn-sm {
    font-size: 12.5px;
    padding: 7px 14px;
    border-radius: 9px;
}

.content .btn-primary,
.dashboard-card .btn-primary {
    background: #059669;
    border-color: #059669;
}

.content .btn-primary:hover,
.dashboard-card .btn-primary:hover {
    background: #022c22;
    border-color: #022c22;
}

.status-badge {
    font-family: "Inter", system-ui, sans-serif;
    padding: 6px 12px;
    font-size: 12px;
}

.demand-badge {
    font-family: "Inter", system-ui, sans-serif;
    padding: 5px 10px;
    font-size: 11px;
}


.dashboard-footer {
    color: #8FB5A3;

    text-align: center;

    font-size: 11px;

    padding: 25px 0 5px;
}


/* =========================================================
   CHARTS / ANALYTICS â€” Dental Clinic Premium Style
========================================================= */

.charts-section {
    margin-bottom: 25px;
}

.charts-section-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    padding: 0 2px;
}

.charts-section-title h3 {
    color: #ffffff;
    font-family: "Fraunces", "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
    font-size: 17px;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-icon {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: linear-gradient(135deg, #059669, #022c22);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    box-shadow: 0 6px 14px rgba(5,150,105,.25);
}

.section-live {
    color: #9CC4B6;
    font-size: 12px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #fff;
    border: 1px solid #808080;
    padding: 6px 12px;
    border-radius: 20px;
}

.charts-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    align-items: stretch;
}

.chart-card {
    background: #ffffff;
    border: 1px solid #808080;
    border-radius: 18px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    height: 100%;
    box-sizing: border-box;
    box-shadow: 0 4px 18px rgba(22,83,101,.05);
    transition: transform .2s ease, box-shadow .2s ease;
}

.chart-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 14px 32px rgba(5,150,105,.12);
}

.chart-wide {
    grid-column: 1 / -1;
}

.chart-card-top {
    padding: 18px 22px;
    border-bottom: 1px solid #808080;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: linear-gradient(180deg, #FDFEFB, #ffffff);
}

.chart-head {
    display: flex;
    align-items: center;
    gap: 12px;
}

.chart-icon {
    width: 42px;
    height: 42px;
    border-radius: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 19px;
    flex-shrink: 0;
}

.icon-blue {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #059669;
}

.icon-teal {
    background: linear-gradient(135deg, #e0f5f0, #c6ece3);
    color: #0e8a6d;
}

.icon-amber {
    background: linear-gradient(135deg, #fff4dc, #ffe7b3);
    color: #c07f0a;
}

.icon-green {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #059669;
}

.chart-card-top h4 {
    color: #1c4454;
    font-family: "Fraunces", "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
    font-size: 14.5px;
    font-weight: 800;
    margin: 0;
    letter-spacing: -0.01em;
}

.chart-card-top small {
    color: #8ca0a8;
    font-size: 11.5px;
    font-weight: 500;
}

.chart-pill {
    font-size: 11px;
    font-weight: 800;
    padding: 6px 13px;
    border-radius: 20px;
    background: #EAF7F0;
    color: #059669;
    border: 1px solid #a7f3d0;
    white-space: nowrap;
    margin-left: auto;
}

.pill-blue {
    background: linear-gradient(135deg, #059669, #022c22);
    color: #fff;
    border: none;
    box-shadow: 0 4px 12px rgba(5,150,105,.3);
}

.pill-amber {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}

.pill-green {
    background: #022c22;
    color: #ffffff;
    border: 1px solid #022c22;
}

.chart-card-body {
    padding: 20px 22px 22px;
    position: relative;
    height: 300px;
}

.chart-card-body canvas {
    width: 100% !important;
    height: 100% !important;
}

/* Hover tooltip sa action buttons (CSS-only, data-tip attribute) */
[data-tip] { position: relative; }
[data-tip]:hover::after {
    content: attr(data-tip);
    position: absolute;
    top: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    background: #0A3B34;
    color: #ffffff;
    font-size: 11px;
    font-weight: 700;
    font-family: "Inter", system-ui, sans-serif;
    padding: 6px 12px;
    border-radius: 8px;
    white-space: nowrap;
    pointer-events: none;
    z-index: 99999;
    box-shadow: 0 8px 20px rgba(0, 0, 0, .35);
}

.modal, .modal-dialog, .modal-content, .status-action-box, .appt-modal-box, .d-modal-box {
    overflow: visible !important;
}
.modal-header, .status-action-head, .appt-modal-head {
    border-top-left-radius: 16px !important;
    border-top-right-radius: 16px !important;
}
.modal-footer, .status-action-foot, .appt-modal-foot {
    border-bottom-left-radius: 16px !important;
    border-bottom-right-radius: 16px !important;
}
.modal-body [data-tip]:hover::after,
.modal-footer [data-tip]:hover::after,
.status-action-body [data-tip]:hover::after,
.status-action-grid [data-tip]:hover::after,
.appt-modal-body [data-tip]:hover::after,
.appt-modal-foot [data-tip]:hover::after,
.d-modal-body [data-tip]:hover::after,
.d-modal-foot [data-tip]:hover::after,
.status-action-resched-form [data-tip]:hover::after,
.modal-content [data-tip]:not(.btn-close):not(.appt-modal-x):hover::after {
    top: auto !important;
    bottom: calc(100% + 8px) !important;
    left: 50% !important;
    transform: translateX(-50%) !important;
    z-index: 99999 !important;
}
.modal-header [data-tip]:hover::after,
.status-action-head [data-tip]:hover::after,
.appt-modal-head [data-tip]:hover::after,
.modal-header .btn-close[data-tip]:hover::after {
    top: calc(100% + 8px) !important;
    bottom: auto !important;
    left: auto !important;
    right: 0 !important;
    transform: none !important;
    z-index: 99999 !important;
}

.chart-doughnut-wrap {
    height: 300px;
}

.chart-center {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -58%);
    text-align: center;
    pointer-events: none;
}

.chart-center strong {
    display: block;
    font-family: "Plus Jakarta Sans", sans-serif;
    font-size: 30px;
    font-weight: 800;
    color: #143f51;
    line-height: 1;
}

.chart-center span {
    font-size: 11px;
    color: #8ca0a8;
    font-weight: 600;
    letter-spacing: .8px;
    text-transform: uppercase;
}

.chart-legend-note {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    padding: 0 22px 18px;
}

.chart-legend-note span {
    font-size: 11.5px;
    font-weight: 600;
    color: #5b7680;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.chart-dot {
    width: 11px;
    height: 11px;
    border-radius: 4px;
    display: inline-block;
}


/* =========================================================
   ANALYTICS ACCORDION â€” Clinic Analytics boxes
========================================================= */

.chart-card-top.chart-toggle {
    cursor: pointer;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
    transition: background .2s ease;
}

.chart-card-top.chart-toggle:hover {
    background: linear-gradient(180deg, #F3FBF7, #ffffff);
}

.chart-card-top.chart-toggle:focus-visible {
    outline: 2px solid #059669;
    outline-offset: -2px;
    border-radius: 18px 18px 0 0;
}

.chart-chevron {
    color: #8ca0a8;
    font-size: 15px;
    flex-shrink: 0;
    transition: transform .3s ease, color .2s ease;
}

.chart-card.is-open .chart-chevron {
    transform: rotate(180deg);
    color: #059669;
}

.chart-card.is-open {
    border-color: #059669;
    box-shadow: 0 14px 32px rgba(5, 150, 105, .12);
}

.chart-panel {
    height: 0;
    overflow: hidden;
    transition: height .3s cubic-bezier(.4, 0, .2, 1);
}

.chart-card .chart-card-top {
    border-bottom-color: transparent;
}

.chart-card.is-open .chart-card-top {
    border-bottom-color: #808080;
}

@media (max-width: 991px) {
    .charts-grid {
        grid-template-columns: 1fr;
    }
    .chart-wide {
        grid-column: auto;
    }
    .chart-card-body,
    .chart-doughnut-wrap {
        height: 270px;
    }
}


/* =========================================================
   MOBILE
========================================================= */

.mobile-menu {
    display: none;

    border: none;

    background: #EAF7F0;

    color: #059669;

    width: 42px;
    height: 42px;

    border-radius: 12px;

    font-size: 20px;
    font-family: "Inter", system-ui, sans-serif;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1100px) {

    .stats-grid {
        grid-template-columns:
            repeat(2, 1fr);
    }

}


@media (max-width: 991px) {

    .sidebar {
        transform:
            translateX(-100%);

        transition:
            transform .25s ease;
    }

    .sidebar.show {
        transform:
            translateX(0);
    }

    .main-content {
        margin-left: 0;

        width: 100%;
    }

    .mobile-menu {
        display: inline-flex;

        align-items: center;

        justify-content: center;
    }

    .topbar {
        padding: 0 20px;
    }

    .content {
        padding:
            25px 20px 40px;
    }

}


@media (max-width: 767px) {

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .topbar {
        height: 70px;
    }

    .page-title h1 {
        font-size: 18px;
    }

    .page-title p {
        display: none;
    }

    .admin-info {
        display: none;
    }

    .welcome-box {
        padding: 24px;
    }

    .welcome-box h2 {
        font-size: 19px;
    }

    .status-grid {
        grid-template-columns: 1fr;
    }

    .forecast-meta {
        flex-direction: column;
    }

}

</style>

</head>


<body>

<div class="dashboard-wrapper">


<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside
    class="sidebar"
    id="sidebar"
>


    <!-- BRAND -->

    <div class="sidebar-brand">

        <div class="brand-logo">

            <img
                src="../assets/dental-bg.jpg"
                alt="Abalos Dental Clinic"
            >

        </div>

        <div>

            <div class="brand-name">
                Abalos Dental
            </div>

            <div class="brand-sub">
                DENTAL CLINIC SYSTEM
            </div>

        </div>

    </div>


    <!-- NAVIGATION -->

    <div class="sidebar-nav">

        <div class="nav-title">
            MAIN MENU
        </div>


        <a
            href="dashboard.php"
            target="_self"
            rel="noopener noreferrer"
            class="sidebar-link active"
        >

            <i class="bi bi-grid-1x2-fill"></i>

            <span>
                Dashboard
            </span>

        </a>


        <a
            href="patients.php"
            target="_self"
            rel="noopener noreferrer"
            class="sidebar-link"
        >

            <i class="bi bi-people-fill"></i>

            <span>
                Patients
            </span>

        </a>


        <a
            href="appointments.php"
            target="_self"
            rel="noopener noreferrer"
            class="sidebar-link"
        >

            <i class="bi bi-calendar2-check-fill"></i>

            <span>
                Appointments
            </span>

        </a>


        <a
            href="appointment_concern.php"
            target="_self"
            rel="noopener noreferrer"
            class="sidebar-link"
        >

            <i class="bi bi-exclamation-circle-fill"></i>

            <span>
                Appointment Concerns
            </span>

        </a>


        <a
            href="dentists.php"
            target="_self"
            rel="noopener noreferrer"
            class="sidebar-link"
        >

            <i class="bi bi-person-workspace"></i>

            <span>
                Dentist Schedules
            </span>

        </a>


        <a
            href="queue.php"
            target="_self"
            rel="noopener noreferrer"
            class="sidebar-link"
        >

            <i class="bi bi-list-ol"></i>

            <span>
                Queue
            </span>

        </a>


        <a
            href="reports.php"
            target="_self"
            rel="noopener noreferrer"
            class="sidebar-link"
        >

            <i class="bi bi-bar-chart-fill"></i>

            <span>
                Reports
            </span>

        </a>


        <a
            href="notifications.php"
            target="_self"
            rel="noopener noreferrer"
            class="sidebar-link"
        >

            <i class="bi bi-bell-fill"></i>

            <span>
                Notifications
            </span>

        </a>


        <a
            href="settings.php"
            target="_self"
            rel="noopener noreferrer"
            class="sidebar-link"
        >

            <i class="bi bi-gear-fill"></i>

            <span>
                Settings
            </span>

        </a>

    </div>


    <!-- LOGOUT -->

    <div class="sidebar-bottom">

        <a
            href="../logout.php"
            class="sidebar-link"
        >

            <i class="bi bi-box-arrow-left"></i>

            <span>
                Logout
            </span>

        </a>


        <!-- TAGLINE -->

        <div class="sidebar-tagline">

            <div class="sidebar-tagline-tooth">
                🦷
            </div>

            <div class="sidebar-tagline-text">
                Healthy Smiles.<br>Brighter Futures
            </div>

        </div>

    </div>

</aside>


<!-- =========================================================
     LOGOUT CONFIRM â€” "Are you sure you want to log out?"
========================================================= -->
<div id="adminLogoutConfirm" class="admin-logout-overlay" style="display:none;">
    <div class="admin-logout-box">
        <div class="admin-logout-head">
            <span class="admin-logout-head-icon"><i class="bi bi-box-arrow-right"></i></span>
            <h5>Log Out</h5>
            <button type="button" class="admin-logout-x" id="adminLogoutClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="admin-logout-body">
            <div class="admin-logout-icon"><i class="bi bi-exclamation-circle-fill"></i></div>
            <h5>Are you sure you want to log out?</h5>
            <p>You will need to log in again to access the admin account.</p>
        </div>
        <div class="admin-logout-foot">
            <button type="button" class="btn btn-light" id="adminLogoutCancel">Cancel</button>
            <a href="../logout.php" class="btn admin-logout-yes" id="adminLogoutYes"><i class="bi bi-box-arrow-right me-1"></i>Log Out</a>
        </div>
    </div>
</div>
<style>
.admin-logout-overlay { position: fixed; inset: 0; background: rgba(10,59,52,.55); z-index: 2000; padding: 20px; align-items: center; justify-content: center; }
.admin-logout-box { background: #ffffff; border-radius: 18px; border: 1px solid #C9E9DA; box-shadow: 0 18px 45px rgba(0,0,0,.25); max-width: 440px; width: 100%; overflow: hidden; font-family: "Inter", "DM Sans", system-ui, sans-serif; animation: adminLogoutPop .18s ease; }
@keyframes adminLogoutPop { from { transform: scale(.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.admin-logout-head { display: flex; align-items: center; gap: 12px; padding: 18px 22px; background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%); border-bottom: 1px solid #C9E9DA; }
.admin-logout-head-icon { width: 38px; height: 38px; border-radius: 11px; background: linear-gradient(135deg, #0E6B5E, #0A4A41); color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 4px 10px rgba(14,107,94,.25); flex-shrink: 0; }
.admin-logout-head h5 { color: #143f51; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; font-size: 17px; margin: 0; flex: 1; }
.admin-logout-x { background: #ffffff; border: 1px solid #D1E7DD; color: #57727d; width: 30px; height: 30px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; flex-shrink: 0; }
.admin-logout-x:hover { background: #EAF7F0; color: #0E6B5E; }
.admin-logout-body { padding: 30px 28px 8px; text-align: center; }
.admin-logout-icon { width: 64px; height: 64px; border-radius: 18px; background: #FDECEC; color: #C0392B; border: 1px solid #F5C6C6; display: inline-flex; align-items: center; justify-content: center; font-size: 30px; margin-bottom: 14px; }
.admin-logout-body h5 { color: #143f51; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-weight: 800; font-size: 18px; margin: 0 0 8px; }
.admin-logout-body p { color: #8aa0a8; font-size: 13.5px; margin: 0; }
.admin-logout-foot { display: flex; gap: 12px; justify-content: center; padding: 18px 22px 22px; background: #F6FBF8; border-top: 1px solid #E6F2EC; margin-top: 20px; }
.admin-logout-foot .btn { border-radius: 10px; font-weight: 700; font-size: 14px; padding: 10px 26px; }
.admin-logout-foot .btn-light { background: #fff !important; border: 1px solid #D1E7DD !important; color: #57727d !important; }
.admin-logout-foot .btn-light:hover { background: #EAF7F0 !important; color: #0E6B5E !important; }
.admin-logout-yes { background: linear-gradient(135deg, #E74C3C, #922B21) !important; border: none !important; color: #ffffff !important; box-shadow: 0 6px 14px rgba(192,57,43,.3) !important; }
.admin-logout-yes:hover { filter: brightness(.93); color: #ffffff !important; }
</style>
<script>
(function()
{
    function openAdminLogout(e)
    {
        if (e) e.preventDefault();
        var overlay = document.getElementById("adminLogoutConfirm");
        if (!overlay) return;
        overlay.style.display = "flex";
        document.body.style.overflow = "hidden";
    }

    function closeAdminLogout()
    {
        var overlay = document.getElementById("adminLogoutConfirm");
        if (!overlay) return;
        overlay.style.display = "none";
        document.body.style.overflow = "";
    }

    function initAdminLogout()
    {
        var overlay = document.getElementById("adminLogoutConfirm");
        if (!overlay || overlay.dataset.bound === "1") return;
        overlay.dataset.bound = "1";

        document.querySelectorAll('a[href="../logout.php"], a[href="logout.php"]').forEach(function(a)
        {
            if (a.closest("#adminLogoutConfirm")) return;
            if (a.dataset.logoutBound === "1") return;
            a.dataset.logoutBound = "1";
            a.addEventListener("click", openAdminLogout);
        });

        document.getElementById("adminLogoutCancel").addEventListener("click", closeAdminLogout);
        document.getElementById("adminLogoutClose").addEventListener("click", closeAdminLogout);
        overlay.addEventListener("click", function(e)
        {
            if (e.target === overlay) closeAdminLogout();
        });
        document.addEventListener("keydown", function(e)
        {
            if (e.key === "Escape") closeAdminLogout();
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initAdminLogout);
    } else {
        initAdminLogout();
    }
})();
</script>



<!-- =========================================================
     MAIN
========================================================= -->

<main class="main-content">


    <!-- TOPBAR -->

    <header class="topbar">

        <div class="d-flex align-items-center gap-3">


            <button
                type="button"
                class="mobile-menu"
                onclick="toggleSidebar()"
            >

                <i class="bi bi-list"></i>

            </button>


            <div class="page-title">

                <h1>
                    Dashboard
                </h1>

                <p>
                    Clinic overview and appointment management
                </p>

            </div>

        </div>


        <div class="admin-area">

            <div class="admin-info">

                <strong>
                    <?= adminDashboardEscape($username) ?>
                </strong>

                <span>
                    Clinic Administrator
                </span>

            </div>


            <div class="admin-avatar">

                <i class="bi bi-person-fill"></i>

            </div>

        </div>

    </header>



    <!-- CONTENT -->

    <div class="content">


        <!-- WELCOME -->

        <div class="welcome-box" id="welcomeBox">

            <div class="welcome-content">

                <div class="welcome-label">
                    ABALOS DENTAL CLINIC
                </div>

                <h2>
                    Welcome to your clinic dashboard
                </h2>

                <p>
                    Monitor today's appointments, patient bookings,
                    queue, and appointment demand.
                </p>

            </div>

        </div>



        <!-- =====================================================
             STATISTICS
        ====================================================== -->

        <div class="stats-grid" id="statsGrid">


            <!-- TODAY -->

            <div class="stat-card stat-clickable" data-view="today" role="button" tabindex="0" title="View Today's Appointments">

                <div class="stat-featured-head">

                    <span class="stat-featured-icon"><i class="bi bi-calendar-event-fill"></i></span>

                    <h3>
                        Today's Appointments
                    </h3>

                </div>

                <div class="stat-number">
                    <?= $dashboardCounts['today'] ?>
                </div>

                <div class="stat-featured-sub">
                    <span class="stat-featured-dot"></span>
                    Scheduled for today
                </div>

                <span class="stat-go"><i class="bi bi-chevron-right"></i></span>

            </div>


            <!-- PENDING -->

            <div class="stat-card stat-clickable" data-view="pending" role="button" tabindex="0" title="View Pending Appointments">

                <div class="stat-featured-head">

                    <span class="stat-featured-icon"><i class="bi bi-hourglass-split"></i></span>

                    <h3>
                        Pending Appointments
                    </h3>

                </div>

                <div class="stat-number">
                    <?= $dashboardCounts['pending'] ?>
                </div>

                <div class="stat-featured-sub">
                    <span class="stat-featured-dot"></span>
                    Awaiting confirmation
                </div>

                <span class="stat-go"><i class="bi bi-chevron-right"></i></span>

            </div>


            <!-- CONFIRMED -->

            <div class="stat-card stat-clickable" data-view="confirmed" role="button" tabindex="0" title="View Confirmed Appointments">

                <div class="stat-featured-head">

                    <span class="stat-featured-icon"><i class="bi bi-calendar2-check-fill"></i></span>

                    <h3>
                        Confirmed Appointments
                    </h3>

                </div>

                <div class="stat-number">
                    <?= $dashboardCounts['confirmed'] ?>
                </div>

                <div class="stat-featured-sub">
                    <span class="stat-featured-dot"></span>
                    Confirmed appointments
                </div>

                <span class="stat-go"><i class="bi bi-chevron-right"></i></span>

            </div>


            <!-- COMPLETED -->

            <div class="stat-card stat-clickable" data-view="completed" role="button" tabindex="0" title="View Completed Appointments">

                <div class="stat-featured-head">

                    <span class="stat-featured-icon"><i class="bi bi-check-circle-fill"></i></span>

                    <h3>
                        Completed Appointments
                    </h3>

                </div>

                <div class="stat-number">
                    <?= $dashboardCounts['completed'] ?>
                </div>

                <div class="stat-featured-sub">
                    <span class="stat-featured-dot"></span>
                    Completed treatments
                </div>

                <span class="stat-go"><i class="bi bi-chevron-right"></i></span>

            </div>


            <!-- QUEUE -->

            <div class="stat-card stat-clickable" data-view="queue" role="button" tabindex="0" title="View Current Queue">

                <div class="stat-featured-head">

                    <span class="stat-featured-icon"><i class="bi bi-list-check"></i></span>

                    <h3>
                        Current Queue
                    </h3>

                </div>

                <div class="stat-number">
                    <?= $dashboardCounts['queue'] ?>
                </div>

                <div class="stat-featured-sub">
                    <span class="stat-featured-dot"></span>
                    Patients in queue now
                </div>

                <span class="stat-go"><i class="bi bi-chevron-right"></i></span>

            </div>


            <!-- FORECAST -->

            <div class="stat-card stat-clickable" data-view="forecast" role="button" tabindex="0" title="View Demand Forecast">

                <div class="stat-featured-head">

                    <span class="stat-featured-icon"><i class="bi bi-graph-up-arrow"></i></span>

                    <h3>
                        Demand Forecast
                    </h3>

                </div>

                <div class="stat-number is-text">
                    Open
                </div>

                <div class="stat-featured-sub">
                    <span class="stat-featured-dot"></span>
                    Demand prediction
                </div>

                <span class="stat-go"><i class="bi bi-chevron-right"></i></span>

            </div>

        </div>


        <!-- =====================================================
             CLICKABLE CARD DETAIL VIEWS (same page, no new tab)
             Ipinapakita sa itaas pag pinindot ang card.
        ====================================================== -->

        <div id="cardDetailWrap">

            <!-- LOOB NA NAV: iba pang summary cards (no need mag-X para lumipat) -->
            <div id="detailNav" style="display:none;">
                <div class="detail-nav-card" data-goto="today" role="button" tabindex="0" title="Today's Appointments">
                    <div class="detail-nav-icon icon-today"><i class="bi bi-calendar-event-fill"></i></div>
                    <div><small>Today's</small><strong><?= $dashboardCounts['today'] ?></strong></div>
                </div>
                <div class="detail-nav-card" data-goto="pending" role="button" tabindex="0" title="Pending Appointments">
                    <div class="detail-nav-icon icon-amber"><i class="bi bi-hourglass-split"></i></div>
                    <div><small>Pending</small><strong><?= $dashboardCounts['pending'] ?></strong></div>
                </div>
                <div class="detail-nav-card" data-goto="confirmed" role="button" tabindex="0" title="Confirmed Appointments">
                    <div class="detail-nav-icon icon-green"><i class="bi bi-calendar2-check-fill"></i></div>
                    <div><small>Confirmed</small><strong><?= $dashboardCounts['confirmed'] ?></strong></div>
                </div>
                <div class="detail-nav-card" data-goto="completed" role="button" tabindex="0" title="Completed Appointments">
                    <div class="detail-nav-icon icon-purple"><i class="bi bi-check-circle-fill"></i></div>
                    <div><small>Completed</small><strong><?= $dashboardCounts['completed'] ?></strong></div>
                </div>
                <div class="detail-nav-card" data-goto="queue" role="button" tabindex="0" title="Current Queue">
                    <div class="detail-nav-icon icon-pink"><i class="bi bi-list-ol"></i></div>
                    <div><small>Queue</small><strong><?= $dashboardCounts['queue'] ?></strong></div>
                </div>
                <div class="detail-nav-card" data-goto="forecast" role="button" tabindex="0" title="Demand Forecast">
                    <div class="detail-nav-icon icon-teal"><i class="bi bi-graph-up-arrow"></i></div>
                    <div><small>Forecast</small><strong style="font-size:13px;">Open</strong></div>
                </div>
            </div>

            <!-- TODAY'S APPOINTMENTS -->
            <div class="dashboard-card card-detail" id="detail-today" style="display:none;">
                <div class="card-top">
                    <div>
                        <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; Today's Appointment</div>
                        <h3><span class="detail-title-icon icon-today"><i class="bi bi-calendar-event-fill"></i></span> Today's Appointment</h3>
                        <p class="detail-sub">View and manage all appointments scheduled for today.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="detail-date-pill"><i class="bi bi-calendar3"></i> <?= date('F j, Y') ?></span>
                        
                    </div>
                </div>
                <div class="card-body-custom" style="border-radius:0 0 16px 16px;overflow:hidden;">
                    <div class="detail-mini-grid" data-mini-group="today">
                        <div class="detail-mini detail-mini-clickable" data-filter="all" role="button" tabindex="0" title="Show all"><div class="detail-mini-icon icon-today"><i class="bi bi-calendar-event-fill"></i></div><div><small>Total Appointments</small><strong><?= $todaySub['total'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="confirmed" role="button" tabindex="0" title="Show confirmed"><div class="detail-mini-icon icon-green"><i class="bi bi-patch-check-fill"></i></div><div><small>Confirmed</small><strong><?= $todaySub['confirmed'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="pending" role="button" tabindex="0" title="Show pending"><div class="detail-mini-icon icon-amber"><i class="bi bi-hourglass-split"></i></div><div><small>Pending</small><strong><?= $todaySub['pending'] ?></strong></div></div>
                    </div>
                    <div class="detail-tabs" data-tab-group="today">
                        <button type="button" class="detail-tab active" data-filter="all">All (<?= $todaySub['total'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="confirmed">Confirmed (<?= $todaySub['confirmed'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="pending">Pending (<?= $todaySub['pending'] ?>)</button>
                    </div>
                    <div class="table-wrapper">
                        <table class="appointment-table">
                            <thead><tr><th>#</th><th>Patient Name</th><th>Date &amp; Time</th><th>Service</th><th>Dentist</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php if (empty($todayList)): ?>
                                <tr class="detail-empty-row"><td colspan="6"><div class="empty-state"><div class="empty-icon"><i class="bi bi-calendar-x"></i></div><strong>No appointments for today.</strong></div></td></tr>
                            <?php else: ?>
                                <?php $n=1; foreach ($todayList as $a):
                                    $pn = trim(($a['patient_name'] ?? '')) !== '' ? trim($a['patient_name']) : 'Patient #' . ($a['patient_id'] ?? '-');
                                    $dn = trim(($a['dentist_name'] ?? '')) !== '' ? trim($a['dentist_name']) : 'Not assigned';
                                    $dt = '-'; if (!empty($a['appointment_date'])) { $ts = strtotime($a['appointment_date'] . ' ' . ($a['start_time'] ?? '')); if ($ts) $dt = date('M j, Y h:i A', $ts); }
                                    $st = trim($a['status'] ?? 'Pending'); $sl = strtolower($st);
                                    $fk = ($sl==='confirmed'||$sl==='approved') ? 'confirmed' : $sl;
                                ?>
                                <tr data-status="<?= adminDashboardEscape($fk) ?>">
                                    <td><?= $n++ ?></td>
                                    <td><div class="patient-cell"><div class="patient-avatar"><i class="bi bi-person-fill"></i></div><div><strong><?= adminDashboardEscape($pn) ?></strong></div></div></td>
                                    <td><?= adminDashboardEscape($dt) ?></td>
                                    <td><?= adminDashboardEscape($a['service_type'] ?? 'Dental Service') ?></td>
                                    <td><?= adminDashboardEscape($dn) ?></td>
                                    <td><span class="status-badge <?= adminDashboardEscape(getStatusBadgeClass($st)) ?>"><?= adminDashboardEscape($st) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- PENDING APPOINTMENTS -->
            <div class="dashboard-card card-detail" id="detail-pending" style="display:none;">
                <div class="card-top">
                    <div>
                        <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; Pending Appointments</div>
                        <h3><span class="detail-title-icon icon-amber"><i class="bi bi-hourglass-split"></i></span> Pending Appointments</h3>
                        <p class="detail-sub">View and manage all pending appointment requests.</p>
                    </div>

                </div>
                <div class="card-body-custom">
                    <div class="detail-mini-grid" data-mini-group="pending">
                        <div class="detail-mini detail-mini-clickable" data-filter="all" role="button" tabindex="0" title="Show all"><div class="detail-mini-icon icon-amber"><i class="bi bi-hourglass-split"></i></div><div><small>Total Pending</small><strong><?= $pendingSub['total'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="new" role="button" tabindex="0" title="Show new"><div class="detail-mini-icon icon-amber"><i class="bi bi-inbox-fill"></i></div><div><small>New Requests</small><strong><?= $pendingSub['new'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="for confirmation" role="button" tabindex="0" title="Show for confirmation"><div class="detail-mini-icon icon-green"><i class="bi bi-patch-check-fill"></i></div><div><small>For Confirmation</small><strong><?= $pendingSub['for_confirmation'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="reschedule" role="button" tabindex="0" title="Show reschedule"><div class="detail-mini-icon icon-blue"><i class="bi bi-arrow-repeat"></i></div><div><small>Reschedule Requests</small><strong><?= $pendingSub['reschedule'] ?></strong></div></div>
                    </div>
                    <div class="detail-tabs" data-tab-group="pending">
                        <button type="button" class="detail-tab active" data-filter="all">All (<?= $pendingSub['total'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="new">New (<?= $pendingSub['new'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="for confirmation">For Confirmation (<?= $pendingSub['for_confirmation'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="reschedule">Reschedule (<?= $pendingSub['reschedule'] ?>)</button>
                    </div>
                    <div class="table-wrapper">
                        <table class="appointment-table">
                            <thead><tr><th>#</th><th>Patient Name</th><th>Requested Date</th><th>Service</th><th>Dentist</th><th>Request Type</th></tr></thead>
                            <tbody>
                            <?php if (empty($pendingList)): ?>
                                <tr class="detail-empty-row"><td colspan="6"><div class="empty-state"><div class="empty-icon"><i class="bi bi-hourglass"></i></div><strong>No pending appointments.</strong></div></td></tr>
                            <?php else: ?>
                                <?php $n=1; foreach ($pendingList as $a):
                                    $pn = trim(($a['patient_name'] ?? '')) !== '' ? trim($a['patient_name']) : 'Patient #' . ($a['patient_id'] ?? '-');
                                    $dn = trim(($a['dentist_name'] ?? '')) !== '' ? trim($a['dentist_name']) : 'Not assigned';
                                    $dt = '-'; if (!empty($a['appointment_date'])) { $ts = strtotime($a['appointment_date'] . ' ' . ($a['start_time'] ?? '')); if ($ts) $dt = date('M j, Y h:i A', $ts); }
                                    $rt = $a['_request_type'] ?? 'For Confirmation';
                                ?>
                                <tr data-status="<?= adminDashboardEscape(strtolower($rt)) ?>">
                                    <td><?= $n++ ?></td>
                                    <td><div class="patient-cell"><div class="patient-avatar"><i class="bi bi-person-fill"></i></div><div><strong><?= adminDashboardEscape($pn) ?></strong></div></div></td>
                                    <td><?= adminDashboardEscape($dt) ?></td>
                                    <td><?= adminDashboardEscape($a['service_type'] ?? 'Dental Service') ?></td>
                                    <td><?= adminDashboardEscape($dn) ?></td>
                                    <td><span class="status-badge badge-pending"><?= adminDashboardEscape($rt) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- CONFIRMED APPOINTMENTS -->
            <div class="dashboard-card card-detail" id="detail-confirmed" style="display:none;">
                <div class="card-top">
                    <div>
                        <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; Confirmed Appointments</div>
                        <h3><span class="detail-title-icon icon-green"><i class="bi bi-calendar2-check-fill"></i></span> Confirmed Appointments</h3>
                        <p class="detail-sub">View and manage all confirmed appointments.</p>
                    </div>

                </div>
                <div class="card-body-custom">
                    <div class="detail-mini-grid" data-mini-group="confirmed">
                        <div class="detail-mini detail-mini-clickable" data-filter="all" role="button" tabindex="0" title="Show all"><div class="detail-mini-icon icon-green"><i class="bi bi-calendar2-check-fill"></i></div><div><small>Total Confirmed</small><strong><?= $confirmedSub['total'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="today" role="button" tabindex="0" title="Show today"><div class="detail-mini-icon icon-green"><i class="bi bi-sun-fill"></i></div><div><small>Today</small><strong><?= $confirmedSub['today'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="week" role="button" tabindex="0" title="Show this week"><div class="detail-mini-icon icon-green"><i class="bi bi-calendar-week-fill"></i></div><div><small>This Week</small><strong><?= $confirmedSub['week'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="month" role="button" tabindex="0" title="Show this month"><div class="detail-mini-icon icon-blue"><i class="bi bi-calendar-month-fill"></i></div><div><small>This Month</small><strong><?= $confirmedSub['month'] ?></strong></div></div>
                    </div>
                    <div class="detail-tabs" data-tab-group="confirmed">
                        <button type="button" class="detail-tab active" data-filter="all">All (<?= $confirmedSub['total'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="today">Today (<?= $confirmedSub['today'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="week">This Week (<?= $confirmedSub['week'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="month">This Month (<?= $confirmedSub['month'] ?>)</button>
                    </div>
                    <div class="table-wrapper">
                        <table class="appointment-table">
                            <thead><tr><th>#</th><th>Patient Name</th><th>Date &amp; Time</th><th>Service</th><th>Dentist</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php if (empty($confirmedList)): ?>
                                <tr class="detail-empty-row"><td colspan="6"><div class="empty-state"><div class="empty-icon"><i class="bi bi-calendar2-check"></i></div><strong>No confirmed appointments.</strong></div></td></tr>
                            <?php else: ?>
                                <?php $n=1; foreach ($confirmedList as $a):
                                    $pn = trim(($a['patient_name'] ?? '')) !== '' ? trim($a['patient_name']) : 'Patient #' . ($a['patient_id'] ?? '-');
                                    $dn = trim(($a['dentist_name'] ?? '')) !== '' ? trim($a['dentist_name']) : 'Not assigned';
                                    $dstr = substr((string)($a['appointment_date'] ?? ''), 0, 10);
                                    $dt = '-'; if ($dstr !== '') { $ts = strtotime($dstr . ' ' . ($a['start_time'] ?? '')); if ($ts) $dt = date('M j, Y h:i A', $ts); }
                                    $st = trim($a['status'] ?? 'Confirmed');
                                    $fk = 'all'; if ($dstr === date('Y-m-d')) $fk = 'today';
                                    $ws = (new DateTime('monday this week'))->format('Y-m-d'); $we = (new DateTime('sunday this week'))->format('Y-m-d');
                                    $inWeek = ($dstr >= $ws && $dstr <= $we); $inMonth = (substr($dstr,0,7) === date('Y-m'));
                                ?>
                                <tr data-today="<?= $dstr === date('Y-m-d') ? '1' : '0' ?>" data-week="<?= $inWeek ? '1' : '0' ?>" data-month="<?= $inMonth ? '1' : '0' ?>">
                                    <td><?= $n++ ?></td>
                                    <td><div class="patient-cell"><div class="patient-avatar"><i class="bi bi-person-fill"></i></div><div><strong><?= adminDashboardEscape($pn) ?></strong></div></div></td>
                                    <td><?= adminDashboardEscape($dt) ?></td>
                                    <td><?= adminDashboardEscape($a['service_type'] ?? 'Dental Service') ?></td>
                                    <td><?= adminDashboardEscape($dn) ?></td>
                                    <td><span class="status-badge <?= adminDashboardEscape(getStatusBadgeClass($st)) ?>"><?= adminDashboardEscape($st) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- COMPLETED APPOINTMENTS -->
            <div class="dashboard-card card-detail" id="detail-completed" style="display:none;">
                <div class="card-top">
                    <div>
                        <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; Completed Appointments</div>
                        <h3><span class="detail-title-icon icon-purple"><i class="bi bi-check-circle-fill"></i></span> Completed Appointments</h3>
                        <p class="detail-sub">View records of all completed appointments.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="detail-date-pill"><i class="bi bi-calendar3"></i> <?= date('F j, Y', strtotime('-30 days')) ?> - <?= date('F j, Y') ?></span>
                        
                    </div>
                </div>
                <div class="card-body-custom">
                    <div class="detail-mini-grid" data-mini-group="completed">
                        <div class="detail-mini detail-mini-clickable" data-filter="all" role="button" tabindex="0" title="Show all"><div class="detail-mini-icon icon-purple"><i class="bi bi-check-circle-fill"></i></div><div><small>Total Completed</small><strong><?= $completedSub['total'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="today" role="button" tabindex="0" title="Show today"><div class="detail-mini-icon icon-green"><i class="bi bi-sun-fill"></i></div><div><small>Today</small><strong><?= $completedSub['today'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="week" role="button" tabindex="0" title="Show this week"><div class="detail-mini-icon icon-green"><i class="bi bi-calendar-week-fill"></i></div><div><small>This Week</small><strong><?= $completedSub['week'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="month" role="button" tabindex="0" title="Show this month"><div class="detail-mini-icon icon-blue"><i class="bi bi-calendar-month-fill"></i></div><div><small>This Month</small><strong><?= $completedSub['month'] ?></strong></div></div>
                    </div>
                    <div class="table-wrapper">
                        <table class="appointment-table">
                            <thead><tr><th>#</th><th>Patient Name</th><th>Date &amp; Time</th><th>Service</th><th>Dentist</th></tr></thead>
                            <tbody>
                            <?php if (empty($completedList)): ?>
                                <tr class="detail-empty-row"><td colspan="5"><div class="empty-state"><div class="empty-icon"><i class="bi bi-check2-square"></i></div><strong>No completed appointments.</strong></div></td></tr>
                            <?php else: ?>
                                <?php $n=1; foreach ($completedList as $a):
                                    $pn = trim(($a['patient_name'] ?? '')) !== '' ? trim($a['patient_name']) : 'Patient #' . ($a['patient_id'] ?? '-');
                                    $dn = trim(($a['dentist_name'] ?? '')) !== '' ? trim($a['dentist_name']) : 'Not assigned';
                                    $dstr = substr((string)($a['appointment_date'] ?? ''), 0, 10);
                                    $dt = '-'; if ($dstr !== '') { $ts = strtotime($dstr . ' ' . ($a['start_time'] ?? '')); if ($ts) $dt = date('M j, Y h:i A', $ts); }
                                    $ws = (new DateTime('monday this week'))->format('Y-m-d'); $we = (new DateTime('sunday this week'))->format('Y-m-d');
                                    $inWeek = ($dstr >= $ws && $dstr <= $we); $inMonth = (substr($dstr,0,7) === date('Y-m'));
                                ?>
                                <tr data-status="completed" data-today="<?= $dstr === date('Y-m-d') ? '1' : '0' ?>" data-week="<?= $inWeek ? '1' : '0' ?>" data-month="<?= $inMonth ? '1' : '0' ?>">
                                    <td><?= $n++ ?></td>
                                    <td><div class="patient-cell"><div class="patient-avatar"><i class="bi bi-person-fill"></i></div><div><strong><?= adminDashboardEscape($pn) ?></strong></div></div></td>
                                    <td><?= adminDashboardEscape($dt) ?></td>
                                    <td><?= adminDashboardEscape($a['service_type'] ?? 'Dental Service') ?></td>
                                    <td><?= adminDashboardEscape($dn) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- CURRENT QUEUE -->
            <div class="dashboard-card card-detail" id="detail-queue" style="display:none;">
                <div class="card-top">
                    <div>
                        <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; Current Queue</div>
                        <h3><span class="detail-title-icon icon-pink"><i class="bi bi-list-ol"></i></span> Current Queue</h3>
                        <p class="detail-sub">Monitor patients currently in the clinic queue.</p>
                    </div>

                </div>
                <div class="card-body-custom">
                    <div class="detail-mini-grid" data-mini-group="queue">
                        <div class="detail-mini detail-mini-clickable" data-filter="all" role="button" tabindex="0" title="Show all"><div class="detail-mini-icon icon-teal"><i class="bi bi-people-fill"></i></div><div><small>Total In Queue</small><strong><?= $queueSub['total'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="waiting" role="button" tabindex="0" title="Show waiting"><div class="detail-mini-icon icon-amber"><i class="bi bi-hourglass-split"></i></div><div><small>Waiting</small><strong><?= $queueSub['waiting'] ?></strong></div></div>
                        <div class="detail-mini detail-mini-clickable" data-filter="consult" role="button" tabindex="0" title="Show in consultation"><div class="detail-mini-icon icon-blue"><i class="bi bi-person-badge-fill"></i></div><div><small>In Consultation</small><strong><?= $queueSub['in_consultation'] ?></strong></div></div>
                        <div class="detail-mini"><div class="detail-mini-icon icon-green"><i class="bi bi-check-circle-fill"></i></div><div><small>Completed Today</small><strong><?= $queueSub['completed_today'] ?></strong></div></div>
                    </div>
                    <div class="detail-tabs" data-tab-group="queue">
                        <button type="button" class="detail-tab active" data-filter="all">All (<?= $queueSub['total'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="waiting">Waiting (<?= $queueSub['waiting'] ?>)</button>
                        <button type="button" class="detail-tab" data-filter="consult">In Consultation (<?= $queueSub['in_consultation'] ?>)</button>
                    </div>
                    <div class="table-wrapper">
                        <table class="appointment-table">
                            <thead><tr><th>#</th><th>Patient Name</th><th>Service</th><th>Dentist</th><th>Status</th><th>Waiting Time</th></tr></thead>
                            <tbody>
                            <?php
                            $queueVisible = array_values(array_filter($queueList, function($q){
                                $s = strtolower(trim($q['status'] ?? ''));
                                return !in_array($s, ['completed','cancelled','canceled'], true);
                            }));
                            ?>
                            <?php if (empty($queueVisible)): ?>
                                <tr class="detail-empty-row"><td colspan="6"><div class="empty-state"><div class="empty-icon"><i class="bi bi-people"></i></div><strong>No patients currently in queue.</strong></div></td></tr>
                            <?php else: ?>
                                <?php $n=1; foreach ($queueVisible as $q):
                                    $pn = trim((string)($q['patient_name'] ?? '')) !== '' ? trim((string)$q['patient_name']) : 'Patient #' . ($q['patient_id'] ?? '-');
                                    $sv = trim((string)($q['service_type'] ?? '')) !== '' ? $q['service_type'] : '—';
                                    $dn = trim((string)($q['dentist_name'] ?? '')) !== '' ? $q['dentist_name'] : '—';
                                    $st = trim($q['status'] ?? 'Waiting'); $sl = strtolower($st);
                                    $fk = (strpos($sl,'consult')!==false) ? 'consult' : 'waiting';
                                    $wt = '—';
                                    if (!empty($q['created_at'])) { $ts = strtotime($q['created_at']); if ($ts) { $mins = max(0, (int)floor((time()-$ts)/60)); if ($mins < 60) $wt = $mins . ' min'; else $wt = floor($mins/60) . 'h ' . ($mins%60) . 'm'; } }
                                ?>
                                <tr data-status="<?= adminDashboardEscape($fk) ?>">
                                    <td><?= $n++ ?></td>
                                    <td><div class="patient-cell"><div class="patient-avatar"><i class="bi bi-person-fill"></i></div><div><strong><?= adminDashboardEscape($pn) ?></strong><span>#<?= adminDashboardEscape($q['queue_number'] ?? $n) ?> · <?= adminDashboardEscape($q['priority'] ?? 'Normal') ?></span></div></div></td>
                                    <td><?= adminDashboardEscape($sv) ?></td>
                                    <td><?= adminDashboardEscape($dn) ?></td>
                                    <td><span class="status-badge <?= adminDashboardEscape(getStatusBadgeClass($st)) ?>"><?= adminDashboardEscape($st) ?></span></td>
                                    <td><?= adminDashboardEscape($wt) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <style>.card-detail .detail-tabs { display: none !important; }</style>
            <!-- DEMAND FORECAST -->
            <div class="dashboard-card card-detail" id="detail-forecast" style="display:none;">
                <div class="card-top">
                    <div>
                        <div class="detail-crumb"><i class="bi bi-house-door"></i> Dashboard &nbsp;›&nbsp; Demand Forecast</div>
                        <h3><span class="detail-title-icon icon-teal"><i class="bi bi-graph-up-arrow"></i></span> Demand Forecast</h3>
                        <p class="detail-sub">Predict and analyze appointment demand using AI Linear Regression forecasting.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="detail-date-pill">Next 7 Days</span>
                    </div>
                </div>
                <div class="card-body-custom">
                    <?php
                    $fVals = !empty($forecastData) ? array_column($forecastData, 'prediction') : [0];
                    $fOpenRows = array_values(array_filter($forecastData ?? [], function ($r) {
                        return ($r['day_name'] ?? '') !== 'Sunday';
                    }));
                    $fOpenVals = !empty($fOpenRows) ? array_column($fOpenRows, 'prediction') : $fVals;
                    $fPeak = !empty($forecastData) ? max($fVals) : 0;
                    $fLow = !empty($fOpenVals) ? min($fOpenVals) : 0;
                    $fPeakDay = null; $fLowDay = null;
                    foreach (($forecastData ?? []) as $f) {
                        if ($fPeakDay === null && (int)($f['prediction'] ?? 0) === (int)$fPeak) $fPeakDay = $f;
                    }
                    foreach ((!empty($fOpenRows) ? $fOpenRows : ($forecastData ?? [])) as $f) {
                        if ($fLowDay === null && (int)($f['prediction'] ?? 0) === (int)$fLow) $fLowDay = $f;
                    }
                    $fAvg = !empty($fOpenVals) ? array_sum($fOpenVals)/count($fOpenVals) : 0;
                    ?>
                    <h4 class="detail-chart-title">Appointment Demand Forecast (Linear Regression + Booked)</h4>
                    <div class="detail-forecast-chart"><canvas id="cardForecastChart"></canvas></div>
                    <div class="detail-forecast-stats">
                        <div class="detail-mini"><div><small>Peak Day</small><strong><?= $fPeakDay ? adminDashboardEscape($fPeakDay['formatted_date'] ?? '') : '—' ?></strong><span class="detail-mini-note"><?= (int)$fPeak ?> appointments</span></div></div>
                        <div class="detail-mini"><div><small>Lowest Operating Day</small><strong><?= $fLowDay ? adminDashboardEscape($fLowDay['formatted_date'] ?? '') : '—' ?></strong><span class="detail-mini-note"><?= (int)$fLow ?> appointments</span></div></div>
                        <div class="detail-mini"><div><small>Daily Average (Open Days)</small><strong><?= number_format($fAvg, 1) ?></strong><span class="detail-mini-note">appointments</span></div></div>
                        <div class="detail-mini"><div><small>Total 7-Day Forecast</small><strong><?= (int)array_sum($fVals) ?></strong><span class="detail-mini-note">appointments</span></div></div>
                    </div>
                    <?php if (!empty($forecastData)): ?>
                    <div class="demand-days mt-3">
                        <?php foreach ($forecastData as $forecast): ?>
                            <div class="demand-day">
                                <div class="demand-date">
                                    <div class="demand-date-icon"><i class="bi bi-calendar3"></i></div>
                                    <div>
                                        <strong><?= adminDashboardEscape($forecast['day_name']) ?></strong>
                                        <span><?= adminDashboardEscape($forecast['formatted_date']) ?><?= ($forecast['day_name'] ?? '') === 'Sunday' ? ' (Clinic Closed)' : '' ?></span>
                                    </div>
                                </div>
                                <div class="demand-right">
                                    <span class="demand-count">
                                        <?= (int)$forecast['prediction'] ?> appointment<?= $forecast['prediction'] == 1 ? '' : 's' ?>
                                    </span>
                                    <span class="demand-booked">
                                        AI: <?= (int)$forecast['ml_prediction'] ?> · Booked: <?= (int)$forecast['actual_booked'] ?>
                                    </span>
                                    <span class="demand-badge <?= adminDashboardEscape($forecast['demand_class']) ?>">
                                        <i class="<?= adminDashboardEscape($forecast['demand_icon']) ?>"></i>
                                        <?= adminDashboardEscape($forecast['demand_level']) ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="planning-box mt-3" style="display:flex;align-items:flex-start;gap:12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px 16px;">
                        <i class="bi bi-stars" style="color:#059669;font-size:20px;flex-shrink:0;"></i>
                        <div style="flex:1;">
                            <strong style="color:#065f46;display:block;margin-bottom:4px;">AI Scheduling &amp; Rescheduling Recommendation</strong>
                            <span style="color:#374151;font-size:13px;display:block;">
                                <?php if ($fPeakDay && $fLowDay): ?>
                                    Peak demand is projected on <strong><?= adminDashboardEscape($fPeakDay['day_name'] . ' (' . $fPeakDay['formatted_date'] . ')') ?></strong> with <strong><?= (int)$fPeak ?> appointments</strong>.
                                    To prevent bottlenecks, the AI recommends assigning new walk-ins and rescheduling missed/cancelled/pending appointments to <strong><?= adminDashboardEscape($fLowDay['day_name'] . ' (' . $fLowDay['formatted_date'] . ')') ?></strong> (lowest open operating day with <strong><?= (int)$fLow ?> appointments</strong>).
                                <?php else: ?>
                                    Assign new walk-in and rescheduled appointments to Moderate/Low-demand operating days to balance dentist workload.
                                <?php endif; ?>
                            </span>
                            <div class="mt-2 d-flex flex-wrap gap-2">
                                <a href="reports.php" class="btn btn-sm btn-success" style="font-size:12px;font-weight:700;border-radius:8px;">
                                    <i class="bi bi-table me-1"></i>View AI Reschedule Candidates in Reports
                                </a>
                                <a href="appointments.php" class="btn btn-sm btn-outline-success" style="font-size:12px;font-weight:700;border-radius:8px;">
                                    <i class="bi bi-calendar2-check me-1"></i>Open Appointments
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>



        <!-- =====================================================
             ANALYTICS CHARTS
        ====================================================== -->

        <div class="charts-section" id="chartsSection">

            <div class="charts-section-title">
                <h3>
                    <span class="section-icon">
                        <i class="bi bi-activity"></i>
                    </span>
                    Clinic Analytics
                </h3>
                <span class="section-live">
                    <span class="live-dot"></span>
                    Live data from appointments
                </span>
            </div>

            <div class="charts-grid">

                <!-- 1. 14-DAY TREND -->
                <div class="chart-card chart-wide" id="analyticsCardTrend">
                    <div class="chart-card-top chart-toggle" role="button" tabindex="0" aria-expanded="false" aria-controls="analyticsPanelTrend">
                        <div class="chart-head">
                            <div class="chart-icon icon-blue">
                                <i class="bi bi-graph-up-arrow"></i>
                            </div>
                            <div>
                                <h4>Appointment Trend</h4>
                                <small>Last 14 days patient visits</small>
                            </div>
                        </div>
                        <span class="chart-pill pill-blue" id="trendTotalPill">0 visits</span>
                        <i class="bi bi-chevron-down chart-chevron"></i>
                    </div>
                    <div class="chart-panel" id="analyticsPanelTrend">
                        <div class="chart-card-body">
                            <canvas id="chartTrend"></canvas>
                        </div>
                    </div>
                </div>

                <!-- 2. STATUS DISTRIBUTION -->
                <div class="chart-card chart-wide" id="analyticsCardStatus">
                    <div class="chart-card-top chart-toggle" role="button" tabindex="0" aria-expanded="false" aria-controls="analyticsPanelStatus">
                        <div class="chart-head">
                            <div class="chart-icon icon-teal">
                                <i class="bi bi-pie-chart-fill"></i>
                            </div>
                            <div>
                                <h4>Appointment Status</h4>
                                <small>Overall distribution</small>
                            </div>
                        </div>
                        <span class="chart-pill">Live</span>
                        <i class="bi bi-chevron-down chart-chevron"></i>
                    </div>
                    <div class="chart-panel" id="analyticsPanelStatus">
                        <div class="chart-card-body chart-doughnut-wrap">
                            <canvas id="chartStatus"></canvas>
                            <div class="chart-center">
                                <strong id="statusTotal">0</strong>
                                <span id="statusCenterLabel">Total</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3. FORECAST -->
                <div class="chart-card chart-wide" id="analyticsCardForecast">
                    <div class="chart-card-top chart-toggle" role="button" tabindex="0" aria-expanded="false" aria-controls="analyticsPanelForecast">
                        <div class="chart-head">
                            <div class="chart-icon icon-amber">
                                <i class="bi bi-calendar2-week-fill"></i>
                            </div>
                            <div>
                                <h4>7-Day Demand Forecast</h4>
                                <small>AI prediction + actual bookings</small>
                            </div>
                        </div>
                        <span class="chart-pill pill-amber">AI Forecast</span>
                        <i class="bi bi-chevron-down chart-chevron"></i>
                    </div>
                    <div class="chart-panel" id="analyticsPanelForecast">
                        <div class="chart-card-body">
                            <canvas id="chartForecast"></canvas>
                        </div>
                    </div>
                </div>

                <!-- 4. MONTHLY VOLUME -->
                <div class="chart-card" id="analyticsCardMonthly" style="grid-column:1/-1;">
                    <div class="chart-card-top chart-toggle" role="button" tabindex="0" aria-expanded="false" aria-controls="analyticsPanelMonthly">
                        <div class="chart-head">
                            <div class="chart-icon icon-green">
                                <i class="bi bi-bar-chart-fill"></i>
                            </div>
                            <div>
                                <h4>Monthly Appointments</h4>
                                <small>Patient volume Â· last 6 months</small>
                            </div>
                        </div>
                        <span class="chart-pill pill-green" id="monthlyTotalPill">0 total</span>
                        <i class="bi bi-chevron-down chart-chevron"></i>
                    </div>
                    <div class="chart-panel" id="analyticsPanelMonthly">
                        <div class="chart-card-body" style="height:300px;">
                            <canvas id="chartMonthly"></canvas>
                        </div>
                    </div>
                </div>

            </div>

        </div>



        <!-- =====================================================
             APPOINTMENT STATUS + FORECAST
        ====================================================== -->

        <div class="row g-4">


            <!-- APPOINTMENT STATUS -->

            <div class="col-12" id="statusCol" style="display:none;">

                <div class="dashboard-card">


                    <div class="card-top">

                        <h3>
                            Appointment Status
                        </h3>


                        <div class="d-flex align-items-center gap-3">

                            <span class="live-status">

                                <span class="live-dot"></span>

                                Live

                            </span>


                            <a
                                href="appointments.php"
                                target="_self"
                                rel="noopener noreferrer"
                            >

                                View Appointments

                                <i
                                    class="bi bi-arrow-right"
                                ></i>

                            </a>

                        </div>

                    </div>


                    <div class="card-body-custom">

                        <div class="status-grid">


                            <!-- PENDING -->

                            <div class="status-box">

                                <strong>
                                    <?= $dashboardCounts['pending'] ?>
                                </strong>

                                <span>

                                    <i
                                        class="status-dot dot-orange"
                                    ></i>

                                    Pending

                                </span>

                            </div>


                            <!-- COMPLETED -->

                            <div class="status-box">

                                <strong>
                                    <?= $dashboardCounts['completed'] ?>
                                </strong>

                                <span>

                                    <i
                                        class="status-dot dot-green"
                                    ></i>

                                    Completed

                                </span>

                            </div>


                            <!-- CANCELLED -->

                            <div class="status-box">

                                <strong>
                                    <?= $dashboardCounts['cancelled'] ?>
                                </strong>

                                <span>

                                    <i
                                        class="status-dot dot-red"
                                    ></i>

                                    Cancelled

                                </span>

                            </div>

                        </div>

                    </div>

                </div>

            </div>



            <!-- =================================================
                 FORECAST
            ================================================== -->

            <div class="col-12" id="forecastSummaryCol" style="display:none;">

                <div class="dashboard-card" style="border-radius:8px;">


                    <div class="card-top">

                        <h3>
                            Appointment Demand Forecast
                        </h3>

                        <div class="d-flex align-items-center gap-2">
                            <span
                                class="forecast-label"
                            >
                                Next 7 Days
                            </span>

                            <a
                                href="reports.php"
                                target="_self"
                                rel="noopener noreferrer"
                                class="forecast-label"
                                style="text-decoration:none;border:1px solid #808080;border-radius:20px;padding:8px 16px;background:#EAF7F0;"
                            >
                                Open Forecast
                                <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>

                    </div>


                    <div class="card-body-custom">


                        <?php if (empty($forecastData)): ?>


                            <div class="empty-state">

                                <div class="empty-icon">

                                    <i
                                        class="bi bi-graph-up"
                                    ></i>

                                </div>


                                <strong>
                                    No forecast available yet
                                </strong>


                                <span>

                                    The Linear Regression model
                                    needs appointment history
                                    before it can estimate demand.

                                </span>

                            </div>


                        <?php else: ?>


                            <!-- OVERALL DEMAND -->

                            <div class="forecast-summary">

                                <div class="forecast-number">

                                    <?= adminDashboardEscape(
                                        $overallDemandLevel
                                    ) ?>

                                </div>


                                <div>

                                    <strong>
                                        Expected demand
                                    </strong>

                                    <span>

                                        Average expected appointments:

                                        <?= number_format(
                                            $forecastAverage,
                                            1
                                        ) ?>

                                        per day

                                    </span>

                                </div>

                            </div>


                            <!-- META -->

                            <div class="forecast-meta">


                                <div class="forecast-meta-box">

                                    <small>
                                        7-DAY DEMAND
                                    </small>

                                    <strong>

                                        <?= $forecastTotal ?>

                                        appointments

                                    </strong>

                                </div>


                                <div class="forecast-meta-box">

                                    <small>
                                        ALREADY BOOKED
                                    </small>

                                    <strong>

                                        <?= $totalUpcomingBooked ?>

                                        appointments

                                    </strong>

                                </div>

                            </div>


                            <!-- HIGHEST DEMAND -->

                            <?php if ($highestDemandDate): ?>

                                <div class="planning-box">

                                    <i
                                        class="bi bi-exclamation-triangle-fill"
                                    ></i>

                                    <div>

                                        <strong>
                                            High-Demand Planning
                                        </strong>

                                        <span>

                                            Expected highest demand:

                                            <b>
                                                <?= adminDashboardEscape(
                                                    $highestDemandDate['day_name']
                                                ) ?>
                                            </b>

                                            (
                                            <?= adminDashboardEscape(
                                                $highestDemandDate['formatted_date']
                                            ) ?>
                                            )

                                            with approximately

                                            <b>
                                                <?= (int)$highestDemandDate['prediction'] ?>
                                            </b>

                                            appointments.

                                        </span>

                                    </div>

                                </div>

                            <?php endif; ?>


                            <!-- MODEL -->

                            <div class="model-info">

                                <div class="model-info-title">

                                    <i
                                        class="bi bi-cpu-fill"
                                    ></i>

                                    MACHINE LEARNING MODEL

                                </div>


                                <div class="model-info-row">

                                    <span>
                                        Model
                                    </span>

                                    <strong>
                                        <?= adminDashboardEscape(
                                            $modelStatus
                                        ) ?>
                                    </strong>

                                </div>


                                <div class="model-info-row">

                                    <span>
                                        Historical Data
                                    </span>

                                    <strong>
                                        <?= $historicalDays ?>
                                        days
                                    </strong>

                                </div>


                                <div class="model-info-row">

                                    <span>
                                        Historical Appointments
                                    </span>

                                    <strong>
                                        <?= $historicalAppointments ?>
                                    </strong>

                                </div>


                                <div class="model-info-row">

                                    <span>
                                        Upcoming Bookings
                                    </span>

                                    <strong>
                                        <?= $totalUpcomingBooked ?>
                                    </strong>

                                </div>


                                <div class="model-info-row">

                                    <span>
                                        Forecast Period
                                    </span>

                                    <strong>
                                        Next 7 Days
                                    </strong>

                                </div>

                            </div>


                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </div>



        <!-- =====================================================
             FORECAST DETAILS
        ====================================================== -->

        <div class="dashboard-card recent-card" id="forecastDetailsCard" style="display:none;border-radius:8px;">


            <div class="card-top">

                <h3>
                    Forecasted Appointment Demand
                </h3>


                <span class="forecast-label">

                    <i class="bi bi-cpu"></i>

                    Linear Regression

                </span>

            </div>


            <div class="card-body-custom demand-days">


                <?php if (empty($forecastData)): ?>


                    <div class="empty-state">

                        <div class="empty-icon">

                            <i
                                class="bi bi-bar-chart-line"
                            ></i>

                        </div>


                        <strong>
                            No forecast available yet
                        </strong>


                        <span>

                            Historical appointment records
                            are required for forecasting.

                        </span>

                    </div>


                <?php else: ?>


                    <?php foreach (
                        $forecastData
                        as $forecast
                    ): ?>


                        <div class="demand-day">


                            <div class="demand-date">


                                <div class="demand-date-icon">

                                    <i
                                        class="bi bi-calendar3"
                                    ></i>

                                </div>


                                <div>

                                    <strong>

                                        <?= adminDashboardEscape(
                                            $forecast['day_name']
                                        ) ?>

                                    </strong>


                                    <span>

                                        <?= adminDashboardEscape(
                                            $forecast['formatted_date']
                                        ) ?>

                                    </span>

                                </div>

                            </div>


                            <div class="demand-right">


                                <span class="demand-count">

                                    <?= (int)$forecast['prediction'] ?>

                                    appointment<?=

                                        $forecast['prediction'] == 1
                                            ? ''
                                            : 's'

                                    ?>

                                </span>


                                <?php if (
                                    $forecast['actual_booked'] > 0
                                ): ?>

                                    <span class="demand-booked">

                                        <?= (int)$forecast['actual_booked'] ?>

                                        already booked

                                    </span>

                                <?php endif; ?>


                                <span
                                    class="
                                        demand-badge
                                        <?= adminDashboardEscape(
                                            $forecast['demand_class']
                                        )
                                    ?>"
                                >

                                    <i
                                        class="<?= adminDashboardEscape(
                                            $forecast['demand_icon']
                                        ) ?>"
                                    ></i>

                                    <?= adminDashboardEscape(
                                        $forecast['demand_level']
                                    ) ?>

                                </span>

                            </div>

                        </div>


                    <?php endforeach; ?>


                    <div class="planning-box">

                        <i
                            class="bi bi-lightbulb-fill"
                        ></i>

                        <div>

                            <strong>
                                Admin Planning Recommendation
                            </strong>

                            <span>

                                The Linear Regression model estimates
                                future appointment demand using historical
                                appointment records.

                                Existing active patient bookings are also
                                included in the displayed demand so the
                                administrator can monitor actual bookings
                                together with the forecast.

                                The forecast is for planning purposes only.
                                The system will not automatically schedule
                                or reschedule patients based on this forecast.

                            </span>

                        </div>

                    </div>


                <?php endif; ?>

            </div>

        </div>



        <!-- =====================================================
             RECENT APPOINTMENTS
        ====================================================== -->

        <div class="dashboard-card recent-card" style="display:none;">


            <div class="card-top">


                <h3>
                    Recent Appointments
                </h3>


                <div class="d-flex align-items-center gap-3">


                    <span class="live-status">

                        <span class="live-dot"></span>

                        Automatically updated

                    </span>


                    <a
                        href="appointments.php"
                        target="_self"
                        rel="noopener noreferrer"
                    >

                        View All

                        <i
                            class="bi bi-arrow-right"
                        ></i>

                    </a>

                </div>

            </div>


            <div class="table-wrapper">


                <table class="appointment-table">


                    <thead>

                        <tr>

                            <th>
                                Patient
                            </th>

                            <th>
                                Dentist
                            </th>

                            <th>
                                Date
                            </th>

                            <th>
                                Time
                            </th>

                            <th>
                                Service
                            </th>

                            <th>
                                Status
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php if (
                        empty($recentAppointments)
                    ): ?>


                        <tr>

                            <td
                                colspan="6"
                            >

                                <div class="empty-state">

                                    <div class="empty-icon">

                                        <i
                                            class="bi bi-calendar-x"
                                        ></i>

                                    </div>


                                    <strong>
                                        No appointments yet
                                    </strong>


                                    <span>

                                        When a patient books an
                                        appointment, it will automatically
                                        appear here.

                                    </span>

                                </div>

                            </td>

                        </tr>


                    <?php else: ?>


                        <?php foreach (
                            $recentAppointments
                            as $appointment
                        ): ?>


                            <?php

                            /*
                            |--------------------------------------------------------------------------
                            | PATIENT NAME
                            |--------------------------------------------------------------------------
                            */

                            $patientName =
                                trim(
                                    $appointment['patient_name']
                                    ?? ''
                                );


                            if ($patientName === '') {

                                $patientName =
                                    'Patient #' .
                                    (
                                        $appointment['patient_id']
                                        ?? '-'
                                    );
                            }


                            /*
                            |--------------------------------------------------------------------------
                            | DENTIST NAME
                            |--------------------------------------------------------------------------
                            */

                            $dentistName =
                                trim(
                                    $appointment['dentist_name']
                                    ?? ''
                                );


                            if ($dentistName === '') {

                                if (
                                    !empty(
                                        $appointment['dentist_id']
                                    )
                                ) {

                                    $dentistName =
                                        'Dentist #' .
                                        $appointment['dentist_id'];

                                } else {

                                    $dentistName =
                                        'Not assigned';
                                }
                            }


                            /*
                            |--------------------------------------------------------------------------
                            | SERVICE
                            |--------------------------------------------------------------------------
                            */

                            $service =
                                trim(
                                    $appointment['service_type']
                                    ?? ''
                                );


                            if ($service === '') {

                                $service =
                                    'Dental Service';
                            }


                            /*
                            |--------------------------------------------------------------------------
                            | STATUS
                            |--------------------------------------------------------------------------
                            */

                            $status =
                                trim(
                                    $appointment['status']
                                    ?? 'Pending'
                                );


                            $badgeClass =
                                getStatusBadgeClass(
                                    $status
                                );


                            /*
                            |--------------------------------------------------------------------------
                            | DATE
                            |--------------------------------------------------------------------------
                            */

                            $appointmentDate =
                                '-';


                            if (
                                !empty(
                                    $appointment[
                                        'appointment_date'
                                    ]
                                )
                            ) {

                                $timestamp =
                                    strtotime(
                                        $appointment[
                                            'appointment_date'
                                        ]
                                    );


                                if ($timestamp !== false) {

                                    $appointmentDate =
                                        date(
                                            'M d, Y',
                                            $timestamp
                                        );
                                }
                            }


                            /*
                            |--------------------------------------------------------------------------
                            | TIME
                            |--------------------------------------------------------------------------
                            */

                            $appointmentTime =
                                '-';


                            if (
                                !empty(
                                    $appointment[
                                        'start_time'
                                    ]
                                )
                            ) {

                                $timestamp =
                                    strtotime(
                                        $appointment[
                                            'start_time'
                                        ]
                                    );


                                if ($timestamp !== false) {

                                    $appointmentTime =
                                        date(
                                            'h:i A',
                                            $timestamp
                                        );


                                    if (
                                        !empty(
                                            $appointment[
                                                'end_time'
                                            ]
                                        )
                                    ) {

                                        $endTimestamp =
                                            strtotime(
                                                $appointment[
                                                    'end_time'
                                                ]
                                            );


                                        if (
                                            $endTimestamp !== false
                                        ) {

                                            $appointmentTime .=
                                                ' - ' .
                                                date(
                                                    'h:i A',
                                                    $endTimestamp
                                                );
                                        }
                                    }
                                }
                            }

                            ?>


                            <tr>


                                <!-- PATIENT -->

                                <td>

                                    <div class="patient-cell">


                                        <div
                                            class="patient-avatar"
                                        >

                                            <i
                                                class="bi bi-person-fill"
                                            ></i>

                                        </div>


                                        <div>

                                            <strong>

                                                <?= adminDashboardEscape(
                                                    $patientName
                                                ) ?>

                                            </strong>


                                            <span>
                                                Patient
                                            </span>

                                        </div>

                                    </div>

                                </td>


                                <!-- DENTIST -->

                                <td>

                                    <?= adminDashboardEscape(
                                        $dentistName
                                    ) ?>

                                </td>


                                <!-- DATE -->

                                <td>

                                    <?= adminDashboardEscape(
                                        $appointmentDate
                                    ) ?>

                                </td>


                                <!-- TIME -->

                                <td>

                                    <?= adminDashboardEscape(
                                        $appointmentTime
                                    ) ?>

                                </td>


                                <!-- SERVICE -->

                                <td>

                                    <?= adminDashboardEscape(
                                        $service
                                    ) ?>

                                </td>


                                <!-- STATUS -->

                                <td>

                                    <span
                                        class="
                                            status-badge
                                            <?= adminDashboardEscape(
                                                $badgeClass
                                            )
                                        ?>"
                                    >

                                        <?= adminDashboardEscape(
                                            $status
                                        ) ?>

                                    </span>

                                </td>

                            </tr>


                        <?php endforeach; ?>


                    <?php endif; ?>


                    </tbody>

                </table>

            </div>

        </div>



        <!-- =====================================================
             FOOTER
        ====================================================== -->

        <div class="dashboard-footer">

            Abalos Dental Clinic

            &nbsp;â€¢&nbsp;

            Dental Appointment Management System

            &nbsp;â€¢&nbsp;

            Â© 2026

        </div>


    </div>

</main>

</div>



<script>

/*
|--------------------------------------------------------------------------
| MOBILE SIDEBAR
|--------------------------------------------------------------------------
*/

function toggleSidebar()
{
    const sidebar =
        document.getElementById("sidebar");

    if (!sidebar) {
        return;
    }

    sidebar.classList.toggle("show");
}


/*
|--------------------------------------------------------------------------
| CLOSE SIDEBAR OUTSIDE
|--------------------------------------------------------------------------
*/

document.addEventListener(
    "click",
    function(event)
    {

        const sidebar =
            document.getElementById("sidebar");

        const menuButton =
            document.querySelector(
                ".mobile-menu"
            );


        if (
            window.innerWidth <= 991 &&
            sidebar &&
            sidebar.classList.contains("show") &&
            !sidebar.contains(event.target) &&
            menuButton &&
            !menuButton.contains(event.target)
        ) {

            sidebar.classList.remove(
                "show"
            );
        }

    }
);


/*
|--------------------------------------------------------------------------
| CLOSE MOBILE SIDEBAR AFTER CLICK
|--------------------------------------------------------------------------
*/

document.querySelectorAll(
    ".sidebar-link"
).forEach(
    function(link)
    {

        link.addEventListener(
            "click",
            function()
            {

                if (
                    window.innerWidth <= 991
                ) {

                    const sidebar =
                        document.getElementById(
                            "sidebar"
                        );

                    if (sidebar) {

                        sidebar.classList.remove(
                            "show"
                        );
                    }
                }

            }
        );

    }
);


/*
|--------------------------------------------------------------------------
| FORECAST NEW TAB VIEW
|--------------------------------------------------------------------------
| Normal dashboard: forecast hidden, button only.
| New tab with #forecast-view: show same forecast content.
|--------------------------------------------------------------------------
*/

(function()
{
    function closeAdminTab()
    {
        try { window.close(); } catch (e) {}
        setTimeout(function()
        {
            try
            {
                if (!window.closed)
                {
                    window.open('', '_self');
                    window.close();
                }
            } catch (e2) {}
            setTimeout(function()
            {
                if (!window.closed)
                {
                    window.location.href = "dashboard.php";
                }
            }, 150);
        }, 200);
    }

    function bindAdminClose(el)
    {
        if (!el || el.dataset.closeBound === "1") return;
        el.dataset.closeBound = "1";
        el.addEventListener("click", function(e)
        {
            if (window.location.hash === "#notab")
            {
                e.preventDefault();
                closeAdminTab();
            }
        });
    }

    function hideSidebarForNewTab() {
        // Mananatiling visible ang sidebar sa kaliwa tulad sa dentist portal
        // Back to Dashboard sa new tab — isara ang tab
        var backs = document.querySelectorAll('a[href="dashboard.php"], a[href*="dashboard.php"]');
        backs.forEach(function(b){
            if (b.textContent && b.textContent.toLowerCase().indexOf("back to dashboard") !== -1) bindAdminClose(b);
        });
        document.addEventListener("click", function(ev)
        {
            var t = ev.target.closest('a[href*="dashboard.php"]');
            if (!t) return;
            if (!t.textContent || t.textContent.toLowerCase().indexOf("back to dashboard") === -1) return;
            if (window.location.hash === "#notab")
            {
                ev.preventDefault();
                closeAdminTab();
            }
        });
    }

    if (window.location.hash === "#notab") {
        hideSidebarForNewTab();
    }

    if (window.location.hash === "#forecast-view") {
        hideSidebarForNewTab();

        var summary = document.getElementById("forecastSummaryCol");
        var details = document.getElementById("forecastDetailsCard");
        var welcome = document.getElementById("welcomeBox");
        var stats = document.getElementById("statsGrid");
        var status = document.getElementById("statusCol");
        var charts = document.getElementById("chartsSection");

        if (welcome) {
            welcome.style.display = "none";
        }

        if (stats) {
            stats.style.display = "none";
        }

        if (status) {
            status.style.display = "none";
        }

        if (charts) {
            charts.style.display = "none";
        }

        if (summary) {
            summary.style.display = "";
        }

        if (details) {
            details.style.display = "";
        }

        setTimeout(function() {
            var target = summary || details;
            if (target && target.scrollIntoView) {
                target.scrollIntoView({ behavior: "smooth", block: "start" });
            }
        }, 300);
    }
})();


/*
|--------------------------------------------------------------------------
| DASHBOARD CHARTS (Chart.js)
|--------------------------------------------------------------------------
*/

(function initDashboardCharts() {
    if (typeof Chart === "undefined") {
        return;
    }

    Chart.defaults.font.family = "'Inter','DM Sans',system-ui,sans-serif";
    Chart.defaults.color = "#7e969f";
    Chart.defaults.font.size = 11;
    Chart.defaults.font.weight = 600;

    var tooltipStyle = {
        backgroundColor: "#022c22",
        titleColor: "#ffffff",
        bodyColor: "#d1fae5",
        padding: 12,
        cornerRadius: 10,
        displayColors: true,
        boxPadding: 4,
        titleFont: { weight: "700", size: 12 }
    };

    var trendLabels = <?= json_encode($chartTrendLabels) ?>;
    var trendData = <?= json_encode($chartTrendData) ?>;

    var statusLabels = <?= json_encode($chartStatusLabels) ?>;
    var statusData = <?= json_encode($chartStatusData) ?>;

    var monthlyLabels = <?= json_encode($chartMonthlyLabels) ?>;
    var monthlyData = <?= json_encode($chartMonthlyData) ?>;

    var forecastLabels = <?= json_encode($chartForecastLabels) ?>;
    var forecastML = <?= json_encode($chartForecastML) ?>;
    var forecastBooked = <?= json_encode($chartForecastBooked) ?>;

    // Pill totals (dental KPI style)
    var trendTotal = trendData.reduce(function(a, b){ return a + (parseInt(b, 10) || 0); }, 0);
    var trendPill = document.getElementById("trendTotalPill");
    if (trendPill) { trendPill.textContent = trendTotal + " visits"; }

    var statusTotal = statusData.reduce(function(a, b){ return a + (parseInt(b, 10) || 0); }, 0);
    var statusTotalEl = document.getElementById("statusTotal");
    if (statusTotalEl) { statusTotalEl.textContent = statusTotal; }

    var monthlyTotal = monthlyData.reduce(function(a, b){ return a + (parseInt(b, 10) || 0); }, 0);
    var monthlyPill = document.getElementById("monthlyTotalPill");
    if (monthlyPill) { monthlyPill.textContent = monthlyTotal + " total"; }

    var trendEl = document.getElementById("chartTrend");
    if (trendEl) {
        var tctx = trendEl.getContext("2d");
        var areaGrad = tctx.createLinearGradient(0, 0, 0, 280);
        areaGrad.addColorStop(0, "rgba(5,150,105,.32)");
        areaGrad.addColorStop(1, "rgba(5,150,105,0)");
        new Chart(trendEl, {
            type: "line",
            data: {
                labels: trendLabels,
                datasets: [{
                    label: "Patient visits",
                    data: trendData,
                    borderColor: "#059669",
                    backgroundColor: areaGrad,
                    fill: true,
                    tension: 0.45,
                    borderWidth: 3,
                    pointBackgroundColor: "#ffffff",
                    pointBorderColor: "#059669",
                    pointBorderWidth: 2.5,
                    pointRadius: 4.5,
                    pointHoverRadius: 7,
                    pointHoverBackgroundColor: "#059669",
                    pointHoverBorderColor: "#ffffff"
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: "index", intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: Object.assign({}, tooltipStyle, {
                        callbacks: {
                            label: function(c){ return " " + c.parsed.y + " appointment" + (c.parsed.y === 1 ? "" : "s"); }
                        }
                    })
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0, color: "#3f5a67", font: { size: 11, weight: "700" } }, grid: { color: "#edf3f4" }, border: { display: false }, title: { display: true, text: "No. of Visits", color: "#143f51", font: { size: 12, weight: "800" } } },
                    x: { ticks: { color: "#3f5a67", font: { size: 11, weight: "700" } }, grid: { display: false }, border: { display: false }, title: { display: true, text: "Date", color: "#143f51", font: { size: 12, weight: "800" } } }
                }
            }
        });
    }

    var statusEl = document.getElementById("chartStatus");
    /* Inline plugin: % sa loob ng slice (>=5%) + label na may linya sa labas
       (tulad sa image: "Pending 52 (78%)"). Walang bagong CDN. */
    var adminStatusPctPlugin = {
        id: "adminStatusPct",
        afterDraw: function(chart) {
            if (!chart || !chart.canvas || chart.canvas.id !== "chartStatus") return;
            var meta = chart.getDatasetMeta(0);
            if (!meta || !meta.data || !meta.data.length) return;
            var dataset = chart.data.datasets[0];
            var labels = chart.data.labels || [];
            var vals = dataset ? dataset.data || [] : [];
            var colors = dataset ? dataset.backgroundColor || [] : [];
            var total = vals.reduce(function(a, b){ return a + (parseInt(b, 10) || 0); }, 0);
            if (!total) return;
            var ctx = chart.ctx;

            /* Loob: % para sa malalaking slice */
            ctx.save();
            ctx.font = "700 11px Inter, sans-serif";
            ctx.fillStyle = "#ffffff";
            ctx.textAlign = "center";
            ctx.textBaseline = "middle";
            ctx.shadowColor = "rgba(0,0,0,0.35)";
            ctx.shadowBlur = 3;
            meta.data.forEach(function(arc, i){
                var v = parseInt(vals[i], 10) || 0;
                if (!v) return;
                var pct = (v / total) * 100;
                if (pct < 5) return;
                var p = arc.getProps(["x", "y", "startAngle", "endAngle", "outerRadius", "innerRadius"], true);
                var mid = (p.startAngle + p.endAngle) / 2;
                var r = (p.outerRadius + p.innerRadius) / 2;
                ctx.fillText(Math.round(pct) + "%", p.x + Math.cos(mid) * r, p.y + Math.sin(mid) * r);
            });
            ctx.restore();

            /* Labas: "Label count (pct%)" na may linyang nakaturo sa slice */
            ctx.save();
            ctx.font = "700 11px Inter, sans-serif";
            ctx.textBaseline = "middle";
            var items = [];
            meta.data.forEach(function(arc, i){
                var v = parseInt(vals[i], 10) || 0;
                if (!v) return;
                var p = arc.getProps(["x", "y", "startAngle", "endAngle", "outerRadius"], true);
                var mid = (p.startAngle + p.endAngle) / 2;
                var pct = Math.round((v / total) * 100);
                var text = (labels[i] || "") + " " + v + " (" + pct + "%)";
                var right = Math.cos(mid) >= 0;
                var sx = p.x + Math.cos(mid) * p.outerRadius;
                var sy = p.y + Math.sin(mid) * p.outerRadius;
                var ex = p.x + Math.cos(mid) * (p.outerRadius + 14);
                var ey = p.y + Math.sin(mid) * (p.outerRadius + 14);
                items.push({
                    right: right, sx: sx, sy: sy, ex: ex, ey: ey,
                    text: text, w: ctx.measureText(text).width,
                    color: colors[i] || "#059669"
                });
            });
            /* Iwas-sapaw ng labels sa magkabilang gilid */
            [true, false].forEach(function(side){
                var sideItems = items.filter(function(it){ return it.right === side; })
                    .sort(function(a, b){ return a.ey - b.ey; });
                var minGap = 26;
                var pass, k;
                for (pass = 0; pass < 3; pass++) {
                    for (k = 1; k < sideItems.length; k++) {
                        if (sideItems[k].ey - sideItems[k-1].ey < minGap) {
                            sideItems[k].ey = sideItems[k-1].ey + minGap;
                        }
                    }
                }
            });
            var pillH = 20, pillPad = 8;
            items.forEach(function(it){
                var pillW = it.w + pillPad * 2;
                var lineEndX = it.right ? it.ex + 8 : it.ex - 8;
                var px = it.right ? lineEndX : lineEndX - pillW;
                var py = it.ey - pillH / 2;
                ctx.strokeStyle = it.color;
                ctx.lineWidth = 1.5;
                ctx.beginPath();
                ctx.moveTo(it.sx, it.sy);
                ctx.lineTo(it.ex, it.ey);
                ctx.lineTo(lineEndX, it.ey);
                ctx.stroke();
                ctx.fillStyle = "#ffffff";
                ctx.lineWidth = 1.2;
                if (typeof ctx.roundRect === "function") {
                    ctx.beginPath();
                    ctx.roundRect(px, py, pillW, pillH, 8);
                    ctx.fill();
                    ctx.stroke();
                } else {
                    ctx.fillRect(px, py, pillW, pillH);
                    ctx.strokeRect(px, py, pillW, pillH);
                }
                ctx.fillStyle = "#143f51";
                ctx.textAlign = "left";
                ctx.fillText(it.text, px + pillPad, it.ey);
            });
            ctx.restore();
        }
    };
    if (statusEl) {
        new Chart(statusEl, {
            type: "doughnut",
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusData,
                    backgroundColor: ["#022c22", "#064e3b", "#059669", "#10b981", "#6ee7b7"],
                    hoverBackgroundColor: ["#022c22", "#064e3b", "#047857", "#059669", "#34d399"],
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
                layout: { padding: { left: 85, right: 85, top: 26, bottom: 12 } },
                onHover: function(evt, activeEls) {
                    var numEl = document.getElementById("statusTotal");
                    var labEl = document.getElementById("statusCenterLabel");
                    if (!numEl || !labEl) return;
                    if (activeEls && activeEls.length) {
                        var idx = activeEls[0].index;
                        var v = parseInt(statusData[idx], 10) || 0;
                        var pct = statusTotal > 0 ? Math.round((v / statusTotal) * 100) : 0;
                        numEl.textContent = pct + "%";
                        labEl.textContent = statusLabels[idx];
                    } else {
                        numEl.textContent = statusTotal;
                        labEl.textContent = "Total";
                    }
                },
                plugins: {
                    legend: { position: "bottom", labels: { boxWidth: 11, boxHeight: 11, borderRadius: 4, useBorderRadius: true, padding: 14, font: { size: 11, weight: "600" } } },
                    tooltip: Object.assign({}, tooltipStyle, {
                        callbacks: {
                            label: function(c){
                                var pct = statusTotal > 0 ? Math.round((c.parsed / statusTotal) * 100) : 0;
                                return " " + c.label + ": " + c.parsed + " (" + pct + "%)";
                            }
                        }
                    })
                }
            },
            plugins: [adminStatusPctPlugin]
        });
    }

    var forecastEl = document.getElementById("chartForecast");
    if (forecastEl) {
        new Chart(forecastEl, {
            type: "bar",
            data: {
                labels: forecastLabels,
                datasets: [
                    {
                        label: "AI prediction",
                        data: forecastML,
                        backgroundColor: "#0E6B5E",
                        hoverBackgroundColor: "#0A4A41",
                        borderRadius: 6,
                        borderSkipped: false,
                        maxBarThickness: 22,
                        categoryPercentage: 0.35,
                        barPercentage: 0.92
                    },
                    {
                        label: "Already booked",
                        data: forecastBooked,
                        backgroundColor: "#10B981",
                        hoverBackgroundColor: "#059669",
                        borderRadius: 6,
                        borderSkipped: false,
                        maxBarThickness: 22,
                        categoryPercentage: 0.35,
                        barPercentage: 0.92
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: "bottom",
                        labels: {
                            boxWidth: 12,
                            boxHeight: 12,
                            borderRadius: 4,
                            useBorderRadius: true,
                            padding: 16,
                            font: { family: "DM Sans, sans-serif", size: 12, weight: "600" },
                            color: "#143f51"
                        }
                    },
                    tooltip: tooltipStyle
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        stacked: false,
                        ticks: { stepSize: 1, precision: 0 },
                        grid: { color: "#edf3f4" },
                        border: { display: false },
                        title: { display: true, text: "No. of Appointments", color: "#143f51", font: { size: 12, weight: "800" } }
                    },
                    x: {
                        stacked: false,
                        grid: { display: false },
                        border: { display: false },
                        title: { display: true, text: "Day (Next 7 Days)", color: "#143f51", font: { size: 12, weight: "800" } }
                    }
                }
            }
        });
    }

    var monthlyEl = document.getElementById("chartMonthly");
    if (monthlyEl) {
        var mctx = monthlyEl.getContext("2d");
        var barGrad = mctx.createLinearGradient(0, 0, 0, 250);
        barGrad.addColorStop(0, "#10b981");
        barGrad.addColorStop(1, "#022c22");
        new Chart(monthlyEl, {
            type: "bar",
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: "Appointments",
                    data: monthlyData,
                    backgroundColor: barGrad,
                    hoverBackgroundColor: "#059669",
                    borderRadius: 10,
                    borderSkipped: false,
                    maxBarThickness: 52
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: Object.assign({}, tooltipStyle, {
                        callbacks: {
                            label: function(c){ return " " + c.parsed.y + " appointments"; }
                        }
                    })
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, grid: { color: "#edf3f4" }, border: { display: false }, title: { display: true, text: "No. of Appointments", color: "#143f51", font: { size: 12, weight: "800" } } },
                    x: { grid: { display: false }, border: { display: false }, title: { display: true, text: "Month", color: "#143f51", font: { size: 12, weight: "800" } } }
                }
            }
        });
    }
})();


/*
|--------------------------------------------------------------------------
| CLINIC ANALYTICS ACCORDION
|--------------------------------------------------------------------------
|
| Bawat box sa Clinic Analytics ay accordion â€” isa lang naka-bukas.
| Naka-save sa sessionStorage kaya hindi nawawala kahit mag-reload
| ang dashboard tuwing 10 seconds.
|
|--------------------------------------------------------------------------
*/

(function initAnalyticsAccordion() {

    var STORAGE_KEY = "adminAnalyticsOpenBox";
    var OPEN_MS = 320;

    var cards = Array.prototype.slice.call(
        document.querySelectorAll("#chartsSection .chart-card")
    );

    if (!cards.length) {
        return;
    }

    function resizeCharts(card) {
        if (typeof Chart === "undefined" || typeof Chart.getChart !== "function") {
            return;
        }
        Array.prototype.forEach.call(card.querySelectorAll("canvas"), function (cv) {
            var inst = Chart.getChart(cv);
            if (inst) { inst.resize(); }
        });
    }

    function setState(card, open, instant) {
        var panel = card.querySelector(".chart-panel");
        var toggle = card.querySelector(".chart-toggle");
        if (!panel || !toggle) { return; }

        toggle.setAttribute("aria-expanded", open ? "true" : "false");
        card.classList.toggle("is-open", open);

        if (open) {
            if (instant) {
                panel.style.height = "auto";
                resizeCharts(card);
                return;
            }
            panel.style.height = panel.scrollHeight + "px";
            window.setTimeout(function () {
                if (!card.classList.contains("is-open")) { return; }
                panel.style.height = "auto";
                resizeCharts(card);
            }, OPEN_MS);
        } else {
            if (instant) {
                panel.style.height = "0px";
                return;
            }
            panel.style.height = panel.scrollHeight + "px";
            void panel.offsetHeight;
            panel.style.height = "0px";
        }
    }

    function closeAll(except) {
        cards.forEach(function (card) {
            if (card !== except && card.classList.contains("is-open")) {
                setState(card, false);
            }
        });
    }

    function remember(card) {
        try {
            if (card.classList.contains("is-open")) {
                sessionStorage.setItem(STORAGE_KEY, card.id);
            } else {
                sessionStorage.removeItem(STORAGE_KEY);
            }
        } catch (e) {}
    }

    function openCard(card, save, instant) {
        closeAll(card);
        setState(card, true, instant === true);
        if (save !== false) { remember(card); }
    }

    function closeCard(card, save) {
        setState(card, false);
        if (save !== false) { remember(card); }
    }

    cards.forEach(function (card) {
        var toggle = card.querySelector(".chart-toggle");
        if (!toggle) { return; }

        toggle.addEventListener("click", function () {
            if (card.classList.contains("is-open")) {
                closeCard(card);
            } else {
                openCard(card);
            }
        });

        toggle.addEventListener("keydown", function (ev) {
            if (ev.key !== "Enter" && ev.key !== " " && ev.key !== "Spacebar") { return; }
            ev.preventDefault();
            toggle.click();
        });
    });

    var saved = null;
    try { saved = sessionStorage.getItem(STORAGE_KEY); } catch (e) {}

    if (saved) {
        var target = document.getElementById(saved);
        if (target && target.classList.contains("chart-card")) {
            openCard(target, false, true);
        }
    }

})();


/*
|--------------------------------------------------------------------------
| AUTOMATIC DASHBOARD UPDATE
|--------------------------------------------------------------------------
|
| Every 10 seconds the dashboard reloads.
|
| Example:
|
| Patient books appointment
|        â†“
| appointments table gets new record
|        â†“
| Admin dashboard reloads
|        â†“
| Recent Appointments gets new record
|        â†“
| Pending count increases
|        â†“
| Forecast updates
|
|--------------------------------------------------------------------------
*/

(function initCardDetailViews() {
    var VIEWS = ["today","pending","confirmed","completed","queue","forecast"];
    var KEY = "adminCardDetailView";
    var wrap = document.getElementById("cardDetailWrap");
    if (!wrap) return;
    var cards = Array.prototype.slice.call(document.querySelectorAll(".stat-clickable[data-view]"));
    var statsGrid = document.getElementById("statsGrid");
    var welcomeBox = document.getElementById("welcomeBox");
    var forecastChart = null;

    function panels() {
        return VIEWS.map(function(v){ return document.getElementById("detail-" + v); }).filter(Boolean);
    }
    function getNav() { return document.getElementById("detailNav"); }
    function hidePanelsOnly() {
        panels().forEach(function(p){ p.style.display = "none"; });
        cards.forEach(function(c){ c.classList.remove("active-card"); });
        // Kasunod ang back button: nasa labas ng panel, kaya hiwalay ding itinatago.
        document.querySelectorAll(".detail-back-row").forEach(function(r){ r.classList.remove("is-open"); });
    }
    function showBackRowFor(panel) {
        document.querySelectorAll(".detail-back-row").forEach(function(r){
            var match = r.getAttribute("data-for") === panel.id;
            r.classList.toggle("is-open", match);
        });
    }
    function drawForecastChart() {
        var cv = document.getElementById("cardForecastChart");
        if (!cv || typeof Chart === "undefined") return;
        if (forecastChart) { try { forecastChart.destroy(); } catch(e){} forecastChart = null; }
        var labels = [];
        var totals = [];
        var mlPreds = [];
        var booked = [];
        try {
            var fd = <?= json_encode(array_map(function($f){ return ['d' => (new DateTime($f['date']))->format('M d'), 'p' => (int)($f['prediction'] ?? 0), 'ml' => (int)($f['ml_prediction'] ?? 0), 'b' => (int)($f['actual_booked'] ?? 0)]; }, $forecastData)) ?>;
            fd.forEach(function(r){ labels.push(r.d); totals.push(r.p); mlPreds.push(r.ml); booked.push(r.b); });
        } catch(e){}
        if (!labels.length) return;
        var ctx = cv.getContext("2d");
        var g = ctx.createLinearGradient(0,0,0,240);
        g.addColorStop(0,"rgba(20,184,166,.35)");
        g.addColorStop(1,"rgba(20,184,166,0)");
        forecastChart = new Chart(cv, {
            type: "line",
            data: {
                labels: labels,
                datasets: [
                    { label: "Total Forecasted Demand", data: totals, borderColor: "#0d9488", backgroundColor: g, fill: true, tension: .4, borderWidth: 3, pointBackgroundColor: "#fff", pointBorderColor: "#0d9488", pointBorderWidth: 2.5, pointRadius: 4.5, pointHoverRadius: 6 },
                    { label: "AI Linear Regression", data: mlPreds, borderColor: "#059669", backgroundColor: "transparent", borderDash: [5, 5], fill: false, tension: .4, borderWidth: 2, pointRadius: 3.5 },
                    { label: "Already Booked", data: booked, borderColor: "#f59e0b", backgroundColor: "transparent", fill: false, tension: .4, borderWidth: 2, pointRadius: 3.5 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true, position: "top" } }, scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, grid: { color: "#edf3f4" }, border: { display: false } },
                    x: { grid: { display: false }, border: { display: false } }
                } }
        });
    }
    function show(view, scroll, fromNav) {
        var panel = document.getElementById("detail-" + view);
        if (!panel) return;
        var alreadyOpen = panel.style.display !== "none";
        var nav = getNav();
        // Pag pinindot ulit ang parehong card: toggle (isara) para bumalik sa summary cards view.
        // Galing sa loob na nav at bukas na: stay lang.
        if (alreadyOpen) {
            if (fromNav) {
                if (nav) {
                    nav.querySelectorAll(".detail-nav-card").forEach(function(n){ n.classList.toggle("active-nav", n.getAttribute("data-goto") === view); });
                }
                return;
            }
            closeAll();
            if (scroll !== false && statsGrid && statsGrid.scrollIntoView) setTimeout(function(){ statsGrid.scrollIntoView({ behavior: "smooth", block: "start" }); }, 80);
            return;
        }
        // 1. Itago lahat ng detail panels
        hidePanelsOnly();
        // 2. Ipakita ang piniling panel
        panel.style.display = "block";
        // 2b. Ipakita ang katugmang Back button (nasa labas ng box).
        showBackRowFor(panel);
        // 3. PAWALAIN ang labas na summary cards + welcome banner habang bukas ang detail.
        //    Walang summary row na maiiwan — tanging ang detail panel lang ang kita.
        if (statsGrid) statsGrid.style.display = "none";
        if (welcomeBox) welcomeBox.style.display = "none";
        cards.forEach(function(c){ c.classList.toggle("active-card", c.getAttribute("data-view") === view); });
        // Loob na nav: lahat ng summary cards MALIBAN sa kasalukuyang bukas.
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
        if (view === "forecast") setTimeout(drawForecastChart, 60);
        // Scroll sa wrap (kasama ang loob na nav) para kita agad ang lipatan ng summary sa loob
        if (scroll !== false && wrap.scrollIntoView) setTimeout(function(){ wrap.scrollIntoView({ behavior: "smooth", block: "start" }); }, 80);
    }
    function closeAll() {
        hidePanelsOnly();
        // Pag close/back: ibalik ang labas na summary + welcome
        if (statsGrid) statsGrid.style.display = "grid";
        if (welcomeBox) welcomeBox.style.display = "";
        var nav2 = getNav();
        if (nav2) {
            nav2.style.display = "none";
            nav2.querySelectorAll(".detail-nav-card").forEach(function(n){ n.classList.remove("active-nav"); n.style.display = ""; });
        }
        try { sessionStorage.removeItem(KEY); } catch(e){}
        try { history.replaceState(null, "", window.location.pathname + window.location.search); } catch(e){}
        if (statsGrid && statsGrid.scrollIntoView) setTimeout(function(){ statsGrid.scrollIntoView({ behavior: "smooth", block: "start" }); }, 80);
    }
    // "Back to Dashboard" — NASA LABAS ng card box, sa itaas nito.
    // Isa bawat detail panel. Ang dating guard ay naghahanap sa parentNode,
    // pero magkakapatid ang lahat ng panel sa #cardDetailWrap — kaya 5/6 lang
    // ang nagkakaroon ng button. Dito, SARILING panel ang tinatseke.
    document.querySelectorAll(".card-detail").forEach(function(panel){
        if (panel.querySelector(".detail-back-btn")) return;
        if (panel.parentNode && panel.parentNode.querySelector(".detail-back-row[data-for='" + panel.id + "']")) return;

        var backBtn = document.createElement("button");
        backBtn.type = "button";
        backBtn.className = "detail-back-btn";
        backBtn.innerHTML = '<i class="bi bi-arrow-left"></i> Back to Dashboard';
        backBtn.addEventListener("click", closeAll);

        var wrap = document.createElement("div");
        wrap.className = "detail-back-row";
        wrap.setAttribute("data-for", panel.id);
        wrap.appendChild(backBtn);

        if (panel.parentNode) panel.parentNode.insertBefore(wrap, panel);
    });
    // Loob na summary cards: lipat ng detail nang hindi nag-X
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
    document.querySelectorAll("[data-close-detail]").forEach(function(btn){
        btn.addEventListener("click", closeAll);
    });
    // Inner filter tabs + mini summary cards + 10-per-page Pagination
    var DETAIL_PER_PAGE = 10;

    function renderDetailTable(card, group, filter, page) {
        var f = (filter || card._currentFilter || "all").toLowerCase();
        var tbody = card.querySelector(".appointment-table tbody");
        if (!tbody) return;

        var rows = Array.prototype.slice.call(
            tbody.querySelectorAll("tr:not(.detail-empty-row):not(.js-filter-empty-row)")
        );
        var matched = [];

        rows.forEach(function(r){
            var keep = false;
            if (f === "all") {
                keep = true;
            } else if (group === "confirmed" || group === "completed") {
                if (f === "today") keep = r.getAttribute("data-today") === "1";
                else if (f === "week") keep = r.getAttribute("data-week") === "1";
                else if (f === "month") keep = r.getAttribute("data-month") === "1";
            } else {
                keep = (r.getAttribute("data-status") || "").toLowerCase() === f;
            }
            r.style.display = "none";
            if (keep) matched.push(r);
        });

        var total = matched.length;
        var totalPages = Math.max(1, Math.ceil(total / DETAIL_PER_PAGE));
        var curPage = parseInt(page, 10) || 1;
        if (curPage < 1) curPage = 1;
        if (curPage > totalPages) curPage = totalPages;

        card._currentFilter = f;
        card._currentPage = curPage;

        var startIdx = (curPage - 1) * DETAIL_PER_PAGE;
        var endIdx = Math.min(startIdx + DETAIL_PER_PAGE, total);

        for (var i = startIdx; i < endIdx; i++) {
            matched[i].style.display = "";
            var firstTd = matched[i].querySelector("td:first-child");
            if (firstTd) {
                firstTd.textContent = (i + 1);
            }
        }

        // Empty state kapag nag-filter at 0 ang tugma
        var filterEmptyRow = tbody.querySelector("tr.js-filter-empty-row");
        if (rows.length > 0) {
            if (total === 0) {
                if (!filterEmptyRow) {
                    var colCount = card.querySelectorAll(".appointment-table thead th").length || 6;
                    filterEmptyRow = document.createElement("tr");
                    filterEmptyRow.className = "js-filter-empty-row";
                    filterEmptyRow.innerHTML = '<td colspan="' + colCount + '"><div class="empty-state"><div class="empty-icon"><i class="bi bi-inbox"></i></div><strong>No matching records found.</strong></div></td>';
                    tbody.appendChild(filterEmptyRow);
                }
                filterEmptyRow.style.display = "";
            } else if (filterEmptyRow) {
                filterEmptyRow.style.display = "none";
            }
        }

        // Pagination bar (10 lists per page)
        var pager = card.querySelector(".detail-pagination");
        if (!pager) {
            var tableWrap = card.querySelector(".table-wrapper");
            if (tableWrap && tableWrap.parentNode) {
                pager = document.createElement("div");
                pager.className = "detail-pagination";
                tableWrap.parentNode.insertBefore(pager, tableWrap.nextSibling);
            }
        }
        if (!pager) return;

        if (total === 0) {
            pager.style.display = "none";
            pager.innerHTML = "";
            return;
        }

        pager.style.display = "flex";
        var fromNum = startIdx + 1;
        var toNum = endIdx;
        var labelUnit = (group === "queue") ? (total === 1 ? "patient" : "patients") : (total === 1 ? "appointment" : "appointments");

        var html = '<span class="detail-pagination-info">Showing <strong>' + fromNum + '</strong>&ndash;<strong>' + toNum + '</strong> of <strong>' + total + '</strong> ' + labelUnit + '</span>';

        if (totalPages > 1) {
            var pStart = Math.max(1, Math.min(curPage - 3, totalPages - 6));
            var pEnd = Math.min(totalPages, pStart + 6);
            pStart = Math.max(1, pEnd - 6);

            html += '<nav class="detail-page-notation" aria-label="Detail table pagination">';
            html += '<button type="button" class="detail-page-btn' + (curPage <= 1 ? ' disabled' : '') + '" data-page="' + (curPage - 1) + '"' + (curPage <= 1 ? ' disabled' : '') + ' aria-label="Previous page"><i class="bi bi-chevron-left"></i></button>';
            for (var p = pStart; p <= pEnd; p++) {
                html += '<button type="button" class="detail-page-btn' + (p === curPage ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
            }
            html += '<button type="button" class="detail-page-btn' + (curPage >= totalPages ? ' disabled' : '') + '" data-page="' + (curPage + 1) + '"' + (curPage >= totalPages ? ' disabled' : '') + ' aria-label="Next page"><i class="bi bi-chevron-right"></i></button>';
            html += '</nav>';
        }

        pager.innerHTML = html;
        pager.querySelectorAll("button.detail-page-btn[data-page]").forEach(function(btn){
            btn.addEventListener("click", function(){
                if (btn.disabled || btn.classList.contains("disabled")) return;
                var targetPage = parseInt(btn.getAttribute("data-page"), 10) || 1;
                renderDetailTable(card, group, card._currentFilter || "all", targetPage);
            });
        });
    }

    document.querySelectorAll(".card-detail").forEach(function(card){
        var tabWrap = card.querySelector(".detail-tabs");
        var miniWrap = card.querySelector(".detail-mini-grid[data-mini-group]");
        var group = "";
        if (tabWrap) group = tabWrap.getAttribute("data-tab-group") || "";
        else if (miniWrap) group = miniWrap.getAttribute("data-mini-group") || "";
        if (!group && card.id) group = card.id.replace("detail-", "");
        if (!card.querySelector(".appointment-table")) return;

        function syncActive(filter) {
            var f = (filter || "all").toLowerCase();
            if (tabWrap) {
                tabWrap.querySelectorAll(".detail-tab").forEach(function(t){
                    t.classList.toggle("active", (t.getAttribute("data-filter") || "all").toLowerCase() === f);
                });
            }
            if (miniWrap) {
                miniWrap.querySelectorAll(".detail-mini-clickable").forEach(function(m){
                    m.classList.toggle("active-mini", (m.getAttribute("data-filter") || "all").toLowerCase() === f);
                });
            }
        }
        if (tabWrap) {
            tabWrap.querySelectorAll(".detail-tab").forEach(function(tab){
                tab.addEventListener("click", function(){
                    var f = tab.getAttribute("data-filter") || "all";
                    syncActive(f);
                    renderDetailTable(card, group, f, 1);
                });
            });
        }
        if (miniWrap) {
            miniWrap.querySelectorAll(".detail-mini-clickable").forEach(function(mini){
                var go = function(){
                    var f = mini.getAttribute("data-filter") || "all";
                    syncActive(f);
                    renderDetailTable(card, group, f, 1);
                };
                mini.addEventListener("click", go);
                mini.addEventListener("keydown", function(ev){
                    if (ev.key === "Enter" || ev.key === " ") { ev.preventDefault(); go(); }
                });
            });
        }
        // Default: naka-highlight ang "All" at 10 rows per page
        syncActive("all");
        renderDetailTable(card, group, "all", 1);
    });
    // DEFAULT: lahat ng detail nakatago pag load — bubukas lang pag pinindot ang summary card.
    // Linisin ang lumang hash/session state para hindi kusa bumukas ang detail.
    try { sessionStorage.removeItem(KEY); } catch(e){}
    try {
        if (window.location.hash && window.location.hash.indexOf("#card-") === 0) {
            history.replaceState(null, "", window.location.pathname + window.location.search);
        }
    } catch(e){}
    closeAll();
})();

</script>

<style>
.detail-pagination {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    margin-top: 12px;
    background: #F6FBF8;
    border: 1px solid #E6F2EC;
    border-radius: 12px;
}
.detail-pagination-info {
    font-size: 13px;
    color: #3F525B;
    font-family: "Inter", system-ui, sans-serif;
    font-weight: 600;
}
.detail-page-notation {
    display: flex;
    align-items: center;
    gap: 6px;
}
.detail-page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    height: 34px;
    padding: 0 10px;
    border-radius: 9px;
    border: 1px solid #D1E7DD;
    background: #ffffff;
    color: #0E6B5E;
    font-size: 13px;
    font-weight: 700;
    font-family: "Inter", system-ui, sans-serif;
    cursor: pointer;
    transition: all 0.2s ease;
}
.detail-page-btn:hover:not(.disabled):not(:disabled) {
    background: #EAF7F0;
    border-color: #0E6B5E;
    color: #0E6B5E;
}
.detail-page-btn.active {
    background: linear-gradient(135deg, #0E6B5E, #0A4A41) !important;
    border-color: #0A4A41 !important;
    color: #ffffff !important;
    box-shadow: 0 3px 8px rgba(14, 107, 94, 0.25);
}
.detail-page-btn.disabled,
.detail-page-btn:disabled {
    opacity: 0.45;
    pointer-events: none;
    background: #f1f5f9;
    border-color: #e2e8f0;
    color: #94a3b8;
}
</style>

</body>

</html>
