<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\SupplierBrand;
use Weline\Product\Service\ProductShardProvisioner;

final class SupplierBrandRepository extends AbstractWebsiteShardRepository
{
    /** @var (\Closure(int): SupplierBrand)|null */
    private readonly mixed $modelFactory;

    /**
     * @param (\Closure(int): SupplierBrand)|null $modelFactory
     */
    public function __construct(
        ProductShardProvisioner $provisioner,
        ?callable $modelFactory = null,
    ) {
        parent::__construct($provisioner);
        $this->modelFactory = $modelFactory;
    }

    /**
     * @return array<int, list<int>> supplier_id => brand_ids
     */
    public function mapBrandIdsBySupplier(int $websiteId): array
    {
        $this->assertWebsite($websiteId);
        $rows = $this->newModel($websiteId)->clear()->select()->fetchArray();
        $map = [];
        foreach ($rows as $row) {
            $supplierId = (int)($row[SupplierBrand::schema_fields_SUPPLIER_ID] ?? 0);
            $brandId = (int)($row[SupplierBrand::schema_fields_BRAND_ID] ?? 0);
            if ($supplierId <= 0 || $brandId <= 0) {
                continue;
            }
            $map[$supplierId][$brandId] = $brandId;
        }
        foreach ($map as $supplierId => $ids) {
            $list = array_values($ids);
            sort($list);
            $map[$supplierId] = $list;
        }

        return $map;
    }

    /**
     * @return list<int>
     */
    public function listBrandIdsForSupplier(int $websiteId, int $supplierId): array
    {
        $this->assertWebsite($websiteId);
        if ($supplierId <= 0) {
            return [];
        }
        $rows = $this->newModel($websiteId)->clear()
            ->where(SupplierBrand::schema_fields_SUPPLIER_ID, $supplierId)
            ->select()
            ->fetchArray();
        $ids = [];
        foreach ($rows as $row) {
            $brandId = (int)($row[SupplierBrand::schema_fields_BRAND_ID] ?? 0);
            if ($brandId > 0) {
                $ids[] = $brandId;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @return list<int>
     */
    public function listSupplierIdsForBrand(int $websiteId, int $brandId): array
    {
        $this->assertWebsite($websiteId);
        if ($brandId <= 0) {
            return [];
        }
        $rows = $this->newModel($websiteId)->clear()
            ->where(SupplierBrand::schema_fields_BRAND_ID, $brandId)
            ->select()
            ->fetchArray();
        $ids = [];
        foreach ($rows as $row) {
            $supplierId = (int)($row[SupplierBrand::schema_fields_SUPPLIER_ID] ?? 0);
            if ($supplierId > 0) {
                $ids[] = $supplierId;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * Replace all brand links for one supplier (N:N sync).
     *
     * @param list<int> $brandIds
     */
    public function replaceBrandsForSupplier(int $websiteId, int $supplierId, array $brandIds): void
    {
        $this->assertWebsite($websiteId);
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException((string)__('supplier_id 必须为正整数'));
        }
        $wanted = $this->normalizePositiveIds($brandIds);
        $existing = $this->newModel($websiteId)->clear()
            ->where(SupplierBrand::schema_fields_SUPPLIER_ID, $supplierId)
            ->select()
            ->fetchArray();
        $existingByBrand = [];
        foreach ($existing as $row) {
            $brandId = (int)($row[SupplierBrand::schema_fields_BRAND_ID] ?? 0);
            $linkId = (int)($row[SupplierBrand::schema_fields_ID] ?? 0);
            if ($brandId > 0 && $linkId > 0) {
                $existingByBrand[$brandId] = $linkId;
            }
        }
        foreach ($existingByBrand as $brandId => $linkId) {
            if (!in_array($brandId, $wanted, true)) {
                $model = $this->newModel($websiteId);
                $model->clear()
                    ->where(SupplierBrand::schema_fields_ID, $linkId)
                    ->find()
                    ->fetch();
                if ($model->getId()) {
                    $model->delete();
                }
            }
        }
        $now = date('Y-m-d H:i:s');
        $position = 0;
        foreach ($wanted as $brandId) {
            $position++;
            if (isset($existingByBrand[$brandId])) {
                continue;
            }
            $model = $this->newModel($websiteId);
            $model->clear()->setData([
                SupplierBrand::schema_fields_SUPPLIER_ID => $supplierId,
                SupplierBrand::schema_fields_BRAND_ID => $brandId,
                SupplierBrand::schema_fields_POSITION => $position,
                SupplierBrand::schema_fields_CREATED_AT => $now,
            ])->save();
        }
    }

    /**
     * Replace all supplier links for one brand (N:N sync).
     *
     * @param list<int> $supplierIds
     */
    public function replaceSuppliersForBrand(int $websiteId, int $brandId, array $supplierIds): void
    {
        $this->assertWebsite($websiteId);
        if ($brandId <= 0) {
            throw new \InvalidArgumentException((string)__('brand_id 必须为正整数'));
        }
        $wanted = $this->normalizePositiveIds($supplierIds);
        $existing = $this->newModel($websiteId)->clear()
            ->where(SupplierBrand::schema_fields_BRAND_ID, $brandId)
            ->select()
            ->fetchArray();
        $existingBySupplier = [];
        foreach ($existing as $row) {
            $supplierId = (int)($row[SupplierBrand::schema_fields_SUPPLIER_ID] ?? 0);
            $linkId = (int)($row[SupplierBrand::schema_fields_ID] ?? 0);
            if ($supplierId > 0 && $linkId > 0) {
                $existingBySupplier[$supplierId] = $linkId;
            }
        }
        foreach ($existingBySupplier as $supplierId => $linkId) {
            if (!in_array($supplierId, $wanted, true)) {
                $model = $this->newModel($websiteId);
                $model->clear()
                    ->where(SupplierBrand::schema_fields_ID, $linkId)
                    ->find()
                    ->fetch();
                if ($model->getId()) {
                    $model->delete();
                }
            }
        }
        $now = date('Y-m-d H:i:s');
        $position = 0;
        foreach ($wanted as $supplierId) {
            $position++;
            if (isset($existingBySupplier[$supplierId])) {
                continue;
            }
            $model = $this->newModel($websiteId);
            $model->clear()->setData([
                SupplierBrand::schema_fields_SUPPLIER_ID => $supplierId,
                SupplierBrand::schema_fields_BRAND_ID => $brandId,
                SupplierBrand::schema_fields_POSITION => $position,
                SupplierBrand::schema_fields_CREATED_AT => $now,
            ])->save();
        }
    }

    /**
     * @param list<mixed> $ids
     * @return list<int>
     */
    private function normalizePositiveIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $int = (int)$id;
            if ($int > 0) {
                $out[$int] = $int;
            }
        }
        $list = array_values($out);
        sort($list);

        return $list;
    }

    protected function newModel(int $websiteId): AbstractWebsiteShardModel
    {
        if ($this->modelFactory !== null) {
            return ($this->modelFactory)($websiteId);
        }
        /** @var SupplierBrand $model */
        $model = ObjectManager::create(SupplierBrand::class, [], false);

        return $model->forWebsite($websiteId);
    }
}
