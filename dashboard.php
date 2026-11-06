<?php
require 'db.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<h2>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></h2>
<a href="rooms.php">Manage Rooms</a> |
<a href="sections.php">Manage Sections</a> |
<a href="schedules.php">Manage Schedules</a> |
<a href="usage.php">Room Usage</a> |
<a href="rate.php">Rate Rooms</a> |
<a href="report.php">Weekly Report</a> |
<?php if (is_admin()): ?><a href="users.php">Manage Users</a><?php endif; ?>
<form method="post" action="logout.php" style="display:inline">
    <?= csrf_field() ?>
    <button type="submit">Log out</button>
</form>

