<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\PaymentMethodIncentiveQuoteInterface;
use Weline\Payment\Service\PaymentMethodManager;

/**
 * 选中支付方式后把激励并入 totals / discount_lines（SPI 归属 Payment；本类仅编排）。
 */
final class CheckoutPaymentIncentiveApplier
{
    public function __construct(private readonly ?ObjectManager $objectManager = null)
    {
    }

    /**
     * @param list<array<string, mixed>> $existingDiscountLines
     * @return array{
     *   savings_minor:int,
     *   grand_total_minor:int,
     *   discount_lines:list<array<string, mixed>>,
     *   payment_method_incentive_amount_minor:int,
     *   totals:array<string, mixed>
     * }
     */
    public function apply(
        string $paymentMethod,
        int $baselineGrandMinor,
        string $currencyCode,
        array $existingDiscountLines = [],
        bool $discountsBanned = false,
        array $scopeContext = [],
    ): array {
        $baseline = max(0, $baselineGrandMinor);
        $currency = strtoupper(trim($currencyCode)) ?: 'CNY';
        $method = strtolower(trim($paymentMethod));
        if ($discountsBanned || $method === '' || $baseline <= 0) {
            return $this->passthrough($baseline, $currency, $existingDiscountLines);
        }

        try {
            $om = $this->objectManager ?? ObjectManager::getInstance();
            /** @var PaymentMethodManager $manager */
            $manager = $om->getInstance(PaymentMethodManager::class);
            $paymentMethodRow = $manager->getMethodByCode($method);
            if ($paymentMethodRow === null) {
                return $this->passthrough($baseline, $currency, $existingDiscountLines);
            }
            $runtime = $manager->getRuntimeConfig($paymentMethodRow, $scopeContext + [
                'website_id' => (int) ($scopeContext['website_id'] ?? 0),
                'store_id' => (int) ($scopeContext['store_id'] ?? 0),
                'currency' => $currency,
                'currency_code' => $currency,
            ]);
            /** @var PaymentMethodIncentiveQuoteInterface $quote */
            $quote = $om->getInstance(PaymentMethodIncentiveQuoteInterface::class);
            $applied = $quote->applyToOrderData($method, [
                'amount_minor' => $baseline,
                'amount' => $baseline / 100,
                'currency' => $currency,
                'currency_code' => $currency,
                'discount_lines' => $existingDiscountLines,
                'totals' => [
                    'grand_total_minor' => $baseline,
                    'currency' => $currency,
                ],
            ], $runtime);
            $orderData = \is_array($applied['order_data'] ?? null) ? $applied['order_data'] : [];
            $savings = max(0, (int) ($applied['savings_minor'] ?? 0));
            $lines = \is_array($orderData['discount_lines'] ?? null) ? $orderData['discount_lines'] : $existingDiscountLines;
            $grand = max(0, (int) ($orderData['amount_minor'] ?? ($baseline - $savings)));
            $totals = \is_array($orderData['totals'] ?? null) ? $orderData['totals'] : [
                'grand_total_minor' => $grand,
                'discount_amount_minor' => $savings,
                'currency' => $currency,
            ];

            return [
                'savings_minor' => $savings,
                'grand_total_minor' => $grand,
                'discount_lines' => array_values(array_filter(
                    $lines,
                    static fn (mixed $line): bool => \is_array($line),
                )),
                'payment_method_incentive_amount_minor' => $savings > 0 ? -1 * $savings : 0,
                'totals' => $totals,
            ];
        } catch (\Throwable) {
            return $this->passthrough($baseline, $currency, $existingDiscountLines);
        }
    }

    /**
     * @param array<string, mixed> $orderData
     * @return array<string, mixed>
     */
    public function applyToPaymentContext(string $paymentMethod, array $orderData): array
    {
        $method = strtolower(trim($paymentMethod));
        if ($method === '') {
            return $orderData;
        }
        $currency = strtoupper(trim((string) ($orderData['currency'] ?? $orderData['currency_code'] ?? 'CNY'))) ?: 'CNY';
        $baseline = (int) ($orderData['amount_minor'] ?? round(((float) ($orderData['amount'] ?? 0)) * 100));
        $lines = \is_array($orderData['discount_lines'] ?? null) ? $orderData['discount_lines'] : [];
        $result = $this->apply(
            $method,
            $baseline,
            $currency,
            $lines,
            false,
            [
                'website_id' => (int) ($orderData['website_id'] ?? 0),
                'store_id' => (int) ($orderData['store_id'] ?? 0),
                'currency' => $currency,
            ],
        );
        $orderData['amount_minor'] = $result['grand_total_minor'];
        $orderData['amount'] = $result['grand_total_minor'] / 100;
        $orderData['discount_lines'] = $result['discount_lines'];
        $orderData['payment_method_incentive_amount_minor'] = $result['payment_method_incentive_amount_minor'];
        $totals = \is_array($orderData['totals'] ?? null) ? $orderData['totals'] : [];
        $orderData['totals'] = array_replace($totals, $result['totals'], [
            'grand_total_minor' => $result['grand_total_minor'],
            'currency' => $currency,
        ]);

        return $orderData;
    }

    /**
     * @param list<array<string, mixed>> $existingDiscountLines
     * @return array{
     *   savings_minor:int,
     *   grand_total_minor:int,
     *   discount_lines:list<array<string, mixed>>,
     *   payment_method_incentive_amount_minor:int,
     *   totals:array<string, mixed>
     * }
     */
    private function passthrough(int $baseline, string $currency, array $existingDiscountLines): array
    {
        return [
            'savings_minor' => 0,
            'grand_total_minor' => $baseline,
            'discount_lines' => array_values(array_filter(
                $existingDiscountLines,
                static fn (mixed $line): bool => \is_array($line),
            )),
            'payment_method_incentive_amount_minor' => 0,
            'totals' => [
                'grand_total_minor' => $baseline,
                'currency' => $currency,
            ],
        ];
    }
}
