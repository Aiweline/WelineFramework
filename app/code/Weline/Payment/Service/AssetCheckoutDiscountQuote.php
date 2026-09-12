<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\CustomerAsset\Api\CustomerAssetFacadeInterface;
use Weline\CustomerAsset\Model\AssetAccount;
use Weline\Framework\Manager\ObjectManager;

/**
 * Payment-owned asset discount quote (data from CustomerAsset; type/policy from payment.asset_policy SPI) (checkout currency UI,
 * website-default wallet). FX fail-closed; base wallet is authoritative after clamp.
 */
final class AssetCheckoutDiscountQuote
{
    public const ASSET_B2B_CREDIT = 'b2b_credit';

    public function __construct(
        private readonly ?WebsiteBenchmarkCurrencyResolver $currency = null,
        private readonly ?AssetPaymentService $assetPayment = null,
        private readonly ?CustomerAssetFacadeInterface $assets = null,
        private readonly ?bool $policyEnabledOverride = null,
        private readonly ?int $minCashDepositBpsOverride = null,
    ) {
    }

    public static function forTesting(
        ?WebsiteBenchmarkCurrencyResolver $currency = null,
        ?CustomerAssetFacadeInterface $assets = null,
        bool $policyEnabled = true,
        int $minCashDepositBps = 0,
    ): self {
        return new self($currency, null, $assets, $policyEnabled, $minCashDepositBps);
    }

