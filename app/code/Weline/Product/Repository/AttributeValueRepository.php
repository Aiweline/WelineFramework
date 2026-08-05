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
        $this->upsert($websiteId, $storeId, $entityType, $entityId, $attributeCode, $locale, [
            AttributeValue::schema_fields_VALUE_TEXT => $value === null ? null : (string)$value,
            AttributeValue::schema_fields_CLEARED => 0,
            AttributeValue::schema_fields_IS_REQUIRED => $isRequired ? 1 : 0,
        ]);
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
            $rows[] = [
                'store_id' => (int)($item[AttributeValue::schema_fields_STORE_ID] ?? 0),
                'entity_type' => (string)($item[AttributeValue::schema_fields_ENTITY_TYPE] ?? ''),
                'entity_id' => (int)($item[AttributeValue::schema_fields_ENTITY_ID] ?? 0),
                'attribute_code' => (string)($item[AttributeValue::schema_fields_ATTRIBUTE_CODE] ?? ''),
                'locale' => (string)($item[AttributeValue::schema_fields_LOCALE] ?? ''),
                'value' => $item[AttributeValue::schema_fields_VALUE_TEXT] ?? null,
                'cleared' => (int)($item[AttributeValue::schema_fields_CLEARED] ?? 0) === 1,
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
            $rows[] = [
                'store_id' => (int)($item[AttributeValue::schema_fields_STORE_ID] ?? 0),
                'locale' => (string)($item[AttributeValue::schema_fields_LOCALE] ?? ''),
                'cleared' => (int)($item[AttributeValue::schema_fields_CLEARED] ?? 0) === 1,
                'value' => $item[AttributeValue::schema_fields_VALUE_TEXT] ?? null,
                'is_required' => (int)($item[AttributeValue::schema_fields_IS_REQUIRED] ?? 0) === 1,
            ];
        }
        return $rows;
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
