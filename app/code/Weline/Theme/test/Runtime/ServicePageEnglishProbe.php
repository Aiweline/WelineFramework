<?php

declare(strict_types=1);

// Read-only check of actual service-page HTML from STDIN, without HTTP headers.
// This does not replace browser interaction, visual QA, or policy-content review.
$page = $argv[1] ?? '';
$pages = [
    'faq', 'payment', 'shipping', 'returns',
    'deals', 'suppliers', 'currency', 'terms', 'privacy', 'cookies',
];
if (!in_array($page, $pages, true)) {
    fwrite(STDERR, 'Choose ' . implode(', ', $pages) . ".\n");
    exit(2);
}
$html = stream_get_contents(STDIN);
if (trim($html) === '') {
    fwrite(STDERR, "No service-page HTML received.\n");
    exit(2);
}

$document = new DOMDocument();
libxml_use_internal_errors(true);
$document->loadHTML($html);
libxml_clear_errors();
$xpath = new DOMXPath($document);
$language = $document->documentElement->getAttribute('lang');
$title = trim($xpath->evaluate('string(//title)'));
$results = [
    ['case' => 'html_language', 'observed' => $language, 'passed' => $language === 'en-US'],
    ['case' => 'english_title', 'observed' => $title, 'passed' => $title !== '' && preg_match('/\p{sc=Han}/u', $title) !== 1],
];
if ($page === 'faq') {
    $heading = trim($xpath->evaluate('string(//*[@id="help-topics-title"])'));
    $results[] = [
        'case' => 'help_topics_heading',
        'observed' => $heading,
        'passed' => $heading === 'Popular help topics',
    ];
}

echo json_encode(['page' => $page, 'checks' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
exit(in_array(false, array_column($results, 'passed'), true) ? 1 : 0);
