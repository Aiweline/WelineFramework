<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Weline\Framework\Session\Auth\AuthenticableInterface;
use Weline\Framework\Session\FrontendSessionCookieMigrator;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\SessionInterface;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllSessionContractsPass().
 */
final class SessionContractsTest extends TestCase
{
    public function testAllSessionContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'BackendRestDeviceFailClosed::testLegacySessionIdRecoveryIsRestrictedToAnAbsentDeviceRegistry' => function () {
            $this->assert_BackendRestDeviceFailClosed_testLegacySessionIdRecoveryIsRestrictedToAnAbsentDeviceRegistry();
        },
            'FrontendSessionCookieMigratorFpc::testMigrateIfNeededDoesNotStartSessionWithoutRequestCookies' => function () {
            $this->setUp_FrontendSessionCookieMigratorFpc();
            try {
                $this->assert_FrontendSessionCookieMigratorFpc_testMigrateIfNeededDoesNotStartSessionWithoutRequestCookies();
            } finally {
                $this->tearDown_FrontendSessionCookieMigratorFpc();
            }
        },
            'SessionSecretLogging::testSessionSecretsAreNeverIncludedInLogs' => function () {
            $this->assert_SessionSecretLogging_testSessionSecretsAreNeverIncludedInLogs();
        },
        ];
    }

private function assert_BackendRestDeviceFailClosed_testLegacySessionIdRecoveryIsRestrictedToAnAbsentDeviceRegistry(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Backend/Controller/BackendRestController.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('resolveDetailed(AuthenticatedDeviceRegistryInterface::class)', $source);
    self::assertStringContainsString('RuntimeProviderResolution::NOT_CONFIGURED', $source);
    $failClosedGuard = strpos($source, 'if (!$this->legacySessionRecoveryAllowed())');
    $legacyLookup = strpos($source, '$this->resolveBackendUser($sessionId)');
    self::assertNotFalse($failClosedGuard);
    self::assertNotFalse($legacyLookup);
    self::assertLessThan($legacyLookup, $failClosedGuard);
}

private function setUp_FrontendSessionCookieMigratorFpc(): void
{
    FrontendSessionCookieMigrator::resetRequestState();
}

private function tearDown_FrontendSessionCookieMigratorFpc(): void
{
    FrontendSessionCookieMigrator::resetRequestState();
}

private function assert_FrontendSessionCookieMigratorFpc_testMigrateIfNeededDoesNotStartSessionWithoutRequestCookies(): void
{
    $session = new class implements SessionInterface
    {
        public bool $started = false;
        public bool $startCalled = false;
        public function start(?string $sessionId = null): void
        {
            $this->startCalled = true;
            $this->started = true;
        }
        public function isStarted(): bool
        {
            return $this->started;
        }
        public function getId(): string
        {
            return '';
        }
        public function get(string $key): mixed
        {
            return null;
        }
        public function set(string $key, mixed $value): void
        {
        }
        public function has(string $key): bool
        {
            return false;
        }
        public function delete(string $key): void
        {
        }
        public function all(): array
        {
            return [];
        }
        public function clear(): void
        {
        }
        public function save(): void
        {
        }
        public function destroy(): void
        {
        }
        public function regenerate(bool $deleteOldSession = true): void
        {
        }
        public function reassertCookieWire(): void
        {
        }
        public function isLogin(): bool
        {
            return false;
        }
        public function login(AuthenticableInterface $user): void
        {
        }
        public function getLoginUser(string $model = ''): ?AuthenticableInterface
        {
            return null;
        }
        public function getLoginUsername(): ?string
        {
            return null;
        }
        public function getLoginUserID(): int|string|null
        {
            return null;
        }
        public function logout(): void
        {
        }
        public function getOriginSession(): SessionInterface
        {
            return $this;
        }
    };
    $factory = $this->createMock(SessionFactory::class);
    $factory->expects(self::never())->method('createStorage');
    FrontendSessionCookieMigrator::migrateIfNeeded($session, $factory);
    self::assertFalse($session->startCalled);
    self::assertFalse($session->started);
}

/** @return iterable<string,array{0:string}> */
private function sessionRuntimeSources_SessionSecretLogging(): iterable
{
    $frameworkRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Framework';
    yield 'session facade' => [$frameworkRoot . '/Session/Session.php'];
    yield 'file storage' => [$frameworkRoot . '/Session/Storage/FileStorage.php'];
    yield 'wls shared storage' => [$frameworkRoot . '/Session/Storage/WlsSharedStorage.php'];
    yield 'http request command' => [$frameworkRoot . '/Http/Console/Http/Request.php'];
    yield 'backend redirect observer' => [$frameworkRoot . '/../Backend/Observer/ResponseRedirectBefore.php'];
    yield 'acl route observer' => [$frameworkRoot . '/../Acl/Observer/RouteBefore.php'];
}

#[DataProvider('sessionRuntimeSources')]
private function assert_SessionSecretLogging_testSessionSecretsAreNeverIncludedInLogs(string $path): void
{
    $source = (string) file_get_contents($path);
    self::assertStringNotContainsString("'sid='", $source);
    self::assertStringNotContainsString("'sessionId='", $source);
    self::assertDoesNotMatchRegularExpression('/(?:w_log_|sessionLog\()[^;]*substr\s*\(\s*\$sessionId/s', $source);
    self::assertDoesNotMatchRegularExpression('/(?:w_log_|sessionLog\()[^;]*substr\s*\(\s*\$this->sessionId/s', $source);
}
}
