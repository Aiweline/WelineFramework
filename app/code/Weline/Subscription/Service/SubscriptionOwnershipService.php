<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

/**
 * Customer ownership guard for Subscription（P4B-001）.
 */
final class SubscriptionOwnershipService
{
    public const ERROR_NOT_OWNER = 'subscription_not_owner';

    public function __construct(
        private readonly SubscriptionStore $store,
    ) {
    }

    public static function forTesting(?SubscriptionStore $store = null): self
    {
        return new self($store ?? SubscriptionStore::forTesting());
    }

    /**
     * @return array<string, mixed>
     */
    public function assertOwner(string $subscriptionId, string $customerId): array
    {
        $row = $this->store->get($subscriptionId);
        if ((string) $row['customer_id'] !== trim($customerId)) {
            throw new SubscriptionConflictException(
                self::ERROR_NOT_OWNER,
                __('客户无权操作该订阅'),
                [
                    'subscription_id' => $subscriptionId,
                    'customer_id' => $customerId,
                ],
            );
        }

        return $row;
    }
}
