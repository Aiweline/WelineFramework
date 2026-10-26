<?php

declare(strict_types=1);

namespace Weline\Points\Service;

use Weline\Payment\Api\PaymentAssetPolicyProviderInterface;

/**
 * Contributes the points discount policy to Payment.
 *
 * Default cap is 30% of the payable; configured through Points settings.
 */
final class PointsPaymentAssetPolicyProvider implements PaymentAssetPolicyProviderInterface
{
    public function __construct(
        private readonly PointsConfig $config,
    ) {
    }

    public function getAssetPolicies(array $context = []): array
    {
        if (!$this->config->discountEnabled()) {
            return [];
        }
        $ratio = $this->config->paymentExchangeRatio();
        if ($ratio <= 0 || !$this->config->paymentPointsEnabled()) {
            return [];
        }
        $maxRatio = $this->config->discountMaxRatio();
        $minCashBps = (int) round((1 - $maxRatio) * 10000);

        return [
            PointsService::ASSET_CODE => [
                'enabled' => true,
                'roles' => [
                    'payment' => false,
                    'discount' => true,
                ],
                'exchange_ratio' => rtrim(rtrim(number_format($ratio, 8, '.', ''), '0'), '.') ?: '0',
                'max_discount_ratio' => number_format($maxRatio, 4, '.', ''),
                'min_cash_deposit_bps' => $minCashBps,
                'allowed_payable_types' => [],
                'required_order_types' => [],
                'refund_strategy' => 'allocation',
            ],
        ];
    }
}
