<?php
require 'db.php';
$error = '';

if (!empty($_SESSION['user_id'])) { header('Location: dashboard.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = mb_substr(trim($_POST['username'] ?? ''), 0, 50);
    $ip = $_SERVER['REMOTE_ADDR'];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND ip = ? AND attempted_at > NOW() - interval '10 minutes'");
    $stmt->execute([$username, $ip]);

    if ($stmt->fetchColumn() >= 5) {
        $error = 'Too many failed attempts. Try again in 10 minutes.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['active'] && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
            $pdo->prepare("DELETE FROM login_attempts WHERE username = ? AND ip = ?")->execute([$username, $ip]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            header('Location: dashboard.php');
            exit;
        }
        $pdo->prepare("INSERT INTO login_attempts (username, ip) VALUES (?, ?)")->execute([$username, $ip]);
        $error = 'Wrong username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in</title>
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="login.css">
</head>
<body class="auth">
<div class="split">
    <?php include 'auth_left.php'; ?>
    <div class="auth-right">
        <div class="auth-form">
            <h2>Please sign in</h2>
            <p class="hint">Sign in to your classroom workspace.</p>
            <?php if ($error) echo "<p class='alert error'>" . htmlspecialchars($error) . "</p>"; ?>
            <?php if (isset($_GET['sent'])) echo "<p class='alert ok'>Request sent. Your administrator will give you a temporary password.</p>"; ?>
            <form method="post" style="display:block">
                <?= csrf_field() ?>
                <label for="u">Username</label>
                <input id="u" name="username" required autofocus autocomplete="username">
                <label for="p">Password</label>
                <div class="pw">
                    <input id="p" name="password" type="password" required autocomplete="current-password">
                    <button type="button" id="toggle" aria-label="Show password">Show</button>
                </div>
                <div class="forgot"><a href="forgot.php">Forgot password?</a></div>
                <button type="submit" class="submit">Log in</button>
            </form>
            <hr>
            <div class="help">Having trouble signing in?<b>Contact your administrator</b></div>
        </div>
    </div>
</div>
<script>
document.getElementById('toggle').addEventListener('click', function () {
    var p = document.getElementById('p');
    var show = p.type === 'password';
    p.type = show ? 'text' : 'password';
    this.textContent = show ? 'Hide' : 'Show';
});
</script>
</body>
</html>