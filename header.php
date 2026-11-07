<?php
$current = basename($_SERVER['SCRIPT_NAME']);
function nav_link(string $file, string $label): string {
    global $current;
    $cls = $current === $file ? ' class="active"' : '';
    return '<a href="' . $file . '"' . $cls . '>' . $label . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title ?? 'Classroom Cleanliness') ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="nav">
    <span class="brand">Classroom Cleanliness</span>
    <div class="links">
        <?= nav_link('dashboard.php', 'Home') ?>
        <?= nav_link('usage.php', 'Room Usage') ?>
        <?= nav_link('rate.php', 'Rate Rooms') ?>
        <?= nav_link('report.php', 'Report') ?>
        <?php if (is_admin()): ?>
            <?= nav_link('rooms.php', 'Rooms') ?>
            <?= nav_link('sections.php', 'Sections') ?>
            <?= nav_link('schedules.php', 'Schedules') ?>
            <?= nav_link('users.php', 'Users') ?>
        <?php endif; ?>
        <form method="post" action="logout.php" style="display:inline">
            <?= csrf_field() ?>
            <button type="submit">Log out</button>
        </form>
    </div>
</nav>
<main class="container">