<?php
require 'db.php';
require_login();
if (!is_admin()) { http_response_code(403); exit('Admins only.'); }
$is_admin = is_admin();
$message = '';
$action = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$is_admin) {
        http_response_code(403);
        exit('Admins only.');
    }

    $action = $_POST['action'] ?? '';
    $room_number = trim($_POST['room_number'] ?? '');
    $building = trim($_POST['building'] ?? '');
    if ($building === '') $building = null;
    $floor = trim($_POST['floor'] ?? '');
    if ($floor === '') $floor = null;

    try {
        if ($action === 'add' || $action === 'update') {
            if ($room_number === '' || $building === null || $floor === null) {
                $message = 'All fields are required.';
            } elseif ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO rooms (room_number, building, floor) VALUES (?, ?, ?)");
                $stmt->execute([$room_number, $building, $floor]);
                header('Location: rooms.php');
                exit;
            } else {
                $room_id = (int)($_POST['room_id'] ?? 0);
                $sid = (int)($_POST['schedule_id'] ?? 0);
                $ti = trim($_POST['time_in'] ?? '');
                $to = trim($_POST['time_out'] ?? '');

                if (($ti !== '' || $to !== '') && !$sid) {
                    $message = 'No class schedule is available for this room today.';
                } elseif ($ti !== '' && !preg_match('/^\d{2}:\d{2}$/', $ti)) {
                    $message = 'Enter a valid time-in.';
                } elseif ($to !== '' && !preg_match('/^\d{2}:\d{2}$/', $to)) {
                    $message = 'Enter a valid time-out.';
                } elseif ($to !== '' && ($ti === '' || $to <= $ti)) {
                    $message = 'Time-out must be later than time-in.';
                } else {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("UPDATE rooms SET room_number = ?, building = ?, floor = ? WHERE room_id = ?");
                    $stmt->execute([$room_number, $building, $floor, $room_id]);

                    // Time fields are optional so room details can be saved without changing today's usage.
                    if ($sid && ($ti !== '' || $to !== '')) {
                        $d = date('Y-m-d');
                        $chk = $pdo->prepare("SELECT 1 FROM schedules WHERE schedule_id = ? AND room_id = ? AND day_of_week = ? AND ?::date BETWEEN valid_from AND valid_to");
                        $chk->execute([$sid, $room_id, (int)date('N'), $d]);
                        if (!$chk->fetchColumn()) {
                            throw new DomainException('That class is not scheduled in this room today.');
                        }
                        $tin = "$d $ti:00";
                        $tout = $to === '' ? null : "$d $to:00";
                        $ex = $pdo->prepare("SELECT usage_id FROM room_usage WHERE schedule_id = ? AND usage_date = ?");
                        $ex->execute([$sid, $d]);
                        $uid = $ex->fetchColumn();
                        if ($uid) {
                            $pdo->prepare("UPDATE room_usage SET time_in = ?, time_out = ?, auto_closed = FALSE WHERE usage_id = ?")->execute([$tin, $tout, $uid]);
                        } else {
                            $pdo->prepare("INSERT INTO room_usage (schedule_id, usage_date, time_in, time_out, logged_by) VALUES (?, ?, ?, ?, ?)")->execute([$sid, $d, $tin, $tout, $_SESSION['user_id']]);
                        }
                    }
                    $pdo->commit();
                    header('Location: rooms.php');
                    exit;
                }
            }
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM rooms WHERE room_id = ?");
            $stmt->execute([(int)$_POST['room_id']]);
            header('Location: rooms.php');
            exit;
        }
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = $e->getMessage();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23505') {
            $message = 'That room already exists.';
        } elseif ($e->getCode() === '23514') {
            $message = 'Time-out must be later than time-in.';
        } elseif ($e->getCode() === '23503') {
            $message = 'Cannot delete: this room is used in a class schedule.';
        } else {
            throw $e;
        }
    }
}

// Which room is being edited?
$edit_room = null;
$edit_id = (int)($_GET['edit'] ?? ($action === 'update' ? $_POST['room_id'] : 0));
if ($is_admin && $edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE room_id = ?");
    $stmt->execute([$edit_id]);
    $edit_room = $stmt->fetch(PDO::FETCH_ASSOC);
}

