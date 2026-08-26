<?php

declare(strict_types=1);

namespace Weline\Product\Repository;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\ResolvedScopeValue;
use Weline\Product\Model\Shard\AbstractWebsiteShardModel;
use Weline\Product\Model\Shard\AttributeValue;
use Weline\Product\Service\CatalogConflictException;
use Weline\Product\Service\CatalogOverlayResolver;
use Weline\Product\Service\ProductShardProvisioner;

/**
 * EAV Store overlay on Website shard (store_id=0 = Website).
 * cleared terminates locale + parent Scope fallback; delete overlay restores inherit.
 */
final class AttributeValueRepository extends AbstractWebsiteShardRepository
{
    /** @var (\Closure(int): AttributeValue)|null */
    private readonly mixed $modelFactory;

    /**
     * @param (\Closure(int): AttributeValue)|null $modelFactory
     */
    public function __construct(
        ProductShardProvisioner $provisioner,
        private readonly CatalogOverlayResolver $resolver = new CatalogOverlayResolver(),
        ?callable $modelFactory = null,
    ) {
        parent::__construct($provisioner);
        $this->modelFactory = $modelFactory;
    }

    /**
     * @param list<string> $localeFallback
     */
    public function read(
        int $websiteId,
        int $storeId,
        string $entityType,
        int $entityId,
        string $attributeCode,
        string $locale = '',
        array $localeFallback = [''],
    ): ResolvedScopeValue {
        $this->assertWebsite($websiteId);
        $this->assertStoreId($storeId);
        $rows = $this->loadRows($websiteId, $entityType, $entityId, $attributeCode);
        return $this->resolver->resolveAttribute($rows, $storeId, $locale, $localeFallback);
    }

    public function writeExplicit(
        int $websiteId,
        int $storeId,
        string $entityType,
        int $entityId,
        string $attributeCode,
        string $locale,
        mixed $value,
        bool $isRequired = false,
    ): void {
        $this->writeTyped(
            $websiteId,
            $storeId,
            $entityType,
            $entityId,
            $attributeCode,
            $locale,
            'string',
            $value,
            $isRequired,
        );
    }

