<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/** Fail-closed Store lifecycle guard for new renewal obligations. */
final class SubscriptionStoreEligibilityService
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $memoryStores = null;

    public function __construct(
        private readonly ?StoreCatalogInterface $stores,
        bool $useMemory = false,
    ) {
        if ($useMemory) {
            $this->memoryStores = [
                0 => [
                    'store_id' => 0,
                    'website_id' => 0,
                    'enabled' => true,
                    'lifecycle_status' => 'active',
                    'tombstoned_at' => null,
                ],
            ];
        }
    }

    public static function forTesting(): self
    {
        return new self(null, useMemory: true);
    }

    public function setStoreState(
        int $storeId,
        int $websiteId,
        bool $enabled,
        string $lifecycleStatus,
        ?string $tombstonedAt = null,
    ): void {
        if ($this->memoryStores === null) {
            throw new \LogicException('setStoreState is test-only');
        }
        $this->memoryStores[$storeId] = [
            'store_id' => $storeId,
            'website_id' => $websiteId,
            'enabled' => $enabled,
            'lifecycle_status' => trim($lifecycleStatus),
            'tombstoned_at' => $tombstonedAt,
        ];
    }

    /** @return array<string, mixed> */
    public function assertRenewalAllowed(int $websiteId, int $storeId): array
    {
        if ($websiteId < 0 || $storeId < 0) {
            throw new \InvalidArgumentException(__('Subscription Store Scope 非法'));
        }
        if ($this->memoryStores !== null) {
            $store = $this->memoryStores[$storeId] ?? null;
        } else {
            if (!$this->stores instanceof StoreCatalogInterface) {
                throw $this->blocked('subscription_store_catalog_unavailable', $websiteId, $storeId);
            }
            try {
                $summary = $storeId > 0
                    ? $this->stores->byId($storeId)
                    : $this->stores->defaultStore($websiteId);
            } catch (\Throwable $throwable) {
                throw new SubscriptionConflictException(
                    'subscription_store_not_renewable',
                    __('Store 不允许创建新 Subscription 续费义务'),
                    [
                        'website_id' => $websiteId,
                        'store_id' => $storeId,
                        'catalog_error' => $throwable::class,
                    ],
                    0,
                    $throwable,
                );
            }
            $store = $summary instanceof StoreSummary ? $summary->toArray() : null;
        }
        if (!\is_array($store)) {
            throw $this->blocked('subscription_store_not_found', $websiteId, $storeId);
        }
        if ((int) ($store['website_id'] ?? -1) !== $websiteId) {
            throw $this->blocked('subscription_store_scope_mismatch', $websiteId, $storeId);
        }
        if (empty($store['enabled'])
            || (string) ($store['lifecycle_status'] ?? '') !== 'active'
            || ($store['tombstoned_at'] ?? null) !== null
        ) {
            throw $this->blocked('subscription_store_not_renewable', $websiteId, $storeId);
        }
        return $store;
    }

    private function blocked(string $code, int $websiteId, int $storeId): SubscriptionConflictException
    {
        return new SubscriptionConflictException(
            $code,
            __('Store 不允许创建新 Subscription 续费义务'),
            ['website_id' => $websiteId, 'store_id' => $storeId],
        );
    }
}
