<?php

namespace Weline\I18n\Model;

use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Intl\Locales;
use Weline\CacheManager\Api\RuntimeCachePolicy;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Registry\Service\RegistryProgress;
use Weline\Framework\System\File\Data\File;
use Weline\I18n\Config\Reader;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\I18n\Service\TranslationCollector;

class I18n
{
    private const MODULE_COLLECTION_FIBER_LIMIT = 6;
    private const LOCALE_CACHE_TTL = 300;
    private const FALLBACK_LOCALE_NAMES = [
        'en' => 'English',
        'en_US' => 'English (United States)',
        'zh_Hans_CN' => 'Chinese (Simplified, China)',
        'zh_Hant_TW' => 'Chinese (Traditional, Taiwan)',
        'ja_JP' => 'Japanese (Japan)',
        'ko_KR' => 'Korean (South Korea)',
        'de_DE' => 'German (Germany)',
        'fr_FR' => 'French (France)',
        'es_ES' => 'Spanish (Spain)',
        'it_IT' => 'Italian (Italy)',
        'pt_BR' => 'Portuguese (Brazil)',
        'ru_RU' => 'Russian (Russia)',
        'nl_NL' => 'Dutch (Netherlands)',
    ];

    private const FALLBACK_LANGUAGE_SELF_NAMES = [
        'en' => 'English',
        'zh' => '中文',
        'zh_Hans' => '简体中文',
        'zh_Hant' => '繁體中文',
        'ja' => '日本語',
        'ko' => '한국어',
        'de' => 'Deutsch',
        'fr' => 'Français',
        'es' => 'Español',
        'it' => 'Italiano',
        'pt' => 'Português',
        'ru' => 'Русский',
        'nl' => 'Nederlands',
    ];

    private const FALLBACK_COUNTRY_NAMES = [
        'AR' => 'Argentina',
        'AU' => 'Australia',
        'BE' => 'Belgium',
        'BR' => 'Brazil',
        'CA' => 'Canada',
        'CH' => 'Switzerland',
        'CN' => 'China',
        'DE' => 'Germany',
        'DK' => 'Denmark',
        'ES' => 'Spain',
        'FI' => 'Finland',
        'FR' => 'France',
        'GB' => 'United Kingdom',
        'IN' => 'India',
        'IT' => 'Italy',
        'JP' => 'Japan',
        'KR' => 'South Korea',
        'MX' => 'Mexico',
        'NL' => 'Netherlands',
        'NO' => 'Norway',
        'RU' => 'Russia',
        'SE' => 'Sweden',
        'TW' => 'Taiwan',
        'US' => 'United States',
    ];

    private static array $local_words = [];
    /** @var array{expires_at: float, value: string[]}|null */
    private static ?array $availableLocaleCodesCache = null;
    /** @var array<string, array{expires_at: float, value: string}> */
    private static array $localByCodeCache = [];
    private Reader $reader;
    public CachePoolInterface $i18nCache;

    public function __construct(
        Reader $reader
    ) {
        $this->reader = $reader;
        $this->i18nCache = w_cache('i18n');
    }

    public function getAvailableLocaleCodes(): array
    {
        if (self::$availableLocaleCodesCache !== null
            && self::$availableLocaleCodesCache['expires_at'] >= microtime(true)) {
            return self::$availableLocaleCodesCache['value'];
        }

        if (class_exists(Locales::class)) {
            try {
                return $this->rememberAvailableLocaleCodes(Locales::getLocales());
            } catch (\Throwable) {
            }
        }

        return $this->rememberAvailableLocaleCodes(array_keys(self::FALLBACK_LOCALE_NAMES));
    }

    public function getLocaleNames(string $displayLocale = 'zh_Hans_CN'): array
    {
        $displayLocale = $this->normalizeIntlDisplayLocale($displayLocale);
        if (class_exists(Locales::class)) {
            try {
                return Locales::getNames($displayLocale);
            } catch (\Throwable) {
                try {
                    return Locales::getNames('en');
                } catch (\Throwable) {
                }
            }
        }

        return self::FALLBACK_LOCALE_NAMES;
    }

    private function normalizeIntlDisplayLocale(string $locale): string
    {
        $locale = $this->normalizeLocaleCode($locale);
        return extension_loaded('intl') ? ($locale !== '' ? $locale : 'en') : 'en';
    }

    private function normalizeLocaleCode(string $localeCode): string
    {
        $localeCode = trim(str_replace('-', '_', $localeCode));
        if ($localeCode === '') {
            return '';
        }

        $parts = explode('_', $localeCode);
        foreach ($parts as $index => $part) {
            $part = trim((string)$part);
            if ($part === '') {
                unset($parts[$index]);
                continue;
            }

            if ($index === 0) {
                $parts[$index] = strtolower($part);
                continue;
            }

            if (strlen($part) === 2 && preg_match('/^[a-zA-Z]{2}$/', $part) === 1) {
                $parts[$index] = strtoupper($part);
                continue;
            }

            if (strlen($part) === 4 && preg_match('/^[a-zA-Z]{4}$/', $part) === 1) {
                $parts[$index] = ucfirst(strtolower($part));
            }
        }

        return implode('_', array_values($parts));
    }

    private function getLocaleNameFromProvider(string $localeCode, string $displayLocale): string
    {
        $displayLocale = $this->normalizeIntlDisplayLocale($displayLocale);
        if (class_exists(Locales::class)) {
            try {
                return Locales::getName($localeCode, $displayLocale);
            } catch (\Throwable) {
            }
        }

        return self::FALLBACK_LOCALE_NAMES[$localeCode] ?? $localeCode;
    }

    private function countryExists(string $countryCode): bool
    {
        $countryCode = strtoupper($countryCode);
        if (class_exists(Countries::class)) {
            try {
                return Countries::exists($countryCode);
            } catch (\Throwable) {
            }
        }

        return isset(self::FALLBACK_COUNTRY_NAMES[$countryCode]);
    }

    private function getCountryName(string $countryCode, string $displayLocale = 'en'): string
    {
        $countryCode = strtoupper($countryCode);
        if (class_exists(Countries::class)) {
            try {
                return Countries::getName($countryCode, $this->normalizeIntlDisplayLocale($displayLocale));
            } catch (\Throwable) {
            }
        }

        return self::FALLBACK_COUNTRY_NAMES[$countryCode] ?? $countryCode;
    }

