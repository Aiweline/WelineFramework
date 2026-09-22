<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Api\Auth\BackendLoginAccount;
use Weline\Framework\Event\Event;
use Weline\Websites\Observer\DenySuperAdminBypassOnNonDefaultWebsite;
use Weline\Websites\Observer\EnforceBackendUserWebsiteBindOnLogin;
use Weline\Websites\Service\WebsiteAclGrantService;

final class SuperAdminWebsiteGateExemptionContractTest extends TestCase
{
    public function testLoginBindSkipsWhenRoleIdIsSuperAdmin(): void
    {
        $user = new BackendLoginAccount(
            5,
            'cursor_ops',
            'cursor_ops@example.test',
            '',
            0,
            false,
            true,
            false,
            1,
        );
        $event = new Event([
            'user' => $user,
            'handled' => false,
        ]);

        (new EnforceBackendUserWebsiteBindOnLogin())->execute($event);

        self::assertFalse((bool)$event->getData('handled'));
        self::assertNull($event->getData('error'));
    }

    public function testLoginBindSkipsWhenUserIdIsOneEvenWithoutSuperRole(): void
    {
        $user = new BackendLoginAccount(
            1,
            'weline',
            'admin@example.test',
            '',
            0,
            false,
            true,
            false,
            0,
        );
        $event = new Event([
            'user' => $user,
            'handled' => false,
        ]);

        (new EnforceBackendUserWebsiteBindOnLogin())->execute($event);

        self::assertFalse((bool)$event->getData('handled'));
        self::assertNull($event->getData('error'));
    }

    public function testDenySuperAdminBypassObserverIsNoOp(): void
    {
        $event = new Event(['allow_bypass' => true, 'role_id' => 1]);
        (new DenySuperAdminBypassOnNonDefaultWebsite())->execute($event);
        self::assertTrue((bool)$event->getData('allow_bypass'));

        $eventCleared = new Event(['allow_bypass' => false, 'role_id' => 1]);
        (new DenySuperAdminBypassOnNonDefaultWebsite())->execute($eventCleared);
        self::assertFalse((bool)$eventCleared->getData('allow_bypass'));
    }

    public function testFilterRoleAclEntriesLeavesSuperAdminEntriesUntouchedOnNonDefaultSite(): void
    {
        $service = new WebsiteAclGrantService();
        $entries = [
            ['source_id' => 'Weline_Backend::dashboard', 'type' => 'menus', 'route' => 'weline_dashboard/backend/dashboard'],
            ['source_id' => 'Weline_Admin::system_dashboard', 'type' => 'menus', 'route' => 'admin'],
        ];

        $filtered = $service->filterRoleAclEntries($entries, 1, 99);

        self::assertSame($entries, $filtered);
    }

    public function testFilterRoleAclEntriesStillEmptiesNonSuperOnEmptyGrantPackage(): void
    {
        $service = new WebsiteAclGrantService();
        WebsiteAclGrantService::clearRequestCache();
        $ref = new \ReflectionClass(WebsiteAclGrantService::class);
        $prop = $ref->getProperty('sourceIdCache');
        $prop->setAccessible(true);
        $prop->setValue(null, [9_999_991 => []]);

        $entries = [
            ['source_id' => 'Weline_Backend::dashboard', 'type' => 'menus', 'route' => 'weline_dashboard/backend/dashboard'],
        ];
        $filtered = $service->filterRoleAclEntries($entries, 2, 9_999_991);

        self::assertSame([], $filtered);
        WebsiteAclGrantService::clearRequestCache();
    }
}
