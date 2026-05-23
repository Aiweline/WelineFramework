<?php

declare(strict_types=1);

namespace Weline\Queue\Service;

use Weline\Cron\Helper\Process;
use Weline\Framework\App\Env;
use Weline\Framework\System\Process\Processer;
use Weline\Queue\Model\Queue;

class QueueDispatchService
{
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

        $freshQueue = $this->loadFreshQueue($queueId);
        if ((int)$freshQueue->getId() <= 0 || !$this->isDispatchable($freshQueue)) {
            return false;
        }

        $this->reconcileRunningQueues();
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
            ->where($this->queue::schema_fields_finished, 0)
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
            $processName = $this->buildQueueRunProcessName((int)$queue->getId(), $queueName);
            $queuePid = (int)($queue->getPid() ?: 0);
            $running = $queuePid > 0 && Processer::isManagedProcessRunning($queuePid, $queueName, '', $processName);
            if ($running) {
                continue;
            }

            if ($queuePid > 0) {
                Processer::removePidFile($processName);
                $output = $this->getManagedProcessOutput($processName, $queuePid);
                $freshQueue = $this->loadFreshQueue((int)$queue->getId());
                if ((int)$freshQueue->getId() > 0) {
                    $queue = $freshQueue;
                }
                $queue->setEndAt(\date('Y-m-d H:i:s'))
                    ->setPid(0);
                if ($queue->isFinished() || $queue->getStatus() === $queue::status_done || $this->hasQueueDoneMarker($output, $queue)) {
                    $queue->setFinished(true);
                    $queue->setResult(PHP_EOL . $output . __('Queue finished...') . $queue->getResult())
                        ->setStatus($queue::status_done)
                        ->save();
                    continue;
                }
                $queue->setStatus($queue::status_error)
                    ->setResult(PHP_EOL . $output . __('Queue process ended unexpectedly...') . $queue->getResult())
                    ->save();
                continue;
            }

            $queue->setStatus($queue::status_pending)
                ->setResult(\trim((string)$queue->getResult() . PHP_EOL . __('Detected running state without PID; reset to pending for rescheduling.')))
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

    private function startQueueProcess(Queue $queue): bool
    {
        if (!$this->isDispatchable($queue)) {
            return false;
        }

        $queueName = Process::initTaskName('queue-' . $queue->getName() . '-' . $queue->getId());
        $processName = $this->buildQueueRunProcessName((int)$queue->getId(), $queueName);
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

    private function buildQueueRunProcessName(int $queueId, string $queueName): string
    {
        $bin = BP . 'bin' . DIRECTORY_SEPARATOR . 'w';

        return \escapeshellarg(PHP_BINARY)
            . ' '
            . \escapeshellarg($bin)
            . ' queue:run --id=' . $queueId
            . ' --name=' . $queueName;
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

    private function hasQueueDoneMarker(string $output, Queue $queue): bool
    {
        $haystack = $output . PHP_EOL . (string)$queue->getResult() . PHP_EOL . (string)$queue->getProcess();

        return \str_contains($haystack, 'QUEUE_DONE');
    }
}
