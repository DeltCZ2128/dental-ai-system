<?php

require_once __DIR__ . '/config.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

$message = '';
$messageType = 'success';
$tokenValid = false;
$tokenUser = null;

if ($token !== '') {
    try {
        $stmt = $pdo->prepare("
            SELECT id, username
            FROM users
            WHERE reset_token = ?
            AND reset_expires IS NOT NULL
            AND reset_expires > NOW()
            LIMIT 1
        ");

        $stmt->execute([$token]);
        $tokenUser = $stmt->fetch(PDO::FETCH_ASSOC);

        $tokenValid = (bool)$tokenUser;
    } catch (Throwable $e) {
        $tokenValid = false;
    }
}


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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 6) {
        $message = 'Password must be at least 6 characters.';
        $messageType = 'danger';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'Passwords do not match.';
        $messageType = 'danger';
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE users
                SET password = ?,
                    reset_token = NULL,
                    reset_expires = NULL
                WHERE id = ?
            ");

            $stmt->execute([
                password_hash($newPassword, PASSWORD_DEFAULT),
                (int)$tokenUser['id']
            ]);

            $tokenValid = false;
            $message = 'Password reset successful. You can now log in with your new password.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'Unable to reset password. Please try again.';
            $messageType = 'danger';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset Password | Abalos Dental Clinic</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
body { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 25px; background: linear-gradient(135deg, #edfaff, #f8fdff); font-family: "DM Sans", sans-serif; color: #173b4d; }
.reset-card { width: 100%; max-width: 430px; background: #fff; border: 1px solid #dcecf1; border-radius: 22px; padding: 42px; box-shadow: 0 25px 70px rgba(22,92,113,.12); }
</style>
</head>
<body>
<div class="reset-card">
<div class="text-center mb-4">
<i class="bi bi-shield-lock-fill fs-1 text-info"></i>
<h2 class="mt-3 fs-4 fw-bold">Reset Password</h2>
</div>
<?php if ($message !== ''): ?>
<div class="alert alert-<?= e($messageType) ?>" role="alert"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($tokenValid): ?>
<form method="POST" autocomplete="off">
<?= csrfField() ?>
<input type="hidden" name="token" value="<?= e($token) ?>">
<div class="mb-3">
<label class="form-label fw-bold small">New Password (min 6 characters)</label>
<input type="password" name="new_password" class="form-control" required minlength="6" placeholder="Enter new password">
</div>
<div class="mb-3">
<label class="form-label fw-bold small">Confirm Password</label>
<input type="password" name="confirm_password" class="form-control" required minlength="6" placeholder="Confirm new password">
</div>
<button type="submit" class="btn btn-info text-white w-100 fw-bold">Set New Password</button>
</form>
<?php elseif ($message === ''): ?>
<div class="alert alert-danger" role="alert">Invalid or expired reset link. Please request a new one.</div>
<?php endif; ?>
<div class="text-center mt-3">
<a href="login.php" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Back to Login</a>
</div>
</div>
</body>
</html>
