<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 多级缓存单元测试
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Test
 */

namespace Weline\Framework\View\Taglib\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Taglib\Cache\WeakMapCache;
use Weline\Framework\View\Taglib\Cache\FileCache;
use Weline\Framework\View\Taglib\Cache\MultiLevelCache;
use Weline\Framework\View\Template;

class CacheTest extends TestCase
{
    private string $testCacheDir;

    protected function setUp(): void
    {
        $this->testCacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'taglib_test_cache_' . getmypid();
    }

    protected function tearDown(): void
    {
        // 清理测试缓存目录
        if (is_dir($this->testCacheDir)) {
            $this->deleteDirectory($this->testCacheDir);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testWeakMapCacheByPath(): void
    {
        $cache = new WeakMapCache();
        
        $path = '/test/template.phtml';
        $hash = 'abc123';
        $compiled = 'compiled content';

        // 初始应为空
        self::assertNull($cache->get($path, $hash));

        // 设置后应能获取
        $cache->set($path, $hash, $compiled);
        self::assertEquals($compiled, $cache->get($path, $hash));

        // 不同哈希应返回 null
        self::assertNull($cache->get($path, 'different_hash'));
    }

    public function testWeakMapCacheByTemplate(): void
    {
        $cache = new WeakMapCache();
        $template = new Template();
        $compiled = 'compiled content';

        // 初始应为空
        self::assertNull($cache->getByTemplate($template));

        // 设置后应能获取
        $cache->setByTemplate($template, $compiled);
        self::assertEquals($compiled, $cache->getByTemplate($template));
    }

    public function testFileCacheOperations(): void
    {
        $cache = new FileCache($this->testCacheDir);
        
        $path = '/test/template.phtml';
        $hash = hash('xxh3', 'test content');
        $compiled = 'compiled content';

        // 初始应为空
        self::assertNull($cache->get($path, $hash));

        // 设置后应能获取
        self::assertTrue($cache->set($path, $hash, $compiled));
        self::assertEquals($compiled, $cache->get($path, $hash));

        // 删除后应返回 null
        self::assertTrue($cache->delete($path));
    }

    public function testFileCacheStats(): void
    {
        $cache = new FileCache($this->testCacheDir);
        
        $cache->set('/test/a.phtml', 'hash1', 'content1');
        $cache->set('/test/b.phtml', 'hash2', 'content2');

        $stats = $cache->stats();
        self::assertEquals(2, $stats['count']);
        self::assertGreaterThan(0, $stats['size']);
    }

    public function testMultiLevelCacheHitL0(): void
    {
        $l0 = new WeakMapCache();
        $cache = new MultiLevelCache($l0);
        
        $path = '/test/template.phtml';
        $content = 'template content';
        $compiled = 'compiled content';

        // 预填充 L0
        $hash = hash('xxh3', $content);
        $l0->set($path, $hash, $compiled);

        // 应该命中 L0
        $result = $cache->getByPath($path, $content);
        self::assertEquals($compiled, $result);

        $stats = $cache->stats();
        self::assertEquals(1, $stats['hits']['l0']);
    }

    public function testMultiLevelCacheMiss(): void
    {
        $cache = new MultiLevelCache();
        
        $result = $cache->getByPath('/nonexistent.phtml', 'content');
        self::assertNull($result);

        $stats = $cache->stats();
        self::assertEquals(1, $stats['misses']);
    }

    public function testMultiLevelCacheFlush(): void
    {
        $cache = new MultiLevelCache(
            null, 
            null, 
            new FileCache($this->testCacheDir)
        );
        
        $cache->setByPath('/test.phtml', 'content', 'compiled');
        
        // 应该能获取
        self::assertEquals('compiled', $cache->getByPath('/test.phtml', 'content'));
        
        // 清空后应获取不到
        $cache->flush();
        self::assertNull($cache->getByPath('/test.phtml', 'content'));
    }
}
