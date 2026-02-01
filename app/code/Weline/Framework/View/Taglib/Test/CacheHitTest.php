<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 多级缓存命中测试
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Test
 */

namespace Weline\Framework\View\Taglib\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;
use Weline\Framework\View\Taglib\Cache\MultiLevelCache;
use Weline\Framework\View\Taglib\Cache\WeakMapCache;
use Weline\Framework\Manager\ObjectManager;

/**
 * 缓存命中测试
 * 
 * 覆盖 L0 缓存（Template WeakMap）命中场景
 */
class CacheHitTest extends TestCase
{
    private Taglib $taglib;
    private Template $template;

    protected function setUp(): void
    {
        $this->taglib = ObjectManager::getInstance(Taglib::class);
        $this->template = ObjectManager::getInstance(Template::class);
        // 清除缓存以确保测试独立性
        $this->taglib->clearCache();
    }

    /**
     * 测试同一模板重复编译命中缓存
     */
    public function testRepeatedCompileHitsCache(): void
    {
        $content = '<if condition="$show">Content</if>';
        $fileName = 'cache-test.phtml';

        // 第一次编译
        $result1 = $this->taglib->compile($this->template, $content, $fileName);

        // 第二次编译（应该命中缓存）
        $result2 = $this->taglib->compile($this->template, $content, $fileName);

        // 结果应该相同
        self::assertEquals($result1, $result2);

        // 检查统计信息
        $stats = $this->taglib->stats();
        self::assertGreaterThanOrEqual(1, $stats['cacheHits'], '应该至少有一次缓存命中');
    }

    /**
     * 测试不同内容不命中缓存
     */
    public function testDifferentContentMissesCache(): void
    {
        $content1 = '<if condition="$show1">Content1</if>';
        $content2 = '<if condition="$show2">Content2</if>';
        $fileName = 'cache-test.phtml';

        // 编译第一个内容
        $result1 = $this->taglib->compile($this->template, $content1, $fileName);

        // 编译第二个内容（应该不命中缓存）
        $result2 = $this->taglib->compile($this->template, $content2, $fileName);

        // 结果应该不同
        self::assertNotEquals($result1, $result2);
    }

    /**
     * 测试清除缓存后重新编译
     */
    public function testClearCacheRecompiles(): void
    {
        $content = '<if condition="$show">Content</if>';
        $fileName = 'cache-clear-test.phtml';

        // 第一次编译
        $this->taglib->compile($this->template, $content, $fileName);

        // 清除缓存
        $this->taglib->clearCache();

        // 获取清除后的统计
        $statsBeforeRecompile = $this->taglib->stats();

        // 第二次编译（应该重新编译，不命中缓存）
        $this->taglib->compile($this->template, $content, $fileName);

        $statsAfterRecompile = $this->taglib->stats();
        
        // 编译次数应该增加
        self::assertGreaterThan(
            $statsBeforeRecompile['compilations'],
            $statsAfterRecompile['compilations']
        );
    }

    /**
     * 测试 WeakMapCache 基本功能
     */
    public function testWeakMapCacheBasicFunctionality(): void
    {
        $cache = new WeakMapCache();
        $hash = hash('xxh3', 'test content');

        // 设置缓存
        $cache->set('/path/to/template.phtml', $hash, 'compiled result');

        // 获取缓存
        $result = $cache->get('/path/to/template.phtml', $hash);
        self::assertEquals('compiled result', $result);

        // 不同的 hash 应该返回 null
        $result2 = $cache->get('/path/to/template.phtml', 'different-hash');
        self::assertNull($result2);
    }

    /**
     * 测试 WeakMapCache 路径删除
     */
    public function testWeakMapCacheDelete(): void
    {
        $cache = new WeakMapCache();
        $hash = hash('xxh3', 'test content');

        $cache->set('/path/to/template.phtml', $hash, 'compiled result');
        $cache->delete('/path/to/template.phtml');

        $result = $cache->get('/path/to/template.phtml', $hash);
        self::assertNull($result);
    }

    /**
     * 测试 WeakMapCache 清空
     */
    public function testWeakMapCacheFlush(): void
    {
        $cache = new WeakMapCache();
        $hash = hash('xxh3', 'test content');

        $cache->set('/path1.phtml', $hash, 'result1');
        $cache->set('/path2.phtml', $hash, 'result2');

        $cache->flush();

        self::assertNull($cache->get('/path1.phtml', $hash));
        self::assertNull($cache->get('/path2.phtml', $hash));
    }

    /**
     * 测试 MultiLevelCache 统计信息
     */
    public function testMultiLevelCacheStats(): void
    {
        $cache = new MultiLevelCache();
        
        $stats = $cache->stats();
        
        self::assertArrayHasKey('hits', $stats);
        self::assertArrayHasKey('misses', $stats);
        self::assertArrayHasKey('hitRate', $stats);
        self::assertArrayHasKey('layers', $stats);
    }

    /**
     * 测试 MultiLevelCache 多层级工作
     */
    public function testMultiLevelCacheLayeredOperation(): void
    {
        $cache = new MultiLevelCache();
        $path = '/test/template.phtml';
        $content = 'template content';
        $compiled = 'compiled result';

        // 设置缓存（应该写入所有层）
        $cache->setByPath($path, $content, $compiled);

        // 获取缓存（应该从最快的层获取）
        $result = $cache->getByPath($path, $content);
        self::assertEquals($compiled, $result);
    }

    /**
     * 测试 Taglib 统计信息完整性
     */
    public function testTaglibStatsCompleteness(): void
    {
        $stats = $this->taglib->stats();

        self::assertArrayHasKey('compilations', $stats);
        self::assertArrayHasKey('cacheHits', $stats);
        self::assertArrayHasKey('cache', $stats);
        self::assertArrayHasKey('pipeline', $stats);
        self::assertArrayHasKey('debug', $stats);
    }
}
