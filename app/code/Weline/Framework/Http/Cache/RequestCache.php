<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/9/23 21:10:50
 */

namespace Weline\Framework\Http\Cache;

use Weline\Framework\Http\Request;
use Weline\Framework\Http\Request\RequestAbstract;
use Weline\Framework\Manager\ObjectManager;

class RequestCache extends \Weline\Framework\Cache\CacheFactory
{
    public function __construct(string $identity = 'request_cache', string $tip = '请求缓存', bool $permanently = true)
    {
        parent::__construct($identity, $tip, $permanently);
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
            $request = ObjectManager::getInstance(Request::class);
        }
        $host = $request->getServer('HTTP_HOST') ?? '';
        $website_code = $request->getServer('WELINE_WEBSITE_CODE') ?? '';
        // 优先使用网站代码，如果没有则使用域名
        return $website_code ?: $host;
    }

    /**
     * @DESC         |规范化 URI（去除尾部斜杠，空则使用 '/'）
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
        $uri = rtrim($uri, '/');
        return empty($uri) ? '/' : $uri;
    }

    /**
     * @DESC         |生成 URI 缓存键（自动包含域名信息）
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
    public static function buildUriCacheKey(string $uri, string $method = 'GET', Request|RequestAbstract|null $request = null): string
    {
        $domain_key = self::getDomainKey($request);
        $uri = self::normalizeUri($uri);
        $method = $method ?: 'GET';
        return $domain_key . '_' . $uri . '_' . $method;
    }
}
