<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\Category;
use Weline\Product\Service\ProductShardProvisioner;

final class CategoryRepository extends AbstractWebsiteShardRepository
{
    /** @var (\Closure(int): Category)|null */
    private readonly mixed $modelFactory;

    /**
     * @param (\Closure(int): Category)|null $modelFactory
     */
    public function __construct(
        ProductShardProvisioner $provisioner,
        ?callable $modelFactory = null,
    ) {
        parent::__construct($provisioner);
        $this->modelFactory = $modelFactory;
    }

    public function findById(int $websiteId, int $categoryId): ?Category
    {
        $this->assertWebsite($websiteId);
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(Category::schema_fields_ID, $categoryId)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    public function findByGlobalUuid(int $websiteId, string $uuid): ?Category
    {
        $this->assertWebsite($websiteId);
        $uuid = $this->normalizeUuid($uuid);
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(Category::schema_fields_GLOBAL_CATEGORY_UUID, $uuid)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    /** @return list<array<string, mixed>> */
    public function listAll(int $websiteId): array
    {
        $this->assertWebsite($websiteId);
        $rows = $this->newModel($websiteId)
            ->clear()
            ->select()
            ->fetchArray();
        usort(
            $rows,
            static function (array $left, array $right): int {
                $leftParent = (int)($left[Category::schema_fields_PARENT_ID] ?? 0);
                $rightParent = (int)($right[Category::schema_fields_PARENT_ID] ?? 0);
                if ($leftParent !== $rightParent) {
                    return $leftParent <=> $rightParent;
                }
                $leftPosition = (int)($left[Category::schema_fields_POSITION] ?? 0);
                $rightPosition = (int)($right[Category::schema_fields_POSITION] ?? 0);
                if ($leftPosition !== $rightPosition) {
                    return $leftPosition <=> $rightPosition;
                }

                return (int)($left[Category::schema_fields_ID] ?? 0)
                    <=> (int)($right[Category::schema_fields_ID] ?? 0);
            },
        );
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function listSiblings(int $websiteId, int $parentId, int $excludeId = 0): array
    {
        $parentField = Category::schema_fields_PARENT_ID;
        $rows = [];
        foreach ($this->listAll($websiteId) as $row) {
            $rowParent = (int)($row[$parentField] ?? 0);
            $rowId = (int)($row[Category::schema_fields_ID] ?? 0);
            if ($rowParent === $parentId && $rowId !== $excludeId) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function deleteById(int $websiteId, int $categoryId): void
    {
        $category = $this->findById($websiteId, $categoryId)
            ?? throw new \InvalidArgumentException(__('Category 不存在：%{1}', [$categoryId]));
        $category->delete();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateFields(int $websiteId, int $categoryId, array $data): Category
    {
        $category = $this->findById($websiteId, $categoryId)
            ?? throw new \InvalidArgumentException(__('Category 不存在：%{1}', [$categoryId]));
        foreach ($data as $field => $value) {
            $category->setData((string)$field, $value);
        }
        $category->save();

        return $this->findById($websiteId, $categoryId)
            ?? throw new \RuntimeException(__('Category 更新后无法回读：%{1}', [$categoryId]));
    }

    /** @param array<int, int> $positions id => position */
    public function batchUpdatePosition(int $websiteId, array $positions): void
    {
        if ($positions === []) {
            return;
        }
        $model = $this->newModel($websiteId);
        $connection = $model->getConnection();
        $table = $model->getTable();
        $ids = [];
        $cases = [];
        $idField = Category::schema_fields_ID;
        foreach ($positions as $nodeId => $position) {
            $nodeId = (int)$nodeId;
            $position = (int)$position;
            if ($nodeId <= 0) {
                continue;
            }
            $ids[] = $nodeId;
            $cases[] = "WHEN {$nodeId} THEN {$position}";
        }
        if ($ids === []) {
            return;
        }
        $idsStr = implode(',', $ids);
        $casesStr = implode(' ', $cases);
        $connection->query(
            "UPDATE `{$table}` SET `position` = CASE `{$idField}` {$casesStr} END WHERE `{$idField}` IN ({$idsStr})",
        )->fetch();
    }

    public function nextSiblingPosition(int $websiteId, int $parentId): int
    {
        $max = 0;
        foreach ($this->listSiblings($websiteId, $parentId) as $row) {
            $max = max($max, (int)($row[Category::schema_fields_POSITION] ?? 0));
        }

        return $max + 1;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $websiteId, array $data): Category
    {
        $this->assertWebsite($websiteId);
        $data[Category::schema_fields_GLOBAL_CATEGORY_UUID] = isset(
            $data[Category::schema_fields_GLOBAL_CATEGORY_UUID],
        )
            ? $this->normalizeUuid((string)$data[Category::schema_fields_GLOBAL_CATEGORY_UUID])
            : $this->newUuid();
        $model = $this->newModel($websiteId);
        $model->clear()->setData(array_merge([
            Category::schema_fields_STATUS => 'active',
            Category::schema_fields_PATH => '',
            Category::schema_fields_POSITION => 0,
        ], $data))->save();
        $id = (int)$model->getId();
        $loaded = $this->findById($websiteId, $id);
        if ($loaded === null) {
            throw new \RuntimeException(__('Category 写入后无法回读：%{1}', [$id]));
        }
        return $loaded;
    }

    /** @param array<string, mixed> $data */
    public function updateStructure(int $websiteId, int $categoryId, array $data): Category
    {
        $category = $this->findById($websiteId, $categoryId)
            ?? throw new \InvalidArgumentException(__('Category 不存在：%{1}', [$categoryId]));
        foreach ([
            Category::schema_fields_PARENT_ID,
            Category::schema_fields_PATH,
            Category::schema_fields_POSITION,
            Category::schema_fields_STATUS,
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $category->setData($field, $data[$field]);
            }
        }
        $category->save();
        return $this->findById($websiteId, $categoryId)
            ?? throw new \RuntimeException(__('Category 更新后无法回读：%{1}', [$categoryId]));
    }

    private function normalizeUuid(string $uuid): string
    {
        $uuid = strtolower(trim($uuid));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $uuid)) {
            throw new \InvalidArgumentException(__('global_category_uuid 格式非法'));
        }
        return $uuid;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    protected function newModel(int $websiteId): AbstractWebsiteShardModel
    {
        if ($this->modelFactory !== null) {
            return ($this->modelFactory)($websiteId);
        }
        /** @var Category $model */
        $model = ObjectManager::create(Category::class, [], false);
        return $model->forWebsite($websiteId);
    }
}
