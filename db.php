<?php
date_default_timezone_set('Asia/Manila');
session_start();
$pdo = new PDO(
    "pgsql:host=localhost;port=5432;dbname=classroom_cleanliness",
    "postgres",
    "jeromelumbao@09",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
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