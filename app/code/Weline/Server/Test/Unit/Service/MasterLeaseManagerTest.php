<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\MasterLeaseManager;
use Weline\Server\Service\MasterLeaseOwnershipLostException;

final class MasterLeaseManagerTest extends TestCase
{
    public function testFreshRunningLeaseRejectsASecondMasterGenerationButAllowsStaleRecovery(): void
    {
        $instance = 'lease-owner-fence-' . \bin2hex(\random_bytes(6));
        $manager = new MasterLeaseManager();
        $ownerToken = \str_repeat('a', 64);
        $candidateToken = \str_repeat('b', 64);
        $path = $manager->writeRunning($instance, 12341, 19091, 7, $ownerToken);

        try {
            $this->expectException(MasterLeaseOwnershipLostException::class);
            $manager->writeRunning($instance, 12342, 19092, 8, $candidateToken);
        } finally {
            @\unlink($path);
            @\rmdir(\dirname($path));
        }
    }

    public function testStaleForeignRunningLeaseCanBeRecoveredByANewMasterGeneration(): void
    {
        $instance = 'lease-stale-recovery-' . \bin2hex(\random_bytes(6));
        $manager = new MasterLeaseManager();
        $path = $manager->writeRunning($instance, 12351, 19101, 7, \str_repeat('c', 64));

        try {
            $lease = $manager->readProtected($path);
            self::assertIsArray($lease);
            $lease['updated_at'] = \microtime(true) - MasterLeaseManager::HEARTBEAT_STALE_SEC - 1.0;
            $writeLease = new \ReflectionMethod(MasterLeaseManager::class, 'writeLease');
            $writeLease->invoke($manager, $path, $lease);

            $manager->writeRunning($instance, 12352, 19102, 8, \str_repeat('d', 64));
            $claimed = $manager->readProtected($path);

            self::assertIsArray($claimed);
            self::assertSame(12352, $claimed['master_pid'] ?? null);
            self::assertSame(8, $claimed['master_epoch'] ?? null);
        } finally {
            @\unlink($path);
            @\rmdir(\dirname($path));
        }
    }

    public function testAtomicWriteCleansOnlyStaleOwnedTemporaryFiles(): void
    {
        $directory = \sys_get_temp_dir()
            . \DIRECTORY_SEPARATOR
            . 'weline-master-lease-'
            . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($directory, 0700));

        $path = $directory . \DIRECTORY_SEPARATOR . 'master_lease.json';
        $stale = $path . '.1234.deadbeef.tmp';
        $fresh = $path . '.5678.cafebabe.tmp';
        $unrelated = $path . '.not-owned.tmp';
        \file_put_contents($stale, '');
        \file_put_contents($fresh, '');
        \file_put_contents($unrelated, '');
        \touch($stale, \time() - 60);

        try {
            $method = new \ReflectionMethod(MasterLeaseManager::class, 'writeLease');
            $method->invoke(new MasterLeaseManager(), $path, [
                'instance' => 'lease-test',
                'master_pid' => 1234,
                'state' => MasterLeaseManager::STATE_RUNNING,
            ]);

            self::assertFileDoesNotExist($stale);
            self::assertFileExists($fresh);
            self::assertFileExists($unrelated);
            self::assertSame(
                'lease-test',
                \json_decode((string)\file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['instance'],
            );
        } finally {
            foreach (\glob($directory . \DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @\unlink($file);
            }
            @\rmdir($directory);
        }
    }
}
