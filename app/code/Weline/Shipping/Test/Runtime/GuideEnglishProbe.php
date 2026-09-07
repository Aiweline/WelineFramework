<?php

declare(strict_types=1);

// Read-only: pipe an actual public guide response to STDIN. This is content
// evidence, not a substitute for browser layout or checkout acceptance.
$guide = $argv[1] ?? '';
$pages = [
    'shipping' => [
        'test_id' => 'shipping-guide',
        'heading' => 'Shipping Information',
        'excerpt' => '1.1 In-stock orders:',
        'timeframe' => '1–3 business days',
    ],
    'returns' => [
        'test_id' => 'shipping-returns',
        'heading' => 'Returns & Exchanges',
        'excerpt' => '1.1 General eligibility:',
        'timeframe' => '3–15 business days',
    ],
];
if (!isset($pages[$guide])) {
    fwrite(STDERR, "Usage: php GuideEnglishProbe.php shipping|returns < response.html\n");
    exit(2);
}

$document = new DOMDocument();
$previousErrors = libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="UTF-8">' . stream_get_contents(STDIN));
libxml_clear_errors();
libxml_use_internal_errors($previousErrors);
$xpath = new DOMXPath($document);
$page = $pages[$guide];
$articles = $xpath->query('//*[@data-testid="' . $page['test_id'] . '"]');
$checks = ['guide_present_once' => $articles->length === 1];
$checks['english_document'] = str_starts_with(strtolower($document->documentElement->getAttribute('lang')), 'en');
if ($articles->length === 1) {
    $article = $articles->item(0);
    foreach (iterator_to_array($xpath->query('.//style|.//script|.//template', $article)) as $nonContent) {
        $nonContent->parentNode->removeChild($nonContent);
    }
    $text = preg_replace('/\s+/u', ' ', $article->textContent);
    $checks['english_heading'] = trim($xpath->evaluate('string(.//h1)', $article)) === $page['heading'];
    $checks['no_chinese_guide_copy'] = preg_match('/\p{Han}/u', $text) === 0;
    $checks['original_sections_retained'] = $xpath->query('.//section[@weline-code]', $article)->length === 8;
    $checks['contents_links_retained'] = $xpath->query('.//aside//nav/a[starts-with(@href,"#")]', $article)->length === 8;
    $checks['policy_copy_present'] = str_contains($text, $page['excerpt']) && str_contains($text, $page['timeframe']);
    $checks['related_links_localized'] = true;
    foreach ($xpath->query('.//a[not(starts-with(@href,"#"))]', $article) as $link) {
        $path = (string) parse_url($link->getAttribute('href'), PHP_URL_PATH);
        if (!str_starts_with($path, '/en_US/')) {
            $checks['related_links_localized'] = false;
        }
    }
}

$passed = count(array_filter($checks));
echo json_encode([
    'guide' => $guide,
    'passed' => $passed,
    'total' => count($checks),
    'checks' => $checks,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
exit($passed === count($checks) ? 0 : 1);
