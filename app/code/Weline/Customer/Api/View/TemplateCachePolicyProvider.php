<?php

declare(strict_types=1);

namespace Weline\Customer\Api\View;

use Weline\Framework\View\Cache\TemplateCachePolicyProviderInterface;

final class TemplateCachePolicyProvider implements TemplateCachePolicyProviderInterface
{
    public function policies(): array
    {
        return [
            'output_files' => [
                'Weline_Customer::hooks/header-account-links.phtml' => ['context' => 'frontend_auth'],
                'Weline_Customer::hooks/Weline_Theme/frontend/layouts/base/body-end.phtml' => ['context' => 'frontend_auth'],
                'Weline_Customer::hooks/Weline_Theme/frontend/layouts/homepage/body-end.phtml' => ['context' => 'frontend_auth'],
            ],
        ];
    }
}
