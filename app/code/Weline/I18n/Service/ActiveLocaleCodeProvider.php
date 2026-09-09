<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\I18n\Model\Locale;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\I18n\Model\Locals;

/**
 * Backend platform language set: merge Locals (translation rows) with Locale (install registry).
 * Reading Locals alone drops installed locales that have no Locals row yet.
 */
class ActiveLocaleCodeProvider
{
    private const FIELD_IS_INSTALL = 'is_install';
    private const FIELD_IS_ACTIVE = 'is_active';
    private const FIELD_CODE = 'code';

    /** @var array<string, list<string>> */
    private array $installedActiveCodes = [];

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
     * Drop request/process memo so the next read reloads from Locals/Locale.
     */
    public function reset(): void
    {
        $this->installedActiveCodes = [];
    }

    /**
     * @return string[]
     */
    public function getInstalledActiveCodes(): array
    {
        $cacheKey = DictionaryCacheNamespace::cacheKey('installed-active-locales');
        if (isset(DictionaryCacheNamespace::localCache($this->installedActiveCodes, 128)[$cacheKey])) {
            return DictionaryCacheNamespace::localCache($this->installedActiveCodes, 128)[$cacheKey];
        }

        $codes = [];
        $seen = [];
        foreach ($this->fetchInstalledActiveRows($this->locale) as $code) {
            $this->pushCode($codes, $seen, $code);
        }
        foreach ($this->fetchInstalledActiveRows($this->locals) as $code) {
            $this->pushCode($codes, $seen, $code);
        }

        return DictionaryCacheNamespace::localCache($this->installedActiveCodes, 128)[$cacheKey] = $codes;
    }

    /**
     * @param Locals|Locale $model
     * @return list<string>
     */
    private function fetchInstalledActiveRows(Locals|Locale $model): array
    {
        try {
            $rows = $model->clearQuery()
                ->where(self::FIELD_IS_INSTALL, 1)
                ->where(self::FIELD_IS_ACTIVE, 1)
                ->select(self::FIELD_CODE)
                ->fetchArray();
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
