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
        $listings = $this->cronTask->pagination()->select()->fetch();
        $tasks    = $listings->getOriginData();
        foreach ($tasks as &$task) {
            $task['out_run']  = false;
            $task['out_time'] = '';
            if ($task['run_date']) {
                $max_next_run_date_time = $task['max_next_run_date'] ? strtotime($task['max_next_run_date']) : 0;
                $run_date_time          = strtotime($task['run_date']);
                $time                   = time();
                if ($time > $max_next_run_date_time) {
                    $task['out_run']  = true;
                    $task['out_time'] = ($time - $run_date_time) / 3600;
                }
            }
        }
        $this->assign('tasks', $tasks);
        $this->assign('pagination', $listings->getPagination());
        $this->assign('total', $listings->getPaginationData()['totalSize']);
        return $this->fetch();
    }

    public function lock(): string
    {
        $task_id = $this->request->getPost('task_id');
        try {
            $task = $this->cronTask->load($task_id);
            $task->setData($task::fields_STATUS, CronStatus::BLOCK->value)
                 ->save();
//            return $this->fetchJson($this->success(__('锁定任务：%1', $task->getData('name'))));
            $this->getMessageManager()->addSuccess(__('锁定任务：%1', $task->getData('name')));
            $this->redirect('*/cron/listing');
        } catch (\ReflectionException|Core $e) {
            $this->getMessageManager()->addError(__('锁定任务失败：%1', $e->getMessage()));
            $this->redirect('*/cron/listing');
//            return $this->fetchJson($this->error($e->getMessage()));
        }
    }

    public function unlock(): string
    {
        $task_id = $this->request->getPost('task_id');
        try {
            $task = $this->cronTask->load($task_id);
            $task->setData($task::fields_STATUS, CronStatus::PENDING->value)
                 ->save();
//            return $this->fetchJson($this->success(__('解锁任务：%1', $task->getData('name'))));
            $this->getMessageManager()->addSuccess(__('解锁任务：%1', $task->getData('name')));
            $this->redirect('*/cron/listing');
        } catch (\ReflectionException|Core $e) {
//            return $this->fetchJson($this->error($e->getMessage()));
            $this->getMessageManager()->addError(__('解锁任务失败：%1', $e->getMessage()));
            $this->redirect('*/cron/listing');
        }
    }
}
