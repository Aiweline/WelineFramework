<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\CategoryLink;
use Weline\Product\Service\ProductShardProvisioner;

final class CategoryLinkRepository extends AbstractWebsiteShardRepository
{
    /** @var (\Closure(int): CategoryLink)|null */
    private readonly mixed $modelFactory;

    /**
     * @param (\Closure(int): CategoryLink)|null $modelFactory
     */
    public function __construct(
        ProductShardProvisioner $provisioner,
        ?callable $modelFactory = null,
    ) {
        parent::__construct($provisioner);
        $this->modelFactory = $modelFactory;
    }

    public function link(int $websiteId, int $categoryId, int $productId): CategoryLink
    {
        $this->assertWebsite($websiteId);
        $existing = $this->find($websiteId, $categoryId, $productId);
        if ($existing !== null) {
            return $existing;
        }
        $model = $this->newModel($websiteId);
        $model->clear()->setData([
            CategoryLink::schema_fields_CATEGORY_ID => $categoryId,
            CategoryLink::schema_fields_PRODUCT_ID => $productId,
        ])->save();
        $loaded = $this->find($websiteId, $categoryId, $productId);
        if ($loaded === null) {
            throw new \RuntimeException(__('CategoryLink 写入后无法回读'));
        }
        return $loaded;
    }

    public function unlink(int $websiteId, int $categoryId, int $productId): void
    {
        $this->assertWebsite($websiteId);
        $existing = $this->find($websiteId, $categoryId, $productId);
        if ($existing !== null) {
            $existing->delete();
        }
    }

    public function find(int $websiteId, int $categoryId, int $productId): ?CategoryLink
    {
        $this->assertWebsite($websiteId);
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(CategoryLink::schema_fields_CATEGORY_ID, $categoryId)
            ->where(CategoryLink::schema_fields_PRODUCT_ID, $productId)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    /**
     * @param list<int> $categoryIds
     * @return list<array{category_id:int,product_id:int}>
     */
    public function listByCategoryIds(int $websiteId, array $categoryIds): array
    {
        $this->assertWebsite($websiteId);
        $categoryIds = array_values(array_unique(array_filter(
            array_map('intval', $categoryIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($categoryIds === []) {
            return [];
        }
        $raw = $this->newModel($websiteId)
            ->clear()
            ->where(CategoryLink::schema_fields_CATEGORY_ID, $categoryIds, 'IN')
            ->select()
            ->fetchArray();
        $rows = [];
        foreach ($raw as $row) {
            $rows[] = [
                'category_id' => (int)($row[CategoryLink::schema_fields_CATEGORY_ID] ?? 0),
                'product_id' => (int)($row[CategoryLink::schema_fields_PRODUCT_ID] ?? 0),
            ];
        }
        usort(
            $rows,
            static fn(array $left, array $right): int => [$left['category_id'], $left['product_id']]
                <=> [$right['category_id'], $right['product_id']],
        );
        return $rows;
    }

    protected function newModel(int $websiteId): AbstractWebsiteShardModel
    {
        if ($this->modelFactory !== null) {
            return ($this->modelFactory)($websiteId);
        }
        /** @var CategoryLink $model */
        $model = ObjectManager::create(CategoryLink::class, [], false);
        return $model->forWebsite($websiteId);
    }
}
