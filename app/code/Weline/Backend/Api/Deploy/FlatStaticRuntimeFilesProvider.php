<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Deploy;

use Weline\Framework\Deploy\FlatStaticRuntimeFilesProviderInterface;

final class FlatStaticRuntimeFilesProvider implements FlatStaticRuntimeFilesProviderInterface
{
    public function moduleName(): string
    {
        return 'Weline_Backend';
    }

    public function relativeFiles(): array
    {
        return [
            'base/weline.modules.js',
            'backend/weline.modules.js',
            'js/url-backend.js',
            'js/weline-api.js',
            'js/weline-api-worker.js',
        ];
    }
}
