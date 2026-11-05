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
    $room_number = trim($_POST['room_number'] ?? '');
    $building = trim($_POST['building'] ?? '');
    if ($building === '') $building = null;

    try {
        if ($action === 'add' || $action === 'update') {
            if ($room_number === '') {
                $message = 'Room number is required.';
            } elseif ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO rooms (room_number, building) VALUES (?, ?)");
                $stmt->execute([$room_number, $building]);
                header('Location: rooms.php');
                exit;
            } else {
                $stmt = $pdo->prepare("UPDATE rooms SET room_number = ?, building = ? WHERE room_id = ?");
                $stmt->execute([$room_number, $building, (int)$_POST['room_id']]);
                header('Location: rooms.php');
                exit;
            }
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM rooms WHERE room_id = ?");
            $stmt->execute([(int)$_POST['room_id']]);
            header('Location: rooms.php');
            exit;
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            $message = 'That room already exists.';
        } elseif ($e->getCode() === '23503') {
            $message = 'Cannot delete: this room is used in a class schedule.';
        } else {
            throw $e;
        }
    }
}

// Which room is being edited?
$edit_room = null;
$edit_id = (int)($_GET['edit'] ?? ($action === 'update' ? $_POST['room_id'] : 0));
if ($is_admin && $edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE room_id = ?");
    $stmt->execute([$edit_id]);
    $edit_room = $stmt->fetch(PDO::FETCH_ASSOC);
}

$rooms = $pdo->query("SELECT * FROM rooms ORDER BY room_number, building")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head><title>Rooms</title></head>
<body>
    <a href="dashboard.php">&larr; Back to dashboard</a>
    <h2>Manage Rooms</h2>

    <?php if ($message) echo "<p style='color:red'>" . htmlspecialchars($message) . "</p>"; ?>

    <?php if ($is_admin): ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $edit_room ? 'update' : 'add' ?>">
        <?php if ($edit_room): ?>
            <input type="hidden" name="room_id" value="<?= $edit_room['room_id'] ?>">
        <?php endif; ?>
        <input name="room_number" placeholder="Room number" maxlength="20" required
               value="<?= htmlspecialchars($edit_room['room_number'] ?? '') ?>">
        <input name="building" placeholder="Building" maxlength="50"
               value="<?= htmlspecialchars($edit_room['building'] ?? '') ?>">
        <button type="submit"><?= $edit_room ? 'Save Changes' : 'Add Room' ?></button>
        <?php if ($edit_room): ?><a href="rooms.php">Cancel</a><?php endif; ?>
    </form>
    <?php else: ?>
        <p><i>View only. Only admins can change rooms.</i></p>
    <?php endif; ?>

    <br>
    <table border="1" cellpadding="6">
        <tr>
            <th>ID</th><th>Room Number</th><th>Building</th>
            <?php if ($is_admin) echo '<th>Actions</th>'; ?>
        </tr>
        <?php foreach ($rooms as $r): ?>
        <tr>
            <td><?= $r['room_id'] ?></td>
            <td><?= htmlspecialchars($r['room_number']) ?></td>
            <td><?= htmlspecialchars($r['building'] ?? '') ?></td>
            <?php if ($is_admin): ?>
            <td>
                <a href="rooms.php?edit=<?= $r['room_id'] ?>">Edit</a>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('Delete this room?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="room_id" value="<?= $r['room_id'] ?>">
                    <button type="submit">Delete</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>