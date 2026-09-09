<?php
declare(strict_types=1);

namespace Weline\Api\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Api\Api\AuthenticatedApiUser;
use Weline\Api\Model\ApiUser;
use Weline\Api\Observer\ApiControllerInitBefore;
use Weline\Api\Service\TokenService;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\Auth\AuthenticableInterface;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

final class ApiCredentialPriorityTest extends TestCase
{
    private mixed $previousSessionFactory;

    protected function setUp(): void
    {
        $this->previousSessionFactory = (new ReflectionProperty(SessionFactory::class, 'instance'))->getValue();
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(SessionFactory::class, 'instance'))->setValue(null, $this->previousSessionFactory);
    }

    public function testExplicitBearerBindsApiUserEvenWhenFrontendSessionIsLoggedIn(): void
    {
        [$data, $eventData, $apiUser] = $this->authenticate('explicit-api-token');
        self::assertInstanceOf(AuthenticatedApiUser::class, $data['api_authenticated_user'] ?? null);
        self::assertSame(42, $data['api_authenticated_user']->getUserId());
        self::assertSame($apiUser, $eventData['user'] ?? null);
    }

    public function testWithoutExplicitCredentialsKeepsExistingFrontendSessionIdentity(): void
    {
        [$data, $eventData, , $sessionUser] = $this->authenticate(null);
        self::assertArrayNotHasKey('api_authenticated_user', $data);
        self::assertSame($sessionUser, $eventData['user'] ?? null);
    }

    private function authenticate(?string $token): array
    {
        $sessionUser = $this->createMock(AuthenticableInterface::class);
        if (method_exists($sessionUser, 'getIsEnabled')) {
            $sessionUser->method('getIsEnabled')->willReturn(true);
        }
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->method('isLoggedIn')->willReturn(true);
        $session->method('getUser')->willReturn($sessionUser);
        $factory = $this->createMock(SessionFactory::class);
        $factory->method('createFrontendSession')->willReturn($session);
        (new ReflectionProperty(SessionFactory::class, 'instance'))->setValue(null, $factory);

        $apiUser = $this->createMock(ApiUser::class);
        $apiUser->method('getId')->willReturn(42);
        $apiUser->method('getIsEnabled')->willReturn(true);
        $apiUser->method('getIsDeleted')->willReturn(false);
        $apiUser->method('isIpWhitelistEnabled')->willReturn(false);
        $apiUser->method('isUserAgentRestrictionEnabled')->willReturn(false);
        $apiUser->method('getRoleModel')->willReturn(null);
        $apiUser->method('isSandboxAccount')->willReturn(false);
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects($token === null ? self::never() : self::once())
            ->method('validateAccessToken')->with($token)->willReturn($apiUser);

        $data = [];
        $request = $this->createMock(Request::class);
        $request->method('getAuth')->willReturn($token ?? '');
        $request->method('getHeader')->willReturn('');
        $request->method('getParam')->willReturn(null);
        $request->method('getPost')->willReturn(null);
        $request->method('setData')->willReturnCallback(static function (string $key, mixed $value) use (&$data, $request) {
            $data[$key] = $value;
            return $request;
        });
        $eventData = [];
        $event = $this->createMock(Event::class);
        $event->method('setData')->willReturnCallback(static function (string $key, mixed $value) use (&$eventData, $event) {
            $eventData[$key] = $value;
            return $event;
        });
        $observer = (new ReflectionClass(ApiControllerInitBefore::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(ApiControllerInitBefore::class, 'request'))->setValue($observer, $request);
        (new ReflectionProperty(ApiControllerInitBefore::class, 'tokenService'))->setValue($observer, $tokens);
        (new ReflectionMethod(ApiControllerInitBefore::class, 'validateFrontendApi'))->invokeArgs($observer, [&$event]);
        return [$data, $eventData, $apiUser, $sessionUser];
    }
}
