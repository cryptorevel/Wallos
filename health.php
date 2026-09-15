<?php

header('Content-Type: text/plain');

$databaseFile = __DIR__ . '/db/wallos.db';
$logosDirectory = __DIR__ . '/images/uploads/logos';
$migrationFiles = glob(__DIR__ . '/migrations/*.php') ?: [];

try {
    if (!is_readable($databaseFile) || !is_dir($logosDirectory) || !is_writable($logosDirectory)) {
        throw new RuntimeException('Persistent storage is unavailable.');
    }

    $db = new SQLite3($databaseFile, SQLITE3_OPEN_READONLY);
    $result = $db->querySingle('SELECT COUNT(*) FROM migrations');
    $db->close();

    if ((int) $result !== count($migrationFiles)) {
        throw new RuntimeException('Database migrations are incomplete.');
    }
} catch (Throwable $error) {
    http_response_code(503);
    echo 'NOT READY';
    exit;
}

http_response_code(200);
echo 'OK';
exit;
