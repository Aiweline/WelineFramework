<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Resumable;

final class ThemeComponentRefineTaskHandler extends AbstractThemeComponentTaskHandler
{
    public function typeCode(): string
    {
        return 'theme.component_refine';
    }

    protected function isRefine(): bool
    {
        return true;
    }
}
