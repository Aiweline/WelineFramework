<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\MasterLeaseManager;

final class MasterLeaseManagerTest extends TestCase
{
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
