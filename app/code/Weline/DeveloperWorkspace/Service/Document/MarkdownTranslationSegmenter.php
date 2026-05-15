<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service\Document;

class MarkdownTranslationSegmenter
{
    private const MARKER_PREFIX = '__WELINE_DOC_SEG_';
    private const PROTECT_PREFIX = '__WELINE_DOC_PROTECT_';

    public function prepare(array $fields): array
    {
        $segments = [];
        $protected = [];
        $templates = [];
        $counter = 1;
        $protectCounter = 1;

        foreach ($fields as $field => $value) {
            $templates[$field] = $this->prepareText(
                (string)$value,
                (string)$field,
                $segments,
                $protected,
                $counter,
                $protectCounter
            );
        }

        return [
            'templates' => $templates,
            'segments' => $segments,
            'protected_tokens' => $protected,
        ];
    }

    public function restore(array $templates, array $translatedSegments, array $protectedTokens): array
    {
        $result = [];
        foreach ($templates as $field => $template) {
            $text = (string)$template;
            foreach ($translatedSegments as $id => $translatedText) {
                $text = str_replace(self::MARKER_PREFIX . $id . '__', (string)$translatedText, $text);
            }
            foreach ($protectedTokens as $token => $raw) {
                $text = str_replace($token, (string)$raw, $text);
            }
            $result[$field] = $text;
        }

        return $result;
    }

    private function prepareText(
        string $text,
        string $field,
        array &$segments,
        array &$protected,
        int &$counter,
        int &$protectCounter
    ): string {
        if ($text === '') {
            return '';
        }

        $lines = preg_split("/(\r\n|\n|\r)/", $text);
        if ($lines === false) {
            return $this->addSegment($field, $text, $segments, $protected, $counter, $protectCounter);
        }

        $inFence = false;
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $inFence = !$inFence;
                $out[] = $line;
                continue;
            }

            if ($inFence) {
                $out[] = $this->prepareCodeCommentLine($field, $line, $segments, $protected, $counter, $protectCounter);
                continue;
            }

            if (trim($line) === '' || preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/', $line)) {
                $out[] = $line;
                continue;
            }

            $out[] = $this->addSegment($field, $line, $segments, $protected, $counter, $protectCounter);
        }

        return implode("\n", $out);
    }

    private function prepareCodeCommentLine(
        string $field,
        string $line,
        array &$segments,
        array &$protected,
        int &$counter,
        int &$protectCounter
    ): string {
        $patterns = [
            '/^(\s*(?:\/\/|#|--)\s*)(.+)$/',
            '/^(\s*\/\*\s*)(.+?)(\s*\*\/\s*)$/',
            '/^(\s*\*\s?)(.+)$/',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $line, $matches)) {
                continue;
            }
            $prefix = (string)($matches[1] ?? '');
            $body = (string)($matches[2] ?? '');
            $suffix = (string)($matches[3] ?? '');
            if (trim($body) === '') {
                return $line;
            }
            return $prefix . $this->addSegment($field . '_comment', $body, $segments, $protected, $counter, $protectCounter) . $suffix;
        }

        return $line;
    }

    private function addSegment(
        string $field,
        string $text,
        array &$segments,
        array &$protected,
        int &$counter,
        int &$protectCounter
    ): string {
        $id = $field . '_' . $counter++;
        $segments[] = [
            'id' => $id,
            'text' => $this->protectInlineTokens($text, $protected, $protectCounter),
        ];

        return self::MARKER_PREFIX . $id . '__';
    }

    private function protectInlineTokens(string $text, array &$protected, int &$protectCounter): string
    {
        $patterns = [
            '/`[^`\n]+`/',
            '#https?://[^\s\]\)<>"]+#i',
            '/\b[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:::[A-Za-z_][A-Za-z0-9_]*)+\b/',
            '/\b[A-Za-z_][A-Za-z0-9_]*\(\)/',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, function (array $match) use (&$protected, &$protectCounter) {
                $token = self::PROTECT_PREFIX . $protectCounter++ . '__';
                $protected[$token] = $match[0];
                return $token;
            }, $text) ?? $text;
        }

        return $text;
    }
}
