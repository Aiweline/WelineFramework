<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

final class ProductAdminSurfaceContractTest extends TestCase
{
    /** @var array<string, string> */
    private const FEATURES = [
        'products' => 'products',
        'offers' => 'offers',
        'skuRegistry' => 'sku-registry',
        'categories' => 'categories',
        'media' => 'media',
        'siteContent' => 'site-content',
        'storeCopy' => 'store-copy',
        'shards' => 'shards',
    ];

    /** @var array<string,string> */
    private const WRITE_ACTIONS = [
        'postRegisterSku' => 'sku-registry',
        'postCreateProduct' => 'products',
        'postCreateOffer' => 'offers',
        'postCreateCategory' => 'categories',
        'postCreateMedia' => 'media',
        'postSaveSiteContent' => 'site-content',
    ];

    public function testEveryProductMenuRouteHasAnExactControllerAcl(): void
    {
        $menu = (string)file_get_contents(BP . 'app/code/Weline/Product/etc/backend/menu.xml');
        $controller = (string)file_get_contents(BP . 'app/code/Weline/Product/Controller/Backend/Catalog.php');
        foreach (self::FEATURES as $method => $code) {
            self::assertStringContainsString("source=\"Weline_Product::commerce:catalog:{$code}\"", $menu);
            self::assertStringContainsString("action=\"weline_product/backend/catalog/{$method}\"", $menu);
            self::assertMatchesRegularExpression(
                '/#\\[Acl\\(\'Weline_Product::commerce:catalog:' . preg_quote($code, '/') . '\'.*?\\)\\]\\s+public function ' . preg_quote($method, '/') . '\\(\\): string/s',
                $controller,
            );
        }
    }

    public function testProductAdminSurfaceUsesRealReadAndWriteServices(): void
    {
        $controller = (string)file_get_contents(BP . 'app/code/Weline/Product/Controller/Backend/Catalog.php');
        $service = (string)file_get_contents(BP . 'app/code/Weline/Product/Service/ProductAdminMutationService.php');
        $siteContentService = (string)file_get_contents(BP . 'app/code/Weline/Product/Service/ProductSiteContentAdminService.php');
        $template = (string)file_get_contents(BP . 'app/code/Weline/Product/view/templates/backend/catalog/index.phtml');
        self::assertStringContainsString('ProductAdminViewService', $controller);
        self::assertStringContainsString('ProductAdminMutationService', $controller);
        foreach (self::WRITE_ACTIONS as $method => $code) {
            self::assertMatchesRegularExpression(
                '/#\\[Acl\\(\'Weline_Product::commerce:catalog:' . preg_quote($code, '/') . '\'.*?\\)\\]\\s+public function ' . $method . '\\(\\): string/s',
                $controller,
            );
        }
        foreach (['SkuRegistryService', 'ProductRepository', 'OfferRepository', 'CategoryRepository', 'MediaRepository'] as $dependency) {
            self::assertStringContainsString($dependency, $service);
        }
        self::assertStringContainsString('AttributeValueRepository', $siteContentService);
        self::assertStringContainsString('writeExplicit', $siteContentService);
        foreach (['product-sku-register-form', 'product-create-form', 'product-offer-create-form', 'product-category-create-form', 'product-media-create-form', 'product-site-content-form'] as $testId) {
            self::assertStringContainsString('data-testid="' . $testId . '"', $template);
        }
        self::assertSame(6, substr_count($template, 'csrf="auto"'));
        self::assertStringContainsString('data-testid="product-management-<?= $escape($section) ?>"', $template);
    }

    public function testProductBrowserCaseHasPostgresqlAssertionAndCleanup(): void
    {
        $spec = (string)file_get_contents(BP . 'app/code/Weline/Product/Test/e2e/backend/Weline_Product-menu-backend.spec.js');
        $fixture = (string)file_get_contents(BP . 'app/code/Weline/Product/Test/e2e/backend/Weline_Product-write-fixture.php');
        self::assertStringContainsString('CK-R43-PRODUCT-WRITE-001', $spec);
        self::assertStringContainsString('CK-R43-PRODUCT-SITE-CONTENT-001', $spec);
        self::assertStringContainsString("['site-content', 'sitecontent']", $spec);
        self::assertStringContainsString('product-site-content-form', $spec);
        self::assertStringContainsString('openBackendMenuBySource', $spec);
        self::assertStringContainsString("fixture('inspect'", $spec);
        self::assertStringContainsString("fixture('cleanup'", $spec);
        self::assertStringContainsString('attribute_values', $fixture);
        self::assertStringContainsString('r43_product_requires_postgresql', $fixture);
        self::assertStringContainsString('remaining', $fixture);
        self::assertSame(2, preg_match_all('/finally\\s*\\{\\s*guards\\.assertClean\\(\\);/', $spec));
    }
}
