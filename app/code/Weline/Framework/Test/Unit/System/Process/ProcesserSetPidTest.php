<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;

final class ProcesserSetPidTest extends TestCase
{
    public function testBuildProcessIdentityRecordUsesProvidedPnameForCurrentProcess(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'buildProcessIdentityRecord');
        $method->setAccessible(true);

        $record = $method->invoke(null, '--name=weline-master-default --launch-id=launch-123 --epoch=7', \getmypid(), 'weline-master-default');

        self::assertSame('weline-master-default', (string) ($record['process_name'] ?? ''));
        self::assertSame('launch-123', (string) ($record['launch_id'] ?? ''));
        self::assertSame(7, (int) ($record['epoch'] ?? 0));
    }

    public function testSetPidWritesPayloadIntoPidFilePath(): void
    {
        $pname = '--name=weline-test-setpid-' . \bin2hex(\random_bytes(4));
        $pid = 654321;
        $pidFile = Processer::getPidFile($pname, $pid);

        try {
            Processer::setPid($pname, $pid);
            $data = Processer::getData($pname);

            self::assertFileExists($pidFile);
            self::assertIsArray($data);
            self::assertSame($pid, (int) ($data['pid'] ?? 0));
            self::assertSame($pname, (string) ($data['pname'] ?? ''));
            self::assertGreaterThan(0, (int) \filesize($pidFile));
        } finally {
            Processer::removePidFile($pname);
        }
    }
}
