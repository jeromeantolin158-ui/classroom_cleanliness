<?php
require 'lib.php';
require_admin();
close_stale_sessions($pdo);

[$week, $ws, $we] = week_bounds($_GET['week'] ?? null);

$stmt = $pdo->prepare("
    SELECT u.usage_date, rt.rated_at, rt.score, rt.comment, rm.room_number, rm.building,
           s.section_name, rb.section_name AS rated_by
    FROM ratings rt
    JOIN room_usage u ON u.usage_id = rt.usage_id
    JOIN schedules sc ON sc.schedule_id = u.schedule_id
    JOIN rooms rm ON rm.room_id = sc.room_id
    JOIN sections s ON s.section_id = sc.section_id
    JOIN sections rb ON rb.section_id = rt.rated_by_section
    WHERE u.usage_date BETWEEN ? AND ?
    ORDER BY rt.rated_at DESC");
$stmt->execute([$ws->format('Y-m-d'), $we->format('Y-m-d')]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$n = count($rows);
$avg = $n ? round(array_sum(array_column($rows, 'score')) / $n, 1) : null;
$five = count(array_filter($rows, fn($r) => (int)$r['score'] === 5));
$low = count(array_filter($rows, fn($r) => (int)$r['score'] < 3));
$buildings = array_values(array_unique(array_filter(array_map(fn($r) => $r['building'] ?? '', $rows))));
sort($buildings);
$today = date('Y-m-d'); $yest = date('Y-m-d', strtotime('-1 day'));
$when = function ($ts) use ($today, $yest) {
    $d = substr($ts, 0, 10);
    if ($d === $today) return date('g:i A', strtotime($ts));
    if ($d === $yest) return 'Yesterday';
    return date('M j', strtotime($ts));
};

$nav_cur = 'dashboard.php';
$title = 'Latest ratings';
include 'header.php'; ?>
<div class="a-top">
    <div><h1 class="s-h1">Latest ratings</h1><p class="s-sub">Every room handoff, with the feedback behind the score.</p></div>
    <a class="a-back" href="dashboard.php">Back to dashboard</a>
</div>

<div class="a-bar">
    <input type="search" data-search placeholder="Search section or room...">
    <select data-filter data-attr="f">
        <option value="">All buildings</option>
        <?php foreach ($buildings as $b): ?><option><?= h($b) ?></option><?php endforeach; ?>
    </select>
    <select data-filter data-attr="r">
        <option value="">All ratings</option>
        <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $i ?> star<?= $i === 1 ? '' : 's' ?></option><?php endfor; ?>
    </select>
    <form method="get" style="margin:0;margin-left:auto"><input type="week" name="week" value="<?= h($week) ?>" onchange="this.form.submit()" style="min-height:42px;background:#fff;border:1px solid #e2ebe5;border-radius:8px;padding:0 12px"></form>
</div>

<div class="s-kpis">
    <div class="s-kpi"><small>Weekly average</small><b><?= $avg !== null ? $avg . ' / 5' : '-' ?></b><em>Across all rated handoffs</em></div>
    <div class="s-kpi"><small>Ratings this week</small><b><?= $n ?></b><em><?= $ws->format('M j') ?> - <?= $we->format('M j, Y') ?></em></div>
    <div class="s-kpi"><small>5-star ratings</small><b><?= $five ?></b><em><?= $n ? round($five / $n * 100) : 0 ?>% of weekly ratings</em></div>
    <div class="s-kpi"><small>Needs attention</small><b style="color:<?= $low ? '#b42318' : 'inherit' ?>"><?= $low ?></b><em>Ratings below 3 stars</em></div>
</div>

<section class="a-card">
    <h3 id="a-count"><?= $n ?> ratings</h3>
    <p class="s-muted">Recent room handoffs, newest first.</p>
    <div class="table-wrap"><table class="a-table tight lt" data-paged="10" data-noun="ratings">
        <thead><tr><th>Section rated</th><th>Room</th><th>Rated by</th><th>Score</th><th>Feedback</th><th>Received</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $sc = (int)$r['score']; ?>
        <tr class="r" data-q="<?= h(strtolower($r['section_name'] . ' ' . $r['room_number'] . ' ' . ($r['building'] ?? '') . ' ' . $r['rated_by'] . ' ' . ($r['comment'] ?? ''))) ?>" data-f="<?= h($r['building'] ?? '') ?>" data-r="<?= $sc ?>">
            <td class="nm"><?= h($r['section_name']) ?></td>
            <td><?= h($r['room_number']) ?><?= $r['building'] ? ' &middot; ' . h($r['building']) : '' ?></td>
            <td><?= h($r['rated_by']) ?></td>
            <td><span class="<?= $sc < 3 ? 'sc-low' : 'sc-ok' ?>"><?= $sc ?> / 5</span></td>
            <td><?= h($r['comment'] ?? '—') ?></td>
            <td><span class="a-st go"><?= h($when($r['rated_at'])) ?></span></td>
        </tr>
        <?php endforeach; ?>
        <tr class="none" hidden><td colspan="6">No ratings match.</td></tr>
        </tbody>
    </table></div>
    <div class="a-pager"><span id="a-showing"></span><div><button type="button" id="a-prev">Previous</button><button type="button" id="a-next">Next</button></div></div>
    <p class="s-muted" style="margin:12px 0 0">Updated today at <?= date('g:i A') ?></p>
</section>

<section class="s-note"><small style="color:#17492f">Rating scale: 1 = Needs improvement &middot; 5 = Excellent. Low ratings help identify rooms that need attention.</small></section>
<?php include 'footer.php'; ?>
