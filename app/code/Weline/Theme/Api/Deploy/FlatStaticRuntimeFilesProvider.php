<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Deploy;

use Weline\Framework\Deploy\FlatStaticRuntimeFilesProviderInterface;

/**
 * Publish Theme storefront runtime JS to flat /static/Weline/Theme/... for PROD.
 * DEV continues to serve app/code/.../view/statics via Module:: path resolution.
 */
final class FlatStaticRuntimeFilesProvider implements FlatStaticRuntimeFilesProviderInterface
{
    public function moduleName(): string
    {
        return 'Weline_Theme';
    }

    public function relativeFiles(): array
    {
        return [
            'frontend/weline.modules.js',
            'js/storefront-image-fallback.js',
            'js/storefront-shopper-toast.js',
            'js/widgets/site-blocks.js',
            'js/widgets/video-carousel.js',
            'js/widgets/mini-cart-extras-tabs.js',
            'js/widgets/mini-cart-icon.js',
            'js/widgets/header-search.js',
            'js/widgets/footer-social-float.js',
        ];
    }
}
