<?php

declare(strict_types=1);

/**
 * Read-only acceptance probe for the configured English Hanfu homepage.
 * Pipe an actual GET /en_US/ HTML response into this script (without headers).
 * This checks rendered content; it is not a substitute for browser acceptance.
 */
$html = stream_get_contents(STDIN);
if (trim($html) === '') {
    fwrite(STDERR, "No homepage HTML received.\n");
    exit(2);
}

$document = new DOMDocument();
libxml_use_internal_errors(true);
$document->loadHTML($html);
libxml_clear_errors();
$xpath = new DOMXPath($document);
$results = [];

$newArrivals = $xpath->query('//*[@data-widget-code="new-arrivals"]')->item(0);
$emptyShell = $newArrivals instanceof DOMElement
    && $xpath->query('.//*[@data-testid="new-arrivals-empty"]', $newArrivals)->length > 0;
if ($emptyShell) {
    $results[] = ['case' => 'new_arrivals_copy', 'status' => 'skipped', 'reason' => 'No current new arrivals'];
} else {
    foreach (['widget-title', 'widget-subtitle'] as $class) {
        $node = $newArrivals instanceof DOMElement
            ? $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]', $newArrivals)->item(0)
            : null;
        $copy = trim($node?->textContent ?? '');
        $results[] = [
            'case' => 'new_arrivals_' . $class,
            'status' => $copy !== '' && preg_match('/\p{sc=Han}/u', $copy) !== 1 ? 'passed' : 'failed',
            'observed' => $copy,
            'expected' => 'Non-empty English storefront copy',
        ];
    }
}

$footerLinks = [];
foreach ($xpath->query('//*[@id="footer"]//a[@href]') as $link) {
    $label = trim((string)preg_replace('/\s+/u', ' ', $link->textContent));
    $footerLinks[$label] = $link->getAttribute('href');
}
foreach ([
    'About Us' => '/en_US/about',
    'News Center' => '/en_US/blog/category/news',
    'Supplier partnerships' => '/en_US/inquiry/suppliers',
    'Terms of Use' => '/en_US/terms',
    'Privacy Notice' => '/en_US/privacy',
    'Cookie Policy' => '/en_US/cookies',
] as $label => $expectedPath) {
    $url = $footerLinks[$label] ?? '';
    $results[] = [
        'case' => 'footer_' . $label,
        'status' => parse_url($url, PHP_URL_PATH) === $expectedPath ? 'passed' : 'failed',
        'observed' => $url,
        'expected_path' => $expectedPath,
    ];
}

$categoryLabels = [];
foreach ($xpath->query('//header//a[@href]') as $link) {
    $path = (string)(parse_url($link->getAttribute('href'), PHP_URL_PATH) ?? '');
    if (!isset($categoryLabels[$path])) {
        $categoryLabels[$path] = trim((string)preg_replace('/\s+/u', ' ', $link->textContent));
    }
}
foreach (['/en_US/category/hanfu', '/en_US/category/hanfu/material', '/en_US/category/hanfu/material/silk'] as $path) {
    $label = $categoryLabels[$path] ?? '';
    $results[] = [
        'case' => 'category_' . $path,
        'status' => $label !== '' && preg_match('/\p{sc=Han}/u', $label) !== 1 ? 'passed' : 'failed',
        'observed' => $label,
        'expected' => 'Preserve the English catalog label',
    ];
}

echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
exit(in_array('failed', array_column($results, 'status'), true) ? 1 : 0);
