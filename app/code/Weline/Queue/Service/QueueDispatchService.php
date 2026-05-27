<?php

declare(strict_types=1);

namespace Weline\Queue\Service;

use Weline\Cron\Helper\Process;
use Weline\Framework\App\Env;
use Weline\Framework\System\Process\Processer;
use Weline\Queue\Model\Queue;

class QueueDispatchService
{
    private const DEFAULT_WORKER_MEMORY_LIMIT = '512M';
    private const DEFAULT_WORKER_MEMORY_LIMIT_BY_CLASS = [
        \GuoLaiRen\PageBuilder\Queue\AiSitePlanQueue::class => '1G',
        \GuoLaiRen\PageBuilder\Queue\AiSiteBuildQueue::class => '1G',
        \GuoLaiRen\PageBuilder\Queue\AiSiteAssetQueue::class => '1G',
    ];

    public function __construct(
        private readonly Queue $queue,
    ) {
    }

    /**
     * Dispatch one specific queue through the same background worker contract
     * used by the cron scheduler.
     */
    public function dispatchQueueIfEligible(Queue $queue): bool
    {
        $queueId = (int)$queue->getId();
        if ($queueId <= 0) {
            return false;
        }

        $this->reconcileRunningQueues();
        $freshQueue = $this->loadFreshQueue($queueId);
        if ((int)$freshQueue->getId() <= 0 || !$this->isDispatchable($freshQueue)) {
            return false;
        }

        if ($this->countRunningAutoQueues() >= $this->resolveMaxConcurrent()) {
            return false;
        }

        return $this->startQueueProcess($freshQueue);
    }

    /**
     * Dispatch pending auto queues. This is the cron scheduler entry point.
     *
     * @return array{dispatched: int, slots: int}
     */
    public function dispatchPendingAutoQueues(?int $limit = null): array
    {
        $maxConcurrent = $this->resolveMaxConcurrent();
        $this->reconcileRunningQueues();
        $runningCount = $this->countRunningAutoQueues();
        $slots = \max(0, $maxConcurrent - $runningCount);
        if ($limit !== null) {
            $slots = \min($slots, \max(0, $limit));
        }
        if ($slots <= 0) {
            return ['dispatched' => 0, 'slots' => 0];
        }

        $pendingQueues = $this->queue->reset()
            ->where($this->queue::schema_fields_finished, 0)
            ->where($this->queue::schema_fields_auto, 1)
            ->where($this->queue::schema_fields_status, $this->queue::status_pending)
            ->pagination(1, $slots)
            ->select()
            ->fetch()
            ->getItems();

        $dispatched = 0;
        foreach ($pendingQueues as $queue) {
            if ($queue instanceof Queue && $this->startQueueProcess($queue)) {
                $dispatched++;
            }
        }

        return ['dispatched' => $dispatched, 'slots' => $slots];
    }

