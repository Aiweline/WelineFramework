<?php

declare(strict_types=1);

namespace Weline\Eav\Service;

use Weline\Eav\Api\Attribute\AttributeStorageException;
use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Eav\Api\Metadata\AttributeGroupMetadata;
use Weline\Eav\Api\Metadata\AttributeMetadata;
use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Api\Metadata\AttributeMetadataPrefetchInterface;
use Weline\Eav\Api\Metadata\AttributeOptionIdentityCatalogInterface;
use Weline\Eav\Api\Metadata\AttributeOptionMetadata;
use Weline\Eav\Api\Metadata\AttributeSetMetadata;
use Weline\Eav\Api\Metadata\CompareMode;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\LocalDescription as AttributeLocalDescription;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Option\LocalDescription as OptionLocalDescription;
use Weline\Eav\Model\EavAttribute\Placement;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;

/**
 * Eav-owned read model. Consumers receive DTOs and never Eav ORM objects.
 */
final class AttributeMetadataCatalog implements AttributeMetadataCatalogInterface, AttributeMetadataPrefetchInterface, AttributeOptionIdentityCatalogInterface
{
    public function __construct(
        private readonly EavEntity $entityModel,
        private readonly Set $setModel,
        private readonly Group $groupModel,
        private readonly EavAttribute $attributeModel,
        private readonly Type $typeModel,
        private readonly Option $optionModel,
        private readonly Placement $placementModel,
        private readonly ?StorefrontScopeHotCache $requestCache = null,
    ) {
    }

    /** @var array<string, array<int, string>> */
    private array $localFieldValues = [];
    /** @var array<string, array<int, true>> */
    private array $localFieldLoadedIds = [];
    private ?string $localFieldRequestId = null;

    public function catalog(EntityDefinitionInterface $entity): array
    {
        $key = 'shared-catalog|' . json_encode([
            strtolower(trim($entity->getEntityCode())),
            $this->resolveStorefrontLocale(),
        ], JSON_THROW_ON_ERROR);

        // The complete tree contains only readonly DTOs. Reusing it avoids
        // rebuilding models, groups and options for every product in a listing.
        return $this->rememberRequest(
            $key,
            fn(): array => $this->catalogWithOptionScopes($entity, [Option::SCOPE_SHARED]),
        );
    }

    public function sharedOptionIdentities(EntityDefinitionInterface $entity, array $attributeCodes): array
    {
        $codes = [];
        foreach ($attributeCodes as $code) {
            $code = strtolower(trim((string)$code));
            if ($code !== '') {
                $codes[$code] = true;
            }
        }
        if ($codes === []) {
            return [];
        }
        $entityCode = strtolower(trim($entity->getEntityCode()));
        // 选项与 placement 写入尚未发布共享版本，因此这里只挂请求内投影。
        // 身份使用与语言无关的原始值，展示翻译仍由完整目录负责。
        $contextKey = 'eav.metadata.shared-option-identities|' . $entityCode;
        $index = (array)RequestContext::get($contextKey, []);
        $missing = array_diff_key($codes, $index);
        if ($missing !== []) {
            $sets = $this->rememberRequest(
                'shared-identity-attributes|' . $entityCode,
                fn(): array => $this->catalogWithOptionScopes($entity, [Option::SCOPE_SHARED], true),
            );
            $attributeIds = [];
            $orderedAttributes = [];
            foreach ($sets as $set) {
                foreach ($set->groups as $group) {
                    foreach ($group->attributes as $attribute) {
                        $code = strtolower(trim($attribute->code));
                        if (isset($missing[$code])) {
                            $attributeIds[$attribute->id] = true;
                            $orderedAttributes[] = [$code, $attribute->id];
                        }
                    }
                }
            }
            $options = $this->loadOptionsByAttribute(
                $this->entityId($entityCode),
                [Option::SCOPE_SHARED],
                '',
                array_keys($attributeIds),
            );
            $loaded = array_fill_keys(array_keys($missing), []);
            foreach ($orderedAttributes as [$code, $attributeId]) {
                $loaded[$code] = array_merge($loaded[$code], $options[$attributeId] ?? []);
            }
            $index += $loaded;
            if (Context::hasCurrent()) {
                // 全部读取成功后才回填，包含已确认不存在的轴；异常不记为缺失。
                RequestContext::set($contextKey, $index);
            }
        }

        $result = [];
        foreach ($codes as $code => $_) {
            $result[$code] = $index[$code];
        }
        return $result;
    }

