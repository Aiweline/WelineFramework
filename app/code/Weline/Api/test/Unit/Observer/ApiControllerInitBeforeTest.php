<?php

declare(strict_types=1);

namespace Weline\Api\test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use WeShop\Auth\Data\ActorContext;
use WeShop\Auth\Service\WeShopAuthTokenService;
use Weline\Customer\Model\Customer as AuthCustomer;
use Weline\Api\Data\ApiAppActor;
use Weline\Api\Data\ApiAppTokenContext;
use Weline\Api\Model\ApiApp;
use Weline\Api\Model\ApiAppInstallation;
use Weline\Api\Observer\ApiControllerInitBefore;
use Weline\Api\Service\ApiAppTokenService;
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
        ObjectManager::removeInstance(WeShopAuthTokenService::class);
        ObjectManager::removeInstance(AuthCustomer::class);
        ObjectManager::removeInstance(ApiAppTokenService::class);
        parent::tearDown();
    }

    public function testExecuteBindsWeShopCustomerBearerTokenToRequestAndEvent(): void
    {
        $request = $this->createRequestMock(
            'weshop/rest/v1/order/list',
            'Order',
            'getList',
            'WeShop\\Order\\Api\\Rest\\V1\\Order',
            authToken: 'weshop-access-token',
            apiFrontend: true
        );
        ObjectManager::setInstance(Request::class, $request);

        $apiSecurityService = $this->createMock(ApiSecurityService::class);
        $apiSecurityService->expects($this->never())->method('isPublicApi');

        $legacyTokenService = $this->createMock(TokenService::class);
        $legacyTokenService->expects($this->once())
            ->method('validateAccessToken')
            ->with('weshop-access-token')
            ->willReturn(null);

        $weshopTokenService = new class() extends WeShopAuthTokenService {
            public function __construct()
            {
            }

            public function resolveAccessToken(string $token): ?ActorContext
            {
                return new ActorContext(
                    ActorContext::ACTOR_CUSTOMER,
                    42,
                    'frontend',
                    ['customer'],
                    true
                );
            }
        };
        ObjectManager::setInstance(WeShopAuthTokenService::class, $weshopTokenService);

        $authCustomer = new class() extends AuthCustomer {
            public function __construct()
            {
            }

            public function load($id = null, $field = null): static
            {
                return $this;
            }

            public function getId(mixed $default = 0): int
            {
                return 42;
            }

            public function getEmail(): string
            {
                return 'buyer@example.com';
            }

            public function isSandboxAccount(): bool
            {
                return false;
            }
        };
        ObjectManager::setInstance(AuthCustomer::class, $authCustomer);

        $observer = new ApiControllerInitBefore(
            $request,
            $apiSecurityService,
            $this->createMock(IpWhitelistService::class),
            $this->createMock(UserAgentRestrictionService::class),
            $legacyTokenService,
            new PublicApiAuthRouteMatcher()
        );

        $event = new Event(['data' => []]);
        $observer->execute($event);

        $this->assertSame($authCustomer, $event->getData('user'));
        $this->assertSame($authCustomer, $request->getData('weshop_auth_user'));
        $this->assertInstanceOf(ActorContext::class, $request->getData('weshop_actor_context'));
        $this->assertSame(42, $request->getData('weshop_actor_context')->getActorId());
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

    public function testExecuteAllowsPublicFrontendApiWithoutAclByControllerClass(): void
    {
        $request = $this->createRequestMock(
            'api/rest/v1/weshop/checkout/methods',
            'Checkout',
            'postMethods',
            'WeShop\\ApiBridge\\Api\\Rest\\V1\\Weshop\\Checkout'
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

    public function testExecuteAllowsGuestCartApiWithoutTokenWhenMatcherMarksItPublic(): void
    {
        $request = $this->createRequestMock(
            'api/rest/v1/weshop/cart/mini-items',
            'Cart',
            'getMiniItems',
            'WeShop\\ApiBridge\\Api\\Rest\\V1\\Weshop\\Cart'
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

    public function testExecuteBindsApiAppBearerTokenToEventAccessSources(): void
    {
        $request = $this->createRequestMock(
            'api/rest/v1/products',
            'Products',
            'getList',
            'Weline\\Catalog\\Api\\Rest\\V1\\Products',
            authToken: 'app-access-token',
            apiFrontend: true
        );
        ObjectManager::setInstance(Request::class, $request);

        $apiSecurityService = $this->createMock(ApiSecurityService::class);
        $apiSecurityService->expects($this->once())->method('isPublicApi')->with($request)->willReturn(false);

        $legacyTokenService = $this->createMock(TokenService::class);
        $legacyTokenService->expects($this->once())
            ->method('validateAccessToken')
            ->with('app-access-token')
            ->willReturn(null);

        $app = new ApiApp();
        $app->setData(ApiApp::schema_fields_ID, 11)
            ->setClientId('app_123')
            ->setName('Demo app')
            ->setStatus(ApiApp::STATUS_ACTIVE);

        $installation = new ApiAppInstallation();
        $installation->setData(ApiAppInstallation::schema_fields_ID, 22)
            ->setAppId(11)
            ->setSubjectType('global')
            ->setSubjectId('0')
            ->setStatus(ApiAppInstallation::STATUS_ACTIVE);

        $accessSources = [[
            'source_id' => 'Vendor_Module::product_read',
            'route' => 'api/rest/v1/products',
            'method' => 'GET',
            'access_mode' => 'read',
        ]];

        $appTokenService = $this->createMock(ApiAppTokenService::class);
        $appTokenService->expects($this->once())
            ->method('resolveAccessToken')
            ->with('app-access-token')
            ->willReturn(new ApiAppTokenContext(new ApiAppActor($app, $installation), $accessSources));

        $observer = new ApiControllerInitBefore(
            $request,
            $apiSecurityService,
            $this->createMock(IpWhitelistService::class),
            $this->createMock(UserAgentRestrictionService::class),
            $legacyTokenService,
            new PublicApiAuthRouteMatcher(),
            $appTokenService
        );

        $event = new Event(['data' => []]);
        $observer->execute($event);

        self::assertInstanceOf(ApiAppActor::class, $event->getData('user'));
        self::assertSame($accessSources, $event->getData('access_sources'));
        self::assertInstanceOf(ApiAppActor::class, $request->getData('api_app_actor'));
    }

    public function testExecuteAllowsBusinessOwnedBearerOnGuestFrontendRoute(): void
    {
        $request = $this->createRequestMock(
            'api/rest/v1/weshop/cart/mini-items',
            'Cart',
            'getMiniItems',
            'WeShop\\ApiBridge\\Api\\Rest\\V1\\Weshop\\Cart',
            authToken: 'business-owned-token',
            apiFrontend: true
        );
        ObjectManager::setInstance(Request::class, $request);

        $apiSecurityService = $this->createMock(ApiSecurityService::class);
        $apiSecurityService->expects($this->never())->method('isPublicApi');

        $legacyTokenService = $this->createMock(TokenService::class);
        $legacyTokenService->expects($this->once())
            ->method('validateAccessToken')
            ->with('business-owned-token')
            ->willReturn(null);

        $appTokenService = $this->createMock(ApiAppTokenService::class);
        $appTokenService->expects($this->once())
            ->method('resolveAccessToken')
            ->with('business-owned-token')
            ->willReturn(null);

        $observer = new ApiControllerInitBefore(
            $request,
            $apiSecurityService,
            $this->createMock(IpWhitelistService::class),
            $this->createMock(UserAgentRestrictionService::class),
            $legacyTokenService,
            new PublicApiAuthRouteMatcher(),
            $appTokenService
        );

        $event = new Event(['data' => []]);
        $observer->execute($event);

        self::assertSame([], $event->getData('user'));
    }

    private function createRequestMock(
        string $routeUrlPath,
        string $controller,
        string $action,
        string $controllerClass,
        string $authToken = '',
        bool $apiFrontend = true,
        bool $apiBackend = false
    ): Request
    {
        return new class($routeUrlPath, $controller, $action, $controllerClass, $authToken, $apiFrontend, $apiBackend) extends Request {
            public function __construct(
                private readonly string $routeUrlPathValue,
                private readonly string $controllerValue,
                private readonly string $actionValue,
                private readonly string $controllerClassValue,
                private readonly string $authTokenValue,
                private readonly bool $apiFrontendValue,
                private readonly bool $apiBackendValue
            ) {
            }

            public function isApiBackend(): bool
            {
                return $this->apiBackendValue;
            }

            public function isApiFrontend(): bool
            {
                return $this->apiFrontendValue;
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

            public function getAuth(string $auth_type = 'bearer')
            {
                if ($auth_type === 'bearer') {
                    return $this->authTokenValue;
                }

                return null;
            }
        };
    }
}
