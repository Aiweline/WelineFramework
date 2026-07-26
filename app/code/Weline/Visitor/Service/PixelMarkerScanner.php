<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

/**
 * 静态 HTML 标记扫描（v1：服务端 HTML，不执行 JS）。
 */
class PixelMarkerScanner
{
    private const ATTR_NAMES = [
        'data-pixel-event',
        'data-visitor-event',
        'data-cta-event',
        'data-cta',
        'data-ga-event',
    ];

    /**
     * @return array{
     *   classes: list<string>,
     *   attrs: list<string>,
     *   events: list<string>,
     *   attr_presence: list<string>
     * }
     */
    public function scanHtml(string $html): array
    {
        $classes = [];
        $attrs = [];
        $events = [];
        $attrPresence = [];

        // Strip non-DOM payloads first: inline visitorTrackingConfig embeds the
        // full event dictionary (weline-pixel::add_to_cart / data-pixel-event=...),
        // which must not count as real page markers.
        $html = $this->stripNonMarkerPayload($html);

        if ($html === '') {
            return [
                'classes' => [],
                'attrs' => [],
                'events' => [],
                'attr_presence' => [],
            ];
        }

        if (\preg_match_all('/\bweline-pixel::([a-zA-Z0-9_:-]+)\b/', $html, $classMatches)) {
            foreach ($classMatches[1] as $token) {
                $token = \trim((string)$token);
                if ($token === '' || \str_contains($token, ':value')) {
                    continue;
                }
                $event = $this->normalizeEventName($token);
                if ($event === '') {
                    continue;
                }
                $classes[] = 'weline-pixel::' . $event;
                $events[] = $event;
            }
        }

        foreach (self::ATTR_NAMES as $attrName) {
            $pattern = '/' . \preg_quote($attrName, '/') . '\s*=\s*(["\'])(.*?)\1/i';
            if (\preg_match_all($pattern, $html, $attrMatches)) {
                $attrPresence[] = $attrName;
                foreach ($attrMatches[2] as $value) {
                    $value = \trim((string)$value);
                    if ($value === '') {
                        $attrs[] = $attrName;
                        continue;
                    }
                    $event = $this->normalizeEventName($value);
                    $attrs[] = $attrName . '=' . ($event !== '' ? $event : $value);
                    if ($event !== '') {
                        $events[] = $event;
                    }
                }
            } elseif (\preg_match('/\b' . \preg_quote($attrName, '/') . '\b/i', $html)) {
                // boolean-like presence without value
                $attrPresence[] = $attrName;
                $attrs[] = $attrName;
            }
        }

        $classes = \array_values(\array_unique($classes));
        $attrs = \array_values(\array_unique($attrs));
        $events = \array_values(\array_unique($events));
        $attrPresence = \array_values(\array_unique($attrPresence));

        return [
            'classes' => $classes,
            'attrs' => $attrs,
            'events' => $events,
            'attr_presence' => $attrPresence,
        ];
    }

    /**
     * Whether dictionary markers are satisfied by a scan result.
     *
     * @param array<string, mixed> $markers
     * @param array<string, mixed> $scan
     */
    public function matchesMarkers(array $markers, array $scan): bool
    {
        $wantClasses = \is_array($markers['classes'] ?? null) ? $markers['classes'] : [];
        $wantAttrs = \is_array($markers['attrs'] ?? null) ? $markers['attrs'] : [];
        $foundClasses = \is_array($scan['classes'] ?? null) ? $scan['classes'] : [];
        $foundAttrs = \is_array($scan['attrs'] ?? null) ? $scan['attrs'] : [];
        $foundPresence = \is_array($scan['attr_presence'] ?? null) ? $scan['attr_presence'] : [];
        $foundEvents = \is_array($scan['events'] ?? null) ? $scan['events'] : [];

        foreach ($wantClasses as $class) {
            $class = \strtolower(\trim((string)$class));
            foreach ($foundClasses as $found) {
                if (\strtolower((string)$found) === $class) {
                    return true;
                }
            }
            if (\str_starts_with($class, 'weline-pixel::')) {
                $event = $this->normalizeEventName(\substr($class, \strlen('weline-pixel::')));
                if ($event !== '' && \in_array($event, $foundEvents, true)) {
                    return true;
                }
            }
        }

        foreach ($wantAttrs as $attr) {
            $attr = \trim((string)$attr);
            if ($attr === '') {
                continue;
            }
            if (!\str_contains($attr, '=')) {
                if (\in_array($attr, $foundPresence, true) || \in_array($attr, $foundAttrs, true)) {
                    return true;
                }
                continue;
            }
            [$name, $value] = \array_pad(\explode('=', $attr, 2), 2, '');
            $name = \trim($name);
            $value = $this->normalizeEventName(\trim($value));
            $needle = $name . '=' . $value;
            foreach ($foundAttrs as $found) {
                if (\strtolower((string)$found) === \strtolower($needle)) {
                    return true;
                }
            }
            if ($value !== '' && \in_array($value, $foundEvents, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove script/style/noscript/template payloads so dictionary JSON and
     * bootstrap JS cannot be mistaken for DOM markers.
     */
    private function stripNonMarkerPayload(string $html): string
    {
        $stripped = \preg_replace(
            '#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is',
            ' ',
            $html
        );
        if (!\is_string($stripped)) {
            return $html;
        }

        // HTML comments may also embed docs/examples with marker strings.
        $stripped = \preg_replace('/<!--.*?-->/s', ' ', $stripped);
        return \is_string($stripped) ? $stripped : $html;
    }

    private function normalizeEventName(string $name): string
    {
        $name = \strtolower(\trim($name));
        $name = \str_replace('-', '_', $name);
        $name = (string)\preg_replace('/[^a-z0-9_]/', '', $name);
        return \substr($name, 0, 64);
    }
}
