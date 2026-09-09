<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;
use Weline\Framework\Service\Query\Store\ArrayFrontendWorkerCredentialTransaction;
use Weline\Framework\Service\Query\Store\FrontendWorkerCredentialType;
use Weline\Framework\Service\Query\Store\FrontendWorkerStateStoreInterface;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeBinding;

final class FrontendWorkerSessionServiceTest extends TestCase
{
    public function testEffectiveSessionExpiresAtCapsLegacyUnboundTtl(): void
    {
        $now = 10_000;
        $legacyUnbound = [
            'created_at' => $now - 700,
            'expires_at' => $now + 6_500,
        ];
        self::assertSame(
            ($now - 700) + 600,
            FrontendWorkerSessionService::effectiveSessionExpiresAt($legacyUnbound, $now),
        );

        $bound = $legacyUnbound + ['backend_binding' => ['principal' => 'backend:1']];
        self::assertSame(
            $now + 6_500,
            FrontendWorkerSessionService::effectiveSessionExpiresAt($bound, $now),
        );
    }

    public function testArrayCredentialTransactionReclaimsLegacyUnboundSessions(): void
    {
        $now = 10_000;
        $unboundKey = \hash('sha256', 'legacy-unbound-session');
        $boundKey = \hash('sha256', 'bound-backend-session');
        $store = [
            'weline_frontend_worker_sessions' => [
                $unboundKey => [
                    'created_at' => $now - 700,
                    'expires_at' => $now + 6_500,
                ],
                $boundKey => [
                    'created_at' => $now - 700,
                    'expires_at' => $now + 6_500,
                    'backend_binding' => ['principal' => 'backend:1'],
                ],
            ],
        ];
        $tx = new ArrayFrontendWorkerCredentialTransaction($store);

        self::assertSame(1, $tx->countRetained(FrontendWorkerCredentialType::SESSION, null, $now));
        $tx->deleteExpired($now, FrontendWorkerCredentialType::SESSION);
        self::assertArrayNotHasKey($unboundKey, $store['weline_frontend_worker_sessions']);
        self::assertArrayHasKey($boundKey, $store['weline_frontend_worker_sessions']);
        self::assertSame(1, $tx->countRetained(FrontendWorkerCredentialType::SESSION, null, $now));
    }

    public function testStreamUrlUsesTheConfiguredRestFrontendPrefix(): void
    {
        $reflection = new \ReflectionClass(FrontendWorkerSessionService::class);
        /** @var FrontendWorkerSessionService $service */
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildStreamUrl');
        $ticket = 'stream-ticket';

        $url = (string)$method->invoke($service, $ticket);
        $prefix = \trim((string)(Env::getAreaRoutePrefix('rest_frontend') ?: 'api'), '/');
        $prefix = $prefix !== '' ? $prefix : 'api';

        self::assertSame('/' . $prefix . '/framework/stream?ticket=' . $ticket, $url);
    }

    public function testStreamTicketPreservesOwnerBinding(): void
    {
        $service = new FrontendWorkerSessionService();
        $owner = [
            'area' => 'backend',
            'principal' => 'backend:7',
        ];

        $created = $service->createStreamTicket('page_builder.aiSiteStream', ['public_id' => 'site-1'], $owner);
        self::assertSame($owner, $created['owner'] ?? null);

        $consumed = $service->consumeStreamTicket((string)$created['ticket']);
        self::assertSame('page_builder.aiSiteStream', $consumed['channel']);
        self::assertSame(['public_id' => 'site-1'], $consumed['params']);
        self::assertSame($owner, $consumed['owner']);
    }

