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
        $pageSize = 2;
        $this->queue->reset()->where($this->queue::schema_fields_finished, 0)
            ->where($this->queue::schema_fields_auto, 1)
            ->where($this->queue::schema_fields_status, $this->queue::status_done, '!=')
            ->where($this->queue::schema_fields_status, $this->queue::status_stop, '!=')
            ->where($this->queue::schema_fields_status, $this->queue::status_error, '!=')
            ->pagination();
        $pages = $this->queue->pagination['lastPage'];
        foreach (range(1, $pages) as $page) {
            $queues = $this->queue->reset()->where($this->queue::schema_fields_finished, 0)
                ->where($this->queue::schema_fields_status, $this->queue::status_done, '!=')
                ->where($this->queue::schema_fields_status, $this->queue::status_stop, '!=')
                ->where($this->queue::schema_fields_status, $this->queue::status_error, '!=')
                ->where($this->queue::schema_fields_auto, 1)
                ->pagination($page, $pageSize)
                ->select()
                ->fetch()
                ->getItems();
            /**@var \Weline\Queue\Model\Queue $queue */
            foreach ($queues as $key => $queue) {
                # 队列名
                $queue_name = Process::initTaskName('queue-' . $queue->getName() . '-' . $queue->getId());
                # 进程名
                $process_name = $this->buildQueueRunProcessName((int)$queue->getId(), $queue_name);
                # 优先使用队列记录 PID 精确判活（Windows 下按命令行匹配不稳定，容易误判）
                $queuePid = (int)($queue->getPid() ?: 0);
                $queuePidRunning = $queuePid > 0
                    && Processer::isManagedProcessRunning($queuePid, $queue_name, '', $process_name);
                # 兼容旧逻辑：命令行名匹配作为回退
                $managedPid = $queuePidRunning ? $queuePid : (int)(Processer::getData($process_name, 'pid') ?: 0);
                $managedPidRunning = $managedPid > 0
                    && Processer::isManagedProcessRunning($managedPid, $queue_name, '', $process_name);
                if (!$managedPidRunning && $managedPid > 0) {
                    Processer::removePidFile($process_name);
                }
                $pid = $queuePidRunning ? $queuePid : ($managedPidRunning ? $managedPid : 0);
                $result = $queue->getResult();
                if ($pid) {
                    $output = $this->getManagedProcessOutput($process_name, $pid);
                    $queue->setResult($output . __('进程已存在，请检查进程状态！进程名：%{1}', $process_name) . $result)
                        ->setPid($pid)
                        ->setStatus($queue::status_running)
                        ->save();
                    continue;
                } elseif ($queue->getPid()) {
                    # -----------没有查到该程序正在运行，数据库又存在PID，说明该任务运行结束-------------
                    $output = $this->getManagedProcessOutput($process_name, $queuePid);
                    $queue->setEndAt(date('Y-m-d H:i:s'))
                        ->setPid(0);
                    if ($queue->isFinished()) {
                        $queue->setResult(PHP_EOL . $output . __('队列结束...') . $result)
                            ->setStatus($queue::status_done)
                            ->save();
                    } else {
                        $queue->setStatus($queue::status_error)
                            ->setResult(PHP_EOL . $output . __('队列进程异常结束...') . $result)
                            ->save();
                    }
                    # 卸载进程记录文件
                    Processer::removePidFile($process_name);
                    continue;
                }
                # 创建进程
                $pid = Processer::create($process_name, true, false, true);
                if (!$pid) {
                    $output = $this->getManagedProcessOutput($process_name);
                    $queue->setResult($output . __('进程创建失败！请检查进程状态！进程名：%{1}', [$process_name]))
                        ->setStartAt(date('Y-m-d H:i:s'))
                        ->setStatus($queue::status_error)
                        ->save();
                } else {
                    # 记录PID
                    $queue->setStatus($queue::status_running)
                        ->setPid($pid)
                        ->setStartAt(date('Y-m-d H:i:s'))
                        ->save();
                }
            }
        }
        return 'OK';
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

    /**
     * @inheritDoc
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return 180;
    }
}
