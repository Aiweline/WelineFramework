<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class B2BCatalogDisplayTypeContractTest extends TestCase
{
    public function testB2bRegistersDisplayTypeAndVisibilityFilterWithoutOfferRouting(): void
    {
        $provider = dirname(__DIR__, 3) . '/extends/module/Weline_Websites/ScopeDisplayType/B2B.php';
        $filter = dirname(__DIR__, 3) . '/Service/B2BStorefrontCatalogVisibilityFilter.php';
        $module = dirname(__DIR__, 3) . '/etc/module.php';
        $configTpl = dirname(__DIR__, 3) . '/view/templates/Backend/Config/index.phtml';
        $configField = dirname(__DIR__, 3) . '/extends/module/Weline_SystemConfig/Config/frontend/catalog-b2b-products-only.phtml';

        self::assertFileExists($provider);
        self::assertFileExists($filter);
        self::assertFileExists($configField);

        $providerSrc = (string)file_get_contents($provider);
        $filterSrc = (string)file_get_contents($filter);
        $moduleSrc = (string)file_get_contents($module);
        $configTplSrc = (string)file_get_contents($configTpl);
        $fieldSrc = (string)file_get_contents($configField);

        self::assertStringContainsString("CODE = 'b2b'", $providerSrc);
        self::assertStringContainsString('StorefrontCatalogVisibilityFilterInterface', $moduleSrc);
        self::assertStringContainsString('B2BStorefrontCatalogVisibilityFilter', $moduleSrc);
        self::assertStringContainsString('catalog_b2b_products_only', $configTplSrc);
        self::assertStringContainsString('catalog_b2b_products_only', $fieldSrc);
        self::assertStringContainsString('default="1"', $fieldSrc);
        $policy = dirname(__DIR__, 3) . '/Service/CatalogB2bProductsOnlyPolicy.php';
        self::assertFileExists($policy);
        $policySrc = (string)file_get_contents($policy);
        self::assertStringContainsString('DEFAULT_ENABLED = true', $policySrc);
        self::assertStringContainsString('allowsWholesaleDisplay', $filterSrc);
        self::assertStringContainsString('forWebsite(', $filterSrc);
        self::assertStringNotContainsString('CommerceCartOfferRoutingInterface', $filterSrc);
        self::assertStringNotContainsString('B2BCartOfferRouting', $filterSrc);
    }
}
