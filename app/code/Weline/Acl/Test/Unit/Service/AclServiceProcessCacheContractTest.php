<?php

declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class AclServiceProcessCacheContractTest extends TestCase
{
    public function testAclServiceOwnsProcessCachesAndClearApi(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/AclService.php');
        self::assertStringContainsString('function clearProcessCache', $src);
        self::assertStringContainsString('$processRouteProtected', $src);
        self::assertStringContainsString('$processRoleAclEntries', $src);
        self::assertStringContainsString('$processRouteResources', $src);
    }

    public function testInvalidatorAndResetterClearProcessCaches(): void
    {
        $invalidator = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/AclCacheInvalidator.php');
        $resetter = (string)file_get_contents(dirname(__DIR__, 3) . '/Api/Runtime/ProcessCacheResetter.php');
        self::assertStringContainsString('AclService::clearProcessCache()', $invalidator);
        self::assertStringContainsString('RouteBefore::clearProcessCache()', $invalidator);
        self::assertStringContainsString('AclService::clearProcessCache()', $resetter);
        self::assertStringContainsString('RouteBefore::clearProcessCache()', $resetter);
    }

    public function testWhiteAclSourceInvalidatesOnChange(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/WhiteAclSource.php');
        self::assertStringContainsString('function invalidateWhitelistCaches', $src);
        self::assertStringContainsString('function save_after', $src);
        self::assertStringContainsString('function delete_after', $src);
    }
}
