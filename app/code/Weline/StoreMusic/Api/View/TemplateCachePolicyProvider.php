<?php

declare(strict_types=1);

namespace Weline\StoreMusic\Api\View;

use Weline\Framework\View\Cache\TemplateCachePolicyProviderInterface;

final class TemplateCachePolicyProvider implements TemplateCachePolicyProviderInterface
{
    public function policies(): array
    {
        return [
            'output_files' => [
                'Weline_StoreMusic::templates/frontend/widgets/store-music.phtml' => ['context' => 'static'],
            ],
        ];
    }
}
