<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Contract\ServiceInfo;
use Weline\Server\Service\ServerInstanceManager;

final class StopCommandFastLocalCleanupTest extends TestCase
{
    public function testFastLocalVerificationCandidatesExcludeSharedServices(): void
    {
        $stop = new class extends Stop {
            public function candidates(ServerInstanceInfo $info): array
            {
                return $this->collectDirectForceStopCandidatePids($info);
            }

            protected function collectRecoverableManagedPids(string $name): array
            {
                unset($name);
                return [];
            }
        };

        $info = new ServerInstanceInfo(
            'default',
            111,
            26899,
            '127.0.0.1',
            443,
            true,
            \Weline\Server\Console\Server\stopTestRuntimeSelection(true),
            1,
            16895,
            80,
            '2026-04-21 10:00:00',
            1776765600,
            [
                new ServiceInfo('session_server', 'Session Server', 1, 222, 26422, 'ready'),
                new ServiceInfo('memory_server', 'Memory Service', 1, 333, 26423, 'ready'),
                new ServiceInfo('worker', 'HTTP Worker', 1, 444, 16895, 'ready'),
            ]
        );

        $candidates = $stop->candidates($info);

        self::assertContains(111, $candidates);
        self::assertNotContains(222, $candidates);
        self::assertNotContains(333, $candidates);
        self::assertNotContains(444, $candidates);
    }

    public function testFastLocalDoesNotCollectOtherInstanceSharedPortOccupants(): void
    {
        $stop = new class extends Stop {
            public int $sharedPortInspections = 0;

            public function candidates(ServerInstanceInfo $info): array
            {
                return $this->collectDirectForceStopCandidatePids($info);
            }

            protected function collectIndexedResidualPids(string $name, bool $includeSharedState = false): array
            {
                unset($name, $includeSharedState);

                throw new \RuntimeException('direct force-stop must not scan PID indexes on the hot path');
            }

            protected function collectResidualPrefixPids(string $name): array
            {
                unset($name);

                throw new \RuntimeException('direct force-stop must not scan process-name prefixes on the hot path');
            }

            protected function collectRecoverableManagedPids(string $name): array
            {
                unset($name);

                return [];
            }

            protected function inspectRecoverablePortOccupant(int $port): array
            {
                if (\in_array($port, [26422, 26423], true)) {
                    $this->sharedPortInspections++;

                    return ['in_use' => true, 'pid' => 999, 'pid_running' => true, 'is_weline' => true, 'state' => 'weline'];
                }

                return ['in_use' => false, 'pid' => 0, 'pid_running' => false, 'is_weline' => false, 'state' => 'free'];
            }
        };

        $info = new ServerInstanceInfo(
            'codex-perf',
            111,
            26899,
            '127.0.0.1',
            9512,
            false,
            \Weline\Server\Console\Server\stopTestRuntimeSelection(true),
            1,
            16895,
            0,
            '2026-04-24 10:00:00',
            1777015200,
            [
                new ServiceInfo('session_server', 'Session Server', 1, 222, 26422, 'ready'),
                new ServiceInfo('memory_server', 'Memory Service', 1, 333, 26423, 'ready'),
                new ServiceInfo('worker', 'HTTP Worker', 1, 444, 16895, 'ready'),
            ]
        );

        $candidates = $stop->candidates($info);

        self::assertSame([111], $candidates);
        self::assertNotContains(999, $candidates);
        self::assertSame(0, $stop->sharedPortInspections);
    }

    public function testFastLocalBlockingRecoverablePortsUsesBindProbe(): void
    {
        $socket = @\stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $errstr,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN
        );
        if (!\is_resource($socket)) {
            self::markTestSkipped('Unable to bind the fast-local port verification test socket.');
        }
        $address = (string)\stream_socket_get_name($socket, false);
        if (!\preg_match('/:(\d+)$/', $address, $matches)) {
            @\fclose($socket);
            self::markTestSkipped('Unable to resolve the fast-local port verification test socket.');
        }
        $port = (int)$matches[1];

