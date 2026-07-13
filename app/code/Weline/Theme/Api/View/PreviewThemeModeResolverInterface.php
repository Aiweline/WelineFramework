<?php

declare(strict_types=1);

namespace Weline\Theme\Api\View;

interface PreviewThemeModeResolverInterface
{
    /** null means no active preview override; empty string means light mode. */
    public function resolveFrontendMode(): ?string;
}
