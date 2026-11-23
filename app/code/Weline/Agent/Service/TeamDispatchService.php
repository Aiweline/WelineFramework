<?php
declare(strict_types=1);

namespace Weline\Agent\Service;

use Weline\Agent\Model\AgentChatSession;
use Weline\Agent\Model\AgentRole;
use Weline\Agent\Model\AgentTask;
use Weline\Framework\Manager\ObjectManager;

/**
 * 团队 @派单服务（P4）
 *
 * - parseMentions：识别 @role:code / @agent:code；
 * - dispatch：同项目强校验后创建 weline_agent_task 并同步执行被派角色回合；
 * - require_approval=1 时任务停在 awaiting_approval，待人工确认后 approve() 放行执行。
 */
final class TeamDispatchService
{
    public function __construct(
        private readonly ?AgentRuntimeService $runtime = null,
        private readonly ?ChatSessionManager $sessionManager = null,
        private readonly ?AgentRole $roleModel = null,
    ) {}

    /**
     * 解析文本中的 @ 派单目标
     *
     * @return list<array{kind:string,code:string}>
     */
    public function parseMentions(string $text): array
    {
        if (!preg_match_all('/@(role|agent):([A-Za-z0-9_-]+)/', $text, $m, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        foreach ($m as $match) {
            $out[] = ['kind' => strtolower($match[1]), 'code' => $match[2]];
        }
        return $out;
    }

    /**
     * 派单：来源会话 → 目标角色
     *
     * @return array{success:bool,task_id?:int,error?:string,result?:string}
     */
    public function dispatch(
        int $fromSessionId,
        string $toRoleCode,
        string $prompt,
        bool $requireApproval = false,
        string $createdByType = 'system',
        string $createdById = ''
    ): array {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return ['success' => false, 'error' => 'prompt is empty'];
        }

        // 项目边界：来源会话必须绑定项目
        try {
            $sm = $this->sessionManager ?? ObjectManager::getInstance(ChatSessionManager::class);
            $fromSession = $sm->getSession($fromSessionId);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'session lookup failed: ' . $e->getMessage()];
        }
        if (!$fromSession) {
            return ['success' => false, 'error' => 'source session not found'];
        }
        $projectId = (int)$fromSession->getData(AgentChatSession::schema_fields_PROJECT_ID);
        if ($projectId <= 0) {
            return ['success' => false, 'error' => 'dispatch requires a project-bound session'];
        }

        // 目标角色解析
        $runtime = $this->runtime ?? ObjectManager::getInstance(AgentRuntimeService::class);
        try {
            $role = $runtime->resolveRole(['role_code' => $toRoleCode]);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'role resolve failed: ' . $e->getMessage()];
        }
        // resolveRole 按 code 未命中时会落到默认角色——必须校验确实命中了目标 code
        if ((string)$role->getData(AgentRole::schema_fields_CODE) !== $toRoleCode) {
            return ['success' => false, 'error' => "role '{$toRoleCode}' not found"];
        }

        $idempotencyKey = 'dispatch_' . hash('sha256', $fromSessionId . '|' . $toRoleCode . '|' . $prompt);

        try {
            $prototype = ObjectManager::getInstance(AgentTask::class);
            $existing = clone $prototype;
            $existing->reset()->clearQuery()
                ->where(AgentTask::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey)
                ->find()->fetch();
            if ($existing->getId()) {
                return [
                    'success' => true,
                    'task_id' => (int)$existing->getId(),
                    'idempotent_replay' => true,
                    'status' => (string)$existing->getData(AgentTask::schema_fields_STATUS),
                ];
            }
        } catch (\Throwable) {
        }

