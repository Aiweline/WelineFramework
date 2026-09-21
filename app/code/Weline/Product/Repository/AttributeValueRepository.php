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
        private readonly ?\Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface $projectionMutations = null,
    ) {
        parent::__construct($provisioner);
        $this->modelFactory = $modelFactory;
    }

    /** Run one product attribute batch through the existing committed catalog change. */
    public function mutateProductAttributes(
        int $websiteId,
        int $productId,
        int $storeId,
        callable $mutation,
    ): mixed {
        $this->assertWebsite($websiteId);
        $this->assertStoreId($storeId);
        $model = $this->newModel($websiteId);
        $projectionMutations = $this->projectionMutations ?? ObjectManager::getInstance(
            \Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface::class,
        );
        $targetStoreId = $storeId === AttributeValue::WEBSITE_STORE_ID ? null : $storeId;

        return $projectionMutations->execute(
            $model->getConnection(),
            $websiteId,
            $targetStoreId === null
                ? \Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface::TARGET_PRODUCT
                : \Weline\Product\Api\ProductSearchProjectionMutationCoordinatorInterface::TARGET_STORE_PRODUCT,
            $productId,
            $targetStoreId,
            $mutation,
        );
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
     * Find product/offer entity ids that already hold an exact attribute value
     * (Website store overlay by default). Used for URL Handle uniqueness checks.
     *
     * @return list<int>
     */
    public function findEntityIdsByAttributeValue(
        int $websiteId,
        string $entityType,
        string $attributeCode,
        string $value,
        int $storeId = 0,
    ): array {
        $this->assertWebsite($websiteId);
        $this->assertStoreId($storeId);
        $entityType = trim($entityType);
        $attributeCode = trim($attributeCode);
        $value = trim($value);
        if ($entityType === '' || $attributeCode === '' || $value === '') {
            return [];
        }

        $raw = $this->newModel($websiteId)
            ->clear()
            ->where(AttributeValue::schema_fields_ENTITY_TYPE, $entityType)
            ->where(AttributeValue::schema_fields_ATTRIBUTE_CODE, $attributeCode)
            ->where(AttributeValue::schema_fields_STORE_ID, $storeId)
            ->where(AttributeValue::schema_fields_VALUE_TEXT, $value)
            ->select()
            ->fetchArray();

        $ids = [];
        foreach ($raw as $item) {
            $cleared = (string)($item['scope_state'] ?? '') === 'cleared'
                || (int)($item[AttributeValue::schema_fields_CLEARED] ?? 0) === 1;
            if ($cleared) {
                continue;
            }
            $entityId = (int)($item[AttributeValue::schema_fields_ENTITY_ID] ?? 0);
            if ($entityId > 0) {
                $ids[$entityId] = $entityId;
            }
        }

        return array_values($ids);
    }

    /** Hard cap on materialized explicit EAV rows per listExplicitRows call. */
    public const LIST_EXPLICIT_ROWS_HARD_LIMIT = 100000;

    /**
     * Return explicit Website/Store rows without applying fallback.
     *
     * @param list<int> $entityIds
     * @param list<int> $storeIds
     * @param list<string>|null $locales null = no locale filter; non-null always includes ''
     * @param list<string>|null $attributeCodes null = no code filter; empty after normalize → []
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
        ?array $locales = null,
        ?array $attributeCodes = null,
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

        $localeFilter = null;
        if ($locales !== null) {
            $localeFilter = [];
            foreach ($locales as $locale) {
                if (!\is_string($locale) && !\is_int($locale) && !\is_float($locale)) {
                    continue;
                }
                $locale = \trim((string)$locale);
                if (!\in_array($locale, $localeFilter, true)) {
                    $localeFilter[] = $locale;
                }
            }
            if (!\in_array('', $localeFilter, true)) {
                $localeFilter[] = '';
            }
        }

        $codeFilter = null;
        if ($attributeCodes !== null) {
            $codeFilter = [];
            foreach ($attributeCodes as $code) {
                if (!\is_string($code) && !\is_int($code) && !\is_float($code)) {
                    continue;
                }
                $code = \trim((string)$code);
                if ($code === '' || \in_array($code, $codeFilter, true)) {
                    continue;
                }
                $codeFilter[] = $code;
            }
            if ($codeFilter === []) {
                return [];
            }
        }

        $diagBefore = \memory_get_usage(false);
        $query = $this->newModel($websiteId)
            ->clear()
            ->where(AttributeValue::schema_fields_ENTITY_TYPE, $entityType)
            ->where(AttributeValue::schema_fields_ENTITY_ID, $entityIds, 'IN')
            ->where(AttributeValue::schema_fields_STORE_ID, $storeIds, 'IN');
        if ($localeFilter !== null) {
            $query->where(AttributeValue::schema_fields_LOCALE, $localeFilter, 'IN');
        }
        if ($codeFilter !== null) {
            $query->where(AttributeValue::schema_fields_ATTRIBUTE_CODE, $codeFilter, 'IN');
        }
        $raw = $query
            ->order(AttributeValue::schema_fields_ENTITY_ID, 'ASC')
            ->order(AttributeValue::schema_fields_STORE_ID, 'ASC')
            ->order(AttributeValue::schema_fields_ATTRIBUTE_CODE, 'ASC')
            ->order(AttributeValue::schema_fields_LOCALE, 'ASC')
            ->select()
            ->fetchIterator();
        $rows = [];
        $rawCount = 0;
        $distinctLocales = [];
        foreach ($raw as $item) {
            ++$rawCount;
            if ($rawCount > self::LIST_EXPLICIT_ROWS_HARD_LIMIT) {
                $requestUri = self::resolveRequestUri();
                if (\class_exists(\Weline\Framework\Runtime\MemDiag::class)) {
                    \Weline\Framework\Runtime\MemDiag::event('eav_list_explicit_rows', [
                        'website_id' => $websiteId,
                        'entity_type' => $entityType,
                        'entity_ids' => \count($entityIds),
                        'store_ids' => $storeIds,
                        'locales' => $localeFilter,
                        'attribute_codes_n' => $codeFilter === null ? null : \count($codeFilter),
                        'distinct_locale' => \count($distinctLocales),
                        'request_uri' => $requestUri,
                        'raw_rows' => $rawCount,
                        'out_rows' => \count($rows),
                        'hard_limit' => self::LIST_EXPLICIT_ROWS_HARD_LIMIT,
                        'delta_before_materialize' => \memory_get_usage(false) - $diagBefore,
                        'delta_after_materialize' => \memory_get_usage(false) - $diagBefore,
                        'delta_before_sort' => \memory_get_usage(false) - $diagBefore,
                        'delta_after_sort' => \memory_get_usage(false) - $diagBefore,
                    ]);
                }
                throw new \Weline\Framework\App\Exception(
                    'listExplicitRows materialize hard limit exceeded: ' . self::LIST_EXPLICIT_ROWS_HARD_LIMIT,
                );
            }
            $cleared = (string)($item['scope_state'] ?? '') === 'cleared'
                || (int)($item[AttributeValue::schema_fields_CLEARED] ?? 0) === 1;
            $locale = (string)($item[AttributeValue::schema_fields_LOCALE] ?? '');
            $distinctLocales[$locale] = true;
            $rows[] = [
                'store_id' => (int)($item[AttributeValue::schema_fields_STORE_ID] ?? 0),
                'entity_type' => (string)($item[AttributeValue::schema_fields_ENTITY_TYPE] ?? ''),
                'entity_id' => (int)($item[AttributeValue::schema_fields_ENTITY_ID] ?? 0),
                'attribute_code' => (string)($item[AttributeValue::schema_fields_ATTRIBUTE_CODE] ?? ''),
                'locale' => $locale,
                'value_type' => (string)($item['value_type'] ?? 'string'),
                'value' => $cleared ? null : $this->decodeTypedValue($item),
                'scope_state' => $cleared ? 'cleared' : (string)($item['scope_state'] ?? 'explicit'),
                'cleared' => $cleared,
                'is_required' => (int)($item[AttributeValue::schema_fields_IS_REQUIRED] ?? 0) === 1,
            ];
        }
        $afterMaterialize = \memory_get_usage(false);
        $deltaMaterialize = $afterMaterialize - $diagBefore;
        if (\class_exists(\Weline\Framework\Runtime\MemDiag::class)) {
            \Weline\Framework\Runtime\MemDiag::event('eav_list_explicit_rows', [
                'website_id' => $websiteId,
                'entity_type' => $entityType,
                'entity_ids' => \count($entityIds),
                'store_ids' => $storeIds,
                'locales' => $localeFilter,
                'attribute_codes_n' => $codeFilter === null ? null : \count($codeFilter),
                'distinct_locale' => \count($distinctLocales),
                'request_uri' => self::resolveRequestUri(),
                'raw_rows' => $rawCount,
                'out_rows' => \count($rows),
                'delta_before_materialize' => $deltaMaterialize,
                'delta_after_materialize' => $deltaMaterialize,
                // Legacy aliases: no PHP usort; both mean post-materialize usage delta.
                'delta_before_sort' => $deltaMaterialize,
                'delta_after_sort' => $deltaMaterialize,
            ]);
        }
        return $rows;
    }

    private static function resolveRequestUri(): string
    {
        if (\function_exists('w_env_request_uri')) {
            try {
                $uri = (string)\w_env_request_uri();
                if ($uri !== '') {
                    return $uri;
                }
            } catch (\Throwable) {
                // Fall through to $_SERVER.
            }
        }

        return (string)($_SERVER['REQUEST_URI'] ?? '');
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
            // Legacy rows may keep value_type=string while value_text holds JSON
            // (typed columns were not in AttributeValue schema_fields and were dropped on save).
            default => $this->decodeLegacyStringValue($row),
        };
    }

    /** @param array<string, mixed> $row */
    private function decodeLegacyStringValue(array $row): mixed
    {
        $string = $row['value_string'] ?? null;
        $text = $row[AttributeValue::schema_fields_VALUE_TEXT] ?? null;
        $raw = (is_string($string) && $string !== '') ? $string : $text;
        if (!is_string($raw) || $raw === '') {
            return $raw;
        }
        $trim = ltrim($raw);
        if ($trim === '' || ($trim[0] !== '{' && $trim[0] !== '[' && $trim[0] !== '"')) {
            return $raw;
        }
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $raw;
        }
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
