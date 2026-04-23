<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteAgentStreamCodecService
{
    /**
     * Prevent unbounded decoded-buffer growth during incremental JSON stream decoding.
     */
    private const STREAM_DECODED_GC_THRESHOLD = 131072;

    /**
     * @return list<string>
     */
    public function chunkStringForSse(string $content, int $chunkLength = 220): array
    {
        $content = \str_replace(["\r\n", "\r"], "\n", $content);
        if ($content === '') {
            return [];
        }

        $chunkLength = \max(1, $chunkLength);
        $chunks = [];
        $length = \mb_strlen($content);
        for ($offset = 0; $offset < $length; $offset += $chunkLength) {
            $chunks[] = (string)\mb_substr($content, $offset, $chunkLength);
        }

        return $chunks;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function extractPlanMarkdownJsonStreamDelta(string $buffer, array &$state): string
    {
        if (($state['stage'] ?? '') === 'done') {
            return '';
        }
        if (($state['stage'] ?? '') === 'seek_key') {
            if (!\preg_match('/"markdown"\s*:\s*"/u', $buffer, $m, \PREG_OFFSET_CAPTURE)) {
                return '';
            }
            $state['stage'] = 'decode';
            $state['i'] = (int)$m[0][1] + \strlen($m[0][0]);
        }
        if (($state['stage'] ?? '') !== 'decode') {
            return '';
        }

        $i = (int)($state['i'] ?? 0);
        $decoded = (string)($state['decoded'] ?? '');
        $len = \strlen($buffer);
        while ($i < $len) {
            $ch = $buffer[$i];
            if ($ch === '"') {
                $i++;
                $state['markdown_string_closed'] = true;
                $state['stage'] = 'done';
                break;
            }
            if ($ch === '\\') {
                if ($i + 1 >= $len) {
                    break;
                }
                $esc = $buffer[$i + 1];
                if ($esc === 'u') {
                    if ($i + 6 > $len) {
                        break;
                    }
                    $hex = \substr($buffer, $i + 2, 4);
                    if (!\ctype_xdigit($hex)) {
                        $decoded .= '\\u';
                        $i += 2;
                        continue;
                    }
                    $decoded .= $this->unicodeCodePointToUtf8((int)\hexdec($hex));
                    $i += 6;
                    continue;
                }
                $i += 2;
                $decoded .= match ($esc) {
                    '"' => '"',
                    '\\' => '\\',
                    '/' => '/',
                    'b' => "\x08",
                    'f' => "\f",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    default => $esc,
                };
                continue;
            }
            $decoded .= $ch;
            $i++;
        }

        $state['i'] = $i;
        $state['decoded'] = $decoded;
        $emitted = (int)($state['emitted'] ?? 0);
        if ($emitted >= \strlen($decoded)) {
            return '';
        }

        $candidate = \substr($decoded, $emitted);
        $safeDelta = $this->clipIncompleteUtf8Suffix($candidate);
        if ($safeDelta !== '') {
            $state['emitted'] = $emitted + \strlen($safeDelta);
        }

        $out = $safeDelta;
        if (($state['markdown_string_closed'] ?? false) === true) {
            $emittedAfter = (int)($state['emitted'] ?? 0);
            if ($emittedAfter < \strlen($decoded)) {
                $tail = \substr($decoded, $emittedAfter);
                $state['emitted'] = \strlen($decoded);
                $out .= $tail;
            }
        }

        $emittedNow = (int)($state['emitted'] ?? 0);
        if ($emittedNow >= self::STREAM_DECODED_GC_THRESHOLD) {
            $decodedLen = \strlen($decoded);
            if ($emittedNow >= $decodedLen) {
                $state['decoded'] = '';
                $state['emitted'] = 0;
            } else {
                $state['decoded'] = (string)\substr($decoded, $emittedNow);
                $state['emitted'] = 0;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function splitMarkdownBlocks(string $markdown): array
    {
        $text = \trim($markdown);
        if ($text === '') {
            return [];
        }

        $blocks = \preg_split('/\n\s*\n(?=##\s|###\s|####\s|#\s)/u', $text) ?: [];
        if ($blocks === []) {
            $blocks = [$text];
        }

        return \array_values(\array_filter(\array_map(static fn(string $block): string => \trim($block), $blocks), static fn(string $block): bool => $block !== ''));
    }

    private function clipIncompleteUtf8Suffix(string $text): string
    {
        if ($text === '' || \mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $len = \strlen($text);
        for ($cut = 1; $cut < 5 && $cut < $len; $cut++) {
            $try = \substr($text, 0, $len - $cut);
            if ($try !== '' && \mb_check_encoding($try, 'UTF-8')) {
                return $try;
            }
        }

        return '';
    }

    private function unicodeCodePointToUtf8(int $cp): string
    {
        if ($cp < 0x80) {
            return \chr($cp);
        }
        if ($cp < 0x800) {
            return \chr(0xC0 | ($cp >> 6)) . \chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return \chr(0xE0 | ($cp >> 12))
                . \chr(0x80 | (($cp >> 6) & 0x3F))
                . \chr(0x80 | ($cp & 0x3F));
        }
        if ($cp <= 0x10FFFF) {
            return \chr(0xF0 | ($cp >> 18))
                . \chr(0x80 | (($cp >> 12) & 0x3F))
                . \chr(0x80 | (($cp >> 6) & 0x3F))
                . \chr(0x80 | ($cp & 0x3F));
        }

        return '';
    }
}