        $task = clone $prototype;
        $task->clearData();
        $task->setData(AgentTask::schema_fields_PROJECT_ID, $projectId);
        $task->setData(AgentTask::schema_fields_SESSION_ID, $fromSessionId);
        $task->setData(AgentTask::schema_fields_ASSIGNEE_ROLE_ID, (int)$role->getId());
        $task->setData(AgentTask::schema_fields_ASSIGNEE_AGENT_CODE, $runtime->resolveAgentCode([], $role));
        $task->setData(AgentTask::schema_fields_PROMPT, $prompt);
        $task->setData(AgentTask::schema_fields_REQUIRE_APPROVAL, $requireApproval ? 1 : 0);
        $task->setData(AgentTask::schema_fields_STATUS, AgentTask::STATUS_QUEUED);
        $task->setData(AgentTask::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey);
        $task->setData(AgentTask::schema_fields_CREATED_BY_TYPE, $createdByType);
        $task->setData(AgentTask::schema_fields_CREATED_BY_ID, $createdById);
        $task->save();
        $taskId = (int)$task->getId();

        if ($requireApproval) {
            $task->setData(AgentTask::schema_fields_STATUS, AgentTask::STATUS_AWAITING_APPROVAL);
            $task->save();
            return ['success' => true, 'task_id' => $taskId, 'status' => AgentTask::STATUS_AWAITING_APPROVAL];
        }

