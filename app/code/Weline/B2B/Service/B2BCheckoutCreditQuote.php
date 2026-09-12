<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\AssetCheckoutDiscountQuote;

/**
 * 薄委托：批发信用「类型」由 B2B payment.asset_policy 声明；
 * 额度数据由 Payment AssetCheckoutDiscountQuote 贡献。
 *
 * @deprecated 请优先直接使用 Payment\Service\AssetCheckoutDiscountQuote
 */
final class B2BCheckoutCreditQuote
{
    public function __construct(
        private readonly ?AssetCheckoutDiscountQuote $paymentQuote = null,
    ) {
    }

    public static function forTesting(?AssetCheckoutDiscountQuote $paymentQuote = null): self
    {
        return new self($paymentQuote);
    }

    public function quote(
        string $customerId,
        int $websiteId,
        string $checkoutCurrency,
        int $depositAmountMinor,
    ): array {
        return $this->inner()->quote($customerId, $websiteId, $checkoutCurrency, $depositAmountMinor);
    }

    public static function unavailableStub(string $reason, int $depositAmountMinor = 0): array
    {
        return AssetCheckoutDiscountQuote::unavailableStub($reason, $depositAmountMinor);
    }

    public function clampApply(array $quote, int $applyCheckoutMinor): array
    {
        return $this->inner()->clampApply($quote, $applyCheckoutMinor);
    }

    public function splitAcrossDeposits(
        array $quote,
        int $totalApplyCheckoutMinor,
        array $depositMinorsPerOrder,
        int $websiteId = 0,
    ): array {
        return $this->inner()->splitAcrossDeposits(
            $quote,
            $totalApplyCheckoutMinor,
            $depositMinorsPerOrder,
            $websiteId,
        );
    }

    private function inner(): AssetCheckoutDiscountQuote
    {
        if ($this->paymentQuote instanceof AssetCheckoutDiscountQuote) {
            return $this->paymentQuote;
        }

        return ObjectManager::getInstance(AssetCheckoutDiscountQuote::class);
    }
}
