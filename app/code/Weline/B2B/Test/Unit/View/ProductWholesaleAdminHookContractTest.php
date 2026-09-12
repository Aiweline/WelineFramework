<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductWholesaleAdminHookContractTest extends TestCase
{
    public function testProductDeclaresBasicAndOffersAfterHooks(): void
    {
        $hook = dirname(__DIR__, 4) . '/Product/hook.php';
        self::assertFileExists($hook);
        $src = (string)file_get_contents($hook);
        self::assertStringContainsString(
            'Weline_Product::backend::catalog::edit::basic-after',
            $src
        );
        self::assertStringContainsString(
            'Weline_Product::backend::catalog::create::basic-after',
            $src
        );
        self::assertStringContainsString(
            'Weline_Product::backend::catalog::edit::offers-after',
            $src
        );
        self::assertStringNotContainsString(
            'Weline_Product::backend::catalog::create::offers-after',
            $src
        );
    }

    public function testEditAndCreateTemplatesHostHooks(): void
    {
        $edit = dirname(__DIR__, 4) . '/Product/view/templates/backend/catalog/edit.phtml';
        $create = dirname(__DIR__, 4) . '/Product/view/templates/backend/catalog/index.phtml';
        self::assertFileExists($edit);
        self::assertFileExists($create);
        $editSrc = (string)file_get_contents($edit);
        $createSrc = (string)file_get_contents($create);
        self::assertStringContainsString(
            'Weline_Product::backend::catalog::edit::basic-after',
            $editSrc
        );
        self::assertStringContainsString(
            'Weline_Product::backend::catalog::edit::offers-after',
            $editSrc
        );
        self::assertStringContainsString(
            'Weline_Product::backend::catalog::create::basic-after',
            $createSrc
        );
    }

    public function testB2BInjectsWholesaleSwitchHooks(): void
    {
        $editHook = dirname(__DIR__, 3)
            . '/view/hooks/Weline_Product/backend/catalog/edit/basic-after.phtml';
        $createHook = dirname(__DIR__, 3)
            . '/view/hooks/Weline_Product/backend/catalog/create/basic-after.phtml';
        $offersHook = dirname(__DIR__, 3)
            . '/view/hooks/Weline_Product/backend/catalog/edit/offers-after.phtml';
        self::assertFileExists($editHook);
        self::assertFileExists($createHook);
        self::assertFileExists($offersHook);
        $editSrc = (string)file_get_contents($editHook);
        $createSrc = (string)file_get_contents($createHook);
        $offersSrc = (string)file_get_contents($offersHook);
        self::assertStringContainsString('data-testid="b2b-product-wholesale-enabled-mirror"', $editSrc);
        self::assertStringContainsString('data-b2b-wholesale-mirror="1"', $editSrc);
        self::assertStringContainsString('selling_mode_tob', $createSrc);
        self::assertStringContainsString('data-testid="b2b-product-wholesale-enabled"', $createSrc);
        self::assertStringContainsString('data-b2b-wholesale-canonical="1"', $createSrc);
        self::assertStringContainsString('启用批发', $createSrc);
        self::assertStringContainsString('data-b2b-wholesale-canonical="1"', $offersSrc);
        self::assertStringContainsString('data-testid="b2b-product-wholesale-enabled"', $offersSrc);
        self::assertStringContainsString('data-testid="b2b-product-tier-table"', $offersSrc);
        self::assertStringContainsString('批发经营与阶梯价', $offersSrc);
        $adminJs = dirname(__DIR__, 4) . '/Product/view/statics/js/backend/product-admin.js';
        self::assertFileExists($adminJs);
        $js = (string)file_get_contents($adminJs);
        self::assertStringContainsString('mergeWholesaleSellingModeFlag', $js);
        self::assertStringContainsString('data-b2b-wholesale-canonical', $js);
        self::assertStringContainsString('saveB2bTiersIfDirty', $js);
        self::assertStringContainsString('initB2bWholesaleOffersUi', $js);
    }
}
