<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\I18n\Model\Locale;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Runtime\RequestContext;
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
    private const REQUEST_CONTEXT_KEY = 'i18n.installed_active_codes.v1';

    /** @var array<string, list<string>> 进程级 installed+active 列表 */
    private static array $processInstalledActiveCodes = [];

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
        if (RequestContext::has(self::REQUEST_CONTEXT_KEY)) {
            RequestContext::remove(self::REQUEST_CONTEXT_KEY);
        }
    }

    /**
     * @return string[]
     */
    public function getInstalledActiveCodes(): array
    {
        if (RequestContext::has(self::REQUEST_CONTEXT_KEY)) {
            $fromRequest = RequestContext::get(self::REQUEST_CONTEXT_KEY, null);
            if (\is_array($fromRequest)) {
                /** @var list<string> $fromRequest */
                return $fromRequest;
            }
        }

        $cacheKey = DictionaryCacheNamespace::cacheKey('installed-active-locales');
        if (isset(DictionaryCacheNamespace::localCache(self::$processInstalledActiveCodes, 128)[$cacheKey])) {
            $cached = DictionaryCacheNamespace::localCache(self::$processInstalledActiveCodes, 128)[$cacheKey];
            RequestContext::set(self::REQUEST_CONTEXT_KEY, $cached);

            return $cached;
        }

        $codes = [];
        $seen = [];
        foreach ($this->fetchInstalledActiveRows($this->locale) as $code) {
            $this->pushCode($codes, $seen, $code);
        }
        foreach ($this->fetchInstalledActiveRows($this->locals) as $code) {
            $this->pushCode($codes, $seen, $code);
        }

        $codes = DictionaryCacheNamespace::localCache(self::$processInstalledActiveCodes, 128)[$cacheKey] = $codes;
        RequestContext::set(self::REQUEST_CONTEXT_KEY, $codes);

        return $codes;
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
