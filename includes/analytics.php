<?php
declare(strict_types=1);

/**
 * Counts a visit once per (session, day) — INSERT IGNORE on the unique key
 * means every later pageview in the same browser session that same day is a
 * free no-op, so COUNT(*) grouped by visit_date (see get_daily_visitors() in
 * includes/admin.php) is a real "unique visitors that day" figure rather
 * than a raw pageview count. No IP address or user-agent is stored. Called
 * once per request from bootstrap.php; wrapped so a database hiccup here
 * never breaks the page it's tracking.
 */
function track_site_visit(): void
{
    try {
        $stmt = db()->prepare('INSERT IGNORE INTO site_visits (session_id, visit_date) VALUES (?, CURDATE())');
        $stmt->execute([session_id()]);
    } catch (Throwable $e) {
        error_log('[analytics] track_site_visit failed: ' . $e->getMessage());
    }
}
