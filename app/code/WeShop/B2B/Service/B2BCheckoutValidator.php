<?php

declare(strict_types=1);

namespace WeShop\B2B\Service;

class B2BCheckoutValidator
{
    public const PAYMENT_CODE = 'b2b_credit_account';

    public function __construct(
        private readonly B2bCustomerService $b2bCustomerService,
        private readonly CreditService $creditService,
        private readonly B2BOrderService $b2bOrderService
    ) {
    }

    /**
     * @param array<string, mixed> $checkoutData
     */
    public function validate(array $checkoutData): void
    {
        $method = (string) ($checkoutData['payment_method'] ?? '');
        if ($method !== self::PAYMENT_CODE) {
            return;
        }

        $customerId = (int) ($checkoutData['customer_id'] ?? 0);
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('A customer account is required for B2B credit checkout.'));
        }

        $profile = $this->b2bCustomerService->getByCustomerId($customerId);
        if ($profile === null || (int) ($profile->getData(\WeShop\B2B\Model\B2bCustomer::schema_fields_STATUS) ?? 0) !== 1) {
            throw new \InvalidArgumentException((string) __('B2B credit payment is only available for approved enterprise accounts.'));
        }

        $grandTotal = $this->resolveGrandTotal($checkoutData);
        $this->creditService->assertCanUseCredit($customerId, $grandTotal);

        $termId = (int) ($checkoutData['b2b_payment_term_id'] ?? 0);
        $this->b2bOrderService->resolveCheckoutContext($customerId, $grandTotal, $termId > 0 ? $termId : null);
    }

    /**
     * @param array<string, mixed> $checkoutData
     */
    private function resolveGrandTotal(array $checkoutData): float
    {
        $fromSummary = $checkoutData['summary'] ?? $checkoutData['totals'] ?? [];
        if (\is_array($fromSummary)) {
            if (isset($fromSummary['grand_total'])) {
                return max(0.0, round((float) $fromSummary['grand_total'], 2));
            }
            if (isset($fromSummary['total'])) {
                return max(0.0, round((float) $fromSummary['total'], 2));
            }
        }

        $customerId = (int) ($checkoutData['customer_id'] ?? 0);
        if ($customerId > 0) {
            $totals = w_query('cart', 'calculateTotals', ['customer_id' => $customerId]);
            if (\is_array($totals) && isset($totals['total'])) {
                return max(0.0, round((float) $totals['total'], 2));
            }
        }

        return max(0.0, round((float) ($checkoutData['grand_total'] ?? 0), 2));
    }
}
