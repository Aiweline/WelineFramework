<?php

declare(strict_types=1);

namespace Weline\Theme\Region;

/**
 * A contributor of markup for one global region.
 *
 * Providers are registered during bootstrap; `render()` is called from the three
 * anchors {@see GlobalRegionSourceRewriter} injects into every page layout.
 */
interface GlobalRegionProviderInterface
{
    /** Region this provider feeds: head | body_open | body_end. */
    public function region(): string;

    /**
     * Markup for this region. Returning '' is normal (nothing enabled, or the
     * site has this region switched off).
     */
    public function render(): string;
}
