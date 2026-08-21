<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Session\Auth\AreaConfig;
use Weline\Framework\Session\Auth\AuthenticableInterface;
use Weline\Framework\Session\Auth\AuthenticatedSession;
use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceContext;
use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceRegistryInterface;
use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceValidation;
use Weline\Framework\Session\Auth\Device\AuthenticatedLoginContext;
use Weline\Framework\Session\Session;
use Weline\Framework\Session\SessionInterface;
use Weline\Framework\Session\Storage\FileStorage;
use Weline\Framework\Session\Strategy\FpmStrategy;

final class AuthenticatedSessionDeviceRegistryTest extends TestCase
{
    /** @var list<SessionInterface> */
    private array $sessions = [];

    /** @var list<string> */
    private array $registryFiles = [];

    protected function setUp(): void
    {
        DeviceRegistryFake::resetState();
    }

    protected function tearDown(): void
    {
        foreach ($this->sessions as $session) {
            if ($session->isStarted()) {
                $session->destroy();
            }
        }
        foreach ($this->registryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testLegacyLoginContinuesWhenRegistryIsNotConfigured(): void
    {
        $session = $this->newSession();
        $auth = new AuthenticatedSession(
            $session,
            AreaConfig::frontend(),
            $this->resolverWith([]),
        );

        $auth->login($this->user(7, 'customer@example.test'));

        self::assertTrue($auth->isLoggedIn());
        self::assertSame(7, $auth->getUserId());
        self::assertSame(0, DeviceRegistryFake::$registerCalls);
    }

    public function testRememberLoginContextRebindsTheRequestedDevice(): void
    {
        $session = $this->newSession();
        $auth = new AuthenticatedSession(
            $session,
            AreaConfig::frontend(),
            $this->resolverWith([
                AuthenticatedDeviceRegistryInterface::class => DeviceRegistryFake::class,
            ]),
        );

        $auth->login(
            $this->user(9, 'remembered@example.test'),
            AuthenticatedLoginContext::remembered('device_public_A'),
        );

        self::assertSame(1, DeviceRegistryFake::$registerCalls);
        self::assertSame('frontend', DeviceRegistryFake::$lastContext?->area);
        self::assertSame('9', DeviceRegistryFake::$lastContext?->principalId);
        self::assertNotSame('', DeviceRegistryFake::$lastContext?->sessionId);
        self::assertSame('device_public_A', DeviceRegistryFake::$lastLoginContext?->deviceId);
        self::assertSame(AuthenticatedLoginContext::SOURCE_REMEMBERED, DeviceRegistryFake::$lastLoginContext?->source);
    }

    public function testPasswordReloginRevokesThePreviousDeviceBeforeSessionRegeneration(): void
    {
        $session = $this->newSession();
        $auth = new AuthenticatedSession(
            $session,
            AreaConfig::frontend(),
            $this->resolverWith([
                AuthenticatedDeviceRegistryInterface::class => DeviceRegistryFake::class,
            ]),
        );

        $auth->login($this->user(9, 'first@example.test'));
        $previousSessionId = $session->getId();

        $auth->login($this->user(10, 'second@example.test'));

        self::assertSame(2, DeviceRegistryFake::$registerCalls);
        self::assertSame(1, DeviceRegistryFake::$revokeCalls);
        self::assertSame('relogin', DeviceRegistryFake::$lastRevokeReason);
        self::assertSame('9', DeviceRegistryFake::$lastRevokedContext?->principalId);
        self::assertSame('device_public_A', DeviceRegistryFake::$lastRevokedContext?->deviceId);
        self::assertSame($previousSessionId, DeviceRegistryFake::$lastRevokedContext?->sessionId);
        self::assertNotSame($previousSessionId, $session->getId());
        self::assertSame(10, $auth->getUserId());
    }

    public function testRevokedDeviceIsValidatedOnceAndClearsOnlyAuthenticationKeys(): void
    {
        $session = $this->newSession();
        $resolver = $this->resolverWith([
            AuthenticatedDeviceRegistryInterface::class => DeviceRegistryFake::class,
        ]);
        $loginSession = new AuthenticatedSession($session, AreaConfig::backend(), $resolver);
        $loginSession->login($this->user(3, 'admin'));
        $session->set('unrelated_cart_or_acl_state', 'preserved');
        $session->save();

        DeviceRegistryFake::$validation = AuthenticatedDeviceValidation::invalid('revoked');
        $nextRequest = new AuthenticatedSession($session, AreaConfig::backend(), $resolver);

        self::assertFalse($nextRequest->isLoggedIn());
        self::assertFalse($nextRequest->isLoggedIn());
        self::assertNull($nextRequest->getUserId());
        self::assertNull($nextRequest->getUsername());
        self::assertSame(1, DeviceRegistryFake::$validateCalls);
        self::assertNull($session->get('WF_BACKEND_USER_ID'));
        self::assertSame('preserved', $session->get('unrelated_cart_or_acl_state'));
    }

    public function testConfiguredButUnavailableRegistryFailsLoginClosed(): void
    {
        $session = $this->newSession();
        $auth = new AuthenticatedSession(
            $session,
            AreaConfig::frontend(),
            $this->resolverWith([
                AuthenticatedDeviceRegistryInterface::class => 'Missing\\Configured\\DeviceRegistry',
            ]),
        );

        $this->expectException(\RuntimeException::class);
        try {
            $auth->login($this->user(11, 'blocked@example.test'));
        } finally {
            self::assertNull($session->get('WF_FRONTEND_USER_ID'));
            self::assertNull($session->get('WF_FRONTEND_USER'));
        }
    }

    public function testRejectedRegistrationPersistsClearedAuthenticationPayload(): void
    {
        DeviceRegistryFake::$validation = AuthenticatedDeviceValidation::invalid('rejected');
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn('rejected-registration-session');
        $session->method('isStarted')->willReturn(true);
        $session->expects(self::once())->method('start');
        $session->expects(self::once())->method('regenerate')->with(true);
        $session->expects(self::exactly(4))->method('delete');
        $session->expects(self::once())->method('save');
        $auth = new AuthenticatedSession(
            $session,
            AreaConfig::frontend(),
            $this->resolverWith([
                AuthenticatedDeviceRegistryInterface::class => DeviceRegistryFake::class,
            ]),
        );

        $this->expectException(\RuntimeException::class);
        $auth->login($this->user(11, 'blocked@example.test'));
    }

    public function testMissingDeviceTableRegistrationSurfacesSetupUpgradeHint(): void
    {
        DeviceRegistryFake::$registerException = new \PDOException(
            'SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "public.weline_authenticated_device" does not exist',
        );
        $session = $this->newSession();
        $auth = new AuthenticatedSession(
            $session,
            AreaConfig::backend(),
            $this->resolverWith([
                AuthenticatedDeviceRegistryInterface::class => DeviceRegistryFake::class,
            ]),
        );

        try {
            $auth->login($this->user(21, 'admin'));
            self::fail('Expected device registration to fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('setup:upgrade', $exception->getMessage());
            self::assertInstanceOf(\PDOException::class, $exception->getPrevious());
            self::assertNull($session->get('WF_BACKEND_USER_ID'));
            self::assertNull($session->get('WF_BACKEND_USER'));
        }
    }

    public function testConfiguredButUnavailableRegistryRejectsAnExistingAuthenticationState(): void
    {
        $session = $this->newSession();
        $session->start('existing_' . bin2hex(random_bytes(8)));
        $session->set('WF_FRONTEND_USER', 'legacy@example.test');
        $session->set('WF_FRONTEND_USER_ID', 12);
        $session->set('WF_FRONTEND_USER_MODEL', TestAuthenticable::class);
        $session->set('unrelated_state', 'kept');
        $session->save();

        $auth = new AuthenticatedSession(
            $session,
            AreaConfig::frontend(),
            $this->resolverWith([
                AuthenticatedDeviceRegistryInterface::class => 'Missing\\Configured\\DeviceRegistry',
            ]),
        );

        self::assertFalse($auth->isLoggedIn());
        self::assertNull($session->get('WF_FRONTEND_USER_ID'));
        self::assertSame('kept', $session->get('unrelated_state'));
    }

    public function testPreUpgradeSessionPersistsTheLazyAdoptedPublicDeviceBinding(): void
    {
        $session = $this->newSession();
        // PHPUnit has already emitted output, so FpmStrategy cannot open a
        // native PHP session here. Regeneration exercises its storage-backed
        // path and gives this pre-upgrade payload a real Session id.
        $session->start();
        $session->regenerate(true);
        $session->set('WF_FRONTEND_USER', 'legacy@example.test');
        $session->set('WF_FRONTEND_USER_ID', 13);
        $session->set('WF_FRONTEND_USER_MODEL', TestAuthenticable::class);
        $session->save();
        $auth = new AuthenticatedSession(
            $session,
            AreaConfig::frontend(),
            $this->resolverWith([
                AuthenticatedDeviceRegistryInterface::class => DeviceRegistryFake::class,
            ]),
        );

        self::assertTrue($auth->isLoggedIn());
        self::assertSame(
            'device_public_A',
            $session->get(AuthenticatedDeviceContext::sessionKeyForArea('frontend')),
        );
        self::assertSame(1, DeviceRegistryFake::$validateCalls);
    }

    public function testLogoutRevokesTheCurrentDeviceAndPreservesUnrelatedSessionState(): void
    {
        $session = $this->newSession();
        $auth = new AuthenticatedSession(
            $session,
            AreaConfig::backend(),
            $this->resolverWith([
                AuthenticatedDeviceRegistryInterface::class => DeviceRegistryFake::class,
            ]),
        );
        $auth->login($this->user(1, 'admin'));
        $session->set('backend_acl_role_id', 1);

        $auth->logout();

        self::assertSame(1, DeviceRegistryFake::$revokeCalls);
        self::assertSame('logout', DeviceRegistryFake::$lastRevokeReason);
        self::assertFalse($auth->isLoggedIn());
        self::assertSame(1, $session->get('backend_acl_role_id'));
    }

    private function newSession(): SessionInterface
    {
        $storage = new FileStorage([
            'path' => sys_get_temp_dir() . '/weline-auth-device-tests/',
            'lifetime' => 3600,
        ]);
        $session = new Session($storage, new FpmStrategy($storage, ['lifetime' => 3600]), 3600);
        $this->sessions[] = $session;
        return $session;
    }

    /** @param array<class-string,string> $providers */
    private function resolverWith(array $providers): RuntimeProviderResolver
    {
        $file = sys_get_temp_dir() . '/weline-auth-device-provider-' . bin2hex(random_bytes(6)) . '.php';
        $this->registryFiles[] = $file;
        $registry = [
            'format' => 1,
            'order' => ['Weline_Test'],
            'modules' => [
                'Weline_Test' => ['provides' => $providers],
            ],
        ];
        file_put_contents($file, '<?php return ' . var_export($registry, true) . ';');
        return new RuntimeProviderResolver(new ServiceProviderRegistry($file));
    }

    private function user(int $id, string $username): AuthenticableInterface
    {
        return new TestAuthenticable($id, $username);
    }
}

final class DeviceRegistryFake implements AuthenticatedDeviceRegistryInterface
{
    public static int $registerCalls = 0;
    public static int $validateCalls = 0;
    public static int $revokeCalls = 0;
    public static ?AuthenticatedDeviceContext $lastContext = null;
    public static ?AuthenticatedDeviceContext $lastRevokedContext = null;
    public static ?AuthenticatedLoginContext $lastLoginContext = null;
    public static string $lastRevokeReason = '';
    public static ?AuthenticatedDeviceValidation $validation = null;
    public static ?\Throwable $registerException = null;

    public static function resetState(): void
    {
        self::$registerCalls = 0;
        self::$validateCalls = 0;
        self::$revokeCalls = 0;
        self::$lastContext = null;
        self::$lastRevokedContext = null;
        self::$lastLoginContext = null;
        self::$lastRevokeReason = '';
        self::$validation = AuthenticatedDeviceValidation::valid('device_public_A');
        self::$registerException = null;
    }

    public function supportsArea(string $area): bool
    {
        return in_array($area, ['frontend', 'backend'], true);
    }

    public function register(
        AuthenticatedDeviceContext $context,
        ?AuthenticatedLoginContext $loginContext = null,
    ): AuthenticatedDeviceValidation {
        self::$registerCalls++;
        self::$lastContext = $context;
        self::$lastLoginContext = $loginContext;
        if (self::$registerException !== null) {
            throw self::$registerException;
        }
        return self::$validation ?? AuthenticatedDeviceValidation::valid('device_public_A');
    }

    public function validate(AuthenticatedDeviceContext $context): AuthenticatedDeviceValidation
    {
        self::$validateCalls++;
        self::$lastContext = $context;
        return self::$validation ?? AuthenticatedDeviceValidation::valid('device_public_A');
    }

    public function revokeCurrent(AuthenticatedDeviceContext $context, string $reason = 'logout'): void
    {
        self::$revokeCalls++;
        self::$lastContext = $context;
        self::$lastRevokedContext = $context;
        self::$lastRevokeReason = $reason;
    }
}

final class TestAuthenticable implements AuthenticableInterface
{
    public function __construct(
        private readonly int $id,
        private readonly string $username,
    ) {
    }

    public function getAuthIdentifier(): int|string
    {
        return $this->id;
    }

    public function getAuthUsername(): string
    {
        return $this->username;
    }

    public function getAuthSessionId(): string
    {
        return '';
    }

    public static function getAuthModelClass(): string
    {
        return self::class;
    }
}
