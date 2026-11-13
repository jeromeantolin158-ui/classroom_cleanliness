<?php
require 'lib.php';
require_login();
close_stale_sessions($pdo);
$title = 'Dashboard';
require is_admin() ? 'dashboard_admin.php' : 'dashboard_student.php';
