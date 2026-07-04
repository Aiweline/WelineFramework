<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http;

use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Context;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

final class UrlParserRequestState
{
    public array $parserServer = [];
    public array $parserMatchs = [];
    public array $parserCache = [];
    public array $decodeUrls = [];
    public bool $parsingInProgress = false;
    /** 当前请求 ID —— 用于检测 WLS 多请求复用 Fiber 时的状态泄漏 */
    public ?string $requestId = null;
}

class Url implements UrlInterface
{
    private const PARSER_SITES_VERSION_CACHE_KEY = 'websites.url.parser_sites_version.v1';
    private const PARSER_SITES_VERSION_CHECK_INTERVAL_SECONDS = 300.0;
    private const PARSER_SITES_REMOTE_VERSION_CHECK_INTERVAL_SECONDS = 300.0;
    private const PARSER_SITES_VERSION_FILE = 'var/cache/website_detect/parser_sites.version';
    private const PROCESS_DECODE_CACHE_TTL = 300;
    private const PROCESS_DECODE_CACHE_MAX_ITEMS = 4096;

    /**
     * @deprecated WLS 模式下此属性会过期。始终使用 $this->getRequest() 获取当前请求。
     */
    protected Request $request;

    public function __construct(
        Request $request
    )
    {
        $this->request = $request;
    }

    private static ?UrlParserRequestState $mainParserState = null;
    /** @var \WeakMap<\Fiber, UrlParserRequestState>|null */
    private static ?\WeakMap $fiberParserStates = null;
    private static string $parserSitesVersion = '';
    private static float $parserSitesVersionCheckedAt = 0.0;
    
    /**
     * 获取当前请求对象
     * 
     * WLS 模式下 Url 是单例，构造函数注入的 $this->request 指向首次创建时的 Request，
     * 后续请求的新 WlsRequest 被注册到 ObjectManager 后，单例仍持有旧引用。
     * 此方法始终从 ObjectManager 获取最新的 Request 实例，避免跨请求状态泄漏。
     * 
     * @return Request
     */
    protected function getRequest(): Request
    {
        return ObjectManager::getInstance(Request::class);
    }
    
    /**
     * 判断当前请求是否为后端区域
     * 
     * WLS 模式下必须使用 $_SERVER['WELINE_AREA'] 判断，
     * 而不是依赖 Request 对象的 isBackend() 方法，
     * 因为 Url 是单例，其内部的 Request 引用可能指向旧的请求对象。
     * 
     * @return bool
     */
    protected function isCurrentAreaBackend(): bool
    {
        // 优先使用 $_SERVER['WELINE_AREA']，这是每个请求都会更新的
        $area = w_env('area', '');
        if ($area !== '') {
            return ($area === 'backend' || $area === 'rest_backend');
        }
        
        // 回退到 Request 对象判断（非 WLS 模式）
        return $this->getRequest()->isBackend();
    }

    /**
     * @param mixed $uri
     * @param string $code
     * @return bool
     */
    public static function detectLanguage(string &$uri, string $code): bool
    {
        $parserCacheKey = self::parserValidationStaticKey('language', $code);
        if (isset(self::$parserLanguages[$parserCacheKey])) {
            if (str_starts_with($uri, '/' . $code)) {
                $uri = substr($uri, strlen('/' . $code));
            }
            self::$parserServer['WELINE_USER_LANG'] = $code;
            self::rememberParserLanguage($code);
            self::traceLanguageValidation('static_hit', $code, $uri, ['parser_key' => $parserCacheKey]);
            return true;
        }

        // 优化：使用语言缓存，避免重复数据库查询
        self::ensureParserValidationMetadataLoaded();
        if (self::hasAuthoritativeParserValidationMetadata() && !isset(self::$knownParserLanguageCodes[\strtolower($code)])) {
            self::traceLanguageValidation('static_deny', $code, $uri, ['parser_key' => $parserCacheKey]);
            return false;
        }

        $cache = w_cache('i18n');
        $checkCacheKey = self::validationCacheKey('lang_check', $code);
        $checkResult = $cache->get($checkCacheKey);
        
        if ($checkResult !== null && $checkResult !== false) {
            if ((bool)$checkResult) {
                // 找到语言，更新URI并设置
                if (str_starts_with($uri, '/' . $code)) {
                    $uri = substr($uri, strlen('/' . $code));
                }
                self::$parserServer['WELINE_USER_LANG'] = $code;
                self::rememberParserLanguage($code);
                self::traceLanguageValidation('remote_hit_allow', $code, $uri, ['cache_key' => $checkCacheKey]);
                return true;
            }
            // 明确知道语言不存在，直接返回
            self::traceLanguageValidation('remote_hit_deny', $code, $uri, ['cache_key' => $checkCacheKey]);
            return false;
        }
        self::traceLanguageValidation('remote_miss', $code, $uri, ['cache_key' => $checkCacheKey]);
        
        // 缓存未命中，分发事件查询数据库
        # 必须有前两个字符是否都是小写字母,且第三个字符必须是_
        $data = new DataObject([
            'result' => false,
            'uri' => $uri,
            'code' => $code
        ]);
        /** @var EventsManager $eventManager */
        $eventManager = w_obj(EventsManager::class);
        $eventManager->dispatch('Weline_Framework_Url::detect_language', $data);
        
        $result = $data->getData('result');
        self::traceLanguageValidation('event_result', $code, $uri, [
            'cache_key' => $checkCacheKey,
            'result' => (bool)$result,
            'error' => (string)$data->getData('error'),
        ]);
        
        // 保存检查结果到缓存
        $cache->set($checkCacheKey, $result ? 1 : 0, $result ? 86400 : 300);
        
        if ($result) {
            if (str_starts_with($uri, '/' . $code)) {
                $uri = substr($uri, strlen('/' . $code));
            }
            self::$parserServer['WELINE_USER_LANG'] = $code;
            self::rememberParserLanguage($code);
            self::traceLanguageValidation('event_allow', $code, $uri, ['cache_key' => $checkCacheKey]);
            return true;
        }
        self::traceLanguageValidation('event_deny', $code, $uri, ['cache_key' => $checkCacheKey]);
        return false;
    }

