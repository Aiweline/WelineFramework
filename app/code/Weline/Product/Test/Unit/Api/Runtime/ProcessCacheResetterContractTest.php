<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Api\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Product\Api\Runtime\ProcessCacheResetter;
use Weline\Product\Service\StorefrontProductMediaUrlResolver;

final class ProcessCacheResetterContractTest extends TestCase
{
    public function testModuleProvidesProcessCacheResetter(): void
    {
        $module = require dirname(__DIR__, 4) . '/etc/module.php';
        self::assertIsArray($module);
        $provides = $module['provides'] ?? [];
        self::assertArrayHasKey('process_cache_resetter.Weline_Product', $provides);
        self::assertSame(
            \Weline\Product\Api\Runtime\ProcessCacheResetter::class,
            $provides['process_cache_resetter.Weline_Product']
        );
    }

    public function testDiagCountsExposeFormerMemDiagProductKeys(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Api/Runtime/ProcessCacheResetter.php'
        );
        foreach ([
            'projection_snapshot_keys',
            'media_ref_cache',
            'diagProcessCacheCounts',
            'processSnapshotCacheCount',
            'processReferenceCacheCount',
        ] as $needle) {
            self::assertStringContainsString($needle, $src);
        }
    }

    public function testSoftMemoryPressureClearsMediaRefOnly(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Api/Runtime/ProcessCacheResetter.php'
        );
        self::assertStringContainsString('isSoftContent()', $src);
        self::assertMatchesRegularExpression(
            '/isSoftContent\(\)[\s\S]*?clearProcessReferenceCache\(\)[\s\S]*?return 1;/',
            $src,
        );
        self::assertDoesNotMatchRegularExpression(
            '/isSoftContent\(\)[\s\S]*?clearProcessSnapshotCache\(\)[\s\S]*?return 1;/',
            $src,
        );

        $resetter = new ProcessCacheResetter();
        $cleared = $resetter->resetProcessCaches(new ProcessCacheResetContext(
            ProcessCacheResetContext::REASON_MEMORY_PRESSURE,
            false,
        ));
        self::assertSame(1, $cleared);
        self::assertSame(0, StorefrontProductMediaUrlResolver::processReferenceCacheCount());
    }
}
