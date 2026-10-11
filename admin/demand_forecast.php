<?php

require_once __DIR__ . '/../config.php';

/*
|--------------------------------------------------------------------------
| DEMAND FORECASTING
|--------------------------------------------------------------------------
| Machine Learning Model:
| Linear Regression
|
| Input:
|   - Historical daily appointment count
|   - Last 90 days
|
| Output:
|   - Next 7 days expected appointment demand
|
| This file does NOT create appointments.
| It only provides forecast information for the Admin Dashboard.
|--------------------------------------------------------------------------
*/


/**
 * Generate an array of dates.
 */
function generateDateRange(DateTime $start, DateTime $end): array
{
    $dates = [];

    $current = clone $start;

    while ($current <= $end) {
        $dates[] = $current->format('Y-m-d');
        $current->modify('+1 day');
    }

    return $dates;
}


/**
 * Perform simple Linear Regression.
 *
 * y = a + bx
 *
 * x = day number
 * y = appointment count
 */
function linearRegression(array $x, array $y): array
{
    $n = count($x);

    if ($n < 2) {
        return [
            'slope' => 0,
            'intercept' => 0
        ];
    }

    $sumX = array_sum($x);
    $sumY = array_sum($y);

    $sumXY = 0;
    $sumX2 = 0;

    for ($i = 0; $i < $n; $i++) {
        $sumXY += $x[$i] * $y[$i];
        $sumX2 += $x[$i] * $x[$i];
    }

    $denominator = ($n * $sumX2) - ($sumX * $sumX);

    if ($denominator == 0) {
        return [
            'slope' => 0,
            'intercept' => $sumY / $n
        ];
    }

    $slope = (
        ($n * $sumXY) -
        ($sumX * $sumY)
    ) / $denominator;

    $intercept = (
        $sumY -
        ($slope * $sumX)
    ) / $n;

    return [
        'slope' => $slope,
        'intercept' => $intercept
    ];
}


/**
 * Main forecast function.
 */
