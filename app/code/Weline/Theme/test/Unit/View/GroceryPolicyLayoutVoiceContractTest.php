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
        $base = dirname(__DIR__, 6) . '/design/Weline/grocery/frontend';
        $refund = $base . '/layouts/policy/refund.phtml';
        $shipping = $base . '/layouts/policy/shipping.phtml';
        $defaults = $base . '/includes/GroceryPolicyDocumentDefaults.php';
        self::assertFileExists($refund);
        self::assertFileExists($shipping);
        self::assertFileExists($defaults);

        $refundSrc = (string)file_get_contents($refund);
        $shippingSrc = (string)file_get_contents($shipping);
        $defaultsSrc = (string)file_get_contents($defaults);

        foreach (['长安汉服', '汉服', '色码', '发往海外', '中国大陆仓', '跨境清关'] as $banned) {
            self::assertStringNotContainsString($banned, $refundSrc, 'refund layout: ' . $banned);
            self::assertStringNotContainsString($banned, $shippingSrc, 'shipping layout: ' . $banned);
            self::assertStringNotContainsString($banned, $defaultsSrc, 'defaults: ' . $banned);
        }

        self::assertStringContainsString('GroceryPolicyDocumentDefaults', $refundSrc);
        self::assertStringContainsString('GroceryPolicyDocumentDefaults', $shippingSrc);
        self::assertStringContainsString('renderRuntimeInline', $refundSrc);
        self::assertStringContainsString('policy-document', $refundSrc);
        self::assertStringNotContainsString('<style>', $refundSrc);
        self::assertStringNotContainsString('<style>', $shippingSrc);

        require_once $defaults;
        self::assertTrue(\class_exists('GroceryPolicyDocumentDefaults'));
        $refundParams = \GroceryPolicyDocumentDefaults::widgetParams('refund');
        $shippingParams = \GroceryPolicyDocumentDefaults::widgetParams('shipping');
        $refundBlob = json_encode($refundParams, JSON_UNESCAPED_UNICODE) ?: '';
        $shippingBlob = json_encode($shippingParams, JSON_UNESCAPED_UNICODE) ?: '';

        self::assertStringContainsString('邻里', $refundBlob);
        self::assertStringContainsString('生鲜', $refundBlob);
        self::assertStringContainsString('社区日送', $shippingBlob);
        self::assertStringContainsString('生鲜冷链与自提', $shippingBlob);
        self::assertGreaterThanOrEqual(8, count($refundParams['sections'] ?? []));
        self::assertGreaterThanOrEqual(7, count($shippingParams['sections'] ?? []));
    }
}
