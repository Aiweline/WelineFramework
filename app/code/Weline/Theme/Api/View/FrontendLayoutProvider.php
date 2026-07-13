<?php

declare(strict_types=1);

namespace Weline\Theme\Api\View;

use Weline\Framework\View\FrontendLayoutProviderInterface;

final class FrontendLayoutProvider implements FrontendLayoutProviderInterface
{
    private const LAYOUTS = [
        'auth' => 'Weline_Theme::theme/frontend/layouts/account/auth.phtml',
        'default' => 'Weline_Theme::theme/frontend/layouts/default/default.phtml',
        'full' => 'Weline_Theme::theme/frontend/layouts/default/default.phtml',
    ];

    public function resolve(string $layoutType): ?string
    {
        return self::LAYOUTS[$layoutType] ?? null;
    }
}
