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
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Cron\Helper\CronStatus;
use Weline\Cron\Helper\Process;
use Weline\Cron\Model\CronTask;
use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandResult;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Phrase\DatabaseFreeTranslator;
use Weline\Framework\Setup\Lock\SetupDatabaseAccessLock;
use Weline\Framework\System\OS\Win;


class Run implements CommandInterface
{
    private const CHILD_GATE_HANDOFF_TIMEOUT_MS = 30_000;
    private const CHILD_GATE_DECISION_TIMEOUT_MS = 15_000;

    /**
     * Lazily resolved only after the command owns the shared database gate.
     */
    private ?CronTask $cronTask = null;
    private Printing $printing;

    public function __construct(Printing $printing)
    {
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $bootstrapLease = SetupDatabaseAccessLock::borrowCliBootstrapSharedLease();
        $databaseAccessLock = $bootstrapLease ?? new SetupDatabaseAccessLock();
        if ($bootstrapLease === null && !$databaseAccessLock->acquireShared()) {
            $this->printing->note(DatabaseFreeTranslator::translate(
                '系统升级正在执行，本次计划任务已跳过且未访问数据库。',
                'Weline_Cron',
            ));
            $explicitRun = (bool)($args['p'] ?? $args['process'] ?? $args['f'] ?? $args['force'] ?? false);
            return CommandResult::shortCircuit($explicitRun ? 75 : 0);
        }

        try {
            $handoffToken = $this->extractSetupGateHandoffToken($args);
            if ($handoffToken !== null) {
                $managedLaunch = $this->extractManagedLaunch($args);
                if ($managedLaunch === null || !hash_equals($managedLaunch['launch_id'], $handoffToken)) {
                    throw new \InvalidArgumentException('Managed Cron child launch and handoff generations do not match.');
                }
                $stableName = $args['name'] ?? null;
                if (!is_string($stableName)) {
                    throw new \InvalidArgumentException('Managed Cron child is missing its stable process name.');
                }
                Process::registerManagedLaunchShutdown(
                    (int)(getmypid() ?: 0),
                    (string)($args['process'] ?? ''),
                    strtolower(trim($stableName)),
                    $handoffToken,
                );
                if (!$databaseAccessLock->publishSharedHandoff($handoffToken)) {
                    $this->markManagedChildFailureFromArgs(
                        $args,
                        (string)__('计划任务子进程无法发布数据库共享锁就绪标记。'),
                    );
                    $databaseAccessLock->release();
                    return CommandResult::shortCircuit(75);
                }
                $decision = SetupDatabaseAccessLock::waitForSharedHandoffDecision(
                    $handoffToken,
                    self::CHILD_GATE_DECISION_TIMEOUT_MS,
                );
                if ($decision !== true) {
                    $message = $decision === false
                        ? (string)__('计划任务子进程的本次启动已被调度父进程取消。')
                        : (string)__('计划任务子进程未在 %{1} 毫秒内收到调度决定。', [self::CHILD_GATE_DECISION_TIMEOUT_MS]);
                    $this->markManagedChildFailureFromArgs($args, $message);
                    SetupDatabaseAccessLock::cleanupSharedHandoff($handoffToken);
                    $databaseAccessLock->release();
                    return CommandResult::shortCircuit(75);
                }
            }
            $this->executeWithDatabaseAccess($args, $data);

            // Cli owns post-command events and the footer. Transfer release to
            // an explicit result so those potentially database-backed steps
            // remain inside the same shared lease.
            return CommandResult::deferFinalizer([$databaseAccessLock, 'release']);
        } catch (\Throwable $throwable) {
            $databaseAccessLock->release();
            throw $throwable;
        }
    }

