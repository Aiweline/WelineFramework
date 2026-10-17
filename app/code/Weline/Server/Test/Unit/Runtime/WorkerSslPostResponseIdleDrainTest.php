<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Runtime\PostResponseTaskQueue;

final class WorkerSslPostResponseIdleDrainTest extends TestCase
{
    private array $originalTasks = [];
    private mixed $previousMaxPerDrain = null;

    protected function setUp(): void
    {
        parent::setUp();
        $property = new \ReflectionProperty(PostResponseTaskQueue::class, 'tasks');
        $this->originalTasks = $property->getValue();
        $property->setValue(null, []);
        $GLOBALS['idle_queue_replay_flushes'] = 0;
        $this->previousMaxPerDrain = Env::get('wls.post_response_task_max_per_drain', 8);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(PostResponseTaskQueue::class, 'tasks'))->setValue(null, $this->originalTasks);
        unset($GLOBALS['idle_queue_replay_flushes']);
        Env::set('wls.post_response_task_max_per_drain', $this->previousMaxPerDrain);
        parent::tearDown();
    }

    public function testIdleCyclesDrainMultipleTasksUnderDefaultBudget(): void
    {
        Env::set('wls.post_response_task_max_per_drain', 8);
        $executed = [];
        foreach (['a', 'b'] as $key) {
            PostResponseTaskQueue::enqueue($key, static function () use (&$executed, $key): void {
                $executed[] = [$key, $GLOBALS['idle_queue_replay_flushes']];
            });
        }
        $this->runIdleCycle();
        self::assertSame([['a', 1], ['b', 1]], $executed, '默认 max_per_drain 应在同一空闲循环内推进短队列（仍受 budget_ms 约束）。');
        self::assertSame(0, PostResponseTaskQueue::pendingCount());

        PostResponseTaskQueue::enqueue('delayed', static function () use (&$executed): void {
            $executed[] = ['delayed', $GLOBALS['idle_queue_replay_flushes']];
        }, microtime(true) + 0.03);
        $this->runIdleCycle();
        self::assertCount(2, $executed);
        usleep(40000);
        $this->runIdleCycle();
        self::assertSame(['delayed', 3], $executed[2]);
        self::assertSame(0, PostResponseTaskQueue::pendingCount());
    }

    public function testPinnedMaxOneStillDrainsOneTaskPerIdleCycle(): void
    {
        Env::set('wls.post_response_task_max_per_drain', 1);
        $executed = [];
        foreach (['a', 'b'] as $key) {
            PostResponseTaskQueue::enqueue($key, static function () use (&$executed, $key): void {
                $executed[] = [$key, $GLOBALS['idle_queue_replay_flushes']];
            });
        }
        $this->runIdleCycle();
        self::assertSame([['a', 1]], $executed, '钉死 max_per_drain=1 时仍按单任务/循环推进。');
        $this->runIdleCycle();
        self::assertSame([['a', 1], ['b', 2]], $executed);
        self::assertSame(0, PostResponseTaskQueue::pendingCount());
    }

    public function testForegroundWorkAndShutdownKeepPriority(): void
    {
        $executed = 0;
        PostResponseTaskQueue::enqueue('foreground-priority', static function () use (&$executed): void { $executed++; });
        $fiber = new \Fiber(static function (): void { \Fiber::suspend(); });
        $fiber->start();
        foreach ([
            ['activeRequests' => 1],
            ['activeFibers' => [7 => ['fiber' => $fiber]]],
            ['requestBuffers' => [7 => 'GET / HTTP/1.1']],
            ['writeBuffers' => [7 => 'pending response']],
            ['http2PendingRequests' => [7 => [['stream_id' => 3]]]],
            ['shouldExit' => true],
            ['ipcDraining' => true],
        ] as $state) {
            $this->runIdleCycle($state);
            self::assertSame(0, $executed);
            self::assertSame(1, PostResponseTaskQueue::pendingCount());
        }
        self::assertTrue($fiber->isSuspended());
        $fiber->resume();
        self::assertTrue($fiber->isTerminated());
        $this->runIdleCycle();
        self::assertSame(1, $executed);
    }

    public function testExistingDrainAdapterReceivesQueuedHttp2Requests(): void
    {
        $executed = false;
        PostResponseTaskQueue::enqueue('queued-h2', static function () use (&$executed): void { $executed = true; });
        $this->loadProductionHelpers();
        \Weline\Server\Test\Unit\Runtime\IdleQueueReplay\wlsDrainPostResponseTasks(0, [], [], null, [7 => [['stream_id' => 3]]]);
        self::assertFalse($executed);
        self::assertSame(1, PostResponseTaskQueue::pendingCount());
        \Weline\Server\Test\Unit\Runtime\IdleQueueReplay\wlsDrainPostResponseTasks(0, [], [], null, []);
        self::assertTrue($executed);
    }

    /** 执行真实循环尾段，仅把已独立验收的写传输边界替换为无副作用夹具。 */
    private function runIdleCycle(array $state = []): void
    {
        $this->loadProductionHelpers();
        $source = (string)file_get_contents(BP . 'app/code/Weline/Server/bin/worker_ssl.php');
        $start = strpos($source, '    // 处理可写连接');
        $end = strpos($source, '    // 重置连续错误计数（本轮循环成功完成）', $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $loop = substr($source, $start, $end - $start);
        $runner = eval('namespace Weline\\Server\\Test\\Unit\\Runtime\\IdleQueueReplay; return static function(array $state): void {
            $activeRequests = 0;
            $shouldExit = $ipcDraining = false;
            $activeFibers = $http2PendingRequests = [];
            $writableConnections = $writeBuffers = $writeZeroProgress = $connections = $requestBuffers = [];
            $connectionLastActivity = $requestLogged = $pendingClose = $longLivedConnections = $http2ConnectionAdapters = [];
            extract($state, EXTR_OVERWRITE);
            ' . $loop . '
        };');
        $runner($state);
    }

    private function loadProductionHelpers(): void
    {
        if (function_exists('Weline\\Server\\Test\\Unit\\Runtime\\IdleQueueReplay\\wlsDrainPostResponseTasks')) {
            return;
        }
        $source = (string)file_get_contents(BP . 'app/code/Weline/Server/bin/worker_ssl.php');
        $start = strpos($source, 'function wlsDrainPostResponseTasks(');
        $end = strpos($source, 'function wlsHttp3SubmitResponse(', $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        eval('namespace Weline\\Server\\Test\\Unit\\Runtime\\IdleQueueReplay; ' . substr($source, $start, $end - $start) . '
            function wlsSslFlushQueuedWrites(mixed ...$args): void { $GLOBALS["idle_queue_replay_flushes"]++; }
        ');
    }
}
