<?php
require 'lib.php';
$my_section = require_student();
close_stale_sessions($pdo);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($my_section === null) { http_response_code(403); exit('Only a section account can log usage.'); }

    $action = $_POST['action'] ?? '';
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    try {
        if ($action === 'in') {
            $sid = (int)($_POST['schedule_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT start_time, end_time FROM schedules WHERE schedule_id = ? AND day_of_week = ? AND section_id = ? AND ?::date BETWEEN valid_from AND valid_to");
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
            if (rate_pending_count($pdo, $my_section) > 0) {
                $message = 'Rate the room before you time out.';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE room_usage SET time_out = ?
                    WHERE usage_id = ? AND time_in IS NOT NULL AND time_out IS NULL
                      AND schedule_id IN (SELECT schedule_id FROM schedules WHERE section_id = ?)");
                $stmt->execute([$now, (int)($_POST['usage_id'] ?? 0), $my_section]);
                header('Location: usage.php');
                exit;
            }
        
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') $message = 'This class is already timed in today.';
        elseif ($e->getCode() === '23514') $message = 'Time-out must be later than time-in.';
        else throw $e;
    }
}

$today = date('Y-m-d'); $dow = (int)date('N');

$title = 'Room usage';
$view = ($_GET['view'] ?? 'today') === 'week' ? 'week' : 'today';

function fetchClasses(PDO $pdo, int $sid, bool $week): array {
    $today = date('Y-m-d');
    $sql = "
        SELECT sc.schedule_id, sc.day_of_week, sc.start_time, sc.end_time, r.room_number, r.building,
               u.usage_id, u.time_in, u.time_out, u.auto_closed,
               (SELECT s2.section_name FROM schedules p JOIN sections s2 ON s2.section_id = p.section_id
                 WHERE p.room_id = sc.room_id AND p.day_of_week = sc.day_of_week AND p.end_time <= sc.start_time
                   AND ?::date BETWEEN p.valid_from AND p.valid_to
                 ORDER BY p.end_time DESC LIMIT 1) AS prev_section
        FROM schedules sc
        JOIN rooms r ON r.room_id = sc.room_id
        LEFT JOIN room_usage u ON u.schedule_id = sc.schedule_id AND u.usage_date = ?
        WHERE sc.section_id = ? " . ($week ? '' : 'AND sc.day_of_week = ? ') . "AND ?::date BETWEEN sc.valid_from AND sc.valid_to
        ORDER BY sc.day_of_week, sc.start_time";
    $params = [$today, $today, $sid];
    if (!$week) $params[] = (int)date('N');
    $params[] = $today;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$todayRows = $my_section ? fetchClasses($pdo, $my_section, false) : [];
$tableRows = ($my_section && $view === 'week') ? fetchClasses($pdo, $my_section, true) : $todayRows;
$pending = $my_section ? rate_pending_count($pdo, $my_section) : 0;
$days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$nowT = date('H:i:s');

$card = null; $cardKind = '';
foreach ($todayRows as $c) { if ($c['usage_id'] && !$c['time_out']) { $card = $c; $cardKind = 'open'; break; } }
if (!$card) foreach ($todayRows as $c) { if (!$c['usage_id'] && $c['end_time'] > $nowT) { $card = $c; $cardKind = 'next'; break; } }
if (!$card && $todayRows) { $card = end($todayRows); $cardKind = 'done'; }

include 'header.php';
?>
<h1 class="s-h1">Room usage</h1>
<p class="s-sub">Your schedule, room check-ins, and class handoffs in one place.</p>
<?php if ($message) echo "<p class='s-alert'>" . h($message) . "</p>"; ?>

<?php if (!$my_section): ?>
    <div class="s-card">This account has no section yet. Ask your administrator.</div>
<?php else: ?>

<?php if ($card): ?>
<section class="s-curr">
    <div class="s-curr-top"><span><?= $cardKind === 'open' ? '● Class in progress' : ($cardKind === 'next' ? 'Up next' : 'Completed') ?></span></div>
    <div class="s-curr-body">
        <div>
            <h2>Room <?= h($card['room_number']) ?></h2>
            <div class="s-muted"><?= h($card['building'] ?? '') ?> · <?= substr($card['start_time'], 0, 5) ?> - <?= substr($card['end_time'], 0, 5) ?></div>
            <div class="s-facts">
                <div><small>Time in</small><b><?= $card['time_in'] ? substr($card['time_in'], 11, 5) : 'Not yet' ?></b></div>
                <div><small>Time out</small><b><?= $card['time_out'] ? substr($card['time_out'], 11, 5) . ($card['auto_closed'] ? ' (auto)' : '') : 'Not yet recorded' ?></b></div>
                <div><small>Room received from</small><b><?= h($card['prev_section'] ?? '-') ?></b></div>
            </div>
        </div>
        <div class="s-acts">
            <?php if ($cardKind === 'open'): ?>
                <?php if ($pending): ?>
                    <button class="s-btn soft" type="button" disabled>Time out</button>
                    <span class="s-muted">Complete the rating first.</span>
                <?php else: ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="out"><input type="hidden" name="usage_id" value="<?= (int)$card['usage_id'] ?>"><button class="s-btn" type="submit">Time out</button></form>
                <?php endif; ?>
            <?php elseif ($cardKind === 'next'): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="in"><input type="hidden" name="schedule_id" value="<?= (int)$card['schedule_id'] ?>"><button class="s-btn" type="submit">Time in</button></form>
            <?php else: ?>
                <span class="s-pill go">Done</span>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="s-card">
    <h3 class="s-ct">Class schedule <span class="s-muted"><?= date('D, M j, Y') ?></span></h3>
    <div class="s-tabbar">
        <a href="usage.php" class="<?= $view === 'today' ? 'on' : '' ?>">Today</a>
        <a href="usage.php?view=week" class="<?= $view === 'week' ? 'on' : '' ?>">This week</a>
    </div>
    <?php if (!$tableRows): ?>
        <p class="s-muted" style="padding:14px 0">No classes to show.</p>
    <?php else: ?>
    <table>
        <tr><?= $view === 'week' ? '<th>Day</th>' : '' ?><th>Time</th><th>Room</th><th>Previous section</th><?php if ($view === 'today'): ?><th>Status</th><th>Action</th><?php endif; ?></tr>
        <?php foreach ($tableRows as $c): ?>
        <tr>
            <?php if ($view === 'week'): ?><td><?= $days[$c['day_of_week']] ?></td><?php endif; ?>
            <td><?= substr($c['start_time'], 0, 5) ?> - <?= substr($c['end_time'], 0, 5) ?></td>
            <td><b>Room <?= h($c['room_number']) ?></b><br><span class="s-muted"><?= h($c['building'] ?? '') ?></span></td>
            <td><?= h($c['prev_section'] ?? '-') ?></td>
            <?php if ($view === 'today'): ?>
            <td><?php if ($c['usage_id']): ?><span class="s-pill go"><?= $c['time_out'] ? 'Done' : 'In progress' ?></span>
                <?php elseif ($c['end_time'] > $nowT): ?><span class="s-pill">Upcoming</span>
                <?php else: ?><span class="s-pill warn">Not logged</span><?php endif; ?></td>
            <td>
                <?php if (!$c['usage_id'] && $c['end_time'] > $nowT): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="in"><input type="hidden" name="schedule_id" value="<?= (int)$c['schedule_id'] ?>"><button class="s-btn soft" type="submit">Time in</button></form>
                    <?php elseif ($c['usage_id'] && !$c['time_out']): ?>
                    <?php if ($pending): ?>
                        <button class="s-btn soft" type="button" disabled title="Rate the room first">Time out</button>
                    <?php else: ?>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="out"><input type="hidden" name="usage_id" value="<?= (int)$c['usage_id'] ?>"><button class="s-btn soft" type="submit">Time out</button></form>
                    <?php endif; ?>
                <?php else: ?>-<?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</section>

<div class="s-note"><b>Time in unlocks your room rating.</b>
    Time in opens 30 minutes before your class and closes when it ends.</div>
<?php endif; ?>
<?php include 'footer.php'; ?>