        $stop = new class extends Stop {
            private int $port = 0;

            public function configurePort(int $port): void
            {
                $this->port = $port;
            }

            public function blockingPorts(string $name, ServerInstanceInfo $info): array
            {
                return $this->collectFastLocalBlockingRecoverablePorts($name, $info);
            }

            protected function collectRemainingRecoverableWlsPorts(
                string $name,
                ServerInstanceInfo $info,
                bool $includeSharedState = false
            ): array {
                unset($name, $info, $includeSharedState);

                return [$this->port];
            }
        };

        $info = new ServerInstanceInfo(
            'default',
            0,
            19982,
            '127.0.0.1',
            $port,
            true,
            \Weline\Server\Console\Server\stopTestRuntimeSelection(true),
            1,
            10000,
            0,
            '2026-04-06 12:00:00',
            1775448000,
            []
        );
        $stop->configurePort($port);

        try {
            self::assertSame([$port], $stop->blockingPorts('default', $info));
        } finally {
            @\fclose($socket);
            \Weline\Framework\System\Process\Processer::clearPortCache($port);
        }

        self::assertSame([], $stop->blockingPorts('default', $info));
    }

    public function testFastLocalCleanupSkipsIpcAndRunsLocalCleanupFlow(): void
    {
        $manager = new class extends ServerInstanceManager {
            public ?ServerInstanceInfo $info = null;
            public array $deleted = [];

            public function __construct()
            {
            }

            public function getInstanceInfo(string $instanceName, bool $strict = false): ?ServerInstanceInfo
            {
                unset($strict);

                return $instanceName === 'default' ? $this->info : null;
            }

            public function getRawInstanceData(string $instanceName): ?array
            {
                unset($instanceName);

                return null;
            }

            public function deleteInstance(string $instanceName): bool
            {
                $this->deleted[] = $instanceName;

                return true;
            }
        };
        $manager->info = new ServerInstanceInfo(
            'default',
            0,
            19982,
            '127.0.0.1',
            9982,
            false,
            \Weline\Server\Console\Server\stopTestRuntimeSelection(false),
            1,
            10000,
            0,
            '2026-04-06 12:00:00',
            1775448000,
            []
        );

        $stop = new class($manager) extends Stop {
            public array $calls = [];

            public function __construct(private readonly ServerInstanceManager $manager)
            {
            }

            protected function printWelcome(): void
            {
            }

            protected function printGoodbye(bool $success = true, string $message = ''): void
            {
                unset($success, $message);
            }

            protected function acquireStopLock(string $instanceName, int $timeout = 5): bool
            {
                unset($instanceName, $timeout);

                return true;
            }

            protected function releaseStopLock(): void
            {
            }

            protected function getInstanceManager(): ServerInstanceManager
            {
                return $this->manager;
            }

            protected function isMasterProcessAvailableForStop(ServerInstanceInfo $info): bool
            {
                unset($info);

                return true;
            }

            protected function showInstanceInfo(ServerInstanceInfo $info, string $edgeAdapterName = \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX): void
            {
                unset($info);
                $this->calls[] = 'show';
            }

            protected function sendStopViaIpcAndWait(string $instanceName, int $controlPort, int $masterPid, bool $force): bool
            {
                unset($instanceName, $controlPort, $masterPid, $force);
                $this->calls[] = 'ipc';

                return true;
            }

            protected function runFastLocalResidualCleanup(string $name, ServerInstanceInfo $info): void
            {
                unset($info);
                $this->calls[] = 'residual:' . $name;
            }

            protected function runResidualCleanupPairWithRetry(string $name, ServerInstanceInfo $info, bool $includeSharedState = false): void
            {
                unset($info);
                $this->calls[] = 'residual:' . $name;
            }

            protected function terminateDirectForceStopCandidatePids(ServerInstanceInfo $info): int
            {
                unset($info);

                return 0;
            }

            protected function collectDirectForceStopCandidatePids(ServerInstanceInfo $info): array
            {
                unset($info);

                return [];
            }

            protected function collectFastLocalResidualPids(string $name, ServerInstanceInfo $info): array
            {
                unset($name, $info);

                return [];
            }

            protected function releaseSharedStateConsumersForInstance(string $instanceName): void
            {
                $this->calls[] = 'release:' . $instanceName;
            }

            protected function cleanupPidFiles(string $name, ServerInstanceInfo $info): void
            {
                unset($info);
                $this->calls[] = 'pid:' . $name;
            }

            protected function releaseStartLock(string $instanceName): void
            {
                $this->calls[] = 'unlock:' . $instanceName;
            }

            protected function cleanupAllWlsProcesses(string $instanceName): void
            {
                $this->calls[] = 'cleanup:' . $instanceName;
            }
        };

        $stop->__init();
        \ob_start();
        try {
            $stop->execute([
                0 => 'server:stop',
                1 => 'default',
                'force' => true,
                'f' => true,
                'fast-local' => true,
            ], []);
        } finally {
            \ob_end_clean();
        }

        self::assertSame(['default'], $manager->deleted);
        self::assertSame(
            [
                'show',
                'residual:default',
                'release:default',
                'pid:default',
                'unlock:default',
            ],
            $stop->calls
        );
    }

    public function testForceStopUsesAuthenticatedIpcWithoutLocalKillFallbackOnSuccess(): void
    {
        $manager = new class extends ServerInstanceManager {
            public ?ServerInstanceInfo $info = null;
            public array $deleted = [];

            public function __construct()
            {
            }

            public function getInstanceInfo(string $instanceName, bool $strict = false): ?ServerInstanceInfo
            {
                unset($strict);

                return $instanceName === 'default' ? $this->info : null;
            }

            public function getRawInstanceData(string $instanceName): ?array
            {
                unset($instanceName);

                return null;
            }

            public function deleteInstance(string $instanceName): bool
            {
                $this->deleted[] = $instanceName;

                return true;
            }
        };
        $manager->info = new ServerInstanceInfo(
            'default',
            33780,
            26895,
            '127.0.0.1',
            443,
            true,
            \Weline\Server\Console\Server\stopTestRuntimeSelection(false),
            2,
            16895,
            80,
            '2026-04-20 12:00:00',
            1775448000,
            []
        );

        $stop = new class($manager) extends Stop {
            public array $calls = [];

            public function __construct(private readonly ServerInstanceManager $manager)
            {
            }

            protected function printWelcome(): void
            {
            }

            protected function printGoodbye(bool $success = true, string $message = ''): void
            {
                unset($success, $message);
            }

            protected function acquireStopLock(string $instanceName, int $timeout = 5): bool
            {
                unset($instanceName, $timeout);

                return true;
            }

            protected function releaseStopLock(): void
            {
            }

            protected function getInstanceManager(): ServerInstanceManager
            {
                return $this->manager;
            }

            protected function isMasterProcessAvailableForStop(ServerInstanceInfo $info): bool
            {
                unset($info);

                return true;
            }

            protected function showInstanceInfo(ServerInstanceInfo $info, string $edgeAdapterName = \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX): void
            {
                unset($info);
                $this->calls[] = 'show';
            }

            protected function sendStopViaIpcAndWait(string $instanceName, int $controlPort, int $masterPid, bool $force): bool
            {
                unset($controlPort, $masterPid);
                $this->calls[] = 'ipc:' . $instanceName . ':' . ($force ? 'force' : 'normal');

                return true;
            }

            protected function runFastLocalResidualCleanup(string $name, ServerInstanceInfo $info): void
            {
                unset($name, $info);

                throw new \RuntimeException('successful authenticated IPC must not enter local cleanup');
            }

            protected function terminateDirectForceStopCandidatePids(ServerInstanceInfo $info): int
            {
                unset($info);

                throw new \RuntimeException('numeric PID cleanup must not run after authenticated IPC success');
            }

            protected function collectDirectForceStopCandidatePids(ServerInstanceInfo $info): array
            {
                unset($info);

                return [];
            }

            protected function collectFastLocalResidualPids(string $name, ServerInstanceInfo $info): array
            {
                unset($name, $info);

                return [];
            }

            protected function runResidualCleanupPairWithRetry(string $name, ServerInstanceInfo $info, bool $includeSharedState = false): void
            {
                unset($name, $info, $includeSharedState);

                throw new \RuntimeException('authenticated IPC success must not enter local lease cleanup');
            }

            protected function cleanupStaleRecoverableProcessPidFiles(): void
            {
                $this->calls[] = 'stale';
            }

            protected function releaseSharedStateConsumersForInstance(string $instanceName): void
            {
                $this->calls[] = 'release:' . $instanceName;
            }

            protected function cleanupPidFiles(string $name, ServerInstanceInfo $info): void
            {
                unset($info);
                $this->calls[] = 'pid:' . $name;
            }

            protected function releaseStartLock(string $instanceName): void
            {
                $this->calls[] = 'unlock:' . $instanceName;
            }
        };

        $stop->__init();
        \ob_start();
        try {
            $stop->execute([
                0 => 'server:stop',
                1 => 'default',
                'force' => true,
                'f' => true,
            ], []);
        } finally {
            \ob_end_clean();
        }

        self::assertSame(['default'], $manager->deleted);
        self::assertSame(
            [
                'show',
                'ipc:default:force',
                'release:default',
                'pid:default',
                'unlock:default',
            ],
            $stop->calls
        );
    }

    public function testForceStopWithMissingMasterUsesExactBirthRetirementThenVerification(): void
    {
        $manager = new class extends ServerInstanceManager {
            public ?ServerInstanceInfo $info = null;
            public array $deleted = [];

            public function __construct()
            {
            }

            public function getInstanceInfo(string $instanceName, bool $strict = false): ?ServerInstanceInfo
            {
                unset($strict);

                return $instanceName === 'default' ? $this->info : null;
            }

            public function getRawInstanceData(string $instanceName): ?array
            {
                unset($instanceName);

                return null;
            }

            public function deleteInstance(string $instanceName): bool
            {
                $this->deleted[] = $instanceName;

                return true;
            }
        };
        $manager->info = new ServerInstanceInfo(
            'default',
            39032,
            26897,
            '127.0.0.1',
            443,
            true,
            \Weline\Server\Console\Server\stopTestRuntimeSelection(false),
            2,
            16895,
            80,
            '2026-04-20 12:00:00',
            1775448000,
            []
        );

        $stop = new class($manager) extends Stop {
            public array $calls = [];

            public function __construct(private readonly ServerInstanceManager $manager)
            {
            }

            protected function printWelcome(): void
            {
            }

            protected function acquireStopLock(string $instanceName, int $timeout = 5): bool
            {
                unset($instanceName, $timeout);

                return true;
            }

            protected function releaseStopLock(): void
            {
            }

            protected function getInstanceManager(): ServerInstanceManager
            {
                return $this->manager;
            }

            protected function isMasterProcessAvailableForStop(ServerInstanceInfo $info): bool
            {
                unset($info);

                return false;
            }

            protected function showInstanceInfo(ServerInstanceInfo $info, string $edgeAdapterName = \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX): void
            {
                unset($info);
                $this->calls[] = 'show';
            }

            protected function sendStopViaIpcAndWait(string $instanceName, int $controlPort, int $masterPid, bool $force): bool
            {
                unset($controlPort, $masterPid);
                $this->calls[] = 'ipc:' . $instanceName . ':' . ($force ? 'force' : 'normal');

                return true;
            }

            protected function retireExactInstanceGeneration(string $name): array
            {
                $this->calls[] = 'exact:' . $name;

                return [
                    'terminated' => 0,
                    'released' => 0,
                    'unreleased' => 0,
                    'reasons' => [],
                ];
            }

            protected function runResidualCleanupPairWithRetry(string $name, ServerInstanceInfo $info, bool $includeSharedState = false): void
            {
                unset($name, $info, $includeSharedState);
                $this->calls[] = 'residual';
            }

            protected function releaseSharedStateConsumersForInstance(string $instanceName): void
            {
                $this->calls[] = 'release:' . $instanceName;
            }

            protected function cleanupPidFiles(string $name, ServerInstanceInfo $info): void
            {
                unset($info);
                $this->calls[] = 'pid:' . $name;
            }

            protected function releaseStartLock(string $instanceName): void
            {
                $this->calls[] = 'unlock:' . $instanceName;
            }
        };

        $stop->__init();
        \ob_start();
        try {
            $stop->execute([
                0 => 'server:stop',
                1 => 'default',
                'force' => true,
                'f' => true,
            ], []);
        } finally {
            \ob_end_clean();
        }

        self::assertSame(['default'], $manager->deleted);
        self::assertSame(['show', 'exact:default', 'residual', 'release:default', 'pid:default', 'unlock:default'], $stop->calls);
    }

    public function testDirectForceRetirementUsesExactGenerationAndNeverPrefixBatch(): void
    {
        $info = new ServerInstanceInfo(
            'default',
            33780,
            26895,
            '127.0.0.1',
            443,
            true,
            \Weline\Server\Console\Server\stopTestRuntimeSelection(false),
            2,
            16895,
            80,
            '2026-04-20 12:00:00',
            1775448000,
            []
        );

        $stop = new class extends Stop {
            public array $prefixCleanupNames = [];
            public array $retiredInstances = [];

            public function terminate(ServerInstanceInfo $info): int
            {
                return $this->terminateDirectForceStopCandidatePids($info);
            }

            protected function retireExactInstanceGeneration(string $name): array
            {
                $this->retiredInstances[] = $name;

                return [
                    'terminated' => 1,
                    'released' => 1,
                    'unreleased' => 0,
                    'reasons' => [],
                ];
            }

            protected function terminateCurrentInstanceProcessPrefixes(string $name, bool $includeSharedState = false): int
            {
                unset($includeSharedState);
                $this->prefixCleanupNames[] = $name;

                return 5;
            }
        };

        self::assertSame(1, $stop->terminate($info));
        self::assertSame([$info->name], $stop->retiredInstances);
        self::assertSame([], $stop->prefixCleanupNames);
    }

    public function testForceIpcSuccessKeepsMetadataWhenVerifiedResidualPidRemains(): void
    {
        $manager = new class extends ServerInstanceManager {
            public ?ServerInstanceInfo $info = null;
            public array $deleted = [];

            public function __construct()
            {
            }

            public function getInstanceInfo(string $instanceName, bool $strict = false): ?ServerInstanceInfo
            {
                unset($strict);

                return $instanceName === 'default' ? $this->info : null;
            }

            public function getRawInstanceData(string $instanceName): ?array
            {
                unset($instanceName);

                return null;
            }

            public function deleteInstance(string $instanceName): bool
            {
                $this->deleted[] = $instanceName;

                return true;
            }
        };
        $manager->info = new ServerInstanceInfo(
            'default',
            33780,
            26895,
            '127.0.0.1',
            443,
            true,
            \Weline\Server\Console\Server\stopTestRuntimeSelection(false),
            2,
            16895,
            80,
            '2026-04-20 12:00:00',
            1775448000,
            []
        );

        $stop = new class($manager) extends Stop {
            public array $calls = [];

            public function __construct(private readonly ServerInstanceManager $manager)
            {
            }

            protected function printWelcome(): void
            {
            }

            protected function acquireStopLock(string $instanceName, int $timeout = 5): bool
            {
                unset($instanceName, $timeout);

                return true;
            }

            protected function releaseStopLock(): void
            {
            }

            protected function getInstanceManager(): ServerInstanceManager
            {
                return $this->manager;
            }

            protected function isMasterProcessAvailableForStop(ServerInstanceInfo $info): bool
            {
                unset($info);

                return true;
            }

            protected function showInstanceInfo(ServerInstanceInfo $info, string $edgeAdapterName = \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX): void
            {
                unset($info);
                $this->calls[] = 'show';
            }

            protected function sendStopViaIpcAndWait(string $instanceName, int $controlPort, int $masterPid, bool $force): bool
            {
                unset($controlPort, $masterPid);
                $this->calls[] = 'ipc:' . $instanceName . ':' . ($force ? 'force' : 'normal');

                return true;
            }

            protected function collectFastLocalResidualPids(string $name, ServerInstanceInfo $info): array
            {
                unset($name, $info);

                return [33780];
            }

            protected function collectRunningResidualPids(array $pids, array $trustedPids = []): array
            {
                unset($trustedPids);

                return $pids;
            }

            protected function cleanupPidFiles(string $name, ServerInstanceInfo $info): void
            {
                unset($name, $info);
                $this->calls[] = 'pid';
            }
        };

        $stop->__init();
        \ob_start();
        try {
            $stop->execute([
                0 => 'server:stop',
                1 => 'default',
                'force' => true,
                'f' => true,
            ], []);
        } finally {
            \ob_end_clean();
        }

        self::assertSame([], $manager->deleted);
        self::assertSame(['show', 'ipc:default:force'], $stop->calls);
    }

    public function testGracefulStopTrustsAuthoritativeIpcCompletionWithoutLocalPrefixScan(): void
    {
        $manager = new class extends ServerInstanceManager {
            public ?ServerInstanceInfo $info = null;
            public array $deleted = [];

            public function __construct()
            {
            }

            public function getInstanceInfo(string $instanceName, bool $strict = false): ?ServerInstanceInfo
            {
                unset($strict);

                return $instanceName === 'default' ? $this->info : null;
            }

            public function getRawInstanceData(string $instanceName): ?array
            {
                return $instanceName === 'default' ? ['startup_phase' => 'running'] : null;
            }

            public function deleteInstance(string $instanceName): bool
            {
                $this->deleted[] = $instanceName;

                return true;
            }
        };
        $manager->info = new ServerInstanceInfo(
            'default',
            200,
            26895,
            '127.0.0.1',
            443,
            true,
            \Weline\Server\Console\Server\stopTestRuntimeSelection(false),
            1,
            16895,
            80,
            '2026-04-20 10:19:17',
            1776651557,
            []
        );

        $stop = new class($manager) extends Stop {
            public array $calls = [];

            public function __construct(private readonly ServerInstanceManager $manager)
            {
            }

            protected function printWelcome(): void
            {
            }

            protected function printGoodbye(bool $success = true, string $message = ''): void
            {
                unset($success, $message);
            }

            protected function acquireStopLock(string $instanceName, int $timeout = 5): bool
            {
                unset($instanceName, $timeout);

                return true;
            }

            protected function releaseStopLock(): void
            {
            }

            protected function getInstanceManager(): ServerInstanceManager
            {
                return $this->manager;
            }

            protected function maybeStopManagedNginx(
                string $instanceName,
                array $instanceData = [],
                bool $force = false,
            ): bool {
                unset($instanceName, $instanceData, $force);

                return true;
            }

            protected function isMasterProcessAvailableForStop(ServerInstanceInfo $info): bool
            {
                unset($info);

                return true;
            }

            protected function showInstanceInfo(ServerInstanceInfo $info, string $edgeAdapterName = \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX): void
            {
                unset($info);
                $this->calls[] = 'show';
            }

            protected function sendStopViaIpcAndWait(string $instanceName, int $controlPort, int $masterPid, bool $force): bool
            {
                unset($instanceName, $controlPort, $masterPid, $force);
                $this->calls[] = 'ipc';

                return true;
            }

            protected function runResidualCleanupPairWithRetry(string $name, ServerInstanceInfo $info, bool $includeSharedState = false): void
            {
                unset($info);
                $this->calls[] = 'residual:' . $name;
            }

            protected function releaseSharedStateConsumersForInstance(string $instanceName): void
            {
                $this->calls[] = 'release:' . $instanceName;
            }

            protected function cleanupPidFiles(string $name, ServerInstanceInfo $info): void
            {
                unset($info);
                $this->calls[] = 'pid:' . $name;
            }

            protected function releaseStartLock(string $instanceName): void
            {
                $this->calls[] = 'unlock:' . $instanceName;
            }
        };

        $stop->__init();
        \ob_start();
        try {
            $stop->execute([
                0 => 'server:stop',
                1 => 'default',
            ], []);
        } finally {
            \ob_end_clean();
        }

        self::assertSame(['default'], $manager->deleted);
        self::assertSame(
            ['show', 'ipc', 'release:default', 'pid:default', 'unlock:default'],
            $stop->calls
        );
    }
}
