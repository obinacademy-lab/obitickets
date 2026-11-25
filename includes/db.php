<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // The database must tell the time the way PHP does (Africa/Kampala). The hosting MySQL runs on UTC, which stamped new records
        // (NOW(), CURRENT_TIMESTAMP defaults) three hours early and shifted every time window. Kampala has no daylight saving.
        try {
            $pdo->exec("SET time_zone = " . $pdo->quote((new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P')));
        } catch (Throwable $e) {
            error_log('[db] could not set the session time zone: ' . $e->getMessage());
        }
    }
    return $pdo;
}
