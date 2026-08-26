<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\CategoryDisplaySelection;
use Weline\Product\Service\ProductShardProvisioner;

/**
 * Store/Channel display selection rows for product category trees.
 */
final class CategoryDisplaySelectionRepository extends AbstractWebsiteShardRepository
{
    /** @var (\Closure(int): CategoryDisplaySelection)|null */
    private readonly mixed $modelFactory;

    /**
     * @param (\Closure(int): CategoryDisplaySelection)|null $modelFactory
     */
    public function __construct(
        ProductShardProvisioner $provisioner,
        ?callable $modelFactory = null,
    ) {
        parent::__construct($provisioner);
        $this->modelFactory = $modelFactory;
    }

    /**
     * @return list<array{selection_id:int,store_id:int,channel_id:int,category_id:int,enabled:int,position:int}>
     */
    public function listForScope(int $websiteId, int $storeId, int $channelId): array
    {
        $this->assertWebsite($websiteId);
        $this->assertStoreId($storeId);
        if ($channelId < 0) {
            throw new \InvalidArgumentException((string)__('channel_id 不能为负数'));
        }
        if ($storeId === 0 && $channelId === 0) {
            throw new \InvalidArgumentException((string)__('展示选择需要 store_id 或 channel_id'));
        }

        $rows = $this->newModel($websiteId)
            ->clear()
            ->where(CategoryDisplaySelection::schema_fields_STORE_ID, $storeId)
            ->where(CategoryDisplaySelection::schema_fields_CHANNEL_ID, $channelId)
            ->select()
            ->fetchArray();

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'selection_id' => (int)($row[CategoryDisplaySelection::schema_fields_ID] ?? 0),
                'store_id' => (int)($row[CategoryDisplaySelection::schema_fields_STORE_ID] ?? 0),
                'channel_id' => (int)($row[CategoryDisplaySelection::schema_fields_CHANNEL_ID] ?? 0),
                'category_id' => (int)($row[CategoryDisplaySelection::schema_fields_CATEGORY_ID] ?? 0),
                'enabled' => (int)($row[CategoryDisplaySelection::schema_fields_ENABLED] ?? 0) === 1 ? 1 : 0,
                'position' => (int)($row[CategoryDisplaySelection::schema_fields_POSITION] ?? 0),
            ];
        }

        usort(
            $out,
            static function (array $left, array $right): int {
                if ($left['position'] !== $right['position']) {
                    return $left['position'] <=> $right['position'];
                }

                return $left['category_id'] <=> $right['category_id'];
            },
        );

        return $out;
    }

    /**
     * Replace all display rows for a store/channel scope.
     *
     * @param list<array{category_id?:int,enabled?:int|bool,position?:int}> $rows
     * @return array{saved:int,store_id:int,channel_id:int}
     */
    public function replaceScope(int $websiteId, int $storeId, int $channelId, array $rows): array
    {
        $this->assertWebsite($websiteId);
        $this->assertStoreId($storeId);
        if ($channelId < 0) {
            throw new \InvalidArgumentException((string)__('channel_id 不能为负数'));
        }
        if ($storeId === 0 && $channelId === 0) {
            throw new \InvalidArgumentException((string)__('展示选择需要 store_id 或 channel_id'));
        }

        $desired = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $categoryId = max(0, (int)($row['category_id'] ?? 0));
            if ($categoryId <= 0) {
                continue;
            }
            $desired[$categoryId] = [
                'category_id' => $categoryId,
                'enabled' => !empty($row['enabled']) ? 1 : 0,
                'position' => max(0, (int)($row['position'] ?? ($index + 1))),
            ];
        }

        $existing = $this->listForScope($websiteId, $storeId, $channelId);
        foreach ($existing as $row) {
            $categoryId = (int)$row['category_id'];
            if (!isset($desired[$categoryId])) {
                $model = $this->find($websiteId, $storeId, $channelId, $categoryId);
                $model?->delete();
            }
        }

        $saved = 0;
        foreach ($desired as $row) {
            $this->upsert(
                $websiteId,
                $storeId,
                $channelId,
                (int)$row['category_id'],
                (int)$row['enabled'] === 1,
                (int)$row['position'],
            );
            $saved++;
        }

        return [
            'saved' => $saved,
            'store_id' => $storeId,
            'channel_id' => $channelId,
        ];
    }

    public function upsert(
        int $websiteId,
        int $storeId,
        int $channelId,
        int $categoryId,
        bool $enabled,
        int $position = 0,
    ): CategoryDisplaySelection {
        $this->assertWebsite($websiteId);
        $this->assertStoreId($storeId);
        if ($channelId < 0 || $categoryId <= 0 || $position < 0) {
            throw new \InvalidArgumentException((string)__('展示选择参数无效'));
        }
        if ($storeId === 0 && $channelId === 0) {
            throw new \InvalidArgumentException((string)__('展示选择需要 store_id 或 channel_id'));
        }

        $existing = $this->find($websiteId, $storeId, $channelId, $categoryId);
        if ($existing !== null) {
            $existing
                ->setData(CategoryDisplaySelection::schema_fields_ENABLED, $enabled ? 1 : 0)
                ->setData(CategoryDisplaySelection::schema_fields_POSITION, $position)
                ->save();

            return $this->find($websiteId, $storeId, $channelId, $categoryId)
                ?? throw new \RuntimeException('category_display_selection_readback_failed');
        }

        $model = $this->newModel($websiteId);
        $model->clear()->setData([
            CategoryDisplaySelection::schema_fields_STORE_ID => $storeId,
            CategoryDisplaySelection::schema_fields_CHANNEL_ID => $channelId,
            CategoryDisplaySelection::schema_fields_CATEGORY_ID => $categoryId,
            CategoryDisplaySelection::schema_fields_ENABLED => $enabled ? 1 : 0,
            CategoryDisplaySelection::schema_fields_POSITION => $position,
        ])->save();

        return $this->find($websiteId, $storeId, $channelId, $categoryId)
            ?? throw new \RuntimeException('category_display_selection_readback_failed');
    }

    public function find(int $websiteId, int $storeId, int $channelId, int $categoryId): ?CategoryDisplaySelection
    {
        $this->assertWebsite($websiteId);
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(CategoryDisplaySelection::schema_fields_STORE_ID, $storeId)
            ->where(CategoryDisplaySelection::schema_fields_CHANNEL_ID, $channelId)
            ->where(CategoryDisplaySelection::schema_fields_CATEGORY_ID, $categoryId)
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    protected function newModel(int $websiteId): AbstractWebsiteShardModel
    {
        if (is_callable($this->modelFactory)) {
            return ($this->modelFactory)($websiteId);
        }

        /** @var CategoryDisplaySelection $model */
        $model = ObjectManager::getInstance(CategoryDisplaySelection::class, [], false);

        return $model->forWebsite($websiteId);
    }
}
