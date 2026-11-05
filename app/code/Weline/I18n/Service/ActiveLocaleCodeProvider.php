<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\I18n\Model\Locale;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\I18n\Model\Locals;

/**
 * Backend platform language set: merge Locals (translation rows) with Locale (install registry).
 * Reading Locals alone drops installed locales that have no Locals row yet.
 *
 * Authority lives in the Worker process bag only — do not mirror the code list into the request bag.
 */
class ActiveLocaleCodeProvider
{
    private const FIELD_IS_INSTALL = 'is_install';
    private const FIELD_IS_ACTIVE = 'is_active';
    private const FIELD_CODE = 'code';

    /** 进程袋身份（显式命名，禁止匿名登记）。 */
    private const BAG_INSTALLED_ACTIVE_CODES = 'i18n.active_locale.codes';

    /** “已安装但未必启用”的列表：同一权威、同一失效点，键与上面分开。 */
    private const BAG_INSTALLED_CODES = 'i18n.installed_locale.codes';

    /** @var array<string, list<string>> 进程级 installed+active 列表 */
    private static array $processInstalledActiveCodes = [];

    /** @var array<string, list<string>> 进程级 installed（不要求 active）列表 */
    private static array $processInstalledCodes = [];

    public function __construct(
        private readonly Locals $locals,
        private readonly Locale $locale,
    ) {
    }

    /**
     * @return array<string, bool>
     */
    public function getInstalledActiveCodeMap(): array
    {
        $allowedLocaleMap = [];
        foreach ($this->getInstalledActiveCodes() as $code) {
            $allowedLocaleMap[$code] = true;
            $allowedLocaleMap[\strtolower($code)] = true;
        }

        return $allowedLocaleMap;
    }

    /**
     * Drop process memo so the next read reloads from Locals/Locale.
     * Called on locale catalog change and process cache reset.
     */
    public function reset(): void
    {
        self::clearProcessCache();
    }

    public static function clearProcessCache(): void
    {
        self::$processInstalledActiveCodes = [];
        self::$processInstalledCodes = [];
    }

    /**
     * @return string[]
     */
    public function getInstalledActiveCodes(): array
    {
        $cacheKey = DictionaryCacheNamespace::processScopeKey('installed-active-locales');
        if ($cacheKey === '') {
            return $this->loadCodesFromDb(true);
        }
        if (isset(DictionaryCacheNamespace::processBag(self::BAG_INSTALLED_ACTIVE_CODES, self::$processInstalledActiveCodes, 128)[$cacheKey])) {
            return DictionaryCacheNamespace::processBag(self::BAG_INSTALLED_ACTIVE_CODES, self::$processInstalledActiveCodes, 128)[$cacheKey];
        }

        $codes = $this->loadCodesFromDb(true);

        return DictionaryCacheNamespace::processBag(self::BAG_INSTALLED_ACTIVE_CODES, self::$processInstalledActiveCodes, 128)[$cacheKey] = $codes;
    }

    /**
     * Installed locales regardless of the active flag.
     *
     * Same authority, same memo, same invalidation point as
     * {@see self::getInstalledActiveCodes()}; callers that need "installed but
     * maybe disabled" (e.g. the storefront render-context catalogue) must not
     * open a second read path onto `Locals`.
     *
     * @return list<string>
     */
    public function getInstalledCodes(): array
    {
        $cacheKey = DictionaryCacheNamespace::processScopeKey('installed-locales');
        if ($cacheKey === '') {
            return $this->loadCodesFromDb(false);
        }
        if (isset(DictionaryCacheNamespace::processBag(self::BAG_INSTALLED_CODES, self::$processInstalledCodes, 128)[$cacheKey])) {
            return DictionaryCacheNamespace::processBag(self::BAG_INSTALLED_CODES, self::$processInstalledCodes, 128)[$cacheKey];
        }

        $codes = $this->loadCodesFromDb(false);

        return DictionaryCacheNamespace::processBag(self::BAG_INSTALLED_CODES, self::$processInstalledCodes, 128)[$cacheKey] = $codes;
    }

    /**
     * Single UNION ALL round-trip (locale then locals); fall back to dual fetchArray.
     *
     * @return list<string>
     */
    private function loadCodesFromDb(bool $activeOnly): array
    {
        $codes = [];
        $seen = [];
        try {
            foreach ($this->fetchCodesUnion($activeOnly) as $code) {
                $this->pushCode($codes, $seen, $code);
            }

            return $codes;
        } catch (\Throwable) {
            // Single-table absence / driver quirks: keep dual query-builder path.
        }

        foreach ($this->fetchRows($this->locale, $activeOnly) as $code) {
            $this->pushCode($codes, $seen, $code);
        }
        foreach ($this->fetchRows($this->locals, $activeOnly) as $code) {
            $this->pushCode($codes, $seen, $code);
        }

        return $codes;
    }

    /**
     * @return list<string>
     */
    private function fetchCodesUnion(bool $activeOnly): array
    {
        if (!\method_exists($this->locale, 'getConnection') || !\method_exists($this->locale, 'getTable')
            || !\method_exists($this->locals, 'getTable')
        ) {
            throw new \RuntimeException('ActiveLocale models lack getTable/getConnection');
        }

        $localeTable = \trim((string)$this->locale->getTable());
        $localsTable = \trim((string)$this->locals->getTable());
        if ($localeTable === '' || $localsTable === '') {
            throw new \RuntimeException('ActiveLocale table names empty');
        }

        $connector = $this->locale->getConnection()->getConnector();
        $filter = ' WHERE (' . self::FIELD_IS_INSTALL . ' = 1)'
            . ($activeOnly ? ' AND (' . self::FIELD_IS_ACTIVE . ' = 1)' : '');
        // Locale rows first, then Locals — PHP pushCode keeps first-seen casing/order.
        $sql = 'SELECT ' . self::FIELD_CODE . ' FROM ' . $localeTable . $filter
            . ' UNION ALL '
            . 'SELECT ' . self::FIELD_CODE . ' FROM ' . $localsTable . $filter;

        $codes = [];
        foreach ($connector->query($sql)->fetchIterator() as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = \trim((string)($row[self::FIELD_CODE] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param Locals|Locale $model
     * @return list<string>
     */
    private function fetchRows(Locals|Locale $model, bool $activeOnly): array
    {
        try {
            $query = $model->clearQuery()->where(self::FIELD_IS_INSTALL, 1);
            if ($activeOnly) {
                $query->where(self::FIELD_IS_ACTIVE, 1);
            }
            $rows = $query->select(self::FIELD_CODE)->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $codes = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = \trim((string)($row[self::FIELD_CODE] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param list<string> $codes
     * @param array<string, true> $seen
     */
    private function pushCode(array &$codes, array &$seen, string $code): void
    {
        $code = \trim($code);
        if ($code === '') {
            return;
        }
        $key = \strtolower($code);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $codes[] = $code;
    }
}
