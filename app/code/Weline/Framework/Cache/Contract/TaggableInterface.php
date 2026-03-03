<?php

declare(strict_types=1);

/**
 * 标签能力接口（ISP: 可选能力）
 * 
 * 支持按标签批量失效缓存。
 * 不是所有缓存池都需要实现此接口。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Contract;

interface TaggableInterface
{
    /**
     * 带标签设置缓存
     *
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param array<string> $tags 标签数组
     * @param int $ttl 过期时间（秒）
     * @return bool 是否成功
     */
    public function setWithTags(string $key, mixed $value, array $tags, int $ttl = 0): bool;

    /**
     * 按标签失效缓存
     *
     * @param array<string> $tags 标签数组
     * @return bool 是否成功
     */
    public function invalidateTags(array $tags): bool;

    /**
     * 获取标签下的所有键
     *
     * @param string $tag 标签
     * @return array<string> 键数组
     */
    public function getKeysByTag(string $tag): array;
}
