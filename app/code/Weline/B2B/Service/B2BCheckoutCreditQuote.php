<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\SystemVipLadder;
use Weline\CustomerAsset\Api\CustomerAssetFacadeInterface;
use Weline\CustomerAsset\Model\AssetAccount;
use Weline\Framework\Manager\ObjectManager;

/**
 * Quote wholesale credit apply amounts for tob deposit (checkout currency UI,
 * website-default wallet). FX fail-closed; base wallet is authoritative after clamp.
 */
final class B2BCheckoutCreditQuote
{
    public function __construct(
        private readonly ?B2BBaseCurrencyResolver $currency = null,
        private readonly ?B2BPaymentAssetPolicyProvider $policy = null,
        private readonly ?CustomerAssetFacadeInterface $assets = null,
    ) {
    }

    public static function forTesting(
        ?B2BBaseCurrencyResolver $currency = null,
        ?B2BPaymentAssetPolicyProvider $policy = null,
        ?CustomerAssetFacadeInterface $assets = null,
    ): self {
        return new self($currency, $policy, $assets);
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
            'website_id' => $websiteId,
            'fx' => null,
            'hint_short' => '',
            'hint_detail' => '',
        ];

        if (!$this->policy()->isEnabled()) {
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
                'hint_detail' => (string)__('批发信用绑定账户钱包，登录后才能查询余额并抵扣定金。'),
            ]);
        }
        if ($depositAmountMinor <= 0) {
            return array_merge($empty, [
                'reason' => 'not_applicable',
                'hint_short' => (string)__('当前订单无定金，无法用批发信用抵扣'),
                'hint_detail' => (string)__('需存在可支付定金后，才可使用批发信用。'),
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

        $maxApplyCheckout = min($availableCheckout, $depositAmountMinor);
        $same = $base === $checkout;
        $hintShort = $same
            ? (string)__('从批发信用余额扣减（与订单同币）。')
            : (string)__('按站点基准货币换算后从批发信用扣减；与营销券无关。');
        $hintDetail = $this->buildHintDetail($base, $checkout, $fx, $availableBase, $availableCheckout, $maxApplyCheckout);

        return [
            'enabled' => $maxApplyCheckout > 0,
            'reason' => $maxApplyCheckout > 0 ? '' : 'zero_cap',
            'base_currency' => $base,
            'checkout_currency' => $checkout,
            'available_base_minor' => $availableBase,
            'available_checkout_minor' => $availableCheckout,
            'max_apply_checkout_minor' => $maxApplyCheckout,
            'deposit_amount_minor' => $depositAmountMinor,
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
                (string)__('批发信用绑定账户钱包，登录后才能查询余额并抵扣定金。'),
            ],
            'not_applicable' => [
                (string)__('当前订单无定金，无法用批发信用抵扣'),
                (string)__('需存在可支付定金后，才可使用批发信用。'),
            ],
            'quote_failed' => [
                (string)__('暂时无法获取批发信用报价，请刷新后重试'),
                (string)__('结账报价未返回可用额度；刷新页面后仍不可用请联系客服。'),
            ],
            'no_balance' => [
                (string)__('额度不够：暂无可用批发信用余额'),
                (string)__('批发信用钱包余额为 0。'),
            ],
            'zero_cap' => [
                (string)__('额度不够：本单可抵扣额度为 0'),
                (string)__('可用余额或定金上限导致本单无可抵扣额度。'),
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
        $maxCheckout = max(0, (int)($quote['max_apply_checkout_minor'] ?? 0));
        $availableBase = max(0, (int)($quote['available_base_minor'] ?? 0));
        $base = (string)($quote['base_currency'] ?? 'CNY');
        $checkout = (string)($quote['checkout_currency'] ?? $base);
        $websiteId = max(0, (int)($quote['website_id'] ?? 0));
        $applyCheckout = max(0, min($applyCheckoutMinor, $maxCheckout, $deposit));
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
                SystemVipLadder::ASSET_CODE_B2B_CREDIT,
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
    ): string {
        $rateLabel = is_array($fx) ? (string)($fx['label'] ?? '') : '';
        $lines = [
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

        return implode("\n", $lines);
    }

    private function currency(): B2BBaseCurrencyResolver
    {
        return $this->currency ??= new B2BBaseCurrencyResolver();
    }

    private function policy(): B2BPaymentAssetPolicyProvider
    {
        return $this->policy ??= new B2BPaymentAssetPolicyProvider();
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
