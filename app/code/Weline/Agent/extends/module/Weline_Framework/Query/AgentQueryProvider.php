<?php

declare(strict_types=1);

namespace Weline\Agent\Extends\Module\Weline_Framework\Query;

use Weline\Agent\Model\AgentChatSession;
use Weline\Agent\Model\AgentRole;
use Weline\Agent\Model\AgentSchedule;
use Weline\Agent\Model\AgentSkill;
use Weline\Agent\Service\AgentAdminService;
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
            'toggleSkill', 'archiveSession', 'deleteSession', 'sendMessage',
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
            ->fetch();
        return $roles->getItems();
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
        $skills->select()->fetch();
        return $skills->getItems();
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
            ->fetch();
        return $sessions->getItems();
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
            ->fetch();
        return $schedules->getItems();
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
            ->fetch();
        return $schedules->getItems();
    }
}
