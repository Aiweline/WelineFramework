<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;

final class StartCommandDaemonModeTest extends TestCase
{
    public function testFrontendModeDoesNotForceForegroundExecution(): void
    {
        $start = new class extends Start {
            /**
             * @param array<string, mixed> $config
             */
            public function daemonMode(array $config, bool $frontend): bool
            {
                return $this->resolveDaemonMode($config, $frontend);
            }
        };
        $start->__init();

        self::assertTrue($start->daemonMode(['daemon' => true], true));
        self::assertFalse($start->daemonMode(['daemon' => false], true));
    }
}
