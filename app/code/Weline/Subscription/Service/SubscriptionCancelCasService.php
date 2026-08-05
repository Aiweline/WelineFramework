<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Weline\Subscription\Model\SubscriptionState;

/**
 * Cancel Subscription with expected-version CAS（P4B-001）.
 *
 * Concurrent cancel/renew races: only matching version wins; losers get version_conflict.
 */
final class SubscriptionCancelCasService
{
    public const ERROR_ALREADY = 'subscription_already_cancelled';
    public const ERROR_VERSION = 'subscription_version_conflict';

    public function __construct(
        private readonly SubscriptionStore $store,
        private readonly SubscriptionOwnershipService $ownership,
    ) {
    }

    public static function forTesting(?SubscriptionStore $store = null): self
    {
        $store ??= SubscriptionStore::forTesting();

        return new self($store, new SubscriptionOwnershipService($store));
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(string $subscriptionId, string $customerId, int $expectedVersion): array
    {
        $this->ownership->assertOwner($subscriptionId, $customerId);
        $current = $this->store->get($subscriptionId);
        if ((string) $current['status'] === SubscriptionState::STATUS_CANCELLED) {
            throw new SubscriptionConflictException(
                self::ERROR_ALREADY,
                __('订阅已取消：%{1}', [$subscriptionId]),
                ['subscription_id' => $subscriptionId, 'version' => (int) $current['version']],
            );
        }
        try {
            $row = $this->store->replaceWithVersionBump($subscriptionId, $expectedVersion, [
                'status' => SubscriptionState::STATUS_CANCELLED,
                'cancelled_at' => gmdate('c'),
            ]);
        } catch (SubscriptionConflictException $e) {
            if ($e->errorCode === 'subscription_version_conflict') {
                throw new SubscriptionConflictException(
                    self::ERROR_VERSION,
                    $e->getMessage(),
                    $e->context,
                    0,
                    $e,
                );
            }
            throw $e;
        }

        return $row;
    }
}