    public function getLocalByCode(string $locale_code): string
    {
        $cacheKey = strtolower(trim($locale_code));
        if (isset(self::$localByCodeCache[$cacheKey])
            && self::$localByCodeCache[$cacheKey]['expires_at'] >= microtime(true)) {
            return self::$localByCodeCache[$cacheKey]['value'];
        }
        unset(self::$localByCodeCache[$cacheKey]);

        if ($data = $this->i18nCache->get($locale_code)) {
            return $this->rememberLocalByCode($cacheKey, (string)$data);
        }
        $locales = $this->getAvailableLocaleCodes();
        foreach ($locales as $locale) {
            if (strtolower($locale_code) === strtolower($locale)) {
                $this->i18nCache->set($locale_code, $locale);
                return $this->rememberLocalByCode($cacheKey, $locale);
            }
        }
        $this->i18nCache->set($locale_code, 'zh_Hans_CN');
        return $this->rememberLocalByCode($cacheKey, 'zh_Hans_CN');
    }

    /**
     * @param string[] $codes
     * @return string[]
     */
    private function rememberAvailableLocaleCodes(array $codes): array
    {
        self::$availableLocaleCodesCache = [
            'expires_at' => microtime(true) + $this->localeCacheTtl(),
            'value' => $codes,
        ];

        return $codes;
    }

    private function rememberLocalByCode(string $cacheKey, string $locale): string
    {
        self::$localByCodeCache[$cacheKey] = [
            'expires_at' => microtime(true) + $this->localeCacheTtl(),
            'value' => $locale,
        ];

        return $locale;
    }

    private function localeCacheTtl(): int
    {
        try {
            /** @var RuntimeCachePolicy $policy */
            $policy = ObjectManager::getInstance(RuntimeCachePolicy::class);
            return $policy->ttl('site.i18n_locale_ttl', self::LOCALE_CACHE_TTL);
        } catch (\Throwable) {
            return self::LOCALE_CACHE_TTL;
        }
    }

    public function getLocals(string $lang_code = 'zh_Hans_CN'): array
    {
        // 未安装 intl 时 Symfony Polyfill 仅支持 en，传 zh_Hans_CN 会抛错，降级为 en
        $lang_code = $this->normalizeIntlDisplayLocale($lang_code);
        $cache_key = 'getLocals' . $lang_code;
        if ($data = $this->i18nCache->get($cache_key)) {
            return $data;
        }
        $locals = $this->getLocaleNames($lang_code);
        $this->i18nCache->set($cache_key, $locals);
        return $locals;
    }

    public function getLocaleName(string $locale_code, string $displace_locale_code = 'zh_Hans_CN'): string
    {
        $name = $locale_code;
        if ($this->localeExists($locale_code)) {
            $name = $this->getLocaleNameFromProvider($locale_code, $displace_locale_code);
        }
        return $name;
    }

    /**
     * 返回语码对应语言在其自身语言下的名称（如 zh_Hans_CN -> 简体中文，en_US -> English）。
     * 与 getLocaleName($code, $websiteLocale) 不同，后者是当前网站界面语言下的 locale 全称。
     */
    public function getLocaleLanguageSelfName(string $localeCode): string
    {
        $localeCode = trim($localeCode);
        if ($localeCode === '') {
            return '';
        }

        $languageTag = $this->extractLanguageTagFromLocaleCode($localeCode);
        if ($languageTag === '') {
            return $localeCode;
        }

        if (class_exists(Languages::class)) {
            try {
                return Languages::getName($languageTag, $languageTag);
            } catch (\Throwable) {
                $baseLanguage = explode('_', $languageTag)[0] ?? '';
                if ($baseLanguage !== '') {
                    try {
                        return Languages::getName($baseLanguage, $baseLanguage);
                    } catch (\Throwable) {
                    }
                }
            }
        }

        return self::FALLBACK_LANGUAGE_SELF_NAMES[$languageTag]
            ?? self::FALLBACK_LANGUAGE_SELF_NAMES[explode('_', $languageTag)[0] ?? '']
            ?? $this->getLocaleName($localeCode, $localeCode);
    }

    private function extractLanguageTagFromLocaleCode(string $localeCode): string
    {
        $parts = explode('_', trim($localeCode));
        $language = strtolower((string)($parts[0] ?? ''));
        if ($language === '') {
            return '';
        }

        $second = (string)($parts[1] ?? '');
        if ($second !== '' && strlen($second) !== 2) {
            return $language . '_' . $second;
        }

        return $language;
    }

    public function getLocalesWithFlags(int $width = 24, int $height = 18, string $lang_code = 'zh_Hans_CN', bool $installed = true)
    {
        $lang_code = $this->normalizeIntlDisplayLocale($lang_code);
        $cache_key = 'getLocalesWithFlags' . $lang_code . $width . $height . (string)$installed;
        if ($data = $this->i18nCache->get($cache_key)) {
            return $data;
        }
        
        $install_packs = [];
        if ($installed) {
            $install_packs_path = glob(Env::path_LANGUAGE_PACK . '*' . DS . '*', GLOB_ONLYDIR);
            foreach ($install_packs_path as $path) {
                $path_arr = explode(DS, $path);
                $install_packs[] = array_pop($path_arr);
            }
        }

        $locals = [];
        $lang_locals = $this->getLocals($lang_code);
        $allLocales = $this->getAvailableLocaleCodes();
        
        foreach ($allLocales as $locale) {
            if ($installed && !in_array($locale, $install_packs)) {
                continue;
            }
            if (!isset($lang_locals[$locale])) {
                continue;
            }

            $countryCode = $this->getCountryCodeFromLocale($locale);
            if (!$countryCode) continue;

            $svg = $this->getCountryFlag($countryCode, $width, $height);
            if ($svg) {
                $locals[$locale] = ['name' => $lang_locals[$locale], 'flag' => $svg];
            }
        }
        
        $this->i18nCache->set($cache_key, $locals, 0);
        return $locals;
    }

