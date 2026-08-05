<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectAuthorizationResult;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Order\Extends\Module\Weline_Framework\Query\OrderAdminQueryProvider;
use Weline\Order\Model\DisplayNumberRegistry;
use Weline\Order\Service\DisplayNumberAllocator;
use Weline\Order\Service\DisplayNumberLookup;
use Weline\Order\Service\OrderFacadeConflictException;
use Weline\Order\Service\OrderObjectScopeService;

final class OrderAdminQueryProviderAuthorizationTest extends TestCase
{
    public function testLegacyOpaqueAdminRequestIsNotPublishedOrExecutable(): void
    {
        $provider = new OrderAdminQueryProvider(
            $this->createMock(BackendObjectAuthorizationGuardInterface::class),
        );
        $operations = array_column($provider->getDescriptor()['operations'], 'name');

        self::assertNotContains('adminRequest', $operations);
        self::assertSame(
            ['lookupDisplayNumber', 'saveStatus', 'deleteStatus', 'toggleStatus'],
            $operations,
        );
        foreach ($provider->getDescriptor()['operations'] as $operation) {
            self::assertNotContains('url', array_column($operation['params'], 'name'));
            self::assertNotContains('headers', array_column($operation['params'], 'name'));
            self::assertNotContains('method', array_column($operation['params'], 'name'));
        }

        $this->expectException(\InvalidArgumentException::class);
        $provider->execute('adminRequest', ['url' => '/weline_order/backend/order/save']);
    }

    public function testTypedDeleteChecksGlobalDeleteGrantBeforeControllerInvocation(): void
    {
        $guard = $this->createMock(BackendObjectAuthorizationGuardInterface::class);
        $guard->expects(self::once())
            ->method('requireSubmitForQuery')
            ->with(
                ObjectAction::DELETE,
                self::callback(static fn(ScopeIdentity $scope): bool => $scope->isGlobal()),
                0,
            )
            ->willThrowException(new FrontendQueryException(
                'object_scope_access_denied',
                '操作授权条件不满足',
                403,
            ));

        $this->expectException(FrontendQueryException::class);
        (new OrderAdminQueryProvider($guard))->execute('deleteStatus', ['id' => 9]);
    }

    public function testDisplayNumberLookupRequiresKindBeforeAuthorization(): void
    {
        $guard = $this->createMock(BackendObjectAuthorizationGuardInterface::class);
        $guard->expects(self::never())->method('requireForQuery');
        $provider = new OrderAdminQueryProvider(
            $guard,
            DisplayNumberLookup::forTesting(),
        );

        try {
            $provider->execute('lookupDisplayNumber', [
                'display_number' => '1234567890',
                'website_id' => 0,
                'store_id' => 0,
            ]);
            self::fail('bare-number query must fail');
        } catch (OrderFacadeConflictException $exception) {
            self::assertSame(
                DisplayNumberLookup::ERROR_KIND_REQUIRED,
                $exception->errorCode(),
            );
        }
    }

    public function testDisplayNumberLookupAuthorizesPersistedScopeAndReturnsQualifiedRef(): void
    {
        $allocator = DisplayNumberAllocator::forTesting();
        $allocator->seed(
            0,
            0,
            DisplayNumberRegistry::KIND_ORDER,
            '1234567890',
            'order-uuid',
        );
        $guard = $this->createMock(BackendObjectAuthorizationGuardInterface::class);
        $guard->expects(self::once())
            ->method('requireForQuery')
            ->with(
                ObjectAction::VIEW,
                self::callback(static fn (ScopeIdentity $scope): bool =>
                    $scope->scopeKind === ScopeIdentity::KIND_WEBSITE
                    && $scope->websiteId === 0
                    && $scope->websiteCode === 'default'
                ),
            )
            ->willReturn(ObjectAuthorizationResult::allow('test', 1));
        $scopeService = new OrderObjectScopeService(
            static fn (int $websiteId, int $storeId): array => [
                'website_code' => 'default',
            ],
        );
        $provider = new OrderAdminQueryProvider(
            $guard,
            new DisplayNumberLookup($allocator),
            $scopeService,
        );

        $result = $provider->execute('lookupDisplayNumber', [
            'number_kind' => DisplayNumberRegistry::KIND_ORDER,
            'display_number' => '1234567890',
            'website_id' => 0,
            'store_id' => 0,
        ]);

        self::assertTrue($result['success']);
        self::assertSame('order-uuid', $result['entity_uuid']);
        self::assertSame(DisplayNumberRegistry::KIND_ORDER, $result['number_kind']);
    }
}
