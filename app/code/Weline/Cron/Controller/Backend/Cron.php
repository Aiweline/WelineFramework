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
use Weline\Cron\Service\CronManualRunStreamer;
use Weline\Cron\Service\CronTestDiscovery;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Exception\Core;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Security\Token;

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
    private CronTask $cronTask;

    public function __construct(
        CronTask $cronTask
    ) {
        $this->cronTask = $cronTask;
    }

    #[Acl('Weline_Cron::cron_listing', '计划任务列表', 'mdi mdi-format-list-bulleted', '查看计划任务列表')]
    public function listing()
    {
        $status = $this->request->getGet('status');
        $search = trim((string) $this->request->getGet('q'));
        $module = trim((string) $this->request->getGet('module'));

        if ($status) {
            $this->cronTask->where($this->cronTask::schema_fields_STATUS, $status);
        }
        if ($module !== '') {
            $this->cronTask->where($this->cronTask::schema_fields_MODULE, $module);
        }
        if ($search !== '') {
            $this->cronTask->where(
                'concat(name,execute_name,module,class,tip)',
                "%{$search}%",
                'like'
            );
        }

        $this->cronTask->order('id', 'ASC');
        $listings = $this->cronTask->pagination()->select()->fetch();
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

    #[Acl('Weline_Cron::cron_lock', '锁定计划任务', 'mdi mdi-lock', '锁定定时任务')]
    public function lock(): string
    {
        $task_id = $this->request->getPost('task_id');
        try {
            $task = $this->cronTask->load($task_id);
            $task->setData($task::schema_fields_STATUS, CronStatus::BLOCK->value)
                ->save();
            $this->getMessageManager()->addSuccess(__('锁定任务：%{1}', $task->getData('name')));
            $this->redirect('*/backend/cron/listing');

            return '';
        } catch (\ReflectionException|Core $e) {
            $this->getMessageManager()->addError(__('锁定任务失败：%{1}', $e->getMessage()));
            $this->redirect('*/backend/cron/listing');

            return '';
        }
    }

    #[Acl('Weline_Cron::cron_unlock', '解锁计划任务', 'mdi mdi-lock-open', '解锁定时任务')]
    public function unlock(): string
    {
        $task_id = $this->request->getPost('task_id');
        try {
            $task = $this->cronTask->load($task_id);
            $task->setData($task::schema_fields_STATUS, CronStatus::PENDING->value)
                ->save();
            $this->getMessageManager()->addSuccess(__('解锁任务：%{1}', $task->getData('name')));
            $this->redirect('*/backend/cron/listing');

            return '';
        } catch (\ReflectionException|Core $e) {
            $this->getMessageManager()->addError(__('解锁任务失败：%{1}', $e->getMessage()));
            $this->redirect('*/backend/cron/listing');

            return '';
        }
    }

    #[Acl('Weline_Cron::cron_run_help', '手动运行帮助', 'mdi mdi-help-circle-outline', '计划任务 SSE 手动运行说明 JSON')]
    public function getRunHelp(): string
    {
        $this->layoutType = null;
        $executeName = \trim((string) $this->request->getGet('execute_name', ''));
        if ($executeName === '' || !\preg_match('/^[a-zA-Z0-9_-]+$/', $executeName)) {
            $this->request->getResponse()->setHttpResponseCode(400);

            return (string) \json_encode([
                'success' => false,
                'message' => (string) __('参数 execute_name 无效'),
            ], JSON_UNESCAPED_UNICODE);
        }

        $task = ObjectManager::make(CronTask::class)->reset()
            ->where(CronTask::schema_fields_EXECUTE_NAME, $executeName)
            ->find()
            ->fetch();
        if (!$task->getId()) {
            $this->request->getResponse()->setHttpResponseCode(404);

            return (string) \json_encode([
                'success' => false,
                'message' => (string) __('任务不存在'),
            ], JSON_UNESCAPED_UNICODE);
        }

        $tip = (string) ($task->getData(CronTask::schema_fields_TIP) ?? '');
        $row = CronTestDiscovery::findById($executeName);
        $description = '';
        $examples = [];
        if ($row !== null) {
            $description = (string) ($row['description'] ?? '');
            $examples = \is_array($row['examples'] ?? null) ? $row['examples'] : [];
        }

        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');

        return (string) \json_encode([
            'success' => true,
            'execute_name' => $executeName,
            'name' => (string) ($task->getData(CronTask::schema_fields_NAME) ?? ''),
            'tip' => $tip,
            'test_help_description' => $description,
            'test_help_examples' => $examples,
            'manual_args_hint' => (string) __(
                '可选「后缀」会写入子进程环境变量 WELINE_CRON_MANUAL_ARGS；任务可在 execute() 内 getenv 读取。留空则与定时调度一致。'
            ),
        ], JSON_UNESCAPED_UNICODE);
    }

    #[Acl('Weline_Cron::cron_run_stream', '手动运行SSE', 'mdi mdi-play-network', '计划任务真实执行 SSE 流')]
    public function postRunStream(): void
    {
        $this->layoutType = null;
        $csrfPost = (string) $this->request->getPost('csrf', '');
        $csrfValid = Token::get('csrf');
        if ($csrfValid === null || !\hash_equals($csrfValid, $csrfPost)) {
            if (!\headers_sent()) {
                \header('Content-Type: application/json; charset=utf-8', true, 403);
            }
            echo \json_encode(['error' => (string) __('CSRF 验证失败')], JSON_UNESCAPED_UNICODE);

            return;
        }

        $executeName = \trim((string) $this->request->getPost('execute_name', ''));
        if ($executeName === '' || !\preg_match('/^[a-zA-Z0-9_-]+$/', $executeName)) {
            $sse = new SseWriter();
            $sse->start();
            $sse->sendError((string) __('执行名无效'));
            $sse->complete(['exit_code' => -1]);

            return;
        }

        $task = ObjectManager::make(CronTask::class)->reset()
            ->where(CronTask::schema_fields_EXECUTE_NAME, $executeName)
            ->find()
            ->fetch();
        if (!$task->getId()) {
            $sse = new SseWriter();
            $sse->start();
            $sse->sendError((string) __('任务不存在'));
            $sse->complete(['exit_code' => -1]);

            return;
        }

        $suffix = (string) $this->request->getPost('suffix', '');
        /** @var CronManualRunStreamer $streamer */
        $streamer = ObjectManager::getInstance(CronManualRunStreamer::class);
        $streamer->stream($executeName, $suffix, new SseWriter());
    }
}
