<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\File\Data\File;
use Weline\Framework\System\File\Scan;
use Weline\Framework\System\Process\Processer;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllSystemContractsPass().
 */
final class SystemContractsTest extends TestCase
{
    public function testAllSystemContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ScanDirTreeIsolation::testSequentialTopLevelScansDoNotMixOrigins' => function () {
            $this->assert_ScanDirTreeIsolation_testSequentialTopLevelScansDoNotMixOrigins();
        },
            'ScanDirTreeIsolation::testMidWalkExceptionDoesNotPoisonNextTopLevelScan' => function () {
            $this->assert_ScanDirTreeIsolation_testMidWalkExceptionDoesNotPoisonNextTopLevelScan();
        },
            'ScanDirTreeIsolation::testScanDirTreeUsesPerWalkContextNotInstanceKeepLevel' => function () {
            $this->assert_ScanDirTreeIsolation_testScanDirTreeUsesPerWalkContextNotInstanceKeepLevel();
        },
            'ProcesserMonotonicClock::testProcessControlNeverUsesTheWallClock' => function () {
            $this->assert_ProcesserMonotonicClock_testProcessControlNeverUsesTheWallClock();
        },
            'ProcesserMonotonicClock::testMonotonicDeadlineConversionIsBoundedAndExact' => function () {
            $this->assert_ProcesserMonotonicClock_testMonotonicDeadlineConversionIsBoundedAndExact();
        },
            'ProcesserMonotonicClock::testMonotonicDurationClampsBackwardSamples' => function () {
            $this->assert_ProcesserMonotonicClock_testMonotonicDurationClampsBackwardSamples();
        },
            'ProcesserMonotonicClock::testMonotonicPrimitivesPreserveFloatNanosecondSamples' => function () {
            $this->assert_ProcesserMonotonicClock_testMonotonicPrimitivesPreserveFloatNanosecondSamples();
        },
            'ProcesserMonotonicClock::testUnixReaperAcceptsFloatMonotonicClockSamples' => function () {
            $this->assert_ProcesserMonotonicClock_testUnixReaperAcceptsFloatMonotonicClockSamples();
        },
            'ProcesserMonotonicClock::testVariableDurationDeadlinesNeverNarrowNanosecondsToInt' => function () {
            $this->assert_ProcesserMonotonicClock_testVariableDurationDeadlinesNeverNarrowNanosecondsToInt();
        },
            'ProcesserMonotonicClock::testFallbackProcessIdentityDoesNotUseWallTime' => function () {
            $this->assert_ProcesserMonotonicClock_testFallbackProcessIdentityDoesNotUseWallTime();
        },
        ];
    }

private function assert_ScanDirTreeIsolation_testSequentialTopLevelScansDoNotMixOrigins(): void
{
    $workspace = sys_get_temp_dir() . '/weline-scan-iso-' . bin2hex(random_bytes(6));
    $a = $workspace . '/theme-a';
    $b = $workspace . '/theme-b';
    self::assertTrue(mkdir($a . '/assets', 0775, true));
    self::assertTrue(mkdir($b . '/assets', 0775, true));
    self::assertNotFalse(file_put_contents($a . '/assets/a.css', '.a{}'));
    self::assertNotFalse(file_put_contents($b . '/assets/b.css', '.b{}'));
    try {
        $scan = new Scan();
        $first = $scan->scanDirTree($a);
        $second = $scan->scanDirTree($b);
        $firstOrigins = $this->origins_ScanDirTreeIsolation($first);
        $secondOrigins = $this->origins_ScanDirTreeIsolation($second);
        self::assertNotEmpty($firstOrigins);
        self::assertNotEmpty($secondOrigins);
        foreach ($firstOrigins as $origin) {
            self::assertStringContainsString('/theme-a/', str_replace('\\', '/', $origin));
            self::assertStringNotContainsString('/theme-b/', str_replace('\\', '/', $origin));
        }
        foreach ($secondOrigins as $origin) {
            self::assertStringContainsString('/theme-b/', str_replace('\\', '/', $origin));
            self::assertStringNotContainsString('/theme-a/', str_replace('\\', '/', $origin));
        }
    } finally {
        $this->removeTree_ScanDirTreeIsolation($workspace);
    }
}

