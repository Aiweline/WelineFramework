<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\ProductSupplier;
use Weline\Product\Service\ProductShardProvisioner;

final class ProductSupplierRepository extends AbstractWebsiteShardRepository
{
    /** @var (\Closure(int): ProductSupplier)|null */
    private readonly mixed $modelFactory;

    /**
     * @param (\Closure(int): ProductSupplier)|null $modelFactory
     */
    public function __construct(
        ProductShardProvisioner $provisioner,
        ?callable $modelFactory = null,
    ) {
        parent::__construct($provisioner);
        $this->modelFactory = $modelFactory;
    }

    public function findById(int $websiteId, int $linkId): ?ProductSupplier
    {
        $this->assertWebsite($websiteId);
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(ProductSupplier::schema_fields_ID, $linkId)
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    public function findByProductAndSupplier(int $websiteId, int $productId, int $supplierId): ?ProductSupplier
    {
        $this->assertWebsite($websiteId);
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(ProductSupplier::schema_fields_PRODUCT_ID, $productId)
            ->where(ProductSupplier::schema_fields_SUPPLIER_ID, $supplierId)
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    public function findPrimaryByProduct(int $websiteId, int $productId): ?ProductSupplier
    {
        $this->assertWebsite($websiteId);
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(ProductSupplier::schema_fields_PRODUCT_ID, $productId)
            ->where(ProductSupplier::schema_fields_IS_PRIMARY, 1)
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByProduct(int $websiteId, int $productId): array
    {
        $this->assertWebsite($websiteId);
        $rows = $this->newModel($websiteId)->clear()
            ->where(ProductSupplier::schema_fields_PRODUCT_ID, $productId)
            ->select()
            ->fetchArray();
        usort(
            $rows,
            static function (array $left, array $right): int {
                $leftPrimary = (int)($left[ProductSupplier::schema_fields_IS_PRIMARY] ?? 0);
                $rightPrimary = (int)($right[ProductSupplier::schema_fields_IS_PRIMARY] ?? 0);
                if ($leftPrimary !== $rightPrimary) {
                    return $rightPrimary <=> $leftPrimary;
                }

                return (int)($left[ProductSupplier::schema_fields_ID] ?? 0)
                    <=> (int)($right[ProductSupplier::schema_fields_ID] ?? 0);
            },
        );

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function upsertPrimary(int $websiteId, int $productId, int $supplierId, array $data): ProductSupplier
    {
        $this->assertWebsite($websiteId);
        if ($productId <= 0 || $supplierId <= 0) {
            throw new \InvalidArgumentException((string)__('product_id 与 supplier_id 必须为正整数'));
        }

        $now = date('Y-m-d H:i:s');
        $existingPrimary = $this->findPrimaryByProduct($websiteId, $productId);
        if ($existingPrimary !== null
            && (int)$existingPrimary->getData(ProductSupplier::schema_fields_SUPPLIER_ID) !== $supplierId
        ) {
            $existingPrimary->setData(ProductSupplier::schema_fields_IS_PRIMARY, 0);
            $existingPrimary->setData(ProductSupplier::schema_fields_UPDATED_AT, $now);
            $existingPrimary->save();
        }

        $existing = $this->findByProductAndSupplier($websiteId, $productId, $supplierId);
        $payload = array_merge($data, [
            ProductSupplier::schema_fields_PRODUCT_ID => $productId,
            ProductSupplier::schema_fields_SUPPLIER_ID => $supplierId,
            ProductSupplier::schema_fields_IS_PRIMARY => 1,
            ProductSupplier::schema_fields_UPDATED_AT => $now,
        ]);
        if ($existing === null) {
            $payload[ProductSupplier::schema_fields_STATUS] = $payload[ProductSupplier::schema_fields_STATUS]
                ?? ProductSupplier::STATUS_ACTIVE;
            $payload[ProductSupplier::schema_fields_CREATED_AT] = $now;
            $model = $this->newModel($websiteId);
            $model->clear()->setData($payload)->save();
            $id = (int)$model->getId();

            return $this->findById($websiteId, $id)
                ?? throw new \RuntimeException((string)__('商品供应商关联创建后无法回读：%{1}', [$id]));
        }

        foreach ($payload as $field => $value) {
            $existing->setData((string)$field, $value);
        }
        $existing->save();

        return $this->findById($websiteId, (int)$existing->getId())
            ?? throw new \RuntimeException((string)__('商品供应商关联更新后无法回读'));
    }

    protected function newModel(int $websiteId): AbstractWebsiteShardModel
    {
        if ($this->modelFactory !== null) {
            return ($this->modelFactory)($websiteId);
        }
        /** @var ProductSupplier $model */
        $model = ObjectManager::create(ProductSupplier::class, [], false);

        return $model->forWebsite($websiteId);
    }
}
