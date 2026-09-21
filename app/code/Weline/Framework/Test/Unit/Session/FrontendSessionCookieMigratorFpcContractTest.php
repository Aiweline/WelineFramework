<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Session\Auth\AuthenticableInterface;
use Weline\Framework\Session\FrontendSessionCookieMigrator;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\SessionInterface;

/**
 * Guest storefront must not allocate a Session cookie during createFrontendSession
 * when the request has no customer/legacy Session jar — otherwise FPC refuses Set-Cookie.
 */
final class FrontendSessionCookieMigratorFpcContractTest extends TestCase
{
    protected function setUp(): void
    {
        FrontendSessionCookieMigrator::resetRequestState();
    }

    protected function tearDown(): void
    {
        FrontendSessionCookieMigrator::resetRequestState();
    }

    public function testMigrateIfNeededDoesNotStartSessionWithoutRequestCookies(): void
    {
        $session = new class implements SessionInterface {
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
}
