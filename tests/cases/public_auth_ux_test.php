<?php

wallos_test('public login exposes recovery, conditional signup, and Turnstile', function () {
    $login = file_get_contents(WALLOS_ROOT . '/login.php');

    assert_contains('href="passwordreset.php"', $login,
        'password login should expose its recovery route');
    assert_contains('if (!$password_login_disabled)', $login,
        'password recovery should remain scoped to password login');
    assert_contains('if ($registrations)', $login,
        'signup should remain conditional on the Admin registration policy');
    assert_contains('href="registration.php"', $login,
        'enabled public registration should expose its real route');
    assert_contains("translate('create_account_link',", $login,
        'signup should use the dedicated create-account label');
    assert_contains('class="cf-turnstile"', $login,
        'enabled Turnstile should render on password login');
    assert_contains('wallos_verify_turnstile(', $login,
        'password login should verify Turnstile server-side');
});

wallos_test('public registration exposes login and reuses server-side Turnstile', function () {
    $registration = file_get_contents(WALLOS_ROOT . '/registration.php');

    assert_contains('href="login.php"', $registration,
        'registration should expose the existing login route');
    assert_contains('class="cf-turnstile"', $registration,
        'enabled Turnstile should render on registration');
    assert_contains('wallos_verify_turnstile(', $registration,
        'registration should verify Turnstile server-side');
    assert_contains("(string) (\$_POST['cf-turnstile-response'] ?? '')", $registration,
        'direct registration POSTs without a token should reach server verification');
});

wallos_test('password recovery remains discoverable when mail is not configured', function () {
    $reset = file_get_contents(WALLOS_ROOT . '/passwordreset.php');

    assert_contains('$passwordRecoveryConfigured =', $reset,
        'password recovery should detect whether delivery is configured');
    assert_contains("translate('password_recovery_unavailable',", $reset,
        'an unavailable recovery flow should explain itself without exposing configuration');
    assert_not_contains('if ($settings[\'smtp_address\'] == ""', $reset,
        'missing mail configuration should not silently redirect to login');
});

wallos_test('English and Persian include complete public auth labels', function () {
    require WALLOS_ROOT . '/includes/i18n/en.php';
    $english = $i18n;
    require WALLOS_ROOT . '/includes/i18n/fa.php';
    $persian = $i18n;

    foreach (['forgot_password', 'no_account_yet', 'already_have_account',
        'create_account_link', 'password_recovery_unavailable'] as $key) {
        assert_true(isset($english[$key]) && trim($english[$key]) !== '',
            "English public auth label $key should exist");
        assert_true(isset($persian[$key]) && trim($persian[$key]) !== '',
            "Persian public auth label $key should exist");
    }
});
