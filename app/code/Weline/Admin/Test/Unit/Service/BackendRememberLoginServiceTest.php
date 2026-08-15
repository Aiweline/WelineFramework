<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Admin\Service\BackendRememberLoginService;
use Weline\Backend\Api\Auth\BackendInteractiveAuthInterface;
use Weline\Backend\Api\Auth\BackendLoginAccount;
use Weline\Backend\Api\Auth\BackendRememberToken;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\CookieScope;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceContext;
use Weline\Framework\Session\Auth\Device\AuthenticatedLoginContext;
use Weline\Framework\Session\Auth\Device\IssuedRememberedDeviceCredential;
use Weline\Framework\Session\Auth\Device\RememberedDeviceCredentialProviderInterface;
use Weline\Framework\Session\Auth\Device\RememberedDeviceCredentialValidation;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\SessionInterface;

final class BackendRememberLoginServiceTest extends TestCase
{
    /** @var list<string> */
    private array $registryFiles = [];

    protected function setUp(): void
    {
        BackendRememberProviderFake::resetState();
    }

    protected function tearDown(): void
    {
        foreach ($this->registryFiles as $file) {
            if (is_file($file)) unlink($file);
        }
        parent::tearDown();
    }

    public function testDeviceCredentialRestoresTheSameDeviceAndRotatesTheToken(): void
    {
        $session = $this->session(false, 'new-backend-session', 'device-public-1');
        $factory = $this->createMock(SessionFactory::class);
        $factory->method('createBackendSession')->willReturn($session);
        $backendAuth = new BackendAuthFake($this->account());
        $service = new class(
            $this->request(),
            $factory,
            $backendAuth,
            $this->createMock(MessageManager::class),
            $this->resolverWith([
                RememberedDeviceCredentialProviderInterface::class => BackendRememberProviderFake::class,
            ]),
        ) extends BackendRememberLoginService {
            protected function readDeviceRememberToken(): string
            {
                return 'raw-device-token';
            }

            protected function readRememberToken(): string
            {
                return '';
            }
        };

        self::assertTrue($service->restoreIfNeeded());
        self::assertSame(AuthenticatedLoginContext::SOURCE_REMEMBERED, $backendAuth->restoredContext?->source);
        self::assertSame('device-public-1', $backendAuth->restoredContext?->deviceId);
        self::assertSame(1, BackendRememberProviderFake::$resolveCalls);
        self::assertSame(1, BackendRememberProviderFake::$issueCalls);
        self::assertSame('new-backend-session', BackendRememberProviderFake::$lastContext?->sessionId);
        self::assertSame(7, $service->consumeRestoredAclContext()['user_id'] ?? 0);
    }

    public function testConfiguredUnavailableProviderDoesNotFallBackToLegacyToken(): void
    {
        $session = $this->session(false, 'backend-session', null);
        $factory = $this->createMock(SessionFactory::class);
        $factory->method('createBackendSession')->willReturn($session);
        $backendAuth = new BackendAuthFake($this->account());
        $service = new class(
            $this->request(),
            $factory,
            $backendAuth,
            $this->createMock(MessageManager::class),
            $this->resolverWith([
                RememberedDeviceCredentialProviderInterface::class => 'Missing\\Remember\\Provider',
            ]),
        ) extends BackendRememberLoginService {
            protected function readRememberToken(): string
            {
                return 'legacy-token-that-must-not-run';
            }
        };

        self::assertFalse($service->restoreIfNeeded());
        self::assertSame(0, $backendAuth->findRememberCalls);
        self::assertNull($backendAuth->restoredContext);
    }

    public function testNotConfiguredProviderPreservesLegacyRememberLoginBehavior(): void
    {
        $session = $this->session(false, 'legacy-session', null);
        $factory = $this->createMock(SessionFactory::class);
        $factory->method('createBackendSession')->willReturn($session);
        $backendAuth = new BackendAuthFake($this->account());
        $backendAuth->legacyRememberToken = new BackendRememberToken(7, time() + 3600);
        $service = new class(
            $this->request(),
            $factory,
            $backendAuth,
            $this->createMock(MessageManager::class),
            $this->resolverWith([]),
        ) extends BackendRememberLoginService {
            protected function readRememberToken(): string
            {
                return 'legacy-token';
            }
        };

        self::assertTrue($service->restoreIfNeeded());
        self::assertSame(1, $backendAuth->findRememberCalls);
        self::assertNull($backendAuth->restoredContext);
    }

