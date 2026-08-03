<?php

declare(strict_types=1);

namespace Weline\Cron\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Cron\Helper\Process;

final class ProcessManagedChildReapTest extends TestCase
{
    public function testExitedDirectChildIsReapedWithoutPsInspection(): void
    {
        if (PHP_OS_FAMILY === 'Windows'
            || !\function_exists('pcntl_fork')
            || !\function_exists('pcntl_waitpid')
            || !\defined('WNOHANG')
        ) {
            self::markTestSkipped('POSIX pcntl is required.');
        }

        $pid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            exit(0);
        }

        $reaped = false;
        try {
            $deadline = \microtime(true) + 2.0;
            do {
                $reaped = Process::reapManagedCronChildIfExited(
                    $pid,
                    'unit_managed_child_reap',
                    \str_repeat('a', 32),
                    '1785734110.471747',
                );
                if (!$reaped) {
                    \usleep(10_000);
                }
            } while (!$reaped && \microtime(true) < $deadline);

            self::assertTrue($reaped, 'The dispatcher must reap its exited child within the bound.');
            self::assertFalse(Process::reapManagedCronChildIfExited(
                $pid,
                'unit_managed_child_reap',
                \str_repeat('a', 32),
                '1785734110.471747',
            ));
        } finally {
            if (!$reaped) {
                $status = 0;
                @\pcntl_waitpid($pid, $status);
            }
        }
    }

    public function testInvalidGenerationCannotReapAnyPid(): void
    {
        self::assertFalse(Process::reapManagedCronChildIfExited(
            (int)(\getmypid() ?: 0),
            'unit_managed_child_reap',
            'invalid',
            'invalid',
        ));
    }
}
