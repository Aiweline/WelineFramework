<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Eav\Api\Metadata\AttributeMetadata;
use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Api\Metadata\AttributeOptionMetadata;
use Weline\Eav\Api\Metadata\AttributeSetMetadata;
use Weline\Framework\App\State;
use Weline\Product\Model\ProductCatalogAttributeEntity;

/**
 * Builds storefront variant axes from Product EAV multiselect values, with the
 * selected value supplied by Offer EAV select values or combination_key.
 */
final class StorefrontVariantAxisResolver
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $attributesByCode = null;

    private ?string $attributesCacheLocale = null;

    private int $productId = 0;

    private ?StorefrontEavLabelResolver $scopedLabelResolver = null;

    private ?self $lastProductScopedResolver = null;

    public function __construct(
        private readonly AttributeMetadataCatalogInterface $metadata,
        private readonly ProductCatalogAttributeEntity $entity,
        private readonly StorefrontEavLabelResolver $labels,
    ) {
    }

    /**
     * Bind product-private option scope so axis chips resolve Chinese labels.
     */
    public function forProduct(int $productId): self
    {
        $productId = max(0, $productId);
        if ($this->productId === $productId) {
            return $this;
        }

        // Do not retain a previous product resolver on the long-lived base
        // instance. Product maps are owned by the request-scoped projector.
        $scoped = clone $this;
        $scoped->productId = $productId;
        $scoped->attributesByCode = null;
        $scoped->attributesCacheLocale = null;
        $scoped->scopedLabelResolver = null;
        $scoped->lastProductScopedResolver = null;

        return $scoped;
    }

    private function scopedLabels(): StorefrontEavLabelResolver
    {
        return $this->scopedLabelResolver ??= $this->productId > 0
            ? $this->labels->forProduct($this->productId)
            : $this->labels;
    }

    /**
     * @param array<string, mixed> $resolvedValues
     * @param array<string, mixed> $availableValues
     * @return list<array{code:string,label:string,value:string,value_label:string,options:list<array{value:string,label:string,swatch_color?:string,swatch_image?:string}>}>
     */
    public function buildAxes(
        array $resolvedValues,
        array $availableValues = [],
    ): array {
        $eavIndex = $this->attributesByCode();
        $axisDefinitions = [];
        foreach ($availableValues as $code => $rawValues) {
            $code = strtolower(trim((string)$code));
            $eav = $eavIndex[$code] ?? null;
            if (!is_array($eav)
                || empty($eav['multiple'])
                || empty($eav['has_option'])
            ) {
                continue;
            }
            $values = $this->axisValueList($rawValues);
            if ($values === []) {
                continue;
            }
            $options = [];
            foreach ($values as $value) {
                $options[] = [
                    'value' => $value,
                    'label' => $this->eavOptionLabel($eav, $value),
                ];
            }
            $axisDefinitions[] = [
                'code' => $code,
                'label' => (string)($eav['name'] ?? $code),
                'options' => $options,
            ];
        }

        $result = [];
        foreach ($axisDefinitions as $axis) {
            $code = strtolower(trim((string)($axis['code'] ?? '')));
            if ($code === '') {
                continue;
            }

            $eav = $eavIndex[$code] ?? null;
            $label = trim((string)($axis['label'] ?? ''));
            if ($label === '' && is_array($eav)) {
                $label = trim((string)($eav['name'] ?? ''));
            }
            if ($label === '') {
                $label = $code;
            }
            $label = (string)__($label);

            $selectedValues = $this->axisValueList($resolvedValues[$code] ?? '');
            $selected = $selectedValues[0] ?? '';
            $options = $this->resolveAxisOptions($code, $axis, $eav);

            $result[] = [
                'code' => $code,
                'label' => $label,
                'value' => $selected,
                'value_label' => $selected !== '' ? $this->scopedLabels()->resolve($code, $selected) : '',
                'options' => $options,
            ];
        }

        return $result;
    }

    /** @return list<string> */
    private function axisValueList(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            if (str_contains($trimmed, ',')) {
                $value = preg_split('/\s*,\s*/', $trimmed) ?: [];
            } else {
                $value = [$trimmed];
            }
        }
        if (!is_array($value)) {
            return is_scalar($value) ? [trim((string)$value)] : [];
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = trim((string)$item);
            if ($item !== '') {
                $result[$item] = $item;
            }
        }
        return array_values($result);
    }

    /** @param array<string,mixed> $attribute */
    private function eavOptionLabel(array $attribute, string $value): string
    {
        foreach (is_array($attribute['options'] ?? null) ? $attribute['options'] : [] as $option) {
            if (!is_array($option)) {
                continue;
            }
            foreach (['id', 'code', 'value'] as $identityKey) {
                if ($value === trim((string)($option[$identityKey] ?? ''))) {
                    return (string)($option['label'] ?? $option['name'] ?? $value);
                }
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $axis
     * @param array<string, mixed>|null $eav
     * @return list<array{value:string,label:string,swatch_color?:string,swatch_image?:string,gallery_images?:list<string>}>
     */
    private function resolveAxisOptions(string $code, array $axis, ?array $eav): array
    {
        $configured = [];
        foreach ((array)($axis['options'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }
            $value = trim((string)($option['value'] ?? $option['code'] ?? ''));
            if ($value === '') {
                continue;
            }
            $label = trim((string)($option['label'] ?? ''));
            $resolved = trim($this->scopedLabels()->resolve($code, $value));
            if ($resolved !== '') {
                $label = $resolved;
            } elseif ($label === '') {
                $label = $value;
            }
            $configured[$value] = $this->mergeOptionWithEav(
                $this->normalizeOptionEntry($value, $label, $option),
                is_array($eav) ? $this->findEavOption($eav, $value) : null,
            );
        }
        if ($configured !== []) {
            return array_values($configured);
        }

        if (!is_array($eav)) {
            return [];
        }

        $options = [];
        foreach ($eav['options'] ?? [] as $option) {
            if (!is_array($option)) {
                continue;
            }
            $value = trim((string)($option['code'] ?? $option['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $label = trim((string)($option['label'] ?? ''));
            if ($label === '') {
                $label = $value;
            }
            $options[] = $this->normalizeOptionEntry($value, $label, $option);
        }

        return $options;
    }

    /**
     * @param array{value:string,label:string,swatch_color?:string,swatch_image?:string} $entry
     * @param array<string, mixed>|null $eavOption
     * @return array{value:string,label:string,swatch_color?:string,swatch_image?:string,gallery_images?:list<string>}
     */
    private function mergeOptionWithEav(array $entry, ?array $eavOption): array
    {
        if ($eavOption === null) {
            return $entry;
        }
        if (!isset($entry['swatch_color'])) {
            $swatchColor = trim((string)($eavOption['swatch_color'] ?? ''));
            if ($swatchColor !== '') {
                $entry['swatch_color'] = $swatchColor;
            }
        }
        $optionCode = trim((string)($eavOption['code'] ?? ''));
        if ($optionCode !== '' && trim((string)($entry['code'] ?? '')) === '') {
            $entry['code'] = $optionCode;
        }
        $eavLabel = trim((string)($eavOption['label'] ?? $eavOption['name'] ?? ''));
        if ($eavLabel !== '' && trim((string)($entry['label'] ?? '')) === '') {
            $entry['label'] = $eavLabel;
        }
        // 全局 EAV 选项图板仅表示抽象选项；具体样本图只来自 Product/Offer 媒体关联。

        return $entry;
    }

    /**
     * @param array<string, mixed> $eav
     * @return array<string, mixed>|null
     */
    private function findEavOption(array $eav, string $value): ?array
    {
        foreach ($eav['options'] ?? [] as $option) {
            if (!is_array($option)) {
                continue;
            }
            foreach (['id', 'code', 'value'] as $identityKey) {
                if ($value === trim((string)($option[$identityKey] ?? ''))) {
                    return $option;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $option
     * @return array{value:string,label:string,swatch_color?:string,swatch_image?:string,gallery_images?:list<string>}
     */
    private function normalizeOptionEntry(string $value, string $label, array $option): array
    {
        $entry = ['value' => $value, 'label' => $label];
        $optionCode = trim((string)($option['code'] ?? ''));
        if ($optionCode !== '') {
            $entry['code'] = $optionCode;
        }
        $swatchColor = trim((string)($option['swatch_color'] ?? $option['swatch'] ?? ''));
        if ($swatchColor !== '') {
            $entry['swatch_color'] = $swatchColor;
        }
        $swatchImage = trim((string)($option['swatch_image'] ?? ''));
        if ($swatchImage !== '') {
            $entry['swatch_image'] = $swatchImage;
        }
        $galleryImages = $this->normalizeGalleryImages($option['gallery_images'] ?? null);
        if ($galleryImages !== []) {
            $entry['gallery_images'] = $galleryImages;
        }
        $galleryByColor = $this->normalizeGalleryByColor($option['gallery_by_color'] ?? null);
        if ($galleryByColor !== []) {
            $entry['gallery_by_color'] = $galleryByColor;
        }

        return $entry;
    }

    /**
     * @param array<string, string> $combination
     * @return list<string>
     */
    private function resolveOptionGallery(array $option, array $combination): array
    {
        $color = strtolower(trim((string)($combination['color'] ?? '')));
        $byColor = $option['gallery_by_color'] ?? null;
        if ($color !== '' && is_array($byColor) && isset($byColor[$color]) && is_array($byColor[$color])) {
            $gallery = $this->normalizeGalleryImages($byColor[$color]);
            if ($gallery !== []) {
                return $gallery;
            }
        }

        return $this->normalizeGalleryImages($option['gallery_images'] ?? null);
    }

    /**
     * @param list<array<string, mixed>> $variantAxes
     * @param array<string, string> $combination
     * @return list<string>
     */
    public function resolveGalleryImages(array $variantAxes, array $combination): array
    {
        $styleType = strtolower(trim((string)($combination['style_type'] ?? '')));
        $images = [];
        foreach ($variantAxes as $axis) {
            if (!is_array($axis)) {
                continue;
            }
            $code = strtolower(trim((string)($axis['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $selected = trim((string)($combination[$code] ?? ''));
            if ($selected === '') {
                continue;
            }
            foreach ((array)($axis['options'] ?? []) as $option) {
                if (!is_array($option)) {
                    continue;
                }
                if (trim((string)($option['value'] ?? '')) !== $selected) {
                    continue;
                }
                $gallery = $this->resolveOptionGallery($option, $combination);
                if ($gallery === []) {
                    break;
                }
                if ($images === []) {
                    $images = $gallery;
                    break;
                }
                $intersection = array_values(array_intersect($images, $gallery));
                if ($intersection !== []) {
                    $images = $intersection;
                } elseif ($code === 'color' && $styleType !== '' && $styleType !== 'set') {
                    // 单件类型已选定构图，颜色轴只负责 SKU 可售，不再用套装主图覆盖。
                } else {
                    $images = $gallery;
                }
                break;
            }
        }

        return array_values(array_unique($images));
    }

    /**
     * @return array<string, list<string>>
     */
    private function normalizeGalleryByColor(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $colorCode => $paths) {
            $colorCode = strtolower(trim((string)$colorCode));
            if ($colorCode === '') {
                continue;
            }
            $gallery = $this->normalizeGalleryImages($paths);
            if ($gallery !== []) {
                $result[$colorCode] = $gallery;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function normalizeGalleryImages(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $images = [];
        foreach ($raw as $path) {
            $path = trim((string)$path);
            if ($path !== '') {
                $images[] = $path;
            }
        }

        return array_values(array_unique($images));
    }

    /**
     * @param list<array<string, mixed>> $variantAxes
     * @param array<string, string> $combination
     */
    public function resolvePreviewImage(array $variantAxes, array $combination): string
    {
        foreach ($variantAxes as $axis) {
            if (!is_array($axis)) {
                continue;
            }
            $code = strtolower(trim((string)($axis['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $selected = trim((string)($combination[$code] ?? ''));
            if ($selected === '') {
                continue;
            }
            foreach ((array)($axis['options'] ?? []) as $option) {
                if (!is_array($option)) {
                    continue;
                }
                if (trim((string)($option['value'] ?? '')) !== $selected) {
                    continue;
                }
                $preview = trim((string)($option['swatch_image'] ?? ''));
                if ($preview !== '') {
                    return $preview;
                }
            }
        }

        return '';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function attributesByCode(): array
    {
        $locale = trim(str_replace('-', '_', (string)State::getLangLocal()));
        if ($locale === '') {
            $locale = 'zh_Hans_CN';
        }
        // Variant metadata is request-local; locale alone is not enough for a
        // long-lived WLS resolver instance.
        $requestKey = \Weline\Framework\Runtime\RequestContext::getRequestId() ?? '<no-request>';
        $cacheKey = $requestKey . '|' . $locale;
        if ($this->attributesByCode !== null && $this->attributesCacheLocale === $cacheKey) {
            return $this->attributesByCode;
        }
        $this->attributesCacheLocale = $cacheKey;

        $index = [];
        try {
            foreach ($this->metadata->catalog($this->entity) as $set) {
                if (!$set instanceof AttributeSetMetadata) {
                    continue;
                }
                foreach ($set->toArray()['groups'] as $group) {
                    foreach ($group['attributes'] as $attribute) {
                        if ($attribute instanceof AttributeMetadata) {
                            $attribute = $attribute->toArray();
                        }
                        if (!is_array($attribute)) {
                            continue;
                        }
                        $code = strtolower(trim((string)($attribute['code'] ?? '')));
                        if ($code === '') {
                            continue;
                        }
                        if (!isset($attribute['options']) && !empty($attribute['has_option'])) {
                            $attribute['options'] = [];
                        }
                        $index[$code] = $attribute;
                    }
                }
            }
        } catch (\Throwable) {
            $index = [];
        }

        return $this->attributesByCode = $index;
    }
}
