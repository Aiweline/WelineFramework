<?php

declare(strict_types=1);

namespace Weline\Inventory\Service;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationQueueService;
use Weline\Inventory\Model\Warehouse;
use Weline\Inventory\Model\WarehouseCodeLabel;
use Weline\Inventory\Model\WarehouseCodeLabel\LocalDescription as WarehouseCodeLabelLocal;

/**
 * 仓库枚举码别名：list/save/delete + 当前语言展示名；种子不可删。
 */
final class WarehouseCodeLabelAdminService
{
    public const ERROR_INVALID_GROUP = 'warehouse_code_label_invalid_group';
    public const ERROR_INVALID_CODE = 'warehouse_code_label_invalid_code';
    public const ERROR_INVALID_NAME = 'warehouse_code_label_invalid_name';
    public const ERROR_DELETE_FORBIDDEN = 'warehouse_code_label_delete_forbidden';
    public const ERROR_NOT_FOUND = 'warehouse_code_label_not_found';

    /** @var list<array{code_group:string,code:string,label_name:string,sort_order:int}> */
    public const DEFAULT_SEEDS = [
        ['code_group' => WarehouseCodeLabel::GROUP_NODE_KIND, 'code' => Warehouse::NODE_COUNTRY, 'label_name' => '国家', 'sort_order' => 10],
        ['code_group' => WarehouseCodeLabel::GROUP_NODE_KIND, 'code' => Warehouse::NODE_PROVINCE, 'label_name' => '省份', 'sort_order' => 20],
        ['code_group' => WarehouseCodeLabel::GROUP_NODE_KIND, 'code' => Warehouse::NODE_WAREHOUSE, 'label_name' => '仓库', 'sort_order' => 30],
        ['code_group' => WarehouseCodeLabel::GROUP_MODE, 'code' => Warehouse::MODE_NORMAL, 'label_name' => '正式', 'sort_order' => 10],
        ['code_group' => WarehouseCodeLabel::GROUP_MODE, 'code' => Warehouse::MODE_TEST, 'label_name' => '测试', 'sort_order' => 20],
        ['code_group' => WarehouseCodeLabel::GROUP_WAREHOUSE_TYPE, 'code' => Warehouse::TYPE_LOGICAL, 'label_name' => '逻辑仓', 'sort_order' => 10],
        ['code_group' => WarehouseCodeLabel::GROUP_WAREHOUSE_TYPE, 'code' => Warehouse::TYPE_PHYSICAL, 'label_name' => '物理仓', 'sort_order' => 20],
    ];

    /** @var array<string, string> */
    public const GROUP_TITLES = [
        WarehouseCodeLabel::GROUP_NODE_KIND => '层级',
        WarehouseCodeLabel::GROUP_MODE => '模式',
        WarehouseCodeLabel::GROUP_WAREHOUSE_TYPE => '类型',
    ];

    /** @var list<array<string, mixed>>|null */
    private ?array $listCache = null;

