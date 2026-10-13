<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Console\Theme\Upgrade as ThemeUpgradeCommand;
use Weline\Theme\Service\ThemeDesignImageStaticUrl;

final class ThemeDesignImageStaticUrlContractTest extends TestCase
{
    public function testRemapModuleThemeImagePathToDesignArea(): void
    {
        $from = '/static/Weline/hanfu/Weline/Theme/view/theme/frontend/assets/images/component-kit/backgrounds/header-mountains.webp';
        $to = ThemeDesignImageStaticUrl::remapModuleThemePathToDesignArea($from);
        self::assertSame(
            '/static/Weline/hanfu/frontend/assets/images/component-kit/backgrounds/header-mountains.webp',
            $to,
        );
    }

    public function testRemapPreservesQueryAndBareModuleThemeImageWithNamespaceHint(): void
    {
        $withQuery = '/static/Weline/hanfu/Weline/Theme/view/theme/frontend/assets/images/a.webp?v=1';
        self::assertSame(
            '/static/Weline/hanfu/frontend/assets/images/a.webp?v=1',
            ThemeDesignImageStaticUrl::remapModuleThemePathToDesignArea($withQuery),
        );

        $bare = '/Weline/Theme/view/theme/frontend/assets/images/ink/ox-cloth-caravan.webp?v=20260921-ox1';
        self::assertSame(
            $bare,
            ThemeDesignImageStaticUrl::remapModuleThemePathToDesignArea($bare),
        );
        self::assertSame(
            '/static/Weline/hanfu/frontend/assets/images/ink/ox-cloth-caravan.webp?v=20260921-ox1',
            ThemeDesignImageStaticUrl::remapModuleThemePathToDesignArea($bare, '/static/Weline/hanfu'),
        );
        self::assertSame(
            '/static/Weline/hanfu',
            ThemeDesignImageStaticUrl::staticNamespaceFromUrl(
                'https://shop.test/static/Weline/hanfu/Weline/Theme/view/theme/frontend/assets/css/hanfu-account.css',
            ),
        );
    }

    public function testRemapLeavesCssAndFontsOnModuleThemePath(): void
    {
        $css = '/static/Weline/hanfu/Weline/Theme/view/theme/frontend/assets/css/hanfu-ink.css';
        self::assertSame($css, ThemeDesignImageStaticUrl::remapModuleThemePathToDesignArea($css));

        $font = '/static/Weline/hanfu/Weline/Theme/view/theme/frontend/assets/fonts/brand.woff2';
        self::assertSame($font, ThemeDesignImageStaticUrl::remapModuleThemePathToDesignArea($font));
    }

    public function testUpgradeSourceSkipsImagesWhenDualWritingDesignArea(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Console/Theme/Upgrade.php');
        self::assertStringContainsString('ThemeDesignImageStaticUrl::isImageExtension', $src);
        self::assertStringContainsString('跳过图片', $src);
        self::assertSame(
            '/static/Weline/daocharms/Weline/Theme/view/theme/frontend/assets/css/theme.css',
            ThemeUpgradeCommand::buildNamespacedModuleThemeRequestPath(
                'Weline/daocharms',
                'Weline',
                'Theme',
                'frontend',
                'assets/css/theme.css',
            ),
        );
    }
}
