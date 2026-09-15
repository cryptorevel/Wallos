<?php

require_once __DIR__ . '/request_security.php';

const WALLOS_LOGIN_RATE_LIMIT_ATTEMPTS = 5;
const WALLOS_LOGIN_RATE_LIMIT_WINDOW = 900;
const WALLOS_REGISTRATION_RATE_LIMIT_ATTEMPTS = 3;
const WALLOS_REGISTRATION_RATE_LIMIT_WINDOW = 3600;
const WALLOS_AUTH_RATE_LIMIT_RETENTION = 172800;

function wallos_normalize_account_identifier(string $identifier): string
{
    $identifier = trim($identifier);
    return function_exists('mb_strtolower')
        ? mb_strtolower($identifier, 'UTF-8')
        : strtolower($identifier);
}

function wallos_rate_limit_hash(string $type, string $value): string
{
    return hash('sha256', 'wallos-auth-rate-limit-v1' . "\0" . $type . "\0" . $value);
}

function wallos_rate_limit_cleanup(SQLite3 $db, ?int $now = null): void
{
    $cutoff = ($now ?? time()) - WALLOS_AUTH_RATE_LIMIT_RETENTION;
    $stmt = $db->prepare('DELETE FROM auth_rate_limits WHERE attempted_at < :cutoff');
    $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
    $stmt->execute();
}

function wallos_login_rate_limited(SQLite3 $db, string $identifier, string $ip, ?int $now = null): bool
{
    $now ??= time();
    wallos_rate_limit_cleanup($db, $now);

    $identifierHash = wallos_rate_limit_hash('account', wallos_normalize_account_identifier($identifier));
    $ipHash = wallos_rate_limit_hash('ip', $ip);
    $stmt = $db->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN identifier_hash = :identifier_hash THEN 1 ELSE 0 END), 0) AS account_attempts,
            COALESCE(SUM(CASE WHEN ip_hash = :ip_hash THEN 1 ELSE 0 END), 0) AS ip_attempts
         FROM auth_rate_limits
         WHERE action = 'login' AND attempted_at >= :cutoff"
    );
    $stmt->bindValue(':cutoff', $now - WALLOS_LOGIN_RATE_LIMIT_WINDOW, SQLITE3_INTEGER);
    $stmt->bindValue(':identifier_hash', $identifierHash, SQLITE3_TEXT);
    $stmt->bindValue(':ip_hash', $ipHash, SQLITE3_TEXT);

    $attempts = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return (int) $attempts['account_attempts'] >= WALLOS_LOGIN_RATE_LIMIT_ATTEMPTS
        || (int) $attempts['ip_attempts'] >= WALLOS_LOGIN_RATE_LIMIT_ATTEMPTS;
}

function wallos_record_login_failure(SQLite3 $db, string $identifier, string $ip, ?int $now = null): void
{
    $stmt = $db->prepare(
        "INSERT INTO auth_rate_limits (action, identifier_hash, ip_hash, attempted_at)
         VALUES ('login', :identifier_hash, :ip_hash, :attempted_at)"
    );
    $stmt->bindValue(
        ':identifier_hash',
        wallos_rate_limit_hash('account', wallos_normalize_account_identifier($identifier)),
        SQLITE3_TEXT
    );
    $stmt->bindValue(':ip_hash', wallos_rate_limit_hash('ip', $ip), SQLITE3_TEXT);
    $stmt->bindValue(':attempted_at', $now ?? time(), SQLITE3_INTEGER);
    $stmt->execute();
}

function wallos_clear_login_failures(SQLite3 $db, string $identifier): void
{
    $stmt = $db->prepare(
        "DELETE FROM auth_rate_limits
         WHERE action = 'login' AND identifier_hash = :identifier_hash"
    );
    $stmt->bindValue(
        ':identifier_hash',
        wallos_rate_limit_hash('account', wallos_normalize_account_identifier($identifier)),
        SQLITE3_TEXT
    );
    $stmt->execute();
}

/**
 * Atomically consumes one registration attempt. The first three attempts in
 * an hour are accepted; later attempts from that IP are rejected.
 */
function wallos_consume_registration_attempt(SQLite3 $db, string $ip, ?int $now = null): bool
{
    $now ??= time();
    wallos_rate_limit_cleanup($db, $now);
    $ipHash = wallos_rate_limit_hash('ip', $ip);

    $db->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM auth_rate_limits
             WHERE action = 'registration' AND ip_hash = :ip_hash AND attempted_at >= :cutoff"
        );
        $stmt->bindValue(':ip_hash', $ipHash, SQLITE3_TEXT);
        $stmt->bindValue(':cutoff', $now - WALLOS_REGISTRATION_RATE_LIMIT_WINDOW, SQLITE3_INTEGER);
        $count = (int) $stmt->execute()->fetchArray(SQLITE3_NUM)[0];

        if ($count >= WALLOS_REGISTRATION_RATE_LIMIT_ATTEMPTS) {
            $db->exec('COMMIT');
            return false;
        }

        $stmt = $db->prepare(
            "INSERT INTO auth_rate_limits (action, identifier_hash, ip_hash, attempted_at)
             VALUES ('registration', '', :ip_hash, :attempted_at)"
        );
        $stmt->bindValue(':ip_hash', $ipHash, SQLITE3_TEXT);
        $stmt->bindValue(':attempted_at', $now, SQLITE3_INTEGER);
        $stmt->execute();
        $db->exec('COMMIT');
        return true;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}
