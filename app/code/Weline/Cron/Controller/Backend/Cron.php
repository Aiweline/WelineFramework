<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/30 15:05:57
 */

namespace Weline\Cron\Controller\Backend;

use Weline\Cron\Helper\CronStatus;
use Weline\Cron\Model\CronTask;
use Weline\Cron\Service\CronAdminReadService;
use Weline\Cron\Service\CronManualRunStreamer;
use Weline\Cron\Service\CronRunLogService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\DatabaseFreeTranslator;
use Weline\Framework\Security\Token;
use Weline\Framework\Setup\Lock\SetupDatabaseAccessLock;

/**
 * 类级 ACL 挂菜单 Weline_Cron::system_cron 下，子方法 ACL 才能被 ControllerAttributes 收集。
 */
#[Acl(
    'Weline_Cron::cron_pc_root',
    '计划任务接口',
    'mdi mdi-clock-outline',
    '计划任务后台（列表、锁定、手动运行等）',
    'Weline_Cron::system_cron'
)]
class Cron extends \Weline\Framework\App\Controller\BackendController
{
    #[Acl('Weline_Cron::cron_listing', '计划任务列表', 'mdi mdi-format-list-bulleted', '查看计划任务列表')]
    public function listing()
    {
        /** @var CronTask $cronTask */
        $cronTask = ObjectManager::make(CronTask::class)->reset();
        $status = $this->request->getGet('status');
        $search = trim((string) $this->request->getGet('q'));
        $module = trim((string) $this->request->getGet('module'));

        if ($status) {
            $cronTask->where(CronTask::schema_fields_STATUS, $status);
        }
        if ($module !== '') {
            $cronTask->where(CronTask::schema_fields_MODULE, $module);
        }
        if ($search !== '') {
            $cronTask->where(
                'concat(name,execute_name,module,class,tip)',
                "%{$search}%",
                'like'
            );
        }

        $cronTask->order('id', 'ASC');
        $listings = $cronTask->pagination()->select()->fetch();
        $tasks = $listings->getOriginData();
        $now = time();
        foreach ($tasks as &$task) {
            $task['out_run'] = false;
            $task['out_time_human'] = '';
            $task['running_duration_human'] = '';
            if ($task['run_date']) {
                $run_date_time = strtotime($task['run_date']);
                $max_next_run_date_time = $task['max_next_run_date'] ? strtotime($task['max_next_run_date']) : 0;
                if (($task['status'] ?? '') === CronStatus::RUNNING->value) {
                    $task['running_duration_human'] = $this->humanizeDuration($now - $run_date_time);
                }
                if ($now > $max_next_run_date_time) {
                    $task['out_run'] = true;
                    $task['out_time_human'] = $this->humanizeDuration($now - $run_date_time);
                }
            }
        }
        unset($task);
        $stats = $this->getCronStats();
        $moduleOptions = $this->getDistinctModules();
        $this->assign('tasks', $tasks);
        $this->assign('pagination', $listings->getPagination());
        $this->assign('total', $listings->getPaginationData()['totalSize']);
        $this->assign('stats', $stats);
        $this->assign('status', $status);
        $this->assign('filterSearch', $search);
        $this->assign('filterModule', $module);
        $this->assign('moduleOptions', $moduleOptions);

        return $this->fetch();
    }

    private function getDistinctModules(): array
    {
        /** @var CronTask $m */
        $m = ObjectManager::make(CronTask::class);
        $items = $m->reset()
            ->select(CronTask::schema_fields_MODULE)
            ->group(CronTask::schema_fields_MODULE)
            ->order(CronTask::schema_fields_MODULE, 'ASC')
            ->fetch()
            ->getItems();
        $list = [];
        foreach ($items as $item) {
            $name = $item->getData(CronTask::schema_fields_MODULE);
            if ($name !== null && $name !== '') {
                $list[] = $name;
            }
        }

        return $list;
    }

    private function humanizeDuration(int $seconds): string
    {
        if ($seconds < 0) {
            return '';
        }
        if ($seconds < 60) {
            return $seconds . __('秒');
        }
        if ($seconds < 3600) {
            $m = (int) floor($seconds / 60);
            $s = $seconds % 60;

            return $s > 0 ? $m . __('分') . $s . __('秒') : $m . __('分');
        }
        if ($seconds < 86400) {
            $h = (int) floor($seconds / 3600);
            $m = (int) floor(($seconds % 3600) / 60);

            return $m > 0 ? $h . __('小时') . $m . __('分') : $h . __('小时');
        }
        $d = (int) floor($seconds / 86400);
        $h = (int) floor(($seconds % 86400) / 3600);

        return $h > 0 ? $d . __('天') . $h . __('小时') : $d . __('天');
    }

