<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App;

use Weline\Framework\App\Localization\LocalizationProviderRegistry;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Context;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class State extends DataObject
{
    public const area_backend = 'backend';

    public const area_frontend = 'frontend';

    public const area_base = 'base';

    /**
     * 进程级：按网站 scope 缓存允许语言表（同 Worker 多 Fiber 共享）。
     *
     * @var array<string, array<string, true>>
     */
    private static array $allowedLanguageCodeMapsByScope = [];

    /**
     * 进程级：按网站 scope 缓存允许货币表。
     *
     * @var array<string, array<string, true>>
     */
    private static array $allowedCurrencyCodeMapsByScope = [];

    /**
     * 进程级：按网站 scope 缓存站点默认语言。
     *
     * @var array<string, string>
     */
    private static array $websiteDefaultLanguageByScope = [];

    /**
     * 进程级：按网站 scope 缓存站点默认货币。
     *
     * @var array<string, string>
     */
    private static array $websiteDefaultCurrencyByScope = [];

    private const PROCESS_LOCALIZATION_MAP_MAX = 64;

    /**
     * @var array{
     *     currency: string,
     *     language: string,
     *     area_offset: int,
     *     consumed: int,
     *     remaining: list<string>,
     *     canonical: list<string>
     * }|null 单次请求路径前缀解析缓存
     */
    private static ?array $pathLocalizationCache = null;

    private static bool $pathLocalizationResolving = false;

    public static bool $is_backend = false;

    /** 请求级缓存：getLangLocal() 结果，同请求内只触发一次事件，WLS 下由 StateManager 重置 */
    private static ?string $langLocalCache = null;

    private const LANG_LOCAL_CONTEXT_CACHE = 'state.lang_local_cache';

    /**
     * Request-scoped language override (e.g. theme editor preview locale).
     * Takes priority over Cookie for this request only; never writes cookies.
     */
    private const REQUEST_LANGUAGE_OVERRIDE = 'state.request_language_override';

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
     * 优先级：路径段 / PATH_LANG > 请求覆盖 > query(locale|locale_code|lang) > 区域默认
     *
     * 前台区域默认是网站默认语言。后台区域默认是 backend_default_language，与网站无关。
     * 不读语言偏好 Cookie。非默认语种主 UX 走 /{locale}/...；query 仅作路径缺失时的兼容入口。
     *
     * @return string
     */
    public static function getLang(): string
    {
        // Theme preview / controlled shells may force a locale for this request only.
        $forced = self::getRequestLanguageOverride();
        if ($forced !== '' && self::isLanguageSegmentCandidate($forced)) {
            return self::normalizeLanguageSegment($forced);
        }

        // Path / PATH_LANG must beat frozen StorefrontCacheKeyContext. Under WLS,
        // early controller __() for document title can otherwise lock onto a peer
        // Fiber's frozen lang while later H1 __() already sees the correct path
        // (title×locale: Contact/Guide on zh_Hans_CN). Align with WidgetI18n.
        $pathLang = self::detectLanguageFromRequestPath();
        if ($pathLang !== '' && self::languageCodeAllowedForCurrentArea($pathLang)) {
            return self::normalizeLanguageSegment($pathLang);
        }

        $resolved = StorefrontCacheKeyContext::current();
        if ($resolved?->hasCompleteFrozenScope()) {
            return $resolved->lang;
        }
        $routeLang = self::resolvedRouteLanguage();
        if ($routeLang !== '') {
            return $routeLang;
        }

        $queryLang = self::detectLanguageFromRequestQuery();
        if ($queryLang !== '' && self::languageCodeAllowedForCurrentArea($queryLang)) {
            return self::normalizeLanguageSegment($queryLang);
        }

        return self::resolveAreaDefaultLanguage();
    }

    /**
     * 当前区域的默认语言。后台不读网站默认语言。
     */
    public static function resolveAreaDefaultLanguage(): string
    {
        if (self::currentAreaIsBackend()) {
            return self::resolveBackendEffectiveDefaultLanguage();
        }

        return self::resolveWebsiteDefaultLanguage();
    }

    /**
     * 后台有效默认语言：当前管理员个人语言 > 全局 backend_default_language > zh_Hans_CN。
     */
    public static function resolveBackendEffectiveDefaultLanguage(): string
    {
        if (\class_exists(\Weline\Backend\Service\BackendPersonalLanguage::class)) {
            try {
                $personal = \Weline\Backend\Service\BackendPersonalLanguage::runtimeOverride();
                if ($personal === '') {
                    $personal = \Weline\Backend\Service\BackendPersonalLanguage::resolveForCurrentUser();
                }
                if ($personal !== '') {
                    return $personal;
                }
            } catch (\Throwable) {
            }
        }

        return self::resolveBackendDefaultLanguage();
    }

    private static function currentAreaIsBackend(): bool
    {
        $area = '';
        try {
            $area = (string)\w_env('area', '');
        } catch (\Throwable) {
        }

        return $area === 'backend' || $area === 'rest_backend' || self::isBackend();
    }

    /**
     * 后台路径/查询语言只校验形态，不套网站语种白名单。
     */
    private static function languageCodeAllowedForCurrentArea(string $code): bool
    {
        return self::currentAreaIsBackend()
            ? self::isLanguageCodeShape($code)
            : self::isAllowedLanguageCode($code);
    }

    /**
     * 后台默认语言：运行时覆盖 > env.php backend_default_language > 框架 zh_Hans_CN。
     * 不读取网站 default_language。
     */
    public static function resolveBackendDefaultLanguage(): string
    {
        $candidates = [];
        try {
            $fromRuntime = \trim((string)\Weline\Framework\Env\WelineEnv::get('backend_default_language', ''));
            if ($fromRuntime !== '') {
                $candidates[] = $fromRuntime;
            }
        } catch (\Throwable) {
        }
        try {
            $fromConfig = \trim((string)Env::get('backend_default_language', ''));
            if ($fromConfig !== '') {
                $candidates[] = $fromConfig;
            }
        } catch (\Throwable) {
        }
        $candidates[] = Env::default_LANGUAGE_CODE;

        foreach ($candidates as $candidate) {
            $code = self::normalizeLanguageSegment((string)$candidate);
            if (self::isLanguageSegmentCandidate($code) && !Env::isAreaRoutePathSegment($code)) {
                return $code;
            }
        }

        return Env::default_LANGUAGE_CODE;
    }

    public static function isLanguageCodeShape(string $code): bool
    {
        $code = self::normalizeLanguageSegment($code);

        return self::isLanguageSegmentCandidate($code) && !Env::isAreaRoutePathSegment($code);
    }

    /**
     * Force language for the current request only (no cookie write).
     * Pass empty string to clear the override.
     * Locale shape is validated; allow-list is not required so theme preview can
     * force an installed editor locale even when Cookie preference differs.
     */
    public static function setRequestLanguageOverride(string $locale): void
    {
        $locale = self::normalizeLanguageSegment(\trim($locale));
        $context = Context::getCurrent();
        if ($context === null) {
            return;
        }
        if ($locale === '' || !self::isLanguageSegmentCandidate($locale)) {
            $context->set(self::REQUEST_LANGUAGE_OVERRIDE, null);
        } else {
            $context->set(self::REQUEST_LANGUAGE_OVERRIDE, $locale);
        }
        self::resetLangLocalCache();
    }

    public static function getRequestLanguageOverride(): string
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return '';
        }
        return \trim((string)$context->get(self::REQUEST_LANGUAGE_OVERRIDE, ''));
    }

    /**
     * 获取当前货币
     * 优先级：路径段 > query(currency) > 网站默认 > CNY
     *
     * 不读货币偏好 Cookie；非默认货币主 UX 走 /{CURRENCY}/...；query 仅作路径缺失时的兼容入口。
     *
     * @return string
     */
    public static function getCurrency(): string
    {
        $resolved = StorefrontCacheKeyContext::current();
        if ($resolved?->hasCompleteFrozenScope()) {
            return $resolved->currency;
        }
        $routeCurrency = self::resolvedRouteCurrency();
        if ($routeCurrency !== '') {
            return $routeCurrency;
        }
        $currency = self::detectCurrencyFromRequestPath();
        if ($currency !== '' && self::isAllowedCurrencyCode($currency)) {
            return $currency;
        }

        $queryCurrency = self::detectCurrencyFromRequestQuery();
        if ($queryCurrency !== '' && self::isAllowedCurrencyCode($queryCurrency)) {
            return $queryCurrency;
        }

        return self::resolveWebsiteDefaultCurrency();
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
        $fromSegments = self::resolveRequestPathLocalization()['language'];
        if ($fromSegments !== '') {
            return $fromSegments;
        }

        // Url::detectLanguage / SEO 改写会剥掉路径中的语言段；PATH_LANG 必须仍作路由最高优先。
        try {
            $locked = \trim((string)\Weline\Framework\Env\WelineEnv::server('WELINE_URL_PATH_LANG', ''));
            if ($locked !== '') {
                return self::normalizeLanguageSegment($locked);
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * 解析路径本地化前缀：可选 area 后，currency / language 可单独出现，也可任意顺序组合。
     *
     * 只按路径段形状识别，不读取 allowed language/currency 配置。
     * area 只允许作为第一段精确命中；remaining 不含 area/本地化段，
     * canonical 保留 area 原值并将本地化段固定为 currency -> language。
     *
     * @param list<string> $segments
     * @return array{
     *     currency: string,
     *     language: string,
     *     area_offset: int,
     *     consumed: int,
     *     remaining: list<string>,
     *     canonical: list<string>
     * }
     */
    /**
     * @param bool $detectAreaPrefix When false, never treat the first segment as an area
     *        route key. Use after Url::parser already consumed rest_frontend / rest_backend
     *        so a module router named "api" is not stripped again.
     */
    public static function resolveLocalizationFromPathSegments(array $segments, bool $detectAreaPrefix = true): array
    {
        $segments = \array_values(\array_map(
            static fn(mixed $segment): string => (string)$segment,
            $segments
        ));

        $index = 0;
        $areaOffset = 0;
        if (
            $detectAreaPrefix
            && isset($segments[$index])
            && Env::getAreaByRoutePrefix($segments[$index]) !== null
        ) {
            $index++;
            $areaOffset = 1;
        }

        $currency = '';
        $language = '';
        $consumed = 0;
        while (isset($segments[$index]) && $consumed < 2) {
            $segment = (string)$segments[$index];
            if ($currency === '' && self::isCurrencySegmentCandidate($segment)) {
                $currency = strtoupper($segment);
                $index++;
                $consumed++;
                continue;
            }

            if ($language === '' && self::isLanguageSegmentCandidate($segment)) {
                $language = self::normalizeLanguageSegment($segment);
                $index++;
                $consumed++;
                continue;
            }

            break;
        }

        $remaining = \array_slice($segments, $areaOffset + $consumed);
        $canonical = $areaOffset === 1 ? [$segments[0]] : [];
        if ($currency !== '') {
            $canonical[] = $currency;
        }
        if ($language !== '') {
            $canonical[] = $language;
        }
        $canonical = \array_merge($canonical, $remaining);

        return [
            'currency' => $currency,
            'language' => $language,
            'area_offset' => $areaOffset,
            'consumed' => $consumed,
            'remaining' => $remaining,
            'canonical' => $canonical,
        ];
    }

    /**
     * Rebuild a storefront path that omits website-default language/currency segments
     * and disallowed (not Website-enabled) language prefixes.
     *
     * Path-first contract: default locale/currency must not appear in visitor URLs;
     * locale codes with valid shape that are not in the Website allow-list are also
     * stripped (301) so they cannot render under a fake prefix.
     * Returns null when the path is already canonical or is not a storefront
     * localization prefix (e.g. backend area key as first segment — keep /ja_JP/admin/…).
     */
    public static function canonicalizeStorefrontLocalizationPath(
        string $path,
        string $defaultLanguage,
        string $defaultCurrency,
    ): ?string {
        $path = \trim($path);
        if ($path === '') {
            $path = '/';
        } elseif (!\str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $segments = \array_values(\array_filter(
            \explode('/', \trim($path, '/')),
            static fn(string $segment): bool => $segment !== ''
        ));
        $localized = self::resolveLocalizationFromPathSegments($segments);
        if ((int)($localized['area_offset'] ?? 0) > 0) {
            return null;
        }

        $currency = \strtoupper(\trim((string)($localized['currency'] ?? '')));
        $language = self::normalizeLanguageSegment((string)($localized['language'] ?? ''));
        $defaultLanguage = self::normalizeLanguageSegment($defaultLanguage);
        $defaultCurrency = \strtoupper(\trim($defaultCurrency));

        $omitCurrency = $currency !== '' && $defaultCurrency !== '' && $currency === $defaultCurrency;
        $omitLanguage = $language !== '' && $defaultLanguage !== ''
            && \strcasecmp($language, $defaultLanguage) === 0;
        // Unenabled path language (valid shape, not Website-allowed): strip like default.
        if (!$omitLanguage
            && $language !== ''
            && self::isLanguageCodeShape($language)
            && !self::isAllowedLanguageCode($language)
        ) {
            $omitLanguage = true;
        }
        if (!$omitCurrency && !$omitLanguage) {
            return null;
        }

        $out = [];
        if ($currency !== '' && !$omitCurrency) {
            $out[] = $currency;
        }
        if ($language !== '' && !$omitLanguage) {
            $out[] = $language;
        }
        foreach ((array)($localized['remaining'] ?? []) as $part) {
            $part = \trim((string)$part);
            if ($part === '') {
                continue;
            }
            // Collapse duplicated default localization segments left in remaining
            // (e.g. /USD/USD or /en_US/en_US after a mount/parser glitch) so 301
            // targets stay canonical and never self-loop on the visitor path.
            if ($omitCurrency
                && $defaultCurrency !== ''
                && \strtoupper($part) === $defaultCurrency
                && self::isCurrencySegmentCandidate($part)
            ) {
                continue;
            }
            $normalizedPartLang = self::normalizeLanguageSegment($part);
            if ($omitLanguage
                && $normalizedPartLang !== ''
                && (
                    ($defaultLanguage !== '' && \strcasecmp($normalizedPartLang, $defaultLanguage) === 0)
                    || (
                        self::isLanguageCodeShape($normalizedPartLang)
                        && !self::isAllowedLanguageCode($normalizedPartLang)
                    )
                )
            ) {
                continue;
            }
            $out[] = $part;
        }

        $canonical = $out === [] ? '/' : '/' . \implode('/', $out);
        $original = '/' . \trim($path, '/');
        if ($original === '/') {
            $original = '/';
        }
        if ($canonical === $original) {
            return null;
        }

        return $canonical;
    }

    /**
     * Remove only the exact configured Website path prefix before the shared
     * localization parser runs. Keeping this boundary in State prevents App,
     * Router and FPC from deriving different language/currency dimensions for
     * `/site/USD/en_US/` and `/site/en_US/USD/`.
     */
    public static function stripWebsitePathPrefix(string $path, string $websiteUrl): string
    {
        $path = \trim($path);
        if ($path === '') {
            $path = '/';
        } elseif (!\str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $websiteUrl = \trim($websiteUrl);
        if ($websiteUrl === '') {
            return $path;
        }

        try {
            $websitePath = \trim((string)(\parse_url($websiteUrl, \PHP_URL_PATH) ?: ''), '/');
        } catch (\ValueError) {
            return $path;
        }
        if ($websitePath === '') {
            return $path;
        }

        $requestPath = \trim($path, '/');
        if ($requestPath === $websitePath) {
            return '/';
        }
        if (!\str_starts_with($requestPath, $websitePath . '/')) {
            return $path;
        }

        return '/' . \substr($requestPath, \strlen($websitePath) + 1);
    }

    /**
     * @return array{
     *     currency: string,
     *     language: string,
     *     area_offset: int,
     *     consumed: int,
     *     remaining: list<string>,
     *     canonical: list<string>
     * }
     */
    private static function resolveRequestPathLocalization(): array
    {
        if (self::$pathLocalizationCache !== null) {
            return self::$pathLocalizationCache;
        }

        if (self::$pathLocalizationResolving) {
            return [
                'currency' => '',
                'language' => '',
                'area_offset' => 0,
                'consumed' => 0,
                'remaining' => [],
                'canonical' => [],
            ];
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
     * 语/币允许表与站点默认值是进程级共享，不在此清空。
     */
    public static function resetRequestPathLocalizationCache(): void
    {
        self::$pathLocalizationCache = null;
        self::$pathLocalizationResolving = false;
    }

    /**
     * 网站关联语/币或默认值变更时清空进程级 localization 表。
     */
    public static function clearProcessLocalizationCaches(): void
    {
        self::$allowedLanguageCodeMapsByScope = [];
        self::$allowedCurrencyCodeMapsByScope = [];
        self::$websiteDefaultLanguageByScope = [];
        self::$websiteDefaultCurrencyByScope = [];
    }

    /**
     * 网站默认语言：website.language / WELINE_WEBSITE_LANGUAGE / WebsiteData，再回落到站点允许列表首项。
     */
    public static function resolveWebsiteDefaultLanguage(): string
    {
        $scope = self::currentWebsiteScopeKey();
        if (isset(self::$websiteDefaultLanguageByScope[$scope])) {
            return self::$websiteDefaultLanguageByScope[$scope];
        }

        try {
            if (\class_exists(\Weline\Websites\Data\WebsiteData::class)) {
                $fromWebsite = self::normalizeLanguageSegment(
                    \trim((string)(\Weline\Websites\Data\WebsiteData::getDefaultLanguage() ?? '')),
                );
                if ($fromWebsite !== '' && self::isLanguageSegmentCandidate($fromWebsite)) {
                    $allowedMap = self::resolveAllowedLanguageCodeMap();
                    if ($allowedMap === [] || isset($allowedMap[\strtolower($fromWebsite)])) {
                        return self::rememberWebsiteDefaultLanguage($scope, $fromWebsite);
                    }
                }
            }
        } catch (\Throwable) {
        }

        $candidates = [];
        try {
            $candidates[] = trim((string)\w_env('website.language', ''));
        } catch (\Throwable) {
        }
        try {
            $candidates[] = trim((string)\Weline\Framework\Env\WelineEnv::server('WELINE_WEBSITE_LANGUAGE', ''));
        } catch (\Throwable) {
        }
        try {
            $candidates[] = trim((string)\Weline\Framework\Env\WelineEnv::server('WELINE-WEBSITE-LANG', ''));
        } catch (\Throwable) {
        }

        $allowedMap = self::resolveAllowedLanguageCodeMap();
        foreach ($candidates as $candidate) {
            $code = self::normalizeLanguageSegment((string)$candidate);
            if ($code === '' || !self::isLanguageSegmentCandidate($code)) {
                continue;
            }
            if ($allowedMap !== [] && !isset($allowedMap[strtolower($code)])) {
                continue;
            }

            return self::rememberWebsiteDefaultLanguage($scope, $code);
        }

        if ($allowedMap !== []) {
            try {
                $codes = ObjectManager::getInstance(LocalizationProviderRegistry::class)->preferredLanguageCodes();
                foreach ($codes as $code) {
                    $code = self::normalizeLanguageSegment((string)$code);
                    if ($code !== '' && self::isLanguageSegmentCandidate($code)) {
                        return self::rememberWebsiteDefaultLanguage($scope, $code);
                    }
                }
            } catch (\Throwable) {
            }
        }

        return self::rememberWebsiteDefaultLanguage($scope, 'zh_Hans_CN');
    }

    /**
     * 网站默认货币：website.currency / WELINE_WEBSITE_CURRENCY / WebsiteData / app env，
     * 再回落到站点允许货币列表首项。
     */
    public static function resolveWebsiteDefaultCurrency(): string
    {
        $scope = self::currentWebsiteScopeKey();
        if (isset(self::$websiteDefaultCurrencyByScope[$scope])) {
            return self::$websiteDefaultCurrencyByScope[$scope];
        }

        try {
            if (\class_exists(\Weline\Websites\Data\WebsiteData::class)) {
                $fromWebsite = \strtoupper(\trim((string)(\Weline\Websites\Data\WebsiteData::getDefaultCurrency() ?? '')));
                if (self::isCurrencySegmentCandidate($fromWebsite)) {
                    $allowedMap = self::resolveAllowedCurrencyCodeMap();
                    if ($allowedMap === [] || isset($allowedMap[$fromWebsite])) {
                        return self::rememberWebsiteDefaultCurrency($scope, $fromWebsite);
                    }
                }
            }
        } catch (\Throwable) {
        }

        $candidates = [];
        try {
            $candidates[] = trim((string)\w_env('website.currency', ''));
        } catch (\Throwable) {
        }
        try {
            $candidates[] = trim((string)\Weline\Framework\Env\WelineEnv::server('WELINE_WEBSITE_CURRENCY', ''));
        } catch (\Throwable) {
        }
        try {
            $candidates[] = trim((string)Env::system('currency', ''));
        } catch (\Throwable) {
        }

        $allowedMap = self::resolveAllowedCurrencyCodeMap();
        foreach ($candidates as $candidate) {
            $code = strtoupper(trim((string)$candidate));
            if (!self::isCurrencySegmentCandidate($code)) {
                continue;
            }
            if ($allowedMap !== [] && !isset($allowedMap[$code])) {
                continue;
            }

            return self::rememberWebsiteDefaultCurrency($scope, $code);
        }

        try {
            $codes = ObjectManager::getInstance(LocalizationProviderRegistry::class)->preferredCurrencyCodes();
            foreach (self::normalizeCurrencyCodeList($codes) as $code) {
                if ($allowedMap === [] || isset($allowedMap[$code])) {
                    return self::rememberWebsiteDefaultCurrency($scope, $code);
                }
            }
        } catch (\Throwable) {
        }

        if ($allowedMap !== []) {
            return self::rememberWebsiteDefaultCurrency($scope, (string)array_key_first($allowedMap));
        }

        return self::rememberWebsiteDefaultCurrency($scope, 'CNY');
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
        $scope = self::currentWebsiteScopeKey();
        if (isset(self::$allowedLanguageCodeMapsByScope[$scope])) {
            return self::$allowedLanguageCodeMapsByScope[$scope];
        }

        $map = [];
        try {
            $codes = ObjectManager::getInstance(LocalizationProviderRegistry::class)->preferredLanguageCodes();
            foreach (self::normalizeLanguageCodeList($codes) as $code) {
                $map[strtolower($code)] = true;
            }
        } catch (\Throwable) {
        }

        return self::rememberProcessScopeMap(self::$allowedLanguageCodeMapsByScope, $scope, $map);
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
            && ctype_alpha($segment)
            && !Env::isAreaRoutePathSegment($segment);
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
            return ObjectManager::getInstance(LocalizationProviderRegistry::class)->supportsLanguage($code);
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
        $scope = self::currentWebsiteScopeKey();
        if (isset(self::$allowedCurrencyCodeMapsByScope[$scope])) {
            return self::$allowedCurrencyCodeMapsByScope[$scope];
        }

        $map = [];
        try {
            $codes = ObjectManager::getInstance(LocalizationProviderRegistry::class)->preferredCurrencyCodes();
            foreach (self::normalizeCurrencyCodeList($codes) as $code) {
                $map[$code] = true;
            }
        } catch (\Throwable) {
        }

        return self::rememberProcessScopeMap(self::$allowedCurrencyCodeMapsByScope, $scope, $map);
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
            return ObjectManager::getInstance(LocalizationProviderRegistry::class)->supportsCurrency($code);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function detectCurrencyFromRequestPath(): string
    {
        return self::resolveRequestPathLocalization()['currency'];
    }

    /**
     * Query locale when path has no language segment: locale | locale_code | lang.
     */
    private static function detectLanguageFromRequestQuery(): string
    {
        $params = self::requestQueryParams();
        foreach (['locale', 'locale_code', 'lang'] as $key) {
            $raw = self::normalizeLanguageSegment((string)($params[$key] ?? ''));
            if ($raw === '' || \strtolower($raw) === 'default') {
                continue;
            }
            if (self::isLanguageSegmentCandidate($raw)) {
                return $raw;
            }
        }

        return '';
    }

    /**
     * Query currency when path has no currency segment.
     */
    private static function detectCurrencyFromRequestQuery(): string
    {
        $params = self::requestQueryParams();
        $code = \strtoupper(\trim((string)($params['currency'] ?? '')));
        if ($code !== '' && self::isCurrencySegmentCandidate($code)) {
            return $code;
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestQueryParams(): array
    {
        try {
            $get = \Weline\Framework\Env\WelineEnv::getGet(null, null);
            if (\is_array($get) && $get !== []) {
                return $get;
            }
        } catch (\Throwable) {
        }

        $query = '';
        try {
            $query = (string)\Weline\Framework\Env\WelineEnv::server('QUERY_STRING', '');
        } catch (\Throwable) {
        }
        if ($query === '') {
            return [];
        }

        $params = [];
        \parse_str($query, $params);

        return \is_array($params) ? $params : [];
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

    /**
     * URL 解析完成后的本请求语言（Context route.language / WelineEnv user.lang）。
     */
    private static function resolvedRouteLanguage(): string
    {
        if (!self::isRouteLocalizationParsed()) {
            return '';
        }

        $context = Context::getCurrent();
        if ($context !== null) {
            $lang = self::normalizeLanguageSegment((string)$context->get('route.language', ''));
            if ($lang !== '' && self::isLanguageSegmentCandidate($lang)) {
                return $lang;
            }
        }

        try {
            $lang = self::normalizeLanguageSegment((string)\Weline\Framework\Env\WelineEnv::get('user.lang', ''));
            if ($lang !== '' && self::isLanguageSegmentCandidate($lang)) {
                return $lang;
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * URL 解析完成后的本请求货币。
     */
    private static function resolvedRouteCurrency(): string
    {
        if (!self::isRouteLocalizationParsed()) {
            return '';
        }

        $context = Context::getCurrent();
        if ($context !== null) {
            $currency = \strtoupper(\trim((string)$context->get('route.currency', '')));
            if (self::isCurrencySegmentCandidate($currency)) {
                return $currency;
            }
        }

        try {
            $currency = \strtoupper(\trim((string)\Weline\Framework\Env\WelineEnv::get('user.currency', '')));
            if (self::isCurrencySegmentCandidate($currency)) {
                return $currency;
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private static function isRouteLocalizationParsed(): bool
    {
        $context = Context::getCurrent();
        if ($context !== null && (bool)$context->get('route.url_parsed', false)) {
            return true;
        }

        try {
            return (bool)\Weline\Framework\Env\WelineEnv::get('url_parsed', false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 进程级网站 scope：优先 website_id，其次 website.code。
     */
    private static function currentWebsiteScopeKey(): string
    {
        $websiteId = '';
        try {
            $websiteId = \trim((string)\w_env('website_id', ''));
        } catch (\Throwable) {
        }
        if ($websiteId === '') {
            try {
                $websiteId = \trim((string)\Weline\Framework\Env\WelineEnv::server('WELINE_WEBSITE_ID', ''));
            } catch (\Throwable) {
            }
        }
        if ($websiteId !== '') {
            return 'id:' . $websiteId;
        }

        $code = '';
        try {
            $code = \trim((string)\w_env('website.code', ''));
        } catch (\Throwable) {
        }
        if ($code === '') {
            try {
                $code = \trim((string)\Weline\Framework\Env\WelineEnv::server('WELINE_WEBSITE_CODE', ''));
            } catch (\Throwable) {
            }
        }
        if ($code !== '') {
            return 'code:' . $code;
        }

        return 'none';
    }

    private static function rememberWebsiteDefaultLanguage(string $scope, string $language): string
    {
        self::rememberProcessScopeScalar(self::$websiteDefaultLanguageByScope, $scope, $language);

        return $language;
    }

    private static function rememberWebsiteDefaultCurrency(string $scope, string $currency): string
    {
        self::rememberProcessScopeScalar(self::$websiteDefaultCurrencyByScope, $scope, $currency);

        return $currency;
    }

    /**
     * @param array<string, string> $bucket
     */
    private static function rememberProcessScopeScalar(array &$bucket, string $scope, string $value): void
    {
        if (\count($bucket) >= self::PROCESS_LOCALIZATION_MAP_MAX && !\array_key_exists($scope, $bucket)) {
            $bucket = \array_slice($bucket, -((int)(self::PROCESS_LOCALIZATION_MAP_MAX / 2)), null, true);
        }
        $bucket[$scope] = $value;
    }

    /**
     * @param array<string, array<string, true>> $bucket
     * @param array<string, true> $map
     * @return array<string, true>
     */
    private static function rememberProcessScopeMap(array &$bucket, string $scope, array $map): array
    {
        if (\count($bucket) >= self::PROCESS_LOCALIZATION_MAP_MAX && !\array_key_exists($scope, $bucket)) {
            $bucket = \array_slice($bucket, -((int)(self::PROCESS_LOCALIZATION_MAP_MAX / 2)), null, true);
        }
        $bucket[$scope] = $map;

        return $map;
    }
}
