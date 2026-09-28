<?php
declare(strict_types=1);

/**
 * ONE-TIME utility to run migration/007_site_visits.sql directly on the live
 * database, for when phpMyAdmin access is the blocker rather than the SQL
 * itself. Visit it once with the correct ?key=, confirm it says "Success",
 * then DELETE THIS FILE — it is not meant to stay on the server.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain');

if (($_GET['key'] ?? '') !== 'obi-migrate-007-run-once') {
    http_response_code(403);
    exit('Forbidden — missing or wrong ?key=');
}

try {
    db()->exec("
        CREATE TABLE IF NOT EXISTS site_visits (
          id INT AUTO_INCREMENT PRIMARY KEY,
          session_id VARCHAR(64) NOT NULL,
          visit_date DATE NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_site_visits_session_day (session_id, visit_date),
          INDEX idx_site_visits_date (visit_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "Success — the site_visits table now exists. Delete this file (_run-migration-007.php) now.";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error: ' . $e->getMessage();
}
