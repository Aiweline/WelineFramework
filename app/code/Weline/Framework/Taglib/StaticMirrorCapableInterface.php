<?php

declare(strict_types=1);

namespace Weline\Framework\Taglib;

/**
 * Optional capability: when attributes/content are compile-time literals,
 * return final HTML/text to bake into the compiled template (lang-style).
 *
 * Returning null means "not mirrored" — the normal {@see TaglibInterface::callback()}
 * path (often emitting `<?php … ?>`) remains authoritative.
 *
 * Implementors usually call this from inside `callback()` before emitting PHP.
 */
interface StaticMirrorCapableInterface
{
    /**
     * @param array<int|string, mixed> $tagData Framework tag_data tuple
     * @param array<string, mixed> $attributes Parsed attributes
     * @return string|null Final markup, or null to fall through
     */
    public static function tryStaticMirror(string $tagKey, array $tagData, array $attributes): ?string;
}
