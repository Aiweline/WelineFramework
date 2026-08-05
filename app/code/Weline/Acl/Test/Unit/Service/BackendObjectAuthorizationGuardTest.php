<?php

declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Api\Auth\BackendIdentityContextProviderInterface;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectAuthorizationAuditInterface;
use Weline\Acl\Api\Authorization\ObjectScopeGrantRecord;
use Weline\Acl\Service\ArrayObjectScopeGrantStore;
use Weline\Acl\Service\BackendObjectAuthorizationGuard;
use Weline\Acl\Service\ObjectAuthorizationService;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\FrontendQueryException;

/** TEST-SEC-04: backend object actions are default-deny and rechecked at submit time. */
final class BackendObjectAuthorizationGuardTest extends TestCase
{
    /** @var list<string> */
    private array $registryFiles = [];

    protected function tearDown(): void
    {
        GuardTestBackendIdentityProvider::$context = null;
        foreach ($this->registryFiles as $file) {
            @\unlink($file);
        }
        $this->registryFiles = [];
        parent::tearDown();
    }

    public function testMissingBackendIdentityFailsWithFixedQueryDenial(): void
    {
        $audit = $this->createMock(ObjectAuthorizationAuditInterface::class);
        $audit->expects(self::once())
            ->method('record')
            ->with(0, 0, ObjectAction::VIEW, self::isInstanceOf(ScopeIdentity::class), false);
        $guard = new BackendObjectAuthorizationGuard(
            new ObjectAuthorizationService(new ArrayObjectScopeGrantStore([])),
            $audit,
            $this->runtimeResolver(null),
        );

        try {
            $guard->requireForQuery(ObjectAction::VIEW, ScopeIdentity::website(0, 'default'));
            self::fail('Missing backend identity must be denied.');
        } catch (FrontendQueryException $exception) {
            self::assertSame('object_scope_access_denied', $exception->getErrorCode());
            self::assertSame(403, $exception->getHttpStatus());
        }
    }

    public function testReadReturnsMatchedGrantVersionWithoutSubmitAudit(): void
    {
        $audit = $this->createMock(ObjectAuthorizationAuditInterface::class);
        $audit->expects(self::never())->method('record');
        $guard = $this->guardWithGrant(
            [ObjectAction::VIEW, ObjectAction::UPDATE],
            7,
            $audit,
        );

        $result = $guard->requireForQuery(
            ObjectAction::VIEW,
            ScopeIdentity::store(0, 'default', 'default', ScopeIdentity::MODE_NORMAL),
        );

        self::assertTrue($result->allowed);
        self::assertSame(7, $result->matchedGrantVersion);
        self::assertSame(8, $guard->currentRoleId());
    }

    public function testSubmitRechecksVersionAndAuditsAllowedDecision(): void
    {
        $scope = ScopeIdentity::website(0, 'default');
        $audit = $this->createMock(ObjectAuthorizationAuditInterface::class);
        $audit->expects(self::once())
            ->method('record')
            ->with(21, 8, ObjectAction::UPDATE, $scope, true, 'granted', 9, 'submit');
        $guard = $this->guardWithGrant([ObjectAction::UPDATE], 9, $audit);

        self::assertTrue(
            $guard->requireSubmitForQuery(ObjectAction::UPDATE, $scope, 9)->allowed,
        );
    }

    public function testSubmitAfterGrantVersionChangeIsFixed403AndAudited(): void
    {
        $scope = ScopeIdentity::website(0, 'default');
        $audit = $this->createMock(ObjectAuthorizationAuditInterface::class);
        $audit->expects(self::once())
            ->method('record')
            ->with(21, 8, ObjectAction::UPDATE, $scope, false, 'grant_version_mismatch', 0, 'submit');
        $guard = $this->guardWithGrant([ObjectAction::UPDATE], 10, $audit);

        try {
            $guard->requireSubmitForQuery(ObjectAction::UPDATE, $scope, 9);
            self::fail('Changed grant version must deny the submit.');
        } catch (FrontendQueryException $exception) {
            self::assertSame('object_scope_access_denied', $exception->getErrorCode());
            self::assertSame(403, $exception->getHttpStatus());
        }
    }

    /**
     * @param list<string> $actions
     */
    private function guardWithGrant(
        array $actions,
        int $grantVersion,
        ObjectAuthorizationAuditInterface $audit,
    ): BackendObjectAuthorizationGuard {
        $grantStore = new ArrayObjectScopeGrantStore([
            new ObjectScopeGrantRecord(
                8,
                false,
                ScopeIdentity::KIND_WEBSITE,
                0,
                'default',
                null,
                null,
                $actions,
                $grantVersion,
            ),
        ]);

        GuardTestBackendIdentityProvider::$context = [
            'user_id' => 21,
            'role_id' => 8,
            'is_enabled' => 1,
        ];

        return new BackendObjectAuthorizationGuard(
            new ObjectAuthorizationService($grantStore),
            $audit,
            $this->runtimeResolver(GuardTestBackendIdentityProvider::class),
        );
    }

    private function runtimeResolver(
        ?string $providerClass,
    ): RuntimeProviderResolver {
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
        }
        if (!\defined('DS')) {
            \define('DS', DIRECTORY_SEPARATOR);
        }
        $file = \sys_get_temp_dir() . '/weline-acl-guard-registry-' . \bin2hex(\random_bytes(6)) . '.php';
        $this->registryFiles[] = $file;
        $provides = $providerClass === null
            ? []
            : [BackendIdentityContextProviderInterface::class => $providerClass];
        $registry = [
            'format' => 1,
            'order' => ['Test_Acl'],
            'modules' => [
                'Test_Acl' => ['provides' => $provides],
            ],
        ];
        \file_put_contents(
            $file,
            "<?php\nreturn " . \var_export($registry, true) . ";\n",
        );

        return new RuntimeProviderResolver(new ServiceProviderRegistry($file));
    }
}

final class GuardTestBackendIdentityProvider implements BackendIdentityContextProviderInterface
{
    /** @var array{user_id:int,role_id:int,is_enabled:int}|null */
    public static ?array $context = null;

    public function getAclContext(int $userId): ?array
    {
        return self::$context;
    }

    public function currentAclContext(): ?array
    {
        return self::$context;
    }

    public function currentWarmupUserId(Request $request): int
    {
        return 0;
    }
}
