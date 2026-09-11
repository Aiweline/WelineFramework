<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Service\B2BBaseCurrencyResolver;
use Weline\B2B\Service\B2BCheckoutCreditQuote;
use Weline\B2B\Service\B2BDepositCreditOrchestrator;
use Weline\B2B\Service\B2BPaymentAssetPolicyProvider;
use Weline\Currency\Service\CurrencyRateService;

final class B2BCheckoutCreditQuoteTest extends TestCase
{
    public function testSameCurrencyClampAndCashDeposit(): void
    {
        $assets = new FakeCustomerAssetFacade();
        $assets->balance = 50_00;
        $quoteSvc = B2BCheckoutCreditQuote::forTesting(
            B2BBaseCurrencyResolver::forTesting($this->fixedRates(['CNY' => 1.0])),
            B2BPaymentAssetPolicyProvider::forTesting(true),
            $assets,
        );
        $quote = $quoteSvc->quote('42', 1, 'CNY', 30_00);
        self::assertTrue($quote['enabled']);
        self::assertSame(30_00, $quote['max_apply_checkout_minor']);
        self::assertStringContainsString('同币', $quote['hint_short']);

        $clamped = $quoteSvc->clampApply($quote, 40_00);
        self::assertSame(30_00, $clamped['apply_checkout_minor']);
        self::assertSame(30_00, $clamped['apply_base_minor']);
        self::assertSame(0, $clamped['cash_deposit_minor']);
        self::assertTrue($clamped['adjusted']);
    }

    public function testFxRoundTripClampsToAvailableBase(): void
    {
        $assets = new FakeCustomerAssetFacade();
        $assets->balance = 100_00;
        $quoteSvc = B2BCheckoutCreditQuote::forTesting(
            B2BBaseCurrencyResolver::forTesting($this->fixedRates(['CNY' => 1.0, 'USD' => 7.0])),
            B2BPaymentAssetPolicyProvider::forTesting(true),
            $assets,
        );
        $depositUsd = 50_00;
        $quote = $quoteSvc->quote('7', 1, 'USD', $depositUsd);
        self::assertTrue($quote['enabled']);
        self::assertNotNull($quote['fx']);
        self::assertSame('CNY', $quote['base_currency']);
        self::assertSame('USD', $quote['checkout_currency']);

        $clamped = $quoteSvc->clampApply($quote, $depositUsd);
        self::assertLessThanOrEqual($assets->balance, $clamped['apply_base_minor']);
        self::assertSame(
            $depositUsd,
            $clamped['apply_checkout_minor'] + $clamped['cash_deposit_minor'],
        );
    }

    public function testMissingFxDisablesQuote(): void
    {
        $assets = new FakeCustomerAssetFacade();
        $assets->balance = 10_00;
        $currency = new class extends B2BBaseCurrencyResolver {
            public function forWebsite(int $websiteId): string
            {
                return 'CNY';
            }

            public function convertMinorFromWebsiteDefault(
                int $amountMinor,
                string $targetCurrency,
                int $websiteId,
            ): ?int {
                return null;
            }

            public function convertMinorToWebsiteDefault(
                int $amountMinor,
                string $sourceCurrency,
                int $websiteId,
            ): ?int {
                return null;
            }

            public function rateSnapshot(string $fromCurrency, string $toCurrency, int $websiteId): ?array
            {
                return null;
            }
        };
        $quoteSvc = B2BCheckoutCreditQuote::forTesting(
            $currency,
            B2BPaymentAssetPolicyProvider::forTesting(true),
            $assets,
        );
        $quote = $quoteSvc->quote('1', 1, 'EUR', 10_00);
        self::assertFalse($quote['enabled']);
        self::assertSame('fx_unavailable', $quote['reason']);
        self::assertStringContainsString('汇率', $quote['hint_short']);
    }

    public function testDisabledPolicyExposesReadableHint(): void
    {
        $quoteSvc = B2BCheckoutCreditQuote::forTesting(
            B2BBaseCurrencyResolver::forTesting($this->fixedRates(['CNY' => 1.0])),
            B2BPaymentAssetPolicyProvider::forTesting(false),
            new FakeCustomerAssetFacade(),
        );
        $quote = $quoteSvc->quote('1', 1, 'CNY', 10_00);
        self::assertFalse($quote['enabled']);
        self::assertSame('b2b_credit_disabled', $quote['reason']);
        self::assertNotSame('', trim((string)$quote['hint_short']));
        self::assertStringContainsString('未开启', $quote['hint_short']);
    }

