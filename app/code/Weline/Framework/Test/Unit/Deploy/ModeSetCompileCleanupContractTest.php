<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * deploy:mode:set：缓存用 flush，模板编译物一次直接删；禁止重复清 tpl / 先掏空再 rmdir。
 */
final class ModeSetCompileCleanupContractTest extends TestCase
{
    private function frameworkFile(string $relativeFromFramework): string
    {
        return \dirname(__DIR__, 3) . '/' . \ltrim(\str_replace('\\', '/', $relativeFromFramework), '/');
    }

    public function testModeSetFlushesPoolsThenDeletesCompileTreesOnce(): void
    {
        $src = (string)\file_get_contents(
            $this->frameworkFile('Console/Console/Deploy/Mode/Set.php')
        );
        self::assertStringContainsString("skip_view_compile' => true", $src);
        self::assertStringContainsString('刷新缓存池（flush）', $src);
        self::assertStringContainsString('正在删除模组模板编译目录', $src);
        self::assertStringContainsString('clearGeneratedComplicateDir', $src);
        self::assertStringContainsString('escapeshellarg', $src);

        // prod/dev 路径不得再二次 cleanTplComDir（整文件仅出现一次调用链：deploy 内一次）。
        $cleanCalls = \substr_count($src, '$this->cleanTplComDir()');
        self::assertSame(1, $cleanCalls, 'cleanTplComDir must run once per deploy()');
    }

    public function testCacheClearSupportsPoolsOnlySkipViewCompile(): void
    {
        $src = (string)\file_get_contents(
            $this->frameworkFile('Cache/Console/Cache/Clear.php')
        );
        self::assertStringContainsString('skip_view_compile', $src);
        self::assertStringContainsString('--pools-only', $src);
    }

    public function testTemplateCacheManagerClearAllDeletesTreeOnce(): void
    {
        $src = (string)\file_get_contents(
            $this->frameworkFile('View/TemplateCacheManager.php')
        );
        self::assertStringContainsString('rm -rf', $src);
        self::assertStringContainsString('escapeshellarg($this->cacheRoot)', $src);
        // 旧慢路径：glob 后逐文件 removeDirectory
        self::assertStringNotContainsString('glob($this->cacheRoot', $src);
    }
}
