</main>
<?php
$cur = basename($_SERVER['SCRIPT_NAME']);
$items = [
    ['dashboard.php', 'Home',       '<path d="M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>'],
    ['usage.php',     'Room Usage', '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'],
    ['rate.php',      'Rate Rooms', '<path d="M12 2l3 7 7 .5-5.5 4.8 1.8 7.2L12 17.8 5.7 21.5l1.8-7.2L2 9.5 9 9z"/>'],
    ['report.php',    'Report',     '<path d="M5 21V10M12 21V4M19 21v-8"/>'],
];
if (is_admin()) {
    $dot = '<circle cx="12" cy="12" r="3"/>';
    $items[] = ['rooms.php',     'Rooms',     '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 21v-6h6v6"/>'];
    $items[] = ['sections.php',  'Sections',  '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3 3-5 6-5s6 2 6 5M16 11a3 3 0 1 0 0-6"/>'];
    $items[] = ['schedules.php', 'Schedules', '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/>'];
    $items[] = ['users.php',     'Users',     '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>'];
}
?>
<nav class="bottomnav <?= is_admin() ? 'adminnav' : '' ?>">
    <?php foreach ($items as [$file, $label, $svg]): ?>
        <a href="<?= $file ?>" class="<?= $cur === $file ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><?= $svg ?></svg><?= $label ?>
        </a>
    <?php endforeach; ?>
    <form method="post" action="logout.php">
        <?= csrf_field() ?>
        <button type="submit">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>Log out
        </button>
    </form>
</nav>
</body>
</html>