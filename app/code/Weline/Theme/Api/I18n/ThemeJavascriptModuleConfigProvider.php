<?php

declare(strict_types=1);

namespace Weline\Theme\Api\I18n;

use Weline\I18n\Api\Javascript\JavascriptModuleConfigProviderInterface;
use Weline\Theme\Config\Reader\WelineModules;

final class ThemeJavascriptModuleConfigProvider implements JavascriptModuleConfigProviderInterface
{
    public function __construct(
        private readonly WelineModules $reader,
    ) {
    }

    public function content(string $area): string
    {
        return $this->reader->getResourceFileContent($area);
    }

    public function priority(): int
    {
        return 100;
    }
}
