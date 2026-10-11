<?php

require_once __DIR__ . "/../functions.php";

requireAdmin();

/*
|--------------------------------------------------------------------------
| DATABASE BACKUP (Export / Import)
|--------------------------------------------------------------------------
| Export  - gumawa ng kumpletong .sql backup (structure + laman) at
|           i-download. Dalhin ito kapag lilipat ng laptop.
| Import  - mag-upload ng .sql backup para ibalik ang lahat ng data
|           (appointments, patients, users, atbp.) sa bagong laptop.
*/

/* ---------- EXPORT ---------- */
if (isset($_GET['export'])) {
    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();

    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
        ->fetchAll(PDO::FETCH_NUM);

    $dump = "-- Abalos Dental Clinic database backup\n"
        . "-- Database: `{$dbName}`\n"
        . "-- Generated: " . date('Y-m-d H:i:s') . "\n"
        . "-- I-import sa bagong laptop sa Admin > Backup > Import.\n\n"
        . "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n"
        . "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $t) {
        $table = $t[0];

        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        $createSql = $create['Create Table'] ?? '';

        $dump .= "-- --------------------------------------------------------\n"
            . "-- Table: `{$table}`\n"
            . "-- --------------------------------------------------------\n\n"
            . "DROP TABLE IF EXISTS `{$table}`;\n"
            . $createSql . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            $cols = array_map(function ($c) {
                return "`" . str_replace("`", "``", $c) . "`";
            }, array_keys($rows[0]));

            $dump .= "INSERT INTO `{$table}` (" . implode(", ", $cols) . ") VALUES\n";

            $lines = [];
            foreach ($rows as $row) {
                $vals = [];
                foreach ($row as $v) {
                    if ($v === null) {
                        $vals[] = "NULL";
                    } else {
                        $vals[] = $pdo->quote((string)$v);
                    }
                }
                $lines[] = "(" . implode(", ", $vals) . ")";
            }

            $dump .= implode(",\n", $lines) . ";\n\n";
        }
    }

    $dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    $filename = "dental_backup_" . date('Ymd_His') . ".sql";

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($dump));

    echo $dump;
    exit;
}

