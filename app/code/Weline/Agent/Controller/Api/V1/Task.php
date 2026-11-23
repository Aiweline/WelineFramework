<?php
declare(strict_types=1);

namespace Weline\Agent\Controller\Api\V1;

use Weline\Agent\Model\AgentTask;
use Weline\Agent\Service\TeamDispatchService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * 团队任务 API：列表 / 审批放行 / 拒绝取消
 *
 * 路由（自动生成）：
 *   GET  /agent/api/v1/task/tasks?project_id=&status=
 *   POST /agent/api/v1/task/approve  {task_id}
 *   POST /agent/api/v1/task/reject   {task_id}
 */
class Task extends FrontendController
{
    /** 任务列表（默认近 50 条，按创建时间倒序） */
    public function getTasks()
    {
        $projectId = (int)$this->request->getQuery('project_id', 0);
        $status = trim((string)$this->request->getQuery('status', ''));

        try {
            $prototype = ObjectManager::getInstance(AgentTask::class);
            $query = clone $prototype;
            $query->reset()->clearQuery()
                ->order(AgentTask::schema_fields_TASK_ID, 'DESC')
                ->limit(50);
            if ($projectId > 0) {
                $query->where(AgentTask::schema_fields_PROJECT_ID, $projectId);
            }
            if ($status !== '') {
                $query->where(AgentTask::schema_fields_STATUS, $status);
            }
            $rows = $query->select()->fetchArray();
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => $e->getMessage()]);
        }

        $list = [];
        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $list[] = [
                'task_id' => (int)($row[AgentTask::schema_fields_TASK_ID] ?? 0),
                'project_id' => (int)($row[AgentTask::schema_fields_PROJECT_ID] ?? 0),
                'assignee_role_id' => (int)($row[AgentTask::schema_fields_ASSIGNEE_ROLE_ID] ?? 0),
                'assignee_agent_code' => (string)($row[AgentTask::schema_fields_ASSIGNEE_AGENT_CODE] ?? ''),
                'prompt' => mb_substr((string)($row[AgentTask::schema_fields_PROMPT] ?? ''), 0, 200),
                'require_approval' => (int)($row[AgentTask::schema_fields_REQUIRE_APPROVAL] ?? 0),
                'status' => (string)($row[AgentTask::schema_fields_STATUS] ?? ''),
                'error' => (string)($row[AgentTask::schema_fields_ERROR] ?? ''),
                'created_by_type' => (string)($row[AgentTask::schema_fields_CREATED_BY_TYPE] ?? ''),
                'created_time' => (int)($row[AgentTask::schema_fields_CREATED_TIME] ?? 0),
                'finished_time' => (int)($row[AgentTask::schema_fields_FINISHED_TIME] ?? 0),
            ];
        }

        return $this->fetchJson(['success' => true, 'tasks' => $list]);
    }

    /** 审批放行 awaiting_approval 任务 */
    public function postApprove()
    {
        $data = $this->jsonBody();
        $taskId = (int)($data['task_id'] ?? 0);
        if ($taskId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => 'task_id required']);
        }
        $service = ObjectManager::getInstance(TeamDispatchService::class);
        $result = $service->approve($taskId);
        return $this->fetchJson([
            'success' => (bool)($result['success'] ?? false),
            'msg' => $result['error'] ?? null,
            'task_id' => $result['task_id'] ?? $taskId,
            'status' => $result['status'] ?? null,
        ]);
    }

    /** 拒绝：awaiting_approval/queued → cancelled */
    public function postReject()
    {
        $data = $this->jsonBody();
        $taskId = (int)($data['task_id'] ?? 0);
        if ($taskId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => 'task_id required']);
        }
        try {
            $task = ObjectManager::getInstance(AgentTask::class)->clearData()->load($taskId);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => $e->getMessage()]);
        }
        if (!$task->getId()) {
            return $this->fetchJson(['success' => false, 'msg' => 'task not found']);
        }
        $status = (string)$task->getData(AgentTask::schema_fields_STATUS);
        if (!in_array($status, [AgentTask::STATUS_QUEUED, AgentTask::STATUS_AWAITING_APPROVAL], true)) {
            return $this->fetchJson(['success' => false, 'msg' => 'task cannot be rejected in status ' . $status]);
        }
        $task->setData(AgentTask::schema_fields_STATUS, AgentTask::STATUS_CANCELLED);
        $task->setData(AgentTask::schema_fields_FINISHED_TIME, time());
        $task->save();
        return $this->fetchJson(['success' => true, 'task_id' => $taskId, 'status' => AgentTask::STATUS_CANCELLED]);
    }

    private function jsonBody(): array
    {
        $body = $this->request->getBodyParams();
        $data = is_string($body) ? json_decode($body, true) : $body;
        return is_array($data) ? $data : [];
    }
}
