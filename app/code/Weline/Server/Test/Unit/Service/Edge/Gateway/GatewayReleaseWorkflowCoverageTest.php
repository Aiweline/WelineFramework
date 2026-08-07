<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class GatewayReleaseWorkflowCoverageTest extends TestCase
{
    public function testCriticalWls2RuntimeChangesTriggerBothReleaseGateEvents(): void
    {
        $root = \dirname(__DIR__, 9);
        $workflow = (string)\file_get_contents(
            $root . DIRECTORY_SEPARATOR . '.github'
                . DIRECTORY_SEPARATOR . 'workflows'
                . DIRECTORY_SEPARATOR . 'wls-gateway-package.yml',
        );
        $eventPaths = [
            'push' => self::eventPaths($workflow, 'push'),
            'pull_request' => self::eventPaths($workflow, 'pull_request'),
        ];

        foreach ([
            'app/code/Weline/Server/Service/SharedStateProtocolProbe.php',
            'app/code/Weline/Server/Service/SharedStateRuntimeResolver.php',
            'app/code/Weline/Server/Service/SharedStateRuntimeScope.php',
            'app/code/Weline/Server/Service/SharedStateServiceManager.php',
            'app/code/Weline/Server/Service/SharedStateServiceRegistry.php',
            'app/code/Weline/Server/Service/SharedRuntimeConnectionWarmup.php',
            'app/code/Weline/Server/Service/MemoryStateFacade.php',
            'app/code/Weline/Server/Service/SessionStateFacade.php',
            'app/code/Weline/Server/Session/Client/SessionClient.php',
            'app/code/Weline/Server/Session/Server/SessionServer.php',
            'app/code/Weline/Server/Session/Server/SharedStateTokenStore.php',
            'app/code/Weline/Server/Shared/Client/SharedStateClient.php',
            'app/code/Weline/Server/Shared/Connection/ConnectionPoolManager.php',
            'app/code/Weline/Server/Shared/Connection/PooledConnection.php',
            'app/code/Weline/Server/bin/session_server.php',
            'app/code/Weline/Server/Service/WorkerStaticResponseL1.php',
            'app/code/Weline/Server/Runtime/WorkerFiberContextTracker.php',
            'app/code/Weline/Server/bin/worker.php',
            'app/code/Weline/Server/bin/worker_runtime_common.php',
            'app/code/Weline/Server/bin/worker_ssl.php',
            'app/code/Weline/Server/IPC/ControlMessage.php',
            'app/code/Weline/Server/Api/System/HostsWriter.php',
            'app/code/Weline/Server/Console/Server/Hosts/Add.php',
            'app/code/Weline/Server/Service/HostsFileManager.php',
            'app/code/Weline/Server/Service/ServerInstanceManager.php',
            'app/code/Weline/Server/Service/Runtime/VerifiedPersistentFileLock.php',
            'app/code/Weline/Server/Service/Security/SecurityPolicyStateStore.php',
            'app/code/Weline/Server/Security/AttackDetector.php',
            'app/code/Weline/Server/Service/WlsPanelSecurityDataService.php',
            'app/code/Weline/Server/Controller/Backend/WlsPanel.php',
            'app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml',
            'app/code/Weline/Framework/System/Process/Processer.php',
            'app/code/Weline/Framework/System/Process/bin/posix_detached_spawn.php',
            'app/code/Weline/Server/Test/Session/SessionServerIntegrationTest.php',
            'app/code/Weline/Server/Test/Unit/Service/SharedStateGenerationAndRecoveryTest.php',
            'app/code/Weline/Server/Test/Unit/Service/SharedStateProtocolProbeTest.php',
            'app/code/Weline/Server/Test/Unit/Service/SharedStateRuntimeResolverTest.php',
            'app/code/Weline/Server/Test/Unit/Service/SharedStateRuntimeScopeTest.php',
            'app/code/Weline/Server/Test/Unit/Service/SharedStateServiceLifecycleTest.php',
            'app/code/Weline/Server/Test/Unit/Service/SharedStateServiceManagerMonotonicTimingTest.php',
            'app/code/Weline/Server/Test/Unit/Service/SharedStateServiceManagerTest.php',
            'app/code/Weline/Server/Test/Unit/Service/StateFacadeInitializationTest.php',
            'app/code/Weline/Server/Test/Unit/Session/SessionServerShutdownCommandTest.php',
            'app/code/Weline/Server/Test/Unit/Session/SessionServerTokenCleanupTest.php',
            'app/code/Weline/Server/Test/Unit/Session/SharedStateTokenStoreTest.php',
            'app/code/Weline/Server/Test/Unit/Shared/Client/SharedStateClientTest.php',
            'app/code/Weline/Server/Test/Unit/Shared/Connection/ConnectionPoolManagerFiberLeaseTest.php',
            'app/code/Weline/Server/Test/Unit/Shared/Connection/ConnectionPoolManagerOptionsMergeTest.php',
            'app/code/Weline/Server/Test/Unit/Shared/Connection/PooledConnectionTokenRotationTest.php',
            'app/code/Weline/Server/Test/Unit/Service/WorkerStaticResponseL1MonotonicTest.php',
            'app/code/Weline/Server/Test/Unit/Runtime/WorkerFiberContextTrackerTest.php',
            'app/code/Weline/Server/Test/Unit/Worker/WorkerMonotonicDeadlineDomainTest.php',
            'app/code/Weline/Server/Test/Unit/Worker/WorkerDetailedHealthCounterHotPathTest.php',
            'app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStopFlowTest.php',
            'app/code/Weline/Server/Test/Unit/Console/HostsPrivilegeBoundaryWiringTest.php',
            'app/code/Weline/Server/Test/Unit/Service/HostsFileManagerTest.php',
            'app/code/Weline/Server/Test/Unit/Service/HostsFileManagerTransactionTest.php',
            'app/code/Weline/Server/Test/Unit/Service/ServerInstanceManagerCleanupTransactionTest.php',
            'app/code/Weline/Framework/Test/Unit/System/Process/ProcesserDetachedSpawnSecurityTest.php',
            'app/code/Weline/Framework/Test/Unit/System/Process/ProcesserMonotonicClockContractTest.php',
            'app/code/Weline/Framework/Test/Unit/System/Process/ProcesserUnixBatchLauncherHandshakeTest.php',
            'app/code/Weline/Server/Test/Unit/IPC/ControlMessageGatewayFallbackTransitionTest.php',
            'app/code/Weline/Server/Test/Unit/Worker/GatewayFallbackWorkerListenerTransitionContractTest.php',
            'app/code/Weline/Server/Test/Unit/Service/Runtime/VerifiedPersistentFileLockSafetyTest.php',
            'app/code/Weline/Server/Test/Unit/Service/Security/SecurityPolicyStateStoreTest.php',
            'app/code/Weline/Server/Test/Unit/Security/AttackDetectorStateTransactionTest.php',
            'app/code/Weline/Server/Test/Unit/Security/SecurityRulesReceiptWiringTest.php',
        ] as $requiredPath) {
            self::assertFileExists(
                $root . DIRECTORY_SEPARATOR . \str_replace('/', DIRECTORY_SEPARATOR, $requiredPath),
                $requiredPath . ' must exist before it is admitted to the release gate.',
            );
            foreach ($eventPaths as $event => $paths) {
                self::assertContains(
                    $requiredPath,
                    $paths,
                    $requiredPath . ' must trigger the ' . $event . ' release gate.',
                );
            }
        }
    }

    public function testCriticalWls2ContractsRunInPostgreSqlPhpunitSteps(): void
    {
        $root = \dirname(__DIR__, 9);
        $workflow = (string)\file_get_contents(
            $root . DIRECTORY_SEPARATOR . '.github'
                . DIRECTORY_SEPARATOR . 'workflows'
                . DIRECTORY_SEPARATOR . 'wls-gateway-package.yml',
        );
        $phpunitSteps = self::phpunitSteps($workflow);

        self::assertNotEmpty($phpunitSteps, 'The release workflow must contain PHPUnit gates.');
        foreach ($phpunitSteps as $step) {
            if (\str_contains(
                $step,
                'Validate database-independent platform runtime contracts',
            )) {
                self::assertSame(
                    \substr_count($step, 'php vendor/bin/phpunit'),
                    \substr_count($step, '--bootstrap vendor/autoload.php'),
                    'The cross-platform process job must remain database-independent.',
                );
                continue;
            }
            self::assertSame(
                \substr_count($step, 'php vendor/bin/phpunit'),
                \substr_count($step, '--bootstrap app/code/Weline/Server/Test/bootstrap_pgsql.php'),
                'Every PHPUnit command in each step must use the dedicated PostgreSQL bootstrap.',
            );
        }

        $phpunitCommands = \implode("\n", $phpunitSteps);
        foreach ([
            'app/code/Weline/Server/Test/Session/SessionServerIntegrationTest.php',
            'app/code/Weline/Server/Test/Unit/Service/SharedStateGenerationAndRecoveryTest.php',
            'app/code/Weline/Server/Test/Unit/Service/SharedStateProtocolProbeTest.php',
            'app/code/Weline/Server/Test/Unit/Service/SharedStateRuntimeResolverTest.php',
            'app/code/Weline/Server/Test/Unit/Service/SharedStateRuntimeScopeTest.php',
            'app/code/Weline/Server/Test/Unit/Service/SharedStateServiceLifecycleTest.php',
            'app/code/Weline/Server/Test/Unit/Service/SharedStateServiceManagerMonotonicTimingTest.php',
            'app/code/Weline/Server/Test/Unit/Service/SharedStateServiceManagerTest.php',
            'app/code/Weline/Server/Test/Unit/Session/SessionServerShutdownCommandTest.php',
            'app/code/Weline/Server/Test/Unit/Session/SessionServerTokenCleanupTest.php',
            'app/code/Weline/Server/Test/Unit/Session/SharedStateTokenStoreTest.php',
            'app/code/Weline/Server/Test/Unit/Service/StateFacadeInitializationTest.php',
            'app/code/Weline/Server/Test/Unit/Shared/Client/SharedStateClientTest.php',
            'app/code/Weline/Server/Test/Unit/Shared/Connection/ConnectionPoolManagerFiberLeaseTest.php',
            'app/code/Weline/Server/Test/Unit/Shared/Connection/ConnectionPoolManagerOptionsMergeTest.php',
            'app/code/Weline/Server/Test/Unit/Shared/Connection/PooledConnectionTokenRotationTest.php',
            'app/code/Weline/Server/Test/Unit/Service/WorkerStaticResponseL1MonotonicTest.php',
            'app/code/Weline/Server/Test/Unit/Runtime/WorkerFiberContextTrackerTest.php',
            'app/code/Weline/Server/Test/Unit/Worker/WorkerMonotonicDeadlineDomainTest.php',
            'app/code/Weline/Server/Test/Unit/Console/HostsPrivilegeBoundaryWiringTest.php',
            'app/code/Weline/Server/Test/Unit/Service/HostsFileManagerTest.php',
            'app/code/Weline/Server/Test/Unit/Service/HostsFileManagerTransactionTest.php',
            'app/code/Weline/Server/Test/Unit/Service/ServerInstanceManagerCleanupTransactionTest.php',
            'app/code/Weline/Framework/Test/Unit/System/Process/ProcesserDetachedSpawnSecurityTest.php',
            'app/code/Weline/Framework/Test/Unit/System/Process/ProcesserMonotonicClockContractTest.php',
            'app/code/Weline/Framework/Test/Unit/System/Process/ProcesserUnixBatchLauncherHandshakeTest.php',
            'app/code/Weline/Server/Test/Unit/IPC/ControlMessageGatewayFallbackTransitionTest.php',
            'app/code/Weline/Server/Test/Unit/Worker/GatewayFallbackWorkerListenerTransitionContractTest.php',
            'app/code/Weline/Server/Test/Unit/Service/Runtime/VerifiedPersistentFileLockSafetyTest.php',
            'app/code/Weline/Server/Test/Unit/Service/Security/SecurityPolicyStateStoreTest.php',
            'app/code/Weline/Server/Test/Unit/Security/AttackDetectorStateTransactionTest.php',
            'app/code/Weline/Server/Test/Unit/Security/SecurityRulesReceiptWiringTest.php',
        ] as $requiredTest) {
            self::assertStringContainsString(
                $requiredTest,
                $phpunitCommands,
                $requiredTest . ' must run in a PostgreSQL PHPUnit step.',
            );
        }
    }

    public function testServingProjectionConsumersTriggerAndRunReleaseGate(): void
    {
        $root = \dirname(__DIR__, 9);
        $workflow = (string)\file_get_contents(
            $root . DIRECTORY_SEPARATOR . '.github'
                . DIRECTORY_SEPARATOR . 'workflows'
                . DIRECTORY_SEPARATOR . 'wls-gateway-package.yml',
        );
        $eventPaths = [
            'push' => self::eventPaths($workflow, 'push'),
            'pull_request' => self::eventPaths($workflow, 'pull_request'),
        ];
        $phpunitCommands = \implode("\n", self::phpunitSteps($workflow));

        foreach ([
            'app/code/Weline/Server/Console/Server/Status.php',
            'app/code/Weline/Server/Console/Server/Benchmark.php',
            'app/code/Weline/Server/Test/Unit/Console/StatusCommandTest.php',
            'app/code/Weline/Server/Test/Unit/Console/BenchmarkCommandTest.php',
            'app/code/Weline/Server/Service/MasterLeaseManager.php',
            'app/code/Weline/Server/Test/Unit/Service/MasterLeaseManagerTest.php',
            'app/code/Weline/Server/Test/Unit/Service/EndpointPersistenceFailureTest.php',
            'app/code/Weline/Server/Service/Runtime/ProtocolEdgeRuntime.php',
            'app/code/Weline/Server/Test/Unit/Service/Runtime/ProtocolEdgeRuntimeOwnershipTest.php',
            'app/code/Weline/Server/Service/Runtime/TlsTicketRingStore.php',
            'app/code/Weline/Server/Test/Unit/Service/Runtime/TlsTicketRingStoreRecoveryTest.php',
            'app/code/Weline/Server/Test/Unit/Service/Edge/Nginx/**',
            'app/code/Weline/Server/Test/Unit/Service/SslCertificateReuseTest.php',
            'app/code/Weline/Server/Test/Unit/Service/SslCertificateStorageSecurityTest.php',
            'app/code/Weline/Server/Log/WlsLogger.php',
            'app/code/Weline/Server/Observability/MetricsSnapshotWriter.php',
            'app/code/Weline/Server/Protocol/Http2/ConnectionAdapter.php',
            'app/code/Weline/Server/Service/AttackLogService.php',
            'app/code/Weline/Server/Service/BatchManager.php',
            'app/code/Weline/Server/Service/FileWatcher.php',
            'app/code/Weline/Server/Service/MemoryStateFacade.php',
            'app/code/Weline/Server/Service/SessionStateFacade.php',
            'app/code/Weline/Server/Service/WlsPerformanceTraceStore.php',
            'app/code/Weline/Server/Test/Unit/Protocol/Http2/ConnectionAdapterHeaderBlockLimitTest.php',
            'app/code/Weline/Server/Test/Unit/Runtime/RuntimeMaintenanceMonotonicTimingTest.php',
            'app/code/Weline/Server/Test/Unit/Worker/WorkerDetailedHealthCounterHotPathTest.php',
            'app/etc/env.sample.php',
        ] as $triggerPath) {
            foreach ($eventPaths as $event => $paths) {
                self::assertContains(
                    $triggerPath,
                    $paths,
                    $triggerPath . ' must trigger the ' . $event . ' release gate.',
                );
            }
        }

        foreach ([
            'GatewayRuntimeServingProjectionTest.php',
            'GatewayStartupRuntimeViewTest.php',
            'StatusCommandTest.php',
            'BenchmarkCommandTest.php',
            'GatewayHostManagerAsyncPublicationTest.php',
            'GatewayProtocolSecurityTest.php',
            'MasterLeaseManagerTest.php',
            'EndpointPersistenceFailureTest.php',
            'ProtocolEdgeRuntimeOwnershipTest.php',
            'TlsTicketRingStoreRecoveryTest.php',
            'SslCertificateReuseTest.php',
            'SslCertificateStorageSecurityTest.php',
            'ConnectionAdapterHeaderBlockLimitTest.php',
            'RuntimeMaintenanceMonotonicTimingTest.php',
            'WorkerDetailedHealthCounterHotPathTest.php',
            'ServiceOrchestratorStopFlowTest.php',
            'ControlMessageGatewayFallbackTransitionTest.php',
            'GatewayFallbackWorkerListenerTransitionContractTest.php',
            'ProcesserDetachedSpawnSecurityTest.php',
            'ProcesserMonotonicClockContractTest.php',
            'ProcesserUnixBatchLauncherHandshakeTest.php',
            'ProcesserWindowsIsolatedBatchReaperTest.php',
            'WindowsListenerHandoffTest.php',
            'GatewayPlatformServiceInstallerSafetyTest.php',
            'GatewayPlatformRetirementRecoverySourceTest.php',
        ] as $testLeaf) {
            self::assertStringContainsString(
                $testLeaf,
                $phpunitCommands,
                $testLeaf . ' must run inside the PostgreSQL release gate.',
            );
        }
        self::assertStringContainsString(
            "\n          app/code/Weline/Server/Test/Unit/Service/Edge/Gateway\n",
            $workflow,
            'The release gate must execute the complete Gateway unit-test directory.',
        );
        self::assertStringContainsString(
            "\n          app/code/Weline/Server/Test/Unit/Service/Edge/Nginx\n",
            $workflow,
            'The release gate must execute the complete managed-Nginx unit-test directory.',
        );
        self::assertStringContainsString(
            "\n          app/code/Weline/Server/Test/Unit/Console/Gateway\n",
            $workflow,
            'The release gate must execute the complete Gateway console unit-test directory.',
        );
        self::assertStringContainsString(
            'extensions: sodium, openssl, intl, pdo_pgsql, pcntl',
            $workflow,
            'The Linux release gate must enable pcntl instead of skipping publication concurrency tests.',
        );
        self::assertSame(
            \substr_count($workflow, 'php vendor/bin/phpunit'),
            \substr_count(
                $workflow,
                '--bootstrap app/code/Weline/Server/Test/bootstrap_pgsql.php',
            ) + \substr_count($workflow, '--bootstrap vendor/autoload.php'),
            'Every PHPUnit release command must use PostgreSQL or the explicit DB-free platform bootstrap.',
        );
        self::assertSame(
            1,
            \substr_count($workflow, '--bootstrap vendor/autoload.php'),
            'Only the database-independent cross-platform runtime job may bypass PostgreSQL.',
        );

        $pgsqlBootstrap = (string)\file_get_contents(
            $root . DIRECTORY_SEPARATOR . 'app'
                . DIRECTORY_SEPARATOR . 'code'
                . DIRECTORY_SEPARATOR . 'Weline'
                . DIRECTORY_SEPARATOR . 'Server'
                . DIRECTORY_SEPARATOR . 'Test'
                . DIRECTORY_SEPARATOR . 'bootstrap_pgsql.php',
        );
        self::assertStringContainsString("\\define('SANDBOX', false)", $pgsqlBootstrap);
        self::assertStringContainsString("\$databaseType !== 'pgsql'", $pgsqlBootstrap);
    }

    public function testReleaseTargetsUseLockedRunnerProfilesAndNativeArmBuilds(): void
    {
        $root = \dirname(__DIR__, 9);
        $packageWorkflow = (string)\file_get_contents(
            $root . '/.github/workflows/wls-gateway-package.yml',
        );
        $nativeWorkflow = (string)\file_get_contents(
            $root . '/.github/workflows/wls-gateway-native.yml',
        );

        self::assertStringContainsString('target_profile:', $packageWorkflow);
        self::assertStringNotContainsString('target_runner:', $packageWorkflow);
        self::assertStringNotContainsString('target_platform:', $packageWorkflow);
        self::assertStringNotContainsString('target_arch:', $packageWorkflow);
        foreach ([
            'linux-x86_64',
            'linux-arm64',
            'darwin-x86_64',
            'darwin-arm64',
            'windows-x86_64',
        ] as $profile) {
            self::assertStringContainsString('- ' . $profile, $packageWorkflow);
        }
        self::assertStringNotContainsString('- windows-arm64', $packageWorkflow);
        foreach ([
            "inputs.target_profile == 'linux-x86_64' && 'ubuntu-24.04'",
            "inputs.target_profile == 'linux-arm64' && 'ubuntu-24.04-arm'",
            "inputs.target_profile == 'darwin-x86_64' && 'macos-15-intel'",
            "inputs.target_profile == 'darwin-arm64' && 'macos-15'",
            "inputs.target_profile == 'windows-x86_64' && 'windows-2025'",
        ] as $mapping) {
            self::assertStringContainsString($mapping, $packageWorkflow);
        }
        self::assertStringContainsString('platform-runtime-contract:', $packageWorkflow);
        self::assertStringContainsString('- platform-runtime-contract', $packageWorkflow);
        self::assertStringContainsString('Verify platform runtime target', $packageWorkflow);
        self::assertSame(
            2,
            \substr_count($packageWorkflow, 'app/code/Weline/Server/Protocol/Http3/**'),
            'HTTP/3 runtime changes must trigger both push and pull-request package gates.',
        );

        foreach ([
            'runner: ubuntu-24.04',
            'runner: ubuntu-24.04-arm',
            'runner: macos-15-intel',
            'runner: macos-15',
        ] as $runner) {
            self::assertStringContainsString($runner, $nativeWorkflow);
        }
        self::assertStringContainsString('runs-on: windows-2025', $nativeWorkflow);
        foreach (['ubuntu-latest', 'macos-latest', 'windows-latest'] as $movingRunner) {
            self::assertStringNotContainsString($movingRunner, $packageWorkflow);
            self::assertStringNotContainsString($movingRunner, $nativeWorkflow);
        }
        self::assertStringContainsString(
            'wls-http3-reuseport-self-test',
            $nativeWorkflow,
        );
        self::assertStringContainsString(
            'Verify Windows native runner target',
            $nativeWorkflow,
        );
        self::assertStringContainsString('Verify native runner target', $nativeWorkflow);
        self::assertSame(
            2,
            \substr_count(
                $nativeWorkflow,
                'composer install --no-interaction --prefer-dist --no-progress',
            ),
            'Both POSIX and Windows native jobs must install the locked PHP test dependencies.',
        );
        self::assertStringContainsString('workflow_call:', $nativeWorkflow);
        self::assertSame(
            2,
            \substr_count(
                $nativeWorkflow,
                'app/code/Weline/Server/Test/Unit/Service/Edge/Gateway/GatewayReleaseWorkflowCoverageTest.php',
            ),
            'Native POSIX and Windows jobs must run the release-workflow guardian against themselves.',
        );
        self::assertStringContainsString(
            'Guard native workflow contracts against self-bypass',
            $nativeWorkflow,
        );
        self::assertSame(
            2,
            \substr_count(
                $packageWorkflow,
                '- ".github/workflows/wls-gateway-native.yml"',
            ),
            'Changing the native workflow must trigger both package push and pull-request gates.',
        );
        self::assertStringContainsString(
            'uses: ./.github/workflows/wls-gateway-native.yml',
            $packageWorkflow,
        );
        self::assertStringContainsString(
            "name: Native runtime gate for this commit\n",
            $packageWorkflow,
        );
        self::assertMatchesRegularExpression(
            '/assemble-production:[\\s\\S]*?- native-gate/',
            $packageWorkflow,
        );
        self::assertMatchesRegularExpression(
            '/sign-production:[\\s\\S]*?- native-gate/',
            $packageWorkflow,
        );
        self::assertStringNotContainsString(
            "container:\n      image: \${{ vars.WLS_GATEWAY_AUDITOR_IMAGE }}",
            $packageWorkflow,
        );
        self::assertStringNotContainsString(
            "container:\n      image: \${{ vars.WLS_GATEWAY_SIGNER_IMAGE }}",
            $packageWorkflow,
        );
        self::assertStringContainsString(
            'Verify auditor image digest before any container start',
            $packageWorkflow,
        );
        self::assertStringContainsString(
            'Verify signer image digest before any container start',
            $packageWorkflow,
        );
        self::assertStringContainsString(
            'wls_linux_reuseport_runtime.c',
            (string)\file_get_contents(
                $root . '/app/code/Weline/Server/Service/Edge/Gateway/Native/CMakeLists.txt',
            ),
        );
        $h3SelfTest = (string)\file_get_contents(
            $root . '/app/code/Weline/Server/Service/Edge/Gateway/Native/posix/wls_linux_reuseport_runtime_self_test.c',
        );
        self::assertStringContainsString('wls_test_argument_contracts', $h3SelfTest);
        self::assertStringContainsString('wls_test_bind_lifecycle', $h3SelfTest);
        self::assertStringContainsString('wls_linux_h3_route_bind', $h3SelfTest);
        self::assertStringContainsString('wls_linux_h3_route_activate', $h3SelfTest);
    }

    /**
     * @return list<string>
     */
    private static function eventPaths(string $workflow, string $event): array
    {
        $paths = [];
        $insideEvent = false;
        $insidePaths = false;

        foreach (\preg_split('/\R/', $workflow) ?: [] as $line) {
            if (!$insideEvent) {
                $insideEvent = $line === '  ' . $event . ':';
                continue;
            }
            if (\preg_match('/^  [^\s].*:\s*$/', $line) === 1) {
                break;
            }
            if (!$insidePaths) {
                $insidePaths = $line === '    paths:';
                continue;
            }
            if (\preg_match('/^      - "([^"]+)"$/', $line, $matches) === 1) {
                $paths[] = $matches[1];
                continue;
            }
            if ($line !== '') {
                break;
            }
        }

        self::assertNotEmpty($paths, $event . ' must define a non-empty paths release filter.');

        return $paths;
    }

    /**
     * @return list<string>
     */
    private static function phpunitSteps(string $workflow): array
    {
        return \array_values(\array_filter(
            \preg_split('/(?=^      - name:)/m', $workflow) ?: [],
            static fn(string $step): bool => \str_contains($step, 'php vendor/bin/phpunit'),
        ));
    }
}