    public function reconcileRunningQueues(): void
    {
        $runningQueues = $this->queue->reset()
            ->where($this->queue::schema_fields_auto, 1)
            ->where($this->queue::schema_fields_status, $this->queue::status_running)
            ->select()
            ->fetch()
            ->getItems();

        foreach ($runningQueues as $queue) {
            if (!$queue instanceof Queue) {
                continue;
            }
            $queueName = Process::initTaskName('queue-' . $queue->getName() . '-' . $queue->getId());
            $processName = $this->buildQueueRunProcessName((int)$queue->getId(), $queueName, $queue);
            $queuePid = (int)($queue->getPid() ?: 0);
            $pidAlive = $queuePid > 0 && Processer::isRunningByPid($queuePid);
            $running = $pidAlive && Processer::isManagedProcessRunning($queuePid, $queueName, '', $processName);
            if ($running) {
                continue;
            }

            if ($queuePid > 0) {
                $output = $this->getManagedProcessOutput($processName, $queuePid);
                Processer::removePidFile($processName);
                $freshQueue = $this->loadFreshQueue((int)$queue->getId());
                if ((int)$freshQueue->getId() > 0) {
                    $queue = $freshQueue;
                }
                $queue->setEndAt(\date('Y-m-d H:i:s'))
                    ->setPid(0);
                if ($queue->isFinished() || $queue->getStatus() === $queue::status_done || $this->hasQueueDoneMarker($output, $queue)) {
                    $queue->setFinished(true);
                    $message = (string)__('队列进程已结束，检测到完成标记，已同步为完成状态。');
                    $queue->setResult($this->prependResultMessage($queue->getResult(), $output, $message))
                        ->setProcess($this->appendProcessMessage($queue->getProcess(), $message))
                        ->setStatus($queue::status_done)
                        ->save();
                    continue;
                }
                $message = $pidAlive
                    ? (string)__('队列记录的 PID %{1} 仍存在，但已不匹配当前队列执行进程，已标记为异常。', [$queuePid])
                    : (string)__('队列记录的 PID %{1} 已不存在，已标记为异常。', [$queuePid]);
                $queue->setStatus($queue::status_error)
                    ->setResult($this->prependResultMessage($queue->getResult(), $output, $message))
                    ->setProcess($this->appendProcessMessage($queue->getProcess(), $message))
                    ->save();
                continue;
            }

            if ($queue->isFinished()) {
                $queue->setStatus($queue::status_done)
                    ->setPid(0)
                    ->setEndAt(\date('Y-m-d H:i:s'))
                    ->save();
                continue;
            }

            $message = (string)__('运行中队列没有记录 PID，已重置为 pending 等待重新调度。');
            $queue->setStatus($queue::status_pending)
                ->setResult($this->appendProcessMessage($queue->getResult(), $message))
                ->setProcess($this->appendProcessMessage($queue->getProcess(), $message))
                ->save();
        }
    }

    public function countRunningAutoQueues(): int
    {
        $items = $this->queue->reset()
            ->where($this->queue::schema_fields_finished, 0)
            ->where($this->queue::schema_fields_auto, 1)
            ->where($this->queue::schema_fields_status, $this->queue::status_running)
            ->select()
            ->fetch()
            ->getItems();

        return \count($items);
    }

    public function getMaxConcurrent(): int
    {
        return $this->resolveMaxConcurrent();
    }

    public function getWorkerMemoryLimit(): string
    {
        return $this->resolveWorkerMemoryLimit();
    }

    private function startQueueProcess(Queue $queue): bool
    {
        if (!$this->isDispatchable($queue)) {
            return false;
        }

        $queueName = Process::initTaskName('queue-' . $queue->getName() . '-' . $queue->getId());
        $processName = $this->buildQueueRunProcessName((int)$queue->getId(), $queueName, $queue);
        $pid = Processer::create($processName, true, false, true);
        if (!$pid) {
            $output = $this->getManagedProcessOutput($processName);
            $queue->setResult($output . __('Failed to create queue process. Process name: %{1}', [$processName]))
                ->setStartAt(\date('Y-m-d H:i:s'))
                ->setStatus($queue::status_error)
                ->save();
            return false;
        }

        $freshQueue = $this->loadFreshQueue((int)$queue->getId());
        if ((int)$freshQueue->getId() > 0) {
            $queue = $freshQueue;
        }
        if (!$this->isDispatchable($queue)) {
            return true;
        }

        $queue->setStatus($queue::status_running)
            ->setPid($pid)
            ->setStartAt(\date('Y-m-d H:i:s'))
            ->save();

        return true;
    }

    private function isDispatchable(Queue $queue): bool
    {
        return !$queue->isFinished()
            && $queue->getAuto()
            && $queue->getStatus() === $queue::status_pending;
    }

    private function loadFreshQueue(int $queueId): Queue
    {
        $freshQueue = clone $this->queue;
        $freshQueue->clear()->load($queueId);

        return $freshQueue;
    }

    private function resolveMaxConcurrent(): int
    {
        $maxConcurrent = (int)(Env::get('queue.cron.max_concurrent', 4) ?: 4);
        if ($maxConcurrent < 1) {
            $maxConcurrent = 1;
        }

        return $maxConcurrent;
    }