    /**
     * @return array{
     *   enabled:bool,
     *   reason:string,
     *   base_currency:string,
     *   checkout_currency:string,
     *   available_base_minor:int,
     *   available_checkout_minor:int,
     *   max_apply_checkout_minor:int,
     *   deposit_amount_minor:int,
     *   fx:?array{from:string,to:string,rate:string,label:string},
     *   hint_short:string,
     *   hint_detail:string
     * }
     */
    public function quote(
        string $customerId,
        int $websiteId,
        string $checkoutCurrency,
        int $depositAmountMinor,
    ): array {
        $base = $this->currency()->forWebsite($websiteId);
        $checkout = strtoupper(trim($checkoutCurrency)) ?: $base;
        $depositAmountMinor = max(0, $depositAmountMinor);
        $empty = [
            'enabled' => false,
            'reason' => '',
            'base_currency' => $base,
            'checkout_currency' => $checkout,
            'available_base_minor' => 0,
            'available_checkout_minor' => 0,
            'max_apply_checkout_minor' => 0,
            'deposit_amount_minor' => $depositAmountMinor,
            'min_cash_deposit_minor' => 0,
            'min_cash_deposit_bps' => 0,
            'website_id' => $websiteId,
            'fx' => null,
            'hint_short' => '',
            'hint_detail' => '',
        ];

        if (!$this->isDiscountAssetEnabled(self::ASSET_B2B_CREDIT, $websiteId)) {
            return array_merge($empty, [
                'reason' => 'b2b_credit_disabled',
                'hint_short' => (string)__('站点未开启批发信用额度'),
                'hint_detail' => (string)__('后台售卖模式中关闭了「启用批发信用额度」时，结账不可抵扣。'),
            ]);
        }
        $customerId = trim($customerId);
        if ($customerId === '') {
            return array_merge($empty, [
                'reason' => 'not_logged_in',
                'hint_short' => (string)__('请先登录后再使用批发信用'),
                'hint_detail' => (string)__('批发信用绑定账户钱包，登录后才能查询余额并抵扣本期定金。'),
            ]);
        }
        if ($depositAmountMinor <= 0) {
            return array_merge($empty, [
                'reason' => 'not_applicable',
                'hint_short' => (string)__('暂无法估算本期定金，无法用批发信用抵扣'),
                'hint_detail' => (string)__('请确认购物车有商品后再试；批发信用只抵本期定金（一般为含税商品小计 30%），不抵尾款；且须保留站点配置的最低现金定金占比。'),
            ]);
        }

        $availableBase = $this->reservableMinor($customerId, $websiteId);
        if ($availableBase <= 0) {
            return array_merge($empty, [
                'reason' => 'no_balance',
                'available_base_minor' => 0,
                'hint_short' => (string)__('额度不够：暂无可用批发信用余额'),
                'hint_detail' => (string)__('批发信用钱包余额为 0。开启批发信用后，消费达标升档会按本档折扣额度补差额；了解更多见帮助页。'),
            ]);
        }

        $fx = $this->currency()->rateSnapshot($base, $checkout, $websiteId);
        $availableCheckout = $this->currency()->convertMinorFromWebsiteDefault(
            $availableBase,
            $checkout,
            $websiteId,
        );
        if ($availableCheckout === null) {
            return array_merge($empty, [
                'reason' => 'fx_unavailable',
                'available_base_minor' => $availableBase,
                'hint_short' => (string)__('暂无汇率，无法使用批发信用'),
            ]);
        }

        $minCashBps = $this->minCashDepositBps($websiteId);
        $minCashMinor = self::minCashDepositMinor($depositAmountMinor, $minCashBps);
        $creditCap = max(0, $depositAmountMinor - $minCashMinor);
        $maxApplyCheckout = min($availableCheckout, $creditCap);
        $same = $base === $checkout;
        $baseAmt = number_format($availableBase / 100, 2, '.', '');
        $checkoutAmt = number_format($availableCheckout / 100, 2, '.', '');
        $maxAmt = number_format($maxApplyCheckout / 100, 2, '.', '');
        $hintShort = $same
            ? (string)__('基准货币 %{1} 可用 %{2}；本单最多可抵 %{3} %{1}。', [$base, $baseAmt, $maxAmt])
            : (string)__('基准货币 %{1} 可用 %{2}，折合 %{3} %{4}；本单最多可抵 %{5} %{3}。', [
                $base,
                $baseAmt,
                $checkout,
                $checkoutAmt,
                $maxAmt,
            ]);
        if ($minCashMinor > 0) {
            $hintShort .= ' ' . (string)__('定金至少保留现金 %{1} %{2}。', [
                number_format($minCashMinor / 100, 2, '.', ''),
                $checkout,
            ]);
        }
        $hintDetail = $this->buildHintDetail(
            $base,
            $checkout,
            $fx,
            $availableBase,
            $availableCheckout,
            $maxApplyCheckout,
            $depositAmountMinor,
            $minCashMinor,
            $minCashBps,
        );

        return [
            'enabled' => $maxApplyCheckout > 0,
            'reason' => $maxApplyCheckout > 0 ? '' : 'zero_cap',
            'base_currency' => $base,
            'checkout_currency' => $checkout,
            'available_base_minor' => $availableBase,
            'available_checkout_minor' => $availableCheckout,
            'max_apply_checkout_minor' => $maxApplyCheckout,
            'deposit_amount_minor' => $depositAmountMinor,
            'min_cash_deposit_minor' => $minCashMinor,
            'min_cash_deposit_bps' => $minCashBps,
            'website_id' => $websiteId,
            'fx' => $fx,
            'hint_short' => $maxApplyCheckout > 0
                ? $hintShort
                : (string)__('额度不够：本单可抵扣额度为 0'),
            'hint_detail' => $hintDetail,
        ];
    }

