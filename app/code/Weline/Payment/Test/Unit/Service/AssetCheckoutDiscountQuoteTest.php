<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

require_once dirname(__DIR__, 4) . '/B2B/Test/Unit/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Currency\Service\CurrencyRateService;
use Weline\CustomerAsset\Api\CustomerAssetFacadeInterface;
use Weline\Payment\Service\AssetCheckoutDiscountQuote;
use Weline\Payment\Service\WebsiteBenchmarkCurrencyResolver;

final class AssetCheckoutDiscountQuoteTest extends TestCase
{
    public function testSameCurrencyClampAndCashDeposit(): void
    {
        $assets = new FakeCustomerAssetFacade();
        $assets->balance = 50_00;
        $quoteSvc = AssetCheckoutDiscountQuote::forTesting(
            WebsiteBenchmarkCurrencyResolver::forTesting($this->fixedRates(['CNY' => 1.0])),
            $assets,
            true,
        );
        $quote = $quoteSvc->quote('42', 1, 'CNY', 30_00);
        self::assertTrue($quote['enabled']);
        self::assertSame(30_00, $quote['max_apply_checkout_minor']);
        self::assertStringContainsString('基准货币', $quote['hint_short']);
        self::assertStringContainsString('CNY', $quote['hint_short']);
        self::assertStringContainsString('可用', $quote['hint_short']);
        self::assertStringContainsString('本单最多可抵', $quote['hint_short']);

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
        $quoteSvc = AssetCheckoutDiscountQuote::forTesting(
            WebsiteBenchmarkCurrencyResolver::forTesting($this->fixedRates(['CNY' => 1.0, 'USD' => 7.0])),
            $assets,
            true,
        );
        $depositUsd = 50_00;
        $quote = $quoteSvc->quote('7', 1, 'USD', $depositUsd);
        self::assertTrue($quote['enabled']);
        self::assertNotNull($quote['fx']);
        self::assertSame('CNY', $quote['base_currency']);
        self::assertSame('USD', $quote['checkout_currency']);
        // 100 CNY → USD at rate 7 (1 USD = 7 CNY) ≈ 14.29 USD — must NOT stay 100_00.
        self::assertSame(100_00, $quote['available_base_minor']);
        self::assertSame(14_29, $quote['available_checkout_minor']);
        self::assertNotSame($quote['available_base_minor'], $quote['available_checkout_minor']);
        self::assertSame(14_29, $quote['max_apply_checkout_minor']);
        self::assertStringContainsString('基准货币', $quote['hint_short']);
        self::assertStringContainsString('折合', $quote['hint_short']);
        self::assertStringContainsString('USD', $quote['hint_short']);

        $clamped = $quoteSvc->clampApply($quote, $depositUsd);
        self::assertSame(14_29, $clamped['apply_checkout_minor']);
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
        $currency = new class extends WebsiteBenchmarkCurrencyResolver {
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
        $quoteSvc = AssetCheckoutDiscountQuote::forTesting($currency, $assets, true);
        $quote = $quoteSvc->quote('1', 1, 'EUR', 10_00);
        self::assertFalse($quote['enabled']);
        self::assertSame('fx_unavailable', $quote['reason']);
        self::assertStringContainsString('汇率', $quote['hint_short']);
    }

    public function testDisabledPolicyExposesReadableHint(): void
    {
        $quoteSvc = AssetCheckoutDiscountQuote::forTesting(
            WebsiteBenchmarkCurrencyResolver::forTesting($this->fixedRates(['CNY' => 1.0])),
            new FakeCustomerAssetFacade(),
            false,
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
        $quoteSvc = AssetCheckoutDiscountQuote::forTesting(
            WebsiteBenchmarkCurrencyResolver::forTesting($this->fixedRates(['CNY' => 1.0])),
            $assets,
            true,
        );
        $quote = $quoteSvc->quote('1', 1, 'CNY', 10_00);
        self::assertFalse($quote['enabled']);
        self::assertSame('no_balance', $quote['reason']);
        self::assertStringContainsString('额度不够', $quote['hint_short']);
        self::assertStringContainsString('余额', $quote['hint_short']);
    }

    public function testNotLoggedInExposesReadableHint(): void
    {
        $quoteSvc = AssetCheckoutDiscountQuote::forTesting(
            WebsiteBenchmarkCurrencyResolver::forTesting($this->fixedRates(['CNY' => 1.0])),
            new FakeCustomerAssetFacade(),
            true,
        );
        $quote = $quoteSvc->quote('', 1, 'CNY', 10_00);
        self::assertFalse($quote['enabled']);
        self::assertSame('not_logged_in', $quote['reason']);
        self::assertStringContainsString('登录', $quote['hint_short']);
    }

    public function testNotApplicableExposesReadableHint(): void
    {
        $quoteSvc = AssetCheckoutDiscountQuote::forTesting(
            WebsiteBenchmarkCurrencyResolver::forTesting($this->fixedRates(['CNY' => 1.0])),
            new FakeCustomerAssetFacade(),
            true,
        );
        $quote = $quoteSvc->quote('1', 1, 'CNY', 0);
        self::assertFalse($quote['enabled']);
        self::assertSame('not_applicable', $quote['reason']);
        self::assertNotSame('', trim((string)$quote['hint_short']));
        // Payable base missing ≠「无定金门槛」；信用是资产抵本期定金。
        self::assertStringContainsString('本期定金', $quote['hint_short']);
        self::assertStringNotContainsString('无定金', $quote['hint_short']);
        self::assertStringContainsString('本期定金', (string)$quote['hint_detail']);
        self::assertStringContainsString('最低现金', (string)$quote['hint_detail']);
        self::assertStringNotContainsString('可支付定金', (string)$quote['hint_detail']);
    }

    public function testUnavailableStubNeverHollow(): void
    {
        $stub = AssetCheckoutDiscountQuote::unavailableStub('quote_failed', 10_00);
        self::assertFalse($stub['enabled']);
        self::assertSame('quote_failed', $stub['reason']);
        self::assertStringNotContainsString('当前不可用批发信用', (string)$stub['hint_short']);
        self::assertNotSame('', trim((string)$stub['hint_short']));
    }

    public function testMinCashFloorCapsApplyAndLeavesCash(): void
    {
        $assets = new FakeCustomerAssetFacade();
        $assets->balance = 100_00;
        // 20% of deposit must remain cash → bps 2000.
        $quoteSvc = AssetCheckoutDiscountQuote::forTesting(
            WebsiteBenchmarkCurrencyResolver::forTesting($this->fixedRates(['CNY' => 1.0])),
            $assets,
            true,
            2000,
        );
        $deposit = 30_00;
        $quote = $quoteSvc->quote('42', 1, 'CNY', $deposit);
        self::assertSame(2000, $quote['min_cash_deposit_bps']);
        self::assertSame(6_00, $quote['min_cash_deposit_minor']);
        self::assertSame(24_00, $quote['max_apply_checkout_minor']);
        self::assertStringContainsString('定金至少保留现金', $quote['hint_short']);

        $clamped = $quoteSvc->clampApply($quote, 40_00);
        self::assertSame(24_00, $clamped['apply_checkout_minor']);
        self::assertSame(6_00, $clamped['cash_deposit_minor']);
        self::assertGreaterThan(0, $clamped['cash_deposit_minor']);
        self::assertSame(
            $deposit,
            $clamped['apply_checkout_minor'] + $clamped['cash_deposit_minor'],
        );
    }

    public function testMinCashDepositMinorCeil(): void
    {
        self::assertSame(0, AssetCheckoutDiscountQuote::minCashDepositMinor(1000, 0));
        self::assertSame(200, AssetCheckoutDiscountQuote::minCashDepositMinor(1000, 2000));
        self::assertSame(1, AssetCheckoutDiscountQuote::minCashDepositMinor(1, 2000));
        self::assertSame(1000, AssetCheckoutDiscountQuote::minCashDepositMinor(1000, 10000));
    }

    public function testSplitAcrossDepositsLastAbsorbsBaseResidue(): void
    {
        $assets = new FakeCustomerAssetFacade();
        $assets->balance = 100_00;
        $quoteSvc = AssetCheckoutDiscountQuote::forTesting(
            WebsiteBenchmarkCurrencyResolver::forTesting($this->fixedRates(['CNY' => 1.0])),
            $assets,
            true,
        );
        $quote = $quoteSvc->quote('1', 1, 'CNY', 90_00);
        $parts = $quoteSvc->splitAcrossDeposits($quote, 90_00, [30_00, 30_00, 30_00]);
        self::assertCount(3, $parts);
        self::assertSame(90_00, array_sum(array_column($parts, 'apply_checkout_minor')));
        self::assertSame(90_00, array_sum(array_column($parts, 'apply_base_minor')));
        self::assertSame(0, array_sum(array_column($parts, 'cash_deposit_minor')));
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

final class FakeCustomerAssetFacade implements CustomerAssetFacadeInterface
{
    public int $balance = 0;
    /** @var list<string> */
    public array $events = [];
    /** @var array<string, array<string,mixed>> */
    public array $reservations = [];

    public function credit(array $request): array
    {
        $eventId = (string)($request['event_id'] ?? '');
        if (in_array($eventId, $this->events, true)) {
            return ['ok' => true, 'idempotent' => true];
        }
        $this->events[] = $eventId;
        $this->balance += (int)($request['amount_minor'] ?? 0);

        return ['ok' => true];
    }

    public function reserve(array $request): array
    {
        $id = 'res_' . count($this->reservations);
        $this->reservations[$id] = $request;
        $this->balance -= (int)($request['amount_minor'] ?? 0);

        return ['reservation' => ['reservation_id' => $id]];
    }

    public function release(string $reservationId, string $eventId): array
    {
        if (isset($this->reservations[$reservationId])) {
            $this->balance += (int)($this->reservations[$reservationId]['amount_minor'] ?? 0);
            unset($this->reservations[$reservationId]);
        }

        return ['ok' => true];
    }

    public function commit(string $reservationId, string $eventId): array
    {
        unset($this->reservations[$reservationId]);

        return ['ok' => true];
    }

    public function returnCommitted(string $reservationId, int $amountMinor, string $eventId): array
    {
        $this->balance += max(0, $amountMinor);

        return ['ok' => true];
    }

    public function getBalance(
        string|int $customerId,
        int $websiteId,
        string $assetCode,
        string $namespace = 'live',
    ): array {
        return [
            'available_minor' => $this->balance,
            'reservable_minor' => $this->balance,
            'reserved_minor' => 0,
        ];
    }

    public function listAccounts(
        string|int $customerId,
        int $websiteId,
        string $namespace = 'live',
        int $limit = 100,
    ): array {
        return [];
    }

    public function listLedger(
        string|int $customerId,
        int $websiteId,
        string $assetCode,
        string $namespace = 'live',
        int $limit = 100,
    ): array {
        return [];
    }

    public function getReservation(string $reservationId): array
    {
        return $this->reservations[$reservationId] ?? [];
    }
}
