<?php

declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Service\AclCacheInvalidator;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllAclServiceContractsPass().
 */
final class AclServiceContractsTest extends TestCase
{
    public function testAllAclServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'AclCacheInvalidator::testFlushDeletesRoleSourceKeyThenClearsPoolAndMenuTree' => function () {
            $this->assert_AclCacheInvalidator_testFlushDeletesRoleSourceKeyThenClearsPoolAndMenuTree();
        },
            'AclCacheInvalidator::testPostAssignFlushesCacheImmediatelyAfterCommit' => function () {
            $this->assert_AclCacheInvalidator_testPostAssignFlushesCacheImmediatelyAfterCommit();
        },
            'AclCacheInvalidator::testInvalidatorClassIsLoadable' => function () {
            $this->assert_AclCacheInvalidator_testInvalidatorClassIsLoadable();
        },
            'AclServiceProcessCache::testAclServiceOwnsProcessCachesAndClearApi' => function () {
            $this->assert_AclServiceProcessCache_testAclServiceOwnsProcessCachesAndClearApi();
        },
            'AclServiceProcessCache::testInvalidatorAndResetterClearProcessCaches' => function () {
            $this->assert_AclServiceProcessCache_testInvalidatorAndResetterClearProcessCaches();
        },
            'AclServiceProcessCache::testWhiteAclSourceInvalidatesOnChange' => function () {
            $this->assert_AclServiceProcessCache_testWhiteAclSourceInvalidatesOnChange();
        },
            'SuperAdminObjectScopeGrantService::testUpgradeObserverDelegatesToGrantServiceBaseline' => function () {
            $this->assert_SuperAdminObjectScopeGrantService_testUpgradeObserverDelegatesToGrantServiceBaseline();
        },
            'SuperAdminObjectScopeGrantService::testGrantServiceSeedsEveryWebsiteNotOnlyDefault' => function () {
            $this->assert_SuperAdminObjectScopeGrantService_testGrantServiceSeedsEveryWebsiteNotOnlyDefault();
        },
            'SuperAdminObjectScopeGrantService::testWebsiteSaveAfterObserverEnsuresWebsiteWriteGrant' => function () {
            $this->assert_SuperAdminObjectScopeGrantService_testWebsiteSaveAfterObserverEnsuresWebsiteWriteGrant();
        },
            'SuperAdminObjectScopeGrantService::testWebsiteGrantCoversGroceryChannelUpdateInAuthorizationMatrix' => function () {
            $this->assert_SuperAdminObjectScopeGrantService_testWebsiteGrantCoversGroceryChannelUpdateInAuthorizationMatrix();
        },
        ];
    }

