<?php

declare(strict_types=1);

namespace WeShop\Auth\Test\Unit\Controller\Frontend\Auth;

use PHPUnit\Framework\TestCase;
use WeShop\Auth\Controller\Frontend\Auth\TwoFactor;
use WeShop\Auth\Service\TwoFactorAccountService;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Http\Url;

class TwoFactorTest extends TestCase
{
    public function testIndexRedirectsGuestsToLogin(): void
    {
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('isLoggedIn')
            ->willReturn(false);

        $controller = $this->getMockBuilder(TwoFactor::class)
            ->setConstructorArgs([
                $customerSession,
                $this->createMock(CustomerContextInterface::class),
                $this->createMock(TwoFactorAccountService::class),
                $this->createMock(Url::class),
            ])
            ->onlyMethods(['redirect'])
            ->getMock();

        $controller->expects($this->once())
            ->method('redirect')
            ->with('weshop/customer/account/login', ['redirect' => 'weshop/frontend/auth/two-factor']);

        $this->assertSame('', $controller->index());
    }

    public function testIndexAssignsEnabledConfigurationForLoggedInCustomer(): void
    {
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('isLoggedIn')
            ->willReturn(true);
        $customerSession->expects($this->once())
            ->method('get')
            ->with('weshop_auth_frontend_2fa_backup_codes')
            ->willReturn(['code-1']);
        $customerSession->expects($this->once())
            ->method('delete')
            ->with('weshop_auth_frontend_2fa_backup_codes');

        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(55);
        $customerContext->expects($this->once())
            ->method('getEmail')
            ->willReturn('shopper@example.com');

        $twoFactorAccountService = $this->createMock(TwoFactorAccountService::class);
        $twoFactorAccountService->expects($this->once())
            ->method('getFlowStatus')
            ->with('frontend')
            ->willReturn(['password' => false, 'google' => true]);
        $twoFactorAccountService->expects($this->once())
            ->method('isEnabled')
            ->with('customer', 55)
            ->willReturn(true);
        $twoFactorAccountService->expects($this->once())
            ->method('getUserConfig')
            ->with('customer', 55)
            ->willReturn(['backup_codes_count' => 7]);

        $url = $this->createMock(Url::class);
        $url->expects($this->exactly(2))
            ->method('getFrontendUrl')
            ->willReturnMap([
                ['weshop/frontend/auth/two-factor', [], false, 'https://example.com/account/2fa'],
                ['weshop/customer/account/index', [], false, 'https://example.com/account'],
            ]);

        $controller = $this->getMockBuilder(TwoFactor::class)
            ->setConstructorArgs([
                $customerSession,
                $customerContext,
                $twoFactorAccountService,
                $url,
            ])
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();

        $controller->expects($this->exactly(7))
            ->method('assign');
        $controller->expects($this->once())
            ->method('fetch')
            ->with('WeShop_Auth::templates/Frontend/Auth/two-factor.phtml')
            ->willReturn('page');

        $this->assertSame('page', $controller->index());
        $this->addToAssertionCount(1);
    }
}
