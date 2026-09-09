<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Model\FreeShippingRule;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\ShippingService;
use Weline\Shipping\Service\DefaultCarrierCoverageProvider;
use Weline\Shipping\Service\DefaultShippingLaneSeedService;
use Weline\Shipping\Service\ShippingConfigScopeService;

final class ShippingScopeAndSeedContractTest extends TestCase
{
    public function testModelsDeclareScopeFields(): void
    {
        self::assertSame('scope_type', ShippingService::schema_fields_SCOPE_TYPE);
        self::assertSame('scope_id', ShippingService::schema_fields_SCOPE_ID);
        self::assertSame('scope_type', RateTemplate::schema_fields_SCOPE_TYPE);
        self::assertSame('scope_id', FreeShippingRule::schema_fields_SCOPE_ID);
    }

    public function testAdminAndQuoteUseScopeService(): void
    {
        $coverage = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/CarrierCoverageMatchService.php');
        self::assertStringContainsString('ShippingConfigScopeService', $coverage);
        self::assertStringContainsString('resolveNearestServiceLayer', $coverage);

        $admin = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ShippingConfigurationAdminService.php');
        self::assertStringContainsString('normalizeScopePayload', $admin);
        self::assertStringContainsString('assertSameScope', $admin);

        $ctrl = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/ShippingService.php');
        self::assertStringContainsString('ShippingBackendScopeTrait', $ctrl);
        self::assertStringContainsString('assignShippingWorkScope', $ctrl);
    }

    public function testScopeToolbarPresentOnConfigPages(): void
    {
        foreach (['ShippingService', 'RateTemplate', 'FreeShippingRule'] as $page) {
            $tpl = (string)file_get_contents(
                dirname(__DIR__, 3) . '/view/templates/Backend/' . $page . '/index.phtml',
            );
            self::assertStringContainsString('scope-toolbar.phtml', $tpl);
            self::assertStringContainsString('name="scope_type"', $tpl);
            self::assertStringContainsString('name="target_scope"', $tpl);
        }
        self::assertFileExists(dirname(__DIR__, 3) . '/view/templates/Backend/partials/scope-toolbar.phtml');
        $toolbar = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/Backend/partials/scope-toolbar.phtml');
        self::assertStringContainsString('shipping-work-scope.js', $toolbar);
        self::assertStringContainsString('shipping-work-scope-i18n', $toolbar);
        self::assertStringNotContainsString('function navigateFromNode', $toolbar);
        self::assertStringNotContainsString('function decodeSegment', $toolbar);
        $js = dirname(__DIR__, 3) . '/view/statics/js/backend/shipping-work-scope.js';
        self::assertFileExists($js);
        $jsBody = (string)file_get_contents($js);
        self::assertStringContainsString('navigateFromNode', $jsBody);
        self::assertStringContainsString('decodeSegment', $jsBody);
        self::assertStringContainsString("getElementById('shipping-work-scope')", $jsBody);
        self::assertFileExists(dirname(__DIR__, 3) . '/Service/ShippingConfigScopeService.php');
    }

    public function testDefaultMarketsSeedAssets(): void
    {
        $tsv = dirname(__DIR__, 3) . '/data/default-markets/countries.tsv';
        self::assertFileExists($tsv);
        $body = (string)file_get_contents($tsv);
        self::assertStringContainsString("CN\tdomestic", $body);
        self::assertStringContainsString("US\tamericas", $body);
        self::assertStringContainsString("DE\teurope", $body);
        self::assertStringNotContainsString("\nKP\t", $body);

        $provider = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/DefaultCarrierCoverageProvider.php');
        self::assertStringContainsString('default-markets/countries.tsv', $provider);

        $upgrade = (string)file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertStringContainsString('seedDefaultLanes', $upgrade);
        self::assertStringContainsString('DefaultShippingLaneSeedService', $upgrade);
        self::assertStringContainsString('migrateConfigScopeColumns', $upgrade);

        self::assertTrue(class_exists(DefaultShippingLaneSeedService::class));
        self::assertTrue(class_exists(DefaultCarrierCoverageProvider::class));
        self::assertTrue(class_exists(ShippingConfigScopeService::class));
    }

    public function testQuoteLayerChainOrder(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ShippingConfigScopeService.php');
        self::assertStringContainsString('function quoteLayerChain', $src);
        $fn = strstr($src, 'function quoteLayerChain');
        self::assertNotFalse($fn);
        $channelPos = strpos((string)$fn, 'SCOPE_CHANNEL');
        $storePos = strpos((string)$fn, 'SCOPE_STORE');
        $websitePos = strpos((string)$fn, 'SCOPE_WEBSITE');
        self::assertNotFalse($channelPos);
        self::assertNotFalse($storePos);
        self::assertNotFalse($websitePos);
        self::assertLessThan((int)$storePos, (int)$channelPos);
        self::assertLessThan((int)$websitePos, (int)$storePos);
    }
}
