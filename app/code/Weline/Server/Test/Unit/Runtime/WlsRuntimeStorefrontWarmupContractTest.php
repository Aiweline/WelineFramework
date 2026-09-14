<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

\defined('DS') || \define('DS', DIRECTORY_SEPARATOR);
\defined('BP') || \define('BP', \dirname(__DIR__, 7) . DS);
\defined('APP_PATH') || \define('APP_PATH', BP . 'app' . DS);
\defined('APP_CODE_PATH') || \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
\defined('APP_ETC_PATH') || \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
\defined('DEV_PATH') || \define('DEV_PATH', BP . 'dev' . DS);
\defined('PUB') || \define('PUB', BP . 'pub' . DS);

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\WlsRuntime;

final class WlsRuntimeStorefrontWarmupContractTest extends TestCase
{
    private const RUNTIME = __DIR__ . '/../../../../Framework/Runtime/WlsRuntime.php';
    private const ENV_SAMPLE = __DIR__ . '/../../../../../../etc/env.sample.php';
    private const WORKER_COMMON = __DIR__ . '/../../../bin/worker_runtime_common.php';

    public function testDeferredWarmupWaitsForTheFirstEventLoopAndPendingTransportWork(): void
    {
        require_once self::WORKER_COMMON;

        self::assertFalse(
            wlsWorkerDeferredWarmupMayStart(
                false,
                true,
                false,
                false,
                false,
                false,
            ),
        );
        self::assertTrue(
            wlsWorkerDeferredWarmupMayStart(
                false,
                true,
                false,
                true,
                false,
                false,
            ),
        );
        self::assertFalse(
            wlsWorkerDeferredWarmupMayStart(
                false,
                true,
                false,
                true,
                true,
                false,
            ),
        );
        self::assertTrue(
            wlsWorkerDeferredWarmupMayStart(
                false,
                true,
                false,
                true,
                false,
                false,
            ),
        );
        self::assertFalse(wlsWorkerListenerHasPendingConnection(null));
    }

    public function testDeferredWarmupKeepsAnIdleGraceWindowAfterTheFirstLoop(): void
    {
        require_once self::WORKER_COMMON;

        $now = 100.0;
        self::assertGreaterThanOrEqual(
            $now + 3.0,
            wlsWorkerDeferredWarmupNotBefore($now, 1),
        );
        self::assertNotSame(
            wlsWorkerDeferredWarmupNotBefore($now, 1),
            wlsWorkerDeferredWarmupNotBefore($now, 2),
        );
    }

    public function testDynamicReadyGateKeepsTheUnifiedWarmupProviderAvailableWithoutBlockingStartup(): void
    {
        $source = file_get_contents(self::RUNTIME);

        self::assertIsString($source);
        self::assertStringContainsString(
            "Env::get('wls.worker.dynamic_ready_gate_enabled', '0')",
            $source,
        );
        self::assertStringContainsString('FpcWarmupProviderInterface', $source);
        self::assertStringContainsString('warmupPaths()', $source);
        self::assertStringContainsString(
            "Env::get('wls.worker.dynamic_ready_gate_fail_open', '1')",
            $source,
        );
        self::assertStringContainsString('storefront_deferred_warmup_enabled', $source);
        self::assertStringContainsString('runStorefrontFpcWarmupInternal', $source);
        self::assertStringContainsString('ready:fpc-hit', $source);
        self::assertStringContainsString('logDeferredStorefrontWarmupStage', $source);
    }

