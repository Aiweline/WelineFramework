<?php

declare(strict_types=1);

namespace Weline\Feed\Service\Orm;

use Weline\Feed\Api\FeedCategoryMapReaderInterface;
use Weline\Feed\Model\FeedCategoryMap;
use Weline\Framework\Manager\ObjectManager;

final class OrmFeedCategoryMapReader implements FeedCategoryMapReaderInterface
{
    public function mapsForWebsite(int $websiteId): array
    {
        if ($websiteId < 0) {
            return [];
        }
        $model = ObjectManager::getInstance(FeedCategoryMap::class);
        if (!$model instanceof FeedCategoryMap) {
            throw new \RuntimeException('feed_category_map_model_missing');
        }
        $rows = $model->clear()
            ->where(FeedCategoryMap::schema_fields_WEBSITE_ID, $websiteId)
            ->select()
            ->fetchArray();
        $maps = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $maps[] = [
                'website_id' => (int)($row[FeedCategoryMap::schema_fields_WEBSITE_ID] ?? 0),
                'vocabulary' => trim((string)($row[FeedCategoryMap::schema_fields_VOCABULARY] ?? '')),
                'store_category_id' => (int)($row[FeedCategoryMap::schema_fields_STORE_CATEGORY_ID] ?? 0),
                'map_value' => trim((string)($row[FeedCategoryMap::schema_fields_MAP_VALUE] ?? '')),
                'map_label' => trim((string)($row[FeedCategoryMap::schema_fields_MAP_LABEL] ?? '')),
            ];
        }

        return $maps;
    }
}
