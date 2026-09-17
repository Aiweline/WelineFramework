<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Service\AbandonedCartSignalService;
use Weline\Cart\Service\ContinueCartUrlBuilder;

final class AbandonedCartSignalContractTest extends TestCase
{
    public function testContinueCartUrlShape(): void
    {
        $b = new ContinueCartUrlBuilder('https://shop.test.weline.com:9555');
        $r = $b->build(1, 'en_US');
        self::assertTrue($r['reachable']);
        self::assertSame('https://shop.test.weline.com:9555/en_US/cart', $r['continue_cart_url']);

        $zh = $b->build(1, 'zh_Hans_CN');
        self::assertSame('https://shop.test.weline.com:9555/cart', $zh['continue_cart_url']);
    }

    public function testProviderContractSource(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CartSignalsQueryProvider.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("return 'cart_signals'", $src);
        self::assertStringContainsString('list_stale_carts', $src);
        self::assertStringContainsString('get_stale_cart', $src);
        self::assertStringContainsString('AbandonedCartSignalService', $src);
        self::assertStringContainsString('Weline_Cart::cart_inspection', $src);
        self::assertStringContainsString("'frontend' => false", $src);

        $svc = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/AbandonedCartSignalService.php');
        self::assertStringContainsString("'line_items'", $svc);
        self::assertStringContainsString('lineItemsFromPayload', $svc);
        self::assertStringContainsString("'has_email'", $svc);
        self::assertStringContainsString("'reachable'", $svc);
        self::assertStringContainsString('continue_cart_url', $svc);
        self::assertStringContainsString('ContinueCartUrlBuilder', $svc);
        self::assertStringContainsString('exclude_empty', $svc);
        self::assertStringContainsString('customer:', $svc);
        self::assertStringContainsString('guest:', $svc);
        self::assertSame(90, AbandonedCartSignalService::MAX_LOOKBACK_DAYS);
    }

    public function testLineItemsProjectionViaReflection(): void
    {
        $service = new AbandonedCartSignalService(new ContinueCartUrlBuilder('https://shop.test.weline.com:9555'));
        $method = new \ReflectionMethod(AbandonedCartSignalService::class, 'lineItemsFromPayload');
        $method->setAccessible(true);
        /** @var list<array<string,mixed>> $lines */
        $lines = $method->invoke($service, [
            'items' => [
                [
                    'name' => 'Demo Tee',
                    'sku' => 'TEE-1',
                    'qty' => 2,
                    'unit_price_minor' => 1990,
                    'row_total_minor' => 3980,
                    'options' => [
                        ['label' => 'Size', 'value_label' => 'M'],
                    ],
                    'image' => 'https://cdn.example/tee.jpg',
                ],
                [
                    'name' => '',
                    'sku' => 'SKIP',
                ],
            ],
        ], 'CNY');

        self::assertCount(1, $lines);
        self::assertSame('Demo Tee', $lines[0]['name']);
        self::assertSame('TEE-1', $lines[0]['sku']);
        self::assertSame(2.0, $lines[0]['qty']);
        self::assertSame('19.90', $lines[0]['unit_price']);
        self::assertSame('39.80', $lines[0]['row_total']);
        self::assertSame('Size: M', $lines[0]['options_text']);
        self::assertSame('https://cdn.example/tee.jpg', $lines[0]['image_url']);
    }

    public function testPayloadEmailExtractionKeepsHasEmailFalseWhenEmpty(): void
    {
        $service = new AbandonedCartSignalService(new ContinueCartUrlBuilder('https://shop.test.weline.com:9555'));
        $method = new \ReflectionMethod(AbandonedCartSignalService::class, 'extractPayloadEmail');
        $method->setAccessible(true);
        self::assertSame('a@example.com', $method->invoke($service, ['guest_email' => 'a@example.com']));
        self::assertSame('b@example.com', $method->invoke($service, ['email' => 'b@example.com']));
        self::assertSame('', $method->invoke($service, ['email' => 'not-an-email']));
        self::assertSame('', $method->invoke($service, []));
    }

    public function testSpecFileExists(): void
    {
        $path = dirname(__DIR__, 3) . '/doc/开发/spec/cart-abandon-signals.md';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('cart_signals', $src);
        self::assertStringContainsString('list_stale_carts', $src);
        self::assertStringContainsString('WHEN', $src);
        self::assertStringContainsString('UC-1', $src);
    }
}
