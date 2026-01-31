<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 多级缓存协调器
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Cache
 */

namespace Weline\Framework\View\Taglib\Cache;

use Weline\Framework\View\Template;

/**
 * 多级缓存协调器
 * 
 * 协调 L0（WeakMap）、L1（APCu）、L3（File）三级缓存
 * 
 * 访问速度：
 * - L0 命中：0.001ms
 * - L1 命中：0.01ms
 * - L3 命中：0.5ms
 * - 未命中：执行编译
 */
final class MultiLevelCache
{
    private readonly WeakMapCache $l0;
    private readonly ApcuCache $l1;
    private readonly FileCache $l3;

    /**
     * 缓存统计
     */
    private int $l0Hits = 0;
    private int $l1Hits = 0;
    private int $l3Hits = 0;
    private int $misses = 0;

    public function __construct(
        ?WeakMapCache $l0 = null,
        ?ApcuCache $l1 = null,
        ?FileCache $l3 = null
    ) {
        $this->l0 = $l0 ?? new WeakMapCache();
        $this->l1 = $l1 ?? new ApcuCache();
        $this->l3 = $l3 ?? new FileCache();
    }

    /**
     * 使用 Template 对象作为 key 获取缓存
     * 
     * @param Template $template 模板对象
     * @param string $path 模板路径
     * @param string $content 模板内容（用于计算哈希）
     * @return string|null 编译结果
     */
    public function get(Template $template, string $path, string $content): ?string
    {
        // L0: 进程内 WeakMap（最快）
        $result = $this->l0->getByTemplate($template);
        if ($result !== null) {
            $this->l0Hits++;
            return $result;
        }

        // 计算内容哈希（使用 xxh3，比 md5 快 10 倍）
        $hash = hash('xxh3', $content);

        // L1: APCu 共享内存
        $result = $this->l1->get($path, $hash);
        if ($result !== null) {
            $this->l1Hits++;
            // 回填 L0
            $this->l0->setByTemplate($template, $result);
            return $result;
        }

        // L3: 文件缓存
        $result = $this->l3->get($path, $hash);
        if ($result !== null) {
            $this->l3Hits++;
            // 回填 L0 和 L1
            $this->l0->setByTemplate($template, $result);
            $this->l1->set($path, $hash, $result);
            return $result;
        }

        $this->misses++;
        return null;
    }

    /**
     * 使用路径获取缓存（无 Template 对象时）
     */
    public function getByPath(string $path, string $content): ?string
    {
        $hash = hash('xxh3', $content);

        // L0: 路径缓存
        $result = $this->l0->get($path, $hash);
        if ($result !== null) {
            $this->l0Hits++;
            return $result;
        }

        // L1: APCu
        $result = $this->l1->get($path, $hash);
        if ($result !== null) {
            $this->l1Hits++;
            $this->l0->set($path, $hash, $result);
            return $result;
        }

        // L3: 文件
        $result = $this->l3->get($path, $hash);
        if ($result !== null) {
            $this->l3Hits++;
            $this->l0->set($path, $hash, $result);
            $this->l1->set($path, $hash, $result);
            return $result;
        }

        $this->misses++;
        return null;
    }

    /**
     * 存储编译结果到所有缓存层
     */
    public function set(Template $template, string $path, string $content, string $compiled): void
    {
        $hash = hash('xxh3', $content);

        // 写入所有层
        $this->l0->setByTemplate($template, $compiled);
        $this->l1->set($path, $hash, $compiled);
        $this->l3->set($path, $hash, $compiled);
    }

    /**
     * 使用路径存储（无 Template 对象时）
     */
    public function setByPath(string $path, string $content, string $compiled): void
    {
        $hash = hash('xxh3', $content);

        $this->l0->set($path, $hash, $compiled);
        $this->l1->set($path, $hash, $compiled);
        $this->l3->set($path, $hash, $compiled);
    }

    /**
     * 删除指定路径的缓存
     */
    public function delete(string $path): void
    {
        $this->l0->delete($path);
        $this->l1->delete($path);
        $this->l3->delete($path);
    }

    /**
     * 清空所有缓存
     */
    public function flush(): void
    {
        $this->l0->flush();
        $this->l1->flush();
        $this->l3->flush();
        
        $this->l0Hits = 0;
        $this->l1Hits = 0;
        $this->l3Hits = 0;
        $this->misses = 0;
    }

    /**
     * 获取缓存统计信息
     */
    public function stats(): array
    {
        $total = $this->l0Hits + $this->l1Hits + $this->l3Hits + $this->misses;
        
        return [
            'hits' => [
                'l0' => $this->l0Hits,
                'l1' => $this->l1Hits,
                'l3' => $this->l3Hits,
                'total' => $this->l0Hits + $this->l1Hits + $this->l3Hits,
            ],
            'misses' => $this->misses,
            'hitRate' => $total > 0 ? round(($total - $this->misses) / $total * 100, 2) : 0,
            'layers' => [
                'l0' => $this->l0->stats(),
                'l1' => $this->l1->stats(),
                'l3' => $this->l3->stats(),
            ],
        ];
    }

    /**
     * 获取各缓存层实例
     */
    public function getL0(): WeakMapCache
    {
        return $this->l0;
    }

    public function getL1(): ApcuCache
    {
        return $this->l1;
    }

    public function getL3(): FileCache
    {
        return $this->l3;
    }
}
