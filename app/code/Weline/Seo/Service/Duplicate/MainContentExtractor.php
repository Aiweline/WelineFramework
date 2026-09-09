<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Duplicate;

/**
 * Strip chrome and extract main textual content from HTML.
 */
final class MainContentExtractor
{
    public function extract(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $html = \preg_replace('#<(script|style|noscript|svg|iframe)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = \preg_replace('#<!--.*?-->#s', ' ', $html) ?? $html;

        $chunk = $this->preferMainChunk($html);
        $chunk = \preg_replace('#</?(nav|header|footer|aside|form)[^>]*>#i', ' ', $chunk) ?? $chunk;
        $text = \strip_tags($chunk);
        $text = \html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = \preg_replace('/\s+/u', ' ', $text) ?? $text;

        return \trim($text);
    }

    private function preferMainChunk(string $html): string
    {
        if (\preg_match('#<article\b[^>]*>(.*?)</article>#is', $html, $m)) {
            return $m[1];
        }
        if (\preg_match('#<main\b[^>]*>(.*?)</main>#is', $html, $m)) {
            return $m[1];
        }
        if (\preg_match('#<div[^>]+(?:id|class)=["\'][^"\']*(?:content|post|article|entry)[^"\']*["\'][^>]*>(.*?)</div>#is', $html, $m)) {
            return $m[1];
        }
        if (\preg_match('#<body\b[^>]*>(.*?)</body>#is', $html, $m)) {
            return $m[1];
        }

        return $html;
    }
}
