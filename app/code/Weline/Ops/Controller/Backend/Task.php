<?php

declare(strict_types=1);

namespace Weline\Ops\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Url;
use Weline\Ops\Model\OpsTask;
use Weline\Ops\Service\OpsDispatchService;
use Weline\Ops\Service\OpsRunLogService;
use Weline\Ops\Service\OpsTaskService;

#[Acl(
    'Weline_Ops::commerce:ops:task',
    '调度任务管理',
    'mdi mdi-clipboard-list-outline',
    '查看与管理调度智能体产生的运营任务',
    'Weline_Backend::ai_ops_group',
)]
class Task extends BackendController
{
    public function __construct(
        private readonly OpsTaskService $taskService,
        private readonly OpsDispatchService $dispatchService,
        private readonly OpsRunLogService $runLogService,
        private readonly Url $url,
    ) {
    }

    #[Acl(
        'Weline_Ops::commerce:ops:task_index',
        '查看调度任务',
        'mdi mdi-clipboard-list-outline',
        '列出调度智能体产生的任务队列',
    )]
    public function index(): string
    {
        $websiteIdRaw = $this->request->getGet('website_id', '');
        $websiteId = $websiteIdRaw === '' || $websiteIdRaw === null ? null : (int)$websiteIdRaw;
        $status = trim((string)$this->request->getGet('status', ''));
        $assignee = trim((string)$this->request->getGet('assignee_agent', ''));
        $sessionId = trim((string)$this->request->getGet('session_id', ''));
        $dispatchOnly = (string)$this->request->getGet('dispatch_only', '1') !== '0';
        $activeTab = trim((string)$this->request->getGet('tab', 'tasks'));
        if (!in_array($activeTab, ['tasks', 'logs'], true)) {
            $activeTab = 'tasks';
        }

        $filters = [
            'status' => $status,
            'assignee_agent' => $assignee,
            'session_id' => $sessionId,
            'dispatch_only' => $dispatchOnly,
        ];
        if ($websiteId !== null) {
            $filters['website_id'] = $websiteId;
        }

        $tasks = $this->taskService->listFiltered($filters, 100);
        $logs = $websiteId !== null
            ? $this->runLogService->listRecent((int)$websiteId, 15)
            : [];

        $this->assign('filters', [
            'website_id' => $websiteIdRaw,
            'status' => $status,
            'assignee_agent' => $assignee,
            'session_id' => $sessionId,
            'dispatch_only' => $dispatchOnly ? '1' : '0',
        ]);
        $this->assign('active_tab', $activeTab);
        $this->assign('tasks', $tasks);
        $this->assign('logs', $logs);
        $this->assign('statuses', OpsTask::allowedStatuses());
        $this->assign('assignees', [
            OpsTask::AGENT_OPS_ANALYST,
            OpsTask::AGENT_BLOG_PUBLISH,
            OpsTask::AGENT_SOCIAL_OPS,
        ]);
        $this->assign('actions', [
            'cancel' => $this->url->getBackendUrl('*/backend/task/cancel'),
            'approve' => $this->url->getBackendUrl('*/backend/task/approve'),
            'desk' => $this->url->getBackendUrl('*/backend/desk/index'),
        ]);

        return $this->fetch('Weline_Ops::templates/backend/Task/index.phtml');
    }

    #[Acl(
        'Weline_Ops::commerce:ops:task_cancel',
        '取消调度任务',
        'mdi mdi-clipboard-list-outline',
        '取消仍在队列中的调度任务',
    )]
    public function cancel()
    {
        $taskId = (int)$this->request->getPost('task_id', 0);
        $websiteId = (int)$this->request->getPost('website_id', 0);
        $got = $this->taskService->get($taskId);
        if (!($got['ok'] ?? false)) {
            $this->getMessageManager()->addError(__('任务不存在'));

            return $this->redirect('*/backend/task/index', ['website_id' => $websiteId]);
        }
        $status = (string)(($got['task']['status'] ?? ''));
        if (!in_array($status, [OpsTask::STATUS_QUEUED, OpsTask::STATUS_FAILED], true)) {
            $this->getMessageManager()->addError(__('仅 queued/failed 可取消'));

            return $this->redirect('*/backend/task/index', ['website_id' => $websiteId]);
        }
        $result = $this->taskService->setStatus($taskId, OpsTask::STATUS_CANCELLED);
        if ($result['ok'] ?? false) {
            $this->getMessageManager()->addSuccess(__('任务已取消'));
        } else {
            $this->getMessageManager()->addError((string)($result['message'] ?? __('取消失败')));
        }

        return $this->redirect('*/backend/task/index', [
            'website_id' => $websiteId,
            'session_id' => (string)(($got['task']['session_id'] ?? '')),
        ]);
    }

    #[Acl(
        'Weline_Ops::commerce:ops:task_approve',
        '批准调度任务外发',
        'mdi mdi-clipboard-list-outline',
        '批准待审任务并触发域外发',
    )]
    public function approve()
    {
        $taskId = (int)$this->request->getPost('task_id', 0);
        $websiteId = (int)$this->request->getPost('website_id', 0);
        $result = $this->dispatchService->approveTask($taskId);
        if ($result['ok'] ?? false) {
            $this->getMessageManager()->addSuccess(__('已批准并外发'));
        } else {
            $this->getMessageManager()->addError((string)($result['message'] ?? __('批准失败')));
        }

        return $this->redirect('*/backend/task/index', ['website_id' => $websiteId]);
    }
}
