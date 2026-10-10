<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\LayoutRelationCompiler;

/**
 * Checkout SSR-slim: nested controller sources must receive baked renderResolved
 * for required slots. Empty published theme-published-slot shells mean compile
 * was skipped or nodes never reached LayoutRelationCompiler.
 */
final class CheckoutRequiredDefaultInjectionBakeContractTest extends TestCase
{
    public function testCheckoutIndexCompileInjectsRequiredWidgetsIntoSlots(): void
    {
        $index = dirname(__DIR__, 4) . '/Checkout/view/frontend/checkout/index.phtml';
        self::assertFileExists($index);
        $source = (string)file_get_contents($index);

        $nodes = [
            'addr' => [
                'node_uid' => 'addr',
                'widget_module' => 'Weline_Shipping',
                'widget_code' => 'checkout-shipping-address',
                'widget_type' => 'content',
                'is_active' => true,
                'slot_id' => 'checkout-shipping-address',
                'config' => ['title' => '结账收货地址'],
                'source' => 'default_injection',
                'sort_order' => 10,
            ],
            'express' => [
                'node_uid' => 'express',
                'widget_module' => 'Weline_Payment',
                'widget_code' => 'checkout-express-payment',
                'widget_type' => 'content',
                'is_active' => true,
                'slot_id' => 'checkout-express-payment',
                'config' => ['title' => '快捷支付'],
                'source' => 'default_injection',
                'sort_order' => 5,
            ],
            'coupon' => [
                'node_uid' => 'coupon',
                'widget_module' => 'Weline_Marketing',
                'widget_code' => 'checkout-coupon',
                'widget_type' => 'content',
                'is_active' => true,
                'slot_id' => 'checkout-summary-discount',
                'config' => ['title' => '优惠券'],
                'source' => 'default_injection',
                'sort_order' => 20,
            ],
            'note' => [
                'node_uid' => 'note',
                'widget_module' => 'Weline_Order',
                'widget_code' => 'order-notice',
                'widget_type' => 'content',
                'is_active' => true,
                'slot_id' => 'checkout-summary-note',
                'config' => ['title' => '订单留言'],
                'source' => 'default_injection',
                'sort_order' => 30,
            ],
        ];

        $compiled = (new LayoutRelationCompiler())->compile($source, $nodes);
        self::assertGreaterThanOrEqual(4, substr_count($compiled, 'renderResolved'));

        $required = [
            'checkout-shipping-address' => 'checkout-shipping-address',
            'checkout-express-payment' => 'checkout-express-payment',
            'checkout-summary-discount' => 'checkout-coupon',
            'checkout-summary-note' => 'order-notice',
        ];
        foreach ($required as $slot => $code) {
            self::assertMatchesRegularExpression(
                '/id="' . preg_quote($slot, '/') . '"[\s\S]{0,1600}?widget_code\'\s*=>\s*\'' . preg_quote($code, '/') . '\'/',
                $compiled,
                "compiled checkout index must inject {$code} into {$slot}"
            );
        }
    }
}
