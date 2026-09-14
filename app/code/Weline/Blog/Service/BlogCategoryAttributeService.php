<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Model\BlogCategoryAttributeEntity;
use Weline\Eav\Api\Attribute\AttributeRecord;
use Weline\Eav\Api\Attribute\EntityAttributeStoreInterface;
use Weline\Eav\Api\Attribute\ScopedAttributeBatchReaderInterface;
use Weline\Framework\App\State;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Localized blog category EAV values (name, code) via EntityAttributeStore.
 */
final class BlogCategoryAttributeService
{
    public const ENTITY_TYPE = BlogCategoryAttributeEntity::entity_code;

    public function __construct(
        private readonly EntityAttributeStoreInterface $store,
        private readonly BlogCategoryAttributeEntity $entity,
    ) {
    }

    public function writeName(
        int $websiteId,
        int $categoryId,
        string $name,
        string $locale = '',
    ): void {
        $this->writeAttribute($websiteId, $categoryId, 'name', $name, $locale);
    }

    public function writeCode(
        int $websiteId,
        int $categoryId,
        string $code,
        string $locale = '',
    ): void {
        $this->writeAttribute($websiteId, $categoryId, 'code', $code, $locale);
    }

    public function writeImage(
        int $websiteId,
        int $categoryId,
        string $image,
        string $locale = '',
    ): void {
        $this->writeAttribute(
            $websiteId,
            $categoryId,
            'image',
            \Weline\Catalog\Service\CategoryMediaConstraints::assertImageUrl($image),
            $locale,
        );
    }

    public function writeBanner(
        int $websiteId,
        int $categoryId,
        string $banner,
        string $locale = '',
    ): void {
        $this->writeAttribute(
            $websiteId,
            $categoryId,
            'banner',
            \Weline\Catalog\Service\CategoryMediaConstraints::assertBannerUrl($banner),
            $locale,
        );
    }

    public function writeSummary(
        int $websiteId,
        int $categoryId,
        string $summary,
        string $locale = '',
    ): void {
        $this->writeAttribute(
            $websiteId,
            $categoryId,
            'summary',
            \Weline\Catalog\Service\CategoryMediaConstraints::assertSummary($summary),
            $locale,
        );
    }

    public function writeDescription(
        int $websiteId,
        int $categoryId,
        string $description,
        string $locale = '',
    ): void {
        $this->writeAttribute(
            $websiteId,
            $categoryId,
            'description',
            \Weline\Catalog\Service\CategoryMediaConstraints::assertDescription($description),
            $locale,
        );
    }

    /**
     * @param list<int> $categoryIds
     * @return array<int, string>
     */
    public function readNameMap(int $websiteId, array $categoryIds, string $locale = ''): array
    {
        return $this->readAttributeMap($websiteId, $categoryIds, 'name', $locale);
    }

    /**
     * @param list<int> $categoryIds
     * @return array<int, string>
     */
    public function readImageMap(int $websiteId, array $categoryIds, string $locale = ''): array
    {
        return $this->readAttributeMap($websiteId, $categoryIds, 'image', $locale);
    }

    /**
     * @param list<int> $categoryIds
     * @return array<int, string>
     */
    public function readBannerMap(int $websiteId, array $categoryIds, string $locale = ''): array
    {
        return $this->readAttributeMap($websiteId, $categoryIds, 'banner', $locale);
    }

    /**
     * @param list<int> $categoryIds
     * @return array<int, string>
     */
    public function readSummaryMap(int $websiteId, array $categoryIds, string $locale = ''): array
    {
        return $this->readAttributeMap($websiteId, $categoryIds, 'summary', $locale);
    }

    /**
     * @param list<int> $categoryIds
     * @return array<int, string>
     */
    public function readDescriptionMap(int $websiteId, array $categoryIds, string $locale = ''): array
    {
        return $this->readAttributeMap($websiteId, $categoryIds, 'description', $locale);
    }

    /** @return array<string, array<int, string>> */
    public function readDisplayMaps(int $websiteId, array $categoryIds, string $locale = ''): array
    {
        return $this->readAttributeMaps($websiteId, $categoryIds, ['name', 'image', 'banner', 'summary', 'description'], $locale);
    }

    public function readName(int $websiteId, int $categoryId, string $locale = ''): string
    {
        $map = $this->readNameMap($websiteId, [$categoryId], $locale);

        return (string)($map[$categoryId] ?? '');
    }

    /**
     * Resolve storefront label: EAV locale → i18n csv → DB fallback.
     */
    public function resolveDisplayName(
        int $websiteId,
        int $categoryId,
        string $locale,
        string $fallbackName,
    ): string {
        $localized = $this->readName($websiteId, $categoryId, $locale);
        if ($localized !== '') {
            return $localized;
        }

        return $this->resolveFallbackName($fallbackName);
    }