    public function catalogForProduct(
        EntityDefinitionInterface $entity,
        int $productId,
        string $freeSetCode = '__product_free',
    ): array {
        if ($productId <= 0) {
            return $this->catalog($entity);
        }

        $entityCode = strtolower(trim($entity->getEntityCode()));
        $locale = $this->resolveStorefrontLocale();
        $key = 'product-catalog|' . json_encode([$entityCode, $locale, $productId, $freeSetCode], JSON_THROW_ON_ERROR);

        return $this->rememberRequest($key, function () use ($entity, $entityCode, $locale, $productId, $freeSetCode): array {
            $shared = $this->catalog($entity);
            $privateOptions = $this->loadOptionsByAttribute(
                $this->entityId($entityCode),
                [$productId],
                $locale,
            );
            $result = $this->withPrivateOptions($shared, $privateOptions);
            $freeSet = $this->buildProductFreeSetMetadata($entity, $productId, $freeSetCode);
            if ($freeSet instanceof AttributeSetMetadata) {
                $result[] = $freeSet;
            }

            return $result;
        });
    }

    /**
     * @param list<AttributeSetMetadata> $sets
     * @param array<int, list<AttributeOptionMetadata>> $privateOptions
     * @return list<AttributeSetMetadata>
     */
    private function withPrivateOptions(array $sets, array $privateOptions): array
    {
        if ($privateOptions === []) {
            return $sets;
        }

        foreach ($sets as $setIndex => $set) {
            $groups = $set->groups;
            $setChanged = false;
            foreach ($groups as $groupIndex => $group) {
                $attributes = $group->attributes;
                $groupChanged = false;
                foreach ($attributes as $attributeIndex => $attribute) {
                    if (!isset($privateOptions[$attribute->id])) {
                        continue;
                    }
                    $options = array_merge($attribute->options, $privateOptions[$attribute->id]);
                    usort(
                        $options,
                        static fn(AttributeOptionMetadata $left, AttributeOptionMetadata $right): int =>
                            [$left->sortOrder, $left->code] <=> [$right->sortOrder, $right->code],
                    );
                    $attributes[$attributeIndex] = new AttributeMetadata(
                        id: $attribute->id,
                        entityId: $attribute->entityId,
                        code: $attribute->code,
                        name: $attribute->name,
                        typeCode: $attribute->typeCode,
                        fieldType: $attribute->fieldType,
                        element: $attribute->element,
                        setId: $attribute->setId,
                        groupId: $attribute->groupId,
                        required: $attribute->required,
                        multiple: $attribute->multiple,
                        enabled: $attribute->enabled,
                        hasOption: $attribute->hasOption,
                        sortOrder: $attribute->sortOrder,
                        options: $options,
                        compareMode: $attribute->compareMode,
                    );
                    $groupChanged = true;
                }
                if ($groupChanged) {
                    $groups[$groupIndex] = new AttributeGroupMetadata(
                        id: $group->id,
                        entityId: $group->entityId,
                        setId: $group->setId,
                        code: $group->code,
                        name: $group->name,
                        sortOrder: $group->sortOrder,
                        attributes: $attributes,
                    );
                    $setChanged = true;
                }
            }
            if ($setChanged) {
                $sets[$setIndex] = new AttributeSetMetadata(
                    id: $set->id,
                    entityId: $set->entityId,
                    code: $set->code,
                    name: $set->name,
                    sortOrder: $set->sortOrder,
                    groups: $groups,
                );
            }
        }

        return $sets;
    }