    private function getCronStats(): array
    {
        /** @var CronTask $m */
        $m = ObjectManager::make(CronTask::class);
        $allCount = (int) $m->reset()->count('id');
        $pendingCount = (int) $m->reset()->where(CronTask::schema_fields_STATUS, CronStatus::PENDING->value)->count('id');
        $runningCount = (int) $m->reset()->where(CronTask::schema_fields_STATUS, CronStatus::RUNNING->value)->count('id');
        $successCount = (int) $m->reset()->where(CronTask::schema_fields_STATUS, CronStatus::SUCCESS->value)->count('id');
        $blockCount = (int) $m->reset()->where(CronTask::schema_fields_STATUS, CronStatus::BLOCK->value)->count('id');
        $failCount = (int) $m->reset()->where(CronTask::schema_fields_STATUS, CronStatus::FAIL->value)->count('id');
        $missCount = (int) $m->reset()->where(CronTask::schema_fields_STATUS, CronStatus::MISS->value)->count('id');

        return [
            'all' => $allCount,
            'pending' => $pendingCount,
            'running' => $runningCount,
            'success' => $successCount,
            'block' => $blockCount,
            'fail' => $failCount,
            'miss' => $missCount,
        ];
    }

    private function resolveCronTaskByIdentifier(string $identifier): ?CronTask
    {
        $identifier = \trim($identifier);
        if ($identifier === '') {
            return null;
        }

        /** @var CronTask $task */
        $task = ObjectManager::make(CronTask::class)->reset()
            ->where(CronTask::schema_fields_EXECUTE_NAME, $identifier)
            ->find()
            ->fetch();
        if ($task->getId()) {
            return $task;
        }

        /** @var CronTask $byName */
        $byName = ObjectManager::make(CronTask::class)->reset()
            ->where(CronTask::schema_fields_NAME, $identifier)
            ->find()
            ->fetch();

        return $byName->getId() ? $byName : null;
    }

    #[Acl('Weline_Cron::cron_lock', '锁定计划任务', 'mdi mdi-lock', '锁定定时任务')]
    public function lock(): string
    {
        $task_id = $this->request->getPost('task_id');
        $databaseAccessLock = new SetupDatabaseAccessLock();
        if (!$databaseAccessLock->acquireShared()) {
            $this->getMessageManager()->addError(DatabaseFreeTranslator::translate(
                '系统升级正在执行，本次计划任务状态变更已跳过且未访问数据库。',
                'Weline_Cron',
            ));
            $this->redirect('*/backend/cron/listing');
            return '';
        }
        try {
            $task = $this->updateIdleTaskStatus($task_id, CronStatus::BLOCK->value);
            $this->getMessageManager()->addSuccess(__('锁定任务：%{1}', $task->getData('name')));
            $this->redirect('*/backend/cron/listing');

            return '';
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError(__('锁定任务失败：%{1}', $e->getMessage()));
            $this->redirect('*/backend/cron/listing');

            return '';
        } finally {
            $databaseAccessLock->release();
        }
    }

    #[Acl('Weline_Cron::cron_unlock', '解锁计划任务', 'mdi mdi-lock-open', '解锁定时任务')]
    public function unlock(): string
    {
        $task_id = $this->request->getPost('task_id');
        $databaseAccessLock = new SetupDatabaseAccessLock();
        if (!$databaseAccessLock->acquireShared()) {
            $this->getMessageManager()->addError(DatabaseFreeTranslator::translate(
                '系统升级正在执行，本次计划任务状态变更已跳过且未访问数据库。',
                'Weline_Cron',
            ));
            $this->redirect('*/backend/cron/listing');
            return '';
        }
        try {
            $task = $this->updateIdleTaskStatus($task_id, CronStatus::PENDING->value);
            $this->getMessageManager()->addSuccess(__('解锁任务：%{1}', $task->getData('name')));
            $this->redirect('*/backend/cron/listing');

            return '';
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError(__('解锁任务失败：%{1}', $e->getMessage()));
            $this->redirect('*/backend/cron/listing');

            return '';
        } finally {
            $databaseAccessLock->release();
        }
    }

