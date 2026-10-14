<?php

declare(strict_types=1);

namespace Weline\Websites\Service\Localization;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * Backfill website_language.sort_order from platform display ranks when all zeros.
 */
final class WebsiteLanguageSortSeedService
{
    public function seedMissingSortOrders(): int
    {
        /** @var WebsiteLanguage $model */
        $model = ObjectManager::getInstance(WebsiteLanguage::class);
        $rows = $model->clearQuery()
            ->fields([
                WebsiteLanguage::schema_fields_WEBSITE_ID,
                WebsiteLanguage::schema_fields_LANGUAGE_CODE,
                WebsiteLanguage::schema_fields_SORT_ORDER,
                WebsiteLanguage::schema_fields_ID,
            ])
            ->order(WebsiteLanguage::schema_fields_WEBSITE_ID, 'ASC')
            ->order(WebsiteLanguage::schema_fields_SORT_ORDER, 'ASC')
            ->order(WebsiteLanguage::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
        if (!\is_array($rows) || $rows === []) {
            return 0;
        }

        $byWebsite = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $websiteId = (int)($row[WebsiteLanguage::schema_fields_WEBSITE_ID] ?? -1);
            if ($websiteId < 0) {
                continue;
            }
            $byWebsite[$websiteId][] = $row;
        }

        $updated = 0;
        foreach ($byWebsite as $websiteId => $websiteRows) {
            $orders = [];
            foreach ($websiteRows as $row) {
                $orders[] = (int)($row[WebsiteLanguage::schema_fields_SORT_ORDER] ?? 0);
            }
            $unique = \array_values(\array_unique($orders));
            // Only seed when every row is still 0 (pre-feature insert order).
            if (!($unique === [0] && \count($websiteRows) > 0)) {
                continue;
            }
            $codes = [];
            foreach ($websiteRows as $row) {
                $code = \trim((string)($row[WebsiteLanguage::schema_fields_LANGUAGE_CODE] ?? ''));
                if ($code !== '') {
                    $codes[] = $code;
                }
            }
            if ($codes === []) {
                continue;
            }
            $ordered = $this->orderByPlatformRank($codes);
            $model->setWebsiteLanguages((int)$websiteId, $ordered);
            $updated++;
        }

        return $updated;
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private function orderByPlatformRank(array $codes): array
    {
        if (\count($codes) < 2) {
            return \array_values($codes);
        }
        if (\class_exists(\Weline\I18n\Service\LocaleDisplayRankService::class)) {
            try {
                return \Weline\I18n\Service\LocaleDisplayRankService::sortLocaleCodes($codes);
            } catch (\Throwable) {
            }
        }

        return \array_values($codes);
    }
}
