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
    
    /**
     * 开启攻击防护模式
     * 
     * 当检测到攻击时，由 Server 模块触发此方法，
     * 通知 CDN 服务商开启攻击防护模式。
     * 
     * @param string $zoneId Zone ID
     * @param array $credentials 凭据数组
     * @param array $attackData 攻击数据，包含：
     *   - signal: array 攻击信号详情
     *     - type: string 攻击类型
     *     - domain: string 被攻击域名
     *     - ip: string 攻击者IP
     *     - timestamp: int 时间戳
     *     - reason: string 原因
     *   - summary: array 攻击摘要
     *     - total: int 总攻击次数
     *     - by_type: array 按类型分组
     *     - recent_ips: array 最近攻击IP
     *   - attacker_ips: array 攻击者IP列表
     * @return array ['success' => bool, 'message' => string, ...]
     */
    public function enableAttackMode(string $zoneId, array $credentials, array $attackData = []): array;
    
    /**
     * 关闭攻击防护模式
     * 
     * 当攻击结束后，关闭攻击防护模式恢复正常访问。
     * 
     * @param string $zoneId Zone ID
     * @param array $credentials 凭据数组
     * @return array ['success' => bool, 'message' => string, ...]
     */
    public function disableAttackMode(string $zoneId, array $credentials): array;
    
    /**
     * 检查是否支持攻击防护模式
     * 
     * @return bool
     */
    public function supportsAttackMode(): bool;
}

