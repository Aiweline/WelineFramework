<?php

declare(strict_types=1);

namespace Weline\Subscription\Api;

/**
 * Public P4B Subscription identity/state boundary.
 *
 * Order, Payment, Queue and Customer internals are intentionally excluded.
 */
interface SubscriptionFacadeInterface
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array;

    /**
     * @return array<string, mixed>
     */
    public function get(string $subscriptionId): array;

    /**
     * @return array<string, mixed>
     */
    public function assertOwner(string $subscriptionId, string $customerId): array;

    /**
     * @return array<string, mixed>
     */
    public function cancel(string $subscriptionId, string $customerId, int $expectedVersion): array;
}
