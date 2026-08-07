<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Worker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerMonotonicDeadlineDomainTest extends TestCase
{
    public function testSharedWorkerClockIsTheRealHrtimeDomainRatherThanWallEpoch(): void
    {
        require_once \dirname(__DIR__, 3) . '/bin/worker_runtime_common.php';

        $before = (float)\hrtime(true) / 1_000_000_000;
        $clockFunction = 'wlsWorker' . 'MonotonicNow';
        self::assertTrue(\function_exists($clockFunction));
        $observed = (float)$clockFunction();
        $after = (float)\hrtime(true) / 1_000_000_000;

        self::assertGreaterThanOrEqual($before, $observed);
        self::assertLessThanOrEqual($after, $observed);
        self::assertGreaterThan(1_000_000.0, \abs(\microtime(true) - $observed));
    }

    #[DataProvider('productionWorkers')]
    public function testProcessLocalDurationsNeverUseWallMicrotime(string $script): void
    {
        $source = $this->source($script);

        // Runtime snapshots intentionally expose a wall-clock observation for
        // correlation with host logs. It is not consumed as a duration or a
        // deadline, so exclude only that exact telemetry field.
        $sourceWithoutWallTelemetry = \preg_replace(
            "/'ts'\s*=>\s*\\\\microtime\(true\)\s*,/",
            "'ts' => 0.0,",
            $source,
        );
        self::assertIsString($sourceWithoutWallTelemetry);

        $wallMicrotimeOffset = \strpos($sourceWithoutWallTelemetry, '\\microtime(true)');
        self::assertFalse(
            $wallMicrotimeOffset,
            $script . ' still drives a process-local duration/deadline from wall microtime at line '
                . ($wallMicrotimeOffset === false
                    ? 0
                    : \substr_count(\substr($sourceWithoutWallTelemetry, 0, $wallMicrotimeOffset), "\n") + 1),
        );
    }

    #[DataProvider('productionWorkers')]
    public function testHousekeepingAndUptimeStateNeverStartFromWallTime(string $script): void
    {
        $source = $this->source($script);

        foreach ([
            'startTime',
            'lastTimeoutCheck',
            'lastMemoryCheck',
            'workerLoopHeartbeatNow',
            'eventLoopLastMetricsLogAt',
            'lastGcTime',
            'lastMasterPidHardCheck',
            'lastLongLivedSaturationReport',
            'queuedWriteNow',
            'postHandshakeReadNow',
            'ipcReconnectDueTime',
            'readySentTime',
            'waitStartedAt',
            'waitDeadline',
            'tlsSessionDrainDeadline',
            'zeroNow',
            'policyStartedAt',
            'handleStartTime',
            'staticFileStart',
            'nowSat',
            'now',
        ] as $variable) {
            foreach ($this->assignmentExpressions($source, $variable) as $expression) {
                self::assertDoesNotMatchRegularExpression(
                    '/\\\\(?:time|microtime)\s*\(/',
                    $expression,
                    $script . ' assigns $' . $variable . ' from a wall clock: ' . $expression,
                );
            }
        }

        $mainLoopNow = $this->firstMainLoopAssignmentExpression($source, 'now');
        self::assertDoesNotMatchRegularExpression(
            '/\\\\(?:time|microtime)\s*\(/',
            $mainLoopNow,
            $script . ' main-loop housekeeping clock is wall-clock based',
        );
        self::assertMatchesRegularExpression(
            '/(?:hrtime|workerLoopHeartbeatNow)/',
            $mainLoopNow,
            $script . ' main-loop housekeeping clock is not proven monotonic',
        );
    }

    #[DataProvider('productionWorkers')]
    public function testEveryConnectionActivityTimestampUsesTheMonotonicDomain(string $script): void
    {
        $source = $this->source($script);
        $assignments = [];
        \preg_match_all(
            '/\$connectionLastActivity\s*\[[^\]]+\]\s*=\s*([^;]+);/',
            $source,
            $assignments,
        );

        self::assertNotEmpty($assignments[1], $script . ' has no connection-activity writes to validate');
        foreach ($assignments[1] as $expression) {
            self::assertDoesNotMatchRegularExpression(
                '/\\\\(?:time|microtime)\s*\(/',
                $expression,
                $script . ' writes connection activity from a wall clock: ' . $expression,
            );
            self::assertStringContainsString(
                'wlsWorkerMonotonicNow',
                $expression,
                $script . ' connection activity is not sourced from the worker monotonic clock: ' . $expression,
            );
        }
    }

    #[DataProvider('productionWorkers')]
    public function testStaticResponseL1PublishNeverReceivesAWallTimestamp(string $script): void
    {
        $source = $this->source($script);
        $matches = [];
        \preg_match_all(
            '/WorkerStaticResponseL1::publish\((.*?)\n\s*\);/s',
            $source,
            $matches,
        );

        self::assertCount(1, $matches[1], $script . ' must publish Static Response L1 exactly once');
        self::assertDoesNotMatchRegularExpression(
            '/\\\\?(?:time|microtime)\s*\(/',
            (string)$matches[1][0],
            $script . ' passes a wall timestamp into process-local Static Response L1',
        );
    }

    public function testTlsTerminatedFiberUsesOneFinishedObservationForMissingStart(): void
    {
        $source = $this->source('worker_ssl.php');
        $blockStart = \strpos($source, 'if ($af->isTerminated()) {');
        self::assertNotFalse($blockStart);
        $blockEnd = \strpos($source, '$afResponse = injectWlsProcessTimeHeader', (int)$blockStart);
        self::assertNotFalse($blockEnd);
        $block = \substr($source, (int)$blockStart, (int)$blockEnd - (int)$blockStart);

        self::assertSame(
            1,
            \substr_count($block, 'wlsWorkerMonotonicNow()'),
            'TLS terminated-Fiber fallback samples the finish clock more than once',
        );
        self::assertStringContainsString('normalizeMonotonicStartSeconds', $block);
        self::assertStringNotContainsString("handleStartTime'] ?? wlsWorkerMonotonicNow()", $block);
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function productionWorkers(): iterable
    {
        yield 'plain HTTP worker' => ['worker.php'];
        yield 'TLS worker' => ['worker_ssl.php'];
    }

    /**
     * @return list<string>
     */
    private function assignmentExpressions(string $source, string $variable): array
    {
        $matches = [];
        \preg_match_all(
            '/\$' . \preg_quote($variable, '/') . '\s*=\s*([^;]+);/',
            $source,
            $matches,
        );

        return \array_map('trim', $matches[1]);
    }

    private function firstMainLoopAssignmentExpression(string $source, string $variable): string
    {
        $loopOffset = \strpos($source, 'while (true) {');
        self::assertNotFalse($loopOffset, 'Production worker has no main loop');

        $mainLoop = \substr($source, (int)$loopOffset);
        $matches = [];
        self::assertSame(
            1,
            \preg_match(
                '/\$' . \preg_quote($variable, '/') . '\s*=\s*([^;]+);/',
                $mainLoop,
                $matches,
            ),
            'Production worker main loop has no $' . $variable . ' assignment',
        );

        return \trim((string)($matches[1] ?? ''));
    }

    private function source(string $script): string
    {
        $path = \dirname(__DIR__, 3) . '/bin/' . $script;
        $source = \file_get_contents($path);
        self::assertIsString($source, 'Unable to read production worker: ' . $path);

        return $source;
    }
}
