<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Taglib\Cache\FileCache;
use Weline\Framework\View\Taglib\Cache\MultiLevelCache;
use Weline\Framework\View\Template;

final class TaglibMultiLevelCacheScopeTest extends TestCore
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/weline-taglib-cache-scope-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->dir . '/*') ?: [] as $bucket) {
            rmdir($bucket);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testSameContentUnderDifferentPathsDoesNotShareCompiledResult(): void
    {
        $template = new Template();
        $content = '<w:field>Name</w:field>';
        $cache = new MultiLevelCache(null, null, new FileCache($this->dir));
        $cache->set($template, 'parent.phtml#scope:a', $content, 'compiled-a');

        self::assertSame('compiled-a', $cache->get($template, 'parent.phtml#scope:a', $content));
        self::assertNull($cache->get($template, 'parent.phtml#scope:b', $content));
        self::assertNull((new MultiLevelCache(null, null, new FileCache($this->dir)))->get(new Template(), '', $content));
    }
}
