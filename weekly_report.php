<?php
require 'lib.php';
require_admin();
close_stale_sessions($pdo);

[$week, $ws, $we] = week_bounds($_GET['week'] ?? null);
$pws = clone $ws; $pws->modify('-7 days'); $pwe = clone $pws; $pwe->modify('+6 days');
$bFilter = $_GET['building'] ?? '';
$today = date('Y-m-d'); $nowT = date('H:i:s');
$NB = '(No building)';
$GRACE = 600; // on time = timed in within 10 minutes after the class starts (or earlier)

$bList = array_map(fn($r) => $r['b'], $pdo->query("SELECT DISTINCT COALESCE(building, '$NB') AS b FROM rooms ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC));

$scs = $pdo->query("
    SELECT sc.schedule_id, sc.day_of_week, sc.start_time, sc.end_time, sc.section_id, sc.valid_from, sc.valid_to,
           COALESCE(rm.building, '$NB') AS building
    FROM schedules sc JOIN rooms rm ON rm.room_id = sc.room_id")->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare("SELECT schedule_id, usage_date, time_in FROM room_usage WHERE usage_date BETWEEN ? AND ?");
$stmt->execute([$ws->format('Y-m-d'), $we->format('Y-m-d')]);
$use = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) $use[$u['schedule_id'] . '|' . $u['usage_date']] = $u['time_in'];

$total = $started = $timed = $ontime = $lateNow = $missed = 0;
$dayHas = []; $dayCount = [];
$bs = []; // per building stats
for ($i = 0; $i < 7; $i++) {
    $d = clone $ws; $d->modify("+$i days"); $ds = $d->format('Y-m-d'); $dow = $i + 1;
    foreach ($scs as $sc) {
        if ((int)$sc['day_of_week'] !== $dow || $ds < $sc['valid_from'] || $ds > $sc['valid_to']) continue;
        if ($bFilter !== '' && $sc['building'] !== $bFilter) continue;
        $b = $sc['building'];
        $bs[$b] ??= ['secs' => [], 'started' => 0, 'ontime' => 0, 'n' => 0, 'sum' => 0];
        $bs[$b]['secs'][$sc['section_id']] = true;
        $total++; $dayHas[$dow] = true; $dayCount[$ds] = ($dayCount[$ds] ?? 0) + 1;
        $isStarted = $ds < $today || ($ds === $today && $sc['start_time'] <= $nowT);
        if (!$isStarted) continue;
        $started++; $bs[$b]['started']++;
        $ti = $use[$sc['schedule_id'] . '|' . $ds] ?? null;
        if ($ti) {
            $timed++;
            if (strtotime($ti) <= strtotime("$ds {$sc['start_time']}") + $GRACE) { $ontime++; $bs[$b]['ontime']++; }
        } else {
            $missed++;
            if ($ds === $today && $sc['end_time'] > $nowT) $lateNow++;
        }
    }
}

$ratingRows = function (DateTime $a, DateTime $z) use ($pdo, $bFilter, $NB) {
    $s = $pdo->prepare("
        SELECT u.usage_date, rt.score, COALESCE(rm.building, '$NB') AS building, rm.room_id, rm.room_number, sec.section_id, sec.section_name
        FROM ratings rt
        JOIN room_usage u ON u.usage_id = rt.usage_id
        JOIN schedules sc ON sc.schedule_id = u.schedule_id
        JOIN rooms rm ON rm.room_id = sc.room_id
        JOIN sections sec ON sec.section_id = sc.section_id
        WHERE u.usage_date BETWEEN ? AND ?");
    $s->execute([$a->format('Y-m-d'), $z->format('Y-m-d')]);
    return array_values(array_filter($s->fetchAll(PDO::FETCH_ASSOC), fn($r) => $bFilter === '' || $r['building'] === $bFilter));
};
$rows = $ratingRows($ws, $we);
$prev = $ratingRows($pws, $pwe);
$avgOf = fn($r) => count($r) ? round(array_sum(array_column($r, 'score')) / count($r), 1) : null;
$avg = $avgOf($rows); $pavg = $avgOf($prev);
$diff = ($avg !== null && $pavg !== null) ? round($avg - $pavg, 1) : null;

$perDay = []; $perSec = []; $secsRated = [];
foreach ($rows as $r) {
    $perDay[$r['usage_date']][] = (int)$r['score'];
    $perSec[$r['section_id']]['n'] = ($perSec[$r['section_id']]['n'] ?? 0) + 1;
    $perSec[$r['section_id']]['sum'] = ($perSec[$r['section_id']]['sum'] ?? 0) + (int)$r['score'];
    $perSec[$r['section_id']]['name'] = $r['section_name'];
    $secsRated[$r['section_id']] = true;
    $b = $r['building'];
    $bs[$b] ??= ['secs' => [], 'started' => 0, 'ontime' => 0, 'n' => 0, 'sum' => 0];
    $bs[$b]['n']++; $bs[$b]['sum'] += (int)$r['score'];
}
// room ranking: cleanest 3 and dirtiest 3 (the two lists never share a room)
$perRoom = [];
foreach ($rows as $r) {
    $x = &$perRoom[$r['room_id']];
    $x['name'] = 'Room ' . $r['room_number']; $x['b'] = $r['building'];
    $x['n'] = ($x['n'] ?? 0) + 1; $x['sum'] = ($x['sum'] ?? 0) + (int)$r['score'];
    unset($x);
}
foreach ($perRoom as &$p) $p['a'] = $p['sum'] / $p['n'];
unset($p);
uasort($perRoom, fn($a, $b) => [$b['a'], $b['n']] <=> [$a['a'], $a['n']]);
$ranked = array_values($perRoom);
$cleanest = array_slice($ranked, 0, 3);
$dirtiest = array_reverse(array_slice(array_slice($ranked, 3), -3));
// best section
$best = null;
foreach ($perSec as $p) { $a = $p['sum'] / $p['n']; if ($best === null || $a > $best['a'] || ($a == $best['a'] && $p['n'] > $best['n'])) $best = ['name' => $p['name'], 'a' => $a, 'n' => $p['n']]; }
// trend: first vs last day that has ratings
$dAvg = [];
foreach ($perDay as $d => $sc_) $dAvg[$d] = array_sum($sc_) / count($sc_);
ksort($dAvg);
$trend = null;
if (count($dAvg) >= 2) {
    $kd = array_keys($dAvg); $f1 = $kd[0]; $l1 = $kd[count($kd) - 1];
    $delta = $dAvg[$l1] - $dAvg[$f1];
    $trend = ['txt' => $delta > 0.05 ? 'Cleaning scores are improving' : ($delta < -0.05 ? 'Cleaning scores are slipping' : 'Cleaning scores are steady'),
              'sub' => number_format($dAvg[$f1], 1) . ' ' . date('l', strtotime($f1)) . ' → ' . number_format($dAvg[$l1], 1) . ' ' . date('l', strtotime($l1)),
              'bad' => $delta < -0.05];
}
// days shown in the chart: Mon-Fri always, weekend only if it has classes
$show = [];
for ($i = 0; $i < 7; $i++) if ($i < 5 || !empty($dayHas[$i + 1])) $show[] = $i;
$activeDays = max(1, count($dayCount));
$weekIsNow = $today >= $ws->format('Y-m-d') && $today <= $we->format('Y-m-d');
$upTo = $weekIsNow ? 'Week to date · through ' . date('l, M j') : 'Full week';
ksort($bs);

$nav_cur = 'dashboard.php';
$title = 'Weekly report';
include 'header.php'; ?>
<div class="a-top">
    <div><h1 class="s-h1">Weekly report</h1><p class="s-sub">Cleaning performance and attendance across campus.</p></div>
    <a class="a-back" href="dashboard.php">Back to dashboard</a>
</div>

<form method="get" class="a-bar">
    <input type="week" name="week" value="<?= h($week) ?>" onchange="this.form.submit()" style="min-height:42px;background:#fff;border:1px solid #e2ebe5;border-radius:8px;padding:0 12px">
    <select name="building" onchange="this.form.submit()">
        <option value="">All buildings</option>
        <?php foreach ($bList as $b): ?><option <?= $bFilter === $b ? 'selected' : '' ?>><?= h($b) ?></option><?php endforeach; ?>
    </select>
    <span class="s-muted" style="margin-left:auto"><?= $ws->format('M j') ?> - <?= $we->format('M j, Y') ?> &middot; <?= h($upTo) ?></span>
</form>

<div class="s-kpis">
    <div class="s-kpi"><small>Average rating</small><b><?= $avg !== null ? $avg . ' / 5' : '-' ?></b>
        <em class="<?= $diff !== null && $diff < 0 ? '' : 'd-kpi-up' ?>"><?= $diff === null ? 'No previous week to compare' : ($diff >= 0 ? '&uarr; ' : '&darr; ') . abs($diff) . ' vs previous week' ?></em></div>
    <div class="s-kpi"><small>Classes scheduled</small><b><?= $total ?></b><em><?= round($total / $activeDays) ?> per day &middot; <?= $started ?> started so far</em></div>
    <div class="s-kpi"><small>On-time time-ins</small><b><?= $ontime ?> / <?= $started ?></b><em><?= $started ? round($ontime / $started * 100, 1) : 0 ?>% of started classes</em></div>
    <div class="s-kpi"><small>Ratings received</small><b><?= count($rows) ?></b><em>Across <?= count($secsRated) ?> section<?= count($secsRated) === 1 ? '' : 's' ?></em></div>
</div>

<div class="w-grid">
    <section class="d-card">
        <h3>Daily cleaning ratings</h3>
        <p class="s-muted" style="margin:-8px 0 8px">Average score / 5</p>
        <div class="w-chart">
            <?php foreach ($show as $i):
                $d = clone $ws; $d->modify("+$i days"); $ds = $d->format('Y-m-d');
                $v = isset($perDay[$ds]) ? array_sum($perDay[$ds]) / count($perDay[$ds]) : null; ?>
            <div class="w-bar <?= $ds === $today ? 'today' : '' ?>">
                <b><?= $v !== null ? number_format($v, 1) : '—' ?></b>
                <i style="height:<?= $v !== null ? round($v / 5 * 100) : 0 ?>%"></i>
                <span><?= $d->format('D j') ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <aside class="w-side">
        <h3>Weekly highlights</h3>
        <?php if ($trend): ?><div class="w-hi <?= $trend['bad'] ? 'warn' : '' ?>"><b><?= h($trend['txt']) ?></b><small><?= h($trend['sub']) ?></small></div>
        <?php else: ?><div class="w-hi plain"><b>Not enough days rated yet</b><small>Trends appear once two days have ratings.</small></div><?php endif; ?>
        <?php if ($weekIsNow): ?>
        <div class="w-hi warn"><b><?= $lateNow ?> missing time-in<?= $lateNow === 1 ? '' : 's' ?> right now</b><small>Follow up with class representatives.</small></div>
        <?php else: ?>
        <div class="w-hi warn"><b><?= $missed ?> class<?= $missed === 1 ? '' : 'es' ?> never timed in</b><small>Started but no time-in was recorded.</small></div>
        <?php endif; ?>
        <div class="w-hi plain"><b>Best-rated section<?= $best ? ' · ' . h($best['name']) : '' ?></b><small><?= $best ? number_format($best['a'], 1) . ' / 5 average this week' : 'No ratings yet this week' ?></small></div>
    </aside>
</div>

<section class="a-card">
    <h3>Room ranking</h3>
    <p class="s-muted">Average rating this week. Rooms with more ratings rank higher when scores tie.</p>
    <div class="rk-grid">
        <?php foreach ([['Cleanest rooms', $cleanest, 'good'], ['Needs attention', $dirtiest, 'bad']] as [$label, $list, $cls]): ?>
        <div>
            <div class="rk-title"><?= $label ?></div>
            <?php if (!$list): ?><p class="s-muted">Not enough rated rooms yet.</p><?php endif; ?>
            <?php foreach ($list as $i => $p): ?>
            <div class="rk-row">
                <span class="rk-n"><?= $i + 1 ?></span>
                <div><b><?= h($p['name']) ?></b><small><?= h($p['b']) ?> &middot; <?= $p['n'] ?> rating<?= $p['n'] === 1 ? '' : 's' ?></small></div>
                <em class="rk-<?= $cls ?>"><?= number_format($p['a'], 1) ?></em>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="a-card">
    <h3>Building performance</h3>
    <p class="s-muted">On time = timed in within 10 minutes of the class start.</p>
    <div class="table-wrap"><table class="a-table lt">
        <thead><tr><th>Building</th><th>Sections</th><th>Ratings</th><th>Average</th><th>On-time time-ins</th></tr></thead>
        <tbody>
        <?php if (!$bs): ?><tr><td colspan="5" style="text-align:center;color:#64756a">No data for this week.</td></tr><?php endif; ?>
        <?php foreach ($bs as $b => $x): ?>
        <tr>
            <td class="nm"><?= h($b) ?></td>
            <td><?= count($x['secs']) ?></td>
            <td><?= $x['n'] ?></td>
            <td><?= $x['n'] ? number_format($x['sum'] / $x['n'], 1) . ' / 5' : '—' ?></td>
            <td><span class="sc-ok"><?= $x['started'] ? round($x['ontime'] / $x['started'] * 100) . '%' : '—' ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="s-muted" style="margin:12px 0 0">Updated today at <?= date('g:i A') ?></p>
</section>
<?php include 'footer.php'; ?>