    /**
     * @param list<int> $scopeInstanceIds
     * @return list<AttributeSetMetadata>
     */
    private function catalogWithOptionScopes(
        EntityDefinitionInterface $entity,
        array $scopeInstanceIds,
        bool $identityOnly = false,
    ): array {
        $entityCode = strtolower(trim($entity->getEntityCode()));
        if ($entityCode === '') {
            throw new \InvalidArgumentException('eav_entity_code_invalid');
        }

        $entityId = $this->entityId($entityCode);
        if ($entityId <= 0) {
            throw new AttributeStorageException(
                AttributeStorageException::ENTITY_NOT_REGISTERED,
                $entityCode,
            );
        }

        $types = [];
        foreach ($this->items($this->typeModel) as $type) {
            if ($type instanceof Type) {
                $types[(int)$type->getId()] = $type;
            }
        }

        $locale = $identityOnly ? '' : $this->resolveStorefrontLocale();
        $optionsByAttribute = $identityOnly ? [] : $this->loadOptionsByAttribute(
            $entityId,
            $scopeInstanceIds,
            $locale,
        );

        // Materialize only the metadata ids that can reach the DTO tree before
        // reading localized descriptions. Local tables can contain thousands
        // of historical rows; an id IN (...) boundary keeps the storefront
        // query proportional to the current entity instead of the whole site.
        $sharedAttributes = $this->items(
            $this->attributeModel,
            EavAttribute::schema_fields_eav_entity_id,
            $entityId,
        );
        $placements = $this->items(
            $this->placementModel,
            Placement::schema_fields_eav_entity_id,
            $entityId,
        );
        $attributeIds = [];
        $sharedAttributesById = [];
        foreach ($sharedAttributes as $attribute) {
            if ($attribute instanceof EavAttribute
                && $attribute->getAttributeId() > 0
                && $this->isSharedScopeRow($attribute)
            ) {
                $attributeIds[] = $attribute->getAttributeId();
                $sharedAttributesById[$attribute->getAttributeId()] = $attribute;
            }
        }
        foreach ($placements as $placement) {
            if ($placement instanceof Placement) {
                $attributeId = (int)$placement->getData(Placement::schema_fields_attribute_id);
                if ($attributeId > 0) {
                    $attributeIds[] = $attributeId;
                }
            }
        }
        $this->attributeLocalNames = $this->localFieldById(
            AttributeLocalDescription::class,
            AttributeLocalDescription::schema_fields_name,
            $locale,
            $attributeIds,
        );

        /** @var array<int, array<string, mixed>> $sets */
        $sets = [];
        foreach ($this->items($this->setModel, Set::schema_fields_eav_entity_id, $entityId) as $set) {
            if (!$set instanceof Set) {
                continue;
            }
            if ($this->isProductFreeSetCode((string)$set->getCode())) {
                continue;
            }
            $setId = (int)$set->getId();
            $sets[$setId] = [
                'id' => $setId,
                'entity_id' => $entityId,
                'code' => $this->label((string)$set->getCode(), 'set_' . $setId),
                'name' => $this->label((string)$set->getName(), (string)$set->getCode()),
                'sort_order' => $setId,
                'groups' => [],
            ];
        }

        foreach ($this->items($this->groupModel, Group::schema_fields_eav_entity_id, $entityId) as $group) {
            if (!$group instanceof Group) {
                continue;
            }
            if (!$this->isSharedScopeRow($group)) {
                continue;
            }
            $setId = (int)$group->getSetId();
            $this->ensureSet($sets, $setId, $entityId);
            $groupId = (int)$group->getId();
            $sets[$setId]['groups'][$groupId] = [
                'id' => $groupId,
                'entity_id' => $entityId,
                'set_id' => $setId,
                'code' => $this->label((string)$group->getCode(), 'group_' . $groupId),
                'name' => $this->label((string)$group->getName(), (string)$group->getCode()),
                'sort_order' => $groupId,
                'attributes' => [],
            ];
        }

        foreach ($sharedAttributes as $attribute) {
            if (!$attribute instanceof EavAttribute || $attribute->getAttributeId() <= 0) {
                continue;
            }
            if (!$this->isSharedScopeRow($attribute)) {
                continue;
            }
            $setId = $attribute->getSetId();
            $groupId = $attribute->getGroupId();
            $this->ensureSet($sets, $setId, $entityId);
            $this->ensureGroup($sets[$setId]['groups'], $groupId, $setId, $entityId);

            $attributeId = $attribute->getAttributeId();
            $sets[$setId]['groups'][$groupId]['attributes'][] = $this->buildAttributeMetadata(
                $attribute,
                $entityId,
                $setId,
                $groupId,
                $types,
                $optionsByAttribute,
            );
        }

        /** @var array<int, array<int, array<int, true>>> $groupAttributeIds */
        $groupAttributeIds = [];
        foreach ($sets as $indexedSetId => $set) {
            foreach ($set['groups'] as $indexedGroupId => $group) {
                $groupAttributeIds[$indexedSetId][$indexedGroupId] = [];
                foreach ($group['attributes'] as $metadata) {
                    if ($metadata instanceof AttributeMetadata) {
                        $groupAttributeIds[$indexedSetId][$indexedGroupId][$metadata->id] = true;
                    }
                }
            }
        }

        foreach ($placements as $placement) {
            if (!$placement instanceof Placement) {
                continue;
            }
            $setId = (int)$placement->getData(Placement::schema_fields_set_id);
            $groupId = (int)$placement->getData(Placement::schema_fields_group_id);
            $attributeId = (int)$placement->getData(Placement::schema_fields_attribute_id);
            if ($setId <= 0 || $groupId <= 0 || $attributeId <= 0) {
                continue;
            }
            if ($groupAttributeIds[$setId][$groupId][$attributeId] ?? false) {
                continue;
            }

            $attribute = $identityOnly ? ($sharedAttributesById[$attributeId] ?? null) : null;
            if (!$attribute instanceof EavAttribute) {
                $attribute = clone $this->attributeModel;
                $attribute->clearData();
                $attribute->loadByAttributeId($attributeId);
            }
            if ($attribute->getAttributeId() <= 0 || !$this->isSharedScopeRow($attribute)) {
                continue;
            }

            $this->ensureSet($sets, $setId, $entityId);
            $this->ensureGroup($sets[$setId]['groups'], $groupId, $setId, $entityId);
            $sets[$setId]['groups'][$groupId]['attributes'][] = $this->buildAttributeMetadata(
                $attribute,
                $entityId,
                $setId,
                $groupId,
                $types,
                $optionsByAttribute,
            );
            $groupAttributeIds[$setId][$groupId][$attributeId] = true;
        }

        $result = [];
        foreach ($sets as $set) {
            $groups = [];
            foreach ($set['groups'] as $group) {
                usort(
                    $group['attributes'],
                    static fn(AttributeMetadata $left, AttributeMetadata $right): int =>
                        [$left->sortOrder, $left->code] <=> [$right->sortOrder, $right->code],
                );
                $groups[] = new AttributeGroupMetadata(
                    id: $group['id'],
                    entityId: $group['entity_id'],
                    setId: $group['set_id'],
                    code: $group['code'],
                    name: $group['name'],
                    sortOrder: $group['sort_order'],
                    attributes: $group['attributes'],
                );
            }
            usort(
                $groups,
                static fn(AttributeGroupMetadata $left, AttributeGroupMetadata $right): int =>
                    [$left->sortOrder, $left->code] <=> [$right->sortOrder, $right->code],
            );
            $result[] = new AttributeSetMetadata(
                id: $set['id'],
                entityId: $set['entity_id'],
                code: $set['code'],
                name: $set['name'],
                sortOrder: $set['sort_order'],
                groups: $groups,
            );
        }
        usort(
            $result,
            static fn(AttributeSetMetadata $left, AttributeSetMetadata $right): int =>
                [$left->sortOrder, $left->code] <=> [$right->sortOrder, $right->code],
        );

        return $result;
    }

