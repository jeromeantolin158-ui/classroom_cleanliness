<?php

require 'db.php';
require_login();
close_stale_sessions($pdo);
/* ---------- Admin ---------- */
if (is_admin()) {
    $title = 'Dashboard';
    $today = date('Y-m-d'); $dow = (int)date('N'); $nowT = date('H:i:s');

    $week = $_GET['week'] ?? date('o-\WW');
    if (!preg_match('/^(\d{4})-W(\d{2})$/', $week, $m) || (int)$m[2] < 1 || (int)$m[2] > 53) {
        $week = date('o-\WW');
        preg_match('/^(\d{4})-W(\d{2})$/', $week, $m);
    }
    $ws = new DateTime(); $ws->setISODate((int)$m[1], (int)$m[2], 1);
    $we = clone $ws; $we->modify('+6 days');
    $range = [$ws->format('Y-m-d'), $we->format('Y-m-d')];
    $bFilter = $_GET['building'] ?? '';

    // Room usage today
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

    // Room status now
    $stmt = $pdo->prepare("
        SELECT rm.room_id, rm.room_number, rm.building,
            (SELECT s.section_name FROM room_usage u
               JOIN schedules sc ON sc.schedule_id = u.schedule_id
               JOIN sections s ON s.section_id = sc.section_id
              WHERE sc.room_id = rm.room_id AND u.usage_date = ?
                AND u.time_in IS NOT NULL AND u.time_out IS NULL LIMIT 1) AS occupant,
            (SELECT s.section_name FROM schedules sc
               JOIN sections s ON s.section_id = sc.section_id
              WHERE sc.room_id = rm.room_id AND sc.day_of_week = ?
                AND ?::time BETWEEN sc.start_time AND sc.end_time
                AND ?::date BETWEEN sc.valid_from AND sc.valid_to LIMIT 1) AS scheduled_now
        FROM rooms rm ORDER BY rm.building NULLS LAST, rm.room_number");
    $stmt->execute([$today, $dow, $nowT, $today]);
    $roomStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_rooms = count($roomStatus);
    $occupied = count(array_filter($roomStatus, fn($r) => $r['occupant'] !== null));
    $bList = array_values(array_unique(array_map(fn($r) => $r['building'] ?? '(No building)', $roomStatus)));
    $shown = array_filter($roomStatus, fn($r) => $bFilter === '' || ($r['building'] ?? '(No building)') === $bFilter);

    // Late classes
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM schedules sc
        LEFT JOIN room_usage u ON u.schedule_id = sc.schedule_id AND u.usage_date = ?
        WHERE sc.day_of_week = ? AND sc.start_time < ?::time AND sc.end_time > ?::time
          AND ?::date BETWEEN sc.valid_from AND sc.valid_to AND u.usage_id IS NULL");
    $stmt->execute([$today, $dow, $nowT, $nowT, $today]);
    $late = (int)$stmt->fetchColumn();

    // Sections per building
    $buildings = $pdo->query("
        SELECT COALESCE(rm.building, '(No building)') AS building,
               COUNT(DISTINCT rm.room_id) AS rooms, COUNT(DISTINCT sc.section_id) AS sections
        FROM rooms rm LEFT JOIN schedules sc ON sc.room_id = rm.room_id
        GROUP BY COALESCE(rm.building, '(No building)') ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);

    // Weekly report
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
    $stmt->execute($range);
    $avgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $min = [];
    foreach ($avgs as $a) { if (!isset($min[$a['room_id']]) || $a['avg_score'] < $min[$a['room_id']]) $min[$a['room_id']] = $a['avg_score']; }
    $wN = array_sum(array_column($avgs, 'n'));
    $wAvg = $wN ? round(array_sum(array_map(fn($a) => $a['avg_score'] * $a['n'], $avgs)) / $wN, 1) : null;

    // Last week's average (for the trend arrow)
    $lw = clone $ws; $lw->modify('-7 days'); $lwe = clone $lw; $lwe->modify('+6 days');
    $stmt = $pdo->prepare("SELECT ROUND(AVG(rt.score)::numeric, 1) FROM ratings rt JOIN room_usage u ON u.usage_id = rt.usage_id WHERE u.usage_date BETWEEN ? AND ?");
    $stmt->execute([$lw->format('Y-m-d'), $lwe->format('Y-m-d')]);
    $lwAvg = $stmt->fetchColumn(); $lwAvg = $lwAvg !== false && $lwAvg !== null ? (float)$lwAvg : null;
    $diff = ($wAvg !== null && $lwAvg !== null) ? round($wAvg - $lwAvg, 1) : null;

    // Average per day (chart)
    $stmt = $pdo->prepare("SELECT u.usage_date, ROUND(AVG(rt.score)::numeric, 1) AS a FROM ratings rt JOIN room_usage u ON u.usage_id = rt.usage_id WHERE u.usage_date BETWEEN ? AND ? GROUP BY u.usage_date");
    $stmt->execute($range);
    $perDay = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) $perDay[$d['usage_date']] = (float)$d['a'];

    // Latest ratings with comments
    $ratings = $pdo->query("
        SELECT u.usage_date, rt.rated_at, rm.room_number, s.section_name, rb.section_name AS rated_by, rt.score, rt.comment
        FROM ratings rt
        JOIN room_usage u ON u.usage_id = rt.usage_id
        JOIN schedules sc ON sc.schedule_id = u.schedule_id
        JOIN rooms rm ON rm.room_id = sc.room_id
        JOIN sections s ON s.section_id = sc.section_id
        JOIN sections rb ON rb.section_id = rt.rated_by_section
        ORDER BY rt.rated_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

    // Counts for the cards
    $done = count(array_filter($todays, fn($t) => $t['time_out']));
    $inUse = count(array_filter($todays, fn($t) => $t['usage_id'] && !$t['time_out']));
    $upcoming = count($todays) - $done - $inUse;
    $nIn = 0; $nLate = 0;
    foreach ($shown as $r) { if ($r['occupant'] !== null) $nIn++; elseif ($r['scheduled_now'] !== null) $nLate++; }
    $nFree = count($shown) - $nIn - $nLate;
    $maxSec = max(1, ...array_map(fn($b) => (int)$b['sections'], $buildings ?: [['sections' => 1]]));

    include 'header.php'; ?>
    <div class="a-head">
        <div><h1 class="s-h1">Campus overview</h1><p class="s-sub">A clearer view of your classrooms, sections, and daily handoffs.</p></div>
        <span class="s-date"><?= date('D, F j, Y') ?></span>
    </div>

    <div class="s-kpis">
        <div class="s-kpi"><small>Classrooms</small><b><?= $total_rooms ?></b><em><?= count($bList) ?> building<?= count($bList) === 1 ? '' : 's' ?></em></div>
        <div class="s-kpi"><small>Classes today</small><b><?= count($todays) ?></b><em><?= $done ?> completed &middot; <?= $inUse ?> in use &middot; <?= $upcoming ?> to go</em></div>
        <div class="s-kpi"><small>Late (not timed in)</small><b style="color:<?= $late ? '#b45309' : 'inherit' ?>"><?= $late ?></b><em>Started, no time-in yet</em></div>
        <div class="s-kpi"><small>Weekly average</small><b><?= $wAvg !== null ? $wAvg . ' / 5' : '-' ?></b>
            <em class="<?= $diff !== null && $diff < 0 ? '' : 'd-kpi-up' ?>"><?= $diff === null ? (int)$wN . ' ratings' : ($diff >= 0 ? '&uarr; ' : '&darr; ') . abs($diff) . ' vs last week' ?></em></div>
    </div>

    <div class="d-grid">
        <section class="d-card">
            <h3>Latest ratings <a class="d-link" href="latest_ratings.php">View details &rarr;</a></h3>
            <?php if (!$ratings): ?><p class="s-muted">No ratings yet.</p><?php endif; ?>
            <?php foreach ($ratings as $c): ?>
            <div class="d-rate">
                <div><b><?= h($c['section_name']) ?></b><small>Room <?= h($c['room_number']) ?> &middot; From <?= h($c['rated_by']) ?></small>
                    <?php if ($c['comment']): ?><small style="display:block;margin-top:2px">&ldquo;<?= h(mb_strimwidth($c['comment'], 0, 70, '...')) ?>&rdquo;</small><?php endif; ?></div>
                <div class="sc"><?= (int)$c['score'] ?>.0 / 5<small><?= h(substr($c['rated_at'], 5, 11)) ?></small></div>
            </div>
            <?php endforeach; ?>
        </section>

        <section class="d-card">
            <h3>Weekly report
                <span style="display:inline-flex;gap:8px;align-items:center">
                <form method="get" style="display:inline-flex;gap:6px;margin:0">
                    <input type="hidden" name="building" value="<?= h($bFilter) ?>">
                    <input type="week" name="week" value="<?= h($week) ?>" onchange="this.form.submit()" style="min-height:34px;font-size:.78rem;padding:0 8px">
                </form>
                <a class="d-link" href="weekly_report.php?week=<?= h($week) ?>">View details &rarr;</a></span>
            </h3>
            <p class="s-muted" style="margin:-8px 0 14px"><?= $ws->format('M j') ?> - <?= $we->format('M j, Y') ?></p>
            <div class="d-week">
                <div><div class="d-big"><?= $wAvg !== null ? $wAvg : '-' ?></div><div class="s-muted">Average rating</div>
                    <?php if ($diff !== null): ?><div class="s-muted <?= $diff >= 0 ? 'd-kpi-up' : '' ?>"><?= $diff >= 0 ? '&uarr; ' : '&darr; ' ?><?= abs($diff) ?> vs last week</div><?php endif; ?></div>
                <div class="d-bars">
                    <?php for ($i = 0; $i < 7; $i++):
                        $dt = clone $ws; $dt->modify("+$i days"); $k = $dt->format('Y-m-d'); $v = $perDay[$k] ?? null; ?>
                    <div class="d-bar <?= $k === $today ? 'today' : '' ?>" title="<?= $v !== null ? $v . ' / 5' : 'No ratings' ?>">
                        <i style="height:<?= $v !== null ? round($v / 5 * 100) : 0 ?>%"></i><span><?= $dt->format('D') ?></span></div>
                    <?php endfor; ?>
                </div>
            </div>
        </section>
    </div>

    <div class="d-grid">
        <section class="d-card">
            <h3>Room status now
                <form method="get" class="d-filter" style="margin:0">
                    <input type="hidden" name="week" value="<?= h($week) ?>">
                    <select name="building" onchange="this.form.submit()">
                        <option value="">All buildings</option>
                        <?php foreach ($bList as $b): ?><option value="<?= h($b) ?>" <?= $bFilter === $b ? 'selected' : '' ?>><?= h($b) ?></option><?php endforeach; ?>
                    </select>
                </form>
            </h3>
            <div class="d-counts">
                <div class="go"><b><?= $nIn ?></b><small>In use</small></div>
                <div><b><?= $nFree ?></b><small>Available</small></div>
                <div class="warn"><b><?= $nLate ?></b><small>Late time-in</small></div>
            </div>
            <?php if ($shown): ?>
            <div class="d-rooms"><table>
                <?php foreach ($shown as $r):
                    if ($r['occupant'] !== null) { $st = 'In use'; $cl = 'go'; $who = $r['occupant']; }
                    elseif ($r['scheduled_now'] !== null) { $st = 'Late time-in'; $cl = 'warn'; $who = $r['scheduled_now']; }
                    else { $st = 'Available'; $cl = 'mute'; $who = '-'; } ?>
                <tr><td><b><?= h($r['room_number']) ?></b> <span class="s-muted"><?= h($r['building'] ?? '') ?></span></td><td><?= h($who) ?></td><td style="text-align:right"><span class="a-st <?= $cl ?>"><?= $st ?></span></td></tr>
                <?php endforeach; ?>
            </table></div>
            <?php endif; ?>
        </section>

        <section class="d-card">
            <h3>Sections per building <a class="d-link" href="sections.php">View details &rarr;</a></h3>
            <?php foreach ($buildings as $b): ?>
            <div class="d-sb"><span><?= h($b['building']) ?></span><div><i style="width:<?= round((int)$b['sections'] / $maxSec * 100) ?>%"></i></div><b><?= (int)$b['sections'] ?></b></div>
            <?php endforeach; ?>
        </section>
    </div>

    <section class="a-card">
        <h3>Room usage today <a class="d-link" href="schedules.php" style="float:right">View details &rarr;</a></h3>
        <p class="s-muted">Every scheduled class for today and whether it timed in.</p>
        <?php if (!$todays): ?><p class="s-muted">No classes scheduled today.</p><?php else: ?>
        <div class="table-wrap"><table class="a-table lt">
            <thead><tr><th>Room / Building</th><th>Section</th><th>Time</th><th>Time in</th><th>Time out</th><th style="text-align:left">Status</th></tr></thead>
            <tbody>
            <?php foreach ($todays as $t): ?>
            <tr>
                <td class="nm"><?= h($t['room_number'] . ' ' . ($t['building'] ?? '')) ?></td>
                <td><?= h($t['section_name']) ?></td>
                <td><?= substr($t['start_time'], 0, 5) ?> - <?= substr($t['end_time'], 0, 5) ?></td>
                <td><?= $t['time_in'] ? substr($t['time_in'], 11, 5) : '-' ?></td>
                <td style="text-align:left"><?= $t['time_out'] ? substr($t['time_out'], 11, 5) . ($t['auto_closed'] ? ' (auto)' : '') : '-' ?></td>
                <td style="text-align:left"><?php if (!$t['usage_id']): ?><span class="a-st mute">Not yet</span><?php elseif (!$t['time_out']): ?><span class="a-st warn">In room</span><?php else: ?><span class="a-st go">Done</span><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>

    <section class="a-card" id="weekly">
        <h3>Weekly report by room</h3>
        <p class="s-muted"><?= $ws->format('M j') ?> to <?= $we->format('M j, Y') ?> &middot; lowest section in each room is highlighted</p>
        <?php if (!$avgs): ?><p class="s-muted">No ratings for this week.</p><?php else: ?>
        <div class="table-wrap"><table class="a-table lt">
            <thead><tr><th>Room</th><th>Section</th><th style="text-align:left">Average</th><th style="text-align:left">Ratings</th><th style="text-align:left"></th></tr></thead>
            <tbody>
            <?php foreach ($avgs as $a): $low = $a['avg_score'] == $min[$a['room_id']]; ?>
            <tr class="<?= $low ? 'row-low' : '' ?>">
                <td class="nm"><?= h($a['room_number'] . ' ' . ($a['building'] ?? '')) ?></td>
                <td><?= h($a['section_name']) ?></td>
                <td style="text-align:left"><?= $a['avg_score'] ?> / 5</td>
                <td style="text-align:left"><?= (int)$a['n'] ?><?= $a['n'] == 1 ? ' (only 1)' : '' ?></td>
                <td style="text-align:left"><?= $low ? '<b>Lowest in room</b>' : '' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>
    <?php include 'footer.php'; exit;
}

/* ---------- Section account ---------- */
$title = 'Dashboard';
$stmt = $pdo->prepare("SELECT s.section_id, s.section_name FROM users u LEFT JOIN sections s ON s.section_id = u.section_id WHERE u.user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);
$sid = (int)($me['section_id'] ?? 0);

$today = date('Y-m-d'); $dow = (int)date('N'); $nowT = date('H:i:s');
$hr = (int)date('G');
$greet = $hr < 12 ? 'Good morning' : ($hr < 18 ? 'Good afternoon' : 'Good evening');
$mon = date('Y-m-d', strtotime('monday this week')); $sun = date('Y-m-d', strtotime('sunday this week'));
$lmon = date('Y-m-d', strtotime('monday last week')); $lsun = date('Y-m-d', strtotime('sunday last week'));

$classes = []; $toRate = null; $pending = 0; $latest = null; $thisW = []; $lastW = [];

$weekStats = function (string $from, string $to) use ($pdo) {
    $stmt = $pdo->prepare("
        SELECT sc.section_id, s.section_name, AVG(rt.score) AS a, COUNT(*) AS n
        FROM ratings rt
        JOIN room_usage u ON u.usage_id = rt.usage_id
        JOIN schedules sc ON sc.schedule_id = u.schedule_id
        JOIN sections s ON s.section_id = sc.section_id
        WHERE u.usage_date BETWEEN ? AND ?
        GROUP BY sc.section_id, s.section_name ORDER BY a DESC, n DESC");
    $stmt->execute([$from, $to]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

if ($sid) {
    $stmt = $pdo->prepare("
        SELECT sc.start_time, sc.end_time, r.room_number, r.building, u.usage_id, u.time_in, u.time_out
        FROM schedules sc
        JOIN rooms r ON r.room_id = sc.room_id
        LEFT JOIN room_usage u ON u.schedule_id = sc.schedule_id AND u.usage_date = ?
        WHERE sc.day_of_week = ? AND sc.section_id = ? AND ?::date BETWEEN sc.valid_from AND sc.valid_to
        ORDER BY sc.start_time");
    $stmt->execute([$today, $dow, $sid, $today]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pending = rate_pending_count($pdo, $sid);

    $stmt = $pdo->prepare("
        SELECT rm.room_number, rm.building, s1.section_name AS prev_section, r2.time_in, sc2.start_time, sc2.end_time
        FROM room_usage r2
        JOIN schedules sc2 ON sc2.schedule_id = r2.schedule_id
        JOIN rooms rm ON rm.room_id = sc2.room_id
        JOIN LATERAL (
            SELECT pu.usage_id, pu.time_out, psc.section_id
            FROM room_usage pu JOIN schedules psc ON psc.schedule_id = pu.schedule_id
            WHERE psc.room_id = sc2.room_id AND pu.usage_date = r2.usage_date AND pu.time_in < r2.time_in
            ORDER BY pu.time_in DESC LIMIT 1) p ON TRUE
        JOIN sections s1 ON s1.section_id = p.section_id
        LEFT JOIN ratings rt ON rt.usage_id = p.usage_id
        WHERE r2.usage_date = ? AND sc2.section_id = ?
          AND p.time_out IS NOT NULL AND p.section_id <> sc2.section_id
          AND p.time_out <= r2.time_in AND r2.time_in - p.time_out <= interval '2 hours'
          AND rt.rating_id IS NULL
        ORDER BY r2.time_in LIMIT 1");
    $stmt->execute([$today, $sid]);
    $toRate = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmt = $pdo->prepare("
        SELECT u.usage_date, rm.room_number, rb.section_name AS rated_by, rt.score, rt.comment
        FROM ratings rt
        JOIN room_usage u ON u.usage_id = rt.usage_id
        JOIN schedules sc ON sc.schedule_id = u.schedule_id
        JOIN rooms rm ON rm.room_id = sc.room_id
        JOIN sections rb ON rb.section_id = rt.rated_by_section
        WHERE sc.section_id = ? ORDER BY rt.rated_at DESC LIMIT 1");
    $stmt->execute([$sid]);
    $latest = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $thisW = $weekStats($mon, $sun);
    $lastW = $weekStats($lmon, $lsun);
}

// Weekly numbers
$myAvg = null; $rank = null; $delta = null;
foreach ($thisW as $i => $w) { if ((int)$w['section_id'] === $sid) { $myAvg = (float)$w['a']; $rank = $i + 1; } }
foreach ($lastW as $w) { if ((int)$w['section_id'] === $sid && $myAvg !== null) $delta = $myAvg - (float)$w['a']; }

// Class status
$open = $nextC = null; $doneCount = 0; $inprog = $upcoming = 0;
foreach ($classes as $c) {
    if ($c['usage_id'] && !$c['time_out']) { $open = $open ?? $c; $inprog++; }
    elseif ($c['usage_id']) { $doneCount++; }
    else { if ($c['end_time'] > $nowT) $upcoming++; $nextC = $nextC ?? ($c['end_time'] > $nowT ? $c : null); }
}
$status = function ($c) use ($nowT) {
    if ($c['usage_id']) return $c['time_out'] ? ['Done', 'go'] : ['In progress', 'go'];
    return $c['end_time'] > $nowT ? ['Upcoming', ''] : ['Not logged', 'warn'];
};

// Hero banner
if ($toRate) {
    $b = ['kick' => "You're timed in · Room " . $toRate['room_number'], 'head' => 'How did ' . $toRate['prev_section'] . ' leave the room?',
          'sub' => 'You arrived at ' . substr($toRate['time_in'], 11, 5) . '. Rate the room, then enjoy your class.',
          'foot' => substr($toRate['start_time'], 0, 5) . ' - ' . substr($toRate['end_time'], 0, 5), 'btn' => 'Rate room →', 'href' => 'rate.php'];
} elseif ($open) {
    $b = ['kick' => "You're timed in · Room " . $open['room_number'], 'head' => 'Your class is in progress',
          'sub' => 'Remember to time out when you leave.', 'foot' => substr($open['start_time'], 0, 5) . ' - ' . substr($open['end_time'], 0, 5),
          'btn' => 'Time out →', 'href' => 'usage.php'];
} elseif ($nextC) {
    $b = ['kick' => 'Up next · Today', 'head' => 'Your next class is in Room ' . $nextC['room_number'],
          'sub' => 'Time in when you arrive.', 'foot' => substr($nextC['start_time'], 0, 5) . ' - ' . substr($nextC['end_time'], 0, 5),
          'btn' => 'Room usage →', 'href' => 'usage.php'];
} else {
    $b = ['kick' => 'Today', 'head' => 'No more classes today', 'sub' => 'Nothing is waiting for you.', 'foot' => '', 'btn' => '', 'href' => ''];
}

// Handoff steps
$s1 = $open || $toRate || $doneCount > 0;
$s2 = $s1 && !$toRate;
$s3 = $doneCount > 0 && !$open;
$steps = [[$s1, 'Time in', 'Confirm your arrival'], [$s2, 'Rate the room', 'Review the previous section'], [$s3, 'Time out', 'When class ends']];
$firstOpen = null; foreach ($steps as $i => $st) { if (!$st[0]) { $firstOpen = $i; break; } }

include 'header.php';
?>
<div class="s-head">
    <div>
        <h1 class="s-h1"><?= $greet ?>, <?= h($_SESSION['full_name']) ?></h1>
        <p class="s-sub">Here's what's next for <?= h($me['section_name'] ?? 'your section') ?>.</p>
    </div>
    <span class="s-date"><?= date('D, F j, Y') ?></span>
</div>

<?php if (!$sid): ?>
    <div class="s-card">This account has no section yet. Ask your administrator.</div>
<?php else: ?>

<section class="s-hero">
    <div>
        <span class="s-kick"><?= h($b['kick']) ?></span>
        <h2><?= h($b['head']) ?></h2>
        <p><?= h($b['sub']) ?></p>
        <?php if ($b['foot']): ?><small><?= h($b['foot']) ?></small><?php endif; ?>
    </div>
    <?php if ($b['href']): ?><a class="s-btn lime" href="<?= h($b['href']) ?>"><?= h($b['btn']) ?></a><?php endif; ?>
</section>

<div class="s-kpis">
    <div class="s-kpi"><small>Section rating</small><b><?= $myAvg !== null ? round($myAvg, 1) . ' / 5' : '-' ?></b>
        <em><?= $delta !== null ? ($delta >= 0 ? '↑ ' : '↓ ') . abs(round($delta, 1)) . ' from last week' : 'This week' ?></em></div>
    <div class="s-kpi"><small>Campus ranking</small><b><?= $rank ? '#' . $rank . ' of ' . count($thisW) : '-' ?></b><em>Based on this week's ratings</em></div>
    <div class="s-kpi"><small>Today's classes</small><b><?= count($classes) ?> <?= count($classes) === 1 ? 'class' : 'classes' ?></b><em><?= $inprog ?> in progress · <?= $upcoming ?> upcoming</em></div>
    <div class="s-kpi"><small>Ratings to give</small><b><?= $pending ? $pending . ' pending' : 'None' ?></b><em><?= $toRate ? 'Room ' . h($toRate['room_number']) . ' · ' . h($toRate['prev_section']) : 'All caught up' ?></em></div>
</div>

<div class="s-two">
    <section class="s-card">
        <h3 class="s-ct">Today's schedule <a href="usage.php">View room usage →</a></h3>
        <?php if (!$classes): ?><p class="s-muted">No classes today.</p><?php endif; ?>
        <?php foreach ($classes as $c): [$lbl, $cls] = $status($c); ?>
        <div class="s-row">
            <span><?= substr($c['start_time'], 0, 5) ?> - <?= substr($c['end_time'], 0, 5) ?></span>
            <span><b>Room <?= h($c['room_number']) ?></b><small><?= h($c['building'] ?? '') ?></small></span>
            <span class="s-pill <?= $cls ?>"><?= $lbl ?></span>
        </div>
        <?php endforeach; ?>
    </section>

    <section class="s-card">
        <h3 class="s-ct">Latest rating for your section</h3>
        <?php if (!$latest): ?>
            <p class="s-muted">No ratings yet.</p>
        <?php else: ?>
            <div class="s-muted">From <?= h($latest['rated_by']) ?> · <?= h($latest['usage_date']) ?>, Room <?= h($latest['room_number']) ?></div>
            <div class="s-stars"><?= str_repeat('★', (int)$latest['score']) . str_repeat('☆', 5 - (int)$latest['score']) ?></div>
            <?php if ($latest['comment']): ?><p class="s-quote">“<?= h($latest['comment']) ?>”</p><?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<div class="s-two" style="grid-template-columns:1fr">
    <section class="s-card s-handoff">
        <h3 class="s-ct">Your room handoff</h3>
        <div class="s-steps">
            <?php foreach ($steps as $i => [$done, $name, $hint]): ?>
            <div><b><?= sprintf('%02d', $i + 1) ?> <?= $name ?><?= $done ? ' ✓' : '' ?></b>
                <small><?= $done ? 'Done' : ($i === $firstOpen ? 'Your next step' : $hint) ?></small></div>
            <?php endforeach; ?>
        </div>
    </section>

</div>

<?php endif; ?>
<?php include 'footer.php'; ?>