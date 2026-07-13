<?php

declare(strict_types=1);

namespace Weline\Location\Api\View;

use Weline\Framework\View\Cache\TemplateCachePolicyProviderInterface;

final class TemplateCachePolicyProvider implements TemplateCachePolicyProviderInterface
{
    public function policies(): array
    {
        return [
            'output_files' => [
                'Weline_Location::hooks/Weline_Theme/frontend/layouts/base/body-end.phtml' => ['context' => 'static'],
            ],
        ];
    }
}
