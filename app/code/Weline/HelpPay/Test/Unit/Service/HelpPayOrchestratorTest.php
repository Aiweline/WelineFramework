<?php

declare(strict_types=1);

namespace Weline\HelpPay\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\HelpPay\Service\HelpPayOrchestrator;
use Weline\HelpPay\Service\ShippingRedactionService;
use Weline\Payment\Service\PaymentLinkService;

final class HelpPayOrchestratorTest extends TestCase
{
    private function orch(): HelpPayOrchestrator
    {
        $repo = new \Weline\Payment\Service\PaymentLinkRecordRepository(
            sys_get_temp_dir() . '/helppay-orch-' . uniqid('', true) . '.json'
        );

        return new HelpPayOrchestrator(new PaymentLinkService($repo));
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
        self::assertGreaterThanOrEqual(time() + 86400 * 6, (int) ($quick['expires_at'] ?? 0));
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
