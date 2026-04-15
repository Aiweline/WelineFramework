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
}

class Url implements UrlInterface
{
    private const PARSER_SITES_VERSION_CACHE_KEY = 'websites.url.parser_sites_version.v1';

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
        // 优化：使用语言缓存，避免重复数据库查询
        $cache = w_cache('i18n');
        $checkCacheKey = 'lang_check_' . strtolower($code);
        $checkResult = $cache->get($checkCacheKey);
        
        if ($checkResult !== false) {
            if ((bool)$checkResult) {
                // 找到语言，更新URI并设置
                if (str_starts_with($uri, '/' . $code)) {
                    $uri = substr($uri, strlen('/' . $code));
                }
                self::$parserServer['WELINE_USER_LANG'] = $code;
                return true;
            }
            // 明确知道语言不存在，直接返回
            return false;
        }
        
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
        
        // 保存检查结果到缓存
        $cache->set($checkCacheKey, $result ? 1 : 0);
        
        if ($result) {
            if (str_starts_with($uri, '/' . $code)) {
                $uri = substr($uri, strlen('/' . $code));
            }
            self::$parserServer['WELINE_USER_LANG'] = $code;
            return true;
        }
        return false;
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
        if (isset(self::$parserCurrencies[$codeUpper])) {
            self::$parserServer['WELINE_USER_CURRENCY'] = $codeUpper;
            return true;
        }
        
        // 优化：使用货币缓存，避免重复数据库查询
        $cache = w_cache('currency');
        $currencyCacheKey = 'currency_code_' . $codeUpper;
        $currency = $cache->get($currencyCacheKey);
        
