<?php
require 'db.php';
require_login();
close_stale_sessions($pdo);
$message = '';

$stmt = $pdo->prepare("SELECT section_id FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$my_section = $stmt->fetchColumn();
$my_section = ($my_section === false || $my_section === null) ? null : (int)$my_section;
$can_act = !is_admin() && $my_section !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$can_act) {
        http_response_code(403);
        exit('Only a section account can log usage.');
    }

    $action = $_POST['action'] ?? '';
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    try {
        if ($action === 'in') {
            $sid = (int)($_POST['schedule_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT start_time, end_time FROM schedules AND ?::date BETWEEN valid_from AND valid_to, and its execute to [$sid, (int)date('N'), $my_section, $today]");
            $stmt->execute([$sid, (int)date('N'), $my_section, $today]);
            $sc = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sc) {
                $message = 'That class is not yours or not scheduled today.';
            } else {
                $t = date('H:i:s');
                $opens = date('H:i:s', strtotime($sc['start_time'] . ' -30 minutes'));
                if ($t < $opens || $t >= $sc['end_time']) {
                    $message = 'Time-in is only allowed from 30 minutes before the class until it ends.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO room_usage (schedule_id, usage_date, time_in, logged_by) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$sid, $today, $now, $_SESSION['user_id']]);
                    header('Location: usage.php');
                    exit;
                }
            }
        } elseif ($action === 'out') {
            $stmt = $pdo->prepare("
                UPDATE room_usage SET time_out = ?
                WHERE usage_id = ? AND time_in IS NOT NULL AND time_out IS NULL
                  AND schedule_id IN (SELECT schedule_id FROM schedules WHERE section_id = ?)");
            $stmt->execute([$now, (int)($_POST['usage_id'] ?? 0), $my_section]);
            header('Location: usage.php');
            exit;
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            $message = 'This class is already timed in today.';
        } elseif ($e->getCode() === '23514') {
            $message = 'Time-out must be later than time-in.';
        } else {
            throw $e;
        }
    }
}

$sql = "
    SELECT sc.schedule_id, sc.start_time, sc.end_time,
           s.section_name, r.room_number, r.building,
           u.usage_id, u.time_in, u.time_out, u.auto_closed
    FROM schedules sc
    JOIN sections s ON s.section_id = sc.section_id
    JOIN rooms r ON r.room_id = sc.room_id
    LEFT JOIN room_usage u ON u.schedule_id = sc.schedule_id AND u.usage_date = ?
    WHERE sc.day_of_week = ? AND ?::date BETWEEN sc.valid_from AND sc.valid_to";
$params = [date('Y-m-d'), (int)date('N'), date('Y-m-d')];

if (!is_admin()) {
    $sql .= " AND sc.section_id = ?";
    $params[] = $my_section ?? 0;
}
$sql .= " ORDER BY r.room_number, sc.start_time";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php $title = 'usage'; include 'header.php'; ?>
    
    <h2>Room Usage - <?= date('l, F j, Y') ?></h2>

    <?php if ($message) echo "<p class='alert error'>" . htmlspecialchars($message) . "</p>"; ?>

    <?php if (!$rows): ?>
        <p>No classes to show today.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
        <tr><th>Room</th><th>Section</th><th>Scheduled</th><th>Time In</th><th>Time Out</th><th>Action</th></tr>
        <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['room_number'] . ' ' . ($row['building'] ?? '')) ?></td>
            <td><?= htmlspecialchars($row['section_name']) ?></td>
            <td><?= substr($row['start_time'], 0, 5) ?> - <?= substr($row['end_time'], 0, 5) ?></td>
            <td><?= $row['time_in'] ? substr($row['time_in'], 11, 5) : '-' ?></td>
            <td><?= $row['time_out'] ? substr($row['time_out'], 11, 5) . ($row['auto_closed'] ? ' (auto)' : '') : '-' ?></td>
            <td>
                <?php if ($row['usage_id'] && $row['time_out']): ?>
                    Done
                <?php elseif (!$can_act): ?>
                    View only
                <?php elseif (!$row['usage_id']): ?>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="in">
                        <input type="hidden" name="schedule_id" value="<?= (int)$row['schedule_id'] ?>">
                        <button type="submit">Time In</button>
                    </form>
                <?php else: ?>
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="out">
                        <input type="hidden" name="usage_id" value="<?= (int)$row['usage_id'] ?>">
                        <button type="submit">Time Out</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>
<?php include 'footer.php'; ?>