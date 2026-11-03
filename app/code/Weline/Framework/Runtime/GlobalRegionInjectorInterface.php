<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * HTML-level fallback that places the global regions when the compile-time
 * anchors are absent. Implemented by the view/theme module.
 */
interface GlobalRegionInjectorInterface
{
    public function ensure(string $html, string $area = 'frontend'): string;

    /**
     * Render one global region (head/body_open/body_end) for compile-time inlining.
     */
    public function render(string $region): string;

    /**
     * Reset per-process region render state (e.g. on locale switch during compile).
     */
    public function resetRegions(): void;
}