    public function getLocalesWithFlagsDisplaySelf(string $display_locale_code = 'zh_Hans_CN', int $width = 24, int $height = 18, bool $installed = true, bool $autoSize = false)
    {
        $default_width = 24;
        $default_height = 18;
        
        // 如果width或height为0，使用默认值
        if ($width <= 0) $width = $default_width;
        if ($height <= 0) $height = $default_height;
        
        $cache_key = 'getLocalesWithFlagsDisplaySelf' . $width . $height . (string)$installed . (string)$autoSize . $display_locale_code;
        if ($data = $this->i18nCache->get($cache_key)) {
            return $data;
        }

        $install_packs = [];
        if ($installed) {
            $install_packs_path = glob(Env::path_LANGUAGE_PACK . '*' . DS . '*', GLOB_ONLYDIR);
            foreach ($install_packs_path as $path) {
                $path_arr = explode(DS, $path);
                $install_packs[] = array_pop($path_arr);
            }
        }

        $locals = [];
        $lang_locals = $this->getLocals();
        $allLocales = $this->getAvailableLocaleCodes();
        
        // 收集所有需要获取的国家代码
        $countryCodes = [];
        $localeToCountryMap = [];
        foreach ($allLocales as $locale) {
            if ($installed && !in_array($locale, $install_packs)) {
                continue;
            }
            if (!isset($lang_locals[$locale])) {
                continue;
            }

            $countryCode = $this->getCountryCodeFromLocale($locale);
            if (!$countryCode) continue;
            
            $countryCodes[] = $countryCode;
            $localeToCountryMap[$locale] = $countryCode;
        }
        
        // 批量获取国旗SVG
        $flags = $this->getCountryFlagsBatch(array_unique($countryCodes), $width, $height, $autoSize);

        // 组装结果
        foreach ($allLocales as $locale) {
            if ($installed && !in_array($locale, $install_packs)) {
                continue;
            }
            if (!isset($lang_locals[$locale])) {
                continue;
            }

            $countryCode = $localeToCountryMap[$locale] ?? null;
            if (!$countryCode) continue;

            $svg = $flags[$countryCode] ?? '';
            if ($svg) {
                if ($display_locale_code === $locale) {
                    $name = $this->getLocaleName($locale, $locale);
                } else {
                    $name = $this->getLocaleName($locale, $display_locale_code) . "({$this->getLocaleName($locale, $locale)})";
                }
                $locals[$locale] = ['name' => $name, 'flag' => $svg];
            }
        }
        $this->i18nCache->set($cache_key, $locals, 0);
        return $locals;
    }

    public function getCountryFlagWithLocal(string $local_code = 'zh_Hans_CN', int $width = 24, int $height = 18): array
    {
        $localeCode = $this->normalizeLocaleCode($local_code);
        if ($localeCode === '') {
            return [];
        }

        $cache_key = 'getCountryFlagWithLocal' . $localeCode . $width . $height;
        if ($data = $this->i18nCache->get($cache_key)) {
            if (is_array($data)) {
                return $data;
            }
        }

        $lang_locals = $this->getLocals($localeCode);
        $countryCode = $this->getCountryCodeFromLocale($localeCode);
        
        if ($countryCode) {
            $svg = $this->getCountryFlag($countryCode, $width, $height);
            if ($svg) {
                $local = ['name' => $lang_locals[$localeCode] ?? $localeCode, 'flag' => $svg];
                $this->i18nCache->set($cache_key, $local, 0);
                return $local;
            }
        }

        $this->i18nCache->set($cache_key, [], 0);
        return [];
    }

    /**
     * @return array<string, string>
     */
    private function getActiveModuleDirectories(?string $moduleName = null): array
    {
        $directories = [];
        foreach (Env::getInstance()->getActiveModules() as $module) {
            if ($moduleName !== null && $module['name'] !== $moduleName) {
                continue;
            }
            $directories[$module['name']] = $module['base_path'];
        }

        return $directories;
    }

    /**
     * @param array<string, string> $directories
     * @return array<string, string>
     */
    private function collectModuleTranslations(array $directories, TranslationCollector $collector): array
    {
        if ($directories === []) {
            return [];
        }

        if (!class_exists(\Fiber::class) || count($directories) <= 1) {
            return $this->collectModuleTranslationsSerial($directories, $collector);
        }

        $translations = [];
        foreach (array_chunk($directories, self::MODULE_COLLECTION_FIBER_LIMIT, true) as $batch) {
            foreach ($this->collectModuleTranslationsFiberBatch($batch, $collector) as $word => $translation) {
                $translations[$word] = $translation;
            }
        }

        return $translations;
    }

    /**
     * @param array<string, string> $directories
     * @return array<string, string>
     */
    private function collectModuleTranslationsSerial(array $directories, TranslationCollector $collector): array
    {
        $translations = [];
        $total = count($directories);
        $index = 0;
        foreach ($directories as $module => $directory) {
            $index++;
            RegistryProgress::module('I18n source module scan', $index, $total, (string)$module);
            foreach ($this->collectSingleModuleTranslations($module, $directory, $collector) as $word => $translation) {
                $translations[$word] = $translation;
            }
        }

        return $translations;
    }

    /**
     * @param array<string, string> $directories
     * @return array<string, string>
     */
    private function collectModuleTranslationsFiberBatch(array $directories, TranslationCollector $collector): array
    {
        /** @var array<string, \Fiber> $fibers */
        $fibers = [];
        $results = [];
        $errors = [];
        $settled = [];
        $total = count($directories);
        $index = 0;

        foreach ($directories as $module => $directory) {
            $index++;
            RegistryProgress::module('I18n source module scan', $index, $total, (string)$module);
            $fibers[$module] = new \Fiber(function () use ($module, $directory, $collector): array {
                return $this->collectSingleModuleTranslations($module, $directory, $collector);
            });
        }

        foreach ($fibers as $module => $fiber) {
            try {
                $fiber->start();
                if ($fiber->isTerminated()) {
                    $results[$module] = $fiber->getReturn();
                    $settled[$module] = true;
                }
            } catch (\Throwable $throwable) {
                $errors[$module] = $throwable;
                $settled[$module] = true;
            }
        }

        while (count($settled) < count($fibers)) {
            $madeProgress = false;

            foreach ($fibers as $module => $fiber) {
                if (isset($settled[$module])) {
                    continue;
                }

                try {
                    if ($fiber->isSuspended()) {
                        $fiber->resume();
                        $madeProgress = true;
                    }

                    if ($fiber->isTerminated()) {
                        $results[$module] = $fiber->getReturn();
                        $settled[$module] = true;
                        $madeProgress = true;
                    }
                } catch (\Throwable $throwable) {
                    $errors[$module] = $throwable;
                    $settled[$module] = true;
                    $madeProgress = true;
                }
            }

            if (!$madeProgress) {
                break;
            }
        }

        if ($errors !== []) {
            $firstError = reset($errors);
            if ($firstError instanceof \Throwable) {
                throw $firstError;
            }
        }

        $translations = [];
        foreach ($results as $moduleTranslations) {
            foreach ($moduleTranslations as $word => $translation) {
                $translations[$word] = $translation;
            }
        }

        return $translations;
    }

    /**
     * @return array<string, string>
     */
    private function collectSingleModuleTranslations(string $module, string $directory, TranslationCollector $collector): array
    {
        $moduleWords = [];
        foreach ($collector->collectLazy($directory, $module) as $original => $info) {
            $moduleWords[$original] = $original;
        }

        $this->refreshModuleLanguageCsvFiles($directory, $moduleWords);
        return $moduleWords;
    }