    /**
     * @param list<int> $scopeInstanceIds
     * @param string $locale
     * @return array<int, list<AttributeOptionMetadata>>
     */
    private function loadOptionsByAttribute(
        int $entityId,
        array $scopeInstanceIds,
        string $locale,
        ?array $attributeIds = null,
    ): array {
        if ($attributeIds !== null) {
            $attributeIds = array_values(array_unique(array_filter(array_map('intval', $attributeIds), static fn(int $id): bool => $id > 0)));
            if ($attributeIds === []) {
                return [];
            }
            sort($attributeIds, SORT_NUMERIC);
        }
        $allowed = [];
        foreach ($scopeInstanceIds as $scopeId) {
            $allowed[max(0, (int)$scopeId)] = true;
        }
        if ($allowed === []) {
            $allowed[Option::SCOPE_SHARED] = true;
        }

        // Shared options are read once per request. Each Product adds only its
        // own rows; unrelated private options never cross the SQL boundary.
        $scopedOptions = [];
        foreach (array_keys($allowed) as $scopeId) {
            $filters = [Option::schema_fields_scope_instance_id => [(int)$scopeId]];
            if ($attributeIds !== null) {
                $filters[Option::schema_fields_attribute_id] = $attributeIds;
            }
            $scopedOptions = array_merge($scopedOptions, $this->items(
                $this->optionModel,
                Option::schema_fields_eav_entity_id,
                $entityId,
                $filters,
            ));
        }

        $optionIds = [];
        foreach ($scopedOptions as $option) {
            if ($option instanceof Option && $option->getOptionId() > 0) {
                $optionIds[] = $option->getOptionId();
            }
        }
        $optionLocals = $optionIds === []
            ? []
            : $this->localFieldById(
                OptionLocalDescription::class,
                OptionLocalDescription::schema_fields_value,
                $locale,
                $optionIds,
            );

        $optionsByAttribute = [];
        foreach ($scopedOptions as $option) {
            if (!$option instanceof Option) {
                continue;
            }
            $scopeInstanceId = $option->getScopeInstanceId();
            if (!isset($allowed[$scopeInstanceId])) {
                continue;
            }
            $attributeId = $option->getAttributeId();
            $optionId = $option->getOptionId();
            $code = trim($option->getCode());
            $sourceLabel = trim($option->getValue());
            $localized = trim((string)($optionLocals[$optionId] ?? ''));
            // Percent-encoded locals are corrupt AI/import payloads — never surface as EN labels.
            if ($localized !== '' && preg_match('/%[0-9A-Fa-f]{2}/', $localized) === 1) {
                $localized = '';
            }
            $label = $localized !== '' ? $localized : $sourceLabel;
            $optionsByAttribute[$attributeId][] = new AttributeOptionMetadata(
                id: $optionId,
                // Keep Chinese/source text as value so storefront resolvers can
                // match product EAV rows that store option labels, not only codes/ids.
                value: $sourceLabel !== '' ? $sourceLabel : (string)$optionId,
                code: $code !== '' ? $code : (string)$optionId,
                label: $label !== '' ? $label : ($code !== '' ? $code : (string)$optionId),
                sortOrder: $optionId,
                swatchImage: $option->getSwatchImage(),
                swatchColor: $option->getSwatchColor(),
                swatchText: $option->getSwatchText(),
            );
        }
        foreach ($optionsByAttribute as &$options) {
            usort(
                $options,
                static fn(AttributeOptionMetadata $left, AttributeOptionMetadata $right): int =>
                    [$left->sortOrder, $left->code] <=> [$right->sortOrder, $right->code],
            );
        }
        unset($options);

        return $optionsByAttribute;
    }

