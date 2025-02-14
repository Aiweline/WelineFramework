<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/27 00:51:30
 */

namespace Weline\Cron\Console\Cron\Task;

use Cron\CronExpression;
use Weline\Cron\CronTaskInterface;
use Weline\Cron\Helper\CronStatus;
use Weline\Cron\Helper\Process;
use Weline\Cron\Model\CronTask;
use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\OS\Win;


class Run implements CommandInterface
{
    /**
     * @var \Weline\Cron\Model\CronTask
     */
    private CronTask $cronTask;
    private Printing $printing;

    public function __construct(
        CronTask $cronTask,
        Printing $printing
    )
    {
        $this->cronTask = $cronTask;
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $force = $args['f'] ?? $args['force'] ?? false;
        $process = $args['p'] ?? $args['process'] ?? false;
        foreach ($args as $key => $arg) {
            if (!is_int($key) || str_starts_with((string)$arg, '-')) {
                unset($args[$key]);
            }
        }
        array_shift($args);
        $task_names = $args;
        if (!is_bool($force)) {
            # 解锁任务
            if (empty($task_names)) {
                ObjectManager::getInstance(Printing::class)->error(__('请指定要执行的任务！php bin/w cron:task:run demo -f'));
                die;
            }
        }
        # 如果给定的任务是单个任务，说明是具体要执行的任务
        if (($process || $force) && count($task_names) == 1) {
            /**@var CronTask $task */
            $task = $this->cronTask->where($this->cronTask::fields_EXECUTE_NAME, array_shift($task_names))->find()->fetch();
            if (!$task->getId()) {
                ObjectManager::getInstance(Printing::class)->error(__('指执行的任务不存在！'));
                exit;
            }
            $class = $task->getData(CronTask::fields_CLASS);
            /**@var CronTaskInterface $instance */
            $instance = ObjectManager::getInstance($class);
            $instance->execute();
            $task->setData($task::fields_RUN_TIMES, (int)$task->getData($task::fields_RUN_TIMES) + 1);
            # 设置程序运行数据
            $task->setData($task::fields_BLOCK_TIME, 0);
            # 解锁
            $task->setData($task::fields_STATUS, CronStatus::SUCCESS->value);
            $task_end_time = microtime(true) - ($task->getData(CronTask::fields_RUN_TIME));
            $task->setData($task::fields_RUNTIME, $task_end_time);
            # 运行完毕将进程ID设置为0
            $task->setData($task::fields_PID, 0);
            exit;
        }
        # 读取给定的任务
        $pageSize = 1;
        if ($task_names) {
            foreach ($task_names as $taskName) {
                $this->cronTask->where($this->cronTask::fields_EXECUTE_NAME, $taskName);
            }
            $pageSize = count($task_names);
        }
        $this->cronTask->order('update_time', 'asc')
            ->pagination(1, $pageSize)
            ->select()
            ->fetch();
        # 读取给定的任务
        if ($task_names) {
            foreach ($task_names as $taskName) {
                $this->cronTask->where($this->cronTask::fields_EXECUTE_NAME, $taskName);
            }
        }
        # 分页读取任务
        $taskTotal = (int)$this->cronTask->pagination['totalSize'];
        $taskPages = (int)$this->cronTask->pagination['lastPage'];
        if ($taskTotal == 0) {
            ObjectManager::getInstance(Printing::class)->error(__('没有要执行的任务：%1 , 参数：', [implode(' ', $task_names), implode(' ', $args)]));
            exit;
        }

        for ($taskPage = 1; $taskPage <= $taskPages; $taskPage++) {
            $offset = ($taskPage - 1) * $pageSize;
            $currentTotal = $offset + $pageSize;
            CronStatus::displayProgressBar(__('任务进度：页(%1=>%2)/目(%3/%4)', [$taskPages, $taskPage, $taskTotal, $taskPage]), $currentTotal,
                $taskTotal, false);
            $tasks = $this->cronTask->limit($pageSize, $offset)
                ->select()
                ->fetch()
                ->getItems();
            # 进程信息管理
            /**@var CronTask $taskModel */
            foreach ($tasks as $key => $taskModel) {
                $execute_name = Process::initTaskName($taskModel->getData($taskModel::fields_EXECUTE_NAME));
                # 进程名
                $command_file = BP . 'bin' . DS . 'w';
                $process_name = PHP_BINARY . ' ' . $command_file . ' cron:task:run -process ' . $execute_name . ($force ? ' -force' : '');
                $task_start_time = ((int)$taskModel->getData($taskModel::fields_RUN_TIME)) ?: microtime(true);
                $task_run_date = date('Y-m-d H:i:s');
                # 上锁
                $cron = new CronExpression($taskModel->getData('cron_time'));
                # 设置程序预计数据
                $taskModel->setData($taskModel::fields_BLOCK_TIME, 0);
                $taskModel->setData($taskModel::fields_RUNTIME_ERROR, '');
                $taskModel->setData($taskModel::fields_NEXT_RUN_DATE, $cron->getNextRunDate()->format('Y-m-d H:i:s'));
                $taskModel->setData($taskModel::fields_MAX_NEXT_RUN_DATE, $cron->getNextRunDate('now', 3)->format('Y-m-d H:i:s'));
                $taskModel->setData($taskModel::fields_PRE_RUN_DATE, $cron->getPreviousRunDate()->format('Y-m-d H:i:s'));
                # ----------使用进程名检测任务进程是否在运行---------------

                # 使用进程名检查该进程是否在运行
                $pid = Process::getPidByName($process_name);
                if ($pid) {
                    $output = Process::getProcessOutput($process_name);
                    $taskModel->setData($taskModel::fields_RUNTIME_ERROR, $output . __('进程已存在，请检查进程状态！进程名：%1', $process_name))
                        ->setData($taskModel::fields_STATUS, CronStatus::RUNNING->value)
                        ->setData($taskModel::fields_BLOCK_TIME, microtime(true) - $task_start_time)
                        ->setData($taskModel::fields_PID, $pid)
                        ->save();
                    # 如果强制执行
                    if ($force) {
                        $msg = __('%1 程序ID:%2 正在运行中，当前强制执行正在杀死进程中...', [$process_name, $pid]);
                        Process::unsetLogProcessFilePath($process_name);
                        $taskModel->setData($taskModel::fields_RUNTIME_ERROR, $output . $msg)
                            ->setData($taskModel::fields_BLOCK_TIME, 0)
                            ->setData($taskModel::fields_STATUS, CronStatus::RUNNING->value)
                            ->save();
                        Process::killPid($pid, $process_name);
                        if (Process::isProcessRunning($pid)) {
                            $force = false;
                            $msg = __('%1 程序ID:%2 杀死失败！程序不会强制执行，请手动杀死进程后重试!', [$process_name, $pid]);
                            $taskModel->setData($taskModel::fields_RUNTIME_ERROR, $msg)->save();
                        }
                    } else {
                        $msg = __('%1 程序ID:%2 正在运行中，若要强制执行，请手动杀死进程后重试!或者使用配置项’-f‘的强制执行', [$process_name, $pid]);
                        $taskModel->setData($taskModel::fields_RUNTIME_ERROR, $output . $msg)->save();
                    }
                    continue;
                } elseif ($pid = ($taskModel->getData($taskModel::fields_PID) ?: 0)) {
                    # -----------如果数据库存在PID,说明程序结束---------------
                    $msg = __('%1 程序ID:%2 已运行完毕!', [$process_name, $pid]);
                    $taskModel->setData($taskModel::fields_RUN_TIMES, (int)$taskModel->getData($taskModel::fields_RUN_TIMES) + 1);
                    # 设置程序运行数据
                    $taskModel->setData($taskModel::fields_BLOCK_TIME, 0);
                    $output = $msg . PHP_EOL . Process::getProcessOutput($process_name);
                    Process::unsetLogProcessFilePath($process_name);
                    $taskModel->setData($taskModel::fields_RUNTIME_ERROR, $output);
                    # 解锁
                    $taskModel->setData($taskModel::fields_STATUS, CronStatus::SUCCESS->value);
                    $taskModel->setData($taskModel::fields_RUNTIME, microtime(true) - $taskModel->getData($taskModel::fields_RUN_TIME));
                    # 运行完毕将进程ID设置为0
                    $taskModel->setData($taskModel::fields_PID, 0)
                        ->save();
                    continue;
                }
                if ($force || $cron->isDue($task_run_date)) {
                    if ($force || ($taskModel->getData($taskModel::fields_STATUS) !== CronStatus::BLOCK->value)) {
                        # 设置程序运行数据
                        # 上锁
                        $taskModel->setData($taskModel::fields_STATUS, CronStatus::BLOCK->value);
                        $taskModel->setData($taskModel::fields_RUN_TIME, $task_start_time);
                        $taskModel->setData($taskModel::fields_RUN_DATE, $task_run_date);
                        # 创建异步程序
                        $pid = Process::create($process_name);
                        if (!$pid) {
                            $taskModel->setData($taskModel::fields_RUNTIME_ERROR, __('进程创建失败！请检查进程状态！'))
                                ->setData($taskModel::fields_STATUS, CronStatus::FAIL->value)
                                ->save();
                        } else {
                            # 记录PID
                            $taskModel->setData($taskModel::fields_PID, $pid)
                                ->save();
                        }
                    } else {
                        # 到了程序下次运行的时间，但是程序仍然处于block阻塞状态，设置程序运行阻塞数据
                        $taskModel->setData($taskModel::fields_BLOCK_TIME, $task_start_time - $task_start_time);
                        if ($block_time = $taskModel->getData($taskModel::fields_BLOCK_TIME)) {
                            if ($block_time > ($taskModel->getData($taskModel::fields_BLOCK_UNLOCK_TIMEOUT) * 60)) {
                                $taskModel->setData($taskModel::fields_BLOCK_TIMES, (int)$taskModel->getData($taskModel::fields_BLOCK_TIMES) + 1);
                                $taskModel->setData($taskModel::fields_STATUS, CronStatus::PENDING->value);
                                $taskModel->setData($taskModel::fields_RUNTIME_ERROR_DATE, date('Y-m-d H:i:s'));
                                $taskModel->setData($taskModel::fields_RUNTIME_ERROR, '任务调度系统：调度任务阻塞超时自动解锁，请查看任务调度设置是否合理！');
                            }
                        }
                    }
                } else {
                    $taskModel->setData($taskModel::fields_STATUS, CronStatus::PENDING->value)->save();
                }
            }
        }

    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '运行计划调度任务。需要运行特定任务时：php bin/w cron:task:run demo demo_run 依次往后添加多个任务名 -f 选项强制解锁运行。';
    }
}
