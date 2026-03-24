<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Auth\Data\ActorContext;
use WeShop\Auth\Service\PendingAuthChallengeService;
use WeShop\Auth\Service\WeShopAuth2FAOrchestrator;
use WeShop\Customer\Service\CustomerAccountService;
use WeShop\Customer\Service\CustomerWebAuthService;
use Weline\Customer\Model\Customer as AuthCustomer;

class CustomerWebAuthServiceTest extends TestCase
{
    public function testBeginLoginForAuthUserPassesRememberDurationThroughPrimaryAuthAndLogin(): void
    {
        $authUser = $this->getMockBuilder(AuthCustomer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $authUser->method('getId')->willReturn(42);

        $customerAccountService = $this->createMock(CustomerAccountService::class);
        $customerAccountService->expects($this->once())
            ->method('login')
            ->with($authUser, true, 21600);

        $twoFactorOrchestrator = $this->createMock(WeShopAuth2FAOrchestrator::class);
        $twoFactorOrchestrator->expects($this->once())
            ->method('beginPrimaryAuth')
            ->with(
                $this->callback(static function (ActorContext $context): bool {
                    return $context->getActorType() === ActorContext::ACTOR_CUSTOMER
                        && $context->getActorId() === 42
                        && $context->getArea() === 'frontend';
                }),
                'password',
                'frontend',
                $this->callback(static function (array $payload): bool {
                    return ($payload['flow'] ?? null) === 'password'
                        && ($payload['remember_me'] ?? null) === true
                        && ($payload['remember_duration'] ?? null) === 21600
                        && ($payload['redirect_url'] ?? null) === 'sales/order/view?id=8';
                })
            )
            ->willReturn([
                'status' => 'authenticated',
            ]);

        $service = new CustomerWebAuthService(
            $customerAccountService,
            $twoFactorOrchestrator,
            $this->createMock(PendingAuthChallengeService::class)
        );

        $result = $service->beginLoginForAuthUser(
            $authUser,
            'password',
            true,
            'sales/order/view?id=8',
            21600
        );

        $this->assertSame('authenticated', $result['status']);
        $this->assertSame('sales/order/view?id=8', $result['redirect_url']);
    }

    public function testCompleteChallengeUsesRememberDurationFromStoredPayload(): void
    {
        $authUser = $this->getMockBuilder(AuthCustomer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $authUser->method('getId')->willReturn(42);

        $customerAccountService = $this->createMock(CustomerAccountService::class);
        $customerAccountService->expects($this->once())
            ->method('getAuthUserById')
            ->with(42)
            ->willReturn($authUser);
        $customerAccountService->expects($this->once())
            ->method('login')
            ->with($authUser, true, 2592000);

        $twoFactorOrchestrator = $this->createMock(WeShopAuth2FAOrchestrator::class);
        $twoFactorOrchestrator->expects($this->once())
            ->method('verifyChallenge')
            ->with('challenge-token', '123456')
            ->willReturn([
                'context' => new ActorContext(ActorContext::ACTOR_CUSTOMER, 42, 'frontend', ['customer'], true),
                'payload' => [
                    'remember_me' => true,
                    'remember_duration' => 2592000,
                    'redirect_url' => 'customer/account',
                ],
            ]);

        $service = new CustomerWebAuthService(
            $customerAccountService,
            $twoFactorOrchestrator,
            $this->createMock(PendingAuthChallengeService::class)
        );

        $result = $service->completeChallenge('challenge-token', '123456');

        $this->assertSame('authenticated', $result['status']);
        $this->assertSame('customer/account', $result['redirect_url']);
    }
}
