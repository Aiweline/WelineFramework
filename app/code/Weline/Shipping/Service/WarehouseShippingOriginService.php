<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Api\WarehouseShippingOriginInterface;
use Weline\Shipping\Model\ShippingAddress;
use Weline\Shipping\Model\WarehouseShippingOrigin;

final class WarehouseShippingOriginService implements WarehouseShippingOriginInterface
{
    private const PROCESS_BAG_MAX = 128;

    /** @var array<string, int> website|warehouse → shipping_address_id (0 = miss) */
    private static array $processOriginByKey = [];

    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    public static function clearProcessCache(): void
    {
        self::$processOriginByKey = [];
        \Weline\Framework\Cache\Service\ScopeSharedMemo::purgeProcessPrefix('shipping.origin.');
    }

    public function requireShippingAddressId(int $websiteId, int $warehouseId): int
    {
        $id = $this->findShippingAddressId($websiteId, $warehouseId);
        if ($id === null || $id <= 0) {
            throw new \RuntimeException(self::ERROR_MISSING);
        }

        return $id;
    }

    public function findShippingAddressId(int $websiteId, int $warehouseId): ?int
    {
        $websiteId = max(0, $websiteId);
        $warehouseId = max(0, $warehouseId);
        if ($warehouseId <= 0) {
            return null;
        }
        $processKey = $websiteId . '|' . $warehouseId;
        if (\array_key_exists($processKey, self::$processOriginByKey)) {
            $cached = self::$processOriginByKey[$processKey];

            return $cached > 0 ? $cached : null;
        }

        $addressId = (int)\Weline\Framework\Cache\Service\ScopeSharedMemo::rememberScoped(
            'shipping',
            'shipping.origin.' . $warehouseId,
            function () use ($websiteId, $warehouseId): int {
                $candidates = [$websiteId];
                if ($websiteId !== 0) {
                    // Seed/default warehouse origins live on website:0; storefront websites
                    // without a copied bind must fall back the same way as service lanes.
                    $candidates[] = 0;
                }
                /** @var WarehouseShippingOrigin $model */
                $model = $this->objectManager->getInstance(WarehouseShippingOrigin::class, [], false);
                foreach ($candidates as $candidateWebsiteId) {
                    $items = $model->reset()
                        ->where(WarehouseShippingOrigin::schema_fields_WEBSITE_ID, $candidateWebsiteId)
                        ->where(WarehouseShippingOrigin::schema_fields_WAREHOUSE_ID, $warehouseId)
                        ->where(WarehouseShippingOrigin::schema_fields_IS_ACTIVE, 1)
                        ->select()
                        ->fetch()
                        ->getItems();
                    $row = \is_array($items) ? ($items[0] ?? null) : null;
                    if (!$row instanceof WarehouseShippingOrigin || !(int)$row->getId()) {
                        continue;
                    }
                    $found = (int)$row->getData(WarehouseShippingOrigin::schema_fields_SHIPPING_ADDRESS_ID);
                    if ($found > 0) {
                        return $found;
                    }
                }

                return 0;
            },
            \Weline\Framework\Runtime\ScopeIdentity::websiteById($websiteId),
            600,
        );

        if (!\array_key_exists($processKey, self::$processOriginByKey)
            && \count(self::$processOriginByKey) >= self::PROCESS_BAG_MAX
        ) {
            $first = \array_key_first(self::$processOriginByKey);
            if ($first !== null) {
                unset(self::$processOriginByKey[$first]);
            }
        }
        self::$processOriginByKey[$processKey] = $addressId;

        return $addressId > 0 ? $addressId : null;
    }

    public function bind(int $websiteId, int $warehouseId, int $shippingAddressId, bool $active = true): array
    {
        $websiteId = max(0, $websiteId);
        $warehouseId = max(0, $warehouseId);
        $shippingAddressId = max(0, $shippingAddressId);
        if ($warehouseId <= 0 || $shippingAddressId <= 0) {
            throw new \InvalidArgumentException(self::ERROR_INVALID);
        }
        /** @var ShippingAddress $address */
        $address = $this->objectManager->getInstance(ShippingAddress::class, [], false)->load($shippingAddressId);
        if (!(int)$address->getId() || !(bool)$address->getData(ShippingAddress::schema_fields_IS_ENABLED)) {
            throw new \InvalidArgumentException(self::ERROR_INVALID);
        }

        /** @var WarehouseShippingOrigin $model */
        $model = $this->objectManager->getInstance(WarehouseShippingOrigin::class, [], false);
        $items = $model->reset()
            ->where(WarehouseShippingOrigin::schema_fields_WEBSITE_ID, $websiteId)
            ->where(WarehouseShippingOrigin::schema_fields_WAREHOUSE_ID, $warehouseId)
            ->select()
            ->fetch()
            ->getItems();
        $existing = is_array($items) ? ($items[0] ?? null) : null;
        $now = date('Y-m-d H:i:s');
        $payload = [
            WarehouseShippingOrigin::schema_fields_WEBSITE_ID => $websiteId,
            WarehouseShippingOrigin::schema_fields_WAREHOUSE_ID => $warehouseId,
            WarehouseShippingOrigin::schema_fields_SHIPPING_ADDRESS_ID => $shippingAddressId,
            WarehouseShippingOrigin::schema_fields_IS_ACTIVE => $active ? 1 : 0,
            WarehouseShippingOrigin::schema_fields_UPDATED_AT => $now,
        ];
        self::clearProcessCache();
        if ($existing instanceof WarehouseShippingOrigin && (int)$existing->getId() > 0) {
            $existing->setData($payload)->save();
            $id = (int)$existing->getId();
        } else {
            /** @var WarehouseShippingOrigin $create */
            $create = $this->objectManager->getInstance(WarehouseShippingOrigin::class, [], false);
            $create->setData(array_merge($payload, [
                WarehouseShippingOrigin::schema_fields_CREATED_AT => $now,
            ]))->save();
            $id = (int)$create->getId();
        }

        return [
            'origin_id' => $id,
            'website_id' => $websiteId,
            'warehouse_id' => $warehouseId,
            'shipping_address_id' => $shippingAddressId,
            'is_active' => $active ? 1 : 0,
        ];
    }
}