    public function testScopeBindingUsesTheSharedSixtySecondClockSkewBoundary(): void
    {
        $service = (new \ReflectionClass(FrontendWorkerSessionService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(FrontendWorkerSessionService::class, 'assertBindingUsable');
        $scope = ScopeIdentity::channel(0, 'default', 'main', 'web', ScopeIdentity::MODE_TEST);

        $method->invoke($service, new FrontendWorkerScopeBinding(
            $scope,
            'shop.example.test',
            hash('sha256', 'accepted-token'),
            1_060,
            2_860,
            true,
        ), 1_000);
        self::addToAssertionCount(1);

        try {
            $method->invoke($service, new FrontendWorkerScopeBinding(
                $scope,
                'shop.example.test',
                hash('sha256', 'rejected-token'),
                1_061,
                2_861,
                true,
            ), 1_000);
            self::fail('A Scope binding beyond the clock-skew boundary was accepted.');
        } catch (FrontendQueryException $exception) {
            self::assertSame('auth_error', $exception->getErrorCode());
            self::assertSame(401, $exception->getHttpStatus());
        }
    }

    public function testScopeBootstrapWrongProofDoesNotConsumeAndValidProofConsumesOnce(): void
    {
        $service = new FrontendWorkerSessionService(new SessionMemoryStateStore());
        $now = time();
        $binding = new FrontendWorkerScopeBinding(
            ScopeIdentity::channel(0, 'default', 'main', 'web', ScopeIdentity::MODE_TEST),
            'shop.example.test',
            hash('sha256', 'valid-token'),
            $now,
            $now + 1800,
            true,
        );
        $bootstrap = $service->createScopeBootstrap($binding);

        try {
            $service->createSessionFromScopeBootstrap(
                'test-deploy',
                'test-worker',
                $bootstrap['bootstrap_id'],
                hash('sha256', 'wrong-token'),
                $binding->digest(),
            );
            self::fail('Wrong bootstrap proof was accepted.');
        } catch (FrontendQueryException $exception) {
            self::assertSame('auth_error', $exception->getErrorCode());
            self::assertSame(401, $exception->getHttpStatus());
        }

        $session = $service->createSessionFromScopeBootstrap(
            'test-deploy',
            'test-worker',
            $bootstrap['bootstrap_id'],
            $binding->tokenFingerprint,
            $binding->digest(),
        );
        self::assertTrue($session['scope_bound']);
        self::assertSame('test-deploy', $session['deploy_version']);
        self::assertSame('test-worker', $session['worker_build_id']);

        try {
            $service->createSessionFromScopeBootstrap(
                'test-deploy',
                'test-worker',
                $bootstrap['bootstrap_id'],
                $binding->tokenFingerprint,
                $binding->digest(),
            );
            self::fail('Consumed Scope bootstrap was replayed.');
        } catch (FrontendQueryException $exception) {
            self::assertSame('auth_error', $exception->getErrorCode());
            self::assertSame(401, $exception->getHttpStatus());
        }
    }

    public function testResolveConfiguredSessionStoreDriverHonorsExplicitOverride(): void
    {
        $env = Env::getInstance();
        $previousDriver = Env::get('wls.frontend_worker_session_store_driver', 'cache');
        try {
            $env->applyRuntimeConfig([
                'wls' => ['frontend_worker_session_store_driver' => 'local'],
            ]);
            self::assertSame('local', FrontendWorkerSessionService::resolveConfiguredSessionStoreDriver());

            $env->applyRuntimeConfig([
                'wls' => ['frontend_worker_session_store_driver' => 'database'],
            ]);
            self::assertSame('database', FrontendWorkerSessionService::resolveConfiguredSessionStoreDriver());

            $env->applyRuntimeConfig([
                'wls' => ['frontend_worker_session_store_driver' => 'cache'],
            ]);
            self::assertSame('cache', FrontendWorkerSessionService::resolveConfiguredSessionStoreDriver());
        } finally {
            $env->applyRuntimeConfig([
                'wls' => [
                    'frontend_worker_session_store_driver' => \is_string($previousDriver) && $previousDriver !== ''
                        ? $previousDriver
                        : 'cache',
                ],
            ]);
        }
    }

    public function testDefaultSessionStoreDriverIsCache(): void
    {
        $env = Env::getInstance();
        $previousDriver = Env::get('wls.frontend_worker_session_store_driver', 'cache');
        try {
            $env->applyRuntimeConfig([
                'wls' => ['frontend_worker_session_store_driver' => ''],
            ]);
            self::assertSame('cache', FrontendWorkerSessionService::defaultSessionStoreDriver());
            self::assertSame('cache', FrontendWorkerSessionService::resolveConfiguredSessionStoreDriver());
        } finally {
            $env->applyRuntimeConfig([
                'wls' => [
                    'frontend_worker_session_store_driver' => \is_string($previousDriver) && $previousDriver !== ''
                        ? $previousDriver
                        : 'cache',
                ],
            ]);
        }
    }

    public function testRepairOwnedPrivateRegularFileTightensGroupWritableModes(): void
    {
        $path = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-fw-store-' . \bin2hex(\random_bytes(6));
        \file_put_contents($path, '{}');
        self::assertTrue(@\chmod($path, 0660));
        \clearstatcache(true, $path);
        self::assertSame(0660, \fileperms($path) & 0777);

        try {
            $service = (new \ReflectionClass(FrontendWorkerSessionService::class))->newInstanceWithoutConstructor();
            $method = new \ReflectionMethod(FrontendWorkerSessionService::class, 'repairOwnedPrivateRegularFile');
            $method->invoke($service, $path);

            \clearstatcache(true, $path);
            self::assertSame(0600, \fileperms($path) & 0777);
        } finally {
            @\unlink($path);
        }
    }

    public function testUnboundSessionUsesTenMinuteTtlNotBackendWindow(): void
    {
        $service = new FrontendWorkerSessionService(new SessionMemoryStateStore());
        $created = $service->createSession('test-deploy', 'test-worker');
        $ttl = (int)$created['expires_at'] - \time();
        self::assertGreaterThanOrEqual(590, $ttl);
        self::assertLessThanOrEqual(600, $ttl);
        self::assertSame('frontend', $created['attested_area']);
        self::assertFalse($created['scope_bound']);
    }

    public function testEffectiveSessionExpiresAtCapsLegacyUnboundRows(): void
    {
        $now = 1_000_000;
        $legacy = [
            'created_at' => $now - 601,
            'expires_at' => $now + 6600,
        ];
        self::assertSame($now - 1, FrontendWorkerSessionService::effectiveSessionExpiresAt($legacy, $now));

        $fresh = [
            'created_at' => $now - 30,
            'expires_at' => $now + 570,
        ];
        self::assertSame($now + 570, FrontendWorkerSessionService::effectiveSessionExpiresAt($fresh, $now));

        $backend = [
            'created_at' => $now - 601,
            'expires_at' => $now + 6600,
            'backend_binding' => ['x' => 1],
        ];
        self::assertSame($now + 6600, FrontendWorkerSessionService::effectiveSessionExpiresAt($backend, $now));
    }

    public function testLegacyUnboundSessionsAreReclaimedBeforeCapacityReject(): void
    {
        $store = new SessionMemoryStateStore();
        $service = new FrontendWorkerSessionService($store);
        $now = \time();
        $sessions = [];
        for ($i = 0; $i < 4096; $i++) {
            $token = 'legacy-' . $i;
            $sessions[\hash('sha256', $token)] = [
                'secret' => 'secret',
                'deploy_version' => 'test-deploy',
                'worker_build_id' => 'test-worker',
                'attested_area' => 'frontend',
                'created_at' => $now - 700,
                'expires_at' => $now + 6500,
            ];
        }
        $store->seed([
            'weline_frontend_worker_sessions' => $sessions,
        ]);

        $created = $service->createSession('test-deploy', 'test-worker');
        self::assertSame('frontend', $created['attested_area']);
        $ttl = (int)$created['expires_at'] - \time();
        self::assertLessThanOrEqual(600, $ttl);
    }
}

final class SessionMemoryStateStore implements FrontendWorkerStateStoreInterface
{
    /** @var array<string, mixed> */
    private array $state = [];

    /** @param array<string, mixed> $state */
    public function seed(array $state): void
    {
        $this->state = $state;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this->state);
    }

    public function driver(): string
    {
        return 'test-memory';
    }

    public function isShared(): bool
    {
        return false;
    }
}
