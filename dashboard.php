<?php
require 'db.php';
require_login();
$title = 'Home';
include 'header.php';
?>
<h2>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></h2>
<p class="sub">What do you want to do?</p>

<div class="grid">
    <a class="tile" href="usage.php"><b>Room Usage</b><span>Time in and out of your room</span></a>
    <a class="tile" href="rate.php"><b>Rate Rooms</b><span>Rate how the last class left it</span></a>
    <a class="tile" href="report.php"><b>Weekly Report</b><span>See the cleanliness results</span></a>
    <?php if (is_admin()): ?>
    <a class="tile" href="rooms.php"><b>Rooms</b><span>Add or edit rooms</span></a>
    <a class="tile" href="sections.php"><b>Sections</b><span>Add or edit classes</span></a>
    <a class="tile" href="schedules.php"><b>Schedules</b><span>Who uses which room, when</span></a>
    <a class="tile" href="users.php"><b>Users</b><span>Accounts and passwords</span></a>
    <?php endif; ?>
</div>
<?php include 'footer.php'; ?>