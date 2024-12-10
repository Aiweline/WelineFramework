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
        $this->queue    = $queue;
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
        $this->queue->reset()->where($this->queue::fields_finished, 0)
            ->where($this->queue::fields_auto, 1)
            ->where($this->queue::fields_status, $this->queue::status_done, '!=')
            ->where($this->queue::fields_status, $this->queue::status_stop, '!=')
            ->where($this->queue::fields_status, $this->queue::status_error, '!=')
            ->pagination();
        $pages = $this->queue->pagination['lastPage'];
        foreach (range(1, $pages) as $page) {
            $queues = $this->queue->reset()->where($this->queue::fields_finished, 0)
                ->where($this->queue::fields_status, $this->queue::status_done, '!=')
                ->where($this->queue::fields_status, $this->queue::status_stop, '!=')
                ->where($this->queue::fields_status, $this->queue::status_error, '!=')
                ->where($this->queue::fields_auto, 1)
                ->pagination($page, $pageSize)
                ->select()
                ->fetch()
                ->getItems();
            /**@var \Weline\Queue\Model\Queue $queue */
            foreach ($queues as $key => $queue) {
                # 队列名
                $queue_name = Process::initTaskName('queue-' . $queue->getName() . '-' . $queue->getId());
                # 进程名
                $process_name = PHP_BINARY . ' bin/m queue:run --id=' . $queue->getId() . ' --name \'' . $queue_name . '\'';
                # 使用进程名检查该进程是否在运行
                $pid = Process::getPidByName($process_name);
                $result = $queue->getResult();
                if ($pid) {
                    $output = Process::getProcessOutput($process_name);
                    $queue->setResult($output . __('进程已存在，请检查进程状态！进程名：%1', $process_name).$result)
                        ->setPid($pid)
                        ->save();
                    continue;
                } elseif ($queue->getPid()) {
                    # -----------没有查到该程序正在运行，数据库又存在PID，说明该任务运行结束-------------
                    $output = Process::getProcessOutput($process_name);
                    $queue->setEndAt(date('Y-m-d H:i:s'))
                        ->setPid(0);
                    if ($queue->isFinished()) {
                        $queue->setResult( PHP_EOL . $output . __('队列结束...').$result)
                            ->setStatus($queue::status_done)
                            ->save();
                    } else {
                        $queue->setStatus($queue::status_error)
                            ->setResult( PHP_EOL . $output . __('队列进程异常结束...').$result)
                            ->save();
                    }
                    # 卸载进程记录文件
                    Process::unsetLogProcessFilePath($process_name);
                    continue;
                }
                # 创建进程
                $pid = Process::create($process_name);
                if (!$pid) {
                    $queue->setResult(__('进程创建失败！请检查进程状态！进程名：%1', [$process_name]))
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

    /**
     * @inheritDoc
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return 180;
    }
}
