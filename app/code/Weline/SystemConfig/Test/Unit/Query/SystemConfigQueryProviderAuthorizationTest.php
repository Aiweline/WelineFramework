<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectAuthorizationResult;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\SystemConfig\Extends\Module\Weline_Framework\Query\SystemConfigQueryProvider;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Model\SystemConfigVersion;
use Weline\SystemConfig\Service\SystemConfigCenterService;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;
use Weline\SystemConfig\Service\SystemConfigTemplateService;

/** TEST-ACL-03: every configuration mutation has an independent object-action authorization gate. */
final class SystemConfigQueryProviderAuthorizationTest extends TestCase
{
    public function testScopedWriteUsesResolvedWebsiteIdAndExpectedGrantVersionBeforeMutation(): void
    {
        $systemConfig = $this->createMock(SystemConfig::class);
        $systemConfig->expects(self::once())
            ->method('setScopedConfig')
            ->willReturn(true);
        $guard = $this->createMock(BackendObjectAuthorizationGuardInterface::class);
        $guard->expects(self::once())
            ->method('requireSubmitForQuery')
            ->with(
                ObjectAction::UPDATE,
                self::callback(
                    static fn(ScopeIdentity $scope): bool => $scope->websiteId === 17
                        && $scope->websiteCode === 'shop'
                        && $scope->scopeKind === ScopeIdentity::KIND_WEBSITE,
                ),
                12,
            )
            ->willReturn(ObjectAuthorizationResult::allow('granted', 12));

        $result = $this->provider($systemConfig, $guard)->execute('setScopedConfig', [
            'key' => 'vendor/module/enabled',
            'value' => true,
            'module' => 'Vendor_Module',
            'website_code' => 'shop',
            'expected_grant_version' => 12,
        ]);

        self::assertTrue($result);
    }

    public function testMissingGrantVersionIsPassedAsZeroAndDeniedBeforeMutation(): void
    {
        $systemConfig = $this->createMock(SystemConfig::class);
        $systemConfig->expects(self::never())->method('setScopedConfig');
        $guard = $this->createMock(BackendObjectAuthorizationGuardInterface::class);
        $guard->expects(self::once())
            ->method('requireSubmitForQuery')
            ->with(ObjectAction::UPDATE, self::isInstanceOf(ScopeIdentity::class), 0)
            ->willThrowException(new FrontendQueryException(
                'object_scope_access_denied',
                '操作授权条件不满足',
                403,
            ));

        $this->expectException(FrontendQueryException::class);
        $this->provider($systemConfig, $guard)->execute('setScopedConfig', [
            'key' => 'vendor/module/enabled',
            'value' => true,
            'module' => 'Vendor_Module',
            'website_code' => 'shop',
        ]);
    }

    public function testRollbackPrecheckDerivesScopeFromPersistedVersionNotRequestScope(): void
    {
        $systemConfig = $this->createMock(SystemConfig::class);
        $systemConfig->method('getConfigVersionDetail')->with(41)->willReturn([
            SystemConfigVersion::schema_fields_ID => 41,
            SystemConfigVersion::schema_fields_SCOPE => 'shop.default.default',
        ]);
        $guard = $this->createMock(BackendObjectAuthorizationGuardInterface::class);
        $guard->expects(self::once())
            ->method('requireForQuery')
            ->with(
                ObjectAction::VIEW,
                self::callback(
                    static fn(ScopeIdentity $scope): bool => $scope->websiteId === 17
                        && $scope->websiteCode === 'shop',
                ),
            )
            ->willReturn(ObjectAuthorizationResult::allow('granted', 7));
        $center = $this->createMock(SystemConfigCenterService::class);
        $center->expects(self::once())
            ->method('precheckTemplateConfigRollback')
            ->with(41, self::isType('array'))
            ->willReturn(['rollbackable' => true, 'status' => 'ready']);

        $result = $this->provider($systemConfig, $guard, $center)->execute(
            'precheckTemplateConfigRollback',
            [
                'version_id' => 41,
                'scope' => 'foreign.default.default',
            ],
        );

        self::assertTrue($result['rollbackable']);
    }

