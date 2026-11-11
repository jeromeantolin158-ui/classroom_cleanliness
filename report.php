<?php
require 'db.php';
require_login();
if (is_admin()) { header('Location: dashboard.php'); exit; }

// Who is viewing? Admin sees all, section accounts see only their own class.
$stmt = $pdo->prepare("SELECT section_id FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$my_section = $stmt->fetchColumn();
$my_section = ($my_section === false || $my_section === null) ? null : (int)$my_section;

$scope_sql = '';
$scope_params = [];
if (!is_admin()) {
    $scope_sql = ' AND sc.section_id = ?';
    $scope_params[] = $my_section ?? 0;
}

// Week picker (format 2026-W45), fall back to this week
$week = $_GET['week'] ?? date('o-\WW');
if (!preg_match('/^(\d{4})-W(\d{2})$/', $week, $m) || (int)$m[2] < 1 || (int)$m[2] > 53) {
    $week = date('o-\WW');
    preg_match('/^(\d{4})-W(\d{2})$/', $week, $m);
}
$start = new DateTime();
$start->setISODate((int)$m[1], (int)$m[2], 1);
$end = clone $start;
$end->modify('+6 days');
$range = [$start->format('Y-m-d'), $end->format('Y-m-d')];

// Averages
$stmt = $pdo->prepare("
    SELECT rm.room_id, rm.room_number, rm.building, s.section_name,
           ROUND(AVG(rt.score)::numeric, 2) AS avg_score,
           COUNT(*) AS n
    FROM ratings rt
    JOIN room_usage u ON u.usage_id = rt.usage_id
    JOIN schedules sc ON sc.schedule_id = u.schedule_id
    JOIN rooms rm ON rm.room_id = sc.room_id
    JOIN sections s ON s.section_id = sc.section_id
    WHERE u.usage_date BETWEEN ? AND ?" . $scope_sql . "
    GROUP BY rm.room_id, rm.room_number, rm.building, s.section_id, s.section_name
    ORDER BY rm.room_number, rm.building, avg_score ASC, n DESC
");
$stmt->execute(array_merge($range, $scope_params));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Comments
$stmt = $pdo->prepare("
    SELECT u.usage_date, rm.room_number, rm.building,
           s.section_name, rb.section_name AS rated_by, rt.score, rt.comment
    FROM ratings rt
    JOIN room_usage u ON u.usage_id = rt.usage_id
    JOIN schedules sc ON sc.schedule_id = u.schedule_id
    JOIN rooms rm ON rm.room_id = sc.room_id
    JOIN sections s ON s.section_id = sc.section_id
    JOIN sections rb ON rb.section_id = rt.rated_by_section
    WHERE u.usage_date BETWEEN ? AND ?
      AND rt.comment IS NOT NULL" . $scope_sql . "
    ORDER BY u.usage_date, rm.room_number
");
$stmt->execute(array_merge($range, $scope_params));
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lowest average per room (ties are all marked)
$min = [];
foreach ($rows as $r) {
    $id = $r['room_id'];
    if (!isset($min[$id]) || $r['avg_score'] < $min[$id]) {
        $min[$id] = $r['avg_score'];
    }
}
?>
<?php $title = 'report'; include 'header.php'; ?>
    
    <h2>Weekly Cleanliness Report</h2>
    <p><?= $start->format('M j, Y') ?> to <?= $end->format('M j, Y') ?></p>
    <?php if (!is_admin()): ?>
        <p><i>Showing results for your section only.</i></p>
    <?php endif; ?>

    <form method="get">
        Week: <input type="week" name="week" value="<?= htmlspecialchars($week) ?>">
        <button type="submit">Show</button>
    </form>
    <br>

    <?php if (!$rows): ?>
        <p>No ratings for this week.</p>
    <?php else: ?>
        <div class="table-wrap">
    <table>
        <tr><th>Room</th><th>Section</th><th>Average</th><th>Ratings</th><th></th></tr>
        <?php foreach ($rows as $r): ?>
        <?php $lowest = $r['avg_score'] == $min[$r['room_id']]; ?>
        <tr <?= $lowest ? "style='background:#fdd'" : '' ?>>
            <td><?= htmlspecialchars($r['room_number'] . ' ' . ($r['building'] ?? '')) ?></td>
            <td><?= htmlspecialchars($r['section_name']) ?></td>
            <td><?= $r['avg_score'] ?> / 5</td>
            <td><?= (int)$r['n'] ?><?= $r['n'] == 1 ? ' (only 1 rating)' : '' ?></td>
            <td><?= $lowest ? '<b>Lowest in this room</b>' : '' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <p><small>Scores: 5 = spotless, 1 = very dirty. Based on the next class's opinion, not an inspection.</small></p>

    <?php if ($comments): ?>
    <h3>Comments</h3>
        <div class="table-wrap">
    <table>
        <tr><th>Date</th><th>Room</th><th>Class rated</th><th>Rated by</th><th>Score</th><th>Comment</th></tr>
        <?php foreach ($comments as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['usage_date']) ?></td>
            <td><?= htmlspecialchars($c['room_number'] . ' ' . ($c['building'] ?? '')) ?></td>
            <td><?= htmlspecialchars($c['section_name']) ?></td>
            <td><?= htmlspecialchars($c['rated_by']) ?></td>
            <td><?= (int)$c['score'] ?>/5</td>
            <td><?= htmlspecialchars($c['comment']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>
<?php include 'footer.php'; ?>