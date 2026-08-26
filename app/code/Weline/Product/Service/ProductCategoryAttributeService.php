<?php

declare(strict_types=1);

namespace Weline\Product\Service;

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

    /**
     * @param list<int> $categoryIds
     * @return array<int, string>
     */
    public function readNameMap(int $websiteId, array $categoryIds, string $locale = ''): array
    {
        $categoryIds = array_values(array_filter(
            array_map('intval', $categoryIds),
            static fn(int $id): bool => $id > 0,
        ));
        if ($categoryIds === []) {
            return [];
        }

        $names = [];
        foreach ($this->attributes->listExplicitRows(
            $websiteId,
            self::ENTITY_TYPE,
            $categoryIds,
            [AttributeValue::WEBSITE_STORE_ID],
        ) as $attribute) {
            if ((string)($attribute['attribute_code'] ?? '') !== 'name' || !empty($attribute['cleared'])) {
                continue;
            }
            $attributeLocale = (string)($attribute['locale'] ?? '');
            $entityId = (int)($attribute['entity_id'] ?? 0);
            if ($attributeLocale === $locale || ($attributeLocale === '' && !isset($names[$entityId]))) {
                $names[$entityId] = trim((string)($attribute['value'] ?? ''));
            }
        }

        return $names;
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