    public function attributeIndexByEntityCode(string $entityCode): array
    {
        $entityCode = strtolower(trim($entityCode));
        if ($entityCode === '') {
            return [];
        }

        $entityId = $this->entityId($entityCode);
        if ($entityId <= 0) {
            return [];
        }

        $types = [];
        foreach ($this->items($this->typeModel) as $type) {
            if ($type instanceof Type) {
                $types[(int)$type->getId()] = $type;
            }
        }

        $index = [];
        foreach ($this->items($this->attributeModel, EavAttribute::schema_fields_eav_entity_id, $entityId) as $attribute) {
            if (!$attribute instanceof EavAttribute || $attribute->getAttributeId() <= 0) {
                continue;
            }
            $code = strtolower(trim($attribute->getCode()));
            if ($code === '') {
                continue;
            }
            $typeModel = $types[$attribute->getTypeId()] ?? null;
            $typeCode = $typeModel instanceof Type ? trim($typeModel->getCode()) : 'string';
            $fieldType = $typeModel instanceof Type ? trim($typeModel->getFieldType()) : 'string';
            $element = $typeModel instanceof Type ? trim($typeModel->getElement()) : 'input';
            $index[$code] = new AttributeMetadata(
                id: $attribute->getAttributeId(),
                entityId: $entityId,
                code: $attribute->getCode(),
                name: $this->label($attribute->getName(), $attribute->getCode()),
                typeCode: $typeCode !== '' ? $typeCode : 'string',
                fieldType: $fieldType !== '' ? $fieldType : 'string',
                element: $element !== '' ? $element : 'input',
                setId: $attribute->getSetId(),
                groupId: $attribute->getGroupId(),
                required: $typeModel instanceof Type && $typeModel->getRequired(),
                multiple: $attribute->getMultipleValued(),
                enabled: (bool)$attribute->isEnable(),
                hasOption: (bool)$attribute->hasOption(),
                sortOrder: $attribute->getAttributeId(),
                compareMode: CompareMode::normalize((string)$attribute->getData(EavAttribute::schema_fields_compare_mode)),
            );
        }

        return $index;
    }

    /** @var array<int, string> */
    private array $attributeLocalNames = [];

    private function resolveStorefrontLocale(): string
    {
        $locale = trim(str_replace('-', '_', (string)State::getLangLocal()));

        return $locale !== '' ? $locale : 'zh_Hans_CN';
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function localFieldById(string $modelClass, string $field, string $locale, array $ids = []): array
    {
        $requestId = (string)(RequestContext::getRequestId() ?? 'no-request');
        if ($this->localFieldRequestId !== $requestId) {
            $this->localFieldRequestId = $requestId;
            $this->localFieldValues = [];
            $this->localFieldLoadedIds = [];
        }

        $locale = trim($locale);
        if ($locale === '' || $field === '') {
            return [];
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0,
        )));
        sort($ids, SORT_NUMERIC);
        $baseKey = $modelClass . '|' . $field . '|' . $locale;
        if ($ids === []) {
            $values = $this->rememberRequest(
                'local-field|' . $baseKey . '|*',
                fn(): array => $this->loadLocalFieldById($modelClass, $field, $locale),
            );
            foreach ($values as $id => $value) {
                $this->localFieldValues[$baseKey][(int)$id] = (string)$value;
            }
            return $values;
        }

        $loadedIds = $this->localFieldLoadedIds[$baseKey] ?? [];
        $missingIds = array_values(array_diff($ids, array_keys($loadedIds)));
        if ($missingIds !== []) {
            $idKey = implode(',', $missingIds);
            $values = $this->rememberRequest(
                'local-field|' . $baseKey . '|' . $idKey,
                fn(): array => $this->loadLocalFieldById($modelClass, $field, $locale, $missingIds),
            );
            foreach ($values as $id => $value) {
                $this->localFieldValues[$baseKey][(int)$id] = (string)$value;
            }
            foreach ($missingIds as $id) {
                $this->localFieldLoadedIds[$baseKey][$id] = true;
            }
        }

        $out = [];
        foreach ($ids as $id) {
            if (isset($this->localFieldValues[$baseKey][$id])) {
                $out[$id] = $this->localFieldValues[$baseKey][$id];
            }
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function loadLocalFieldById(string $modelClass, string $field, string $locale, array $ids = []): array
    {
        try {
            /** @var \Weline\I18n\Api\Localization\LocalModel $model */
            $model = ObjectManager::getInstance($modelClass);
            $query = $model->reset()
                ->where($model::schema_fields_local_code, $locale);
            if ($ids !== []) {
                $query->where($model::schema_fields_ID, $ids, 'IN');
            }
            $rows = $query->select()->fetchIterator();
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row[$model::schema_fields_ID] ?? 0);
            $value = trim((string)($row[$field] ?? ''));
            if ($id > 0 && $value !== '') {
                $out[$id] = $value;
            }
        }

        return $out;
    }

