<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ThemeData / DiskHead 热路径不得清共享命名空间或打 weline_site_runtime IPC。
 * 进程 L1 刷新只认 theme_disk_refresh；整池 clearCache 只留在发布与 deleteParamValue。
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
        $src = self::themeSource('Service/Disk/ThemeDiskHeadService.php');

        self::assertStringContainsString('ThemeData::clearProcessMemoryCache()', $src);
        self::assertStringNotContainsString('ThemeData::clearCache()', $src);
        self::assertStringContainsString('never clearNamespace(weline_site_runtime)', $src);
        self::assertStringContainsString('shouldRefreshProcessCache', $src);
        self::assertStringContainsString('theme_disk_refresh', $src);

        $withoutGuardComment = str_replace('never clearNamespace(weline_site_runtime)', '', $src);
        self::assertStringNotContainsString('clearNamespace(', $withoutGuardComment);

        $refresh = self::sliceFunction($src, 'function shouldRefreshProcessCache(', true);
        self::assertStringContainsString("'theme_disk_refresh'", $refresh);
        $lowerRefresh = strtolower($refresh);
        foreach (['editor_mode', 'preview', 'visual_editor'] as $token) {
            self::assertStringNotContainsString(
                $token,
                $lowerRefresh,
                'shouldRefreshProcessCache must not treat ' . $token . ' as a refresh condition'
            );
        }
        self::assertDoesNotMatchRegularExpression(
            '/\$area\s*===\s*([\'"])backend\1\s*\)\s*\{?\s*return\s+true\s*;/',
            $refresh,
            'area === backend must not return true'
        );
        self::assertDoesNotMatchRegularExpression(
            '/return\s+\$area\s*===\s*([\'"])backend\1\s*;/',
            $refresh,
            'area === backend must not return true'
        );
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

    public function testGetConfigListBodyHasNoTypePrefixOrVersionField(): void
    {
        $body = self::sliceFunction(self::themeSource('Helper/ThemeData.php'), 'function getConfigList(');
        self::assertStringNotContainsString('function getScopes(', $body);
        self::assertStringNotContainsString('configKeyPrefix', $body);
        self::assertMetaConfigSearchHasNoVersionField($body);
    }

    public function testSetBodyDoesNotCallClearCache(): void
    {
        $body = self::sliceFunction(self::themeSource('Helper/ThemeData.php'), 'function set(');
        self::assertStringNotContainsString('function deleteParamValue(', $body);
        self::assertStringNotContainsString('clearCache', $body);
    }

    public function testPublishCleanerStillCallsThemeDataClearCache(): void
    {
        $src = self::themeSource('Service/ThemeRuntimeCacheCleaner.php');
        self::assertStringContainsString('ThemeData::clearCache()', $src);
    }

    private static function themeSource(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $src = file_get_contents($path);
        self::assertIsString($src, 'missing ' . $relative);

        return $src;
    }

    private static function sliceFunction(string $source, string $signature, bool $includeLeadingDocblock = false): string
    {
        $pos = strpos($source, $signature);
        self::assertNotFalse($pos, 'missing ' . $signature);
        $start = $pos;
        if ($includeLeadingDocblock) {
            $before = substr($source, 0, $pos);
            if (preg_match('/\/\*\*(?:(?!\*\/).)*\*\/\s*(?:(?:public|protected|private|static|final)\s+)*$/s', $before, $doc) === 1) {
                $start = $pos - strlen($doc[0]);
            }
        }
        $scanFrom = $pos + strlen($signature);
        $found = preg_match(
            '/\n {4}(?:public|protected|private) (?:static )?function /',
            $source,
            $match,
            PREG_OFFSET_CAPTURE,
            $scanFrom
        );
        self::assertSame(1, $found, 'next function not found after ' . $signature);

        return substr($source, $start, $match[0][1] - $start);
    }

    private static function assertMetaConfigSearchHasNoVersionField(string $body): void
    {
        $needle = 'new MetaConfigSearch(';
        $offset = 0;
        while (($pos = strpos($body, $needle, $offset)) !== false) {
            $open = $pos + strlen('new MetaConfigSearch');
            self::assertSame('(', $body[$open] ?? '');
            $depth = 0;
            $end = null;
            $length = strlen($body);
            for ($i = $open; $i < $length; $i++) {
                $char = $body[$i];
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }
            self::assertNotNull($end, 'unclosed MetaConfigSearch');
            $args = substr($body, $open, $end - $open + 1);
            self::assertDoesNotMatchRegularExpression('/\bversion(?:_id)?\b/i', $args);
            $offset = $end + 1;
        }
    }
}
