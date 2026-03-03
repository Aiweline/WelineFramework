<?php

declare(strict_types=1);

/**
 * 缓存池接口（业务层）
 * 
 * 在 Adapter 基础上增加：批量操作、统计信息。
 * 每个缓存池对应一个 identity（如 router, config, database）。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Contract;

interface CachePoolInterface extends CacheAdapterInterface
{
    /**
     * 获取池标识
     *
     * @return string
     */
    public function getIdentity(): string;

    /**
     * 获取池说明
     *
     * @return string
     */
    public function getTip(): string;

    /**
     * 是否为持久缓存（需要 -f 强制清理）
     *
     * @return bool
     */
    public function isPermanent(): bool;

    /**
     * 批量获取
     *
     * @param array<string> $keys 缓存键数组
     * @return array<string, mixed> 键值对数组
     */
    public function getMultiple(array $keys): array;

    /**
     * 批量设置
     *
     * @param array<string, mixed> $values 键值对数组
     * @param int $ttl 过期时间（秒）
     * @return bool 是否全部成功
     */
    public function setMultiple(array $values, int $ttl = 0): bool;

    /**
     * 批量删除
     *
     * @param array<string> $keys 缓存键数组
     * @return bool 是否全部成功
     */
    public function deleteMultiple(array $keys): bool;

    /**
     * 获取统计信息
     *
     * @return array{identity: string, hits: int, misses: int, hit_ratio: float, permanent: bool}
     */
    public function getStats(): array;
}
