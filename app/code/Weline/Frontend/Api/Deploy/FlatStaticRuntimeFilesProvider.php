<?php

declare(strict_types=1);

namespace Weline\Frontend\Api\Deploy;

use Weline\Framework\Deploy\FlatStaticRuntimeFilesProviderInterface;

final class FlatStaticRuntimeFilesProvider implements FlatStaticRuntimeFilesProviderInterface
{
    public function moduleName(): string
    {
        return 'Weline_Frontend';
    }

    public function relativeFiles(): array
    {
        return [
            'base/weline.modules.js',
            'frontend/weline.modules.js',
            'js/weline-api.js',
            'js/weline-api-worker.js',
            'js/weline.js',
        ];
    }
}
