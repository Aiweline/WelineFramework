<?php

declare(strict_types=1);

namespace Weline\HelpPay\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutQuoteLineWeightResolver;
use Weline\HelpPay\Service\HelpPayOrchestrator;
use Weline\HelpPay\Service\HelpPayQuickShippingQuoteService;
use Weline\HelpPay\Service\ShippingRedactionService;
use Weline\Payment\Service\PaymentLinkService;

final class HelpPayOrchestratorTest extends TestCase
{
    private function orch(?HelpPayQuickShippingQuoteService $shipping = null): HelpPayOrchestrator
    {
        $repo = new \Weline\Payment\Service\PaymentLinkRecordRepository(
            sys_get_temp_dir() . '/helppay-orch-' . uniqid('', true) . '.json'
        );

        return new HelpPayOrchestrator(
            new PaymentLinkService($repo),
            new ShippingRedactionService(),
            $shipping,
        );
    }

    private function shippingStub(int $weightMinor = 300, int $amountMinor = 200): HelpPayQuickShippingQuoteService
    {
        return HelpPayQuickShippingQuoteService::forTesting(
            CheckoutQuoteLineWeightResolver::forTesting(static fn (): int => $weightMinor),
            [[
                'service_code' => 'SEED_LANE_AMERICAS',
                'label' => '美洲',
                'amount_minor' => $amountMinor,
            ]],
        );
    }

    public function testCreateHelpPayRequiresRulesAndAddressConfirm(): void
    {
        $orch = $this->orch();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('helppay_rules_not_accepted');
        $orch->createHelpPay([
            'amount_minor' => 100,
            'shipping_address' => [
                'name' => 'A', 'line1' => '1', 'phone' => '1', 'country' => 'CN',
            ],
            'address_confirmed' => true,
            'rules_accepted' => false,
        ]);
    }

    public function testCreateHelpPayAndPayerResolveHasNoShipping(): void
    {
        $orch = $this->orch();
        $out = $orch->createHelpPay([
            'amount_minor' => 2500,
            'currency_code' => 'CNY',
            'shipping_address' => [
                'name' => 'Alice',
                'line1' => 'Road 1',
                'phone' => '13800000000',
                'country' => 'CN',
            ],
            'address_confirmed' => true,
            'rules_accepted' => true,
            'line_summary' => [['title' => 'Mug', 'qty' => 1]],
            'public_origin' => 'https://demo.test.weline.com',
            'cart_type' => 'toc',
        ]);

        self::assertTrue($out['share_delivery']['copy_url']);
        self::assertTrue($out['share_delivery']['copy_qr_image']);
        self::assertStringContainsString('/h/', $out['url']);

        $payer = $orch->resolveHelpPayForPayer((string) $out['token']);
        self::assertNotNull($payer);
        self::assertTrue((bool) ($payer['shipping_redacted'] ?? false));
        self::assertFalse((bool) ($payer['discounts_allowed'] ?? true));
        self::assertArrayNotHasKey('shipping_address', $payer);
        self::assertFalse((bool) ($payer['load_payer_cart'] ?? true));
    }

    public function testTobCartRejected(): void
    {
        $orch = $this->orch();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('helppay_toc_only');
        $orch->createSelectionShare([
            'selection_snapshot' => ['lines' => [['sku' => 'x']]],
            'cart_type' => 'tob',
        ]);
    }

    public function testSelectionShareAndQuickPayDualDelivery(): void
    {
        $orch = $this->orch();
        $share = $orch->createSelectionShare([
            'selection_snapshot' => ['lines' => [['sku' => 'SKU1', 'qty' => 2]]],
            'public_origin' => 'https://demo.test.weline.com',
        ]);
        self::assertStringContainsString('/s/', $share['url']);
        self::assertTrue($share['share_delivery']['show_qr']);

        $quick = $orch->createQuickPay([
            'amount_minor' => 900,
            'shipping_address' => [
                'name' => 'Bob', 'line1' => 'St', 'phone' => '1', 'country' => 'US',
            ],
            'public_origin' => 'https://demo.test.weline.com',
        ]);
        self::assertStringContainsString('/q/', $quick['url']);
        self::assertTrue($quick['share_delivery']['copy_qr_image']);
        self::assertTrue($quick['session_isolation'] ?? false);
        self::assertGreaterThanOrEqual(time() + 86400 * 6, (int) ($quick['expires_at'] ?? 0));

        $withShip = $this->orch($this->shippingStub())->createQuickPay([
            'product_id' => 321,
            'qty' => 1,
            'goods_amount_minor' => 1000,
            'shipping_amount_minor' => 200,
            'service_code' => 'SEED_LANE_AMERICAS',
            'service_label' => '美洲',
            'shipping_address' => [
                'name' => 'Bob', 'line1' => 'St', 'phone' => '1', 'country' => 'US',
            ],
            'public_origin' => 'https://demo.test.weline.com',
        ]);
        self::assertSame(1200, (int) ($withShip['amount_minor'] ?? 0));
        self::assertSame('SEED_LANE_AMERICAS', (string) ($withShip['service_code'] ?? ''));
        self::assertSame('美洲', (string) ($withShip['service_label'] ?? ''));
    }

    public function testCreateQuickPayRefusesMissingWeight(): void
    {
        $orch = $this->orch($this->shippingStub(0, 200));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('helppay_missing_weight');
        $orch->createQuickPay([
            'product_id' => 192,
            'qty' => 1,
            'goods_amount_minor' => 1000,
            'shipping_amount_minor' => 200,
            'service_code' => 'SEED_LANE_AMERICAS',
            'shipping_address' => [
                'name' => 'Bob', 'line1' => 'St', 'phone' => '1', 'country' => 'US',
            ],
            'public_origin' => 'https://demo.test.weline.com',
        ]);
    }

    public function testCreateQuickPayRequiresProductWhenShippingSelected(): void
    {
        $orch = $this->orch($this->shippingStub());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('helppay_product_required');
        $orch->createQuickPay([
            'goods_amount_minor' => 1000,
            'shipping_amount_minor' => 200,
            'service_code' => 'SEED_LANE_AMERICAS',
            'shipping_address' => [
                'name' => 'Bob', 'line1' => 'St', 'phone' => '1', 'country' => 'US',
            ],
            'public_origin' => 'https://demo.test.weline.com',
        ]);
    }

    public function testShippingRedactionStripsOwnerFacingFields(): void
    {
        $svc = new ShippingRedactionService();
        $out = $svc->redactStorefront([
            'order_id' => 1,
            'shipping_address' => ['line1' => 'secret'],
            'recipient_phone' => '1',
            'grand_total' => 10,
        ], true);
        self::assertArrayNotHasKey('shipping_address', $out);
        self::assertArrayNotHasKey('recipient_phone', $out);
        self::assertTrue($out['shipping_redacted']);
        self::assertSame(10, $out['grand_total']);
    }
}