    /**
     * Fail-closed stub when quote cannot run (guest / exception / missing deposit).
     *
     * @return array{
     *   enabled:bool,
     *   reason:string,
     *   hint_short:string,
     *   hint_detail:string,
     *   available_base_minor:int,
     *   available_checkout_minor:int,
     *   max_apply_checkout_minor:int,
     *   deposit_amount_minor:int
     * }
     */
    public static function unavailableStub(string $reason, int $depositAmountMinor = 0): array
    {
        $reason = trim($reason) !== '' ? trim($reason) : 'quote_failed';
        $hints = [
            'not_logged_in' => [
                (string)__('请先登录后再使用批发信用'),
                (string)__('批发信用绑定账户钱包，登录后才能查询余额并抵扣本期定金。'),
            ],
            'not_applicable' => [
                (string)__('暂无法估算本期定金，无法用批发信用抵扣'),
                (string)__('请确认购物车有商品后再试；批发信用只抵本期定金（一般为含税商品小计 30%），不抵尾款；且须保留站点配置的最低现金定金占比。'),
            ],
            'quote_failed' => [
                (string)__('暂时无法获取批发信用报价，请刷新后重试'),
                (string)__('结账报价未返回可用额度；刷新页面后仍不可用请联系客服。'),
            ],
            'no_balance' => [
                (string)__('额度不够：暂无可用批发信用余额'),
                (string)__('批发信用钱包余额为 0。开启批发信用后，消费达标升档会按本档折扣额度补差额；了解更多见帮助页。'),
            ],
            'zero_cap' => [
                (string)__('额度不够：本单可抵扣额度为 0'),
                (string)__('可用余额、本期定金上限或最低现金占比导致本单无可抵扣额度。'),
            ],
        ];
        $pair = $hints[$reason] ?? $hints['quote_failed'];

        return [
            'enabled' => false,
            'reason' => $reason,
            'base_currency' => '',
            'checkout_currency' => '',
            'available_base_minor' => 0,
            'available_checkout_minor' => 0,
            'max_apply_checkout_minor' => 0,
            'deposit_amount_minor' => max(0, $depositAmountMinor),
            'fx' => null,
            'hint_short' => $pair[0],
            'hint_detail' => $pair[1],
        ];
    }

    /**
     * Clamp user input (checkout minor) using frozen quote currencies.
     *
     * @param array<string,mixed> $quote from quote()
     * @return array{
     *   apply_checkout_minor:int,
     *   apply_base_minor:int,
     *   cash_deposit_minor:int,
     *   adjusted:bool,
     *   fx:?array{from:string,to:string,rate:string,label:string}
     * }
     */
    public function clampApply(array $quote, int $applyCheckoutMinor): array
    {
        $deposit = max(0, (int)($quote['deposit_amount_minor'] ?? 0));
        $minCashBps = max(0, min(10000, (int)($quote['min_cash_deposit_bps'] ?? 0)));
        $minCash = array_key_exists('min_cash_deposit_minor', $quote)
            ? max(0, (int)$quote['min_cash_deposit_minor'])
            : self::minCashDepositMinor($deposit, $minCashBps);
        $creditCap = max(0, $deposit - $minCash);
        $maxCheckout = max(0, min((int)($quote['max_apply_checkout_minor'] ?? 0), $creditCap));
        $availableBase = max(0, (int)($quote['available_base_minor'] ?? 0));
        $base = strtoupper(trim((string)($quote['base_currency'] ?? 'CNY'))) ?: 'CNY';
        $checkout = strtoupper(trim((string)($quote['checkout_currency'] ?? '')));
        $websiteId = max(0, (int)($quote['website_id'] ?? 0));
        // Empty checkout currency must not fall through to identity conversion (1:1 number bug).
        if ($checkout === '') {
            return [
                'apply_checkout_minor' => 0,
                'apply_base_minor' => 0,
                'cash_deposit_minor' => $deposit,
                'adjusted' => true,
                'fx' => is_array($quote['fx'] ?? null) ? $quote['fx'] : null,
            ];
        }
        $applyCheckout = max(0, min($applyCheckoutMinor, $maxCheckout, $creditCap));
        $adjusted = $applyCheckout !== max(0, $applyCheckoutMinor);

        $applyBase = $this->currency()->convertMinorToWebsiteDefault($applyCheckout, $checkout, $websiteId);
        if ($applyBase === null && $base === $checkout) {
            $applyBase = $applyCheckout;
        }
        if ($applyBase === null) {
            return [
                'apply_checkout_minor' => 0,
                'apply_base_minor' => 0,
                'cash_deposit_minor' => $deposit,
                'adjusted' => true,
                'fx' => is_array($quote['fx'] ?? null) ? $quote['fx'] : null,
            ];
        }
        if ($applyBase > $availableBase) {
            $applyBase = $availableBase;
            $back = $this->currency()->convertMinorFromWebsiteDefault($applyBase, $checkout, $websiteId);
            if ($back !== null) {
                $applyCheckout = min($applyCheckout, $back);
            }
            $adjusted = true;
        }

        return [
            'apply_checkout_minor' => $applyCheckout,
            'apply_base_minor' => $applyBase,
            'cash_deposit_minor' => max(0, $deposit - $applyCheckout),
            'adjusted' => $adjusted,
            'fx' => is_array($quote['fx'] ?? null) ? $quote['fx'] : null,
        ];
    }

