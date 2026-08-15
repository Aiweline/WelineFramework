<?php

declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Service\AclCacheInvalidator;

final class AclCacheInvalidatorContractTest extends TestCase
{
    public function testFlushDeletesRoleSourceKeyThenClearsPoolAndMenuTree(): void
    {
        $source = (string)\file_get_contents(
            BP . '/app/code/Weline/Acl/Service/AclCacheInvalidator.php'
        );

        self::assertStringContainsString("acl_' . \$roleId . '_source", $source);
        self::assertStringContainsString("w_cache('acl')", $source);
        self::assertStringContainsString('$cache->clear()', $source);
        self::assertStringContainsString('AclService::resetRequestCache', $source);
        self::assertStringContainsString('RouteBefore::resetRequestCache', $source);
        self::assertStringContainsString('AclTaglib::resetRequestState', $source);
        self::assertStringContainsString('invalidateBackendMenuTreeCache', $source);
        self::assertStringContainsString('clearSnapshot', $source);
        self::assertStringContainsString('Weline_Acl::role_access_cache_invalidated', $source);
    }

    public function testPostAssignFlushesCacheImmediatelyAfterCommit(): void
    {
        $controller = (string)\file_get_contents(
            BP . '/app/code/Weline/Acl/Controller/Backend/Acl/Role.php'
        );

        $commitPos = \strpos($controller, '$roleAccessModel->commit();');
        $flushPos = \strpos($controller, 'AclCacheInvalidator::flushAfterRoleAccessChange');

        self::assertNotFalse($commitPos);
        self::assertNotFalse($flushPos);
        self::assertGreaterThan($commitPos, $flushPos);
        self::assertStringNotContainsString("w_cache('acl')->clear();", $controller);
    }

    public function testInvalidatorClassIsLoadable(): void
    {
        self::assertTrue(\class_exists(AclCacheInvalidator::class));
        self::assertTrue(\is_callable([AclCacheInvalidator::class, 'flushAfterRoleAccessChange']));
    }
}
