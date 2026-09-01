<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * Park script/style (and optional widget-wrapper inners) before string slot scans.
 */
final class SlotHtmlOpaqueParker
{
    /** @var array<string, string> */
    private array $tokens = [];

    public function reset(): void
    {
        $this->tokens = [];
    }

    /**
     * @return array<string, string>
     */
    public function tokens(): array
    {
        return $this->tokens;
    }

    public function park(string $html): string
    {
        $this->reset();
        if ($html === '') {
            return $html;
        }

        $html = $this->parkOpaqueBlocks($html);
        return $this->parkWidgetWrapperInners($html);
    }

    public function restore(string $html): string
    {
        if ($this->tokens === [] || $html === '') {
            return $html;
        }

        for ($i = 0; $i < 8; $i++) {
            $next = strtr($html, $this->tokens);
            if ($next === $html) {
                break;
            }
            $html = $next;
        }

        return $html;
    }

    private function parkOpaqueBlocks(string $html): string
    {
        if (!str_contains($html, '<script') && !str_contains($html, '<style')) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/<(script|style)\b([^>]*)>(.*?)<\/\1>/is',
            function (array $match): string {
                $token = '<!--WELINE_SLOT_OPAQUE_' . count($this->tokens) . '_' . bin2hex(random_bytes(4)) . '-->';
                $this->tokens[$token] = '<' . $match[1] . $match[2] . '>' . $match[3] . '</' . $match[1] . '>';

                return $token;
            },
            $html,
        );
    }

    private function parkWidgetWrapperInners(string $html): string
    {
        if (!str_contains($html, 'widget-wrapper')) {
            return $html;
        }

        $length = strlen($html);
        $offset = 0;
        $out = '';

        while ($offset < $length) {
            $open = $this->findNextWidgetWrapperOpen($html, $offset);
            if ($open === null) {
                $out .= substr($html, $offset);
                break;
            }

            $openStart = $open['start'];
            $openTag = $open['tag'];
            $openEnd = $open['end'];
            $out .= substr($html, $offset, $openStart - $offset);

            $innerEnd = $this->findMatchingDivClose($html, $openEnd);
            if ($innerEnd === null) {
                $out .= $openTag;
                $offset = $openEnd;
                continue;
            }

            $inner = substr($html, $openEnd, $innerEnd - $openEnd);
            $token = '<!--WELINE_SLOT_OPAQUE_WIDGET_' . count($this->tokens) . '_' . bin2hex(random_bytes(4)) . '-->';
            $this->tokens[$token] = $inner;
            $out .= $openTag . $token . '</div>';
            $offset = $innerEnd + 6;
        }

        return $out;
    }

    /**
     * @return array{start:int,end:int,tag:string}|null
     */
    private function findNextWidgetWrapperOpen(string $html, int $offset): ?array
    {
        $length = strlen($html);
        $pos = max(0, $offset);
        while ($pos < $length) {
            if (preg_match('/<div\b/i', $html, $match, PREG_OFFSET_CAPTURE, $pos) !== 1) {
                return null;
            }
            $start = (int) $match[0][1];
            $end = $this->findHtmlTagClose($html, $start + 4);
            if ($end === null) {
                return null;
            }
            $tag = substr($html, $start, $end - $start);
            if (preg_match('/\bwidget-wrapper\b/i', $tag) === 1) {
                return ['start' => $start, 'end' => $end, 'tag' => $tag];
            }
            $pos = $end;
        }

        return null;
    }

    private function findHtmlTagClose(string $html, int $from): ?int
    {
        $length = strlen($html);
        $quote = null;
        for ($i = max(0, $from); $i < $length; $i++) {
            $ch = $html[$i];
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                continue;
            }
            if ($ch === '>') {
                return $i + 1;
            }
        }

        return null;
    }

    private function findMatchingDivClose(string $html, int $openEnd): ?int
    {
        $length = strlen($html);
        $depth = 1;
        $cursor = $openEnd;
        while ($cursor < $length && $depth > 0) {
            $nextOpen = stripos($html, '<div', $cursor);
            $nextClose = stripos($html, '</div>', $cursor);
            if ($nextClose === false) {
                return null;
            }
            if ($nextOpen !== false && $nextOpen < $nextClose && preg_match('/<div\b/i', substr($html, $nextOpen, 10)) === 1) {
                $depth++;
                $cursor = $nextOpen + 4;
                continue;
            }
            $depth--;
            if ($depth === 0) {
                return $nextClose;
            }
            $cursor = $nextClose + 6;
        }

        return null;
    }
}
