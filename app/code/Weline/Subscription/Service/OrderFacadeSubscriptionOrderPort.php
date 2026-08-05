<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Weline\Order\Api\Data\CreateCheckoutGroupCommand;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Subscription\Api\SubscriptionOrderPortInterface;

/** Production Subscription → Order Facade adapter. */
final class OrderFacadeSubscriptionOrderPort implements SubscriptionOrderPortInterface
{
    public function __construct(
        private readonly OrderFacadeInterface $orders,
    ) {
    }

    public function createPeriodOrder(array $command): array
    {
        $periodKey = trim((string) ($command['period_key'] ?? ''));
        $subscriptionId = trim((string) ($command['subscription_id'] ?? ''));
        $planCode = trim((string) ($command['plan_code'] ?? ''));
        $customerId = trim((string) ($command['customer_id'] ?? ''));
        $websiteId = (int) ($command['website_id'] ?? -1);
        $storeId = (int) ($command['store_id'] ?? -1);
        $amountMinor = (int) ($command['amount_minor'] ?? -1);
        $currency = strtoupper(trim((string) ($command['currency'] ?? 'CNY')));
        if ($periodKey === '' || $subscriptionId === '' || $planCode === ''
            || $websiteId < 0 || $storeId < 0 || $amountMinor < 0 || $currency === ''
        ) {
            throw new \InvalidArgumentException(__('Subscription Order command 非法'));
        }

        $request = [
            'period_key' => $periodKey,
            'subscription_id' => $subscriptionId,
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'plan_code' => $planCode,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
        ];
        $requestHash = hash(
            'sha256',
            json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        );
        $created = $this->orders->create(new CreateCheckoutGroupCommand(
            idempotencyKey: 'subscription-period-' . hash('sha256', $periodKey),
            requestHash: $requestHash,
            websiteId: $websiteId,
            storeId: $storeId,
            currency: $currency,
            customerId: ctype_digit($customerId) ? (int) $customerId : null,
            lines: [[
                'sku' => $planCode,
                'name' => $planCode,
                'qty_minor' => 1,
                'unit_price_minor' => $amountMinor,
                'split_key' => 'subscription:' . $subscriptionId,
                'requires_shipping' => false,
                'currency' => $currency,
            ]],
            options: [
                'source' => 'subscription',
                'subscription_id' => $subscriptionId,
                'period_key' => $periodKey,
            ],
        ));
        if (count($created->orderUuids) !== 1) {
            throw new SubscriptionConflictException(
                'subscription_order_cardinality_invalid',
                __('Subscription Period 必须创建且只创建一个 Order'),
                ['period_key' => $periodKey, 'order_count' => count($created->orderUuids)],
            );
        }

        return [
            'ok' => true,
            'order_ref' => (string) $created->orderUuids[0],
            'replayed' => $created->replayed,
        ];
    }
}

