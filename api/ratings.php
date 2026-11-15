<?php
// GET /api/ratings.php?week=2026-W46&building=Annex&max_score=2&limit=50&offset=0
// Admin-only list of ratings for one week, newest first, with the week's average and total.
require __DIR__ . '/_bootstrap.php';
api_require_admin_get();

[$week, $from, $to] = api_week();
$limit = api_int('limit', 50, 1, 200);
$offset = api_int('offset', 0, 0, 1000000);
$max = api_int('max_score', 5, 1, 5);

$where = "u.usage_date BETWEEN ? AND ? AND rt.score <= ?";
$params = [$from->format('Y-m-d'), $to->format('Y-m-d'), $max];
if (($_GET['building'] ?? '') !== '') { $where .= " AND rm.building = ?"; $params[] = $_GET['building']; }

$from_sql = "FROM ratings rt
    JOIN room_usage u ON u.usage_id = rt.usage_id
    JOIN schedules sc ON sc.schedule_id = u.schedule_id
    JOIN rooms rm ON rm.room_id = sc.room_id
    JOIN sections s ON s.section_id = sc.section_id
    JOIN sections rb ON rb.section_id = rt.rated_by_section
    WHERE $where";

$stmt = $pdo->prepare("SELECT COUNT(*) AS n, AVG(rt.score) AS avg $from_sql");
$stmt->execute($params);
$sum = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT rt.rating_id, u.usage_date, rt.rated_at, rt.score, rt.comment,
           rm.room_id, rm.room_number, rm.building, s.section_name, rb.section_name AS rated_by
    $from_sql ORDER BY rt.rated_at DESC, rt.rating_id DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);

$ratings = array_map(fn($r) => [
    'id' => (int)$r['rating_id'],
    'date' => $r['usage_date'],
    'rated_at' => $r['rated_at'],
    'score' => (int)$r['score'],
    'comment' => $r['comment'],
    'room' => ['id' => (int)$r['room_id'], 'number' => $r['room_number'], 'building' => $r['building']],
    'rated_section' => $r['section_name'],
    'rated_by' => $r['rated_by'],
], $stmt->fetchAll(PDO::FETCH_ASSOC));

api_ok([
    'week' => $week, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'),
    'total' => (int)$sum['n'],
    'average' => $sum['avg'] === null ? null : round((float)$sum['avg'], 1),
    'limit' => $limit, 'offset' => $offset,
    'ratings' => $ratings,
]);
