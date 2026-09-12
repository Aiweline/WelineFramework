<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\App\State;
use Weline\Product\Model\Category\LocalDescription;
use Weline\Product\Model\CategoryAttributeEntity;
use Weline\Product\Model\Shard\AttributeValue;
use Weline\Product\Repository\AttributeValueRepository;

/**
 * Single write path for category EAV values on the Product shard (S1).
 */
final class ProductCategoryAttributeService
{
    public const ENTITY_TYPE = CategoryAttributeEntity::entity_code;

    public function __construct(
        private readonly AttributeValueRepository $attributes,
    ) {
    }

    public function writeName(
        int $websiteId,
        int $categoryId,
        string $name,
        string $locale = '',
    ): void {
        $this->attributes->writeExplicit(
            $websiteId,
            AttributeValue::WEBSITE_STORE_ID,
            self::ENTITY_TYPE,
            $categoryId,
            'name',
            $locale,
            $name,
            true,
        );
        // Keep <local> LocalModel in sync with EAV (skip when Local drawer is already writing).
        if (!LocalDescription::isSyncing()) {
            LocalDescription::upsertQuiet($categoryId, $locale, $name);
        }
    }

    public function writeCode(
        int $websiteId,
        int $categoryId,
        string $code,
        string $locale = '',
    ): void {
        $this->attributes->writeExplicit(
            $websiteId,
            AttributeValue::WEBSITE_STORE_ID,
            self::ENTITY_TYPE,
            $categoryId,
            'code',
            $locale,
            $code,
            true,
        );
    }

    public function writeGoogleTaxonomyId(
        int $websiteId,
        int $categoryId,
        string $googleTaxonomyId,
        string $locale = '',
    ): void {
        $this->attributes->writeExplicit(
            $websiteId,
            AttributeValue::WEBSITE_STORE_ID,
            self::ENTITY_TYPE,
            $categoryId,
            'google_taxonomy_id',
            $locale,
            trim($googleTaxonomyId),
            false,
        );
    }

    public function writeImage(
        int $websiteId,
        int $categoryId,
        string $image,
        string $locale = '',
    ): void {
        $this->attributes->writeExplicit(
            $websiteId,
            AttributeValue::WEBSITE_STORE_ID,
            self::ENTITY_TYPE,
            $categoryId,
            'image',
            $locale,
            \Weline\Catalog\Service\CategoryMediaConstraints::assertImageUrl($image),
            false,
        );
    }

    public function writeBanner(
        int $websiteId,
        int $categoryId,
        string $banner,
        string $locale = '',
    ): void {
        $this->attributes->writeExplicit(
            $websiteId,
            AttributeValue::WEBSITE_STORE_ID,
            self::ENTITY_TYPE,
            $categoryId,
            'banner',
            $locale,
            \Weline\Catalog\Service\CategoryMediaConstraints::assertBannerUrl($banner),
            false,
        );
    }

    public function writeSummary(
        int $websiteId,
        int $categoryId,
        string $summary,
        string $locale = '',
    ): void {
        $this->attributes->writeExplicit(
            $websiteId,
            AttributeValue::WEBSITE_STORE_ID,
            self::ENTITY_TYPE,
            $categoryId,
            'summary',
            $locale,
            \Weline\Catalog\Service\CategoryMediaConstraints::assertSummary($summary),
            false,
        );
    }

    public function writeSourcePlatform(
        int $websiteId,
        int $categoryId,
        string $sourcePlatform,
        string $locale = '',
    ): void {
        $sourcePlatform = strtolower(trim($sourcePlatform));
        $this->attributes->writeExplicit(
            $websiteId,
            AttributeValue::WEBSITE_STORE_ID,
            self::ENTITY_TYPE,
            $categoryId,
            'source_platform',
            $locale,
            $sourcePlatform,
            false,
        );
    }

    public function writeDescription(
        int $websiteId,
        int $categoryId,
        string $description,
        string $locale = '',
    ): void {
        $this->attributes->writeExplicit(
            $websiteId,
            AttributeValue::WEBSITE_STORE_ID,
            self::ENTITY_TYPE,
            $categoryId,
            'description',
            $locale,
            \Weline\Catalog\Service\CategoryMediaConstraints::assertDescription($description),
            false,
        );
    }