    public function prefetchForProducts(
        EntityDefinitionInterface $entity,
        array $productIds,
        string $freeSetCode = '__product_free',
    ): void {
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($productIds === [] || !Context::hasCurrent()) {
            return;
        }
        sort($productIds, SORT_NUMERIC);
        $entityId = $this->entityId(strtolower(trim($entity->getEntityCode())));
        $optionLocalIds = $this->rememberRequest(
            'prefetch-product-rows|' . json_encode([$entityId, $productIds, $freeSetCode], JSON_THROW_ON_ERROR),
            function () use ($entityId, $productIds, $freeSetCode): array {
                $freeSetId = $this->rememberRequest(
                    'free-set-id|' . json_encode([$entityId, $freeSetCode], JSON_THROW_ON_ERROR),
                    function () use ($entityId, $freeSetCode): int {
                        foreach ($this->items($this->setModel, Set::schema_fields_eav_entity_id, $entityId) as $set) {
                            if ($set instanceof Set && (string)$set->getCode() === $freeSetCode) {
                                return (int)$set->getId();
                            }
                        }
                        return 0;
                    },
                );
                $optionLocalIds = [];
                foreach ($this->items(
                    $this->optionModel,
                    Option::schema_fields_eav_entity_id,
                    $entityId,
                    [Option::schema_fields_scope_instance_id => [Option::SCOPE_SHARED]],
                ) as $option) {
                    if ($option instanceof Option && $option->getOptionId() > 0) {
                        $optionLocalIds[] = $option->getOptionId();
                    }
                }
                foreach (array_chunk($productIds, 200) as $batch) {
                    $optionRowsByProduct = $this->prefetchRowsByProduct(
                        $this->optionModel,
                        [Option::schema_fields_eav_entity_id => $entityId],
                        Option::schema_fields_scope_instance_id,
                        $batch,
                        true,
                    );
                    foreach ($optionRowsByProduct as $optionRows) {
                        foreach ($optionRows as $optionRow) {
                            $optionId = (int)($optionRow[Option::schema_fields_ID] ?? 0);
                            if ($optionId > 0) {
                                $optionLocalIds[] = $optionId;
                            }
                        }
                    }
                    if ($freeSetId <= 0) {
                        continue;
                    }
                    $groups = $this->prefetchRowsByProduct(
                        $this->groupModel,
                        [Group::schema_fields_eav_entity_id => $entityId, 'set_id' => $freeSetId],
                        Group::schema_fields_scope_product_id,
                        $batch,
                    );
                    $withGroups = array_keys(array_filter($groups));
                    if ($withGroups !== []) {
                        $this->prefetchRowsByProduct(
                            $this->attributeModel,
                            [EavAttribute::schema_fields_eav_entity_id => $entityId, 'set_id' => $freeSetId],
                            EavAttribute::schema_fields_scope_product_id,
                            $withGroups,
                        );
                    }
                }
                return array_values(array_unique($optionLocalIds));
            },
        );
        if (is_array($optionLocalIds) && $optionLocalIds !== []) {
            $this->localFieldById(
                OptionLocalDescription::class,
                OptionLocalDescription::schema_fields_value,
                $this->resolveStorefrontLocale(),
                $optionLocalIds,
            );
        }
    }

    /**
     * @param array<string, mixed> $baseFilters
     * @param list<int> $productIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function prefetchRowsByProduct(
        object $prototype,
        array $baseFilters,
        string $scopeField,
        array $productIds,
        bool $scopeAsArray = false,
    ): array {
        $rowsByProduct = array_fill_keys($productIds, []);
        $query = clone $prototype;
        $query->reset()->clearData();
        foreach ($baseFilters as $field => $value) {
            $query->where($field, $value, is_array($value) ? 'in' : '=');
        }
        $query->where($scopeField, $productIds, 'in');
        foreach ($query->select()->fetchIterator() as $row) {
            $productId = (int)($row[$scopeField] ?? 0);
            if (array_key_exists($productId, $rowsByProduct)) {
                $rowsByProduct[$productId][] = $row;
            }
        }
        foreach ($rowsByProduct as $productId => $rows) {
            $filters = array_merge($baseFilters, [
                $scopeField => $scopeAsArray ? [(int)$productId] : (int)$productId,
            ]);
            // Seed exactly the scalar-row key read by items(), including misses.
            $rowsByProduct[$productId] = $this->rememberRequest(
                $this->rowsCacheKey($prototype, $filters),
                static fn(): array => $rows,
            );
        }
        return $rowsByProduct;
    }

    private function rowsCacheKey(object $prototype, array $filters): string
    {
        return 'rows|' . $prototype::class . '|' . json_encode($filters, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<object>
     */
    private function items(
        object $prototype,
        ?string $field = null,
        mixed $value = null,
        array $additionalFilters = [],
    ): array {
        $filters = $field === null ? [] : [$field => $value];
        $filters = array_merge($filters, $additionalFilters);
        $cacheKey = $this->rowsCacheKey($prototype, $filters);
        $rows = $this->rememberRequest($cacheKey, static function () use ($prototype, $filters): array {
            $query = clone $prototype;
            $query->reset()->clearData();
            foreach ($filters as $filterField => $filterValue) {
                $query->where($filterField, $filterValue, is_array($filterValue) ? 'in' : '=');
            }

            $rows = [];
            // 直接读取标量行，避免先构造 ORM 再拆成数组、随后又克隆一轮。
            foreach ($query->select()->fetchIterator() as $row) {
                $rows[] = $row;
            }
            return $rows;
        });

        // Context stores scalar rows, never mutable ORM instances. A consumer
        // receives its own model objects and cannot corrupt a later catalog.
        $items = [];
        foreach ($rows as $row) {
            $item = clone $prototype;
            $item->reset()->clearData()->setData($row);
            $items[] = $item;
        }
        return $items;
    }

