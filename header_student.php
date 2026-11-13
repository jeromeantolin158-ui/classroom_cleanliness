<?php
require_once __DIR__ . '/lib.php';
$cur = basename($_SERVER['SCRIPT_NAME']);
$stmt = $pdo->prepare("SELECT u.full_name, s.section_id, s.section_name FROM users u LEFT JOIN sections s ON s.section_id = u.section_id WHERE u.user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$me_ = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$badge = !empty($me_['section_id']) ? rate_pending_count($pdo, (int)$me_['section_id']) : 0;   // ratings waiting for this section

$nav = [['dashboard.php', 'Dashboard', 'dash'], ['usage.php', 'Room usage', 'use'], ['rate.php', 'Rating', 'rate'], ['profile.php', 'Account', 'acc']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title ?? 'Classroom Cleaning') ?></title>
<link rel="stylesheet" href="app.css">
</head>
<body class="stu">
<aside class="s-side">
    <div class="s-brand"><b>CICI</b><small>Classroom Cleaning</small></div>
    <div class="s-label">Student workspace</div>
    <nav class="s-nav">
        <?php foreach ($nav as [$f, $l, $i]): ?>
            <a href="<?= $f ?>" class="<?= $cur === $f ? 'on' : '' ?>"><span class="s-l"><?= icon($i) ?><?= $l ?></span><?php if ($f === 'rate.php' && $badge): ?><span class="s-badge"><?= $badge ?></span><?php endif; ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="s-foot">
        <a href="help.php"><span class="s-l"><?= icon('help') ?>Help &amp; guidelines</span></a>
        <form method="post" action="logout.php"><?= csrf_field() ?><button type="submit"><span class="s-l"><?= icon('out') ?>Sign out</span></button></form>
    </div>
</aside>
<div class="s-main">
    <header class="s-top">
        <div class="s-crumb">Student portal <span>/</span> <b><?= h($title ?? '') ?></b></div>
        <a class="s-user <?= $cur === 'profile.php' ? 'on' : '' ?>" href="profile.php">
            <b><?= h($me_['full_name'] ?? '') ?></b>
            <small>Representative<?= !empty($me_['section_name']) ? ' &middot; ' . h($me_['section_name']) : '' ?></small>
        </a>
    </header>
    <main class="s-content">
