<?php

wallos_test('Vazirmatn is loaded only for Persian with the intended weights', function () {
    $lang = 'fa';
    ob_start();
    require WALLOS_ROOT . '/includes/persian_font.php';
    $persianHead = ob_get_clean();
    assert_contains('family=Vazirmatn:wght@300;400;500;600;700&amp;display=swap',
        $persianHead, 'Persian font request should use only required weights and swap');

    $lang = 'en';
    ob_start();
    require WALLOS_ROOT . '/includes/persian_font.php';
    $englishHead = ob_get_clean();
    assert_same('', $englishHead, 'non-Persian locale should not request Vazirmatn');
});

wallos_test('Persian font stack is root-scoped without changing the default font', function () {
    foreach (['styles.css', 'login.css'] as $stylesheet) {
        $css = file_get_contents(WALLOS_ROOT . '/styles/' . $stylesheet);
        assert_contains('html[lang="fa"]', $css, 'Persian font rule should be locale scoped');
        assert_contains('font-family: "Vazirmatn", Tahoma, Arial, sans-serif;', $css,
            'Persian controls should inherit the Vazirmatn stack');
        assert_contains("font-family: Barlow, 'Helvetica Neue', Helvetica, sans-serif;", $css,
            'default non-Persian font stack should stay unchanged');
    }

    $statistics = file_get_contents(WALLOS_ROOT . '/scripts/stats.js');
    assert_contains('getComputedStyle(document.body).fontFamily', $statistics,
        'charts should inherit the active locale font');
    assert_not_contains('const font   = "Barlow', $statistics,
        'charts should not force the non-Persian font in Persian');
});
