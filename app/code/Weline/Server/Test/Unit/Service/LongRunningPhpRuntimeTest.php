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

    /**
     * Tracing JIT is ON by default (user directive 2026-10-29).
     *
     * Measured 2026-10-29 4-way matrix (fresh cache dir per run):
     *   jit=on  + file_cache → cached=320 .bin=0     (does NOT write)
     *   jit=off + file_cache → cached=320 .bin=321   (writes)
     * JIT suppresses writes but still READS a cache filled by another process:
     * pre-warmed dir + fresh jit=on process → 0.18-0.20s vs 0.38-0.42s without,
     * with hits=339 misses=0 and jit.on=1.
     */
    public function testStartupCliArgumentsEnableTracingJitByDefault(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $args = LongRunningPhpRuntime::startupCliArguments();
            self::assertContains('opcache.jit=0', $args);
            self::assertContains('opcache.jit_buffer_size=0', $args);

            return;
        }

        if (!\extension_loaded('Zend OPcache') && !\function_exists('opcache_get_status')) {
            self::markTestSkipped('Zend OPcache is not loaded');
        }

        $args = LongRunningPhpRuntime::startupCliArguments();
        self::assertContains('opcache.enable_cli=1', $args);
        self::assertSame('tracing', $this->argumentValue($args, 'opcache.jit'));
        self::assertSame('64M', $this->argumentValue($args, 'opcache.jit_buffer_size'));
        self::assertNotContains('opcache.jit=off', $args);
    }

    /**
     * A usable disk cache must stay on the argv alongside JIT: JIT only blocks
     * writes, not reads, so a pre-warmed cache still accelerates cold start.
     */
    public function testStartupCliArgumentsKeepDiskCacheAlongsideJit(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Unix-only argv path');
        }

        if (!\extension_loaded('Zend OPcache') && !\function_exists('opcache_get_status')) {
            self::markTestSkipped('Zend OPcache is not loaded');
        }

        $args = LongRunningPhpRuntime::startupCliArguments();
        $fileCache = $this->argumentValue($args, 'opcache.file_cache');

        if ($fileCache === null || $fileCache === '') {
            self::markTestSkipped('No writable file cache directory available');
        }

        self::assertSame('0', $this->argumentValue($args, 'opcache.file_cache_only'));
        self::assertSame('tracing', $this->argumentValue($args, 'opcache.jit'));
    }

    /**
     * CLI OPcache is per-process; measured warm footprint is 30.2KB/PHP script
     * and 45.8KB/compiled template, so the legacy 88MB segment only holds ≈3427
     * scripts against a ≥13852-script working set. 192MB is the floor that keeps
     * the application classes resident without exhausting a 9-worker fleet.
     */
    public function testStartupCliArgumentsSizeOpcacheForMeasuredWorkingSet(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('CLI OPcache sizing only applies to the non-Windows argv path');
        }

        if (!\extension_loaded('Zend OPcache') && !\function_exists('opcache_get_status')) {
            self::markTestSkipped('Zend OPcache is not loaded');
        }

        $args = LongRunningPhpRuntime::startupCliArguments();
        $memoryArg = $this->argumentValue($args, 'opcache.memory_consumption');

        self::assertNotNull($memoryArg, 'opcache.memory_consumption must be pinned on the worker argv');
        self::assertNotSame('88', $memoryArg, 'the 1533-script sizing assumption is obsolete');

        $configuredMb = (int)$memoryArg;
        self::assertGreaterThanOrEqual(192, $configuredMb, 'segment must hold the measured warm working set');
        self::assertLessThanOrEqual(256, $configuredMb, 'per-process segment must stay bounded across the worker fleet');
    }

    /**
     * The disk bytecode cache is shared across process generations, unlike the
     * per-process CLI segment, so a recycled Worker must be handed a usable path.
     */
    public function testStartupCliArgumentsShareDiskBytecodeCache(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('CLI OPcache sizing only applies to the non-Windows argv path');
        }

        if (!\extension_loaded('Zend OPcache') && !\function_exists('opcache_get_status')) {
            self::markTestSkipped('Zend OPcache is not loaded');
        }

        $args = LongRunningPhpRuntime::startupCliArguments();
        $fileCacheArg = $this->argumentValue($args, 'opcache.file_cache');

        self::assertNotNull($fileCacheArg, 'a shared file_cache path must be passed to workers');
        self::assertDirectoryExists($fileCacheArg);
        self::assertDirectoryIsWritable($fileCacheArg);
        self::assertSame('0', $this->argumentValue($args, 'opcache.file_cache_only'), 'memory remains the primary tier');
    }

    /**
     * @param list<string> $args
     */
    private function argumentValue(array $args, string $name): ?string
    {
        foreach ($args as $value) {
            if (!\is_string($value) || !\str_starts_with($value, $name . '=')) {
                continue;
            }

            return \substr($value, \strlen($name) + 1);
        }

        return null;
    }
}
