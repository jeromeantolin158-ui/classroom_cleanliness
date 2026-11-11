<?php
require 'db.php';
require_login();
header('Content-Type: application/json');
if (!is_admin() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}
csrf_check();

$action = $_POST['action'] ?? '';
$rid = (int)($_POST['request_id'] ?? 0);

if ($action === 'dismiss') {
    $pdo->prepare("UPDATE password_requests SET status = 'dismissed', handled_by = ?, handled_at = NOW() WHERE request_id = ? AND status = 'pending'")
        ->execute([$_SESSION['user_id'], $rid]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'reset') {
    $stmt = $pdo->prepare("SELECT r.request_id, u.user_id, u.username FROM password_requests r JOIN users u ON u.user_id = r.user_id WHERE r.request_id = ? AND r.status = 'pending'");
    $stmt->execute([$rid]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) { echo json_encode(['ok' => false]); exit; }

    $temp = bin2hex(random_bytes(5));
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE users SET password_hash = ?, must_change = TRUE WHERE user_id = ?")
        ->execute([password_hash($temp, PASSWORD_DEFAULT), $req['user_id']]);
    $pdo->prepare("UPDATE password_requests SET status = 'done', handled_by = ?, handled_at = NOW() WHERE request_id = ?")
        ->execute([$_SESSION['user_id'], $req['request_id']]);
    $pdo->commit();
    echo json_encode(['ok' => true, 'username' => $req['username'], 'temp' => $temp]);
    exit;
}

echo json_encode(['ok' => false]);