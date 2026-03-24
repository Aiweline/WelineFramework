<?php

declare(strict_types=1);

namespace WeShop\Auth\Test\Unit\Api\Rest\V1;

use PHPUnit\Framework\TestCase;
use WeShop\Auth\Api\Rest\V1\Auth;
use WeShop\Auth\Service\AuthGrantService;
use Weline\Framework\Http\Request;

class AuthControllerTest extends TestCase
{
    public function testPostTokenUsesPasswordGrantWithFrontendDefaults(): void
    {
        $authGrantService = $this->createMock(AuthGrantService::class);
        $authGrantService->expects($this->once())
            ->method('issuePasswordToken')
            ->with('frontend', 'buyer@example.com', 'secret-123')
            ->willReturn([
                'status' => 'authenticated',
                'actor_type' => 'customer',
                'access_token' => 'access-token-1',
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')->willReturnCallback(static function (string $key) {
            return match ($key) {
                'grant_type' => 'password',
                'email' => 'buyer@example.com',
                'password' => 'secret-123',
                default => null,
            };
        });
        $request->method('getPost')->willReturn(null);

        $controller = $this->getMockBuilder(Auth::class)
            ->setConstructorArgs([$authGrantService])
            ->onlyMethods(['success', 'exception'])
            ->getMock();
        $controller->expects($this->once())
            ->method('success')
            ->with(
                $this->stringContains('Authentication succeeded'),
                $this->callback(static fn (array $data): bool => ($data['access_token'] ?? '') === 'access-token-1')
            )
            ->willReturn('token-password-ok');
        $controller->expects($this->never())->method('exception');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('token-password-ok', $controller->postToken());
    }

    public function testPostTokenUsesGoogleCodeGrant(): void
    {
        $authGrantService = $this->createMock(AuthGrantService::class);
        $authGrantService->expects($this->once())
            ->method('issueGoogleCodeToken')
            ->with('backend', 'google-code-1')
            ->willReturn([
                'status' => 'challenge_required',
                'actor_type' => 'backend',
                'challenge_token' => 'challenge-1',
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')->willReturnCallback(static function (string $key) {
            return match ($key) {
                'grant_type' => 'google_code',
                'area' => 'backend',
                'code' => 'google-code-1',
                default => null,
            };
        });
        $request->method('getPost')->willReturn(null);

        $controller = $this->getMockBuilder(Auth::class)
            ->setConstructorArgs([$authGrantService])
            ->onlyMethods(['success', 'exception'])
            ->getMock();
        $controller->expects($this->once())
            ->method('success')
            ->with(
                $this->stringContains('Authentication succeeded'),
                $this->callback(static fn (array $data): bool => ($data['challenge_token'] ?? '') === 'challenge-1')
            )
            ->willReturn('token-google-ok');
        $controller->expects($this->never())->method('exception');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('token-google-ok', $controller->postToken());
    }

    public function testPostTokenUsesApiCredentialsGrant(): void
    {
        $authGrantService = $this->createMock(AuthGrantService::class);
        $authGrantService->expects($this->once())
            ->method('issueApiCredentialsToken')
            ->with('api-key-1', 'api-secret-1')
            ->willReturn([
                'status' => 'authenticated',
                'actor_type' => 'integration',
                'access_token' => 'access-token-api',
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')->willReturnCallback(static function (string $key) {
            return match ($key) {
                'grant_type' => 'api_credentials',
                'api_key' => 'api-key-1',
                'api_secret' => 'api-secret-1',
                default => null,
            };
        });
        $request->method('getPost')->willReturn(null);

        $controller = $this->getMockBuilder(Auth::class)
            ->setConstructorArgs([$authGrantService])
            ->onlyMethods(['success', 'exception'])
            ->getMock();
        $controller->expects($this->once())
            ->method('success')
            ->with(
                $this->stringContains('Authentication succeeded'),
                $this->callback(static fn (array $data): bool => ($data['actor_type'] ?? '') === 'integration')
            )
            ->willReturn('token-api-ok');
        $controller->expects($this->never())->method('exception');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('token-api-ok', $controller->postToken());
    }

