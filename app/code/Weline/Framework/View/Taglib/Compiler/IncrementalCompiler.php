<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 增量编译器
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Compiler
 */

namespace Weline\Framework\View\Taglib\Compiler;

use Weline\Framework\View\Taglib\Cache\MultiLevelCache;

/**
 * 增量编译器
 * 
 * 只编译变化的模板，利用缓存避免重复编译
 */
final class IncrementalCompiler
{
    /**
     * 哈希缓存（路径 => 哈希）
     * @var array<string, string>
     */
    private array $hashCache = [];

    /**
     * 多级缓存
     */
    private readonly MultiLevelCache $cache;

    /**
     * 全量编译回调
     */
    private ?\Closure $fullCompileCallback = null;

    public function __construct(?MultiLevelCache $cache = null)
    {
        $this->cache = $cache ?? new MultiLevelCache();
    }

    /**
     * 设置全量编译回调
     */
    public function setFullCompileCallback(\Closure $callback): void
    {
        $this->fullCompileCallback = $callback;
    }

    /**
     * 增量编译
     * 
     * @param string $path 模板路径
     * @param string $content 模板内容
     * @return string 编译结果
     */
    public function compile(string $path, string $content): string
    {
        // 计算内容哈希
        $newHash = hash('xxh3', $content);
        $oldHash = $this->hashCache[$path] ?? null;

        // 检查是否有变化
        if ($oldHash === $newHash) {
            // 无变化，尝试从缓存获取
            $cached = $this->cache->getByPath($path, $content);
            if ($cached !== null) {
                return $cached;
            }
        }

        // 更新哈希缓存
        $this->hashCache[$path] = $newHash;

        // 尝试从缓存获取
        $cached = $this->cache->getByPath($path, $content);
        if ($cached !== null) {
            return $cached;
        }

        // 执行全量编译
        if ($this->fullCompileCallback === null) {
            throw new \RuntimeException('Full compile callback not set');
        }

        $compiled = ($this->fullCompileCallback)($path, $content);

        // 写入缓存
        $this->cache->setByPath($path, $content, $compiled);

        return $compiled;
    }

    /**
     * 检查模板是否需要重新编译
     */
    public function needsRecompile(string $path, string $content): bool
    {
        $newHash = hash('xxh3', $content);
        $oldHash = $this->hashCache[$path] ?? null;

        if ($oldHash !== $newHash) {
            return true;
        }

        // 检查缓存是否存在
        return $this->cache->getByPath($path, $content) === null;
    }

    /**
     * 使缓存失效
     */
    public function invalidate(string $path): void
    {
        unset($this->hashCache[$path]);
        $this->cache->delete($path);
    }

    /**
     * 清除所有缓存
     */
    public function flush(): void
    {
        $this->hashCache = [];
        $this->cache->flush();
    }

    /**
     * 预热缓存
     * 
     * @param array<string, string> $templates 路径 => 内容
     */
    public function warmup(array $templates): void
    {
        foreach ($templates as $path => $content) {
            $this->compile($path, $content);
        }
    }

    /**
     * 获取统计信息
     */
    public function stats(): array
    {
        return [
            'hashCacheCount' => count($this->hashCache),
            'cache' => $this->cache->stats(),
        ];
    }
}
