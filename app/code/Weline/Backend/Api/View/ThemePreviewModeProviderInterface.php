<?php

declare(strict_types=1);

namespace Weline\Backend\Api\View;

/** Optional theme preview contribution for backend appearance settings. */
interface ThemePreviewModeProviderInterface
{
    /** Return dark/rtl/custom mode, an empty string for light, or null when no preview applies. */
    public function resolveBackendMode(): ?string;
}
