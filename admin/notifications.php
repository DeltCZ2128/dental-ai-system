<?php

require_once __DIR__ . '/../functions.php';

requireLogin();

$notifications = [];

try {
    $stmt = $pdo->query("
        SELECT
            a.id,
            a.appointment_date,
            a.start_time,
            a.status,
            a.created_at,
            a.service_type,
            CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
            d.dentist_name
        FROM appointments a
        INNER JOIN patients p ON p.id = a.patient_id
        INNER JOIN dentists d ON d.id = a.dentist_id
        ORDER BY a.created_at DESC
        LIMIT 50
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $appointment) {
        $dateText = date('M d, Y', strtotime($appointment['appointment_date']));
        $timeText = date('h:i A', strtotime($appointment['start_time']));
        $status = $appointment['status'];

        if ($status === 'Pending') {
            $notifications[] = [
                'title' => 'New Appointment Request',
                'message' => $appointment['patient_name'] . ' requested ' . $appointment['service_type'] . ' on ' . $dateText . ' at ' . $timeText . '.',
                'icon' => 'bi-hourglass-split',
                'class' => 'pending',
                'date' => $appointment['created_at'],
                'appointment_id' => $appointment['id'],
                'link' => 'appointments.php?view=' . (int)$appointment['id'] . '&from=notifications#appt-' . (int)$appointment['id']
            ];
        } elseif ($status === 'Confirmed') {
            $notifications[] = [
                'title' => 'Appointment Confirmed',
                'message' => $appointment['patient_name'] . ' is confirmed with ' . $appointment['dentist_name'] . ' on ' . $dateText . ' at ' . $timeText . '.',
                'icon' => 'bi-check-circle-fill',
                'class' => 'confirmed',
                'date' => $appointment['created_at'],
                'appointment_id' => $appointment['id'],
                'link' => 'appointments.php?view=' . (int)$appointment['id'] . '&from=notifications#appt-' . (int)$appointment['id']
            ];
        } elseif ($status === 'Cancelled') {
            $notifications[] = [
                'title' => 'Appointment Cancelled',
                'message' => $appointment['patient_name'] . ' has a cancelled appointment on ' . $dateText . '.',
                'icon' => 'bi-x-circle-fill',
                'class' => 'cancelled',
                'date' => $appointment['created_at'],
                'appointment_id' => $appointment['id'],
                'link' => 'appointments.php?view=' . (int)$appointment['id'] . '&from=notifications#appt-' . (int)$appointment['id']
            ];
        }
    }

    $stmt = $pdo->query("
        SELECT
            n.title,
            n.message,
            n.created_at,
            n.appointment_id
        FROM notifications n
        WHERE n.title = 'Appointment Rescheduled'
        ORDER BY n.created_at DESC
        LIMIT 30
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $storedNotification) {
        $reschedId = (int)($storedNotification['appointment_id'] ?? 0);
        $notifications[] = [
            'title' => 'Appointment Rescheduled',
            'message' => $storedNotification['message'],
            'icon' => 'bi-calendar2-week-fill',
            'class' => 'rescheduled',
            'date' => $storedNotification['created_at'],
            'appointment_id' => $storedNotification['appointment_id'],
            'link' => $reschedId > 0 ? 'appointments.php?view=' . $reschedId . '&from=notifications' : 'appointments.php?from=notifications'
        ];
    }

    // Patient Appointment Concerns - visible in admin Notifications
    // Kapag pinindot, sa appointment_concern page magdidirect
    try {
        $stmt = $pdo->query("
            SELECT
                an.id,
                an.title,
                an.message,
                an.created_at,
                an.appointment_id,
                an.concern_id,
                an.is_read
            FROM admin_notifications an
            WHERE an.type = 'Appointment Concern'
            ORDER BY an.created_at DESC
            LIMIT 30
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $concernNotification) {
            $notifications[] = [
                'title' => $concernNotification['title'],
                'message' => $concernNotification['message'],
                'icon' => 'bi-exclamation-circle-fill',
                'class' => (int)$concernNotification['is_read'] === 0 ? 'pending' : 'confirmed',
                'date' => $concernNotification['created_at'],
                'appointment_id' => $concernNotification['appointment_id'],
                'link' => 'appointment_concern.php?highlight=' . (int)$concernNotification['id'] . '&from=notifications'
            ];
        }
    } catch (PDOException $e) {
        // admin_notifications table may not exist yet - keep existing notifications
    }
} catch (PDOException $e) {
    $notifications = [];
}

