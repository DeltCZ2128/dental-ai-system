<?php
require_once __DIR__ . "/../functions.php";
requireLogin();

// Admin mag No-Show: pag di na nakapunta si patient kahit niresched pero di pa rin pumunta

/*
|--------------------------------------------------------------------------
| CROSS-SITE REQUEST FORGERY CHECK
|--------------------------------------------------------------------------
| Every request that changes data is compared with the token held in the
| session before any handler of this page runs. A submission that carries
| no token, or a token that does not match, is refused.
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_no_show') {
    $aid = (int)($_POST['appointment_id'] ?? 0);
    if ($aid) {
        $stmt = $pdo->prepare("SELECT patient_id, appointment_date, start_time FROM appointments WHERE id = ? LIMIT 1");
        $stmt->execute([$aid]);
        $appt = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($appt) {
            $pdo->prepare("UPDATE appointments SET status = 'No-Show' WHERE id = ?")->execute([$aid]);
            try {
                $check = $pdo->prepare("SELECT id FROM notifications WHERE appointment_id = ? AND title = 'No-Show' LIMIT 1");
                $check->execute([$aid]);
                if (!$check->fetch()) {
                    $stmtN = $pdo->prepare("INSERT INTO notifications (patient_id, appointment_id, title, message, type) VALUES (?, ?, ?, ?, ?)");
                    $stmtN->execute([
                        (int)$appt['patient_id'],
                        $aid,
                        'No-Show',
                        'You were marked as No-Show for your appointment on '.$appt['appointment_date'].' at '.substr($appt['start_time'],0,5).'. Please contact the clinic.',
                        'System'
                    ]);
                }
            } catch (Throwable $e) {}
        }
    }
    redirect("no_show.php?appointment_id=".$aid);
}

// Optional: if appointment_id given, show that appointment's No-Show context (walang prediction)
$appointmentId = (int)($_GET['appointment_id'] ?? 0);
$appointmentInfo = null;
if ($appointmentId) {
    $stmt = $pdo->prepare("
        SELECT a.*, p.first_name, p.last_name, p.patient_number, d.dentist_name
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
        JOIN dentists d ON d.id = a.dentist_id
        WHERE a.id = ?
    ");
    $stmt->execute([$appointmentId]);
    $appointmentInfo = $stmt->fetch(PDO::FETCH_ASSOC);
}

// No-Show consideration: pag ilang araw di naka-attend sa mismong appointment, magnonotif sa patient dashboard
// Hindi na pinapakita ang "Patients Considered No-Show" list dito - tinanggal per request
// Notification ay gagawin lang kapag admin nag-consider ng No-Show (appointment_id na past due)
if ($appointmentInfo) {
    $isPastDue = $appointmentInfo['appointment_date'] < date('Y-m-d');
    $isConsiderNoShow = $isPastDue && !in_array($appointmentInfo['status'], ['No-Show','Completed','Cancelled'], true);
    if ($isConsiderNoShow) {
        try {
            // I-notify ang patient sa kanyang dashboard/notifications (hindi binabago ang ibang system)
            $check = $pdo->prepare("SELECT id FROM notifications WHERE appointment_id = ? AND title = 'No-Show' LIMIT 1");
            $check->execute([$appointmentId]);
            if (!$check->fetch()) {
                $stmtN = $pdo->prepare("INSERT INTO notifications (patient_id, appointment_id, title, message, type) VALUES (?, ?, ?, ?, ?)");
                $pid = (int)($pdo->query("SELECT patient_id FROM appointments WHERE id = ".(int)$appointmentId)->fetchColumn() ?: 0);
                if ($pid) {
                    $stmtN->execute([
                        $pid,
                        $appointmentId,
                        'No-Show',
                        'You were marked as No-Show for your appointment on '.$appointmentInfo['appointment_date'].' at '.substr($appointmentInfo['start_time'],0,5).'. Please contact the clinic.',
                        'System'
                    ]);
                }
            }
        } catch (Throwable $e) {
            // ignore - para walang error sa laman
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>No-Show</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/style.css?v=2">
<style>
body { font-family: "Inter","DM Sans",system-ui,sans-serif; background:#0A3B34; color:#173b4d; }
.main-simple { max-width: 960px; margin: 0 auto; padding: 32px 24px 45px; }
.main-simple h2 { font-family:"Plus Jakarta Sans","Inter",system-ui,sans-serif; font-size:22px; font-weight:800; letter-spacing:-0.02em; color:#ffffff; }
.no-show-table th { color:#8b9ea5; font-size:12.5px; text-transform:uppercase; letter-spacing:.4px; white-space:nowrap; }
.no-show-table td { color:#4d6873; font-size:14.5px; vertical-align:middle; font-family:"Inter","DM Sans",system-ui,sans-serif; }
.badge-no-show { background:#ffe8e8; color:#d65b5b; border-radius:20px; padding:6px 12px; font-size:12px; font-weight:700; font-family:"Inter",system-ui,sans-serif; }
</style>
</head>
<body>
<div class="main-simple">
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="mb-1" style="color:#ffffff;"><i class="bi bi-person-x-fill" style="color:#d65b5b;"></i> No-Show</h2>
        <p class="mb-0" style="font-size:14px; color:#9CC4B6;">Makikita dito ang pasyente na ilang beses hindi naka-attend — icoconsider ni admin na No-Show.</p>
    </div>
    <a href="appointments.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back to Appointments</a>
</div>

<?php if ($appointmentInfo): ?>
<div class="card mb-4">
<div class="card-body">
<h5 style="font-family:'Plus Jakarta Sans','Inter',sans-serif; font-weight:800; color:#244e5e; font-size:17px;">Appointment No-Show Info</h5>
<p style="font-size:14.5px; margin-bottom:4px;"><strong style="color:#244e5e;">Patient:</strong> <?= e($appointmentInfo['first_name'].' '.$appointmentInfo['last_name']) ?> <small style="color:#8ca0a7;"><?= e($appointmentInfo['patient_number']) ?></small></p>
<p style="font-size:14.5px; margin-bottom:4px;"><strong style="color:#244e5e;">Dentist:</strong> <?= e($appointmentInfo['dentist_name']) ?></p>
<p style="font-size:14.5px; margin-bottom:8px;"><strong style="color:#244e5e;">Appointment:</strong> <?= e($appointmentInfo['appointment_date']) ?> <?= date("h:i A", strtotime($appointmentInfo['start_time'])) ?> — <span class="badge bg-secondary" style="font-size:12px;"><?= e($appointmentInfo['status']) ?></span></p>
<?php if ($appointmentInfo['status'] !== 'No-Show'): ?>
<form method="POST" class="mt-3">
<?= csrfField() ?>
<input type="hidden" name="action" value="mark_no_show">
<input type="hidden" name="appointment_id" value="<?= (int)$appointmentId ?>">
<button class="btn btn-dark btn-sm" style="font-size:13.5px; padding:8px 16px;"><i class="bi bi-person-x-fill"></i> Mark as No-Show (Admin)</button>
<small class="text-muted ms-2" style="font-size:12.5px;">Di na nakapunta kahit niresched — magnonotif sa patient.</small>
</form>
<?php else: ?>
<div class="alert alert-warning mt-3 mb-0" style="font-size:14px;"><i class="bi bi-check-circle-fill"></i> Naka No-Show na ito. Na-notify na ang pasyente sa kanyang dashboard/notifications.</div>
<?php endif; ?>
</div>
</div>
<?php endif; ?>

<div class="card">
<div class="card-body">
<p class="text-muted mb-0" style="font-size:14px;"><i class="bi bi-info-circle" style="color:#0E6B5E;"></i> Kapag ilang araw nang hindi naka-attend ang pasyente sa mismong appointment at na-consider ni admin na No-Show, automatic na magnonotif ito sa <strong>dashboard at notifications ng pasyente</strong>.</p>
</div>
</div>

</div>
</body>
</html>