private function assert_AclCacheInvalidator_testFlushDeletesRoleSourceKeyThenClearsPoolAndMenuTree(): void
{
    $source = (string) \file_get_contents(BP . '/app/code/Weline/Acl/Service/AclCacheInvalidator.php');
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

private function assert_AclCacheInvalidator_testPostAssignFlushesCacheImmediatelyAfterCommit(): void
{
    $controller = (string) \file_get_contents(BP . '/app/code/Weline/Acl/Controller/Backend/Acl/Role.php');
    $commitPos = \strpos($controller, '$roleAccessModel->commit();');
    $flushPos = \strpos($controller, 'AclCacheInvalidator::flushAfterRoleAccessChange');
    self::assertNotFalse($commitPos);
    self::assertNotFalse($flushPos);
    self::assertGreaterThan($commitPos, $flushPos);
    self::assertStringNotContainsString("w_cache('acl')->clear();", $controller);
}

private function assert_AclCacheInvalidator_testInvalidatorClassIsLoadable(): void
{
    self::assertTrue(\class_exists(AclCacheInvalidator::class));
    self::assertTrue(\is_callable([AclCacheInvalidator::class, 'flushAfterRoleAccessChange']));
}

private function assert_AclServiceProcessCache_testAclServiceOwnsProcessCachesAndClearApi(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Acl' . '/Service/AclService.php');
    self::assertStringContainsString('function clearProcessCache', $src);
    self::assertStringContainsString('$processRouteProtected', $src);
    self::assertStringContainsString('$processRoleAclEntries', $src);
    self::assertStringContainsString('$processRouteResources', $src);
}

private function assert_AclServiceProcessCache_testInvalidatorAndResetterClearProcessCaches(): void
{
    $invalidator = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Acl' . '/Service/AclCacheInvalidator.php');
    $resetter = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Acl' . '/Api/Runtime/ProcessCacheResetter.php');
    self::assertStringContainsString('AclService::clearProcessCache()', $invalidator);
    self::assertStringContainsString('RouteBefore::clearProcessCache()', $invalidator);
    self::assertStringContainsString('AclService::clearProcessCache()', $resetter);
    self::assertStringContainsString('RouteBefore::clearProcessCache()', $resetter);
}

private function assert_AclServiceProcessCache_testWhiteAclSourceInvalidatesOnChange(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Acl' . '/Model/WhiteAclSource.php');
    self::assertStringContainsString('function invalidateWhitelistCaches', $src);
    self::assertStringContainsString('function save_after', $src);
    self::assertStringContainsString('function delete_after', $src);
}

private function assert_SuperAdminObjectScopeGrantService_testUpgradeObserverDelegatesToGrantServiceBaseline(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Acl';
    $observer = \file_get_contents($root . '/Observer/SetupUpgradeGrantSuperAdminObjectScope.php');
    self::assertIsString($observer);
    self::assertStringContainsString('SuperAdminObjectScopeGrantService', $observer);
    self::assertStringContainsString('ensureBaselineGrants', $observer);
    self::assertStringNotContainsString("website_code' => 'default'", $observer);
}

private function assert_SuperAdminObjectScopeGrantService_testGrantServiceSeedsEveryWebsiteNotOnlyDefault(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Acl';
    $service = \file_get_contents($root . '/Service/SuperAdminObjectScopeGrantService.php');
    self::assertIsString($service);
    self::assertStringContainsString('ensureWebsiteWriteGrant', $service);
    self::assertStringContainsString('listWebsiteTargets', $service);
    self::assertStringContainsString('KIND_WEBSITE', $service);
    self::assertStringContainsString('ALL_SITES_READ_ACTIONS', $service);
}

private function assert_SuperAdminObjectScopeGrantService_testWebsiteSaveAfterObserverEnsuresWebsiteWriteGrant(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Acl';
    $observer = \file_get_contents($root . '/Observer/WebsiteSaveAfterGrantSuperAdminObjectScope.php');
    self::assertIsString($observer);
    self::assertStringContainsString('ensureWebsiteWriteGrant', $observer);
    $events = \file_get_contents($root . '/etc/event.xml');
    self::assertIsString($events);
    self::assertStringContainsString('Weline_Websites::website_save_after', $events);
    self::assertStringContainsString('WebsiteSaveAfterGrantSuperAdminObjectScope', $events);
}

private function assert_SuperAdminObjectScopeGrantService_testWebsiteGrantCoversGroceryChannelUpdateInAuthorizationMatrix(): void
{
    $store = new \Weline\Acl\Service\ArrayObjectScopeGrantStore([new \Weline\Acl\Api\Authorization\ObjectScopeGrantRecord(1, false, \Weline\Framework\Runtime\ScopeIdentity::KIND_WEBSITE, 544, 'grocery', null, null, [\Weline\Acl\Api\Authorization\ObjectAction::VIEW, \Weline\Acl\Api\Authorization\ObjectAction::UPDATE], 1)]);
    $svc = new \Weline\Acl\Service\ObjectAuthorizationService($store);
    $channel = \Weline\Framework\Runtime\ScopeIdentity::channel(544, 'grocery', 'default', 'default', \Weline\Framework\Runtime\ScopeIdentity::MODE_NORMAL);
    self::assertTrue($svc->isObjectActionAllowed(1, \Weline\Acl\Api\Authorization\ObjectAction::UPDATE, $channel));
}
}
