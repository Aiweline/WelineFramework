<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Grocery design theme owns neighborhood-grocery policy voice (not Theme module hardcode).
 */
final class GroceryPolicyLayoutVoiceContractTest extends TestCase
{
    public function testGroceryRefundAndShippingLayoutsAvoidHanfuCrossBorderCopy(): void
    {
        $base = dirname(__DIR__, 6) . '/design/Weline/grocery/frontend/layouts/policy';
        $refund = $base . '/refund.phtml';
        $shipping = $base . '/shipping.phtml';
        self::assertFileExists($refund);
        self::assertFileExists($shipping);

        $refundSrc = (string)file_get_contents($refund);
        $shippingSrc = (string)file_get_contents($shipping);

        foreach (['长安汉服', '汉服', '色码', '发往海外', '中国大陆仓', '跨境清关'] as $banned) {
            self::assertStringNotContainsString($banned, $refundSrc, 'refund: ' . $banned);
            self::assertStringNotContainsString($banned, $shippingSrc, 'shipping: ' . $banned);
        }

        self::assertStringContainsString('邻里', $refundSrc);
        self::assertStringContainsString('生鲜', $refundSrc);
        self::assertStringContainsString('siteBrandLate', $refundSrc);
        self::assertStringContainsString('社区日送', $shippingSrc);
        self::assertStringContainsString('生鲜冷链与自提', $shippingSrc);
        self::assertStringContainsString('siteBrandLate', $shippingSrc);
    }
}