    public function testNoBalanceExposesReadableHint(): void
    {
        $assets = new FakeCustomerAssetFacade();
        $assets->balance = 0;
        $quoteSvc = B2BCheckoutCreditQuote::forTesting(
            B2BBaseCurrencyResolver::forTesting($this->fixedRates(['CNY' => 1.0])),
            B2BPaymentAssetPolicyProvider::forTesting(true),
            $assets,
        );
        $quote = $quoteSvc->quote('1', 1, 'CNY', 10_00);
        self::assertFalse($quote['enabled']);
        self::assertSame('no_balance', $quote['reason']);
        self::assertStringContainsString('额度不够', $quote['hint_short']);
        self::assertStringContainsString('余额', $quote['hint_short']);
    }

    public function testNotLoggedInExposesReadableHint(): void
    {
        $quoteSvc = B2BCheckoutCreditQuote::forTesting(
            B2BBaseCurrencyResolver::forTesting($this->fixedRates(['CNY' => 1.0])),
            B2BPaymentAssetPolicyProvider::forTesting(true),
            new FakeCustomerAssetFacade(),
        );
        $quote = $quoteSvc->quote('', 1, 'CNY', 10_00);
        self::assertFalse($quote['enabled']);
        self::assertSame('not_logged_in', $quote['reason']);
        self::assertStringContainsString('登录', $quote['hint_short']);
    }

    public function testNotApplicableExposesReadableHint(): void
    {
        $quoteSvc = B2BCheckoutCreditQuote::forTesting(
            B2BBaseCurrencyResolver::forTesting($this->fixedRates(['CNY' => 1.0])),
            B2BPaymentAssetPolicyProvider::forTesting(true),
            new FakeCustomerAssetFacade(),
        );
        $quote = $quoteSvc->quote('1', 1, 'CNY', 0);
        self::assertFalse($quote['enabled']);
        self::assertSame('not_applicable', $quote['reason']);
        self::assertNotSame('', trim((string)$quote['hint_short']));
        self::assertStringContainsString('定金', $quote['hint_short']);
    }

    public function testUnavailableStubNeverHollow(): void
    {
        $stub = B2BCheckoutCreditQuote::unavailableStub('quote_failed', 10_00);
        self::assertFalse($stub['enabled']);
        self::assertSame('quote_failed', $stub['reason']);
        self::assertStringNotContainsString('当前不可用批发信用', (string)$stub['hint_short']);
        self::assertNotSame('', trim((string)$stub['hint_short']));
    }

    public function testSplitAcrossDepositsLastAbsorbsBaseResidue(): void
    {
        $assets = new FakeCustomerAssetFacade();
        $assets->balance = 100_00;
        $quoteSvc = B2BCheckoutCreditQuote::forTesting(
            B2BBaseCurrencyResolver::forTesting($this->fixedRates(['CNY' => 1.0])),
            B2BPaymentAssetPolicyProvider::forTesting(true),
            $assets,
        );
        $quote = $quoteSvc->quote('1', 1, 'CNY', 90_00);
        $parts = $quoteSvc->splitAcrossDeposits($quote, 90_00, [30_00, 30_00, 30_00]);
        self::assertCount(3, $parts);
        self::assertSame(90_00, array_sum(array_column($parts, 'apply_checkout_minor')));
        self::assertSame(90_00, array_sum(array_column($parts, 'apply_base_minor')));
        self::assertSame(0, array_sum(array_column($parts, 'cash_deposit_minor')));
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

    /** @param array<string,float> $toBase */
    private function fixedRates(array $toBase): CurrencyRateService
    {
        $mock = $this->getMockBuilder(CurrencyRateService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBaseCurrency', 'tryConvert'])
            ->getMock();
        $mock->method('getBaseCurrency')->willReturn('CNY');
        $mock->method('tryConvert')->willReturnCallback(
            static function (float $amount, ?string $sourceCurrency = null, ?string $targetCurrency = null) use ($toBase): ?float {
                $from = strtoupper(trim((string)$sourceCurrency));
                $to = strtoupper(trim((string)$targetCurrency));
                if ($from === '' || $to === '' || !isset($toBase[$from]) || !isset($toBase[$to])) {
                    return null;
                }
                if ($from === $to) {
                    return $amount;
                }

                return ($amount * $toBase[$from]) / $toBase[$to];
            },
        );

        return $mock;
    }
}