    public function testMissingAndForeignVersionUseFixedDenialBeforePrecheck(): void
    {
        $systemConfig = $this->createMock(SystemConfig::class);
        $systemConfig->method('getConfigVersionDetail')->willReturn(null);
        $guard = $this->createMock(BackendObjectAuthorizationGuardInterface::class);
        $guard->expects(self::once())
            ->method('denyForQuery')
            ->with(ObjectAction::VIEW, self::isInstanceOf(ScopeIdentity::class))
            ->willThrowException(new FrontendQueryException(
                'object_scope_access_denied',
                '操作授权条件不满足',
                403,
            ));
        $center = $this->createMock(SystemConfigCenterService::class);
        $center->expects(self::never())->method('precheckTemplateConfigRollback');

        try {
            $this->provider($systemConfig, $guard, $center)->execute(
                'precheckTemplateConfigRollback',
                ['version_id' => 999999],
            );
            self::fail('Missing version must use the fixed object denial.');
        } catch (FrontendQueryException $exception) {
            self::assertSame('object_scope_access_denied', $exception->getErrorCode());
            self::assertSame(403, $exception->getHttpStatus());
        }
    }

    public function testUnlockUsesDedicatedActionAndChecksGrantBeforeMutation(): void
    {
        $systemConfig = $this->createMock(SystemConfig::class);
        $guard = $this->createMock(BackendObjectAuthorizationGuardInterface::class);
        $guard->expects(self::once())
            ->method('requireSubmitForQuery')
            ->with(
                ObjectAction::UNLOCK,
                self::callback(
                    static fn(ScopeIdentity $scope): bool => $scope->websiteId === 17
                        && $scope->websiteCode === 'shop',
                ),
                23,
            )
            ->willReturn(ObjectAuthorizationResult::allow('granted', 23));
        $center = $this->createMock(SystemConfigCenterService::class);
        $center->expects(self::once())
            ->method('unlockScope')
            ->with(
                'Vendor_Module',
                SystemConfig::area_BACKEND,
                'vendor/module/enabled',
                'shop.default.default',
                SystemConfig::LOCALE_DEFAULT,
                self::isType('array'),
            )
            ->willReturn(['success' => true, 'status' => 'unlocked']);

        $result = $this->provider($systemConfig, $guard, $center)->execute('unlockScope', [
            'module' => 'Vendor_Module',
            'key' => 'vendor/module/enabled',
            'website_code' => 'shop',
            'expected_grant_version' => 23,
        ]);

        self::assertTrue($result['success']);
    }

    public function testDescriptorPublishesAllAuthorizationSensitiveOperations(): void
    {
        $systemConfig = $this->createMock(SystemConfig::class);
        $guard = $this->createMock(BackendObjectAuthorizationGuardInterface::class);
        $descriptor = $this->provider($systemConfig, $guard)->getDescriptor();
        $operations = array_column($descriptor['operations'], 'name');

        foreach ([
            'previewScopeLock',
            'lockScope',
            'unlockScope',
            'previewRestoreSuppressed',
            'restoreSuppressedRows',
            'discardSuppressedRows',
            'exportConfigEnvelope',
            'previewConfigImport',
            'importConfigEnvelope',
        ] as $operation) {
            self::assertContains($operation, $operations);
        }
    }

    private function provider(
        SystemConfig $systemConfig,
        BackendObjectAuthorizationGuardInterface $guard,
        ?SystemConfigCenterService $center = null,
    ): SystemConfigQueryProvider {
        return new SystemConfigQueryProvider(
            $systemConfig,
            $this->createMock(SystemConfigTemplateService::class),
            $center ?? $this->createMock(SystemConfigCenterService::class),
            new SystemConfigTargetScopeService(
                new SystemConfigScopeResolver(),
                static fn(string $code): int => $code === 'shop' ? 17 : 18,
            ),
            $guard,
        );
    }
}
