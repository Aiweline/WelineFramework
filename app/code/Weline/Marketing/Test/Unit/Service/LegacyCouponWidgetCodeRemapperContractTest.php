<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Marketing\Service\LegacyCouponWidgetCodeRemapper;

final class LegacyCouponWidgetCodeRemapperContractTest extends TestCase
{
    public function testRemapNodesMapDropsLegacyWhenCanonicalAlreadyInSlot(): void
    {
        $nodes = [
            'legacy' => [
                'node_uid' => 'legacy',
                'widget_code' => 'mini-cart-coupon',
                'widget_module' => 'Weline_Marketing',
                'slot_id' => 'footer-extras',
            ],
            'canonical' => [
                'node_uid' => 'canonical',
                'widget_code' => 'checkout-coupon',
                'widget_module' => 'Weline_Marketing',
                'slot_id' => 'footer-extras',
            ],
        ];

        $result = LegacyCouponWidgetCodeRemapper::remapNodesMap($nodes);
        self::assertTrue($result['changed']);
        self::assertSame(1, $result['removed']);
        self::assertSame(0, $result['renamed']);
        self::assertArrayNotHasKey('legacy', $result['nodes']);
        self::assertArrayHasKey('canonical', $result['nodes']);
    }

    public function testRemapNodesMapRenamesLegacyWhenCanonicalMissing(): void
    {
        $nodes = [
            'legacy' => [
                'node_uid' => 'legacy',
                'widget_code' => 'cart-coupon',
                'widget_module' => 'Weline_Marketing',
                'slot_id' => 'cart-summary-discount',
            ],
        ];

        $result = LegacyCouponWidgetCodeRemapper::remapNodesMap($nodes);
        self::assertTrue($result['changed']);
        self::assertSame(0, $result['removed']);
        self::assertSame(1, $result['renamed']);
        self::assertSame('checkout-coupon', $result['nodes']['legacy']['widget_code']);
    }

    public function testRemapJsonDocumentHandlesReleaseEnvelopeAndChromeMap(): void
    {
        $release = \json_encode([
            'theme_id' => 3,
            'nodes' => [
                'a' => [
                    'widget_code' => 'mini-cart-coupon',
                    'widget_module' => 'Weline_Marketing',
                    'slot_id' => 'footer-extras',
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
        $releaseResult = LegacyCouponWidgetCodeRemapper::remapJsonDocument($release);
        self::assertTrue($releaseResult['changed']);
        $decoded = \json_decode((string)$releaseResult['json'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('checkout-coupon', $decoded['nodes']['a']['widget_code']);

        $chrome = \json_encode([
            'a' => [
                'widget_code' => 'mini-cart-coupon',
                'widget_module' => 'Weline_Marketing',
                'slot_id' => 'footer-extras',
            ],
            'b' => [
                'widget_code' => 'checkout-coupon',
                'widget_module' => 'Weline_Marketing',
                'slot_id' => 'footer-extras',
            ],
        ], \JSON_THROW_ON_ERROR);
        $chromeResult = LegacyCouponWidgetCodeRemapper::remapJsonDocument($chrome);
        self::assertTrue($chromeResult['changed']);
        $chromeDecoded = \json_decode((string)$chromeResult['json'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('a', $chromeDecoded);
        self::assertArrayHasKey('b', $chromeDecoded);
    }

    public function testUpgradeDelegatesToRemapperService(): void
    {
        $upgrade = \file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertIsString($upgrade);
        self::assertStringContainsString('LegacyCouponWidgetCodeRemapper', $upgrade);
        self::assertFileExists(dirname(__DIR__, 3) . '/Service/LegacyCouponWidgetCodeRemapper.php');
    }
}