    private function entityId(string $entityCode): int
    {
        return (int)$this->rememberRequest('entity-id|' . $entityCode, function () use ($entityCode): int {
            $entityRow = clone $this->entityModel;
            $entityRow->clearData()->load(EavEntity::schema_fields_code, $entityCode);
            return (int)$entityRow->getId();
        });
    }

    private function rememberRequest(string $logicalKey, callable $builder): mixed
    {
        $cache = $this->requestCache ?? ObjectManager::getInstance(StorefrontScopeHotCache::class);
        return $cache->rememberForRequest('eav.metadata', $logicalKey, $builder);
    }

    /**
     * @param array<int, array<string, mixed>> $sets
     */
    private function ensureSet(array &$sets, int $setId, int $entityId): void
    {
        if (isset($sets[$setId])) {
            return;
        }
        $sets[$setId] = [
            'id' => $setId,
            'entity_id' => $entityId,
            'code' => $setId > 0 ? 'legacy_set_' . $setId : 'ungrouped',
            'name' => $setId > 0 ? '历史属性集 #' . $setId : '未分组属性',
            'sort_order' => $setId,
            'groups' => [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     */
    private function ensureGroup(array &$groups, int $groupId, int $setId, int $entityId): void
    {
        if (isset($groups[$groupId])) {
            return;
        }
        $groups[$groupId] = [
            'id' => $groupId,
            'entity_id' => $entityId,
            'set_id' => $setId,
            'code' => $groupId > 0 ? 'legacy_group_' . $groupId : 'general',
            'name' => $groupId > 0 ? '历史属性组 #' . $groupId : '常规',
            'sort_order' => $groupId,
            'attributes' => [],
        ];
    }

    private function label(string $value, string $fallback): string
    {
        $value = trim($value);

        return $value !== '' ? $value : $fallback;
    }

    /**
     * @param array<int, Type> $types
     * @param array<int, list<AttributeOptionMetadata>> $optionsByAttribute
     */
    private function buildAttributeMetadata(
        EavAttribute $attribute,
        int $entityId,
        int $setId,
        int $groupId,
        array $types,
        array $optionsByAttribute,
    ): AttributeMetadata {
        $type = $types[$attribute->getTypeId()] ?? null;
        $typeCode = $type instanceof Type ? trim($type->getCode()) : 'string';
        $fieldType = $type instanceof Type ? trim($type->getFieldType()) : 'string';
        $element = $type instanceof Type ? trim($type->getElement()) : 'input';
        $attributeId = $attribute->getAttributeId();

        return new AttributeMetadata(
            id: $attributeId,
            entityId: $entityId,
            code: $attribute->getCode(),
            name: $this->label(
                trim((string)($this->attributeLocalNames[$attributeId] ?? '')) !== ''
                    ? (string)$this->attributeLocalNames[$attributeId]
                    : $attribute->getName(),
                $attribute->getCode(),
            ),
            typeCode: $typeCode !== '' ? $typeCode : 'string',
            fieldType: $fieldType !== '' ? $fieldType : 'string',
            element: $element !== '' ? $element : 'input',
            setId: $setId,
            groupId: $groupId,
            required: $type instanceof Type && $type->getRequired(),
            multiple: $attribute->getMultipleValued(),
            enabled: (bool)$attribute->isEnable(),
            hasOption: (bool)$attribute->hasOption(),
            sortOrder: $attributeId,
            options: $optionsByAttribute[$attributeId] ?? [],
            compareMode: CompareMode::normalize((string)$attribute->getData(EavAttribute::schema_fields_compare_mode)),
        );
    }

    private function isProductFreeSetCode(string $code): bool
    {
        return strtolower(trim($code)) === '__product_free';
    }

    private function isSharedScopeRow(object $row): bool
    {
        $scopeProductId = (int)$row->getData(Group::schema_fields_scope_product_id);

        return $scopeProductId <= 0;
    }

    private function buildProductFreeSetMetadata(
        EntityDefinitionInterface $entity,
        int $productId,
        string $freeSetCode,
    ): ?AttributeSetMetadata {
        $entityCode = strtolower(trim($entity->getEntityCode()));
        if ($entityCode === '') {
            return null;
        }

        $entityId = $this->entityId($entityCode);
        if ($entityId <= 0) {
            return null;
        }

        $freeSetId = $this->rememberRequest(
            'free-set-id|' . json_encode([$entityId, $freeSetCode], JSON_THROW_ON_ERROR),
            function () use ($entityId, $freeSetCode): int {
                foreach ($this->items($this->setModel, Set::schema_fields_eav_entity_id, $entityId) as $set) {
                    if ($set instanceof Set && (string)$set->getCode() === $freeSetCode) {
                        return (int)$set->getId();
                    }
                }
                return 0;
            },
        );
        if ($freeSetId <= 0) {
            return null;
        }

        $groups = [];
        foreach ($this->items(
            $this->groupModel,
            Group::schema_fields_eav_entity_id,
            $entityId,
            ['set_id' => $freeSetId, Group::schema_fields_scope_product_id => $productId],
        ) as $group) {
            if (!$group instanceof Group) {
                continue;
            }
            if ((int)$group->getSetId() !== $freeSetId) {
                continue;
            }
            if ((int)$group->getData(Group::schema_fields_scope_product_id) !== $productId) {
                continue;
            }
            $groupId = (int)$group->getId();
            $groups[$groupId] = [
                'id' => $groupId,
                'entity_id' => $entityId,
                'set_id' => $freeSetId,
                'code' => $this->label((string)$group->getCode(), 'group_' . $groupId),
                'name' => $this->label((string)$group->getName(), (string)$group->getCode()),
                'sort_order' => $groupId,
                'attributes' => [],
            ];
        }

        if ($groups === []) {
            return new AttributeSetMetadata(
                id: $freeSetId,
                entityId: $entityId,
                code: $freeSetCode,
                name: '自由属性',
                sortOrder: PHP_INT_MAX,
                groups: [],
            );
        }

        $types = [];
        foreach ($this->items($this->typeModel) as $type) {
            if ($type instanceof Type) {
                $types[(int)$type->getId()] = $type;
            }
        }

        foreach ($this->items(
            $this->attributeModel,
            EavAttribute::schema_fields_eav_entity_id,
            $entityId,
            ['set_id' => $freeSetId, EavAttribute::schema_fields_scope_product_id => $productId],
        ) as $attribute) {
            if (!$attribute instanceof EavAttribute || $attribute->getAttributeId() <= 0) {
                continue;
            }
            if ((int)$attribute->getSetId() !== $freeSetId) {
                continue;
            }
            if ((int)$attribute->getData(EavAttribute::schema_fields_scope_product_id) !== $productId) {
                continue;
            }
            $groupId = $attribute->getGroupId();
            if (!isset($groups[$groupId])) {
                continue;
            }
            $type = $types[$attribute->getTypeId()] ?? null;
            $typeCode = $type instanceof Type ? trim($type->getCode()) : 'string';
            $fieldType = $type instanceof Type ? trim($type->getFieldType()) : 'string';
            $element = $type instanceof Type ? trim($type->getElement()) : 'input';
            $attributeId = $attribute->getAttributeId();
            $groups[$groupId]['attributes'][] = new AttributeMetadata(
                id: $attributeId,
                entityId: $entityId,
                code: $attribute->getCode(),
                name: $this->label($attribute->getName(), $attribute->getCode()),
                typeCode: $typeCode !== '' ? $typeCode : 'string',
                fieldType: $fieldType !== '' ? $fieldType : 'string',
                element: $element !== '' ? $element : 'input',
                setId: $freeSetId,
                groupId: $groupId,
                required: $type instanceof Type && $type->getRequired(),
                multiple: $attribute->getMultipleValued(),
                enabled: (bool)$attribute->isEnable(),
                hasOption: (bool)$attribute->hasOption(),
                sortOrder: $attributeId,
                options: [],
                compareMode: CompareMode::normalize((string)$attribute->getData(EavAttribute::schema_fields_compare_mode)),
            );
        }

        $groupMetadata = [];
        foreach ($groups as $group) {
            usort(
                $group['attributes'],
                static fn(AttributeMetadata $left, AttributeMetadata $right): int =>
                    [$left->sortOrder, $left->code] <=> [$right->sortOrder, $right->code],
            );
            $groupMetadata[] = new AttributeGroupMetadata(
                id: $group['id'],
                entityId: $group['entity_id'],
                setId: $group['set_id'],
                code: $group['code'],
                name: $group['name'],
                sortOrder: $group['sort_order'],
                attributes: $group['attributes'],
            );
        }

        usort(
            $groupMetadata,
            static fn(AttributeGroupMetadata $left, AttributeGroupMetadata $right): int =>
                [$left->sortOrder, $left->code] <=> [$right->sortOrder, $right->code],
        );

        return new AttributeSetMetadata(
            id: $freeSetId,
            entityId: $entityId,
            code: $freeSetCode,
            name: '自由属性',
            sortOrder: PHP_INT_MAX,
            groups: $groupMetadata,
        );
    }
}
