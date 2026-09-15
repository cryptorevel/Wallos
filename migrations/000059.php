<?php

$db->exec(
    'CREATE TABLE IF NOT EXISTS auth_rate_limits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        action TEXT NOT NULL,
        identifier_hash TEXT NOT NULL DEFAULT \'\',
        ip_hash TEXT NOT NULL,
        attempted_at INTEGER NOT NULL
    )'
);

$db->exec(
    'CREATE INDEX IF NOT EXISTS idx_auth_rate_limits_identifier
     ON auth_rate_limits (action, identifier_hash, attempted_at)'
);
$db->exec(
    'CREATE INDEX IF NOT EXISTS idx_auth_rate_limits_ip
     ON auth_rate_limits (action, ip_hash, attempted_at)'
);

?>