private function assert_ScanDirTreeIsolation_testMidWalkExceptionDoesNotPoisonNextTopLevelScan(): void
{
    $workspace = sys_get_temp_dir() . '/weline-scan-boom-' . bin2hex(random_bytes(6));
    $boom = $workspace . '/boom-theme';
    $ok = $workspace . '/ok-theme';
    self::assertTrue(mkdir($boom . '/nested', 0775, true));
    self::assertTrue(mkdir($ok . '/assets', 0775, true));
    self::assertNotFalse(file_put_contents($boom . '/nested/x.css', '.x{}'));
    self::assertNotFalse(file_put_contents($ok . '/assets/ok.css', '.ok{}'));
    $scan = new class extends Scan
    {
        public bool $throwOnNested = true;
        protected function scanDirTreeInto(string $dirPath, int $level, int $walkDepth, array &$dirs, array &$visited): void
        {
            if ($this->throwOnNested && $walkDepth > 1) {
                throw new \RuntimeException('mid-walk failure');
            }
            parent::scanDirTreeInto($dirPath, $level, $walkDepth, $dirs, $visited);
        }
    };
    try {
        try {
            $scan->scanDirTree($boom);
            self::fail('expected mid-walk exception');
        } catch (\RuntimeException $exception) {
            self::assertSame('mid-walk failure', $exception->getMessage());
        }
        $scan->throwOnNested = false;
        $origins = $this->origins_ScanDirTreeIsolation($scan->scanDirTree($ok));
        self::assertNotEmpty($origins);
        foreach ($origins as $origin) {
            self::assertStringContainsString('/ok-theme/', str_replace('\\', '/', $origin));
            self::assertStringNotContainsString('/boom-theme/', str_replace('\\', '/', $origin));
        }
    } finally {
        $this->removeTree_ScanDirTreeIsolation($workspace);
    }
}

private function assert_ScanDirTreeIsolation_testScanDirTreeUsesPerWalkContextNotInstanceKeepLevel(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/System/File/Scan.php');
    self::assertStringContainsString('function scanDirTreeInto(', $src);
    self::assertStringContainsString('$dirs = [];', $src);
    self::assertStringContainsString('$visited = [];', $src);
    self::assertStringNotContainsString('private int $keepLevel', $src);
    self::assertStringNotContainsString('private array $visitedRealPaths', $src);
    self::assertStringNotContainsString('$this->keepLevel', $src);
    self::assertStringNotContainsString('$this->visitedRealPaths', $src);
    // Tree results must not write into the scanDir() instance buffer.
    self::assertDoesNotMatchRegularExpression('/scanDirTreeInto[\s\S]*?\$this->dirs\[/', $src);
}

/**
 * @param array<string, list<File>> $tree
 * @return list<string>
 */
private function origins_ScanDirTreeIsolation(array $tree): array
{
    $out = [];
    foreach ($tree as $group) {
        foreach ($group as $file) {
            if ($file instanceof File) {
                $out[] = $file->getOrigin();
            }
        }
    }
    return $out;
}

