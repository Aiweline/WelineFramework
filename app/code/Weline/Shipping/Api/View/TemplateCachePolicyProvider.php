<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\View;

use Weline\Framework\View\Cache\TemplateCachePolicyProviderInterface;

final class TemplateCachePolicyProvider implements TemplateCachePolicyProviderInterface
{
    public function policies(): array
    {
        // Widget HTML uses @widget.cache {seconds} only — do not re-register widget templates here.
        return [
            'output_files' => [
                'Weline_Shipping::hooks/header-account-links.phtml' => ['context' => 'static'],
                'Weline_Shipping::hooks/Weline_Theme/frontend/layouts/base/body-end.phtml' => ['context' => 'static'],
            ],
        ];
    }
}
