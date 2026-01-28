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
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class Url implements UrlInterface
{
    protected Request $request;

    public function __construct(
        Request $request
    )
    {
        $this->request = $request;
    }

    /**
     * @param mixed $uri
     * @param string $code
     * @return bool
     */
    public static function detectLanguage(string &$uri, string $code): bool
    {
        // 优化：使用语言缓存类，避免重复数据库查询
        $languageCache = new \Weline\I18n\Cache\LanguageCache();
        $checkResult = $languageCache->checkLanguage($code);
        
        if ($checkResult === true) {
            // 找到语言，更新URI并设置
            if (str_starts_with($uri, '/' . $code)) {
                $uri = substr($uri, strlen('/' . $code));
            }
            self::$parserServer['WELINE_USER_LANG'] = $code;
            return true;
        } elseif ($checkResult === false) {
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
        $languageCache->setLanguageCheck($code, $result);
        
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
        
        // 优化：使用货币缓存类，避免重复数据库查询
        $currencyCache = new \Weline\Currency\Cache\CurrencyCache();
        $currency = $currencyCache->getByCode($code);
        
        if ($currency !== null && isset($currency['code'])) {
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
        # 判断前后端
        if ($this->request->isBackend()) {
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
                $router = $this->request->getRouterData('router');
                if (str_contains($path, '*')) {
                    $path = str_replace('*', $router, $path);
                    $path = str_replace('//', '/', $path);
                }
                $url = $this->request->getBaseHost() . '/' . Env::getInstance()->getConfig('api_admin') . '/' . $path;
            } else {
                $url = $path;
            }
        } else {
            $url = $this->request->getBaseUrl();
        }
        return $this->extractedUrl($params, $merge_url_params, $url);
    }

    public function getFrontendApiUrl(string $path = '', array $params = [], bool $merge_url_params = true): string
    {
        if ($path) {
            if (!$this->isLink($path)) {
                # URL自带星号处理
                $router = $this->request->getRouterData('router');
                if (str_contains($path, '*')) {
                    $path = str_replace('*', $router, $path);
                    $path = str_replace('//', '/', $path);
                }
                
                // 获取货币和语言
                $currency = $_SERVER['WELINE_USER_CURRENCY'] ?? $_SERVER['WELINE_WEBSITE_CURRENCY'] ?? 'CNY';
                $language = $_SERVER['WELINE_USER_LANG'] ?? $_SERVER['WELINE_WEBSITE_LANGUAGE'] ?? 'zh_Hans_CN';
                
                // 获取API area前缀（前端API使用Env::get('api')）
                $apiArea = Env::get('api') ?: 'api';
                $apiAreaPrefix = '/' . strtolower($apiArea) . '/';
                
                // 构建URL: /{area_prefix}/{currency}/{language}/{path}
                $url = $this->request->getBaseHost() . $apiAreaPrefix . $currency . '/' . $language . '/' . ltrim($path, '/');
            } else {
                $url = $path;
            }
        } else {
            // 获取货币和语言
            $currency = $_SERVER['WELINE_USER_CURRENCY'] ?? $_SERVER['WELINE_WEBSITE_CURRENCY'] ?? 'CNY';
            $language = $_SERVER['WELINE_USER_LANG'] ?? $_SERVER['WELINE_WEBSITE_LANGUAGE'] ?? 'zh_Hans_CN';
            
            // 获取API area前缀（前端API使用Env::get('api')）
            $apiArea = Env::get('api') ?: 'api';
            $apiAreaPrefix = '/' . strtolower($apiArea) . '/';
            
            // 构建URL: /{area_prefix}/{currency}/{language}/
            $url = $this->request->getBaseHost() . $apiAreaPrefix . $currency . '/' . $language . '/';
        }
        return $this->extractedUrl($params, $merge_url_params, $url);
    }

    public function getFrontendUrl(string $path = '', array $params = [], bool $merge_url_params = false)
    {
        if ($path) {
            if (!$this->isLink($path)) {
                # URL自带星号处理
                $router = $this->request->getRouterData('router');
                if (str_contains($path, '*')) {
                    $path = str_replace('*', $router, $path);
                    $path = str_replace('//', '/', $path);
                }
                $url = $this->request->getBaseHost() . self::getPrefix() . '/' . ltrim($path, '/');
            } else {
                $url = $path;
            }
        } else {
            $url = $this->request->getBaseUrl();
        }
        return $this->extractedUrl($params, $merge_url_params, $url);
    }

    public function getUrl(string $path = '', array $params = [], bool $merge_url_params = false): string
    {
        if ($this->request->isBackend()) {
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
                $router = $this->request->getRouterData('router');
                if (str_contains($path, '*')) {
                    $path = str_replace('*', $router, $path);
                    $path = str_replace('//', '/', $path);
                }
                $url = $this->request->getBaseHost() . '/' . ltrim($path, '/');
            } else {
                $url = $path;
            }
        } else {
            $url = $this->request->getBaseUrl();
        }
        return $this->extractedUrl($params, $merge_url_params, $url);
    }

    static function getPrefix()
    {
        $prefix = '';
        
        // 安全地获取货币前缀
        if (!empty($_SERVER['WELINE_USER_CURRENCY'])) {
            $prefix .= '/' . $_SERVER['WELINE_USER_CURRENCY'];
        }
        
        // 安全地获取语言前缀
        if (!empty($_SERVER['WELINE_USER_LANG'])) {
            $prefix .= '/' . $_SERVER['WELINE_USER_LANG'];
        }
        
        return $prefix;
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
                $router = $this->request->getRouterData('router');
                if (str_contains($path, '*')) {
                    $path = str_replace('*', $router, $path);
                    $path = str_replace('//', '/', $path);
                }
                $url = $this->request->getBaseHost() . '/' . Env::getInstance()->getConfig('admin') . self::getPrefix() . (('/' === $path) ? '/' : '/' . ltrim($path, '/'));
            } else {
                $url = $path;
            }
        } else {
            $url = $this->request->getOriginBaseUrl();
        }

        return $this->extractedUrl($params, $merge_url_params, $url);
    }

    public function getOriginBackendUrl(string $path = '', array $params = [], bool $merge_url_params = false): string
    {
        if ($path) {
            if (!$this->isLink($path)) {
                # URL自带星号处理
                $router = $this->request->getRouterData('backend_router');
                if (str_contains($path, '*')) {
                    $path = str_replace('*', $router, $path);
                    $path = str_replace('//', '/', $path);
                }
                $url = $this->request->getBaseHost() . '/' . Env::getInstance()->getConfig('admin') . (('/' === $path) ? '' : '/' . ltrim($path, '/'));
            } else {
                $url = $path;
            }
        } else {
            $url = $this->request->getBaseUrl();
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
            return $this->request->getUri();
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
            $url = $this->request->getBaseUrl();
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
            $getParams = $this->request->getGet();
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

    public function getUrlOrigin($s, $use_forwarded_host = false): string
    {
        $ssl = (!empty($s['HTTPS']) && $s['HTTPS'] == 'on');
        $sp = strtolower($s['SERVER_PROTOCOL']);
        if ($sp == 'https' || $sp == 'ssl://' || $sp == 'HTTP/1.1') {
            $ssl = true;
        }
        $protocol = $ssl ? 'https' : 'http';
        $port = $s['SERVER_PORT'];
        $port = ((!$ssl && $port == '80') || ($ssl && $port == '443')) ? '' : ':' . $port;
        $host = ($use_forwarded_host && isset($s['HTTP_X_FORWARDED_HOST'])) ? $s['HTTP_X_FORWARDED_HOST'] : ($s['SERVER_NAME'] ?? null);
        $host = ($host ?? $s['SERVER_NAME']) . $port;
        return $protocol . '://' . $host;
    }

    public function getFullUrl($s, $use_forwarded_host = false): string
    {
        return self::removeExtraDoubleSlashes($this->getUrlOrigin($s, $use_forwarded_host) . '/' . ($s['WELINE_ORIGIN_REQUEST_URI'] ?? $s['REQUEST_URI']));
    }

    public function getCurrentUrl(array $params = [], bool $merge_url_params = true): string
    {
        $url = self::removeExtraDoubleSlashes($this->getUrlOrigin($_SERVER, false) . '/' . ($_SERVER['WELINE_ORIGIN_REQUEST_URI'] ?? $_SERVER['REQUEST_URI']));
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
        // 防止重入：如果正在解析中，直接返回URL，避免无限循环
        if (self::$parsingInProgress) {
            return $parse_url ?: $_SERVER['REQUEST_URI'] ?? '/';
        }
        
        # 静态文件不用再分析店铺
        if ($parse_url and str_contains($parse_url, '.')
            and preg_match('/\.(jpg|jpeg|png|webp|gif|css|js|ico|woff|woff2|txt|pdf|doc|docx|xls|xlsx|ppt|pptx)$/', $parse_url)) {
            return $parse_url;
        }

        $url = $parse_url;
        # 初始化server
        if (empty(self::$parserServer)) {
            self::$parserServer = $_SERVER;
            self::$parserServer['WELINE_ORIGIN_TIMEZONE'] = date_default_timezone_get();
            // 获取API area前缀，用于URL匹配和生成
            $apiArea = Env::get('api');
            if (empty($apiArea)) {
                // 如果没有配置，使用默认值 'api'
                self::$parserServer['WELINE_API_AREA'] = 'api';
                self::$parserServer['WELINE_API_AREA_PREFIX'] = '/api/rest/';
            } else {
                // 如果配置了，使用配置值（如 'api123'）
                self::$parserServer['WELINE_API_AREA'] = strtolower($apiArea);
                self::$parserServer['WELINE_API_AREA_PREFIX'] = '/' . strtolower($apiArea) . '/';
            }
            self::$parserServer['WELINE_API_ADMIN_AREA'] = Env::get('api_admin');
            self::$parserServer['WELINE_BACKEND_AREA'] = Env::get('admin');
            self::$parserServer['WELINE_AREA_ROUTE'] = '';
            self::$parserServer['WELINE_AREA'] = 'frontend';
            self::$parserServer['WELINE_USER_CURRENCY'] = Cookie::getCurrency();
            self::$parserServer['WELINE_USER_LANG'] = Cookie::getLang();
            self::$parserServer['WELINE_WEBSITE_ID'] = $_SERVER['WELINE_WEBSITE_ID'] ?? '';
            self::$parserServer['WELINE_WEBSITE_CODE'] = $_SERVER['WELINE_WEBSITE_CODE'] ?? '';
            self::$parserServer['WELINE_WEBSITE_URL'] = $_SERVER['WELINE_WEBSITE_URL'] ?? '';
        }
        
        if ($url) {
            $path = self::parse_url($url, 'path') ?: '';
            $query = self::parse_url($url, 'query') ?: '';
            $uri = $path . $query;
        } else {
            $uri = $_SERVER['REQUEST_URI'];
            // 确保 REQUEST_URI 以 / 开头，避免拼接时出现双斜杠
            $request_uri = $_SERVER['REQUEST_URI'];
            if (!str_starts_with($request_uri, '/')) {
                $request_uri = '/' . $request_uri;
            }
            $url = ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . $_SERVER['HTTP_HOST'] . $request_uri;
        }
        # 静态文件不用再分析店铺
        if ($uri and str_contains($uri, '.')
            and preg_match('/\.(jpg|jpeg|png|webp|gif|css|js|ico|woff|woff2|txt|pdf|doc|docx|xls|xlsx|ppt|pptx)$/', $_SERVER['REQUEST_URI'])) {
            return $url;
        }
        // 优化：使用缓存类跨请求缓存网站数据，避免每次请求都查询数据库
        // 如果 self::$parserSites 已经初始化，直接使用，避免重复查询和事件分发
        if (empty(self::$parserSites)) {
            // 使用网站缓存类
            $websiteCache = new \Weline\Websites\Cache\WebsiteCache();
            $cachedSites = $websiteCache->getAllSites();
            
            if ($cachedSites !== null && is_array($cachedSites)) {
                // 使用缓存数据，转换为 self::$parserSites 格式
                foreach ($cachedSites as $site) {
                    $site_url = $site['url'] ?? '';
                    if ($site_url) {
                        self::$parserSites[$site_url] = $site;
                    }
                }
            } else {
                // 缓存不存在或无效，从数据库查询
                // 设置重入保护标志，防止事件处理过程中触发循环
                self::$parsingInProgress = true;
                try {
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
                    $sites = $detect_website_data->getData('sites');
                    # 找出站点链接最长的，依次写入self::$parserSites
                    $tmp = [];
                    foreach ($sites as $site) {
                        $site_url = $site['url'];
                        $length = strlen($site_url);
                        $tmp[$length] = $site;
                    }
                    krsort($tmp);
                    foreach ($tmp as $site) {
                        $site_url = $site['url'];
                        self::$parserSites[$site_url] = $site;
                    }
                    
                    // 将查询结果保存到缓存
                    $websiteCache->setAllSites($sites);
                } finally {
                    // 确保无论成功或异常都重置标志
                    self::$parsingInProgress = false;
                }
            }
        }
        # 匹配网站 self::$parserSites 最长倒序
        $parsers = self::parse_url($url);
        if (!is_array($parsers)) {
            $parsers = [];
        }
        $data['website_url'] = ($parsers['scheme'] ?? '') . '://' . ($parsers['host'] ?? '').(($parsers['port'] ?? '') == '80' || ($parsers['port'] ?? '') == '443' ? '' : ':' . ($parsers['port'] ?? ''));
        self::$parserServer['WELINE_WEBSITE_URL'] = $data['website_url'];
        foreach (self::$parserSites as $site_url => $site) {
            if (str_starts_with($url, $site_url)) {
                $url = str_replace($site_url, '', $url);
                $uri = self::parse_url($url, 'path') ?: '';
                if (isset(self::$parserSiteMatchs[$site_url])) {
                    $data = array_merge((array)$data, self::$parserSiteMatchs[$site_url]);
                }
                $data['url'] = $url;
                $parsed_url = self::parse_url($url);
                $data['parse'] = is_array($parsed_url) ? $parsed_url : [];
                $data['website_url'] = $site_url;
                $data['website'] = $site;
                self::$parserServer['WELINE_WEBSITE_CODE'] = $site['code'];
                self::$parserServer['WELINE_WEBSITE_ID'] = $site['website_id'];
                self::$parserServer['WELINE_WEBSITE_URL'] = $site['url'];
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
                    $data['url'] = $site_url . $query;
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

        # 如果网站匹配失败，从完整URL中提取路径部分
        if (!isset($data['website']) && str_contains($url, '://')) {
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
        }

        # 前缀区域去除
        $splits = self::split_url($url, 'split');

        # 完全前缀匹配 最长匹配逻辑（[网站前缀]/[货币前缀]/[语言前缀]）三个参数都存在的情况
        foreach (self::$parserMatchs as $match_url => $match_data) {
            if (str_starts_with($url, $match_url)) {
                $url = str_replace($match_url, '', $url);
                self::$parserServer['ORIGIN_REQUEST_URI'] = $uri;
                self::$parserServer['REQUEST_URI'] = $url;
                $data['url'] = $url;
                return $data;
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
            return $url;
        }
        $has_area = $data['has_area'] ?? false;
        switch ($area) {
            case self::$parserServer['WELINE_API_AREA']:
                self::$parserServer['WELINE_AREA'] = 'api';
                self::$parserServer['WELINE_AREA_ROUTE'] = self::$parserServer['WELINE_API_AREA_PREFIX'] ?? '/api/rest/';
                array_shift($splits);
                $uri = '/' . implode('/', $splits);
                $has_area = true;
                break;
            case self::$parserServer['WELINE_API_ADMIN_AREA']:
                self::$parserServer['WELINE_AREA'] = 'api_admin';
                self::$parserServer['WELINE_AREA_ROUTE'] = self::$parserServer['WELINE_API_ADMIN_AREA'];
                array_shift($splits);
                $uri = '/' . implode('/', $splits);
                $has_area = true;
                break;
            case self::$parserServer['WELINE_BACKEND_AREA']:
                self::$parserServer['WELINE_AREA'] = 'admin';
                self::$parserServer['WELINE_AREA_ROUTE'] = self::$parserServer['WELINE_BACKEND_AREA'];
                array_shift($splits);
                $uri = '/' . implode('/', $splits);
                $has_area = true;
                break;
            default:
                self::$parserServer['WELINE_AREA'] = 'frontend';
                self::$parserServer['WELINE_AREA_ROUTE'] = '';
                # frontend 分支也需要重建 $uri，避免包含查询字符串
                $uri = '/' . implode('/', $splits);
        }
        $data['has_area'] = $has_area;
        $data['area'] = self::$parserServer['WELINE_AREA'];
        $data['area_route'] = self::$parserServer['WELINE_AREA_ROUTE'];
        
        $url = $uri . (self::parse_url($url, 'query') ? '?' . self::parse_url($url, 'query') : '');
        # URL结构：[网站前缀]/[货币前缀]/[语言前缀]/[路由]

        $data['currency'] = '';
        $data['language'] = '';
        $data['timezone'] = $data['website']['timezone'] ?? 'Asia/Shanghai';
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
            $data['currency'] = self::$parserServer['WELINE_USER_CURRENCY'] ?? $data['website']['default_currency'] ?? 'CNY';
        }
        if (empty($data['language'])) {
            $data['language'] = self::$parserServer['WELINE_USER_LANG'] ?? $data['website']['default_language'] ?? 'zh_Hans_CN';
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
        if ($data['all_match']) {
            $match_url = $data['website_url'] . ($has_area ? $area : '') . '/' . $data['currency'] . '/' . $data['language'];
            self::$parserMatchs[$match_url] = $data;
        }
        # 解析缓存
        self::$parserCache[$url] = $data;
        self::$parserServer['ORIGIN_REQUEST_URI'] = $uri;
        self::$parserServer['REQUEST_URI'] = $pure_uri;
        $data['server'] = self::$parserServer;
        if ($key) {
            return $data[$key] ?? '';
        }

        return $data;
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
}
