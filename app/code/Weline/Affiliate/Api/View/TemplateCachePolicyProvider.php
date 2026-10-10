<?php

declare(strict_types=1);

namespace Weline\Affiliate\Api\View;

use Weline\Framework\View\Cache\TemplateCachePolicyProviderInterface;

final class TemplateCachePolicyProvider implements TemplateCachePolicyProviderInterface
{
    public function policies(): array
    {
        return [
            'output_files' => [
                // Guest shell + free share materials; identity/commission via JS.
                'Weline_Affiliate::hooks/Weline_Product/frontend/product/detail/after-add-to-cart.phtml' => [
                    'context' => 'static',
                    'ttl' => 300,
                ],
            ],
        ];
    }
}
