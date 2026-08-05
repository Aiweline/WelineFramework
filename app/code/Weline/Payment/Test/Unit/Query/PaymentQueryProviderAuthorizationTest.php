<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectAuthorizationResult;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Payment\Extends\Module\Weline_Framework\Query\PaymentQueryProvider;
use Weline\Payment\Model\PaymentTransaction;
use Weline\Payment\Service\PaymentMethodManager;
use Weline\Payment\Service\PaymentObjectScopeService;
use Weline\Payment\Service\PaymentTransactionAccessService;

final class PaymentQueryProviderAuthorizationTest extends TestCase
{
    public function testTransactionReplayUsesPersistedScopeAndGrantVersionBeforeProviderCall(): void
    {
        $scope = ScopeIdentity::website(17, 'shop');
        $transaction = $this->createMock(PaymentTransaction::class);
        $access = $this->createMock(PaymentTransactionAccessService::class);
        $access->expects(self::once())->method('find')->with(41)->willReturn([
            'transaction' => $transaction,
            'scope' => $scope,
        ]);
        $access->expects(self::once())->method('queryStatus')->with($transaction);
        $guard = $this->createMock(BackendObjectAuthorizationGuardInterface::class);
        $guard->expects(self::once())
            ->method('requireSubmitForQuery')
            ->with(ObjectAction::REPLAY, $scope, 19)
            ->willReturn(ObjectAuthorizationResult::allow('granted', 19));

        $result = $this->provider($guard, $access)->execute('queryTransactionStatus', [
            'id' => 41,
            'scope' => 'foreign.default.default',
            'expected_grant_version' => 19,
        ]);

        self::assertTrue($result['success']);
    }

    public function testMissingTransactionUsesFixedDenialAndNeverCallsProvider(): void
    {
        $access = $this->createMock(PaymentTransactionAccessService::class);
        $access->expects(self::once())->method('find')->with(999)->willReturn(null);
        $access->expects(self::never())->method('queryStatus');
        $guard = $this->createMock(BackendObjectAuthorizationGuardInterface::class);
        $guard->expects(self::once())
            ->method('denyForQuery')
            ->with(ObjectAction::REPLAY, self::isInstanceOf(ScopeIdentity::class))
            ->willThrowException(new FrontendQueryException(
                'object_scope_access_denied',
                '操作授权条件不满足',
                403,
            ));

        $this->expectException(FrontendQueryException::class);
        $this->provider($guard, $access)->execute('queryTransactionStatus', ['id' => 999]);
    }

    public function testProviderRegistrationRequiresExplicitUpdateGrant(): void
    {
        $scope = ScopeIdentity::website(17, 'shop');
        $manager = $this->createMock(PaymentMethodManager::class);
        $manager->expects(self::once())->method('registerAllProviders')->willReturn(3);
        $access = $this->createMock(PaymentTransactionAccessService::class);
        $guard = $this->createMock(BackendObjectAuthorizationGuardInterface::class);
        $guard->expects(self::once())
            ->method('requireSubmitForQuery')
            ->with(ObjectAction::UPDATE, $scope, 7)
            ->willReturn(ObjectAuthorizationResult::allow('granted', 7));

        $result = $this->provider($guard, $access, $manager)->execute('registerProviders', [
            'target_scope' => 'shop.default.default',
            'expected_grant_version' => 7,
        ]);

        self::assertSame(3, $result['providers_registered']);
    }

    private function provider(
        BackendObjectAuthorizationGuardInterface $guard,
        PaymentTransactionAccessService $access,
        ?PaymentMethodManager $manager = null,
    ): PaymentQueryProvider {
        return new PaymentQueryProvider(
            $manager ?? $this->createMock(PaymentMethodManager::class),
            $this->createMock(ObjectManager::class),
            new PaymentObjectScopeService(
                static fn(string $code): int => $code === 'shop' ? 17 : 0,
            ),
            $access,
            $guard,
        );
    }
}
