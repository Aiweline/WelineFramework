<?php

declare(strict_types=1);

/**
 * 缓存适配器接口（ISP: 最小存储接口）
 * 
 * 只定义存储层必须的 5 个操作，不包含任何业务逻辑。
 * 所有缓存驱动必须实现此接口。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Contract;

interface CacheAdapterInterface
{
    /**
     * 获取缓存值
     *
     * @param string $key 缓存键
     * @return mixed 缓存值，不存在返回 null
     */
    public function get(string $key): mixed;

    /**
     * 设置缓存值
     *
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param int $ttl 过期时间（秒），0 表示永不过期
     * @return bool 是否成功
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool;

    /**
     * 删除缓存
     *
     * @param string $key 缓存键
     * @return bool 是否成功
     */
    public function delete(string $key): bool;

    /**
     * 清空所有缓存
     *
     * @return bool 是否成功
     */
    public function clear(): bool;

    /**
     * 检查缓存是否存在
     *
     * @param string $key 缓存键
     * @return bool 是否存在
     */
    public function has(string $key): bool;
}
