<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

final class BlogTranslationHtml
{
    public static function textNodes(string $html): array
    {
        $nodes = [];
        $stack = [];
        $segments = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === null) {
                continue;
            }
            if ($segment[0] === '<') {
                self::updateSkipStack($segment, $stack);
                continue;
            }
            if ($stack !== []) {
                continue;
            }
            $trimmed = trim($segment);
            if ($trimmed !== '') {
                $nodes[] = $trimmed;
            }
        }
        return array_values($nodes);
    }

    public static function rebuild(string $html, array $map): string
    {
        $parts = [];
        $stack = [];
        $segments = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === null) {
                continue;
            }
            if ($segment[0] === '<') {
                self::updateSkipStack($segment, $stack);
                $parts[] = $segment;
                continue;
            }
            if ($stack === []) {
                $trimmed = trim($segment);
                if ($trimmed !== '' && array_key_exists($trimmed, $map)) {
                    preg_match('/^(\s*)(.*?)(\s*)$/su', $segment, $m);
                    $parts[] = ($m[1] ?? '') . (string)$map[$trimmed] . ($m[3] ?? '');
                    continue;
                }
            }
            $parts[] = $segment;
        }
        return implode('', $parts);
    }

    public static function slugFor(string $slug, string $locale, int $attempt = 0): string
    {
        $base = strtolower(trim($slug));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base);
        $base = trim((string)$base, '-');
        if ($base === '') {
            $base = 'post';
        }
        $lang = strtolower(substr(str_replace('-', '_', $locale), 0, 2));
        if (strlen($lang) < 2) {
            $lang = 'xx';
        }
        $out = $base . '-' . $lang;
        if ($attempt > 0) {
            $out .= '-' . ($attempt + 1);
        }
        return $out;
    }

    private static function updateSkipStack(string $segment, array &$stack): void
    {
        if (preg_match('/^<\s*\/\s*([a-zA-Z][a-zA-Z0-9]*)/', $segment, $m)) {
            $tag = strtolower($m[1]);
            if ($stack !== [] && $stack[count($stack) - 1] === $tag) {
                array_pop($stack);
            }
            return;
        }
        if (preg_match('/^<\s*([a-zA-Z][a-zA-Z0-9]*)/', $segment, $m)) {
            $tag = strtolower($m[1]);
            if (in_array($tag, ['script', 'style', 'code', 'pre'], true)) {
                $stack[] = $tag;
            }
        }
    }
}
