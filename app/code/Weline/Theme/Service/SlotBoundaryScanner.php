<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * String scan for compile-time slot boundary comments and wrapper inners.
 */
final class SlotBoundaryScanner
{
    private const OPEN_PATTERN = '/<!--@weline-slot:([\w.-]+)-->/';
    private const CLOSE_PATTERN = '/<!--@\/weline-slot:([\w.-]+)-->/';

    /**
     * @return list<array{
     *     id: string,
     *     depth: int,
     *     region_start: int,
     *     region_end: int,
     *     wrapper_open_start: int,
     *     wrapper_open_end: int,
     *     inner_start: int,
     *     inner_end: int,
     *     wrapper_close_end: int
     * }>
     */
    public function enumerateRegions(string $html): array
    {
        if ($html === '' || !SlotBoundaryMarkers::hasMarkers($html)) {
            return [];
        }

        $regions = [];
        if (preg_match_all(self::OPEN_PATTERN, $html, $openMatches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        foreach ($openMatches[1] as $index => $idMatch) {
            $slotId = (string) $idMatch[0];
            $openStart = (int) $openMatches[0][$index][1];
            $openEnd = $openStart + strlen($openMatches[0][$index][0]);
            $closeEnd = $this->findMatchingCloseEnd($html, $openEnd, $slotId);
            if ($closeEnd === null) {
                continue;
            }

            $closeStart = $this->findCloseCommentStart($html, $closeEnd, $slotId);
            if ($closeStart === null) {
                continue;
            }

            $depth = $this->countOpenMarkersBefore($html, $openStart);
            $wrapperBounds = $this->findWrapperBounds(substr($html, $openEnd, $closeStart - $openEnd), $slotId);
            if ($wrapperBounds === null) {
                continue;
            }

            $regions[] = [
                'id' => $slotId,
                'depth' => $depth,
                'region_start' => $openStart,
                'region_end' => $closeEnd,
                'wrapper_open_start' => $openEnd + $wrapperBounds['open_start'],
                'wrapper_open_end' => $openEnd + $wrapperBounds['open_end'],
                'inner_start' => $openEnd + $wrapperBounds['inner_start'],
                'inner_end' => $openEnd + $wrapperBounds['inner_end'],
                'wrapper_close_end' => $openEnd + $wrapperBounds['close_end'],
            ];
        }

        usort(
            $regions,
            static fn(array $a, array $b): int => ($b['depth'] <=> $a['depth'])
                ?: ($a['region_start'] <=> $b['region_start']),
        );

        return $regions;
    }

    /**
     * Extract wrapper inner HTML by data-wslot (preview stubs without boundary comments).
     */
    public function extractWrapperInnerBySlotId(string $html, string $slotId): ?string
    {
        $bounds = $this->findSlotElementBounds($html, $slotId);
        if ($bounds === null) {
            return null;
        }

        [$openEnd, $closeStart] = $bounds;

        return substr($html, $openEnd, $closeStart - $openEnd);
    }

    /**
     * Extract slot inner via boundary region when present, else wrapper inner.
     */
    public function extractSlotInner(string $html, string $slotId): ?string
    {
        foreach ($this->enumerateRegions($html) as $region) {
            if ($region['id'] === $slotId) {
                return substr($html, $region['inner_start'], $region['inner_end'] - $region['inner_start']);
            }
        }

        return $this->extractWrapperInnerBySlotId($html, $slotId);
    }

    public function replaceWrapperInner(string $html, array $region, string $newInner): string
    {
        $before = substr($html, 0, $region['inner_start']);
        $after = substr($html, $region['inner_end']);

        return $before . $newInner . $after;
    }

    /**
     * @return array{0:int,1:int}|null [openTagEnd, closeTagStart] absolute offsets
     */
    public function findSlotElementBounds(string $html, string $slotId): ?array
    {
        $quotedSlotId = preg_quote($slotId, '/');
        $pattern = '/<([a-z][a-z0-9:-]*)(?=[^>]*\b(?:data-wslot|data-slot-id|data-preview-slot)\s*=\s*(["\'])'
            . $quotedSlotId . '\2)[^>]*>/i';
        if (!preg_match($pattern, $html, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $openTag = $matches[0][0];
        $start = (int) $matches[0][1];
        $tagName = (string) $matches[1][0];
        $openEnd = $start + strlen($openTag);
        $closeEnd = $this->findElementEndByTag($html, $tagName, $openEnd);
        if ($closeEnd === null) {
            return null;
        }

        $closeTagLen = strlen('</' . $tagName . '>');
        $closeStart = $closeEnd - $closeTagLen;

        return [$openEnd, $closeStart];
    }

    /**
     * @return array{open_start:int,open_end:int,inner_start:int,inner_end:int,close_end:int}|null
     */
    private function findWrapperBounds(string $fragment, string $slotId): ?array
    {
        $bounds = $this->findSlotElementBounds($fragment, $slotId);
        if ($bounds === null) {
            return null;
        }

        [$openEnd, $closeStart] = $bounds;
        $openStart = $this->findSlotOpenTagStart($fragment, $slotId);
        if ($openStart === null) {
            return null;
        }

        $closeEnd = $this->findElementEndByTag($fragment, $this->slotTagName($fragment, $slotId) ?? 'div', $openEnd);
        if ($closeEnd === null) {
            return null;
        }

        return [
            'open_start' => $openStart,
            'open_end' => $openEnd,
            'inner_start' => $openEnd,
            'inner_end' => $closeStart,
            'close_end' => $closeEnd,
        ];
    }

    private function findSlotOpenTagStart(string $html, string $slotId): ?int
    {
        $quotedSlotId = preg_quote($slotId, '/');
        $pattern = '/<([a-z][a-z0-9:-]*)(?=[^>]*\b(?:data-wslot|data-slot-id|data-preview-slot)\s*=\s*(["\'])'
            . $quotedSlotId . '\2)[^>]*>/i';
        if (!preg_match($pattern, $html, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return (int) $matches[0][1];
    }

    private function slotTagName(string $html, string $slotId): ?string
    {
        $quotedSlotId = preg_quote($slotId, '/');
        $pattern = '/<([a-z][a-z0-9:-]*)(?=[^>]*\b(?:data-wslot|data-slot-id|data-preview-slot)\s*=\s*(["\'])'
            . $quotedSlotId . '\2)[^>]*>/i';
        if (!preg_match($pattern, $html, $matches)) {
            return null;
        }

        return (string) $matches[1];
    }

    private function findMatchingCloseEnd(string $html, int $from, string $slotId): ?int
    {
        $offset = $from;
        $length = strlen($html);
        $depth = 1;

        while ($offset < $length && $depth > 0) {
            $nextOpen = preg_match(self::OPEN_PATTERN, $html, $openMatch, PREG_OFFSET_CAPTURE, $offset) === 1
                ? (int) $openMatch[0][1]
                : null;
            $nextClose = preg_match(self::CLOSE_PATTERN, $html, $closeMatch, PREG_OFFSET_CAPTURE, $offset) === 1
                ? (int) $closeMatch[0][1]
                : null;

            if ($nextClose === null) {
                return null;
            }

            if ($nextOpen !== null && $nextOpen < $nextClose) {
                $depth++;
                $offset = $nextOpen + strlen($openMatch[0][0]);
                continue;
            }

            $depth--;
            if ($depth === 0) {
                if ((string) ($closeMatch[1][0] ?? '') !== $slotId) {
                    return null;
                }

                return $nextClose + strlen($closeMatch[0][0]);
            }

            $offset = $nextClose + strlen($closeMatch[0][0]);
        }

        return null;
    }

    private function findCloseCommentStart(string $html, int $closeEnd, string $slotId): ?int
    {
        $marker = SlotBoundaryMarkers::close($slotId);
        $start = strrpos(substr($html, 0, $closeEnd), $marker);
        if ($start === false) {
            return null;
        }

        return (int) $start;
    }

    private function countOpenMarkersBefore(string $html, int $position): int
    {
        $prefix = substr($html, 0, $position);
        $opens = preg_match_all(self::OPEN_PATTERN, $prefix) ?: 0;
        $closes = preg_match_all(self::CLOSE_PATTERN, $prefix) ?: 0;

        return max(0, $opens - $closes);
    }

    private function findElementEndByTag(string $html, string $tagName, int $offset): ?int
    {
        $tagName = preg_quote($tagName, '/');
        $pattern = '/<\/?' . $tagName . '\b[^>]*>/i';
        $depth = 1;

        while (preg_match($pattern, $html, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $tag = $matches[0][0];
            $position = (int) $matches[0][1];
            $offset = $position + strlen($tag);

            if (str_starts_with($tag, '</')) {
                $depth--;
                if ($depth === 0) {
                    return $offset;
                }
                continue;
            }

            if (!str_ends_with(rtrim($tag), '/>')) {
                $depth++;
            }
        }

        return null;
    }
}