$today = date('Y-m-d'); $dow = (int)date('N'); $nowT = date('H:i:s');
$stmt = $pdo->prepare("
    SELECT rm.room_id, rm.room_number, rm.building, rm.floor,
           cur.schedule_id, cur.start_time, cur.end_time, cur.section_name,
           cur.usage_id, cur.time_in, cur.time_out
    FROM rooms rm
    LEFT JOIN LATERAL (
        SELECT sc.schedule_id, sc.start_time, sc.end_time, s.section_name, u.usage_id, u.time_in, u.time_out
        FROM schedules sc
        JOIN sections s ON s.section_id = sc.section_id
        LEFT JOIN room_usage u ON u.schedule_id = sc.schedule_id AND u.usage_date = ?
        WHERE sc.room_id = rm.room_id AND sc.day_of_week = ? AND ?::date BETWEEN sc.valid_from AND sc.valid_to
        ORDER BY (sc.start_time <= ?::time AND sc.end_time > ?::time) DESC,
                 (sc.end_time > ?::time) DESC,
                 CASE WHEN sc.end_time > ?::time THEN sc.start_time END ASC,
                 sc.end_time DESC
        LIMIT 1
    ) cur ON TRUE
    ORDER BY rm.room_number, rm.building");
$stmt->execute([$today, $dow, $today, $nowT, $nowT, $nowT, $nowT]);
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$edit_schedule = null;
if ($edit_room) {
    foreach ($rooms as $room_row) {
        if ((int)$room_row['room_id'] === (int)$edit_room['room_id']) { $edit_schedule = $room_row; break; }
    }
}

$nIn = $nLate = 0;
foreach ($rooms as &$r) {
    $running = $r['schedule_id'] && $r['start_time'] <= $nowT && $r['end_time'] > $nowT;
    if ($r['time_in'] && !$r['time_out']) { $r['st'] = 'In use'; $r['c'] = 'go'; $nIn++; }
    elseif ($running && !$r['time_in']) { $r['st'] = 'Late time-in'; $r['c'] = 'warn'; $nLate++; }
    else { $r['st'] = 'Available'; $r['c'] = 'mute'; }
    $r['active'] = $r['st'] !== 'Available';
}
unset($r);
$nFree = count($rooms) - $nIn - $nLate;
$buildings = array_values(array_unique(array_filter(array_map(fn($r) => $r['building'] ?? '', $rooms))));
sort($buildings);
$t12 = fn($t) => $t ? date('g:i A', strtotime($t)) : '';
$ts = fn($t) => $t ? date('g:i A', strtotime($t)) : '—';

// Values shown in the add/edit window
$fv = $edit_room ?? [];
if ($message && in_array($action, ['add', 'update'], true)) {
    $fv = ['room_number' => $room_number, 'building' => $building, 'floor' => $floor];
}
$postedTimeIn = $message && $action === 'update' ? ($_POST['time_in'] ?? '') : (($edit_schedule && $edit_schedule['time_in']) ? substr($edit_schedule['time_in'], 11, 5) : '');
$postedTimeOut = $message && $action === 'update' ? ($_POST['time_out'] ?? '') : (($edit_schedule && $edit_schedule['time_out']) ? substr($edit_schedule['time_out'], 11, 5) : '');
$openForm = $edit_room || ($message && in_array($action, ['add', 'update'], true));
$title = 'Rooms'; include 'header.php'; ?>
<div class="a-head">
    <div><h1 class="s-h1">Rooms</h1><p class="s-sub">Manage classrooms and see live occupancy and missing time-ins at a glance.</p></div>
    <span class="s-date"><?= date('D, F j, Y') ?></span>
</div>

<?php if ($message) echo "<p class='s-alert'>" . h($message) . "</p>"; ?>

<div class="s-kpis">
    <div class="s-kpi"><small>Classrooms</small><b><?= count($rooms) ?></b><em>Across <?= count($buildings) ?> building<?= count($buildings) === 1 ? '' : 's' ?></em></div>
    <div class="s-kpi"><small>In use</small><b><?= $nIn ?></b><em>Class has timed in</em></div>
    <div class="s-kpi"><small>Available</small><b><?= $nFree ?></b><em>No class currently in the room</em></div>
    <div class="s-kpi"><small>Late time-in</small><b style="color:<?= $nLate ? '#b45309' : 'inherit' ?>"><?= $nLate ?></b><em>Scheduled but not timed in</em></div>
</div>

<div class="a-bar">
    <input type="search" data-search placeholder="Search room...">
    <select data-filter data-attr="f">
        <option value="">All buildings</option>
        <?php foreach ($buildings as $b): ?><option><?= h($b) ?></option><?php endforeach; ?>
    </select>
    <select data-filter data-attr="s">
        <option value="">All statuses</option>
        <option>In use</option><option>Available</option><option>Late time-in</option>
    </select>
    <button type="button" class="s-btn" data-open="formModal">+ Add room</button>
</div>

<section class="a-card">
    <h3 id="a-count"><?= count($rooms) ?> classrooms</h3>
    <p class="s-muted">Current classroom status. Edit room details and today’s time record from one place.</p>
    <div class="table-wrap"><table class="a-table tight" data-paged="8" data-noun="classrooms">
        <thead><tr><th>Room</th><th>Building</th><th>Floor</th><th>Current section</th><th>Scheduled time</th><th>Time in</th><th>Time out</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rooms as $r):
            if ($r['schedule_id'] && $r['start_time'] > $nowT) $sched = 'Next: ' . $t12($r['start_time']);
            elseif ($r['schedule_id']) $sched = $t12($r['start_time']) . '–' . $t12($r['end_time']);
            else $sched = '—'; ?>
        <tr class="r" data-q="<?= h(strtolower($r['room_number'] . ' ' . ($r['building'] ?? '') . ' ' . ($r['floor'] ?? '') . ' ' . ($r['active'] ? $r['section_name'] : ''))) ?>" data-f="<?= h($r['building'] ?? '') ?>" data-s="<?= $r['st'] ?>">
            <td class="nm">Room <?= h($r['room_number']) ?></td>
            <td><?= h($r['building'] ?? '—') ?></td>
            <td><?= h($r['floor'] ?? '—') ?></td>
            <td><?= $r['active'] || ($r['time_in'] && $r['time_out']) ? h($r['section_name']) : '—' ?></td>
            <td><?= $sched ?></td>
            <td><?= $r['time_in'] ? $ts(substr($r['time_in'], 11, 8)) : '—' ?></td>
            <td><?= $r['time_out'] ? $ts(substr($r['time_out'], 11, 8)) : '—' ?></td>
            <td><span class="a-st <?= $r['c'] ?>"><?= $r['st'] ?></span></td>
            <td>
                <a class="a-btn" href="rooms.php?edit=<?= (int)$r['room_id'] ?>">Edit</a>
                <form method="post" data-confirm data-title="Delete room" data-heading="Delete Room <?= h($r['room_number']) ?>?" data-text="This record will be removed. A room that is used in a class schedule can't be deleted.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="room_id" value="<?= (int)$r['room_id'] ?>">
                    <button type="submit" class="a-btn del">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <tr class="none" hidden><td colspan="9">No rooms match.</td></tr>
        </tbody>
    </table></div>
    <div class="a-pager"><span id="a-showing"></span><div><button type="button" id="a-prev">Previous</button><button type="button" id="a-next">Next</button></div></div>
    <p class="s-muted" style="margin:12px 0 0">Updated today at <?= date('g:i A') ?></p>