function getDemandForecast(PDO $pdo): array
{
    $result = [
        'success' => false,
        'historical_days' => 0,
        'forecast_days' => [],
        'highest_day' => null,
        'highest_demand' => 0,
        'historical_average' => 0,
        'model' => 'Linear Regression',
        'message' => ''
    ];

    try {

        /*
        |--------------------------------------------------------------------------
        | 1. Historical period (up to 90 days, starting from first active record)
        |--------------------------------------------------------------------------
        */

        $historicalEnd = new DateTime('today');

        $historicalStart = clone $historicalEnd;
        $historicalStart->modify('-89 days');

        $startDate = $historicalStart->format('Y-m-d');
        $endDate = $historicalEnd->format('Y-m-d');


        /*
        |--------------------------------------------------------------------------
        | 2. Get historical appointment counts
        |--------------------------------------------------------------------------
        |
        | Cancelled appointments are not considered demand.
        |
        */

        $sql = "
            SELECT
                appointment_date,
                COUNT(*) AS appointment_count
            FROM appointments
            WHERE appointment_date BETWEEN :start_date AND :end_date
            AND status NOT IN ('Cancelled', 'Canceled')
            GROUP BY appointment_date
            ORDER BY appointment_date ASC
        ";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


        /*
        |--------------------------------------------------------------------------
        | 3. Convert database result into date => count & weekday averages
        |--------------------------------------------------------------------------
        */

        $historicalCounts = [];
        $earliestActiveDate = null;

        foreach ($rows as $row) {
            $d = $row['appointment_date'];
            $historicalCounts[$d] = (int) $row['appointment_count'];
            if ($earliestActiveDate === null || $d < $earliestActiveDate) {
                $earliestActiveDate = $d;
            }
        }

        if ($earliestActiveDate !== null) {
            $earliestDt = new DateTime($earliestActiveDate);
            $minWindowDt = (clone $historicalEnd)->modify('-13 days');
            $effectiveStart = $earliestDt < $minWindowDt ? $earliestDt : $minWindowDt;
            if ($effectiveStart > $historicalStart) {
                $historicalStart = $effectiveStart;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | 4. Create active dataset & weekday seasonality profile
        |--------------------------------------------------------------------------
        */

        $dates = generateDateRange(
            $historicalStart,
            $historicalEnd
        );

        $x = [];
        $y = [];
        $weekdaySums = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];
        $weekdayCounts = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];

        $dayNumber = 1;

        foreach ($dates as $date) {
            $dow = (int) date('w', strtotime($date));
            $count = $historicalCounts[$date] ?? 0;

            if ($dow !== 0) {
                $x[] = $dayNumber;
                $y[] = $count;
                $dayNumber++;
            }

            $weekdaySums[$dow] += $count;
            $weekdayCounts[$dow]++;
        }


        /*
        |--------------------------------------------------------------------------
        | 5. Calculate historical average (across operating days)
        |--------------------------------------------------------------------------
        */

        $historicalTotal = array_sum($y);

        $historicalDays = count($y);

        $historicalAverage = $historicalDays > 0
            ? $historicalTotal / $historicalDays
            : 0;

        $result['historical_days'] = count($dates);
        $result['historical_average'] = $historicalAverage;


        /*
        |--------------------------------------------------------------------------
        | 6. Check if there is enough data
        |--------------------------------------------------------------------------
        */

        if ($historicalDays < 2) {

            $result['message'] =
                'Not enough historical data for forecasting.';

            return $result;
        }


        /*
        |--------------------------------------------------------------------------
        | 6B. Get upcoming already-booked appointments for next 7 days
        |--------------------------------------------------------------------------
        */

        $upcomingBooked = [];
        $upStmt = $pdo->query("
            SELECT appointment_date, COUNT(*) AS booked_count
            FROM appointments
            WHERE appointment_date >= CURDATE()
              AND appointment_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
              AND status NOT IN ('Cancelled', 'Canceled', 'No-Show', 'Completed')
            GROUP BY appointment_date
        ");
        foreach ($upStmt->fetchAll(PDO::FETCH_ASSOC) as $uRow) {
            $upcomingBooked[$uRow['appointment_date']] = (int) $uRow['booked_count'];
        }


        /*
        |--------------------------------------------------------------------------
        | 7. Train Linear Regression
        |--------------------------------------------------------------------------
        */

        $model = linearRegression($x, $y);

        $slope = $model['slope'];
        $intercept = $model['intercept'];


        /*
        |--------------------------------------------------------------------------
        | 8. Forecast next 7 days
        |--------------------------------------------------------------------------
        */

        $forecastStart = new DateTime('+1 day');

        $forecastDays = [];
        $futureOperatingOffset = 1;

        for ($i = 0; $i < 7; $i++) {

            $forecastDate = clone $forecastStart;

            if ($i > 0) {
                $forecastDate->modify("+{$i} days");
            }

            $dateStr = $forecastDate->format('Y-m-d');
            $dow = (int) $forecastDate->format('w');

            if ($dow === 0) {
                // Clinic is closed on Sundays
                $mlPrediction = 0;
            } else {
                $futureX = $historicalDays + $futureOperatingOffset;
                $futureOperatingOffset++;

                $regPrediction = max(0, $intercept + ($slope * $futureX));
                $dowAvg = $weekdayCounts[$dow] > 0
                    ? ($weekdaySums[$dow] / $weekdayCounts[$dow])
                    : $historicalAverage;

                $blended = ($regPrediction * 0.5) + ($dowAvg * 0.5);
                if ($historicalTotal > 0 && $blended < 1) {
                    $blended = max($dowAvg, $historicalAverage, 1);
                }
                $mlPrediction = (int) round($blended);
            }

            $booked = $upcomingBooked[$dateStr] ?? 0;
            $prediction = $mlPrediction + $booked;

            $forecastDays[] = [
                'date' => $dateStr,
                'day' => $forecastDate->format('l'),
                'formatted_date' => $forecastDate->format('M d, Y'),
                'ml_prediction' => $mlPrediction,
                'actual_booked' => $booked,
                'predicted_appointments' => $prediction,
                'demand_level' => 'Low'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | 9. Determine High / Medium / Low demand
        |--------------------------------------------------------------------------
        */

        $nonZeroPredictions = array_values(array_filter(
            array_column($forecastDays, 'predicted_appointments'),
            function ($v) { return $v > 0; }
        ));
        $forecastOperatingAvg = !empty($nonZeroPredictions)
            ? (array_sum($nonZeroPredictions) / count($nonZeroPredictions))
            : $historicalAverage;
        $baselineAvg = max($historicalAverage, $forecastOperatingAvg);

        foreach ($forecastDays as &$forecast) {

            $prediction =
                $forecast['predicted_appointments'];

            if ($prediction <= 0) {
                $forecast['demand_level'] = 'Low';
            } elseif ($baselineAvg <= 0) {

                if ($prediction >= 5) {
                    $forecast['demand_level'] = 'High';
                } elseif ($prediction >= 2) {
                    $forecast['demand_level'] = 'Medium';
                } else {
                    $forecast['demand_level'] = 'Low';
                }

            } else {

                $highThreshold =
                    $baselineAvg * 1.10;

                $mediumThreshold =
                    $baselineAvg * 0.78;

                if ($prediction >= $highThreshold) {

                    $forecast['demand_level'] = 'High';

                } elseif ($prediction >= $mediumThreshold) {

                    $forecast['demand_level'] = 'Medium';

                } else {

                    $forecast['demand_level'] = 'Low';
                }
            }
        }

        unset($forecast);


        /*
        |--------------------------------------------------------------------------
        | 10. Find highest expected demand
        |--------------------------------------------------------------------------
        */

        $highestDay = null;
        $highestDemand = 0;

        foreach ($forecastDays as $forecast) {

            if (
                $highestDay === null ||
                $forecast['predicted_appointments'] > $highestDemand
            ) {

                $highestDay = $forecast;

                $highestDemand =
                    $forecast['predicted_appointments'];
            }
        }


        /*
        |--------------------------------------------------------------------------
        | 11. Return results
        |--------------------------------------------------------------------------
        */

        $result['success'] = true;
        $result['forecast_days'] = $forecastDays;
        $result['highest_day'] = $highestDay;
        $result['highest_demand'] = $highestDemand;

        return $result;

    } catch (PDOException $e) {

        $result['message'] =
            'Unable to generate demand forecast.';

        return $result;
    }
}


/*
|--------------------------------------------------------------------------
| Execute forecast
|--------------------------------------------------------------------------
*/

$demandForecastResult = getDemandForecast($pdo);