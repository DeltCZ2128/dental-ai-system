<?php

require_once __DIR__ . '/../config.php';

$conn = $pdo;

/*
|--------------------------------------------------------------------------
| DENTIST ACCESS
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (strtolower(trim($_SESSION['role'] ?? '')) !== 'dentist') {
    header('Location: ../login.php');
    exit;
}

$dentist_id = function_exists('resolveDentistId')
    ? resolveDentistId((int)($_SESSION['user_id'] ?? 0), trim($_SESSION['full_name'] ?? ''))
    : 0;
if ($dentist_id <= 0) {
    $dentistLookup = $conn->prepare("SELECT id FROM dentists WHERE id = ? OR dentist_name = ? ORDER BY id = ? DESC LIMIT 1");
    $dentistLookup->execute([
        (int) $_SESSION['user_id'],
        trim($_SESSION['full_name'] ?? ''),
        (int) $_SESSION['user_id']
    ]);
    $dentist_id = (int) ($dentistLookup->fetchColumn() ?: 0);
}

$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

/*
|--------------------------------------------------------------------------
| CARD WHITELIST + PAGE COPY
|--------------------------------------------------------------------------
*/

$cardConfigs = [
    'today' => [
        'title'    => "Today's Appointments",
        'subtitle' => 'View and manage all appointments scheduled for today.',
        'empty'    => 'No appointments for today.',
        'family'   => 'status',
    ],
    'upcoming' => [
        'title'    => 'Upcoming Appointments',
        'subtitle' => 'View and manage all upcoming appointments.',
        'empty'    => 'No upcoming appointments.',
        'family'   => 'upcoming',
    ],
    'completed' => [
        'title'    => 'Completed Appointments',
        'subtitle' => 'View records of all completed appointments.',
        'empty'    => 'No completed appointments.',
        'family'   => 'time',
    ],
    'pending' => [
        'title'    => 'Pending Appointments',
        'subtitle' => 'View and manage all pending appointment requests.',
        'empty'    => 'No pending appointments.',
        'family'   => 'time',
    ],
    'confirmed' => [
        'title'    => 'Confirmed Appointments',
        'subtitle' => 'View and manage all confirmed appointments.',
        'empty'    => 'No confirmed appointments.',
        'family'   => 'time',
    ],
    'noshow' => [
        'title'    => 'No-Show Patients',
        'subtitle' => 'View records of patients who did not show up.',
        'empty'    => 'No no-show records.',
        'family'   => 'time',
    ],
];

$card = (string) ($_GET['card'] ?? '');

if (!isset($cardConfigs[$card])) {
    header('Location: dentist_dashboard.php');
    exit;
}

$cardConfig = $cardConfigs[$card];

/*
|--------------------------------------------------------------------------
| SCOPE (depende sa card)
|--------------------------------------------------------------------------
*/

$cardScopeSql = function ($key) use ($today) {
    switch ($key) {
        case 'today':
            return ['a.appointment_date = ?', [$today]];
        case 'upcoming':
            return ["a.appointment_date > ? AND a.status NOT IN ('Cancelled', 'Completed')", [$today]];
        case 'completed':
            return ["a.status = 'Completed'", []];
        case 'pending':
            return ["a.status = 'Pending'", []];
        case 'confirmed':
            return ["a.status IN ('Confirmed', 'Approved')", []];
        case 'noshow':
            return ["a.status = 'No-Show'", []];
    }

    return ['1 = 1', []];
};

$scope = $cardScopeSql($card);

/*
|--------------------------------------------------------------------------
| SUB-STAT COUNTS (single aggregate query)
|--------------------------------------------------------------------------
*/

$stats = [
    'total'     => 0,
    'confirmed' => 0,
    'pending'   => 0,
    'completed' => 0,
    'today'     => 0,
    'week'      => 0,
    'month'     => 0,
    'beyond'    => 0,
];

