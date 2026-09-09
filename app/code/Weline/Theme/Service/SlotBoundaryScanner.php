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
    private const MARKER_PATTERN = '/<!--@(\/?)weline-slot:([\w.-]+)-->/';

    /**
     * @param array<string, true>|null $targetSlotIds Optional fill targets. All markers
     * are still paired to preserve depth. Duplicate or malformed marker streams
     * retain the complete legacy region set so batch ordering stays unchanged.
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
    public function enumerateRegions(string $html, ?string $onlySlotId = null, ?array $targetSlotIds = null): array
    {
        if ($html === '' || !SlotBoundaryMarkers::hasMarkers($html)) {
            return [];
        }

        $markerMatches = [];
        if (preg_match_all(self::MARKER_PATTERN, $html, $markerMatches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        /** @var list<array{id:string,open_start:int,open_end:int,depth:int}> $stack */
        $stack = [];
        /** @var list<array{id:string,open_start:int,open_end:int,depth:int,close_start:int,close_end:int}> $paired */
        $paired = [];
        $malformed = false;
        foreach ($markerMatches[2] as $index => $idMatch) {
            $slotId = (string) $idMatch[0];
            $markerStart = (int) $markerMatches[0][$index][1];
            $markerEnd = $markerStart + strlen((string) $markerMatches[0][$index][0]);
            $isClose = (string)($markerMatches[1][$index][0] ?? '') === '/';
            if (!$isClose) {
                $stack[] = [
                    'id' => $slotId,
                    'open_start' => $markerStart,
                    'open_end' => $markerEnd,
                    'depth' => count($stack),
                ];
                continue;
            }

            $open = $stack[count($stack) - 1] ?? null;
            if ($open === null || $open['id'] !== $slotId) {
                // Preserve the legacy tolerant behavior for malformed marker
                // streams. Production output is well-formed, while previews may
                // be edited mid-render and should keep the old recovery path.
                $malformed = true;
                break;
            }
            array_pop($stack);
            $paired[] = $open + [
                'close_start' => $markerStart,
                'close_end' => $markerEnd,
            ];
        }

        if ($malformed) {
            return $this->enumerateRegionsLegacy($html, $onlySlotId);
        }

        if ($targetSlotIds !== null) {
            $seen = [];
            foreach ($paired as $pair) {
                if (isset($seen[$pair['id']])) {
                    // Duplicate IDs anywhere in the page historically disable
                    // sibling batching, even if that ID has no layout widgets.
                    // Hydrate all regions so the caller can validate duplicates.
                    $targetSlotIds = null;
                    break;
                }
                $seen[$pair['id']] = true;
            }
        }

        $regions = [];
        foreach ($paired as $pair) {
            $slotId = $pair['id'];
            if ($onlySlotId !== null && $slotId !== $onlySlotId) {
                continue;
            }

            if ($targetSlotIds !== null && !isset($targetSlotIds[$slotId])) {
                continue;
            }

            $wrapperBounds = $this->findSlotWrapperBounds(
                substr($html, $pair['open_end'], $pair['close_start'] - $pair['open_end']),
                $slotId,
            );
            if ($wrapperBounds === null) {
                continue;
            }

            $regions[] = [
                'id' => $slotId,
                'depth' => $pair['depth'],
                'region_start' => $pair['open_start'],
                'region_end' => $pair['close_end'],
                'wrapper_open_start' => $pair['open_end'] + $wrapperBounds['open_start'],
                'wrapper_open_end' => $pair['open_end'] + $wrapperBounds['open_end'],
                'inner_start' => $pair['open_end'] + $wrapperBounds['inner_start'],
                'inner_end' => $pair['open_end'] + $wrapperBounds['inner_end'],
                'wrapper_close_end' => $pair['open_end'] + $wrapperBounds['close_end'],
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
     * Legacy marker pairing retained as a bounded recovery path for malformed
     * preview HTML. Well-formed production pages use the single-pass parser
     * above, avoiding a full-prefix regex scan for every marker.
     *
     * @return list<array<string, int|string>>
     */
    private function enumerateRegionsLegacy(string $html, ?string $onlySlotId = null): array
    {
        $regions = [];
        if (preg_match_all(self::OPEN_PATTERN, $html, $openMatches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        foreach ($openMatches[1] as $index => $idMatch) {
            $slotId = (string) $idMatch[0];
            if ($onlySlotId !== null && $slotId !== $onlySlotId) {
                continue;
            }
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
            $wrapperBounds = $this->findSlotWrapperBounds(substr($html, $openEnd, $closeStart - $openEnd), $slotId);
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
        // Preview extracts several slots from the same page. Scan the requested
        // region only instead of reparsing every widget for every slot.
        foreach ($this->enumerateRegions($html, $slotId) as $region) {
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
     * Replace disjoint wrapper inners while copying the source HTML once.
     *
     * Boundary batches are made of regions at the same nesting depth, so their
     * offsets refer to the same source string and cannot overlap. Keeping the
     * offsets in source order avoids copying the full page once per sibling.
     *
     * @param list<array{inner_start:int,inner_end:int,new_inner:string}> $replacements
     */
    public function replaceWrapperInners(string $html, array $replacements): string
    {
        if ($replacements === []) {
            return $html;
        }

        $ordered = array_values($replacements);
        usort(
            $ordered,
            static fn(array $a, array $b): int => (int)$a['inner_start'] <=> (int)$b['inner_start'],
        );

        $cursor = 0;
        $length = strlen($html);
        $result = '';
        foreach ($ordered as $replacement) {
            $start = (int)($replacement['inner_start'] ?? -1);
            $end = (int)($replacement['inner_end'] ?? -1);
            if ($start < $cursor || $end < $start || $end > $length) {
                return $html;
            }

            $result .= substr($html, $cursor, $start - $cursor);
            $result .= (string)($replacement['new_inner'] ?? '');
            $cursor = $end;
        }

        return $result . substr($html, $cursor);
    }

    /**
     * @return array{0:int,1:int}|null [openTagEnd, closeTagStart] absolute offsets
     */
    public function findSlotElementBounds(string $html, string $slotId): ?array
    {
        $bounds = $this->findSlotWrapperBounds($html, $slotId);
        return $bounds === null ? null : [$bounds['inner_start'], $bounds['inner_end']];
    }

    /**
     * @return array{open_start:int,open_end:int,inner_start:int,inner_end:int,close_end:int}|null
     */
    public function findSlotWrapperBounds(string $fragment, string $slotId): ?array
    {
        foreach ($this->scanTags($fragment) as $tag) {
            if ($tag['closing']) {
                continue;
            }
            foreach (['data-wslot', 'data-slot-id', 'data-preview-slot'] as $attribute) {
                if ($this->attributeValue($tag['html'], $attribute) === $slotId) {
                    $bounds = $this->findElementBounds($fragment, $tag['start']);
                    return $bounds === null ? null : [
                        'open_start' => $bounds['open_start'],
                        'open_end' => $bounds['open_end'],
                        'inner_start' => $bounds['open_end'],
                        'inner_end' => $bounds['close_start'],
                        'close_end' => $bounds['close_end'],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Yield real HTML tags with byte offsets; quoted attributes, comments and raw
     * text must never contribute element depth. No serialization changes HTML.
     *
     * @return \Generator<int,array{name:string,start:int,end:int,html:string,closing:bool,self_closing:bool}>
     */
    public function scanTags(string $html, int $offset = 0): \Generator
    {
        $length = strlen($html);
        while ($offset < $length && ($start = strpos($html, '<', $offset)) !== false) {
            if (substr($html, $start, 4) === '<!--') {
                $end = strpos($html, '-->', $start + 4);
                if ($end === false) {
                    return;
                }
                $offset = $end + 3;
                continue;
            }
            if (preg_match('/\G<(\/?)([a-z][a-z0-9:-]*)(?=[\s\/>])/i', $html, $match, 0, $start) !== 1) {
                $offset = $start + 1;
                continue;
            }
            $end = $this->findTagEnd($html, $start + strlen($match[0]));
            if ($end === null) {
                return;
            }
            $name = strtolower($match[2]);
            $closing = $match[1] === '/';
            $tag = substr($html, $start, $end - $start);
            $selfClosing = str_ends_with(rtrim(substr($tag, 0, -1)), '/')
                || in_array($name, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true);
            yield ['name' => $name, 'start' => $start, 'end' => $end, 'html' => $tag, 'closing' => $closing, 'self_closing' => $selfClosing];
            $offset = $end;
            if (!$closing && !$selfClosing && in_array($name, ['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes'], true)) {
                if (preg_match('/<\/' . preg_quote($name, '/') . '(?=[\s\/>])/i', $html, $close, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                    return;
                }
                $offset = (int)$close[0][1];
            }
        }
    }

    /** @return array{open_start:int,open_end:int,close_start:int,close_end:int}|null */
    public function findElementBounds(string $html, int $openStart): ?array
    {
        $opening = null;
        $depth = 0;
        foreach ($this->scanTags($html, $openStart) as $tag) {
            if ($opening === null) {
                if ($tag['start'] !== $openStart || $tag['closing']) {
                    return null;
                }
                $opening = $tag;
            }
            if ($tag['name'] !== $opening['name']) {
                continue;
            }
            $depth += $tag['closing'] ? -1 : ($tag['self_closing'] ? 0 : 1);
            if ($depth === 0) {
                return [
                    'open_start' => $openStart,
                    'open_end' => $opening['end'],
                    'close_start' => $tag['closing'] ? $tag['start'] : $tag['end'],
                    'close_end' => $tag['end'],
                ];
            }
        }

        return null;
    }

    public function attributeValue(string $openTag, string $name): ?string
    {
        // Attribute names are literal and case-insensitive; most tags do not have the requested one.
        if (stripos($openTag, $name) === false) {
            return null;
        }
        if (preg_match('/^<[a-z][a-z0-9:-]*/i', $openTag, $prefix) !== 1) {
            return null;
        }
        preg_match_all(
            '/([^\s\/=<>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/',
            substr($openTag, strlen($prefix[0]), -1),
            $attributes,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
        );
        foreach ($attributes as $attribute) {
            if (strcasecmp($attribute[1], $name) === 0) {
                return html_entity_decode($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return null;
    }

    private function findTagEnd(string $html, int $offset): ?int
    {
        $length = strlen($html);
        while ($offset < $length) {
            // Skip unquoted text in C, then skip an entire quoted attribute value.
            $offset += strcspn($html, "\"'>", $offset);
            if ($offset >= $length) {
                return null;
            }
            if ($html[$offset] === '>') {
                return $offset + 1;
            }
            $quoteEnd = strpos($html, $html[$offset], $offset + 1);
            if ($quoteEnd === false) {
                return null;
            }
            $offset = $quoteEnd + 1;
        }
        return null;
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

}
