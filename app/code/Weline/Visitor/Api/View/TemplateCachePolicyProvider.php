<?php

declare(strict_types=1);

namespace Weline\Visitor\Api\View;

use Weline\Framework\View\Cache\TemplateCachePolicyProviderInterface;

final class TemplateCachePolicyProvider implements TemplateCachePolicyProviderInterface
{
    public function policies(): array
    {
        return [
            'output_files' => [
                'Weline_Visitor::hooks/Weline_Theme/frontend/layouts/base/body-end.phtml' => ['context' => 'body_end'],
            ],
        ];
    }
}
