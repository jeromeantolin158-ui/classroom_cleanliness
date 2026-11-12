<?php
require 'db.php';
require_login();
if (!is_admin()) {
    http_response_code(403);
    exit('Admins only.');
}
$message = '';
$ok = '';
$action = '';
$me = (int)$_SESSION['user_id'];

// One-time notice after creating an account (shows the temporary password)
if (!empty($_SESSION['flash_ok'])) { $ok = $_SESSION['flash_ok']; unset($_SESSION['flash_ok']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $role = $_POST['role'] ?? '';
    $section_id = (int)($_POST['section_id'] ?? 0);

    try {
        if ($action === 'add' || $action === 'update') {
            if ($full_name === '' || mb_strlen($full_name) > 100) {
                $message = 'Full name is required (max 100 characters).';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
                $message = 'Enter a valid email address.';
            } elseif (!in_array($role, ['admin', 'user'], true)) {
                $message = 'Invalid role.';
            } elseif ($role === 'user' && !$section_id) {
                $message = 'A section account needs an assigned section.';
            } elseif ($action === 'add' && !preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
                $message = 'Username: 3-50 letters, numbers or underscore.';
            } elseif ($action === 'add') {
                $temp = bin2hex(random_bytes(5));
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, email, role, section_id, must_change) VALUES (?, ?, ?, ?, ?, ?, TRUE)");
                $stmt->execute([$username, password_hash($temp, PASSWORD_DEFAULT), $full_name, $email, $role, $role === 'user' ? $section_id : null]);
                $_SESSION['flash_ok'] = "Account created. Temporary password for $username: $temp (shown once, give it privately. They must change it at first sign-in.)";
                header('Location: users.php');
                exit;
            } else {
                $uid = (int)($_POST['user_id'] ?? 0);
                if ($uid === $me) {
                    // Never let an admin lock themselves out: role and status stay as they are.
                    $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ? WHERE user_id = ?");
                    $stmt->execute([$full_name, $email, $uid]);
                } else {
                    $active = ($_POST['active'] ?? '1') === '1' ? 't' : 'f';
                    $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, role = ?, section_id = ?, active = ?::boolean WHERE user_id = ?");
                    $stmt->execute([$full_name, $email, $role, $role === 'user' ? $section_id : null, $active, $uid]);
                }
                header('Location: users.php');
                exit;
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
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            $message = stripos($e->getMessage(), 'email') !== false ? 'That email is already used by another account.' : 'That username already exists.';
        } else {
            throw $e;
        }
    }
}

$edit = null;
$edit_id = (int)($_GET['edit'] ?? ($action === 'update' ? ($_POST['user_id'] ?? 0) : 0));
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT user_id, username, full_name, email, role, section_id, active FROM users WHERE user_id = ?");
    $stmt->execute([$edit_id]);
    $edit = $stmt->fetch(PDO::FETCH_ASSOC);
}

$sections = $pdo->query("SELECT section_id, section_name FROM sections ORDER BY section_name")->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query("
    SELECT u.user_id, u.username, u.full_name, u.email, u.role, u.active, s.section_name
    FROM users u LEFT JOIN sections s ON s.section_id = u.section_id
    ORDER BY u.role, u.username
")->fetchAll(PDO::FETCH_ASSOC);

$fv = $edit ?? ($message && $action === 'add' ? ['username' => $username, 'full_name' => $full_name, 'email' => $email, 'role' => $role, 'section_id' => $section_id] : []);
$openForm = $edit || ($message && $action === 'add');
$self = $edit && (int)$edit['user_id'] === $me;
$title = 'Users'; include 'header.php'; ?>
<div class="a-head">
    <div><h1 class="s-h1">Users</h1><p class="s-sub">Manage administrator and section accounts.</p></div>
    <span class="s-date"><?= date('D, F j, Y') ?></span>
</div>

<?php if ($message) echo "<p class='s-alert'>" . h($message) . "</p>"; ?>
<?php if ($ok) echo "<p class='s-alert ok'>" . h($ok) . "</p>"; ?>

<div class="a-bar">
    <input type="search" data-search placeholder="Search users...">
    <select data-filter>
        <option value="">All roles</option>
        <option>Administrator</option>
        <option>Section account</option>
    </select>
    <button type="button" class="s-btn" data-open="formModal">+ Add user</button>
</div>

<section class="a-card">
    <h3 id="a-count"><?= count($users) ?> users</h3>
    <p class="s-muted">Manage assignments and access from one place.</p>
    <div class="table-wrap"><table class="a-table tight" data-paged="8" data-noun="users">
        <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Section</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): $role_l = $u['role'] === 'admin' ? 'Administrator' : 'Section account'; ?>
        <tr class="r" data-q="<?= h(strtolower($u['full_name'] . ' ' . $u['username'] . ' ' . ($u['email'] ?? '') . ' ' . ($u['section_name'] ?? ''))) ?>" data-f="<?= $role_l ?>">
            <td class="nm"><?= h($u['full_name']) ?></td>
            <td><?= h($u['username']) ?></td>
            <td><?= h($u['email'] ?? '—') ?></td>
            <td><?= $role_l ?></td>
            <td><?= h($u['section_name'] ?? '—') ?></td>
            <td><span class="a-st <?= $u['active'] ? 'go' : 'off' ?>"><?= $u['active'] ? 'Active' : 'Deactivated' ?></span></td>
            <td>
                <a class="a-btn" href="users.php?edit=<?= (int)$u['user_id'] ?>">Edit</a>
                <button type="button" class="a-btn" data-reset="<?= (int)$u['user_id'] ?>" data-name="<?= h($u['full_name']) ?>">Password</button>
            </td>
        </tr>
        <?php endforeach; ?>
        <tr class="none" hidden><td colspan="7">No users match.</td></tr>
        </tbody>
    </table></div>
    <div class="a-pager"><span id="a-showing"></span><div><button type="button" id="a-prev">Previous</button><button type="button" id="a-next">Next</button></div></div>
