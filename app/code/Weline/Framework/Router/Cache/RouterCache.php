<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router\Cache;

use Weline\Framework\Http\Request;
use Weline\Framework\Http\Request\RequestAbstract;
use Weline\Framework\Manager\ObjectManager;

class RouterCache extends \Weline\Framework\Cache\CacheFactory
{
    // 统一缓存键常量
    public const UNIFIED_CACHE_URL_KEY = 'url';
    public const UNIFIED_CACHE_RULE_KEY = 'rule';
    public const UNIFIED_CACHE_ROUTER_KEY = 'router';
    public const UNIFIED_CACHE_PARAMS_KEY = 'generated_get_params';
    public const UNIFIED_CACHE_FPC_KEY = 'fpc_html';
    public const UNIFIED_CACHE_HEADERS_KEY = 'fpc_headers';

    public function __construct(string $identity = 'framework_router')
    {
        parent::__construct($identity, '路由缓存', false);
    }

    /**
     * @DESC         |获取域名键（统一处理域名信息，确保 www 和非 www 使用不同的缓存键）
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/12/19
     * 参数区：
     * @param Request|RequestAbstract|null $request
     * @return string
     */
    public static function getDomainKey(Request|RequestAbstract|null $request = null): string
    {
        if ($request === null) {
            // 避免创建 Request 实例时触发循环，直接从 $_SERVER 获取
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $website_code = $_SERVER['WELINE_WEBSITE_CODE'] ?? '';
            return $website_code ?: $host;
        }
        $host = $request->getServer('HTTP_HOST') ?? '';
        $website_code = $request->getServer('WELINE_WEBSITE_CODE') ?? '';
        // 优先使用网站代码，如果没有则使用域名
        return $website_code ?: $host;
    }

    /**
     * @DESC         |规范化 URI（去除查询参数和尾部斜杠，空则使用 '/'）
     *                确保缓存键只基于路径部分，不包含查询参数，避免相同路径因查询参数不同而生成不同的缓存键
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/12/19
     * 参数区：
     * @param string $uri
     * @return string
     */
    public static function normalizeUri(string $uri): string
    {
        // 去除查询参数（? 及其后面的内容）
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }
        // 去除尾部斜杠
        $uri = rtrim($uri, '/');
        return empty($uri) ? '/' : $uri;
    }

    /**
     * @DESC         |生成 URL 缓存键（自动包含域名信息）
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/12/19
     * 参数区：
     * @param string $uri
     * @param string $method
     * @param Request|RequestAbstract|null $request
     * @return string
     */
    public static function buildUrlCacheKey(string $uri, string $method = 'GET', Request|RequestAbstract|null $request = null): string
    {
        $domain_key = self::getDomainKey($request);
        $uri = self::normalizeUri($uri);
        $method = $method ?: 'GET';
        return 'url_cache_key_' . $domain_key . '_' . $uri . '_' . $method;
    }

    /**
     * @DESC         |生成规则缓存键（自动包含域名信息）
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/12/19
     * 参数区：
     * @param string $uri
     * @param string $method
     * @param Request|RequestAbstract|null $request
     * @return string
     */
    public static function buildRuleCacheKey(string $uri, string $method = 'GET', Request|RequestAbstract|null $request = null): string
    {
        $domain_key = self::getDomainKey($request);
        $uri = self::normalizeUri($uri);
        $method = $method ?: 'GET';
        return 'rule_data_cache_key_' . $domain_key . '_' . $uri . '_' . $method;
    }

    /**
     * @DESC         |生成路由启动缓存键（自动包含域名信息）
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/12/19
     * 参数区：
     * @param string $uri
     * @param string $method
     * @param Request|RequestAbstract|null $request
     * @return string
     */
    public static function buildRouterStartCacheKey(string $uri, string $method = 'GET', Request|RequestAbstract|null $request = null): string
    {
        $domain_key = self::getDomainKey($request);
        $uri = self::normalizeUri($uri);
        $method = $method ?: 'GET';
        return 'router_start_cache_key_' . $domain_key . '_' . $uri . '_' . $method;
    }

    /**
     * @DESC         |生成统一请求缓存键（包含所有请求相关数据的缓存键）
     *                统一缓存结构包含：url、rule、router、generated_get_params、fpc_html
     *                全页缓存键直接使用 WELINE_FULL_REQUEST_URI，它包含完整的URL（协议、域名、端口、路径、查询参数等）
     *                不需要额外处理，WELINE_FULL_REQUEST_URI 已经包含了所有必要信息
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2025/01/XX
     * 参数区：
     * @param string $uri 此参数已废弃，不再使用，保留仅为兼容性
     * @param string $method
     * @param Request|RequestAbstract|null $request
     * @return string
     */
    public static function buildUnifiedRequestCacheKey(string $uri, string $method = 'GET', Request|RequestAbstract|null $request = null): string
    {
        $method = $method ?: 'GET';
        
        // 获取完整URI（包含协议、域名、端口、路径、查询参数等所有信息）
        // WELINE_FULL_REQUEST_URI 在 App.php 中保存，包含完整的URL信息
        if ($request === null) {
            // 避免创建 Request 实例时触发循环，直接从 $_SERVER 获取
            $fullUri = $_SERVER['WELINE_FULL_REQUEST_URI'] ?? '/';
        } else {
            $fullUri = $request->getServer('WELINE_FULL_REQUEST_URI') ?? '/';
        }
        
        // 直接使用 WELINE_FULL_REQUEST_URI 构建缓存键，包含所有信息（域名、端口、查询参数等）
        // 格式：unified_request_cache_{full_uri}_{method}
        return 'unified_request_cache_' . $fullUri . '_' . $method;
    }
}