    /**
     * @param array<string, string> $moduleWords
     */
    private function refreshModuleLanguageCsvFiles(string $directory, array $moduleWords): void
    {
        $i18nDir = $directory . '/i18n';
        if (!is_dir($i18nDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($i18nDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'csv') {
                continue;
            }

            $fileWords = $this->readModuleLanguageCsvFile($file->getPathname());
            $fileTranslations = [];
            foreach ($moduleWords as $key => $defaultValue) {
                $value = $fileWords[$key] ?? $defaultValue;
                if (self::isLikelyCorruptedTranslation($value)) {
                    $value = $defaultValue;
                }
                $fileTranslations[$key] = $value;
            }

            $this->writeModuleLanguageCsvFile($file->getPathname(), $fileTranslations);
        }
    }

    /**
     * @return array<string, string>
     */
    private function readModuleLanguageCsvFile(string $filePath): array
    {
        $translations = [];
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            return $translations;
        }

        $line = 1;
        while (($data = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
            $data = $this->normalizeCsvRow($data, $line);
            $line += 1;

            if ($this->isEffectivelyEmptyCsvRow($data)) {
                continue;
            }

            if (!isset($data[0], $data[1]) || $data[0] === '') {
                continue;
            }

            $translations[$data[0]] = $data[1];
        }

        fclose($handle);
        return $translations;
    }

    /**
     * @param array<string, string> $translations
     */
    private function writeModuleLanguageCsvFile(string $filePath, array $translations): void
    {
        $csvFile = @fopen($filePath, 'w+');
        if ($csvFile === false) {
            return;
        }

        if ($translations !== []) {
            fwrite($csvFile, "\xEF\xBB\xBF");
            foreach ($translations as $key => $value) {
                fputcsv($csvFile, [$key, $value], ',', '"', '\\');
            }
        }

        fclose($csvFile);
    }

    /**
     * @param array<int, mixed> $data
     * @return array<int, mixed>
     */
    private function normalizeCsvRow(array $data, int $line): array
    {
        foreach ($data as $index => $value) {
            if (!is_string($value)) {
                continue;
            }

            $data[$index] = $this->normalizeCsvCell($value, $line === 1 && $index === 0);
        }

        return $data;
    }

    /**
     * @param array<int, mixed> $data
     */
    private function isEffectivelyEmptyCsvRow(array $data): bool
    {
        foreach ($data as $index => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $normalized = $this->normalizeCsvCell((string)$value, (int)$index === 0);
            if ($normalized !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeCsvCell(string $value, bool $stripBom = false): string
    {
        if ($stripBom) {
            $value = $this->stripUtf8Bom($value);
        }

        return trim($value);
    }

    private function stripUtf8Bom(string $value): string
    {
        if (strncmp($value, "\xEF\xBB\xBF", 3) === 0) {
            return substr($value, 3);
        }

        return preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
    }

    /**
     * 批量获取多个国家的国旗SVG
     * 
     * @param array $country_codes 国家代码数组
     * @param int $width 宽度，0表示使用默认值
     * @param int $height 高度，0表示使用默认值
     * @param bool $autoSize 是否自适应
     * @return array 返回 ['country_code' => 'svg_content'] 格式的数组
     */
    public function getCountryFlagsBatch(array $country_codes, int $width = 24, int $height = 18, bool $autoSize = false): array
    {
        $default_width = 24;
        $default_height = 18;
        
        // 如果width或height为0，使用默认值
        if ($width <= 0) $width = $default_width;
        if ($height <= 0) $height = $default_height;
        
        $results = [];
        $cache_prefix = 'flag_' . $width . '_' . $height . '_' . ($autoSize ? 'auto' : 'fixed') . '_';
        
        // 批量检查缓存
        $uncached_codes = [];
        foreach ($country_codes as $code) {
            $cache_key = $cache_prefix . strtolower($code);
            $cached = $this->i18nCache->get($cache_key);
            if ($cached !== false && $cached !== null) {
                $results[$code] = $cached;
            } else {
                $uncached_codes[] = $code;
            }
        }
        
        // 批量处理未缓存的
        if (!empty($uncached_codes)) {
            foreach ($uncached_codes as $code) {
                $flag = $this->getCountryFlag($code, $width, $height, $autoSize);
                $results[$code] = $flag;
                // 缓存结果
                $cache_key = $cache_prefix . strtolower($code);
                $this->i18nCache->set($cache_key, $flag, 3600);
            }
        }
        
        return $results;
    }

    public function getCountryFlag(string $country_code = 'CN', int $width = 24, int $height = 18, bool $autoSize = false): string
    {
        $default_width = 24;
        $default_height = 18;
        
        // 如果width或height为0，使用默认值
        if ($width <= 0) $width = $default_width;
        if ($height <= 0) $height = $default_height;
        
        $country_code = strtolower($country_code);
        $cache_key = 'flag_' . $country_code . '_' . $width . '_' . $height . '_' . ($autoSize ? 'auto' : 'fixed');
        
        // 检查缓存
        if ($cached = $this->i18nCache->get($cache_key)) {
            return $cached;
        }
        
        $flag_path = BP . 'vendor' . DS . 'lipis' . DS . 'flag-icons' . DS . 'flags' . DS . '4x3' . DS . $country_code . '.svg';
        
        // 从本地文件获取
        if (!file_exists($flag_path)) {
            return '';
        }

        $svg = @file_get_contents($flag_path);
        if (!$svg) {
            return '';
        }

        $svg_xml = @simplexml_load_string($svg);
        if (!$svg_xml) {
            // 如果无法解析为XML，直接返回原始SVG
            return $svg;
        }

        $o_width = (float)($svg_xml->attributes()->width ?? 0);
        $o_height = (float)($svg_xml->attributes()->height ?? 0);

        if ($autoSize) {
            // 自适应模式：直接修改XML字符串，移除固定尺寸，添加样式使其自适应容器
            $svg = $svg_xml->asXML();
            // 先移除可能存在的style属性
            $svg = preg_replace('/\s+style\s*=\s*["\'][^"\']*["\']/i', '', $svg);
            // 移除width和height属性（处理单引号和双引号，以及可能的空格）
            $svg = preg_replace('/\s+width\s*=\s*["\'][^"\']*["\']/i', '', $svg);
            $svg = preg_replace('/\s+height\s*=\s*["\'][^"\']*["\']/i', '', $svg);
            // 在<svg标签中添加完整的style属性
            $styleAttr = 'style="width: auto; height: 1.2em; max-height: 20px; vertical-align: middle; display: inline-block;"';
            $svg = preg_replace('/(<svg)([^>]*)(>)/i', '$1$2 ' . $styleAttr . '$3', $svg, 1);
            // 缓存结果
            $this->i18nCache->set($cache_key, $svg, 3600);
            return $svg;
        } else {
            // 固定尺寸模式：按照指定的宽高调整，移除style属性
            // 直接修改XML字符串，确保属性正确设置
            $svg = $svg_xml->asXML();
            
            // 计算实际要设置的宽高
            $final_width = $width;
            $final_height = $height;
            
            // 如果原始SVG没有width/height，但有viewBox，从viewBox计算比例
            if ($o_width <= 0 || $o_height <= 0) {
                // 尝试从viewBox获取尺寸
                $viewBox = (string)($svg_xml->attributes()->viewBox ?? '');
                if ($viewBox && preg_match('/\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s*$/', $viewBox, $matches)) {
                    $o_width = (float)$matches[1];
                    $o_height = (float)$matches[2];
                }
            }
            
            // 根据参数计算最终尺寸
            if ($width > 0 && $height > 0) {
                // 两个参数都有值，直接使用
                $final_width = $width;
                $final_height = $height;
            } elseif ($width > 0 && $o_width > 0 && $o_height > 0) {
                // 只有width，按比例计算height
                $scale = $width / $o_width;
                $final_width = $width;
                $final_height = (int)($o_height * $scale);
            } elseif ($height > 0 && $o_width > 0 && $o_height > 0) {
                // 只有height，按比例计算width
                $scale = $height / $o_height;
                $final_width = (int)($o_width * $scale);
                $final_height = $height;
            }
            
            // 移除可能存在的style属性
            $svg = preg_replace('/\s+style\s*=\s*["\'][^"\']*["\']/i', '', $svg);
            // 移除可能存在的width和height属性
            $svg = preg_replace('/\s+width\s*=\s*["\'][^"\']*["\']/i', '', $svg);
            $svg = preg_replace('/\s+height\s*=\s*["\'][^"\']*["\']/i', '', $svg);
            
            // 添加width和height属性
            if ($final_width > 0 && $final_height > 0) {
                $sizeAttr = 'width="' . $final_width . '" height="' . $final_height . '"';
                $svg = preg_replace('/(<svg)([^>]*)(>)/i', '$1$2 ' . $sizeAttr . '$3', $svg, 1);
            } elseif ($final_width > 0) {
                $svg = preg_replace('/(<svg)([^>]*)(>)/i', '$1$2 width="' . $final_width . '"$3', $svg, 1);
            } elseif ($final_height > 0) {
                $svg = preg_replace('/(<svg)([^>]*)(>)/i', '$1$2 height="' . $final_height . '"$3', $svg, 1);
            }
            
            // 缓存结果
            $this->i18nCache->set($cache_key, $svg, 3600);
            return $svg;
        }
    }

    public function getCountry(string $country_code = 'CN'): array
    {
        if (!$this->countryExists($country_code)) {
            return [];
        }

        return [
            'code' => $country_code,
            'name' => $this->getCountryName($country_code),
            'locales' => $this->getLocalesForCountry($country_code)
        ];
    }

    private function getLocalesForCountry(string $countryCode): array
    {
        $locales = $this->getAvailableLocaleCodes();
        $countryLocales = [];
        $countryCode = strtoupper($countryCode);
        
        foreach ($locales as $locale) {
            if (str_ends_with($locale, '_' . $countryCode)) {
                $countryLocales[] = $locale;
            }
        }
        return $countryLocales;
    }

    private function getCountryCodeFromLocale(string $locale): ?string
    {
        $parts = explode('_', $this->normalizeLocaleCode($locale));
        for ($index = count($parts) - 1; $index >= 1; $index--) {
            $part = strtoupper((string)($parts[$index] ?? ''));
            if (strlen($part) === 2 && preg_match('/^[A-Z]{2}$/', $part) === 1) {
                return $part;
            }
        }

        return null;
    }

    public function localeExists(string $locale_code): bool
    {
        if (class_exists(Locales::class)) {
            try {
                return Locales::exists($locale_code);
            } catch (\Throwable) {
            }
        }

        return isset(self::FALLBACK_LOCALE_NAMES[$locale_code]);
    }

    public function getLocalsWords(bool $cache = true, ?string $moduleName = null): array
    {
        // 翻译数据量大，提前提升内存限制，避免在收集过程中内存溢出
        $_prevMemLimit = ini_get('memory_limit');
        $currentLimit = $this->parseMemoryLimit($_prevMemLimit);
        if ($currentLimit > 0 && $currentLimit < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }

        if (self::$local_words and $cache) {
            return self::$local_words;
        }
        $all_locals_words_file = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
        $translate_mode = Env::get('translation.mode', 'default');
        
        if ($cache) {
            if (!file_exists($all_locals_words_file)) {
                touch($all_locals_words_file);
                $text = '<?php return ' . w_var_export([], true) . ';';
                file_put_contents($all_locals_words_file, $text);
            }
            $all_locals_words = (array)(include $all_locals_words_file);
            if (!empty($all_locals_words)) {
                if ($translate_mode === 'online') {
                    $locals_words = $all_locals_words;
                } else {
                    self::$local_words = $all_locals_words;
                    return $all_locals_words;
                }
            }
        }
        
        $locals_names = $this->getLocaleNames();
        if (!isset($locals_words)) {
            $locals_words = [];
        }
        $error_count = 0;
        $first_error = true;
        
        $collector = ObjectManager::getInstance(TranslationCollector::class);
        $words_by_module = [
            'all_words' => [],
        ];
        $all_i18ns = $this->reader->getAllI18ns();
        RegistryProgress::count('I18n CSV source files', array_sum(array_map('count', $all_i18ns)), 'files');
        $csv_module_count = count($all_i18ns);
        $csv_module_index = 0;
        $csv_word_count = 0;
        foreach ($all_i18ns as $module_name => $i18n_files) {
            $csv_module_index++;
            RegistryProgress::module('I18n CSV read', $csv_module_index, $csv_module_count, (string)$module_name, count($i18n_files) . ' files');
            $full_module_name = $this->getFullModuleName($module_name);
            foreach ($i18n_files as $local => $i18n_file) {
                if (isset($locals_names[$local])) {
                    $this->ensureLocaleInstalled($local);
                    
                    $handle = @fopen($i18n_file, 'r');
                    if ($handle === false) {
                        if ($first_error && php_sapi_name() === 'cli') {
                            echo "\n" . str_repeat("=", 80) . "\n";
                            echo "i18n 文件格式问题\n";
                            echo str_repeat("=", 80) . "\n";
                            $first_error = false;
                        }
                        $relative_path = ltrim(str_replace(BP, '', $i18n_file), '/');
                        if (php_sapi_name() === 'cli') {
                            echo $relative_path . "  【无法打开文件】\n";
                        } else {
                            w_log_warning($relative_path . "  【无法打开文件】", [], 'i18n');
                        }
                        $error_count++;
                        continue;
                    }
                    $is_utf8 = false;
                    $line = 1;
                    $relative_path = ltrim(str_replace(BP, '', $i18n_file), '/');
                    
                    while (($data = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
                        $data = $this->normalizeCsvRow($data, $line);
                        if ($this->isEffectivelyEmptyCsvRow($data)) {
                            $line += 1;
                            continue;
                        }

                        if (!isset($data[0]) || $data[0] === '') {
                            if ($first_error && php_sapi_name() === 'cli') {
                                echo "\n" . str_repeat("=", 80) . "\n";
                                echo "i18n 文件格式问题\n";
                                echo str_repeat("=", 80) . "\n";
                                $first_error = false;
                            }
                            if (php_sapi_name() === 'cli') {
                                echo $relative_path . ":" . $line . "  【没有翻译原文】\n";
                            } else {
                                w_log_warning($relative_path . ":" . $line . "  【没有翻译原文】", [], 'i18n');
                            }
                            $error_count++;
                            $line += 1;
                            continue;
                        }
                        if (!isset($data[1])) {
                            if ($first_error && php_sapi_name() === 'cli') {
                                echo "\n" . str_repeat("=", 80) . "\n";
                                echo "i18n 文件格式问题\n";
                                echo str_repeat("=", 80) . "\n";
                                $first_error = false;
                            }
                            if (php_sapi_name() === 'cli') {
                                echo $relative_path . ":" . $line . "  【没有翻译内容】\n";
                            } else {
                                w_log_warning($relative_path . ":" . $line . "  【没有翻译内容】", [], 'i18n');
                            }
                            $error_count++;
                            $line += 1;
                            continue;
                        }
                        $word_module = isset($data[2]) && !empty(trim($data[2])) ? trim($data[2]) : $full_module_name;
                        
                        if (!$is_utf8) {
                            if (md5(mb_convert_encoding($data[0], 'utf-8', 'utf-8')) === md5($data[0])) {
                                $is_utf8 = true;
                            } else {
                                if ($first_error && php_sapi_name() === 'cli') {
                                    echo "\n" . str_repeat("=", 80) . "\n";
                                    echo "i18n 文件格式问题\n";
                                    echo str_repeat("=", 80) . "\n";
                                    $first_error = false;
                                }
                                if (php_sapi_name() === 'cli') {
                                    echo $relative_path . ":" . $line . "  【编码不是UTF-8】\n";
                                } else {
                                    w_log_warning($relative_path . ":" . $line . "  【编码不是UTF-8】", [], 'i18n');
                                }
                                $error_count++;
                                $line += 1;
                                continue;
                            }
                        }
                        
                        if (!isset($locals_words[$local])) {
                            $locals_words[$local] = [];
                        }
                        $locals_words[$local][$data[0]] = $data[1];
                        $csv_word_count++;

                        if ($collector->isValidTranslationString($data[0])) {
                            if (!isset($words_by_module[$local])) {
                                $words_by_module[$local] = [];
                            }
                            if (!isset($words_by_module[$local][$word_module])) {
                                $words_by_module[$local][$word_module] = [];
                            }
                            $words_by_module[$local][$word_module][$data[0]] = $data[1];
                        }
                        $line += 1;
                    }

                    fclose($handle);
                } else {
                    if ($first_error && php_sapi_name() === 'cli') {
                        echo "\n" . str_repeat("=", 80) . "\n";
                        echo "i18n 文件格式问题\n";
                        echo str_repeat("=", 80) . "\n";
                        $first_error = false;
                    }
                    $relative_path = ltrim(str_replace(BP, '', $i18n_file), '/');
                    if (php_sapi_name() === 'cli') {
                        echo $relative_path . "  【语言代码 " . $local . " 无效】\n";
                    } else {
                        w_log_warning($relative_path . "  【语言代码 " . $local . " 无效】", [], 'i18n');
                    }
                    $error_count++;
                }
            }
        }
        unset($all_i18ns);
        RegistryProgress::count('I18n CSV loaded', $csv_word_count, 'entries');
        
        if ($error_count > 0 && php_sapi_name() === 'cli') {
            echo str_repeat("=", 80) . "\n";
            echo "共发现 " . $error_count . " 个问题\n";
            echo str_repeat("=", 80) . "\n\n";
        }

        $directories = $this->getActiveModuleDirectories($moduleName);
        RegistryProgress::count('I18n source scan', count($directories), 'modules');
        $translations = $this->collectModuleTranslations($directories, $collector);
        RegistryProgress::count('I18n source scan', count($translations), 'source words');
        unset($directories);

        if ($translations or isset($locals_words[Env::default_LANGUAGE_CODE])) {
            $default_local_words = array_merge($translations, $locals_words[Env::default_LANGUAGE_CODE] ?? []);
            $default_local_file = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
            
            $dir = dirname($default_local_file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $file = @fopen($default_local_file, 'w+');
            if ($file === false) {
                w_log_warning(__("警告：无法创建翻译文件 %{file}", ['file' => $default_local_file]), [], 'i18n');
            } else {
                $text = '<?php return ' . var_export($default_local_words, true) . ';';
                fwrite($file, $text);
                fclose($file);
                unset($text);
            }
            unset($default_local_words);
        }
        if ($translations and isset($locals_words[Env::default_LANGUAGE_CODE])) {
            $locals_words[Env::default_LANGUAGE_CODE] = array_merge($translations, $locals_words[Env::default_LANGUAGE_CODE]);
        }
        foreach ($translations as $word => $translate) {
            if (
                $collector->isValidTranslationString((string)$word)
                && !$this->hasModuleTranslation($words_by_module, Env::default_LANGUAGE_CODE, (string)$word)
                && !isset($words_by_module['all_words'][$word])
            ) {
                $words_by_module['all_words'][(string)$word] = (string)$translate;
            }
        }
        unset($translations); // 释放 $translations，后面不再需要
        
        $translate_mode = Env::get('translation.mode', 'default');
        if ($translate_mode === 'online') {
            try {
                $localeDictionary = ObjectManager::getInstance(LocaleDictionary::class);
                foreach ($locals_names as $local_code => $local_name) {
                    if (!isset($locals_words[$local_code])) {
                        $locals_words[$local_code] = [];
                    }
                    $db_translations = $localeDictionary->reset()
                        ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $local_code)
                        ->select()
                        ->fetchArray();
                    foreach ($db_translations as $db_trans) {
                        $word = $db_trans[LocaleDictionary::schema_fields_WORD] ?? '';
                        $translate = $db_trans[LocaleDictionary::schema_fields_TRANSLATE] ?? '';
                        if ($word && $translate) {
                            $locals_words[$local_code][$word] = $translate;
                            if (
                                $collector->isValidTranslationString((string)$word)
                                && !$this->hasModuleTranslation($words_by_module, (string)$local_code, (string)$word)
                                && !isset($words_by_module['all_words'][$word])
                            ) {
                                $words_by_module['all_words'][(string)$word] = (string)$translate;
                            }
                        }
                    }
                    unset($db_translations);
                }
            } catch (\Exception $e) {
                w_log_error("在线翻译模式：从数据库读取翻译失败：" . $e->getMessage(), [], 'i18n');
            }
        }
        
        if ($locals_words) {
            $words_file = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
            $dir = dirname($words_file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            foreach (array_keys($locals_words) as $locale) {
                if (!isset($words_by_module[$locale])) {
                    $words_by_module[$locale] = [];
                }
            }
            
            foreach ($locals_words as $locale => $words) {
                $words_by_module[$locale] ??= [];
                foreach ($words as $word => $translate) {
                    if (!$collector->isValidTranslationString((string)$word)) {
                        continue;
                    }
                    
                    if (
                        !$this->hasModuleTranslation($words_by_module, (string)$locale, (string)$word)
                        && !isset($words_by_module['all_words'][$word])
                    ) {
                        $words_by_module['all_words'][(string)$word] = (string)$translate;
                    }
                }
            }
            
            
            // 使用 var_export 替代 w_var_export，避免正则处理导致的额外内存开销
            $text = '<?php return ' . var_export($words_by_module, true) . ';';
            $result = @file_put_contents($words_file, $text);
            unset($text); // 及时释放导出字符串
            if ($result === false) {
                w_log_warning(__("警告：无法写入翻译文件 %{file}", ['file' => $words_file]), [], 'i18n');
            }
            
            foreach ($words_by_module as $locale => $module_words_data) {
                if ($locale === 'all_words') {
                    continue;
                }
                
                $words_filename = Env::path_TRANSLATE_FILES_PATH . $locale . '.php';
                $words_dir = dirname($words_filename);
                if (!is_dir($words_dir)) {
                    mkdir($words_dir, 0755, true);
                }
                $file = new \Weline\Framework\System\File\Io\File();
                $file->open($words_filename, $file::mode_w);
                $text = '<?php return ' . var_export($module_words_data, true) . ';?>';

                try {
                    $file->write($text);
                } catch (Exception $e) {
                    w_log_warning(__("警告：无法写入语言文件 %{file}", ['file' => $words_filename]), [], 'i18n');
                }
                $file->close();
                unset($text);
            }
            
            foreach ($words_by_module as $locale => $module_words_data) {
                if ($locale === 'all_words') {
                    continue;
                }
                
                $csv_file_path = dirname($words_file) . DS . $locale . '_total.csv';
                $csv_handle = @fopen($csv_file_path, 'w+');
                if ($csv_handle !== false) {
                    if (isset($words_by_module['all_words'])) {
                        foreach ($words_by_module['all_words'] as $word => $translate) {
                            fputcsv($csv_handle, [$word, $translate, ''], ',', '"', '\\');
                        }
                    }
                    foreach ($module_words_data as $module_name => $words) {
                        foreach ($words as $word => $translate) {
                            fputcsv($csv_handle, [$word, $translate, $module_name], ',', '"', '\\');
                        }
                    }
                    fclose($csv_handle);
                }
            }
            unset($words_by_module);
        }
        self::$local_words = $cache ? $locals_words : [];
        // 恢复原始内存限制（确保不低于当前内存使用量）
        $restoreLimit = $_prevMemLimit ?: '128M';
        $currentUsage = memory_get_usage(true);
        $restoreLimitBytes = $this->parseMemoryLimit($restoreLimit);
        if ($restoreLimitBytes > 0 && $restoreLimitBytes < $currentUsage) {
            // 原始限制低于当前使用量，保持 512M 或设为当前使用量的 1.5 倍
            $restoreLimit = max($restoreLimitBytes, (int)($currentUsage * 1.5));
            $restoreLimit = ceil($restoreLimit / 1024 / 1024) . 'M';
        }
        @ini_set('memory_limit', $restoreLimit);
        return $locals_words;
    }

    public function getLocalWords(string $local_code = 'zh_Hans_CN'): array
    {
        $locals_words = $this->getLocalsWords();

        $words = [];
        if (isset($locals_words['all_words']) && is_array($locals_words['all_words'])) {
            $words = $this->mergePreferTranslatedWords(
                $words,
                $this->flattenLocaleWords((array)$locals_words['all_words'])
            );
        }

        if (isset($locals_words[$local_code]) && is_array($locals_words[$local_code])) {
            return $this->mergePreferTranslatedWords(
                $words,
                $this->flattenLocaleWords((array)$locals_words[$local_code])
            );
        }

        if (isset($locals_words['zh_Hans_CN']) && is_array($locals_words['zh_Hans_CN'])) {
            return $this->mergePreferTranslatedWords(
                $words,
                $this->flattenLocaleWords((array)$locals_words['zh_Hans_CN'])
            );
        }

        if ($words) {
            return $words;
        }

        return $this->flattenLocaleWords($locals_words);
    }

    public function convertToLanguageFile(bool $cache = true, ?string $moduleName = null): void
    {
        $this->getLocalsWords($cache, $moduleName);
    }

    public function getCollectedWords(): array
    {
        return $this->getLocalWords(Env::default_LANGUAGE_CODE);
    }

    public static function clearLocalWordsCache(): void
    {
        self::$local_words = [];
    }

    private function flattenLocaleWords(array $words): array
    {
        $flatWords = [];

        foreach ($words as $word => $translate) {
            if (is_array($translate)) {
                $flatWords = $this->mergePreferTranslatedWords(
                    $flatWords,
                    $this->flattenLocaleWords($translate)
                );
                continue;
            }

            if (!is_string($word) && !is_int($word)) {
                continue;
            }

            if (!is_scalar($translate) && $translate !== null) {
                continue;
            }

            $word = trim((string)$word);
            if ($word === '') {
                continue;
            }

            $translate = $translate === null ? '' : (string)$translate;
            $flatWords[$word] = trim($translate) === '' ? $word : $translate;
        }

        return $flatWords;
    }

    private function mergePreferTranslatedWords(array $baseWords, array $candidateWords): array
    {
        foreach ($candidateWords as $word => $translate) {
            if (!is_string($word) || !is_string($translate)) {
                continue;
            }

            if (
                !isset($baseWords[$word])
                || $baseWords[$word] === $word
                || $translate !== $word
            ) {
                $baseWords[$word] = $translate;
            }
        }

        return $baseWords;
    }
    
    private function getFullModuleName(string $module_name): string
    {
        if (str_starts_with($module_name, 'Weline_')) {
            return $module_name;
        }
        try {
            $module_info = Env::getInstance()->getModuleInfo($module_name);
            if ($module_info && isset($module_info['name'])) {
                return $module_info['name'];
            }
        } catch (\Exception $e) {}
        return 'Weline_' . $module_name;
    }

    /**
     * 判断翻译值是否疑似乱码（如 UTF-8 被误存为非 UTF-8 后中文变成 ?）
     */
    private static function isLikelyCorruptedTranslation(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        if (preg_match('/\?{3,}/', $value)) {
            return true;
        }
        $qCount = substr_count($value, '?');
        return $qCount >= 5;
    }

    private function hasModuleTranslation(array $wordsByModule, string $locale, string $word): bool
    {
        foreach (($wordsByModule[$locale] ?? []) as $moduleWords) {
            if (is_array($moduleWords) && array_key_exists($word, $moduleWords)) {
                return true;
            }
        }

        return false;
    }

    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '-1') {
            return -1;
        }
        $value = (int)$limit;
        $unit = strtoupper(substr($limit, -1));
        switch ($unit) {
            case 'G':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'M':
                $value *= 1024 * 1024;
                break;
            case 'K':
                $value *= 1024;
                break;
        }
        return $value;
    }

    public function getCountries(string $display_local_code = 'zh_Hans_CN'): array
    {
        if (class_exists(Countries::class)) {
            try {
                return Countries::getNames($this->normalizeIntlDisplayLocale($display_local_code));
            } catch (\Throwable) {
                try {
                    return Countries::getNames('en');
                } catch (\Throwable) {
                }
            }
        }

        return self::FALLBACK_COUNTRY_NAMES;
    }

    public function getActiveLocalsModel(string $target_local = 'zh_Hans_CN'): Locals
    {
        $cache_key = __FUNCTION__ . '_' . $target_local;
        $cached = $this->i18nCache->get($cache_key);
        if ($cached instanceof Locals) {
            return $cached;
        }
        $LocalsModel = ObjectManager::getInstance(Locals::class)->where('target_code', $target_local);
        $this->i18nCache->set($cache_key, $LocalsModel);
        return $LocalsModel;
    }

    public function ensureLocaleInstalled(string $localeCode): void
    {
        static $installedLocales = [];
        if (isset($installedLocales[$localeCode])) {
            return;
        }
        $installedLocales[$localeCode] = true;

        try {
            $countryCode = $this->getCountryCodeFromLocale($localeCode);
            if ($countryCode && $this->countryExists($countryCode)) {
                $countriesModel = ObjectManager::getInstance(\Weline\I18n\Model\Countries::class);
                $country = $countriesModel->reset()
                    ->where(\Weline\I18n\Model\Countries::schema_fields_CODE, $countryCode)
                    ->find()
                    ->fetch();
                
                if (!$country->getId()) {
                    $flag = (string)$this->getCountryFlag($countryCode);
                    $countriesModel->reset()
                        ->setData([
                            \Weline\I18n\Model\Countries::schema_fields_CODE => $countryCode,
                            \Weline\I18n\Model\Countries::schema_fields_FLAG => $flag,
                            \Weline\I18n\Model\Countries::schema_fields_IS_INSTALL => 1,
                            \Weline\I18n\Model\Countries::schema_fields_IS_ACTIVE => 1,
                        ])
                        ->save();
                    if (php_sapi_name() === 'cli') {
                        echo "  [+] 自动注册并激活国家: {$countryCode}\n";
                    }
                } else {
                    $needUpdate = false;
                    if (!$country->getData(\Weline\I18n\Model\Countries::schema_fields_IS_INSTALL)) {
                        $country->setData(\Weline\I18n\Model\Countries::schema_fields_IS_INSTALL, 1);
                        $needUpdate = true;
                    }
                    if (!$country->getData(\Weline\I18n\Model\Countries::schema_fields_IS_ACTIVE)) {
                        $country->setData(\Weline\I18n\Model\Countries::schema_fields_IS_ACTIVE, 1);
                        $needUpdate = true;
                    }
                    if ($needUpdate) {
                        $country->save();
                        if (php_sapi_name() === 'cli') {
                            echo "  [*] 启用并激活国家: {$countryCode}\n";
                        }
                    }
                }
            }

            $localeModel = ObjectManager::getInstance(Locale::class);
            $locale = $localeModel->reset()
                ->where(Locale::schema_fields_CODE, $localeCode)
                ->find()
                ->fetch();
            
            if (!$locale->getId()) {
                $flag = '';
                if ($countryCode) {
                    $flag = (string)$this->getCountryFlag($countryCode);
                }
                $localeModel->reset()
                    ->setData([
                        Locale::schema_fields_CODE => $localeCode,
                        Locale::schema_fields_COUNTRY_CODE => $countryCode,
                        Locale::schema_fields_FLAG => $flag,
                        Locale::schema_fields_IS_ACTIVE => 1,
                        Locale::schema_fields_IS_INSTALL => 1,
                    ])
                    ->save();
                if (php_sapi_name() === 'cli') {
                    echo "  [+] 自动注册并激活语言: {$localeCode}\n";
                }
            } else {
                $needUpdate = false;
                if (!$locale->getData(Locale::schema_fields_IS_INSTALL)) {
                    $locale->setData(Locale::schema_fields_IS_INSTALL, 1);
                    $needUpdate = true;
                }
                if (!$locale->getData(Locale::schema_fields_IS_ACTIVE)) {
                    $locale->setData(Locale::schema_fields_IS_ACTIVE, 1);
                    $needUpdate = true;
                }
                if ($needUpdate) {
                    $locale->save();
                    if (php_sapi_name() === 'cli') {
                        echo "  [*] 启用并激活语言: {$localeCode}\n";
                    }
                }
            }
        } catch (\Throwable $e) {
            if (php_sapi_name() === 'cli') {
                echo "  [!] 注册语言 {$localeCode} 失败: " . $e->getMessage() . "\n";
            }
        }
    }
}
