<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;

final class ProcesserMonotonicClockContractTest extends TestCase
{
    public function testProcessControlNeverUsesTheWallClock(): void
    {
        $reflection = new \ReflectionClass(Processer::class);
        $file = $reflection->getFileName();
        self::assertIsString($file);
        $source = \file_get_contents($file);
        self::assertIsString($source);
        \preg_match_all('/^.*microtime\s*\(.*$/m', $source, $matches);

        self::assertSame(
            [],
            $matches[0] ?? [],
            'Processer deadlines, waits, retry windows, reaping, throttling, TTLs, and durations must use monotonic time.',
        );
    }

    public function testMonotonicDeadlineConversionIsBoundedAndExact(): void
    {
        $method = $this->privateMethod('monotonicDeadlineFrom');

        self::assertSame(1_350_000_000, $method->invoke(null, 1_000_000_000, 0.35));
        self::assertSame(1_000_000_000, $method->invoke(null, 1_000_000_000, -1.0));
        self::assertSame(1_000_000_000, $method->invoke(null, 1_000_000_000, INF));
    }

    public function testMonotonicDurationClampsBackwardSamples(): void
    {
        $method = $this->privateMethod('monotonicElapsedSeconds');

        self::assertSame(0.25, $method->invoke(null, 2_000_000_000, 2_250_000_000));
        self::assertSame(0.0, $method->invoke(null, 2_000_000_000, 1_999_999_999));
    }

    public function testMonotonicPrimitivesPreserveFloatNanosecondSamples(): void
    {
        $deadline = $this->privateMethod('monotonicDeadlineFrom')->invoke(
            null,
            5_000_000_000.5,
            0.25,
        );
        self::assertIsFloat($deadline);
        self::assertSame(5_250_000_000.5, $deadline);

        $elapsed = $this->privateMethod('monotonicElapsedSeconds')->invoke(
            null,
            5_000_000_000.75,
            5_250_000_000.25,
        );
        self::assertEqualsWithDelta(0.2499999995, $elapsed, 0.000000000001);
    }

    public function testUnixReaperAcceptsFloatMonotonicClockSamples(): void
    {
        $codeMethod = $this->privateMethod('unixBatchLauncherTerminateAndReapCode');
        $code = $codeMethod->invoke(null);
        self::assertIsString($code);
        $terminateAndReap = eval('return ' . $code . ';');
        self::assertInstanceOf(\Closure::class, $terminateAndReap);
        $clock = 6_000_000_000.5;
        $waitCalls = 0;

        try {
            $reaped = $terminateAndReap(
                4545,
                false,
                6_003_000_000.5,
                static fn (int $pid, int $signal): bool => $pid === 4545 && $signal > 0,
                static function (int $pid, int &$status, int $options) use (&$waitCalls): int {
                    unset($pid, $options);
                    $waitCalls++;
                    $status = 0;

                    return 0;
                },
                static fn (): int => 0,
                static function () use (&$clock): float {
                    $now = $clock;
                    $clock += 1_000_000.0;

                    return $now;
                },
                static function (int $microseconds): void {
                    unset($microseconds);
                },
            );
        } catch (\TypeError $exception) {
            self::fail('The Unix reaper rejected a valid float hrtime sample: ' . $exception->getMessage());
        }

        self::assertFalse($reaped);
        self::assertSame(4, $waitCalls);
    }

    public function testVariableDurationDeadlinesNeverNarrowNanosecondsToInt(): void
    {
        foreach ([
            'terminateManagedProcessLeases',
            'batchCreateUnix',
            'unixBatchLauncherCode',
        ] as $methodName) {
            $method = new \ReflectionMethod(Processer::class, $methodName);
            $file = $method->getFileName();
            self::assertIsString($file);
            $lines = \file($file);
            self::assertIsArray($lines);
            $source = \implode('', \array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));

            self::assertDoesNotMatchRegularExpression(
                '/\(int\)[^;\r\n]*1_000_000_000/',
                $source,
                $methodName . ' narrows a variable nanosecond duration on 32-bit PHP.',
            );
        }
    }

    public function testFallbackProcessIdentityDoesNotUseWallTime(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'generateProcessName');
        $file = $method->getFileName();
        self::assertIsString($file);
        $lines = \file($file);
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringNotContainsString('time()', $source);
        self::assertStringContainsString('hrtime(true)', $source);
    }

    private function privateMethod(string $name): \ReflectionMethod
    {
        self::assertTrue(
            \method_exists(Processer::class, $name),
            'Processer must provide the monotonic time primitive used by its production paths: ' . $name,
        );
        $method = new \ReflectionMethod(Processer::class, $name);
        $method->setAccessible(true);

        return $method;
    }
}