    /**
     * Split total apply_checkout across deposits; last order absorbs base residue.
     *
     * @param list<int> $depositMinorsPerOrder checkout-currency deposit per order
     * @return list<array{apply_checkout_minor:int,apply_base_minor:int,cash_deposit_minor:int}>
     */
    public function splitAcrossDeposits(
        array $quote,
        int $totalApplyCheckoutMinor,
        array $depositMinorsPerOrder,
        int $websiteId = 0,
    ): array {
        $clamped = $this->clampApply($quote, $totalApplyCheckoutMinor);
        $totalCheckout = $clamped['apply_checkout_minor'];
        $totalBase = $clamped['apply_base_minor'];
        $sumDeposit = array_sum(array_map(static fn ($d) => max(0, (int)$d), $depositMinorsPerOrder));
        $out = [];
        $assignedCheckout = 0;
        $assignedBase = 0;
        $n = count($depositMinorsPerOrder);
        foreach ($depositMinorsPerOrder as $i => $dep) {
            $dep = max(0, (int)$dep);
            if ($i === $n - 1) {
                $partCheckout = max(0, $totalCheckout - $assignedCheckout);
                $partBase = max(0, $totalBase - $assignedBase);
            } elseif ($sumDeposit <= 0 || $totalCheckout <= 0) {
                $partCheckout = 0;
                $partBase = 0;
            } else {
                $partCheckout = (int)floor($totalCheckout * $dep / $sumDeposit);
                $partBase = (int)floor($totalBase * $dep / $sumDeposit);
            }
            $partCheckout = min($partCheckout, $dep);
            $assignedCheckout += $partCheckout;
            $assignedBase += $partBase;
            $out[] = [
                'apply_checkout_minor' => $partCheckout,
                'apply_base_minor' => $partBase,
                'cash_deposit_minor' => max(0, $dep - $partCheckout),
            ];
        }

        return $out;
    }

