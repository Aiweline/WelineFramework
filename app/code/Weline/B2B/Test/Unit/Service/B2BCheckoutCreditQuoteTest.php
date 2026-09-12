<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

require_once dirname(__DIR__) . '/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\B2B\Service\B2BCheckoutCreditQuote;
use Weline\B2B\Service\B2BDepositCreditOrchestrator;
use Weline\Payment\Service\AssetCheckoutDiscountQuote;

final class B2BCheckoutCreditQuoteTest extends TestCase
{
    public function testThinDelegateUnavailableStub(): void
    {
        $stub = B2BCheckoutCreditQuote::unavailableStub('quote_failed', 10_00);
        self::assertFalse($stub['enabled']);
        self::assertSame('quote_failed', $stub['reason']);
    }

    public function testThinDelegateForwardsClamp(): void
    {
        $inner = AssetCheckoutDiscountQuote::forTesting(null, null, true);
        $svc = B2BCheckoutCreditQuote::forTesting($inner);
        $quote = [
            'deposit_amount_minor' => 30_00,
            'max_apply_checkout_minor' => 30_00,
            'available_base_minor' => 50_00,
            'base_currency' => 'CNY',
            'checkout_currency' => 'CNY',
            'website_id' => 1,
            'fx' => null,
        ];
        $clamped = $svc->clampApply($quote, 40_00);
        self::assertSame(30_00, $clamped['apply_checkout_minor']);
        self::assertSame(0, $clamped['cash_deposit_minor']);
    }

    public function testOrchestratorReserveCommitRelease(): void
    {
        $assets = new FakeCustomerAssetFacade();
        $assets->balance = 80_00;
        $orch = new B2BDepositCreditOrchestrator($assets, false);
        $apply = [
            'apply_base_minor' => 20_00,
            'apply_checkout_minor' => 20_00,
            'cash_deposit_minor' => 10_00,
            'base_currency' => 'CNY',
            'checkout_currency' => 'CNY',
            'fx' => ['rate' => '1', 'label' => 'CNY=CNY'],
        ];
        $reserved = $orch->reserveForDeposit('9', 1, 'ord-1', 'idem-1', $apply);
        self::assertTrue($reserved['ok']);
        self::assertSame(B2BDepositCreditOrchestrator::STATUS_RESERVED, $reserved['status']);
        self::assertNotSame('', $reserved['reservation_id']);
        self::assertSame(60_00, $assets->balance);

        $payload = $reserved['type_payload'];
        $committed = $orch->commitOnDepositPaid($payload, 'ord-1', 'idem-1');
        self::assertTrue($committed['ok']);
        self::assertSame(B2BDepositCreditOrchestrator::STATUS_COMMITTED, $committed['status']);

        $assets->balance = 80_00;
        $reserved2 = $orch->reserveForDeposit('9', 1, 'ord-2', 'idem-2', $apply);
        $released = $orch->releaseOnFailure($reserved2['type_payload'], 'ord-2', 'idem-2');
        self::assertTrue($released['ok']);
        self::assertSame(B2BDepositCreditOrchestrator::STATUS_RELEASED, $released['status']);
        self::assertSame(80_00, $assets->balance);
    }
}
