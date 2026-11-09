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
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login</title>
<link rel="stylesheet" href="style.css">
</head>
<body class="landing">
<div class="landing-top"><span class="logo">CICI</span></div>
<main class="container">
    <h1 class="title">Classroom<br>Cleanliness</h1>
    <p class="tagline">Cleanliness Today,<br>Excellence Tomorrow!</p>

    <div class="card login-box">
        <?php if ($error) echo "<p class='alert error'>" . htmlspecialchars($error) . "</p>"; ?>
        <form method="post">
            <?= csrf_field() ?>
            <label for="u">Username:</label>
            <input id="u" name="username" required autofocus>
            <label for="p">Password:</label>
            <input id="p" name="password" type="password" required>
            <div class="actions">
                <a class="btn" href="#about">About</a>
                <button type="submit" class="btn">Log in</button>
            </div>
        </form>
    </div>

    <section id="about" class="card">
        <h2>About Classroom Cleanliness</h2>
        <p>Maintaining a clean and healthy school environment is everyone's responsibility. Our Cleanliness Monitoring System is designed to promote cleanliness, discipline, and environmental awareness by monitoring and evaluating the cleanliness of classrooms and school facilities. Through daily cleanliness ratings, proper waste management, and regular evaluations, we aim to encourage students and school personnel to take responsibility for keeping their surroundings clean and organized. Together, let's build a cleaner, healthier, and more comfortable learning environment for everyone. "Clean Surroundings, Healthy Minds, Brighter Futures!"</p>
    </section>
</main>
<footer class="footer">Classroom Cleanliness</footer>
</body>
</html>