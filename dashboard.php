<?php
ini_set('display_errors', 1); error_reporting(E_ALL);
require 'db.php';
require_login();
close_stale_sessions($pdo);
$title = 'Home';

/* ---------- Admin: overview tables ---------- */
if (is_admin()) {
    $today = date('Y-m-d');
    $dow   = (int)date('N');
    $mon   = date('Y-m-d', strtotime('monday this week'));
    $sun   = date('Y-m-d', strtotime('sunday this week'));

    // Today's classes
    $stmt = $pdo->prepare("
        SELECT sc.start_time, sc.end_time, r.room_number, r.building, s.section_name,
               u.usage_id, u.time_in, u.time_out, u.auto_closed
        FROM schedules sc
        JOIN rooms r ON r.room_id = sc.room_id
        JOIN sections s ON s.section_id = sc.section_id
        LEFT JOIN room_usage u ON u.schedule_id = sc.schedule_id AND u.usage_date = ?
        WHERE sc.day_of_week = ? AND ?::date BETWEEN sc.valid_from AND sc.valid_to
        ORDER BY r.room_number, sc.start_time");
    $stmt->execute([$today, $dow, $today]);
    $todays = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // This week's averages per room and section
    $stmt = $pdo->prepare("
        SELECT rm.room_id, rm.room_number, rm.building, s.section_name,
               ROUND(AVG(rt.score)::numeric, 2) AS avg_score, COUNT(*) AS n
        FROM ratings rt
        JOIN room_usage u ON u.usage_id = rt.usage_id
        JOIN schedules sc ON sc.schedule_id = u.schedule_id
        JOIN rooms rm ON rm.room_id = sc.room_id
        JOIN sections s ON s.section_id = sc.section_id
        WHERE u.usage_date BETWEEN ? AND ?
        GROUP BY rm.room_id, rm.room_number, rm.building, s.section_id, s.section_name
        ORDER BY rm.room_number, avg_score ASC");
    $stmt->execute([$mon, $sun]);
    $avgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Keep only the lowest section per room (ties all kept)
    $min = [];
    foreach ($avgs as $a) {
        if (!isset($min[$a['room_id']]) || $a['avg_score'] < $min[$a['room_id']]) {
            $min[$a['room_id']] = $a['avg_score'];
        }
    }
    $lowest = array_filter($avgs, fn($a) => $a['avg_score'] == $min[$a['room_id']]);

    // Latest comments
    $comments = $pdo->query("
        SELECT u.usage_date, rm.room_number, s.section_name, rb.section_name AS rated_by,
               rt.score, rt.comment
        FROM ratings rt
        JOIN room_usage u ON u.usage_id = rt.usage_id
        JOIN schedules sc ON sc.schedule_id = u.schedule_id
        JOIN rooms rm ON rm.room_id = sc.room_id
        JOIN sections s ON s.section_id = sc.section_id
        JOIN sections rb ON rb.section_id = rt.rated_by_section
        WHERE rt.comment IS NOT NULL
        ORDER BY rt.rated_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

    $nowT = date('H:i:s');

    // Room status right now
    $stmt = $pdo->prepare("
        SELECT rm.room_id, rm.room_number, rm.building,
            (SELECT s.section_name FROM room_usage u
               JOIN schedules sc ON sc.schedule_id = u.schedule_id
               JOIN sections s ON s.section_id = sc.section_id
              WHERE sc.room_id = rm.room_id AND u.usage_date = ?
                AND u.time_in IS NOT NULL AND u.time_out IS NULL
              LIMIT 1) AS occupant,
            (SELECT s.section_name FROM schedules sc
               JOIN sections s ON s.section_id = sc.section_id
              WHERE sc.room_id = rm.room_id AND sc.day_of_week = ?
                AND ?::time BETWEEN sc.start_time AND sc.end_time
                AND ?::date BETWEEN sc.valid_from AND sc.valid_to
              LIMIT 1) AS scheduled_now
        FROM rooms rm
        ORDER BY rm.building NULLS LAST, rm.room_number");
    $stmt->execute([$today, $dow, $nowT, $today]);
    $roomStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_rooms = count($roomStatus);
    $occupied = count(array_filter($roomStatus, fn($r) => $r['occupant'] !== null));

    $bFilter = $_GET['building'] ?? '';
    $bList = array_values(array_unique(array_map(fn($r) => $r['building'] ?? '(No building)', $roomStatus)));
    $shown = array_filter($roomStatus, fn($r) => $bFilter === '' || ($r['building'] ?? '(No building)') === $bFilter);

    // Classes that started today but have not timed in
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM schedules sc
        LEFT JOIN room_usage u ON u.schedule_id = sc.schedule_id AND u.usage_date = ?
        WHERE sc.day_of_week = ? AND sc.start_time < ?::time
          AND sc.end_time > ?::time AND u.usage_id IS NULL
          AND ?::date BETWEEN sc.valid_from AND sc.valid_to");
    $stmt->execute([$today, $dow, $nowT, $nowT, $today]);
    $late = (int)$stmt->fetchColumn();

    // Sections and rooms per building
    $buildings = $pdo->query("
        SELECT COALESCE(rm.building, '(No building)') AS building,
               COUNT(DISTINCT rm.room_id) AS rooms,
               COUNT(DISTINCT sc.section_id) AS sections
        FROM rooms rm
        LEFT JOIN schedules sc ON sc.room_id = rm.room_id
        GROUP BY COALESCE(rm.building, '(No building)')
        ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);

    include 'header.php'; ?>
    <?php if ($pendingReq): ?>
        <p class="alert error"><?= $pendingReq ?> password reset request<?= $pendingReq > 1 ? 's' : '' ?> waiting. <a href="users.php">Review now &rarr;</a></p>
    <?php endif; ?>

    <h2>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></h2>
    <p class="sub">Overview for <?= date('l, F j, Y') ?></p>

    <div class="stats">
        <div class="stat"><b><?= $total_rooms ?></b><span>Total rooms</span></div>
        <div class="stat"><b><?= $occupied ?></b><span>Occupied now</span></div>
        <div class="stat"><b><?= $total_rooms - $occupied ?></b><span>Vacant</span></div>
        <div class="stat <?= $late ? 'warn' : '' ?>"><b><?= $late ?></b><span>Late, not timed in</span></div>
    </div>

    <h3>Room status</h3>
    <form method="get">
        <select name="building" onchange="this.form.submit()">
            <option value="">All buildings</option>
            <?php foreach ($bList as $b): ?>
                <option value="<?= htmlspecialchars($b) ?>" <?= $bFilter === $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php if (!$roomStatus): ?>
        <p class="empty">No rooms yet.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
        <tr><th>Room</th><th>Status</th><th>Section</th></tr>
        <?php foreach ($roomStatus as $r): ?>
        <?php
            if ($r['occupant'] !== null)          { $st = 'Occupied'; $cls = 'busy'; $who = $r['occupant']; }
            elseif ($r['scheduled_now'] !== null) { $st = 'Expected'; $cls = 'exp';  $who = $r['scheduled_now']; }
            else                                  { $st = 'Vacant';   $cls = 'free'; $who = '-'; }
        ?>
        <tr>
            <td><?= htmlspecialchars($r['room_number'] . ' ' . ($r['building'] ?? '')) ?></td>
            <td><span class="pill <?= $cls ?>"><?= $st ?></span></td>
            <td><?= htmlspecialchars($who) ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>

    <h3>Sections per building</h3>
    <div class="table-wrap"><table>
        <tr><th>Building</th><th>Rooms</th><th>Sections with classes</th></tr>
        <?php foreach ($buildings as $b): ?>
        <tr>
            <td><?= htmlspecialchars($b['building']) ?></td>
            <td><?= (int)$b['rooms'] ?></td>
            <td><?= (int)$b['sections'] ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>

    <h3>Today's classes</h3>
    <?php if (!$todays): ?>
        <p class="empty">No classes scheduled today.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
        <tr><th>Room</th><th>Section</th><th>Scheduled</th><th>Time In</th><th>Time Out</th><th>Status</th></tr>
        <?php foreach ($todays as $t): ?>
        <tr>
            <td><?= htmlspecialchars($t['room_number'] . ' ' . ($t['building'] ?? '')) ?></td>
            <td><?= htmlspecialchars($t['section_name']) ?></td>
            <td><?= substr($t['start_time'], 0, 5) ?> - <?= substr($t['end_time'], 0, 5) ?></td>
            <td><?= $t['time_in'] ? substr($t['time_in'], 11, 5) : '-' ?></td>
            <td><?= $t['time_out'] ? substr($t['time_out'], 11, 5) . ($t['auto_closed'] ? ' (auto)' : '') : '-' ?></td>
            <td><?= !$t['usage_id'] ? 'Not yet' : (!$t['time_out'] ? 'In room' : 'Done') ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>

    <h3>Lowest-rated this week</h3>
    <?php if (!$lowest): ?>
        <p class="empty">No ratings yet this week.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
        <tr><th>Room</th><th>Section</th><th>Average</th><th>Ratings</th></tr>
        <?php foreach ($lowest as $l): ?>
        <tr>
            <td><?= htmlspecialchars($l['room_number'] . ' ' . ($l['building'] ?? '')) ?></td>
            <td><?= htmlspecialchars($l['section_name']) ?></td>
            <td><?= $l['avg_score'] ?> / 5</td>
            <td><?= (int)$l['n'] ?><?= $l['n'] == 1 ? ' (only 1 rating)' : '' ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
    <p><a href="report.php">See the full weekly report &rarr;</a></p>

    <h3>Latest comments</h3>
    <?php if (!$comments): ?>
        <p class="empty">No comments yet.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
        <tr><th>Date</th><th>Room</th><th>Class rated</th><th>Rated by</th><th>Score</th><th>Comment</th></tr>
        <?php foreach ($comments as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['usage_date']) ?></td>
            <td><?= htmlspecialchars($c['room_number']) ?></td>
            <td><?= htmlspecialchars($c['section_name']) ?></td>
            <td><?= htmlspecialchars($c['rated_by']) ?></td>
            <td><?= (int)$c['score'] ?>/5</td>
            <td class="comment-cell">
            <?= htmlspecialchars($c['comment']) ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
    <?php include 'footer.php'; exit;
}

/* ---------- Section account ---------- */
$stmt = $pdo->prepare("SELECT section_id FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$sid = (int)($stmt->fetchColumn() ?: 0);

$today = date('Y-m-d');
$dow   = (int)date('N');
$mon   = date('Y-m-d', strtotime('monday this week'));
$sun   = date('Y-m-d', strtotime('sunday this week'));

$classes = $comments = [];
$result = ['avg' => null, 'n' => 0];
$rate_pending = 0;

if ($sid) {
    $stmt = $pdo->prepare("
        SELECT sc.start_time, sc.end_time, r.room_number, r.building, u.usage_id, u.time_in, u.time_out
        FROM schedules sc
        JOIN rooms r ON r.room_id = sc.room_id
        LEFT JOIN room_usage u ON u.schedule_id = sc.schedule_id AND u.usage_date = ?
        WHERE sc.day_of_week = ? AND sc.section_id = ?
          AND ?::date BETWEEN sc.valid_from AND sc.valid_to
        ORDER BY sc.start_time");
    $stmt->execute([$today, $dow, $sid, $today]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM room_usage r2
        JOIN schedules sc2 ON sc2.schedule_id = r2.schedule_id
        JOIN LATERAL (
            SELECT pu.usage_id, pu.time_out, psc.section_id
            FROM room_usage pu JOIN schedules psc ON psc.schedule_id = pu.schedule_id
            WHERE psc.room_id = sc2.room_id AND pu.usage_date = r2.usage_date AND pu.time_in < r2.time_in
            ORDER BY pu.time_in DESC LIMIT 1) p ON TRUE
        LEFT JOIN ratings rt ON rt.usage_id = p.usage_id
        WHERE r2.usage_date = ? AND sc2.section_id = ?
          AND p.time_out IS NOT NULL AND p.section_id <> sc2.section_id
          AND p.time_out <= r2.time_in AND r2.time_in - p.time_out <= interval '2 hours'
          AND rt.rating_id IS NULL");
    $stmt->execute([$today, $sid]);
    $rate_pending = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT u.usage_date, r.room_number, rt.score, rt.comment
        FROM ratings rt
        JOIN room_usage u ON u.usage_id = rt.usage_id
        JOIN schedules sc ON sc.schedule_id = u.schedule_id
        JOIN rooms r ON r.room_id = sc.room_id
        WHERE sc.section_id = ? AND rt.comment IS NOT NULL
        ORDER BY rt.rated_at DESC LIMIT 3");
    $stmt->execute([$sid]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT ROUND(AVG(rt.score)::numeric, 2) AS avg, COUNT(*) AS n
        FROM ratings rt
        JOIN room_usage u ON u.usage_id = rt.usage_id
        JOIN schedules sc ON sc.schedule_id = u.schedule_id
        WHERE sc.section_id = ? AND u.usage_date BETWEEN ? AND ?");
    $stmt->execute([$sid, $mon, $sun]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
}

$next = ['What to do next?', 'No more classes today', null];
$open = $notyet = false;
foreach ($classes as $c) {
    if ($c['usage_id'] && !$c['time_out']) $open = true;
    if (!$c['usage_id']) $notyet = true;
}
if ($rate_pending)  $next = ['What to do next?', 'Rate the room', 'rate.php'];
elseif ($open)      $next = ['What to do next?', 'Time Out', 'usage.php'];
elseif ($notyet)    $next = ['What to do next?', 'Time In', 'usage.php'];

$pendingReq = (int)$pdo->query("SELECT COUNT(*) FROM password_requests WHERE status = 'pending'")->fetchColumn();
include 'header.php';
?>
<h1 class="hello">Hello, <?= htmlspecialchars($_SESSION['full_name']) ?></h1>

<?php if (!$sid): ?>
    <p class="empty">This account has no section yet. Ask your admin.</p>
<?php else: ?>

<?php if ($next[2]): ?>
    <a class="next" href="<?= $next[2] ?>"><small><?= $next[0] ?></small><b><?= $next[1] ?></b></a>
<?php else: ?>
    <div class="next off"><small><?= $next[0] ?></small><b><?= $next[1] ?></b></div>
<?php endif; ?>

<h3 class="section-title">Latest comment about your class</h3>
<?php if (!$comments): ?>
    <p class="empty">No comments yet.</p>
<?php else: ?>
<div class="table-wrap"><table>
    <tr><th>Date</th><th>Room</th><th>Score</th><th>Comment</th></tr>
    <?php foreach ($comments as $c): ?>
    <tr>
        <td><?= htmlspecialchars($c['usage_date']) ?></td>
        <td><?= htmlspecialchars($c['room_number']) ?></td>
        <td><?= (int)$c['score'] ?>/5</td>
        <td class="comment-cell">
        <?= htmlspecialchars($c['comment']) ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table></div>
<?php endif; ?>

<h3 class="section-title" id="classes">Today's classes</h3>
<?php if (!$classes): ?>
    <p class="empty">No classes today.</p>
<?php else: ?>
<div class="table-wrap"><table>
    <tr><th>Room</th><th>Time</th><th>Status</th></tr>
    <?php foreach ($classes as $c): ?>
    <tr>
        <td><?= htmlspecialchars($c['room_number'] . ' ' . ($c['building'] ?? '')) ?></td>
        <td><?= substr($c['start_time'], 0, 5) ?> - <?= substr($c['end_time'], 0, 5) ?></td>
        <td><?= !$c['usage_id'] ? 'Not yet' : (!$c['time_out'] ? 'In since ' . substr($c['time_in'], 11, 5) : 'Done') ?></td>
    </tr>
    <?php endforeach; ?>
</table></div>
<?php endif; ?>

<h3 class="section-title" id="results">This week's result</h3>
<?php if (!$result['n']): ?>
    <p class="empty">No ratings yet this week.</p>
<?php else: ?>
<div class="table-wrap"><table>
    <tr><th>Average</th><th>Ratings</th><th>Note</th></tr>
    <tr>
        <td><?= $result['avg'] ?> / 5</td>
        <td><?= (int)$result['n'] ?></td>
        <td><?= $result['n'] == 1 ? 'Only 1 rating' : '5 = spotless, 1 = very dirty' ?></td>
    </tr>
</table></div>
<?php endif; ?>

<?php endif; ?>
<?php include 'footer.php'; ?>