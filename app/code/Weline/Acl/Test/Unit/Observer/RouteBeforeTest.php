<?php

declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Admin\Service\BackendRememberLoginService;
use Weline\Acl\Model\WhiteAclSource;
use Weline\Acl\Observer\RouteBefore;
use Weline\Acl\Service\AclService;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\PublicApiAuthRouteMatcher;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\SessionInterface;

class RouteBeforeTest extends TestCase
{
    protected function tearDown(): void
    {
        RouteBefore::resetRequestCache();
        Runtime::resetModeCache();
        $this->setSessionFactorySingleton(null);
        parent::tearDown();
    }

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
            new PublicApiAuthRouteMatcher(),
            $this->createMock(BackendRememberLoginService::class)
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

        $event = new Event(['route' => $route, 'user' => null, 'role' => null, 'access_sources' => []]);
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
            new PublicApiAuthRouteMatcher(),
            $this->createMock(BackendRememberLoginService::class)
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

        $event = new Event(['data' => ['route' => $route, 'user' => null, 'role' => null, 'access_sources' => []]]);
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
            new PublicApiAuthRouteMatcher(),
            $this->createMock(BackendRememberLoginService::class)
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

    public function testExecuteAllowsBackendWhitelistBehindEntryPrefix(): void
    {
        $request = new class() extends Request {
            public function isBackend(): bool
            {
                return true;
            }

            public function isApiBackend(): bool
            {
                return false;
            }

            public function isApiFrontend(): bool
            {
                return false;
            }

            public function getMethod(): string
            {
                return 'GET';
            }

            public function getRouteUrlPath(string $url = ''): string
            {
                unset($url);
                return 'fihcOt0KAaSGD7NDdqHsCcD05Qo6PfR1/admin/login';
            }

            public function getReferer(): string
            {
                return '';
            }
        };

        $whiteAclSource = new class extends WhiteAclSource {
            public function __construct()
            {
            }

            public function fields($columns = '*', string|bool $sequence = ''): static
            {
                unset($columns, $sequence);
                return $this;
            }

            public function where(...$args): static
            {
                unset($args);
                return $this;
            }

            public function select(...$args): static
            {
                unset($args);
                return $this;
            }

            public function fetchArray(): array
            {
                return [['path' => 'admin/login']];
            }
        };

        $aclService = $this->createMock(AclService::class);
        $aclService->expects(self::never())->method('isRouteProtected');

        $observer = new RouteBefore(
            $whiteAclSource,
            $aclService,
            new PublicApiAuthRouteMatcher(),
            $this->createMock(BackendRememberLoginService::class)
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

        $event = new Event(['route' => $route]);
        $observer->execute($event);

        self::assertTrue(true);
    }

    public function testExecuteApiBackendWithoutRememberRestoreDoesNotReadUndefinedAclContext(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);

        $request = new class() extends Request {
            public function isBackend(): bool
            {
                return false;
            }

            public function isApiBackend(): bool
            {
                return true;
            }

            public function isApiFrontend(): bool
            {
                return false;
            }

            public function getMethod(): string
            {
                return 'GET';
            }

            public function getRouteUrlPath(string $url = ''): string
            {
                unset($url);
                return 'api/rest/v1/protected/backend';
            }

            public function getReferer(): string
            {
                return '';
            }
        };

        $whiteAclSource = new class extends WhiteAclSource {
            public function __construct()
            {
            }

            public function fields($columns = '*', string|bool $sequence = ''): static
            {
                unset($columns, $sequence);
                return $this;
            }

            public function where(...$args): static
            {
                unset($args);
                return $this;
            }

            public function select(...$args): static
            {
                unset($args);
                return $this;
            }

            public function fetchArray(): array
            {
                return [];
            }
        };

        $aclService = $this->createMock(AclService::class);
        $aclService->expects(self::once())->method('isRouteProtected')->with('api/rest/v1/protected/backend')->willReturn(true);

        $rawSession = $this->createMock(SessionInterface::class);
        $rawSession->expects(self::exactly(2))
            ->method('get')
            ->willReturnMap([
                ['backend_acl_role_id', 1],
                ['backend_acl_is_enabled', 1],
            ]);

        $backendSession = $this->createMock(AuthenticatedSessionInterface::class);
        $backendSession->expects(self::once())->method('getUserId')->willReturn(7);
        $backendSession->expects(self::once())->method('getSession')->willReturn($rawSession);

        $sessionFactory = $this->createMock(SessionFactory::class);
        $sessionFactory->expects(self::once())->method('createBackendSession')->willReturn($backendSession);
        $this->setSessionFactorySingleton($sessionFactory);

        $rememberLoginService = $this->createMock(BackendRememberLoginService::class);
        $rememberLoginService->expects(self::never())->method('restoreIfNeeded');

        $observer = new RouteBefore(
            $whiteAclSource,
            $aclService,
            new PublicApiAuthRouteMatcher(),
            $rememberLoginService
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

        $event = new Event(['route' => $route]);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $observer->execute($event);
            self::assertTrue(true);
        } catch (ResponseTerminateException $exception) {
            self::fail('Cached backend ACL context should allow this path to complete without API termination: ' . $exception->getCode());
        } finally {
            restore_error_handler();
        }
    }

    private function setSessionFactorySingleton(?SessionFactory $instance): void
    {
        $reflection = new \ReflectionProperty(SessionFactory::class, 'instance');
        $reflection->setAccessible(true);
        $reflection->setValue(null, $instance);
    }
}