    /**
     * @param list<int> $categoryIds
     * @return array<int, string>
     */
    public function readGoogleTaxonomyIdMap(int $websiteId, array $categoryIds, string $locale = ''): array
    {
        return $this->readAttributeMap($websiteId, $categoryIds, 'google_taxonomy_id', $locale);
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
    public function readCodeMap(int $websiteId, array $categoryIds, string $locale = ''): array
    {
        return $this->readAttributeMap($websiteId, $categoryIds, 'code', $locale);
    }

    /**
     * @param list<int> $categoryIds
     * @return array<int, string>
     */
    public function readSourcePlatformMap(int $websiteId, array $categoryIds, string $locale = ''): array
    {
        return $this->readAttributeMap($websiteId, $categoryIds, 'source_platform', $locale);
    }

    /**
     * Read the category presentation in one repository batch. Locale fallback
     * remains identical to the individual attribute readers.
     *
     * @param list<int> $categoryIds
     * @return array{name: array<int, string>, image: array<int, string>, banner: array<int, string>, summary: array<int, string>, description: array<int, string>}
     */
    public function readPresentationMaps(int $websiteId, array $categoryIds, string $locale = ''): array
    {
        return $this->readAttributeMaps(
            $websiteId,
            $categoryIds,
            ['name', 'image', 'banner', 'summary', 'description'],
            $locale,
        );
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
        return $this->readAttributeMaps($websiteId, $categoryIds, [$attributeCode], $locale)[$attributeCode];
    }

    /**
     * @param list<int> $categoryIds
     * @param list<string> $attributeCodes
     * @return array<string, array<int, string>>
     */
    private function readAttributeMaps(
        int $websiteId,
        array $categoryIds,
        array $attributeCodes,
        string $locale,
    ): array {
        $maps = array_fill_keys($attributeCodes, []);
        $categoryIds = array_values(array_unique(array_filter(
            array_map('intval', $categoryIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($categoryIds === []) {
            return $maps;
        }

        $locale = self::normalizeLocaleKey($locale !== '' ? $locale : (string)State::getLangLocal());
        $byAttribute = [];
        foreach ($this->attributes->listExplicitRows(
            $websiteId,
            self::ENTITY_TYPE,
            $categoryIds,
            [AttributeValue::WEBSITE_STORE_ID],
        ) as $attribute) {
            $attributeCode = (string)($attribute['attribute_code'] ?? '');
            if (!isset($maps[$attributeCode]) || !empty($attribute['cleared'])) {
                continue;
            }
            $entityId = (int)($attribute['entity_id'] ?? 0);
            if ($entityId <= 0) {
                continue;
            }
            $attributeLocale = self::normalizeLocaleKey((string)($attribute['locale'] ?? ''));
            $value = trim((string)($attribute['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $byAttribute[$attributeCode][$entityId][$attributeLocale] = $value;
        }

        foreach ($attributeCodes as $attributeCode) {
            foreach ($categoryIds as $entityId) {
                if (!isset($byAttribute[$attributeCode][$entityId])) {
                    continue;
                }
                $picked = self::pickLocalizedAttributeValue($byAttribute[$attributeCode][$entityId], $locale);
                if ($picked !== '') {
                    $maps[$attributeCode][$entityId] = $picked;
                }
            }
        }

        return $maps;
    }

    /**
     * @param array<string, string> $localeValues normalized locale => value
     */
    public static function pickLocalizedAttributeValue(array $localeValues, string $locale): string
    {
        if ($localeValues === []) {
            return '';
        }
        $locale = self::normalizeLocaleKey($locale);
        if ($locale !== '' && isset($localeValues[$locale]) && $localeValues[$locale] !== '') {
            return $localeValues[$locale];
        }
        foreach (self::localeAliases($locale) as $alias) {
            if ($alias === $locale) {
                continue;
            }
            if (isset($localeValues[$alias]) && $localeValues[$alias] !== '') {
                return $localeValues[$alias];
            }
        }
        if (isset($localeValues['']) && $localeValues[''] !== '') {
            return $localeValues[''];
        }

        return '';
    }

    public static function normalizeLocaleKey(string $locale): string
    {
        return trim(str_replace('-', '_', $locale));
    }

    /** @return list<string> */
    private static function localeAliases(string $locale): array
    {
        $locale = self::normalizeLocaleKey($locale);
        if ($locale === '') {
            return [];
        }
        $aliases = [$locale];
        if (preg_match('/^([a-z]{2,3})_([A-Za-z]+)_([A-Z]{2})$/', $locale, $matches) === 1) {
            $aliases[] = $matches[1] . '_' . $matches[3];
            $aliases[] = $matches[1] . '_' . $matches[2];
            $aliases[] = $matches[1];
        } elseif (preg_match('/^([a-z]{2,3})_([A-Z]{2})$/', $locale, $matches) === 1) {
            $aliases[] = $matches[1];
        }

        return array_values(array_unique($aliases));
    }

    /**
     * @param list<int> $categoryIds
     * @param list<int> $storeIds
     * @return list<array<string, mixed>>
     */
    public function listExplicitRows(int $websiteId, array $categoryIds, array $storeIds = []): array
    {
        if ($storeIds === []) {
            $storeIds = [AttributeValue::WEBSITE_STORE_ID];
        }

        return $this->attributes->listExplicitRows(
            $websiteId,
            self::ENTITY_TYPE,
            $categoryIds,
            $storeIds,
        );
    }

    public function purge(int $websiteId, int $categoryId): void
    {
        if ($categoryId <= 0) {
            return;
        }
        $this->attributes->purgeEntity($websiteId, self::ENTITY_TYPE, $categoryId);
    }

    /**
     * @param array<int, int> $entityMap source category_id => target category_id
     * @param list<int> $sourceStoreIds
     * @param callable(int): list<int> $targetStoreIdsForSourceRow
     */
    public function copyExplicitAttributes(
        int $sourceWebsiteId,
        int $targetWebsiteId,
        array $entityMap,
        array $sourceStoreIds,
        callable $targetStoreIdsForSourceRow,
    ): void {
        if ($entityMap === []) {
            return;
        }
        $rows = $this->attributes->listExplicitRows(
            $sourceWebsiteId,
            self::ENTITY_TYPE,
            array_keys($entityMap),
            $sourceStoreIds,
        );
        foreach ($rows as $row) {
            $targetEntityId = $entityMap[(int)$row['entity_id']] ?? null;
            if ($targetEntityId === null) {
                continue;
            }
            foreach ($targetStoreIdsForSourceRow((int)$row['store_id']) as $targetStoreId) {
                if ($row['cleared']) {
                    $this->attributes->writeCleared(
                        $targetWebsiteId,
                        $targetStoreId,
                        self::ENTITY_TYPE,
                        $targetEntityId,
                        (string)$row['attribute_code'],
                        (string)$row['locale'],
                        (bool)$row['is_required'],
                    );
                } else {
                    $this->attributes->writeExplicit(
                        $targetWebsiteId,
                        $targetStoreId,
                        self::ENTITY_TYPE,
                        $targetEntityId,
                        (string)$row['attribute_code'],
                        (string)$row['locale'],
                        $row['value'],
                        (bool)$row['is_required'],
                    );
                }
            }
        }
    }
}
