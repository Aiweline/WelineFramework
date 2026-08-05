<?php

declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectScopeGrantRecord;
use Weline\Acl\Service\ArrayObjectScopeGrantStore;
use Weline\Acl\Service\ObjectAuthorizationService;
use Weline\Framework\Runtime\ScopeIdentity;

final class ObjectAuthorizationServiceTest extends TestCase
{
    public function testDefaultDenyWithoutGrants(): void
    {
        $svc = new ObjectAuthorizationService(new ArrayObjectScopeGrantStore([]));
        $object = ScopeIdentity::store(0, 'default', 'default', ScopeIdentity::MODE_NORMAL);
        $result = $svc->authorize(9, ObjectAction::LIST, $object);
        self::assertFalse($result->allowed);
        self::assertSame('no_grants', $result->reason);
    }

    public function testWebsiteGrantCoversStoreAndChannelButNotSiblingWebsite(): void
    {
        $store = new ArrayObjectScopeGrantStore([
            new ObjectScopeGrantRecord(
                9,
                false,
                ScopeIdentity::KIND_WEBSITE,
                0,
                'default',
                null,
                null,
                [ObjectAction::LIST, ObjectAction::VIEW, ObjectAction::UPDATE],
                3,
            ),
        ]);
        $svc = new ObjectAuthorizationService($store);

        self::assertTrue($svc->isObjectActionAllowed(
            9,
            ObjectAction::LIST,
            ScopeIdentity::store(0, 'default', 'default', ScopeIdentity::MODE_NORMAL),
        ));
        self::assertTrue($svc->isObjectActionAllowed(
            9,
            ObjectAction::VIEW,
            ScopeIdentity::channel(0, 'default', 'default', 'default', ScopeIdentity::MODE_NORMAL),
        ));
        self::assertFalse($svc->isObjectActionAllowed(
            9,
            ObjectAction::LIST,
            ScopeIdentity::website(2, 'other'),
        ));
    }

    public function testAllSitesIsReadOnlyAndNeverAllowsWrite(): void
    {
        $store = new ArrayObjectScopeGrantStore([
            new ObjectScopeGrantRecord(
                4,
                true,
                null,
                null,
                null,
                null,
                null,
                [ObjectAction::LIST, ObjectAction::VIEW, ObjectAction::EXPORT],
                1,
            ),
        ]);
        $svc = new ObjectAuthorizationService($store);
        $object = ScopeIdentity::channel(7, 'shop', 'main', 'web', ScopeIdentity::MODE_NORMAL);

        self::assertTrue($svc->isObjectActionAllowed(4, ObjectAction::LIST, $object));
        self::assertTrue($svc->isObjectActionAllowed(4, ObjectAction::VIEW, $object));
        self::assertFalse($svc->isObjectActionAllowed(4, ObjectAction::UPDATE, $object));
        self::assertFalse($svc->isObjectActionAllowed(4, ObjectAction::DELETE, $object));
        self::assertFalse($svc->isObjectActionAllowed(4, ObjectAction::CREATE, $object));
    }

    public function testExplicitGlobalGrantAllowsGlobalWriteWithoutAllSitesWildcard(): void
    {
        $store = new ArrayObjectScopeGrantStore([
            new ObjectScopeGrantRecord(
                6,
                false,
                ScopeIdentity::KIND_GLOBAL,
                null,
                null,
                null,
                null,
                [ObjectAction::VIEW, ObjectAction::UPDATE],
                4,
            ),
        ]);
        $svc = new ObjectAuthorizationService($store);

        self::assertTrue($svc->isObjectActionAllowed(6, ObjectAction::VIEW, ScopeIdentity::global()));
        self::assertTrue($svc->isObjectActionAllowed(6, ObjectAction::UPDATE, ScopeIdentity::global()));
        self::assertFalse($svc->isObjectActionAllowed(
            6,
            ObjectAction::UPDATE,
            ScopeIdentity::website(0, 'default'),
        ));
    }

    public function testForeignStoreIdIsDeniedWithoutExistenceDistinction(): void
    {
        $store = new ArrayObjectScopeGrantStore([
            new ObjectScopeGrantRecord(
                5,
                false,
                ScopeIdentity::KIND_STORE,
                1,
                'site-a',
                'main',
                null,
                [ObjectAction::LIST, ObjectAction::VIEW, ObjectAction::UPDATE, ObjectAction::DELETE],
                2,
            ),
        ]);
        $svc = new ObjectAuthorizationService($store);

        $denied = $svc->authorize(
            5,
            ObjectAction::UPDATE,
            ScopeIdentity::store(1, 'site-a', 'other', ScopeIdentity::MODE_NORMAL),
        );
        self::assertFalse($denied->allowed);
        self::assertSame('scope_or_action_denied', $denied->reason);
    }

    public function testSubmitReauthRequiresMatchingGrantVersion(): void
    {
        $store = new ArrayObjectScopeGrantStore([
            new ObjectScopeGrantRecord(
                8,
                false,
                ScopeIdentity::KIND_WEBSITE,
                0,
                'default',
                null,
                null,
                [ObjectAction::UPDATE],
                11,
            ),
        ]);
        $svc = new ObjectAuthorizationService($store);
        $object = ScopeIdentity::website(0, 'default');

        self::assertTrue($svc->authorizeForSubmit(8, ObjectAction::UPDATE, $object, 11)->allowed);
        self::assertFalse($svc->authorizeForSubmit(8, ObjectAction::UPDATE, $object, 10)->allowed);
        self::assertSame(
            'grant_version_mismatch',
            $svc->authorizeForSubmit(8, ObjectAction::UPDATE, $object, 10)->reason,
        );
    }

    public function testReconcileIsKnownWriteAndRequiresExplicitGrant(): void
    {
        self::assertTrue(ObjectAction::isKnown(ObjectAction::RECONCILE));
        self::assertTrue(ObjectAction::isWrite(ObjectAction::RECONCILE));
        self::assertFalse(ObjectAction::isAllSitesReadable(ObjectAction::RECONCILE));

        $scope = ScopeIdentity::website(0, 'default');
        $denied = new ObjectAuthorizationService(new ArrayObjectScopeGrantStore([]));
        self::assertFalse($denied->isObjectActionAllowed(14, ObjectAction::RECONCILE, $scope));

        $allowed = new ObjectAuthorizationService(new ArrayObjectScopeGrantStore([
            new ObjectScopeGrantRecord(
                14,
                false,
                ScopeIdentity::KIND_WEBSITE,
                0,
                'default',
                null,
                null,
                [ObjectAction::RECONCILE],
                8,
            ),
        ]));
        self::assertTrue($allowed->authorizeForSubmit(
            14,
            ObjectAction::RECONCILE,
            $scope,
            8,
        )->allowed);
    }

    public function testSuperAdminRoleHasNoImplicitStoreStarWrite(): void
    {
        $svc = new ObjectAuthorizationService(new ArrayObjectScopeGrantStore([]));
        self::assertFalse($svc->isObjectActionAllowed(
            1,
            ObjectAction::UPDATE,
            ScopeIdentity::store(0, 'default', 'default', ScopeIdentity::MODE_NORMAL),
        ));
    }
}
