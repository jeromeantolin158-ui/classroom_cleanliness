<?php
$cur = basename($_SERVER['SCRIPT_NAME']);
$stmt = $pdo->prepare("SELECT u.full_name, s.section_id, s.section_name FROM users u LEFT JOIN sections s ON s.section_id = u.section_id WHERE u.user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$me_ = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$sid_ = (int)($me_['section_id'] ?? 0);
$badge = $sid_ ? rate_pending_count($pdo, $sid_) : 0;

$ic_dash = '<path d="M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>';
$ic_use  = '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>';
$ic_rate = '<path d="M12 2l3 7 7 .5-5.5 4.8 1.8 7.2L12 17.8 5.7 21.5l1.8-7.2L2 9.5 9 9z"/>';
$ic_acc  = '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>';
$ic_out  = '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>';
$ic_help = '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01"/>';
function s_icon(string $p): string { return '<svg class="s-ic" viewBox="0 0 24 24">' . $p . '</svg>'; }

$nav_ = [['dashboard.php', 'Dashboard', $ic_dash], ['usage.php', 'Room usage', $ic_use], ['rate.php', 'Rating', $ic_rate], ['profile.php', 'Account', $ic_acc]];
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
<body class="stu">
<aside class="s-side">
    <div class="s-brand"><b>CICI</b><small>Classroom Cleaning</small></div>
    <div class="s-label">Student workspace</div>
    <nav class="s-nav">
        <?php foreach ($nav_ as [$f, $l, $p]): ?>
            <a href="<?= $f ?>" class="<?= $cur === $f ? 'on' : '' ?>"><span class="s-l"><?= s_icon($p) ?><?= $l ?></span><?php if ($f === 'rate.php' && $badge): ?><span class="s-badge"><?= $badge ?></span><?php endif; ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="s-foot">
        <a href="help.php"><span class="s-l"><?= s_icon($ic_help) ?>Help &amp; guidelines</span></a>
        <form method="post" action="logout.php"><?= csrf_field() ?><button type="submit"><span class="s-l"><?= s_icon($ic_out) ?>Sign out</span></button></form>
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