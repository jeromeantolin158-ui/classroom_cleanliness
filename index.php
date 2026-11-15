<?php
// Home page: send visitors to the app (the dashboard sends signed-out visitors on to the login page).
header('Location: dashboard.php');
exit;
