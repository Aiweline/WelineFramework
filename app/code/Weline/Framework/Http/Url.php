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
use Weline\Framework\Session\Session;

class Url implements UrlInterface
{
    protected Request $request;

    public function __construct(
        Request $request
    )
    {
        $this->request = $request;
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

    public function getUrl(string $path = '', array $params = [], bool $merge_url_params = false): string
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
                $url = $this->request->getBaseHost() . self::getPrefix() . '/' . Env::getInstance()->getConfig('admin') . (('/' === $path) ? '' : '/' . ltrim($path, '/'));
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
                $router = $this->request->getRouterData('router');
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
        }
        if ($url_params) {
            if ($merge_url_params) {
                if ($params) {
                    $params = array_merge($url_params, $params);
                } else {
                    $params = $url_params;
                }
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
        return self::removeExtraDoubleSlashes($url);
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
        $protocol = substr($sp, 0, strpos($sp, '/')) . (($ssl) ? 's' : '');
        $port = $s['SERVER_PORT'];
        $port = ((!$ssl && $port == '80') || ($ssl && $port == '443')) ? '' : ':' . $port;
        $host = ($use_forwarded_host && isset($s['HTTP_X_FORWARDED_HOST'])) ? $s['HTTP_X_FORWARDED_HOST'] : ($s['HTTP_HOST'] ?? null);
        $host = $host ?? $s['SERVER_NAME'] . $port;
        return $protocol . '://' . $host;
    }

    public function getFullUrl($s, $use_forwarded_host = false): string
    {
        return self::removeExtraDoubleSlashes($this->getUrlOrigin($s, $use_forwarded_host) . '/' . ($s['ORIGIN_REQUEST_URI'] ?? $s['REQUEST_URI']));
    }

    public function getCurrentUrl(array $params = [], bool $merge_url_params = true): string
    {
        $url = self::removeExtraDoubleSlashes($this->getUrlOrigin($_SERVER, false) . '/' . ($_SERVER['ORIGIN_REQUEST_URI'] ?? $_SERVER['REQUEST_URI']));
        return $this->extractedUrl($params, $merge_url_params, $url);
    }
}