        if ($currency !== false && is_array($currency) && isset($currency['code'])) {
            // 找到货币，更新URI并设置
            if (str_starts_with($uri, '/' . $code)) {
                $uri = substr($uri, strlen('/' . $code));
            }
            self::$parserServer['WELINE_USER_CURRENCY'] = $codeUpper;
            // 缓存成功的货币代码到静态缓存
            self::$parserCurrencies[$codeUpper] = $codeUpper;
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

        if ($detect_currency_data->getData('result')) {
            if (str_starts_with($uri_, '/' . $code)) {
                $uri = substr($uri_, strlen('/' . $code));
            }
            self::$parserServer['WELINE_USER_CURRENCY'] = $codeUpper;
            // 缓存成功的货币代码到静态缓存
            self::$parserCurrencies[$codeUpper] = $codeUpper;
        }
        return (bool)$detect_currency_data->getData('result');
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
    public static $parserMatchs = [];
    public static array $parserSiteMatchs = [];

    public static array $parserUrlCache = [];

    public static function parse_url(string $url, string $key = '', string $default = ''): array|string
    {
        if (isset(self::$parserUrlCache[$url])) {
            if ($key) {
                return self::$parserUrlCache[$url][$key] ?? $default;
            }
            return self::$parserUrlCache[$url];
        }
        $parsed = parse_url($url);
        self::$parserUrlCache[$url] = is_array($parsed) ? $parsed : [];
        if ($key) {
            return self::$parserUrlCache[$url][$key] ?? $default;
        }
        return self::$parserUrlCache[$url];
    }

    public static array $splitUrlCache = [];

    public static function split_url(string $url, string $key = '', string $default = ''): array|string
    {
        if (isset(self::$splitUrlCache[$url])) {
            if ($key) {
                return self::$splitUrlCache[$url][$key] ?? $default;
            }
            return self::$splitUrlCache[$url];
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
        self::$splitUrlCache[$url]['path'] = $parse['path'] ?? '';
        self::$splitUrlCache[$url]['split'] = $paths;
        self::$splitUrlCache[$url]['query'] = $parse['query'] ?? '';
        if ($key) {
            return self::$splitUrlCache[$url][$key] ?? $default;
        }
        return self::$splitUrlCache[$url];
    }

    public static array $parserCache = [];
    
    /**
     * 防止重入标志，避免在获取网站列表时触发循环
     * @var bool
     */
    private static bool $parsingInProgress = false;

    public static function parser(string $parse_url = '', string $key = ''): array|string
    {
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

        $url = $parse_url;
        # 初始化server
        if (empty($parserServer)) {
            $parserServer = self::currentServer();
            $parserServer['WELINE_ORIGIN_TIMEZONE'] = date_default_timezone_get();
            
            // 使用新的 area_routes 分组配置获取区域前缀
            $restFrontendPrefix = Env::getAreaRoutePrefix('rest_frontend');
            if (empty($restFrontendPrefix)) {
                // 如果没有配置，使用默认值 'api'
                $parserServer['WELINE_REST_FRONTEND_PREFIX'] = 'api';
            } else {
                $parserServer['WELINE_REST_FRONTEND_PREFIX'] = strtolower($restFrontendPrefix);
            }
            $parserServer['WELINE_REST_BACKEND_PREFIX'] = Env::getAreaRoutePrefix('rest_backend') ?? '';
            $parserServer['WELINE_BACKEND_PREFIX'] = Env::getAreaRoutePrefix('backend') ?? '';
            
            // 保留旧的变量名以兼容，后续可逐步移除
            $parserServer['WELINE_API_AREA'] = $parserServer['WELINE_REST_FRONTEND_PREFIX'];
            $parserServer['WELINE_API_AREA_PREFIX'] = '/' . $parserServer['WELINE_REST_FRONTEND_PREFIX'] . '/';
            $parserServer['WELINE_API_ADMIN_AREA'] = $parserServer['WELINE_REST_BACKEND_PREFIX'];
            $parserServer['WELINE_BACKEND_AREA'] = $parserServer['WELINE_BACKEND_PREFIX'];
            
            $parserServer['WELINE_AREA_ROUTE'] = '';
            $parserServer['WELINE_AREA'] = 'frontend';
            $parserServer['WELINE_USER_CURRENCY'] = State::getCurrency();
            $parserServer['WELINE_USER_LANG'] = State::getLang();
            $parserServer['WELINE_WEBSITE_ID'] = $parserServer['WELINE_WEBSITE_ID'] ?? '';
            $parserServer['WELINE_WEBSITE_CODE'] = $parserServer['WELINE_WEBSITE_CODE'] ?? '';
            $parserServer['WELINE_WEBSITE_URL'] = $parserServer['WELINE_WEBSITE_URL'] ?? '';
        }
        
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
        // 如果 self::$parserSites 已经初始化，直接使用，避免重复事件分发
        if (empty(self::$parserSites)) {
            $parsingInProgress = true;
            try {
                $detectWebsiteStart = \microtime(true);
                $detect_website_data = new DataObject([
                    'sites' => [],
                    'site_sample' => [
                        "website_id" => 1,
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
                $parsingInProgress = false;
            }
        }
        # 匹配网站 self::$parserSites 最长倒序
        $parsers = self::parse_url($url);
        if (!is_array($parsers)) {
            $parsers = [];
        }
        // 构建网站 URL，确保使用当前请求的协议
        $currentScheme = self::getCurrentScheme();
        $hostPart = ($parsers['host'] ?? 'localhost');
        $portPart = ($parsers['port'] ?? '');
        $portSuffix = ($portPart === '' || $portPart === '80' || $portPart === '443') ? '' : ':' . $portPart;
        $data['website_url'] = $currentScheme . '://' . $hostPart . $portSuffix;
        self::$parserServer['WELINE_WEBSITE_URL'] = $data['website_url'];
        foreach (self::$parserSites as $site_url => $site) {
            $site_url_for_match = self::ensureCurrentScheme($site_url);
            // 如果站点 URL 无端口，但当前请求有端口，需要补全端口再匹配
            // 否则 str_starts_with('https://my.com:9981/...', 'https://my.com') 会误匹配
            // 导致 str_replace 后残留 ':9981/...'，parse_url 无法解析路径
            $site_parsed_tmp = \parse_url($site_url_for_match);
            if (!isset($site_parsed_tmp['port']) && !empty($parsers['port'])) {
                $site_url_for_match = ($site_parsed_tmp['scheme'] ?? 'https') . '://'
                    . ($site_parsed_tmp['host'] ?? 'localhost')
                    . ':' . $parsers['port']
                    . ($site_parsed_tmp['path'] ?? '');
            }
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
        if (!isset($data['website']) && str_contains($url, '://')) {
            self::resetWebsiteParserSites();
            self::ensureParserSitesLoaded();
            foreach (self::$parserSites as $site_url => $site) {
                $site_url_for_match = self::ensureCurrentScheme($site_url);
                $site_parsed_tmp = \parse_url($site_url_for_match);
                if (!isset($site_parsed_tmp['port']) && !empty($parsers['port'])) {
                    $site_url_for_match = ($site_parsed_tmp['scheme'] ?? 'https') . '://'
                        . ($site_parsed_tmp['host'] ?? 'localhost')
                        . ':' . $parsers['port']
                        . ($site_parsed_tmp['path'] ?? '');
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
            
            // 域名不匹配时回退到默认网站：
            // 当使用非配置域名（如 my.com 而非 127.0.0.1）访问时，上方的 str_starts_with 匹配
            // 全部失败，导致 WELINE_WEBSITE_CODE/ID 为空。
            // 依赖网站上下文的逻辑（SEO URL 解码、Store 路由、货币/语言默认值等）
            // 会因缺少网站信息而失败，导致 404。
            // 修复：从已加载的 parserSites 中选取默认网站，填充网站元数据，
            // 但不修改 URL（路径已由上方 host 剥离逻辑正确处理）。
            if (!empty(self::$parserSites)) {
                $defaultSite = null;
                // 优先查找 code='default' 的网站
                foreach (self::$parserSites as $siteUrl => $siteData) {
                    if (($siteData['code'] ?? '') === 'default') {
                        $defaultSite = $siteData;
                        break;
                    }
                }
                // 无 'default' 则取列表中最后一个（排序后最短 URL，通常为根网站）
                if ($defaultSite === null) {
                    $defaultSite = end(self::$parserSites);
                }
                if ($defaultSite) {
                    $data['website'] = $defaultSite;
                    self::$parserServer['WELINE_WEBSITE_CODE'] = $defaultSite['code'] ?? '';
                    self::$parserServer['WELINE_WEBSITE_ID'] = $defaultSite['website_id'] ?? '';
                    self::$parserServer['WELINE_WEBSITE_URL'] = $data['website_url']; // 使用当前请求的 scheme://host:port
                    self::$parserServer['WELINE_WEBSITE_CURRENCY'] = $defaultSite['default_currency'] ?? '';
                    self::$parserServer['WELINE_WEBSITE_LANGUAGE'] = $defaultSite['default_language'] ?? '';
                    if (empty(self::$parserServer['WELINE_USER_LANG'])) {
                        self::$parserServer['WELINE_USER_LANG'] = State::getLang() ?: ($defaultSite['default_language'] ?? '');
                    }
                    if (empty(self::$parserServer['WELINE_USER_CURRENCY'])) {
                        self::$parserServer['WELINE_USER_CURRENCY'] = State::getCurrency() ?: ($defaultSite['default_currency'] ?? '');
                    }
                }
            }
        }

        # 前缀区域去除
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
        if (isset(self::$parserCache[$url])) {
            if ($key) {
                return self::$parserCache[$url][$key] ?? '';
            }
            return self::$parserCache[$url];
        }
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
            && (str_contains($url, 'ai-site-agent') || $area === 'U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8' || str_contains($url, 'pagebuilder/backend'))
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
        
        $url = $uri . (self::parse_url($url, 'query') ? '?' . self::parse_url($url, 'query') : '');
        # URL结构：[网站前缀]/[货币前缀]/[语言前缀]/[路由]

        $data['currency'] = '';
        $data['language'] = '';
        $data['timezone'] = ($data['website'] ?? [])['timezone'] ?? 'Asia/Shanghai';
        # 匹配货币 self::$parserCurrencies 最长倒序
        foreach (self::$parserCurrencies as $currency) {
            if (str_starts_with($url, '/' . $currency)) {
                $url = str_replace('/' . $currency, '', $url);
                $data['currency'] = $currency;
                break;
            }
        }

        # 匹配语言 self::$parserLanguages 最长倒序
        foreach (self::$parserLanguages as $language) {
            if (str_starts_with($url, $language)) {
                $url = str_replace($language, '', $url);
                $data['language'] = $language;
                break;
            }
        }

        $data['all_match'] = false;
        if ($uri and '/' !== $uri) {
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
                    if (strlen($pre_path_1) === 3) {
                        $has_currency = self::detectCurrency($url, $pre_path_1);
                        if ($has_currency) {
                            $data['currency'] = $pre_path_1;
                            self::$parserCurrencies[$pre_path_1] = $pre_path_1;
                        }
                    }
                    if (!$has_currency && strlen($pre_path_1) > 3 && strlen($pre_path_1) <= 10 && ctype_lower(substr($pre_path_1, 0, 2)) && $pre_path_1[2] === '_') {
                        # 检查头路径$pre_path_1是否是语言
                        $has_language = self::detectLanguage($url, $pre_path_1);
                        if ($has_language) {
                            $data['language'] = $pre_path_1;
                            self::$parserLanguages[$pre_path_1] = $pre_path_1;
                        }
                    }
                }
                if ($pre_path_2) {
                    # 检查第二个路径是否是语言
                    if (!$has_language && strlen($pre_path_2) > 3 && strlen($pre_path_2) <= 10 && ctype_lower(substr($pre_path_2, 0, 2)) && $pre_path_2[2] === '_') {
                        $has_language = self::detectLanguage($url, $pre_path_2);
                        if ($has_language) {
                            $data['language'] = $pre_path_2;
                            self::$parserLanguages[$pre_path_2] = $pre_path_2;
                        }
                    }

                    # 检查第二个路径是否是货币
                    if (!$has_currency && strlen($pre_path_2) === 3) {
                        $has_currency = self::detectCurrency($url, $pre_path_2);
                        if ($has_currency) {
                            $data['currency'] = $pre_path_2;
                            self::$parserCurrencies[$pre_path_2] = $pre_path_2;
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
        if (empty($data['currency']) || empty($data['language'])) {
            $parsed_url = self::parse_url($url);
            $query_params = [];
            if (isset($parsed_url['query'])) {
                parse_str($parsed_url['query'], $query_params);
            }
            
            // 从URL参数获取currency（如果路径级别没有）
            if (empty($data['currency']) && isset($query_params['currency'])) {
                $currency = trim($query_params['currency'] ?? '');
                if (!empty($currency) && strlen($currency) === 3) {
                    // 验证是否为有效的货币代码（3位大写字母）
                    if (ctype_upper($currency)) {
                        $data['currency'] = $currency;
                    }
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
        $decode_url = self::decode_url($url);
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
        self::syncParserServerToCurrentContext();
        $data['server'] = self::$parserServer;
        
        # 解析缓存（必须在更新 $parserServer 后缓存，确保 server 字段包含正确的语言/货币）
        if ($data['all_match']) {
            $match_url = $data['website_url'] . ($has_area ? $area : '') . '/' . $data['currency'] . '/' . $data['language'];
            self::$parserMatchs[$match_url] = $data;
        }
        self::$parserCache[$url] = $data;
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

    public static function decode_url(string $url): string
    {
        if (isset(self::$decode_urls[$url])) {
            return self::$decode_urls[$url];
        }
        # decode seo url
        if (Env::get('seo')) {
            /**@var EventsManager $event */
            $event = ObjectManager::getInstance(EventsManager::class);
            $origin_url = $url;
            $event->dispatch('Weline_Framework_Url::seo_decode', $url);
            
            // 缓存原始URL到解码后URL的映射
            self::$decode_urls[$origin_url] = $url;
            
            // 关键：如果URL发生了变化，也缓存解码后的URL指向自己，避免二次解码
            if ($url !== $origin_url) {
                self::$decode_urls[$url] = $url;
            }
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
        self::$parserCache = [];
        self::$decode_urls = [];
        self::$parsingInProgress = false;
    }

    public static function resetWebsiteParserSites(): void
    {
        self::$parserSites = [];
        self::$parserSiteMatchs = [];
        self::$parserMatchs = [];
        self::$parserSitesVersion = '';
    }

    private static function ensureParserSitesFresh(): void
    {
        $version = '';
        try {
            $cached = w_cache('website_detect')->get(self::PARSER_SITES_VERSION_CACHE_KEY);
            $version = \is_scalar($cached) ? (string)$cached : '';
        } catch (\Throwable) {
        }

        if ($version === '') {
            return;
        }

        if (self::$parserSitesVersion !== '' && self::$parserSitesVersion !== $version) {
            self::resetWebsiteParserSites();
        }

        self::$parserSitesVersion = $version;
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
                    "website_id" => 1,
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

    private static function parserState(): UrlParserRequestState
    {
        $fiber = self::currentParserFiber();
        if ($fiber === null) {
            self::$mainParserState ??= new UrlParserRequestState();
            return self::$mainParserState;
        }

        self::$fiberParserStates ??= new \WeakMap();
        if (!isset(self::$fiberParserStates[$fiber])) {
            self::$fiberParserStates[$fiber] = new UrlParserRequestState();
        }

        return self::$fiberParserStates[$fiber];
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
        // WLS 常驻模式下，若当前不在 Fiber 请求上下文，禁止回退 mainContext，
        // 避免误读上一次请求残留的上下文 server 快照。
        if (\class_exists(\Weline\Framework\Runtime\Runtime::class, false)
            && \Weline\Framework\Runtime\Runtime::isPersistent()
            && \class_exists(\Fiber::class)
            && \Fiber::getCurrent() === null
        ) {
            return \is_array($_SERVER ?? null) ? $_SERVER : [];
        }

        $context = Context::getCurrent();
        $server = $context?->server();
        if (\is_array($server) && $server !== []) {
            return $server;
        }

        return \is_array($_SERVER ?? null) ? $_SERVER : [];
    }

    private static function updateCurrentServerVar(string $key, mixed $value): void
    {
        $_SERVER[$key] = $value;

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
        $context->set('route.area', (string)(self::$parserServer['WELINE_AREA'] ?? $context->get('route.area', 'frontend')));
        $context->set('route.area_route', (string)(self::$parserServer['WELINE_AREA_ROUTE'] ?? $context->get('route.area_route', '')));
        $context->set('route.website_id', (int)(self::$parserServer['WELINE_WEBSITE_ID'] ?? $context->get('route.website_id', 0)));
        $context->set('route.website_code', (string)(self::$parserServer['WELINE_WEBSITE_CODE'] ?? $context->get('route.website_code', '')));
        $context->set('route.website_url', (string)(self::$parserServer['WELINE_WEBSITE_URL'] ?? $context->get('route.website_url', '')));
        $context->set('route.language', (string)(self::$parserServer['WELINE_USER_LANG'] ?? $context->get('route.language', 'zh_Hans_CN')));
        $context->set('route.currency', (string)(self::$parserServer['WELINE_USER_CURRENCY'] ?? $context->get('route.currency', 'CNY')));
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
        ]);
    }
}
