<?php
// "Missed time-in" reminders. Emails a section's account when its class started a while ago, is still running,
// and nobody has timed in.  Run it from the Rooms page (admin button) or on a schedule:  php send_reminders.php
require 'lib.php';
require_once 'mailer.php';

function send_missed_timein_reminders(PDO $pdo): array {
    $c = mail_config();
    $today = date('Y-m-d'); $now = date('H:i:s');
    $stmt = $pdo->prepare("
        SELECT sc.schedule_id, sc.start_time, sc.end_time, sc.subject, s.section_name, r.room_number, r.building,
               u.user_id, u.full_name, u.email
        FROM schedules sc
        JOIN sections s ON s.section_id = sc.section_id
        JOIN rooms r ON r.room_id = sc.room_id
        JOIN users u ON u.section_id = sc.section_id AND u.role = 'user' AND u.active
        WHERE sc.day_of_week = ? AND ?::date BETWEEN sc.valid_from AND sc.valid_to
          AND sc.start_time + make_interval(mins => ?::int) <= ?::time
          AND sc.end_time > ?::time
          AND NOT EXISTS (SELECT 1 FROM room_usage x WHERE x.schedule_id = sc.schedule_id AND x.usage_date = ?)
          AND NOT EXISTS (SELECT 1 FROM email_log l WHERE l.kind = 'missed_timein' AND l.schedule_id = sc.schedule_id
                          AND l.usage_date = ? AND l.to_email = u.email AND l.status <> 'failed')
        ORDER BY sc.start_time, r.room_number");
    $stmt->execute([(int)date('N'), $today, (int)$c['grace_minutes'], $now, $now, $today, $today]);

    $out = ['dry' => !$c['enabled'], 'sent' => 0, 'failed' => 0, 'no_email' => 0, 'errors' => []];
    $claim = $pdo->prepare("INSERT INTO email_log (kind, schedule_id, usage_date, user_id, to_email, status)
                            VALUES ('missed_timein', ?, ?, ?, ?, 'sending') ON CONFLICT DO NOTHING RETURNING log_id");
    $done = $pdo->prepare("UPDATE email_log SET status = ?, error = ?, sent_at = NOW() WHERE log_id = ?");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!$row['email']) { $out['no_email']++; continue; }
        if ($out['dry']) { $out['sent']++; continue; }          // dry run: count only, send and log nothing

        $claim->execute([$row['schedule_id'], $today, $row['user_id'], $row['email']]);
        $logId = $claim->fetchColumn();
        if (!$logId) continue;                                   // another run already took this one

        $room = 'Room ' . $row['room_number'] . ($row['building'] ? ' (' . $row['building'] . ')' : '');
        $when = date('g:i A', strtotime($row['start_time']));
        $link = $c['base_url'] ? rtrim($c['base_url'], '/') . '/usage.php' : '';
        $text = "Hi {$row['full_name']},\n\n{$row['section_name']} has not timed in to $room yet. The class started at $when.\n"
              . "Please time in so the next section can rate the room." . ($link ? "\n\n$link" : '') . "\n\nSpotLess";
        $html = '<p>Hi ' . h($row['full_name']) . ',</p><p><b>' . h($row['section_name']) . '</b> has not timed in to <b>' . h($room)
              . '</b> yet. The class started at ' . h($when) . '.</p><p>Please time in so the next section can rate the room.</p>'
              . ($link ? '<p><a href="' . h($link) . '">Open SpotLess</a></p>' : '') . '<p style="color:#64756a">SpotLess</p>';

        [$ok, $err] = send_mail($row['email'], "Reminder: time in to $room", $html, $text);
        $done->execute([$ok ? 'sent' : 'failed', $ok ? null : $err, $logId]);
        if ($ok) $out['sent']++; else { $out['failed']++; $out['errors'][] = $err; }
    }
    return $out;
}

function reminder_summary(array $r): string {
    if ($r['dry']) {
        return $r['sent'] . ' reminder(s) would be sent. Email is off, so nothing was sent (set enabled to true in mail.local.php).'
             . ($r['no_email'] ? " {$r['no_email']} account(s) have no email." : '');
    }
    return $r['sent'] . ' reminder(s) sent.' . ($r['failed'] ? " {$r['failed']} failed: " . implode('; ', array_unique($r['errors'])) : '')
         . ($r['no_email'] ? " {$r['no_email']} account(s) have no email." : '');
}

if (PHP_SAPI === 'cli') {
    echo reminder_summary(send_missed_timein_reminders($pdo)), PHP_EOL;
} elseif (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $_SESSION['flash_ok'] = reminder_summary(send_missed_timein_reminders($pdo));
    }
    header('Location: rooms.php');
    exit;
}
