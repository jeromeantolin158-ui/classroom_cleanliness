<?php
// Sends email through SMTP (PHPMailer). Settings live in mail.local.php, which is never committed.
require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

function mail_config(): array {
    static $c = null;
    if ($c === null) {
        $file = __DIR__ . '/mail.local.php';
        $c = (is_file($file) ? require $file : []) + [
            'enabled' => false, 'host' => '', 'port' => 587, 'secure' => 'tls', 'user' => '', 'pass' => '',
            'from' => '', 'from_name' => 'SpotLess', 'base_url' => '', 'grace_minutes' => 10,
        ];
    }
    return $c;
}

/** Sends one email. Returns [true, ''] or [false, 'error text']. */
function send_mail(string $to, string $subject, string $html, string $text): array {
    $c = mail_config();
    $m = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $m->isSMTP();
        $m->Host = $c['host'];
        $m->Port = (int)$c['port'];
        $m->SMTPAuth = $c['user'] !== '';
        $m->Username = $c['user'];
        $m->Password = $c['pass'];
        $m->SMTPSecure = $c['secure'];            // 'tls', 'ssl' or '' for none
        $m->SMTPAutoTLS = $c['secure'] !== '';
        $m->CharSet = 'UTF-8';
        $m->Timeout = 10;
        $m->setFrom($c['from'] ?: $c['user'], $c['from_name']);
        $m->addAddress($to);
        $m->isHTML(true);
        $m->Subject = $subject;
        $m->Body = $html;
        $m->AltBody = $text;
        $m->send();
        return [true, ''];
    } catch (Throwable $e) {
        return [false, $m->ErrorInfo ?: $e->getMessage()];
    }
}
