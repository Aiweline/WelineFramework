<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Model\Customer;
use Weline\Customer\Model\CustomerToken;
use Weline\Customer\Service\CustomerRememberDeviceService;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Context;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceContext;
use Weline\Framework\Session\Auth\Device\AuthenticatedLoginContext;
use Weline\Framework\Session\Auth\Device\IssuedRememberedDeviceCredential;
use Weline\Framework\Session\Auth\Device\RememberedDeviceCredentialProviderInterface;
use Weline\Framework\Session\Auth\Device\RememberedDeviceCredentialValidation;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\SessionInterface;

final class CustomerRememberDeviceServiceTest extends TestCase
{
    private string $registryFile = '';

    protected function setUp(): void
    {
        CustomerLegacyTokenFake::resetState();
        CustomerRememberProviderFake::resetState();
        FailingCustomerRememberProvider::resetState();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->registryFile !== '' && is_file($this->registryFile)) {
            unlink($this->registryFile);
        }
        HeaderCollector::reset();
        WelineEnv::getInstance()->reset();
        Context::leave();
        parent::tearDown();
    }

    public function testConfiguredCredentialFailureLogsOutTheJustAuthenticatedCustomer(): void
    {
        FailingCustomerRememberProvider::$issueCalls = 0;
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->method('getId')->willReturn('frontend-session');
        $session->method('getSession')->willReturn($this->createMock(SessionInterface::class));
        $session->expects(self::once())->method('logout');
        $factory = $this->createMock(SessionFactory::class);
        $customer = new Customer();
        $customer->setData(Customer::schema_fields_ID, 7);
        $service = new CustomerRememberDeviceService(
            $this->resolverWith([
                RememberedDeviceCredentialProviderInterface::class => FailingCustomerRememberProvider::class,
            ]),
            $factory,
            new Customer(),
            new CustomerToken(),
        );

        try {
            $service->issueForAuthenticatedCustomer($customer, 3600, $session);
            self::fail('Configured provider failure must reject the login.');
        } catch (\RuntimeException $exception) {
            self::assertStringNotContainsString('simulated configured provider failure', $exception->getMessage());
            self::assertSame(1, FailingCustomerRememberProvider::$issueCalls);
        }
    }

    public function testExplicitLogoutDeletesAreaConfirmedLegacyTokenFromPreviousCustomer(): void
    {
        CustomerLegacyTokenFake::$deleted = false;
        Context::enter(new Context());
        Context::current()->set('input.cookie', ['w_ut' => 'confirmed-customer-legacy-token']);
        $legacyToken = new CustomerLegacyTokenFake([
            CustomerToken::schema_fields_ID => 91,
            CustomerToken::schema_fields_user_id => 7,
            CustomerToken::schema_fields_token => 'confirmed-customer-legacy-token',
            CustomerToken::schema_fields_type => 'remember_me',
            CustomerToken::schema_fields_token_expire_time => time() + 3600,
        ]);
        $service = new CustomerRememberDeviceService(
            $this->resolverWith([
                RememberedDeviceCredentialProviderInterface::class => FailingCustomerRememberProvider::class,
            ]),
            $this->createMock(SessionFactory::class),
            new Customer(),
            $legacyToken,
        );

        $service->clearAfterLogout(8);

        self::assertTrue(CustomerLegacyTokenFake::$deleted);
    }

    public function testPasswordLoginRevokesPreviousDeviceCredentialBeforeReplacementIssue(): void
    {
        FailingCustomerRememberProvider::resetState();
        Context::enter(new Context());
        Context::current()->set('input.cookie', ['w_frontend_ut' => 'previous-customer-device-token']);
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->method('getId')->willReturn('new-frontend-session');
        $session->method('getSession')->willReturn($this->createMock(SessionInterface::class));
        $session->expects(self::once())->method('logout');
        $customer = new Customer();
        $customer->setData(Customer::schema_fields_ID, 8);
        $service = new CustomerRememberDeviceService(
            $this->resolverWith([
                RememberedDeviceCredentialProviderInterface::class => FailingCustomerRememberProvider::class,
            ]),
            $this->createMock(SessionFactory::class),
            new Customer(),
            new CustomerToken(),
        );

        try {
            $service->issueForAuthenticatedCustomer($customer, 3600, $session);
            self::fail('Replacement issuance is expected to fail in this regression test.');
        } catch (\RuntimeException) {
            self::assertSame([
                'revoke:frontend:previous-customer-device-token:password_login_replaced',
                'issue',
            ], FailingCustomerRememberProvider::$events);
        }
    }

    public function testConfiguredUnavailableProviderDoesNotFallBackToLegacyToken(): void
    {
        Context::enter(new Context());
        Context::current()->set('input.cookie', ['w_ut' => 'legacy-token-that-must-not-run']);
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->expects(self::never())->method('login');
        $service = new CustomerRememberDeviceService(
            $this->resolverWith([
                RememberedDeviceCredentialProviderInterface::class => 'Missing\\Customer\\RememberProvider',
            ]),
            $this->createMock(SessionFactory::class),
            new CustomerLookupFake([Customer::schema_fields_ID => 7]),
            $this->legacyToken('legacy-token-that-must-not-run'),
        );

        self::assertFalse($service->restoreIfNeeded($session));
        self::assertSame(0, CustomerLegacyTokenFake::$findCalls);
    }

    public function testNotConfiguredProviderPreservesLegacyRememberLoginBehavior(): void
    {
        Context::enter(new Context());
        Context::current()->set('input.cookie', ['w_ut' => 'legacy-customer-token']);
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->method('isLoggedIn')->willReturn(false);
        $session->method('getId')->willReturn('legacy-frontend-session');
        $session->expects(self::once())
            ->method('login')
            ->with(self::callback(
                static fn(Customer $customer): bool => (int)$customer->getId() === 7,
            ), null);
        $service = new CustomerRememberDeviceService(
            $this->resolverWith([]),
            $this->createMock(SessionFactory::class),
            new CustomerLookupFake([Customer::schema_fields_ID => 7]),
            $this->legacyToken('legacy-customer-token'),
        );

        self::assertTrue($service->restoreIfNeeded($session));
        self::assertSame(1, CustomerLegacyTokenFake::$findCalls);
        self::assertFalse(CustomerLegacyTokenFake::$deleted);
    }

    public function testDeviceCredentialRestoresSameDeviceRotatesAndCannotReuseOldToken(): void
    {
        CustomerRememberProviderFake::$validResolutionsRemaining = 1;
        Context::enter(new Context());
        Context::current()->set('input.cookie', ['w_frontend_ut' => 'single-use-device-token']);
        $rawSession = $this->createMock(SessionInterface::class);
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->method('isLoggedIn')->willReturn(false);
        $session->method('getId')->willReturn('restored-frontend-session');
        $session->method('getSession')->willReturn($rawSession);
        $session->method('get')->willReturn('frontend-device-public-id');
        $session->expects(self::once())
            ->method('login')
            ->with(
                self::callback(static fn(Customer $customer): bool => (int)$customer->getId() === 7),
                self::callback(static fn(?AuthenticatedLoginContext $context): bool =>
                    $context?->source === AuthenticatedLoginContext::SOURCE_REMEMBERED
                    && $context->deviceId === 'frontend-device-public-id'),
            );
        $service = new CustomerRememberDeviceService(
            $this->resolverWith([
                RememberedDeviceCredentialProviderInterface::class => CustomerRememberProviderFake::class,
            ]),
            $this->createMock(SessionFactory::class),
            new CustomerLookupFake([Customer::schema_fields_ID => 7]),
            new CustomerLegacyTokenFake(),
        );

        self::assertTrue($service->restoreIfNeeded($session));
        self::assertFalse($service->restoreIfNeeded($session));
        self::assertSame(2, CustomerRememberProviderFake::$resolveCalls);
        self::assertSame(1, CustomerRememberProviderFake::$issueCalls);
        self::assertSame('restored-frontend-session', CustomerRememberProviderFake::$lastContext?->sessionId);
        self::assertSame('frontend-device-public-id', CustomerRememberProviderFake::$lastContext?->deviceId);
    }

    public function testClearingDeviceCookiePreservesPartitionedCookieAttributes(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        WelineEnv::set('server.http_host', 'shop.test:9502', 'customer remember cookie test');
        WelineEnv::set('server.server_port', 9502, 'customer remember cookie test');
        WelineEnv::set('server.https', 'on', 'customer remember cookie test');
        $service = new CustomerRememberDeviceService(
            $this->resolverWith([]),
            $this->createMock(SessionFactory::class),
            new Customer(),
            new CustomerToken(),
        );

        $method = new \ReflectionMethod(CustomerRememberDeviceService::class, 'clearDeviceCookie');
        $method->invoke($service);

        $cookies = array_values(HeaderCollector::getInstance()->getCookies());
        $cookie = array_values(array_filter(
            $cookies,
            static fn(array $candidate): bool => ($candidate['name'] ?? '') === 'w_frontend_ut_9502',
        ))[0] ?? null;
        self::assertIsArray($cookie);
        self::assertTrue((bool)($cookie['secure'] ?? false));
        self::assertSame('None; Partitioned', $cookie['sameSite'] ?? null);
        self::assertLessThan(time(), (int)($cookie['expire'] ?? PHP_INT_MAX));
    }

    public function testConfiguredProviderMigratesConfirmedLegacyTokenOnlyOnce(): void
    {
        Context::enter(new Context());
        Context::current()->set('input.cookie', ['w_ut' => 'confirmed-customer-legacy-token']);
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->method('isLoggedIn')->willReturn(false);
        $session->method('getId')->willReturn('migrated-frontend-session');
        $session->method('getSession')->willReturn($this->createMock(SessionInterface::class));
        $session->method('get')->willReturn('migrated-device-public-id');
        $session->expects(self::once())
            ->method('login')
            ->with(
                self::isInstanceOf(Customer::class),
                self::callback(static fn(?AuthenticatedLoginContext $context): bool =>
                    $context?->source === AuthenticatedLoginContext::SOURCE_LEGACY_REMEMBERED),
            );
        $service = new CustomerRememberDeviceService(
            $this->resolverWith([
                RememberedDeviceCredentialProviderInterface::class => CustomerRememberProviderFake::class,
            ]),
            $this->createMock(SessionFactory::class),
            new CustomerLookupFake([Customer::schema_fields_ID => 7]),
            $this->legacyToken('confirmed-customer-legacy-token'),
        );

        self::assertTrue($service->restoreIfNeeded($session));
        self::assertTrue(CustomerLegacyTokenFake::$deleted);
        self::assertSame(1, CustomerRememberProviderFake::$issueCalls);

        self::assertFalse($service->restoreIfNeeded($session));
        self::assertSame(1, CustomerRememberProviderFake::$issueCalls);
    }

    private function legacyToken(string $rawToken): CustomerLegacyTokenFake
    {
        return new CustomerLegacyTokenFake([
            CustomerToken::schema_fields_ID => 91,
            CustomerToken::schema_fields_user_id => 7,
            CustomerToken::schema_fields_token => $rawToken,
            CustomerToken::schema_fields_type => 'remember_me',
            CustomerToken::schema_fields_token_expire_time => time() + 3600,
        ]);
    }

    /** @param array<class-string,string> $providers */
    private function resolverWith(array $providers): RuntimeProviderResolver
    {
        $this->registryFile = sys_get_temp_dir()
            . '/weline-customer-remember-provider-'
            . bin2hex(random_bytes(6))
            . '.php';
        file_put_contents($this->registryFile, '<?php return ' . var_export([
            'format' => 1,
            'order' => ['Weline_Test'],
            'modules' => ['Weline_Test' => ['provides' => $providers]],
        ], true) . ';');
        return new RuntimeProviderResolver(new ServiceProviderRegistry($this->registryFile));
    }
}

