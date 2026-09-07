<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;

final class StopCommandPidDirectoryOrphanPruneTest extends TestCase
{
    public function testEnsureRecoverablePidDirectoryPrunesWhenOverEntryLimit(): void
    {
        $pruned = 0;
        $stop = new class ($pruned) extends Stop {
            public int $pruned;

            public function __construct(int &$pruned)
            {
                $this->pruned = &$pruned;
            }

            protected function cleanupDeadRecoverablePidJsonOrphans(): int
            {
                $this->pruned++;

                return 3;
            }

            protected function countRecoveryDirectoryEntries(string $directory): int
            {
                unset($directory);

                return 5000;
            }
        };

        $method = new \ReflectionMethod(Stop::class, 'ensureRecoverablePidDirectoryWithinEntryLimit');
        $method->setAccessible(true);
        $method->invoke($stop, '/tmp');

        self::assertSame(1, $pruned);
    }

    public function testEnsureRecoverablePidDirectorySkipsPruneWhenUnderLimit(): void
    {
        $pruned = false;
        $stop = new class ($pruned) extends Stop {
            public bool $pruned;

            public function __construct(bool &$pruned)
            {
                $this->pruned = &$pruned;
            }

            protected function cleanupDeadRecoverablePidJsonOrphans(): int
            {
                $this->pruned = true;

                return 1;
            }

            protected function countRecoveryDirectoryEntries(string $directory): int
            {
                unset($directory);

                return 10;
            }
        };

        $method = new \ReflectionMethod(Stop::class, 'ensureRecoverablePidDirectoryWithinEntryLimit');
        $method->setAccessible(true);
        $method->invoke($stop, '/tmp');

        self::assertFalse($pruned);
    }

    public function testQueryRecoverableManagedPidsInvokesEntryLimitGuard(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Console/Server/Stop.php'
        );
        self::assertStringContainsString(
            'ensureRecoverablePidDirectoryWithinEntryLimit($pidDir)',
            $source
        );
        self::assertStringContainsString(
            'cleanupDeadPidJsonOrphansFast()',
            $source
        );
    }
}
