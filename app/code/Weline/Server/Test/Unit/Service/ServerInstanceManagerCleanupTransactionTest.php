<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Runtime\ServerLifecycleOperationLock;
use Weline\Server\Service\Runtime\VerifiedPersistentFileLock;
use Weline\Server\Service\ServerInstanceManager;

final class ServerInstanceManagerCleanupTransactionTest extends TestCase
{
    private string $directory;

    /** @var list<string> */
    private array $instanceNames = [];

    /** @var list<array{pid:int,process_name:string,launch_id:string}> */
    private array $managedLeases = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-clean-transaction-' . \bin2hex(\random_bytes(8))
            . DIRECTORY_SEPARATOR;
        self::assertTrue(@\mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        foreach (\array_reverse($this->managedLeases) as $lease) {
            Processer::removeManagedProcessLeaseRecord(
                $lease['pid'],
                $lease['process_name'],
                $lease['launch_id'],
            );
        }
        foreach ($this->instanceNames as $instanceName) {
            foreach ([
                ServerLifecycleOperationLock::pathForInstance($instanceName),
                Env::VAR_DIR . 'server' . DS . 'locks' . DS
                    . 'start_' . $instanceName . '.lock',
                Env::VAR_DIR . 'server' . DS . 'locks' . DS
                    . 'stop_' . $instanceName . '.lock',
            ] as $lockPath) {
                @\unlink($lockPath);
            }
        }
        if (\is_dir($this->directory)) {
            $iterator = new \FilesystemIterator(
                $this->directory,
                \FilesystemIterator::SKIP_DOTS,
            );
            foreach ($iterator as $item) {
                $item->isDir() && !$item->isLink()
                    ? @\rmdir($item->getPathname())
                    : @\unlink($item->getPathname());
            }
            @\rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function testForceCleanupRejectsEndpointGenerationPublishedAfterInitialScan(): void
    {
        $name = $this->newInstanceName('generation-race');
        $initial = $this->stoppedEndpoint($name, 11, 1_000);
        $replacement = $this->stoppedEndpoint($name, 12_345, 9_999_999);
        $replacement['gateway'] = ['requested_mode' => 'wls'];
        $this->writeEndpoint($name, $initial);

        $manager = new class($this->directory, $name, $replacement) extends ServerInstanceManager {
            private int $reads = 0;

            /** @param array<string,mixed> $replacement */
            public function __construct(
                private readonly string $directory,
                private readonly string $targetName,
                private readonly array $replacement,
            ) {
                parent::__construct();
            }

            public function getInstanceDir(): string
            {
                return $this->directory;
            }

            public function getRawInstanceData(string $name): ?array
            {
                $selected = parent::getRawInstanceData($name);
                if ($name === $this->targetName && $this->reads++ === 0) {
                    $path = $this->getInstanceFile($name);
                    \file_put_contents(
                        $path,
                        \json_encode(
                            $this->replacement,
                            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
                        ),
                    );
                    @\chmod($path, 0600);
                    \clearstatcache(true, $path);
                }
                return $selected;
            }
        };

        self::assertSame([], $manager->cleanupInactiveInstances());
        self::assertSame($replacement, $manager->getRawInstanceData($name));
    }

    public function testCleanupDefersWithinBoundedTimeWhenLifecycleOrStartLockIsHeld(): void
    {
        foreach (['lifecycle', 'start'] as $kind) {
            $name = $this->newInstanceName($kind . '-held');
            $this->writeEndpoint($name, $this->stoppedEndpoint($name, 21, 2_000));
            $manager = $this->manager();
            $lockPath = $kind === 'lifecycle'
                ? ServerLifecycleOperationLock::pathForInstance($name)
                : Env::VAR_DIR . 'server' . DS . 'locks' . DS
                    . 'start_' . $name . '.lock';
            [$process, $pipes] = $this->holdLockInChildProcess($lockPath);
            try {
                $heldIdentity = @\lstat($lockPath);
                self::assertIsArray($heldIdentity);
                $status = \proc_get_status($process);
                self::assertTrue((bool)($status['running'] ?? false));
                self::assertTrue(VerifiedPersistentFileLock::isHeld($lockPath));
                $started = \hrtime(true);
                $cleaned = $manager->cleanupInactiveInstances();
                $elapsed = (\hrtime(true) - $started) / 1_000_000_000;
                $afterIdentity = @\lstat($lockPath);
                self::assertIsArray($afterIdentity);
                self::assertSame((int)$heldIdentity['dev'], (int)$afterIdentity['dev']);
                self::assertSame((int)$heldIdentity['ino'], (int)$afterIdentity['ino']);
                self::assertTrue((bool)(\proc_get_status($process)['running'] ?? false));
                self::assertTrue(VerifiedPersistentFileLock::isHeld($lockPath));
                self::assertSame([], $cleaned);
                self::assertLessThan(1.0, $elapsed);
                self::assertFileExists($manager->getInstanceFile($name));
            } finally {
                @\fwrite($pipes[0], "\n");
                @\fclose($pipes[0]);
                @\fclose($pipes[1]);
                @\fclose($pipes[2]);
                @\proc_close($process);
            }
            @\unlink($manager->getInstanceFile($name));
        }
    }

    public function testCleanupRetainsEveryStableLockPathAndInode(): void
    {
        $name = $this->newInstanceName('stable-locks');
        $this->writeEndpoint($name, $this->stoppedEndpoint($name, 31, 3_000));
        $manager = $this->manager();
        $lockPaths = [
            $manager->getInstanceFile($name) . '.lock',
            $manager->getPidFile($name) . '.lock',
            $this->directory . $name . '.resurrect.lock',
            $this->directory . ServerInstanceManager::GATEWAY_ENDPOINT_NAMESPACE_LOCK,
            Env::VAR_DIR . 'server' . DS . 'locks' . DS
                . 'start_' . $name . '.lock',
            Env::VAR_DIR . 'server' . DS . 'locks' . DS
                . 'stop_' . $name . '.lock',
            ServerLifecycleOperationLock::pathForInstance($name),
        ];
        $identities = [];
        foreach ($lockPaths as $lockPath) {
            $parent = \dirname($lockPath);
            if (!\is_dir($parent)) {
                self::assertTrue(@\mkdir($parent, 0700, true));
            }
            self::assertNotFalse(\file_put_contents($lockPath, 'stable-lock'));
            @\chmod($lockPath, 0600);
            $status = @\lstat($lockPath);
            self::assertIsArray($status);
            $identities[$lockPath] = $status;
        }

        self::assertSame([$name], $manager->cleanupInactiveInstances());
        self::assertFileDoesNotExist($manager->getInstanceFile($name));
        foreach ($identities as $lockPath => $before) {
            $after = @\lstat($lockPath);
            self::assertIsArray($after, $lockPath . ' must remain a stable lock path');
            self::assertSame((int)$before['dev'], (int)$after['dev'], $lockPath);
            self::assertSame((int)$before['ino'], (int)$after['ino'], $lockPath);
        }
    }

    public function testCleanupRetiresServingReferencesBeforeEndpointCommit(): void
    {
        $name = $this->newInstanceName('serving-retirement');
        $endpoint = $this->stoppedEndpoint($name, 35, 3_500);
        $endpoint['gateway'] = [
            'instance_id' => $name,
            'serving_manifest_generation' => 7,
            'serving_manifest_digest' => \str_repeat('a', 64),
        ];
        $this->writeEndpoint($name, $endpoint);

        $manager = new class($this->directory) extends ServerInstanceManager {
            /** @var list<array{instance:string,generation:int,digest:string,endpoint_present:bool}> */
            public array $retirements = [];

            public function __construct(private readonly string $directory)
            {
                parent::__construct();
            }

            public function getInstanceDir(): string
            {
                return $this->directory;
            }

            protected function retireInactiveServingManifestReferences(
                string $name,
                array $rawData,
            ): void {
                $gateway = (array)($rawData['gateway'] ?? []);
                $this->retirements[] = [
                    'instance' => $name,
                    'generation' => (int)($gateway['serving_manifest_generation'] ?? 0),
                    'digest' => (string)($gateway['serving_manifest_digest'] ?? ''),
                    'endpoint_present' => \is_file($this->getInstanceFile($name)),
                ];
            }
        };

        self::assertSame([$name], $manager->cleanupInactiveInstances());
        self::assertSame([[
            'instance' => $name,
            'generation' => 7,
            'digest' => \str_repeat('a', 64),
            'endpoint_present' => true,
        ]], $manager->retirements);
        self::assertFileDoesNotExist($manager->getInstanceFile($name));
    }

    public function testCleanupRemovesOnlyEndpointSelectedManagedLeaseGeneration(): void
    {
        $name = $this->newInstanceName('pid-cas');
        $processName = MasterProcess::getMasterProcessName($name);
        $oldPid = 1_700_000_000 + \random_int(1, 100_000);
        $replacementPid = $oldPid + 200_000;
        $oldLaunchId = 'cleanup-old-' . \bin2hex(\random_bytes(12));
        $replacementLaunchId = 'cleanup-new-' . \bin2hex(\random_bytes(12));
        $oldPname = '--name=' . $processName
            . ' --launch-id=' . $oldLaunchId . ' --epoch=41';
        $replacementPname = '--name=' . $processName
            . ' --launch-id=' . $replacementLaunchId . ' --epoch=42';
        Processer::setPid($oldPname, $oldPid, false);
        Processer::setPid($replacementPname, $replacementPid, false);
        $this->managedLeases[] = [
            'pid' => $oldPid,
            'process_name' => $processName,
            'launch_id' => $oldLaunchId,
        ];
        $this->managedLeases[] = [
            'pid' => $replacementPid,
            'process_name' => $processName,
            'launch_id' => $replacementLaunchId,
        ];

        $endpoint = $this->stoppedEndpoint($name, 41, 4_000);
        $endpoint['startup_events'] = [[
            'kind' => 'master_started',
            'pid' => $oldPid,
            'seq' => 1,
        ]];
        $this->writeEndpoint($name, $endpoint);
        $manager = $this->manager();

        self::assertSame([$name], $manager->cleanupInactiveInstances());
        self::assertSame([], Processer::getManagedProcessLeaseRecord($oldPid, $oldPname));
        self::assertNotSame(
            [],
            Processer::getManagedProcessLeaseRecord($replacementPid, $replacementPname),
            'A same-name lease outside the selected endpoint generation must survive.',
        );
    }

    public function testCleanupHasNoPostTransactionTouchedPidSweep(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Server/Service/ServerInstanceManager.php',
        );
        $start = \strpos($source, 'public function cleanupInactiveInstances(): array');
        $end = \strpos($source, 'private function shouldPurgeStoppedInstanceRecord', (int)$start);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $method = \substr($source, $start, $end - $start);

        self::assertStringNotContainsString('$touchedPids', $method);
        self::assertStringNotContainsString('cleanupStalePidFilesForPids', $method);

        $transactionEnd = \strpos(
            $source,
            'public function findRunningInstanceNameByPort',
            (int)$end,
        );
        self::assertIsInt($transactionEnd);
        $transaction = \substr($source, $start, $transactionEnd - $start);
        self::assertStringNotContainsString('Processer::removePidFile(', $transaction);
        self::assertStringNotContainsString(
            'Processer::cleanupStalePidFilesForPids(',
            $transaction,
        );
        self::assertStringNotContainsString('Processer::kill', $transaction);
        self::assertStringNotContainsString('@\\unlink(', $transaction);
    }

    private function manager(): ServerInstanceManager
    {
        return new class($this->directory) extends ServerInstanceManager {
            public function __construct(private readonly string $directory)
            {
                parent::__construct();
            }

            public function getInstanceDir(): string
            {
                return $this->directory;
            }
        };
    }

    /**
     * @return array{0:resource,1:array{0:resource,1:resource,2:resource}}
     */
    private function holdLockInChildProcess(string $path): array
    {
        $parent = \dirname($path);
        if (!\is_dir($parent)) {
            self::assertTrue(@\mkdir($parent, 0700, true));
        }
        $script = <<<'PHP'
$path = (string)($argv[1] ?? '');
$handle = @fopen($path, 'c+');
if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
    exit(2);
}
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
fread(STDIN, 1);
flock($handle, LOCK_UN);
fclose($handle);
PHP;
        $pipes = [];
        $process = \proc_open(
            [PHP_BINARY, '-r', $script, $path],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        self::assertIsResource($process);
        self::assertCount(3, $pipes);
        \stream_set_timeout($pipes[1], 2);
        self::assertSame("READY\n", \fgets($pipes[1]));

        return [$process, $pipes];
    }

    private function newInstanceName(string $prefix): string
    {
        $name = $prefix . '-' . \bin2hex(\random_bytes(6));
        $this->instanceNames[] = $name;
        return $name;
    }

    /** @return array<string,mixed> */
    private function stoppedEndpoint(string $name, int $epoch, int $updatedAt): array
    {
        return [
            'schema_version' => 2,
            'name' => $name,
            'instance_name' => $name,
            'pid' => 0,
            'master_pid' => 0,
            'master_enabled' => false,
            'count' => 0,
            'master_epoch' => $epoch,
            'started_timestamp' => $updatedAt - 1,
            'startup_phase' => 'stopped',
            'lifecycle_state' => 'stopped',
            'updated_at' => $updatedAt,
        ];
    }

    /** @param array<string,mixed> $endpoint */
    private function writeEndpoint(string $name, array $endpoint): void
    {
        $path = $this->directory . $name . '.json';
        self::assertNotFalse(\file_put_contents(
            $path,
            \json_encode($endpoint, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        ));
        @\chmod($path, 0600);
        \clearstatcache(true, $path);
    }
}