    private function reservableMinor(string $customerId, int $websiteId): int
    {
        try {
            $assets = $this->assets();
            if ($assets === null) {
                return 0;
            }
            $row = $assets->getBalance(
                $customerId,
                $websiteId,
                self::ASSET_B2B_CREDIT,
                AssetAccount::NS_LIVE,
            );
            return max(0, (int)($row['reservable_minor'] ?? $row['available_minor'] ?? 0));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function buildHintDetail(
        string $base,
        string $checkout,
        ?array $fx,
        int $availableBase,
        int $availableCheckout,
        int $maxApply,
        int $depositAmountMinor = 0,
        int $minCashMinor = 0,
        int $minCashBps = 0,
    ): string {
        $rateLabel = is_array($fx) ? (string)($fx['label'] ?? '') : '';
        $lines = [
            (string)__('批发信用只抵本期定金（约商品小计 30%），不抵尾款；同档消费后额度不自动回补。'),
            (string)__('基准货币：%{1}', [$base]),
            (string)__('结账货币：%{1}', [$checkout]),
        ];
        if ($rateLabel !== '') {
            $lines[] = (string)__('本单汇率：%{1}', [$rateLabel]);
        }
        $lines[] = (string)__('公式：信用抵扣(结账币) ↔ 钱包扣减(基准币)，按汇率换算，四舍五入到分。');
        $lines[] = (string)__('可用钱包 %{1} %{2}（约 %{3} %{4}）；本单最多抵扣 %{5} %{4}。', [
            number_format($availableBase / 100, 2, '.', ''),
            $base,
            number_format($availableCheckout / 100, 2, '.', ''),
            $checkout,
            number_format($maxApply / 100, 2, '.', ''),
        ]);
        if ($depositAmountMinor > 0) {
            $lines[] = (string)__('本期定金 %{1} %{2}。', [
                number_format($depositAmountMinor / 100, 2, '.', ''),
                $checkout,
            ]);
        }
        if ($minCashMinor > 0) {
            $pct = intdiv(max(0, min(10000, $minCashBps)), 100);
            $lines[] = (string)__('最低现金占比 %{1}%：现金定金至少 %{2} %{3}。', [
                (string)$pct,
                number_format($minCashMinor / 100, 2, '.', ''),
                $checkout,
            ]);
        }
        $lines[] = (string)__('规则说明见帮助页 /faq/b2b-wholesale。');

        return implode("\n", $lines);
    }

    /**
     * Minimum cash required from deposit given basis points (ceil).
     */
    public static function minCashDepositMinor(int $depositAmountMinor, int $minCashDepositBps): int
    {
        $depositAmountMinor = max(0, $depositAmountMinor);
        $minCashDepositBps = max(0, min(10000, $minCashDepositBps));
        if ($depositAmountMinor <= 0 || $minCashDepositBps <= 0) {
            return 0;
        }
        // ceil(deposit * bps / 10000)
        return intdiv($depositAmountMinor * $minCashDepositBps + 9999, 10000);
    }

    private function minCashDepositBps(int $websiteId): int
    {
        if ($this->minCashDepositBpsOverride !== null) {
            return max(0, min(10000, $this->minCashDepositBpsOverride));
        }
        try {
            $svc = $this->assetPayment();
            $policy = $svc->getPolicyForScope([
                'website_id' => $websiteId,
                'order_type' => 'tob',
            ]);
            $row = is_array($policy[self::ASSET_B2B_CREDIT] ?? null) ? $policy[self::ASSET_B2B_CREDIT] : [];

            return max(0, min(10000, (int)($row['min_cash_deposit_bps'] ?? 0)));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function currency(): WebsiteBenchmarkCurrencyResolver
    {
        // Constructor props are readonly — never assign via ??=. Resolve once per call when null.
        if ($this->currency instanceof WebsiteBenchmarkCurrencyResolver) {
            return $this->currency;
        }

        return new WebsiteBenchmarkCurrencyResolver();
    }

    private function isDiscountAssetEnabled(string $assetCode, int $websiteId): bool
    {
        if ($this->policyEnabledOverride !== null) {
            return $this->policyEnabledOverride;
        }
        try {
            $svc = $this->assetPayment();
            $policy = $svc->getPolicyForScope([
                'website_id' => $websiteId,
                'order_type' => 'tob',
            ]);
            $row = is_array($policy[$assetCode] ?? null) ? $policy[$assetCode] : [];
            if (!(bool)($row['enabled'] ?? false)) {
                return false;
            }
            $roles = is_array($row['roles'] ?? null) ? $row['roles'] : [];
            return !empty($roles[AssetAllocationService::ROLE_DISCOUNT]);
        } catch (\Throwable) {
            return false;
        }
    }

    private function assetPayment(): AssetPaymentService
    {
        if ($this->assetPayment instanceof AssetPaymentService) {
            return $this->assetPayment;
        }

        return ObjectManager::getInstance(AssetPaymentService::class);
    }

    private function assets(): ?CustomerAssetFacadeInterface
    {
        if ($this->assets instanceof CustomerAssetFacadeInterface) {
            return $this->assets;
        }
        try {
            $resolved = ObjectManager::getInstance(CustomerAssetFacadeInterface::class);
            return $resolved instanceof CustomerAssetFacadeInterface ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
