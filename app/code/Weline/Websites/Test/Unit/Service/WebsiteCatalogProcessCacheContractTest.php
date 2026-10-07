<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class WebsiteCatalogProcessCacheContractTest extends TestCase
{
    public function testCatalogOwnsProcessBagAndClear(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/WebsiteCatalog.php');
        self::assertStringContainsString('$processBag', $src);
        self::assertStringContainsString('function clearProcessCache', $src);
        self::assertStringContainsString('PROCESS_ALL_KEY', $src);
        self::assertStringContainsString('PROCESS_DEFAULT_ID_KEY', $src);
        self::assertStringContainsString('ScopeSharedMemo::rememberScoped', $src);
        self::assertStringContainsString('ScopeIdentity::global()', $src);
        self::assertStringContainsString('websites.catalog.all', $src);
    }

    public function testMaintenanceGateOwnsProcessBag(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ScopeMaintenanceGate.php');
        self::assertStringContainsString('$processStatusByScopeKey', $src);
        self::assertStringContainsString('function clearProcessCache', $src);
        self::assertStringContainsString('ScopeSharedMemo::rememberScoped', $src);
        self::assertStringContainsString('forgetScoped', $src);
    }

    public function testProcessCacheResetterClearsCatalogAndGate(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Api/Runtime/ProcessCacheResetter.php');
        self::assertStringContainsString('WebsiteCatalog::clearProcessCache()', $src);
        self::assertStringContainsString('ScopeMaintenanceGate::clearProcessCache()', $src);
    }
}