final class CustomerLegacyTokenFake extends CustomerToken
{
    public static bool $deleted = false;
    public static int $findCalls = 0;

    public static function resetState(): void
    {
        self::$deleted = false;
        self::$findCalls = 0;
    }

    public function clearData(bool $with_query = true): static
    {
        return $this;
    }

    public function clearQuery(string $type = ''): static
    {
        return $this;
    }

    public function where(...$arguments): static
    {
        return $this;
    }

    public function find(...$arguments): static
    {
        self::$findCalls++;
        return $this;
    }

    public function fetch(string $model_class = ''): mixed
    {
        if (self::$deleted) {
            $this->setData(self::schema_fields_ID, null);
        }
        return $this;
    }

    public function delete(): static
    {
        self::$deleted = true;
        return $this;
    }

    public function save(string|array|bool|AbstractModel $data = [], string|array $sequence = ''): bool|int
    {
        return (int)$this->getId();
    }
}

final class CustomerLookupFake extends Customer
{
    public function clearData(bool $with_query = true): static
    {
        return $this;
    }

    public function clearQuery(string $type = ''): static
    {
        return $this;
    }

    public function load(int|string $field_or_pk_value, $value = null): AbstractModel
    {
        $requestedId = $value === null ? (int)$field_or_pk_value : (int)$value;
        if ($requestedId !== (int)$this->getId()) {
            $this->setData(self::schema_fields_ID, null);
        }
        return $this;
    }

