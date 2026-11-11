<?php

require_once __DIR__ . "/config.php";

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

function requireLogin()
{
    if (!isset($_SESSION['user_id'])) {
        $loginPath = strpos(
            str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''),
            '/admin/'
        ) !== false ? '../login.php' : 'login.php';

        header("Location: " . $loginPath);
        exit;
    }
}

function requireAdmin()
{
    requireLogin();

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        die("Access denied.");
    }
}

/*
|--------------------------------------------------------------------------
| GENERAL HELPERS
|--------------------------------------------------------------------------
*/

function redirect($url)
{
    header("Location: $url");
    exit;
}

function flash($message, $type = "success")
{
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type
    ];
}

function showFlash()
{
    if (isset($_SESSION['flash'])) {

        $flash = $_SESSION['flash'];

        echo '<div class="alert alert-' .
            e($flash['type']) .
            ' alert-dismissible fade show">';

        echo e($flash['message']);

        echo '<button type="button"
                class="btn-close"
                data-bs-dismiss="alert"></button>';

        echo '</div>';

        unset($_SESSION['flash']);
    }
}

function generatePatientNumber()
{
    global $pdo;
    if (function_exists('generateShortPatientNumber')) {
        return generateShortPatientNumber($pdo);
    }
    return "PAT-" . rand(1000, 9999);
}

/*
|--------------------------------------------------------------------------
| SETTINGS
|--------------------------------------------------------------------------
|
| Automatically creates the settings table if it does not exist.
| This prevents the dashboard from crashing when the table is missing.
|
*/

function ensureSettingsTable()
{
    global $pdo;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            id INT NOT NULL AUTO_INCREMENT,
            clinic_name VARCHAR(255) NOT NULL DEFAULT 'Abalos Dental Clinic',
            address TEXT NOT NULL,
            contact_number VARCHAR(50) DEFAULT '',
            email VARCHAR(255) DEFAULT '',
            opening_time TIME NOT NULL DEFAULT '08:00:00',
            closing_time TIME NOT NULL DEFAULT '17:00:00',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    /*
     * Make sure there is always one settings record.
     */
    $stmt = $pdo->query("SELECT COUNT(*) FROM settings");
    $count = (int) $stmt->fetchColumn();

    if ($count === 0) {

        $stmt = $pdo->prepare("
            INSERT INTO settings
            (
                id,
                clinic_name,
                address,
                contact_number,
                email,
                opening_time,
                closing_time
            )
            VALUES
            (
                1,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
        ");

        $stmt->execute([
            'Abalos Dental Clinic',
            'Malolos Avenue, Caloocan City',
            '',
            '',
            '08:00:00',
            '17:00:00'
        ]);
    }
}

function getSetting()
{
    global $pdo;

    ensureSettingsTable();

    $stmt = $pdo->query("
        SELECT *
        FROM settings
        WHERE id = 1
        LIMIT 1
    ");

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| APPOINTMENT CONFLICT
|--------------------------------------------------------------------------
*/

function appointmentConflict(
    $dentistId,
    $date,
    $start,
    $end,
    $excludeId = null
) {
    global $pdo;

    $sql = "
        SELECT id
        FROM appointments
        WHERE dentist_id = ?
        AND appointment_date = ?
        AND status NOT IN ('Cancelled', 'No-Show')
        AND start_time < ?
        AND end_time > ?
    ";

    $params = [
        $dentistId,
        $date,
        $end,
        $start
    ];

    if ($excludeId) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| QUEUE
|--------------------------------------------------------------------------
*/

function generateQueueNumber($date)
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT MAX(queue_number)
        FROM queue
        WHERE queue_date = ?
    ");

    $stmt->execute([$date]);

    $number = $stmt->fetchColumn();

    return $number ? $number + 1 : 1;
}

/*
|--------------------------------------------------------------------------
| NOTIFICATIONS
|--------------------------------------------------------------------------
*/

function createNotification(
    $patientId,
    $appointmentId,
    $title,
    $message,
    $type = "System"
) {
    global $pdo;

    $stmt = $pdo->prepare("
        INSERT INTO notifications
        (
            patient_id,
            appointment_id,
            title,
            message,
            type
        )
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $patientId,
        $appointmentId,
        $title,
        $message,
        $type
    ]);
}

/*
|--------------------------------------------------------------------------
| NO-SHOW STATISTICS
|--------------------------------------------------------------------------
*/

function getNoShowStats($patientId)
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(status = 'No-Show') AS no_shows,
            SUM(status = 'Completed') AS completed
        FROM appointments
        WHERE patient_id = ?
    ");

    $stmt->execute([$patientId]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| LOCAL NO-SHOW PREDICTION
|--------------------------------------------------------------------------
*/

function localPrediction($patientId)
{
    global $pdo;

    $stats = getNoShowStats($patientId);

    $total = (int) ($stats['total'] ?? 0);
    $noShows = (int) ($stats['no_shows'] ?? 0);

    if ($total == 0) {

        return [
            'probability' => 20,
            'result' => 'Show',
            'risk' => 'Low',
            'recommendation' =>
                'New patient. Send standard appointment reminder.'
        ];
    }

    $probability = ($noShows / $total) * 100;

    if ($probability >= 60) {

        $result = "No-Show";
        $risk = "High";

        $recommendation =
            "Send reminder 24 hours before appointment and confirm attendance.";

    } elseif ($probability >= 30) {

        $result = "Show";
        $risk = "Medium";

        $recommendation =
            "Send appointment reminder and request confirmation.";

    } else {

        $result = "Show";
        $risk = "Low";

        $recommendation =
            "Standard appointment reminder is recommended.";
    }

    return [
        'probability' => round($probability, 2),
        'result' => $result,
        'risk' => $risk,
        'recommendation' => $recommendation
    ];
}

/*
|--------------------------------------------------------------------------
| CLINIC GMAIL SENDER (REMINDER ONLY)
|--------------------------------------------------------------------------
| Moved to config.php so patient pages (config-only) can use it too.
| See sendClinicGmail() in config.php.
*/