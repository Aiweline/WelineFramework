<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

use Weline\Feed\Api\Data\FeedCategoryPlan;
use Weline\Feed\Api\Data\FeedDefinition;
use Weline\Feed\Api\Data\FeedItem;
use Weline\Feed\Api\FeedCategoryMapReaderInterface;
use Weline\Feed\Api\FeedStoreCategoryReaderInterface;

/**
 * Applies one website's category maps. Unmapped values are never replaced with a placeholder.
 */
final class FeedCategoryPlanner
{
    public function __construct(
        private readonly ?FeedCategoryMapReaderInterface $maps = null,
        private readonly ?FeedStoreCategoryReaderInterface $categories = null,
    ) {
    }

    /**
     * @param list<FeedItem> $items
     */
    public function apply(FeedDefinition $definition, array $items, string $filenameSuffix): FeedCategoryPlan
    {
        $vocabulary = FeedCategoryVocabulary::forChannel($definition->channelCode, $filenameSuffix);
        $required = FeedCategoryVocabulary::required($definition->channelCode, $filenameSuffix);
        $productIds = [];
        foreach ($items as $item) {
            if ($item->productId > 0) {
                $productIds[$item->productId] = $item->productId;
            }
        }
        $links = $this->links($definition, array_values($productIds));
        $maps = $this->maps($definition->websiteId, $vocabulary);

        $kept = [];
        $diagnostics = [];
        $skipped = 0;
        foreach ($items as $item) {
            $choice = $this->choose($links[$item->productId] ?? [], $maps);
            if ($vocabulary === '') {
                $diagnostics[] = $this->diagnostic('category_unmapped', $definition, $item, 0);
                $kept[] = $item->withChannelCategory(null, null, 0);
                continue;
            }
            if ($choice['status'] === 'mapped') {
                $kept[] = $item->withChannelCategory(
                    $choice['value'],
                    $choice['label'],
                    $choice['store_category_id'],
                );
                continue;
            }
            $diagnostics[] = $this->diagnostic(
                $choice['status'] === 'ambiguous' ? 'category_ambiguous' : 'category_unmapped',
                $definition,
                $item,
                $choice['store_category_id'],
            );
            if ($required) {
                $skipped++;
                continue;
            }
            $kept[] = $item->withChannelCategory(null, null, $choice['store_category_id']);
        }

        return new FeedCategoryPlan($kept, $diagnostics, $skipped);
    }

    /**
     * @param list<int> $productIds
     * @return array<int, list<array{category_id:int,primary:bool,depth:int,name:string}>>
     */
    private function links(FeedDefinition $definition, array $productIds): array
    {
        if ($this->categories === null || $productIds === []) {
            return [];
        }
        $grouped = [];
        foreach ($this->categories->assignments($definition->websiteId, $productIds, $definition->storeId()) as $row) {
            $productId = (int)($row['product_id'] ?? 0);
            $categoryId = (int)($row['category_id'] ?? 0);
            if ($productId <= 0 || $categoryId <= 0) {
                continue;
            }
            $grouped[$productId][] = [
                'category_id' => $categoryId,
                'primary' => (bool)($row['primary'] ?? false),
                'depth' => (int)($row['depth'] ?? 0),
                'name' => trim((string)($row['name'] ?? '')),
            ];
        }

        return $grouped;
    }

    /**
     * @return array<int, array{value:string,label:string,name:string}>
     */
    private function maps(int $websiteId, string $vocabulary): array
    {
        if ($this->maps === null || $vocabulary === '') {
            return [];
        }
        $names = [];
        if ($this->categories !== null) {
            foreach ($this->categories->categories($websiteId) as $category) {
                $names[(int)$category['category_id']] = trim((string)$category['name']);
            }
        }
        $indexed = [];
        foreach ($this->maps->mapsForWebsite($websiteId) as $row) {
            if ((int)($row['website_id'] ?? 0) !== $websiteId) {
                continue;
            }
            if (trim((string)($row['vocabulary'] ?? '')) !== $vocabulary) {
                continue;
            }
            $categoryId = (int)($row['store_category_id'] ?? 0);
            $value = FeedCategoryValue::real($vocabulary, (string)($row['map_value'] ?? ''), $names[$categoryId] ?? '');
            if ($categoryId <= 0 || $value === '') {
                continue;
            }
            $indexed[$categoryId] = [
                'value' => $value,
                'label' => trim((string)($row['map_label'] ?? '')),
                'name' => $names[$categoryId] ?? '',
            ];
        }

        return $indexed;
    }

    /**
     * @param list<array{category_id:int,primary:bool,depth:int,name:string}> $links
     * @param array<int, array{value:string,label:string,name:string}> $maps
     * @return array{status:string,value:string,label:string,store_category_id:int}
     */
    private function choose(array $links, array $maps): array
    {
        $empty = ['status' => 'unmapped', 'value' => '', 'label' => '', 'store_category_id' => 0];
        if ($links === []) {
            return $empty;
        }
        $primaries = array_values(array_filter($links, static fn(array $link): bool => $link['primary']));
        $candidates = $primaries !== [] ? $primaries : $links;
        $mapped = [];
        foreach ($candidates as $link) {
            $map = $maps[$link['category_id']] ?? null;
            if ($map === null) {
                continue;
            }
            $mapped[] = $link + $map;
        }
        if ($mapped === []) {
            $empty['store_category_id'] = $candidates[0]['category_id'];

            return $empty;
        }
        $deepest = 0;
        foreach ($mapped as $link) {
            $deepest = max($deepest, $link['depth']);
        }
        $atDepth = array_values(array_filter(
            $mapped,
            static fn(array $link): bool => $link['depth'] === $deepest,
        ));
        $values = array_values(array_unique(array_map(static fn(array $link): string => $link['value'], $atDepth)));
        if (count($values) > 1) {
            return [
                'status' => 'ambiguous',
                'value' => '',
                'label' => '',
                'store_category_id' => $atDepth[0]['category_id'],
            ];
        }
        $winner = $atDepth[0];

        return [
            'status' => 'mapped',
            'value' => $winner['value'],
            'label' => $winner['label'],
            'store_category_id' => $winner['category_id'],
        ];
    }

    /**
     * @return array{code:string,channel_code:string,product_id:int,offer_id:string,store_category_id:int,vocabulary:string}
     */
    private function diagnostic(string $code, FeedDefinition $definition, FeedItem $item, int $storeCategoryId): array
    {
        return [
            'code' => $code,
            'channel_code' => $definition->channelCode,
            'product_id' => $item->productId,
            'offer_id' => $item->id,
            'store_category_id' => $storeCategoryId,
            'vocabulary' => FeedCategoryVocabulary::forChannel($definition->channelCode),
        ];
    }
}
