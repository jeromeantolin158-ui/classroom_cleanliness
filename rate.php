
<?php
require 'lib.php';
$my_section = require_student();
close_stale_sessions($pdo);

$message = '';
$today = date('Y-m-d');

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
            r2.time_in AS my_time_in,
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
    if ($my_section === null) {
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
$rows = $my_section === null
    ? []
    : rateable($pdo, $today, null, $my_section);

?>

<?php
$title = 'Rating';
$recent = null;
if ($my_section) {
    $stmt = $pdo->prepare("
        SELECT u.usage_date, rm.room_number, s.section_name, rt.score
        FROM ratings rt
        JOIN room_usage u ON u.usage_id = rt.usage_id
        JOIN schedules sc ON sc.schedule_id = u.schedule_id
        JOIN rooms rm ON rm.room_id = sc.room_id
        JOIN sections s ON s.section_id = sc.section_id
        WHERE rt.rated_by_section = ? ORDER BY rt.rated_at DESC LIMIT 1");
    $stmt->execute([$my_section]);
    $recent = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
include 'header.php';
?>

<h1 class="s-h1">Rate the room</h1>
<p class="s-sub">Give fair feedback on how the previous section left your shared space.</p>
<?php if ($message) echo "<p class='s-alert'>" . h($message) . "</p>"; ?>

<?php if (!$rows): ?>
    <div class="s-card">Nothing to rate yet. Time in after another section has left the room, and it will appear here.</div>
<?php endif; ?>

<?php foreach ($rows as $r): $k = (int)$r['my_usage']; ?>
<?php if ($r['score'] === null): ?>
<p class="s-alert ok">✓ Timed in at <?= h(substr($r['my_time_in'], 11, 5)) ?>. You can now rate Room <?= h($r['room_number']) ?>.</p>
<?php endif; ?>
<div class="s-ratewrap">
    <section class="s-card" style="margin:0">
        <h2 style="margin:0">Room <?= h($r['room_number']) ?></h2>
        <p class="s-muted">You're rating <?= h($r['prev_section']) ?>, not your own section.</p>
        <?php if ($r['score'] !== null): ?>
            <div class="s-stars"><?= str_repeat('★', (int)$r['score']) . str_repeat('☆', 5 - (int)$r['score']) ?></div>
            <p>Rated <?= (int)$r['score'] ?>/5. Thank you!</p>
        <?php else: ?>
        <form method="post" class="s-rform">
            <?= csrf_field() ?>
            <input type="hidden" name="my_usage" value="<?= $k ?>">
            <input type="hidden" name="prev_usage" value="<?= (int)$r['prev_usage'] ?>">
            <h3 style="margin:14px 0 0;font-size:1rem;text-transform:none;letter-spacing:0">How was the room when you arrived?</h3>
            <div class="s-muted">Choose 1 to 5 stars.</div>
            <div class="s-starpick">
                <?php for ($v = 1; $v <= 5; $v++): ?>
                    <input type="radio" name="score" id="s<?= $k ?>_<?= $v ?>" value="<?= $v ?>" required>
                    <label for="s<?= $k ?>_<?= $v ?>" data-v="<?= $v ?>">★</label>
                <?php endfor; ?>
                <span class="s-starlabel"></span>
            </div>
            <div class="s-cnt"><span>1 · Very dirty</span><span>5 · Spotless</span></div>
            <label style="font-weight:700;font-size:.85rem">Comment <span class="s-muted" style="font-weight:400">Optional</span></label>
            <textarea name="comment" maxlength="500" placeholder="Be specific, respectful, and focused on the room."></textarea>
            <div class="s-cnt"><span>Be specific, respectful, and focused on the room.</span><span class="cnt">0 / 500</span></div>
            <div class="s-btns">
                <button type="submit" class="s-btn">Submit rating →</button>
                <a class="s-btn soft" href="usage.php">Back to room usage</a>
            </div>
        </form>
        <?php endif; ?>
    </section>
    <aside>
        <div class="s-sidecard">
            <h3>The room before your class</h3>
            <div class="s-k">Previous section</div>
            <b><?= h($r['prev_section']) ?></b>
            <div class="s-muted">Left at <?= h(substr($r['time_out'], 11, 5)) ?><?= $r['auto_closed'] ? ' (auto)' : '' ?> · Room <?= h($r['room_number']) ?></div>
            <hr style="border:none;border-top:1px solid #e2ebe5;margin:14px 0">
            <div class="s-k">Your section</div>
            <b><?= h($r['my_section']) ?></b>
            <div class="s-muted">Timed in <?= h(substr($r['my_time_in'], 11, 5)) ?></div>
        </div>
        <div class="s-sidecard tip">
            <h3>A little feedback goes a long way.</h3>
            Look at the room as you found it:
            <ul><li>Clean floors and desks</li><li>Properly arranged chairs</li><li>Clean board and empty bins</li></ul>
        </div>
    </aside>
</div>
<?php endforeach; ?>

<?php if ($recent): ?>
<section class="s-card" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <b>Your recent feedback</b>
    <span class="s-muted"><?= h($recent['usage_date']) ?> · Room <?= h($recent['room_number']) ?> · <?= h($recent['section_name']) ?> · <?= (int)$recent['score'] ?> / 5</span>
</section>
<?php endif; ?>

<?php include 'footer.php'; ?>