        return $this->executeTask($task, $taskId, $projectId, $role, $prompt, $runtime);
    }

    /**
     * 项目级直接派单（无来源会话）：巡检重拉、系统任务等场景。
     * 项目边界由显式 projectId 强制；roleCode 必须精确命中。
     *
     * @return array{success:bool,task_id?:int,error?:string,result?:string,status?:string}
     */
    public function dispatchForProject(
        int $projectId,
        string $roleCode,
        string $prompt,
        bool $requireApproval = false,
        string $createdByType = 'system',
        string $createdById = ''
    ): array {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return ['success' => false, 'error' => 'prompt is empty'];
        }
        if ($projectId <= 0) {
            return ['success' => false, 'error' => 'project required'];
        }

        $runtime = $this->runtime ?? ObjectManager::getInstance(AgentRuntimeService::class);
        try {
            $role = $runtime->resolveRole(['role_code' => $roleCode]);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'role resolve failed: ' . $e->getMessage()];
        }
        if ((string)$role->getData(AgentRole::schema_fields_CODE) !== $roleCode) {
            return ['success' => false, 'error' => "role '{$roleCode}' not found"];
        }

        $idempotencyKey = 'dispatch_' . hash('sha256', 'project|' . $projectId . '|' . $roleCode . '|' . $prompt . '|' . $createdById);
        try {
            $prototype = ObjectManager::getInstance(AgentTask::class);
            $existing = clone $prototype;
            $existing->reset()->clearQuery()
                ->where(AgentTask::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey)
                ->find()->fetch();
            if ($existing->getId()) {
                return [
                    'success' => true,
                    'task_id' => (int)$existing->getId(),
                    'idempotent_replay' => true,
                    'status' => (string)$existing->getData(AgentTask::schema_fields_STATUS),
                ];
            }
        } catch (\Throwable) {
        }

        $task = clone ObjectManager::getInstance(AgentTask::class);
        $task->clearData();
        $task->setData(AgentTask::schema_fields_PROJECT_ID, $projectId);
        $task->setData(AgentTask::schema_fields_SESSION_ID, 0);
        $task->setData(AgentTask::schema_fields_ASSIGNEE_ROLE_ID, (int)$role->getId());
        $task->setData(AgentTask::schema_fields_ASSIGNEE_AGENT_CODE, $runtime->resolveAgentCode([], $role));
        $task->setData(AgentTask::schema_fields_PROMPT, $prompt);
        $task->setData(AgentTask::schema_fields_REQUIRE_APPROVAL, $requireApproval ? 1 : 0);
        $task->setData(AgentTask::schema_fields_STATUS, AgentTask::STATUS_QUEUED);
        $task->setData(AgentTask::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey);
        $task->setData(AgentTask::schema_fields_CREATED_BY_TYPE, $createdByType);
        $task->setData(AgentTask::schema_fields_CREATED_BY_ID, $createdById);
        $task->save();
        $taskId = (int)$task->getId();

        if ($requireApproval) {
            $task->setData(AgentTask::schema_fields_STATUS, AgentTask::STATUS_AWAITING_APPROVAL);
            $task->save();
            return ['success' => true, 'task_id' => $taskId, 'status' => AgentTask::STATUS_AWAITING_APPROVAL];
        }

        return $this->executeTask($task, $taskId, $projectId, $role, $prompt, $runtime);
    }

    /** 审批放行：awaiting_approval → 执行 */
    public function approve(int $taskId): array
    {
        try {
            $task = clone ObjectManager::getInstance(AgentTask::class);
            $task->clearData()->load($taskId);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
        if (!$task->getId()) {
            return ['success' => false, 'error' => 'task not found'];
        }
        if ($task->getData(AgentTask::schema_fields_STATUS) !== AgentTask::STATUS_AWAITING_APPROVAL) {
            return ['success' => false, 'error' => 'task is not awaiting approval'];
        }

        $projectId = (int)$task->getData(AgentTask::schema_fields_PROJECT_ID);
        $roleId = (int)$task->getData(AgentTask::schema_fields_ASSIGNEE_ROLE_ID);
        $prompt = (string)$task->getData(AgentTask::schema_fields_PROMPT);

        $role = null;
        try {
            $prototype = $this->roleModel ?? ObjectManager::getInstance(AgentRole::class);
            $role = clone $prototype;
            $role->load($roleId);
        } catch (\Throwable) {
        }
        if (!$role || !$role->getId()) {
            return ['success' => false, 'error' => 'assignee role missing'];
        }

        $runtime = $this->runtime ?? ObjectManager::getInstance(AgentRuntimeService::class);
        return $this->executeTask($task, $taskId, $projectId, $role, $prompt, $runtime);
    }

    private function executeTask(
        AgentTask $task,
        int $taskId,
        int $projectId,
        AgentRole $role,
        string $prompt,
        AgentRuntimeService $runtime
    ): array {
        $task->setData(AgentTask::schema_fields_STATUS, AgentTask::STATUS_RUNNING);
        $task->setData(AgentTask::schema_fields_STARTED_TIME, time());
        $task->save();

        try {
            $result = $runtime->runTurn([
                'prompt' => $prompt,
                'role_id' => (int)$role->getId(),
                'project_id' => $projectId,
                'agent_code' => (string)$task->getData(AgentTask::schema_fields_ASSIGNEE_AGENT_CODE),
                'channel' => AgentChatSession::CHANNEL_WEB,
                'meta_json' => null,
                'skip_mentions' => true,
            ]);
        } catch (\Throwable $e) {
            $task->setData(AgentTask::schema_fields_STATUS, AgentTask::STATUS_FAILED);
            $task->setData(AgentTask::schema_fields_ERROR, $e->getMessage());
            $task->setData(AgentTask::schema_fields_FINISHED_TIME, time());
            $task->save();
            return ['success' => false, 'task_id' => $taskId, 'error' => $e->getMessage()];
        }

        if (!$result->success) {
            $task->setData(AgentTask::schema_fields_STATUS, AgentTask::STATUS_FAILED);
            $task->setData(AgentTask::schema_fields_ERROR, (string)($result->error ?? 'turn failed'));
            $task->setData(AgentTask::schema_fields_FINISHED_TIME, time());
            $task->save();
            return ['success' => false, 'task_id' => $taskId, 'error' => (string)$result->error];
        }

        $task->setData(AgentTask::schema_fields_STATUS, AgentTask::STATUS_DONE);
        $task->setData(AgentTask::schema_fields_RESULT_JSON, json_encode([
            'content' => (string)$result->content,
            'agent_code' => $result->agentCode ?? '',
            'model_code' => $result->modelCode ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $task->setData(AgentTask::schema_fields_FINISHED_TIME, time());
        $task->save();

        return [
            'success' => true,
            'task_id' => $taskId,
            'status' => AgentTask::STATUS_DONE,
            'result' => (string)$result->content,
        ];
    }
}
