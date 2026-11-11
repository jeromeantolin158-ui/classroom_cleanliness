<?php
require 'db.php';
require_login();
$title = 'Help & guidelines';
include 'header.php';
?>
<h1 class="s-h1">Help &amp; guidelines</h1>
<p class="s-sub">A simple habit. A cleaner campus.</p>
<section class="s-card">
    <div class="s-steps">
        <div><b>01 Time in</b><small>Open Room usage and tap Time in when your class arrives. It opens 30 minutes before class.</small></div>
        <div><b>02 Rate the room</b><small>Rate how clean the previous section left it, from 1 to 5.</small></div>
        <div><b>03 Time out</b><small>Tap Time out when you leave. If you forget, the system closes it at your class end time.</small></div>
    </div>
</section>
<section class="s-note"><b>Forgot your password?</b>Use "Forgot password?" on the sign-in page. Your administrator will give you a temporary one.</section>
<?php include 'footer.php'; ?>