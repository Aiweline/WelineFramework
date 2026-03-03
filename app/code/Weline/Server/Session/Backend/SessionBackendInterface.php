<?php

declare(strict_types=1);

/**
 * WLS Session 后端统一接口
 *
 * 所有 Session 存储后端（WLS 内置、Redis、Memcached 等）必须实现此接口。
 * 提供可插拔的 Session 存储能力，支持无缝切换后端。
 *
 * @author Aiweline
 */

namespace Weline\Server\Session\Backend;

interface SessionBackendInterface
{
    /**
     * 获取 Session 数据
     *
     * @param string $sessionId Session ID
     * @param string|null $key 键名，null 返回整个 Session
     * @return mixed 值或 null（不存在时）
     */
    public function get(string $sessionId, ?string $key = null): mixed;

    /**
     * 设置 Session 数据
     *
     * @param string $sessionId Session ID
     * @param string $key 键名
     * @param mixed $value 值
     * @param int $ttl 过期时间（秒）
     * @return bool 是否成功
     */
    public function set(string $sessionId, string $key, mixed $value, int $ttl = 3600): bool;

    /**
     * 删除 Session 中的某个键
     *
     * @param string $sessionId Session ID
     * @param string $key 键名
     * @return bool 是否成功
     */
    public function delete(string $sessionId, string $key): bool;

    /**
     * 销毁整个 Session
     *
     * @param string $sessionId Session ID
     * @return bool 是否成功
     */
    public function destroy(string $sessionId): bool;

    /**
     * 获取整个 Session 数据
     *
     * @param string $sessionId Session ID
     * @return array Session 数据，不存在返回空数组
     */
    public function getAll(string $sessionId): array;

    /**
     * 批量设置 Session 数据（替换整个 Session）
     *
     * @param string $sessionId Session ID
     * @param array $data 数据
     * @param int $ttl 过期时间（秒）
     * @return bool 是否成功
     */
    public function setAll(string $sessionId, array $data, int $ttl = 3600): bool;

    /**
     * 垃圾回收
     *
     * @param int $maxLifetime 最大生存时间（秒）
     * @return int 清理的 Session 数量
     */
    public function gc(int $maxLifetime): int;

    /**
     * 刷新 Session 过期时间
     *
     * @param string $sessionId Session ID
     * @param int $ttl 新的过期时间（秒）
     * @return bool 是否成功
     */
    public function touch(string $sessionId, int $ttl = 3600): bool;

    /**
     * 检查 Session 是否存在
     *
     * @param string $sessionId Session ID
     * @return bool 是否存在
     */
    public function exists(string $sessionId): bool;

    /**
     * 连接到后端存储
     *
     * @return bool 是否连接成功
     */
    public function connect(): bool;

    /**
     * 断开与后端存储的连接
     */
    public function disconnect(): void;

    /**
     * 检查是否已连接
     *
     * @return bool 是否已连接
     */
    public function isConnected(): bool;

    /**
     * 获取后端统计信息
     *
     * @return array 统计信息
     */
    public function getStats(): array;
}
