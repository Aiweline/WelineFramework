<?php

declare(strict_types=1);

/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2024
 * 描述：轻量级 URL 解析工具，仅提取货币和语言，不触发事件，不查询数据库
 */

namespace Weline\Maintenance\Helper;

/**
 * 轻量级 URL 解析器
 * 
 * 用于维护模式等早期阶段，仅从 URL 路径中提取货币和语言信息
 * 不触发事件，不查询数据库，性能极高
 */
class UrlParser
{
    /**
     * 解析 URL，提取货币、语言和区域信息
     * 参照 Url::parser() 的方式处理，但不做 SEO 地址解码
     * 返回结构与 Url::parser() 完全一致，包括 server 字段
     * 
     * @param string $uri 请求 URI（如：/api/CNY/zh_Hans_CN/products）
     * @return array 包含 area, currency, language, uri, server 等的数组（与 Url::parser() 结构一致）
     */
    public static function parse(string $uri): array
    {
        // 初始化 server 数组（参照 Url::parser() 的逻辑）
        $server = $_SERVER;
        $server['WELINE_ORIGIN_TIMEZONE'] = date_default_timezone_get();
        
        // 获取API area前缀，用于URL匹配和生成
        $apiArea = \Weline\Framework\App\Env::get('api');
        if (empty($apiArea)) {
            $server['WELINE_API_AREA'] = 'api';
            $server['WELINE_API_AREA_PREFIX'] = '/api/rest/';
        } else {
            $server['WELINE_API_AREA'] = strtolower($apiArea);
            $server['WELINE_API_AREA_PREFIX'] = '/' . strtolower($apiArea) . '/';
        }
        $server['WELINE_API_ADMIN_AREA'] = \Weline\Framework\App\Env::get('api_admin') ?: '';
        $server['WELINE_BACKEND_AREA'] = \Weline\Framework\App\Env::get('admin') ?: 'admin';
        $server['WELINE_AREA_ROUTE'] = '';
        $server['WELINE_AREA'] = 'frontend';
        $server['WELINE_USER_CURRENCY'] = $_COOKIE['WELINE_USER_CURRENCY'] ?? 'CNY';
        $server['WELINE_USER_LANG'] = $_COOKIE['WELINE_USER_LANG'] ?? 'zh_Hans_CN';
        $server['WELINE_WEBSITE_ID'] = $_SERVER['WELINE_WEBSITE_ID'] ?? '';
        $server['WELINE_WEBSITE_CODE'] = $_SERVER['WELINE_WEBSITE_CODE'] ?? '';
        $server['WELINE_WEBSITE_URL'] = $_SERVER['WELINE_WEBSITE_URL'] ?? '';

        $result = [
            'area' => 'frontend',
            'area_route' => '',
            'currency' => '',
            'language' => '',
            'uri' => '/',
            'has_area' => false,
            'all_match' => false,
            'timezone' => 'Asia/Shanghai',
            'server' => $server,
        ];

        // 移除查询字符串，只处理路径部分
        $path = parse_url($uri, PHP_URL_PATH) ?: $uri;
        $originalUri = $path;
        $path = trim($path, '/');
        
        if (empty($path)) {
            $result['uri'] = '/';
            $result['currency'] = $server['WELINE_USER_CURRENCY'];
            $result['language'] = $server['WELINE_USER_LANG'];
            $server['ORIGIN_REQUEST_URI'] = $originalUri;
            $server['REQUEST_URI'] = '/';
            $result['server'] = $server;
            return $result;
        }

        // 分割路径
        $segments = explode('/', $path);
        if (empty($segments)) {
            $result['uri'] = '/';
            $result['currency'] = $server['WELINE_USER_CURRENCY'];
            $result['language'] = $server['WELINE_USER_LANG'];
            $server['ORIGIN_REQUEST_URI'] = $originalUri;
            $server['REQUEST_URI'] = '/';
            $result['server'] = $server;
            return $result;
        }

        // 检测区域（area）- 参照 Url::parser() 的逻辑
        $firstSegment = $segments[0] ?? '';
        $apiAreaLower = strtolower($server['WELINE_API_AREA']);
        $apiAdminAreaLower = strtolower($server['WELINE_API_ADMIN_AREA'] ?: '');
        $adminAreaLower = strtolower($server['WELINE_BACKEND_AREA']);

        $hasArea = false;
        if ($firstSegment === $apiAreaLower) {
            $result['area'] = 'api';
            $server['WELINE_AREA'] = 'api';
            $server['WELINE_AREA_ROUTE'] = $server['WELINE_API_AREA_PREFIX'] ?? '/api/rest/';
            $result['area_route'] = $server['WELINE_AREA_ROUTE'];
            array_shift($segments);
            $hasArea = true;
        } elseif ($firstSegment === $apiAdminAreaLower && !empty($apiAdminAreaLower)) {
            $result['area'] = 'api_admin';
            $server['WELINE_AREA'] = 'api_admin';
            $server['WELINE_AREA_ROUTE'] = $server['WELINE_API_ADMIN_AREA'];
            $result['area_route'] = $server['WELINE_AREA_ROUTE'];
            array_shift($segments);
            $hasArea = true;
        } elseif ($firstSegment === $adminAreaLower) {
            $result['area'] = 'admin';
            $server['WELINE_AREA'] = 'admin';
            $server['WELINE_AREA_ROUTE'] = $server['WELINE_BACKEND_AREA'];
            $result['area_route'] = $server['WELINE_AREA_ROUTE'];
            array_shift($segments);
            $hasArea = true;
        }
        $result['has_area'] = $hasArea;

        // 如果已经没有段了，返回
        if (empty($segments)) {
            $result['uri'] = '/';
            $result['currency'] = $server['WELINE_USER_CURRENCY'];
            $result['language'] = $server['WELINE_USER_LANG'];
            $server['ORIGIN_REQUEST_URI'] = $originalUri;
            $server['REQUEST_URI'] = '/';
            $result['server'] = $server;
            return $result;
        }

        // 提取货币和语言 - 参照 Url::parser() 的逻辑（第 699-745 行）
        // URL 结构：[area]/[currency]/[language]/[route] 或 [area]/[language]/[currency]/[route]
        $pre_path_1 = $segments[0] ?? '';
        $pre_path_2 = $segments[1] ?? '';

        $has_currency = false;
        $has_language = false;

        // 检查第一个路径段
        if ($pre_path_1) {
            // 检查是否是货币（3位大写字母）
            if (strlen($pre_path_1) === 3 && ctype_upper($pre_path_1)) {
                $has_currency = true;
                $result['currency'] = $pre_path_1;
                $server['WELINE_USER_CURRENCY'] = $pre_path_1;
                array_shift($segments);
            }
            // 检查是否是语言（格式：xx_XXX，前两个字符是小写字母，第三个字符是下划线，长度 5-10）
            elseif (strlen($pre_path_1) > 3 && strlen($pre_path_1) <= 10 
                && ctype_lower(substr($pre_path_1, 0, 2)) && $pre_path_1[2] === '_') {
                if (self::isValidLanguageCode($pre_path_1)) {
                    $has_language = true;
                    $result['language'] = $pre_path_1;
                    $server['WELINE_USER_LANG'] = $pre_path_1;
                    array_shift($segments);
                }
            }
        }

        // 检查第二个路径段（参照 Url::parser() 的逻辑）
        // 如果第一个段被移除了，现在 segments[0] 就是原来的第二个段
        if (!empty($segments)) {
            $pre_path_2 = $segments[0] ?? '';
            // 检查是否是语言（如果第一个不是语言）
            if (!$has_language && strlen($pre_path_2) > 3 && strlen($pre_path_2) <= 10 
                && ctype_lower(substr($pre_path_2, 0, 2)) && $pre_path_2[2] === '_') {
                if (self::isValidLanguageCode($pre_path_2)) {
                    $has_language = true;
                    $result['language'] = $pre_path_2;
                    $server['WELINE_USER_LANG'] = $pre_path_2;
                    array_shift($segments);
                }
            }
            // 检查是否是货币（如果第一个不是货币）
            if (!$has_currency && !empty($segments)) {
                $pre_path_2 = $segments[0] ?? '';
                if (strlen($pre_path_2) === 3 && ctype_upper($pre_path_2)) {
                    $has_currency = true;
                    $result['currency'] = $pre_path_2;
                    $server['WELINE_USER_CURRENCY'] = $pre_path_2;
                    array_shift($segments);
                }
            }
        }

        // 如果从路径中没有提取到，从 Cookie 或默认值获取
        if (empty($result['currency'])) {
            $result['currency'] = $server['WELINE_USER_CURRENCY'];
        } else {
            $server['WELINE_USER_CURRENCY'] = $result['currency'];
        }
        
        if (empty($result['language'])) {
            $result['language'] = $server['WELINE_USER_LANG'];
        } else {
            $server['WELINE_USER_LANG'] = $result['language'];
        }

        // 构建处理后的 URI（剩余的 segments）
        if (empty($segments)) {
            $result['uri'] = '/';
            $pureUri = '/';
        } else {
            $pureUri = '/' . implode('/', $segments);
            $result['uri'] = $pureUri;
        }

        // 设置 all_match（货币和语言都从路径中提取到）
        $result['all_match'] = $has_currency && $has_language;

        // 更新 server 中的 REQUEST_URI
        $server['ORIGIN_REQUEST_URI'] = $originalUri;
        $server['REQUEST_URI'] = $pureUri;
        $result['server'] = $server;

        return $result;
    }

