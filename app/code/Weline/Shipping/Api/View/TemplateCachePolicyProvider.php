<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\View;

use Weline\Framework\View\Cache\TemplateCachePolicyProviderInterface;

final class TemplateCachePolicyProvider implements TemplateCachePolicyProviderInterface
{
    public function policies(): array
    {
        return [
            'output_files' => [
                'Weline_Shipping::hooks/header-account-links.phtml' => ['context' => 'frontend_auth'],
                'Weline_Shipping::hooks/Weline_Theme/frontend/layouts/base/body-end.phtml' => ['context' => 'static'],
            ],
        ];
    }
}
