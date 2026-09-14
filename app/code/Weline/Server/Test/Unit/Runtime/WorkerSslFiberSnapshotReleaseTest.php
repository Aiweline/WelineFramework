<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\WlsFiberContext;

final class WorkerSslFiberSnapshotReleaseTest extends TestCase
{
    public function testCompletedRequestReleasesSnapshotAfterTheActualWorkerLoop(): void
    {
        [$activeFibers, $payload] = $this->suspendedRequest(false);
        $result = $this->runWorkerLoop($activeFibers);

        self::assertNull($result['error']);
        self::assertSame([], $activeFibers);
        self::assertSame([], \array_keys($result['locals']), 'Main-loop locals must not retain a completed request snapshot.');
        \gc_collect_cycles();
        self::assertTrue($payload->get() === null, 'The 16 MiB request object must be released after request cleanup and pool removal.');
    }

    public function testSuspendedRequestKeepsItsOwnedSnapshotAndCanResume(): void
    {
        [$activeFibers, $payload] = $this->suspendedRequest(true);
        $first = $this->runWorkerLoop($activeFibers);

        self::assertNull($first['error']);
        self::assertCount(1, $activeFibers);
        self::assertTrue($activeFibers[11]['fiber']->isSuspended());
        self::assertInstanceOf(WlsFiberContext::class, $activeFibers[11]['context']);
        self::assertNotNull($payload->get());
        self::assertSame([], \array_keys($first['locals']));

        $second = $this->runWorkerLoop($activeFibers);
        self::assertNull($second['error']);
        self::assertSame([], $activeFibers);
        self::assertSame([], \array_keys($second['locals']));
        \gc_collect_cycles();
        self::assertTrue($payload->get() === null);
    }

    public function testEmptyAndInvalidFiberContinuePathsReleasePreviousLocals(): void
    {
        $empty = [];
        $result = $this->runWorkerLoop($empty, true);
        self::assertNull($result['error']);
        self::assertSame([], \array_keys($result['locals']));

        $invalid = [11 => ['fiber' => null, 'context' => new \stdClass(), 'conn_id' => 11]];
        $result = $this->runWorkerLoop($invalid);
        self::assertNull($result['error']);
        self::assertSame([], $invalid);
        self::assertSame([], \array_keys($result['locals']));
    }

    public function testSchedulerFailureDropsLocalsWithoutDeletingSuspendedRequest(): void
    {
        [$activeFibers, $payload] = $this->suspendedRequest(false);
        $failure = new \RuntimeException('scheduler fixture failure');
        $result = $this->runWorkerLoop($activeFibers, false, $failure);

        self::assertSame($failure, $result['error']);
        self::assertSame([], \array_keys($result['locals']));
        self::assertCount(1, $activeFibers);
        self::assertNotNull($payload->get());
        self::assertTrue($activeFibers[11]['fiber']->isSuspended());

        $result = $this->runWorkerLoop($activeFibers);
        self::assertNull($result['error']);
        self::assertSame([], $activeFibers);
        self::assertTrue($payload->get() === null);
    }

    /** @return array{array<int, array<string, mixed>>, \WeakReference} */
    private function suspendedRequest(bool $suspendTwice): array
    {
        $weak = null;
        $fiber = new \Fiber(static function () use (&$weak, $suspendTwice): string {
            Context::enter(new Context());
            $payload = new \stdClass();
            $payload->bytes = \str_repeat('x', 16 * 1024 * 1024);
            $weak = \WeakReference::create($payload);
            RequestContext::set('snapshot_release_test', $payload);
            unset($payload);
            \Fiber::suspend();
            if ($suspendTwice) {
                \Fiber::suspend();
            }
            RequestContext::cleanup();
            Context::leave();
            return 'HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK';
        });
        $fiber->start();

        return [[11 => [
            'fiber' => $fiber,
            'context' => WlsFiberContext::captureForFiber($fiber),
            'conn_id' => 11,
        ]], $weak];
    }

    /** Execute the source loop itself; only transport/timer boundary helpers are fixtures. */
    private function runWorkerLoop(array &$activeFibers, bool $seedPreviousLocals = false, ?\Throwable $failure = null): array
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Server/bin/worker_ssl.php');
        $start = \strpos($source, '    // 连接已经关闭时，先按 (connId, streamId)');
        $end = \strpos($source, '    \\Weline\\Server\\Runtime\\WorkerFiberSnapshot::setSnapshot(', $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $loop = \substr($source, $start, $end - $start);
        $runner = eval('namespace ' . __NAMESPACE__ . '; return static function (array &$activeFibers, bool $seedPreviousLocals, ?\\Throwable $failure): array {
            $connections = [11 => true];
            $activeRequests = count($activeFibers);
            $fiberTickBudgetMs = 0.0;
            $socket = null;
            $shouldExit = $ipcDraining = false;
            $drainStartTime = 0.0;
            $maxDrainTime = 10;
            $fiberScheduler = new WorkerSslSnapshotSchedulerFixture($activeFibers, $failure);
            // The preceding connection-idle scan can retain this same snapshot.
            $fiberState = $activeFibers === [] ? null : reset($activeFibers);
            if ($seedPreviousLocals) {
                $afData = $orphanFiberState = $fiberState = ["context" => new \\stdClass()];
                $af = $afResponse = $afHttp2Adapter = new \\stdClass();
            }
            $error = null;
            try {' . $loop . '} catch (\\Throwable $caught) { $error = $caught; }
            return ["error" => $error, "locals" => array_intersect_key(get_defined_vars(), array_fill_keys(["afData", "orphanFiberState", "fiberState", "af", "afResponse", "afHttp2Adapter"], true))];
        };');
        return $runner($activeFibers, $seedPreviousLocals, $failure);
    }
}

final class WorkerSslSnapshotSchedulerFixture
{
    public function __construct(private array &$activeFibers, private ?\Throwable $failure) {}

    public function tick(callable $before, ?float $budget, callable $after, callable $onFailure): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        foreach ($this->activeFibers as $state) {
            $fiber = $state['fiber'] ?? null;
            if (!$fiber instanceof \Fiber || !$fiber->isSuspended()) {
                continue;
            }
            $before($fiber);
            $fiber->resume();
            $after($fiber);
        }
    }

    public function unregisterFiber(): void {}
}

function wlsResetLongRunningExecutionLimit(): void {}
function wlsDrainAfterResponseIfRequested(mixed ...$args): void {}
function wlsWorkerMonotonicNow(): float { return \hrtime(true) / 1_000_000_000; }
function injectWlsProcessTimeHeader(string $response, float $duration): string { return $response; }
