<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Api;

/**
 * CDN适配器接口
 * 
 * 所有CDN提供商适配器必须实现此接口，提供统一的缓存清理和规则管理能力
 */
interface AdapterInterface
{
    /**
     * @DESC          # 清理所有缓存
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param string $zoneId Zone ID
     * @param array $credentials 凭据数组（如：['api_token' => 'xxx']）
     * @return array 返回结果 ['success' => bool, 'message' => string, 'data' => mixed]
     */
    public function purgeEverything(string $zoneId, array $credentials): array;

    /**
     * @DESC          # 按URL清理缓存
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param string $zoneId Zone ID
     * @param array $urls URL数组
     * @param array $credentials 凭据数组
     * @return array
     */
    public function purgeUrls(string $zoneId, array $urls, array $credentials): array;

    /**
     * @DESC          # 按主机清理缓存
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param string $zoneId Zone ID
     * @param array $hosts 主机数组
     * @param array $credentials 凭据数组
     * @return array
     */
    public function purgeHosts(string $zoneId, array $hosts, array $credentials): array;

    /**
     * @DESC          # 按标签清理缓存
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param string $zoneId Zone ID
     * @param array $tags 标签数组
     * @param array $credentials 凭据数组
     * @return array
     */
    public function purgeTags(string $zoneId, array $tags, array $credentials): array;

    /**
     * @DESC          # 按缓存键清理
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param string $zoneId Zone ID
     * @param array $cacheKeys 缓存键数组
     * @param array $credentials 凭据数组
     * @return array
     */
    public function purgeCacheKeys(string $zoneId, array $cacheKeys, array $credentials): array;

    /**
     * @DESC          # 获取规则列表
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param string $zoneId Zone ID
     * @param array $credentials 凭据数组
     * @return array 返回规则数组
     */
    public function getRules(string $zoneId, array $credentials): array;

    /**
     * @DESC          # 推送/同步规则到CDN
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param string $zoneId Zone ID
     * @param array $rules 规则数组（Cache Rules格式）
     * @param array $credentials 凭据数组
     * @return array
     */
    public function putRules(string $zoneId, array $rules, array $credentials): array;

    /**
     * @DESC          # 根据域名解析/校验Zone ID（可选实现）
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param string $domain 域名
     * @param array $credentials 凭据数组
     * @return array ['zone_id' => string, 'zone_name' => string] 或错误信息
     */
    public function ensureZone(string $domain, array $credentials): array;

    /**
     * @DESC          # 获取适配器名称/标识
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @return string 适配器代码（如：'cloudflare'）
     */
    public function getAdapterCode(): string;

    /**
     * @DESC          # 获取适配器显示名称
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @return string 显示名称（如：'Cloudflare'）
     */
    public function getAdapterName(): string;
}

