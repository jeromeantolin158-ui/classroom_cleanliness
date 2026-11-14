<?php
// Copy to mail.local.php and fill in. mail.local.php is in .gitignore, so your password never reaches GitHub.
// Gmail: turn on 2-Step Verification, then create an "App password" (Google Account > Security). Use that, not your real password.
return [
    'enabled'       => false,                         // false = dry run (nothing is sent). true = really send.
    'host'          => 'smtp.gmail.com',
    'port'          => 587,
    'secure'        => 'tls',
    'user'          => 'yourname@gmail.com',
    'pass'          => 'your-16-letter-app-password',
    'from'          => 'yourname@gmail.com',
    'from_name'     => 'SpotLess',
    'base_url'      => 'http://localhost/classroom',  // used for the link inside the email
    'grace_minutes' => 10,                            // remind when nobody timed in this long after the class starts
];