/* ---------- IMPORT ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'import') {
        if (
            !isset($_FILES['backup_file']) ||
            $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK
        ) {
            flash("Please choose a valid .sql backup file.", "danger");
            redirect("backup.php");
        }

        $tmpPath = $_FILES['backup_file']['tmp_name'];
        $origName = (string)($_FILES['backup_file']['name'] ?? '');

        if (strtolower(substr($origName, -4)) !== '.sql') {
            flash("Only .sql files can be imported.", "danger");
            redirect("backup.php");
        }

        $sql = @file_get_contents($tmpPath);

        if ($sql === false || trim($sql) === '') {
            flash("The uploaded file is empty or unreadable.", "danger");
            redirect("backup.php");
        }

        // Tanggalin ang comment lines (-- ... at # ...) para malinis ang split.
        $cleanLines = [];
        foreach (preg_split("/\r\n|\n|\r/", $sql) as $line) {
            $trimmed = ltrim($line);
            if (
                $trimmed === '' ||
                strncmp($trimmed, '--', 2) === 0 ||
                strncmp($trimmed, '#', 1) === 0
            ) {
                continue;
            }
            $cleanLines[] = $line;
        }
        $clean = implode("\n", $cleanLines);

        // Hatiin kada statement (nagtatapos sa ; + newline). Walang triggers/procedures ang DB na ito.
        $statements = preg_split('/;\s*\n/', $clean);

        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            $ok = 0;
            $fail = 0;

            foreach ($statements as $stmtSql) {
                $stmtSql = trim($stmtSql);

                if ($stmtSql === '') {
                    continue;
                }

                try {
                    $pdo->exec($stmtSql);
                    $ok++;
                } catch (PDOException $e) {
                    $fail++;
                }
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            // I-relink ang dentist accounts pagkatapos mag-import.
            if (function_exists('autoLinkDentistAccounts')) {
                autoLinkDentistAccounts();
            }

            if ($ok > 0 && $fail === 0) {
                flash("Backup imported successfully ({$ok} statements). Lahat ng data ay naibalik.");
            } elseif ($ok > 0) {
                flash("Import finished with {$ok} successful and {$fail} failed statements. Tingnan ang data kung kumpleto.", "danger");
            } else {
                flash("Import failed. Siguraduhing galing ito sa Export ng system na ito.", "danger");
            }
        } catch (Throwable $e) {
            try {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            } catch (Throwable $e2) {
            }
            flash("Import failed: " . $e->getMessage(), "danger");
        }

        redirect("backup.php");
    }
}

$apptCount = 0;
$patientCount = 0;
$userCount = 0;

try {
    $apptCount = (int)$pdo->query("SELECT COUNT(*) FROM appointments")->fetchColumn();
    $patientCount = (int)$pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
    $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
} catch (Throwable $e) {
}

?>
<!DOCTYPE html>
<html>

<head>

<title>Backup</title>

<meta name="viewport" content="width=device-width,initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<link rel="stylesheet" href="../assets/style.css?v=2">

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">

<style>
body { background: linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22) !important; background-size: 300% 300% !important; animation: adminBgAnim 8s ease infinite !important; background-attachment: fixed !important; }
@keyframes adminBgAnim { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
.shared-sidebar { display: none !important; }
.shared-topbar { left: 0 !important; right: 0 !important; }
.main { margin-left: 0 !important; width: 100% !important; padding: 110px 24px 40px !important; background: transparent !important; min-height: 100vh; }
.main > a.btn, .main > .alert { margin-left: 14px; margin-right: 14px; }
.main > .alert { font-size: 13.5px; border-radius: 12px; }
.main .card { background: #ffffff !important; border: 1px solid #C9E9DA !important; border-radius: 16px !important; box-shadow: 0 8px 24px rgba(14,107,94,.08) !important; padding: 22px !important; margin: 0 14px 16px 14px !important; }
.main .card h5 { font-family: "Plus Jakarta Sans","Inter",system-ui,sans-serif; font-weight: 800; color: #143f51; }
.main .card p, .main .card li { font-size: 13.5px; color: #4d6873; }
.main .btn-primary { background: linear-gradient(135deg, #149A72, #0A4A41) !important; border: none !important; border-radius: 10px; box-shadow: 0 6px 14px rgba(14,107,94,.22); font-weight: 700; }
.main .btn-primary:hover { background: linear-gradient(135deg, #0E6B5E, #083A32) !important; }
.stat-chip { display:inline-block; background:#EAF7F0; border:1px solid #C9E9DA; color:#0A4A41; border-radius:20px; padding:6px 14px; font-size:13px; font-weight:700; margin:0 8px 8px 0; }
@media (max-width: 991px) { .main { padding: 25px 16px 40px !important; } .main .card { margin-left: 0 !important; margin-right: 0 !important; } }
</style>

</head>

<body>

<?php include __DIR__ . "/includes.php"; ?>

<div class="main">

<?php showFlash(); ?>

<a href="dashboard.php" class="btn btn-outline-secondary btn-sm mb-3">
<i class="bi bi-arrow-left me-1"></i>Back to Dashboard
</a>

<div class="card">
<h5><i class="bi bi-database-fill-check me-2" style="color:#0E6B5E;"></i>Database Backup</h5>
<p class="mb-2">Kasalukuyang laman ng database:</p>
<div>
<span class="stat-chip"><i class="bi bi-calendar2-check-fill me-1"></i><?= (int)$apptCount ?> appointments</span>
<span class="stat-chip"><i class="bi bi-people-fill me-1"></i><?= (int)$patientCount ?> patients</span>
<span class="stat-chip"><i class="bi bi-person-fill me-1"></i><?= (int)$userCount ?> users</span>
</div>
<p class="mt-2 mb-0"><strong>Paalala:</strong> ang project files (htdocs) ay <u>hindi</u> kasama ang database. Kapag lilipat ng laptop, kailangan <u>Export</u> dito at <u>Import</u> doon — kung hindi, mawawala ang appointments at lahat ng data.</p>
</div>

<div class="card">
<h5><i class="bi bi-download me-2" style="color:#0E6B5E;"></i>1. Export (mag-save ng backup)</h5>
<p>I-download ang buong database bilang <code>.sql</code> file — kasama ang appointments, patients, users, dentists, at lahat ng records. Itago ito sa USB/Google Drive bago lumipat ng laptop.</p>
<a href="backup.php?export=1" class="btn btn-primary"><i class="bi bi-download me-1"></i>Download Backup (.sql)</a>
</div>

<div class="card">
<h5><i class="bi bi-upload me-2" style="color:#0E6B5E;"></i>2. Import (ibalik ang backup sa bagong laptop)</h5>
<p>Sa bagong laptop: buksan ang Admin &gt; Backup &gt; piliin ang <code>.sql</code> backup file &gt; Import. Papalitan nito ang laman ng database ng laman ng backup.</p>
<form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Papalitan ang lahat ng data ng laman ng backup file. Ituloy?')">
<?= csrfField() ?>
<input type="hidden" name="action" value="import">
<div class="row g-2 align-items-center">
<div class="col-md-6">
<input type="file" name="backup_file" class="form-control" accept=".sql" required>
</div>
<div class="col-md-6">
<button class="btn btn-primary" type="submit"><i class="bi bi-upload me-1"></i>Import Backup</button>
</div>
</div>
</form>
</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>