    private static function traceLanguageValidation(string $stage, string $code, string $uri, array $extra = []): void
    {
        $currentServer = self::currentServer();
        $requestUri = (string)(
            self::$parserServer['WELINE_FULL_REQUEST_URI']
            ?? self::$parserServer['REQUEST_URI']
            ?? ($currentServer['WELINE_FULL_REQUEST_URI'] ?? $currentServer['REQUEST_URI'] ?? '')
        );
        if ($requestUri === '' || (!str_contains($requestUri, 'debug_sidebar=1') && !str_contains($requestUri, 'ai_url_debug=1'))) {
            return;
        }

        $context = [
            'stage' => $stage,
            'code' => $code,
            'uri' => $uri,
            'request_uri' => $requestUri,
            'scope' => self::currentWebsiteValidationScope(),
            'parser_lang' => (string)(self::$parserServer['WELINE_USER_LANG'] ?? ''),
            'website_id' => (string)(self::$parserServer['WELINE_WEBSITE_ID'] ?? ''),
            'website_code' => (string)(self::$parserServer['WELINE_WEBSITE_CODE'] ?? ''),
        ] + $extra;

        \error_log('[UrlDetectLanguageTrace] ' . (\json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'));
    }

    /**
     * @param mixed $uri
     * @param string $code
     * @return bool
     */
    public static function detectCurrency(string &$uri, string $code): bool
    {
        // 优化：使用静态缓存，避免重复查询
        $codeUpper = strtoupper($code);
        if (Env::isAreaRoutePathSegment($codeUpper)) {
            return false;
        }

        $parserCacheKey = self::parserValidationStaticKey('currency', $codeUpper);
        if (isset(self::$parserCurrencies[$parserCacheKey])) {
            if (str_starts_with($uri, '/' . $code)) {
                $uri = substr($uri, strlen('/' . $code));
            }
            self::$parserServer['WELINE_USER_CURRENCY'] = $codeUpper;
            return true;
        }
        
        // 优化：使用货币缓存，避免重复数据库查询
        self::ensureParserValidationMetadataLoaded();
        if (self::hasAuthoritativeParserValidationMetadata() && !isset(self::$knownParserCurrencyCodes[$codeUpper])) {
            return false;
        }

        $cache = w_cache('currency');
        $currencyCacheKey = self::validationCacheKey('currency_code', $codeUpper);
        $currency = $cache->get($currencyCacheKey);
        
        if ($currency !== null && $currency !== false) {
            $isAllowed = $currency === 1
                || $currency === '1'
                || $currency === true
                || (\is_array($currency) && isset($currency['code']));
            if (!$isAllowed) {
                if ($currency === 0 || $currency === '0' || $currency === []) {
                    return false;
                }
            } else {
                if (str_starts_with($uri, '/' . $code)) {
                    $uri = substr($uri, strlen('/' . $code));
                }
                self::$parserServer['WELINE_USER_CURRENCY'] = $codeUpper;
                self::rememberParserCurrency($codeUpper);
                return true;
            }

            return false;
        }

        if (is_array($currency) && isset($currency['code'])) {
            // 找到货币，更新URI并设置
            if (str_starts_with($uri, '/' . $code)) {
                $uri = substr($uri, strlen('/' . $code));
            }
            self::$parserServer['WELINE_USER_CURRENCY'] = $codeUpper;
            // 缓存成功的货币代码到静态缓存
            self::rememberParserCurrency($codeUpper);
            return true;
        }
        
        // 缓存未命中，分发事件查询数据库
        $detect_currency_data = new DataObject([
            'result' => false,
            'uri' => $uri,
            'code' => $code,
        ]);
        /** @var EventsManager $eventManager */
        $eventManager = w_obj(EventsManager::class);
        $eventManager->dispatch('Weline_Framework_Url::detect_currency', $detect_currency_data);
        $uri_ = $detect_currency_data->getData('uri');
        $result = (bool)$detect_currency_data->getData('result');

        $cache->set($currencyCacheKey, $result ? 1 : 0, $result ? 86400 : 300);

        if ($result) {
            if (str_starts_with($uri_, '/' . $code)) {
                $uri = substr($uri_, strlen('/' . $code));
            }
            self::$parserServer['WELINE_USER_CURRENCY'] = $codeUpper;
            // 缓存成功的货币代码到静态缓存
            self::rememberParserCurrency($codeUpper);
        }
        return $result;
    }

    private static function ensureParserValidationMetadataLoaded(): void
    {
        if (self::$parserValidationMetadataLoaded) {
            return;
        }

        try {
            $currencyCodes = self::loadKnownCurrencyCodes();
            $languageCodes = self::loadKnownLanguageCodes();

            self::$knownParserCurrencyCodes = [];
            foreach ($currencyCodes as $currencyCode) {
                $currencyCode = \strtoupper((string)$currencyCode);
                if ($currencyCode !== '') {
                    self::$knownParserCurrencyCodes[$currencyCode] = true;
                }
            }

            self::$knownParserLanguageCodes = [];
            foreach ($languageCodes as $languageCode) {
                $languageCode = (string)$languageCode;
                if ($languageCode !== '') {
                    self::$knownParserLanguageCodes[\strtolower($languageCode)] = true;
                }
            }

            self::$parserValidationMetadataLoaded = true;
        } catch (\Throwable) {
        }
    }

    private static function hasAuthoritativeParserValidationMetadata(): bool
    {
        return self::$parserValidationMetadataLoaded
            && (self::$knownParserCurrencyCodes !== [] || self::$knownParserLanguageCodes !== []);
    }

    private static function validationCacheKey(string $prefix, string $code): string
    {
        return $prefix . ':v4:' . self::currentWebsiteValidationScope() . ':' . strtolower($code);
    }

    private static function parserValidationStaticKey(string $prefix, string $code): string
    {
        return $prefix . ':' . self::currentWebsiteValidationScope() . ':' . strtolower($code);
    }

    private static function rememberParserLanguage(string $code): void
    {
        self::$parserLanguages[self::parserValidationStaticKey('language', $code)] = $code;
    }

    private static function hasRememberedParserLanguage(string $code): bool
    {
        return isset(self::$parserLanguages[self::parserValidationStaticKey('language', $code)]);
    }

    private static function rememberParserCurrency(string $code): void
    {
        $codeUpper = strtoupper($code);
        self::$parserCurrencies[self::parserValidationStaticKey('currency', $codeUpper)] = $codeUpper;
    }

    public static function preloadWorkerRoutingMetadata(): void
    {
        $previousParserServer = self::$parserServer;

        try {
            self::ensureParserSitesLoaded();
            $scopes = self::knownWebsiteValidationScopes();
            $currencyCodes = self::loadKnownCurrencyCodes();
            $languageCodes = self::loadKnownLanguageCodes();
            self::$knownParserCurrencyCodes = [];
            foreach ($currencyCodes as $currencyCode) {
                $currencyCode = \strtoupper((string)$currencyCode);
                if ($currencyCode !== '') {
                    self::$knownParserCurrencyCodes[$currencyCode] = true;
                }
            }
            self::$knownParserLanguageCodes = [];
            foreach ($languageCodes as $languageCode) {
                $languageCode = (string)$languageCode;
                if ($languageCode !== '') {
                    self::$knownParserLanguageCodes[\strtolower($languageCode)] = true;
                }
            }
            self::$parserValidationMetadataLoaded = true;

            foreach ($scopes as $scope => $defaults) {
                $defaultCurrency = (string)($defaults['currency'] ?? '');
                if ($defaultCurrency !== '') {
                    $currencyCodes[\strtoupper($defaultCurrency)] = \strtoupper($defaultCurrency);
                }

                $defaultLanguage = (string)($defaults['language'] ?? '');
                if ($defaultLanguage !== '') {
                    $languageCodes[$defaultLanguage] = $defaultLanguage;
                }

                foreach ($currencyCodes as $currencyCode) {
                    $currencyCode = \strtoupper((string)$currencyCode);
                    if ($currencyCode !== '') {
                        self::$parserCurrencies[self::parserValidationStaticKeyForScope('currency', $scope, $currencyCode)] = $currencyCode;
                    }
                }

                foreach ($languageCodes as $languageCode) {
                    $languageCode = (string)$languageCode;
                    if ($languageCode !== '') {
                        self::$parserLanguages[self::parserValidationStaticKeyForScope('language', $scope, $languageCode)] = $languageCode;
                    }
                }
            }
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[UrlParserWarmup] ' . $e->getMessage());
            }
        } finally {
            self::$parserServer = $previousParserServer;
        }
    }

    /**
     * @return array<string, array{currency?: string, language?: string}>
     */
    private static function knownWebsiteValidationScopes(): array
    {
        $scopes = ['global' => []];
        foreach (self::$parserSites as $site) {
            if (!\is_array($site)) {
                continue;
            }

            $defaults = [
                'currency' => (string)($site['default_currency'] ?? ''),
                'language' => (string)($site['default_language'] ?? ''),
            ];

            $websiteId = (int)($site['website_id'] ?? 0);
            if ($websiteId >= 0) {
                $scopes['website:' . $websiteId] = $defaults;
            }

            $websiteCode = (string)($site['code'] ?? '');
            if ($websiteCode !== '') {
                $scopes['website_code:' . \sha1(\strtolower($websiteCode))] = $defaults;
            }
        }

        return $scopes;
    }

    /**
     * @return array<string, string>
     */
    private static function loadKnownCurrencyCodes(): array
    {
        $codes = [];
        try {
            if (!\class_exists(\Weline\Currency\Model\Currency::class)) {
                return $codes;
            }

            $currencyModel = ObjectManager::getInstance(\Weline\Currency\Model\Currency::class);
            $rows = $currencyModel->clear()->select()->fetchArray();
            foreach ((array)$rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $code = \strtoupper(\trim((string)($row[\Weline\Currency\Model\Currency::schema_fields_CODE] ?? '')));
                if ($code !== '') {
                    $codes[$code] = $code;
                }
            }
        } catch (\Throwable) {
        }

        return $codes;
    }

    /**
     * @return array<string, string>
     */
    private static function loadKnownLanguageCodes(): array
    {
        $codes = [];
        try {
            if (!\class_exists(\Weline\I18n\Model\Locals::class)) {
                return $codes;
            }

            $localModel = ObjectManager::getInstance(\Weline\I18n\Model\Locals::class);
            $rows = $localModel->clear()
                ->where(\Weline\I18n\Model\Locals::schema_fields_IS_INSTALL, 1)
                ->where(\Weline\I18n\Model\Locals::schema_fields_IS_ACTIVE, 1)
                ->select()
                ->fetchArray();
            foreach ((array)$rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $code = \trim((string)($row[\Weline\I18n\Model\Locals::schema_fields_CODE] ?? ''));
                if ($code !== '') {
                    $codes[$code] = $code;
                }
            }
        } catch (\Throwable) {
        }

        return $codes;
    }

    private static function parserValidationStaticKeyForScope(string $prefix, string $scope, string $code): string
    {
        return $prefix . ':' . $scope . ':' . \strtolower($code);
    }

    private static function currentWebsiteValidationScope(): string
    {
        if (\array_key_exists('WELINE_WEBSITE_ID', self::$parserServer)) {
            $parserWebsiteId = (int)(self::$parserServer['WELINE_WEBSITE_ID'] ?? 0);
            return 'website:' . $parserWebsiteId;
        }

        $parserWebsiteCode = (string)(self::$parserServer['WELINE_WEBSITE_CODE'] ?? '');
        if ($parserWebsiteCode !== '') {
            return 'website_code:' . sha1(strtolower($parserWebsiteCode));
        }

        try {
            if (\class_exists(\Weline\Websites\Data\WebsiteData::class, false)) {
                $website = \Weline\Websites\Data\WebsiteData::getWebsite();
                if ($website !== null && \method_exists($website, 'getWebsiteId')) {
                    $websiteId = (int)$website->getWebsiteId();
                    if ($websiteId >= 0) {
                        return 'website:' . $websiteId;
                    }
                }
            }
        } catch (\Throwable) {
        }

        $context = Context::getCurrent();
        if ($context?->has('route.website_id')) {
            $websiteId = (int)($context->get('route.website_id', 0) ?? 0);
            return 'website:' . $websiteId;
        }

        $websiteCode = (string)($context?->get('route.website_code', '') ?? '');
        if ($websiteCode !== '') {
            return 'website_code:' . sha1(strtolower($websiteCode));
        }

        $currentServer = self::currentServer();
        $host = (string)(
            $context?->get('input.host', '')
            ?: ($context?->server('HTTP_HOST', '') ?? '')
            ?: ($currentServer['HTTP_HOST'] ?? $currentServer['SERVER_NAME'] ?? '')
        );

        return $host !== '' ? 'host:' . sha1(strtolower($host)) : 'global';
    }

    public function getApiUrl(string $path = '', array $params = [], bool $merge_url_params = true): string
    {
        # 判断前后端 - WLS 模式下使用 $_SERVER['WELINE_AREA'] 判断，避免对象状态污染
        if ($this->isCurrentAreaBackend()) {
            return $this->getBackendApiUrl($path, $params, $merge_url_params);
        } else {
            return $this->getFrontendApiUrl($path, $params, $merge_url_params);
        }
    }

    public function getBackendApiUrl(string $path = '', array $params = [], bool $merge_url_params = true): string
    {
        if ($path) {
            if (!$this->isLink($path)) {
                # URL自带星号处理
                $router = $this->getRequest()->getRouterData('router');
                if (str_contains($path, '*')) {
                    $path = str_replace('*', (string)($router ?? ''), $path);
                    $path = str_replace('//', '/', $path);
                }
                $url = $this->getRequest()->getBaseHost() . '/' . Env::getAreaRoutePrefix('rest_backend') . '/' . $path;
            } else {
                $url = $path;
            }
        } else {
            $url = $this->getRequest()->getBaseUrl();
        }
        return $this->extractedUrl($params, $merge_url_params, $url);
    }

    public function getFrontendApiUrl(string $path = '', array $params = [], bool $merge_url_params = true): string
    {
        $prefix = self::getLocalePrefix();
        if ($path) {
            if (!$this->isLink($path)) {
                # URL自带星号处理
                $router = $this->getRequest()->getRouterData('router');
                if (str_contains($path, '*')) {
                    $path = str_replace('*', (string)($router ?? ''), $path);
                    $path = str_replace('//', '/', $path);
                }

                // 获取 REST 前端 API 前缀
                $restFrontendPrefix = Env::getAreaRoutePrefix('rest_frontend') ?: 'api';
                $apiAreaPrefix = '/' . strtolower($restFrontendPrefix) . '/';

                // 默认货币/语言不输出路径段，仅在显式非默认值时输出。
                $prefixPath = '' === $prefix ? '' : ltrim($prefix, '/') . '/';
                $url = $this->getRequest()->getBaseHost() . $apiAreaPrefix . $prefixPath . ltrim($path, '/');
            } else {
                $url = $path;
            }
        } else {
            // 获取 REST 前端 API 前缀
            $restFrontendPrefix = Env::getAreaRoutePrefix('rest_frontend') ?: 'api';
            $apiAreaPrefix = '/' . strtolower($restFrontendPrefix) . '/';

            // 构建URL: /{area_prefix}/{currency?}/{language?}
            $url = $this->getRequest()->getBaseHost() . $apiAreaPrefix . ltrim($prefix . '/', '/');
        }
        return $this->extractedUrl($params, $merge_url_params, $url);
    }

    public function getFrontendUrl(string $path = '', array $params = [], bool $merge_url_params = false)
    {
        if ($path) {
            if (!$this->isLink($path)) {
                # URL自带星号处理
                $router = $this->getRequest()->getRouterData('router');
                if (str_contains($path, '*')) {
                    $path = str_replace('*', (string)($router ?? ''), $path);
                    $path = str_replace('//', '/', $path);
                }
                $url = $this->getRequest()->getBaseHost() . self::getPrefix() . '/' . ltrim($path, '/');
            } else {
                $url = $path;
            }
        } else {
            $url = $this->getRequest()->getBaseUrl();
        }
        return $this->extractedUrl($params, $merge_url_params, $url);
    }

    public function getUrl(string $path = '', array $params = [], bool $merge_url_params = false): string
    {
        // WLS 模式下使用 $_SERVER['WELINE_AREA'] 判断，避免对象状态污染
        if ($this->isCurrentAreaBackend()) {
            return $this->getBackendUrl($path, $params, $merge_url_params);
        } else {
            return $this->getFrontendUrl($path, $params, $merge_url_params);
        }
    }

    /**
     * 判断是否同站链接
     * @param string $url
     * @return bool
     */
    public static function is_same_site(string $url): bool
    {
        $parse = self::parser($url);
        $url_site = $parse['website_url'] ?? '';
        /** @var Request $req */
        $req = w_obj(Request::class);
        $req_parse = self::parser($req->getUrlBuilder()->getCurrentUrl());
        $req_host = $req_parse['website_url'] ?? '';
        return $url_site === $req_host;
    }

    public function getOriginUrl(string $path = '', array $params = [], bool $merge_url_params = false): string
    {
        if ($path) {
            if (!$this->isLink($path)) {
                # URL自带星号处理
                $router = $this->getRequest()->getRouterData('router');
                if (str_contains($path, '*')) {
                    $path = str_replace('*', (string)($router ?? ''), $path);
                    $path = str_replace('//', '/', $path);
                }
                $url = $this->getRequest()->getBaseHost() . '/' . ltrim($path, '/');
            } else {
                $url = $path;
            }
        } else {
            $url = $this->getRequest()->getBaseUrl();
        }
        return $this->extractedUrl($params, $merge_url_params, $url);
    }

    static function getPrefix()
    {
        $prefix = '';

        $currency = self::normalizeCurrency(w_env('user.currency'));
        $language = self::normalizeLanguage(w_env('user.lang'));
        $websiteCurrency = self::normalizeCurrency(w_env('website.currency')) ?: self::getFrameworkDefaultCurrency();
        $websiteLanguage = self::normalizeLanguage(w_env('website.language')) ?: self::getFrameworkDefaultLanguage();
        $frameworkCurrency = self::getFrameworkDefaultCurrency();
        $frameworkLanguage = self::getFrameworkDefaultLanguage();

        if (Env::isAreaRoutePathSegment($currency)) {
            $currency = '';
        }
        if ('' !== $currency && !State::isAllowedCurrencyCode($currency)) {
            $currency = '';
        }

        if (Env::isAreaRoutePathSegment($language)) {
            $language = '';
        }

        // 默认值（网站默认或框架默认）不拼接到 URL 段中。
        if ('' !== $currency && $currency !== $websiteCurrency && $currency !== $frameworkCurrency) {
            $prefix .= '/' . $currency;
        }

        if ('' !== $language && $language !== $websiteLanguage && $language !== $frameworkLanguage) {
            $prefix .= '/' . $language;
        }

        return $prefix;
    }

    private static function getLocalePrefix(): string
    {
        return self::getPrefix();
    }

    private static function getFrameworkDefaultCurrency(): string
    {
        $currency = self::normalizeCurrency(Env::get('currency', 'CNY'));
        return '' !== $currency ? $currency : 'CNY';
    }

    private static function getFrameworkDefaultLanguage(): string
    {
        $language = self::normalizeLanguage(Env::get('locale', Env::get('lang', 'zh_Hans_CN')));
        return '' !== $language ? $language : 'zh_Hans_CN';
    }

    private static function normalizeCurrency(mixed $currency): string
    {
        $value = strtoupper(trim((string)$currency));
        return $value;
    }

    private static function normalizeLanguage(mixed $language): string
    {
        return trim((string)$language);
    }

    public static function removeExtraDoubleSlashes(null|string $url = ''): string
    {
        if ('' === $url || null === $url) {
            return '';
        }
        $parts = parse_url($url);
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $rest = str_replace('//', '/', substr($url, strlen($scheme)));
        return $scheme . $rest;
    }

    public function getBackendUrl(string $path = '', array $params = [], bool $merge_url_params = false): string
    {
        if ($path) {
            if (!$this->isLink($path)) {
                # URL自带星号处理
                $router = $this->getRequest()->getRouterData('router');
                if (str_contains($path, '*')) {
                    $path = str_replace('*', (string)($router ?? ''), $path);
                    $path = str_replace('//', '/', $path);
                }
                $url = $this->getRequest()->getBaseHost() . '/' . Env::getAreaRoutePrefix('backend') . self::getPrefix() . (('/' === $path) ? '/' : '/' . ltrim($path, '/'));
            } else {
                $url = $path;
            }
        } else {
            $url = $this->getRequest()->getOriginBaseUrl();
        }

        return $this->extractedUrl($params, $merge_url_params, $url);
    }

    /**
     * 获取后端 URL 的路径部分（不含 scheme/host/port），用于前端 fetch 等，相对当前页 origin 请求，避免端口丢失
     *
     * @param string $path
     * @param array  $params
     * @param bool   $merge_url_params
     * @return string 以 / 开头的 path + query
     */
    public function getBackendUrlPath(string $path = '', array $params = [], bool $merge_url_params = false): string
    {
        $full = $this->getBackendUrl($path, $params, $merge_url_params);
        $pathPart = parse_url($full, PHP_URL_PATH);
        $query = parse_url($full, PHP_URL_QUERY);
        return ($pathPart ?? '') . ($query !== null && $query !== '' ? '?' . $query : '');
    }

    /**
     * 获取后端 API URL 的路径部分（不含 scheme/host/port），用于前端 fetch 等，相对当前页 origin 请求，避免端口丢失
     *
     * @param string $path
     * @param array  $params
     * @param bool   $merge_url_params
     * @return string 以 / 开头的 path + query
     */
    public function getBackendApiUrlPath(string $path = '', array $params = [], bool $merge_url_params = true): string
    {
        $full = $this->getBackendApiUrl($path, $params, $merge_url_params);
        $pathPart = parse_url($full, PHP_URL_PATH);
        $query = parse_url($full, PHP_URL_QUERY);
        return ($pathPart ?? '') . ($query !== null && $query !== '' ? '?' . $query : '');
    }

    public function getOriginBackendUrl(string $path = '', array $params = [], bool $merge_url_params = false): string
    {
        if ($path) {
            if (!$this->isLink($path)) {
                # URL自带星号处理
                $router = $this->getRequest()->getRouterData('backend_router');
                if (str_contains($path, '*')) {
                    $path = str_replace('*', (string)($router ?? ''), $path);
                    $path = str_replace('//', '/', $path);
                }
                $url = $this->getRequest()->getBaseHost() . '/' . Env::getAreaRoutePrefix('backend') . (('/' === $path) ? '' : '/' . ltrim($path, '/'));
            } else {
                $url = $path;
            }
        } else {
            $url = $this->getRequest()->getBaseUrl();
        }
        return $this->extractedUrl($params, $merge_url_params, $url);
    }

    /**
     * @DESC          # 获取URL
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/9/22 22:33
     * 参数区：
     *
     * @param string $path
     *
     * @return string
     */
    public function getUri(string $path = ''): string
    {
        if (!$path) {
            return $this->getRequest()->getUri();
        }
        if ($position = strpos($path, '?')) {
            $path = substr($path, 0, $position);
        }
        return $path;
    }

    /**
     * @DESC          # 提取Url
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/2/8 23:27
     * 参数区：
     *
     * @param array $params
     * @param bool $merge_url_params
     * @param string $url
     *
     * @return string
     */
    public function extractedUrl(array $params, bool $merge_url_params = false, string $url = ''): string
    {
        if (empty($url)) {
            $url = $this->getRequest()->getBaseUrl();
        }
        $url_params = [];
        if (strpos($url, '?') !== false) {
            $url_arrs = explode('?', $url);
            $url_query = $url_arrs[1];
            // 确保 $url_query 是字符串
            if (is_string($url_query)) {
                $url_params = explode('&', $url_query);
                foreach ($url_params as $key => $url_param) {
                    unset($url_params[$key]);
                    // 确保 $url_param 是字符串
                    if (is_string($url_param)) {
                        $url_param_arr = explode('=', $url_param);
                        $url_params[$url_param_arr[0]] = $url_param_arr[1] ?? '';
                    }
                }
            }
            $url = $url_arrs[0];
            // 如果原url有参数，并且没有传入参数，则将原url的参数赋值给$params 避免参数丢失
            if ($url_params and empty($params)) {
                $params = $url_params;
            }
        }
        if ($url_params) {
            if ($merge_url_params) {
                if ($params) {
                    $params = array_merge($url_params, $params);
                } else {
                    $params = $url_params;
                }
            } else {
                $params = $url_params;
            }
        }
        if ($merge_url_params) {
            $getParams = $this->getRequest()->getGet();
            // 过滤掉数组值，只保留字符串值
            foreach ($getParams as $key => $value) {
                if (is_array($value)) {
                    unset($getParams[$key]);
                }
            }
            $params = array_merge($getParams, $params);
        }
        if ($params) {
            // 过滤掉数组值，避免在 http_build_query 时出现问题
            foreach ($params as $key => $param) {
                if (empty($param) || is_array($param)) {
                    unset($params[$key]);
                }
            }
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
        }
        $url = self::removeExtraDoubleSlashes($url);
        if (Env::get('seo')) {
            /** @var EventsManager $eventManager */
            $eventManager = ObjectManager::getInstance(EventsManager::class);
            $eventManager->dispatch('Weline_Framework_Url::url_generate_rewrite', $url);
        }
        return $url;
    }

    public function isLink($path): bool
    {
        if (str_starts_with($path, 'https://') || str_starts_with($path, 'http://') || str_starts_with($path, '//')) {
            return true;
        }
        return false;
    }

    /**
     * 获取当前请求的协议（静态方法，可在任何地方调用）
     * 
     * @return string 'http' 或 'https'
     */
    public static function getCurrentScheme(): string
    {
        // 按优先级检查各种协议来源
        $sources = [
            w_env('request.scheme'),
            w_env('server.https'),
            w_env('http_x_forwarded_proto'),
            w_env('http_weline_original_scheme'),
            w_env('server.server_port'),
        ];

        foreach ($sources as $value) {
            if ($value === 'https' || $value === 'on' || $value === '1' || $value === '443') {
                return 'https';
            }
        }

        return 'http';
    }
    
    /**
     * 确保 URL 使用当前请求的协议
     * 
     * @param string $url 原始 URL
     * @return string 修正协议后的 URL
     */
    public static function ensureCurrentScheme(string $url): string
    {
        $currentScheme = self::getCurrentScheme();
        
        if (\str_starts_with($url, 'http://')) {
            return $currentScheme . '://' . \substr($url, 7);
        } elseif (\str_starts_with($url, 'https://')) {
            return $currentScheme . '://' . \substr($url, 8);
        }
        
        return $url;
    }
    
    /**
     * 获取静态资源 URL（带版本号）
     * 
     * @param string $path 资源路径（相对于 statics 目录）
     * @param string|null $version 版本号（null 时自动生成）
     * @return string 完整的静态资源 URL
     */
    public function getStaticUrl(string $path, ?string $version = null): string
    {
        $baseHost = $this->getRequest()->getBaseHost();
        $path = \ltrim($path, '/');
        
        // 自动添加版本号
        if ($version === null) {
            $version = Env::get('static_version') ?: '1.0.0';
        }
        
        $url = $baseHost . '/statics/' . $path;
        
        // 添加版本参数
        if ($version && !\str_contains($url, '?')) {
            $url .= '?v=' . $version;
        }
        
        return self::removeExtraDoubleSlashes($url);
    }
    
    /**
     * 获取主题资源 URL
     * 
     * @param string $module 模块名
     * @param string $path 资源路径
     * @param string $area 区域（frontend/backend）
     * @return string 完整的主题资源 URL
     */
    public function getThemeUrl(string $module, string $path, string $area = 'frontend'): string
    {
        $baseHost = $this->getRequest()->getBaseHost();
        $path = \ltrim($path, '/');
        $module = \str_replace('_', '/', $module);
        
        $url = $baseHost . '/statics/' . $area . '/' . $module . '/' . $path;
        
        // 添加版本号
        $version = Env::get('static_version') ?: '1.0.0';
        if (!\str_contains($url, '?')) {
            $url .= '?v=' . $version;
        }
        
        return self::removeExtraDoubleSlashes($url);
    }

    public function getUrlOrigin($s, $use_forwarded_host = false): string
    {
        // 使用统一的协议检测方法
        $protocol = self::getCurrentScheme();
        
        $port = $s['SERVER_PORT'] ?? '80';
        $isDefaultPort = ($protocol === 'https' && $port === '443') || ($protocol === 'http' && $port === '80');
        $portSuffix = $isDefaultPort ? '' : ':' . $port;
        
        $host = ($use_forwarded_host && isset($s['HTTP_X_FORWARDED_HOST'])) 
            ? $s['HTTP_X_FORWARDED_HOST'] 
            : ($s['SERVER_NAME'] ?? $s['HTTP_HOST'] ?? 'localhost');
        
        // 如果 host 已经包含端口，不重复添加
        if (\str_contains($host, ':')) {
            return $protocol . '://' . $host;
        }
        
        return $protocol . '://' . $host . $portSuffix;
    }

    public function getFullUrl($s, $use_forwarded_host = false): string
    {
        return self::removeExtraDoubleSlashes($this->getUrlOrigin($s, $use_forwarded_host) . '/' . ($s['WELINE_ORIGIN_REQUEST_URI'] ?? $s['REQUEST_URI']));
    }

    public function getCurrentUrl(array $params = [], bool $merge_url_params = true): string
    {
        $server = self::currentServer();
        $url = self::removeExtraDoubleSlashes(
            $this->getUrlOrigin($server, false) . '/' . ((string)($server['WELINE_ORIGIN_REQUEST_URI'] ?? $server['REQUEST_URI'] ?? '/'))
        );
        return $this->extractedUrl($params, $merge_url_params, $url);
    }

    public static $parserServer = [];
    public static $parserSites = [];
    public static $parserCurrencies = [];
    public static $parserLanguages = [];
    private static bool $parserValidationMetadataLoaded = false;
    /** @var array<string, bool> */
    private static array $knownParserCurrencyCodes = [];
    /** @var array<string, bool> */
    private static array $knownParserLanguageCodes = [];
    public static $parserMatchs = [];
    public static array $parserSiteMatchs = [];
    private static array $parserSiteMatchUrlCache = [];

    public static array $parserUrlCache = [];

    /**
     * @param array<string, mixed> $context
     */
    private static function buildUrlRequestCacheKey(string $scope, string $url, array $context = []): string
    {
        $currentServer = self::currentServer();
        $parserServer = self::$parserServer;
        $contextValue = static function (string $key, string $fallback = '') use ($currentServer, $parserServer): string {
            $value = (string)($parserServer[$key] ?? '');
            if ($value !== '') {
                return $value;
            }
            return (string)($currentServer[$key] ?? $fallback);
        };
        $scopeContext = [
            'scope' => $scope,
            'url' => $url,
            'area' => $contextValue('WELINE_AREA'),
            'area_route' => $contextValue('WELINE_AREA_ROUTE'),
            'website_id' => $contextValue('WELINE_WEBSITE_ID'),
            'website_code' => $contextValue('WELINE_WEBSITE_CODE'),
            'website_url' => $contextValue('WELINE_WEBSITE_URL'),
            'lang' => $contextValue('WELINE_USER_LANG'),
            'currency' => $contextValue('WELINE_USER_CURRENCY'),
            'host' => (string)($currentServer['HTTP_HOST'] ?? $currentServer['SERVER_NAME'] ?? $parserServer['HTTP_HOST'] ?? $parserServer['SERVER_NAME'] ?? ''),
            'request_uri' => (string)($currentServer['WELINE_ORIGIN_REQUEST_URI'] ?? $currentServer['REQUEST_URI'] ?? $parserServer['WELINE_ORIGIN_REQUEST_URI'] ?? $parserServer['REQUEST_URI'] ?? ''),
            'full_request_uri' => (string)($currentServer['WELINE_FULL_REQUEST_URI'] ?? $parserServer['WELINE_FULL_REQUEST_URI'] ?? ''),
        ];

        return KeyBuilder::requestScopeHash(
            \array_replace($scopeContext, $context),
            ['full_request_uri' => false]
        );
    }

    public static function parse_url(string $url, string $key = '', string $default = ''): array|string
    {
        $cacheKey = self::buildUrlRequestCacheKey('parse_url', $url);
        if (isset(self::$parserUrlCache[$cacheKey])) {
            if ($key) {
                return self::$parserUrlCache[$cacheKey][$key] ?? $default;
            }
            return self::$parserUrlCache[$cacheKey];
        }
        try {
            $parsed = parse_url($url);
        } catch (\ValueError $e) {
            $parsed = [];
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[Url] parse_url failed for malformed URL: ' . $e->getMessage());
            }
        }
        self::$parserUrlCache[$cacheKey] = is_array($parsed) ? $parsed : [];
        if ($key) {
            return self::$parserUrlCache[$cacheKey][$key] ?? $default;
        }
        return self::$parserUrlCache[$cacheKey];
    }

