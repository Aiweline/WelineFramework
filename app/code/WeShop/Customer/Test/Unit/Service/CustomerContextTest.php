<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Model\Customer as CustomerProfile;
use WeShop\Customer\Service\CustomerContext;
use WeShop\Customer\Service\CustomerProfileService;
use WeShop\Customer\Session\CustomerSession;
use Weline\Customer\Model\Customer as AuthCustomer;
use Weline\Framework\Http\Request;

class CustomerContextTest extends TestCase
{
    public function testGetAuthUserFallsBackToRequestScopedBearerUserWhenSessionIsEmpty(): void
    {
        $session = $this->createMock(CustomerSession::class);
        $session->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $request = $this->createMock(Request::class);
        $authUser = $this->createMock(AuthCustomer::class);
        $request->expects($this->once())
            ->method('getData')
            ->with('weshop_auth_user')
            ->willReturn($authUser);

        $context = new CustomerContext(
            $session,
            $this->createMock(CustomerProfileService::class),
            $request
        );

        $this->assertSame($authUser, $context->getAuthUser());
    }

    public function testGetProfileUsesRequestScopedBearerUserIdWhenSessionIsEmpty(): void
    {
        $session = $this->createMock(CustomerSession::class);
        $session->expects($this->exactly(3))
            ->method('getUser')
            ->willReturn(null);

        $authUser = $this->createMock(AuthCustomer::class);
        $authUser->method('getId')->willReturn(15);
        $authUser->method('getEmail')->willReturn('ada@example.com');

        $request = $this->createMock(Request::class);
        $request->expects($this->exactly(3))
            ->method('getData')
            ->with('weshop_auth_user')
            ->willReturn($authUser);

        $profile = $this->createMock(CustomerProfile::class);

        $profileService = $this->createMock(CustomerProfileService::class);
        $profileService->expects($this->once())
            ->method('getByUserId')
            ->with(15)
            ->willReturn($profile);

        $context = new CustomerContext($session, $profileService, $request);

        $this->assertSame($profile, $context->getProfile());
        $this->assertSame(15, $context->getUserId());
        $this->assertSame('ada@example.com', $context->getEmail());
    }
}
