<?php

declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Model\WhiteAclSource;
use Weline\Acl\Observer\RouteBefore;
use Weline\Acl\Service\AclService;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\PublicApiAuthRouteMatcher;
use Weline\Framework\Http\Request;

class RouteBeforeTest extends TestCase
{
    public function testExecuteAllowsWeShopPublicAuthRouteBeforeAclFrontendLoginGate(): void
    {
        $request = new class() extends Request {
            public function isBackend(): bool
            {
                return false;
            }

            public function isApiBackend(): bool
            {
                return false;
            }

            public function isApiFrontend(): bool
            {
                return true;
            }

            public function getMethod(): string
            {
                return 'POST';
            }

            public function getRouteUrlPath(string $url = ''): string
            {
                return 'api/weshop/rest/v1/auth/token';
            }

            public function getPath(): string
            {
                return 'api/weshop/rest/v1/auth/token';
            }

            public function getController(): string
            {
                return 'Auth';
            }

            public function getAction(): string
            {
                return 'postToken';
            }

            public function getRouterData(string $key): mixed
            {
                return match ($key) {
                    'controller' => 'WeShop\\Auth\\Api\\Rest\\V1\\Auth',
                    default => null,
                };
            }
        };

        $whiteAclSource = $this->createMock(WhiteAclSource::class);
        $aclService = $this->createMock(AclService::class);

        $observer = new RouteBefore(
            $whiteAclSource,
            $aclService,
            new PublicApiAuthRouteMatcher()
        );

        $route = new class($request) {
            public function __construct(private readonly Request $request)
            {
            }

            public function getRequest(): Request
            {
                return $this->request;
            }
        };

        $event = new Event(['data' => ['route' => $route]]);
        $observer->execute($event);

        $this->assertTrue(true);
    }

    public function testExecuteAllowsGuestFrontendRouteWithoutAclBeforeLoginGate(): void
    {
        $request = new class() extends Request {
            public function isBackend(): bool
            {
                return false;
            }

            public function isApiBackend(): bool
            {
                return false;
            }

            public function isApiFrontend(): bool
            {
                return true;
            }

            public function getMethod(): string
            {
                return 'POST';
            }

            public function getRouteUrlPath(string $url = ''): string
            {
                return 'api/rest/v1/weshop/checkout/methods';
            }

            public function getPath(): string
            {
                return 'api/rest/v1/weshop/checkout/methods';
            }

            public function getController(): string
            {
                return 'Checkout';
            }

            public function getAction(): string
            {
                return 'postMethods';
            }

            public function getRouterData(string $key): mixed
            {
                return match ($key) {
                    'controller' => 'WeShop\\ApiBridge\\Api\\Rest\\V1\\Weshop\\Checkout',
                    default => null,
                };
            }
        };

        $whiteAclSource = $this->createMock(WhiteAclSource::class);
        $aclService = $this->createMock(AclService::class);

        $observer = new RouteBefore(
            $whiteAclSource,
            $aclService,
            new PublicApiAuthRouteMatcher()
        );

        $route = new class($request) {
            public function __construct(private readonly Request $request)
            {
            }

            public function getRequest(): Request
            {
                return $this->request;
            }
        };

        $event = new Event(['data' => ['route' => $route]]);
        $observer->execute($event);

        $this->assertTrue(true);
    }

    public function testExecuteAllowsGuestCartFrontendRouteWithoutAclBeforeLoginGate(): void
    {
        $request = new class() extends Request {
            public function isBackend(): bool
            {
                return false;
            }

            public function isApiBackend(): bool
            {
                return false;
            }

            public function isApiFrontend(): bool
            {
                return true;
            }

            public function getMethod(): string
            {
                return 'GET';
            }

            public function getRouteUrlPath(string $url = ''): string
            {
                return 'api/rest/v1/weshop/cart/mini-items';
            }

            public function getPath(): string
            {
                return 'api/rest/v1/weshop/cart/mini-items';
            }

            public function getController(): string
            {
                return 'Cart';
            }

            public function getAction(): string
            {
                return 'getMiniItems';
            }

            public function getRouterData(string $key): mixed
            {
                return match ($key) {
                    'controller' => 'WeShop\\ApiBridge\\Api\\Rest\\V1\\Weshop\\Cart',
                    default => null,
                };
            }
        };

        $whiteAclSource = $this->createMock(WhiteAclSource::class);
        $aclService = $this->createMock(AclService::class);

        $observer = new RouteBefore(
            $whiteAclSource,
            $aclService,
            new PublicApiAuthRouteMatcher()
        );

        $route = new class($request) {
            public function __construct(private readonly Request $request)
            {
            }

            public function getRequest(): Request
            {
                return $this->request;
            }
        };

        $event = new Event(['data' => ['route' => $route]]);
        $observer->execute($event);

        $this->assertTrue(true);
    }
}
