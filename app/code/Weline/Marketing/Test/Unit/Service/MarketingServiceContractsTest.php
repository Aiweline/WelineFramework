<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Marketing\Service\LegacyCouponWidgetCodeRemapper;
use Weline\Marketing\Service\MarketingWebsiteScope;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllMarketingServiceContractsPass().
 */
final class MarketingServiceContractsTest extends TestCase
{
    public function testAllMarketingServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'LegacyCouponWidgetCodeRemapper::testRemapNodesMapDropsLegacyWhenCanonicalAlreadyInSlot' => function () {
            $this->assert_LegacyCouponWidgetCodeRemapper_testRemapNodesMapDropsLegacyWhenCanonicalAlreadyInSlot();
        },
            'LegacyCouponWidgetCodeRemapper::testRemapNodesMapRenamesLegacyWhenCanonicalMissing' => function () {
            $this->assert_LegacyCouponWidgetCodeRemapper_testRemapNodesMapRenamesLegacyWhenCanonicalMissing();
        },
            'LegacyCouponWidgetCodeRemapper::testRemapJsonDocumentHandlesReleaseEnvelopeAndChromeMap' => function () {
            $this->assert_LegacyCouponWidgetCodeRemapper_testRemapJsonDocumentHandlesReleaseEnvelopeAndChromeMap();
        },
            'LegacyCouponWidgetCodeRemapper::testUpgradeDelegatesToRemapperService' => function () {
            $this->assert_LegacyCouponWidgetCodeRemapper_testUpgradeDelegatesToRemapperService();
        },
            'MarketingWebsiteScope::testEntityAllIsNegativeOneAndZeroIsDefaultSite' => function () {
            $this->assert_MarketingWebsiteScope_testEntityAllIsNegativeOneAndZeroIsDefaultSite();
        },
            'MarketingWebsiteScope::testRunnersUseGeZeroNotGtZero' => function () {
            $this->assert_MarketingWebsiteScope_testRunnersUseGeZeroNotGtZero();
        },
        ];
    }

private function assert_LegacyCouponWidgetCodeRemapper_testRemapNodesMapDropsLegacyWhenCanonicalAlreadyInSlot(): void
{
    $nodes = ['legacy' => ['node_uid' => 'legacy', 'widget_code' => 'mini-cart-coupon', 'widget_module' => 'Weline_Marketing', 'slot_id' => 'footer-extras'], 'canonical' => ['node_uid' => 'canonical', 'widget_code' => 'checkout-coupon', 'widget_module' => 'Weline_Marketing', 'slot_id' => 'footer-extras']];
    $result = LegacyCouponWidgetCodeRemapper::remapNodesMap($nodes);
    self::assertTrue($result['changed']);
    self::assertSame(1, $result['removed']);
    self::assertSame(0, $result['renamed']);
    self::assertArrayNotHasKey('legacy', $result['nodes']);
    self::assertArrayHasKey('canonical', $result['nodes']);
}

private function assert_LegacyCouponWidgetCodeRemapper_testRemapNodesMapRenamesLegacyWhenCanonicalMissing(): void
{
    $nodes = ['legacy' => ['node_uid' => 'legacy', 'widget_code' => 'cart-coupon', 'widget_module' => 'Weline_Marketing', 'slot_id' => 'cart-summary-discount']];
    $result = LegacyCouponWidgetCodeRemapper::remapNodesMap($nodes);
    self::assertTrue($result['changed']);
    self::assertSame(0, $result['removed']);
    self::assertSame(1, $result['renamed']);
    self::assertSame('checkout-coupon', $result['nodes']['legacy']['widget_code']);
}

private function assert_LegacyCouponWidgetCodeRemapper_testRemapJsonDocumentHandlesReleaseEnvelopeAndChromeMap(): void
{
    $release = \json_encode(['theme_id' => 3, 'nodes' => ['a' => ['widget_code' => 'mini-cart-coupon', 'widget_module' => 'Weline_Marketing', 'slot_id' => 'footer-extras']]], \JSON_THROW_ON_ERROR);
    $releaseResult = LegacyCouponWidgetCodeRemapper::remapJsonDocument($release);
    self::assertTrue($releaseResult['changed']);
    $decoded = \json_decode((string) $releaseResult['json'], true, 512, \JSON_THROW_ON_ERROR);
    self::assertSame('checkout-coupon', $decoded['nodes']['a']['widget_code']);
    $chrome = \json_encode(['a' => ['widget_code' => 'mini-cart-coupon', 'widget_module' => 'Weline_Marketing', 'slot_id' => 'footer-extras'], 'b' => ['widget_code' => 'checkout-coupon', 'widget_module' => 'Weline_Marketing', 'slot_id' => 'footer-extras']], \JSON_THROW_ON_ERROR);
    $chromeResult = LegacyCouponWidgetCodeRemapper::remapJsonDocument($chrome);
    self::assertTrue($chromeResult['changed']);
    $chromeDecoded = \json_decode((string) $chromeResult['json'], true, 512, \JSON_THROW_ON_ERROR);
    self::assertArrayNotHasKey('a', $chromeDecoded);
    self::assertArrayHasKey('b', $chromeDecoded);
}

private function assert_LegacyCouponWidgetCodeRemapper_testUpgradeDelegatesToRemapperService(): void
{
    $upgrade = \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/Setup/Upgrade.php');
    self::assertIsString($upgrade);
    self::assertStringContainsString('LegacyCouponWidgetCodeRemapper', $upgrade);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/Service/LegacyCouponWidgetCodeRemapper.php');
}

private function assert_MarketingWebsiteScope_testEntityAllIsNegativeOneAndZeroIsDefaultSite(): void
{
    self::assertSame(-1, MarketingWebsiteScope::ENTITY_ALL);
    self::assertSame(-1, MarketingWebsiteScope::normalizeEntity(''));
    self::assertSame(-1, MarketingWebsiteScope::normalizeEntity(null));
    self::assertSame(0, MarketingWebsiteScope::normalizeEntity(0));
    self::assertSame(0, MarketingWebsiteScope::normalizeEntity('0'));
    self::assertSame([0, -1], MarketingWebsiteScope::listMatchIds(0));
    self::assertTrue(MarketingWebsiteScope::appliesToSite(-1, 0));
    self::assertTrue(MarketingWebsiteScope::appliesToSite(0, 0));
    self::assertFalse(MarketingWebsiteScope::appliesToSite(2, 0));
}

private function assert_MarketingWebsiteScope_testRunnersUseGeZeroNotGtZero(): void
{
    $files = ['/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/Service/WinbackUnpaidOrderRunner.php', '/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/Service/WinbackCheckoutAbandonRunner.php', '/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/Service/WinbackCartAbandonRunner.php'];
    foreach ($files as $file) {
        $src = (string) file_get_contents($file);
        self::assertStringContainsString('if ($websiteId >= 0)', $src, $file);
        self::assertStringNotContainsString('if ($websiteId > 0)', $src, $file);
    }
}
}
