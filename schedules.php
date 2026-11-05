<?php
require 'db.php';
require_login();
$is_admin = is_admin();
$message = '';
$action = '';
$days = [1=>'Monday', 2=>'Tuesday', 3=>'Wednesday', 4=>'Thursday', 5=>'Friday', 6=>'Saturday', 7=>'Sunday'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$is_admin) {
        http_response_code(403);
        exit('Admins only.');
    }

    $action = $_POST['action'] ?? '';
    $room_id = (int)($_POST['room_id'] ?? 0);
    $section_id = (int)($_POST['section_id'] ?? 0);
    $day = (int)($_POST['day_of_week'] ?? 0);
    $start = $_POST['start_time'] ?? '';
    $end = $_POST['end_time'] ?? '';

    try {
        if ($action === 'add' || $action === 'update') {
            if (!$room_id || !$section_id || !isset($days[$day]) || $start === '' || $end === '') {
                $message = 'Please fill in every field.';
            } elseif ($end <= $start) {
                $message = 'End time must be later than start time.';
            } elseif ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO schedules (room_id, section_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$room_id, $section_id, $day, $start, $end]);
                header('Location: schedules.php');
                exit;
            } else {
                $stmt = $pdo->prepare("UPDATE schedules SET room_id = ?, section_id = ?, day_of_week = ?, start_time = ?, end_time = ? WHERE schedule_id = ?");
                $stmt->execute([$room_id, $section_id, $day, $start, $end, (int)$_POST['schedule_id']]);
                header('Location: schedules.php');
                exit;
            }
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM schedules WHERE schedule_id = ?");
            $stmt->execute([(int)$_POST['schedule_id']]);
            header('Location: schedules.php');
            exit;
        }
    } catch (PDOException $e) {
        $code = $e->getCode();
        if ($code === '23P01') {
            if (strpos($e->getMessage(), 'no_room_overlap') !== false) {
                $message = 'That room is already booked at an overlapping time on that day.';
            } else {
                $message = 'That section already has a class at an overlapping time on that day.';
            }
        } elseif ($code === '23503') {
            $message = 'Cannot delete: this schedule already has usage records.';
        } elseif ($code === '23514') {
            $message = 'Invalid day or time.';
        } else {
            throw $e;
        }
    }
}

// Which schedule is being edited?
$edit = null;
$edit_id = (int)($_GET['edit'] ?? ($action === 'update' ? ($_POST['schedule_id'] ?? 0) : 0));
if ($is_admin && $edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM schedules WHERE schedule_id = ?");
    $stmt->execute([$edit_id]);
    $edit = $stmt->fetch(PDO::FETCH_ASSOC);
}

$rooms = $pdo->query("SELECT room_id, room_number, building FROM rooms ORDER BY room_number")->fetchAll(PDO::FETCH_ASSOC);
$sections = $pdo->query("SELECT section_id, section_name FROM sections ORDER BY section_name")->fetchAll(PDO::FETCH_ASSOC);

$schedules = $pdo->query("
    SELECT sc.schedule_id, sc.day_of_week, sc.start_time, sc.end_time,
           r.room_number, r.building, s.section_name
    FROM schedules sc
    JOIN rooms r ON r.room_id = sc.room_id
    JOIN sections s ON s.section_id = sc.section_id
    ORDER BY sc.day_of_week, sc.start_time, r.room_number
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head><title>Schedules</title></head>
<body>
    <a href="dashboard.php">&larr; Back to dashboard</a>
    <h2>Manage Schedules</h2>

    <?php if ($message) echo "<p style='color:red'>" . htmlspecialchars($message) . "</p>"; ?>

    <?php if ($is_admin): ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'add' ?>">
        <?php if ($edit): ?>
            <input type="hidden" name="schedule_id" value="<?= $edit['schedule_id'] ?>">
        <?php endif; ?>

        <select name="section_id" required>
            <option value="">-- Section --</option>
            <?php foreach ($sections as $s): ?>
                <option value="<?= $s['section_id'] ?>" <?= ($edit['section_id'] ?? 0) == $s['section_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s['section_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="room_id" required>
            <option value="">-- Room --</option>
            <?php foreach ($rooms as $r): ?>
                <option value="<?= $r['room_id'] ?>" <?= ($edit['room_id'] ?? 0) == $r['room_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r['room_number'] . ' ' . ($r['building'] ?? '')) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="day_of_week" required>
            <option value="">-- Day --</option>
            <?php foreach ($days as $n => $label): ?>
                <option value="<?= $n ?>" <?= ($edit['day_of_week'] ?? 0) == $n ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>

        Start <input type="time" name="start_time" required
                     value="<?= $edit ? substr($edit['start_time'], 0, 5) : '' ?>">
        End <input type="time" name="end_time" required
                   value="<?= $edit ? substr($edit['end_time'], 0, 5) : '' ?>">

        <button type="submit"><?= $edit ? 'Save Changes' : 'Add Schedule' ?></button>
        <?php if ($edit): ?><a href="schedules.php">Cancel</a><?php endif; ?>
    </form>
    <?php else: ?>
        <p><i>View only. Only admins can change schedules.</i></p>
    <?php endif; ?>

    <br>
    <table border="1" cellpadding="6">
        <tr>
            <th>Day</th><th>Time</th><th>Section</th><th>Room</th>
            <?php if ($is_admin) echo '<th>Actions</th>'; ?>
        </tr>
        <?php foreach ($schedules as $sc): ?>
        <tr>
            <td><?= $days[$sc['day_of_week']] ?></td>
            <td><?= substr($sc['start_time'], 0, 5) ?> - <?= substr($sc['end_time'], 0, 5) ?></td>
            <td><?= htmlspecialchars($sc['section_name']) ?></td>
            <td><?= htmlspecialchars($sc['room_number'] . ' ' . ($sc['building'] ?? '')) ?></td>
            <?php if ($is_admin): ?>
            <td>
                <a href="schedules.php?edit=<?= $sc['schedule_id'] ?>">Edit</a>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('Delete this schedule?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="schedule_id" value="<?= $sc['schedule_id'] ?>">
                    <button type="submit">Delete</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>