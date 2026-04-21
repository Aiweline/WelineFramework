<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;

final class ProcesserBatchKillProcessTreesTest extends TestCase
{
    public function testBuildWindowsBatchTreeKillCommandAggregatesPidList(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'buildWindowsBatchTreeKillCommand');
        $method->setAccessible(true);

        $command = $method->invoke(null, [101, 202, 303]);

        self::assertSame('taskkill /F /T /PID 101 /PID 202 /PID 303 2>NUL', $command);
    }

    public function testBuildWindowsAsyncBatchTreeKillCommandDispatchesWithoutWaiting(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'buildWindowsAsyncBatchTreeKillCommand');
        $method->setAccessible(true);

        $command = $method->invoke(null, [101, 202, 303]);

        self::assertSame(
            'cmd /d /c start "" /B cmd /d /c "taskkill /F /T /PID 101 /PID 202 /PID 303 1>NUL 2>NUL"',
            $command
        );
    }
}
