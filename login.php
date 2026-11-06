<?php
require 'db.php';
$error = '';

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
<html>
<head><title>Login</title></head>
<body>
    <h2>Classroom Cleanliness System</h2>
    <?php if ($error) echo "<p style='color:red'>" . htmlspecialchars($error) . "</p>"; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input name="username" placeholder="Username" required><br><br>
        <input name="password" type="password" placeholder="Password" required><br><br>
        <button type="submit">Log in</button>
    </form>
</body>
</html>