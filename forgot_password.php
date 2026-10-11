<?php

require_once __DIR__ . '/config.php';

$message = '';
$messageType = 'success';


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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $messageType = 'danger';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id
                FROM users
                WHERE username = ?
                LIMIT 1
            ");

            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $token = bin2hex(random_bytes(32));

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET reset_token = ?,
                        reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR)
                    WHERE id = ?
                ");

                $stmt->execute([$token, (int)$user['id']]);

                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    ? 'https'
                    : 'http';

                $basePath = rtrim(
                    str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')),
                    '/'
                );

                $resetLink = $scheme . '://' . $_SERVER['HTTP_HOST'] .
                    ($basePath !== '' ? $basePath : '') .
                    '/reset_password.php?token=' . $token;

                $subject = 'Password Reset - Abalos Dental Clinic';

                $body =
                    "Hello,\n\n" .
                    "You requested a password reset for your Abalos Dental Clinic account.\n\n" .
                    "Click the link below within 1 hour to set a new password:\n" .
                    $resetLink . "\n\n" .
                    "If you did not request this, please ignore this email.\n\n" .
                    "Thank you.";

                list($sent, $sendError) = sendClinicGmail($email, $subject, $body);

                if (!$sent) {
                    $message = 'Could not send reset email: ' . $sendError;
                    $messageType = 'danger';
                }
            }

            if ($message === '') {
                $message = 'If the email exists in our records, a reset link was sent. Please check the Gmail inbox (and spam folder).';
                $messageType = 'success';
            }
        } catch (Throwable $e) {
            $message = 'Unable to process request. Please try again later.';
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
<title>Forgot Password | Abalos Dental Clinic</title>
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
<i class="bi bi-key-fill fs-1 text-info"></i>
<h2 class="mt-3 fs-4 fw-bold">Forgot Password</h2>
<p class="text-muted small">Enter your account email. We will send a reset link to your Gmail.</p>
</div>
<?php if ($message !== ''): ?>
<div class="alert alert-<?= e($messageType) ?>" role="alert"><?= e($message) ?></div>
<?php endif; ?>
<form method="POST" autocomplete="off">
<?= csrfField() ?>
<div class="mb-3">
<label class="form-label fw-bold small">Email Address</label>
<input type="email" name="email" class="form-control" placeholder="Enter your email" required value="<?= e($_POST['email'] ?? '') ?>">
</div>
<button type="submit" class="btn btn-info text-white w-100 fw-bold">Send Reset Link</button>
</form>
<div class="text-center mt-3">
<a href="login.php" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Back to Login</a>
</div>
</div>
</body>
</html>
