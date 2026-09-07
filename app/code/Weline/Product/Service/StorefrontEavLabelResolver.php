<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Eav\Api\Attribute\Option\AttributeOptionRecord;
use Weline\Eav\Api\Attribute\Option\AttributeOptionStoreInterface;
use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Api\Metadata\AttributeSetMetadata;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Model\ProductCatalogAttributeEntity;

/**
 * Resolves Product catalog EAV option identities to storefront labels and public codes.
 *
 * Internal combination keys stay on canonical option IDs. Public URLs prefer option codes.
 * Product-private options use the Eav-owned product catalog, including localized labels.
 * AttributeOptionStore remains a compatibility fallback for options absent from metadata.
 */
class StorefrontEavLabelResolver
{
    /** @var array<string, array<string, string>>|null */
    private ?array $optionLabelsByAttribute = null;

    /** @var array<string, string>|null */
    private ?array $attributeNamesByCode = null;

    /** @var array<string, list<array{id:string,code:string,label:string,aliases:list<string>}>>|null */
    private ?array $optionsByAttribute = null;

    /** @var list<AttributeSetMetadata>|null */
    private ?array $metadataCatalog = null;

    private ?string $cacheLocale = null;

    private int $productId = 0;

    /** @var array<string, array{id:string,code:string,label:string}|null> */
    private array $privateOptionCache = [];

    private ?AttributeOptionStoreInterface $resolvedOptionStore = null;

    public function __construct(
        private readonly AttributeMetadataCatalogInterface $metadata,
        private readonly ProductCatalogAttributeEntity $entity,
        private readonly ?AttributeOptionStoreInterface $optionStore = null,
    ) {
    }

    /** @param list<int> $productIds */
    public function prefetchForProducts(array $productIds): void
    {
        if ($this->metadata instanceof \Weline\Eav\Api\Metadata\AttributeMetadataPrefetchInterface) {
            $this->metadata->prefetchForProducts($this->entity, $productIds);
        }
    }

    /**
     * Bind instance-private option scope (scope_instance_id = product_id).
     */
    public function forProduct(int $productId): self
    {
        $productId = max(0, $productId);
        if ($this->productId === $productId) {
            return $this;
        }

        $scoped = clone $this;
        $scoped->productId = $productId;
        $scoped->clearCaches();

        return $scoped;
    }

    public function resolve(string $attributeCode, string $value): string
    {
        $code = strtolower(trim($attributeCode));
        $value = trim($value);
        if ($code === '' || $value === '') {
            return $value;
        }

        $labels = $this->optionLabelsByAttribute($code);
        $attributeLabels = $labels[$code] ?? [];
        if (array_key_exists($value, $attributeLabels)) {
            return $attributeLabels[$value];
        }
        foreach ($attributeLabels as $optionCode => $label) {
            // PHP casts numeric string keys to int; normalize before strcasecmp.
            $optionCode = (string)$optionCode;
            if (strcasecmp($optionCode, $value) === 0) {
                return $label;
            }
        }

        $private = $this->findPrivateOption($value);
        if ($private !== null && $private['label'] !== '') {
            return $private['label'];
        }

        return $value;
    }

    /**
     * Storefront attribute display name from Product catalog EAV metadata.
     * Empty when metadata has no distinct name (caller may fall back).
     */
    public function attributeLabel(string $attributeCode): string
    {
        $code = strtolower(trim($attributeCode));
        if ($code === '') {
            return '';
        }

        return $this->attributeNamesByCode()[$code] ?? '';
    }

    /**
     * Public URL token for an option identity (id/code/label). Prefers EAV option code.
     */
    public function publicOptionCode(string $attributeCode, string $token): string
    {
        $option = $this->findOption($attributeCode, $token);
        if ($option === null) {
            return trim($token);
        }
        if ($option['code'] !== '') {
            return $option['code'];
        }
        if ($option['id'] !== '') {
            return $option['id'];
        }

        return trim($token);
    }

