<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Api\Metadata\AttributeSetMetadata;
use Weline\Product\Model\ProductCatalogAttributeEntity;

/**
 * Resolves Product catalog EAV option identities to storefront labels and public codes.
 *
 * Internal combination keys stay on canonical option IDs. Public URLs prefer option codes.
 */
class StorefrontEavLabelResolver
{
    /** @var array<string, array<string, string>>|null */
    private ?array $optionLabelsByAttribute = null;

    /** @var array<string, list<array{id:string,code:string,label:string,aliases:list<string>}>>|null */
    private ?array $optionsByAttribute = null;

    public function __construct(
        private readonly AttributeMetadataCatalogInterface $metadata,
        private readonly ProductCatalogAttributeEntity $entity,
    ) {
    }

    public function resolve(string $attributeCode, string $value): string
    {
        $code = strtolower(trim($attributeCode));
        $value = trim($value);
        if ($code === '' || $value === '') {
            return $value;
        }

        $labels = $this->optionLabelsByAttribute();
        foreach ($labels[$code] ?? [] as $optionCode => $label) {
            // PHP casts numeric string keys to int; normalize before strcasecmp.
            $optionCode = (string)$optionCode;
            if ($optionCode === $value || strcasecmp($optionCode, $value) === 0) {
                return $label;
            }
        }

        return $value;
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
            $token = trim((string)$query[$axisCode]);
            if ($token === '') {
                continue;
            }
            $query[$axisCode] = $this->canonicalOptionId($axisCode, $token);
        }

        return $query;
    }

    /**
     * @param array<string, string> $selection
     * @return array<string, string>
     */
    public function toPublicQuery(array $selection): array
    {
        $public = [];
        foreach ($selection as $axis => $value) {
            $axis = strtolower(trim((string)$axis));
            $value = trim((string)$value);
            if ($axis === '' || $value === '') {
                continue;
            }
            $public[$axis] = $this->publicOptionCode($axis, $value);
        }
        ksort($public, SORT_STRING);

        return $public;
    }

    /**
     * @return array{id:string,code:string,label:string,aliases:list<string>}|null
     */
    private function findOption(string $attributeCode, string $token): ?array
    {
        $attributeCode = strtolower(trim($attributeCode));
        $token = trim($token);
        if ($attributeCode === '' || $token === '') {
            return null;
        }

        foreach ($this->optionsByAttribute()[$attributeCode] ?? [] as $option) {
            foreach ($option['aliases'] as $alias) {
                $alias = (string)$alias;
                if ($alias === $token || strcasecmp($alias, $token) === 0) {
                    return $option;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function optionLabelsByAttribute(): array
    {
        if ($this->optionLabelsByAttribute !== null) {
            return $this->optionLabelsByAttribute;
        }

        $index = [];
        foreach ($this->optionsByAttribute() as $attributeCode => $options) {
            foreach ($options as $option) {
                foreach ($option['aliases'] as $alias) {
                    $index[$attributeCode][$alias] = $option['label'];
                }
            }
        }

        return $this->optionLabelsByAttribute = $index;
    }

    /**
     * @return array<string, list<array{id:string,code:string,label:string,aliases:list<string>}>>
     */
    private function optionsByAttribute(): array
    {
        if ($this->optionsByAttribute !== null) {
            return $this->optionsByAttribute;
        }

        $index = [];
        try {
            foreach ($this->metadata->catalog($this->entity) as $set) {
                if (!$set instanceof AttributeSetMetadata) {
                    continue;
                }
                foreach ($set->toArray()['groups'] as $group) {
                    foreach ($group['attributes'] as $attribute) {
                        $attributeCode = strtolower(trim((string)($attribute['code'] ?? '')));
                        if ($attributeCode === '') {
                            continue;
                        }
                        foreach ($attribute['options'] ?? [] as $option) {
                            if (!is_array($option)) {
                                continue;
                            }
                            $id = trim((string)($option['id'] ?? ''));
                            $code = trim((string)($option['code'] ?? ''));
                            $storedValue = trim((string)($option['value'] ?? ''));
                            $aliases = [];
                            foreach ([$id, $code, $storedValue] as $identity) {
                                if ($identity !== '') {
                                    $aliases[(string)$identity] = true;
                                }
                            }
                            if ($aliases === []) {
                                continue;
                            }
                            $label = trim((string)($option['label'] ?? ''));
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

        return $this->optionsByAttribute = $index;
    }
}
