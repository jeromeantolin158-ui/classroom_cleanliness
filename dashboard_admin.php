<?php
// Admin dashboard (loaded by dashboard.php)
$today = date('Y-m-d'); $dow = (int)date('N'); $nowT = date('H:i:s');
[$week, $ws, $we] = week_bounds($_GET['week'] ?? null);
$range = [$ws->format('Y-m-d'), $we->format('Y-m-d')];
$bFilter = $_GET['building'] ?? '';

// Classes today and whether they timed in
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
$done = count(array_filter($todays, fn($t) => $t['time_out']));
$inUse = count(array_filter($todays, fn($t) => $t['usage_id'] && !$t['time_out']));
$upcoming = count($todays) - $done - $inUse;

// Room status right now
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
$bList = array_values(array_unique(array_map(fn($r) => $r['building'] ?? '(No building)', $roomStatus)));
$shown = array_filter($roomStatus, fn($r) => $bFilter === '' || ($r['building'] ?? '(No building)') === $bFilter);
$nIn = $nLate = 0;
foreach ($shown as $r) { if ($r['occupant'] !== null) $nIn++; elseif ($r['scheduled_now'] !== null) $nLate++; }
$nFree = count($shown) - $nIn - $nLate;

// Classes that already started but have no time-in
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM schedules sc
    LEFT JOIN room_usage u ON u.schedule_id = sc.schedule_id AND u.usage_date = ?
    WHERE sc.day_of_week = ? AND sc.start_time < ?::time AND sc.end_time > ?::time
      AND ?::date BETWEEN sc.valid_from AND sc.valid_to AND u.usage_id IS NULL");
$stmt->execute([$today, $dow, $nowT, $nowT, $today]);
$late = (int)$stmt->fetchColumn();

// Sections per building
$buildings = $pdo->query("
    SELECT COALESCE(rm.building, '(No building)') AS building, COUNT(DISTINCT sc.section_id) AS sections
    FROM rooms rm LEFT JOIN schedules sc ON sc.room_id = rm.room_id
    GROUP BY 1 ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
$maxSec = max(1, ...array_map(fn($b) => (int)$b['sections'], $buildings ?: [['sections' => 1]]));

// Weekly average (this week and the one before), plus the average per day for the chart
$avgFor = function (string $from, string $to) use ($pdo) {
    $stmt = $pdo->prepare("SELECT ROUND(AVG(rt.score)::numeric, 1), COUNT(*) FROM ratings rt JOIN room_usage u ON u.usage_id = rt.usage_id WHERE u.usage_date BETWEEN ? AND ?");
    $stmt->execute([$from, $to]);
    [$avg, $n] = $stmt->fetch(PDO::FETCH_NUM);
    return [$avg !== null ? (float)$avg : null, (int)$n];
};
[$wAvg, $wN] = $avgFor(...$range);
$pw = (clone $ws)->modify('-7 days');
[$lwAvg] = $avgFor($pw->format('Y-m-d'), (clone $pw)->modify('+6 days')->format('Y-m-d'));
$diff = ($wAvg !== null && $lwAvg !== null) ? round($wAvg - $lwAvg, 1) : null;

$stmt = $pdo->prepare("SELECT u.usage_date, ROUND(AVG(rt.score)::numeric, 1) AS a FROM ratings rt JOIN room_usage u ON u.usage_id = rt.usage_id WHERE u.usage_date BETWEEN ? AND ? GROUP BY u.usage_date");
$stmt->execute($range);
$perDay = array_map('floatval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'a', 'usage_date'));

// Latest ratings
$ratings = $pdo->query("
    SELECT rt.rated_at, rm.room_number, s.section_name, rb.section_name AS rated_by, rt.score, rt.comment
    FROM ratings rt
    JOIN room_usage u ON u.usage_id = rt.usage_id
    JOIN schedules sc ON sc.schedule_id = u.schedule_id
    JOIN rooms rm ON rm.room_id = sc.room_id
    JOIN sections s ON s.section_id = sc.section_id
    JOIN sections rb ON rb.section_id = rt.rated_by_section
    ORDER BY rt.rated_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

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
<?php include 'footer.php'; ?>
