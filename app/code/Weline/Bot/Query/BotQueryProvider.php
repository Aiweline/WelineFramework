<?php
declare(strict_types=1);

namespace Weline\Bot\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Bot\Model\BotRole;
use Weline\Bot\Model\BotSkill;
use Weline\Bot\Model\BotChatSession;
use Weline\Bot\Model\BotSchedule;

/**
 * Bot 模块查询提供者
 *
 * 提供统一的 Bot 数据查询接口
 * 
 * 使用示例：
 *   w_query('bot', 'getRole', ['code' => 'assistant'])
 *   w_query('bot', 'getActiveRoles', [])
 *   w_query('bot', 'getSkills', ['category' => 'filesystem'])
 *   w_query('bot', 'getSession', ['id' => 1])
 */
class BotQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly BotRole $roleModel,
        private readonly BotSkill $skillModel,
        private readonly BotChatSession $sessionModel,
        private readonly BotSchedule $scheduleModel,
    ) {}

    public function getProviderName(): string
    {
        return 'bot';
    }

    public function execute(string $operation, array $params = []): mixed
    {
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
            'introspect' => $this->introspect($params),
            default => null,
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'bot',
            'name' => 'Bot 智能体查询',
            'description' => '提供 Bot 角色管理、技能系统、会话管理、任务调度等查询能力',
            'module' => 'Weline_Bot',
            'operations' => [
                [
                    'name' => 'getRole',
                    'description' => '根据代码获取角色',
                    'params' => [
                        ['name' => 'code', 'type' => 'string', 'required' => true, 'description' => '角色代码'],
                    ],
                ],
                [
                    'name' => 'getRoleById',
                    'description' => '根据 ID 获取角色',
                    'params' => [
                        ['name' => 'id', 'type' => 'int', 'required' => true, 'description' => '角色ID'],
                    ],
                ],
                [
                    'name' => 'getActiveRoles',
                    'description' => '获取所有启用的角色',
                    'params' => [
                        ['name' => 'limit', 'type' => 'int|null', 'required' => false, 'description' => '限制数量'],
                    ],
                ],
                [
                    'name' => 'getDefaultRole',
                    'description' => '获取默认角色',
                    'params' => [],
                ],
                [
                    'name' => 'getSkills',
                    'description' => '获取技能列表',
                    'params' => [
                        ['name' => 'category', 'type' => 'string|null', 'required' => false, 'description' => '技能分类'],
                        ['name' => 'active_only', 'type' => 'bool', 'required' => false, 'description' => '仅启用的'],
                    ],
                ],
                [
                    'name' => 'getSkill',
                    'description' => '获取单个技能',
                    'params' => [
                        ['name' => 'code', 'type' => 'string', 'required' => true, 'description' => '技能代码'],
                    ],
                ],
                [
                    'name' => 'getSession',
                    'description' => '获取会话',
                    'params' => [
                        ['name' => 'id', 'type' => 'int', 'required' => true, 'description' => '会话ID'],
                    ],
                ],
                [
                    'name' => 'getActiveSessions',
                    'description' => '获取活跃会话',
                    'params' => [
                        ['name' => 'channel', 'type' => 'string', 'required' => false, 'description' => '渠道'],
                        ['name' => 'context_id', 'type' => 'string', 'required' => false, 'description' => '上下文ID'],
                        ['name' => 'limit', 'type' => 'int', 'required' => false, 'description' => '限制数量'],
                    ],
                ],
                [
                    'name' => 'getSchedules',
                    'description' => '获取调度任务',
                    'params' => [
                        ['name' => 'status', 'type' => 'string|null', 'required' => false, 'description' => '状态过滤'],
                    ],
                ],
                [
                    'name' => 'getDueSchedules',
                    'description' => '获取到期的调度任务',
                    'params' => [
                        ['name' => 'limit', 'type' => 'int', 'required' => false, 'description' => '限制数量'],
                    ],
                ],
            ],
        ];
    }

    /**
     * 根据代码获取角色
     */
    private function getRole(array $params): ?array
    {
        $code = $params['code'] ?? '';
        if (empty($code)) {
            return null;
        }

        $role = $this->roleModel->reset()
            ->where(BotRole::schema_fields_CODE, $code)
            ->find()
            ->fetch();

        return $role->getId() ? $role->getData() : null;
    }

    /**
     * 根据 ID 获取角色
     */
    private function getRoleById(array $params): ?array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $role = $this->roleModel->load($id);
        return $role->getId() ? $role->getData() : null;
    }

    /**
     * 获取所有启用的角色
     */
    private function getActiveRoles(array $params): array
    {
        $limit = $params['limit'] ?? 100;

        $roles = $this->roleModel->reset()
            ->where(BotRole::schema_fields_STATUS, BotRole::STATUS_ENABLED)
            ->limit($limit)
            ->select()
            ->fetch();

        return $roles->getItems();
    }

    /**
     * 获取默认角色
     */
    private function getDefaultRole(): ?array
    {
        $role = $this->roleModel->reset()
            ->where(BotRole::schema_fields_IS_DEFAULT, 1)
            ->where(BotRole::schema_fields_STATUS, BotRole::STATUS_ENABLED)
            ->find()
            ->fetch();

        return $role->getId() ? $role->getData() : null;
    }

    /**
     * 获取技能列表
     */
    private function getSkills(array $params): array
    {
        $category = $params['category'] ?? null;
        $activeOnly = $params['active_only'] ?? true;

        $skills = $this->skillModel->reset();

        if ($category) {
            $skills->where(BotSkill::schema_fields_CATEGORY, $category);
        }
        if ($activeOnly) {
            $skills->where(BotSkill::schema_fields_IS_ACTIVE, 1);
        }

        $skills->select()->fetch();
        return $skills->getItems();
    }

    /**
     * 获取单个技能
     */
    private function getSkill(array $params): ?array
    {
        $code = $params['code'] ?? '';
        if (empty($code)) {
            return null;
        }

        $skill = $this->skillModel->reset()
            ->where(BotSkill::schema_fields_CODE, $code)
            ->find()
            ->fetch();

        return $skill->getId() ? $skill->getData() : null;
    }

    /**
     * 获取会话
     */
    private function getSession(array $params): ?array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $session = $this->sessionModel->load($id);
        return $session->getId() ? $session->getData() : null;
    }

    /**
     * 获取活跃会话
     */
    private function getActiveSessions(array $params): array
    {
        $channel = $params['channel'] ?? null;
        $contextId = $params['context_id'] ?? null;
        $limit = $params['limit'] ?? 20;

        $sessions = $this->sessionModel->reset()
            ->where(BotChatSession::schema_fields_STATUS, BotChatSession::STATUS_ACTIVE);

        if ($channel) {
            $sessions->where(BotChatSession::schema_fields_CHANNEL, $channel);
        }
        if ($contextId) {
            $sessions->where(BotChatSession::schema_fields_CONTEXT_ID, $contextId);
        }

        $sessions->order(BotChatSession::schema_fields_UPDATED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetch();

        return $sessions->getItems();
    }

    /**
     * 获取调度任务
     */
    private function getSchedules(array $params): array
    {
        $status = $params['status'] ?? null;

        $schedules = $this->scheduleModel->reset();

        if ($status) {
            $schedules->where(BotSchedule::schema_fields_STATUS, $status);
        }

        $schedules->order(BotSchedule::schema_fields_SCHEDULE_ID, 'DESC')
            ->limit(100)
            ->select()
            ->fetch();

        return $schedules->getItems();
    }

    /**
     * 获取到期的调度任务
     */
    private function getDueSchedules(array $params): array
    {
        $limit = $params['limit'] ?? 10;
        $now = time();

        $schedules = $this->scheduleModel->reset()
            ->where(BotSchedule::schema_fields_STATUS, BotSchedule::STATUS_ENABLED)
            ->where(BotSchedule::schema_fields_NEXT_RUN_AT, $now, '<=')
            ->limit($limit)
            ->select()
            ->fetch();

        return $schedules->getItems();
    }

    /**
     * 自省接口
     */
    private function introspect(array $params): array
    {
        $what = $params['what'] ?? 'providers';

        return match ($what) {
            'providers' => [$this->getProviderName() => $this->getDescriptor()],
            'operations' => $this->getDescriptor()['operations'],
            'operation' => $this->getOperationDetail($params['operation'] ?? ''),
            default => $this->getDescriptor(),
        };
    }

    /**
     * 获取操作详情
     */
    private function getOperationDetail(string $operation): ?array
    {
        foreach ($this->getDescriptor()['operations'] as $op) {
            if ($op['name'] === $operation) {
                return $op;
            }
        }
        return null;
    }
}
