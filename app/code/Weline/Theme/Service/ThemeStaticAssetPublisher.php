<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Model\WelineTheme;

class ThemeStaticAssetPublisher
{
    public function __construct(
        private readonly ThemeResourceGateway $themeResourceGateway,
    ) {
    }

    public function publishForRequestPath(string $requestPath, ?WelineTheme $theme = null): ?string
    {
        return $this->themeResourceGateway->publishForRequestPath($requestPath, $theme);
    }
}
