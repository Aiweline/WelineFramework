<?php

declare(strict_types=1);

namespace Weline\Agent\Extends\Module\Weline_Framework\Query;

use Weline\Agent\Model\AgentChatMessage;
use Weline\Agent\Model\AgentChatSession;
use Weline\Agent\Model\AgentFlow;
use Weline\Agent\Model\AgentFlowNodeRun;
use Weline\Agent\Model\AgentFlowRun;
use Weline\Agent\Model\AgentProject;
use Weline\Agent\Model\AgentRole;
use Weline\Agent\Model\AgentSchedule;
use Weline\Agent\Model\AgentSkill;
use Weline\Agent\Model\AgentTask;
use Weline\Agent\Service\AgentAdminService;
use Weline\Agent\Service\TeamDispatchService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;

/**
 * Agent BinQuery provider (reads + backend mutations).
 */
class AgentQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly AgentRole $roleModel,
        private readonly AgentSkill $skillModel,
        private readonly AgentChatSession $sessionModel,
        private readonly AgentChatMessage $messageModel,
        private readonly AgentProject $projectModel,
        private readonly AgentFlow $flowModel,
        private readonly AgentFlowRun $flowRunModel,
        private readonly AgentFlowNodeRun $flowNodeRunModel,
        private readonly AgentTask $taskModel,
        private readonly TeamDispatchService $dispatchService,
        private readonly AgentSchedule $scheduleModel,
        private readonly AgentAdminService $adminService,
        private readonly SessionFactory $sessionFactory,
    ) {}

    public function getProviderName(): string
    {
        return 'agent';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        $this->assertBackendSessionForWrite($operation);

        return match ($operation) {
            'getRole' => $this->getRole($params),
            'getRoleById' => $this->getRoleById($params),
            'getActiveRoles' => $this->getActiveRoles($params),
            'getDefaultRole' => $this->getDefaultRole(),
            'getSkills' => $this->getSkills($params),
            'getSkill' => $this->getSkill($params),
            'getSession' => $this->getSession($params),
            'getActiveSessions' => $this->getActiveSessions($params),
            'getSchedules' => $this->getSchedules($params),
            'getDueSchedules' => $this->getDueSchedules($params),
            'saveRole' => $this->adminService->saveRole($this->payload($params)),
            'suggestRole' => $this->adminService->suggestRole($this->payload($params)),
            'deleteRole' => $this->adminService->deleteRole((int)($params['id'] ?? 0)),
            'toggleRole' => $this->adminService->toggleRole((int)($params['id'] ?? 0)),
            'saveSchedule' => $this->adminService->saveSchedule($this->payload($params)),
            'deleteSchedule' => $this->adminService->deleteSchedule((int)($params['id'] ?? 0)),
            'toggleSchedule' => $this->adminService->toggleSchedule((int)($params['id'] ?? 0)),
            'runSchedule' => $this->adminService->runSchedule((int)($params['id'] ?? 0)),
            'toggleSkill' => $this->adminService->toggleSkill((int)($params['id'] ?? 0)),
            'archiveSession' => $this->adminService->archiveSession((int)($params['id'] ?? 0)),
            'deleteSession' => $this->adminService->deleteSession((int)($params['id'] ?? 0)),
            'sendMessage' => $this->adminService->sendMessage($this->payload($params)),
            'getChatHistory' => $this->adminService->getChatHistory(
                (int)($params['session_id'] ?? 0),
                (int)($params['limit'] ?? 50)
            ),
            'getSessionMessages' => $this->getSessionMessages($params),
            'listProjects' => $this->listProjects($params),
            'getProject' => $this->getProject($params),
            'createProject' => $this->createProject($params),
            'listModels' => $this->listModels($params),
            'listFlows' => $this->listFlows($params),
            'getFlow' => $this->getFlow($params),
            'saveFlow' => $this->saveFlow($params),
            'getFlowRun' => $this->getFlowRun($params),
            'getFlowNodeRuns' => $this->getFlowNodeRuns($params),
            'listTasks' => $this->listTasks($params),
            'getTask' => $this->getTask($params),
            'dispatchTask' => $this->dispatchService->dispatch(
                (int)($params['from_session_id'] ?? 0),
                (string)($params['to_role_code'] ?? ''),
                (string)($params['prompt'] ?? ''),
                (bool)($params['require_approval'] ?? false),
                'admin',
                (string)($this->sessionFactory->createBackendSession()->getUserId() ?? '')
            ),
            'approveTask' => $this->dispatchService->approve((int)($params['id'] ?? 0)),
            'introspect' => $this->getDescriptor(),
            default => throw new \InvalidArgumentException((string)__('不支持的智能体操作：%{1}', $operation)),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'agent',
            'name' => '智能体',
            'description' => '智能体角色/技能/会话/调度查询与后台写操作（BinQuery）',
            'module' => 'Weline_Agent',
            'operations' => [
                $this->op('getRole', 'read', 'Weline_Agent::role_list', [
                    'code' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                ]),
                $this->op('getRoleById', 'read', 'Weline_Agent::role_list', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->op('getActiveRoles', 'read', 'Weline_Agent::role_list', [
                    'limit' => ['type' => 'int', 'min' => 1, 'max' => 500],
                ]),
                $this->op('getDefaultRole', 'read', 'Weline_Agent::role_list', []),
                $this->op('getSkills', 'read', 'Weline_Agent::skill_list', [
                    'category' => ['type' => 'string', 'max_length' => 64],
                    'active_only' => ['type' => 'bool'],
                ]),
                $this->op('getSkill', 'read', 'Weline_Agent::skill_list', [
                    'code' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                ]),
                $this->op('getSession', 'read', 'Weline_Agent::session_list', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->op('getActiveSessions', 'read', 'Weline_Agent::session_list', [
                    'channel' => ['type' => 'string', 'max_length' => 64],
                    'context_id' => ['type' => 'string', 'max_length' => 191],
                    'limit' => ['type' => 'int', 'min' => 1, 'max' => 200],
                ]),
                $this->op('getSchedules', 'read', 'Weline_Agent::schedule_list', [
                    'status' => ['type' => 'string', 'max_length' => 32],
                ]),
                $this->op('getDueSchedules', 'read', 'Weline_Agent::schedule_list', [
                    'limit' => ['type' => 'int', 'min' => 1, 'max' => 100],
                ]),
                $this->op('saveRole', 'write', 'Weline_Agent::role_save', [
                    'payload' => ['type' => 'map'],
                    'id' => ['type' => 'int', 'min' => 0],
                    'code' => ['type' => 'string', 'max_length' => 128],
                    'name' => ['type' => 'string', 'max_length' => 191],
                    'system_prompt' => ['type' => 'string'],
                    'model_id' => ['type' => 'int', 'min' => 0],
                    'scenario_adapter_code' => ['type' => 'string', 'max_length' => 128],
                    'status' => ['type' => 'string', 'max_length' => 32],
                    'description' => ['type' => 'string'],
                    'icon' => ['type' => 'string', 'max_length' => 64],
                    'permissions' => ['type' => 'list'],
                    'permissions_text' => ['type' => 'string'],
                    'skills' => ['type' => 'list'],
                    'model_config' => ['type' => 'map'],
                    'model_config_text' => ['type' => 'string'],
                ]),
                $this->op('suggestRole', 'write', 'Weline_Agent::role_suggest', [
                    'payload' => ['type' => 'map'],
                    'template_code' => ['type' => 'string', 'max_length' => 128],
                    'brief' => ['type' => 'string'],
                    'project_count' => ['type' => 'int', 'min' => 1, 'max' => 500],
                    'target_outcome' => ['type' => 'string'],
                    'workflow_style' => ['type' => 'string', 'max_length' => 64],
                    'risk_level' => ['type' => 'string', 'max_length' => 64],
                    'agent_profile' => ['type' => 'string', 'max_length' => 64],
                    'role_name' => ['type' => 'string', 'max_length' => 191],
                    'role_code' => ['type' => 'string', 'max_length' => 128],
                    'model_id' => ['type' => 'int', 'min' => 0],
                    'icon' => ['type' => 'string', 'max_length' => 64],
                ]),
                $this->op('deleteRole', 'write', 'Weline_Agent::role_delete', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->op('toggleRole', 'write', 'Weline_Agent::role_toggle', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->op('saveSchedule', 'write', 'Weline_Agent::schedule_save', [
                    'payload' => ['type' => 'map'],
                    'id' => ['type' => 'int', 'min' => 0],
                    'role_id' => ['type' => 'int', 'min' => 0],
                    'name' => ['type' => 'string', 'max_length' => 191],
                    'description' => ['type' => 'string'],
                    'trigger_expr' => ['type' => 'string', 'max_length' => 128],
                    'prompt' => ['type' => 'string'],
                    'context' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'max_length' => 32],
                ]),
                $this->op('deleteSchedule', 'write', 'Weline_Agent::schedule_delete', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->op('toggleSchedule', 'write', 'Weline_Agent::schedule_toggle', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->op('runSchedule', 'write', 'Weline_Agent::schedule_run', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->op('toggleSkill', 'write', 'Weline_Agent::skill_toggle', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->op('archiveSession', 'write', 'Weline_Agent::session_archive', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->op('deleteSession', 'write', 'Weline_Agent::session_delete', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->op('sendMessage', 'write', 'Weline_Agent::console_index', [
                    'payload' => ['type' => 'map'],
                    'role_code' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                    'message' => ['type' => 'string', 'required' => true],
                    'session_id' => ['type' => 'int', 'min' => 0],
                    'context_id' => ['type' => 'string', 'max_length' => 191],
                    'channel' => ['type' => 'string', 'max_length' => 64],
                ]),
                $this->op('getChatHistory', 'read', 'Weline_Agent::console_index', [
                    'session_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    'limit' => ['type' => 'int', 'min' => 1, 'max' => 200],
                ]),
                $this->op('getSessionMessages', 'read', 'Weline_Agent::console_index', [
                    'session_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    'limit' => ['type' => 'int', 'min' => 1, 'max' => 200],
                ]),
                $this->op('listProjects', 'read', 'Weline_Agent::console_index', [
                    'status' => ['type' => 'string', 'max_length' => 32],
                    'limit' => ['type' => 'int', 'min' => 1, 'max' => 200],
                ]),
                $this->op('getProject', 'read', 'Weline_Agent::console_index', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->op('createProject', 'write', 'Weline_Agent::console_index', [
                    'name' => ['type' => 'string', 'required' => true, 'max_length' => 191],
                    'website_id' => ['type' => 'int', 'min' => 0],
                    'config' => ['type' => 'map'],
                ]),
                $this->op('listModels', 'read', 'Weline_Agent::console_index', [
                    'modality' => ['type' => 'string', 'max_length' => 64],
                    'status' => ['type' => 'string', 'max_length' => 32],
                ]),
                $this->op('listFlows', 'read', 'Weline_Agent::console_index', [
                    'project_id' => ['type' => 'int', 'min' => 0],
                    'status' => ['type' => 'string', 'max_length' => 32],
                    'limit' => ['type' => 'int', 'min' => 1, 'max' => 200],
                ]),
                $this->op('getFlow', 'read', 'Weline_Agent::console_index', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->op('saveFlow', 'write', 'Weline_Agent::console_index', [
                    'id' => ['type' => 'int', 'min' => 0],
                    'project_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    'name' => ['type' => 'string', 'required' => true, 'max_length' => 191],
                    'description' => ['type' => 'string'],
                    'graph' => ['type' => 'map'],
                    'graph_json' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'max_length' => 32],
                ]),
                $this->op('getFlowRun', 'read', 'Weline_Agent::console_index', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->op('getFlowNodeRuns', 'read', 'Weline_Agent::console_index', [
                    'run_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->op('listTasks', 'read', 'Weline_Agent::console_index', [
                    'project_id' => ['type' => 'int', 'min' => 0],
                    'status' => ['type' => 'string', 'max_length' => 32],
                    'limit' => ['type' => 'int', 'min' => 1, 'max' => 200],
                ]),
                $this->op('getTask', 'read', 'Weline_Agent::console_index', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->op('dispatchTask', 'write', 'Weline_Agent::console_index', [
                    'from_session_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    'to_role_code' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                    'prompt' => ['type' => 'string', 'required' => true],
                    'require_approval' => ['type' => 'bool'],
                ]),
                $this->op('approveTask', 'write', 'Weline_Agent::console_index', [
                    'id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function op(string $name, string $mode, string $aclSource, array $params): array
    {
        return [
            'name' => $name,
            'frontend' => true,
            'mode' => $mode,
            'graph' => false,
            'cost' => 1,
            'auth' => 'backend',
            'backend_acl' => [
                'kind' => 'source',
                'source_id' => $aclSource,
            ],
            'params' => $params,
            'returns' => ['type' => 'array'],
        ];
    }

    private function assertBackendSessionForWrite(string $operation): void
    {
        $writes = [
            'saveRole', 'suggestRole', 'deleteRole', 'toggleRole',
            'saveSchedule', 'deleteSchedule', 'toggleSchedule', 'runSchedule',
            'toggleSkill', 'archiveSession', 'deleteSession', 'sendMessage', 'createProject', 'saveFlow',
            'dispatchTask', 'approveTask',
        ];
        if (!in_array($operation, $writes, true)) {
            return;
        }
        $session = $this->sessionFactory->createBackendSession();
        if (!$session->isLoggedIn() || (int)($session->getUserId() ?? 0) <= 0) {
            throw new \RuntimeException((string)__('请先登录后台'));
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function payload(array $params): array
    {
        $payload = $params['payload'] ?? null;
        if (is_array($payload)) {
            return array_merge($params, $payload);
        }
        return $params;
    }

    private function getRole(array $params): ?array
    {
        $code = (string)($params['code'] ?? '');
        if ($code === '') {
            return null;
        }
        $role = $this->roleModel->reset()
            ->where(AgentRole::schema_fields_CODE, $code)
            ->find()
            ->fetch();
        return $role->getId() ? $role->getData() : null;
    }

    private function getRoleById(array $params): ?array
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $role = $this->roleModel->load($id);
        return $role->getId() ? $role->getData() : null;
    }

    private function getActiveRoles(array $params): array
    {
        $limit = max(1, (int)($params['limit'] ?? 100));
        $roles = $this->roleModel->reset()
            ->where(AgentRole::schema_fields_STATUS, AgentRole::STATUS_ENABLED)
            ->limit($limit)
            ->select()
            ->fetchArray();
        return $roles;
    }

    private function getDefaultRole(): ?array
    {
        $role = $this->roleModel->reset()
            ->where(AgentRole::schema_fields_IS_DEFAULT, 1)
            ->where(AgentRole::schema_fields_STATUS, AgentRole::STATUS_ENABLED)
            ->find()
            ->fetch();
        return $role->getId() ? $role->getData() : null;
    }

    private function getSkills(array $params): array
    {
        $category = $params['category'] ?? null;
        $activeOnly = array_key_exists('active_only', $params) ? (bool)$params['active_only'] : true;
        $skills = $this->skillModel->reset();
        if (is_string($category) && $category !== '') {
            $skills->where(AgentSkill::schema_fields_CATEGORY, $category);
        }
        if ($activeOnly) {
            $skills->where(AgentSkill::schema_fields_IS_ACTIVE, 1);
        }
        return $skills->select()->fetchArray();
    }

    private function getSkill(array $params): ?array
    {
        $code = (string)($params['code'] ?? '');
        if ($code === '') {
            return null;
        }
        $skill = $this->skillModel->reset()
            ->where(AgentSkill::schema_fields_CODE, $code)
            ->find()
            ->fetch();
        return $skill->getId() ? $skill->getData() : null;
    }

    private function getSession(array $params): ?array
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $session = $this->sessionModel->load($id);
        return $session->getId() ? $session->getData() : null;
    }

    private function getActiveSessions(array $params): array
    {
        $channel = $params['channel'] ?? null;
        $contextId = $params['context_id'] ?? null;
        $limit = max(1, (int)($params['limit'] ?? 20));
        $sessions = $this->sessionModel->reset()
            ->where(AgentChatSession::schema_fields_STATUS, AgentChatSession::STATUS_ACTIVE);
        if (is_string($channel) && $channel !== '') {
            $sessions->where(AgentChatSession::schema_fields_CHANNEL, $channel);
        }
        if (is_string($contextId) && $contextId !== '') {
            $sessions->where(AgentChatSession::schema_fields_CONTEXT_ID, $contextId);
        }
        $sessions->order(AgentChatSession::schema_fields_UPDATED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();
        return $sessions;
    }

    /** 会话消息（含回合级 role_id/agent_code/model_code/project_id/meta_json） */
    private function getSessionMessages(array $params): array
    {
        $sessionId = (int)($params['session_id'] ?? 0);
        $limit = max(1, min(200, (int)($params['limit'] ?? 100)));
        if ($sessionId <= 0) {
            return [];
        }
        return $this->messageModel->reset()
            ->where(AgentChatMessage::schema_fields_SESSION_ID, $sessionId)
            ->order(AgentChatMessage::schema_fields_CREATED_AT, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();
    }

    private function listProjects(array $params): array
    {
        $limit = max(1, min(200, (int)($params['limit'] ?? 100)));
        $status = $params['status'] ?? null;
        $query = $this->projectModel->reset();
        if (is_string($status) && $status !== '') {
            $query->where(AgentProject::schema_fields_STATUS, $status);
        }
        return $query->order(AgentProject::schema_fields_UPDATED_TIME, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();
    }

    private function getProject(array $params): ?array
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $project = $this->projectModel->load($id);
        return $project->getId() ? $project->getData() : null;
    }

    private function createProject(array $params): array
    {
        $name = trim((string)($params['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException((string)__('项目名称不能为空'));
        }

        $session = $this->sessionFactory->createBackendSession();
        $ownerId = (string)($session->getUserId() ?? '');

        $project = clone $this->projectModel;
        $project->reset();
        $project->setData(AgentProject::schema_fields_NAME, $name);
        $project->setData(AgentProject::schema_fields_OWNER_TYPE, AgentProject::OWNER_ADMIN);
        $project->setData(AgentProject::schema_fields_OWNER_ID, $ownerId !== '' ? $ownerId : '0');
        $websiteId = (int)($params['website_id'] ?? 0);
        if ($websiteId > 0) {
            $project->setData(AgentProject::schema_fields_WEBSITE_ID, $websiteId);
        }
        $config = $params['config'] ?? null;
        if (is_array($config)) {
            $project->setData(AgentProject::schema_fields_CONFIG_JSON, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $project->save();

        return $project->getData();
    }

    /** 模型目录：透传 Weline_Ai listModels，前端选择器统一入口 */
    private function listModels(array $params): array
    {
        $forward = [];
        foreach (['modality', 'status', 'scenario_code'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $forward[$key] = $params[$key];
            }
        }
        $result = w_query('ai', 'listModels', $forward);
        return is_array($result) ? $result : [];
    }

    private function listFlows(array $params): array
    {
        $limit = max(1, min(200, (int)($params['limit'] ?? 100)));
        $query = $this->flowModel->reset();
        $projectId = (int)($params['project_id'] ?? 0);
        if ($projectId > 0) {
            $query->where(AgentFlow::schema_fields_PROJECT_ID, $projectId);
        }
        $status = $params['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $query->where(AgentFlow::schema_fields_STATUS, $status);
        }
        return $query->order(AgentFlow::schema_fields_UPDATED_TIME, 'DESC')
            ->limit($limit)->select()->fetchArray();
    }

    private function getFlow(array $params): ?array
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $flow = $this->flowModel->load($id);
        return $flow->getId() ? $flow->getData() : null;
    }

    private function saveFlow(array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $projectId = (int)($params['project_id'] ?? 0);
        $name = trim((string)($params['name'] ?? ''));
        if ($projectId <= 0 || $name === '') {
            throw new \InvalidArgumentException((string)__('项目与流程名称必填'));
        }

        $graph = $params['graph'] ?? null;
        if (!is_array($graph) && isset($params['graph_json'])) {
            $decoded = json_decode((string)$params['graph_json'], true);
            $graph = is_array($decoded) ? $decoded : null;
        }
        if (is_array($graph)) {
            $nodes = $graph['nodes'] ?? [];
            if (is_array($nodes) && count($nodes) > AgentFlow::MAX_NODES) {
                throw new \InvalidArgumentException((string)__('流程节点数超过上限 %{1}', [AgentFlow::MAX_NODES]));
            }
        }

        $flow = $id > 0 ? $this->flowModel->load($id) : clone $this->flowModel;
        if ($id <= 0) {
            $flow->reset()->clearQuery();
        }
        $flow->setData(AgentFlow::schema_fields_PROJECT_ID, $projectId);
        $flow->setData(AgentFlow::schema_fields_NAME, $name);
        $flow->setData(AgentFlow::schema_fields_DESCRIPTION, (string)($params['description'] ?? ''));
        if (is_array($graph)) {
            $flow->setGraph($graph);
            $flow->setData(AgentFlow::schema_fields_VERSION, (int)$flow->getData(AgentFlow::schema_fields_VERSION) + 1);
        }
        $status = trim((string)($params['status'] ?? ''));
        if (in_array($status, [AgentFlow::STATUS_DRAFT, AgentFlow::STATUS_ACTIVE, AgentFlow::STATUS_DISABLED], true)) {
            $flow->setData(AgentFlow::schema_fields_STATUS, $status);
        } elseif (!$flow->getId()) {
            $flow->setData(AgentFlow::schema_fields_STATUS, AgentFlow::STATUS_DRAFT);
        }
        $session = $this->sessionFactory->createBackendSession();
        if (!$flow->getId() && (int)($session->getUserId() ?? 0) > 0) {
            $flow->setData(AgentFlow::schema_fields_CREATED_BY, (int)$session->getUserId());
        }
        $flow->save();

        return ['success' => true, 'id' => (int)$flow->getId()];
    }

    private function getFlowRun(array $params): ?array
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $run = $this->flowRunModel->load($id);
        return $run->getId() ? $run->getData() : null;
    }

    private function getFlowNodeRuns(array $params): array
    {
        $runId = (int)($params['run_id'] ?? 0);
        if ($runId <= 0) {
            return [];
        }
        return $this->flowNodeRunModel->reset()
            ->where(AgentFlowNodeRun::schema_fields_RUN_ID, $runId)
            ->order(AgentFlowNodeRun::schema_fields_NODE_RUN_ID, 'ASC')
            ->select()->fetchArray();
    }

    private function listTasks(array $params): array
    {
        $limit = max(1, min(200, (int)($params['limit'] ?? 100)));
        $query = $this->taskModel->reset();
        $projectId = (int)($params['project_id'] ?? 0);
        if ($projectId > 0) {
            $query->where(AgentTask::schema_fields_PROJECT_ID, $projectId);
        }
        $status = $params['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $query->where(AgentTask::schema_fields_STATUS, $status);
        }
        return $query->order(AgentTask::schema_fields_TASK_ID, 'DESC')
            ->limit($limit)->select()->fetchArray();
    }

    private function getTask(array $params): ?array
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $task = $this->taskModel->load($id);
        return $task->getId() ? $task->getData() : null;
    }

    private function getSchedules(array $params): array
    {
        $status = $params['status'] ?? null;
        $schedules = $this->scheduleModel->reset();
        if (is_string($status) && $status !== '') {
            $schedules->where(AgentSchedule::schema_fields_STATUS, $status);
        }
        $schedules->order(AgentSchedule::schema_fields_SCHEDULE_ID, 'DESC')
            ->limit(100)
            ->select()
            ->fetchArray();
        return $schedules;
    }

    private function getDueSchedules(array $params): array
    {
        $limit = max(1, (int)($params['limit'] ?? 10));
        $now = time();
        $schedules = $this->scheduleModel->reset()
            ->where(AgentSchedule::schema_fields_STATUS, AgentSchedule::STATUS_ENABLED)
            ->where(AgentSchedule::schema_fields_NEXT_RUN_AT, $now, '<=')
            ->limit($limit)
            ->select()
            ->fetchArray();
        return $schedules;
    }
}
