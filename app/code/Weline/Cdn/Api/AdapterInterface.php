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
 * 所有CDN适配器必须实现此接口
 * 
 * @package Weline_Cdn
 */
interface AdapterInterface
{
    /**
     * 获取适配器代码
     * 
     * @return string
     */
    public function getAdapterCode(): string;

    /**
     * 获取适配器名称
     * 
     * @return string
     */
    public function getAdapterName(): string;

    /**
     * 获取适配器描述
     * 
     * @return string
     */
    public function getDescription(): string;

    /**
     * 获取适配器版本
     * 
     * @return string
     */
    public function getVersion(): string;

    /**
     * 清理所有缓存
     * 
     * @param string $zoneId Zone ID
     * @param array $credentials 凭据数组
     * @return array ['success' => bool, 'message' => string, ...]
     */
    public function purgeEverything(string $zoneId, array $credentials): array;

    /**
     * 按URL清理缓存
     * 
     * @param string $zoneId Zone ID
     * @param array $urls URL数组
     * @param array $credentials 凭据数组
     * @return array ['success' => bool, 'message' => string, ...]
     */
    public function purgeUrls(string $zoneId, array $urls, array $credentials): array;

    /**
     * 按Host清理缓存
     * 
     * @param string $zoneId Zone ID
     * @param array $hosts Host数组
     * @param array $credentials 凭据数组
     * @return array ['success' => bool, 'message' => string, ...]
     */
    public function purgeHosts(string $zoneId, array $hosts, array $credentials): array;

    /**
     * 按Tag清理缓存
     * 
     * @param string $zoneId Zone ID
     * @param array $tags Tag数组
     * @param array $credentials 凭据数组
     * @return array ['success' => bool, 'message' => string, ...]
     */
    public function purgeTags(string $zoneId, array $tags, array $credentials): array;

    /**
     * 按Cache Key清理缓存
     * 
     * @param string $zoneId Zone ID
     * @param array $keys Cache Key数组
     * @param array $credentials 凭据数组
     * @return array ['success' => bool, 'message' => string, ...]
     */
    public function purgeCacheKeys(string $zoneId, array $keys, array $credentials): array;

    /**
     * 获取规则
     * 
     * @param string $zoneId Zone ID
     * @param array $credentials 凭据数组
     * @return array 规则数组
     */
    public function getRules(string $zoneId, array $credentials): array;

    /**
     * 推送规则
     * 
     * @param string $zoneId Zone ID
     * @param array $rules 规则数组
     * @param array $credentials 凭据数组
     * @return array ['success' => bool, 'message' => string, ...]
     */
    public function putRules(string $zoneId, array $rules, array $credentials): array;

    /**
     * 确保Zone存在（查找或创建）
     * 
     * @param string $domain 域名
     * @param array $credentials 凭据数组
     * @return array ['zone_id' => string, 'zone_name' => string]
     * @throws \Weline\Framework\Exception\Core
     */
    public function ensureZone(string $domain, array $credentials): array;
}

