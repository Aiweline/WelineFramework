<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Session\Session;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\Storage\SessionStorageInterface;
use Weline\Framework\Session\Strategy\WlsStrategy;

final class SessionFiberRequestIsolationTest extends TestCase
{
    private SessionFactory $factory;

    protected function setUp(): void
    {
        Runtime::setMode('wls');
        SessionFactory::resetAll();
        Session::resetRequestState();

        $this->factory = new SessionFactory([
            'default' => 'file',
            'wls_managed' => false,
            'lifetime' => 3600,
            'drivers' => [
                'file' => ['path' => 'var/test_session/'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        SessionFactory::resetAll();
        Session::resetRequestState();
        Runtime::resetModeCache();
    }

    public function testFactoryKeepsSessionAndAuthenticatedSessionInsideOwningFiber(): void
    {
        $sessionA = null;
        $sessionAAfterResume = null;
        $authA = null;
        $authAAfterResume = null;
        $sessionB = null;
        $authB = null;

        $fiberA = new \Fiber(function () use (
            &$sessionA,
            &$sessionAAfterResume,
            &$authA,
            &$authAAfterResume,
        ): void {
            $sessionA = $this->factory->createSession();
            $authA = $this->factory->createFrontendSession();
            \Fiber::suspend();
            $sessionAAfterResume = $this->factory->createSession();
            $authAAfterResume = $this->factory->createFrontendSession();
        });

        $fiberB = new \Fiber(function () use (&$sessionB, &$authB): void {
            $sessionB = $this->factory->createSession();
            $authB = $this->factory->createFrontendSession();
        });

        $fiberA->start();
        $fiberB->start();

        self::assertNotSame($sessionA, $sessionB);
        self::assertNotSame($authA, $authB);

        $fiberA->resume();

        self::assertSame($sessionA, $sessionAAfterResume);
        self::assertSame($authA, $authAAfterResume);
    }

    public function testAuthenticatedIdentityAndDeviceValidationCacheStayInsideOwningFiber(): void
    {
        $userIdA = null;
        $userIdAAfterResume = null;
        $rawUserIdB = null;
        $userIdB = 'not-read';

        $validation = new \ReflectionProperty(
            \Weline\Framework\Session\Auth\AuthenticatedSession::class,
            'deviceValidationResult',
        );

        $fiberA = new \Fiber(function () use (
            &$userIdA,
            &$userIdAAfterResume,
            $validation,
        ): void {
            $session = $this->factory->createFrontendSession();
            $session->start('fiber-auth-a');
            $session->getSession()->set('WF_FRONTEND_USER', 'fiber-a@example.test');
            $session->getSession()->set('WF_FRONTEND_USER_ID', 101);
            $validation->setValue($session, true);
            $userIdA = $session->getUserId();
            \Fiber::suspend();
            $userIdAAfterResume = $this->factory->createFrontendSession()->getUserId();
        });

        $fiberB = new \Fiber(function () use (
            &$rawUserIdB,
            &$userIdB,
            $validation,
        ): void {
            $session = $this->factory->createFrontendSession();
            $session->start('fiber-auth-b');
            $session->getSession()->set('WF_FRONTEND_USER', 'fiber-b@example.test');
            $session->getSession()->set('WF_FRONTEND_USER_ID', 202);
            $validation->setValue($session, false);
            $rawUserIdB = $session->getSession()->get('WF_FRONTEND_USER_ID');
            $userIdB = $session->getUserId();
        });

        $fiberA->start();
        $fiberB->start();
        $fiberA->resume();

        self::assertSame(101, $userIdA);
        self::assertSame(101, $userIdAAfterResume);
        self::assertSame(202, $rawUserIdB);
        self::assertNull($userIdB);
    }

    public function testResetRequestInstancesClearsOnlyCurrentFiber(): void
    {
        $sessionA = null;
        $sessionAAfterResume = null;
        $sessionBBeforeReset = null;
        $sessionBAfterReset = null;

        $fiberA = new \Fiber(function () use (&$sessionA, &$sessionAAfterResume): void {
            $sessionA = $this->factory->createSession();
            \Fiber::suspend();
            $sessionAAfterResume = $this->factory->createSession();
        });

        $fiberB = new \Fiber(function () use (&$sessionBBeforeReset, &$sessionBAfterReset): void {
            $sessionBBeforeReset = $this->factory->createSession();
            $this->factory->resetRequestInstances();
            $sessionBAfterReset = $this->factory->createSession();
        });

        $fiberA->start();
        $fiberB->start();
        $fiberA->resume();

        self::assertNotSame($sessionBBeforeReset, $sessionBAfterReset);
        self::assertSame($sessionA, $sessionAAfterResume);
        self::assertNotSame($sessionA, $sessionBAfterReset);
    }

    public function testFlushRequestSessionsDoesNotFlushSuspendedPeerFiber(): void
    {
        $storage = $this->createInMemoryStorage();
        $sessionA = new Session($storage, new WlsStrategy($storage, ['lifetime' => 3600]), 3600);
        $sessionB = new Session($storage, new WlsStrategy($storage, ['lifetime' => 3600]), 3600);

        $fiberA = new \Fiber(static function () use ($sessionA): void {
            $sessionA->start('fiber-a');
            $sessionA->set('owner', 'a');
            \Fiber::suspend();
            Session::flushRequestSessions();
        });

        $fiberB = new \Fiber(static function () use ($sessionB): void {
            $sessionB->start('fiber-b');
            $sessionB->set('owner', 'b');
            Session::flushRequestSessions();
        });

        $fiberA->start();
        $fiberB->start();

        self::assertSame([], $storage->read('fiber-a'));
        self::assertSame(['owner' => 'b'], $storage->read('fiber-b'));

        $fiberA->resume();

        self::assertSame(['owner' => 'a'], $storage->read('fiber-a'));
    }

    public function testExplicitTargetCleanupDropsOnlyTargetFiberState(): void
    {
        $sessionA = null;
        $sessionB = null;
        $sessionBAfterResume = null;

        $fiberA = new \Fiber(function () use (&$sessionA): void {
            $sessionA = $this->factory->createSession();
        });
        $fiberB = new \Fiber(function () use (&$sessionB, &$sessionBAfterResume): void {
            $sessionB = $this->factory->createSession();
            \Fiber::suspend();
            $sessionBAfterResume = $this->factory->createSession();
        });

        $fiberA->start();
        $fiberB->start();
        self::assertSame(2, $this->factory->getFiberRequestScopeCount());

        $this->factory->clearRequestInstancesForFiber($fiberA);

        self::assertSame(1, $this->factory->getFiberRequestScopeCount());
        $fiberB->resume();
        self::assertSame($sessionB, $sessionBAfterResume);
        self::assertNotSame($sessionA, $sessionB);
    }

    public function testWeakMapsReleaseRequestStateAfterFiberGarbageCollection(): void
    {
        $storage = $this->createInMemoryStorage();
        $fiber = new \Fiber(function () use ($storage): void {
            $this->factory->createSession();
            $session = new Session($storage, new WlsStrategy($storage, ['lifetime' => 3600]), 3600);
            $session->start('gc-fiber');
        });

        $fiber->start();
        self::assertSame(1, $this->factory->getFiberRequestScopeCount());
        self::assertSame(1, Session::getFiberRequestStateCount());

        unset($fiber);
        \gc_collect_cycles();

        self::assertSame(0, $this->factory->getFiberRequestScopeCount());
        self::assertSame(0, Session::getFiberRequestStateCount());
    }

    public function testRepeatedRequestFibersDoNotAccumulateSessionBuckets(): void
    {
        $storage = $this->createInMemoryStorage();

        for ($request = 0; $request < 1_000; $request++) {
            $fiber = new \Fiber(function () use ($storage, $request): void {
                $this->factory->createSession();
                $session = new Session($storage, new WlsStrategy($storage, ['lifetime' => 3600]), 3600);
                $session->start('request-' . $request);
                Session::resetRequestState();
                $this->factory->resetRequestInstances();
            });
            $fiber->start();
            unset($fiber);
        }
        \gc_collect_cycles();

        self::assertSame(0, $this->factory->getFiberRequestScopeCount());
        self::assertSame(0, Session::getFiberRequestStateCount());
    }

    private function createInMemoryStorage(): SessionStorageInterface
    {
        return new class implements SessionStorageInterface {
            /** @var array<string, array<string, mixed>> */
            private array $store = [];

            public function read(string $sessionId): array
            {
                return $this->store[$sessionId] ?? [];
            }

            public function write(string $sessionId, array $data, int $ttl): bool
            {
                $this->store[$sessionId] = $data;
                return true;
            }

            public function destroy(string $sessionId): bool
            {
                unset($this->store[$sessionId]);
                return true;
            }

            public function exists(string $sessionId): bool
            {
                return \array_key_exists($sessionId, $this->store);
            }

            public function touch(string $sessionId, int $ttl): bool
            {
                return $this->exists($sessionId);
            }

            public function gc(int $maxLifetime): int
            {
                return 0;
            }

            public function getConfig(): array
            {
                return [];
            }

            public function list(array $options = []): array
            {
                return [];
            }
        };
    }
}
