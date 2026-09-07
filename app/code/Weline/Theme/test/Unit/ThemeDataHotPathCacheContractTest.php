<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ThemeData / DiskHead 热路径不得清共享命名空间或打 weline_site_runtime IPC。
 */
final class ThemeDataHotPathCacheContractTest extends TestCase
{
    public function testThemeDataRuntimeCacheIsProcessLocalOnly(): void
    {
        $path = dirname(__DIR__, 2) . '/Helper/ThemeData.php';
        $src = file_get_contents($path);
        self::assertIsString($src);

        $getPos = strpos($src, 'private static function getRuntimeCache(string $key): array');
        $setPos = strpos($src, 'private static function setRuntimeCache(string $key, mixed $value): void');
        $prunePos = strpos($src, 'private static function pruneRuntimeCache(): void');
        self::assertNotFalse($getPos);
        self::assertNotFalse($setPos);
        self::assertNotFalse($prunePos);

        $getBody = substr($src, $getPos, $setPos - $getPos);
        $setBody = substr($src, $setPos, $prunePos - $setPos);
        self::assertStringContainsString('Hot path stays process-local', $getBody);
        self::assertStringNotContainsString("\$cache->get(self::SHARED_CACHE_NAMESPACE", $getBody);
        self::assertStringContainsString('Do not mirror to SharedState', $setBody);
        self::assertStringNotContainsString("\$cache->set(self::SHARED_CACHE_NAMESPACE", $setBody);
    }

    public function testDiskHeadClearsProcessCacheNotSharedNamespace(): void
    {
        $path = dirname(__DIR__, 2) . '/Service/Disk/ThemeDiskHeadService.php';
        $src = file_get_contents($path);
        self::assertIsString($src);

        self::assertStringContainsString('ThemeData::clearProcessMemoryCache()', $src);
        self::assertStringNotContainsString('ThemeData::clearCache()', $src);
        self::assertStringContainsString('never clearNamespace(weline_site_runtime)', $src);
        self::assertStringContainsString('shouldRefreshProcessCache', $src);
    }

    public function testDiskHeadExposesBoundedTimingPhases(): void
    {
        $path = dirname(__DIR__, 2) . '/Service/Disk/ThemeDiskHeadService.php';
        $src = file_get_contents($path);
        self::assertIsString($src);

        self::assertStringContainsString("theme.head.disk_override.reset", $src);
        self::assertStringContainsString("theme.head.disk_override.config", $src);
        self::assertStringContainsString("theme.head.disk_override.resolve", $src);
    }
}
