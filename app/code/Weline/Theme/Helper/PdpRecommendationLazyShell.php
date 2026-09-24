<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\View\Template;

/**
 * Theme-side PDP recommendation lazy-shell contract (WS4).
 *
 * Product owns {@see \Weline\Product\Service\StorefrontPdpShelfDeferral} request gate.
 * Theme keeps slot presence + skeleton/hydrate markers; never strips required injections.
 *
 * Optional injection config key {@see CONFIG_KEY} documents intent; runtime deferral
 * follows Product Detail enableForRequest() when that class is present.
 */
final class PdpRecommendationLazyShell
{
    public const CONFIG_KEY = 'lazy_load';

    /**
     * @param Template|array<string, mixed> $source
     */
    public static function shouldDeferCards(Template|array $source): bool
    {
        $preview = false;
        $editor = false;
        $flag = null;

        if ($source instanceof Template) {
            $preview = (bool)($source->getData('preview_mode') ?? false);
            $editor = (bool)($source->getData('editor_mode') ?? false);
            $flag = $source->getData(self::CONFIG_KEY);
            if ($flag === null) {
                $flag = $source->getData('lazyLoad');
            }
        } else {
            $preview = !empty($source['preview_mode']);
            $editor = !empty($source['editor_mode']);
            $flag = $source[self::CONFIG_KEY] ?? ($source['lazyLoad'] ?? null);
        }

        if ($preview || $editor) {
            return false;
        }

        if (\class_exists(\Weline\Product\Service\StorefrontPdpShelfDeferral::class)) {
            return \Weline\Product\Service\StorefrontPdpShelfDeferral::shouldDeferCardAssembly(false);
        }

        if ($flag === null) {
            return false;
        }

        if (\is_bool($flag)) {
            return $flag;
        }
        if (\is_int($flag) || \is_float($flag)) {
            return ((int)$flag) !== 0;
        }

        $raw = \strtolower(\trim((string)$flag));
        if ($raw === '' || $raw === '0' || $raw === 'false' || $raw === 'no' || $raw === 'off') {
            return false;
        }

        return true;
    }

    public static function skeletonCount(int $limit, int $max = 8): int
    {
        return max(1, min($max, max(1, $limit)));
    }
}
