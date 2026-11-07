<?php
require 'db.php';
require_login();
if (!is_admin()) {
    http_response_code(403);
    exit('Admins only.');
}
$message = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $username = trim($_POST['username'] ?? '');
            $full_name = trim($_POST['full_name'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? '';
            $section_id = (int)($_POST['section_id'] ?? 0);

            if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
                $message = 'Username: 3-50 letters, numbers or underscore.';
            } elseif ($full_name === '' || mb_strlen($full_name) > 100) {
                $message = 'Full name is required (max 100 characters).';
            } elseif (strlen($password) < 8) {
                $message = 'Password must be at least 8 characters.';
            } elseif (!in_array($role, ['admin', 'user'], true)) {
                $message = 'Invalid role.';
            } elseif ($role === 'user' && !$section_id) {
                $message = 'A section account needs a section.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, section_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $full_name,
                    $role,
                    $role === 'user' ? $section_id : null
                ]);
                $ok = 'Account created.';
            }
        } elseif ($action === 'reset') {
            $password = $_POST['password'] ?? '';
            if (strlen($password) < 8) {
                $message = 'Password must be at least 8 characters.';
            } else {
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                $stmt->execute([password_hash($password, PASSWORD_DEFAULT), (int)($_POST['user_id'] ?? 0)]);
                $ok = 'Password changed.';
            }
        } elseif ($action === 'toggle') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid === (int)$_SESSION['user_id']) {
                $message = 'You cannot deactivate your own account.';
            } else {
                $stmt = $pdo->prepare("UPDATE users SET active = NOT active WHERE user_id = ?");
                $stmt->execute([$uid]);
                $ok = 'Account updated.';
            }
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            $message = 'That username already exists.';
        } else {
            throw $e;
        }
    }
}

$sections = $pdo->query("SELECT section_id, section_name FROM sections ORDER BY section_name")->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query("
    SELECT u.user_id, u.username, u.full_name, u.role, u.active, s.section_name
    FROM users u LEFT JOIN sections s ON s.section_id = u.section_id
    ORDER BY u.role, u.username
")->fetchAll(PDO::FETCH_ASSOC);
?>
<?php $title = 'users'; include 'header.php'; ?>
    
    <h2>Manage Users</h2>

    <?php if ($message) echo "<p class='alert error'>" . htmlspecialchars($message) . "</p>"; ?>
    <?php if ($ok) echo "<p class='alert ok'>" . htmlspecialchars($ok) . "</p>"; ?>

    <h3>Add account</h3>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <input name="username" placeholder="Username" maxlength="50" required>
        <input name="full_name" placeholder="Full name" maxlength="100" required>
        <input name="password" type="password" placeholder="Password (min 8)" required>
        <select name="role">
            <option value="user">Section account</option>
            <option value="admin">Admin</option>
        </select>
        <select name="section_id">
            <option value="">-- Section (for section accounts) --</option>
            <?php foreach ($sections as $s): ?>
                <option value="<?= $s['section_id'] ?>"><?= htmlspecialchars($s['section_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Create</button>
    </form>

    <h3>Accounts</h3>
    <div class="table-wrap">
        <table>
        <tr><th>Username</th><th>Name</th><th>Role</th><th>Section</th><th>Status</th><th>Actions</th></tr>
        <?php foreach ($users as $u): ?>
        <tr>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars($u['full_name']) ?></td>
            <td><?= htmlspecialchars($u['role']) ?></td>
            <td><?= htmlspecialchars($u['section_name'] ?? '-') ?></td>
            <td><?= $u['active'] ? 'Active' : '<b>Deactivated</b>' ?></td>
            <td>
                <form method="post" style="display:inline" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset">
                    <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                    <input name="password" type="password" placeholder="New password" required>
                    <button type="submit">Change password</button>
                </form>
                <?php if ((int)$u['user_id'] !== (int)$_SESSION['user_id']): ?>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('Change this account status?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                    <button type="submit"><?= $u['active'] ? 'Deactivate' : 'Activate' ?></button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </table>
    </div>

<?php include 'footer.php'; ?>