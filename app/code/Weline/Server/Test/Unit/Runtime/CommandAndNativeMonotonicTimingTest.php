<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Benchmark;
use Weline\Server\Console\Server\Gateway\Promote;
use Weline\Server\Console\Server\IpcPing;
use Weline\Server\Console\Server\Maintenance;
use Weline\Server\Console\Server\Policy\PolicyCommandAbstract;
use Weline\Server\Console\Server\Reload;
use Weline\Server\Protocol\Http3\NativeTransportCompiler;
use Weline\Server\Protocol\Http3\NativeTransportSelfTest;
use Weline\Server\Service\Benchmark\ServerBenchmarkService;

final class CommandAndNativeMonotonicTimingTest extends TestCase
{
    /**
     * @dataProvider commandAndNativeClassProvider
     */
    public function testCommandWaitsBenchmarksAndNativeDeadlinesUseMonotonicClock(string $class): void
    {
        $source = (string)\file_get_contents((new \ReflectionClass($class))->getFileName());

        self::assertStringNotContainsString('\\microtime(true)', $source, $class);
        self::assertStringContainsString('\\hrtime(true)', $source, $class);
    }

    public function testBenchmarkReportNamesUseWallClockInsteadOfTheDurationClock(): void
    {
        $source = (string)\file_get_contents((new \ReflectionClass(Benchmark::class))->getFileName());

        self::assertStringContainsString('$now ??= self::wallClockSeconds()', $source);
        self::assertStringContainsString("new \\DateTimeImmutable('now')", $source);
    }

    /** @return iterable<string,array{class-string}> */
    public static function commandAndNativeClassProvider(): iterable
    {
        yield 'million-request benchmark command' => [Benchmark::class];
        yield 'bounded benchmark service' => [ServerBenchmarkService::class];
        yield 'legacy gateway promotion maintenance window' => [Promote::class];
        yield 'IPC latency probe' => [IpcPing::class];
        yield 'maintenance command completion waits' => [Maintenance::class];
        yield 'policy commit wait' => [PolicyCommandAbstract::class];
        yield 'reload completion wait' => [Reload::class];
        yield 'native transport publication locks' => [NativeTransportCompiler::class];
        yield 'native HTTP3 runtime self-test deadlines' => [NativeTransportSelfTest::class];
    }
}
