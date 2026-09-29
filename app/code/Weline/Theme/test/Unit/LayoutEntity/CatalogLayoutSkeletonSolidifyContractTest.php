<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Observer\ControllerFetchFileAfter;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityDocumentShellBaker;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer;

/**
 * products/category 固化必须保留 products-layout 网格；空 list-grid 由 FetchAfter 注入控制器目录。
 */
final class CatalogLayoutSkeletonSolidifyContractTest extends TestCase
{


    public function testDocumentShellTrailingKeepsProductsLayoutStyles(): void
    {
        $baker = \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityDocumentShellBaker.php';
        self::assertFileExists($baker);
        $src = (string)\file_get_contents($baker);

        self::assertStringContainsString('<style', $src);
        self::assertStringContainsString('products-layout', $src);
        self::assertStringContainsString('layoutStyles', $src);
    }

    public function testBakeProductsLayoutPostambleIncludesGridCss(): void
    {
        $products = \dirname(__DIR__, 4) . '/Product/view/theme/frontend/layouts/products/default.phtml';
        self::assertFileExists($products);
        $baker = new ThemeLayoutEntityDocumentShellBaker();
        $segments = $baker->bakeFromSource((string)\file_get_contents($products), $products);

        self::assertStringContainsString('products-layout__container', $segments['postamble']);
        self::assertStringContainsString('grid-template-columns', $segments['postamble']);
    }




}
