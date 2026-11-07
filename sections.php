<?php
require 'db.php';
require_login();
$is_admin = is_admin();
$message = '';
$action = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$is_admin) {
        http_response_code(403);
        exit('Admins only.');
    }

    $action = $_POST['action'] ?? '';
    $section_name = mb_strtoupper(trim($_POST['section_name'] ?? ''));
    $program = trim($_POST['program'] ?? '');
    if ($program === '') $program = null;

    try {
        if ($action === 'add' || $action === 'update') {
            if ($section_name === '') {
                $message = 'Section name is required.';
            } elseif ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO sections (section_name, program) VALUES (?, ?)");
                $stmt->execute([$section_name, $program]);
                header('Location: sections.php');
                exit;
            } else {
                $stmt = $pdo->prepare("UPDATE sections SET section_name = ?, program = ? WHERE section_id = ?");
                $stmt->execute([$section_name, $program, (int)$_POST['section_id']]);
                header('Location: sections.php');
                exit;
            }
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM sections WHERE section_id = ?");
            $stmt->execute([(int)$_POST['section_id']]);
            header('Location: sections.php');
            exit;
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            $message = 'That section already exists.';
        } elseif ($e->getCode() === '23503') {
            $message = 'Cannot delete: this section is used in a schedule or rating.';
        } else {
            throw $e;
        }
    }
}

$edit_section = null;
$edit_id = (int)($_GET['edit'] ?? ($action === 'update' ? $_POST['section_id'] : 0));
if ($is_admin && $edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM sections WHERE section_id = ?");
    $stmt->execute([$edit_id]);
    $edit_section = $stmt->fetch(PDO::FETCH_ASSOC);
}

$sections = $pdo->query("SELECT * FROM sections ORDER BY section_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<?php $title = 'Sections'; include 'header.php'; ?>
    
    <h2>Manage Sections</h2>

    <?php if ($message) echo "<p class='alert error'>" . htmlspecialchars($message) . "</p>"; ?>

    <?php if ($is_admin): ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $edit_section ? 'update' : 'add' ?>">
        <?php if ($edit_section): ?>
            <input type="hidden" name="section_id" value="<?= $edit_section['section_id'] ?>">
        <?php endif; ?>
        <input name="section_name" placeholder="Section (e.g. BSIT 2-A)" maxlength="50" required
               value="<?= htmlspecialchars($edit_section['section_name'] ?? '') ?>">
        <input name="program" placeholder="Program (e.g. BSIT)" maxlength="50"
               value="<?= htmlspecialchars($edit_section['program'] ?? '') ?>">
        <button type="submit"><?= $edit_section ? 'Save Changes' : 'Add Section' ?></button>
        <?php if ($edit_section): ?><a href="sections.php">Cancel</a><?php endif; ?>
    </form>
    
    <?php else: ?>
        <p><i>View only. Only admins can change sections.</i></p>
    <?php endif; ?>

    <br>
    <div class="table-wrap">
        <table>
        <tr>
            <th>ID</th><th>Section</th><th>Program</th>
            <?php if ($is_admin) echo '<th>Actions</th>'; ?>
        </tr>
        <?php foreach ($sections as $s): ?>
        <tr>
            <td><?= $s['section_id'] ?></td>
            <td><?= htmlspecialchars($s['section_name']) ?></td>
            <td><?= htmlspecialchars($s['program'] ?? '') ?></td>
            <?php if ($is_admin): ?>
            <td>
            <a href="sections.php?edit=<?= $s['section_id'] ?>">Edit</a>
                <form method="post" style="display:inline"
                    onsubmit="return confirm('Delete this section?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="section_id" value="<?= $s['section_id'] ?>">
                    <button type="submit">Delete</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </div>
    </table>
<?php include 'footer.php'; ?>