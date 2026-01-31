<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | L0 进程内 WeakMap 缓存
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Cache
 */

namespace Weline\Framework\View\Taglib\Cache;

use WeakMap;
use Weline\Framework\View\Template;

/**
 * L0 进程内缓存
 * 
 * 使用 WeakMap 以 Template 对象为 key，自动随对象销毁释放内存
 * 访问速度：约 0.001ms
 */
final class WeakMapCache
{
    /**
     * Template 对象到编译结果的映射
     */
    private WeakMap $cache;

    /**
     * 路径到编译结果的映射（用于非 Template 场景）
     * @var array<string, array{hash: string, compiled: string}>
     */
    private array $pathCache = [];

    /**
     * 最大路径缓存条目数
     */
    private const MAX_PATH_ENTRIES = 100;

    public function __construct()
    {
        $this->cache = new WeakMap();
    }

    /**
     * 以 Template 对象为 key 获取缓存
     */
    public function getByTemplate(Template $template): ?string
    {
        return $this->cache[$template] ?? null;
    }

    /**
     * 以 Template 对象为 key 设置缓存
     */
    public function setByTemplate(Template $template, string $compiled): void
    {
        $this->cache[$template] = $compiled;
    }

    /**
     * 以路径和哈希获取缓存
     */
    public function get(string $path, string $hash): ?string
    {
        $entry = $this->pathCache[$path] ?? null;
        if ($entry === null || $entry['hash'] !== $hash) {
            return null;
        }
        return $entry['compiled'];
    }

    /**
     * 以路径和哈希设置缓存
     */
    public function set(string $path, string $hash, string $compiled): void
    {
        // 防止缓存溢出
        if (count($this->pathCache) >= self::MAX_PATH_ENTRIES) {
            // 移除最早的一半
            $this->pathCache = array_slice($this->pathCache, self::MAX_PATH_ENTRIES / 2, null, true);
        }

        $this->pathCache[$path] = [
            'hash' => $hash,
            'compiled' => $compiled,
        ];
    }

    /**
     * 删除路径缓存
     */
    public function delete(string $path): void
    {
        unset($this->pathCache[$path]);
    }

    /**
     * 清空所有缓存
     */
    public function flush(): void
    {
        $this->cache = new WeakMap();
        $this->pathCache = [];
    }

    /**
     * 获取统计信息
     */
    public function stats(): array
    {
        return [
            'weakMapCount' => count($this->cache),
            'pathCacheCount' => count($this->pathCache),
        ];
    }
}
