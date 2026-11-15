<?php
// Shared setup for the JSON API. Always answers in JSON (never a redirect or an HTML page).
require_once __DIR__ . '/../lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function api_fail(int $status, string $error): never {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

function api_ok(array $data): never {
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/** Read-only, admin-only endpoint. 405 not GET, 401 not signed in, 403 not an admin or password change pending. */
function api_require_admin_get(): void {
    global $pdo;
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET');
        api_fail(405, 'Method not allowed. Use GET.');
    }
    if (!isset($_SESSION['user_id'])) api_fail(401, 'Not signed in.');
    $stmt = $pdo->prepare("SELECT role, active, must_change FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u || !$u['active']) api_fail(401, 'Not signed in.');
    if ($u['must_change']) api_fail(403, 'Change your temporary password first.');
    if ($u['role'] !== 'admin') api_fail(403, 'Admins only.');
}

/** ?week=2026-W46 (default: this week). Returns [week, Monday, Sunday]. A bad value is a 400, not a silent fallback. */
function api_week(): array {
    $w = $_GET['week'] ?? null;
    if ($w !== null && !preg_match('/^\d{4}-W(0[1-9]|[1-4]\d|5[0-3])$/', $w)) {
        api_fail(400, "Invalid week. Use the format 2026-W46.");
    }
    return week_bounds($w);
}

/** Integer query parameter with limits, or a 400. */
function api_int(string $name, int $default, int $min, int $max): int {
    if (!isset($_GET[$name])) return $default;
    $v = filter_var($_GET[$name], FILTER_VALIDATE_INT);
    if ($v === false || $v < $min || $v > $max) api_fail(400, "Invalid $name. Use a whole number from $min to $max.");
    return $v;
}
