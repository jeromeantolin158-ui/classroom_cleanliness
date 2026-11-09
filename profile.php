<?php
require 'db.php';
require_login();

$message = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $current = $_POST['current'] ?? '';
    $new     = $_POST['new'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify($current, $hash)) {
        $message = 'Your current password is wrong.';
    } elseif (strlen($new) < 8) {
        $message = 'New password must be at least 8 characters.';
    } elseif (strlen($new) > 72) {
        $message = 'New password is too long (max 72 characters).';
    } elseif ($new !== $confirm) {
        $message = 'The new passwords do not match.';
    } elseif ($new === $current) {
        $message = 'The new password must be different from the current one.';
    } else {
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
            ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
        session_regenerate_id(true);
        $ok = 'Password changed.';
    }
}

$stmt = $pdo->prepare("
    SELECT u.username, u.full_name, u.role, u.active, s.section_name
    FROM users u LEFT JOIN sections s ON s.section_id = u.section_id
    WHERE u.user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);

$title = 'Profile';
$subtitle = 'Your account details and password.';
include 'header.php';
?>
<?php if ($message) echo "<p class='alert error'>" . htmlspecialchars($message) . "</p>"; ?>
<?php if ($ok) echo "<p class='alert ok'>" . htmlspecialchars($ok) . "</p>"; ?>

<div class="card login-box">
    <h3 style="margin-top:0">Profile</h3>
    <div class="table-wrap"><table>
        <tr><th>Name</th><td><?= htmlspecialchars($me['full_name']) ?></td></tr>
        <tr><th>Username</th><td><?= htmlspecialchars($me['username']) ?></td></tr>
        <tr><th>Role</th><td><?= $me['role'] === 'admin' ? 'Administrator' : 'Section account' ?></td></tr>
        <tr><th>Section</th><td><?= htmlspecialchars($me['section_name'] ?? '-') ?></td></tr>
        <tr><th>Status</th><td><?= $me['active'] ? 'Active' : 'Deactivated' ?></td></tr>
    </table></div>
</div>

<div class="card login-box">
    <h3 style="margin-top:0">Change password</h3>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <label for="c">Current password</label>
        <input id="c" name="current" type="password" required autocomplete="current-password">
        <label for="n">New password</label>
        <input id="n" name="new" type="password" required minlength="8" autocomplete="new-password">
        <label for="f">Confirm new password</label>
        <input id="f" name="confirm" type="password" required minlength="8" autocomplete="new-password">
        <button type="submit" class="btn">Change password</button>
    </form>
</div>
<?php include 'footer.php'; ?>