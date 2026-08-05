<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Weline\Subscription\Api\SubscriptionOrderPortInterface;

/**
 * Memory Order port for TEST-P4B-02 — one new order_ref per period_key.
 */
final class ArraySubscriptionOrderPort implements SubscriptionOrderPortInterface
{
    /** @var array<string, array<string, mixed>> period_key => order */
    private array $orders = [];

    private bool $failNext = false;

    public static function forTesting(): self
    {
        return new self();
    }

    public function failNext(bool $fail = true): void
    {
        $this->failNext = $fail;
    }

    public function createPeriodOrder(array $command): array
    {
        $periodKey = trim((string) ($command['period_key'] ?? ''));
        if ($periodKey === '') {
            throw new \InvalidArgumentException('subscription_order_period_key_required');
        }
        if (isset($this->orders[$periodKey])) {
            return [
                'ok' => true,
                'order_ref' => (string) $this->orders[$periodKey]['order_ref'],
                'replayed' => true,
            ];
        }
        if ($this->failNext) {
            $this->failNext = false;
            throw new SubscriptionConflictException(
                'subscription_order_port_failed',
                __('Subscription Order 创建失败（测试注入）'),
                ['period_key' => $periodKey],
            );
        }
        $orderRef = 'ord_sub_' . substr(hash('sha256', $periodKey . '|' . microtime(true)), 0, 12);
        $this->orders[$periodKey] = [
            'order_ref' => $orderRef,
            'period_key' => $periodKey,
            'subscription_id' => (string) ($command['subscription_id'] ?? ''),
            'website_id' => (int) ($command['website_id'] ?? 0),
            'customer_id' => (string) ($command['customer_id'] ?? ''),
            'amount_minor' => (int) ($command['amount_minor'] ?? 0),
            'created_at' => gmdate('c'),
        ];

        return ['ok' => true, 'order_ref' => $orderRef, 'replayed' => false];
    }

    public function orderCount(): int
    {
        return count($this->orders);
    }

    /** @return list<string> */
    public function orderRefs(): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) $row['order_ref'],
            $this->orders,
        ));
    }
}