    public function testReadyGateBuildsABoundedAnonymousStorefrontFpcBeforePublicTraffic(): void
    {
        $runtimeSource = file_get_contents(self::RUNTIME);
        $envSource = file_get_contents(self::ENV_SAMPLE);

        self::assertIsString($runtimeSource);
        self::assertIsString($envSource);
        self::assertStringContainsString('runReadyGateStorefrontFpcWarmup', $runtimeSource);
        self::assertStringContainsString(
            "Env::get('wls.worker.storefront_ready_gate_enabled', '0')",
            $runtimeSource,
        );
        self::assertStringContainsString(
            "Env::get('wls.worker.storefront_ready_gate_max_paths', 4)",
            $runtimeSource,
        );
        self::assertStringContainsString(
            "Env::get('wls.worker.storefront_deferred_warmup_enabled', '0')",
            $runtimeSource,
        );
        self::assertStringContainsString(
            "Env::get('wls.worker.backend_deferred_warmup_enabled', '0')",
            $runtimeSource,
        );
        self::assertStringContainsString(
            "Env::get('wls.worker.dynamic_deferred_warmup_enabled', '0')",
            $runtimeSource,
        );
        self::assertStringContainsString("'storefront_ready_gate_enabled' => false", $envSource);
        self::assertStringContainsString("'storefront_ready_gate_max_paths' => 4", $envSource);
        self::assertStringContainsString("'storefront_deferred_warmup_enabled' => false", $envSource);
        self::assertStringContainsString("'backend_deferred_warmup_enabled' => false", $envSource);
        self::assertStringContainsString("'dynamic_deferred_warmup_enabled' => false", $envSource);
    }

    public function testReadyGateRuntimeTraceHonorsTheConfiguredWlsDebugFlag(): void
    {
        $runtimeSource = file_get_contents(self::RUNTIME);

        self::assertIsString($runtimeSource);
        self::assertStringContainsString(
            "Env::get('wls.debug.worker_startup_trace', false)",
            $runtimeSource,
        );
    }

