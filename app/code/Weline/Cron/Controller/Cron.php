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

namespace Weline\Cron\Controller;

use Weline\Cron\Helper\CronStatus;
use Weline\Cron\Model\CronTask;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;

class Cron extends \Weline\Framework\App\Controller\BackendController
{
    /**
     * @var \Weline\Cron\Model\CronTask
     */
    private CronTask $cronTask;

    public function __construct(
        CronTask $cronTask
    )
    {
        $this->cronTask = $cronTask;
    }

    public function listing()
    {
        $status = $this->request->getGet('status');
        $search = trim((string) $this->request->getGet('q'));

        if ($status) {
            $this->cronTask->where($this->cronTask::schema_fields_STATUS, $status);
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
        foreach ($tasks as &$task) {
            $task['out_run'] = false;
            $task['out_time'] = '';
            if ($task['run_date']) {
                $max_next_run_date_time = $task['max_next_run_date'] ? strtotime($task['max_next_run_date']) : 0;
                $run_date_time = strtotime($task['run_date']);
                $time = time();
                if ($time > $max_next_run_date_time) {
                    $task['out_run'] = true;
                    $task['out_time'] = ($time - $run_date_time) / 3600;
                }
            }
        }
        $stats = $this->getCronStats();
        $this->assign('tasks', $tasks);
        $this->assign('pagination', $listings->getPagination());
        $this->assign('total', $listings->getPaginationData()['totalSize']);
        $this->assign('stats', $stats);
        $this->assign('status', $status);
        $this->assign('filterSearch', $search);
        return $this->fetch();
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

    public function lock(): string
    {
        $task_id = $this->request->getPost('task_id');
        try {
            $task = $this->cronTask->load($task_id);
            $task->setData($task::schema_fields_STATUS, CronStatus::BLOCK->value)
                ->save();
//            return $this->fetchJson($this->success(__('锁定任务：%{1}', $task->getData('name'))));
            $this->getMessageManager()->addSuccess(__('锁定任务：%{1}', $task->getData('name')));
            $this->redirect('*/cron/listing');
            return '';
        } catch (\ReflectionException|Core $e) {
            $this->getMessageManager()->addError(__('锁定任务失败：%{1}', $e->getMessage()));
            $this->redirect('*/cron/listing');
            return '';
//            return $this->fetchJson($this->error($e->getMessage()));
        }
    }

    public function unlock(): string
    {
        $task_id = $this->request->getPost('task_id');
        try {
            $task = $this->cronTask->load($task_id);
            $task->setData($task::schema_fields_STATUS, CronStatus::PENDING->value)
                ->save();
//            return $this->fetchJson($this->success(__('解锁任务：%{1}', $task->getData('name'))));
            $this->getMessageManager()->addSuccess(__('解锁任务：%{1}', $task->getData('name')));
            $this->redirect('*/cron/listing');
            return '';
        } catch (\ReflectionException|Core $e) {
            $this->getMessageManager()->addError(__('解锁任务失败：%{1}', $e->getMessage()));
            $this->redirect('*/cron/listing');
            return '';
        }
    }
}
