<?php
// GET /api/room_ranking.php?week=2026-W46&building=Annex
// Admin-only. Rooms ranked by average rating for the week (cleanest first; ties go to the room with more ratings).
require __DIR__ . '/_bootstrap.php';
api_require_admin_get();

[$week, $from, $to] = api_week();
$where = "u.usage_date BETWEEN ? AND ?";
$params = [$from->format('Y-m-d'), $to->format('Y-m-d')];
if (($_GET['building'] ?? '') !== '') { $where .= " AND rm.building = ?"; $params[] = $_GET['building']; }

$stmt = $pdo->prepare("SELECT rm.room_id, rm.room_number, rm.building, COUNT(*) AS n, AVG(rt.score) AS avg
    FROM ratings rt
    JOIN room_usage u ON u.usage_id = rt.usage_id
    JOIN schedules sc ON sc.schedule_id = u.schedule_id
    JOIN rooms rm ON rm.room_id = sc.room_id
    WHERE $where
    GROUP BY rm.room_id, rm.room_number, rm.building
    ORDER BY avg DESC, n DESC, rm.room_number");
$stmt->execute($params);

$rank = 0;
$rooms = array_map(function ($r) use (&$rank) {
    return ['rank' => ++$rank, 'room' => ['id' => (int)$r['room_id'], 'number' => $r['room_number'], 'building' => $r['building']],
            'ratings' => (int)$r['n'], 'average' => round((float)$r['avg'], 1)];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

api_ok(['week' => $week, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'rooms' => $rooms]);
