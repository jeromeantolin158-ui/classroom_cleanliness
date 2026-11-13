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
        $pdo->prepare("UPDATE users SET password_hash = ?, must_change = FALSE WHERE user_id = ?")
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


$stmt->execute([$_SESSION['user_id']]);
$forced = (bool)$stmt->fetchColumn();

$title = 'Profile';
$subtitle = 'Your account details and password.';
include 'header.php';
?>
<h1 class="s-h1">My profile</h1>
<p class="s-sub">Your account details and security.</p>

<?php if ($message) echo "<p class='s-alert'>" . h($message) . "</p>"; ?>
<?php if ($ok) echo "<p class='s-alert ok'>" . h($ok) . "</p>"; ?>

<div class="s-prof">
    <div>
        <section class="s-card">
            <span class="s-pill go"><?= $me['role'] === 'admin' ? 'Administrator' : 'Class representative' ?></span>
            <h2 style="margin:12px 0 2px"><?= h($me['full_name']) ?></h2>
            <div class="s-muted"><?= h($me['section_name'] ?? '-') ?></div>
            <p><span class="s-pill <?= $me['active'] ? 'go' : 'warn' ?>"><?= $me['active'] ? '● Active account' : 'Deactivated' ?></span></p>
            <hr style="border:none;border-top:1px solid #e2ebe5;margin:14px 0">
            <div class="s-k">Username</div><b><?= h($me['username']) ?></b>
        </section>
        <section class="s-card s-handoff">
            <b>Need to update your details?</b>
            <p class="s-muted">Your school manages your section and role. Contact your administrator if something doesn't look right.</p>
        </section>
    </div>
    <div>
        <section class="s-card">
            <h3 class="s-ct">Personal information</h3>
            <div class="s-info">
                <div><small>Full name</small><b><?= h($me['full_name']) ?></b></div>
                <div><small>Username</small><b><?= h($me['username']) ?></b></div>
                <div><small>Section</small><b><?= h($me['section_name'] ?? '-') ?></b></div>
                <div><small>Role</small><b><?= $me['role'] === 'admin' ? 'Administrator' : 'Class representative' ?></b></div>
            </div>
        </section>
        <section class="s-card">
            <h3 class="s-ct">Change password</h3>
            <form method="post" autocomplete="off" style="display:block">
                <?= csrf_field() ?>
                <?php foreach ([['current', 'Current password', 'current-password'], ['new', 'New password', 'new-password'], ['confirm', 'Confirm new password', 'new-password']] as [$n, $l, $ac]): ?>
                <div class="s-field">
                    <label for="<?= $n ?>"><?= $l ?></label>
                    <div class="s-pw">
                        <input id="<?= $n ?>" name="<?= $n ?>" type="password" required autocomplete="<?= $ac ?>">
                        <button type="button" class="show">Show</button>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="s-muted" style="margin-bottom:14px">Use at least 8 characters.</div>
                <button type="submit" class="s-btn">Update password</button>
            </form>
        </section>
    </div>
</div>
<?php include 'footer.php'; ?>