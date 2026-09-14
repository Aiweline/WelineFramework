<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationQueueService;
use Weline\Shipping\Model\FreeShippingConditionType;
use Weline\Shipping\Model\FreeShippingConditionType\LocalDescription as FreeShippingConditionTypeLocalDescription;
use Weline\Shipping\Model\FreeShippingRule;

/**
 * 免邮条件类型字典：list/label + 种子；LocalModel 可翻译。
 */
final class FreeShippingConditionTypeAdminService
{
    /** @var list<array{condition_code:string,condition_name:string,sort_order:int}> */
    public const DEFAULT_SEEDS = [
        ['condition_code' => FreeShippingRule::CONDITION_ORDER_AMOUNT, 'condition_name' => '订单金额', 'sort_order' => 10],
        ['condition_code' => FreeShippingRule::CONDITION_REGION, 'condition_name' => '目的地区域', 'sort_order' => 20],
        ['condition_code' => FreeShippingRule::CONDITION_MIXED, 'condition_name' => '金额 + 地区', 'sort_order' => 30],
        ['condition_code' => FreeShippingRule::CONDITION_MEMBER_LEVEL, 'condition_name' => '会员等级', 'sort_order' => 40],
        ['condition_code' => FreeShippingRule::CONDITION_COUPON, 'condition_name' => '优惠券', 'sort_order' => 50],
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

        /** @var FreeShippingConditionType $model */
        $model = $this->objectManager->getInstance(FreeShippingConditionType::class);
        $items = $model->reset()
            ->order(FreeShippingConditionType::schema_fields_SORT_ORDER, 'ASC')
            ->order(FreeShippingConditionType::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $out = [];
        foreach ($items as $item) {
            if ($item instanceof FreeShippingConditionType) {
                $out[] = $this->rowFromModel($item);
            }
        }

        return $this->listCache = $out;
    }

    /**
     * @return array<string, array<string, mixed>> condition_code => row
     */
    public function mapByCode(): array
    {
        $out = [];
        foreach ($this->listAll() as $row) {
            $code = strtolower(trim((string)($row['condition_code'] ?? '')));
            if ($code !== '') {
                $out[$code] = $row;
            }
        }

        return $out;
    }

    public function labelForCode(string $conditionCode): string
    {
        $code = strtolower(trim($conditionCode));
        if ($code === '') {
            return (string)__('未说明');
        }
        foreach ($this->listAll() as $row) {
            if (strtolower((string)($row['condition_code'] ?? '')) === $code) {
                $label = trim((string)($row['display_name'] ?? $row['condition_name'] ?? ''));

                return $label !== '' ? $label : $code;
            }
        }

        return $code;
    }

    /**
     * Upsert seed defaults. Never deletes. Preserves merchant-edited names.
     */
    public function seedDefaults(): int
    {
        $n = 0;
        $now = date('Y-m-d H:i:s');
        foreach (self::DEFAULT_SEEDS as $seed) {
            $code = strtolower(trim((string)$seed['condition_code']));
            $name = trim((string)$seed['condition_name']);
            $sort = (int)$seed['sort_order'];
            $existing = $this->findByCode($code);
            if ($existing instanceof FreeShippingConditionType) {
                $existing->setData(FreeShippingConditionType::schema_fields_ORIGIN, FreeShippingConditionType::ORIGIN_SEED);
                $existing->setData(FreeShippingConditionType::schema_fields_IS_ACTIVE, 1);
                if (trim((string)$existing->getData(FreeShippingConditionType::schema_fields_CONDITION_NAME)) === '') {
                    $existing->setData(FreeShippingConditionType::schema_fields_CONDITION_NAME, $name);
                }
                $existing->setData(FreeShippingConditionType::schema_fields_UPDATED_AT, $now);
                $existing->save();
            } else {
                /** @var FreeShippingConditionType $row */
                $row = $this->objectManager->getInstance(FreeShippingConditionType::class);
                $row->clearData()->setData([
                    FreeShippingConditionType::schema_fields_CONDITION_CODE => $code,
                    FreeShippingConditionType::schema_fields_CONDITION_NAME => $name,
                    FreeShippingConditionType::schema_fields_ORIGIN => FreeShippingConditionType::ORIGIN_SEED,
                    FreeShippingConditionType::schema_fields_IS_ACTIVE => 1,
                    FreeShippingConditionType::schema_fields_SORT_ORDER => $sort,
                    FreeShippingConditionType::schema_fields_CREATED_AT => $now,
                    FreeShippingConditionType::schema_fields_UPDATED_AT => $now,
                ]);
                $row->save();
            }
            ++$n;
        }
        $this->listCache = null;
        $this->enqueueTranslation();

        return $n;
    }

    private function findByCode(string $code): ?FreeShippingConditionType
    {
        /** @var FreeShippingConditionType $model */
        $model = $this->objectManager->getInstance(FreeShippingConditionType::class);
        $items = $model->reset()
            ->where(FreeShippingConditionType::schema_fields_CONDITION_CODE, $code)
            ->select()
            ->fetch()
            ->getItems();
        $existing = is_array($items) ? ($items[0] ?? null) : null;

        return $existing instanceof FreeShippingConditionType && (int)$existing->getId() > 0 ? $existing : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFromModel(FreeShippingConditionType $item): array
    {
        $typeId = (int)$item->getId();
        $code = (string)$item->getData(FreeShippingConditionType::schema_fields_CONDITION_CODE);
        $name = (string)$item->getData(FreeShippingConditionType::schema_fields_CONDITION_NAME);
        $origin = strtolower(trim((string)$item->getData(FreeShippingConditionType::schema_fields_ORIGIN)));
        if ($origin === '') {
            $origin = FreeShippingConditionType::ORIGIN_SEED;
        }
        $display = $this->resolveDisplayName($typeId, $name);

        return [
            'type_id' => $typeId,
            'condition_code' => $code,
            'condition_name' => $name,
            'display_name' => $display !== '' ? $display : ($name !== '' ? $name : $code),
            'origin' => $origin,
            'is_seed' => $origin === FreeShippingConditionType::ORIGIN_SEED ? 1 : 0,
            'is_active' => (int)$item->getData(FreeShippingConditionType::schema_fields_IS_ACTIVE),
            'sort_order' => (int)$item->getData(FreeShippingConditionType::schema_fields_SORT_ORDER),
        ];
    }

    private function resolveDisplayName(int $typeId, string $fallback): string
    {
        if ($typeId <= 0) {
            return $fallback;
        }
        $locale = trim((string)Cookie::getLang());
        if ($locale === '') {
            return $fallback;
        }
        try {
            /** @var FreeShippingConditionTypeLocalDescription $local */
            $local = $this->objectManager->getInstance(FreeShippingConditionTypeLocalDescription::class);
            $items = $local->reset()
                ->where(FreeShippingConditionTypeLocalDescription::schema_fields_ID, $typeId)
                ->where(FreeShippingConditionTypeLocalDescription::schema_fields_local_code, $locale)
                ->select()
                ->fetch()
                ->getItems();
            foreach ($items as $item) {
                if ($item instanceof FreeShippingConditionTypeLocalDescription) {
                    $name = trim((string)$item->getData(FreeShippingConditionTypeLocalDescription::schema_fields_CONDITION_NAME));
                    if ($name !== '') {
                        return $name;
                    }
                }
            }
        } catch (\Throwable) {
            // Fall back to main table name.
        }

        return $fallback;
    }

    private function enqueueTranslation(): void
    {
        try {
            /** @var LocalModelTranslationQueueService $queue */
            $queue = $this->objectManager->getInstance(LocalModelTranslationQueueService::class);
            $queue->enqueueScan(FreeShippingConditionTypeLocalDescription::class);
        } catch (\Throwable) {
            // Queue optional.
        }
    }
}
