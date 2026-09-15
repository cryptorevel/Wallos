<?php

/**
 * Request and cookie security helpers shared by authentication entry points.
 *
 * Render terminates TLS before forwarding requests to the container. Its
 * trusted runtime marker lets us distinguish Render's proxy headers from
 * similarly named headers supplied directly to a self-hosted instance.
 */

function wallos_is_render_environment(): bool
{
    return strtolower(trim((string) getenv('RENDER'))) === 'true';
}

function wallos_request_is_https(): bool
{
    $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
    if ($https === 'on' || $https === '1' || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    return wallos_is_render_environment()
        && strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
}

function wallos_normalize_ip(?string $ip): ?string
{
    $ip = trim((string) $ip);
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return null;
    }

    $packed = @inet_pton($ip);
    return $packed === false ? $ip : inet_ntop($packed);
}

function wallos_client_ip(): string
{
    if (wallos_is_render_environment()) {
        $cloudflareIp = wallos_normalize_ip($_SERVER['HTTP_CF_CONNECTING_IP'] ?? null);
        if ($cloudflareIp !== null) {
            return $cloudflareIp;
        }
    }

    return wallos_normalize_ip($_SERVER['REMOTE_ADDR'] ?? null) ?? 'unknown';
}

function wallos_session_cookie_options(int $lifetime = 2592000): array
{
    return [
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => wallos_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function wallos_start_session(int $lifetime = 2592000): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(wallos_session_cookie_options($lifetime));
        session_start();
    }
}

function wallos_auth_cookie_options(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => wallos_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}
