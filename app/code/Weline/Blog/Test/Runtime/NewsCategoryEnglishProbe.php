<?php

declare(strict_types=1);

// Read-only content acceptance for GET /en_US/blog/category/news, without headers.
// This is not a replacement for browser interaction or visual acceptance.
$html = stream_get_contents(STDIN);
if (trim($html) === '') {
    fwrite(STDERR, "No news category HTML received.\n");
    exit(2);
}
$document = new DOMDocument();
libxml_use_internal_errors(true);
$document->loadHTML($html);
libxml_clear_errors();
$xpath = new DOMXPath($document);
$title = trim($xpath->evaluate('string(//title)'));
$heading = trim($xpath->evaluate('string(//*[contains(concat(" ", normalize-space(@class), " "), " amazon-blog-listing__title ")])'));
$lang = $document->documentElement->getAttribute('lang');
$results = [
    ['case' => 'html_language', 'observed' => $lang, 'passed' => $lang === 'en-US'],
    ['case' => 'english_title', 'observed' => $title, 'passed' => str_contains($title, 'News Center') && preg_match('/\p{sc=Han}/u', $title) !== 1],
    ['case' => 'news_heading', 'observed' => $heading, 'passed' => $heading === 'News Center'],
];
echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
exit(in_array(false, array_column($results, 'passed'), true) ? 1 : 0);
