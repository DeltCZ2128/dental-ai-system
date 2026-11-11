<?php
require_once __DIR__ . "/config.php";

$success = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $service = trim($_POST['service'] ?? '');

    if ($name === '' || $service === '') {
        $error = "Please enter your name and select dental service.";
    } else {
        try {
            try {
                $pdo->exec("ALTER TABLE queue ADD COLUMN IF NOT EXISTS service_type VARCHAR(150) NOT NULL DEFAULT ''");
            } catch (Throwable $e) {}

            $parts = preg_split('/\s+/', $name);
            $first = $parts[0] ?? $name;
            $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '-';
            $patientNumber = generateShortPatientNumber($pdo);

            $stmtP = $pdo->prepare("INSERT INTO patients (patient_number, first_name, last_name, contact_number, email) VALUES (?, ?, ?, ?, ?)");
            $stmtP->execute([$patientNumber, $first, $last, '', '']);
            $newId = (int)$pdo->lastInsertId();

            $date = date('Y-m-d');

            if (function_exists('generateQueueNumber')) {
                require_once __DIR__ . "/functions.php";
                $qnum = generateQueueNumber($date);
            } else {
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(queue_number),0)+1 FROM queue WHERE queue_date = ?");
                $stmt->execute([$date]);
                $qnum = (int)$stmt->fetchColumn();
                if ($qnum <= 0) $qnum = 1;
            }

            try {
                $stmt = $pdo->prepare("INSERT INTO queue (patient_id, queue_number, queue_date, priority, service_type) VALUES (?, ?, ?, 'Normal', ?)");
                $stmt->execute([$newId, $qnum, $date, $service]);
            } catch (Throwable $e) {
                $stmt = $pdo->prepare("INSERT INTO queue (patient_id, queue_number, queue_date, priority) VALUES (?, ?, ?, 'Normal')");
                $stmt->execute([$newId, $qnum, $date]);
            }

            $success = "Thank you, " . $name . "! You have been added to the queue. Queue number: Q-" . str_pad($qnum, 3, '0', STR_PAD_LEFT) . " (" . $service . "). Please wait for your number to be called.";
        } catch (Throwable $e) {
            $error = "Unable to add to queue. Please try again.";
        }
    }
}

$services = [
    'General Dentistry',
    'Orthodontics (Braces)',
    'Oral Surgery (Tooth Extraction)',
    'Cosmetic',
    'Fillings',
    'Root Canal',
    'Oral Prophylaxis (Cleaning)',
    'Denture',
    'Teeth Whitening',
    'Crown',
    'Bridge',
    'Veneers',
    'Dental Check-up'
];
?>
<!DOCTYPE html>
<html lang="tl">
<head>
<meta charset="UTF-8">
<!-- Universal: gumagana sa Android (Chrome/Lens) at iPhone (Safari/Camera) pag iniscan ang QR -->
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#022c22">
<title>Walk-in Queue | Abalos Dental Clinic</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
body { font-family: "Inter", system-ui, sans-serif; min-height:100vh; background: linear-gradient(135deg,#022c22 0%,#064e3b 55%,#065f46 100%); display:flex; align-items:center; justify-content:center; padding:20px; }
.walkin-card { width:100%; max-width:440px; background:#fff; border-radius:20px; padding:32px 28px; box-shadow:0 30px 80px rgba(0,0,0,.45); }
.walkin-card h2 { font-weight:800; color:#022c22; font-size:22px; }
.walkin-card p { font-size:13.5px; color:#047857; }
.form-label { font-size:12px; font-weight:700; color:#022c22; }
.form-control, .form-select { height:50px; border-radius:12px; font-size:14.5px; }
.btn-submit { height:52px; border-radius:12px; background:linear-gradient(135deg,#022c22,#064e3b); border:none; font-weight:800; font-size:15px; }
.btn-submit:hover { background:#064e3b; }
.logo-circle { width:60px; height:60px; border-radius:18px; background:linear-gradient(135deg,#022c22,#064e3b); color:#fff; display:flex; align-items:center; justify-content:center; font-size:28px; margin-bottom:14px; }
/* Android touch-friendly: malalaking tap target, safe-area, no zoom issue sa Chrome */
input, select, button { font-size:16px !important; /* iwas auto-zoom sa Android Chrome/iOS Safari */ }
.btn-submit { min-height:52px; touch-action:manipulation; -webkit-tap-highlight-color: transparent; }
.walkin-card { padding-bottom: calc(32px + env(safe-area-inset-bottom, 0px)); }
.device-hint { font-size:12px; color:#6b7280; background:#f0fdf4; border:1px solid #a7f3d0; border-radius:10px; padding:10px 12px; margin-top:14px; }
</style>
</head>
<body>
<div class="walkin-card">
<div class="logo-circle"><i class="bi bi-qr-code-scan"></i></div>
<h2>Walk-in Queue</h2>
<p>Abalos Dental Clinic — Enter your Name + Dental Service to join the queue.<br><span style="font-size:12px;">&#10003; Compatible with <b>Android</b> (Camera / Chrome / Lens) and <b>iPhone</b> (Camera / Safari).</span></p>
<?php if ($success): ?>
<div class="alert alert-success" style="font-size:15px;"><?= htmlspecialchars($success) ?></div>
<div class="text-center mt-2"><a href="walkin_qr.php" class="btn btn-outline-success btn-sm">+ Queue another person</a></div>
<?php else: ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="POST" autocomplete="on">
<div class="mb-3">
<label class="form-label">Name *</label>
<input type="text" name="name" class="form-control" placeholder="Juan Dela Cruz" required autocomplete="name" inputmode="text" enterkeyhint="next" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
</div>
<div class="mb-4">
<label class="form-label">Dental Service *</label>
<select name="service" class="form-select" required>
<option value="">-- Select a service --</option>
<?php foreach ($services as $s): ?>
<option value="<?= htmlspecialchars($s) ?>" <?= (($_POST['service'] ?? '') === $s) ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
<?php endforeach; ?>
</select>
</div>
<button class="btn btn-success btn-submit w-100" type="submit"><i class="bi bi-check-circle me-1"></i>Submit</button>
</form>
<div class="device-hint">&#128241; <b>Android:</b> Open Camera or Chrome &gt; tap the link &gt; enter details &gt; Submit.<br>&#127822; <b>iPhone:</b> Open Camera &gt; tap the link &gt; enter details &gt; Submit.</div>
<?php endif; ?>
</div>
</body>
</html>