    public function resolveFallbackName(string $fallbackName): string
    {
        $fallbackName = trim($fallbackName);
        if ($fallbackName === '') {
            return '';
        }

        $translated = (string)__($fallbackName);

        return $translated !== $fallbackName ? $translated : $fallbackName;
    }

    /**
     * @param list<int> $categoryIds
     * @return list<array<string, mixed>>
     */
    public function listExplicitRows(int $websiteId, array $categoryIds): array
    {
        $rows = [];
        foreach (['name', 'code', 'image', 'banner', 'summary', 'description'] as $code) {
            $attribute = $this->getAttribute($code);
            if (!$attribute instanceof AttributeRecord) {
                continue;
            }
            foreach ($categoryIds as $categoryId) {
                if ($categoryId <= 0) {
                    continue;
                }
                $scope = ScopeIdentity::website($websiteId, 'default');
                foreach (['', 'zh_Hans_CN', 'en_US'] as $locale) {
                    $hit = $this->store->readScopedValue($this->entity, $categoryId, $attribute, $scope, $locale);
                    if ($hit->isCleared() || trim((string)($hit->value ?? '')) === '') {
                        continue;
                    }
                    $rows[] = [
                        'entity_id' => $categoryId,
                        'attribute_code' => $code,
                        'locale' => $locale,
                        'value' => (string)$hit->value,
                        'cleared' => false,
                        'is_required' => $code === 'name',
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * @param list<int> $categoryIds
     * @return array<int, string>
     */
    private function readAttributeMap(
        int $websiteId,
        array $categoryIds,
        string $attributeCode,
        string $locale = '',
    ): array {
        return $this->readAttributeMaps($websiteId, $categoryIds, [$attributeCode], $locale)[$attributeCode] ?? [];
    }

    /** @return array<string, array<int, string>> */
    private function readAttributeMaps(int $websiteId, array $categoryIds, array $attributeCodes, string $locale): array
    {
        $categoryIds = array_values(array_filter(
            array_unique(array_map('intval', $categoryIds)),
            static fn(int $id): bool => $id > 0,
        ));
        if ($categoryIds === []) {
            return [];
        }

        $attributes = [];
        foreach (array_unique($attributeCodes) as $code) {
            $attribute = $this->getAttribute($code);
            if ($attribute instanceof AttributeRecord) {
                $attributes[] = $attribute;
            }
        }
        if ($attributes === []) {
            return [];
        }

        $locale = self::normalizeLocaleKey($locale !== '' ? $locale : (string)State::getLangLocal());
        $scope = ScopeIdentity::website($websiteId, 'default');
        $hits = [];
        if ($this->store instanceof ScopedAttributeBatchReaderInterface) {
            $hits = $this->store->readScopedValues($this->entity, $categoryIds, $attributes, $scope, $locale);
        } else {
            // Preserve third-party single-value store implementations.
            foreach ($categoryIds as $categoryId) {
                foreach ($attributes as $attribute) {
                    $hits[$categoryId][$attribute->code] = $this->store->readScopedValue($this->entity, $categoryId, $attribute, $scope, $locale);
                }
            }
        }
        $values = [];
        foreach ($categoryIds as $categoryId) {
            foreach ($attributes as $attribute) {
                $hit = $hits[$categoryId][$attribute->code] ?? null;
                if ($hit === null || $hit->isCleared()) {
                    continue;
                }
                $value = trim((string)($hit->value ?? ''));
                if ($value !== '') {
                    $values[$attribute->code][$categoryId] = $value;
                }
            }
        }

        return $values;
    }

    private function writeAttribute(
        int $websiteId,
        int $categoryId,
        string $attributeCode,
        string $value,
        string $locale = '',
    ): void {
        $attribute = $this->getAttribute($attributeCode);
        if (!$attribute instanceof AttributeRecord) {
            throw new \RuntimeException('blog_category_attribute_missing:' . $attributeCode);
        }
        $scope = ScopeIdentity::website($websiteId, 'default');
        $this->store->writeScopedValue(
            $this->entity,
            $categoryId,
            $attribute,
            $scope,
            trim($value),
            self::normalizeLocaleKey($locale),
        );
        BlogContentCache::clearRequestSnapshots();
        // EAV-only writes skip Category model mutation; still bump storefront blog snapshots.
        try {
            $namespaces = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Framework\Cache\Contract\NamespaceGenerationInterface::class,
            );
            if ($namespaces instanceof \Weline\Framework\Cache\Contract\NamespaceGenerationInterface) {
                $namespaces->bumpMany(BlogContentCache::changedPaths($websiteId, $websiteId));
            }
        } catch (\Throwable) {
            // Durable EAV value is authoritative; namespace bump is an accelerator.
        }
    }

    private function getAttribute(string $code): ?AttributeRecord
    {
        return $this->store->getAttribute($this->entity, $code);
    }

    private static function normalizeLocaleKey(string $locale): string
    {
        return trim(str_replace('-', '_', $locale));
    }
}
