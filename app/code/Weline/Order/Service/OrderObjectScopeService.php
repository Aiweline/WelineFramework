<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Order\Model\Order;

/**
 * 从订单持久化 website_id/store_id 恢复对象授权 Scope。
 */
class OrderObjectScopeService
{
    private readonly ?\Closure $identityResolver;

    public function __construct(?callable $identityResolver = null)
    {
        $this->identityResolver = $identityResolver === null
            ? null
            : \Closure::fromCallable($identityResolver);
    }

    public function fromOrder(Order $order): ScopeIdentity
    {
        return $this->fromPersistedIds(
            (int)$order->getData(Order::schema_fields_WEBSITE_ID),
            (int)$order->getData(Order::schema_fields_STORE_ID),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    public function fromExplicitCreate(array $input): ScopeIdentity
    {
        if (!\array_key_exists(Order::schema_fields_WEBSITE_ID, $input)
            || !\array_key_exists(Order::schema_fields_STORE_ID, $input)
        ) {
            throw new \InvalidArgumentException('order_create_requires_explicit_scope');
        }

        return $this->fromPersistedIds(
            (int)$input[Order::schema_fields_WEBSITE_ID],
            (int)$input[Order::schema_fields_STORE_ID],
        );
    }

    public function fromPersistedIds(int $websiteId, int $storeId): ScopeIdentity
    {
        if ($websiteId < 0 || $storeId < 0) {
            throw new \InvalidArgumentException('order_scope_identity_invalid');
        }
        $resolved = $this->identityResolver !== null
            ? ($this->identityResolver)($websiteId, $storeId)
            : $this->resolveFromWebsiteModels($websiteId, $storeId);
        if (!\is_array($resolved)) {
            throw new \InvalidArgumentException('order_scope_identity_not_found');
        }
        $websiteCode = \strtolower(\trim((string)($resolved['website_code'] ?? '')));
        if ($websiteCode === '') {
            throw new \InvalidArgumentException('order_scope_identity_not_found');
        }
        if ($storeId === 0) {
            return ScopeIdentity::website($websiteId, $websiteCode);
        }
        $storeCode = \strtolower(\trim((string)($resolved['store_code'] ?? '')));
        $storeMode = \strtolower(\trim((string)($resolved['store_mode'] ?? ScopeIdentity::MODE_NORMAL)));
        if ($storeCode === '') {
            throw new \InvalidArgumentException('order_scope_identity_not_found');
        }

        return ScopeIdentity::store($websiteId, $websiteCode, $storeCode, $storeMode);
    }

    /**
     * @return array{website_code:string,store_code?:string,store_mode?:string}
     */
    private function resolveFromWebsiteModels(int $websiteId, int $storeId): array
    {
        if (!\class_exists(\Weline\Websites\Model\Website::class)) {
            throw new \InvalidArgumentException('order_scope_identity_not_found');
        }
        /** @var \Weline\Websites\Model\Website $website */
        $website = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
        $website->load($websiteId);
        if (!$website->getId() && $websiteId !== 0) {
            throw new \InvalidArgumentException('order_scope_identity_not_found');
        }
        $websiteCode = \strtolower(\trim((string)$website->getData(
            \Weline\Websites\Model\Website::schema_fields_CODE,
        )));
        if ($websiteCode === '' && $websiteId === 0) {
            $websiteCode = 'default';
        }
        $result = ['website_code' => $websiteCode];
        if ($storeId === 0) {
            return $result;
        }
        if (!\class_exists(\Weline\Websites\Model\Store::class)) {
            throw new \InvalidArgumentException('order_scope_identity_not_found');
        }
        /** @var \Weline\Websites\Model\Store $store */
        $store = ObjectManager::getInstance(\Weline\Websites\Model\Store::class);
        $store->load($storeId);
        if (!$store->getId()
            || (int)$store->getData(\Weline\Websites\Model\Store::schema_fields_WEBSITE_ID) !== $websiteId
        ) {
            throw new \InvalidArgumentException('order_scope_identity_not_found');
        }
        $result['store_code'] = (string)$store->getData(\Weline\Websites\Model\Store::schema_fields_CODE);
        $result['store_mode'] = (string)$store->getData(\Weline\Websites\Model\Store::schema_fields_STORE_MODE);

        return $result;
    }
}
