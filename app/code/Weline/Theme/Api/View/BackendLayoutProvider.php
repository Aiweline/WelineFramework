<?php

declare(strict_types=1);

namespace Weline\Theme\Api\View;

use Weline\Framework\View\BackendLayoutProviderInterface;
use Weline\Theme\Service\ThemeContextService;

final class BackendLayoutProvider implements BackendLayoutProviderInterface
{
    public function __construct(
        private readonly ThemeContextService $themeContext,
    ) {
    }

    public function resolve(string $layoutType, string $layoutOption): ?string
    {
        $theme = $this->themeContext->resolveTheme('backend', null, false);
        if (!$theme || !$theme->getId()) {
            return null;
        }

        return LayoutPathResolver::resolveLayoutTemplate(
            'theme' . DS . 'backend' . DS . 'layouts' . DS . $layoutType . DS . $layoutOption . '.phtml',
            $theme,
            'backend',
        );
    }
}
