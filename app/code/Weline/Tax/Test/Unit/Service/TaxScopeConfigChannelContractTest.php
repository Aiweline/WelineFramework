<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Tax\Service\CheckoutTaxAdvisor;
use Weline\Tax\Service\TaxScopeConfig;

/**
 * Tax runtime switches are writable at channel scope and resolved with channel_id.
 */
final class TaxScopeConfigChannelContractTest extends TestCase
{
    public function testBackendTaxFieldsAllowChannelScope(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_SystemConfig/Config/backend/tax.phtml'
        );

        self::assertStringContainsString('tax/general/enabled', $src);
        self::assertStringContainsString('tax/general/prices_include_tax', $src);
        self::assertStringContainsString('tax/general/collect_sales_tax_countries', $src);
        self::assertGreaterThanOrEqual(
            5,
            substr_count($src, 'scope="global,website,store,channel"'),
            'tax/general runtime fields must allow channel scope'
        );
        self::assertDoesNotMatchRegularExpression(
            '/scope="global,website,store"/',
            $src,
            'runtime tax fields must not omit channel'
        );
    }

    public function testResolveAcceptsChannelIdAndCheckoutAdvisorPassesIt(): void
    {
        $scopeSrc = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/TaxScopeConfig.php'
        );
        self::assertStringContainsString(
            'public function resolve(int $websiteId, int $storeId, int $channelId = 0)',
            $scopeSrc
        );
        self::assertStringContainsString('ScopeIdentity::channel(', $scopeSrc);
        self::assertStringContainsString('SalesChannelCatalogInterface', $scopeSrc);
        self::assertStringContainsString('toStorageScope($identity)', $scopeSrc);

        $advisorSrc = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutTaxAdvisor.php'
        );
        self::assertStringContainsString('max(0, $channelId)', $advisorSrc);
        self::assertStringContainsString("resolved['enabled']", $advisorSrc);

        $resolved = TaxScopeConfig::forTesting(['enabled' => true])->resolve(0, 1, 2);
        self::assertSame(2, (int)$resolved['channel_id']);
        self::assertTrue((bool)$resolved['enabled']);

        $advisor = CheckoutTaxAdvisor::forTestingActive();
        // forTestingActive allowlists website:0 (store/channel 0 → website subject).
        self::assertTrue($advisor->isEffectivelyOn(0, 0, 0));
    }

    public function testDisabledScopeConfigTurnsAdvisorOffEvenWhenRolloutAllows(): void
    {
        $advisor = CheckoutTaxAdvisor::forTestingActive(
            pricesIncludeTax: true,
            collectSalesTaxCountries: '',
        );
        // Replace with disabled scope via reflection-free forTesting stub path:
        $disabled = new CheckoutTaxAdvisor(
            \Weline\Tax\Service\TaxEngine::forTesting(),
            $advisor->rollout(),
            $advisor->lkg(),
            TaxScopeConfig::forTesting(['enabled' => false]),
        );
        self::assertFalse($disabled->isEffectivelyOn(0, 1, 1));
    }
}
