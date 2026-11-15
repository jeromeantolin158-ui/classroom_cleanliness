<?php
date_default_timezone_set('Asia/Manila');

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    // Send the login cookie over HTTPS only (also behind a hosting proxy that terminates HTTPS).
    'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
]);
session_start();

// Database settings come from environment variables (hosting: DATABASE_URL, or DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS/DB_SSLMODE) or from db.local.php (your computer). Never commit secrets.
if (getenv('DATABASE_URL')) {                       // one connection string, e.g. the one Neon shows (use the non-pooled one)
    $u = parse_url(getenv('DATABASE_URL'));
    parse_str($u['query'] ?? '', $q);
    $c = ['host' => $u['host'] ?? '', 'port' => $u['port'] ?? 5432, 'name' => ltrim($u['path'] ?? '', '/'),
          'user' => urldecode($u['user'] ?? ''), 'pass' => urldecode($u['pass'] ?? ''), 'sslmode' => $q['sslmode'] ?? 'require'];
} elseif (getenv('DB_HOST')) {
    $c = ['host' => getenv('DB_HOST'), 'port' => getenv('DB_PORT') ?: 5432, 'name' => getenv('DB_NAME'),
          'user' => getenv('DB_USER'), 'pass' => getenv('DB_PASS'), 'sslmode' => getenv('DB_SSLMODE') ?: 'prefer'];
} else {
    $cfg = __DIR__ . '/db.local.php';
    if (!is_file($cfg)) { http_response_code(500); exit('Missing db.local.php (copy db.example.php).'); }
    $c = require $cfg;
}
$pdo = new PDO(
    "pgsql:host={$c['host']};port={$c['port']};dbname={$c['name']};sslmode=" . ($c['sslmode'] ?? 'prefer'),
    $c['user'],
    $c['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function require_login() {
    global $pdo;
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
    $stmt = $pdo->prepare("SELECT active, must_change FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u || !$u['active']) {
        session_destroy();
        header('Location: login.php');
        exit;
    }
     // Temporary password: show only the locked popup until it is changed
    if ($u['must_change']) {
        include __DIR__ . '/force_password.php';
        exit;
    }
}

function is_admin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
}

function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Invalid request.');
    }
}
function close_stale_sessions(PDO $pdo): void {
    $stmt = $pdo->prepare("
        UPDATE room_usage u
        SET time_out = GREATEST(u.usage_date + sc.end_time, u.time_in + interval '1 second'),
            auto_closed = TRUE
        FROM schedules sc
        WHERE sc.schedule_id = u.schedule_id
          AND u.time_out IS NULL
          AND u.time_in IS NOT NULL
          AND (u.usage_date + sc.end_time) < ?::timestamp
    ");
    $stmt->execute([date('Y-m-d H:i:s')]);
}
function h($s): string { return htmlspecialchars((string)$s); }

function rate_pending_count(PDO $pdo, int $sid): int {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM room_usage r2
        JOIN schedules sc2 ON sc2.schedule_id = r2.schedule_id
        JOIN LATERAL (
            SELECT pu.usage_id, pu.time_out, psc.section_id
            FROM room_usage pu JOIN schedules psc ON psc.schedule_id = pu.schedule_id
            WHERE psc.room_id = sc2.room_id AND pu.usage_date = r2.usage_date AND pu.time_in < r2.time_in
            ORDER BY pu.time_in DESC LIMIT 1) p ON TRUE
        LEFT JOIN ratings rt ON rt.usage_id = p.usage_id
        WHERE r2.usage_date = ? AND sc2.section_id = ?
          AND p.time_out IS NOT NULL AND p.section_id <> sc2.section_id
          AND p.time_out <= r2.time_in AND r2.time_in - p.time_out <= interval '2 hours'
          AND rt.rating_id IS NULL");
    $stmt->execute([date('Y-m-d'), $sid]);
    return (int)$stmt->fetchColumn();
}