    public function testPostTokenRejectsUnsupportedGrantType(): void
    {
        $authGrantService = $this->createMock(AuthGrantService::class);
        $authGrantService->expects($this->never())->method('issuePasswordToken');
        $authGrantService->expects($this->never())->method('issueGoogleCodeToken');
        $authGrantService->expects($this->never())->method('refreshToken');
        $authGrantService->expects($this->never())->method('issueApiCredentialsToken');

        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')->willReturnCallback(static function (string $key) {
            return $key === 'grant_type' ? 'magic' : null;
        });
        $request->method('getPost')->willReturn(null);

        $controller = $this->getMockBuilder(Auth::class)
            ->setConstructorArgs([$authGrantService])
            ->onlyMethods(['success', 'exception'])
            ->getMock();
        $controller->expects($this->never())->method('success');
        $controller->expects($this->once())
            ->method('exception')
            ->with(
                $this->callback(static fn (\Throwable $throwable): bool => $throwable instanceof \InvalidArgumentException),
                $this->stringContains('Authentication failed')
            )
            ->willReturn('token-error');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('token-error', $controller->postToken());
    }

    public function testPostTokenRejectsMissingPasswordGrantCredentials(): void
    {
        $authGrantService = $this->createMock(AuthGrantService::class);
        $authGrantService->expects($this->never())->method('issuePasswordToken');
        $authGrantService->expects($this->never())->method('issueGoogleCodeToken');
        $authGrantService->expects($this->never())->method('refreshToken');
        $authGrantService->expects($this->never())->method('issueApiCredentialsToken');

        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')->willReturnCallback(static function (string $key) {
            return match ($key) {
                'grant_type' => 'password',
                default => null,
            };
        });
        $request->method('getPost')->willReturn(null);

        $controller = $this->getMockBuilder(Auth::class)
            ->setConstructorArgs([$authGrantService])
            ->onlyMethods(['success', 'exception'])
            ->getMock();
        $controller->expects($this->never())->method('success');
        $controller->expects($this->once())
            ->method('exception')
            ->with(
                $this->callback(static fn (\Throwable $throwable): bool => $throwable instanceof \InvalidArgumentException
                    && str_contains($throwable->getMessage(), 'Username or email and password are required.')),
                $this->stringContains('Authentication failed')
            )
            ->willReturn('token-missing-credentials');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('token-missing-credentials', $controller->postToken());
    }

    public function testPostRefreshUsesRefreshGrantWithoutGrantType(): void
    {
        $authGrantService = $this->createMock(AuthGrantService::class);
        $authGrantService->expects($this->once())
            ->method('refreshToken')
            ->with('refresh-token-123')
            ->willReturn([
                'status' => 'authenticated',
                'access_token' => 'access-token-123',
            ]);
        $authGrantService->expects($this->never())->method('issuePasswordToken');

        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')->willReturnCallback(static function (string $key) {
            return match ($key) {
                'refresh_token' => 'refresh-token-123',
                default => null,
            };
        });
        $request->method('getPost')->willReturn(null);

        $controller = $this->getMockBuilder(Auth::class)
            ->setConstructorArgs([$authGrantService])
            ->onlyMethods(['success', 'exception'])
            ->getMock();
        $controller->expects($this->once())
            ->method('success')
            ->with(
                $this->stringContains('Authentication succeeded'),
                $this->callback(static fn (array $data): bool => ($data['access_token'] ?? '') === 'access-token-123')
            )
            ->willReturn('refresh-ok');
        $controller->expects($this->never())->method('exception');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('refresh-ok', $controller->postRefresh());
    }

    public function testPostLoginForcesPasswordGrantEvenWhenGrantTypeConflicts(): void
    {
        $authGrantService = $this->createMock(AuthGrantService::class);
        $authGrantService->expects($this->once())
            ->method('issuePasswordToken')
            ->with('backend', 'admin@example.com', 'secret-123')
            ->willReturn([
                'status' => 'authenticated',
                'actor_type' => 'backend',
            ]);
        $authGrantService->expects($this->never())->method('refreshToken');

        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')->willReturnCallback(static function (string $key) {
            return match ($key) {
                'grant_type' => 'refresh_token',
                'area' => 'backend',
                'email' => 'admin@example.com',
                'password' => 'secret-123',
                default => null,
            };
        });
        $request->method('getPost')->willReturn(null);

        $controller = $this->getMockBuilder(Auth::class)
            ->setConstructorArgs([$authGrantService])
            ->onlyMethods(['success', 'exception'])
            ->getMock();
        $controller->expects($this->once())
            ->method('success')
            ->with(
                $this->stringContains('Authentication succeeded'),
                $this->callback(static fn (array $data): bool => ($data['actor_type'] ?? '') === 'backend')
            )
            ->willReturn('login-ok');
        $controller->expects($this->never())->method('exception');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('login-ok', $controller->postLogin());
    }

    public function testGetMeFallsBackToApiTokenHeader(): void
    {
        $authGrantService = $this->createMock(AuthGrantService::class);
        $authGrantService->expects($this->once())
            ->method('resolveMe')
            ->with('header-token-001')
            ->willReturn([
                'status' => 'authenticated',
                'actor_type' => 'customer',
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getAuth')->with('bearer')->willReturn('');
        $request->method('getHeader')->willReturnCallback(static function (string $key) {
            return $key === 'X-API-Token' ? 'header-token-001' : null;
        });
        $request->method('getParam')->willReturn(null);
        $request->method('getBodyParam')->willReturn(null);
        $request->method('getPost')->willReturn(null);

        $controller = $this->getMockBuilder(Auth::class)
            ->setConstructorArgs([$authGrantService])
            ->onlyMethods(['success', 'exception'])
            ->getMock();
        $controller->expects($this->once())
            ->method('success')
            ->with(
                $this->stringContains('Current actor resolved'),
                $this->callback(static fn (array $data): bool => ($data['actor_type'] ?? '') === 'customer')
            )
            ->willReturn('me-ok');
        $controller->expects($this->never())->method('exception');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('me-ok', $controller->getMe());
    }

    public function testPostLogoutUsesBearerToken(): void
    {
        $authGrantService = $this->createMock(AuthGrantService::class);
        $authGrantService->expects($this->once())
            ->method('logout')
            ->with('bearer-token-xyz')
            ->willReturn(true);

        $request = $this->createMock(Request::class);
        $request->method('getAuth')->with('bearer')->willReturn('bearer-token-xyz');
        $request->method('getHeader')->willReturn(null);
        $request->method('getParam')->willReturn(null);
        $request->method('getBodyParam')->willReturn(null);
        $request->method('getPost')->willReturn(null);

        $controller = $this->getMockBuilder(Auth::class)
            ->setConstructorArgs([$authGrantService])
            ->onlyMethods(['success', 'exception'])
            ->getMock();
        $controller->expects($this->once())
            ->method('success')
            ->with(
                $this->stringContains('Logout succeeded'),
                $this->callback(static fn (array $data): bool => ($data['status'] ?? '') === 'logged_out')
            )
            ->willReturn('logout-ok');
        $controller->expects($this->never())->method('exception');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('logout-ok', $controller->postLogout());
    }

    public function testPostExchangeRequiresChallengeTokenAndCode(): void
    {
        $authGrantService = $this->createMock(AuthGrantService::class);
        $authGrantService->expects($this->never())->method('verifyChallenge');

        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')->willReturn(null);
        $request->method('getPost')->willReturn(null);

        $controller = $this->getMockBuilder(Auth::class)
            ->setConstructorArgs([$authGrantService])
            ->onlyMethods(['success', 'exception'])
            ->getMock();
        $controller->expects($this->never())->method('success');
        $controller->expects($this->once())
            ->method('exception')
            ->with(
                $this->callback(static fn (\Throwable $throwable): bool => $throwable instanceof \InvalidArgumentException),
                $this->stringContains('Challenge verification failed')
            )
            ->willReturn('exchange-error');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('exchange-error', $controller->postExchange());
    }

    public function testPostExchangeUsesChallengeGrantService(): void
    {
        $authGrantService = $this->createMock(AuthGrantService::class);
        $authGrantService->expects($this->once())
            ->method('verifyChallenge')
            ->with('challenge-token-1', '123456')
            ->willReturn([
                'status' => 'authenticated',
                'access_token' => 'access-token-1',
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')->willReturnCallback(static function (string $key) {
            return match ($key) {
                'challenge_token' => 'challenge-token-1',
                'code' => '123456',
                default => null,
            };
        });
        $request->method('getPost')->willReturn(null);

        $controller = $this->getMockBuilder(Auth::class)
            ->setConstructorArgs([$authGrantService])
            ->onlyMethods(['success', 'exception'])
            ->getMock();
        $controller->expects($this->once())
            ->method('success')
            ->with(
                $this->stringContains('Challenge verification succeeded'),
                $this->callback(static fn (array $data): bool => ($data['access_token'] ?? '') === 'access-token-1')
            )
            ->willReturn('exchange-ok');
        $controller->expects($this->never())->method('exception');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('exchange-ok', $controller->postExchange());
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }

        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($target, $value);
    }
}
