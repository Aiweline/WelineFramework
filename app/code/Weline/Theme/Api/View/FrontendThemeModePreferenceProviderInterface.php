<?php

declare(strict_types=1);

namespace Weline\Theme\Api\View;

interface FrontendThemeModePreferenceProviderInterface
{
    public function resolveFrontendMode(): ?string;
}