    /**
     * Canonical storefront matching identity for an option token. Prefers EAV option id.
     */
    public function canonicalOptionId(string $attributeCode, string $token): string
    {
        $option = $this->findOption($attributeCode, $token);
        if ($option === null) {
            return trim($token);
        }
        if ($option['id'] !== '') {
            return $option['id'];
        }
        if ($option['code'] !== '') {
            return $option['code'];
        }

        return trim($token);
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string> $axisCodes
     * @return array<string, mixed>
     */
    public function canonicalizeAxisQuery(array $query, array $axisCodes): array
    {
        foreach ($axisCodes as $axisCode) {
            $axisCode = strtolower(trim((string)$axisCode));
            if ($axisCode === '' || !array_key_exists($axisCode, $query)) {
                continue;
            }
            if (!is_scalar($query[$axisCode])) {
                continue;
            }
            $query[$axisCode] = $this->canonicalOptionId($axisCode, (string)$query[$axisCode]);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function toPublicQuery(array $query): array
    {
        $public = [];
        foreach ($query as $axisCode => $token) {
            $axisCode = strtolower(trim((string)$axisCode));
            if ($axisCode === '' || !is_scalar($token)) {
                continue;
            }
            $public[$axisCode] = $this->publicOptionCode($axisCode, (string)$token);
        }

        return $public;
    }

    /**
     * @return array{id:string,code:string,label:string}|null
     */
    private function findOption(string $attributeCode, string $token): ?array
    {
        $code = strtolower(trim($attributeCode));
        $token = trim($token);
        if ($code === '' || $token === '') {
            return null;
        }

        foreach ($this->optionsByAttribute($code)[$code] ?? [] as $option) {
            foreach ($option['aliases'] as $alias) {
                if ($alias === $token || strcasecmp($alias, $token) === 0) {
                    return [
                        'id' => $option['id'],
                        'code' => $option['code'],
                        'label' => $option['label'],
                    ];
                }
            }
        }

        return $this->findPrivateOption($token);
    }

    /**
     * @return array{id:string,code:string,label:string}|null
     */
    private function findPrivateOption(string $token): ?array
    {
        $token = trim($token);
        if ($this->productId <= 0 || $token === '') {
            return null;
        }

        $cacheKey = $this->productId . ':' . $token;
        if (array_key_exists($cacheKey, $this->privateOptionCache)) {
            return $this->privateOptionCache[$cacheKey];
        }

        try {
            if (ctype_digit($token)) {
                $record = $this->optionStore()->assertUsableByInstance((int)$token, $this->productId);

                return $this->privateOptionCache[$cacheKey] = $this->recordToOption($record);
            }

            $code = strtolower($token);
            foreach ([AttributeOptionStoreInterface::SCOPE_SHARED, $this->productId] as $scope) {
                // Attribute id unknown at this layer; scan product/shared by code via store
                // through a tiny model lookup when shared catalog missed.
                $record = $this->findPrivateRecordByCode($code, (int)$scope);
                if ($record !== null) {
                    return $this->privateOptionCache[$cacheKey] = $this->recordToOption($record);
                }
            }
        } catch (\Throwable) {
            return $this->privateOptionCache[$cacheKey] = null;
        }

        return $this->privateOptionCache[$cacheKey] = null;
    }

    private function findPrivateRecordByCode(string $code, int $scopeInstanceId): ?AttributeOptionRecord
    {
        if ($code === '') {
            return null;
        }
        try {
            $optionId = ObjectManager::getInstance(\Weline\Framework\Cache\Service\StorefrontScopeHotCache::class)
                ->rememberForRequest(
                    'product.eav.option_id_by_scope_code',
                    serialize([$scopeInstanceId, $code]),
                    static function () use ($code, $scopeInstanceId): ?int {
                        /** @var \Weline\Eav\Model\EavAttribute\Option $model */
                        $model = ObjectManager::getInstance(\Weline\Eav\Model\EavAttribute\Option::class);
                        $option = (clone $model)
                            ->clearData()
                            ->clearQuery()
                            ->where(\Weline\Eav\Model\EavAttribute\Option::schema_fields_code, $code)
                            ->where(
                                \Weline\Eav\Model\EavAttribute\Option::schema_fields_scope_instance_id,
                                $scopeInstanceId,
                            )
                            ->find()
                            ->fetch();

                        return $option->getOptionId() ? (int)$option->getOptionId() : null;
                    },
                );
            if ($optionId === null) {
                return null;
            }

            // Only the lookup identity is shared; each product still validates its own scope.
            return $this->optionStore()->assertUsableByInstance($optionId, $this->productId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{id:string,code:string,label:string}
     */
    private function recordToOption(AttributeOptionRecord $record): array
    {
        $label = trim($record->value);
        if ($label === '') {
            $label = trim($record->code);
        }
        if ($label === '') {
            $label = (string)$record->id;
        }

        return [
            'id' => (string)$record->id,
            'code' => trim($record->code),
            'label' => $label,
        ];
    }

    private function optionStore(): AttributeOptionStoreInterface
    {
        return $this->resolvedOptionStore ??= ($this->optionStore
            ?? ObjectManager::getInstance(AttributeOptionStoreInterface::class));
    }

    private function currentLocale(): string
    {
        $locale = trim(str_replace('-', '_', RequestContext::getWelineUserLang()));

        return $locale !== '' ? $locale : 'zh_Hans_CN';
    }

    private function refreshCachesForLocale(): void
    {
        $locale = $this->currentLocale();
        if ($this->cacheLocale === $locale) {
            return;
        }
        $this->cacheLocale = $locale;
        $this->clearCaches();
    }

    private function clearCaches(): void
    {
        $this->optionLabelsByAttribute = null;
        $this->attributeNamesByCode = null;
        $this->optionsByAttribute = null;
        $this->metadataCatalog = null;
        $this->privateOptionCache = [];
    }

    /** @return list<AttributeSetMetadata> */
    private function metadataCatalog(): array
    {
        return $this->metadataCatalog ??= ($this->productId > 0
            ? $this->metadata->catalogForProduct($this->entity, $this->productId)
            : $this->metadata->catalog($this->entity));
    }

    /**
     * @return array<string, string>
     */
    private function attributeNamesByCode(): array
    {
        $this->refreshCachesForLocale();
        if ($this->attributeNamesByCode !== null) {
            return $this->attributeNamesByCode;
        }

        $index = [];
        try {
            foreach ($this->metadataCatalog() as $set) {
                if (!$set instanceof AttributeSetMetadata) {
                    continue;
                }
                foreach ($set->groups as $group) {
                    foreach ($group->attributes as $attribute) {
                        $attributeCode = strtolower(trim($attribute->code));
                        if ($attributeCode === '') {
                            continue;
                        }
                        $name = trim($attribute->name);
                        if ($name === '' || strcasecmp($name, $attributeCode) === 0) {
                            continue;
                        }
                        $index[$attributeCode] = $name;
                    }
                }
            }
        } catch (\Throwable) {
            $index = [];
        }

        return $this->attributeNamesByCode = $index;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function optionLabelsByAttribute(string $attributeCode): array
    {
        $this->refreshCachesForLocale();
        if ($this->optionLabelsByAttribute !== null
            && array_key_exists($attributeCode, $this->optionLabelsByAttribute)
        ) {
            return $this->optionLabelsByAttribute;
        }

        $labels = [];
        foreach ($this->optionsByAttribute($attributeCode)[$attributeCode] ?? [] as $option) {
            foreach ($option['aliases'] as $alias) {
                $labels[$alias] = $option['label'];
            }
        }
        $this->optionLabelsByAttribute[$attributeCode] = $labels;

        return $this->optionLabelsByAttribute;
    }

    /**
     * @return array<string, list<array{id:string,code:string,label:string,aliases:list<string>}>>
     */
    private function optionsByAttribute(string $requestedCode): array
    {
        $this->refreshCachesForLocale();
        if ($this->optionsByAttribute !== null
            && array_key_exists($requestedCode, $this->optionsByAttribute)
        ) {
            return $this->optionsByAttribute;
        }

        $index = [];
        try {
            foreach ($this->metadataCatalog() as $set) {
                if (!$set instanceof AttributeSetMetadata) {
                    continue;
                }
                foreach ($set->groups as $group) {
                    foreach ($group->attributes as $attribute) {
                        $attributeCode = strtolower(trim($attribute->code));
                        if ($attributeCode === '' || $attributeCode !== $requestedCode) {
                            continue;
                        }
                        foreach ($attribute->options as $option) {
                            $id = trim((string)$option->id);
                            $code = trim($option->code);
                            $storedValue = trim($option->value);
                            $aliases = [];
                            foreach ([$id, $code, $storedValue] as $identity) {
                                if ($identity !== '') {
                                    $aliases[(string)$identity] = true;
                                }
                            }
                            if ($aliases === []) {
                                continue;
                            }
                            $label = trim($option->label);
                            if ($label === '') {
                                $label = $storedValue;
                            }
                            if ($label === '') {
                                $label = (string)array_key_first($aliases);
                            }
                            $index[$attributeCode][] = [
                                'id' => $id,
                                'code' => $code,
                                'label' => $label,
                                'aliases' => array_map('strval', array_keys($aliases)),
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable) {
            $index = [];
        }

        $this->optionsByAttribute[$requestedCode] = $index[$requestedCode] ?? [];

        return $this->optionsByAttribute;
    }
}