try {

    if ($cardConfig['family'] === 'status') {

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS total,
                COALESCE(SUM(a.status IN ('Confirmed', 'Approved')), 0) AS confirmed,
                COALESCE(SUM(a.status = 'Pending'), 0) AS pending,
                COALESCE(SUM(a.status = 'Completed'), 0) AS completed
            FROM appointments a
            WHERE a.dentist_id = ?
            AND ({$scope[0]})
        ");

        $stmt->execute(array_merge([$dentist_id], $scope[1]));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    } elseif ($cardConfig['family'] === 'upcoming') {

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS total,
                COALESCE(SUM(a.appointment_date >= ? AND a.appointment_date <= ?), 0) AS week,
                COALESCE(SUM(a.appointment_date >= ? AND a.appointment_date <= ?), 0) AS month,
                COALESCE(SUM(a.appointment_date > ?), 0) AS beyond
            FROM appointments a
            WHERE a.dentist_id = ?
            AND ({$scope[0]})
        ");

        $stmt->execute(array_merge(
            [$weekStart, $weekEnd, $monthStart, $monthEnd, $monthEnd],
            [$dentist_id],
            $scope[1]
        ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    } else {

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS total,
                COALESCE(SUM(a.appointment_date = ?), 0) AS today,
                COALESCE(SUM(a.appointment_date >= ? AND a.appointment_date <= ?), 0) AS week,
                COALESCE(SUM(a.appointment_date >= ? AND a.appointment_date <= ?), 0) AS month
            FROM appointments a
            WHERE a.dentist_id = ?
            AND ({$scope[0]})
        ");

        $stmt->execute(array_merge(
            [$today, $weekStart, $weekEnd, $monthStart, $monthEnd],
            [$dentist_id],
            $scope[1]
        ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    }

    foreach ($stats as $statsKey => $statsValue) {
        $stats[$statsKey] = (int) ($row[$statsKey] ?? 0);
    }

} catch (PDOException $e) {
    $stats = array_map(function () {
        return 0;
    }, $stats);
}

/*
|--------------------------------------------------------------------------
| SUB-STAT CARDS + FILTER TABS
|--------------------------------------------------------------------------
*/

if ($card === 'today') {

    $subStats = [
        ['label' => 'Total Appointments', 'value' => $stats['total']],
        ['label' => 'Confirmed', 'value' => $stats['confirmed']],
        ['label' => 'Pending', 'value' => $stats['pending']],
        ['label' => 'Completed', 'value' => $stats['completed']],
    ];

    $tabs = [
        ['label' => 'All', 'count' => $stats['total'], 'type' => 'all', 'value' => ''],
        ['label' => 'Confirmed', 'count' => $stats['confirmed'], 'type' => 'status', 'value' => 'confirmed'],
        ['label' => 'Pending', 'count' => $stats['pending'], 'type' => 'status', 'value' => 'pending'],
        ['label' => 'Completed', 'count' => $stats['completed'], 'type' => 'status', 'value' => 'completed'],
    ];

} elseif ($card === 'upcoming') {

    $subStats = [
        ['label' => 'Total Upcoming', 'value' => $stats['total']],
        ['label' => 'This Week', 'value' => $stats['week']],
        ['label' => 'This Month', 'value' => $stats['month']],
        ['label' => 'Beyond Month', 'value' => $stats['beyond']],
    ];

    $tabs = [
        ['label' => 'All', 'count' => $stats['total'], 'type' => 'all', 'value' => ''],
        ['label' => 'This Week', 'count' => $stats['week'], 'type' => 'time', 'value' => 'week'],
        ['label' => 'This Month', 'count' => $stats['month'], 'type' => 'time', 'value' => 'month'],
        ['label' => 'Beyond Month', 'count' => $stats['beyond'], 'type' => 'time', 'value' => 'beyond'],
    ];

} else {

    $totalLabel = $card === 'noshow' ? 'Total No-Show' : 'Total ' . $cardConfig['title'];

    if ($card === 'pending') {
        $totalLabel = 'Total Pending';
    } elseif ($card === 'confirmed') {
        $totalLabel = 'Total Confirmed';
    } elseif ($card === 'completed') {
        $totalLabel = 'Total Completed';
    }

    $subStats = [
        ['label' => $totalLabel, 'value' => $stats['total']],
        ['label' => 'Today', 'value' => $stats['today']],
        ['label' => 'This Week', 'value' => $stats['week']],
        ['label' => 'This Month', 'value' => $stats['month']],
    ];

    $tabs = [
        ['label' => 'All', 'count' => $stats['total'], 'type' => 'all', 'value' => ''],
        ['label' => 'Today', 'count' => $stats['today'], 'type' => 'time', 'value' => 'today'],
        ['label' => 'This Week', 'count' => $stats['week'], 'type' => 'time', 'value' => 'week'],
        ['label' => 'This Month', 'count' => $stats['month'], 'type' => 'time', 'value' => 'month'],
    ];

}

/*
|--------------------------------------------------------------------------
| TABLE ROWS
|--------------------------------------------------------------------------
*/

$appointments = [];

try {

    $orderClause = $card === 'today'
        ? 'ORDER BY a.appointment_date ASC, a.start_time ASC'
        : 'ORDER BY a.appointment_date DESC, a.start_time DESC';

    $stmt = $conn->prepare("
        SELECT
            a.appointment_date,
            a.start_time AS appointment_time,
            a.service_type,
            a.status,
            CONCAT(p.first_name, ' ', p.last_name) AS patient_name
        FROM appointments a
        INNER JOIN patients p
            ON a.patient_id = p.id
        WHERE a.dentist_id = ?
        AND ({$scope[0]})
        {$orderClause}
        LIMIT 100
    ");

    $stmt->execute(array_merge([$dentist_id], $scope[1]));
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $appointments = [];
}

function cardStatusKey($status)
{
    $key = strtolower(trim((string) ($status ?? '')));

    if ($key === 'approved') {
        $key = 'confirmed';
    } elseif ($key === 'canceled') {
        $key = 'cancelled';
    } elseif ($key === 'no show') {
        $key = 'no-show';
    }

    if (!in_array($key, ['confirmed', 'pending', 'completed', 'cancelled', 'no-show'], true)) {
        $key = 'pending';
    }

    return $key;
}

function esc($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$emptyIcon = $card === 'noshow' ? 'bi bi-person-x' : 'bi bi-calendar-x';

?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title><?= esc($cardConfig['title']) ?> — Dentist Portal</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700;800&family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: "Inter","DM Sans",system-ui,sans-serif;
        }

        body {
            background: #f4f7fb;
            color: #0f2f3d;
            min-height: 100vh;
        }

        .page {
            max-width: 1100px;
            margin: 0 auto;
            padding: 28px 20px 48px;
        }

        /* ===== Breadcrumb ===== */

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-wrap: wrap;
            font-size: 12px;
            font-weight: 700;
            color: #7b94a0;
            margin-bottom: 14px;
        }

        .breadcrumb a {
            color: #0e6b5e;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb i {
            font-size: 10px;
        }

        /* ===== Header ===== */

        .page-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 6px;
        }

        .page-title {
            color: #244f60;
            font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif;
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .page-subtitle {
            color: #7b94a0;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 22px;
        }

        .date-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 9px 14px;
            font-size: 13px;
            font-weight: 700;
            color: #244f60;
            white-space: nowrap;
        }

        .date-pill i {
            color: #0e6b5e;
        }

        /* ===== Sub-stat cards ===== */

        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 15px;
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .card h3 {
            color: #244f60;
            font-size: 12px;
            font-family: "Inter",system-ui,sans-serif;
            font-weight: 700;
        }

        .card .number {
            color: #0f2f3d;
            font-family: "Fraunces","Plus Jakarta Sans","Inter",system-ui,sans-serif;
            font-size: 24px;
            letter-spacing: -0.02em;
            font-variant-numeric: tabular-nums;
            font-weight: 800;
        }

        /* ===== Filter tabs ===== */

        .filter-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .filter-tab {
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #244f60;
            font-size: 13px;
            font-weight: 700;
            font-family: inherit;
            padding: 8px 15px;
            border-radius: 999px;
            cursor: pointer;
            transition: .2s;
        }

        .filter-tab:hover {
            border-color: #149a72;
            color: #149a72;
        }

        .filter-tab.active {
            background: #0e6b5e;
            border-color: #0e6b5e;
            color: #ffffff;
        }

        /* ===== Detail panel ===== */

        .panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 15px;
            padding: 8px 18px 14px;
            box-shadow: 0 10px 26px rgba(22,83,101,.05);
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 14px 10px;
            border-bottom: 2px solid #e8eef2;
            color: #244f60;
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }

        td {
            padding: 13px 10px;
            border-bottom: 1px solid #edf2f7;
            font-size: 14px;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status.confirmed,
        .status.approved {
            background: #dcfce7;
            color: #166534;
        }

        .status.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status.completed {
            background: #dbeafe;
            color: #1e40af;
        }

        .status.cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .status.no-show {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty {
            padding: 40px 12px;
            text-align: center;
            color: #7b94a0;
            font-size: 14px;
            font-weight: 600;
        }

        .empty-icon {
            display: flex;
            justify-content: center;
            margin-bottom: 12px;
        }

        .empty-icon i {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            border-radius: 14px;
            background: #eef4f7;
            color: #9fb2bc;
            font-size: 24px;
        }

        [hidden] {
            display: none !important;
        }

        @media (max-width: 700px) {
            .page {
                padding: 20px 14px 40px;
            }

            .page-title {
                font-size: 20px;
            }
        }

    </style>

</head>
<body>

<div class="page">

    <nav class="breadcrumb">
        <a href="dentist_dashboard.php">Dashboard</a>
        <i class="bi bi-chevron-right"></i>
        <span><?= esc($cardConfig['title']) ?></span>
    </nav>

    <div class="page-head">
        <div>
            <h1 class="page-title"><?= esc($cardConfig['title']) ?></h1>
        </div>

        <span class="date-pill">
            <i class="bi bi-calendar-event-fill"></i>
            <?= esc(date('M d, Y')) ?>
        </span>
    </div>

    <p class="page-subtitle"><?= esc($cardConfig['subtitle']) ?></p>

    <div class="cards">

        <?php foreach ($subStats as $subStat): ?>

            <div class="card">
                <h3><?= esc($subStat['label']) ?></h3>
                <div class="number"><?= (int) $subStat['value'] ?></div>
            </div>

        <?php endforeach; ?>

    </div>

    <div class="filter-tabs">

        <?php foreach ($tabs as $index => $tab): ?>

            <button
                type="button"
                class="filter-tab<?= $index === 0 ? ' active' : '' ?>"
                data-type="<?= esc($tab['type']) ?>"
                data-value="<?= esc($tab['value']) ?>"
            >
                <?= esc($tab['label']) ?> (<?= (int) $tab['count'] ?>)
            </button>

        <?php endforeach; ?>

    </div>

    <div class="panel">

        <?php if (empty($appointments)): ?>

            <div class="empty">
                <div class="empty-icon"><i class="<?= esc($emptyIcon) ?>"></i></div>
                <?= esc($cardConfig['empty']) ?>
            </div>

        <?php else: ?>

            <div class="table-wrap" id="tableWrap">

                <table>

                    <thead>

                        <tr>
                            <th>#</th>
                            <th>Patient Name</th>
                            <th>Date &amp; Time</th>
                            <th>Service</th>
                            <th>Status</th>
                        </tr>

                    </thead>

                    <tbody id="cardTableBody">

                        <?php foreach ($appointments as $index => $row): ?>

                            <tr
                                data-status="<?= esc(cardStatusKey($row['status'] ?? '')) ?>"
                                data-date="<?= esc($row['appointment_date'] ?? '') ?>"
                            >
                                <td><?= $index + 1 ?></td>
                                <td><?= esc($row['patient_name']) ?></td>
                                <td>
                                    <?php
                                        $dateLabel = !empty($row['appointment_date'])
                                            ? date('M d, Y', strtotime($row['appointment_date']))
                                            : '';
                                        $timeLabel = !empty($row['appointment_time'])
                                            ? date('h:i A', strtotime($row['appointment_time']))
                                            : '';
                                        echo esc(trim($dateLabel . ' ' . $timeLabel));
                                    ?>
                                </td>
                                <td>
                                    <?= esc($row['service_type'] ?? '') !== ''
                                        ? esc($row['service_type'])
                                        : '&mdash;' ?>
                                </td>
                                <td>
                                    <span class="status <?= esc(cardStatusKey($row['status'] ?? '')) ?>">
                                        <?= esc($row['status'] ?? '') ?>
                                    </span>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <div class="empty" id="filterEmpty" hidden>
                <div class="empty-icon"><i class="bi bi-funnel"></i></div>
                No records match this filter.
            </div>

        <?php endif; ?>

    </div>

</div>

<script>

(function () {
    var tabs = document.querySelectorAll('.filter-tab');
    var body = document.getElementById('cardTableBody');

    if (!tabs.length || !body) return;

    var rows = Array.prototype.slice.call(body.querySelectorAll('tr'));
    var tableWrap = document.getElementById('tableWrap');
    var filterEmpty = document.getElementById('filterEmpty');

    var dates = {
        today: <?= json_encode($today, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        weekStart: <?= json_encode($weekStart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        weekEnd: <?= json_encode($weekEnd, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        monthStart: <?= json_encode($monthStart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        monthEnd: <?= json_encode($monthEnd, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };

    function matches(row, type, value) {
        if (type === 'all') return true;

        if (type === 'status') {
            return row.getAttribute('data-status') === value;
        }

        var date = row.getAttribute('data-date');

        if (!date) return false;

        if (value === 'today') return date === dates.today;
        if (value === 'week') return date >= dates.weekStart && date <= dates.weekEnd;
        if (value === 'month') return date >= dates.monthStart && date <= dates.monthEnd;
        if (value === 'beyond') return date > dates.monthEnd;

        return true;
    }

    function applyFilter(type, value) {
        var visible = 0;

        rows.forEach(function (row) {
            var show = matches(row, type, value);
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        if (visible === 0) {
            tableWrap.hidden = true;
            filterEmpty.hidden = false;
        } else {
            tableWrap.hidden = false;
            filterEmpty.hidden = true;
        }
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (other) {
                other.classList.remove('active');
            });

            tab.classList.add('active');
            applyFilter(tab.getAttribute('data-type'), tab.getAttribute('data-value'));
        });
    });
})();

</script>

</body>
</html>
