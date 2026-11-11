<?php
require 'db.php';
if (!empty($_SESSION['user_id'])) { header('Location: dashboard.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = mb_substr(trim($_POST['username'] ?? ''), 0, 50);
    $ip = $_SERVER['REMOTE_ADDR'];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = '(forgot)' AND ip = ? AND attempted_at > NOW() - interval '10 minutes'");
    $stmt->execute([$ip]);
    if ($stmt->fetchColumn() >= 5) {
        $error = 'Too many requests. Try again in 10 minutes.';
    } else {
        $pdo->prepare("INSERT INTO login_attempts (username, ip) VALUES ('(forgot)', ?)")->execute([$ip]);

        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? AND active");
        $stmt->execute([$username]);
        $uid = $stmt->fetchColumn();
        if ($uid) {
            $pdo->prepare("INSERT INTO password_requests (user_id) VALUES (?) ON CONFLICT DO NOTHING")->execute([$uid]);
        }
        header('Location: login.php?sent=1');   // same answer whether or not the username exists
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot password</title>
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="login.css">
</head>
<body class="auth">
<div class="split">
    <?php include 'auth_left.php'; ?>
    <div class="auth-right">
        <div class="auth-form">
            <h2>Forgot your password?</h2>
            <p class="hint">Enter your username. Your administrator will be notified and will give you a temporary password.</p>
            <?php if ($error) echo "<p class='alert error'>" . htmlspecialchars($error) . "</p>"; ?>
            <form method="post" style="display:block">
                <?= csrf_field() ?>
                <label for="u">Username</label>
                <input id="u" name="username" required autofocus>
                <div class="forgot"><a href="login.php">&larr; Back to sign in</a></div>
                <button type="submit" class="submit">Notify administrator</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>