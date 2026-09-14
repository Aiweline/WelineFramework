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
use Weline\Framework\Phrase\DictionaryCompiler;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Cache\Contract\NamespaceScopedCachePoolInterface;
use Weline\Framework\Phrase\DictionaryWordValidator;
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

    /** 公共 I18n 事实共享同一版本；事务内和早期引导阶段不发布共享结果。 */
    private function namespaceCache(): ?CachePoolInterface
    {
        if (DictionaryCacheNamespace::fingerprint() === null) {
            return null;
        }
        if (!$this->i18nCache instanceof NamespaceScopedCachePoolInterface
            || !in_array(DictionaryCacheNamespace::NAMESPACE, $this->i18nCache->getNamespaces(), true)
        ) {
            // Locale names, installation state and flags are global catalog facts.
            // Dictionary content commits do not change this root-owned directory.
            $this->i18nCache = \Weline\Framework\Cache\Pool\NamespaceScopedCachePool::create(
                $this->i18nCache, [DictionaryCacheNamespace::NAMESPACE],
            );
        }
        return $this->i18nCache;
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
        $cacheKey = DictionaryCacheNamespace::cacheKey(strtolower(trim($locale_code)));
        if (isset(DictionaryCacheNamespace::localCache(self::$localByCodeCache)[$cacheKey])
            && DictionaryCacheNamespace::localCache(self::$localByCodeCache)[$cacheKey]['expires_at'] >= microtime(true)) {
            return DictionaryCacheNamespace::localCache(self::$localByCodeCache)[$cacheKey]['value'];
        }
        unset(DictionaryCacheNamespace::localCache(self::$localByCodeCache)[$cacheKey]);

        if ($data = $this->namespaceCache()?->get($locale_code)) {
            return $this->rememberLocalByCode($cacheKey, (string)$data);
        }
        $locales = $this->getAvailableLocaleCodes();
        foreach ($locales as $locale) {
            if (strtolower($locale_code) === strtolower($locale)) {
                $this->namespaceCache()?->set($locale_code, $locale);
                return $this->rememberLocalByCode($cacheKey, $locale);
            }
        }
        $this->namespaceCache()?->set($locale_code, 'zh_Hans_CN');
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
        DictionaryCacheNamespace::localCache(self::$localByCodeCache)[$cacheKey] = [
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
        if ($data = $this->namespaceCache()?->get($cache_key)) {
            return $data;
        }
        $locals = $this->getLocaleNames($lang_code);
        $this->namespaceCache()?->set($cache_key, $locals);
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
        $cache_key = 'getLocalesWithFlags_img_v1_' . $lang_code . $width . $height . (string)$installed;
        if ($data = $this->namespaceCache()?->get($cache_key)) {
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
        
        $this->namespaceCache()?->set($cache_key, $locals, 0);
        return $locals;
    }

    /**
     * @deprecated Prefer LanguageSelect::getLanguageItems() / LanguageSwitcher catalog.
     * Display names use LanguageSelect::buildDisplayName() (no English_zh hard concat).
     */
    public function getLocalesWithFlagsDisplaySelf(string $display_locale_code = 'zh_Hans_CN', int $width = 24, int $height = 18, bool $installed = true, bool $autoSize = false)
    {
        $default_width = 24;
        $default_height = 18;
        
        // 如果width或height为0，使用默认值
        if ($width <= 0) $width = $default_width;
        if ($height <= 0) $height = $default_height;
        
        $cache_key = 'getLocalesWithFlagsDisplaySelf_img_v1_' . $width . $height . (string)$installed . (string)$autoSize . $display_locale_code;
        if ($data = $this->namespaceCache()?->get($cache_key)) {
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
                $localizedName = (string)$this->getLocaleName($locale, $display_locale_code);
                $selfName = (string)$this->getLocaleName($locale, $locale);
                $referenceName = (string)$this->getLocaleName($locale, 'en');
                $name = \Weline\I18n\Taglib\LanguageSelect::buildDisplayName(
                    $localizedName,
                    $referenceName,
                    $selfName,
                    $locale,
                );
                $tagLabel = \Weline\I18n\Taglib\LanguageSelect::buildTagLabel(
                    $localizedName,
                    $selfName,
                    $referenceName,
                    $locale,
                );
                $locals[$locale] = [
                    'name' => $name,
                    'display_name' => $name,
                    'tag_label' => $tagLabel,
                    'self_name' => $selfName,
                    'flag' => $svg,
                ];
            }
        }
        $this->namespaceCache()?->set($cache_key, $locals, 0);
        return $locals;
    }

    public function getCountryFlagWithLocal(string $local_code = 'zh_Hans_CN', int $width = 24, int $height = 18): array
    {
        $localeCode = $this->normalizeLocaleCode($local_code);
        if ($localeCode === '') {
            return [];
        }

        $cache_key = 'getCountryFlagWithLocal' . $localeCode . $width . $height;
        if ($data = $this->namespaceCache()?->get($cache_key)) {
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
                $this->namespaceCache()?->set($cache_key, $local, 0);
                return $local;
            }
        }

        $this->namespaceCache()?->set($cache_key, [], 0);
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

            // 保留 CSV 中已有、但本次静态收集未扫到的词条（如数组字面量再经 __($var) 输出的首页文案）。
            foreach ($fileWords as $key => $value) {
                if ($key === '' || \array_key_exists($key, $fileTranslations)) {
                    continue;
                }
                if (self::isLikelyCorruptedTranslation($value)) {
                    continue;
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
        // Codec drops garbled (BOM/U+FFFD) rows; does not strip-and-keep polluted keys.
        return \Weline\I18n\Service\I18nCsvCodec::readWords($filePath);
    }

    /**
     * @param array<string, string> $translations
     */
    private function writeModuleLanguageCsvFile(string $filePath, array $translations): void
    {
        try {
            \Weline\I18n\Service\I18nCsvCodec::writeWords($filePath, $translations);
        } catch (\Throwable) {
            // Keep historical soft-fail behavior for module CSV rewrite.
        }
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

            $data[$index] = $this->normalizeCsvCell($value, $index === 0);
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
        return \Weline\I18n\Service\I18nCsvCodec::stripBom($value);
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
            $cached = $this->namespaceCache()?->get($cache_key);
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
                $this->namespaceCache()?->set($cache_key, $flag, 3600);
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
        if ($cached = $this->namespaceCache()?->get($cache_key)) {
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
            $this->namespaceCache()?->set($cache_key, $svg, 3600);
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
            $this->namespaceCache()?->set($cache_key, $svg, 3600);
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

        // This snapshot contains every locale: the no-locale namespace is
        // CONTENT_NAMESPACE, while namespaceCache() above owns only the directory.
        $wordsCacheKey = DictionaryCacheNamespace::cacheKey($moduleName ?? '*');
        if ($cache && isset(DictionaryCacheNamespace::localCache(self::$local_words, 64)[$wordsCacheKey])) {
            return DictionaryCacheNamespace::localCache(self::$local_words, 64)[$wordsCacheKey];
        }
        $all_locals_words_file = Env::path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE;
        $translate_mode = Env::get('translation.mode', 'default');
        
        if ($cache) {
            if (!file_exists($all_locals_words_file)) {
                touch($all_locals_words_file);
                $text = '<?php return ' . w_var_export([], true) . ';';
                file_put_contents($all_locals_words_file, $text);
            }
            // 新代次首次读取时仅失效实际语言文件，兼容关闭时间戳校验的 OPcache。
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($all_locals_words_file, true);
            }
            $all_locals_words = (array)(include $all_locals_words_file);
            if (!empty($all_locals_words)) {
                if ($translate_mode === 'online') {
                    $locals_words = $all_locals_words;
                } else {
                    DictionaryCacheNamespace::localCache(self::$local_words, 64)[$wordsCacheKey] = $all_locals_words;
                    return $all_locals_words;
                }
            }
        }
        
        $locals_names = $this->getLocaleNames();
        if (!isset($locals_words)) {
            $locals_words = [];
        }

        /** @var DictionaryCompiler $compiler */
        $compiler = ObjectManager::getInstance(DictionaryCompiler::class);
        $locals_words = $compiler->compile($moduleName, false);
        if ($cache) {
            DictionaryCacheNamespace::localCache(self::$local_words, 64)[$wordsCacheKey] = $locals_words;
        }
        @ini_set('memory_limit', $_prevMemLimit !== '' ? $_prevMemLimit : '128M');
        return $locals_words;
    }

    /**
     * dictionary_compile Observer 增强：源码扫描 + online DB 合并。
     *
     * @param array<string, array<string, string>> $localsWords
     * @param array<string, mixed> $wordsByModule
     * @param array<string, string> $sourceTranslations
     */
    public function enrichDictionaryCompile(
        array &$localsWords,
        array &$wordsByModule,
        array &$sourceTranslations,
        ?string $moduleName = null,
    ): void {
        $collector = ObjectManager::getInstance(TranslationCollector::class);
        $directories = $this->getActiveModuleDirectories($moduleName);
        RegistryProgress::count('I18n source scan', count($directories), 'modules');
        $sourceTranslations = $this->collectModuleTranslations($directories, $collector);
        RegistryProgress::count('I18n source scan', count($sourceTranslations), 'source words');

        $defaultLocale = Env::default_LANGUAGE_CODE;
        if ($sourceTranslations !== [] && isset($localsWords[$defaultLocale])) {
            $localsWords[$defaultLocale] = array_merge($sourceTranslations, $localsWords[$defaultLocale]);
        } elseif ($sourceTranslations !== []) {
            $localsWords[$defaultLocale] = $sourceTranslations;
        }

        foreach ($sourceTranslations as $word => $translate) {
            if (
                $collector->isValidTranslationString((string)$word)
                && !$this->hasModuleTranslation($wordsByModule, $defaultLocale, (string)$word)
                && !isset($wordsByModule['all_words'][$word])
            ) {
                $wordsByModule['all_words'][(string)$word] = (string)$translate;
            }
        }

        $translateMode = Env::get('translation.mode', 'default');
        if ($translateMode !== 'online') {
            return;
        }

        try {
            $localeDictionary = ObjectManager::getInstance(LocaleDictionary::class);
            foreach ($this->getLocaleNames() as $localCode => $localName) {
                $localsWords[$localCode] ??= [];
                $dbTranslations = $localeDictionary->reset()
                    ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localCode)
                    ->select()
                    ->fetchArray();
                foreach ($dbTranslations as $dbTrans) {
                    $word = $dbTrans[LocaleDictionary::schema_fields_WORD] ?? '';
                    $translate = $dbTrans[LocaleDictionary::schema_fields_TRANSLATE] ?? '';
                    if ($word === '' || $translate === '') {
                        continue;
                    }
                    $localsWords[$localCode][$word] = $translate;
                    if (
                        DictionaryWordValidator::isValidTranslationString((string)$word)
                        && !$this->hasModuleTranslation($wordsByModule, (string)$localCode, (string)$word)
                        && !isset($wordsByModule['all_words'][$word])
                    ) {
                        $wordsByModule['all_words'][(string)$word] = (string)$translate;
                    }
                }
            }
        } catch (\Exception $exception) {
            w_log_error('在线翻译模式：从数据库读取翻译失败：' . $exception->getMessage(), [], 'i18n');
        }
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
        // 此处尚未查询，查询构造器不能成为跨请求/Worker 的公共缓存值。
        $model = clone ObjectManager::getInstance(Locals::class);
        return $model->clearData()->clearQuery()->where('target_code', $target_local);
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