    private function updateIdleTaskStatus(int|string|null $taskId, string $targetStatus): CronTask
    {
        if ((!\is_int($taskId) && !\is_string($taskId))
            || !\ctype_digit((string)$taskId)
            || (int)$taskId < 1
        ) {
            throw new \InvalidArgumentException((string)__('任务 ID 无效'));
        }

        /** @var CronTask $task */
        $task = ObjectManager::make(CronTask::class)->reset()
            ->where(CronTask::schema_fields_ID, (int)$taskId)
            ->find()
            ->fetch();
        if (!$task->getId()) {
            throw new \RuntimeException((string)__('任务不存在'));
        }
        $pid = (int)$task->getData(CronTask::schema_fields_PID);
        $launchId = trim((string)$task->getData(CronTask::schema_fields_LAUNCH_ID));
        $status = (string)$task->getData(CronTask::schema_fields_STATUS);
        $runTime = (string)$task->getData(CronTask::schema_fields_RUN_TIME);
        if ($pid > 0 || $launchId !== '' || $status === CronStatus::RUNNING->value) {
            throw new \RuntimeException((string)__ (
                '任务存在受管运行代次，拒绝修改状态：%{1}',
                [(string)$task->getData(CronTask::schema_fields_NAME)],
            ));
        }
        if ($status === $targetStatus) {
            return $task;
        }

        /** @var CronTask $query */
        $query = ObjectManager::make(CronTask::class)->reset()
            ->where(CronTask::schema_fields_ID, (int)$task->getId())
            ->where(CronTask::schema_fields_STATUS, $status)
            ->where(CronTask::schema_fields_RUN_TIME, $runTime)
            ->where(CronTask::schema_fields_LAUNCH_ID, '')
            ->where(CronTask::schema_fields_PID, 0);
        $updated = $query->getQuery()->update([
            CronTask::schema_fields_STATUS => $targetStatus,
        ])->fetch();
        if ($updated !== true && (!\is_int($updated) || $updated !== 1)) {
            throw new \RuntimeException((string)__('任务状态已变化，请刷新后重试'));
        }
        $task->setData(CronTask::schema_fields_STATUS, $targetStatus);

        return $task;
    }

    #[Acl('Weline_Cron::cron_run_help', '手动运行帮助', 'mdi mdi-help-circle-outline', '计划任务 SSE 手动运行说明 JSON')]
    public function getRunHelp(): string
    {
        $this->layoutType = null;

        /** @var CronAdminReadService $service */
        $service = ObjectManager::getInstance(CronAdminReadService::class);

        return $this->renderAdminReadResponse(
            $service->getRunHelp((string)$this->request->getGet('execute_name', ''))
        );
    }

    #[Acl('Weline_Cron::cron_run_stream_get', '手动运行SSE（GET）', 'mdi mdi-play-network', '计划任务真实执行 SSE 流（GET 兼容入口）')]
    public function getRunStream(): void
    {
        $this->streamManualRun();
    }

    #[Acl('Weline_Cron::cron_run_stream', '手动运行SSE', 'mdi mdi-play-network', '计划任务真实执行 SSE 流')]
    public function postRunStream(): void
    {
        $this->streamManualRun();
    }

    private function streamManualRun(): void
    {
        $this->layoutType = null;
        $databaseAccessLock = new SetupDatabaseAccessLock();
        if (!$databaseAccessLock->acquireShared()) {
            $sse = new SseWriter();
            $sse->start();
            $sse->sendError(DatabaseFreeTranslator::translate(
                '系统升级正在执行，本次计划任务已跳过且未访问数据库。',
                'Weline_Cron',
            ));
            $sse->complete(['exit_code' => 75]);
            return;
        }

        try {
            $this->streamManualRunWithDatabaseAccess();
        } finally {
            $databaseAccessLock->release();
        }
    }

