<?php
// Included by require_login() when users.must_change is TRUE. Shows only this popup.
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $new = $_POST['new'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ? AND must_change");
    $stmt->execute([$_SESSION['user_id']]);
    $hash = $stmt->fetchColumn();

    if (!$hash) {
        header('Location: dashboard.php'); exit;
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif (strlen($new) > 72) {
        $error = 'New password is too long (max 72 characters).';
    } elseif ($new !== $confirm) {
        $error = 'The passwords do not match.';
    } elseif (password_verify($new, $hash)) {
        $error = 'Choose a different password from the temporary one.';
    } else {
        $pdo->prepare("UPDATE users SET password_hash = ?, must_change = FALSE WHERE user_id = ?")
            ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
        session_regenerate_id(true);
        header('Location: dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set your password</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 20px;
         font-family: "Segoe UI", Arial, sans-serif; font-size: 15px; color: #14231a;
         background: linear-gradient(180deg, #a8d5b5 0, #17492f 100%) fixed; }
  .modal { width: 100%; max-width: 420px; background: #fff; border-radius: 16px; padding: 28px;
           box-shadow: 0 20px 60px rgba(0,0,0,.35); }
  .modal h1 { margin: 0 0 6px; font-size: 1.35rem; color: #17492f; }
  .modal p { margin: 0 0 18px; color: #64756a; font-size: .88rem; }
  .err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; font-size: .88rem; }
  label { display: block; font-size: .78rem; font-weight: 600; margin: 14px 0 6px; }
  .pw { position: relative; }
  .pw input { width: 100%; min-height: 44px; padding: 10px 64px 10px 14px; border: 1px solid #d4e0d8; border-radius: 8px; font: inherit; }
  .pw input:focus { outline: none; border-color: #16834a; box-shadow: 0 0 0 3px rgba(22,131,74,.15); }
  .pw button { position: absolute; right: 8px; top: 7px; min-height: 30px; padding: 0 8px; background: none; border: none; color: #16834a; font-size: .75rem; font-weight: 700; cursor: pointer; }
  .hint { font-size: .75rem; color: #64756a; margin: 10px 0 16px; }
  .go { width: 100%; min-height: 46px; background: #226b46; color: #fff; border: none; border-radius: 8px; font: inherit; font-weight: 700; cursor: pointer; }
  .go:hover { background: #17492f; }
  .out { text-align: center; margin-top: 14px; }
  .out button { background: none; border: none; color: #64756a; font-size: .78rem; cursor: pointer; text-decoration: underline; }
</style>
</head>
<body>
<div class="modal" role="dialog" aria-modal="true" aria-labelledby="t">
    <h1 id="t">Set your own password</h1>
    <p>You signed in with a temporary password. Choose a new one to continue.</p>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <label for="new">New password</label>
        <div class="pw"><input id="new" name="new" type="password" required minlength="8" autofocus autocomplete="new-password"><button type="button" class="show">Show</button></div>
        <label for="confirm">Confirm new password</label>
        <div class="pw"><input id="confirm" name="confirm" type="password" required minlength="8" autocomplete="new-password"><button type="button" class="show">Show</button></div>
        <div class="hint">Use at least 8 characters.</div>
        <button type="submit" class="go">Save password</button>
    </form>
    <form method="post" action="logout.php" class="out"><?= csrf_field() ?><button type="submit">Sign out</button></form>
</div>
<script src="app.js"></script>
</body>
</html>