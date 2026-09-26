<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * Compile-time slot boundary comments for string-based slot fill/extract.
 *
 * DEV final HTML keeps markers; PROD strips them before response/FPC.
 * wave8-8s3: also strip reactive data-wslot* attributes on published storefront
 * outbound so LayoutSlotRenderer can early-return (bake/widget debris must not
 * re-arm fill).
 */
final class SlotBoundaryMarkers
{
    public const OPEN_PREFIX = '<!--@weline-slot:';
    public const CLOSE_PREFIX = '<!--@/weline-slot:';

    public static function open(string $slotId): string
    {
        $slotId = self::normalizeId($slotId);

        return self::OPEN_PREFIX . $slotId . '-->';
    }

    public static function close(string $slotId): string
    {
        $slotId = self::normalizeId($slotId);

        return self::CLOSE_PREFIX . $slotId . '-->';
    }

    public static function hasMarkers(string $html): bool
    {
        return $html !== '' && str_contains($html, self::OPEN_PREFIX);
    }

    /**
     * Remove boundary comments from final HTML (PROD / published outbound).
     * wave8-8s3: also strip reactive data-wslot* / widget-slot-area attributes.
     */
    public static function strip(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        if (self::hasMarkers($html)) {
            $stripped = (string) preg_replace(
                '/<!--@(?:\/)?weline-slot:[\w.-]+-->\s*/',
                '',
                $html,
            );
            $html = $stripped === '' ? $html : $stripped;
        }

        return self::stripReactiveSlotAttributes($html);
    }

    /**
     * Remove editor/reactive slot attributes (data-wslot / data-wslot-* / widget-slot-area).
     * required-default-always-present: before dropping data-wslot, promote its id onto
     * data-slot-id and ensure theme-published-slot class so required overlay still has
     * a destination after outbound sanitize (forbid naked declaration slots).
     */
    public static function stripReactiveSlotAttributes(string $html): string
    {
        if ($html === ''
            || (!\str_contains($html, 'data-wslot') && !\str_contains($html, 'widget-slot-area'))
        ) {
            return $html;
        }

        // Promote primary data-wslot="id" → data-slot-id + theme-published-slot first.
        // Also promote data-wslot-layout → data-slot-layout so layout-scoped merges
        // (mini-cart footer-extras) survive reactive strip on published outbound.
        if (\str_contains($html, 'data-wslot')) {
            $promoted = \preg_replace_callback(
                '/<([a-z][a-z0-9:-]*)\b([^>]*?\bdata-wslot\s*=\s*(["\'])([^"\']+)\3[^>]*)>/i',
                static function (array $m): string {
                    $tag = $m[1];
                    $attrs = $m[2];
                    $slotId = \trim((string)$m[4]);
                    if ($slotId === '' || \preg_match('/^[\w.-]+$/', $slotId) !== 1) {
                        return $m[0];
                    }
                    if (!\preg_match('/\bdata-slot-id\s*=/i', $attrs)) {
                        $attrs .= ' data-slot-id="' . $slotId . '"';
                    }
                    if (\preg_match('/\bdata-wslot-layout\s*=\s*(["\'])([^"\']*)\1/i', $attrs, $layoutMatch) === 1) {
                        $layoutType = \trim((string)$layoutMatch[2]);
                        if ($layoutType !== ''
                            && \preg_match('/^[\w.-]+$/', $layoutType) === 1
                            && !\preg_match('/\bdata-slot-layout\s*=/i', $attrs)
                        ) {
                            $attrs .= ' data-slot-layout="' . $layoutType . '"';
                        }
                    }
                    if (\preg_match('/\bdata-wslot-multiple\s*=\s*(["\']?)true\1/i', $attrs) === 1
                        && !\preg_match('/\bdata-slot-multiple\s*=/i', $attrs)
                    ) {
                        $attrs .= ' data-slot-multiple="true"';
                    }
                    if (\preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $attrs, $classMatch) === 1) {
                        $classes = \preg_split('/\s+/', \trim((string)$classMatch[2])) ?: [];
                        if (!\in_array('theme-published-slot', $classes, true)) {
                            $classes[] = 'theme-published-slot';
                            $attrs = \preg_replace(
                                '/\bclass\s*=\s*(["\'])(.*?)\1/i',
                                'class="' . \implode(' ', $classes) . '"',
                                $attrs,
                                1,
                            ) ?? $attrs;
                        }
                    } else {
                        $attrs .= ' class="theme-published-slot"';
                    }

                    return '<' . $tag . $attrs . '>';
                },
                $html,
            );
            if (\is_string($promoted)) {
                $html = $promoted;
            }
        }

        $patterns = [
            // quoted: data-wslot="…" / data-wslot-name='…'
            '/\s+\bdata-wslot(?:-[a-z0-9_-]+)?\s*=\s*(["\'])(?:\\\\.|(?!\1).)*\1/i',
            '/\s+\bwidget-slot-area\s*=\s*(["\'])(?:\\\\.|(?!\1).)*\1/i',
            // bare / unquoted fallback
            '/\s+\bdata-wslot(?:-[a-z0-9_-]+)?\s*=\s*[^\s>]+/i',
            '/\s+\bwidget-slot-area\s*=\s*[^\s>]+/i',
        ];
        $stripped = $html;
        foreach ($patterns as $pattern) {
            $next = \preg_replace($pattern, '', $stripped);
            if (\is_string($next)) {
                $stripped = $next;
            }
        }

        return $stripped;
    }

    private static function normalizeId(string $slotId): string
    {
        $slotId = trim($slotId);
        if ($slotId === '' || !preg_match('/^[\w.-]+$/', $slotId)) {
            throw new \InvalidArgumentException('Invalid slot id for boundary marker: ' . $slotId);
        }

        return $slotId;
    }
}