    private function streamManualRunWithDatabaseAccess(): void
    {
        $csrfPost = (string) $this->request->getPost('csrf', (string) $this->request->getGet('csrf', ''));
        $csrfValid = Token::get('csrf');
        if ($csrfValid === null || !\hash_equals($csrfValid, $csrfPost)) {
            $sse = new SseWriter();
            $sse->start();
            $sse->sendError((string) __('CSRF 验证失败'));
            $sse->complete(['exit_code' => -1]);

            return;
        }

        $taskIdentifier = \trim((string) $this->request->getPost('execute_name', (string) $this->request->getGet('execute_name', '')));
        if ($taskIdentifier === '') {
            $sse = new SseWriter();
            $sse->start();
            $sse->sendError((string) __('执行名不能为空'));
            $sse->complete(['exit_code' => -1]);

            return;
        }

        $task = $this->resolveCronTaskByIdentifier($taskIdentifier);
        if (!$task) {
            $sse = new SseWriter();
            $sse->start();
            $sse->sendError((string) __('任务不存在'));
            $sse->complete(['exit_code' => -1]);

            return;
        }
        $executeName = (string) ($task->getData(CronTask::schema_fields_EXECUTE_NAME) ?? '');

        $suffix = (string) $this->request->getPost('suffix', (string) $this->request->getGet('suffix', ''));
        /** @var CronManualRunStreamer $streamer */
        $streamer = ObjectManager::getInstance(CronManualRunStreamer::class);
        $streamer->stream($executeName, $suffix, new SseWriter());
    }

    #[Acl('Weline_Cron::cron_run_log_list', '运行日志列表', 'mdi mdi-history', '计划任务调度日志历史与当前文件信息', 'Weline_Cron::cron_pc_root')]
    public function runLogList(): string
    {
        $this->layoutType = null;

        /** @var CronAdminReadService $service */
        $service = ObjectManager::getInstance(CronAdminReadService::class);

        return $this->renderAdminReadResponse(
            $service->runLogList((string)$this->request->getGet('execute_name', ''))
        );
    }

    #[Acl('Weline_Cron::cron_run_log_content', '运行日志内容', 'mdi mdi-file-document-outline', '读取单次调度归档日志全文', 'Weline_Cron::cron_pc_root')]
    public function runLogContent(): string
    {
        $this->layoutType = null;

        /** @var CronAdminReadService $service */
        $service = ObjectManager::getInstance(CronAdminReadService::class);

        return $this->renderAdminReadResponse(
            $service->runLogContent(
                (string)$this->request->getGet('execute_name', ''),
                (string)$this->request->getGet('file', '')
            )
        );
    }

    /**
     * @param array{status:int,data:array<string,mixed>} $result
     */
    private function renderAdminReadResponse(array $result): string
    {
        $response = $this->request->getResponse();
        $response->setHttpResponseCode((int)$result['status']);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');

        return (string)\json_encode($result['data'], JSON_UNESCAPED_UNICODE);
    }

    #[Acl('Weline_Cron::cron_run_log_stream_get', '运行日志SSE（GET）', 'mdi mdi-access-point', '当前调度日志实时尾随（GET 兼容入口）', 'Weline_Cron::cron_pc_root')]
    public function getRunLogStream(): void
    {
        $this->streamRunLog();
    }

    #[Acl('Weline_Cron::cron_run_log', '运行日志SSE', 'mdi mdi-access-point', '当前调度日志实时尾随（SSE）', 'Weline_Cron::cron_pc_root')]
    public function postRunLogStream(): void
    {
        $this->streamRunLog();
    }

    private function streamRunLog(): void
    {
        $this->layoutType = null;
        $csrfPost = (string) $this->request->getPost('csrf', (string) $this->request->getGet('csrf', ''));
        $csrfValid = Token::get('csrf');
        if ($csrfValid === null || !\hash_equals($csrfValid, $csrfPost)) {
            $sse = new SseWriter();
            $sse->start();
            $sse->sendError((string) \__('CSRF 验证失败'));
            $sse->complete(['exit_code' => -1]);

            return;
        }
        $taskIdentifier = \trim((string) $this->request->getPost('execute_name', (string) $this->request->getGet('execute_name', '')));
        if ($taskIdentifier === '') {
            $sse = new SseWriter();
            $sse->start();
            $sse->sendError((string) \__('执行名不能为空'));
            $sse->complete(['exit_code' => -1]);

            return;
        }
        $task = $this->resolveCronTaskByIdentifier($taskIdentifier);
        if (!$task) {
            $sse = new SseWriter();
            $sse->start();
            $sse->sendError((string) \__('任务不存在'));
            $sse->complete(['exit_code' => -1]);

            return;
        }
        $executeName = (string) ($task->getData(CronTask::schema_fields_EXECUTE_NAME) ?? '');
        /** @var CronRunLogService $svc */
        $svc = ObjectManager::getInstance(CronRunLogService::class);
        $svc->streamLiveLogTail($executeName, new SseWriter());
    }
}
