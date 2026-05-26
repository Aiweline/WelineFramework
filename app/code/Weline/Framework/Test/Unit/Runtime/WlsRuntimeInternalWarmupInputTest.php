<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\WlsRuntime;

final class WlsRuntimeInternalWarmupInputTest extends TestCase
{
    public function testMalformedWarmupAuthorityIsRejected(): void
    {
        $runtime = new WlsRuntime();
        $method = new \ReflectionMethod($runtime, 'normalizeInternalWarmupHost');
        $method->setAccessible(true);

        self::assertNull($method->invoke($runtime, '127.0.0.1]'));
        self::assertNull($method->invoke($runtime, 'https://127.0.0.1]/catalog'));
        self::assertSame('127.0.0.1', $method->invoke($runtime, 'https://127.0.0.1/catalog'));
    }

    public function testWarmupPathUsesOriginForm(): void
    {
        $runtime = new WlsRuntime();
        $method = new \ReflectionMethod($runtime, 'normalizeInternalWarmupPath');
        $method->setAccessible(true);

        self::assertSame('/catalog/category/sports?store=en', $method->invoke($runtime, 'https://127.0.0.1/catalog/category/sports?store=en'));
        self::assertSame('/catalog/category/sports', $method->invoke($runtime, 'catalog/category/sports'));
    }

    public function testDynamicWarmupValidationAllowsSlowTargetByDefault(): void
    {
        \putenv('WLS_WORKER_DYNAMIC_WARMUP_BLOCK_ON_TARGET_MS');

        $runtime = new WlsRuntime();
        $method = new \ReflectionMethod($runtime, 'validateDynamicFirstRenderWarmup');
        $method->setAccessible(true);

        $result = $method->invoke($runtime, [
            'headers' => [
                'X-WLS-FPC-Status' => 'BYPASS',
                'X-WLS-Category-View-Cache' => 'local',
            ],
            'status_code' => 200,
            'elapsed_ms' => 120.0,
        ], 70.0);

        self::assertTrue($result['ok']);
        self::assertSame('local', $result['cache']);
        self::assertStringStartsWith('ready:slow', $result['reason']);
    }

    public function testDynamicWarmupValidationCanStrictlyFailOnTarget(): void
    {
        \putenv('WLS_WORKER_DYNAMIC_WARMUP_BLOCK_ON_TARGET_MS=1');

        try {
            $runtime = new WlsRuntime();
            $method = new \ReflectionMethod($runtime, 'validateDynamicFirstRenderWarmup');
            $method->setAccessible(true);

            $result = $method->invoke($runtime, [
                'headers' => [
                    'X-WLS-FPC-Status' => 'BYPASS',
                    'X-WLS-Category-View-Cache' => 'local',
                ],
                'status_code' => 200,
                'elapsed_ms' => 120.0,
            ], 70.0);

            self::assertFalse($result['ok']);
            self::assertSame('local', $result['cache']);
            self::assertSame('elapsed_ms=120 target_ms=70', $result['reason']);
        } finally {
            \putenv('WLS_WORKER_DYNAMIC_WARMUP_BLOCK_ON_TARGET_MS');
        }
    }

    public function testDeferredDynamicWarmupDefaultsToOwnerWorkerAfterReady(): void
    {
        $this->withDynamicWarmupEnv([
            'WLS_WORKER_DYNAMIC_DEFERRED_WARMUP_ENABLED' => '1',
            'WLS_WORKER_DYNAMIC_DEFERRED_WARMUP_OWNER_WORKER_ID' => null,
            'WLS_WORKER_ID' => '2',
            'WLS_PROCESS_ROLE' => 'worker',
        ], function (): void {
            $runtime = new WlsRuntime();

            self::assertFalse($this->invokePrivate($runtime, 'shouldRunDeferredDynamicFirstRenderWarmup'));
        });

        $this->withDynamicWarmupEnv([
            'WLS_WORKER_DYNAMIC_DEFERRED_WARMUP_ENABLED' => '1',
            'WLS_WORKER_DYNAMIC_DEFERRED_WARMUP_OWNER_WORKER_ID' => null,
            'WLS_WORKER_ID' => '1',
            'WLS_PROCESS_ROLE' => 'worker',
        ], function (): void {
            $runtime = new WlsRuntime();

            self::assertTrue($this->invokePrivate($runtime, 'shouldRunDeferredDynamicFirstRenderWarmup'));
        });
    }

    public function testDeferredDynamicWarmupHonorsExplicitOwnerWorker(): void
    {
        $this->withDynamicWarmupEnv([
            'WLS_WORKER_DYNAMIC_DEFERRED_WARMUP_ENABLED' => '1',
            'WLS_WORKER_DYNAMIC_DEFERRED_WARMUP_OWNER_WORKER_ID' => '1',
            'WLS_WORKER_ID' => '2',
            'WLS_PROCESS_ROLE' => 'worker',
        ], function (): void {
            $runtime = new WlsRuntime();

            self::assertFalse($this->invokePrivate($runtime, 'shouldRunDeferredDynamicFirstRenderWarmup'));
        });

        $this->withDynamicWarmupEnv([
            'WLS_WORKER_DYNAMIC_DEFERRED_WARMUP_ENABLED' => '1',
            'WLS_WORKER_DYNAMIC_DEFERRED_WARMUP_OWNER_WORKER_ID' => '2',
            'WLS_WORKER_ID' => '2',
            'WLS_PROCESS_ROLE' => 'worker',
        ], function (): void {
            $runtime = new WlsRuntime();

            self::assertTrue($this->invokePrivate($runtime, 'shouldRunDeferredDynamicFirstRenderWarmup'));
        });
    }