usort($notifications, function ($first, $second) {
    return strcmp($second['date'], $first['date']);
});

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | Abalos Dental Clinic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/style.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg,#022c22,#059669,#065f46,#10b981,#022c22) !important; background-size: 300% 300% !important; animation: adminBgAnim 8s ease infinite !important; background-attachment: fixed !important; }
@keyframes adminBgAnim { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        .shared-sidebar { display: none !important; }
        .shared-topbar { left: 0 !important; right: 0 !important; }
        .notifications-page { margin-left: 0 !important; width: 100% !important; min-height: 100vh; padding: 110px 24px 40px !important; background: transparent !important; box-sizing: border-box; display: block !important; }
        .notifications-page > a.btn[href*="dashboard.php"] { margin-left: 14px !important; margin-right: 14px !important; margin-bottom: 16px !important; }
        .notifications-head { margin: 0 14px 16px 14px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; }
        .notifications-head h1 { color: #ffffff !important; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; margin: 0; display: flex; align-items: center; gap: 10px; }
        .notifications-head h1 .head-icon { width: 38px; height: 38px; border-radius: 11px; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.2); display: inline-flex; align-items: center; justify-content: center; font-size: 18px; color: #fff; }
        .notifications-head p { margin: 4px 0 0; color: #C9E9DA; font-size: 13px; }
        .notifications-count-pill { display: inline-flex; align-items: center; gap: 6px; background: linear-gradient(135deg, #C9A227, #A8861B); color: #0A3B34; font-size: 12px; font-weight: 800; font-family: "Inter", system-ui, sans-serif; border-radius: 20px; padding: 7px 14px; box-shadow: 0 6px 14px rgba(0,0,0,.2); white-space: nowrap; }
        .notifications-panel { background: #fff !important; border: 1px solid #C9E9DA !important; border-radius: 16px !important; overflow: hidden !important; box-shadow: 0 8px 24px rgba(14,107,94,.08) !important; padding: 0 !important; margin: 0 14px 24px 14px !important; }
        .notifications-panel-header { padding: 18px 20px; background: linear-gradient(135deg, #D9F2E6 0%, #EAF7F0 100%); border-bottom: 1px solid #C9E9DA; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .notifications-panel h1 { color: #143f51 !important; font-family: "Plus Jakarta Sans", "Inter", system-ui, sans-serif; font-size: 17px !important; font-weight: 800; letter-spacing: -0.02em; margin: 0; display: flex; align-items: center; gap: 8px; }
        .notifications-panel h1 i { color: #0E6B5E; font-size: 18px; }
        .notifications-panel .panel-subtitle { color: #8aa0a8; font-size: 12px; margin: 3px 0 0 26px; }
        .notifications-list { padding: 8px 20px 18px; }
        .notification-item { display: flex; align-items: flex-start; gap: 14px; padding: 16px 16px; margin-top: 12px; border: 1.5px solid #C9D9D2; border-radius: 14px; background: #ffffff; box-shadow: 0 2px 10px rgba(20,63,81,.05); }
        .notification-item:last-child { margin-bottom: 4px; }
        .notification-item:hover { background: #F6FBF8; border-color: #B9CFC6; }
        .notification-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 18px; background: #EFF6F2; color: #3A6B5E; border: 1.5px solid #C9D9D2; }
        .notification-title { color: #2A4A57; font-size: 14.5px; font-weight: 800; font-family: "Inter", "DM Sans", system-ui, sans-serif; }
        .notification-message { color: #5B7680; font-size: 13.5px; line-height: 1.6; margin-top: 4px; font-family: "Inter", "DM Sans", system-ui, sans-serif; }
        .notification-date { color: #8aa0a8; font-size: 12px; margin-top: 6px; font-family: "Inter", system-ui, sans-serif; }
        .notification-item.pending { border: 1.5px solid #E3D3A0; border-left: 5px solid #D9BE6A; background: #FFFDF4; }
        .notification-item.pending .notification-icon { background: #FFF7E0; color: #8A6D1B; border-color: #E3D3A0; }
        .notification-item.confirmed { border: 1.5px solid #BFD9CB; border-left: 5px solid #7FBFA0; background: #F7FCF9; }
        .notification-item.confirmed .notification-icon { background: #E7F4EC; color: #3E7D5E; border-color: #BFD9CB; }
        .notification-item.cancelled { border: 1.5px solid #E3C2C2; border-left: 5px solid #D69A9A; background: #FDF8F8; }
        .notification-item.cancelled .notification-icon { background: #F9EDED; color: #9A5A5A; border-color: #E3C2C2; }
        .notification-item.upcoming { border: 1.5px solid #B9CFC6; border-left: 5px solid #6FA895; background: #F7FAF8; }
        .notification-item.upcoming .notification-icon { background: #EDF4F0; color: #3A6B5E; border-color: #B9CFC6; }
        .notification-item.rescheduled { border: 1.5px solid #CFC6E3; border-left: 5px solid #A99BD1; background: #FAF8FE; }
        .notification-item.rescheduled .notification-icon { background: #F1EDFA; color: #6A5D8F; border-color: #CFC6E3; }
        .notifications-page .btn-primary { background: linear-gradient(135deg, #149A72, #0A4A41) !important; border: none !important; border-radius: 8px; box-shadow: 0 6px 14px rgba(14,107,94,.22); font-weight: 700; }
        .notifications-page .btn-outline-primary { color: #0E6B5E !important; border-color: #C9E9DA !important; background: #fff !important; }
        .notifications-page .btn-outline-primary:hover { background: #EAF7F0 !important; color: #0E6B5E !important; border-color: #0E6B5E !important; }
        .notifications-page .btn { font-size: 13.5px; }
        .notifications-page .btn-sm { font-size: 12.5px; }
        .notifications-empty { text-align: center; padding: 40px 20px; color: #8aa0a8; font-size: 13.5px; font-family: "Inter", "DM Sans", system-ui, sans-serif; }
        .notifications-empty i { display: inline-flex; align-items: center; justify-content: center; width: 52px; height: 52px; border-radius: 14px; background: #EAF7F0; color: #0E6B5E; border: 1px solid #C9E9DA; font-size: 24px; margin-bottom: 12px; }
        @media (max-width: 991px) { .notifications-page { padding: 25px 16px 40px !important; } .notifications-panel { margin-left: 0 !important; margin-right: 0 !important; } .notifications-head { margin: 0 0 16px 0; } }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes.php'; ?>

<main class="notifications-page">
    <a href="dashboard.php" id="adminNotifBackDashboard" class="btn mb-3" data-tip="Back to Dashboard">
        <i class="bi bi-arrow-left-circle-fill me-1"></i>Back to Dashboard
    </a>
    <div class="notifications-head">
        <div>
            <h1><span class="head-icon"><i class="bi bi-bell-fill"></i></span>Notifications</h1>
            <p>Appointment activity for the clinic administrator.</p>
        </div>
        <?php if (!empty($notifications)): ?>
            <span class="notifications-count-pill"><i class="bi bi-bell-fill"></i><?= count($notifications) ?> notifications</span>
        <?php endif; ?>
    </div>
    <section class="notifications-panel">
        <div class="notifications-panel-header">
            <div>
                <h1><i class="bi bi-list-check"></i>Appointment Activity</h1>
                <p class="panel-subtitle">Latest appointment updates and patient concerns.</p>
            </div>
            <i class="bi bi-bell-fill fs-4" style="color:#0E6B5E;"></i>
        </div>
        <div class="notifications-list">

        <?php if (empty($notifications)): ?>
            <div class="notifications-empty">
                <i class="bi bi-bell-slash"></i>
                <div class="fw-bold" style="color:#426471;">No notifications yet.</div>
                <div class="small">New appointment activity will appear here.</div>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notification): ?>
                <?php $notifLink = trim($notification['link'] ?? ''); ?>
                <a href="<?= e($notifLink !== '' ? $notifLink : 'appointments.php') ?>" class="notification-item <?= e($notification['class']) ?> text-decoration-none" style="cursor:pointer;" title="Click to view: <?= e($notification['title']) ?>" data-tip="View Notification">
                    <div class="notification-icon"><i class="bi <?= e($notification['icon']) ?>"></i></div>
                    <div class="flex-grow-1">
                        <div class="notification-title"><?= e($notification['title']) ?></div>
                        <div class="notification-message"><?= e($notification['message']) ?></div>
                        <div class="notification-date"><?= e(date('M d, Y h:i A', strtotime($notification['date']))) ?></div>
                    </div>
                    <span class="btn btn-sm btn-outline-primary">View</span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </section>
</main>
<script>
(function(){
    function closeTabThenFallback(url){
        try { window.close(); } catch(e) {}
        setTimeout(function(){
            try {
                if (!window.closed) { window.open('', '_self'); window.close(); }
            } catch(e2) {}
            setTimeout(function(){ if (!window.closed) window.location.href = url; }, 150);
        }, 200);
    }
    // Mark na galing sa notification click — para mag-close ang tab sa dulo ng flow
    document.querySelectorAll('a.notification-item').forEach(function(a){
        a.addEventListener('click', function(){
            try { sessionStorage.setItem('adminNotifReturn', '1'); } catch(e) {}
            // dalhin ang #notab hash kung meron
            try {
                if (window.location.hash && a.getAttribute('href').indexOf('#') === -1) {
                    a.href = a.getAttribute('href') + window.location.hash;
                }
            } catch(e) {}
        });
    });
    var back = document.getElementById('adminNotifBackDashboard');
    if (back) {
        // dalhin din ang hash sa dashboard kung #notab mode
        try {
            if (window.location.hash === '#notab' && back.getAttribute('href').indexOf('#') === -1) {
                back.href = back.getAttribute('href') + '#notab';
            }
        } catch(e) {}
        back.addEventListener('click', function(e){
            var fromFlow = false;
            try { fromFlow = sessionStorage.getItem('adminNotifReturn') === '1'; } catch(err) {}
            if (fromFlow || window.location.hash === '#notab') {
                e.preventDefault();
                try { sessionStorage.removeItem('adminNotifReturn'); } catch(err) {}
                closeTabThenFallback(back.href);
            }
        });
    }
})();
</script>
</body>
</html>