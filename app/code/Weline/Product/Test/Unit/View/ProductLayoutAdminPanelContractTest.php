<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

/**
 * Backend product/category layout panel + storefront Detail resolve wiring.
 */
final class ProductLayoutAdminPanelContractTest extends TestCase
{
    public function testProductEditPanelExists(): void
    {
        $tpl = (string)\file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/backend/catalog/edit.phtml',
        );
        self::assertStringContainsString('data-product-layout-panel', $tpl);
        self::assertStringContainsString('data-product-layout-create-dialog', $tpl);
        self::assertStringContainsString('data-product-layout-schedule-dialog', $tpl);
        self::assertStringContainsString('theme/backend/theme-editor', $tpl);
    }

    public function testCategoryDefaultLayoutPanelExists(): void
    {
        $tpl = (string)\file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/backend/catalog/categories.phtml',
        );
        self::assertStringContainsString('data-category-product-layout-panel', $tpl);
        self::assertStringContainsString('category_product_default', $tpl);
        self::assertStringContainsString('默认产品布局', $tpl);
    }

    public function testProductAdminJsBindsLayoutPanel(): void
    {
        $js = (string)\file_get_contents(
            BP . 'app/code/Weline/Product/view/statics/js/backend/product-admin.js',
        );
        self::assertStringContainsString('initializeProductLayoutPanel', $js);
        self::assertStringContainsString('createProductLayout', $js);
        self::assertStringContainsString('saveProductLayoutSelection', $js);
        self::assertStringContainsString('product_layout_mode', $js);
        self::assertStringNotContainsString('theme_virtual_layout', $js);
    }

    public function testDetailResolvesEffectiveLayoutOption(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Product/Controller/Frontend/Detail.php',
        );
        self::assertStringContainsString('ProductLayoutResolveService', $source);
        self::assertStringContainsString('resolveProductLayoutOption', $source);
        self::assertStringContainsString('bustIfScheduleMembershipChanged', $source);
        self::assertStringContainsString("layoutType = 'product.'", $source);
    }

    public function testProductAdminJsSeedsCloneFromOptions(): void
    {
        $js = (string)\file_get_contents(
            BP . 'app/code/Weline/Product/view/statics/js/backend/product-admin.js',
        );
        self::assertStringContainsString('fallbackOptions', $js);
        self::assertStringContainsString('normalizeLayoutOptions', $js);
        self::assertStringContainsString('data-product-layout-create', $js);

        $tpl = (string)\file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/backend/catalog/edit.phtml',
        );
        self::assertStringContainsString('data-product-layout-clone-from', $tpl);
        self::assertStringContainsString('value="default"', $tpl);
        self::assertStringContainsString('value="festival"', $tpl);
        self::assertStringContainsString('data-product-layout-create-submit', $tpl);
        self::assertStringNotContainsString('method="dialog"', $tpl);
    }

    public function testProductLayoutCreateUsesButtonNotNestedForm(): void
    {
        $js = (string)\file_get_contents(
            BP . 'app/code/Weline/Product/view/statics/js/backend/product-admin.js',
        );
        self::assertStringContainsString('data-product-layout-create-submit', $js);
        self::assertStringContainsString('data-product-layout-schedule-submit', $js);
        self::assertStringNotContainsString("createDialog?.querySelector('form')", $js);
    }

    public function testProductAdminQueryExposesLayoutOperations(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Product/extends/module/Weline_Framework/Query/ProductAdminQueryProvider.php',
        );
        foreach ([
            'listProductLayouts',
            'createProductLayout',
            'saveProductLayoutSelection',
            'saveProductLayoutSchedule',
            'deleteProductLayoutSchedule',
        ] as $op) {
            self::assertStringContainsString("'" . $op . "'", $source);
        }
    }
}
