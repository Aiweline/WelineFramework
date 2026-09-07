<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\TextileHeritageCatalog;
use Weline\Theme\Service\TextileHeritageCatalog as ThemeTextileHeritageCatalog;

final class TextileHeritageWidgetContractTest extends TestCase
{
    public function testProductRegistryNoLongerOwnsTextileHeritageWidget(): void
    {
        $widgets = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Product/widget.php';

        self::assertArrayNotHasKey('textile-heritage', $widgets);
    }

    public function testLegacyCatalogDelegatesToThemeOwnedRealAssets(): void
    {
        self::assertSame(ThemeTextileHeritageCatalog::TITLE, TextileHeritageCatalog::TITLE);
        self::assertSame(ThemeTextileHeritageCatalog::IMAGE_BASE, TextileHeritageCatalog::IMAGE_BASE);
        self::assertSame(ThemeTextileHeritageCatalog::items(), TextileHeritageCatalog::items());
        self::assertSame(ThemeTextileHeritageCatalog::widgetConfig(), TextileHeritageCatalog::widgetConfig());

        foreach (TextileHeritageCatalog::items() as $item) {
            self::assertStringStartsWith('/Weline/Theme/view/statics/images/textile-heritage/', (string)$item['image']);
            self::assertDoesNotMatchRegularExpression('/\.svg(?:$|\?)/i', (string)$item['image']);
            self::assertStringStartsWith('https://', (string)$item['source_url']);
            self::assertNotSame('', trim((string)$item['license']));
        }
    }

    public function testLegacyTemplateLoadsThemeOwnedStylesWithoutInlineCarouselScript(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/textile-heritage.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('@widget.code {textile-heritage}', $source);
        self::assertStringContainsString(
            'Weline_Theme::theme/frontend/widgets/content/textile-heritage/default.phtml',
            $source
        );
        self::assertStringNotContainsString('<script', $source);
    }
}
