<?php

declare(strict_types=1);

namespace Weline\Framework\Php;

use Weline\Framework\Runtime\SchedulerSystem;

/**
 * 多路 cURL 流式调度器（CLI / 系统调用场景）。
 *
 * 职责单一：
 * - 持有一个 `\CurlMultiHandle`，把多个 `\CurlHandle` 注册进去；
 * - 通过 `WRITEFUNCTION` 把每个 handle 的字节切片塞入对应的 `\SplQueue`；
 * - `tick()` 推进一次 `curl_multi_select` + `curl_multi_exec`，并消费 `curl_multi_info_read`；
 * - `awaitChunk()` 给业务/Fiber 用：取下一段 chunk；为空则在 Fiber 里 yield，否则自驱 `tick()`。
 *
 * 与 {@see FiberTaskRunner} 配合使用：
 * - FiberTaskRunner 在 idle 段调 `tick()`，让多个 Fiber 真正并行 I/O；
 * - 业务（如 Weline\Ai\Service\Provider\OpenAiProvider 的流式分支）只关心
 *   `register / awaitChunk / finalize` 三个方法。
 *
 * 进程内线程安全：PHP 单线程 + Fiber 协作，任何时刻仅一个执行流推进 `tick()`，
 * `WRITEFUNCTION` 也仅在 `tick()` 内被调用，故 `\SplQueue` 写入无需加锁。
 */
final class CurlStreamPump
{
    private const DEFAULT_SELECT_TIMEOUT_SECONDS = 0.2;
    private const STANDALONE_TICK_TIMEOUT_SECONDS = 0.05;

    private ?\CurlMultiHandle $multi = null;

    /** @var array<int, \CurlHandle> */
    private array $handles = [];

    /** @var array<int, \SplQueue<string>> */
    private array $queues = [];

    /** @var array<int, bool> */
    private array $finished = [];

    /** @var array<int, array{ok:bool, errno:int, error:string}> */
    private array $results = [];

    /** @var array<int, bool> */
    private array $finalized = [];

    /**
     * 把一个 cURL 句柄注册进多路调度器。
     * 句柄的 `WRITEFUNCTION` 会被覆盖为内部分发器；调用方设置的其它选项保持不变。
     *
     * @return int handleId（基于 spl_object_id），同一 pump 内唯一
     * @throws \InvalidArgumentException 重复注册
     */
    public function register(\CurlHandle $ch): int
    {
        $id = \spl_object_id($ch);
        if (isset($this->handles[$id])) {
            throw new \InvalidArgumentException('CurlHandle is already registered with this pump.');
        }

        $this->ensureMultiHandle();

        $queue = new \SplQueue();
        $this->queues[$id] = $queue;
        $this->finished[$id] = false;
        $this->results[$id] = ['ok' => false, 'errno' => 0, 'error' => ''];
        $this->finalized[$id] = false;
        $this->handles[$id] = $ch;

        \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, \CURLOPT_HEADER, false);
        \curl_setopt($ch, \CURLOPT_WRITEFUNCTION, static function ($curl, string $data) use ($queue): int {
            if ($data === '') {
                return 0;
            }
            $queue->enqueue($data);

            return \strlen($data);
        });

        \curl_multi_add_handle($this->multi, $ch);