    public function testDeferredDynamicWarmupRunsWhenEnabledForOwnerWorker(): void
    {
        $this->withDynamicWarmupEnv([
            'WLS_WORKER_DYNAMIC_DEFERRED_WARMUP_ENABLED' => '1',
            'WLS_WORKER_DYNAMIC_DEFERRED_WARMUP_OWNER_WORKER_ID' => '2',
            'WLS_WORKER_ID' => '2',
            'WLS_PROCESS_ROLE' => 'worker',
        ], function (): void {
            $runtime = new WlsRuntime();

            self::assertTrue($this->invokePrivate($runtime, 'shouldRunDeferredDynamicFirstRenderWarmup'));
        });
    }

    public function testReadyGateDynamicWarmupDefaultsOffBeforeReady(): void
    {
        $this->withDynamicWarmupEnv([
            'WLS_WORKER_DYNAMIC_READY_GATE_ENABLED' => null,
            'WLS_WORKER_ID' => '2',
            'WLS_PROCESS_ROLE' => 'worker',
        ], function (): void {
            $runtime = new WlsRuntime();

            self::assertFalse($this->invokePrivate($runtime, 'shouldRunReadyGateDynamicFirstRenderWarmup'));
        });

        $this->withDynamicWarmupEnv([
            'WLS_WORKER_DYNAMIC_READY_GATE_ENABLED' => '1',
            'WLS_WORKER_ID' => '2',
            'WLS_PROCESS_ROLE' => 'worker',
        ], function (): void {
            $runtime = new WlsRuntime();

            self::assertTrue($this->invokePrivate($runtime, 'shouldRunReadyGateDynamicFirstRenderWarmup'));
        });

        $this->withDynamicWarmupEnv([
            'WLS_WORKER_DYNAMIC_READY_GATE_ENABLED' => '1',
            'WLS_WORKER_ID' => '2',
            'WLS_PROCESS_ROLE' => 'maintenance',
        ], function (): void {
            $runtime = new WlsRuntime();

            self::assertFalse($this->invokePrivate($runtime, 'shouldRunReadyGateDynamicFirstRenderWarmup'));
        });
    }

    public function testReadyGateDynamicWarmupCanBeDisabled(): void
    {
        $this->withDynamicWarmupEnv([
            'WLS_WORKER_DYNAMIC_READY_GATE_ENABLED' => '0',
            'WLS_WORKER_ID' => '1',
            'WLS_PROCESS_ROLE' => 'worker',
        ], function (): void {
            $runtime = new WlsRuntime();

            self::assertFalse($this->invokePrivate($runtime, 'shouldRunReadyGateDynamicFirstRenderWarmup'));
        });
    }

    public function testReadyGateDynamicWarmupUsesCriticalPathsByDefault(): void
    {
        $this->withDynamicWarmupEnv([
            'WLS_WORKER_DYNAMIC_READY_GATE_DISCOVERY' => 'critical',
            'WLS_WORKER_DYNAMIC_READY_GATE_MAX_PATHS' => '3',
        ], function (): void {
            $runtime = new WlsRuntime();
            $paths = $this->invokePrivate($runtime, 'resolveReadyGateDynamicWarmupPaths');

            self::assertSame([
                '/',
                '/catalog/category/clothing',
                '/en_US/catalog/category/clothing',
            ], $paths);
        });
    }

    public function testReadyGateWarmupPathsAreShardedByWorkerId(): void
    {
        $this->withDynamicWarmupEnv([
            'WLS_WORKER_ID' => '2',
            'WLS_WORKER_COUNT' => '3',
        ], function (): void {
            $runtime = new WlsRuntime();

            self::assertSame(
                ['b', 'e'],
                $this->invokePrivate($runtime, 'shardReadyGateWarmupPaths', ['a', 'b', 'c', 'd', 'e', 'f'])
            );
        });
    }

    public function testReadyGateDynamicWarmupShardsCriticalPathsAcrossWorkers(): void
    {
        $this->withDynamicWarmupEnv([
            'WLS_WORKER_DYNAMIC_READY_GATE_DISCOVERY' => 'critical',
            'WLS_WORKER_DYNAMIC_READY_GATE_MAX_PATHS' => '8',
            'WLS_WORKER_ID' => '2',
            'WLS_WORKER_COUNT' => '3',
        ], function (): void {
            $runtime = new WlsRuntime();
            $paths = $this->invokePrivate($runtime, 'resolveReadyGateDynamicWarmupPaths');

            self::assertSame([
                '/catalog/category/clothing',
                '/zh_Hans_CN/catalog/category/clothing',
                '/en_US/product/demo-category-81-sports',
            ], $paths);
        });
    }

