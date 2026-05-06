<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：11/7/2023 09:17:50
 */

namespace Weline\Queue\Cron;

use Weline\Cron\Helper\CronStatus;
use Weline\Cron\Helper\Process;
use Weline\Framework\App\Env;
use Weline\Framework\System\Process\Processer;
use Weline\Framework\Output\Cli\Printing;

class Queue implements \Weline\Cron\CronTaskInterface
{

    private \Weline\Queue\Model\Queue $queue;
    private \Weline\Framework\Output\Cli\Printing $printing;

    function __construct(
        \Weline\Queue\Model\Queue $queue,
        Printing                  $printing
    )
    {
        $this->queue = $queue;
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return '消息队列-消费任务';
    }

    /**
     * @inheritDoc
     */
    public function execute_name(): string
    {
        return 'queue';
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return <<<QUEUETIP
定时消费任务，每分钟检测一次消息队列。如果有任务继续执行队列中的任务。
QUEUETIP;

    }

    /**
     * @inheritDoc
     */
    public function cron_time(): string
    {
        return '*/1 * * * *';
    }

    /**
     * @inheritDoc
     */
    public function execute(): string
    {
        $maxConcurrent = $this->resolveMaxConcurrent();
        $this->reconcileRunningQueues();
        $runningCount = $this->countRunningAutoQueues();
        $slots = max(0, $maxConcurrent - $runningCount);
        if ($slots <= 0) {
            return 'OK';
        }

        $pendingQueues = $this->queue->reset()
            ->where($this->queue::schema_fields_finished, 0)
            ->where($this->queue::schema_fields_auto, 1)
            ->where($this->queue::schema_fields_status, $this->queue::status_pending)
            ->pagination(1, $slots)
            ->select()
            ->fetch()
            ->getItems();

        /** @var \Weline\Queue\Model\Queue $queue */
        foreach ($pendingQueues as $queue) {
            $this->startQueueProcess($queue);
        }
        return 'OK';
    }

    private function startQueueProcess(\Weline\Queue\Model\Queue $queue): void
    {
        $queueName = Process::initTaskName('queue-' . $queue->getName() . '-' . $queue->getId());
        $processName = $this->buildQueueRunProcessName((int)$queue->getId(), $queueName);
        $pid = Processer::create($processName, true, false, true);
        if (!$pid) {
            $output = $this->getManagedProcessOutput($processName);
            $queue->setResult($output . __('进程创建失败！请检查进程状态！进程名：%{1}', [$processName]))
                ->setStartAt(date('Y-m-d H:i:s'))
                ->setStatus($queue::status_error)
                ->save();
            return;
        }

        $queue->setStatus($queue::status_running)
            ->setPid($pid)
            ->setStartAt(date('Y-m-d H:i:s'))
            ->save();
    }

    private function reconcileRunningQueues(): void
    {
        $runningQueues = $this->queue->reset()
            ->where($this->queue::schema_fields_finished, 0)
            ->where($this->queue::schema_fields_auto, 1)
            ->where($this->queue::schema_fields_status, $this->queue::status_running)
            ->select()
            ->fetch()
            ->getItems();

        /** @var \Weline\Queue\Model\Queue $queue */
        foreach ($runningQueues as $queue) {
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
                $freshQueue = clone $this->queue;
                $freshQueue->clear()->load((int)$queue->getId());
                if ($freshQueue->getId()) {
                    $queue = $freshQueue;
                }
                $queue->setEndAt(date('Y-m-d H:i:s'))
                    ->setPid(0);
                if ($queue->isFinished() || $queue->getStatus() === $queue::status_done || $this->hasQueueDoneMarker($output, $queue)) {
                    $queue->setFinished(true);
                    $queue->setResult(PHP_EOL . $output . __('队列结束...') . $queue->getResult())
                        ->setStatus($queue::status_done)
                        ->save();
                    continue;
                }
                $queue->setStatus($queue::status_error)
                    ->setResult(PHP_EOL . $output . __('队列进程异常结束...') . $queue->getResult())
                    ->save();
                continue;
            }

            // running 但 pid=0 说明是脏状态，回收后重新进入待调度。
            $queue->setStatus($queue::status_pending)
                ->setResult(\trim((string)$queue->getResult() . PHP_EOL . __('检测到无 PID 的运行中状态，已回收为 pending，等待重新调度。')))
                ->save();
        }
    }

    private function countRunningAutoQueues(): int
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

        return escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($bin)
            . ' queue:run --id=' . $queueId
            . ' --name=' . $queueName;
    }

    private function getManagedProcessOutput(string $processName, int $pid = 0): string
    {
        try {
            if ($pid > 0) {
                $output = Processer::outputByPid($pid);
                if (is_string($output) && $output !== '') {
                    return $output;
                }
            }

            $path = Processer::getLogFile($processName);
            if (is_file($path)) {
                $output = file_get_contents($path);
                if (is_string($output)) {
                    return $output;
                }
            }
        } catch (\Throwable) {
            return '';
        }

        return '';
    }

    private function hasQueueDoneMarker(string $output, \Weline\Queue\Model\Queue $queue): bool
    {
        $haystack = $output . PHP_EOL . (string)$queue->getResult() . PHP_EOL . (string)$queue->getProcess();

        return str_contains($haystack, 'QUEUE_DONE');
    }

    /**
     * @inheritDoc
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return 180;
    }
}
