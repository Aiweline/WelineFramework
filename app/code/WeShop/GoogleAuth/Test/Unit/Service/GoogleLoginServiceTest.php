<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Service\CustomerAccountService;
use WeShop\Customer\Service\CustomerProfileService;
use WeShop\GoogleAuth\Service\GoogleBindingService;
use WeShop\GoogleAuth\Service\GoogleLoginService;
use WeShop\GoogleAuth\Service\GoogleOAuthService;
use Weline\Backend\Model\BackendUser;
use Weline\Customer\Model\Customer as AuthCustomer;

class GoogleLoginServiceTest extends TestCase
{
    public function testBindByCodeRequiresLocalUserId(): void
    {
        $service = new GoogleLoginService(
            $this->createMock(GoogleOAuthService::class),
            $this->createMock(GoogleBindingService::class),
            $this->createMock(CustomerAccountService::class),
            $this->createMock(CustomerProfileService::class),
            $this->createMock(AuthCustomer::class),
            $this->createMock(BackendUser::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('local user');

        $service->bindByCode('frontend', 0, 'test-code');
    }

    public function testAuthenticateByCodeForBackendRequiresExistingBinding(): void
    {
        $googleOAuthService = $this->createMock(GoogleOAuthService::class);
        $googleOAuthService->expects($this->once())
            ->method('fetchGoogleUser')
            ->with('test-code')
            ->willReturn([
                'sub' => 'google-subject',
                'email' => 'admin@example.com',
            ]);

        $googleBindingService = $this->createMock(GoogleBindingService::class);
        $googleBindingService->expects($this->once())
            ->method('getByGoogleSubject')
            ->with('backend', 'google-subject')
            ->willReturn(null);

        $service = new GoogleLoginService(
            $googleOAuthService,
            $googleBindingService,
            $this->createMock(CustomerAccountService::class),
            $this->createMock(CustomerProfileService::class),
            $this->createMock(AuthCustomer::class),
            $this->createMock(BackendUser::class)
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not bound');

        $service->authenticateByCode('backend', 'test-code');
    }
}
