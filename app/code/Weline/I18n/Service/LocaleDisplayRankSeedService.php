<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locale;

/**
 * Backfill i18n_locale.display_rank from curated PHP table (Upgrade seed only).
 */
final class LocaleDisplayRankSeedService
{
    public function seedMissingDisplayRanks(): int
    {
        /** @var Locale $model */
        $model = ObjectManager::getInstance(Locale::class);
        $rows = $model->clearQuery()
            ->fields([
                Locale::schema_fields_CODE,
                Locale::schema_fields_DISPLAY_RANK,
                Locale::schema_fields_COUNTRY_CODE,
            ])
            ->select()
            ->fetchArray();
        if (!\is_array($rows) || $rows === []) {
            return 0;
        }

        $updated = 0;
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = \trim((string)($row[Locale::schema_fields_CODE] ?? ''));
            if ($code === '') {
                continue;
            }
            $current = (int)($row[Locale::schema_fields_DISPLAY_RANK] ?? 0);
            if ($current !== 0) {
                continue;
            }
            $country = (string)($row[Locale::schema_fields_COUNTRY_CODE] ?? '');
            $score = LocaleDisplayRankService::score($code, $country);
            if ($score <= 0) {
                continue;
            }
            try {
                $model->clearData(true)
                    ->load($code)
                    ->setData(Locale::schema_fields_DISPLAY_RANK, $score)
                    ->save();
                $updated++;
            } catch (\Throwable) {
            }
        }

        return $updated;
    }
}