    public static array $splitUrlCache = [];

    public static function split_url(string $url, string $key = '', string $default = ''): array|string
    {
        $cacheKey = self::buildUrlRequestCacheKey('split_url', $url);
        if (isset(self::$splitUrlCache[$cacheKey])) {
            if ($key) {
                return self::$splitUrlCache[$cacheKey][$key] ?? $default;
            }
            return self::$splitUrlCache[$cacheKey];
        }
        $parse = self::parse_url($url);
        if (!is_array($parse)) {
            $parse = [];
        }
        $path = $parse['path'] ?? '';
        $paths = [];
        if ($path) {
            $paths = explode('/', trim($path, '/'));
        }
        self::$splitUrlCache[$cacheKey]['path'] = $parse['path'] ?? '';
        self::$splitUrlCache[$cacheKey]['split'] = $paths;
        self::$splitUrlCache[$cacheKey]['query'] = $parse['query'] ?? '';
        if ($key) {
            return self::$splitUrlCache[$cacheKey][$key] ?? $default;
        }
        return self::$splitUrlCache[$cacheKey];
    }

    public static array $parserCache = [];
    
    /**
     * 防止重入标志，避免在获取网站列表时触发循环
     * @var bool
     */
    private static bool $parsingInProgress = false;

    public static function parser(string $parse_url = '', string $key = ''): array|string
    {
        $perfStart = \microtime(true);
        $perfMarks = [];
        $isCurrentRequestParse = $parse_url === '' || self::isCurrentRequestUrl($parse_url);
        $perfMark = static function (string $name) use (&$perfMarks, $perfStart): void {
            $perfMarks[$name] = \round((\microtime(true) - $perfStart) * 1000, 2);
        };
        $restoreParserScratch = false;
        $parserScratchBackup = [];
        if (self::currentParserFiber() !== null) {
            $parserScratchBackup = [
                'parserServer' => self::$parserServer,
                'parserMatchs' => self::$parserMatchs,
                'parserCache' => self::$parserCache,
                'decodeUrls' => self::$decode_urls,
                'parsingInProgress' => self::$parsingInProgress,
            ];
            $state = self::parserState();
            self::$parserServer = $state->parserServer;
            self::$parserMatchs = $state->parserMatchs;
            self::$parserCache = $state->parserCache;
            self::$decode_urls = $state->decodeUrls;
            self::$parsingInProgress = $state->parsingInProgress;
            $restoreParserScratch = true;
        }
        
        // 防止重入：如果正在解析中，直接返回URL，避免无限循环
        try {
        if (self::$parsingInProgress) {
            return $parse_url ?: (string)(self::currentServer()['REQUEST_URI'] ?? '/');
        }
        
        # 静态文件不用再分析店铺（只读 WELINE_IS_STATIC_FILE 或对给定 URL 用统一判断）
        if ($parse_url && weline_is_static_file_path($parse_url)) {
            return $parse_url;
        }
        if (!$parse_url && !empty(self::currentServer()['WELINE_IS_STATIC_FILE'])) {
            return (string)(self::currentServer()['REQUEST_URI'] ?? '/');
        }

        self::ensureParserSitesFresh();
        $perfMark('sites_fresh');

        $url = $parse_url;
        # 初始化server（直接使用 self::$parserServer，避免局部变量与静态属性不一致导致跨请求污染）
        if (empty(self::$parserServer)) {
            self::$parserServer = self::currentServer();
            self::$parserServer['WELINE_ORIGIN_TIMEZONE'] = date_default_timezone_get();

            // 使用新的 area_routes 分组配置获取区域前缀
            $restFrontendPrefix = Env::getAreaRoutePrefix('rest_frontend');
            if (empty($restFrontendPrefix)) {
                // 如果没有配置，使用默认值 'api'
                self::$parserServer['WELINE_REST_FRONTEND_PREFIX'] = 'api';
            } else {
                self::$parserServer['WELINE_REST_FRONTEND_PREFIX'] = strtolower($restFrontendPrefix);
            }
            self::$parserServer['WELINE_REST_BACKEND_PREFIX'] = Env::getAreaRoutePrefix('rest_backend') ?? '';
            self::$parserServer['WELINE_BACKEND_PREFIX'] = Env::getAreaRoutePrefix('backend') ?? '';

            // 保留旧的变量名以兼容，后续可逐步移除
            self::$parserServer['WELINE_API_AREA'] = self::$parserServer['WELINE_REST_FRONTEND_PREFIX'];
            self::$parserServer['WELINE_API_AREA_PREFIX'] = '/' . self::$parserServer['WELINE_REST_FRONTEND_PREFIX'] . '/';
            self::$parserServer['WELINE_API_ADMIN_AREA'] = self::$parserServer['WELINE_REST_BACKEND_PREFIX'];
            self::$parserServer['WELINE_BACKEND_AREA'] = self::$parserServer['WELINE_BACKEND_PREFIX'];

            self::$parserServer['WELINE_AREA_ROUTE'] = '';
            self::$parserServer['WELINE_AREA'] = 'frontend';
            self::$parserServer['WELINE_USER_CURRENCY'] = State::getCurrency();
            self::$parserServer['WELINE_USER_LANG'] = State::getLang();
            self::$parserServer['WELINE_WEBSITE_ID'] = self::$parserServer['WELINE_WEBSITE_ID'] ?? '';
            self::$parserServer['WELINE_WEBSITE_CODE'] = self::$parserServer['WELINE_WEBSITE_CODE'] ?? '';
            self::$parserServer['WELINE_WEBSITE_URL'] = self::$parserServer['WELINE_WEBSITE_URL'] ?? '';
        }
        $perfMark('server_init');
        
        if ($url) {
            $path = self::parse_url($url, 'path') ?: '';
            $query = self::parse_url($url, 'query') ?: '';
            $uri = $path . $query;
        } else {
            // 1) 百分号解码：REQUEST_URI 先 rawurldecode（%2F→/ 等），便于后续网站匹配与重写查找
            $currentServer = self::currentServer();
            $request_uri = (string)($currentServer['REQUEST_URI'] ?? '/');
            $request_uri = rawurldecode($request_uri);
            $uri = $request_uri;
            if (!str_starts_with($request_uri, '/')) {
                $request_uri = '/' . $request_uri;
            }
            $url = ((string)($currentServer['REQUEST_SCHEME'] ?? 'http')) . '://' . (string)($currentServer['HTTP_HOST'] ?? 'localhost') . $request_uri;
            // 后续必须执行：2) 网站信息解码（匹配 self::$parserSites，设置 WELINE_WEBSITE_ID 等）
            // 3) 重写路由解码（decode_url → seo_decode 事件，查找 UrlRewrite 并改写为真实 path）
            // 缺一会导致路由或网站上下文错误
        }
        # 静态文件不用再分析店铺（只读请求入口写入的 WELINE_IS_STATIC_FILE）
        if (!empty(self::currentServer()['WELINE_IS_STATIC_FILE'])) {
            return $url;
        }
        $perfMark('request_url');
        $parserCacheKey = self::buildUrlRequestCacheKey('parser', $url, [
            'input_url' => $parse_url,
        ]);
        // 如果 self::$parserSites 已经初始化，直接使用，避免重复事件分发
        if (empty(self::$parserSites)) {
            self::$parsingInProgress = true;
            try {
                $detectWebsiteStart = \microtime(true);
                $detect_website_data = new DataObject([
                    'sites' => [],
                    'site_sample' => [
                        "website_id" => 0,
                        "name" => "默认网站",
                        "code" => "default",
                        "url" => "http://127.0.0.1:9981/default",
                        "default_currency" => "CNY",
                        "default_language" => "zh_Hans_CN",
                        "default_timezone" => "Asia/Shanghai",
                    ],
                    'get_sites' => true
                ]);
                $eventManager = w_obj(EventsManager::class);
                $eventManager->dispatch('Weline_Framework_Url::detect_website', $detect_website_data);
                $detectWebsiteEnd = \microtime(true);
                $detectWebsiteDuration = \round(($detectWebsiteEnd - $detectWebsiteStart) * 1000, 2);
                if ($detectWebsiteDuration > 100) {
                    w_log_warning('[WLS Performance] detect_website event took ' . $detectWebsiteDuration . 'ms');
                }
                $sites = $detect_website_data->getData('sites');
                # 找出站点链接最长的，依次写入self::$parserSites
                $tmp = [];
                foreach ($sites as $site) {
                    $site_url = $site['url'];
                    $length = strlen($site_url);
                    $tmp[$length][] = $site;
                }
                krsort($tmp);
                foreach ($tmp as $sitesAtLength) {
                    foreach ((array)$sitesAtLength as $site) {
                        $site_url = $site['url'];
                        self::$parserSites[$site_url] = $site;
                    }
                }
            } finally {
                // 确保无论成功或异常都重置标志
                self::$parsingInProgress = false;
            }
        }
        # 匹配网站 self::$parserSites 最长倒序
        $perfMark('sites_loaded');
        $parsers = self::parse_url($url);
        if (!is_array($parsers)) {
            $parsers = [];
        }
        // Absolute URLs carry the authoritative scheme. WLS TLS/proxy requests
        // can expose REQUEST_SCHEME=http while the parsed URL is https://...
        $currentScheme = self::getCurrentScheme();
        $requestScheme = \strtolower((string)($parsers['scheme'] ?? ''));
        if ($requestScheme !== 'http' && $requestScheme !== 'https') {
            $requestScheme = $currentScheme;
        }
        $hostPart = ($parsers['host'] ?? 'localhost');
        $portPart = ($parsers['port'] ?? '');
        $portSuffix = ($portPart === '' || $portPart === '80' || $portPart === '443') ? '' : ':' . $portPart;
        $data['website_url'] = $requestScheme . '://' . $hostPart . $portSuffix;
        self::$parserServer['WELINE_WEBSITE_URL'] = $data['website_url'];
        foreach (self::parserSiteMatchUrls($requestScheme, (string)$portPart) as $site_url => $site_url_for_match) {
            $site = self::$parserSites[$site_url] ?? null;
            if (!\is_array($site)) {
                continue;
            }
            // 如果站点 URL 无端口，但当前请求有端口，需要补全端口再匹配
            // 否则 str_starts_with('https://my.com:9981/...', 'https://my.com') 会误匹配
            // 导致 str_replace 后残留 ':9981/...'，parse_url 无法解析路径
            if (str_starts_with($url, $site_url_for_match)) {                $url = str_replace($site_url_for_match, '', $url);
                $uri = self::parse_url($url, 'path') ?: '';
                if (isset(self::$parserSiteMatchs[$site_url])) {
                    $data = array_merge((array)$data, self::$parserSiteMatchs[$site_url]);
                }
                $data['url'] = $url;
                $parsed_url = self::parse_url($url);
                $data['parse'] = is_array($parsed_url) ? $parsed_url : [];
                $matchedWebsiteUrl = $site_url_for_match;
                $data['website_url'] = $matchedWebsiteUrl;
                $data['website'] = $site;
                self::$parserServer['WELINE_WEBSITE_CODE'] = $site['code'];
                self::$parserServer['WELINE_WEBSITE_ID'] = $site['website_id'];
                // 使用当前请求的协议、host 和端口，避免 WLS 特殊端口丢失
                self::$parserServer['WELINE_WEBSITE_URL'] = $matchedWebsiteUrl;
                self::$parserServer['WELINE_WEBSITE_CURRENCY'] = $site['default_currency'];
                self::$parserServer['WELINE_WEBSITE_LANGUAGE'] = $site['default_language'];
                if (empty(self::$parserServer['WELINE_USER_LANG'])) {
                    self::$parserServer['WELINE_USER_LANG'] = State::getLang() ?: $site['default_language'];
                }
                if (empty(self::$parserServer['WELINE_USER_CURRENCY'])) {
                    self::$parserServer['WELINE_USER_CURRENCY'] = State::getCurrency() ?: $site['default_currency'];
                }
                # 如果URI是空的，后边就不用判断了，直接返回环境包含的参数
                if (empty($uri)) {
                    $query_part = self::parse_url($url, 'query') ?: '';
                    $query = $query_part ? '?' . $query_part : '';
                    $data['url'] = $matchedWebsiteUrl . $query;
                    $data['server'] = self::$parserServer;
                    $data['language'] = self::$parserServer['WELINE_USER_LANG'];
                    $data['currency'] = self::$parserServer['WELINE_USER_CURRENCY'];
                    if ($key) {
                        return $data[$key] ?? '';
                    }
                    return $data;
                }
                break;
            }
        }

        # 如果网站匹配失败，从完整URL中提取路径部分，并回退到默认网站
        $perfMark('site_match');
        if (!isset($data['website']) && str_contains($url, '://')) {
            self::resetWebsiteParserSites();
            self::ensureParserSitesLoaded();
            foreach (self::parserSiteMatchUrls($requestScheme, (string)$portPart) as $site_url => $site_url_for_match) {
                $site = self::$parserSites[$site_url] ?? null;
                if (!\is_array($site)) {
                    continue;
                }
                if (str_starts_with($url, $site_url_for_match)) {                    $url = str_replace($site_url_for_match, '', $url);
                    $uri = self::parse_url($url, 'path') ?: '';
                    if (isset(self::$parserSiteMatchs[$site_url])) {
                        $data = array_merge((array)$data, self::$parserSiteMatchs[$site_url]);
                    }
                    $data['url'] = $url;
                    $parsed_url = self::parse_url($url);
                    $data['parse'] = is_array($parsed_url) ? $parsed_url : [];
                    $matchedWebsiteUrl = $site_url_for_match;
                    $data['website_url'] = $matchedWebsiteUrl;
                    $data['website'] = $site;
                    self::$parserServer['WELINE_WEBSITE_CODE'] = $site['code'];
                    self::$parserServer['WELINE_WEBSITE_ID'] = $site['website_id'];
                    self::$parserServer['WELINE_WEBSITE_URL'] = $matchedWebsiteUrl;
                    self::$parserServer['WELINE_WEBSITE_CURRENCY'] = $site['default_currency'];
                    self::$parserServer['WELINE_WEBSITE_LANGUAGE'] = $site['default_language'];
                    if (empty(self::$parserServer['WELINE_USER_LANG'])) {
                        self::$parserServer['WELINE_USER_LANG'] = State::getLang() ?: $site['default_language'];
                    }
                    if (empty(self::$parserServer['WELINE_USER_CURRENCY'])) {
                        self::$parserServer['WELINE_USER_CURRENCY'] = State::getCurrency() ?: $site['default_currency'];
                    }
                    if (empty($uri)) {
                        $query_part = self::parse_url($url, 'query') ?: '';
                        $query = $query_part ? '?' . $query_part : '';
                        $data['url'] = $matchedWebsiteUrl . $query;
                        $data['server'] = self::$parserServer;
                        $data['language'] = self::$parserServer['WELINE_USER_LANG'];
                        $data['currency'] = self::$parserServer['WELINE_USER_CURRENCY'];
                        if ($key) {
                            return $data[$key] ?? '';
                        }
                        return $data;
                    }
                    break;
                }
            }
            if (!isset($data['website'])) {
                $directSite = self::detectWebsiteForAbsoluteUrl($url);
                if ($directSite !== null) {
                    $matchedWebsiteUrl = (string)($directSite['url'] ?? $data['website_url'] ?? '');
                    if ($matchedWebsiteUrl !== '' && \str_starts_with($url, $matchedWebsiteUrl)) {
                        $url = \str_replace($matchedWebsiteUrl, '', $url);
                    } else {
                        $fallbackPath = self::parse_url($url, 'path') ?: '/';
                        $fallbackQuery = self::parse_url($url, 'query') ?: '';
                        $url = $fallbackPath . ($fallbackQuery !== '' ? '?' . $fallbackQuery : '');
                    }
                    if ($url === '') {
                        $url = '/';
                    }
                    $uri = self::parse_url($url, 'path') ?: '/';
                    $parsed_url = self::parse_url($url);
                    $data['url'] = $url;
                    $data['parse'] = \is_array($parsed_url) ? $parsed_url : [];
                    $data['website_url'] = $matchedWebsiteUrl !== '' ? $matchedWebsiteUrl : (string)($data['website_url'] ?? '');
                    $data['website'] = $directSite;
                    self::$parserSites[$data['website_url']] = $directSite;
                    self::$parserServer['WELINE_WEBSITE_CODE'] = $directSite['code'] ?? '';
                    self::$parserServer['WELINE_WEBSITE_ID'] = $directSite['website_id'] ?? '';
                    self::$parserServer['WELINE_WEBSITE_URL'] = $data['website_url'];
                    self::$parserServer['WELINE_WEBSITE_CURRENCY'] = $directSite['default_currency'] ?? '';
                    self::$parserServer['WELINE_WEBSITE_LANGUAGE'] = $directSite['default_language'] ?? '';
                    if (empty(self::$parserServer['WELINE_USER_LANG'])) {
                        self::$parserServer['WELINE_USER_LANG'] = State::getLang() ?: ($directSite['default_language'] ?? '');
                    }
                    if (empty(self::$parserServer['WELINE_USER_CURRENCY'])) {
                        self::$parserServer['WELINE_USER_CURRENCY'] = State::getCurrency() ?: ($directSite['default_currency'] ?? '');
                    }
                }
            }
            $parsed = self::parse_url($url);
            if (isset($parsed['path'])) {
                # 规范化路径：去除多余斜杠，确保以 / 开头
                $path = $parsed['path'];
                $path = str_replace('//', '/', $path);
                if (!str_starts_with($path, '/')) {
                    $path = '/' . $path;
                }
                $url = $path . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
                $uri = $path . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
            }

            // Host misses must not inherit an arbitrary website context.
            // Website rewrites require an explicit Website.url or WebsiteDomain match.
            if (!isset($data['website'])) {
                unset($data['website']);
                self::$parserServer['WELINE_WEBSITE_CODE'] = '';
                self::$parserServer['WELINE_WEBSITE_ID'] = '';
                self::$parserServer['WELINE_WEBSITE_CURRENCY'] = '';
                self::$parserServer['WELINE_WEBSITE_LANGUAGE'] = '';
            }
        }

        # 前缀区域去除
        $perfMark('fallback_site');
        $splits = self::split_url($url, 'split');

        # 完全前缀匹配 最长匹配逻辑（[网站前缀]/[货币前缀]/[语言前缀]）三个参数都存在的情况
        foreach (self::$parserMatchs as $match_url => $match_data) {
            if (str_starts_with($url, $match_url)) {
                $url = str_replace($match_url, '', $url);
                // 关键修复：必须使用缓存的完整 $match_data（包含 server, area, currency 等）
                // 而不是返回当前局部变量 $data（缺少 server 等关键字段）
                // 否则 processUrlParse() 无法正确识别后台/前台/API 请求，导致间歇性 404
                $matchResult = $match_data;
                $matchResult['url'] = $url;
                // 更新 server 中的 REQUEST_URI 为当前请求的实际路由部分
                if (isset($matchResult['server'])) {
                    $matchResult['server']['ORIGIN_REQUEST_URI'] = $uri;
                    $matchResult['server']['REQUEST_URI'] = $url;
                } else {
                    // 兼容：如果缓存数据意外缺少 server，使用 self::$parserServer
                    self::$parserServer['ORIGIN_REQUEST_URI'] = $uri;
                    self::$parserServer['REQUEST_URI'] = $url;
                    $matchResult['server'] = self::$parserServer;
                }
                if ($key) {
                    return $matchResult[$key] ?? '';
                }
                return $matchResult;
            }
        }
        # 完全url匹配  比如只有语言，或者只有货币的情况
        if (isset(self::$parserCache[$parserCacheKey])) {
            if ($key) {
                return self::$parserCache[$parserCacheKey][$key] ?? '';
            }
            return self::$parserCache[$parserCacheKey];
        }
        $perfMark('prefix_cache');
        # 如果还有路由
        $area = $splits[0] ?? '';
        if (empty($area)) {
            // path 为空（如 "/"）时不能直接 return：须继续走 Cookie/默认值填充、decode_url 重写解码、
            // 并把 parserServer（含 WELINE_WEBSITE_ID）包装进 $data['server'] 返回，否则网站匹配不到站点 id/页面 id、查不到重写路由
            $uri = str_starts_with($url, '/') ? $url : '/' . $url;
            $has_area = false;
            self::$parserServer['WELINE_AREA'] = 'frontend';
            self::$parserServer['WELINE_AREA_ROUTE'] = '';
            self::$parserServer['REQUEST_URI'] = $uri;
        } else {
        $has_area = $data['has_area'] ?? false;
        
        // 使用 Env::getAreaByRoutePrefix() 动态识别区域
        // 支持通过 URL 首段匹配 area_routes 配置
        $matchedArea = Env::getAreaByRoutePrefix($area);

        // 诊断日志：记录区域匹配结果和调用栈
        if (
            Env::get('wls.debug.hot_path_logs', false)
            && (str_contains($url, 'ai-site-agent') || $area === 'U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8')
        ) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            $caller = '';
            foreach ($backtrace as $trace) {
                if (isset($trace['class']) && isset($trace['function'])) {
                    $caller .= $trace['class'] . '::' . $trace['function'] . ' -> ';
                }
            }
            if (\class_exists(\Weline\Server\Log\WlsLogger::class)) {
                \Weline\Server\Log\WlsLogger::info_('[Url::parser] Area matching', [
                    'url' => $url,
                    'first_segment' => $area,
                    'matched_area' => $matchedArea ?? 'null',
                    'caller' => \rtrim($caller, ' -> '),
                ]);
            }
        }

        if ($matchedArea !== null) {
            // 匹配到配置的区域前缀
            switch ($matchedArea) {
                case 'rest_frontend':
                    self::$parserServer['WELINE_AREA'] = 'rest_frontend';
                    self::$parserServer['WELINE_AREA_ROUTE'] = '/' . $area . '/';
                    array_shift($splits);
                    $uri = '/' . implode('/', $splits);
                    $has_area = true;
                    self::$parserServer['REQUEST_URI'] = $uri;
                    break;
                case 'rest_backend':
                    self::$parserServer['WELINE_AREA'] = 'rest_backend';
                    self::$parserServer['WELINE_AREA_ROUTE'] = $area;
                    array_shift($splits);
                    $uri = '/' . implode('/', $splits);
                    $has_area = true;
                    self::$parserServer['REQUEST_URI'] = $uri;
                    break;
                case 'backend':
                    // 后台 URL：/backendKey/USD/zh_Hans_CN/admin/login
                    // backendKey（如 U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8）已能识别后台
                    // 去掉首段 backend key，直接使用剩余路径，不需要额外添加 /admin/ 前缀
                    // REQUEST_URI = /USD/zh_Hans_CN/admin/login（原始路径已包含 admin/）
                    // WELINE_AREA_ROUTE = ''，路由匹配时无需额外去除前缀
                    self::$parserServer['WELINE_AREA'] = 'backend';
                    self::$parserServer['WELINE_AREA_ROUTE'] = '';
                    array_shift($splits);  // 去掉 backend key
                    $uri = '/' . implode('/', $splits);
                    $has_area = true;
                    self::$parserServer['REQUEST_URI'] = $uri;

                    // 诊断日志：记录后台 URL 解析
                    if (Env::get('wls.debug.hot_path_logs', false) && str_contains($uri, 'ai-site-agent')) {
                        w_log_warning('[Url::parser] Backend URL detected | uri=' . $uri . ' | area=backend | backendKey=' . $area);
                    }
                    break;
            }
        } else {
            // 未匹配到配置的区域前缀
            // 后台请求必须通过配置的 backendKey 作为 URL 首段访问
            // 不再通过 URL 中的 "admin" 或 "backend" 段来判断区域，避免安全漏洞

            // 默认：前端区域
            self::$parserServer['WELINE_AREA'] = 'frontend';
            self::$parserServer['WELINE_AREA_ROUTE'] = '';
            $uri = '/' . implode('/', $splits);
        }
        }
        $data['has_area'] = $has_area;
        $data['area'] = self::$parserServer['WELINE_AREA'];
        $data['area_route'] = self::$parserServer['WELINE_AREA_ROUTE'];
        $perfMark('area');
        
        $url = $uri . (self::parse_url($url, 'query') ? '?' . self::parse_url($url, 'query') : '');
        # URL结构：[网站前缀]/[货币前缀]/[语言前缀]/[路由]

        $data['currency'] = '';
        $data['language'] = '';
        $data['timezone'] = ($data['website'] ?? [])['timezone'] ?? 'Asia/Shanghai';
        # 匹配货币 self::$parserCurrencies 最长倒序
        foreach (\array_unique(self::$parserCurrencies) as $currency) {
            if (str_starts_with($url, '/' . $currency)) {
                $candidateUrl = $url;
                if (self::detectCurrency($candidateUrl, (string)$currency)) {
                    $url = $candidateUrl;
                    $data['currency'] = $currency;
                    break;
                }
            }
        }

        # 匹配语言 self::$parserLanguages 最长倒序
        foreach (\array_unique(self::$parserLanguages) as $language) {
            if (!State::isAllowedLanguageCode((string)$language)) {
                continue;
            }
            if (str_starts_with($url, '/' . $language)) {
                $candidateUrl = $url;
                if (self::detectLanguage($candidateUrl, (string)$language)) {
                    $url = $candidateUrl;
                    $data['language'] = $language;
                    break;
                }
            }
        }

        $quickParts = explode('/', ltrim($uri, '/'), 3);
        $quickCurrency = $quickParts[0] ?? '';
        $quickLanguage = $quickParts[1] ?? '';
        if (
            $data['currency'] === ''
            && \strlen($quickCurrency) === 3
            && \ctype_upper($quickCurrency)
            && State::isAllowedCurrencyCode($quickCurrency)
        ) {
            $candidateUrl = $url;
            if (self::detectCurrency($candidateUrl, $quickCurrency)) {
                $url = $candidateUrl;
                $data['currency'] = $quickCurrency;
                self::rememberParserCurrency($quickCurrency);
            }
        }
        if ($data['language'] === '' && $quickLanguage !== '' && State::isAllowedLanguageCode($quickLanguage)) {
            $candidateUrl = $url;
            if (self::detectLanguage($candidateUrl, $quickLanguage)) {
                $url = $candidateUrl;
                $data['language'] = $quickLanguage;
                self::rememberParserLanguage($quickLanguage);
            }
        }

        $data['all_match'] = !empty($data['currency']) && !empty($data['language']);
        if ((!$data['all_match']) && $uri and '/' !== $uri) {
            # 获取路由前缀，可能是货币码或者语言码  剩余URL结构：[货币前缀]/[语言前缀]/[路由]，没有网站
            $uri_arr = explode('/', ltrim($uri, '/'));
            if ($uri_arr) {
                # 如果还有路由
                $pre_path_1 = $uri_arr[0] ?? '';
                $pre_path_2 = $uri_arr[1] ?? '';

                $has_currency = false;
                $has_language = false;
                if ($pre_path_1) {
                    # 检查头路径$pre_path_1是否是货币
                    if (strlen($pre_path_1) === 3 && ctype_upper($pre_path_1) && State::isAllowedCurrencyCode($pre_path_1)) {
                        $has_currency = self::detectCurrency($url, $pre_path_1);
                        if ($has_currency) {
                            $data['currency'] = $pre_path_1;
                            self::rememberParserCurrency($pre_path_1);
                        }
                    }
                    if (!$has_currency && State::isAllowedLanguageCode($pre_path_1)) {
                        # 检查头路径$pre_path_1是否是语言
                        $has_language = self::detectLanguage($url, $pre_path_1);
                        if ($has_language) {
                            $data['language'] = $pre_path_1;
                            self::rememberParserLanguage($pre_path_1);
                        }
                    }
                }
                if ($pre_path_2) {
                    # 检查第二个路径是否是语言
                    if (!$has_language && State::isAllowedLanguageCode($pre_path_2)) {
                        $has_language = self::detectLanguage($url, $pre_path_2);
                        if ($has_language) {
                            $data['language'] = $pre_path_2;
                            self::rememberParserLanguage($pre_path_2);
                        }
                    }

                    # 检查第二个路径是否是货币
                    if (!$has_currency && strlen($pre_path_2) === 3 && ctype_upper($pre_path_2) && State::isAllowedCurrencyCode($pre_path_2)) {
                        $has_currency = self::detectCurrency($url, $pre_path_2);
                        if ($has_currency) {
                            $data['currency'] = $pre_path_2;
                            self::rememberParserCurrency($pre_path_2);
                        }
                    }
                }

                # 最长完全匹配
                $data['all_match'] = $has_currency && $has_language;

                self::$parserServer['REQUEST_URI'] = $uri;
                if (!$pre_path_1) {
                    self::$parserServer['REQUEST_URI'] = implode('/', $uri_arr);
                }
            }
        }
        // 优先级：Path Level > URL Parameters > Cookie/Default values
        // 如果路径级别没有找到，尝试从URL查询参数获取
        $data['all_match'] = !empty($data['currency']) && !empty($data['language']);
        $perfMark('currency_language');
        if (empty($data['currency']) || empty($data['language'])) {
            $parsed_url = self::parse_url($url);
            $query_params = [];
            if (isset($parsed_url['query'])) {
                parse_str($parsed_url['query'], $query_params);
            }
            
            // 从URL参数获取currency（如果路径级别没有）
            if (empty($data['currency']) && isset($query_params['currency'])) {
                $currency = strtoupper(trim((string)($query_params['currency'] ?? '')));
                if ($currency !== '' && State::isAllowedCurrencyCode($currency)) {
                    $data['currency'] = $currency;
                }
            }
            
            // 从URL参数获取locale（如果路径级别没有）
            if (empty($data['language']) && isset($query_params['locale'])) {
                $locale = trim($query_params['locale'] ?? '');
                if (!empty($locale) && strlen($locale) >= 5 && strlen($locale) <= 10) {
                    // 验证是否为有效的locale格式（如：zh_Hans_CN）
                    if (preg_match('/^[a-z]{2}_[A-Z][a-z]+_[A-Z]{2}$/', $locale)) {
                        $data['language'] = $locale;
                    }
                }
            }
        }
        
        // 如果还是没有找到，使用Cookie或默认值
        if (empty($data['currency'])) {
            $data['currency'] = self::$parserServer['WELINE_USER_CURRENCY'] ?? (($data['website'] ?? [])['default_currency'] ?? null) ?? 'CNY';
        }
        if (empty($data['language'])) {
            $data['language'] = self::$parserServer['WELINE_USER_LANG'] ?? (($data['website'] ?? [])['default_language'] ?? null) ?? 'zh_Hans_CN';
        }
        // 重写路由解码前，把已解析的网站信息写入 $_SERVER，供 seo_decode 观察者（如 RouterRewrite）getCurrentWebsiteId() 使用
        if (isset(self::$parserServer['WELINE_WEBSITE_ID'])) {
            self::updateCurrentServerVar('WELINE_WEBSITE_ID', self::$parserServer['WELINE_WEBSITE_ID']);
        }
        if (isset(self::$parserServer['WELINE_WEBSITE_CODE'])) {
            self::updateCurrentServerVar('WELINE_WEBSITE_CODE', self::$parserServer['WELINE_WEBSITE_CODE']);
        }
        $perfMark('before_decode');
        $decode_url = self::decode_url($url);
        $perfMark('decode');
        if($url !== $decode_url){
            $uri = $decode_url;
        }
        # 新增逻辑：去除区域、货币、语言前缀，得到纯路由部分
        $pure_uri = $uri;
        # 去除区域
        $area_route = $data['area_route'] ?? '';
        if ($area_route && str_starts_with(ltrim($pure_uri, '/'), $area_route)) {
            $pure_uri = substr(ltrim($pure_uri, '/'), strlen($area_route));
        }
        # 去除货币
        $currency = $data['currency'] ?? '';
        if ($currency && str_starts_with(ltrim($pure_uri, '/'), $currency)) {
            $pure_uri = substr(ltrim($pure_uri, '/'), strlen($currency));
        }
        # 去除语言
        $language = $data['language'] ?? '';
        if ($language && str_starts_with(ltrim($pure_uri, '/'), $language)) {
            $pure_uri = substr(ltrim($pure_uri, '/'), strlen($language));
        }
        $pure_uri = ltrim($pure_uri, '/');
        $data['uri'] = $pure_uri;
        # 新增逻辑结束
        
        self::$parserServer['ORIGIN_REQUEST_URI'] = $uri;
        // 统一使用 pure_uri（已移除区域、货币、语言前缀的纯路由）
        // 后台区域通过 WELINE_AREA=backend 识别，不需要在 REQUEST_URI 中保留 admin/ 前缀
        self::$parserServer['REQUEST_URI'] = '/' . ltrim($pure_uri, '/');
        // 关键修复：用 URL 解析出的语言/货币更新 $parserServer
        // 初始化时 $parserServer 从 Cookie 获取默认值，但 URL 路径中的语言/货币优先级更高
        // 如果不更新，State::getLang() 读取 $_SERVER 时会得到 Cookie 值而非 URL 值
        if (!empty($data['language'])) {
            self::$parserServer['WELINE_USER_LANG'] = $data['language'];
        }
        if (!empty($data['currency'])) {
            self::$parserServer['WELINE_USER_CURRENCY'] = $data['currency'];
        }
        if ($isCurrentRequestParse) {
            self::syncParserServerToCurrentContext();
        }
        $perfMark('sync_context');
        $data['server'] = self::$parserServer;
        
        # 解析缓存（必须在更新 $parserServer 后缓存，确保 server 字段包含正确的语言/货币）
        if ($data['all_match']) {
            $match_url = $data['website_url'] . ($has_area ? $area : '') . '/' . $data['currency'] . '/' . $data['language'];
            self::$parserMatchs[$match_url] = $data;
        }
        $perfMark('done');
        $data['_perf'] = $perfMarks;
        self::$parserCache[$parserCacheKey] = $data;
        if ($key) {
            return $data[$key] ?? '';
        }

        return $data;
        } finally {
            if ($restoreParserScratch) {
                $state = self::parserState();
                $state->parserServer = self::$parserServer;
                $state->parserMatchs = self::$parserMatchs;
                $state->parserCache = self::$parserCache;
                $state->decodeUrls = self::$decode_urls;
                $state->parsingInProgress = self::$parsingInProgress;

                self::$parserServer = $parserScratchBackup['parserServer'] ?? [];
                self::$parserMatchs = $parserScratchBackup['parserMatchs'] ?? [];
                self::$parserCache = $parserScratchBackup['parserCache'] ?? [];
                self::$decode_urls = $parserScratchBackup['decodeUrls'] ?? [];
                self::$parsingInProgress = (bool)($parserScratchBackup['parsingInProgress'] ?? false);
            }
        }
    }

    static private array $decode_urls = [];
    static private array $processDecodeUrls = [];
    static private array $processDecodeUrlExpiresAt = [];

    public static function decode_url(string $url): string
    {
        $decodeCacheKey = self::buildUrlRequestCacheKey('decode_url', $url);
        if (isset(self::$decode_urls[$decodeCacheKey])) {
            return self::$decode_urls[$decodeCacheKey];
        }
        # decode seo url
        if (Env::get('seo')) {
            $websiteId = (string)(self::currentServer()['WELINE_WEBSITE_ID'] ?? self::$parserServer['WELINE_WEBSITE_ID'] ?? '');
            $processCacheKey = self::buildUrlRequestCacheKey('decode_url_process', $url, [
                'website_id' => $websiteId,
            ]);
            $expiresAt = self::$processDecodeUrlExpiresAt[$processCacheKey] ?? 0;
            if ($expiresAt >= \time() && \array_key_exists($processCacheKey, self::$processDecodeUrls)) {
                $cachedUrl = (string)self::$processDecodeUrls[$processCacheKey];
                self::$decode_urls[$decodeCacheKey] = $cachedUrl;
                return $cachedUrl;
            }
            unset(self::$processDecodeUrls[$processCacheKey], self::$processDecodeUrlExpiresAt[$processCacheKey]);

            /**@var EventsManager $event */
            $event = ObjectManager::getInstance(EventsManager::class);
            $origin_url = $url;
            $event->dispatch('Weline_Framework_Url::seo_decode', $url);
            if (!\is_scalar($url)) {
                $url = $origin_url;
            } else {
                $url = (string)$url;
            }
            
            // 缓存原始URL到解码后URL的映射
            self::$decode_urls[self::buildUrlRequestCacheKey('decode_url', $origin_url)] = $url;
            
            // 关键：如果URL发生了变化，也缓存解码后的URL指向自己，避免二次解码
            if ($url !== $origin_url) {
                self::$decode_urls[self::buildUrlRequestCacheKey('decode_url', $url)] = $url;
            }
            if (\count(self::$processDecodeUrls) >= self::PROCESS_DECODE_CACHE_MAX_ITEMS) {
                \array_shift(self::$processDecodeUrls);
                \array_shift(self::$processDecodeUrlExpiresAt);
            }
            self::$processDecodeUrls[$processCacheKey] = $url;
            self::$processDecodeUrlExpiresAt[$processCacheKey] = \time() + self::PROCESS_DECODE_CACHE_TTL;
            return $url;
        }
        return $url;
    }
    
    /**
     * WLS 多 Fiber 交错时清理「随请求变化」的 Url 解析缓存。
     *
     * {@see self::$parserServer} 在首次 {@see parser()} 时从 $_SERVER 拷贝后会持续原地修改；
     * 与 {@see WlsFiberContext} 只快照 $_SERVER 不同，静态解析态不会随 Fiber 切换而自动一致，
     * 会导致 getBackendUrl()/getBaseHost 依赖的 HTTP_HOST、WELINE_WEBSITE_URL 等与当前连接错位
     *（例如 SSE 长连接挂起期间其它请求改写 parserServer，恢复后仍沿用错误 host）。
     *
     * 调用点：WLS worker 中 Fiber 新请求入口 `wlsFiberRequestContextEnter`、
     * {@see WlsFiberContext::restore}（恢复 $_SERVER 之后）。
     *
     * 不清空 parserSites/parserMatchs 等站点与路由表缓存，避免每次握手重复 detect_website。
     */
    public static function resetWlsFiberInterleavedParserScratch(): void
    {
        $state = self::parserState();
        $state->parserServer = [];
        $state->parserMatchs = [];
        $state->parserCache = [];
        $state->decodeUrls = [];
        $state->parsingInProgress = false;

        self::$parserServer = [];
        self::$parserMatchs = [];
        self::$parserCache = [];
        self::$decode_urls = [];
        self::$parsingInProgress = false;
    }

    /**
     * 重置所有请求级解析缓存（WLS 请求入口调用）。
     *
     * 与 StateManager::reset()（请求结束时运行）不同，此方法在请求开始时运行，
     * 确保 run_before 观察者和后续 URL 解析不会读到上一个请求的残留状态。
     * parserSites/parserCurrencies/parserLanguages 保留不重置（跨请求缓存以节省 DB 查询）。
     */
    public static function resetParserRequestCaches(): void
    {
        self::resetWlsFiberInterleavedParserScratch();
        self::$parserMatchs = [];
        self::$parserSiteMatchs = [];
        self::$parserUrlCache = [];
        self::$splitUrlCache = [];
    }

    public static function resetWebsiteParserSites(): void
    {
        self::$parserSites = [];
        self::$parserCurrencies = [];
        self::$parserLanguages = [];
        self::$parserValidationMetadataLoaded = false;
        self::$knownParserCurrencyCodes = [];
        self::$knownParserLanguageCodes = [];
        self::$parserSiteMatchs = [];
        self::$parserSiteMatchUrlCache = [];
        self::$parserMatchs = [];
        self::$parserSitesVersion = '';
    }

    public static function bumpWebsiteParserSitesVersion(?string $version = null): string
    {
        $version = $version !== null && $version !== '' ? $version : (string)\microtime(true);
        self::writeParserSitesVersionFile($version);

        try {
            $cache = w_cache('website_detect');
            $cache->clear();
            $cache->set(self::PARSER_SITES_VERSION_CACHE_KEY, $version, 86400);
        } catch (\Throwable) {
        }

        self::resetWebsiteParserSites();
        self::$parserSitesVersion = $version;

        return $version;
    }

    /**
     * @return array<string, string>
     */
    private static function parserSiteMatchUrls(string $currentScheme, string $requestPort): array
    {
        $siteKeys = \array_keys(self::$parserSites);
        $cacheKey = $currentScheme . '|' . $requestPort . '|' . self::$parserSitesVersion . '|' . \count($siteKeys) . '|' . (string)($siteKeys[0] ?? '') . '|' . (string)($siteKeys[\count($siteKeys) - 1] ?? '');
        if (isset(self::$parserSiteMatchUrlCache[$cacheKey])) {
            return self::$parserSiteMatchUrlCache[$cacheKey];
        }

        $urls = [];
        foreach ($siteKeys as $siteUrl) {
            $siteUrlForMatch = self::replaceUrlScheme((string)$siteUrl, $currentScheme);
            $siteParsed = \parse_url($siteUrlForMatch);
            if (!isset($siteParsed['port']) && $requestPort !== '') {
                $siteUrlForMatch = ($siteParsed['scheme'] ?? $currentScheme) . '://'
                    . ($siteParsed['host'] ?? 'localhost')
                    . ':' . $requestPort
                    . ($siteParsed['path'] ?? '');
            }
            $urls[(string)$siteUrl] = $siteUrlForMatch;
        }

        if (\count(self::$parserSiteMatchUrlCache) > 16) {
            self::$parserSiteMatchUrlCache = \array_slice(self::$parserSiteMatchUrlCache, -8, null, true);
        }
        self::$parserSiteMatchUrlCache[$cacheKey] = $urls;

        return $urls;
    }

    private static function replaceUrlScheme(string $url, string $scheme): string
    {
        if (\str_starts_with($url, 'http://')) {
            return $scheme . '://' . \substr($url, 7);
        }
        if (\str_starts_with($url, 'https://')) {
            return $scheme . '://' . \substr($url, 8);
        }

        return $url;
    }

    private static function ensureParserSitesFresh(): void
    {
        $now = \microtime(true);
        if (
            self::$parserSitesVersionCheckedAt > 0
            && ($now - self::$parserSitesVersionCheckedAt) < self::PARSER_SITES_VERSION_CHECK_INTERVAL_SECONDS
        ) {
            return;
        }
        self::$parserSitesVersionCheckedAt = $now;

        $version = self::readParserSitesVersionFile();
        if ($version === '' && empty(self::$parserSites)) {
            $version = self::readParserSitesVersionFromSharedCache($now);
        }

        if ($version === '') {
            return;
        }

        if (self::$parserSitesVersion !== '' && self::$parserSitesVersion !== $version) {
            self::resetWebsiteParserSites();
        }

        self::$parserSitesVersion = $version;
    }

    private static function readParserSitesVersionFile(): string
    {
        $path = self::parserSitesVersionFilePath();
        if ($path === '' || !\is_file($path)) {
            return '';
        }

        $version = @\file_get_contents($path);
        return \is_string($version) ? \trim($version) : '';
    }

    private static function writeParserSitesVersionFile(string $version): void
    {
        $path = self::parserSitesVersionFilePath();
        if ($path === '') {
            return;
        }

        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }

        @\file_put_contents($path, $version, \LOCK_EX);
    }

    private static function parserSitesVersionFilePath(): string
    {
        if (!\defined('BP')) {
            return '';
        }

        return \rtrim((string)BP, "\\/") . \DIRECTORY_SEPARATOR . \str_replace('/', \DIRECTORY_SEPARATOR, self::PARSER_SITES_VERSION_FILE);
    }

    private static function readParserSitesVersionFromSharedCache(float $now): string
    {
        static $lastRemoteCheckAt = 0.0;

        if (
            $lastRemoteCheckAt > 0
            && ($now - $lastRemoteCheckAt) < self::PARSER_SITES_REMOTE_VERSION_CHECK_INTERVAL_SECONDS
        ) {
            return '';
        }
        $lastRemoteCheckAt = $now;

        try {
            $cached = w_cache('website_detect')->get(self::PARSER_SITES_VERSION_CACHE_KEY);
            return \is_scalar($cached) ? (string)$cached : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Resolve the current absolute request against the domain table when the
     * process-level site list is stale. This keeps published local domains from
     * falling through to the default website until cache TTL/reload catches up.
     *
     * @return array<string, mixed>|null
     */
    private static function detectWebsiteForAbsoluteUrl(string $url): ?array
    {
        if ($url === '' || !\str_contains($url, '://')) {
            return null;
        }

        try {
            $detectWebsiteData = new DataObject(['url' => $url]);
            $eventManager = w_obj(EventsManager::class);
            $eventManager->dispatch('Weline_Framework_Url::detect_website', $detectWebsiteData);
            if (!$detectWebsiteData->hasData('website_id')) {
                return null;
            }
            $websiteId = (int)$detectWebsiteData->getData('website_id');
            if ($websiteId < 0) {
                return null;
            }

            $websiteUrl = (string)($detectWebsiteData->getData('website_url') ?? '');
            if ($websiteUrl === '') {
                $parsed = self::parse_url($url);
                if (!\is_array($parsed)) {
                    return null;
                }
                $scheme = (string)($parsed['scheme'] ?? self::getCurrentScheme());
                $host = (string)($parsed['host'] ?? '');
                if ($host === '') {
                    return null;
                }
                $port = isset($parsed['port']) && !\in_array((string)$parsed['port'], ['80', '443'], true)
                    ? ':' . (string)$parsed['port']
                    : '';
                $websiteUrl = $scheme . '://' . $host . $port;
            }

            return [
                'website_id' => $websiteId,
                'name' => (string)($detectWebsiteData->getData('name') ?? ''),
                'code' => (string)($detectWebsiteData->getData('code') ?? ''),
                'url' => $websiteUrl,
                'default_currency' => (string)($detectWebsiteData->getData('default_currency') ?: self::getFrameworkDefaultCurrency()),
                'default_language' => (string)($detectWebsiteData->getData('default_language') ?: self::getFrameworkDefaultLanguage()),
                'default_timezone' => (string)($detectWebsiteData->getData('default_timezone') ?: \date_default_timezone_get()),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function ensureParserSitesLoaded(): void
    {
        if (!empty(self::$parserSites)) {
            return;
        }

        self::$parsingInProgress = true;
        try {
            $detectWebsiteStart = \microtime(true);
            $detect_website_data = new DataObject([
                'sites' => [],
                'site_sample' => [
                    "website_id" => 0,
                    "name" => "榛樿缃戠珯",
                    "code" => "default",
                    "url" => "http://127.0.0.1:9981/default",
                    "default_currency" => "CNY",
                    "default_language" => "zh_Hans_CN",
                    "default_timezone" => "Asia/Shanghai",
                ],
                'get_sites' => true
            ]);
            $eventManager = w_obj(EventsManager::class);
            $eventManager->dispatch('Weline_Framework_Url::detect_website', $detect_website_data);
            $detectWebsiteEnd = \microtime(true);
            $detectWebsiteDuration = \round(($detectWebsiteEnd - $detectWebsiteStart) * 1000, 2);
            if ($detectWebsiteDuration > 100) {
                w_log_warning('[WLS Performance] detect_website event took ' . $detectWebsiteDuration . 'ms');
            }
            $sites = $detect_website_data->getData('sites');
            $tmp = [];
            foreach ($sites as $site) {
                $site_url = $site['url'];
                $length = strlen($site_url);
                $tmp[$length][] = $site;
            }
            krsort($tmp);
            foreach ($tmp as $sitesAtLength) {
                foreach ((array)$sitesAtLength as $site) {
                    $site_url = $site['url'];
                    self::$parserSites[$site_url] = $site;
                }
            }
        } finally {
            self::$parsingInProgress = false;
        }
    }

    private static function currentParserFiber(): ?\Fiber
    {
        if (!\class_exists(\Weline\Framework\Runtime\Runtime::class)) {
            return null;
        }

        if (!\Weline\Framework\Runtime\Runtime::isPersistent()) {
            return null;
        }

        return \Fiber::getCurrent();
    }

    /**
     * 获取当前请求的唯一标识（用于跨请求缓存隔离）。
     *
     * 优先级：
     * 1. RequestContext::getRequestId() —— 每个 WLS 请求由 bootstrapRequestCycle 生成
     * 2. Context::current()->get('runtime.request_count') —— WlsRuntime 在 parser 前设置
     * 3. $_SERVER['WLS_REQUEST_COUNT'] —— 兜底
     * 4. null —— FPM 模式无需隔离
     */
    private static function currentRequestId(): ?string
    {
        if (\class_exists(\Weline\Framework\Runtime\RequestContext::class, false)) {
            $requestId = \Weline\Framework\Runtime\RequestContext::getRequestId();
            if ($requestId !== null && $requestId !== '') {
                return $requestId;
            }
        }

        if (\class_exists(\Weline\Framework\Runtime\Runtime::class, false)
            && \Weline\Framework\Runtime\Runtime::isPersistent()
        ) {
            $context = Context::getCurrent();
            if ($context !== null) {
                $count = $context->get('runtime.request_count', null);
                if ($count !== null) {
                    return 'rc-' . $count;
                }
            }
            $count = \Weline\Framework\Env\WelineEnv::server('WLS_REQUEST_COUNT', null);
            if ($count !== null) {
                return 'rc-' . $count;
            }
        }

        return null;
    }

    private static function parserState(): UrlParserRequestState
    {
        $fiber = self::currentParserFiber();
        if ($fiber === null) {
            self::$mainParserState ??= new UrlParserRequestState();
            $requestId = self::currentRequestId();
            if ($requestId !== null && self::$mainParserState->requestId !== $requestId) {
                self::$mainParserState = new UrlParserRequestState();
                self::$mainParserState->requestId = $requestId;
            }
            return self::$mainParserState;
        }

        self::$fiberParserStates ??= new \WeakMap();
        if (!isset(self::$fiberParserStates[$fiber])) {
            self::$fiberParserStates[$fiber] = new UrlParserRequestState();
        }

        $state = self::$fiberParserStates[$fiber];
        $requestId = self::currentRequestId();
        // WLS 中同一个 Fiber 可能被复用于处理多个请求（Fiber 结束后被重用），
        // 此时 state 里可能残留上一个请求的解析缓存。用 requestId 检测并重置。
        if ($requestId !== null && $state->requestId !== $requestId) {
            $state = new UrlParserRequestState();
            $state->requestId = $requestId;
            self::$fiberParserStates[$fiber] = $state;
        }

        return $state;
    }

    private static function &parserServerRef(): array
    {
        $state = self::parserState();
        return $state->parserServer;
    }

    private static function &parserMatchsRef(): array
    {
        $state = self::parserState();
        return $state->parserMatchs;
    }

    private static function &parserCacheRef(): array
    {
        $state = self::parserState();
        return $state->parserCache;
    }

    private static function &decodeUrlsRef(): array
    {
        $state = self::parserState();
        return $state->decodeUrls;
    }

    private static function &parsingInProgressRef(): bool
    {
        $state = self::parserState();
        return $state->parsingInProgress;
    }

    private static function currentServer(): array
    {
        $context = Context::getCurrent();
        $server = $context?->server();
        if (\is_array($server) && $server !== []) {
            return $server;
        }

        return \Weline\Framework\Env\WelineEnv::serverAll();
    }

    private static function updateCurrentServerVar(string $key, mixed $value): void
    {
        \Weline\Framework\Env\WelineEnv::setServer($key, $value, 'Url::parser current request sync');

        $context = Context::getCurrent();
        if ($context === null) {
            return;
        }

        $server = $context->server();
        if (!\is_array($server)) {
            $server = [];
        }
        $server[$key] = $value;
        $context->set('input.server', $server);
    }

    private static function syncParserServerToCurrentContext(): void
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return;
        }

        $server = $context->server();
        if (!\is_array($server)) {
            $server = [];
        }
        $server = \array_merge($server, self::$parserServer);
        $context->set('input.server', $server);
        $context->set('input.uri', (string)(self::$parserServer['REQUEST_URI'] ?? $context->get('input.uri', '/')));
        $context->set('input.host', (string)(self::$parserServer['HTTP_HOST'] ?? self::$parserServer['SERVER_NAME'] ?? $context->get('input.host', '')));
        $context->set('input.scheme', (string)(self::$parserServer['REQUEST_SCHEME'] ?? $context->get('input.scheme', 'http')));
        $context->set('input.origin_request_uri', (string)(self::$parserServer['WELINE_ORIGIN_REQUEST_URI'] ?? self::$parserServer['ORIGIN_REQUEST_URI'] ?? $context->get('input.origin_request_uri', '/')));
        $context->set('input.full_request_uri', (string)(self::$parserServer['WELINE_FULL_REQUEST_URI'] ?? $context->get('input.full_request_uri', '')));
        $context->set('route.area', (string)(self::$parserServer['WELINE_AREA'] ?? $context->get('route.area', 'frontend')));
        $context->set('route.area_route', (string)(self::$parserServer['WELINE_AREA_ROUTE'] ?? $context->get('route.area_route', '')));
        $context->set('route.website_id', (int)(self::$parserServer['WELINE_WEBSITE_ID'] ?? $context->get('route.website_id', 0)));
        $context->set('route.website_code', (string)(self::$parserServer['WELINE_WEBSITE_CODE'] ?? $context->get('route.website_code', '')));
        $context->set('route.website_url', (string)(self::$parserServer['WELINE_WEBSITE_URL'] ?? $context->get('route.website_url', '')));
        $context->set('route.language', (string)(self::$parserServer['WELINE_USER_LANG'] ?? $context->get('route.language', 'zh_Hans_CN')));
        $context->set('route.currency', (string)(self::$parserServer['WELINE_USER_CURRENCY'] ?? $context->get('route.currency', 'CNY')));

        if (\class_exists(\Weline\Framework\Env\WelineEnv::class, false)) {
            foreach ([
                         'HTTP_HOST',
                         'SERVER_NAME',
                         'SERVER_PORT',
                         'REQUEST_SCHEME',
                         'HTTPS',
                         'REQUEST_URI',
                         'WELINE_ORIGIN_REQUEST_URI',
                         'WELINE_FULL_REQUEST_URI',
                         'WELINE_USER_LANG',
                         'WELINE_USER_CURRENCY',
                         'WELINE_WEBSITE_URL',
                         'WELINE_WEBSITE_ID',
                         'WELINE_WEBSITE_CODE',
                     ] as $serverKey) {
                if (\array_key_exists($serverKey, self::$parserServer)) {
                    \Weline\Framework\Env\WelineEnv::setServer(
                        $serverKey,
                        self::$parserServer[$serverKey],
                        'Url::parser current request sync'
                    );
                }
            }
        }
    }

    private static function isCurrentRequestUrl(string $parseUrl): bool
    {
        if ($parseUrl === '' || !\str_contains($parseUrl, '://')) {
            return false;
        }

        $server = self::currentServer();
        $requestUri = (string)($server['WELINE_ORIGIN_REQUEST_URI'] ?? $server['REQUEST_URI'] ?? '/');
        if ($requestUri === '') {
            $requestUri = '/';
        }
        if (!\str_starts_with($requestUri, '/')) {
            $requestUri = '/' . $requestUri;
        }

        $scheme = self::getCurrentScheme();
        $host = (string)($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '');
        if ($host === '') {
            return false;
        }

        $port = (string)($server['SERVER_PORT'] ?? '');
        $hostWithoutPort = $host;
        if (\str_contains($host, ':')) {
            [$hostWithoutPort] = \explode(':', $host, 2);
        }

        $candidates = [
            $scheme . '://' . $host . $requestUri,
        ];
        if ($port !== ''
            && $hostWithoutPort !== ''
            && !\str_contains($host, ':')
            && !(($scheme === 'https' && $port === '443') || ($scheme !== 'https' && $port === '80'))
        ) {
            $candidates[] = $scheme . '://' . $hostWithoutPort . ':' . $port . $requestUri;
        }

        $normalize = static function (string $url): string {
            return \rtrim(self::removeExtraDoubleSlashes($url), '/');
        };
        $target = $normalize($parseUrl);
        foreach ($candidates as $candidate) {
            if ($target === $normalize($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 注册静态变量重置到 StateManager
     * 
     * 用于 WLS 模式下每个请求后自动清理静态缓存。
     * 由 StateManager::registerFrameworkResets() 调用。
     */
    public static function registerStateResets(): void
    {
        if (!\class_exists(\Weline\Framework\Runtime\StateManager::class, false)) {
            return;
        }
        
        \Weline\Framework\Runtime\StateManager::registerStaticResets(self::class, [
            'parserServer' => [],
            'parserUrlCache' => [],
            'splitUrlCache' => [],
            'parserCache' => [],
            'parsingInProgress' => false,
            'decode_urls' => [],
            'parserMatchs' => [],
            'parserSiteMatchs' => [],
        ]);
    }
}
