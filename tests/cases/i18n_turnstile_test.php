<?php

wallos_test('Persian catalogue covers every English translation key', function () {
    require WALLOS_ROOT . '/includes/i18n/en.php';
    $english = $i18n;

    require WALLOS_ROOT . '/includes/i18n/fa.php';
    $persian = $i18n;

    assert_same([], array_values(array_diff(array_keys($english), array_keys($persian))),
        'Persian must not omit English translation keys');
    assert_same([], array_values(array_diff(array_keys($persian), array_keys($english))),
        'Persian must not introduce unknown translation keys');
    assert_same('ورود', $persian['login'], 'Login should be translated to Persian');
    assert_same('تأیید کپچا ناموفق بود.', $persian['captcha_verification_failed'],
        'CAPTCHA failures should have a Persian translation');
});

wallos_test('Persian is registered as an RTL language', function () {
    require WALLOS_ROOT . '/includes/i18n/languages.php';

    assert_true(isset($languages['fa']), 'Persian should appear in the language registry');
    assert_same('فارسی', $languages['fa']['name'], 'Persian should use its native label');
    assert_same('rtl', $languages['fa']['dir'], 'Persian should render right-to-left');
});

wallos_test('Turnstile defaults off and fails closed when enabled without keys', function () {
    require_once WALLOS_ROOT . '/includes/turnstile.php';

    putenv('TURNSTILE_ENABLED');
    putenv('TURNSTILE_SITE_KEY');
    putenv('TURNSTILE_SECRET_KEY');

    $disabled = wallos_get_turnstile_configuration();
    assert_same(false, $disabled['enabled'], 'Turnstile should be opt-in');
    assert_same(true, wallos_verify_turnstile('', null)['success'],
        'Disabled Turnstile should preserve existing authentication behavior');

    putenv('TURNSTILE_ENABLED=true');
    $incomplete = wallos_get_turnstile_configuration();
    assert_same(true, $incomplete['enabled'], 'Explicitly enabled Turnstile should remain enabled');
    assert_same(false, $incomplete['configured'], 'Missing keys should be reported as incomplete');
    assert_same('configuration', wallos_verify_turnstile('not-a-real-token', null)['error'],
        'Incomplete configuration should fail closed before a network request');

    putenv('TURNSTILE_SITE_KEY=x');
    putenv('TURNSTILE_SECRET_KEY=x');
    assert_same('missing', wallos_verify_turnstile('', null)['error'],
        'An enabled form submission without a token should be rejected');

    putenv('TURNSTILE_ENABLED');
    putenv('TURNSTILE_SITE_KEY');
    putenv('TURNSTILE_SECRET_KEY');
});
