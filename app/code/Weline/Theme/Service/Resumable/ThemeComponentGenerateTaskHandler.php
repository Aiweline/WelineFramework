<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Resumable;

final class ThemeComponentGenerateTaskHandler extends AbstractThemeComponentTaskHandler
{
    public function typeCode(): string
    {
        return 'theme.component_generate';
    }

    protected function isRefine(): bool
    {
        return false;
    }
}
