<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Api\DefaultWarehouseResolverInterface;
use Weline\Inventory\Api\FulfillmentSplitPlanInterface;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Model\WarehouseQuota;
use Weline\Inventory\Model\WarehouseStoreAuthorization;

/**
 * 确定性分仓：Store 授权仓中配额足够者，优先默认逻辑仓，其次 warehouse_id ASC。
 */
final class FulfillmentSplitPlanService implements FulfillmentSplitPlanInterface
{
    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly DefaultWarehouseResolverInterface $defaultWarehouse,
    ) {
    }

    public function planPackages(int $websiteId, int $storeId, array $lines): array
    {
        $websiteId = max(0, $websiteId);
        $storeId = max(0, $storeId);
        $shippable = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            if (array_key_exists('requires_shipping', $line) && !(bool)$line['requires_shipping']) {
                continue;
            }
            $qty = $this->lineQtyMinor($line);
            if ($qty <= 0) {
                continue;
            }
            $shippable[] = $line;
        }
        if ($shippable === []) {
            return [];
        }

        $authorized = $this->authorizedWarehouseIds($websiteId, $storeId);
        if ($authorized === []) {
            throw new \RuntimeException(self::ERROR_UNFULFILLABLE);
        }

        $defaultId = 0;
        try {
            $assignment = $this->defaultWarehouse->resolveDefault($websiteId, $storeId);
            $defaultId = (int)$assignment->warehouseId;
        } catch (\Throwable) {
            $defaultId = 0;
        }

        /** @var array<int, list<array<string,mixed>>> $buckets */
        $buckets = [];
        foreach ($shippable as $line) {
            $offerId = (int)($line['offer_id'] ?? $line['product_offer_id'] ?? 0);
            $qty = $this->lineQtyMinor($line);
            $warehouseId = $this->pickWarehouse($websiteId, $offerId, $qty, $authorized, $defaultId);
            if ($warehouseId <= 0) {
                throw new \RuntimeException(self::ERROR_UNFULFILLABLE);
            }
            $buckets[$warehouseId][] = $line;
        }

        ksort($buckets, SORT_NUMERIC);
        $packages = [];
        foreach ($buckets as $warehouseId => $pkgLines) {
            $packages[] = [
                'split_key' => 'wh:' . $warehouseId,
                'warehouse_id' => (int)$warehouseId,
                'lines' => array_values($pkgLines),
            ];
        }

        return $packages;
    }

    /**
     * @param list<int> $authorized
     */
    private function pickWarehouse(
        int $websiteId,
        int $offerId,
        int $qtyMinor,
        array $authorized,
        int $defaultId,
    ): int {
        $ordered = $authorized;
        usort($ordered, static function (int $a, int $b) use ($defaultId): int {
            if ($a === $defaultId && $b !== $defaultId) {
                return -1;
            }
            if ($b === $defaultId && $a !== $defaultId) {
                return 1;
            }

            return $a <=> $b;
        });

        foreach ($ordered as $warehouseId) {
            if ($offerId <= 0) {
                // 无 offer 时仍确定性落到排序首仓（默认仓优先）
                return $warehouseId;
            }
            if ($this->quotaEnough($websiteId, $warehouseId, $offerId, $qtyMinor)) {
                return $warehouseId;
            }
        }

        return 0;
    }

    private function quotaEnough(int $websiteId, int $warehouseId, int $offerId, int $qtyMinor): bool
    {
        /** @var WarehouseQuota $quota */
        $quota = $this->objectManager->getInstance(WarehouseQuota::class, [], false);
        $items = $quota->reset()
            ->where(WarehouseQuota::schema_fields_WEBSITE_ID, $websiteId)
            ->where(WarehouseQuota::schema_fields_WAREHOUSE_ID, $warehouseId)
            ->where(WarehouseQuota::schema_fields_OFFER_ID, $offerId)
            ->select()
            ->fetch()
            ->getItems();
        $row = is_array($items) ? ($items[0] ?? null) : null;
        if (!$row instanceof WarehouseQuota) {
            // 无配额行：允许落到授权仓（开发/未 cutover 库存）以免阻塞；有配额则严格比较
            return true;
        }
        $available = (int)$row->getData(WarehouseQuota::schema_fields_QTY_MINOR);

        return $available >= $qtyMinor;
    }

    /**
     * @return list<int>
     */
    private function authorizedWarehouseIds(int $websiteId, int $storeId): array
    {
        /** @var WarehouseStoreAuthorization $auth */
        $auth = $this->objectManager->getInstance(WarehouseStoreAuthorization::class, [], false);
        $items = $auth->reset()
            ->where(WarehouseStoreAuthorization::schema_fields_WEBSITE_ID, $websiteId)
            ->where(WarehouseStoreAuthorization::schema_fields_STORE_ID, $storeId)
            ->where(WarehouseStoreAuthorization::schema_fields_ENABLED, 1)
            ->order(WarehouseStoreAuthorization::schema_fields_WAREHOUSE_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        $ids = [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!$item instanceof WarehouseStoreAuthorization) {
                    continue;
                }
                $wid = (int)$item->getData(WarehouseStoreAuthorization::schema_fields_WAREHOUSE_ID);
                if ($wid > 0) {
                    $ids[] = $wid;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        // 过滤禁用仓
        $alive = [];
        foreach ($ids as $wid) {
            /** @var Warehouse $wh */
            $wh = $this->objectManager->getInstance(Warehouse::class, [], false)->load($wid);
            if ((int)$wh->getId() > 0 && (bool)$wh->getData(Warehouse::schema_fields_ENABLED)) {
                $alive[] = $wid;
            }
        }

        return $alive;
    }

    /** @param array<string,mixed> $line */
    private function lineQtyMinor(array $line): int
    {
        if (isset($line['qty_minor'])) {
            return max(0, (int)$line['qty_minor']);
        }
        $qty = (float)($line['qty'] ?? $line['quantity'] ?? 0);

        return (int)max(0, (int)round($qty * 1000));
    }
}
