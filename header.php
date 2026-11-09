<?php
$current = basename($_SERVER['SCRIPT_NAME']);
$admin = is_admin();
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
<body class="student <?= $admin ? 'admin' : '' ?>">
<?php if ($admin): ?>
<header class="sheader">
    <a class="logo" href="dashboard.php">CICI</a>
</header>
<?php else: ?>
<header class="sheader">
    <a class="logo" href="dashboard.php">CICI</a>
    <div class="hbtns">
        <?php foreach (($header_buttons ?? []) as [$label, $href]): ?>
            <a class="hbtn" href="<?= htmlspecialchars($href) ?>"><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>
    </div>
</header>
<?php endif; ?>
<main class="container">