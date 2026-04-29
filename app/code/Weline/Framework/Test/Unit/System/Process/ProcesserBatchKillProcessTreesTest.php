<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Driver\ProcessDriverFactory;
use Weline\Framework\System\Process\Driver\ProcessDriverInterface;
use Weline\Framework\System\Process\Processer;

final class ProcesserBatchKillProcessTreesTest extends TestCase
{
    protected function tearDown(): void
    {
        $reflection = new \ReflectionProperty(ProcessDriverFactory::class, 'driver');
        $reflection->setAccessible(true);
        $reflection->setValue(null, null);

        parent::tearDown();
    }

    public function testBuildWindowsBatchTreeKillCommandAggregatesPidList(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'buildWindowsBatchTreeKillCommand');
        $method->setAccessible(true);

        $command = $method->invoke(null, [101, 202, 303]);

        self::assertSame('taskkill /F /T /PID 101 /PID 202 /PID 303 2>NUL', $command);
    }

    public function testBuildPosixKillCommandCanUseNonInteractiveSudo(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'buildPosixKillCommand');
        $method->setAccessible(true);

        self::assertSame(
            'sudo -n kill -9 101 202 2>/dev/null',
            $method->invoke(null, [101, 202], 9, true)
        );
    }

    public function testBatchKillProcessTreesPosixReportsStillRunningPidAsRemaining(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX kill behavior is verified on Linux-like systems.');
        }

        $pid = 987654321;
        $driver = $this->createMock(ProcessDriverInterface::class);
        $driver->method('isRunningByPid')
            ->with($pid)
            ->willReturn(true);

        $this->replaceProcessDriver($driver);

        $method = new \ReflectionMethod(Processer::class, 'batchKillProcessTreesPosix');
        $method->setAccessible(true);

        self::assertSame([
            'killed' => 0,
            'failed' => 1,
            'remaining' => [$pid],
        ], $method->invoke(null, [$pid]));
    }

    public function testPrepareProcessLogFileForWriteRecoversReadOnlyLog(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX file mode behavior is verified on Linux-like systems.');
        }

        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-processer-log-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0777, true));

        $path = $dir . DIRECTORY_SEPARATOR . 'process.log';
        file_put_contents($path, 'old');
        chmod($path, 0444);

        try {
            $method = new \ReflectionMethod(Processer::class, 'prepareProcessLogFileForWrite');
            $method->setAccessible(true);

            self::assertTrue($method->invoke(null, $path));
            self::assertFileExists($path);
            self::assertTrue(is_writable($path));
            self::assertNotFalse(file_put_contents($path, 'new', FILE_APPEND));
        } finally {
            @chmod($path, 0666);
            @unlink($path);
            @rmdir($dir);
        }
    }

    private function replaceProcessDriver(ProcessDriverInterface $driver): void
    {
        $reflection = new \ReflectionProperty(ProcessDriverFactory::class, 'driver');
        $reflection->setAccessible(true);
        $reflection->setValue(null, $driver);
    }

}