    public function save(string|array|bool|AbstractModel $data = [], string|array $sequence = ''): bool|int
    {
        return (int)$this->getId();
    }
}

final class CustomerRememberProviderFake implements RememberedDeviceCredentialProviderInterface
{
    public static int $resolveCalls = 0;
    public static int $issueCalls = 0;
    public static int $validResolutionsRemaining = PHP_INT_MAX;
    public static ?AuthenticatedDeviceContext $lastContext = null;

    public static function resetState(): void
    {
        self::$resolveCalls = 0;
        self::$issueCalls = 0;
        self::$validResolutionsRemaining = PHP_INT_MAX;
        self::$lastContext = null;
    }

    public function issueCredential(
        AuthenticatedDeviceContext $context,
        int $expiresAt,
    ): IssuedRememberedDeviceCredential {
        self::$issueCalls++;
        self::$lastContext = $context;
        return new IssuedRememberedDeviceCredential(
            'rotated-customer-token',
            'frontend-device-public-id',
            $expiresAt,
        );
    }

    public function resolveCredential(string $area, string $rawToken): RememberedDeviceCredentialValidation
    {
        self::$resolveCalls++;
        if (self::$validResolutionsRemaining <= 0) {
            return RememberedDeviceCredentialValidation::invalid('invalid_or_expired');
        }
        self::$validResolutionsRemaining--;
        return RememberedDeviceCredentialValidation::valid(
            '7',
            'frontend-device-public-id',
            time() + 3600,
        );
    }

    public function revokeCredential(string $area, string $rawToken, string $reason = 'logout'): void
    {
    }
}

final class FailingCustomerRememberProvider implements RememberedDeviceCredentialProviderInterface
{
    public static int $issueCalls = 0;
    /** @var list<string> */
    public static array $events = [];

    public static function resetState(): void
    {
        self::$issueCalls = 0;
        self::$events = [];
    }

    public function issueCredential(
        AuthenticatedDeviceContext $context,
        int $expiresAt,
    ): IssuedRememberedDeviceCredential {
        self::$issueCalls++;
        self::$events[] = 'issue';
        throw new \RuntimeException('simulated configured provider failure');
    }

    public function resolveCredential(string $area, string $rawToken): RememberedDeviceCredentialValidation
    {
        return RememberedDeviceCredentialValidation::invalid();
    }

    public function revokeCredential(string $area, string $rawToken, string $reason = 'logout'): void
    {
        self::$events[] = 'revoke:' . $area . ':' . $rawToken . ':' . $reason;
    }
}
