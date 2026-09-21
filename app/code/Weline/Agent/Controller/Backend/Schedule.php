<?php
declare(strict_types=1);

namespace Weline\Agent\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Agent\Model\AgentSchedule;
use Weline\Agent\Model\AgentRole;
use Weline\Agent\Service\AgentAdminService;

/**
 * 调度任务管理控制器
 */
#[Acl('Weline_Agent::schedule', '调度任务', '管理 AI 智能体调度任务', '')]
class Schedule extends BackendController
{
    use AgentHtmlSafeMutationTrait;

    public function __construct(
        private readonly AgentSchedule $scheduleModel,
        private readonly AgentRole $roleModel,
        private readonly AgentAdminService $adminService,
    ) {}

    #[Acl('Weline_Agent::schedule_list', '任务列表', '', '查看调度任务列表')]
    public function getList()
    {
        $status = $this->request->getParam('status', '');

        $schedules = $this->scheduleModel->reset();

        if ($status) {
            $schedules->where(AgentSchedule::schema_fields_STATUS, $status);
        }

        $schedules->order(AgentSchedule::schema_fields_SCHEDULE_ID, 'DESC')
            ->pagination()
            ->select()
            ->fetch();

        $this->assign('schedules', $schedules->getItems());
        $this->assign('pagination', $schedules->getPagination());
        return $this->fetch();
    }

    #[Acl('Weline_Agent::schedule_listing', '任务列表', '', '查看调度任务列表')]
    public function listing()
    {
        return $this->getList();
    }

    #[Acl('Weline_Agent::schedule_add', '添加任务', '', '添加调度任务')]
    public function getAdd()
    {
        $roles = $this->roleModel->reset()
            ->where(AgentRole::schema_fields_STATUS, AgentRole::STATUS_ENABLED)
            ->select()
            ->fetch();

        $this->assign('roles', $roles->getItems());
        $this->assign('schedule', null);
        return $this->fetch('form');
    }

    #[Acl('Weline_Agent::schedule_edit', '编辑任务', '', '编辑调度任务')]
    public function getEdit()
    {
        $id = (int) $this->request->getParam('id', 0);
        $schedule = $this->scheduleModel->load($id);

        if (!$schedule->getId()) {
            $this->getMessageManager()->addError(__('任务不存在'));
            return $this->redirect('*/backend/schedule/listing');
        }

        $roles = $this->roleModel->reset()
            ->where(AgentRole::schema_fields_STATUS, AgentRole::STATUS_ENABLED)
            ->select()
            ->fetch();

        $this->assign('schedule', $schedule);
        $this->assign('roles', $roles->getItems());
        return $this->fetch('form');
    }

    #[Acl('Weline_Agent::schedule_save', '保存任务', '', '保存调度任务')]
    public function postSave()
    {
        $result = $this->adminService->saveSchedule([
            'id' => (int) $this->request->getParam('id', 0),
            'role_id' => (int) $this->request->getParam('role_id', 0),
            'name' => (string) $this->request->getParam('name', ''),
            'description' => (string) $this->request->getParam('description', ''),
            'trigger_expr' => (string) $this->request->getParam('trigger_expr', ''),
            'prompt' => (string) $this->request->getParam('prompt', ''),
            'context' => $this->request->getParam('context', []),
            'status' => (string) $this->request->getParam('status', AgentSchedule::STATUS_ENABLED),
        ]);
        return $this->respondMutation($result, '*/backend/schedule/listing');
    }

    #[Acl('Weline_Agent::schedule_delete', '删除任务', '', '删除调度任务')]
    public function postDelete()
    {
        $result = $this->adminService->deleteSchedule((int) $this->request->getParam('id', 0));
        return $this->respondMutation($result, '*/backend/schedule/listing');
    }

    #[Acl('Weline_Agent::schedule_toggle', '切换状态', '', '切换任务状态')]
    public function postToggle()
    {
        $result = $this->adminService->toggleSchedule((int) $this->request->getParam('id', 0));
        return $this->respondMutation($result, '*/backend/schedule/listing');
    }

    #[Acl('Weline_Agent::schedule_run', '立即执行', '', '立即执行任务')]
    public function postRun()
    {
        $result = $this->adminService->runSchedule((int) $this->request->getParam('id', 0));
        return $this->respondMutation($result, '*/backend/schedule/listing');
    }

}