    public function testConfiguredProviderMigratesConfirmedLegacyTokenAndItCannotRestoreAgain(): void
    {
        $session = $this->session(false, 'legacy-migration-session', 'device-public-1');
        $factory = $this->createMock(SessionFactory::class);
        $factory->method('createBackendSession')->willReturn($session);
        $backendAuth = new BackendAuthFake($this->account());
        $backendAuth->legacyRememberToken = new BackendRememberToken(7, time() + 3600);
        $service = new class(
            $this->request(),
            $factory,
            $backendAuth,
            $this->createMock(MessageManager::class),
            $this->resolverWith([
                RememberedDeviceCredentialProviderInterface::class => BackendRememberProviderFake::class,
            ]),
        ) extends BackendRememberLoginService {
            protected function readDeviceRememberToken(): string
            {
                return '';
            }

            protected function readRememberToken(): string
            {
                return 'confirmed-backend-legacy-token';
            }
        };

        self::assertTrue($service->restoreIfNeeded());
        self::assertSame(AuthenticatedLoginContext::SOURCE_LEGACY_REMEMBERED, $backendAuth->restoredContext?->source);
        self::assertSame(1, BackendRememberProviderFake::$issueCalls);
        self::assertSame(1, $backendAuth->invalidateRememberCalls);
        self::assertNull($backendAuth->legacyRememberToken);

        self::assertFalse($service->restoreIfNeeded());
        self::assertSame(1, BackendRememberProviderFake::$issueCalls);
    }

    public function testLoginPostNeverAttemptsRememberRecovery(): void
    {
        $request = new class extends Request {
            public function getRouteUrlPath(string $url = ''): string
            {
                return 'admin/login/post';
            }
        };
        $factory = $this->createMock(SessionFactory::class);
        $factory->expects(self::never())->method('createBackendSession');
        $service = new BackendRememberLoginService(
            $request,
            $factory,
            new BackendAuthFake($this->account()),
            $this->createMock(MessageManager::class),
            $this->resolverWith([]),
        );

        self::assertFalse($service->restoreIfNeeded());
    }

    public function testConfiguredRememberFailureLogsOutTheJustAuthenticatedSession(): void
    {
        BackendRememberProviderFake::$throwOnIssue = true;
        $session = $this->session(true, 'backend-session', 'device-public-1');
        $session->expects(self::once())->method('logout');
        $factory = $this->createMock(SessionFactory::class);
        $service = new BackendRememberLoginService(
            $this->request(),
            $factory,
            new BackendAuthFake($this->account()),
            $this->createMock(MessageManager::class),
            $this->resolverWith([
                RememberedDeviceCredentialProviderInterface::class => BackendRememberProviderFake::class,
            ]),
        );

        $this->expectException(\RuntimeException::class);
        $service->configureRememberedLogin($this->account(), true, $session);
    }

    public function testPasswordLoginRevokesPreviousDeviceCredentialBeforeReplacementIssue(): void
    {
        BackendRememberProviderFake::$throwOnIssue = true;
        $session = $this->session(true, 'new-backend-session', 'new-device-public-id');
        $session->expects(self::once())->method('logout');
        $service = new class(
            $this->request(),
            $this->createMock(SessionFactory::class),
            new BackendAuthFake($this->account()),
            $this->createMock(MessageManager::class),
            $this->resolverWith([
                RememberedDeviceCredentialProviderInterface::class => BackendRememberProviderFake::class,
            ]),
        ) extends BackendRememberLoginService {
            protected function readDeviceRememberToken(): string
            {
                return 'previous-administrator-device-token';
            }

            protected function readRememberToken(): string
            {
                return '';
            }
        };

        try {
            $service->configureRememberedLogin($this->account(), true, $session);
            self::fail('Replacement issuance is expected to fail in this regression test.');
        } catch (\RuntimeException) {
            self::assertSame([
                'revoke:backend:previous-administrator-device-token:password_login_replaced',
                'issue',
            ], BackendRememberProviderFake::$events);
        }
    }

