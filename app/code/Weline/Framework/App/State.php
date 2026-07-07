<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App;

use Weline\Framework\Context;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class State extends DataObject
{
    public const area_backend = 'backend';

    public const area_frontend = 'frontend';

    public const area_base = 'base';

    /** @var array<string, true>|null */
    private static ?array $allowedCurrencyCodeMap = null;

    private static string $allowedCurrencyCodeScope = '';

    /** @var array<string, true>|null language code lower-case => true */
    private static ?array $allowedLanguageCodeMap = null;

    private static string $allowedLanguageCodeScope = '';

    /** @var array{currency: string, language: string}|null 单次请求路径前缀解析缓存 */
    private static ?array $pathLocalizationCache = null;

    private static bool $pathLocalizationResolving = false;

    public static bool $is_backend = false;

    /** 请求级缓存：getLangLocal() 结果，同请求内只触发一次事件，WLS 下由 StateManager 重置 */
    private static ?string $langLocalCache = null;

    private const LANG_LOCAL_CONTEXT_CACHE = 'state.lang_local_cache';

    /**
     * State 初始函数...
     *
     * @param Request $request
     */
    public function __construct(
        Request $request
    )
    {
        parent::__construct();
        self::$is_backend = $request->isBackend();
    }

    /**
     * 获取当前请求对象（始终从 ObjectManager 获取最新实例，兼容 WLS 单例场景）
     */
    protected function getRequest(): Request
    {
        return ObjectManager::getInstance(Request::class);
    }

    public function getStateCode()
    {
        return $this->getRequest()->getAreaRouter();
    }

    static function isBackend(): bool
    {
        return self::$is_backend;
    }

    static function setIsBackend()
    {
        self::$is_backend = true;
    }

    /**
     * 获取当前语言
     * 优先级：URL 路径解析的 SERVER 变量 > Cookie > 默认值
     * 
     * @return string
     */
    public static function getLang(): string
    {
        // 优先从 URL 路径解析的变量中读取（从路径配置的 URL）
        $lang = self::detectLanguageFromRequestPath();
        if (!empty($lang)) {
            return $lang;
        }

        $lang = \w_env('user.lang');
        // 如果 w_env 中没有，从 Cookie 读取
        if (empty($lang)) {
            $lang = Cookie::get('WELINE_USER_LANG');
        }
        // 默认网站语言
        if (empty($lang)) {
            $lang = Cookie::get('WELINE-WEBSITE-LANG', 'zh_Hans_CN');
        }
        return $lang;
    }

    /**
     * 获取当前货币
     * 优先级：URL 路径解析的变量 > Cookie > 默认值
     *
     * @return string
     */
    public static function getCurrency(): string
    {
        // 优先从 URL 路径解析的变量中读取（从路径配置的 URL）
        $currency = self::detectCurrencyFromRequestPath();
        if (!empty($currency)) {
            return $currency;
        }

        $currency = \w_env('user.currency');
        // 如果 w_env 中没有，从 Cookie 读取
        if (empty($currency)) {
            $currency = Cookie::get('WELINE_USER_CURRENCY');
        }
        // 默认网站货币
        if (empty($currency)) {
            $currency = Cookie::get('WELINE_WEBSITE_CURRENCY', 'CNY');
        }
        return $currency;
    }

    /**
     * 获取语言本地化代码（触发事件，允许其他模块修改）
     * 同请求内只触发一次事件，后续调用直接返回缓存值，减少重复 dispatch。
     *
     * @return string
     */
    public static function getLangLocal(): string
    {
        $lang = self::getLang();
        $currency = self::getCurrency();
        $cacheKey = $lang . '|' . $currency;
        $context = Context::getCurrent();
        if ($context !== null) {
            $cached = $context->get(self::LANG_LOCAL_CONTEXT_CACHE, null);
            if (\is_array($cached)
                && (string)($cached['key'] ?? '') === $cacheKey
                && \array_key_exists('value', $cached)
            ) {
                return (string)$cached['value'];
            }
        } elseif (self::$langLocalCache !== null) {
            return self::$langLocalCache;
        }
        $data = new DataObject();
        $data->setData('lang', $lang);
        $data->setData('currency', $currency);
        $data->setData('lang_local', $lang);

        try {
            \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class)
                ->dispatch('Weline_Framework_Cookie::lang_local', $data);
        } catch (\Exception $e) {
            // 如果事件系统未初始化，静默处理
        }

        $langLocal = (string)$data->getData('lang_local');
        if ($context !== null) {
            $context->set(self::LANG_LOCAL_CONTEXT_CACHE, [
                'key' => $cacheKey,
                'value' => $langLocal,
            ]);
        } else {
            self::$langLocalCache = $langLocal;
        }

        return $langLocal;
    }

    private static function detectLanguageFromRequestPath(): string
    {
        return self::resolveRequestPathLocalization()['language'];
    }

    /**
     * 解析路径本地化前缀：可选 area 后，currency / language 可单独出现，也可任意顺序组合。
     *
     * @param list<string> $segments
     * @return array{currency: string, language: string}
     */
    public static function resolveLocalizationFromPathSegments(array $segments): array
    {
        if ($segments === []) {
            return ['currency' => '', 'language' => ''];
        }

        if (\count($segments) > 3) {
            $segments = \array_slice($segments, 0, 3);
        }

        $index = 0;
        if (isset($segments[$index]) && Env::isAreaRoutePathSegment($segments[$index])) {
            $index++;
        }

        $currency = '';
        $language = '';
        $localizationSegments = 0;
        while (isset($segments[$index]) && $localizationSegments < 2) {
            $segment = (string)$segments[$index];
            if ($currency === '' && self::isCurrencySegmentCandidate($segment)) {
                $currency = strtoupper($segment);
                $index++;
                $localizationSegments++;
                continue;
            }

            if ($language === '' && self::isLanguageSegmentCandidate($segment)) {
                $language = self::normalizeLanguageSegment($segment);
                $index++;
                $localizationSegments++;
                continue;
            }

            break;
        }

        return ['currency' => $currency, 'language' => $language];
    }

    /**
     * @return array{currency: string, language: string}
     */
    private static function resolveRequestPathLocalization(): array
    {
        if (self::$pathLocalizationCache !== null) {
            return self::$pathLocalizationCache;
        }

        if (self::$pathLocalizationResolving) {
            return ['currency' => '', 'language' => ''];
        }

        self::$pathLocalizationResolving = true;
        try {
            self::$pathLocalizationCache = self::resolveLocalizationFromPathSegments(
                self::requestPathPrefixSegments()
            );
        } finally {
            self::$pathLocalizationResolving = false;
        }

        return self::$pathLocalizationCache;
    }

    /**
     * 单元测试 / WLS 请求切换后重置路径解析缓存。
     */
    public static function resetRequestPathLocalizationCache(): void
    {
        self::$pathLocalizationCache = null;
        self::$pathLocalizationResolving = false;
    }

    /**
     * WLS/CLI 同进程切换语言或货币后，重置本地化语言缓存。
     */
    public static function resetLangLocalCache(): void
    {
        self::$langLocalCache = null;
        $context = Context::getCurrent();
        if ($context !== null) {
            $context->set(self::LANG_LOCAL_CONTEXT_CACHE, null);
        }
    }

    /**
     * 判断路径段是否为当前请求允许的语言代码。
     *
     * 优先级：当前网站关联语言 > 全局已启用语言 > i18n 缓存/库探测。
     */
    public static function isAllowedLanguageCode(string $code): bool
    {
        $code = self::normalizeLanguageSegment($code);
        if (!self::isLanguageSegmentCandidate($code)) {
            return false;
        }
        if (Env::isAreaRoutePathSegment($code)) {
            return false;
        }

        $allowedMap = self::resolveAllowedLanguageCodeMap();
        if ($allowedMap !== []) {
            return isset($allowedMap[strtolower($code)]);
        }

        return self::probeLanguageExistsInStore($code);
    }

    /**
     * @return array<string, true>
     */
    private static function resolveAllowedLanguageCodeMap(): array
    {
        $scope = (string)\w_env('website_id', '')
            . '|' . (string)\w_env('website.code', '')
            . '|' . (string)\Weline\Framework\Env\WelineEnv::server('WELINE_WEBSITE_ID', '');
        if (self::$allowedLanguageCodeMap !== null && self::$allowedLanguageCodeScope === $scope) {
            return self::$allowedLanguageCodeMap;
        }

        $map = [];
        foreach (self::loadWebsiteBoundLanguageCodes() as $code) {
            $map[strtolower($code)] = true;
        }
        if ($map === []) {
            foreach (self::loadGlobalEnabledLanguageCodes() as $code) {
                $map[strtolower($code)] = true;
            }
        }

        self::$allowedLanguageCodeScope = $scope;
        self::$allowedLanguageCodeMap = $map;

        return self::$allowedLanguageCodeMap;
    }

    /**
     * @return list<string>
     */
    private static function loadWebsiteBoundLanguageCodes(): array
    {
        try {
            if (\class_exists(\Weline\Websites\Data\WebsiteData::class, false)) {
                $codes = \Weline\Websites\Data\WebsiteData::getLanguageCodes();
                if ($codes !== []) {
                    return self::normalizeLanguageCodeList($codes);
                }
            }
        } catch (\Throwable) {
        }

        $websiteId = (int)\w_env('website_id', 0);
        if ($websiteId <= 0) {
            $websiteId = (int)\Weline\Framework\Env\WelineEnv::server('WELINE_WEBSITE_ID', 0);
        }
        if ($websiteId <= 0 || !\class_exists(\Weline\Websites\Model\WebsiteLanguage::class, false)) {
            return [];
        }

        try {
            $websiteLanguage = ObjectManager::getInstance(\Weline\Websites\Model\WebsiteLanguage::class);
            return self::normalizeLanguageCodeList($websiteLanguage->getWebsiteLanguageCodes($websiteId));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private static function loadGlobalEnabledLanguageCodes(): array
    {
        try {
            if (!\class_exists(\Weline\I18n\Model\Locals::class, false)) {
                return [];
            }
            $localModel = ObjectManager::getInstance(\Weline\I18n\Model\Locals::class);
            $rows = $localModel->clear()
                ->where(\Weline\I18n\Model\Locals::schema_fields_IS_INSTALL, 1)
                ->where(\Weline\I18n\Model\Locals::schema_fields_IS_ACTIVE, 1)
                ->select()
                ->fetchArray();
            $codes = [];
            foreach ((array)$rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $code = trim((string)($row[\Weline\I18n\Model\Locals::schema_fields_CODE] ?? ''));
                if ($code !== '') {
                    $codes[] = $code;
                }
            }
            return self::normalizeLanguageCodeList($codes);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<int, string> $codes
     * @return list<string>
     */
    private static function normalizeLanguageCodeList(array $codes): array
    {
        $normalized = [];
        foreach ($codes as $code) {
            $code = self::normalizeLanguageSegment((string)$code);
            if (self::isLanguageLocaleShape($code)) {
                $normalized[] = $code;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function normalizeLanguageSegment(string $code): string
    {
        return str_replace('-', '_', trim($code));
    }

    private static function isLanguageLocaleShape(string $code): bool
    {
        return self::isLanguageSegmentCandidate($code);
    }

    /**
     * 语言路径段快速形态判断（无正则）：xx_Name 或 xx_Name_CC。
     */
    private static function isLanguageSegmentCandidate(string $segment): bool
    {
        $code = self::normalizeLanguageSegment($segment);
        if (\strlen($code) < 5 || $code[2] !== '_') {
            return false;
        }
        if (!ctype_lower($code[0]) || !ctype_lower($code[1]) || !ctype_alpha($code[0]) || !ctype_alpha($code[1])) {
            return false;
        }

        $parts = explode('_', $code);
        if (\count($parts) < 2 || \count($parts) > 3 || \strlen($parts[0]) !== 2) {
            return false;
        }
        if (\strlen($parts[1]) < 2 || !ctype_alpha($parts[1])) {
            return false;
        }
        if (\count($parts) === 3) {
            return \strlen($parts[2]) === 2 && ctype_upper($parts[2]) && ctype_alpha($parts[2]);
        }

        return true;
    }

    private static function isCurrencySegmentCandidate(string $segment): bool
    {
        return \strlen($segment) === 3
            && $segment === strtoupper($segment)
            && ctype_alpha($segment);
    }

    private static function probeLanguageExistsInStore(string $code): bool
    {
        $codeLower = strtolower(self::normalizeLanguageSegment($code));
        if ($codeLower === '') {
            return false;
        }

        try {
            $cache = w_cache('i18n');
            $checkCacheKey = 'lang_check_' . $codeLower;
            $checkResult = $cache->get($checkCacheKey);
            if ($checkResult !== null && $checkResult !== false) {
                return (bool)$checkResult;
            }
        } catch (\Throwable) {
        }

        try {
            if (!\class_exists(\Weline\I18n\Model\Locals::class, false)) {
                return false;
            }
            $localModel = ObjectManager::getInstance(\Weline\I18n\Model\Locals::class);
            $local = $localModel->clear()
                ->where(\Weline\I18n\Model\Locals::schema_fields_CODE, $code)
                ->where(\Weline\I18n\Model\Locals::schema_fields_IS_INSTALL, 1)
                ->where(\Weline\I18n\Model\Locals::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            return (bool)$local->getId();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 判断路径段是否为当前请求允许的 ISO 货币码。
     *
     * 优先级：当前网站关联货币 > 全局启用货币 > 货币表缓存探测。
     * 永远排除区域路由前缀（如 api），避免与 REST 路径混淆。
     */
    public static function isAllowedCurrencyCode(string $code): bool
    {
        $code = strtoupper(trim($code));
        if (!self::isCurrencySegmentCandidate($code)) {
            return false;
        }
        if (Env::isAreaRoutePathSegment($code)) {
            return false;
        }

        $allowedMap = self::resolveAllowedCurrencyCodeMap();
        if ($allowedMap !== []) {
            return isset($allowedMap[$code]);
        }

        return self::probeCurrencyExistsInStore($code);
    }

    /**
     * @return array<string, true>
     */
    private static function resolveAllowedCurrencyCodeMap(): array
    {
        $scope = (string)\w_env('website_id', '')
            . '|' . (string)\w_env('website.code', '')
            . '|' . (string)\Weline\Framework\Env\WelineEnv::server('WELINE_WEBSITE_ID', '');
        if (self::$allowedCurrencyCodeMap !== null && self::$allowedCurrencyCodeScope === $scope) {
            return self::$allowedCurrencyCodeMap;
        }

        $map = [];
        foreach (self::loadWebsiteBoundCurrencyCodes() as $code) {
            $map[$code] = true;
        }
        if ($map === []) {
            foreach (self::loadGlobalEnabledCurrencyCodes() as $code) {
                $map[$code] = true;
            }
        }

        self::$allowedCurrencyCodeScope = $scope;
        self::$allowedCurrencyCodeMap = $map;

        return self::$allowedCurrencyCodeMap;
    }

    /**
     * @return list<string>
     */
    private static function loadWebsiteBoundCurrencyCodes(): array
    {
        try {
            if (\class_exists(\Weline\Websites\Data\WebsiteData::class, false)) {
                $codes = \Weline\Websites\Data\WebsiteData::getCurrencyCodes();
                if ($codes !== []) {
                    return self::normalizeCurrencyCodeList($codes);
                }
            }
        } catch (\Throwable) {
        }

        $websiteId = (int)\w_env('website_id', 0);
        if ($websiteId <= 0) {
            $websiteId = (int)\Weline\Framework\Env\WelineEnv::server('WELINE_WEBSITE_ID', 0);
        }
        if ($websiteId <= 0 || !\class_exists(\Weline\Websites\Model\WebsiteCurrency::class, false)) {
            return [];
        }

        try {
            $websiteCurrency = ObjectManager::getInstance(\Weline\Websites\Model\WebsiteCurrency::class);
            return self::normalizeCurrencyCodeList($websiteCurrency->getWebsiteCurrencyCodes($websiteId));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private static function loadGlobalEnabledCurrencyCodes(): array
    {
        try {
            if (\class_exists(\Weline\Currency\Data\CurrencyData::class, false)) {
                $codes = [];
                foreach (\Weline\Currency\Data\CurrencyData::getCurrencies() as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    $code = strtoupper(trim((string)($row['code'] ?? '')));
                    if ($code !== '') {
                        $codes[] = $code;
                    }
                }
                if ($codes !== []) {
                    return self::normalizeCurrencyCodeList($codes);
                }
            }
        } catch (\Throwable) {
        }

        try {
            if (!\class_exists(\Weline\Currency\Model\Currency::class, false)) {
                return [];
            }
            $currencyModel = ObjectManager::getInstance(\Weline\Currency\Model\Currency::class);
            $rows = $currencyModel->clear()
                ->where(\Weline\Currency\Model\Currency::schema_fields_STATUS, true)
                ->select()
                ->fetchArray();
            $codes = [];
            foreach ((array)$rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $code = strtoupper(trim((string)($row[\Weline\Currency\Model\Currency::schema_fields_CODE] ?? '')));
                if ($code !== '') {
                    $codes[] = $code;
                }
            }
            return self::normalizeCurrencyCodeList($codes);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<int, string> $codes
     * @return list<string>
     */
    private static function normalizeCurrencyCodeList(array $codes): array
    {
        $normalized = [];
        foreach ($codes as $code) {
            $code = strtoupper(trim((string)$code));
            if (self::isCurrencySegmentCandidate($code)) {
                $normalized[] = $code;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function probeCurrencyExistsInStore(string $code): bool
    {
        try {
            $cache = w_cache('currency');
            $cacheKey = 'currency_code_' . $code;
            $cached = $cache->get($cacheKey);
            if (\is_array($cached) && isset($cached['code'])) {
                return true;
            }
            if ($cached === []) {
                return false;
            }
        } catch (\Throwable) {
        }

        try {
            if (\class_exists(\Weline\Currency\Data\CurrencyData::class, false)) {
                return \Weline\Currency\Data\CurrencyData::getCurrency($code) !== null;
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private static function detectCurrencyFromRequestPath(): string
    {
        return self::resolveRequestPathLocalization()['currency'];
    }

    /**
     * 取首个可用 URI 的前缀段（最多 3：area / currency / language）。根路径 / 直接返回空数组。
     *
     * @return list<string>
     */
    private static function requestPathPrefixSegments(): array
    {
        $uris = [
            (string)\w_env('origin_request_uri', ''),
            (string)\Weline\Framework\Env\WelineEnv::server('WELINE_ORIGIN_REQUEST_URI', ''),
            (string)\w_env('full_request_uri', ''),
            (string)\w_env('request.uri', ''),
            (string)\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', ''),
        ];

        foreach ($uris as $uri) {
            if ($uri === '' || $uri === '/') {
                continue;
            }

            $path = (string)(parse_url($uri, PHP_URL_PATH) ?: $uri);
            if ($path === '' || $path === '/') {
                continue;
            }

            $segments = array_values(array_filter(
                explode('/', trim($path, '/')),
                static fn (string $segment): bool => $segment !== ''
            ));
            if ($segments === []) {
                continue;
            }

            return \array_slice($segments, 0, 3);
        }

        return [];
    }
}
