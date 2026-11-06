
<?php
require 'db.php';
require_login();
close_stale_sessions($pdo);

$message = '';
$today = date('Y-m-d');

// Get the logged-in user's section
$stmt = $pdo->prepare("SELECT section_id FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);

$my_section = $stmt->fetchColumn();

$my_section = $my_section === false || $my_section === null
    ? null
    : (int)$my_section;

// Only non-admin accounts with a section can rate
$can_rate = !is_admin() && $my_section !== null;


// Finds sessions that timed in today,
// plus the class that used the same room right before them.
function rateable(
    PDO $pdo,
    string $date,
    ?int $my_usage = null,
    ?int $section = null
): array {

    $sql = "
        SELECT
            r2.usage_id AS my_usage,
            sc2.section_id AS my_section_id,
            s2.section_name AS my_section,
            rm.room_number,
            rm.building,
            p.usage_id AS prev_usage,
            s1.section_name AS prev_section,
            p.time_out,
            p.auto_closed,
            rt.score

        FROM room_usage r2

        JOIN schedules sc2
            ON sc2.schedule_id = r2.schedule_id

        JOIN sections s2
            ON s2.section_id = sc2.section_id

        JOIN rooms rm
            ON rm.room_id = sc2.room_id

        JOIN LATERAL (
            SELECT
                pu.usage_id,
                pu.time_out,
                pu.auto_closed,
                psc.section_id

            FROM room_usage pu

            JOIN schedules psc
                ON psc.schedule_id = pu.schedule_id

            WHERE psc.room_id = sc2.room_id
              AND pu.usage_date = r2.usage_date
              AND pu.time_in < r2.time_in

            ORDER BY pu.time_in DESC
            LIMIT 1

        ) p ON TRUE

        JOIN sections s1
            ON s1.section_id = p.section_id

        LEFT JOIN ratings rt
            ON rt.usage_id = p.usage_id

        WHERE r2.usage_date = ?
          AND p.time_out IS NOT NULL
          AND p.section_id <> sc2.section_id
          AND p.time_out <= r2.time_in
          AND r2.time_in - p.time_out <= interval '2 hours'
    ";

    $params = [$date];

    if ($my_usage !== null) {
        $sql .= " AND r2.usage_id = ?";
        $params[] = $my_usage;
    }

    // Filter by the logged-in user's section
    if ($section !== null) {
        $sql .= " AND sc2.section_id = ?";
        $params[] = $section;
    }

    $sql .= " ORDER BY rm.room_number, r2.time_in";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// Handle rating submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();

    // Prevent admins and accounts without sections from rating
    if (!$can_rate) {
        http_response_code(403);
        exit('Only a section account can rate.');
    }

    $my_usage = (int)($_POST['my_usage'] ?? 0);
    $prev_usage = (int)($_POST['prev_usage'] ?? 0);
    $score = (int)($_POST['score'] ?? 0);

    $comment = trim($_POST['comment'] ?? '');

    if ($comment === '') {
        $comment = null;
    }

    // Verify that the session is eligible for rating
    $match = rateable(
        $pdo,
        $today,
        $my_usage,
        $my_section
    )[0] ?? null;

    if (!$match || (int)$match['prev_usage'] !== $prev_usage) {

        $message = 'That session cannot be rated.';

    } elseif ($match['score'] !== null) {

        $message = 'That session was already rated.';

    } elseif ($score < 1 || $score > 5) {

        $message = 'Score must be 1 to 5.';

    } elseif ($comment !== null && mb_strlen($comment) > 500) {

        $message = 'Comment is too long (max 500 characters).';

    } else {

        try {

            $stmt = $pdo->prepare("
                INSERT INTO ratings
                    (usage_id, rated_by_section, score, comment)
                VALUES (?, ?, ?, ?)
            ");

            $stmt->execute([
                $prev_usage,
                $match['my_section_id'],
                $score,
                $comment
            ]);

            header('Location: rate.php');
            exit;

        } catch (PDOException $e) {

            if ($e->getCode() === '23505') {

                $message = 'That session was already rated.';

            } else {

                throw $e;
            }
        }
    }
}


// Get rateable sessions
// Admins see everything; section accounts see their own section.
$rows = (!is_admin() && $my_section === null)
    ? []
    : rateable($pdo, $today, null, $my_section);

?>

<!DOCTYPE html>
<html>
<head>
    <title>Rate Rooms</title>
</head>

<body>

    <a href="dashboard.php">&larr; Back to dashboard</a>

    <h2>Rate Room Cleanliness - <?= date('l, F j, Y') ?></h2>

    <p>Rate how clean the previous class left the room.</p>

    <?php if ($message): ?>

        <p style="color:red">
            <?= htmlspecialchars($message) ?>
        </p>

    <?php endif; ?>


    <?php if (!$rows): ?>

        <p>
            Nothing to rate yet. A class must time in after another
            class has finished in the same room.
        </p>

    <?php else: ?>

        <table border="1" cellpadding="6">

            <tr>
                <th>Room</th>
                <th>You (rater)</th>
                <th>Previous class</th>
                <th>Left at</th>
                <th>Rating</th>
            </tr>

            <?php foreach ($rows as $r): ?>

                <tr>

                    <td>
                        <?= htmlspecialchars(
                            $r['room_number'] . ' ' . ($r['building'] ?? '')
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($r['my_section']) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($r['prev_section']) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(substr($r['time_out'], 11, 5)) ?>

                        <?= $r['auto_closed'] ? ' (auto)' : '' ?>
                    </td>

                    <td>

                        <?php if ($r['score'] !== null): ?>

                            Rated <?= (int)$r['score'] ?>/5

                        <?php elseif (!$can_rate): ?>

                            View only

                        <?php else: ?>

                            <form method="post">

                                <?= csrf_field() ?>

                                <input
                                    type="hidden"
                                    name="my_usage"
                                    value="<?= (int)$r['my_usage'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="prev_usage"
                                    value="<?= (int)$r['prev_usage'] ?>"
                                >

                                <select name="score" required>

                                    <option value="">Score</option>
                                    <option value="5">5 - Spotless</option>
                                    <option value="4">4 - Clean</option>
                                    <option value="3">3 - Okay</option>
                                    <option value="2">2 - Dirty</option>
                                    <option value="1">1 - Very dirty</option>

                                </select>

                                <input
                                    name="comment"
                                    placeholder="Comment (optional)"
                                    maxlength="500"
                                >

                                <button type="submit">
                                    Submit
                                </button>

                            </form>

                        <?php endif; ?>

                    </td>

                </tr>

            <?php endforeach; ?>

        </table>

    <?php endif; ?>

</body>
</html>
