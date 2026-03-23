<?php

declare(strict_types=1);

namespace Weline\Api\test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Api\Observer\ApiControllerInitBefore;
use Weline\Api\Service\ApiSecurityService;
use Weline\Api\Service\IpWhitelistService;
use Weline\Api\Service\TokenService;
use Weline\Api\Service\UserAgentRestrictionService;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\PublicApiAuthRouteMatcher;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class ApiControllerInitBeforeTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::removeInstance(Request::class);
        parent::tearDown();
    }

    public function testExecuteAllowsWeShopTokenRouteWithApiPrefix(): void
    {
        $request = $this->createRequestMock(
            'api/weshop/rest/v1/auth/token',
            'Auth',
            'postToken',
            'WeShop\\Auth\\Api\\Rest\\V1\\Auth'
        );
        ObjectManager::setInstance(Request::class, $request);

        $apiSecurityService = $this->createMock(ApiSecurityService::class);
        $apiSecurityService->expects($this->never())->method('isPublicApi');

        $ipWhitelistService = $this->createMock(IpWhitelistService::class);
        $userAgentRestrictionService = $this->createMock(UserAgentRestrictionService::class);
        $tokenService = $this->createMock(TokenService::class);

        $observer = new ApiControllerInitBefore(
            $request,
            $apiSecurityService,
            $ipWhitelistService,
            $userAgentRestrictionService,
            $tokenService,
            new PublicApiAuthRouteMatcher()
        );

        $event = new Event(['data' => []]);
        $observer->execute($event);

        $this->assertTrue(true);
    }

    public function testExecuteAllowsWeShopChallengeVerifyRouteWithApiPrefix(): void
    {
        $request = $this->createRequestMock(
            'api/weshop/rest/v1/auth/challenge/verify',
            'Challenge',
            'postVerify',
            'WeShop\\Auth\\Api\\Rest\\V1\\Auth\\Challenge'
        );
        ObjectManager::setInstance(Request::class, $request);

        $apiSecurityService = $this->createMock(ApiSecurityService::class);
        $apiSecurityService->expects($this->never())->method('isPublicApi');

        $ipWhitelistService = $this->createMock(IpWhitelistService::class);
        $userAgentRestrictionService = $this->createMock(UserAgentRestrictionService::class);
        $tokenService = $this->createMock(TokenService::class);

        $observer = new ApiControllerInitBefore(
            $request,
            $apiSecurityService,
            $ipWhitelistService,
            $userAgentRestrictionService,
            $tokenService,
            new PublicApiAuthRouteMatcher()
        );

        $event = new Event(['data' => []]);
        $observer->execute($event);

        $this->assertTrue(true);
    }

    public function testExecuteAllowsAuthPostTokenActionByControllerName(): void
    {
        $request = $this->createRequestMock(
            'non-whitelisted/path',
            'Auth',
            'postToken',
            'WeShop\\Auth\\Api\\Rest\\V1\\Auth'
        );
        ObjectManager::setInstance(Request::class, $request);

        $apiSecurityService = $this->createMock(ApiSecurityService::class);
        $apiSecurityService->expects($this->never())->method('isPublicApi');

        $observer = new ApiControllerInitBefore(
            $request,
            $apiSecurityService,
            $this->createMock(IpWhitelistService::class),
            $this->createMock(UserAgentRestrictionService::class),
            $this->createMock(TokenService::class),
            new PublicApiAuthRouteMatcher()
        );

        $event = new Event(['data' => []]);
        $observer->execute($event);

        $this->assertTrue(true);
    }

    public function testExecuteAllowsAuthPostVerifyActionByControllerClass(): void
    {
        $request = $this->createRequestMock(
            'another/non-whitelisted/path',
            'Challenge',
            'postVerify',
            'WeShop\\Auth\\Api\\Rest\\V1\\Auth\\Challenge'
        );
        ObjectManager::setInstance(Request::class, $request);

        $apiSecurityService = $this->createMock(ApiSecurityService::class);
        $apiSecurityService->expects($this->never())->method('isPublicApi');

        $observer = new ApiControllerInitBefore(
            $request,
            $apiSecurityService,
            $this->createMock(IpWhitelistService::class),
            $this->createMock(UserAgentRestrictionService::class),
            $this->createMock(TokenService::class),
            new PublicApiAuthRouteMatcher()
        );

        $event = new Event(['data' => []]);
        $observer->execute($event);

        $this->assertTrue(true);
    }

    private function createRequestMock(string $routeUrlPath, string $controller, string $action, string $controllerClass): Request
    {
        return new class($routeUrlPath, $controller, $action, $controllerClass) extends Request {
            public function __construct(
                private readonly string $routeUrlPathValue,
                private readonly string $controllerValue,
                private readonly string $actionValue,
                private readonly string $controllerClassValue
            ) {
            }

            public function isApiBackend(): bool
            {
                return false;
            }

            public function isApiFrontend(): bool
            {
                return true;
            }

            public function getRouteUrlPath(string $url = ''): string
            {
                return $this->routeUrlPathValue;
            }

            public function getPath(): string
            {
                return $this->routeUrlPathValue;
            }

            public function getController(): string
            {
                return $this->controllerValue;
            }

            public function getAction(): string
            {
                return $this->actionValue;
            }

            public function getRouterData(string $key): mixed
            {
                return match ($key) {
                    'module_path' => '',
                    'controller' => $this->controllerClassValue,
                    default => null,
                };
            }
        };
    }
}
