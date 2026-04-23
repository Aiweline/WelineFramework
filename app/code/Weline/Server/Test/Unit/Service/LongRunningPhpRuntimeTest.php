<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\LongRunningPhpRuntime;

final class LongRunningPhpRuntimeTest extends TestCase
{
    public function testApplyDisablesExecutionLimitAndEnablesAbortProtection(): void
    {
        $runtime = new class extends LongRunningPhpRuntime {
            public array $iniValues = [];
            public array $timeLimits = [];
            public array $abortFlags = [];
            public int $consoleEncodingInitCount = 0;

            protected function initConsoleEncoding(): void
            {
                $this->consoleEncodingInitCount++;
            }

            protected function setIniValue(string $key, string $value): void
            {
                $this->iniValues[$key] = $value;
            }

            protected function setTimeLimit(int $seconds): void
            {
                $this->timeLimits[] = $seconds;
            }

            protected function setIgnoreUserAbort(bool $enabled): void
            {
                $this->abortFlags[] = $enabled;
            }
        };

        $runtime->apply();

        self::assertSame(1, $runtime->consoleEncodingInitCount);
        self::assertSame(['max_execution_time' => '0'], $runtime->iniValues);
        self::assertSame([0], $runtime->timeLimits);
        self::assertSame([true], $runtime->abortFlags);
    }
}
