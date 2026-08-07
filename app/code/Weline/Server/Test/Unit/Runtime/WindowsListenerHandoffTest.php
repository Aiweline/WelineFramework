<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;
use Weline\Server\Service\Runtime\WindowsListenerHandoff;
use Weline\Server\Service\Runtime\WindowsListenerHandoffMutexGuard;
use Weline\Server\Service\Runtime\WindowsListenerHandoffRuntime;

final class WindowsListenerHandoffTest extends TestCase
{
    private string $directory = '';

    /** @var list<\Socket> */
    private array $sockets = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-windows-handoff-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        try {
            $this->simulateSourceProcessExit();
        } catch (\Throwable) {
        }
        foreach ($this->sockets as $socket) {
            try {
                @\socket_close($socket);
            } catch (\Throwable) {
            }
        }
        $this->removeTree($this->directory);
        parent::tearDown();
    }

    public function testExportOwnershipReleasesWhenPendingRecordCannotBePublished(): void
    {
        [$listener, $host, $port] = $this->listener();
        $path = $this->directory . DIRECTORY_SEPARATOR . 'handoff.json';
        self::assertTrue(\mkdir($this->pendingRegistryPath(), 0700));
        $released = [];
        $runtime = $this->runtime(
            exporter: static fn (\Socket $socket, int $pid): string => 'protocol-token',
            releaser: static function (string $token) use (&$released): bool {
                $released[] = $token;
                return true;
            },
        );

        try {
            $this->publish(
                $listener,
                $path,
                $this->intent($host, $port),
                $runtime,
            );
            self::fail('An unsafe pending registry must abort publication.');
        } catch (\RuntimeException) {
        }

        self::assertSame(['protocol-token'], $released);
        self::assertFileDoesNotExist($path);
    }

    public function testPendingRegistryMutationCollectsBackupPairedWithValidRegistry(): void
    {
        [$listener, $host, $port] = $this->listener();
        $path = $this->directory . DIRECTORY_SEPARATOR . 'handoff.json';
        $monotonic = 10.0;
        $released = [];
        $runtime = $this->runtime(
            exporter: static fn (\Socket $socket, int $pid): string => 'protocol-token',
            releaser: static function (string $token) use (&$released): bool {
                $released[] = $token;
                return true;
            },
            monotonicClock: static function () use (&$monotonic): float {
                return $monotonic;
            },
        );
        $this->publish($listener, $path, $this->intent($host, $port), $runtime);
        $registry = $this->pendingRegistryPath();
        $backup = $registry . '.wls-backup-' . \str_repeat('c', 16);
        self::assertNotFalse(@\copy($registry, $backup));
        @\chmod($backup, 0600);

        $monotonic = 71.0;
        self::assertSame(1, $this->sweep($runtime));

        self::assertFileDoesNotExist($backup);
        self::assertSame(['protocol-token'], $released);
    }

    public function testPendingRegistryMutationPreservesBackupForMalformedRegistry(): void
    {
        [$listener, $host, $port] = $this->listener();
        $path = $this->directory . DIRECTORY_SEPARATOR . 'handoff.json';
        $runtime = $this->runtime(
            exporter: static fn (\Socket $socket, int $pid): string => 'protocol-token',
        );
        $this->publish($listener, $path, $this->intent($host, $port), $runtime);
        $registry = $this->pendingRegistryPath();
        $backup = $registry . '.wls-backup-' . \str_repeat('d', 16);
        self::assertNotFalse(@\copy($registry, $backup));
        @\chmod($backup, 0600);
        self::assertNotFalse(@\file_put_contents($registry, "{}\n"));

        try {
            $this->sweep($runtime);
            self::fail('Malformed paired registry must veto retained-backup cleanup.');
        } catch (\RuntimeException) {
            self::assertFileExists($backup);
            self::assertSame("{}\n", (string)\file_get_contents($registry));
        }
    }

    public function testEnvelopeValidationFailureReleasesRegisteredToken(): void
    {
        [$listener, $host, $port] = $this->listener();
        $path = $this->directory . DIRECTORY_SEPARATOR . 'handoff.json';
        $released = [];
        $imports = 0;
        $runtime = $this->runtime(
            exporter: static fn (\Socket $socket, int $pid): string => 'protocol-token',
            importer: static function (string $token) use (&$imports): bool {
                $imports++;
                return false;
            },
            releaser: static function (string $token) use (&$released): bool {
                $released[] = $token;
                return true;
            },
        );
        $intent = $this->intent($host, $port);
        $this->publish($listener, $path, $intent, $runtime);

        try {
            $this->await(
                $path,
                $intent,
                \str_repeat('f', 32),
                $runtime,
            );
            self::fail('A launch-mismatched envelope must be rejected.');
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $imports);
        self::assertSame(['protocol-token'], $released);
        self::assertFileDoesNotExist($path);
        self::assertPendingRegistryEmpty();
    }

    public function testTargetValidationFailureRequestsReleaseFromExactExporter(): void
    {
        [$listener, $host, $port] = $this->listener();
        $path = $this->directory . DIRECTORY_SEPARATOR . 'handoff.json';
        $sourceReleases = [];
        $targetReleases = [];
        $identity = $this->identity();
        $sourceRuntime = $this->runtime(
            identity: $identity,
            exporter: static fn (\Socket $socket, int $pid): string => 'protocol-token',
            releaser: static function (string $token) use (&$sourceReleases): bool {
                $sourceReleases[] = $token;
                return true;
            },
            currentPid: 111,
        );
        $targetRuntime = $this->runtime(
            identity: $identity,
            releaser: static function (string $token) use (&$targetReleases): bool {
                $targetReleases[] = $token;
                return false;
            },
            currentPid: 222,
        );
        $intent = $this->intent($host, $port);
        $this->publish($listener, $path, $intent, $sourceRuntime, 222);

        try {
            $this->await(
                $path,
                $intent,
                \str_repeat('f', 32),
                $targetRuntime,
                222,
            );
            self::fail('A launch-mismatched envelope must be rejected.');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $targetReleases);
        self::assertSame([], $sourceReleases);
        $records = $this->pendingRecords();
        self::assertCount(1, $records);
        $pending = \array_values($records)[0];
        self::assertSame(111, $pending['source_pid']);
        self::assertSame(222, $pending['target_pid']);
        self::assertSame(1, $pending['socket_generation']);
        self::assertTrue($pending['release_requested']);
        self::assertSame('REJECTED', $pending['consumer_state']);

        self::assertSame(1, $this->sweep($sourceRuntime));
        self::assertSame(['protocol-token'], $sourceReleases);
        self::assertSame([], $targetReleases);
        self::assertPendingRegistryEmpty();
    }

    public function testGenerationMismatchCannotRequestOrDeletePendingExport(): void
    {
        [$listener, $host, $port] = $this->listener();
        $path = $this->directory . DIRECTORY_SEPARATOR . 'handoff.json';
        $monotonic = 10.0;
        $sourceReleases = [];
        $targetReleases = [];
        $identity = $this->identity();
        $sourceRuntime = $this->runtime(
            identity: $identity,
            exporter: static fn (\Socket $socket, int $pid): string => 'protocol-token',
            releaser: static function (string $token) use (&$sourceReleases): bool {
                $sourceReleases[] = $token;
                return true;
            },
            monotonicClock: static function () use (&$monotonic): float {
                return $monotonic;
            },
            currentPid: 111,
        );
        $targetRuntime = $this->runtime(
            identity: $identity,
            releaser: static function (string $token) use (&$targetReleases): bool {
                $targetReleases[] = $token;
                return false;
            },
            currentPid: 222,
        );
        $intent = $this->intent($host, $port);
        $this->publish($listener, $path, $intent, $sourceRuntime, 222);

        try {
            $this->await(
                $path,
                $intent,
                \str_repeat('b', 32),
                $targetRuntime,
                222,
                2,
            );
            self::fail('A generation-mismatched target must be rejected.');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $targetReleases);
        self::assertSame([], $sourceReleases);
        $records = $this->pendingRecords();
        self::assertCount(1, $records);
        $pending = \array_values($records)[0];
        self::assertFalse($pending['release_requested']);
        self::assertSame('PENDING', $pending['consumer_state']);
        self::assertSame(1, $pending['socket_generation']);

        $monotonic = 71.0;
        self::assertSame(1, $this->sweep($sourceRuntime));
        self::assertSame(['protocol-token'], $sourceReleases);
        self::assertPendingRegistryEmpty();
    }

    public function testImportedSocketIsClosedWhenListenerAssertionFails(): void
    {
        [$listener, $host, $port] = $this->listener();
        $imported = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertInstanceOf(\Socket::class, $imported);
        $this->sockets[] = $imported;
        $path = $this->directory . DIRECTORY_SEPARATOR . 'handoff.json';
        $sourceReleases = [];
        $targetReleases = [];
        $closed = 0;
        $identity = $this->identity();
        $sourceRuntime = $this->runtime(
            identity: $identity,
            exporter: static fn (\Socket $socket, int $pid): string => 'protocol-token',
            releaser: static function (string $token) use (&$sourceReleases): bool {
                $sourceReleases[] = $token;
                return true;
            },
            currentPid: 111,
        );
        $targetRuntime = $this->runtime(
            identity: $identity,
            importer: static fn (string $token): \Socket => $imported,
            releaser: static function (string $token) use (&$targetReleases): bool {
                $targetReleases[] = $token;
                return false;
            },
            closer: static function (\Socket $socket) use (&$closed): void {
                $closed++;
                @\socket_close($socket);
            },
            currentPid: 222,
        );
        $intent = $this->intent($host, $port);
        $this->publish($listener, $path, $intent, $sourceRuntime, 222);

        try {
            $this->await(
                $path,
                $intent,
                \str_repeat('b', 32),
                $targetRuntime,
                222,
            );
            self::fail('A non-listening imported socket must be rejected.');
        } catch (\RuntimeException) {
        }

        self::assertSame(1, $closed);
        self::assertSame([], $targetReleases);
        self::assertSame([], $sourceReleases);
        $records = $this->pendingRecords();
        self::assertCount(1, $records);
        self::assertSame('REJECTED', \array_values($records)[0]['consumer_state']);
        self::assertSame(1, $this->sweep($sourceRuntime));
        self::assertSame(['protocol-token'], $sourceReleases);
        self::assertPendingRegistryEmpty();
    }

    public function testSuccessfulImportIsReleasedOnlyByExactExporter(): void
    {
        [$listener, $host, $port] = $this->listener();
        $path = $this->directory . DIRECTORY_SEPARATOR . 'handoff.json';
        $sourceReleases = [];
        $targetReleases = [];
        $targetCloses = 0;
        $mutexReleases = 0;
        $identity = $this->identity();
        $intent = $this->intent($host, $port);
        $targetRuntime = $this->runtime(
            identity: $identity,
            importer: static fn (string $token): \Socket => $listener,
            releaser: static function (string $token) use (&$targetReleases): bool {
                $targetReleases[] = $token;
                return false;
            },
            closer: static function (\Socket $socket) use (&$targetCloses): void {
                $targetCloses++;
            },
            currentPid: 222,
        );
        $sourceRuntime = $this->runtime(
            identity: $identity,
            exporter: static fn (\Socket $socket, int $pid): string => 'protocol-token',
            releaser: static function (string $token) use (&$sourceReleases): bool {
                $sourceReleases[] = $token;
                return true;
            },
            currentPid: 111,
            mutexAcquirer: static function (int $timeout) use (
                &$mutexReleases,
            ): WindowsListenerHandoffMutexGuard {
                return new WindowsListenerHandoffMutexGuard(
                    false,
                    static function () use (&$mutexReleases): void {
                        $mutexReleases++;
                    },
                );
            },
            publisherCoordinator: function (string $publishedPath) use (
                $intent,
                $targetRuntime,
            ): bool {
                $this->await(
                    $publishedPath,
                    $intent,
                    \str_repeat('b', 32),
                    $targetRuntime,
                    222,
                );
                return false;
            },
        );
        $this->publish($listener, $path, $intent, $sourceRuntime, 222);

        self::assertSame(0, $targetCloses);
        self::assertSame([], $targetReleases);
        self::assertSame(['protocol-token'], $sourceReleases);
        self::assertSame(1, $mutexReleases);
        self::assertPendingRegistryEmpty();
    }

    public function testSourceReusesMutexUntilEveryPendingMappingIsReleased(): void
    {
        [$listener, $host, $port] = $this->listener();
        $monotonic = 10.0;
        $exports = 0;
        $mutexAcquires = 0;
        $mutexReleases = 0;
        $releasedTokens = [];
        $runtime = $this->runtime(
            exporter: static function (\Socket $socket, int $pid) use (&$exports): string {
                $exports++;
                return 'protocol-token-' . $exports;
            },
            releaser: static function (string $token) use (&$releasedTokens): bool {
                $releasedTokens[] = $token;
                return true;
            },
            monotonicClock: static function () use (&$monotonic): float {
                return $monotonic;
            },
            currentPid: 111,
            mutexAcquirer: static function (int $timeout) use (
                &$mutexAcquires,
                &$mutexReleases,
            ): WindowsListenerHandoffMutexGuard {
                $mutexAcquires++;
                return new WindowsListenerHandoffMutexGuard(
                    false,
                    static function () use (&$mutexReleases): void {
                        $mutexReleases++;
                    },
                );
            },
        );
        $intent = $this->intent($host, $port);
        $this->publish(
            $listener,
            $this->directory . DIRECTORY_SEPARATOR . 'handoff-a.json',
            $intent,
            $runtime,
            222,
        );
        $this->publish(
            $listener,
            $this->directory . DIRECTORY_SEPARATOR . 'handoff-b.json',
            $intent,
            $runtime,
            223,
        );

        self::assertSame(1, $mutexAcquires);
        self::assertSame(0, $mutexReleases);
        self::assertCount(2, $this->pendingRecords());
        $monotonic = 71.0;
        self::assertSame(2, $this->sweep($runtime));
        \sort($releasedTokens, SORT_STRING);
        self::assertSame(['protocol-token-1', 'protocol-token-2'], $releasedTokens);
        self::assertSame(1, $mutexReleases);
        self::assertPendingRegistryEmpty();
    }

    public function testMutexTimeoutFailsBeforeNativeExport(): void
    {
        [$listener, $host, $port] = $this->listener();
        $exportCalls = 0;
        $observedTimeout = 0;
        $runtime = $this->runtime(
            exporter: static function (\Socket $socket, int $pid) use (&$exportCalls): string {
                $exportCalls++;
                return 'must-not-export';
            },
            currentPid: 111,
            mutexAcquirer: static function (int $timeout) use (&$observedTimeout): never {
                $observedTimeout = $timeout;
                throw new \RuntimeException('simulated mutex timeout');
            },
        );

        try {
            $this->publish(
                $listener,
                $this->directory . DIRECTORY_SEPARATOR . 'handoff.json',
                $this->intent($host, $port),
                $runtime,
                222,
            );
            self::fail('A mutex timeout must fail before WSAPROTOCOL export.');
        } catch (\RuntimeException $exception) {
            self::assertSame('simulated mutex timeout', $exception->getMessage());
        }

        self::assertSame(20_000, $observedTimeout);
        self::assertSame(0, $exportCalls);
        self::assertFileDoesNotExist($this->pendingRegistryPath());
    }

    public function testAbandonedMutexAcquisitionIsPersistedAsRecoveryState(): void
    {
        [$listener, $host, $port] = $this->listener();
        $monotonic = 10.0;
        $mutexReleases = 0;
        $runtime = $this->runtime(
            exporter: static fn (\Socket $socket, int $pid): string => 'protocol-token',
            releaser: static fn (string $token): bool => true,
            monotonicClock: static function () use (&$monotonic): float {
                return $monotonic;
            },
            currentPid: 111,
            mutexAcquirer: static function (int $timeout) use (
                &$mutexReleases,
            ): WindowsListenerHandoffMutexGuard {
                return new WindowsListenerHandoffMutexGuard(
                    true,
                    static function () use (&$mutexReleases): void {
                        $mutexReleases++;
                    },
                );
            },
        );
        $this->publish(
            $listener,
            $this->directory . DIRECTORY_SEPARATOR . 'handoff.json',
            $this->intent($host, $port),
            $runtime,
            222,
        );

        $records = $this->pendingRecords();
        self::assertCount(1, $records);
        self::assertSame(
            'ABANDONED',
            \array_values($records)[0]['mutex_recovery_state'],
        );
        $monotonic = 71.0;
        self::assertSame(1, $this->sweep($runtime));
        self::assertSame(1, $mutexReleases);
        self::assertPendingRegistryEmpty();
    }

    public function testAbandonedMutexCannotDeleteAnUnretiredSourceBirth(): void
    {
        [$listener, $host, $port] = $this->listener();
        $identity = $this->identity();
        $oldRuntime = $this->runtime(
            identity: $identity,
            exporter: static fn (\Socket $socket, int $pid): string => 'stale-token',
            releaser: static fn (string $token): bool => true,
            currentPid: 444,
        );
        $this->publish(
            $listener,
            $this->directory . DIRECTORY_SEPARATOR . 'stale.json',
            $this->intent($host, $port),
            $oldRuntime,
            555,
        );
        $this->simulateSourceProcessExit();

        $exports = 0;
        $mutexReleases = 0;
        $newRuntime = $this->runtime(
            identity: $identity,
            exporter: static function (\Socket $socket, int $pid) use (&$exports): string {
                $exports++;
                return 'new-token';
            },
            currentPid: 111,
            mutexAcquirer: static function (int $timeout) use (
                &$mutexReleases,
            ): WindowsListenerHandoffMutexGuard {
                return new WindowsListenerHandoffMutexGuard(
                    true,
                    static function () use (&$mutexReleases): void {
                        $mutexReleases++;
                    },
                );
            },
        );
        try {
            $this->publish(
                $listener,
                $this->directory . DIRECTORY_SEPARATOR . 'new.json',
                $this->intent($host, $port),
                $newRuntime,
                222,
            );
            self::fail('An unretired source birth must block abandoned recovery.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Abandoned Windows export mutex has an unretired source birth.',
                $exception->getMessage(),
            );
        }

        self::assertSame(0, $exports);
        self::assertSame(1, $mutexReleases);
        $records = $this->pendingRecords();
        self::assertCount(1, $records);
        self::assertSame(444, \array_values($records)[0]['source_pid']);
    }

    public function testExitedSourceBirthClearsRegistryWithoutForeignRelease(): void
    {
        [$listener, $host, $port] = $this->listener();
        $sourceAlive = true;
        $nativeReleases = [];
        $identity = $this->identity(
            static function (int $pid) use (&$sourceAlive): array {
                if ($pid === 111 && !$sourceAlive) {
                    return ['exists' => false];
                }
                return [
                    'exists' => true,
                    'start_time' => 'stable-' . $pid,
                    'name' => 'php',
                    'command' => PHP_BINARY,
                ];
            },
        );
        $sourceRuntime = $this->runtime(
            identity: $identity,
            exporter: static fn (\Socket $socket, int $pid): string => 'protocol-token',
            releaser: static function (string $token) use (&$nativeReleases): bool {
                $nativeReleases[] = $token;
                return true;
            },
            currentPid: 111,
        );
        $this->publish(
            $listener,
            $this->directory . DIRECTORY_SEPARATOR . 'handoff.json',
            $this->intent($host, $port),
            $sourceRuntime,
            222,
        );
        self::assertCount(1, $this->pendingRecords());

        $sourceAlive = false;
        $this->simulateSourceProcessExit();
        $observerRuntime = $this->runtime(
            identity: $identity,
            releaser: static function (string $token) use (&$nativeReleases): bool {
                $nativeReleases[] = $token;
                return false;
            },
            currentPid: 333,
        );
        self::assertSame(1, $this->sweep($observerRuntime));
        self::assertSame([], $nativeReleases);
        self::assertPendingRegistryEmpty();
    }

    public function testPendingExportExpiresUsingMonotonicDeadline(): void
    {
        [$listener, $host, $port] = $this->listener();
        $path = $this->directory . DIRECTORY_SEPARATOR . 'handoff.json';
        $monotonic = 10.0;
        $released = [];
        $runtime = $this->runtime(
            exporter: static fn (\Socket $socket, int $pid): string => 'protocol-token',
            releaser: static function (string $token) use (&$released): bool {
                $released[] = $token;
                return true;
            },
            monotonicClock: static function () use (&$monotonic): float {
                return $monotonic;
            },
            currentPid: 111,
        );
        $this->publish(
            $listener,
            $path,
            $this->intent($host, $port),
            $runtime,
            222,
        );
        self::assertFileExists($this->pendingRegistryPath());
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, \fileperms($this->pendingRegistryPath()) & 0777);
        }

        $monotonic = 71.0;
        self::assertSame(1, $this->sweep($runtime));
        self::assertSame(['protocol-token'], $released);
        self::assertPendingRegistryEmpty();
    }

    public function testPendingExportUsesBirthMismatchInsteadOfNumericPid(): void
    {
        [$listener, $host, $port] = $this->listener();
        $path = $this->directory . DIRECTORY_SEPARATOR . 'handoff.json';
        $targetBirthGeneration = 'first';
        $released = [];
        $identity = $this->identity(
            static function (int $pid) use (&$targetBirthGeneration): array {
                return [
                    'exists' => true,
                    'start_time' => $pid === 222
                        ? $targetBirthGeneration . '-' . $pid
                        : 'source-stable-' . $pid,
                    'name' => 'php',
                    'command' => PHP_BINARY,
                ];
            },
        );
        $runtime = $this->runtime(
            identity: $identity,
            exporter: static fn (\Socket $socket, int $pid): string => 'protocol-token',
            releaser: static function (string $token) use (&$released): bool {
                $released[] = $token;
                return true;
            },
            monotonicClock: static fn (): float => 10.0,
            currentPid: 111,
        );
        $this->publish(
            $listener,
            $path,
            $this->intent($host, $port),
            $runtime,
            222,
        );

        $targetBirthGeneration = 'recycled';
        self::assertSame(1, $this->sweep($runtime));
        self::assertSame(['protocol-token'], $released);
        self::assertPendingRegistryEmpty();
    }

    /** @return array{0:\Socket,1:string,2:int} */
    private function listener(): array
    {
        $socket = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertInstanceOf(\Socket::class, $socket);
        self::assertTrue(\socket_bind($socket, '127.0.0.1', 0));
        self::assertTrue(\socket_listen($socket));
        $host = '';
        $port = 0;
        self::assertTrue(\socket_getsockname($socket, $host, $port));
        $this->sockets[] = $socket;
        return [$socket, $host, $port];
    }

    /** @return array<string,mixed> */
    private function intent(string $host, int $port): array
    {
        $intent = [
            'schema_version' => 1,
            'transport' => WindowsListenerHandoff::TRANSPORT,
            'continuous_ownership' => true,
            'handoff_id' => \str_repeat('1', 32),
            'lease_id' => \str_repeat('2', 32),
            'instance' => 'handoff-test',
            'wls_instance' => 'handoff-test',
            'bind_host' => $host,
            'port' => $port,
            'launch_id' => \str_repeat('a', 32),
            'master_path' => $this->directory . DIRECTORY_SEPARATOR . 'master.json',
        ];
        $digest = new \ReflectionMethod(WindowsListenerHandoff::class, 'digest');
        $intent['intent_digest'] = $digest->invoke(null, $intent);
        return $intent;
    }

    private function runtime(
        ?MasterLeaseRuntimeIdentity $identity = null,
        ?\Closure $exporter = null,
        ?\Closure $importer = null,
        ?\Closure $releaser = null,
        ?\Closure $closer = null,
        ?\Closure $monotonicClock = null,
        ?int $currentPid = null,
        ?\Closure $mutexAcquirer = null,
        ?\Closure $publisherCoordinator = null,
    ): WindowsListenerHandoffRuntime {
        return new WindowsListenerHandoffRuntime(
            $identity ?? $this->identity(),
            $exporter,
            $importer,
            $releaser,
            $closer,
            $monotonicClock ?? static fn (): float => 10.0,
            static fn (): int => 1_700_000_000,
            $currentPid !== null ? static fn (): int => $currentPid : null,
            $mutexAcquirer ?? static fn (int $timeout): WindowsListenerHandoffMutexGuard =>
                new WindowsListenerHandoffMutexGuard(false, static function (): void {
                }),
            $publisherCoordinator ?? static fn (string $path): bool => true,
        );
    }

    private function identity(?\Closure $processInfo = null): MasterLeaseRuntimeIdentity
    {
        $processInfo ??= static fn (int $pid): array => [
            'exists' => true,
            'start_time' => 'stable-' . $pid,
            'name' => 'php',
            'command' => PHP_BINARY,
        ];
        return new MasterLeaseRuntimeIdentity(
            static fn (): string => \str_repeat('c', 64),
            static fn (): float => 10.0,
            $processInfo,
            null,
            static fn (int $pid): ?string => PHP_OS_FAMILY === 'Linux'
                ? 'pid:[424242]'
                : null,
        );
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    private function publish(
        \Socket $socket,
        string $path,
        array $intent,
        WindowsListenerHandoffRuntime $runtime,
        ?int $targetPid = null,
    ): array {
        $method = new \ReflectionMethod(WindowsListenerHandoff::class, 'publishEnvelope');
        return $method->invoke(
            null,
            $socket,
            $path,
            $intent,
            'master_to_dispatcher',
            $targetPid ?? \getmypid(),
            \str_repeat('b', 32),
            'dispatcher:1',
            1,
            $runtime,
        );
    }

    /** @param array<string,mixed> $intent */
    private function await(
        string $path,
        array $intent,
        string $launchId,
        WindowsListenerHandoffRuntime $runtime,
        ?int $targetPid = null,
        int $generation = 1,
    ): void {
        $method = new \ReflectionMethod(WindowsListenerHandoff::class, 'awaitEnvelope');
        $method->invoke(
            null,
            $path,
            $intent,
            'master_to_dispatcher',
            $targetPid ?? \getmypid(),
            $launchId,
            'dispatcher:1',
            $generation,
            $runtime,
        );
    }

    private function sweep(WindowsListenerHandoffRuntime $runtime): int
    {
        $method = new \ReflectionMethod(
            WindowsListenerHandoff::class,
            'sweepPendingExports',
        );
        return (int)$method->invoke(null, $this->directory, $runtime);
    }

    private function pendingRegistryPath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR
            . '.wls-listener-handoff-pending.json';
    }

    private function assertPendingRegistryEmpty(): void
    {
        $path = $this->pendingRegistryPath();
        if (!\is_file($path)) {
            self::assertFileDoesNotExist($path);
            return;
        }
        self::assertSame([], $this->pendingRecords());
    }

    /** @return array<string,array<string,mixed>> */
    private function pendingRecords(): array
    {
        $decoded = \json_decode(
            (string)\file_get_contents($this->pendingRegistryPath()),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);
        self::assertArrayHasKey('records', $decoded);
        self::assertIsArray($decoded['records']);
        return $decoded['records'];
    }

    private function simulateSourceProcessExit(): void
    {
        $reflection = new \ReflectionClass(WindowsListenerHandoff::class);
        $guardProperty = $reflection->getProperty('exportMutexGuard');
        $guard = $guardProperty->getValue();
        if ($guard instanceof WindowsListenerHandoffMutexGuard) {
            $guard->release();
        }
        foreach ([
            'exportMutexGuard' => null,
            'ownedExportProtocols' => [],
            'exportMutexSourcePid' => 0,
            'exportMutexSourceBirth' => '',
            'exportMutexSourcePidNamespaceId' => '',
            'exportMutexRecoveryState' => 'NONE',
        ] as $property => $value) {
            $reflection->getProperty($property)->setValue(null, $value);
        }
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($root);
    }
}