    public function testExplicitLogoutInvalidatesAreaConfirmedLegacyTokenFromPreviousAdministrator(): void
    {
        $backendAuth = new BackendAuthFake($this->account());
        $backendAuth->legacyRememberToken = new BackendRememberToken(7, time() + 3600);
        $service = new class(
            $this->request(),
            $this->createMock(SessionFactory::class),
            $backendAuth,
            $this->createMock(MessageManager::class),
            $this->resolverWith([
                RememberedDeviceCredentialProviderInterface::class => BackendRememberProviderFake::class,
            ]),
        ) extends BackendRememberLoginService {
            protected function readDeviceRememberToken(): string
            {
                return '';
            }

            protected function readRememberToken(): string
            {
                return 'confirmed-backend-legacy-token';
            }
        };

        $service->clearAfterLogout(8);

        self::assertSame(1, $backendAuth->invalidateRememberCalls);
        self::assertNull($backendAuth->legacyRememberToken);
    }

    public function testClearingDeviceCookiePreservesPartitionedCookieAttributes(): void
    {
        HeaderCollector::reset();
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        WelineEnv::set('server.http_host', 'admin.test:9502', 'backend remember cookie test');
        WelineEnv::set('server.server_port', 9502, 'backend remember cookie test');
        WelineEnv::set('server.https', 'on', 'backend remember cookie test');
        CookieScope::setPolicyResolverOverride(static fn(): array => [
            'active' => true,
            'name_suffix' => '_w0',
            'name_suffix_pattern' => '/_w\d+$/D',
            'mount_path' => '/store',
            'expire_unscoped_aliases' => true,
            'revision' => 'backend-remember-test',
        ]);
        try {
            $service = new BackendRememberLoginService(
                $this->request(),
                $this->createMock(SessionFactory::class),
                new BackendAuthFake($this->account()),
                $this->createMock(MessageManager::class),
                $this->resolverWith([]),
            );

            (new \ReflectionMethod(BackendRememberLoginService::class, 'clearDeviceCookie'))
                ->invoke($service);

            $cookie = array_values(array_filter(
                HeaderCollector::getInstance()->getCookies(),
                static fn(array $candidate): bool => ($candidate['name'] ?? '') === 'w_backend_ut_9502',
            ))[0] ?? null;
            $backendCookies = array_values(array_filter(
                HeaderCollector::getInstance()->getCookies(),
                static fn(array $candidate): bool => str_starts_with(
                    (string)($candidate['name'] ?? ''),
                    'w_backend_ut',
                ),
            ));
            self::assertIsArray($cookie);
            self::assertCount(1, $backendCookies);
            self::assertSame('/', $cookie['path'] ?? null);
            self::assertTrue((bool)($cookie['secure'] ?? false));
            self::assertSame('None; Partitioned', $cookie['sameSite'] ?? null);
            self::assertLessThan(time(), (int)($cookie['expire'] ?? PHP_INT_MAX));
        } finally {
            CookieScope::setPolicyResolverOverride(null);
            HeaderCollector::reset();
            WelineEnv::getInstance()->reset();
            Context::leave();
        }
    }

    private function request(): Request
    {
        return new class extends Request {
            public function getRouteUrlPath(string $url = ''): string
            {
                return 'session-manager/backend/device';
            }

            public function clientIP(): string
            {
                return '127.0.0.1';
            }

            public function getAreaRouter(): string
            {
                return 'backend';
            }
        };
    }

    private function session(bool $loggedIn, string $sessionId, ?string $deviceId): AuthenticatedSessionInterface
    {
        $raw = $this->createMock(SessionInterface::class);
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->method('isLoggedIn')->willReturn($loggedIn);
        $session->method('getId')->willReturn($sessionId);
        $session->method('getSession')->willReturn($raw);
        $session->method('get')->willReturnCallback(
            static fn(string $key): mixed => str_contains($key, 'authenticated_device') ? $deviceId : null,
        );
        return $session;
    }