    public function testStorefrontWarmupPrefersPublicHostForFpcIdentity(): void
    {
        $reflection = new \ReflectionClass(WlsRuntime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('selectStorefrontWarmupHost');
        $method->setAccessible(true);

        self::assertSame(
            'p05113ef3.test.weline.com:9555',
            $method->invoke($runtime, [
                'p05113ef3.test.weline.com:9555',
                '127.0.0.1:9555',
            ]),
        );
        self::assertSame(
            '127.0.0.1:9555',
            $method->invoke($runtime, ['127.0.0.1:9555']),
        );
    }

    public function testSingleStorefrontPathIsWarmedByEveryWorker(): void
    {
        $source = file_get_contents(self::RUNTIME);

        self::assertIsString($source);
        self::assertStringContainsString(
            'if (\count($paths) <= 1) {',
            $source,
        );
        self::assertStringContainsString(
            "storefront_deferred_warmup_max_paths', 6)",
            $source,
        );
    }

    public function testDeferredWarmupOwnsHomepageWhenReadyGateFailOpen(): void
    {
        $source = file_get_contents(self::RUNTIME);

        self::assertIsString($source);
        self::assertStringContainsString('isHomepageReadyGateFailOpen()', $source);
        self::assertStringContainsString("\$paths['/'] = '/';", $source);
        self::assertStringContainsString(
            'left every Worker cold for the first anonymous homepage SSR',
            $source,
        );
        self::assertStringContainsString(
            'left catalog cold',
            $source,
        );
        self::assertStringNotContainsString(
            "if (\$path !== '/') {\n            \$paths[\$path] = \$path;\n        }",
            $source,
        );
    }

    public function testDeferredStorefrontWarmupRunsOnlyOnConfiguredOwnerWorker(): void
    {
        $reflection = new \ReflectionClass(WlsRuntime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('shouldRunDeferredStorefrontCriticalWarmup');
        $method->setAccessible(true);

        $previousRole = $_SERVER['WLS_PROCESS_ROLE'] ?? null;
        $previousWorkerId = $_SERVER['WLS_WORKER_ID'] ?? null;
        $previousEnabled = \getenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_ENABLED');
        $previousOwner = \getenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_OWNER_WORKER_ID');

        $_SERVER['WLS_PROCESS_ROLE'] = 'worker';
        $_SERVER['WLS_WORKER_ID'] = '1';
        \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_ENABLED=1');
        \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_OWNER_WORKER_ID=2');

        try {
            self::assertFalse($method->invoke($runtime));

            $_SERVER['WLS_WORKER_ID'] = '2';
            self::assertTrue($method->invoke($runtime));
        } finally {
            if ($previousRole === null) {
                unset($_SERVER['WLS_PROCESS_ROLE']);
            } else {
                $_SERVER['WLS_PROCESS_ROLE'] = $previousRole;
            }
            if ($previousWorkerId === null) {
                unset($_SERVER['WLS_WORKER_ID']);
            } else {
                $_SERVER['WLS_WORKER_ID'] = $previousWorkerId;
            }
            if ($previousEnabled === false) {
                \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_ENABLED');
            } else {
                \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_ENABLED=' . $previousEnabled);
            }
            if ($previousOwner === false) {
                \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_OWNER_WORKER_ID');
            } else {
                \putenv('WLS_WORKER_STOREFRONT_DEFERRED_WARMUP_OWNER_WORKER_ID=' . $previousOwner);
            }
        }
    }

    public function testDynamicReadyProofAcceptsTheFirstBusinessPathWhenHomepageIsNotSampled(): void
    {
        $reflection = new \ReflectionClass(WlsRuntime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildDynamicFirstRenderReadyProof');
        $method->setAccessible(true);

        $proof = $method->invoke($runtime, [
            'samples' => [[
                'host' => 'p05113ef3.test.weline.com:9555',
                'path' => '/en_US/products',
                'status' => 200,
                'body_length' => 128,
                'elapsed_ms' => 782.41,
                'target_ms' => 70.0,
                'attempts' => 1,
                'fpc_status' => 'BYPASS',
                'cache' => 'shared:storefront',
                'ready' => true,
                'reason' => 'ready:controller-cache',
            ]],
        ]);

        self::assertIsArray($proof);
        self::assertTrue($proof['ready']);
        self::assertSame('/en_US/products', $proof['path']);
        self::assertSame(200, $proof['status_code']);
    }

    public function testSampleConfigurationLeavesCriticalPathsToModuleProviders(): void
    {
        $source = file_get_contents(self::ENV_SAMPLE);

        self::assertIsString($source);
        self::assertStringContainsString("'dynamic_ready_gate_enabled' => false", $source);
        self::assertStringContainsString("'dynamic_ready_gate_paths' => []", $source);
        self::assertStringContainsString("'dynamic_ready_gate_fail_open' => true", $source);
        self::assertStringContainsString("'worker_startup_trace' => false", $source);
        self::assertStringContainsString("'storefront_deferred_warmup_peer_wait_ms' => 5000", $source);
        self::assertStringContainsString("'storefront_deferred_warmup_owner_worker_id' => 1", $source);
    }

    public function testSlowDiagnosticWarmupDoesNotRenderTheSamePathThreeTimesByDefault(): void
    {
        $source = file_get_contents(self::RUNTIME);

        self::assertIsString($source);
        self::assertGreaterThanOrEqual(
            2,
            substr_count($source, '|| !$this->shouldBlockDynamicWarmupOnTargetMs()'),
        );
    }

    public function testRenderedBusinessPathWithoutControllerCacheHeaderIsStillReady(): void
    {
        $reflection = new \ReflectionClass(WlsRuntime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('validateDynamicFirstRenderWarmup');
        $method->setAccessible(true);

        $validation = $method->invoke(
            $runtime,
            [
                'headers' => [
                    'X-WLS-FPC-Status' => 'BYPASS',
                ],
                'status_code' => 200,
                'body_length' => 128,
                'elapsed_ms' => 541.64,
            ],
            70.0,
            true,
        );

        self::assertIsArray($validation);
        self::assertTrue($validation['ok']);
        self::assertStringContainsString('ready:', $validation['reason']);
    }
}