</section>

<section class="s-note"><b>Keep your campus records up to date</b><small>Use Edit to update a record. Delete asks for confirmation before removing it.</small></section>

<dialog id="formModal" class="a-modal fig" <?= $openForm ? 'data-autoopen' : '' ?>>
    <form method="post">
        <?= csrf_field() ?>
        <h3><?= $edit_room ? 'Edit room' : 'Add room' ?></h3>
        <p class="a-sub"><?= $edit_room ? 'Update room information and today’s class times in one place.' : 'Create a new campus record.' ?></p>
        <input type="hidden" name="action" value="<?= $edit_room ? 'update' : 'add' ?>">
        <?php if ($edit_room): ?><input type="hidden" name="room_id" value="<?= (int)$edit_room['room_id'] ?>"><?php endif; ?>

        <h4 class="a-form-section">Room information</h4>
        <label>Room name</label><input name="room_number" placeholder="Enter room name" maxlength="20" required value="<?= h($fv['room_number'] ?? '') ?>">
        <label>Building</label><input name="building" placeholder="Enter building" maxlength="50" required value="<?= h($fv['building'] ?? '') ?>">
        <label>Floor</label><input name="floor" placeholder="Enter floor" maxlength="20" required value="<?= h($fv['floor'] ?? '') ?>">

        <?php if ($edit_room && $edit_schedule && $edit_schedule['schedule_id']): ?>
        <h4 class="a-form-section">Today’s class time</h4>
        <p class="a-req" style="margin-top:0"><?= h($edit_schedule['section_name']) ?> · <?= h($t12($edit_schedule['start_time'])) ?>–<?= h($t12($edit_schedule['end_time'])) ?></p>
        <input type="hidden" name="schedule_id" value="<?= (int)$edit_schedule['schedule_id'] ?>">
        <div class="a-two">
            <div><label>Time in</label><input type="time" name="time_in" value="<?= h($postedTimeIn) ?>"></div>
            <div><label>Time out</label><input type="time" name="time_out" value="<?= h($postedTimeOut) ?>"></div>
        </div>
        <p class="a-req">Leave both time fields empty to keep today’s time record unchanged. Leave time out empty if the class is still in the room.</p>
        <?php elseif ($edit_room): ?>
        <h4 class="a-form-section">Today’s class time</h4>
        <p class="a-req" style="margin-top:0">No class is currently scheduled for this room today.</p>
        <?php endif; ?>

        <p class="a-req">Room name, building, and floor are required.</p>
        <div class="a-acts"><button type="button" class="s-btn soft" data-close>Cancel</button><button type="submit" class="s-btn"><?= $edit_room ? 'Save changes' : 'Add room' ?></button></div>
    </form>
</dialog>
<?php include 'footer.php'; ?>
