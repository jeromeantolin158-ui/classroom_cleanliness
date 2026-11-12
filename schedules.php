
<?php
require 'db.php';
require_login();
if (!is_admin()) { http_response_code(403); exit('Admins only.'); }

$is_admin = is_admin();
$message = '';
$action = '';

$days = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (!$is_admin) {
        http_response_code(403);
        exit('Admins only.');
    }

    $action = $_POST['action'] ?? '';
    $room_id = (int)($_POST['room_id'] ?? 0);
    $section_id = (int)($_POST['section_id'] ?? 0);
    $day = (int)($_POST['day_of_week'] ?? 0);
    $start = $_POST['start_time'] ?? '';
    $end = $_POST['end_time'] ?? '';

    $subject = trim($_POST['subject'] ?? '');

    try {
        if ($action === 'add' || $action === 'update') {

            if ($subject === '' || !$room_id || !$section_id || !isset($days[$day]) || $start === '' || $end === '') {
                $message = 'All fields are required.';
            } elseif (mb_strlen($subject) > 100) {
                $message = 'Subject is too long (max 100 characters).';

            } elseif ($end <= $start) {
                $message = 'End time must be later than start time.';


            } elseif ($action === 'add') {

                $stmt = $pdo->prepare("
                    INSERT INTO schedules
                    (room_id, section_id, day_of_week, start_time, end_time, subject, valid_from, valid_to)
                    VALUES (?, ?, ?, ?, ?, ?, CURRENT_DATE, '9999-12-31')
                ");

                $stmt->execute([
                    $room_id,
                    $section_id,
                    $day,
                    $start,
                    $end,
                    $subject
                ]);

                header('Location: schedules.php');
                exit;

            } else {

                $stmt = $pdo->prepare("
                    UPDATE schedules
                    SET room_id = ?,
                        section_id = ?,
                        day_of_week = ?,
                        start_time = ?,
                        end_time = ?,
                        subject = ?
                    WHERE schedule_id = ?
                ");

                $stmt->execute([
                    $room_id,
                    $section_id,
                    $day,
                    $start,
                    $end,
                    $subject,
                    (int)$_POST['schedule_id']
                ]);

                header('Location: schedules.php');
                exit;
            }

        } elseif ($action === 'delete') {

            $stmt = $pdo->prepare("
                DELETE FROM schedules
                WHERE schedule_id = ?
            ");

            $stmt->execute([(int)$_POST['schedule_id']]);

            header('Location: schedules.php');
            exit;
        }

    } catch (PDOException $e) {

        $code = $e->getCode();

        if ($code === '23P01') {

            if (strpos($e->getMessage(), 'no_room_overlap') !== false) {
                $message = 'That room is already booked at an overlapping time on that day.';
            } else {
                $message = 'That section already has a class at an overlapping time on that day.';
            }

        } elseif ($code === '23503') {

            $message = 'Cannot delete: this schedule already has usage records.';

        } elseif ($code === '23514') {

            $message = 'Invalid day or time.';

        } else {
            throw $e;
        }
    }
}

// Which schedule is being edited?
$edit = null;

$edit_id = (int)($_GET['edit'] ?? (
    $action === 'update' ? ($_POST['schedule_id'] ?? 0) : 0
));

if ($is_admin && $edit_id > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM schedules
        WHERE schedule_id = ?
    ");

    $stmt->execute([$edit_id]);
    $edit = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get rooms and sections
$rooms = $pdo->query("
    SELECT room_id, room_number, building
    FROM rooms
    ORDER BY room_number
")->fetchAll(PDO::FETCH_ASSOC);

$sections = $pdo->query("
    SELECT section_id, section_name
    FROM sections
    ORDER BY section_name
")->fetchAll(PDO::FETCH_ASSOC);

// Get schedules, including validity dates + today's status
$today = date('Y-m-d'); $dow = (int)date('N'); $nowT = date('H:i:s');
$stmt = $pdo->prepare("
    SELECT sc.schedule_id, sc.day_of_week, sc.start_time, sc.end_time, sc.valid_from, sc.valid_to, sc.subject,
           r.room_number, r.building, s.section_name, u.usage_id, u.time_out
    FROM schedules sc
    JOIN rooms r ON r.room_id = sc.room_id
    JOIN sections s ON s.section_id = sc.section_id
    LEFT JOIN room_usage u ON u.schedule_id = sc.schedule_id AND u.usage_date = ?
    ORDER BY sc.day_of_week, sc.start_time, r.room_number");
$stmt->execute([$today]);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

$title = 'Schedule'; include 'header.php'; ?>
<div class="a-head">
    <div><h1 class="s-h1">Schedule</h1><p class="s-sub">Organize classes and prevent room conflicts.</p></div>
    <span class="s-date"><?= date('D, F j, Y') ?></span>
</div>

<?php if ($message) echo "<p class='s-alert'>" . h($message) . "</p>"; ?>

<div class="a-bar">
    <input type="search" data-search placeholder="Search schedules...">
    <select data-filter>
        <option value="">All days</option>
        <?php foreach ($days as $n => $label): ?><option><?= $label ?></option><?php endforeach; ?>
    </select>
    <button type="button" class="s-btn" data-open="formModal">+ Add schedule</button>
</div>

<section class="a-card">
    <h3 id="a-count"><?= count($schedules) ?> classes</h3>
    <p class="s-muted">Status is shown for today's classes only.</p>
    <div class="table-wrap"><table class="a-table" data-paged="10" data-noun="classes">
        <thead><tr><th>Day</th><th>Time</th><th>Subject</th><th>Section</th><th>Room</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($schedules as $sc):
            $st = '-'; $c = 'mute';
            $live = (int)$sc['day_of_week'] === $dow && $today >= $sc['valid_from'] && $today <= $sc['valid_to'];
            if ($live) {
                if ($sc['usage_id'] && $sc['time_out']) { $st = 'Done'; $c = 'go'; }
                elseif ($sc['usage_id']) { $st = 'In use'; $c = 'go'; }
                elseif ($nowT > $sc['start_time']) { $st = 'Not timed in'; $c = 'warn'; }
                else { $st = 'Upcoming'; $c = 'mute'; }
            }
            $room = $sc['room_number'] . ' ' . ($sc['building'] ?? ''); ?>
        <tr class="r" data-q="<?= h(strtolower($days[$sc['day_of_week']] . ' ' . ($sc['subject'] ?? '') . ' ' . $sc['section_name'] . ' ' . $room)) ?>" data-f="<?= h($days[$sc['day_of_week']]) ?>">
            <td class="nm"><?= $days[$sc['day_of_week']] ?></td>
            <td><?= substr($sc['start_time'], 0, 5) ?> - <?= substr($sc['end_time'], 0, 5) ?></td>
            <td><?= h($sc['subject'] ?? '—') ?></td>
            <td><?= h($sc['section_name']) ?></td>
            <td><?= h($room) ?></td>
            <td><span class="a-st <?= $c ?>"><?= $st ?></span></td>
            <td>
                <a class="a-btn" href="schedules.php?edit=<?= (int)$sc['schedule_id'] ?>">Edit</a>
                <form method="post" data-confirm data-title="Delete schedule" data-heading="Delete <?= h(($sc['subject'] ?? '') !== '' && $sc['subject'] !== null ? $sc['subject'] : $sc['section_name'] . ' on ' . $days[$sc['day_of_week']]) ?>?" data-text="This record will be removed. A schedule that already has usage records can't be deleted.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="schedule_id" value="<?= (int)$sc['schedule_id'] ?>">
                    <button type="submit" class="a-btn del">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <tr class="none" hidden><td colspan="7">No classes match.</td></tr>
        </tbody>
    </table></div>
    <div class="a-pager"><span id="a-showing"></span><div><button type="button" id="a-prev">Previous</button><button type="button" id="a-next">Next</button></div></div>
</section>

<section class="s-note"><b>Keep room assignments conflict-free</b><small>Choose a room and time that do not overlap another class.</small></section>

<?php $fv = $edit ?? ($message && $action === 'add' ? ['subject' => $subject, 'section_id' => $section_id, 'room_id' => $room_id, 'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end] : []);
      $openForm = $edit || ($message && $action === 'add'); ?>
<dialog id="formModal" class="a-modal fig" <?= $openForm ? 'data-autoopen' : '' ?>>
    <form method="post">
        <?= csrf_field() ?>
        <h3><?= $edit ? 'Edit schedule' : 'Add schedule' ?></h3>
        <p class="a-sub"><?= $edit ? 'Update the selected record below.' : 'Create a new campus record.' ?></p>
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'add' ?>">
        <?php if ($edit): ?><input type="hidden" name="schedule_id" value="<?= (int)$edit['schedule_id'] ?>"><?php endif; ?>
        <label>Subject</label><input name="subject" placeholder="Enter subject" maxlength="100" required value="<?= h($fv['subject'] ?? '') ?>">
        <label>Section</label>
        <select name="section_id" required><option value="">Enter section</option>
        <?php foreach ($sections as $sx): ?><option value="<?= $sx['section_id'] ?>" <?= ($fv['section_id'] ?? 0) == $sx['section_id'] ? 'selected' : '' ?>><?= h($sx['section_name']) ?></option><?php endforeach; ?>
        </select>
        <label>Room</label>
        <select name="room_id" required><option value="">Enter room</option>
        <?php foreach ($rooms as $r): ?><option value="<?= $r['room_id'] ?>" <?= ($fv['room_id'] ?? 0) == $r['room_id'] ? 'selected' : '' ?>>Room <?= h($r['room_number'] . ' ' . ($r['building'] ?? '')) ?></option><?php endforeach; ?>
        </select>
        <label>Day</label>
        <select name="day_of_week" required><option value="">Enter day</option>
        <?php foreach ($days as $n => $label): ?><option value="<?= $n ?>" <?= ($fv['day_of_week'] ?? 0) == $n ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
        </select>
        <label>Time</label>
        <div class="a-two">
            <input type="time" name="start_time" required value="<?= !empty($fv['start_time']) ? h(substr($fv['start_time'], 0, 5)) : '' ?>">
            <input type="time" name="end_time" required value="<?= !empty($fv['end_time']) ? h(substr($fv['end_time'], 0, 5)) : '' ?>">
        </div>
        <p class="a-req">All fields are required.</p>
        <div class="a-acts"><button type="button" class="s-btn soft" data-close>Cancel</button><button type="submit" class="s-btn"><?= $edit ? 'Save changes' : 'Add schedule' ?></button></div>
    </form>
</dialog>
<?php include 'footer.php'; ?>
