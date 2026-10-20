<?php

declare(strict_types=1);

namespace Weline\I18n\Service\Repository;

use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\I18n\Api\Localization\CountryRepositoryInterface;
use Weline\I18n\Api\Localization\Data\CountryRecord;
use Weline\I18n\Model\Countries;
use Weline\I18n\Model\Countries\Locale\Name;

final class CountryRepository implements CountryRepositoryInterface
{
    /** 进程袋身份（显式命名，禁止匿名登记）。 */
    private const BAG_COUNTRY_ROWS = 'i18n.repository.country.rows';

    /** @var array<string, list<CountryRecord>> Worker 进程级国家记录，按 displayLocale + activeOnly 分区 */
    private static array $processRows = [];

    public function __construct(
        private readonly Countries $countries,
        private readonly Name $names,
    ) {
    }

    /**
     * 清空进程级缓存：国家安装/启停后由 locale 目录观察者调用。
     */
    public static function clearProcessCache(): void
    {
        self::$processRows = [];
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
        $cacheKey = DictionaryCacheNamespace::cacheKey(
            'countries|' . ($activeOnly ? 'active' : 'installed') . '|' . $displayLocale,
            [$displayLocale],
        );
        $bag = &DictionaryCacheNamespace::processBag(
            self::BAG_COUNTRY_ROWS,
            self::$processRows,
            16,
            [$displayLocale],
        );
        if (isset($bag[$cacheKey])) {
            return $bag[$cacheKey];
        }

        $records = $this->loadRecords($displayLocale, $activeOnly);
        $bag[$cacheKey] = $records;

        return $records;
    }

    /** @return list<CountryRecord> */
    private function loadRecords(string $displayLocale, bool $activeOnly): array
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
