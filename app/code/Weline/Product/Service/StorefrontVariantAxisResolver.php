<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Eav\Api\Metadata\AttributeMetadata;
use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Api\Metadata\AttributeOptionMetadata;
use Weline\Eav\Api\Metadata\AttributeSetMetadata;
use Weline\Product\Model\ProductCatalogAttributeEntity;

/**
 * Builds storefront variant axes from product type_configuration + EAV option metadata.
 *
 * type_configuration.axes lists which EAV attributes participate in the matrix;
 * labels and option display text come from EAV unless the product narrows the option set.
 */
final class StorefrontVariantAxisResolver
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $attributesByCode = null;

    public function __construct(
        private readonly AttributeMetadataCatalogInterface $metadata,
        private readonly ProductCatalogAttributeEntity $entity,
        private readonly StorefrontEavLabelResolver $labels,
    ) {
    }

    /**
     * @param array<string, mixed> $typeConfiguration
     * @param array<string, string> $resolvedValues
     * @return list<array{code:string,label:string,value:string,value_label:string,options:list<array{value:string,label:string,swatch_color?:string,swatch_image?:string}>}>
     */
    public function buildAxes(array $typeConfiguration, array $resolvedValues): array
    {
        $axisDefinitions = is_array($typeConfiguration['axes'] ?? null)
            ? $typeConfiguration['axes']
            : [];
        if ($axisDefinitions === []) {
            return [];
        }

        $eavIndex = $this->attributesByCode();
        $result = [];
        foreach ($axisDefinitions as $axis) {
            if (!is_array($axis)) {
                continue;
            }
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
            // 属性显示名走模块 i18n（CSV 源文多为中文），避免店面语言切换后仍输出种子原文。
            $label = (string)__($label);

            $selected = trim((string)($resolvedValues[$code] ?? ''));
            $options = $this->resolveAxisOptions($code, $axis, $eav);

            $result[] = [
                'code' => $code,
                'label' => $label,
                'value' => $selected,
                'value_label' => $selected !== '' ? $this->labels->resolve($code, $selected) : '',
                'options' => $options,
            ];
        }

        return $result;
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
            if ($label === '') {
                $label = $this->labels->resolve($code, $value);
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
        // 全局 EAV 选项图板仅表示「可填图」能力；具体样本图只来自商品 type_configuration。

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
            $code = trim((string)($option['code'] ?? $option['value'] ?? ''));
            if ($code === $value) {
                return $option;
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
        if ($this->attributesByCode !== null) {
            return $this->attributesByCode;
        }

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