    private function account(): BackendLoginAccount
    {
        return new BackendLoginAccount(7, 'admin', 'admin@example.test', '', 0, false, true, false, 3);
    }

    /** @param array<class-string,string> $providers */
    private function resolverWith(array $providers): RuntimeProviderResolver
    {
        $file = sys_get_temp_dir() . '/weline-backend-remember-provider-' . bin2hex(random_bytes(6)) . '.php';
        $this->registryFiles[] = $file;
        file_put_contents($file, '<?php return ' . var_export([
            'format' => 1,
            'order' => ['Weline_Test'],
            'modules' => ['Weline_Test' => ['provides' => $providers]],
        ], true) . ';');
        return new RuntimeProviderResolver(new ServiceProviderRegistry($file));
    }
}

final class BackendRememberProviderFake implements RememberedDeviceCredentialProviderInterface
{
    public static int $resolveCalls = 0;
    public static int $issueCalls = 0;
    public static ?AuthenticatedDeviceContext $lastContext = null;
    public static bool $throwOnIssue = false;
    /** @var list<string> */
    public static array $events = [];

    public static function resetState(): void
    {
        self::$resolveCalls = 0;
        self::$issueCalls = 0;
        self::$lastContext = null;
        self::$throwOnIssue = false;
        self::$events = [];
    }

    public function issueCredential(AuthenticatedDeviceContext $context, int $expiresAt): IssuedRememberedDeviceCredential
    {
        self::$events[] = 'issue';
        if (self::$throwOnIssue) {
            throw new \RuntimeException('simulated configured provider failure');
        }
        self::$issueCalls++;
        self::$lastContext = $context;
        return new IssuedRememberedDeviceCredential('rotated-token', 'device-public-1', $expiresAt);
    }

    public function resolveCredential(string $area, string $rawToken): RememberedDeviceCredentialValidation
    {
        self::$resolveCalls++;
        return RememberedDeviceCredentialValidation::valid('7', 'device-public-1', time() + 3600);
    }

    public function revokeCredential(string $area, string $rawToken, string $reason = 'logout'): void
    {
        self::$events[] = 'revoke:' . $area . ':' . $rawToken . ':' . $reason;
    }
}

final class BackendAuthFake implements BackendInteractiveAuthInterface
{
    public int $findRememberCalls = 0;
    public int $invalidateRememberCalls = 0;
    public ?BackendRememberToken $legacyRememberToken = null;
    public ?AuthenticatedLoginContext $restoredContext = null;

    public function __construct(private readonly BackendLoginAccount $account)
    {
    }

    public function find(int $userId): ?BackendLoginAccount { return $userId === $this->account->getId() ? $this->account : null; }
    public function findByUsername(string $username): ?BackendLoginAccount { return $this->account; }
    public function findBySessionId(string $sessionId): ?BackendLoginAccount { return $this->account; }
    public function incrementAttemptTimes(int $userId): BackendLoginAccount { return $this->account; }
    public function recordAttemptContext(int $userId, string $sessionId, string $attemptIp): BackendLoginAccount { return $this->account; }
    public function verifyPassword(int $userId, string $password): bool { return true; }
    public function installSessionIdentity(object $session, BackendLoginAccount $account): void {}
    public function completeLogin(int $userId, string $sessionId, string $loginIp): BackendLoginAccount { return $this->account; }
    public function storeRememberToken(int $userId, string $token, int $expireAt): void {}

    public function findRememberToken(string $token): ?BackendRememberToken
    {
        $this->findRememberCalls++;
        return $this->legacyRememberToken;
    }

    public function invalidateRememberToken(string $token): bool
    {
        $this->invalidateRememberCalls++;
        $this->legacyRememberToken = null;
        return true;
    }
    public function invalidateRememberTokenForUser(int $userId): bool { return true; }

    public function restoreRememberedSession(
        object $session,
        BackendLoginAccount $account,
        int $expireAt,
        ?AuthenticatedLoginContext $context = null,
    ): void {
        $this->restoredContext = $context;
    }
}
