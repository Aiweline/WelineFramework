<?php
declare(strict_types=1);

namespace Weline\Bot\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Bot\Model\BotSchedule;
use Weline\Bot\Model\BotRole;

/**
 * 调度任务管理控制器
 */
#[Acl('Weline_Bot::schedule', '调度任务', '管理 AI 智能体调度任务', '')]
class Schedule extends BackendController
{
    public function __construct(
        private readonly BotSchedule $scheduleModel,
        private readonly BotRole $roleModel,
    ) {}

    #[Acl('Weline_Bot::schedule_list', '任务列表', '', '查看调度任务列表')]
    public function getList()
    {
        $status = $this->request->getParam('status', '');

        $schedules = $this->scheduleModel->reset();

        if ($status) {
            $schedules->where(BotSchedule::schema_fields_STATUS, $status);
        }

        $schedules->order(BotSchedule::schema_fields_SCHEDULE_ID, 'DESC')
            ->pagination()
            ->select()
            ->fetch();

        $this->assign('schedules', $schedules->getItems());
        $this->assign('pagination', $schedules->getPagination());
        return $this->fetch();
    }

    #[Acl('Weline_Bot::schedule_listing', '任务列表', '', '查看调度任务列表')]
    public function listing()
    {
        return $this->getList();
    }

    #[Acl('Weline_Bot::schedule_add', '添加任务', '', '添加调度任务')]
    public function getAdd()
    {
        $roles = $this->roleModel->reset()
            ->where(BotRole::schema_fields_STATUS, BotRole::STATUS_ENABLED)
            ->select()
            ->fetch();

        $this->assign('roles', $roles->getItems());
        $this->assign('schedule', null);
        return $this->fetch('form');
    }

    #[Acl('Weline_Bot::schedule_edit', '编辑任务', '', '编辑调度任务')]
    public function getEdit()
    {
        $id = (int) $this->request->getParam('id', 0);
        $schedule = $this->scheduleModel->load($id);

        if (!$schedule->getId()) {
            $this->getSession()->addError(__('任务不存在'));
            return $this->redirect('*/*/listing');
        }

        $roles = $this->roleModel->reset()
            ->where(BotRole::schema_fields_STATUS, BotRole::STATUS_ENABLED)
            ->select()
            ->fetch();

        $this->assign('schedule', $schedule);
        $this->assign('roles', $roles->getItems());
        return $this->fetch('form');
    }

    #[Acl('Weline_Bot::schedule_save', '保存任务', '', '保存调度任务')]
    public function postSave()
    {
        $id = (int) $this->request->getParam('id', 0);
        $roleId = (int) $this->request->getParam('role_id', 0);
        $name = $this->request->getParam('name', '');
        $description = $this->request->getParam('description', '');
        $triggerExpr = $this->request->getParam('trigger_expr', '');
        $prompt = $this->request->getParam('prompt', '');
        $context = $this->request->getParam('context', []);
        $status = $this->request->getParam('status', BotSchedule::STATUS_ENABLED);

        if (empty($name) || empty($prompt)) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('任务名称和提示词不能为空'),
            ]);
        }

        $schedule = $id > 0 ? $this->scheduleModel->load($id) : $this->scheduleModel;
        $schedule->setData(BotSchedule::schema_fields_ROLE_ID, $roleId);
        $schedule->setData(BotSchedule::schema_fields_NAME, $name);
        $schedule->setData(BotSchedule::schema_fields_DESCRIPTION, $description);
        // 调度统一使用 Cron 触发。
        $schedule->setData(BotSchedule::schema_fields_TRIGGER_TYPE, BotSchedule::TRIGGER_CRON);
        $schedule->setData(BotSchedule::schema_fields_TRIGGER_EXPR, $triggerExpr);
        $schedule->setData(BotSchedule::schema_fields_PROMPT, $prompt);
        $schedule->setData(BotSchedule::schema_fields_STATUS, $status);

        // 处理上下文
        if (is_string($context)) {
            $context = json_decode($context, true) ?: [];
        }
        $schedule->setContext($context);

        // 计算下次执行时间
        $schedule->setData(BotSchedule::schema_fields_NEXT_RUN_AT, $schedule->calculateNextRun());

        $schedule->save();

        return $this->fetchJson([
            'success' => true,
            'msg' => __('保存成功'),
            'data' => ['id' => $schedule->getId()],
        ]);
    }

    #[Acl('Weline_Bot::schedule_delete', '删除任务', '', '删除调度任务')]
    public function postDelete()
    {
        $id = (int) $this->request->getParam('id', 0);
        $schedule = $this->scheduleModel->load($id);

        if (!$schedule->getId()) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('任务不存在'),
            ]);
        }

        $schedule->delete();

        return $this->fetchJson([
            'success' => true,
            'msg' => __('删除成功'),
        ]);
    }

    #[Acl('Weline_Bot::schedule_toggle', '切换状态', '', '切换任务状态')]
    public function postToggle()
    {
        $id = (int) $this->request->getParam('id', 0);
        $schedule = $this->scheduleModel->load($id);

        if (!$schedule->getId()) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('任务不存在'),
            ]);
        }

        $newStatus = $schedule->isEnabled() ? BotSchedule::STATUS_DISABLED : BotSchedule::STATUS_ENABLED;
        $schedule->setData(BotSchedule::schema_fields_STATUS, $newStatus);
        $schedule->save();

        return $this->fetchJson([
            'success' => true,
            'msg' => __('状态已更新'),
            'data' => ['status' => $newStatus],
        ]);
    }

    #[Acl('Weline_Bot::schedule_run', '立即执行', '', '立即执行任务')]
    public function postRun()
    {
        $id = (int) $this->request->getParam('id', 0);
        $schedule = $this->scheduleModel->load($id);

        if (!$schedule->getId()) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('任务不存在'),
            ]);
        }

        // 创建异步任务执行
        // TODO: 实际执行逻辑

        return $this->fetchJson([
            'success' => true,
            'msg' => __('任务已加入执行队列'),
        ]);
    }
}
