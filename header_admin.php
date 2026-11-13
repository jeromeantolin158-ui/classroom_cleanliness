<?php
require_once __DIR__ . '/lib.php';
$cur = $nav_cur ?? basename($_SERVER['SCRIPT_NAME']);   // a page can set $nav_cur to highlight another menu item
$nav = [['dashboard.php', 'Dashboard', 'dash'], ['rooms.php', 'Rooms', 'room'], ['sections.php', 'Sections', 'sec'],
        ['schedules.php', 'Schedule', 'sch'], ['users.php', 'Users', 'users']];

// Pending "forgot password" requests for the bell
$pending_requests = $pdo->query("SELECT r.request_id, r.requested_at, u.username, u.full_name FROM password_requests r JOIN users u ON u.user_id = r.user_id WHERE r.status = 'pending' ORDER BY r.requested_at")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title ?? 'Classroom Cleaning') ?></title>
<link rel="stylesheet" href="app.css">
</head>
<body class="stu adm">
<aside class="s-side">
    <div class="s-brand"><b>CICI</b><small>Classroom Cleaning</small></div>
    <div class="s-label">Admin workspace</div>
    <nav class="s-nav">
        <?php foreach ($nav as [$f, $l, $i]): ?>
            <a href="<?= $f ?>" class="<?= $cur === $f ? 'on' : '' ?>"><span class="s-l"><?= icon($i) ?><?= $l ?></span></a>
        <?php endforeach; ?>
    </nav>
    <div class="s-foot">
        <a href="help.php" class="<?= $cur === 'help.php' ? 'on' : '' ?>"><span class="s-l"><?= icon('help') ?>Help &amp; guidelines</span></a>
        <form method="post" action="logout.php"><?= csrf_field() ?><button type="submit"><span class="s-l"><?= icon('out') ?>Sign out</span></button></form>
    </div>
</aside>
<div class="s-main">
    <header class="s-top">
        <div class="s-crumb">Admin portal <span>/</span> <b><?= h($title ?? '') ?></b></div>
        <div class="s-right">
            <div class="bell-wrap" id="bellWrap" data-csrf="<?= h($_SESSION['csrf']) ?>">
                <button type="button" class="bell" id="bell" aria-label="Notifications" aria-expanded="false"><?= icon('bell') ?><span class="bell-dot" id="bellN" <?= $pending_requests ? '' : 'hidden' ?>><?= count($pending_requests) ?></span></button>
                <div class="bell-pop" id="bellPop" hidden>
                    <div class="bell-head">Notifications</div>
                    <div id="bellList">
                        <?php foreach ($pending_requests as $p): ?>
                        <div class="bell-item" data-id="<?= (int)$p['request_id'] ?>" data-pending="1">
                            <div class="bell-body"><b><?= h($p['full_name']) ?></b> <span>(<?= h($p['username']) ?>)</span> forgot their password.<small><?= h(substr($p['requested_at'], 0, 16)) ?></small></div>
                            <div class="bell-actions"><button type="button" data-act="reset">Reset</button><button type="button" class="ghost" data-act="dismiss">Dismiss</button></div>
                        </div>
                        <?php endforeach; ?>
                        <div class="bell-empty" id="bellEmpty" <?= $pending_requests ? 'hidden' : '' ?>>No new notifications.</div>
                    </div>
                </div>
            </div>
            <a class="s-user <?= $cur === 'profile.php' ? 'on' : '' ?>" href="profile.php"><b><?= h($_SESSION['full_name'] ?? '') ?></b><small>Administrator access</small></a>
        </div>
    </header>
    <main class="s-content">