</section>

<section class="s-note"><b>Keep your campus records up to date</b><small>Set an account to Deactivated in Edit to block sign-in without deleting its history.</small></section>

<dialog id="formModal" class="a-modal fig" <?= $openForm ? 'data-autoopen' : '' ?>>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <h3><?= $edit ? 'Edit user' : 'Add user' ?></h3>
        <p class="a-sub"><?= $edit ? 'Update the selected record below.' : 'Create a new campus record.' ?></p>
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'add' ?>">
        <?php if ($edit): ?><input type="hidden" name="user_id" value="<?= (int)$edit['user_id'] ?>"><?php endif; ?>
        <label>Full name</label><input name="full_name" placeholder="Enter full name" maxlength="100" required value="<?= h($fv['full_name'] ?? '') ?>">
        <?php if (!$edit): ?>
        <label>Username</label><input name="username" placeholder="Enter username (used to sign in)" maxlength="50" required value="<?= h($fv['username'] ?? '') ?>">
        <?php endif; ?>
        <label>Email</label><input type="email" name="email" placeholder="Enter email" maxlength="100" required value="<?= h($fv['email'] ?? '') ?>">
        <label>Role</label>
        <select name="role" data-role required <?= $self ? 'disabled' : '' ?>>
            <option value="user" <?= ($fv['role'] ?? 'user') === 'user' ? 'selected' : '' ?>>Section account</option>
            <option value="admin" <?= ($fv['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrator</option>
        </select>
        <?php if ($self): ?><input type="hidden" name="role" value="admin"><?php endif; ?>
        <label>Assigned section</label>
        <select name="section_id" data-sect <?= $self ? 'disabled' : '' ?>>
            <option value="">Enter assigned section</option>
            <?php foreach ($sections as $s): ?><option value="<?= $s['section_id'] ?>" <?= (int)($fv['section_id'] ?? 0) === (int)$s['section_id'] ? 'selected' : '' ?>><?= h($s['section_name']) ?></option><?php endforeach; ?>
        </select>
        <?php if ($edit): ?>
        <label>Account status</label>
        <select name="active" <?= $self ? 'disabled' : '' ?>>
            <option value="1" <?= !empty($edit['active']) ? 'selected' : '' ?>>Active</option>
            <option value="0" <?= empty($edit['active']) ? 'selected' : '' ?>>Deactivated</option>
        </select>
        <?php if ($self): ?><p class="a-req">This is your own account, so your role and status can't be changed here.</p><?php endif; ?>
        <?php else: ?>
        <p class="a-req">A temporary password is created for the new account. They must change it when they first sign in.</p>
        <?php endif; ?>
        <div class="a-acts"><button type="button" class="s-btn soft" data-close>Cancel</button><button type="submit" class="s-btn"><?= $edit ? 'Save changes' : 'Add user' ?></button></div>
    </form>
</dialog>

<dialog id="resetModal" class="a-modal fig">
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <h3>Change password</h3>
        <p class="a-sub">For <b id="resetName"></b></p>
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="user_id" value="">
        <label>New password (min 8)</label><input name="password" type="password" required>
        <div class="a-acts"><button type="button" class="s-btn soft" data-close>Cancel</button><button type="submit" class="s-btn">Save</button></div>
    </form>
</dialog>
<?php include 'footer.php'; ?>
