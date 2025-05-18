<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http;

use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use Weline\Websites\Model\Website;

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
        # 必须有前两个字符是否都是小写字母,且第三个字符必须是_
        $data = new DataObject([
            'result' => false,
            'uri' => $uri,
            'code' => $code
        ]);
        /** @var EventsManager $eventManager */
        $eventManager = w_obj(EventsManager::class);
        $eventManager->dispatch('Framework_Url::detect_language', $data);
        if ($data->getData('result')) {
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
        $detect_currency_data = new DataObject([
            'result' => false,
            'uri' => $uri,
            'code' => $code,
        ]);
        /** @var EventsManager $eventManager */
        $eventManager = w_obj(EventsManager::class);
        $eventManager->dispatch('Framework_Url::detect_currency', $detect_currency_data);
        $uri_ = $detect_currency_data->getData('uri');

        if ($detect_currency_data->getData('result')) {
            if (str_starts_with($uri_, '/' . $code)) {
                $uri = substr($uri_, strlen('/' . $code));
            }
            self::$parserServer['WELINE_USER_CURRENCY'] = $code;
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
                $api = Env::get('api');
                if (empty($api)) {
                    $url = $this->request->getBaseHost() . '/' . $path;
                } else {
                    $url = $this->request->getBaseHost() . '/' . $api . '/' . $path;
                }
            } else {
                $url = $path;
            }
        } else {
            $api = Env::get('api');
            if (empty($api)) {
                $url = $this->request->getBaseHost();
            } else {
                $url = $this->request->getBaseHost() . $api . '/';
            }
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
        return (Cookie::getCurrency() ? '/' . Cookie::getCurrency() : '') . (Cookie::getLang() ? '/' . Cookie::getLang() : '');
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
            $url = $this->request->getBaseUrl();
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
            $url_params = explode('&', $url_query);
            foreach ($url_params as $key => $url_param) {
                unset($url_params[$key]);
                $url_param_arr = explode('=', $url_param);
                $url_params[$url_param_arr[0]] = $url_param_arr[1] ?? '';
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
            $params = array_merge($this->request->getGet(), $params);
        }

        if ($params) {
            foreach ($params as $key => $param) {
                if (empty($param)) {
                    unset($params[$key]);
                }
            }
            $url .= '?' . http_build_query($params);
        }
        $url = self::removeExtraDoubleSlashes($url);
        if (Env::get('seo')) {
            /** @var EventsManager $eventManager */
            $eventManager = ObjectManager::getInstance(EventsManager::class);
            $eventManager->dispatch('Framework_Url::url_generate_rewrite', $url);
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
        $host = $host ?? $s['SERVER_NAME'] . $port;
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
        self::$parserUrlCache[$url] = parse_url($url);
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

    public static function parser(string $parse_url = '', string $key = ''): array|string
    {
        $url = $parse_url;
        # 初始化server
        if (empty(self::$parserServer)) {
            self::$parserServer = $_SERVER;
            self::$parserServer['WELINE_ORIGIN_REQUEST_URI'] = self::$parserServer['REQUEST_URI'];
            self::$parserServer['WELINE_ORIGIN_TIMEZONE'] = date_default_timezone_get();
            self::$parserServer['WELINE_API_AREA'] = Env::get('api');
            self::$parserServer['WELINE_API_ADMIN_AREA'] = Env::get('api_admin');
            self::$parserServer['WELINE_BACKEND_AREA'] = Env::get('admin');
            self::$parserServer['WELINE_AREA_ROUTE'] = '';
            self::$parserServer['WELINE_AREA'] = 'frontend';
            self::$parserServer['WELINE_USER_CURRENCY'] = Cookie::get('WELINE_USER_CURRENCY') ?? '';
            self::$parserServer['WELINE_USER_LANG'] = Cookie::get('WELINE_USER_LANG') ?? '';
            self::$parserServer['WELINE_WEBSITE_ID'] = $_SERVER['WELINE_WEBSITE_ID'] ?? '';
            self::$parserServer['WELINE_WEBSITE_CODE'] = $_SERVER['WELINE_WEBSITE_CODE'] ?? '';
            self::$parserServer['WELINE_WEBSITE_URL'] = $_SERVER['WELINE_WEBSITE_CODE'] ?? '';
        }
        if ($url) {
            $uri = self::parse_url($url, 'path') . self::parse_url($url, 'query');
        } else {
            $uri = $_SERVER['REQUEST_URI'];
            $url = ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . $_SERVER['HTTP_HOST'] . '/' . $_SERVER['REQUEST_URI'];
        }
        # 静态文件不用再分析店铺
        if ($uri and str_contains($uri, '.')
            and preg_match('/\.(jpg|jpeg|png|webp|gif|css|js|ico|woff|woff2|txt|pdf|doc|docx|xls|xlsx|ppt|pptx)$/', $_SERVER['REQUEST_URI'])) {
            return $url;
        }

        if (empty(self::$parserMatchs)) {
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
            $eventManager->dispatch('Framework_Url::detect_website', $detect_website_data);
            $sites = $detect_website_data->getData('sites');
            # 找出站点链接最长的，依次写入self::$parserMatchs
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
        }

        # 匹配网站 self::$parserSites 最长倒序
        $parsers = self::parse_url($url);
        $data['website_url'] = ($parsers['scheme'] ?? '') . '://' . ($parsers['host'] ?? '');
        self::$parserServer['WELINE_WEBSITE_URL'] = $data['website_url'];
        foreach (self::$parserSites as $site_url => $site) {
            if (str_starts_with($url, $site_url)) {
                $url = str_replace($site_url, '', $url);
                $uri = self::parse_url($url, 'path');
                if (isset(self::$parserSiteMatchs[$site_url])) {
                    $data = array_merge((array)$data, self::$parserSiteMatchs[$site_url]);
                }
                $data['url'] = $url;
                $data['parse'] = self::parse_url($url);
                $data['website_url'] = $site_url;
                $data['website'] = $site;
                self::$parserServer['WELINE_WEBSITE_CODE'] = $site['code'];
                self::$parserServer['WELINE_WEBSITE_ID'] = $site['website_id'];
                self::$parserServer['WELINE_WEBSITE_URL'] = $site['url'];
                self::$parserServer['WELINE_WEBSITE_CURRENCY'] = $site['default_currency'];
                self::$parserServer['WELINE_WEBSITE_LANGUAGE'] = $site['default_language'];
                if (empty(self::$parserServer['WELINE_USER_LANG'])) {
                    self::$parserServer['WELINE_USER_LANG'] = Cookie::getLang() ?: $site['default_language'];
                }
                if (empty(self::$parserServer['WELINE_USER_CURRENCY'])) {
                    self::$parserServer['WELINE_USER_CURRENCY'] = Cookie::getCurrency() ?: $site['default_currency'];
                }
                # 如果URI是空的，后边就不用判断了，直接返回环境包含的参数
                if (empty($uri)) {
                    $query = self::parse_url($url, 'query') ? '?' . self::parse_url($url, 'query') : '';
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
                self::$parserServer['WELINE_AREA_ROUTE'] = self::$parserServer['WELINE_API_AREA'];
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
        if (empty($data['currency'])) {
            $data['currency'] = self::$parserServer['WELINE_USER_CURRENCY'] ?? $data['website']['default_currency'] ?? 'CNY';
        }
        if (empty($data['language'])) {
            $data['language'] = self::$parserServer['WELINE_USER_LANG'] ?? $data['website']['default_language'] ?? 'zh_Hans_CN';
        }
        $url = self::decode_url($url);
        $data['uri'] = $url;
        if ($data['all_match']) {
            $match_url = $data['website_url'] . ($has_area ? $area : '') . '/' . $data['currency'] . '/' . $data['language'];
            self::$parserMatchs[$match_url] = $data;
        }
        $data['server'] = self::$parserServer;
        // 解析缓存
        self::$parserCache[$url] = $data;
        self::$parserServer['ORIGIN_REQUEST_URI'] = $uri;
        self::$parserServer['REQUEST_URI'] = $url;
        if ($key) {
            return $data[$key] ?? '';
        }
        return $data;
    }

    static private array $decode_urls = [];

    public static function decode_url(string $url): string
    {
        # decode seo url
        if (Env::get('seo')) {
            if (isset(self::$decode_urls[$url])) {
                return self::$decode_urls[$url];
            }
            /**@var EventsManager $event */
            $event = ObjectManager::getInstance(EventsManager::class);
            $event->dispatch('Framework_Url::seo_decode', $url);
            self::$decode_urls[$url] = $url;
        }
        return $url;
    }
}
