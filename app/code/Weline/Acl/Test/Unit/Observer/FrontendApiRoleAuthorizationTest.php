<?php
declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Observer;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Observer\RouteBefore;
use Weline\Acl\Service\AclServiceInterface;
use Weline\Api\Model\ApiUser;
use Weline\Api\Api\AuthenticatedApiUser;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

final class FrontendApiRoleAuthorizationTest extends TestCase
{
    private mixed $previousSessionFactory;

    protected function setUp(): void
    {
        RequestContext::set('acl.route_before.state.v1', ['frontend_whitelist' => []]);
        $property = new ReflectionProperty(SessionFactory::class, 'instance');
        $this->previousSessionFactory = $property->getValue();
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->method('isLoggedIn')->willReturn(false);
        $factory = $this->createMock(SessionFactory::class);
        $factory->method('createFrontendSession')->willReturn($session);
        $property->setValue(null, $factory);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(SessionFactory::class, 'instance'))->setValue(null, $this->previousSessionFactory);
        RouteBefore::resetRequestCache();
    }

    public function testAuthorizedApiUserUsesExistingRoleAuthorization(): void
    {
        $acl = $this->createMock(AclServiceInterface::class);
        $acl->method('isRouteProtected')->willReturn(true);
        $acl->expects(self::once())->method('isRouteAllowed')->with(12, 'weline_product/rest/v1/products/create', 'POST')->willReturn(true);
        $this->authorize($acl, true, 12);
    }

    public function testApiUserWithoutRouteGrantReceivesForbidden(): void
    {
        $acl = $this->createMock(AclServiceInterface::class);
        $acl->method('isRouteProtected')->willReturn(true);
        $acl->method('isRouteAllowed')->willReturn(false);
        $this->assertDenied(403, fn() => $this->authorize($acl, true, 12));
    }

    public function testAnonymousStillReceivesUnauthorized(): void
    {
        $acl = $this->createMock(AclServiceInterface::class);
        $acl->expects(self::never())->method('isRouteAllowed');
        $this->assertDenied(401, fn() => $this->authorize($acl, false, 0));
    }

    public function testApplicationStillUsesItsGrantedScopes(): void
    {
        $acl = $this->createMock(AclServiceInterface::class);
        $acl->expects(self::never())->method('isRouteAllowed');
        $acl->method('hasAnyAclEntries')->willReturn(true);
        $acl->expects(self::once())->method('isRouteAllowedByEntries')->with([['scope' => 'create']], 'weline_product/rest/v1/products/create', 'POST', true)->willReturn(true);
        $this->authorize($acl, true, 0, true);
    }

    public function testApplicationWithoutScopeRemainsForbidden(): void
    {
        $acl = $this->createMock(AclServiceInterface::class);
        $acl->expects(self::never())->method('isRouteAllowed');
        $acl->method('hasAnyAclEntries')->willReturn(false);
        $this->assertDenied(403, fn() => $this->authorize($acl, true, 0, true));
    }

    public function testAuthenticatedPublicRouteDoesNotRequireRole(): void
    {
        $acl = $this->createMock(AclServiceInterface::class);
        $acl->method('isRouteProtected')->willReturn(false);
        $acl->expects(self::never())->method('isRouteAllowed');
        $this->authorize($acl, true, 0);
    }

    public function testOtherFrontendSessionKeepsExistingProtectedRouteBehavior(): void
    {
        $acl = $this->createMock(AclServiceInterface::class);
        $acl->method('isRouteProtected')->willReturn(true);
        $acl->expects(self::never())->method('isRouteAllowed');
        $this->authorize($acl, true, 0, false, false);
    }

    private function assertDenied(int $status, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected authentication/authorization denial.');
        } catch (ResponseTerminateException $exception) {
            self::assertSame($status, $exception->getStatusCode());
        }
    }

    private function authorize(AclServiceInterface $acl, bool $authenticated, int $roleId, bool $application = false, bool $trustedApiUser = true): void
    {
        $observer = (new ReflectionClass(RouteBefore::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(RouteBefore::class, 'aclService'))->setValue($observer, $acl);
        $request = $this->createMock(Request::class);
        $request->method('getRouteUrlPath')->willReturn('weline_product/rest/v1/products/create');
        $request->method('getMethod')->willReturn('POST');
        $identity = $authenticated && !$application && $trustedApiUser ? new AuthenticatedApiUser(31, $roleId) : null;
        $request->method('getData')->willReturnCallback(static fn(string $key = '') => match ($key) {
            'api_app_actor' => $application ? new stdClass() : null,
            'api_authenticated_user' => $identity,
            default => null,
        });
        $user = $authenticated ? $this->createMock(ApiUser::class) : null;
        if ($user !== null) { $user->method('getIsEnabled')->willReturn(true); }
        $role = new class($roleId) {
            public function __construct(private int $id) {}
            public function getId(): int { return $this->id; }
            public function getAccess(): array { return []; }
        };
        $values = ['user' => $user, 'role' => $role, 'access_sources' => $application ? [['scope' => 'create']] : []];
        $event = $this->createMock(Event::class);
        $event->method('getData')->willReturnCallback(static fn(string $key = '') => $values[$key] ?? null);
        (new ReflectionMethod(RouteBefore::class, 'validateFrontendApiAccess'))->invokeArgs($observer, [$request, &$event]);
    }
}
