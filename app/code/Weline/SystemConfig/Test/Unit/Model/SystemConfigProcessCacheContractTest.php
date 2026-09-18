<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Model;

use PHPUnit\Framework\TestCase;

final class SystemConfigProcessCacheContractTest extends TestCase
{
    public function testModelExposesClearProcessCacheAndKeyedProcessBucket(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/SystemConfig.php');
        self::assertStringContainsString('function clearProcessCache', $src);
        self::assertStringContainsString('self::$configs[$area][$module][$processKey]', $src);
    }

    public function testInvalidationClearsProcessCacheHelper(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ConfigCacheInvalidationService.php');
        self::assertStringContainsString('SystemConfig::clearProcessCache($area, $module)', $src);
    }
}
