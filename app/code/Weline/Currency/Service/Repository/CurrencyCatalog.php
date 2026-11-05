<?php

declare(strict_types=1);

namespace Weline\Currency\Service\Repository;

use Weline\Currency\Api\CurrencyCatalogInterface;
use Weline\Currency\Api\Data\CurrencyRecord;
use Weline\Currency\Data\CurrencyData;
use Weline\Currency\Model\Currency;

/**
 * Projection of {@see CurrencyData}'s active-currency authority into the
 * cross-module {@see CurrencyRecord} DTO.
 *
 * This class deliberately owns no query and no cache. "Which currencies are
 * active" is one fact with one read path (CurrencyData: process memo →
 * `w_cache('currency')` → DB + local-description join), so the storefront
 * switcher, the taglib select and every Api consumer observe the same members,
 * the same localised names and the same ordering. Keeping a second
 * `status = 1` query here previously produced rows whose names were never
 * localised, which made two currency pickers on the same page disagree.
 */
final class CurrencyCatalog implements CurrencyCatalogInterface
{
    public function active(): array
    {
        $records = [];
        foreach (CurrencyData::getCurrencies() as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = \trim((string)($row[Currency::schema_fields_CODE] ?? ''));
            if ($code === '') {
                continue;
            }
            $records[] = new CurrencyRecord(
                code: $code,
                name: (string)($row[Currency::schema_fields_NAME] ?? $code),
                symbol: (string)($row[Currency::schema_fields_SYMBOL] ?? ''),
                active: true,
                format: (string)($row[Currency::schema_fields_FORMAT] ?? '1,0'),
                position: (string)($row[Currency::schema_fields_POSITION] ?? 'left'),
                rate: (float)($row[Currency::schema_fields_RATE] ?? 0.0),
            );
        }

        // Deterministic order for select widgets (the previous contract: code ASC).
        \usort(
            $records,
            static fn(CurrencyRecord $left, CurrencyRecord $right): int => $left->code <=> $right->code,
        );

        return $records;
    }

    /**
     * Kept for the Currency model change hook and ProcessCacheResetter.
     *
     * The rows live in CurrencyData's process memo, so clearing that is the
     * whole job; a second private cache here would recreate exactly the drift
     * this class was collapsed to remove.
     */
    public static function clearProcessCache(): void
    {
        CurrencyData::clearProcessCache();
    }
}