        return $id;
    }

    /**
     * 取下一段 chunk；EOF 返回 null。
     *
     * - 若 chunk 队列非空：立即返回。
     * - 若已 EOF 且队列空：返回 null。
     * - 若处于活跃 Fiber 且 SchedulerSystem 已激活：调 `SchedulerSystem::yield()` 让外层
     *   {@see FiberTaskRunner} 调度其它 Fiber，下次 resume 时再检查。
     * - 否则（CLI 直跑、单测）：自驱 `tick()` 直到产出或 EOF。
     *
     * @throws \InvalidArgumentException 未知 handleId
     */
    public function awaitChunk(int $handleId): ?string
    {
        $this->assertKnownHandle($handleId);

        while (true) {
            if (isset($this->queues[$handleId]) && !$this->queues[$handleId]->isEmpty()) {
                return $this->queues[$handleId]->dequeue();
            }
            if ($this->finished[$handleId]) {
                return null;
            }

            if (\class_exists(\Fiber::class)
                && \Fiber::getCurrent() !== null
                && SchedulerSystem::isSchedulerActive()
            ) {
                SchedulerSystem::yield();
                continue;
            }

            $this->tick(self::STANDALONE_TICK_TIMEOUT_SECONDS);
        }
    }

    /**
     * 推进一次 `curl_multi`：等待最多 $timeoutSeconds 秒，然后 exec + 读 info。
     *
     * @return bool 是否产生了任何进展（新 chunk 入队 / 任意 handle 完成）
     */
    public function tick(float $timeoutSeconds = self::DEFAULT_SELECT_TIMEOUT_SECONDS): bool
    {
        if ($this->multi === null || $this->handles === []) {
            return false;
        }

        $running = 0;
        $hadProgress = false;
        $beforeQueueLen = $this->totalQueuedChunks();

        if ($timeoutSeconds > 0) {
            $select = @\curl_multi_select($this->multi, \max(0.0, $timeoutSeconds));
            // -1 表示无 fd 可等（windows 偶发），不阻断；继续 exec 一次。
            if ($select === -1) {
                \usleep(1_000);
            }
        }

        do {
            $status = \curl_multi_exec($this->multi, $running);
        } while ($status === \CURLM_CALL_MULTI_PERFORM);

        while ($info = \curl_multi_info_read($this->multi)) {
            $handle = $info['handle'] ?? null;
            if (!$handle instanceof \CurlHandle) {
                continue;
            }
            $id = \spl_object_id($handle);
            if (!isset($this->handles[$id])) {
                continue;
            }
            $errno = (int)($info['result'] ?? \CURLE_OK);
            $this->finished[$id] = true;
            $this->results[$id] = [
                'ok' => $errno === \CURLE_OK,
                'errno' => $errno,
                'error' => $errno === \CURLE_OK ? '' : (string)\curl_strerror($errno),
            ];
            $hadProgress = true;
        }

        if ($this->totalQueuedChunks() > $beforeQueueLen) {
            $hadProgress = true;
        }

        return $hadProgress;
    }

    /**
     * cURL 下载是否已结束（成功 / 失败均算）。
     * 注意：返回 true 时队列里仍可能有未消费的 chunk；要判断"完全消费完"请配合 {@see awaitChunk} 的 null 返回值。
     */
    public function isComplete(int $handleId): bool
    {
        $this->assertKnownHandle($handleId);

        return $this->finished[$handleId];
    }

    /**
     * 完全消费完毕（cURL 已结束 且 队列已空 / 已 finalize）。
     */
    public function isDrained(int $handleId): bool
    {
        $this->assertKnownHandle($handleId);

        if (!$this->finished[$handleId]) {
            return false;
        }

        return !isset($this->queues[$handleId]) || $this->queues[$handleId]->isEmpty();
    }

    /**
     * 释放与 handle 相关的资源，返回最终结果。
     * 多次 finalize 同一 handle 是幂等的。
     *
     * @return array{ok:bool, errno:int, error:string}
     */
    public function finalize(int $handleId): array
    {
        $this->assertKnownHandle($handleId);

        $result = $this->results[$handleId];
        if ($this->finalized[$handleId]) {
            return $result;
        }

        if (isset($this->handles[$handleId])) {
            $ch = $this->handles[$handleId];
            if ($this->multi !== null) {
                @\curl_multi_remove_handle($this->multi, $ch);
            }
        }
        unset($this->handles[$handleId], $this->queues[$handleId]);
        $this->finalized[$handleId] = true;

        $this->releaseMultiIfEmpty();

        return $result;
    }

    /**
     * 是否还有未完成 / 未 finalize 的 handle。
     */
    public function hasActiveHandles(): bool
    {
        return $this->handles !== [];
    }

    /**
     * 取消并强制移除某个 handle（业务侧 abort 信号）。
     */
    public function abort(int $handleId): void
    {
        if (!isset($this->handles[$handleId])) {
            return;
        }
        $this->finished[$handleId] = true;
        $this->results[$handleId] = [
            'ok' => false,
            'errno' => \CURLE_ABORTED_BY_CALLBACK,
            'error' => 'Aborted by caller',
        ];
        $this->finalize($handleId);
    }

    private function ensureMultiHandle(): void
    {
        if ($this->multi instanceof \CurlMultiHandle) {
            return;
        }

        $this->multi = \curl_multi_init();
    }

    private function releaseMultiIfEmpty(): void
    {
        if ($this->multi !== null && $this->handles === []) {
            @\curl_multi_close($this->multi);
            $this->multi = null;
        }
    }

    private function totalQueuedChunks(): int
    {
        $total = 0;
        foreach ($this->queues as $queue) {
            $total += $queue->count();
        }

        return $total;
    }

    private function assertKnownHandle(int $handleId): void
    {
        if (!isset($this->results[$handleId])) {
            throw new \InvalidArgumentException("Unknown handleId {$handleId} on this pump.");
        }
    }
}
