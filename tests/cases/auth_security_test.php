<?php

require_once WALLOS_ROOT . '/includes/auth_rate_limit.php';
require_once WALLOS_ROOT . '/libs/csrf.php';

wallos_test('auth rate-limit migration creates indexed durable storage', function () {
    $db = wallos_test_open_database();

    $table = $db->querySingle(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'auth_rate_limits'"
    );
    assert_same('auth_rate_limits', $table, 'rate-limit table should be created by migrations');
    assert_same(2, (int) $db->querySingle(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND tbl_name = 'auth_rate_limits'"
    ), 'rate-limit lookup indexes should be created');

    $db->close();
});

wallos_test('login limiter protects account and IP independently and clears on success', function () {
    $db = wallos_test_open_database();
    $now = 2000000000;

    for ($attempt = 0; $attempt < WALLOS_LOGIN_RATE_LIMIT_ATTEMPTS; $attempt++) {
        assert_true(!wallos_login_rate_limited($db, 'UserName', '203.0.113.10', $now),
            'login should remain available before the failure threshold');
        wallos_record_login_failure($db, 'UserName', '203.0.113.10', $now - $attempt);
    }
    assert_true(wallos_login_rate_limited($db, ' username ', '198.51.100.9', $now),
        'normalized account should be limited across IP addresses');
    assert_true(wallos_login_rate_limited($db, 'someone-else', '203.0.113.10', $now),
        'an abusive IP should be limited across account identifiers');

    wallos_record_login_failure($db, 'other-account', '203.0.113.10', $now);
    wallos_clear_login_failures($db, 'USERNAME');
    assert_true(!wallos_login_rate_limited($db, 'username', '203.0.113.10', $now),
        'successful login should clear that account\'s failures');
    assert_same(1, (int) $db->querySingle("SELECT COUNT(*) FROM auth_rate_limits WHERE action = 'login'"),
        'successful login must not clear failures for other accounts on the same IP');
    $db->close();
});

wallos_test('login limiter keeps account and IP counters separate', function () {
    $db = wallos_test_open_database();
    $now = 2000000000;

    for ($attempt = 0; $attempt < 3; $attempt++) {
        wallos_record_login_failure($db, 'one-user', '203.0.113.' . (20 + $attempt), $now);
    }
    for ($attempt = 0; $attempt < 2; $attempt++) {
        wallos_record_login_failure($db, 'other-' . $attempt, '198.51.100.20', $now);
    }
    assert_true(!wallos_login_rate_limited($db, 'one-user', '198.51.100.20', $now),
        'independent counters must not be added together');
    $db->close();
});

wallos_test('registration limiter atomically accepts three attempts per IP per hour', function () {
    $db = wallos_test_open_database();
    $now = 2000000000;

    for ($attempt = 0; $attempt < WALLOS_REGISTRATION_RATE_LIMIT_ATTEMPTS; $attempt++) {
        assert_true(wallos_consume_registration_attempt($db, '203.0.113.30', $now),
            'attempt within registration allowance should be consumed');
    }
    assert_true(!wallos_consume_registration_attempt($db, '203.0.113.30', $now),
        'fourth registration attempt in an hour should be rejected');
    assert_true(wallos_consume_registration_attempt($db, '203.0.113.31', $now),
        'a separate IP should have an independent allowance');
    assert_true(wallos_consume_registration_attempt($db, '203.0.113.30', $now + 3601),
        'registration allowance should recover after the window');
    $db->close();
});

wallos_test('stale auth limiter records are cleaned without retaining raw identifiers', function () {
    $db = wallos_test_open_database();
    $now = 2000000000;
    wallos_record_login_failure($db, 'private@example.com', '203.0.113.40', $now - 200000);
    wallos_rate_limit_cleanup($db, $now);

    assert_same(0, (int) $db->querySingle('SELECT COUNT(*) FROM auth_rate_limits'),
        'records older than retention should be deleted');
    wallos_record_login_failure($db, 'private@example.com', '203.0.113.40', $now);
    $record = $db->querySingle('SELECT identifier_hash, ip_hash FROM auth_rate_limits', true);
    assert_true($record['identifier_hash'] !== 'private@example.com', 'account identifier should be hashed');
    assert_true($record['ip_hash'] !== '203.0.113.40', 'IP address should be hashed');
    $db->close();
});

wallos_test('CSRF tokens are random, session-bound, constant-time checked, and rotatable', function () {
    $_SESSION = [];
    $token = generate_csrf_token();
    assert_same(64, strlen($token), 'CSRF token should contain 32 random bytes as hex');
    assert_true(verify_csrf_token($token), 'current CSRF token should verify');
    assert_true(!verify_csrf_token(null), 'missing CSRF token should fail');
    assert_true(!verify_csrf_token(str_repeat('0', 64)), 'invalid CSRF token should fail');

    $rotated = rotate_csrf_token();
    assert_true($rotated !== $token, 'CSRF token should rotate after authentication');
    assert_true(!verify_csrf_token($token), 'rotated-out CSRF token should fail');
    assert_true(verify_csrf_token($rotated), 'new CSRF token should verify');
});

wallos_test('proxy-aware request helpers trust forwarded metadata only on Render', function () {
    $server = $_SERVER;
    $render = getenv('RENDER');
    $_SERVER = [
        'REMOTE_ADDR' => '192.0.2.10',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.50',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ];
    putenv('RENDER');
    assert_same('192.0.2.10', wallos_client_ip(), 'direct requests should ignore forwarding headers');
    assert_true(!wallos_request_is_https(), 'direct HTTP should ignore spoofed forwarded protocol');

    putenv('RENDER=true');
    assert_same('203.0.113.50', wallos_client_ip(), 'Render should use Cloudflare connecting IP');
    assert_true(wallos_request_is_https(), 'Render should honor its forwarded HTTPS protocol');

    $_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
    assert_same('192.0.2.10', wallos_client_ip(), 'invalid proxy IP should fall back safely');
    $_SERVER = $server;
    $render === false ? putenv('RENDER') : putenv('RENDER=' . $render);
});

wallos_test('session cookie policy is HTTPS-aware and preserves local HTTP', function () {
    $server = $_SERVER;
    $render = getenv('RENDER');
    $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];
    putenv('RENDER');
    $local = wallos_session_cookie_options();
    assert_same(false, $local['secure'], 'local HTTP session cookie should remain usable');
    assert_same(true, $local['httponly'], 'session cookie should be HttpOnly');
    assert_same('Lax', $local['samesite'], 'session cookie should use SameSite=Lax');

    putenv('RENDER=true');
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    assert_same(true, wallos_session_cookie_options()['secure'],
        'proxied Render HTTPS session cookie should be Secure');
    $_SERVER = $server;
    $render === false ? putenv('RENDER') : putenv('RENDER=' . $render);
});

wallos_test('public password auth forms enforce CSRF and durable throttling', function () {
    $login = file_get_contents(WALLOS_ROOT . '/login.php');
    $registration = file_get_contents(WALLOS_ROOT . '/registration.php');

    foreach ([$login, $registration] as $form) {
        assert_contains('name="csrf_token"', $form, 'public auth form should submit a CSRF token');
        assert_contains('verify_csrf_token(', $form, 'public auth POST should validate its CSRF token');
    }
    assert_contains('wallos_login_rate_limited(', $login, 'password login should enforce durable throttling');
    assert_contains('wallos_record_login_failure(', $login, 'password failures should be recorded');
    assert_contains('wallos_clear_login_failures(', $login, 'successful password auth should clear failures');
    assert_contains('wallos_consume_registration_attempt(', $registration,
        'registration POST should atomically consume its IP allowance');
});