    public function testReadyGateDynamicWarmupDiscoveryIsExplicitOptIn(): void
    {
        $this->withDynamicWarmupEnv([
            'WLS_WORKER_DYNAMIC_READY_GATE_DISCOVERY' => 'critical',
        ], function (): void {
            $runtime = new WlsRuntime();

            self::assertFalse($this->invokePrivate($runtime, 'shouldUseReadyGateDynamicDiscovery'));
        });

        $this->withDynamicWarmupEnv([
            'WLS_WORKER_DYNAMIC_READY_GATE_DISCOVERY' => 'auto',
        ], function (): void {
            $runtime = new WlsRuntime();

            self::assertTrue($this->invokePrivate($runtime, 'shouldUseReadyGateDynamicDiscovery'));
        });
    }

    public function testBackendReadyGateWarmupDefaultsToBusinessWorkers(): void
    {
        $this->withDynamicWarmupEnv([
            'WLS_WORKER_BACKEND_READY_GATE_WARMUP' => null,
            'WLS_PROCESS_ROLE' => 'worker',
        ], function (): void {
            $runtime = new WlsRuntime();

            self::assertTrue($this->invokePrivate($runtime, 'shouldRunReadyGateBackendFirstRenderWarmup'));
        });

        $this->withDynamicWarmupEnv([
            'WLS_WORKER_BACKEND_READY_GATE_WARMUP' => null,
            'WLS_PROCESS_ROLE' => 'maintenance',
        ], function (): void {
            $runtime = new WlsRuntime();

            self::assertFalse($this->invokePrivate($runtime, 'shouldRunReadyGateBackendFirstRenderWarmup'));
        });
    }

    public function testDeferredBackendWarmupDefaultsToOwnerWorker(): void
    {
        $this->withDynamicWarmupEnv([
            'WLS_WORKER_BACKEND_DEFERRED_WARMUP' => null,
            'WLS_WORKER_BACKEND_DEFERRED_WARMUP_OWNER_WORKER_ID' => null,
            'WLS_WORKER_ID' => '2',
            'WLS_PROCESS_ROLE' => 'worker',
        ], function (): void {
            $runtime = new WlsRuntime();

            self::assertFalse($this->invokePrivate($runtime, 'shouldRunDeferredBackendFirstRenderWarmup'));
        });

        $this->withDynamicWarmupEnv([
            'WLS_WORKER_BACKEND_DEFERRED_WARMUP' => null,
            'WLS_WORKER_BACKEND_DEFERRED_WARMUP_OWNER_WORKER_ID' => null,
            'WLS_WORKER_ID' => '1',
            'WLS_PROCESS_ROLE' => 'worker',
        ], function (): void {
            $runtime = new WlsRuntime();

            self::assertTrue($this->invokePrivate($runtime, 'shouldRunDeferredBackendFirstRenderWarmup'));
        });
    }

    public function testBackendWarmupPathsIncludePlainAndLocalizedAdminRoutes(): void
    {
        $runtime = new WlsRuntime();
        $paths = $this->invokePrivate($runtime, 'resolveReadyGateBackendWarmupPaths');

        self::assertNotEmpty($paths);
        self::assertNotSame([], \array_values(\array_filter($paths, static fn (string $path): bool => \str_ends_with($path, '/admin/login'))));
        self::assertNotSame([], \array_values(\array_filter($paths, static fn (string $path): bool => \str_contains($path, '/CNY/zh_Hans_CN/admin/login'))));
        self::assertNotSame([], \array_values(\array_filter($paths, static fn (string $path): bool => \str_contains($path, '/USD/en_US/admin/login'))));
        self::assertNotSame([], \array_values(\array_filter($paths, static fn (string $path): bool => \str_ends_with($path, '/admin'))));
        self::assertNotSame([], \array_values(\array_filter($paths, static fn (string $path): bool => \str_contains($path, '/USD/en_US/admin'))));
    }

    public function testBackendReadyGateWarmupHostsDefaultToLoopbackOnly(): void
    {
        $runtime = new WlsRuntime();
        $hosts = $this->invokePrivate($runtime, 'resolveReadyGateBackendWarmupHosts');

        self::assertSame(['127.0.0.1'], $hosts);
    }

    private function invokePrivate(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }

    /**
     * @param array<string, string|null> $values
     */
    private function withDynamicWarmupEnv(array $values, callable $callback): void
    {
        $previousEnv = [];
        $previousServer = [];
        foreach ($values as $key => $value) {
            $previousEnv[$key] = \getenv($key);
            $previousServer[$key] = $_SERVER[$key] ?? null;
            if ($value === null) {
                \putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
                continue;
            }

            \putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            $callback();
        } finally {
            foreach ($values as $key => $_) {
                if ($previousEnv[$key] === false) {
                    \putenv($key);
                    unset($_ENV[$key]);
                } else {
                    \putenv($key . '=' . $previousEnv[$key]);
                    $_ENV[$key] = $previousEnv[$key];
                }

                if ($previousServer[$key] === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $previousServer[$key];
                }
            }
        }
    }
}
