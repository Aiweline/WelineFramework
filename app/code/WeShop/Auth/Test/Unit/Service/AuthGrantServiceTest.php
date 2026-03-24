<?php

declare(strict_types=1);

namespace WeShop\Auth\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Auth\Data\ActorContext;
use WeShop\Auth\Service\AuthGrantService;
use WeShop\Auth\Service\BackendPasswordAuthenticator;
use WeShop\Auth\Service\GoogleCodeAuthenticator;
use WeShop\Auth\Service\IntegrationCredentialAuthenticator;
use WeShop\Auth\Service\WeShopAuth2FAOrchestrator;
use WeShop\Auth\Service\WeShopAuthTokenService;
use WeShop\Customer\Service\CustomerAccountService;
use Weline\Api\Model\ApiUser;
use Weline\Backend\Model\BackendUser;

class AuthGrantServiceTest extends TestCase
{
    public function testIssuePasswordTokenDelegatesBackendCredentialVerification(): void
    {
        $customerAccountService = $this->createMock(CustomerAccountService::class);
        $backendAuthenticator = $this->createMock(BackendPasswordAuthenticator::class);
        $googleCodeAuthenticator = $this->createMock(GoogleCodeAuthenticator::class);
        $integrationAuthenticator = $this->createMock(IntegrationCredentialAuthenticator::class);
        $twoFactorOrchestrator = $this->createMock(WeShopAuth2FAOrchestrator::class);
        $tokenService = $this->createMock(WeShopAuthTokenService::class);

        $backendUser = $this->getMockBuilder(BackendUser::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $backendUser->method('getId')->willReturn(42);

        $backendAuthenticator->expects($this->once())
            ->method('authenticate')
            ->with('admin@example.com', 'secret-123')
            ->willReturn($backendUser);

        $twoFactorOrchestrator->expects($this->once())
            ->method('beginPrimaryAuth')
            ->with(
                $this->callback(static function (ActorContext $context): bool {
                    return $context->getActorType() === ActorContext::ACTOR_BACKEND
                        && $context->getActorId() === 42
                        && $context->getArea() === 'backend'
                        && $context->getScopes() === ['backend']
                        && !$context->is2faVerified();
                }),
                'password',
                'backend',
                ['flow' => 'password']
            )
            ->willReturn(['status' => 'authenticated']);

        $tokenService->expects($this->once())
            ->method('createTokenPair')
            ->with($this->callback(static function (ActorContext $context): bool {
                return $context->getActorType() === ActorContext::ACTOR_BACKEND
                    && $context->getActorId() === 42
                    && $context->is2faVerified();
            }))
            ->willReturn([
                'access_token' => 'access-token-42',
                'refresh_token' => 'refresh-token-42',
                'expires_at' => '2026-03-24 00:00:00',
            ]);

        $service = new AuthGrantService(
            $customerAccountService,
            $backendAuthenticator,
            $googleCodeAuthenticator,
            $integrationAuthenticator,
            $twoFactorOrchestrator,
            $tokenService
        );

        $result = $service->issuePasswordToken('backend', 'admin@example.com', 'secret-123');

        $this->assertSame('authenticated', $result['status'] ?? null);
        $this->assertSame('backend', $result['actor_type'] ?? null);
        $this->assertSame('access-token-42', $result['access_token'] ?? null);
        $this->assertSame('backend', $result['actor']['area'] ?? null);
        $this->assertTrue((bool) ($result['actor']['is_2fa_verified'] ?? false));
    }

    public function testIssueApiCredentialsTokenDelegatesIntegrationCredentialVerification(): void
    {
        $customerAccountService = $this->createMock(CustomerAccountService::class);
        $backendAuthenticator = $this->createMock(BackendPasswordAuthenticator::class);
        $googleCodeAuthenticator = $this->createMock(GoogleCodeAuthenticator::class);
        $integrationAuthenticator = $this->createMock(IntegrationCredentialAuthenticator::class);
        $twoFactorOrchestrator = $this->createMock(WeShopAuth2FAOrchestrator::class);
        $tokenService = $this->createMock(WeShopAuthTokenService::class);

        $apiUser = $this->getMockBuilder(ApiUser::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $apiUser->method('getId')->willReturn(88);

        $integrationAuthenticator->expects($this->once())
            ->method('authenticate')
            ->with('api-key-1', 'api-secret-1')
            ->willReturn($apiUser);

        $twoFactorOrchestrator->expects($this->never())->method('beginPrimaryAuth');

        $tokenService->expects($this->once())
            ->method('createTokenPair')
            ->with($this->callback(static function (ActorContext $context): bool {
                return $context->getActorType() === ActorContext::ACTOR_INTEGRATION
                    && $context->getActorId() === 88
                    && $context->getArea() === 'integration'
                    && $context->getScopes() === ['integration']
                    && $context->is2faVerified();
            }))
            ->willReturn([
                'access_token' => 'access-token-88',
                'refresh_token' => 'refresh-token-88',
                'expires_at' => '2026-03-24 00:00:00',
            ]);

        $service = new AuthGrantService(
            $customerAccountService,
            $backendAuthenticator,
            $googleCodeAuthenticator,
            $integrationAuthenticator,
            $twoFactorOrchestrator,
            $tokenService
        );

        $result = $service->issueApiCredentialsToken('api-key-1', 'api-secret-1');

        $this->assertSame('authenticated', $result['status'] ?? null);
        $this->assertSame('integration', $result['actor_type'] ?? null);
        $this->assertSame('access-token-88', $result['access_token'] ?? null);
        $this->assertSame('integration', $result['actor']['area'] ?? null);
        $this->assertTrue((bool) ($result['actor']['is_2fa_verified'] ?? false));
    }

    public function testIssueGoogleCodeTokenDelegatesGoogleAuthenticator(): void
    {
        $customerAccountService = $this->createMock(CustomerAccountService::class);
        $backendAuthenticator = $this->createMock(BackendPasswordAuthenticator::class);
        $googleCodeAuthenticator = $this->createMock(GoogleCodeAuthenticator::class);
        $integrationAuthenticator = $this->createMock(IntegrationCredentialAuthenticator::class);
        $twoFactorOrchestrator = $this->createMock(WeShopAuth2FAOrchestrator::class);
        $tokenService = $this->createMock(WeShopAuthTokenService::class);

        $googleCodeAuthenticator->expects($this->once())
            ->method('authenticate')
            ->with('backend', 'google-code-1')
            ->willReturn(new ActorContext(
                ActorContext::ACTOR_BACKEND,
                51,
                'backend',
                ['backend']
            ));

        $twoFactorOrchestrator->expects($this->once())
            ->method('beginPrimaryAuth')
            ->with(
                $this->callback(static function (ActorContext $context): bool {
                    return $context->getActorType() === ActorContext::ACTOR_BACKEND
                        && $context->getActorId() === 51
                        && $context->getArea() === 'backend'
                        && $context->getScopes() === ['backend']
                        && !$context->is2faVerified();
                }),
                'google',
                'backend',
                ['flow' => 'google']
            )
            ->willReturn(['status' => 'authenticated']);

        $tokenService->expects($this->once())
            ->method('createTokenPair')
            ->with($this->callback(static function (ActorContext $context): bool {
                return $context->getActorType() === ActorContext::ACTOR_BACKEND
                    && $context->getActorId() === 51
                    && $context->is2faVerified();
            }))
            ->willReturn([
                'access_token' => 'google-access-token-51',
                'refresh_token' => 'google-refresh-token-51',
                'expires_at' => '2026-03-24 00:00:00',
            ]);

        $service = new AuthGrantService(
            $customerAccountService,
            $backendAuthenticator,
            $googleCodeAuthenticator,
            $integrationAuthenticator,
            $twoFactorOrchestrator,
            $tokenService
        );

        $result = $service->issueGoogleCodeToken('backend', 'google-code-1');

        $this->assertSame('authenticated', $result['status'] ?? null);
        $this->assertSame('backend', $result['actor_type'] ?? null);
        $this->assertSame('google-access-token-51', $result['access_token'] ?? null);
        $this->assertSame('backend', $result['actor']['area'] ?? null);
        $this->assertTrue((bool) ($result['actor']['is_2fa_verified'] ?? false));
    }

    public function testIssuePasswordTokenRejectsMissingCredentialsBeforeAuthenticating(): void
    {
        $customerAccountService = $this->createMock(CustomerAccountService::class);
        $customerAccountService->expects($this->never())->method('authenticate');

        $service = new AuthGrantService(
            $customerAccountService,
            $this->createMock(BackendPasswordAuthenticator::class),
            $this->createMock(GoogleCodeAuthenticator::class),
            $this->createMock(IntegrationCredentialAuthenticator::class),
            $this->createMock(WeShopAuth2FAOrchestrator::class),
            $this->createMock(WeShopAuthTokenService::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Username or email and password are required.');

        $service->issuePasswordToken('frontend', '', '');
    }

    public function testIssueGoogleCodeTokenRejectsMissingCode(): void
    {
        $service = new AuthGrantService(
            $this->createMock(CustomerAccountService::class),
            $this->createMock(BackendPasswordAuthenticator::class),
            $this->createMock(GoogleCodeAuthenticator::class),
            $this->createMock(IntegrationCredentialAuthenticator::class),
            $this->createMock(WeShopAuth2FAOrchestrator::class),
            $this->createMock(WeShopAuthTokenService::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Google authorization code is required.');

        $service->issueGoogleCodeToken('frontend', '');
    }

    public function testIssueApiCredentialsTokenRejectsMissingCredentials(): void
    {
        $service = new AuthGrantService(
            $this->createMock(CustomerAccountService::class),
            $this->createMock(BackendPasswordAuthenticator::class),
            $this->createMock(GoogleCodeAuthenticator::class),
            $this->createMock(IntegrationCredentialAuthenticator::class),
            $this->createMock(WeShopAuth2FAOrchestrator::class),
            $this->createMock(WeShopAuthTokenService::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API key and secret are required.');

        $service->issueApiCredentialsToken('', '');
    }

    public function testRefreshTokenRejectsMissingRefreshToken(): void
    {
        $service = new AuthGrantService(
            $this->createMock(CustomerAccountService::class),
            $this->createMock(BackendPasswordAuthenticator::class),
            $this->createMock(GoogleCodeAuthenticator::class),
            $this->createMock(IntegrationCredentialAuthenticator::class),
            $this->createMock(WeShopAuth2FAOrchestrator::class),
            $this->createMock(WeShopAuthTokenService::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refresh token is required.');

        $service->refreshToken('');
    }
}