private function removeTree_ScanDirTreeIsolation(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

private function assert_ProcesserMonotonicClock_testProcessControlNeverUsesTheWallClock(): void
{
    $reflection = new \ReflectionClass(Processer::class);
    $file = $reflection->getFileName();
    self::assertIsString($file);
    $source = \file_get_contents($file);
    self::assertIsString($source);
    \preg_match_all('/^.*microtime\s*\(.*$/m', $source, $matches);
    self::assertSame([], $matches[0] ?? [], 'Processer deadlines, waits, retry windows, reaping, throttling, TTLs, and durations must use monotonic time.');
}

private function assert_ProcesserMonotonicClock_testMonotonicDeadlineConversionIsBoundedAndExact(): void
{
    $method = $this->privateMethod_ProcesserMonotonicClock('monotonicDeadlineFrom');
    self::assertSame(1350000000, $method->invoke(null, 1000000000, 0.35));
    self::assertSame(1000000000, $method->invoke(null, 1000000000, -1.0));
    self::assertSame(1000000000, $method->invoke(null, 1000000000, INF));
}

private function assert_ProcesserMonotonicClock_testMonotonicDurationClampsBackwardSamples(): void
{
    $method = $this->privateMethod_ProcesserMonotonicClock('monotonicElapsedSeconds');
    self::assertSame(0.25, $method->invoke(null, 2000000000, 2250000000));
    self::assertSame(0.0, $method->invoke(null, 2000000000, 1999999999));
}

private function assert_ProcesserMonotonicClock_testMonotonicPrimitivesPreserveFloatNanosecondSamples(): void
{
    $deadline = $this->privateMethod_ProcesserMonotonicClock('monotonicDeadlineFrom')->invoke(null, 5000000000.5, 0.25);
    self::assertIsFloat($deadline);
    self::assertSame(5250000000.5, $deadline);
    $elapsed = $this->privateMethod_ProcesserMonotonicClock('monotonicElapsedSeconds')->invoke(null, 5000000000.75, 5250000000.25);
    self::assertEqualsWithDelta(0.2499999995, $elapsed, 1.0E-12);
}

private function assert_ProcesserMonotonicClock_testUnixReaperAcceptsFloatMonotonicClockSamples(): void
{
    $codeMethod = $this->privateMethod_ProcesserMonotonicClock('unixBatchLauncherTerminateAndReapCode');
    $code = $codeMethod->invoke(null);
    self::assertIsString($code);
    $terminateAndReap = eval('return ' . $code . ';');
    self::assertInstanceOf(\Closure::class, $terminateAndReap);
    $clock = 6000000000.5;
    $waitCalls = 0;
    try {
        $reaped = $terminateAndReap(4545, false, 6003000000.5, static fn(int $pid, int $signal): bool => $pid === 4545 && $signal > 0, static function (int $pid, int &$status, int $options) use (&$waitCalls): int {
            unset($pid, $options);
            $waitCalls++;
            $status = 0;
            return 0;
        }, static fn(): int => 0, static function () use (&$clock): float {
            $now = $clock;
            $clock += 1000000.0;
            return $now;
        }, static function (int $microseconds): void {
            unset($microseconds);
        });
    } catch (\TypeError $exception) {
        self::fail('The Unix reaper rejected a valid float hrtime sample: ' . $exception->getMessage());
    }
    self::assertFalse($reaped);
    self::assertSame(4, $waitCalls);
}

private function assert_ProcesserMonotonicClock_testVariableDurationDeadlinesNeverNarrowNanosecondsToInt(): void
{
    foreach (['terminateManagedProcessLeases', 'batchCreateUnix', 'unixBatchLauncherCode'] as $methodName) {
        $method = new \ReflectionMethod(Processer::class, $methodName);
        $file = $method->getFileName();
        self::assertIsString($file);
        $lines = \file($file);
        self::assertIsArray($lines);
        $source = \implode('', \array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        self::assertDoesNotMatchRegularExpression('/\(int\)[^;\r\n]*1_000_000_000/', $source, $methodName . ' narrows a variable nanosecond duration on 32-bit PHP.');
    }
}

private function assert_ProcesserMonotonicClock_testFallbackProcessIdentityDoesNotUseWallTime(): void
{
    $method = new \ReflectionMethod(Processer::class, 'generateProcessName');
    $file = $method->getFileName();
    self::assertIsString($file);
    $lines = \file($file);
    self::assertIsArray($lines);
    $source = \implode('', \array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    self::assertStringNotContainsString('time()', $source);
    self::assertStringContainsString('hrtime(true)', $source);
}

private function privateMethod_ProcesserMonotonicClock(string $name): \ReflectionMethod
{
    self::assertTrue(\method_exists(Processer::class, $name), 'Processer must provide the monotonic time primitive used by its production paths: ' . $name);
    $method = new \ReflectionMethod(Processer::class, $name);
    $method->setAccessible(true);
    return $method;
}
}