    public function __construct(
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        if ($this->listCache !== null) {
            return $this->listCache;
        }

        /** @var WarehouseCodeLabel $model */
        $model = $this->objectManager->getInstance(WarehouseCodeLabel::class);
        $items = $model->reset()
            ->order(WarehouseCodeLabel::schema_fields_CODE_GROUP, 'ASC')
            ->order(WarehouseCodeLabel::schema_fields_SORT_ORDER, 'ASC')
            ->order(WarehouseCodeLabel::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $out = [];
        foreach ($items as $item) {
            if ($item instanceof WarehouseCodeLabel) {
                $out[] = $this->rowFromModel($item);
            }
        }

        return $this->listCache = $out;
    }

    public function labelFor(string $group, string $code): string
    {
        $group = strtolower(trim($group));
        $code = strtolower(trim($code));
        if ($group === '' || $code === '') {
            return $code;
        }
        foreach ($this->listAll() as $row) {
            if ((string)($row['code_group'] ?? '') !== $group) {
                continue;
            }
            if (strtolower((string)($row['code'] ?? '')) !== $code) {
                continue;
            }
            $label = trim((string)($row['display_name'] ?? $row['label_name'] ?? ''));

            return $label !== '' ? $label : $code;
        }

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    public function save(string $group, string $code, string $labelName, ?int $sortOrder = null, bool $active = true): array
    {
        $group = strtolower(trim($group));
        if (!in_array($group, WarehouseCodeLabel::GROUPS, true)) {
            throw new \InvalidArgumentException(self::ERROR_INVALID_GROUP);
        }
        $code = strtolower(trim($code));
        if ($code === '' || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $code)) {
            throw new \InvalidArgumentException(self::ERROR_INVALID_CODE);
        }
        $name = trim($labelName);
        if ($name === '') {
            throw new \InvalidArgumentException(self::ERROR_INVALID_NAME);
        }

        $existing = $this->findByGroupCode($group, $code);
        $now = date('Y-m-d H:i:s');
        if ($existing instanceof WarehouseCodeLabel) {
            $existing->setData(WarehouseCodeLabel::schema_fields_LABEL_NAME, $name);
            $existing->setData(WarehouseCodeLabel::schema_fields_IS_ACTIVE, $active ? 1 : 0);
            if ($sortOrder !== null) {
                $existing->setData(WarehouseCodeLabel::schema_fields_SORT_ORDER, max(0, $sortOrder));
            }
            $existing->setData(WarehouseCodeLabel::schema_fields_UPDATED_AT, $now);
            $existing->save();
            $this->listCache = null;
            $this->enqueueTranslation();

            return $this->rowFromModel($existing);
        }

        /** @var WarehouseCodeLabel $row */
        $row = $this->objectManager->getInstance(WarehouseCodeLabel::class);
        $row->clearData()->setData([
            WarehouseCodeLabel::schema_fields_CODE_GROUP => $group,
            WarehouseCodeLabel::schema_fields_CODE => $code,
            WarehouseCodeLabel::schema_fields_LABEL_NAME => $name,
            WarehouseCodeLabel::schema_fields_ORIGIN => WarehouseCodeLabel::ORIGIN_MANUAL,
            WarehouseCodeLabel::schema_fields_IS_ACTIVE => $active ? 1 : 0,
            WarehouseCodeLabel::schema_fields_SORT_ORDER => $sortOrder !== null ? max(0, $sortOrder) : $this->nextSortOrder($group),
            WarehouseCodeLabel::schema_fields_CREATED_AT => $now,
            WarehouseCodeLabel::schema_fields_UPDATED_AT => $now,
        ]);
        $row->save();
        $this->listCache = null;
        $this->enqueueTranslation();

        return $this->rowFromModel($row);
    }

    public function delete(int $labelId): void
    {
        $row = $this->requireRow($labelId);
        $origin = strtolower(trim((string)$row->getData(WarehouseCodeLabel::schema_fields_ORIGIN)));
        if ($origin !== WarehouseCodeLabel::ORIGIN_MANUAL) {
            throw new \RuntimeException(self::ERROR_DELETE_FORBIDDEN);
        }
        $row->delete();
        $this->listCache = null;
    }

    public function seedDefaults(): int
    {
        $n = 0;
        $now = date('Y-m-d H:i:s');
        foreach (self::DEFAULT_SEEDS as $seed) {
            $group = strtolower(trim((string)$seed['code_group']));
            $code = strtolower(trim((string)$seed['code']));
            $name = trim((string)$seed['label_name']);
            $sort = (int)$seed['sort_order'];
            $existing = $this->findByGroupCode($group, $code);
            if ($existing instanceof WarehouseCodeLabel) {
                $existing->setData(WarehouseCodeLabel::schema_fields_ORIGIN, WarehouseCodeLabel::ORIGIN_SEED);
                $existing->setData(WarehouseCodeLabel::schema_fields_IS_ACTIVE, 1);
                if (trim((string)$existing->getData(WarehouseCodeLabel::schema_fields_LABEL_NAME)) === '') {
                    $existing->setData(WarehouseCodeLabel::schema_fields_LABEL_NAME, $name);
                }
                $existing->setData(WarehouseCodeLabel::schema_fields_UPDATED_AT, $now);
                $existing->save();
            } else {
                /** @var WarehouseCodeLabel $row */
                $row = $this->objectManager->getInstance(WarehouseCodeLabel::class);
                $row->clearData()->setData([
                    WarehouseCodeLabel::schema_fields_CODE_GROUP => $group,
                    WarehouseCodeLabel::schema_fields_CODE => $code,
                    WarehouseCodeLabel::schema_fields_LABEL_NAME => $name,
                    WarehouseCodeLabel::schema_fields_ORIGIN => WarehouseCodeLabel::ORIGIN_SEED,
                    WarehouseCodeLabel::schema_fields_IS_ACTIVE => 1,
                    WarehouseCodeLabel::schema_fields_SORT_ORDER => $sort,
                    WarehouseCodeLabel::schema_fields_CREATED_AT => $now,
                    WarehouseCodeLabel::schema_fields_UPDATED_AT => $now,
                ]);
                $row->save();
            }
            $n++;
        }
        $this->listCache = null;
        $this->enqueueTranslation();

        return $n;
    }

    private function findByGroupCode(string $group, string $code): ?WarehouseCodeLabel
    {
        /** @var WarehouseCodeLabel $model */
        $model = $this->objectManager->getInstance(WarehouseCodeLabel::class);
        $items = $model->reset()
            ->where(WarehouseCodeLabel::schema_fields_CODE_GROUP, $group)
            ->where(WarehouseCodeLabel::schema_fields_CODE, $code)
            ->select()
            ->fetch()
            ->getItems();
        foreach ($items as $item) {
            if ($item instanceof WarehouseCodeLabel) {
                return $item;
            }
        }

        return null;
    }

    private function requireRow(int $labelId): WarehouseCodeLabel
    {
        /** @var WarehouseCodeLabel $model */
        $model = $this->objectManager->getInstance(WarehouseCodeLabel::class);
        $model->load($labelId);
        if (!(int)$model->getId()) {
            throw new \InvalidArgumentException(self::ERROR_NOT_FOUND);
        }

        return $model;
    }

    private function nextSortOrder(string $group): int
    {
        $max = 0;
        foreach ($this->listAll() as $row) {
            if ((string)($row['code_group'] ?? '') !== $group) {
                continue;
            }
            $max = max($max, (int)($row['sort_order'] ?? 0));
        }

        return $max + 10;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFromModel(WarehouseCodeLabel $item): array
    {
        $origin = strtolower(trim((string)$item->getData(WarehouseCodeLabel::schema_fields_ORIGIN)));
        if ($origin !== WarehouseCodeLabel::ORIGIN_MANUAL) {
            $origin = WarehouseCodeLabel::ORIGIN_SEED;
        }
        $group = (string)$item->getData(WarehouseCodeLabel::schema_fields_CODE_GROUP);
        $defaultName = trim((string)$item->getData(WarehouseCodeLabel::schema_fields_LABEL_NAME));
        $localized = $this->localizedName((int)$item->getId(), $defaultName);
        $groupTitle = self::GROUP_TITLES[$group] ?? $group;

        return [
            'label_id' => (int)$item->getId(),
            'code_group' => $group,
            'group_title' => (string)\__($groupTitle),
            'code' => (string)$item->getData(WarehouseCodeLabel::schema_fields_CODE),
            'label_name' => $defaultName,
            'display_name' => $localized,
            'origin' => $origin,
            'is_seed' => $origin === WarehouseCodeLabel::ORIGIN_SEED ? 1 : 0,
            'can_delete' => $origin === WarehouseCodeLabel::ORIGIN_MANUAL ? 1 : 0,
            'is_active' => (int)$item->getData(WarehouseCodeLabel::schema_fields_IS_ACTIVE) === 1 ? 1 : 0,
            'sort_order' => (int)$item->getData(WarehouseCodeLabel::schema_fields_SORT_ORDER),
        ];
    }

    private function localizedName(int $labelId, string $defaultName): string
    {
        if ($labelId <= 0) {
            return $defaultName;
        }
        $locale = trim((string)Cookie::getLangLocal());
        if ($locale === '') {
            $locale = 'zh_Hans_CN';
        }
        try {
            /** @var WarehouseCodeLabelLocal $local */
            $local = $this->objectManager->getInstance(WarehouseCodeLabelLocal::class);
            $items = $local->reset()
                ->where(WarehouseCodeLabelLocal::schema_fields_ID, $labelId)
                ->where(WarehouseCodeLabelLocal::schema_fields_local_code, $locale)
                ->select()
                ->fetch()
                ->getItems();
            foreach ($items as $item) {
                if ($item instanceof WarehouseCodeLabelLocal) {
                    $name = trim((string)$item->getData(WarehouseCodeLabel::schema_fields_LABEL_NAME));
                    if ($name !== '') {
                        return $name;
                    }
                }
            }
        } catch (\Throwable) {
            // Fall back to main-table name.
        }

        return $defaultName;
    }

    private function enqueueTranslation(): void
    {
        try {
            /** @var LocalModelTranslationQueueService $queue */
            $queue = $this->objectManager->getInstance(LocalModelTranslationQueueService::class);
            $queue->enqueue('Weline_Inventory:WarehouseCodeLabelAdminService', false);
        } catch (\Throwable) {
            // Queue optional.
        }
    }
}