    /**
     * 验证是否是有效的语言代码格式
     * 参照 Url::detectLanguage() 的逻辑，但不触发事件
     * 
     * 支持的格式：
     * - xx_XX（如：en_US, zh_CN）
     * - xx_XXX（如：en_GB）
     * - xx_XXX_XX（如：zh_Hans_CN）
     * 
     * @param string $code 语言代码
     * @return bool
     */
    private static function isValidLanguageCode(string $code): bool
    {
        // 长度检查：5-10 字符（与 Url::parser() 保持一致）
        if (strlen($code) < 5 || strlen($code) > 10) {
            return false;
        }

        // 格式检查：前两个字符是小写字母，第三个字符是下划线（与 Url::parser() 保持一致）
        if (strlen($code) < 3 || !ctype_lower(substr($code, 0, 2)) || $code[2] !== '_') {
            return false;
        }

        // 支持多种格式：
        // 1. xx_XX（如：en_US, zh_CN）- 两个大写字母
        // 2. xx_XXX（如：en_GB）- 两个大写字母
        // 3. xx_XXX_XX（如：zh_Hans_CN）- 一个大写字母+小写字母+可选的两个大写字母
        return (bool)preg_match('/^[a-z]{2}_([A-Z]{2}|[A-Z][a-z]+)(_[A-Z]{2})?$/', $code);
    }

    /**
     * 判断是否是 API 请求
     * 
     * @param string $uri 请求 URI
     * @return bool
     */
    public static function isApiRequest(string $uri): bool
    {
        $parsed = self::parse($uri);
        return $parsed['area'] === 'api' || $parsed['area'] === 'api_admin';
    }

    /**
     * 判断是否是后端请求
     * 
     * @param string $uri 请求 URI
     * @return bool
     */
    public static function isBackendRequest(string $uri): bool
    {
        $parsed = self::parse($uri);
        return $parsed['area'] === 'admin';
    }

    /**
     * 判断是否是前端请求
     * 
     * @param string $uri 请求 URI
     * @return bool
     */
    public static function isFrontendRequest(string $uri): bool
    {
        $parsed = self::parse($uri);
        return $parsed['area'] === 'frontend';
    }
}
