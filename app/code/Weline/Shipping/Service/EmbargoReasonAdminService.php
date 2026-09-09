<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationQueueService;
use Weline\Shipping\Model\EmbargoReason;
use Weline\Shipping\Model\EmbargoReason\LocalDescription as EmbargoReasonLocalDescription;

/**
 * 禁运原因字典：list/save/delete + 当前语言展示名；种子不可删。
 */
final class EmbargoReasonAdminService
{
    public const ERROR_INVALID_CODE = 'embargo_reason_invalid_code';
    public const ERROR_INVALID_NAME = 'embargo_reason_invalid_name';
    public const ERROR_DELETE_FORBIDDEN = 'embargo_reason_delete_forbidden';
    public const ERROR_NOT_FOUND = 'embargo_reason_not_found';

    /** @var list<array{reason_code:string,reason_name:string,sort_order:int}> */
    public const DEFAULT_SEEDS = [
        ['reason_code' => 'territory', 'reason_name' => '特殊领土（无常驻人口或特殊辖区）', 'sort_order' => 10],
        ['reason_code' => 'no_commerce', 'reason_name' => '无正常商业往来', 'sort_order' => 20],
        ['reason_code' => 'sanction', 'reason_name' => '制裁或贸易限制', 'sort_order' => 30],
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

        /** @var EmbargoReason $model */
        $model = $this->objectManager->getInstance(EmbargoReason::class);
        $items = $model->reset()
            ->order(EmbargoReason::schema_fields_SORT_ORDER, 'ASC')
            ->order(EmbargoReason::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $out = [];
        foreach ($items as $item) {
            if ($item instanceof EmbargoReason) {
                $out[] = $this->rowFromModel($item);
            }
        }

        return $this->listCache = $out;
    }

    /**
     * 启用中的原因，供新增禁运下拉。
     *
     * @return list<array{reason_code:string,reason_name:string,label:string}>
     */
    public function listActiveOptions(): array
    {
        $out = [];
        foreach ($this->listAll() as $row) {
            if (empty($row['is_active'])) {
                continue;
            }
            $code = (string)($row['reason_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $label = (string)($row['display_name'] ?? $row['reason_name'] ?? $code);
            $out[] = [
                'reason_code' => $code,
                'reason_name' => (string)($row['reason_name'] ?? ''),
                'label' => $label !== '' ? $label : $code,
            ];
        }

        return $out;
    }

    public function labelForCode(string $reasonCode): string
    {
        $code = strtolower(trim($reasonCode));
        if ($code === '') {
            return (string)__('未说明');
        }
        foreach ($this->listAll() as $row) {
            if (strtolower((string)($row['reason_code'] ?? '')) === $code) {
                $label = trim((string)($row['display_name'] ?? $row['reason_name'] ?? ''));

                return $label !== '' ? $label : $code;
            }
        }

        return $reasonCode;
    }

    /**
     * Upsert by reason_code. Seed rows keep origin=seed.
     *
     * @return array<string, mixed>
     */
    public function save(string $reasonCode, string $reasonName, ?int $sortOrder = null, bool $active = true): array
    {
        $code = strtolower(trim($reasonCode));
        if ($code === '' || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $code)) {
            throw new \InvalidArgumentException(self::ERROR_INVALID_CODE);
        }
        $name = trim($reasonName);
        if ($name === '') {
            throw new \InvalidArgumentException(self::ERROR_INVALID_NAME);
        }

        $existing = $this->findByCode($code);
        $now = date('Y-m-d H:i:s');
        if ($existing instanceof EmbargoReason) {
            $existing->setData(EmbargoReason::schema_fields_REASON_NAME, $name);
            $existing->setData(EmbargoReason::schema_fields_IS_ACTIVE, $active ? 1 : 0);
            if ($sortOrder !== null) {
                $existing->setData(EmbargoReason::schema_fields_SORT_ORDER, max(0, $sortOrder));
            }
            $existing->setData(EmbargoReason::schema_fields_UPDATED_AT, $now);
            $existing->save();
            $this->listCache = null;
            $this->enqueueTranslation();

            return $this->rowFromModel($existing);
        }

        /** @var EmbargoReason $row */
        $row = $this->objectManager->getInstance(EmbargoReason::class);
        $row->clearData()->setData([
            EmbargoReason::schema_fields_REASON_CODE => $code,
            EmbargoReason::schema_fields_REASON_NAME => $name,
            EmbargoReason::schema_fields_ORIGIN => EmbargoReason::ORIGIN_MANUAL,
            EmbargoReason::schema_fields_IS_ACTIVE => $active ? 1 : 0,
            EmbargoReason::schema_fields_SORT_ORDER => $sortOrder !== null ? max(0, $sortOrder) : $this->nextSortOrder(),
            EmbargoReason::schema_fields_CREATED_AT => $now,
            EmbargoReason::schema_fields_UPDATED_AT => $now,
        ]);
        $row->save();
        $this->listCache = null;
        $this->enqueueTranslation();

        return $this->rowFromModel($row);
    }

    public function delete(int $reasonId): void
    {
        $row = $this->requireRow($reasonId);
        $origin = strtolower(trim((string)$row->getData(EmbargoReason::schema_fields_ORIGIN)));
        if ($origin !== EmbargoReason::ORIGIN_MANUAL) {
            throw new \RuntimeException(self::ERROR_DELETE_FORBIDDEN);
        }
        $row->delete();
        $this->listCache = null;
    }

    /**
     * Upsert seed defaults. Never deletes.
     */
    public function seedDefaults(): int
    {
        $n = 0;
        $now = date('Y-m-d H:i:s');
        foreach (self::DEFAULT_SEEDS as $seed) {
            $code = strtolower(trim((string)$seed['reason_code']));
            $name = trim((string)$seed['reason_name']);
            $sort = (int)$seed['sort_order'];
            $existing = $this->findByCode($code);
            if ($existing instanceof EmbargoReason) {
                // 保留运营改过的名称；仅确保种子标记与启用。
                $existing->setData(EmbargoReason::schema_fields_ORIGIN, EmbargoReason::ORIGIN_SEED);
                $existing->setData(EmbargoReason::schema_fields_IS_ACTIVE, 1);
                if (trim((string)$existing->getData(EmbargoReason::schema_fields_REASON_NAME)) === '') {
                    $existing->setData(EmbargoReason::schema_fields_REASON_NAME, $name);
                }
                $existing->setData(EmbargoReason::schema_fields_UPDATED_AT, $now);
                $existing->save();
            } else {
                /** @var EmbargoReason $row */
                $row = $this->objectManager->getInstance(EmbargoReason::class);
                $row->clearData()->setData([
                    EmbargoReason::schema_fields_REASON_CODE => $code,
                    EmbargoReason::schema_fields_REASON_NAME => $name,
                    EmbargoReason::schema_fields_ORIGIN => EmbargoReason::ORIGIN_SEED,
                    EmbargoReason::schema_fields_IS_ACTIVE => 1,
                    EmbargoReason::schema_fields_SORT_ORDER => $sort,
                    EmbargoReason::schema_fields_CREATED_AT => $now,
                    EmbargoReason::schema_fields_UPDATED_AT => $now,
                ]);
                $row->save();
            }
            $n++;
        }
        $this->listCache = null;
        $this->enqueueTranslation();

        return $n;
    }

    private function findByCode(string $code): ?EmbargoReason
    {
        /** @var EmbargoReason $model */
        $model = $this->objectManager->getInstance(EmbargoReason::class);
        $items = $model->reset()
            ->where(EmbargoReason::schema_fields_REASON_CODE, $code)
            ->select()
            ->fetch()
            ->getItems();
        foreach ($items as $item) {
            if ($item instanceof EmbargoReason) {
                return $item;
            }
        }

        return null;
    }

    private function requireRow(int $reasonId): EmbargoReason
    {
        /** @var EmbargoReason $model */
        $model = $this->objectManager->getInstance(EmbargoReason::class);
        $model->load($reasonId);
        if (!(int)$model->getId()) {
            throw new \InvalidArgumentException(self::ERROR_NOT_FOUND);
        }

        return $model;
    }

    private function nextSortOrder(): int
    {
        $max = 0;
        foreach ($this->listAll() as $row) {
            $max = max($max, (int)($row['sort_order'] ?? 0));
        }

        return $max + 10;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFromModel(EmbargoReason $item): array
    {
        $origin = strtolower(trim((string)$item->getData(EmbargoReason::schema_fields_ORIGIN)));
        if ($origin !== EmbargoReason::ORIGIN_MANUAL) {
            $origin = EmbargoReason::ORIGIN_SEED;
        }
        $defaultName = trim((string)$item->getData(EmbargoReason::schema_fields_REASON_NAME));
        $localized = $this->localizedName((int)$item->getId(), $defaultName);

        return [
            'reason_id' => (int)$item->getId(),
            'reason_code' => (string)$item->getData(EmbargoReason::schema_fields_REASON_CODE),
            'reason_name' => $defaultName,
            'display_name' => $localized,
            'origin' => $origin,
            'is_seed' => $origin === EmbargoReason::ORIGIN_SEED ? 1 : 0,
            'can_delete' => $origin === EmbargoReason::ORIGIN_MANUAL ? 1 : 0,
            'is_active' => (int)$item->getData(EmbargoReason::schema_fields_IS_ACTIVE) === 1 ? 1 : 0,
            'sort_order' => (int)$item->getData(EmbargoReason::schema_fields_SORT_ORDER),
        ];
    }

    private function localizedName(int $reasonId, string $defaultName): string
    {
        if ($reasonId <= 0) {
            return $defaultName;
        }
        $locale = trim((string)Cookie::getLangLocal());
        if ($locale === '') {
            $locale = 'zh_Hans_CN';
        }
        try {
            /** @var EmbargoReasonLocalDescription $local */
            $local = $this->objectManager->getInstance(EmbargoReasonLocalDescription::class);
            $items = $local->reset()
                ->where(EmbargoReasonLocalDescription::schema_fields_ID, $reasonId)
                ->where(EmbargoReasonLocalDescription::schema_fields_local_code, $locale)
                ->select()
                ->fetch()
                ->getItems();
            foreach ($items as $item) {
                if ($item instanceof EmbargoReasonLocalDescription) {
                    $name = trim((string)$item->getData(EmbargoReason::schema_fields_REASON_NAME));
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
            $queue->enqueue('Weline_Shipping:EmbargoReasonAdminService', false);
        } catch (\Throwable) {
            // Queue optional.
        }
    }
}
