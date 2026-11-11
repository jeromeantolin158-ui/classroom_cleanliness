<?php
$cur = basename($_SERVER['SCRIPT_NAME']);
$pend_ = [];
try {
    $pend_ = $pdo->query("SELECT r.request_id, r.requested_at, u.username, u.full_name FROM password_requests r JOIN users u ON u.user_id = r.user_id WHERE r.status = 'pending' ORDER BY r.requested_at")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$ic = [
  'dash'  => '<path d="M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
  'room'  => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 21v-6h6v6"/>',
  'sec'   => '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3 3-5 6-5s6 2 6 5M16 11a3 3 0 1 0 0-6"/>',
  'sch'   => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/>',
  'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5M17 4.5a3.5 3.5 0 0 1 0 7M22 20c0-2.5-1.5-4-4-4.8"/>',
  'acc'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
  'out'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
  'bell'  => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8M10 20a2 2 0 0 0 4 0"/>',
];
function s_icon(string $p): string { return '<svg class="s-ic" viewBox="0 0 24 24">' . $p . '</svg>'; }

$nav_main = [['dashboard.php', 'Dashboard', $ic['dash']]];
$nav_manage = [['rooms.php', 'Rooms', $ic['room']], ['sections.php', 'Sections', $ic['sec']], ['schedules.php', 'Schedules', $ic['sch']], ['users.php', 'Users', $ic['users']]];
$nav_all = array_merge($nav_main, $nav_manage, [['profile.php', 'Account', $ic['acc']]]);
$ic_out = $ic['out'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title ?? 'Classroom Cleaning') ?></title>
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="student.css">
</head>
<body class="stu adm">
<aside class="s-side">
    <div class="s-brand"><b>CICI</b><small>Classroom Cleaning</small></div>
    <div class="s-label">Admin workspace</div>
    <nav class="s-nav">
        <?php foreach ($nav_main as [$f, $l, $p]): ?>
            <a href="<?= $f ?>" class="<?= $cur === $f ? 'on' : '' ?>"><span class="s-l"><?= s_icon($p) ?><?= $l ?></span></a>
        <?php endforeach; ?>
    </nav>
    <div class="s-label">Manage</div>
    <nav class="s-nav">
        <?php foreach ($nav_manage as [$f, $l, $p]): ?>
            <a href="<?= $f ?>" class="<?= $cur === $f ? 'on' : '' ?>"><span class="s-l"><?= s_icon($p) ?><?= $l ?></span></a>
        <?php endforeach; ?>
    </nav>
    <div class="s-foot">
        <a href="profile.php" class="<?= $cur === 'profile.php' ? 'on' : '' ?>"><span class="s-l"><?= s_icon($ic['acc']) ?>Account</span></a>
        <form method="post" action="logout.php"><?= csrf_field() ?><button type="submit"><span class="s-l"><?= s_icon($ic['out']) ?>Sign out</span></button></form>
    </div>
</aside>
<div class="s-main">
    <header class="s-top">
        <div class="s-crumb">Admin portal <span>/</span> <b><?= h($title ?? '') ?></b></div>
        <div class="s-right">
            <div class="bell-wrap" id="bellWrap">
                <button type="button" class="bell" id="bell" aria-label="Notifications" aria-expanded="false"><?= s_icon($ic['bell']) ?><span class="bell-dot" id="bellN" <?= $pend_ ? '' : 'hidden' ?>><?= count($pend_) ?></span></button>
                <div class="bell-pop" id="bellPop" hidden>
                    <div class="bell-head">Notifications</div>
                    <div id="bellList">
                        <?php foreach ($pend_ as $p): ?>
                        <div class="bell-item" data-id="<?= (int)$p['request_id'] ?>" data-pending="1">
                            <div class="bell-body"><b><?= h($p['full_name']) ?></b> <span>(<?= h($p['username']) ?>)</span> forgot their password.<small><?= h(substr($p['requested_at'], 0, 16)) ?></small></div>
                            <div class="bell-actions"><button type="button" data-act="reset">Reset</button><button type="button" class="ghost" data-act="dismiss">Dismiss</button></div>
                        </div>
                        <?php endforeach; ?>
                        <div class="bell-empty" id="bellEmpty" <?= $pend_ ? 'hidden' : '' ?>>No new notifications.</div>
                    </div>
                </div>
            </div>
            <a class="s-user" href="profile.php"><b><?= h($_SESSION['full_name'] ?? '') ?></b><small>Administrator</small></a>
        </div>
    </header>
    <main class="container s-content">
<script>
(function () {
    var wrap = document.getElementById('bellWrap'), bell = document.getElementById('bell'),
        pop = document.getElementById('bellPop'), list = document.getElementById('bellList'),
        dot = document.getElementById('bellN'), empty = document.getElementById('bellEmpty'),
        csrf = <?= json_encode($_SESSION['csrf'] ?? '') ?>;

    function refresh() {
        var n = list.querySelectorAll('.bell-item[data-pending="1"]').length;
        dot.textContent = n; dot.hidden = n === 0;
        empty.hidden = list.querySelectorAll('.bell-item').length > 0;
    }
    function toggle(show) { pop.hidden = !show; bell.setAttribute('aria-expanded', show ? 'true' : 'false'); }

    bell.addEventListener('click', function () { toggle(pop.hidden); });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) toggle(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') toggle(false); });

    function send(action, id) {
        var f = new FormData();
        f.append('csrf', csrf); f.append('action', action); f.append('request_id', id);
        return fetch('notify.php', { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    list.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-act]');
        if (!b) return;
        var item = b.closest('.bell-item'), act = b.dataset.act;
        if (act === 'ok') { item.remove(); refresh(); return; }
        if (act === 'reset' && !confirm('Reset this password?')) return;
        b.disabled = true;
        send(act, item.dataset.id).then(function (d) {
            if (!d.ok) throw new Error('failed');
            if (act === 'dismiss') { item.remove(); }
            else {
                item.dataset.pending = '0';
                var box = item.querySelector('.bell-body'); box.textContent = '';
                var t = document.createElement('div'); t.textContent = 'Temporary password for ' + d.username + ':';
                var c = document.createElement('code'); c.textContent = d.temp;
                var s = document.createElement('small'); s.textContent = 'Shown once. Give it to the student privately.';
                box.appendChild(t); box.appendChild(c); box.appendChild(s);
                item.querySelector('.bell-actions').innerHTML = '<button type="button" data-act="ok">Done</button>';
            }
            refresh();
        }).catch(function () { b.disabled = false; alert('Something went wrong. Reload the page and try again.'); });
    });
})();
</script>