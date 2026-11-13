<?php
// Shared helpers for the signed-in pages. Pages start with:  require 'lib.php';
// (it loads db.php for you, so db.php itself stays untouched.)
require_once __DIR__ . '/db.php';

/** Admin-only page. */
function require_admin(): void {
    require_login();
    if (!is_admin()) { http_response_code(403); exit('Admins only.'); }
}

/** Section-account page. Admins are sent to the dashboard. Returns the account's section id (null if it has none). */
function require_student(): ?int {
    global $pdo;
    require_login();
    if (is_admin()) { header('Location: dashboard.php'); exit; }
    $stmt = $pdo->prepare("SELECT section_id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $sid = $stmt->fetchColumn();
    return $sid === false || $sid === null ? null : (int)$sid;
}

/** Turns a "2026-W46" value (from <input type=week>) into [normalised value, Monday, Sunday]. Falls back to this week. */
function week_bounds(?string $week): array {
    if (!preg_match('/^(\d{4})-W(\d{2})$/', (string)$week, $m) || (int)$m[2] < 1 || (int)$m[2] > 53) {
        $week = date('o-\WW');
        preg_match('/^(\d{4})-W(\d{2})$/', $week, $m);
    }
    $start = (new DateTime())->setISODate((int)$m[1], (int)$m[2], 1);
    $end = (clone $start)->modify('+6 days');
    return [$week, $start, $end];
}

/** SVG icon by name (used by the sidebars). */
function icon(string $name): string {
    static $p = [
        'dash'  => '<path d="M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
        'room'  => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 21v-6h6v6"/>',
        'sec'   => '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3 3-5 6-5s6 2 6 5M16 11a3 3 0 1 0 0-6"/>',
        'sch'   => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5M17 4.5a3.5 3.5 0 0 1 0 7M22 20c0-2.5-1.5-4-4-4.8"/>',
        'use'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'rate'  => '<path d="M12 2l3 7 7 .5-5.5 4.8 1.8 7.2L12 17.8 5.7 21.5l1.8-7.2L2 9.5 9 9z"/>',
        'acc'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        'out'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'help'  => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01"/>',
        'bell'  => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8M10 20a2 2 0 0 0 4 0"/>',
    ];
    return '<svg class="s-ic" viewBox="0 0 24 24">' . $p[$name] . '</svg>';
}
