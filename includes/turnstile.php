<?php

const WALLOS_TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

function wallos_turnstile_parse_enabled($value): ?bool
{
    if ($value === false || trim((string) $value) === '') {
        return false;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
}

function wallos_get_turnstile_configuration(): array
{
    $enabledValue = getenv('TURNSTILE_ENABLED');
    $enabled = wallos_turnstile_parse_enabled($enabledValue);
    $siteKey = trim((string) (getenv('TURNSTILE_SITE_KEY') ?: ''));
    $secretKey = trim((string) (getenv('TURNSTILE_SECRET_KEY') ?: ''));
    $enabledValueIsValid = $enabled !== null;

    // Treat an invalid non-empty flag as an enabled but broken configuration,
    // so a typo cannot silently disable CAPTCHA protection.
    if (!$enabledValueIsValid) {
        $enabled = true;
    }

    return [
        'enabled' => $enabled,
        'configured' => !$enabled || ($enabledValueIsValid && $siteKey !== '' && $secretKey !== ''),
        'site_key' => $siteKey,
        'secret_key' => $secretKey,
    ];
}

function wallos_verify_turnstile(string $token, ?string $remoteIp = null): array
{
    $configuration = wallos_get_turnstile_configuration();

    if (!$configuration['enabled']) {
        return ['success' => true, 'error' => null];
    }

    if (!$configuration['configured']) {
        error_log('Wallos Turnstile configuration is incomplete or invalid.');
        return ['success' => false, 'error' => 'configuration'];
    }

    if (trim($token) === '') {
        return ['success' => false, 'error' => 'missing'];
    }

    $requestData = [
        'secret' => $configuration['secret_key'],
        'response' => $token,
    ];

    if ($remoteIp !== null && filter_var($remoteIp, FILTER_VALIDATE_IP)) {
        $requestData['remoteip'] = $remoteIp;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($requestData),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents(WALLOS_TURNSTILE_VERIFY_URL, false, $context);
    if ($response === false) {
        error_log('Wallos Turnstile verification request failed.');
        return ['success' => false, 'error' => 'unavailable'];
    }

    $result = json_decode($response, true);
    if (!is_array($result)) {
        error_log('Wallos Turnstile verification returned invalid JSON.');
        return ['success' => false, 'error' => 'unavailable'];
    }

    if (($result['success'] ?? false) !== true) {
        $responseErrorCodes = $result['error-codes'] ?? [];
        if (!is_array($responseErrorCodes)) {
            $responseErrorCodes = [];
        }
        $errorCodes = array_filter(
            $responseErrorCodes,
            static fn($code) => is_string($code) && preg_match('/^[a-z0-9_-]+$/i', $code)
        );
        error_log('Wallos Turnstile verification rejected a token: ' . implode(',', $errorCodes));
        return ['success' => false, 'error' => 'invalid'];
    }

    return ['success' => true, 'error' => null];
}

function wallos_turnstile_error_translation_key(?string $error): string
{
    return match ($error) {
        'configuration' => 'captcha_configuration_error',
        'unavailable' => 'captcha_unavailable',
        'missing' => 'captcha_human_prompt',
        default => 'captcha_verification_failed',
    };
}
