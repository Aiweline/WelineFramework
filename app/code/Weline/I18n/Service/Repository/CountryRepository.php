<?php

declare(strict_types=1);

namespace Weline\I18n\Service\Repository;

use Weline\I18n\Api\Localization\CountryRepositoryInterface;
use Weline\I18n\Api\Localization\Data\CountryRecord;
use Weline\I18n\Model\Countries;
use Weline\I18n\Model\Countries\Locale\Name;

final class CountryRepository implements CountryRepositoryInterface
{
    public function __construct(
        private readonly Countries $countries,
        private readonly Name $names,
    ) {
    }

    public function installed(string $displayLocale): array
    {
        return $this->records($displayLocale, false);
    }

    public function installedActive(string $displayLocale): array
    {
        return $this->records($displayLocale, true);
    }

    /** @return list<CountryRecord> */
    private function records(string $displayLocale, bool $activeOnly): array
    {
        $query = $this->countries->reset()
            ->where(Countries::schema_fields_IS_INSTALL, 1);
        if ($activeOnly) {
            $query->where(Countries::schema_fields_IS_ACTIVE, 1);
        }

        $rows = $query->order(Countries::schema_fields_CODE, 'ASC')
            ->select()
            ->fetchArray();
        $codes = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row[Countries::schema_fields_CODE] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        if ($codes === []) {
            return [];
        }

        $nameRows = $this->names->reset()
            ->where(Name::schema_fields_COUNTRY_CODE, $codes, 'IN')
            ->where(Name::schema_fields_DISPLAY_LOCALE_CODE, $displayLocale)
            ->select()
            ->fetchArray();
        $nameByCode = [];
        foreach ($nameRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row[Name::schema_fields_COUNTRY_CODE] ?? ''));
            if ($code !== '') {
                $nameByCode[$code] = (string)($row[Name::schema_fields_DISPLAY_NAME] ?? $code);
            }
        }

        $records = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row[Countries::schema_fields_CODE] ?? ''));
            if ($code === '') {
                continue;
            }
            $records[] = new CountryRecord(
                code: $code,
                displayLocale: $displayLocale,
                displayName: $nameByCode[$code] ?? $code,
                flag: (string)($row[Countries::schema_fields_FLAG] ?? ''),
                active: (bool)($row[Countries::schema_fields_IS_ACTIVE] ?? false),
                installed: (bool)($row[Countries::schema_fields_IS_INSTALL] ?? false),
            );
        }

        return $records;
    }
}
