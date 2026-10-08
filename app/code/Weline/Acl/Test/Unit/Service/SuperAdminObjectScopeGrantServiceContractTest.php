<?php

declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 超管对象 Scope 种子：全站 Website 写权 + 建站增量入口契约。
 */
final class SuperAdminObjectScopeGrantServiceContractTest extends TestCase
{
    public function testUpgradeObserverDelegatesToGrantServiceBaseline(): void
    {
        $root = \dirname(__DIR__, 3);
        $observer = \file_get_contents($root . '/Observer/SetupUpgradeGrantSuperAdminObjectScope.php');
        self::assertIsString($observer);
        self::assertStringContainsString('SuperAdminObjectScopeGrantService', $observer);
        self::assertStringContainsString('ensureBaselineGrants', $observer);
        self::assertStringNotContainsString("website_code' => 'default'", $observer);
    }

    public function testGrantServiceSeedsEveryWebsiteNotOnlyDefault(): void
    {
        $root = \dirname(__DIR__, 3);
        $service = \file_get_contents($root . '/Service/SuperAdminObjectScopeGrantService.php');
        self::assertIsString($service);
        self::assertStringContainsString('ensureWebsiteWriteGrant', $service);
        self::assertStringContainsString('listWebsiteTargets', $service);
        self::assertStringContainsString('KIND_WEBSITE', $service);
        self::assertStringContainsString('ALL_SITES_READ_ACTIONS', $service);
    }

    public function testWebsiteSaveAfterObserverEnsuresWebsiteWriteGrant(): void
    {
        $root = \dirname(__DIR__, 3);
        $observer = \file_get_contents($root . '/Observer/WebsiteSaveAfterGrantSuperAdminObjectScope.php');
        self::assertIsString($observer);
        self::assertStringContainsString('ensureWebsiteWriteGrant', $observer);

        $events = \file_get_contents($root . '/etc/event.xml');
        self::assertIsString($events);
        self::assertStringContainsString('Weline_Websites::website_save_after', $events);
        self::assertStringContainsString('WebsiteSaveAfterGrantSuperAdminObjectScope', $events);
    }

    public function testWebsiteGrantCoversGroceryChannelUpdateInAuthorizationMatrix(): void
    {
        $store = new \Weline\Acl\Service\ArrayObjectScopeGrantStore([
            new \Weline\Acl\Api\Authorization\ObjectScopeGrantRecord(
                1,
                false,
                \Weline\Framework\Runtime\ScopeIdentity::KIND_WEBSITE,
                544,
                'grocery',
                null,
                null,
                [
                    \Weline\Acl\Api\Authorization\ObjectAction::VIEW,
                    \Weline\Acl\Api\Authorization\ObjectAction::UPDATE,
                ],
                1,
            ),
        ]);
        $svc = new \Weline\Acl\Service\ObjectAuthorizationService($store);
        $channel = \Weline\Framework\Runtime\ScopeIdentity::channel(
            544,
            'grocery',
            'default',
            'default',
            \Weline\Framework\Runtime\ScopeIdentity::MODE_NORMAL,
        );

        self::assertTrue($svc->isObjectActionAllowed(
            1,
            \Weline\Acl\Api\Authorization\ObjectAction::UPDATE,
            $channel,
        ));
    }
}
