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
            $task = $this->cronTask->where($this->cronTask::schema_fields_EXECUTE_NAME, array_shift($task_names))->find()->fetch();
            if (!$task->getId()) {
                ObjectManager::getInstance(Printing::class)->error(__('指执行的任务不存在！'));
                exit;
            }
            $class = (string) ($task->getData(CronTask::schema_fields_CLASS) ?? '');
            $executeName = (string) ($task->getData(CronTask::schema_fields_EXECUTE_NAME) ?? '');
            if ($class !== '' && !class_exists($class)) {
                $fallback = $executeName === 'domain_lifecycle_orchestration'
                    ? 'Weline\\Websites\\Cron\\DomainLifecycleOrchestration'
                    : '';
                if ($fallback !== '' && class_exists($fallback)) {
                    $class = $fallback;
                    $task->setData(CronTask::schema_fields_CLASS, $class)->setData(CronTask::schema_fields_MODULE, 'Weline_Websites')->save();
                }
            }
            /**@var CronTaskInterface $instance */
            $instance = ObjectManager::getInstance($class);
            $sseManual = ($v = \getenv('WELINE_CRON_MANUAL_SSE')) !== false && $v !== '' && $v !== '0';
            if ($sseManual) {
                $this->printing->note((string) __('【后台手动运行】%{1} 开始执行…', [$executeName]));
                $this->flushCronCliStreams();
            }
            $result = $instance->execute();
            if ($result !== '' && $result !== null) {
                $this->printing->success((string) $result);
            } elseif ($sseManual) {
                $this->printing->note(
                    (string) __('【摘要】任务未返回简短摘要（若上方无其它行，可能本轮无待处理项；详情见 var/log）')
                );
            }
            if ($sseManual) {
                $this->flushCronCliStreams();
            }
            $task->setData($task::schema_fields_RUN_TIMES, (int)$task->getData($task::schema_fields_RUN_TIMES) + 1);
            # 设置程序运行数据
            $task->setData($task::schema_fields_BLOCK_TIME, 0);
            # 解锁
            $task->setData($task::schema_fields_STATUS, CronStatus::SUCCESS->value);
            $task_end_time = microtime(true) - ($task->getData(CronTask::schema_fields_RUN_TIME));
            $task->setData($task::schema_fields_RUNTIME, $task_end_time);
            # 运行完毕将进程ID设置为0
            $task->setData($task::schema_fields_PID, 0);
            $task->save();
            exit;
        }
        $tasks = $this->loadTaskSnapshot($task_names);
        $taskTotal = \count($tasks);
        if ($taskTotal == 0) {
            ObjectManager::getInstance(Printing::class)->error(__('没有要执行的任务：%{1} , 参数：', [implode(' ', $task_names), implode(' ', $args)]));
            exit;
        }

        # 进程信息管理
        /**@var CronTask $taskModel */
        foreach ($tasks as $key => $taskModel) {
                $currentTotal = $key + 1;
                CronStatus::displayProgressBar(__('任务进度：页(%{1}=>%{2})/目(%{3}/%{4})', [$taskTotal, $currentTotal, $taskTotal, $currentTotal]), $currentTotal,
                    $taskTotal, false);
                $execute_name = Process::initTaskName($taskModel->getData($taskModel::schema_fields_EXECUTE_NAME));
                # 进程名
                $command_file = BP . 'bin' . DS . 'w';
                $process_name = PHP_BINARY . ' ' . $command_file . ' cron:task:run -process ' . $execute_name . ($force ? ' -force' : '');
                $task_start_time = ((int)$taskModel->getData($taskModel::schema_fields_RUN_TIME)) ?: microtime(true);
                $task_run_date = date('Y-m-d H:i:s');
                # 上锁
                $cron = new CronExpression($taskModel->getData('cron_time'));
                # 设置程序预计数据
                $taskModel->setData($taskModel::schema_fields_BLOCK_TIME, 0);
                $taskModel->setData($taskModel::schema_fields_RUNTIME_ERROR, '');
                $taskModel->setData($taskModel::schema_fields_NEXT_RUN_DATE, $cron->getNextRunDate()->format('Y-m-d H:i:s'));
                $taskModel->setData($taskModel::schema_fields_MAX_NEXT_RUN_DATE, $cron->getNextRunDate('now', 3)->format('Y-m-d H:i:s'));
                $taskModel->setData($taskModel::schema_fields_PRE_RUN_DATE, $cron->getPreviousRunDate()->format('Y-m-d H:i:s'));
                # ----------优先使用已记录 PID 检测，避免 Windows 每个任务都全表扫描进程---------------

                $storedPid = (int) ($taskModel->getData($taskModel::schema_fields_PID) ?: 0);
                $pid = $this->resolveRunningPid($taskModel, $process_name, (bool) $force, $storedPid);
                if ($pid) {
                    $output = Process::getProcessOutput($process_name);
                    $taskModel->setData($taskModel::schema_fields_RUNTIME_ERROR, $output . __('进程已存在，请检查进程状态！进程名：%{1}', $process_name))
                        ->setData($taskModel::schema_fields_STATUS, CronStatus::RUNNING->value)
                        ->setData($taskModel::schema_fields_BLOCK_TIME, microtime(true) - $task_start_time)
                        ->setData($taskModel::schema_fields_PID, $pid)
                        ->save();
                    # 如果强制执行
                    if ($force) {
                        $msg = __('%{1} 程序ID:%{2} 正在运行中，当前强制执行正在杀死进程中...', [$process_name, $pid]);
                        $taskModel->setData($taskModel::schema_fields_RUNTIME_ERROR, $output . $msg)
                            ->setData($taskModel::schema_fields_BLOCK_TIME, 0)
                            ->setData($taskModel::schema_fields_STATUS, CronStatus::RUNNING->value)
                            ->save();
                        Process::killPid($pid, $process_name);
                        if (Process::isProcessRunning($pid)) {
                            $force = false;
                            $msg = __('%{1} 程序ID:%{2} 杀死失败！程序不会强制执行，请手动杀死进程后重试!', [$process_name, $pid]);
                            $taskModel->setData($taskModel::schema_fields_RUNTIME_ERROR, $msg)->save();
                        }
                    } else {
                        $msg = __('%{1} 程序ID:%{2} 正在运行中，若要强制执行，请手动杀死进程后重试!或者使用配置项’-f‘的强制执行', [$process_name, $pid]);
                        $taskModel->setData($taskModel::schema_fields_RUNTIME_ERROR, $output . $msg)->save();
                    }
                    continue;
                } elseif ($storedPid > 0) {
                    # -----------如果数据库存在PID,说明程序结束---------------
                    $pid = $storedPid;
                    $msg = __('%{1} 程序ID:%{2} 已运行完毕!', [$process_name, $pid]);
                    $taskModel->setData($taskModel::schema_fields_RUN_TIMES, (int)$taskModel->getData($taskModel::schema_fields_RUN_TIMES) + 1);
                    # 设置程序运行数据
                    $taskModel->setData($taskModel::schema_fields_BLOCK_TIME, 0);
                    $output = $msg . PHP_EOL . Process::getProcessOutput($process_name);
                    Process::unsetLogProcessFilePath($process_name);
                    $taskModel->setData($taskModel::schema_fields_RUNTIME_ERROR, $output);
                    # 解锁
                    $taskModel->setData($taskModel::schema_fields_STATUS, CronStatus::SUCCESS->value);
                    $taskModel->setData($taskModel::schema_fields_RUNTIME, microtime(true) - $taskModel->getData($taskModel::schema_fields_RUN_TIME));
                    # 运行完毕将进程ID设置为0
                    $taskModel->setData($taskModel::schema_fields_PID, 0)
                        ->save();
                    continue;
                }
                if ($force || $cron->isDue($task_run_date)) {
                    if ($force || ($taskModel->getData($taskModel::schema_fields_STATUS) !== CronStatus::BLOCK->value)) {
                        # 设置程序运行数据
                        # 上锁
                        $taskModel->setData($taskModel::schema_fields_STATUS, CronStatus::BLOCK->value);
                        $taskModel->setData($taskModel::schema_fields_RUN_TIME, $task_start_time);
                        $taskModel->setData($taskModel::schema_fields_RUN_DATE, $task_run_date);
                        # 创建异步程序
                        $pid = Process::create($process_name);
                        if (!$pid) {
                            $taskModel->setData($taskModel::schema_fields_RUNTIME_ERROR, __('进程创建失败！请检查进程状态！'))
                                ->setData($taskModel::schema_fields_STATUS, CronStatus::FAIL->value)
                                ->save();
                        } else {
                            # 记录PID
                            $taskModel->setData($taskModel::schema_fields_PID, $pid)
                                ->save();
                        }
                    } else {
                        # 到了程序下次运行的时间，但是程序仍然处于block阻塞状态，设置程序运行阻塞数据
                        $taskModel->setData($taskModel::schema_fields_BLOCK_TIME, microtime(true) - $task_start_time);
                        if ($block_time = $taskModel->getData($taskModel::schema_fields_BLOCK_TIME)) {
                            if ($block_time > ($taskModel->getData($taskModel::schema_fields_BLOCK_UNLOCK_TIMEOUT) * 60)) {
                                $taskModel->setData($taskModel::schema_fields_BLOCK_TIMES, (int)$taskModel->getData($taskModel::schema_fields_BLOCK_TIMES) + 1);
                                $taskModel->setData($taskModel::schema_fields_STATUS, CronStatus::PENDING->value);
                                $taskModel->setData($taskModel::schema_fields_RUNTIME_ERROR_DATE, date('Y-m-d H:i:s'));
                                $taskModel->setData($taskModel::schema_fields_RUNTIME_ERROR, '任务调度系统：调度任务阻塞超时自动解锁，请查看任务调度设置是否合理！');
                            }
                        }
                    }
                } else {
                    $taskModel->setData($taskModel::schema_fields_STATUS, CronStatus::PENDING->value)->save();
                }
        }

    }

    private function resolveRunningPid(CronTask $taskModel, string $processName, bool $force, int $storedPid): int
    {
        if ($storedPid > 0) {
            return Process::isProcessRunning($storedPid) ? $storedPid : 0;
        }

        $status = (string) ($taskModel->getData(CronTask::schema_fields_STATUS) ?? '');
        if (!$force && !\in_array($status, [CronStatus::BLOCK->value, CronStatus::RUNNING->value], true)) {
            return 0;
        }

        return Process::getPidByName($processName);
    }

    /**
     * @param array<int, string> $taskNames
     * @return array<int, CronTask>
     */
    private function loadTaskSnapshot(array $taskNames): array
    {
        if ($taskNames) {
            $tasks = [];
            foreach (\array_values(\array_unique($taskNames)) as $taskName) {
                /** @var CronTask $task */
                $task = ObjectManager::make(CronTask::class)->reset()
                    ->where(CronTask::schema_fields_EXECUTE_NAME, $taskName)
                    ->find()
                    ->fetch();
                if ($task->getId()) {
                    $tasks[] = $task;
                }
            }

            return $tasks;
        }

        /** @var CronTask $task */
        $task = ObjectManager::make(CronTask::class);

        return $task->reset()
            ->order(CronTask::schema_fields_ID, 'asc')
            ->select()
            ->fetch()
            ->getItems();
    }

    private function flushCronCliStreams(): void
    {
        if (\function_exists('fflush')) {
            if (\defined('STDOUT')) {
                @\fflush(\STDOUT);
            }
            if (\defined('STDERR')) {
                @\fflush(\STDERR);
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

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            []
        );
    }
}
