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
            'app/code/Weline/Server/Test/Unit/Service/Runtime/DirectReloadSurgeIdAllocatorTest.php',
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
        foreach ([
            'app/code/Weline/Server/IPC/**',
            'app/code/Weline/Server/Service/Control/**',
            'app/code/Weline/Server/Dispatcher/**',
            'app/code/Weline/Server/Supervisor/**',
            'app/code/Weline/Server/Service/Runtime/**',
            'app/code/Weline/Server/Service/Policy/**',
            'app/code/Weline/Server/Test/Unit/ChildControl/**',
            'app/code/Weline/Server/Test/Unit/Control/**',
            'app/code/Weline/Server/Test/Unit/Dispatcher/**',
            'app/code/Weline/Server/Test/Unit/Supervisor/**',
        ] as $requiredPathGlob) {
            foreach ($eventPaths as $event => $paths) {
                self::assertContains(
                    $requiredPathGlob,
                    $paths,
                    $requiredPathGlob . ' must trigger the ' . $event
                        . ' release gate.',
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
            'app/code/Weline/Server/Test/Unit/Service/Runtime/DirectReloadSurgeIdAllocatorTest.php',
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
            'app/code/Weline/Server/Service/Edge/Gateway/GatewayBackendIngressTokenStore.php',
            'app/code/Weline/Server/Test/Unit/Service/Runtime/GatewayBackendIngressTokenStoreOwnershipTest.php',
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
            'GatewayBackendIngressTokenStoreOwnershipTest.php',
            'DirectReloadSurgeIdAllocatorTest.php',
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
        foreach ([
            'ChildControl',
            'Control',
            'Dispatcher',
            'Supervisor',
        ] as $controlTestDirectory) {
            self::assertStringContainsString(
                "\n          app/code/Weline/Server/Test/Unit/{$controlTestDirectory}\n",
                $workflow,
                'The release gate must execute the complete '
                    . $controlTestDirectory . ' unit-test directory.',
            );
        }
        foreach ([
            'HybridControlPlaneServerTest.php',
            'MasterProcessControlPlaneRuntimeTest.php',
            'ServiceOrchestratorGatewayLeaseDeadlineTest.php',
            'GatewayStartupFallbackControlTest.php',
        ] as $controlTestLeaf) {
            self::assertStringContainsString(
                $controlTestLeaf,
                $workflow,
                $controlTestLeaf
                    . ' must execute in the PostgreSQL release gate.',
            );
        }
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
        foreach ([
            'app/code/Weline/Server/Service/Edge/Gateway/GatewayBoundedCommandRunner.php',
            'app/code/Weline/Server/Service/Edge/Gateway/GatewayWindowsNamedPipeTransport.php',
        ] as $windowsRunnerPath) {
            self::assertSame(
                2,
                \substr_count($nativeWorkflow, '- "' . $windowsRunnerPath . '"'),
                $windowsRunnerPath . ' must trigger both native push and pull-request gates.',
            );
        }
        foreach (['posix', 'windows', 'windows-production-capacity'] as $job) {
            self::assertStringContainsString(
                'composer install --no-interaction --prefer-dist --no-progress',
                self::jobBlock($nativeWorkflow, $job),
                $job . ' must install the locked PHP test dependencies.',
            );
        }
        self::assertSame(
            2,
            \substr_count(
                $nativeWorkflow,
                'NativeGatewayControllerOnlyRecoveryContractTest.php',
            ),
            'Both POSIX and Windows jobs must lock the Controller-only recovery contract.',
        );
        self::assertStringNotContainsString('continue-on-error:', $nativeWorkflow);
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
        $windowsJob = self::jobBlock($nativeWorkflow, 'windows');
        self::assertStringContainsString(
            'GatewayBoundedCommandRunnerDeadlineTest.php',
            $windowsJob,
            'The Windows native job must execute the bounded runner deadline/reaper contract.',
        );
        self::assertMatchesRegularExpression(
            '/name: Windows named-pipe absolute-deadline source contract'
                . '[\s\S]*?--bootstrap app\/code\/Weline\/Server\/Test\/'
                . 'bootstrap_pgsql\.php[\s\S]*?GatewayBoundedCommandRunnerDeadlineTest\.php/',
            $windowsJob,
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

        $assemble = self::jobBlock($packageWorkflow, 'assemble-production');
        self::assertStringContainsString(
            "\$components += @('wls-bounded-command', 'wls-gateway-guardian')",
            $assemble,
            'Windows package assembly must download the Guardian alongside the bounded helper.',
        );
        self::assertStringContainsString(
            '--wls-gateway-guardian=',
            $assemble,
            'Windows package assembly must bind the downloaded Guardian into the immutable package.',
        );
    }

    public function testNativeWorkflowGuardsItsOwnRevisionAndReleaseWaitsForEveryNativeProfile(): void
    {
        $root = \dirname(__DIR__, 9);
        $packageWorkflow = (string)\file_get_contents(
            $root . '/.github/workflows/wls-gateway-package.yml',
        );
        $nativeWorkflow = (string)\file_get_contents(
            $root . '/.github/workflows/wls-gateway-native.yml',
        );
        $guardian = 'app/code/Weline/Server/Test/Unit/Service/Edge/Gateway/'
            . 'GatewayReleaseWorkflowCoverageTest.php';

        foreach (['push', 'pull_request'] as $event) {
            self::assertContains(
                '.github/workflows/wls-gateway-native.yml',
                self::eventPaths($nativeWorkflow, $event),
                'The native workflow must trigger its own guardian when its YAML changes.',
            );
            self::assertContains(
                'app/code/Weline/Server/Protocol/Http3/**',
                self::eventPaths($nativeWorkflow, $event),
                'HTTP/3 compiler, dependency, runtime and native-source changes must all trigger the native gate.',
            );
        }

        $posix = self::jobBlock($nativeWorkflow, 'posix');
        $windows = self::jobBlock($nativeWorkflow, 'windows');
        foreach (['posix' => $posix, 'windows' => $windows] as $job => $block) {
            self::assertStringContainsString($guardian, $block, $job . ' must execute the guardian.');
            self::assertStringContainsString(
                'ref: ${{ github.sha }}',
                $block,
                $job . ' must build the exact caller commit instead of an implicit moving ref.',
            );
            self::assertStringNotContainsString('continue-on-error:', $block);
        }

        foreach ([
            'runner: ubuntu-24.04',
            'runner: ubuntu-24.04-arm',
            'runner: macos-15-intel',
            'runner: macos-15',
        ] as $runner) {
            self::assertStringContainsString($runner, $posix);
        }
        self::assertStringContainsString('runs-on: windows-2025', $windows);

        $nativeGate = self::jobBlock($packageWorkflow, 'native-gate');
        self::assertStringContainsString('uses: ./.github/workflows/wls-gateway-native.yml', $nativeGate);
        self::assertStringNotContainsString('continue-on-error:', $nativeGate);
        foreach (['assemble-production', 'sign-production'] as $job) {
            self::assertMatchesRegularExpression(
                '/needs:[\s\S]*?\n\s+- native-gate(?:\n|$)/',
                self::jobBlock($packageWorkflow, $job),
                $job . ' must wait for all five jobs in the reusable native workflow.',
            );
        }
    }

    public function testStableLauncherRollbackProofRunsOnBothPlatformsBeforeRelease(): void
    {
        $root = \dirname(__DIR__, 9);
        $packageWorkflow = (string)\file_get_contents(
            $root . '/.github/workflows/wls-gateway-package.yml',
        );
        $nativeWorkflow = (string)\file_get_contents(
            $root . '/.github/workflows/wls-gateway-native.yml',
        );
        $builder = (string)\file_get_contents(
            $root . '/dev/tools/wls-gateway-package.php',
        );
        $argument = '--rollback-target-proof-self-test';
        $posixCommand = './build/wls-gateway-native/wls-gateway-launcher ' . $argument;
        $windowsCommand = './build/wls-gateway-native/Release/'
            . 'wls-gateway-launcher.exe ' . $argument;
        $posix = self::jobBlock($nativeWorkflow, 'posix');
        $windows = self::jobBlock($nativeWorkflow, 'windows');

        self::assertSame(1, \substr_count($posix, $posixCommand));
        self::assertSame(1, \substr_count($windows, $windowsCommand));
        self::assertStringContainsString(
            "      - name: Stable launcher rollback-target proof self-test\n"
                . "        run: {$posixCommand}\n",
            $posix,
            'The POSIX proof step must be unconditional.',
        );
        self::assertStringContainsString(
            "      - name: Stable launcher rollback-target proof self-test\n"
                . "        shell: pwsh\n"
                . "        run: {$windowsCommand}\n",
            $windows,
            'The Windows proof step must be unconditional.',
        );
        self::assertStringNotContainsString('continue-on-error:', $posix);
        self::assertStringNotContainsString('continue-on-error:', $windows);
        self::assertTrue(
            \strpos($posix, 'cmake --build') < \strpos($posix, $posixCommand),
            'The POSIX rollback proof must run against the freshly compiled stable launcher.',
        );
        self::assertTrue(
            \strpos($windows, 'cmake --build') < \strpos($windows, $windowsCommand),
            'The Windows rollback proof must run against the freshly compiled stable launcher.',
        );

        $assemble = self::jobBlock($packageWorkflow, 'assemble-production');
        $audit = self::jobBlock($packageWorkflow, 'audit-production');
        $sign = self::jobBlock($packageWorkflow, 'sign-production');
        self::assertStringContainsString('- native-gate', $assemble);
        self::assertStringContainsString('- assemble-production', $audit);
        self::assertStringContainsString('- native-gate', $sign);
        self::assertStringContainsString('- audit-production', $sign);

        $selfTestCall = \strpos($builder, '$this->runComponentSelfTests(');
        $capabilityManifest = \strpos($builder, '$capabilities = [');
        self::assertIsInt($selfTestCall);
        self::assertIsInt($capabilityManifest);
        self::assertLessThan(
            $capabilityManifest,
            $selfTestCall,
            'The builder must run native proofs before constructing capability claims.',
        );
        self::assertStringContainsString($argument, $builder);
    }

    public function testStableLauncherRecoveryLedgerProofRunsOnBothPlatformsBeforeRelease(): void
    {
        $root = \dirname(__DIR__, 9);
        $nativeWorkflow = (string)\file_get_contents(
            $root . '/.github/workflows/wls-gateway-native.yml',
        );
        $builder = (string)\file_get_contents(
            $root . '/dev/tools/wls-gateway-package.php',
        );
        $argument = '--recovery-ledger-self-test';
        $posixCommand = './build/wls-gateway-native/wls-gateway-launcher ' . $argument;
        $windowsCommand = './build/wls-gateway-native/Release/'
            . 'wls-gateway-launcher.exe ' . $argument;
        $posix = self::jobBlock($nativeWorkflow, 'posix');
        $windows = self::jobBlock($nativeWorkflow, 'windows');

        self::assertSame(1, \substr_count($posix, $posixCommand));
        self::assertSame(1, \substr_count($windows, $windowsCommand));
        self::assertStringContainsString(
            "      - name: Stable launcher recovery-ledger self-test\n"
                . "        run: {$posixCommand}\n",
            $posix,
        );
        self::assertStringContainsString(
            "      - name: Stable launcher recovery-ledger self-test\n"
                . "        shell: pwsh\n"
                . "        run: {$windowsCommand}\n",
            $windows,
        );
        self::assertStringNotContainsString('continue-on-error:', $posix);
        self::assertStringNotContainsString('continue-on-error:', $windows);
        self::assertTrue(
            \strpos($posix, 'cmake --build') < \strpos($posix, $posixCommand),
        );
        self::assertTrue(
            \strpos($windows, 'cmake --build') < \strpos($windows, $windowsCommand),
        );
        self::assertStringContainsString($argument, $builder);
    }

    public function testReleaseContainersConsumeOnlyHostVerifiedImmutableImageOutputs(): void
    {
        $root = \dirname(__DIR__, 9);
        $workflow = (string)\file_get_contents(
            $root . '/.github/workflows/wls-gateway-package.yml',
        );

        $verifier = self::jobBlock($workflow, 'verify-release-container-images');
        self::assertStringContainsString('runs-on: ubuntu-24.04', $verifier);
        self::assertStringContainsString('auditor_image:', $verifier);
        self::assertStringContainsString('signer_image:', $verifier);
        self::assertStringContainsString('${{ vars.WLS_GATEWAY_AUDITOR_IMAGE }}', $verifier);
        self::assertStringContainsString('${{ vars.WLS_GATEWAY_SIGNER_IMAGE }}', $verifier);
        self::assertStringContainsString('docker pull --quiet', $verifier);
        self::assertStringContainsString('docker image inspect', $verifier);
        self::assertStringContainsString('@sha256:', $verifier);
        self::assertStringContainsString('$GITHUB_OUTPUT', $verifier);
        self::assertStringNotContainsString('container:', $verifier);

        $audit = self::jobBlock($workflow, 'audit-production');
        self::assertStringContainsString('- verify-release-container-images', $audit);
        self::assertStringContainsString(
            '${{ needs.verify-release-container-images.outputs.auditor_image }}',
            $audit,
        );
        self::assertStringNotContainsString('${{ vars.WLS_GATEWAY_AUDITOR_IMAGE }}', $audit);
        self::assertStringNotContainsString('docker pull --quiet', $audit);

        $sign = self::jobBlock($workflow, 'sign-production');
        self::assertStringContainsString('- verify-release-container-images', $sign);
        self::assertStringContainsString(
            '${{ needs.verify-release-container-images.outputs.signer_image }}',
            $sign,
        );
        self::assertStringNotContainsString('${{ vars.WLS_GATEWAY_SIGNER_IMAGE }}', $sign);
        self::assertStringNotContainsString('docker pull --quiet', $sign);
    }

    public function testSignedGatewayReleaseIsPublishedAsAProjectBootstrapDistribution(): void
    {
        $root = \dirname(__DIR__, 9);
        $workflow = (string)\file_get_contents(
            $root . '/.github/workflows/wls-gateway-package.yml',
        );
        $assemble = self::jobBlock($workflow, 'assemble-production');
        $sign = self::jobBlock($workflow, 'sign-production');

        self::assertStringContainsString(
            'WLS_GATEWAY_RELEASE_PUBLIC_KEY_BASE64',
            $assemble,
        );
        self::assertStringContainsString(
            'Inject enabled release verification key',
            $assemble,
        );
        self::assertStringContainsString(
            'app/code/Weline/Server/env/gateway/trusted-release-keys.json',
            $assemble,
        );
        self::assertStringContainsString("'enabled' => true", $assemble);

        self::assertStringContainsString(
            'Stage signed project bootstrap distribution',
            $sign,
        );
        self::assertStringContainsString(
            'extend/server/wls-gateway/${{ inputs.target_profile }}',
            $sign,
        );
        self::assertStringContainsString(
            'app/code/Weline/Server/env/gateway/trusted-release-keys.json',
            $sign,
        );
        self::assertStringContainsString(
            'wls-gateway-project-distribution-${{ github.run_id }}-${{ github.run_attempt }}',
            $sign,
        );
        self::assertStringContainsString(
            '${{ runner.temp }}/wls-gateway-project-distribution',
            $sign,
        );
    }

    public function testLinuxHttp3NativeGateCompilesProductionTransportAndNeverMasksBpfFailures(): void
    {
        $root = \dirname(__DIR__, 9);
        $nativeWorkflow = (string)\file_get_contents(
            $root . '/.github/workflows/wls-gateway-native.yml',
        );
        $cmake = (string)\file_get_contents(
            $root . '/app/code/Weline/Server/Service/Edge/Gateway/Native/CMakeLists.txt',
        );
        $selfTest = (string)\file_get_contents(
            $root . '/app/code/Weline/Server/Service/Edge/Gateway/Native/posix/'
                . 'wls_linux_reuseport_runtime_self_test.c',
        );
        $generator = (string)\file_get_contents(
            $root . '/app/code/Weline/Server/Service/Edge/Gateway/Native/posix/'
                . 'wls_linux_reuseport_bpf_header_generator.php',
        );
        $generatedHeader = (string)\file_get_contents(
            $root . '/app/code/Weline/Server/Protocol/Http3/Native/'
                . 'wls_linux_reuseport_bpf_code.h',
        );

        foreach ([
            'wls_transport.c',
            'PkgConfig::WLS_NGTCP2',
            'PkgConfig::WLS_NGTCP2_CRYPTO_OSSL',
            'PkgConfig::WLS_NGHTTP3',
            'PkgConfig::WLS_OPENSSL',
            '--target=bpfel',
            'wls_linux_reuseport_bpf.c',
            'wls_linux_reuseport_bpf.o',
            'NAMES clang-18',
            'WLS_BPF_CLANG_MAJOR',
            'wls_linux_reuseport_bpf_header_generator.php',
            'wls_linux_reuseport_bpf_generated.h',
        ] as $requiredBuildContract) {
            self::assertStringContainsString($requiredBuildContract, $cmake);
        }
        self::assertStringContainsString('WLS_BUILD_HTTP3_TRANSPORT=ON', $nativeWorkflow);
        self::assertStringContainsString('NativeTransportCompiler', $nativeWorkflow);
        self::assertStringContainsString('ensure(true)', $nativeWorkflow);
        self::assertStringContainsString('h3_status=0', $nativeWorkflow);
        self::assertStringContainsString('77)', $nativeWorkflow);
        self::assertStringContainsString('$GITHUB_STEP_SUMMARY', $nativeWorkflow);
        self::assertStringContainsString('clang-18 llvm-18', $nativeWorkflow);
        self::assertStringContainsString('cmp --silent', $nativeWorkflow);
        self::assertStringNotContainsString(
            'wls-http3-reuseport-self-test || true',
            $nativeWorkflow,
        );

        foreach ([
            '#include <elf.h>',
            'wls_test_compiled_bpf_header_sync',
            'wls_test_nonprivileged_reuseport_io',
            'WLS_SELF_TEST_SKIP',
            'wls_missing_bpf_capabilities',
            'wls_transport_get_versions',
        ] as $requiredSelfTestContract) {
            self::assertStringContainsString($requiredSelfTestContract, $selfTest);
        }

        foreach ([
            "hash('sha256'",
            'Unexpected ELF section',
            'R_BPF_64_64',
            'clang version 18',
            'wls_linux_reuseport_bpf_code_sha256',
        ] as $requiredGeneratorContract) {
            self::assertStringContainsString($requiredGeneratorContract, $generator);
        }
        self::assertStringContainsString(
            'WLS_LINUX_REUSEPORT_BPF_CLANG_MAJOR 18u',
            $generatedHeader,
        );
        self::assertMatchesRegularExpression(
            '/WLS_LINUX_REUSEPORT_BPF_SOURCE_SHA256 "[a-f0-9]{64}"/',
            $generatedHeader,
        );
        self::assertMatchesRegularExpression(
            '/WLS_LINUX_REUSEPORT_BPF_CODE_SHA256 "[a-f0-9]{64}"/',
            $generatedHeader,
        );
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

    private static function jobBlock(string $workflow, string $job): string
    {
        $matched = \preg_match(
            '/^  ' . \preg_quote($job, '/') . ':[\s\S]*?(?=^  [a-zA-Z0-9_-]+:|\z)/m',
            $workflow,
            $matches,
        );
        self::assertSame(1, $matched, 'Workflow job is missing: ' . $job);

        return (string)($matches[0] ?? '');
    }
}
