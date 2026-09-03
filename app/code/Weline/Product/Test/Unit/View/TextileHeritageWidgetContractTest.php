<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\TextileHeritageCatalog;

final class TextileHeritageWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationOwnsDefaultsWithImagesAndSearchLinks(): void
    {
        $widgets = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Product/widget.php';
        $widget = $widgets['textile-heritage'] ?? [];

        self::assertSame('textile-heritage', $widget['code'] ?? null);
        self::assertSame(
            'Weline_Product::templates/frontend/widgets/textile-heritage.phtml',
            $widget['template'] ?? null
        );

        $brands = $widget['params']['brands']['default'] ?? [];
        self::assertIsArray($brands);
        self::assertCount(6, $brands);

        $names = [];
        foreach ($brands as $item) {
            self::assertIsArray($item);
            self::assertNotSame('', (string)($item['name'] ?? ''));
            self::assertNotSame('', (string)($item['image'] ?? ''));
            self::assertStringStartsWith('/Weline/Product/view/statics/images/textile-heritage/', (string)$item['image']);
            self::assertStringStartsWith('/search?q=', (string)($item['link'] ?? ''));
            $names[] = (string)$item['name'];
        }

        self::assertSame(['云锦', '宋锦', '蜀锦', '苏绣', '妆花', '花罗'], $names);

        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('homepage-brands', $injection['slot'] ?? null);
        self::assertTrue((bool)($injection['required'] ?? false));
        self::assertSame($brands, $injection['config']['brands'] ?? null);
    }

    public function testCatalogAssetsExistOnDisk(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (TextileHeritageCatalog::items() as $item) {
            $relative = ltrim((string)$item['image'], '/');
            // /Weline/Product/view/statics/... → app/code/Weline/Product/view/statics/...
            $path = $root . '/view/statics/images/textile-heritage/' . $item['slug'] . '.svg';
            self::assertFileExists($path, $item['slug'] . ' svg missing');
            self::assertStringContainsString($item['slug'] . '.svg', (string)$item['image']);
            unset($relative);
        }
    }

    public function testTemplateRendersImageAndLinkProtocol(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/textile-heritage.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('@widget.code {textile-heritage}', $source);
        self::assertStringContainsString('TextileHeritageCatalog', $source);
        self::assertStringContainsString('data-testid="textile-heritage"', $source);
        self::assertStringContainsString('class="brand-logo"', $source);
        self::assertStringContainsString('$brandLink !== \'\'', $source);
    }
}