    public function writeTyped(
        int $websiteId,
        int $storeId,
        string $entityType,
        int $entityId,
        string $attributeCode,
        string $locale,
        string $valueType,
        mixed $value,
        bool $isRequired = false,
    ): void {
        $valueType = strtolower(trim($valueType));
        if (!in_array($valueType, ['string', 'number', 'boolean', 'date', 'select', 'multiselect', 'json'], true)) {
            throw new \InvalidArgumentException('product_attribute_value_type_invalid');
        }

        $fields = [
            AttributeValue::schema_fields_VALUE_TEXT => null,
            'value_type' => $valueType,
            'value_string' => null,
            'value_number' => null,
            'value_boolean' => null,
            'value_date' => null,
            'value_json' => null,
            'scope_state' => 'explicit',
            AttributeValue::schema_fields_CLEARED => 0,
            AttributeValue::schema_fields_IS_REQUIRED => $isRequired ? 1 : 0,
        ];
        if ($valueType === 'string') {
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('product_attribute_string_invalid');
            }
            $fields['value_string'] = $value === null ? '' : (string)$value;
            $fields[AttributeValue::schema_fields_VALUE_TEXT] = $fields['value_string'];
        } elseif ($valueType === 'number') {
            $number = trim((string)$value);
            if (!preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $number)) {
                throw new \InvalidArgumentException('product_attribute_number_invalid');
            }
            $fields['value_number'] = $number;
            $fields[AttributeValue::schema_fields_VALUE_TEXT] = $number;
        } elseif ($valueType === 'boolean') {
            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($normalized === null) {
                throw new \InvalidArgumentException('product_attribute_boolean_invalid');
            }
            $fields['value_boolean'] = $normalized ? 1 : 0;
            $fields[AttributeValue::schema_fields_VALUE_TEXT] = $normalized ? '1' : '0';
        } elseif ($valueType === 'date') {
            try {
                $date = new \DateTimeImmutable((string)$value);
            } catch (\Throwable) {
                throw new \InvalidArgumentException('product_attribute_date_invalid');
            }
            $fields['value_date'] = $date->format('Y-m-d H:i:s');
            $fields[AttributeValue::schema_fields_VALUE_TEXT] = $fields['value_date'];
        } else {
            if ($valueType === 'select' && !is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('product_attribute_select_invalid');
            }
            if ($valueType === 'multiselect' && !is_array($value)) {
                throw new \InvalidArgumentException('product_attribute_multiselect_invalid');
            }
            $encoded = json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $fields['value_json'] = $encoded;
            $fields[AttributeValue::schema_fields_VALUE_TEXT] = $encoded;
        }

        $this->upsert(
            $websiteId,
            $storeId,
            $entityType,
            $entityId,
            $attributeCode,
            $locale,
            $fields,
        );
    }

    public function writeCleared(
        int $websiteId,
        int $storeId,
        string $entityType,
        int $entityId,
        string $attributeCode,
        string $locale,
        bool $isRequired = false,
    ): void {
        $this->upsert($websiteId, $storeId, $entityType, $entityId, $attributeCode, $locale, [
            AttributeValue::schema_fields_VALUE_TEXT => null,
            'value_string' => null,
            'value_number' => null,
            'value_boolean' => null,
            'value_date' => null,
            'value_json' => null,
            'scope_state' => 'cleared',
            AttributeValue::schema_fields_CLEARED => 1,
            AttributeValue::schema_fields_IS_REQUIRED => $isRequired ? 1 : 0,
        ]);
    }

    /**
     * Delete overlay row to restore parent inheritance (not the same as cleared).
     */
    public function deleteOverlay(
        int $websiteId,
        int $storeId,
        string $entityType,
        int $entityId,
        string $attributeCode,
        string $locale = '',
    ): void {
        $this->assertWebsite($websiteId);
        $this->assertStoreId($storeId);
        $model = $this->findRow($websiteId, $storeId, $entityType, $entityId, $attributeCode, $locale);
        if ($model !== null) {
            $model->delete();
        }
    }

    public function purgeEntity(int $websiteId, string $entityType, int $entityId): void
    {
        $this->assertWebsite($websiteId);
        $entityType = trim($entityType);
        if ($entityType === '' || $entityId <= 0) {
            return;
        }
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(AttributeValue::schema_fields_ENTITY_TYPE, $entityType)
            ->where(AttributeValue::schema_fields_ENTITY_ID, $entityId)
            ->delete()
            ->fetch();
    }

    /**
     * @param list<string> $localeFallback
     * @throws CatalogConflictException when required attribute is cleared
     */
    public function assertPublishable(
        int $websiteId,
        int $storeId,
        string $entityType,
        int $entityId,
        string $attributeCode,
        string $locale = '',
        array $localeFallback = [''],
    ): void {
        $resolved = $this->read(
            $websiteId,
            $storeId,
            $entityType,
            $entityId,
            $attributeCode,
            $locale,
            $localeFallback,
        );
        if (!$resolved->isCleared()) {
            return;
        }
        $rows = $this->loadRows($websiteId, $entityType, $entityId, $attributeCode);
        $required = false;
        foreach ($rows as $row) {
            if ((int)$row['store_id'] === $resolved->resolvedStoreId
                && (string)($row['locale'] ?? '') === $resolved->resolvedLocale
                && !empty($row['is_required'])
            ) {
                $required = true;
                break;
            }
        }
        if ($required) {
            throw new CatalogConflictException(
                'cleared_at_scope',
                __('Required 属性在 Scope/locale 上已 cleared，禁止发布：%{1}', [$attributeCode]),
                [
                    'website_id' => $websiteId,
                    'store_id' => $storeId,
                    'attribute_code' => $attributeCode,
                    'locale' => $locale,
                    'resolved_store_id' => $resolved->resolvedStoreId,
                ],
            );
        }
    }

    /**
     * Return explicit Website/Store rows without applying fallback.
     *
     * @param list<int> $entityIds
     * @param list<int> $storeIds
     * @return list<array{
     *   store_id:int,entity_type:string,entity_id:int,attribute_code:string,
     *   locale:string,value:mixed,cleared:bool,is_required:bool
     * }>
     */
    public function listExplicitRows(
        int $websiteId,
        string $entityType,
        array $entityIds,
        array $storeIds,
    ): array {
        $this->assertWebsite($websiteId);
        $entityType = trim($entityType);
        $entityIds = array_values(array_unique(array_filter(
            array_map('intval', $entityIds),
            static fn(int $id): bool => $id > 0,
        )));
        $storeIds = array_values(array_unique(array_filter(
            array_map('intval', $storeIds),
            static fn(int $id): bool => $id >= 0,
        )));
        if ($entityType === '' || $entityIds === [] || $storeIds === []) {
            return [];
        }
        $raw = $this->newModel($websiteId)
            ->clear()
            ->where(AttributeValue::schema_fields_ENTITY_TYPE, $entityType)
            ->where(AttributeValue::schema_fields_ENTITY_ID, $entityIds, 'IN')
            ->where(AttributeValue::schema_fields_STORE_ID, $storeIds, 'IN')
            ->select()
            ->fetchArray();
        $rows = [];
        foreach ($raw as $item) {
            $cleared = (string)($item['scope_state'] ?? '') === 'cleared'
                || (int)($item[AttributeValue::schema_fields_CLEARED] ?? 0) === 1;
            $rows[] = [
                'store_id' => (int)($item[AttributeValue::schema_fields_STORE_ID] ?? 0),
                'entity_type' => (string)($item[AttributeValue::schema_fields_ENTITY_TYPE] ?? ''),
                'entity_id' => (int)($item[AttributeValue::schema_fields_ENTITY_ID] ?? 0),
                'attribute_code' => (string)($item[AttributeValue::schema_fields_ATTRIBUTE_CODE] ?? ''),
                'locale' => (string)($item[AttributeValue::schema_fields_LOCALE] ?? ''),
                'value_type' => (string)($item['value_type'] ?? 'string'),
                'value' => $cleared ? null : $this->decodeTypedValue($item),
                'scope_state' => $cleared ? 'cleared' : (string)($item['scope_state'] ?? 'explicit'),
                'cleared' => $cleared,
                'is_required' => (int)($item[AttributeValue::schema_fields_IS_REQUIRED] ?? 0) === 1,
            ];
        }
        usort(
            $rows,
            static fn(array $left, array $right): int => [
                $left['entity_id'],
                $left['store_id'],
                $left['attribute_code'],
                $left['locale'],
            ] <=> [
                $right['entity_id'],
                $right['store_id'],
                $right['attribute_code'],
                $right['locale'],
            ],
        );
        return $rows;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function upsert(
        int $websiteId,
        int $storeId,
        string $entityType,
        int $entityId,
        string $attributeCode,
        string $locale,
        array $fields,
    ): void {
        $this->assertWebsite($websiteId);
        $this->assertStoreId($storeId);
        $existing = $this->findRow($websiteId, $storeId, $entityType, $entityId, $attributeCode, $locale);
        if ($existing !== null) {
            foreach ($fields as $k => $v) {
                $existing->setData($k, $v);
            }
            $existing->save();
            return;
        }
        $model = $this->newModel($websiteId);
        $model->clear()->setData(array_merge([
            AttributeValue::schema_fields_STORE_ID => $storeId,
            AttributeValue::schema_fields_ENTITY_TYPE => $entityType,
            AttributeValue::schema_fields_ENTITY_ID => $entityId,
            AttributeValue::schema_fields_ATTRIBUTE_CODE => $attributeCode,
            AttributeValue::schema_fields_LOCALE => trim($locale),
        ], $fields))->save();
    }

    private function findRow(
        int $websiteId,
        int $storeId,
        string $entityType,
        int $entityId,
        string $attributeCode,
        string $locale,
    ): ?AttributeValue {
        $model = $this->newModel($websiteId);
        $model->clear()
            ->where(AttributeValue::schema_fields_STORE_ID, $storeId)
            ->where(AttributeValue::schema_fields_ENTITY_TYPE, $entityType)
            ->where(AttributeValue::schema_fields_ENTITY_ID, $entityId)
            ->where(AttributeValue::schema_fields_ATTRIBUTE_CODE, $attributeCode)
            ->where(AttributeValue::schema_fields_LOCALE, trim($locale))
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    /**
     * @return list<array{store_id:int, locale?:string, cleared:bool, value?:mixed, is_required?:bool}>
     */
    private function loadRows(
        int $websiteId,
        string $entityType,
        int $entityId,
        string $attributeCode,
    ): array {
        $model = $this->newModel($websiteId);
        $raw = $model->clear()
            ->where(AttributeValue::schema_fields_ENTITY_TYPE, $entityType)
            ->where(AttributeValue::schema_fields_ENTITY_ID, $entityId)
            ->where(AttributeValue::schema_fields_ATTRIBUTE_CODE, $attributeCode)
            ->select()
            ->fetchArray();
        $rows = [];
        foreach ($raw as $item) {
            $cleared = (string)($item['scope_state'] ?? '') === 'cleared'
                || (int)($item[AttributeValue::schema_fields_CLEARED] ?? 0) === 1;
            $rows[] = [
                'store_id' => (int)($item[AttributeValue::schema_fields_STORE_ID] ?? 0),
                'locale' => (string)($item[AttributeValue::schema_fields_LOCALE] ?? ''),
                'cleared' => $cleared,
                'value' => $cleared ? null : $this->decodeTypedValue($item),
                'value_type' => (string)($item['value_type'] ?? 'string'),
                'scope_state' => $cleared ? 'cleared' : (string)($item['scope_state'] ?? 'explicit'),
                'is_required' => (int)($item[AttributeValue::schema_fields_IS_REQUIRED] ?? 0) === 1,
            ];
        }
        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function decodeTypedValue(array $row): mixed
    {
        $type = (string)($row['value_type'] ?? 'string');
        return match ($type) {
            'number' => $row['value_number'] ?? $row[AttributeValue::schema_fields_VALUE_TEXT] ?? null,
            'boolean' => isset($row['value_boolean']) ? (bool)$row['value_boolean'] : null,
            'date' => $row['value_date'] ?? $row[AttributeValue::schema_fields_VALUE_TEXT] ?? null,
            'select', 'multiselect', 'json' => $this->decodeJsonValue(
                $row['value_json'] ?? $row[AttributeValue::schema_fields_VALUE_TEXT] ?? null,
            ),
            default => $row['value_string'] ?? $row[AttributeValue::schema_fields_VALUE_TEXT] ?? null,
        };
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value;
        }
    }

    protected function newModel(int $websiteId): AbstractWebsiteShardModel
    {
        if ($this->modelFactory !== null) {
            return ($this->modelFactory)($websiteId);
        }
        /** @var AttributeValue $model */
        $model = ObjectManager::create(AttributeValue::class, [], false);
        return $model->forWebsite($websiteId);
    }
}