    private function executeWithDatabaseAccess(array $args = [], array $data = [])
    {
        $managedLaunch = $this->extractManagedLaunch($args);
        /** @var CronTask $cronTask */
        $cronTask = ObjectManager::getInstance(CronTask::class);
        $this->cronTask = $cronTask;
        $serializeManagedChildren = self::requiresManagedChildSerialization(
            (string)$cronTask->getConnection()
                ->getConnector()
                ->getConfigProvider()
                ->getDbType(),
        );
        $force = $args['f'] ?? $args['force'] ?? false;
        $process = $args['p'] ?? $args['process'] ?? false;
        $manualSse = ($manualValue = \getenv('WELINE_CRON_MANUAL_SSE')) !== false
            && $manualValue !== ''
            && $manualValue !== '0';
        $manualLaunchCompleted = false;
        if ($process && $managedLaunch === null) {
            throw new \InvalidArgumentException('Cron process mode requires a managed launch fence.');
        }
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
                return;
            }
        }
        # 只有经父调度器 READY/CAS/GO 放行的受管子进程才直接执行业务。
        if ($process && count($task_names) === 1) {
            $requestedExecuteName = (string)array_shift($task_names);
            /**@var CronTask $task */
            $task = ObjectManager::make(CronTask::class)->reset()
                ->where(CronTask::schema_fields_ID, $managedLaunch['task_id'])
                ->find()
                ->fetch();
            if (!$task->getId()) {
                ObjectManager::getInstance(Printing::class)->error(__('指执行的任务不存在！'));
                try {
                    $this->markManagedChildFailure(
                        $managedLaunch,
                        (string)__('计划任务子进程对应的调度记录不存在。'),
                    );
                } catch (\Throwable) {
                    // The missing task is the primary failure.
                }
                return;
            }
            $executeName = (string) ($task->getData(CronTask::schema_fields_EXECUTE_NAME) ?? '');
            if (!hash_equals($executeName, $requestedExecuteName)) {
                $this->markManagedChildFailure(
                    $managedLaunch,
                    (string)__('计划任务子进程的任务身份与调度记录不匹配。'),
                );
                throw new \RuntimeException('Cron managed child execute-name fence mismatch.');
            }
            try {
                $this->assertManagedLaunchOwnsTask($task, $managedLaunch);
                $class = (string) ($task->getData(CronTask::schema_fields_CLASS) ?? '');
            if ($class === '' || !class_exists($class)) {
                throw new \RuntimeException((string)__(
                    '计划任务 %{1} 的实现已失效，请先执行 php bin/w cron:task:collect 重建编译任务索引。',
                    [$executeName],
                ));
            }
            /**@var CronTaskInterface $instance */
            $instance = ObjectManager::getInstance($class);
            $sseManual = $manualSse;
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
                if (!$this->completeManagedChildSuccess($task, $managedLaunch)) {
                    throw new \RuntimeException('Cron managed child success fence was lost.');
                }
            } catch (\Throwable $throwable) {
                try {
                    $this->markManagedChildFailure(
                        $managedLaunch,
                        (string)__('计划任务子进程执行异常：%{1}', [$throwable->getMessage()]),
                    );
                } catch (\Throwable) {
                    // Preserve the original task exception.
                }
                throw $throwable;
            }
            return;
        }
        $tasks = $this->loadTaskSnapshot($task_names);
        $taskTotal = \count($tasks);
        if ($taskTotal == 0) {
            ObjectManager::getInstance(Printing::class)->error(__('没有要执行的任务：%{1} , 参数：', [implode(' ', $task_names), implode(' ', $args)]));
            return;
        }

        # 进程信息管理
        /**@var CronTask $taskModel */
        foreach ($tasks as $key => $taskModel) {
                $forceTask = (bool)$force;
                $currentTotal = $key + 1;
                CronStatus::displayProgressBar(__('任务进度：页(%{1}=>%{2})/目(%{3}/%{4})', [$taskTotal, $currentTotal, $taskTotal, $currentTotal]), $currentTotal,
                    $taskTotal, false);
                $execute_name = (string)$taskModel->getData($taskModel::schema_fields_EXECUTE_NAME);
                $process_name = Process::stableProcessIdentity($execute_name);
                $command_file = BP . 'bin' . DIRECTORY_SEPARATOR . 'w';
                $task_start_time = (float)$taskModel->getData($taskModel::schema_fields_RUN_TIME);
                $task_start_time = $task_start_time > 0 ? $task_start_time : microtime(true);
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

                $taskFence = $this->taskFence($taskModel);
                $storedPid = (int) ($taskModel->getData($taskModel::schema_fields_PID) ?: 0);
                $storedLaunchId = strtolower(trim((string)$taskModel->getData($taskModel::schema_fields_LAUNCH_ID)));
                $storedRunStart = trim((string)$taskModel->getData($taskModel::schema_fields_RUN_TIME));
                $storedStatus = (string)$taskModel->getData(CronTask::schema_fields_STATUS);
                if ($forceTask
                    && $storedPid === 0
                    && $storedStatus === CronStatus::BLOCK->value
                    && preg_match('/^[0-9a-f]{32}$/D', $storedLaunchId) === 1
                ) {
                    $this->updateTaskIf($taskFence, [
                        CronTask::schema_fields_RUNTIME_ERROR => (string)__ (
                            '计划任务正处于 READY 启动窗口，本次强制执行未抢占当前代次。',
                        ),
                    ]);
                    continue;
                }
                $processProbe = $storedPid > 0
                    ? Process::probeManagedCronProcess(
                        $storedPid,
                        $execute_name,
                        $storedLaunchId,
                        $storedRunStart,
                    ) + ['pid' => $storedPid]
                    : $this->resolveRunningProcessProbe(
                        $taskModel,
                        $process_name,
                        $storedLaunchId,
                        $storedRunStart,
                    );
                if ($processProbe !== null && $processProbe['unknown']) {
                    $unknownPid = (int)($processProbe['pid'] ?? $storedPid);
                    $this->updateTaskIf($taskFence, [
                        CronTask::schema_fields_RUNTIME_ERROR => (string)__ (
                            '计划任务 PID %{1} 的进程身份暂时无法确认，本轮保留 PID 并跳过。',
                            [$unknownPid],
                        ),
                    ]);
                    continue;
                }
                if ($storedPid === 0
                    && (string)$taskModel->getData(CronTask::schema_fields_STATUS) === CronStatus::RUNNING->value
                    && $processProbe === null
                ) {
                    $this->updateTaskIf($taskFence, [
                        CronTask::schema_fields_RUNTIME_ERROR => (string)__ (
                            '计划任务处于运行状态但缺少可验证 PID，本轮保留代次并跳过。',
                        ),
                    ]);
                    continue;
                }
                $pid = $processProbe !== null && $processProbe['running']
                    ? (int)($processProbe['pid'] ?? 0)
                    : 0;
                if ($pid) {
                    $output = Process::getProcessOutput($execute_name);
                    if (!$this->updateTaskIf($taskFence, [
                        CronTask::schema_fields_RUNTIME_ERROR => $output . __('进程已存在，请检查进程状态！进程名：%{1}', $process_name),
                        CronTask::schema_fields_STATUS => CronStatus::RUNNING->value,
                        CronTask::schema_fields_BLOCK_TIME => microtime(true) - $task_start_time,
                        CronTask::schema_fields_PID => $pid,
                    ])) {
                        continue;
                    }
                    $runningFence = [
                        CronTask::schema_fields_ID => (int)$taskModel->getId(),
                        CronTask::schema_fields_RUN_TIME => $taskFence[CronTask::schema_fields_RUN_TIME],
                        CronTask::schema_fields_LAUNCH_ID => $taskFence[CronTask::schema_fields_LAUNCH_ID],
                        CronTask::schema_fields_STATUS => CronStatus::RUNNING->value,
                        CronTask::schema_fields_PID => $pid,
                    ];
                    # 如果强制执行
                    if ($forceTask) {
                        $msg = __('%{1} 程序ID:%{2} 正在运行中，当前强制执行正在杀死进程中...', [$process_name, $pid]);
                        if (!$this->updateTaskIf($runningFence, [
                            CronTask::schema_fields_RUNTIME_ERROR => $output . $msg,
                            CronTask::schema_fields_BLOCK_TIME => 0,
                        ])) {
                            continue;
                        }
                        $termination = Process::terminateTrackedCronProcess(
                            $pid,
                            $execute_name,
                            $storedLaunchId,
                            $storedRunStart,
                        );
                        if (empty($termination['released'])) {
                            $forceTask = false;
                            $msg = __('%{1} 程序ID:%{2} 杀死失败！程序不会强制执行，请手动杀死进程后重试!', [$process_name, $pid]);
                            $this->updateTaskIf($runningFence, [
                                CronTask::schema_fields_RUNTIME_ERROR => $msg . ' '
                                    . (string)($termination['reason'] ?? 'termination_unknown'),
                            ]);
                        } else {
                            $released = $this->updateTaskIf($runningFence, [
                                CronTask::schema_fields_STATUS => CronStatus::FAIL->value,
                                CronTask::schema_fields_PID => 0,
                                CronTask::schema_fields_LAUNCH_ID => '',
                                CronTask::schema_fields_BLOCK_TIME => 0,
                                CronTask::schema_fields_RUNTIME_ERROR_DATE => date('Y-m-d H:i:s'),
                                CronTask::schema_fields_RUNTIME_ERROR => (string)__ (
                                    '计划任务进程已被强制终止。进程ID：%{1}',
                                    [$pid],
                                ),
                            ]);
                            if (!$released) {
                                continue;
                            }
                            $taskModel->setData(CronTask::schema_fields_STATUS, CronStatus::FAIL->value);
                            $taskModel->setData(CronTask::schema_fields_PID, 0);
                            $taskModel->setData(CronTask::schema_fields_LAUNCH_ID, '');
                        }
                    } else {
                        $msg = __('%{1} 程序ID:%{2} 正在运行中，若要强制执行，请手动杀死进程后重试!或者使用配置项’-f‘的强制执行', [$process_name, $pid]);
                        $this->updateTaskIf($runningFence, [
                            CronTask::schema_fields_RUNTIME_ERROR => $output . $msg,
                        ]);
                    }
                    if (!$forceTask || empty($termination['released'])) {
                        continue;
                    }
                } elseif ($storedPid > 0) {
                    $status = (string)$taskModel->getData($taskModel::schema_fields_STATUS);
                    $output = (string)(Process::getProcessOutput($execute_name) ?: '');
                    $updates = [
                        CronTask::schema_fields_PID => 0,
                        CronTask::schema_fields_LAUNCH_ID => '',
                        CronTask::schema_fields_BLOCK_TIME => 0,
                    ];
                    if (!\in_array($status, [CronStatus::SUCCESS->value, CronStatus::FAIL->value], true)) {
                        $message = (string)__('计划任务进程已退出但未提交成功状态，已标记失败。进程ID：%{1}', [$storedPid]);
                        $updates[CronTask::schema_fields_STATUS] = CronStatus::FAIL->value;
                        $updates[CronTask::schema_fields_RUNTIME_ERROR_DATE] = date('Y-m-d H:i:s');
                        $updates[CronTask::schema_fields_RUNTIME_ERROR] = trim($output . PHP_EOL . $message);
                    }
                    if (!$this->updateTaskIf($taskFence, $updates)) {
                        continue;
                    }
                    $taskModel->setData(
                        CronTask::schema_fields_STATUS,
                        $updates[CronTask::schema_fields_STATUS] ?? $status,
                    );
                    $taskModel->setData(CronTask::schema_fields_PID, 0);
                    $taskModel->setData(CronTask::schema_fields_LAUNCH_ID, '');
                    if (!$forceTask) {
                        continue;
                    }
                }
                if ($forceTask || $cron->isDue($task_run_date)) {
                    if ($forceTask || ($taskModel->getData($taskModel::schema_fields_STATUS) !== CronStatus::BLOCK->value)) {
                        $runStart = sprintf('%.6F', microtime(true));
                        $handoffToken = SetupDatabaseAccessLock::newSharedHandoffToken();
                        if (!$this->claimTaskLaunch(
                            $taskModel,
                            $runStart,
                            $task_run_date,
                            $handoffToken,
                        )) {
                            continue;
                        }

                        $pid = 0;
                        $readyPid = 0;
                        $pidStored = false;
                        $goPublished = false;
                        try {
                        $childArgv = [
                            PHP_BINARY,
                            $command_file,
                            'cron:task:run',
                            '-process',
                            $execute_name,
                            '--cron-task-id=' . (int)$taskModel->getId(),
                            '--cron-run-start=' . $runStart,
                            '--setup-gate-handoff=' . $handoffToken,
                            $process_name,
                            '--launch-id=' . $handoffToken,
                        ];
                        if ($forceTask) {
                            $childArgv[] = '-force';
                        }

                        try {
                            $pid = Process::createDetachedPhpArgv($childArgv, $execute_name, $handoffToken);
                        } catch (\Throwable $throwable) {
                            SetupDatabaseAccessLock::publishSharedHandoffDecision($handoffToken, false);
                            SetupDatabaseAccessLock::cleanupSharedHandoff($handoffToken);
                            $this->markLaunchFailure(
                                (int)$taskModel->getId(),
                                $runStart,
                                $handoffToken,
                                0,
                                0,
                                (string)__('进程创建失败！请检查进程状态！%{1}', [$throwable->getMessage()]),
                            );
                            continue;
                        }

                        $readyPid = SetupDatabaseAccessLock::waitForSharedHandoff(
                            $handoffToken,
                            self::CHILD_GATE_HANDOFF_TIMEOUT_MS,
                        );
                        if ($readyPid < 1 || $readyPid !== $pid) {
                            $termination = $this->abortManagedLaunch(
                                $handoffToken,
                                $execute_name,
                                $runStart,
                                [$pid, $readyPid],
                            );
                            $message = $readyPid < 1
                                ? (string)__('计划任务子进程未在 %{1} 毫秒内接管数据库共享锁。', [self::CHILD_GATE_HANDOFF_TIMEOUT_MS])
                                : (string)__('计划任务子进程 PID 与启动器返回值不一致：%{1}/%{2}。', [$readyPid, $pid]);
                            $this->markLaunchFailure(
                                (int)$taskModel->getId(),
                                $runStart,
                                $handoffToken,
                                0,
                                $termination['released'] ? 0 : (int)$termination['pid'],
                                $message . ' ' . (string)$termination['reason'],
                            );
                            continue;
                        }

                        $pidStored = $this->updateTaskIf([
                            CronTask::schema_fields_ID => (int)$taskModel->getId(),
                            CronTask::schema_fields_RUN_TIME => $runStart,
                            CronTask::schema_fields_LAUNCH_ID => $handoffToken,
                            CronTask::schema_fields_STATUS => CronStatus::BLOCK->value,
                            CronTask::schema_fields_PID => 0,
                        ], [
                            CronTask::schema_fields_STATUS => CronStatus::RUNNING->value,
                            CronTask::schema_fields_PID => $readyPid,
                        ]);
                        if (!$pidStored) {
                            $termination = $this->abortManagedLaunch(
                                $handoffToken,
                                $execute_name,
                                $runStart,
                                [$readyPid],
                            );
                            $this->markLaunchFailure(
                                (int)$taskModel->getId(),
                                $runStart,
                                $handoffToken,
                                0,
                                $termination['released'] ? 0 : (int)$termination['pid'],
                                (string)__('计划任务父进程丢失 PID 写入围栏，已取消本次启动。') . ' '
                                . (string)$termination['reason'],
                            );
                            continue;
                        }
                        if (!SetupDatabaseAccessLock::publishSharedHandoffDecision($handoffToken, true)) {
                            $termination = $this->abortManagedLaunch(
                                $handoffToken,
                                $execute_name,
                                $runStart,
                                [$readyPid],
                            );
                            $this->markLaunchFailure(
                                (int)$taskModel->getId(),
                                $runStart,
                                $handoffToken,
                                $readyPid,
                                $termination['released'] ? 0 : (int)$termination['pid'],
                                (string)__('计划任务父进程无法发布执行决定。') . ' ' . (string)$termination['reason'],
                            );
                        } else {
                            $goPublished = true;
                            if ($manualSse) {
                                $this->waitForManagedChildCompletionAndRelay(
                                    (int)$taskModel->getId(),
                                    $execute_name,
                                    $runStart,
                                    $handoffToken,
                                );
                                $manualLaunchCompleted = true;
                            } elseif ($serializeManagedChildren) {
                                $this->waitForManagedChildCompletion(
                                    (int)$taskModel->getId(),
                                    $execute_name,
                                    $runStart,
                                    $handoffToken,
                                    false,
                                    false,
                                );
                            }
                        }
                        } catch (\Throwable $throwable) {
                            if (!$goPublished) {
                                $termination = ['released' => true, 'pid' => 0, 'reason' => 'launch_exception_before_spawn'];
                                if (is_string($handoffToken) && $handoffToken !== '') {
                                    try {
                                        if ($pid > 0 || $readyPid > 0) {
                                            $termination = $this->abortManagedLaunch(
                                                $handoffToken,
                                                $execute_name,
                                                $runStart,
                                                [$pid, $readyPid],
                                            );
                                        } else {
                                            SetupDatabaseAccessLock::cleanupSharedHandoff($handoffToken);
                                        }
                                    } catch (\Throwable) {
                                        $termination = [
                                            'released' => false,
                                            'pid' => $readyPid > 0 ? $readyPid : $pid,
                                            'reason' => 'launch_exception_cleanup_failed',
                                        ];
                                    }
                                }
                                try {
                                    $this->markLaunchFailure(
                                        (int)$taskModel->getId(),
                                        $runStart,
                                        $handoffToken,
                                        $pidStored ? $readyPid : 0,
                                        $termination['released'] ? 0 : (int)$termination['pid'],
                                        (string)__('计划任务启动协议异常：%{1}', [$throwable->getMessage()]) . ' '
                                        . (string)$termination['reason'],
                                    );
                                } catch (\Throwable) {
                                    // Preserve the original launch/DB exception.
                                }
                            }
                            throw $throwable;
                        }
                    } else {
                        # 到了程序下次运行的时间，但是程序仍然处于block阻塞状态，设置程序运行阻塞数据
                        $block_time = microtime(true) - $task_start_time;
                        if ($block_time > 0) {
                            if ($block_time > ($taskModel->getData($taskModel::schema_fields_BLOCK_UNLOCK_TIMEOUT) * 60)) {
                                $this->updateTaskIf($taskFence, [
                                    CronTask::schema_fields_BLOCK_TIME => $block_time,
                                    CronTask::schema_fields_BLOCK_TIMES => (int)$taskModel->getData($taskModel::schema_fields_BLOCK_TIMES) + 1,
                                    CronTask::schema_fields_STATUS => CronStatus::PENDING->value,
                                    CronTask::schema_fields_PID => 0,
                                    CronTask::schema_fields_LAUNCH_ID => '',
                                    CronTask::schema_fields_RUNTIME_ERROR_DATE => date('Y-m-d H:i:s'),
                                    CronTask::schema_fields_RUNTIME_ERROR => '任务调度系统：调度任务阻塞超时自动解锁，请查看任务调度设置是否合理！',
                                ]);
                            }
                        }
                    }
                } else {
                    $this->updateTaskIf($taskFence, [
                        CronTask::schema_fields_STATUS => CronStatus::PENDING->value,
                        CronTask::schema_fields_LAUNCH_ID => '',
                        CronTask::schema_fields_NEXT_RUN_DATE => $taskModel->getData(CronTask::schema_fields_NEXT_RUN_DATE),
                        CronTask::schema_fields_MAX_NEXT_RUN_DATE => $taskModel->getData(CronTask::schema_fields_MAX_NEXT_RUN_DATE),
                        CronTask::schema_fields_PRE_RUN_DATE => $taskModel->getData(CronTask::schema_fields_PRE_RUN_DATE),
                    ]);
                }
        }

        if ($manualSse && !$process && !$manualLaunchCompleted) {
            throw new \RuntimeException((string)__ (
                '后台手动运行未取得新的受管任务代次，请查看调度状态后重试。',
            ));
        }

    }

    private function waitForManagedChildCompletionAndRelay(
        int $taskId,
        string $executeName,
        string $runStart,
        string $launchId,
    ): void {
        $this->waitForManagedChildCompletion(
            $taskId,
            $executeName,
            $runStart,
            $launchId,
            true,
            true,
        );
    }

    private function waitForManagedChildCompletion(
        int $taskId,
        string $executeName,
        string $runStart,
        string $launchId,
        bool $relayLogs,
        bool $throwOnFailure,
    ): bool {
        $logPath = $relayLogs ? Process::getManagedLogProcessFilePath($executeName) : '';
        $offset = 0;
        if ($relayLogs) {
            $this->printing->note((string)__ (
                '受管任务已启动，正在等待本代任务完成并转发运行日志：%{1}',
                [$executeName],
            ));
            $this->flushCronCliStreams();
        }
        $observedPid = 0;

        while (true) {
            /** @var CronTask $task */
            $task = ObjectManager::make(CronTask::class)->reset()
                ->where(CronTask::schema_fields_ID, $taskId)
                ->find()
                ->fetch();
            if (!$task->getId()) {
                throw new \RuntimeException((string)__('受管计划任务记录已不存在。'));
            }

            $currentRunStart = trim((string)$task->getData(CronTask::schema_fields_RUN_TIME));
            $currentLaunchId = strtolower(trim((string)$task->getData(CronTask::schema_fields_LAUNCH_ID)));
            $status = (string)$task->getData(CronTask::schema_fields_STATUS);
            $pid = (int)$task->getData(CronTask::schema_fields_PID);
            if ($pid > 0 && $currentLaunchId !== '' && hash_equals($launchId, $currentLaunchId)) {
                $observedPid = $pid;
            }
            if (!hash_equals($runStart, $currentRunStart)
                || ($currentLaunchId !== '' && !hash_equals($launchId, $currentLaunchId))
            ) {
                throw new \RuntimeException((string)__ (
                    '受管计划任务代次已被替换，停止等待旧代次：%{1}',
                    [$executeName],
                ));
            }

            $reaped = $pid > 0
                && $currentLaunchId !== ''
                && hash_equals($launchId, $currentLaunchId)
                && Process::reapManagedCronChildIfExited(
                    $pid,
                    $executeName,
                    $launchId,
                    $runStart,
                );
            if ($reaped && $status === CronStatus::RUNNING->value) {
                $message = (string)__ (
                    '受管计划任务子进程已退出但未提交终态，已由调度父进程标记失败。进程ID：%{1}',
                    [$pid],
                );
                $failed = $this->updateTaskIf([
                    CronTask::schema_fields_ID => $taskId,
                    CronTask::schema_fields_RUN_TIME => $runStart,
                    CronTask::schema_fields_LAUNCH_ID => $launchId,
                    CronTask::schema_fields_STATUS => CronStatus::RUNNING->value,
                    CronTask::schema_fields_PID => $pid,
                ], [
                    CronTask::schema_fields_STATUS => CronStatus::FAIL->value,
                    CronTask::schema_fields_PID => 0,
                    CronTask::schema_fields_LAUNCH_ID => '',
                    CronTask::schema_fields_RUNTIME_ERROR_DATE => date('Y-m-d H:i:s'),
                    CronTask::schema_fields_RUNTIME_ERROR => $message,
                ]);
                if (!$failed) {
                    \Weline\Framework\Runtime\SchedulerSystem::usleep(100_000);
                    continue;
                }
                if ($relayLogs) {
                    $this->relayManagedLogDelta($logPath, $offset);
                }
                if ($throwOnFailure) {
                    throw new \RuntimeException($message);
                }
                return false;
            }

            if ($relayLogs) {
                $this->relayManagedLogDelta($logPath, $offset);
            }
            if ($status === CronStatus::SUCCESS->value && $pid === 0 && $currentLaunchId === '') {
                if ($observedPid > 0) {
                    $probe = Process::probeManagedCronProcess(
                        $observedPid,
                        $executeName,
                        $launchId,
                        $runStart,
                    );
                    if (!$probe['released']) {
                        \Weline\Framework\Runtime\SchedulerSystem::usleep(100_000);
                        continue;
                    }
                }
                \Weline\Framework\Runtime\SchedulerSystem::usleep(50_000);
                if ($relayLogs) {
                    $this->relayManagedLogDelta($logPath, $offset);
                }
                return true;
            }

            if ($status === CronStatus::FAIL->value) {
                if ($pid > 0 && $currentLaunchId !== '') {
                    $probe = Process::probeManagedCronProcess(
                        $pid,
                        $executeName,
                        $launchId,
                        $runStart,
                    );
                    if ($probe['released']) {
                        $cleared = $this->updateTaskIf([
                            CronTask::schema_fields_ID => $taskId,
                            CronTask::schema_fields_RUN_TIME => $runStart,
                            CronTask::schema_fields_LAUNCH_ID => $launchId,
                            CronTask::schema_fields_STATUS => CronStatus::FAIL->value,
                            CronTask::schema_fields_PID => $pid,
                        ], [
                            CronTask::schema_fields_PID => 0,
                            CronTask::schema_fields_LAUNCH_ID => '',
                        ]);
                        if (!$cleared) {
                            \Weline\Framework\Runtime\SchedulerSystem::usleep(100_000);
                            continue;
                        }
                        $pid = 0;
                        $currentLaunchId = '';
                    }
                }
                if ($pid === 0 && $currentLaunchId === '') {
                    \Weline\Framework\Runtime\SchedulerSystem::usleep(50_000);
                    if ($relayLogs) {
                        $this->relayManagedLogDelta($logPath, $offset);
                    }
                    $failure = trim((string)$task->getData(CronTask::schema_fields_RUNTIME_ERROR));
                    if ($throwOnFailure) {
                        throw new \RuntimeException($failure !== ''
                            ? $failure
                            : (string)__('受管计划任务失败，任务未提供错误摘要。'));
                    }
                    return false;
                }
            }

            if (!in_array($status, [
                CronStatus::BLOCK->value,
                CronStatus::RUNNING->value,
                CronStatus::FAIL->value,
            ], true)) {
                throw new \RuntimeException((string)__ (
                    '等待受管计划任务时丢失状态围栏：%{1}',
                    [$status],
                ));
            }
            \Weline\Framework\Runtime\SchedulerSystem::usleep(100_000);
        }
    }

    private static function requiresManagedChildSerialization(string $databaseType): bool
    {
        return \strtolower(\trim($databaseType)) === 'sqlite';
    }

    private function relayManagedLogDelta(string $logPath, int &$offset): void
    {
        clearstatcache(true, $logPath);
        $size = is_file($logPath) ? (int)filesize($logPath) : 0;
        if ($size < $offset) {
            $offset = 0;
        }
        if ($size <= $offset) {
            return;
        }
        $stream = @fopen($logPath, 'rb');
        if (!is_resource($stream)) {
            return;
        }
        try {
            @fseek($stream, $offset);
            while ($offset < $size) {
                $chunk = (string)@fread($stream, min(65_536, $size - $offset));
                if ($chunk === '') {
                    break;
                }
                $offset += strlen($chunk);
                if (defined('STDOUT')) {
                    $written = 0;
                    $length = strlen($chunk);
                    while ($written < $length) {
                        $bytes = @fwrite(STDOUT, substr($chunk, $written));
                        if (!is_int($bytes) || $bytes < 1) {
                            break 2;
                        }
                        $written += $bytes;
                    }
                } else {
                    echo $chunk;
                }
            }
        } finally {
            fclose($stream);
        }
        $this->flushCronCliStreams();
    }

    /** @return array{task_id:int,run_start:string,launch_id:string}|null */
    private function extractManagedLaunch(array $args): ?array
    {
        $taskId = $args['cron-task-id'] ?? null;
        $runStart = $args['cron-run-start'] ?? null;
        $launchId = $args['launch-id'] ?? null;
        if ($taskId === null && $runStart === null && $launchId === null) {
            return null;
        }
        if ((!is_int($taskId) && !is_string($taskId))
            || !ctype_digit((string)$taskId)
            || (int)$taskId < 1
            || !is_string($runStart)
            || preg_match('/^[0-9]{10,12}\.[0-9]{6}$/D', $runStart) !== 1
            || !is_string($launchId)
            || preg_match('/^[0-9a-f]{32}$/D', strtolower(trim($launchId))) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid Cron managed child launch fence.');
        }

        return [
            'task_id' => (int)$taskId,
            'run_start' => $runStart,
            'launch_id' => strtolower(trim($launchId)),
        ];
    }

    /** @param array{task_id:int,run_start:string,launch_id:string} $launch */
    private function assertManagedLaunchOwnsTask(CronTask $task, array $launch): void
    {
        $matches = (int)$task->getId() === $launch['task_id']
            && hash_equals((string)$task->getData(CronTask::schema_fields_RUN_TIME), $launch['run_start'])
            && hash_equals((string)$task->getData(CronTask::schema_fields_LAUNCH_ID), $launch['launch_id'])
            && (string)$task->getData(CronTask::schema_fields_STATUS) === CronStatus::RUNNING->value
            && (int)$task->getData(CronTask::schema_fields_PID) === (int)(getmypid() ?: 0);
        if (!$matches) {
            throw new \RuntimeException('Cron managed child no longer owns the launch fence.');
        }
    }

    /** @param array{task_id:int,run_start:string,launch_id:string} $launch */
    private function completeManagedChildSuccess(CronTask $task, array $launch): bool
    {
        return $this->updateTaskIf([
            CronTask::schema_fields_ID => $launch['task_id'],
            CronTask::schema_fields_RUN_TIME => $launch['run_start'],
            CronTask::schema_fields_LAUNCH_ID => $launch['launch_id'],
            CronTask::schema_fields_STATUS => CronStatus::RUNNING->value,
            CronTask::schema_fields_PID => (int)(getmypid() ?: 0),
        ], [
            CronTask::schema_fields_RUN_TIMES => (int)$task->getData(CronTask::schema_fields_RUN_TIMES) + 1,
            CronTask::schema_fields_BLOCK_TIME => 0,
            CronTask::schema_fields_STATUS => CronStatus::SUCCESS->value,
            CronTask::schema_fields_RUNTIME => microtime(true) - (float)$launch['run_start'],
            CronTask::schema_fields_RUNTIME_ERROR => '',
            CronTask::schema_fields_PID => 0,
            CronTask::schema_fields_LAUNCH_ID => '',
        ]);
    }

    private function markManagedChildFailureFromArgs(array $args, string $message): void
    {
        $launch = $this->extractManagedLaunch($args);
        if ($launch !== null) {
            $this->markManagedChildFailure($launch, $message);
        }
    }

    /** @param array{task_id:int,run_start:string,launch_id:string} $launch */
    private function markManagedChildFailure(array $launch, string $message): void
    {
        $pid = (int)(getmypid() ?: 0);
        if ($pid > 0 && $this->markLaunchFailure(
            $launch['task_id'],
            $launch['run_start'],
            $launch['launch_id'],
            $pid,
            $pid,
            $message,
        )) {
            return;
        }
        $this->markLaunchFailure(
            $launch['task_id'],
            $launch['run_start'],
            $launch['launch_id'],
            0,
            $pid,
            $message,
        );
    }

    private function claimTaskLaunch(
        CronTask $task,
        string $runStart,
        string $runDate,
        string $launchId,
    ): bool {
        return $this->updateTaskIf([
            CronTask::schema_fields_ID => (int)$task->getId(),
            CronTask::schema_fields_STATUS => $task->getData(CronTask::schema_fields_STATUS),
            CronTask::schema_fields_RUN_TIME => $task->getData(CronTask::schema_fields_RUN_TIME),
            CronTask::schema_fields_LAUNCH_ID => $task->getData(CronTask::schema_fields_LAUNCH_ID),
            CronTask::schema_fields_PID => $task->getData(CronTask::schema_fields_PID),
        ], [
            CronTask::schema_fields_STATUS => CronStatus::BLOCK->value,
            CronTask::schema_fields_PID => 0,
            CronTask::schema_fields_RUN_TIME => $runStart,
            CronTask::schema_fields_LAUNCH_ID => $launchId,
            CronTask::schema_fields_RUN_DATE => $runDate,
            CronTask::schema_fields_BLOCK_TIME => 0,
            CronTask::schema_fields_RUNTIME_ERROR => '',
            CronTask::schema_fields_NEXT_RUN_DATE => $task->getData(CronTask::schema_fields_NEXT_RUN_DATE),
            CronTask::schema_fields_MAX_NEXT_RUN_DATE => $task->getData(CronTask::schema_fields_MAX_NEXT_RUN_DATE),
            CronTask::schema_fields_PRE_RUN_DATE => $task->getData(CronTask::schema_fields_PRE_RUN_DATE),
        ]);
    }

    /** @return array<string,mixed> */
    private function taskFence(CronTask $task): array
    {
        return [
            CronTask::schema_fields_ID => (int)$task->getId(),
            CronTask::schema_fields_RUN_TIME => $task->getData(CronTask::schema_fields_RUN_TIME),
            CronTask::schema_fields_LAUNCH_ID => $task->getData(CronTask::schema_fields_LAUNCH_ID),
            CronTask::schema_fields_STATUS => $task->getData(CronTask::schema_fields_STATUS),
            CronTask::schema_fields_PID => $task->getData(CronTask::schema_fields_PID),
        ];
    }

    private function markLaunchFailure(
        int $taskId,
        string $runStart,
        string $launchId,
        int $expectedPid,
        int $retainedPid,
        string $message,
    ): bool {
        $expectedPid = max(0, $expectedPid);
        $retainedPid = max(0, $retainedPid);
        return $this->updateTaskIf([
            CronTask::schema_fields_ID => $taskId,
            CronTask::schema_fields_RUN_TIME => $runStart,
            CronTask::schema_fields_LAUNCH_ID => $launchId,
            CronTask::schema_fields_STATUS => $expectedPid > 0
                ? CronStatus::RUNNING->value
                : CronStatus::BLOCK->value,
            CronTask::schema_fields_PID => $expectedPid,
        ], [
            CronTask::schema_fields_STATUS => CronStatus::FAIL->value,
            CronTask::schema_fields_PID => $retainedPid,
            CronTask::schema_fields_LAUNCH_ID => $retainedPid > 0 ? $launchId : '',
            CronTask::schema_fields_BLOCK_TIME => 0,
            CronTask::schema_fields_RUNTIME => max(0, microtime(true) - (float)$runStart),
            CronTask::schema_fields_RUNTIME_ERROR_DATE => date('Y-m-d H:i:s'),
            CronTask::schema_fields_RUNTIME_ERROR => $message,
        ]);
    }

    /**
     * @param list<int> $candidatePids
     * @return array{released:bool,pid:int,reason:string}
     */
    private function abortManagedLaunch(
        string $handoffToken,
        string $executeName,
        string $runStart,
        array $candidatePids,
    ): array {
        $decisionPublished = SetupDatabaseAccessLock::publishSharedHandoffDecision($handoffToken, false);
        $released = true;
        $unreleasedPid = 0;
        $reasons = $decisionPublished ? [] : ['abort_decision_unavailable'];
        foreach (array_values(array_unique(array_filter(
            array_map('intval', $candidatePids),
            static fn(int $pid): bool => $pid > 0,
        ))) as $pid) {
            $termination = Process::terminateManagedLaunch(
                $pid,
                $executeName,
                $handoffToken,
                $runStart,
            );
            $reasons[] = (string)($termination['reason'] ?? 'termination_unknown');
            if (empty($termination['released'])) {
                $released = false;
                $unreleasedPid = $pid;
            }
        }
        if ($released) {
            SetupDatabaseAccessLock::cleanupSharedHandoff($handoffToken);
        }

        return [
            'released' => $released,
            'pid' => $unreleasedPid,
            'reason' => implode(',', array_values(array_unique($reasons))),
        ];
    }

    /**
     * @param array<string,mixed> $expected
     * @param array<string,mixed> $updates
     */
    private function updateTaskIf(array $expected, array $updates): bool
    {
        /** @var CronTask $query */
        $query = ObjectManager::make(CronTask::class)->reset();
        foreach ($expected as $field => $value) {
            if (is_array($value) && array_key_exists('in', $value)) {
                $query->where((string)$field, (array)$value['in'], 'in');
                continue;
            }
            $query->where((string)$field, $value);
        }
        $result = $query->getQuery()->update($updates)->fetch();
        if (is_int($result) && $result > 1) {
            throw new \RuntimeException('Cron fenced update unexpectedly affected multiple tasks.');
        }
        if ($result === true || (is_int($result) && $result === 1)) {
            return true;
        }

        // SQLite may execute UPDATE successfully while exposing an empty result
        // set as false. Accept that ambiguous adapter value only when the exact
        // task postimage contains every requested update.
        $taskId = (int)($expected[CronTask::schema_fields_ID] ?? 0);
        if ($taskId < 1 || $updates === []) {
            return false;
        }
        /** @var CronTask $fresh */
        $fresh = ObjectManager::make(CronTask::class)->reset()
            ->where(CronTask::schema_fields_ID, $taskId)
            ->find()
            ->fetch();
        if ((int)$fresh->getId() !== $taskId) {
            return false;
        }
        foreach ($updates as $field => $value) {
            $actual = $fresh->getData((string)$field);
            if ($value === null) {
                if ($actual !== null && $actual !== '') {
                    return false;
                }
                continue;
            }
            if (is_bool($value)) {
                if ((bool)$actual !== $value) {
                    return false;
                }
                continue;
            }
            if (is_int($value)) {
                if ((int)$actual !== $value) {
                    return false;
                }
                continue;
            }
            if (is_float($value)) {
                if ((float)$actual !== $value) {
                    return false;
                }
                continue;
            }
            if ((string)$actual !== (string)$value) {
                return false;
            }
        }

        return true;
    }

    private function extractSetupGateHandoffToken(array $args): ?string
    {
        $token = $args['setup-gate-handoff'] ?? null;
        if ($token === null) {
            return null;
        }
        if (!is_string($token) || preg_match('/^[0-9a-f]{32}$/D', strtolower(trim($token))) !== 1) {
            throw new \InvalidArgumentException('Invalid Cron setup gate handoff token.');
        }

        return strtolower(trim($token));
    }

    /**
     * @return array{pid:int,running:bool,released:bool,unknown:bool,reason:string,launch_id:string}|null
     */
    private function resolveRunningProcessProbe(
        CronTask $taskModel,
        string $processName,
        string $expectedLaunchId,
        string $expectedRunStart,
    ): ?array {
        $status = (string) ($taskModel->getData(CronTask::schema_fields_STATUS) ?? '');
        // BLOCK + pid=0 is an owned launch-in-progress window. Only the parent
        // holding that launch token may publish the PID after READY; a second
        // scheduler must never adopt a stable-name match from another attempt.
        if ($status === CronStatus::BLOCK->value) {
            return null;
        }
        if ($status !== CronStatus::RUNNING->value) {
            return null;
        }

        $candidatePid = Process::getPidByName($processName);
        if ($candidatePid < 1) {
            return null;
        }
        $executeName = (string)$taskModel->getData(CronTask::schema_fields_EXECUTE_NAME);
        $probe = Process::probeManagedCronProcess(
            $candidatePid,
            $executeName,
            $expectedLaunchId,
            $expectedRunStart,
        );

        return $probe + ['pid' => $candidatePid];
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
