<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\MasterLeaseManager;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\ServerInstanceManager;

final class ServerInstanceManagerMasterLeaseOverlayTest extends TestCase
{
    private ?ServerInstanceManager $manager = null;
    private string $instanceName = '';
    private string $instanceFile = '';
    private string $leaseFile = '';
    private string $processIdentity = '';
    /** @var resource|null */
    private $childProcess = null;

    protected function tearDown(): void
    {
        if (\is_resource($this->childProcess)) {
            @\proc_terminate($this->childProcess);
            @\proc_close($this->childProcess);
        }
        if ($this->processIdentity !== '') {
            Processer::removePidFile($this->processIdentity);
        }
        if ($this->instanceFile !== '' && \is_file($this->instanceFile)) {
            @\unlink($this->instanceFile);
        }
        if ($this->leaseFile !== '' && \is_file($this->leaseFile)) {
            @\unlink($this->leaseFile);
        }
        if ($this->leaseFile !== '') {
            @\rmdir(\dirname($this->leaseFile));
        }
    }

    public function testLiveMasterLeaseOverlaysOuterNamespacePidForNormalInstanceLookup(): void
    {
        if (!\function_exists('cli_set_process_title')) {
            self::markTestSkipped('cli_set_process_title is required for managed Master identity evidence.');
        }

        $this->manager = new ServerInstanceManager();
        $this->instanceName = 'ut-master-overlay-' . \bin2hex(\random_bytes(4));
        $this->instanceFile = $this->manager->getInstanceFile($this->instanceName);
        $this->leaseFile = MasterLeaseManager::pathForInstance($this->instanceName);

        $masterName = MasterProcess::getMasterProcessName($this->instanceName);
        $masterTitle = MasterProcess::getMasterProcessCliTitle($this->instanceName);
        $this->processIdentity = '--name=' . $masterName;
        $childCode = <<<'PHP'
$title = (string) ($argv[1] ?? '');
cli_set_process_title($title);
while (true) {
    usleep(100000);
}
PHP;
        $pipes = [];
        $this->childProcess = \proc_open(
            [PHP_BINARY, '-r', $childCode, $masterTitle],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        self::assertIsResource($this->childProcess);
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
        }

        $status = \proc_get_status($this->childProcess);
        $masterPid = (int)($status['pid'] ?? 0);
        self::assertGreaterThan(0, $masterPid);
        $deadline = \microtime(true) + 2.0;
        do {
            $liveIdentity = Processer::getProcessCommandLine($masterPid, true);
            if ($liveIdentity === $masterTitle) {
                break;
            }
            \usleep(10_000);
        } while (\microtime(true) < $deadline);
        self::assertSame($masterTitle, $liveIdentity);

        Processer::setPid($this->processIdentity, $masterPid);
        $epoch = 7;
        $this->writeEndpoint(987654321, $epoch - 1);
        (new MasterLeaseManager())->writeRunning(
            $this->instanceName,
            $masterPid,
            24680,
            $epoch,
            \bin2hex(\random_bytes(32)),
        );

        $info = $this->manager->getInstanceInfoWithIpcTimeout(
            $this->instanceName,
            false,
            0.0,
        );

        self::assertNotNull($info);
        self::assertSame($masterPid, $info->masterPid);
        self::assertTrue($info->isMasterRunning());

        $durable = $this->manager->getRawInstanceData($this->instanceName);
        self::assertSame(
            987654321,
            (int)($durable['master_pid'] ?? 0),
            'The live overlay must remain read-only and must not rewrite the durable endpoint.',
        );
    }

    public function testFreshNewerLeaseVetoesDestructiveCleanupWhenPidIsInAnotherNamespace(): void
    {
        $this->manager = new ServerInstanceManager();
        $this->instanceName = 'ut-master-foreign-namespace-' . \bin2hex(\random_bytes(4));
        $this->instanceFile = $this->manager->getInstanceFile($this->instanceName);
        $this->leaseFile = MasterLeaseManager::pathForInstance($this->instanceName);
        $oldNamespacePid = 987654321;
        $this->writeEndpoint($oldNamespacePid, 6);
        (new MasterLeaseManager())->writeRunning(
            $this->instanceName,
            987654322,
            24681,
            7,
            \bin2hex(\random_bytes(32)),
        );

        self::assertTrue(
            $this->manager->hasSupersedingLiveMasterGeneration(
                $this->instanceName,
                $oldNamespacePid,
            ),
        );
        self::assertFileExists($this->instanceFile);
    }

    private function writeEndpoint(int $outerPid, int $epoch): void
    {
        $runtimeSelection = RuntimeSelection::fromArray([
            'requested_topology' => 'direct',
            'effective_topology' => 'direct',
            'topology_source' => 'unit-test',
            'os_family' => PHP_OS_FAMILY,
            'event_loop_driver' => 'select',
            'ssl_engine' => 'stream',
            'listener_mode' => 'shared_fd',
            'policy_compatible' => true,
            'reason_codes' => ['unit_test'],
            'reason' => 'unit test runtime selection',
        ]);
        ServerInstanceManager::atomicWriteJsonStatic($this->instanceFile, [
            'schema_version' => RuntimeSelection::ENDPOINT_SCHEMA_VERSION,
            'runtime_selection' => $runtimeSelection->toArray(),
            'name' => $this->instanceName,
            'instance_name' => $this->instanceName,
            'pid' => $outerPid,
            'master_pid' => $outerPid,
            'master_epoch' => $epoch,
            'epoch' => $epoch,
            'master_enabled' => true,
            'control_port' => 24680,
            'host' => '127.0.0.1',
            'port' => 19999,
            'ssl_enabled' => true,
            'count' => 1,
            'worker_port' => 19999,
            'started_at' => '2026-07-31 00:00:00',
            'started_timestamp' => 1785456000,
            'startup_phase' => 'running',
            'lifecycle_state' => 'running',
        ], 5);
    }
}
