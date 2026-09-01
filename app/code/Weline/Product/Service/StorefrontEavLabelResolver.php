<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Api\Metadata\AttributeSetMetadata;
use Weline\Product\Model\ProductCatalogAttributeEntity;

/**
 * Resolves Product catalog EAV option codes to storefront display labels.
 */
class StorefrontEavLabelResolver
{
    /** @var array<string, array<string, string>>|null */
    private ?array $optionLabelsByAttribute = null;

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
     * @return array<string, array<string, string>>
     */
    private function optionLabelsByAttribute(): array
    {
        if ($this->optionLabelsByAttribute !== null) {
            return $this->optionLabelsByAttribute;
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
                            $optionCode = trim((string)($option['code'] ?? ''));
                            if ($optionCode === '') {
                                $optionCode = trim((string)($option['value'] ?? ''));
                            }
                            $label = trim((string)($option['label'] ?? ''));
                            if ($optionCode === '') {
                                continue;
                            }
                            if ($label === '') {
                                $label = $optionCode;
                            }
                            $index[$attributeCode][$optionCode] = $label;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            $index = [];
        }

        return $this->optionLabelsByAttribute = $index;
    }
}
