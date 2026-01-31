<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 编译缓存接口
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Cache
 */

namespace Weline\Framework\View\Taglib\Cache;

use Weline\Framework\View\Template;

/**
 * 编译缓存接口
 */
interface CacheInterface
{
    /**
     * 获取缓存的编译结果
     *
     * @param string $path 模板路径
     * @param string $hash 内容哈希
     * @return string|null 编译结果，未命中返回 null
     */
    public function get(string $path, string $hash): ?string;

    /**
     * 存储编译结果
     *
     * @param string $path 模板路径
     * @param string $hash 内容哈希
     * @param string $compiled 编译结果
     * @return bool 是否成功
     */
    public function set(string $path, string $hash, string $compiled): bool;

    /**
     * 删除缓存
     *
     * @param string $path 模板路径
     * @return bool 是否成功
     */
    public function delete(string $path): bool;

    /**
     * 清空所有缓存
     *
     * @return bool 是否成功
     */
    public function flush(): bool;
}
