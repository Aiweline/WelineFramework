<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\StaticRequestBypassDecider;

class StaticRequestBypassDeciderTest extends TestCase
{
    public function testThemeModuleStaticRequestStaysOnWlsFastPath(): void
    {
        self::assertFalse(
            StaticRequestBypassDecider::shouldDeferToFramework(
                'Weline/Theme/view/theme/frontend/variables/_colors.css'
            )
        );
        self::assertFalse(
            StaticRequestBypassDecider::shouldDeferToFramework(
                'Weline/Theme/view/theme/backend/assets/css/theme.css'
            )
        );
    }

    public function testPublishedThemePreviewStaticRequestIsDeferredToFramework(): void
    {
        self::assertTrue(
            StaticRequestBypassDecider::shouldDeferToFramework(
                'static/__preview/token_pv_6_demo/WeShop/motor/Weline/Theme/view/theme/frontend/assets/css/theme.css'
            )
        );
    }

    public function testThemeStaticRequestWithExplicitPreviewQueryIsDeferredToFramework(): void
    {
        self::assertTrue(
            StaticRequestBypassDecider::shouldDeferToFramework(
                'Weline/Theme/view/theme/frontend/assets/css/theme.css',
                '/Weline/Theme/view/theme/frontend/assets/css/theme.css?preview_theme=12'
            )
        );
    }

    public function testRegularStaticRequestStaysOnWlsFastPath(): void
    {
        self::assertFalse(
            StaticRequestBypassDecider::shouldDeferToFramework(
                'Weline/Theme/view/statics/js/theme.js'
            )
        );
    }

    public function testDynamicMediaImageRequestIsDeferredToFramework(): void
    {
        self::assertTrue(
            StaticRequestBypassDecider::shouldDeferToFramework(
                'media/image/backend/logo/logo.png'
            )
        );
        self::assertFalse(
            StaticRequestBypassDecider::shouldReturnFastMissingStatic(
                'media/image/backend/logo/logo.png'
            )
        );
    }

    public function testDynamicMediaFileRequestIsDeferredToFramework(): void
    {
        self::assertTrue(
            StaticRequestBypassDecider::shouldDeferToFramework(
                'media/file/backend/logo/logo.pdf'
            )
        );
        self::assertFalse(
            StaticRequestBypassDecider::shouldReturnFastMissingStatic(
                'media/file/backend/logo/logo.pdf'
            )
        );
    }

    public function testGeneratedSitemapShardIsDeferredToFramework(): void
    {
        self::assertTrue(
            StaticRequestBypassDecider::shouldDeferToFramework(
                'sitemaps/default/canonical/sitemap_weline-index-frontend-1e3458908e8954e9_default-37a8eec1ce19687d_1_08424fb3b59d.xml'
            )
        );
        self::assertTrue(
            StaticRequestBypassDecider::shouldDeferToFramework(
                'pub/sitemaps/default/canonical/sitemap.xml'
            )
        );
        self::assertTrue(
            StaticRequestBypassDecider::shouldDeferToFramework(
                'sitemap.xml'
            )
        );
        self::assertTrue(
            StaticRequestBypassDecider::shouldDeferToFramework(
                'robots.txt'
            )
        );
    }
}
