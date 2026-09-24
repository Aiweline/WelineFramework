<?php

declare(strict_types=1);

namespace Weline\StoreMusic\Api\Deploy;

use Weline\Framework\Deploy\FlatStaticRuntimeFilesProviderInterface;

/**
 * Publish StoreMusic storefront statics to flat /static/Weline/StoreMusic/... for PROD.
 * DEV continues to serve app/code/.../view/statics via Module:: path resolution.
 */
final class FlatStaticRuntimeFilesProvider implements FlatStaticRuntimeFilesProviderInterface
{
    public function moduleName(): string
    {
        return 'Weline_StoreMusic';
    }

    public function relativeFiles(): array
    {
        return [
            'frontend/weline.modules.js',
            'js/store-music.js',
            'js/widgets/widget-store-music-0.js',
            'js/widgets/widget-store-music-1.js',
            'css/store-music.css',
            'css/widgets/widget-store-music.css',
            'images/guofeng-cameo.png',
            'images/guofeng-woman-c.png',
            'images/music-box-closed.png',
            'images/music-box-open.png',
        ];
    }
}