    private function buildQueueRunProcessName(int $queueId, string $queueName, ?Queue $queue = null): string
    {
        $bin = BP . 'bin' . DIRECTORY_SEPARATOR . 'w';
        $memoryLimit = $this->resolveWorkerMemoryLimit($queue);

        return \escapeshellarg(PHP_BINARY)
            . ' -d memory_limit=' . \escapeshellarg($memoryLimit)
            . ' '
            . \escapeshellarg($bin)
            . ' queue:run --id=' . $queueId
            . ' --name=' . $queueName;
    }

    private function resolveWorkerMemoryLimit(?Queue $queue = null): string
    {
        $queueClass = $this->resolveQueueClass($queue);
        if ($queueClass !== '') {
            $configuredByClass = Env::get(
                'queue.worker.memory_limit_by_class.' . $queueClass,
                Env::get('queue.worker.memory_limit.' . $queueClass, null)
            );
            if ($configuredByClass !== null && $configuredByClass !== '') {
                return $this->normalizeMemoryLimit(
                    $configuredByClass,
                    self::DEFAULT_WORKER_MEMORY_LIMIT_BY_CLASS[$queueClass] ?? self::DEFAULT_WORKER_MEMORY_LIMIT
                );
            }

            if (isset(self::DEFAULT_WORKER_MEMORY_LIMIT_BY_CLASS[$queueClass])) {
                return $this->normalizeMemoryLimit(
                    self::DEFAULT_WORKER_MEMORY_LIMIT_BY_CLASS[$queueClass],
                    self::DEFAULT_WORKER_MEMORY_LIMIT
                );
            }
        }

        $configured = Env::get(
            'queue.worker.memory_limit',
            Env::get('queue.cron.memory_limit', self::DEFAULT_WORKER_MEMORY_LIMIT)
        );

        return $this->normalizeMemoryLimit($configured, self::DEFAULT_WORKER_MEMORY_LIMIT);
    }

    private function resolveQueueClass(?Queue $queue): string
    {
        if (!$queue instanceof Queue || (int)$queue->getTypeId() <= 0) {
            return '';
        }

        try {
            return \ltrim((string)$queue->getType()->getClass(), '\\');
        } catch (\Throwable) {
            return '';
        }
    }

    private function normalizeMemoryLimit(mixed $value, string $default): string
    {
        if (\is_int($value) || \is_float($value)) {
            $value = (string)(int)$value;
        }

        $value = \strtoupper(\trim((string)$value));
        $default = \strtoupper(\trim($default)) ?: self::DEFAULT_WORKER_MEMORY_LIMIT;
        if ($value === '') {
            return $default;
        }
        if ($value === '-1') {
            return '-1';
        }
        if (\preg_match('/^[1-9]\d*$/', $value)) {
            return $value . 'M';
        }
        if (\preg_match('/^[1-9]\d*(?:K|M|G)$/', $value)) {
            return $value;
        }

        return $default;
    }

    private function getManagedProcessOutput(string $processName, int $pid = 0): string
    {
        try {
            if ($pid > 0) {
                $output = Processer::outputByPid($pid);
                if (\is_string($output) && $output !== '') {
                    return $output;
                }
            }

            $path = Processer::getLogFile($processName);
            if (\is_file($path)) {
                $output = \file_get_contents($path);
                if (\is_string($output)) {
                    return $output;
                }
            }
        } catch (\Throwable) {
            return '';
        }

        return '';
    }

    private function appendProcessMessage(mixed $current, string $message): string
    {
        return \trim((string)$current . PHP_EOL . $message);
    }

    private function prependResultMessage(string $current, string $output, string $message): string
    {
        return \trim($output . PHP_EOL . $message . PHP_EOL . $current);
    }

    private function hasQueueDoneMarker(string $output, Queue $queue): bool
    {
        $haystack = $output . PHP_EOL . (string)$queue->getResult() . PHP_EOL . (string)$queue->getProcess();

        return \str_contains($haystack, 'QUEUE_DONE');
    }
}
