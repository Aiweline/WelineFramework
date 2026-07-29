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
            public int $openFileLimitApplyCount = 0;

            protected function initConsoleEncoding(): void
            {
                $this->consoleEncodingInitCount++;
            }

            protected function raiseOpenFileSoftLimit(): void
            {
                $this->openFileLimitApplyCount++;
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
        self::assertSame(1, $runtime->openFileLimitApplyCount);
        self::assertSame(['max_execution_time' => '0'], $runtime->iniValues);
        self::assertSame([0], $runtime->timeLimits);
        self::assertSame([true], $runtime->abortFlags);
    }

    public function testApplyRaisesPosixOpenFileSoftLimitWithoutChangingHardLimit(): void
    {
        $runtime = new class extends LongRunningPhpRuntime {
            public array $setCalls = [];
            public array $warnings = [];

            protected function initConsoleEncoding(): void
            {
            }

            protected function supportsPosixOpenFileLimits(): bool
            {
                return true;
            }

            protected function readPosixResourceLimits(): array|false
            {
                return [
                    'soft openfiles' => 1024,
                    'hard openfiles' => 524288,
                ];
            }

            protected function setPosixOpenFileLimit(int $softLimit, int $hardLimit): bool
            {
                $this->setCalls[] = [$softLimit, $hardLimit];
                return true;
            }

            protected function reportOpenFileLimitWarning(string $message): void
            {
                $this->warnings[] = $message;
            }
        };

        $runtime->apply();

        self::assertSame([[65536, 524288]], $runtime->setCalls);
        self::assertSame([], $runtime->warnings);
    }

    public function testApplyCapsOpenFileSoftLimitAtHostHardLimitAndReportsConstraint(): void
    {
        $runtime = new class extends LongRunningPhpRuntime {
            public array $setCalls = [];
            public array $warnings = [];

            protected function initConsoleEncoding(): void
            {
            }

            protected function supportsPosixOpenFileLimits(): bool
            {
                return true;
            }

            protected function readPosixResourceLimits(): array|false
            {
                return [
                    'soft openfiles' => '1024',
                    'hard openfiles' => '4096',
                ];
            }

            protected function setPosixOpenFileLimit(int $softLimit, int $hardLimit): bool
            {
                $this->setCalls[] = [$softLimit, $hardLimit];
                return true;
            }

            protected function reportOpenFileLimitWarning(string $message): void
            {
                $this->warnings[] = $message;
            }
        };

        $runtime->apply();

        self::assertSame([[4096, 4096]], $runtime->setCalls);
        self::assertCount(1, $runtime->warnings);
        self::assertStringContainsString('below the recommended 65536', $runtime->warnings[0]);
    }

    public function testApplyLeavesWindowsHandleLimitsUntouched(): void
    {
        $runtime = new class extends LongRunningPhpRuntime {
            public int $readCount = 0;

            protected function initConsoleEncoding(): void
            {
            }

            protected function isWindows(): bool
            {
                return true;
            }

            protected function readPosixResourceLimits(): array|false
            {
                $this->readCount++;
                return [];
            }
        };

        $runtime->apply();

        self::assertSame(0, $runtime->readCount);
    }

    public function testWindowsWlsDaemonSkipsConsoleEncodingInitialization(): void
    {
        $runtime = new class extends LongRunningPhpRuntime {
            public int $consoleEncodingInitCount = 0;

            public function runConsoleEncodingInit(): void
            {
                $this->initConsoleEncoding();
            }

            protected function initializeConsoleEncoding(): void
            {
                $this->consoleEncodingInitCount++;
            }

            protected function isWindows(): bool
            {
                return true;
            }

            protected function isWlsDaemonProcess(): bool
            {
                return true;
            }
        };

        $runtime->runConsoleEncodingInit();

        self::assertSame(0, $runtime->consoleEncodingInitCount);
    }
}
