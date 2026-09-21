<?php

declare(strict_types=1);

namespace Weline\Agent\Service;

use Weline\Agent\Model\AgentChatSession;
use Weline\Agent\Model\AgentRole;
use Weline\Agent\Model\AgentSchedule;
use Weline\Agent\Model\AgentSkill;

/**
 * Backend admin mutations shared by BinQuery + controllers.
 */
class AgentAdminService
{
    public function __construct(
        private readonly AgentRole $roleModel,
        private readonly AgentSkill $skillModel,
        private readonly AgentSchedule $scheduleModel,
        private readonly AgentChatSession $sessionModel,
        private readonly ChatSessionManager $sessionManager,
        private readonly RoleConfigAssistant $roleConfigAssistant,
        private readonly AgentEngine $agentEngine,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array{success: bool, msg: string, data?: array<string, mixed>}
     */
    public function saveRole(array $payload): array
    {
        $id = (int)($payload['id'] ?? 0);
        $code = trim((string)($payload['code'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        $systemPrompt = (string)($payload['system_prompt'] ?? '');
        $modelId = (int)($payload['model_id'] ?? 0);
        $scenarioAdapterCode = trim((string)($payload['scenario_adapter_code'] ?? ''));
        $status = (string)($payload['status'] ?? AgentRole::STATUS_ENABLED);
        $description = (string)($payload['description'] ?? '');
        $icon = (string)($payload['icon'] ?? 'mdi-robot');

        $permissions = $this->parseStringList(
            $payload['permissions'] ?? [],
            (string)($payload['permissions_text'] ?? '')
        );
        $selectedSkills = $this->parseStringList($payload['skills'] ?? []);
        $modelConfig = $this->parseJsonArray(
            $payload['model_config'] ?? null,
            (string)($payload['model_config_text'] ?? '')
        );

        if ($code === '' || $name === '') {
            return ['success' => false, 'msg' => (string)__('角色代码和名称必填')];
        }

        $existingRole = $this->roleModel->reset()
            ->where(AgentRole::schema_fields_CODE, $code)
            ->find()
            ->fetch();

        if ($existingRole->getId() && (int)$existingRole->getId() !== $id) {
            return ['success' => false, 'msg' => (string)__('角色代码已存在')];
        }

        $role = $id > 0 ? $this->roleModel->load($id) : clone $this->roleModel;
        if ($id <= 0) {
            $role->setData(AgentRole::schema_fields_IS_DEFAULT, 0);
        }
        $role->setData(AgentRole::schema_fields_CODE, $code);
        $role->setData(AgentRole::schema_fields_NAME, $name);
        $role->setData(AgentRole::schema_fields_SYSTEM_PROMPT, $systemPrompt);
        $role->setData(AgentRole::schema_fields_STATUS, $status);
        $role->setData(AgentRole::schema_fields_DESCRIPTION, $description);
        $role->setData(AgentRole::schema_fields_ICON, $icon !== '' ? $icon : 'mdi-robot');
        $role->setData(AgentRole::schema_fields_MODEL_ID, $modelId > 0 ? $modelId : null);
        $role->setData(
            AgentRole::schema_fields_SCENARIO_ADAPTER_CODE,
            $scenarioAdapterCode !== '' ? $scenarioAdapterCode : null
        );
        $role->setPermissions($permissions);
        $role->setSkills($selectedSkills);
        $role->setModelConfig($modelConfig);
        $role->save();

        return [
            'success' => true,
            'msg' => (string)__('保存成功'),
            'data' => ['id' => (int)$role->getId()],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{success: bool, msg: string, data?: mixed}
     */
    public function suggestRole(array $payload): array
    {
        $skills = $this->listActiveSkillsForSuggest();
        $models = [];
        $adapters = [];

        $suggestion = $this->roleConfigAssistant->buildSuggestion($payload, $models, $adapters, $skills);

        return [
            'success' => true,
            'msg' => (string)__('建议已生成'),
            'data' => $suggestion,
        ];
    }

    /**
     * @return array{success: bool, msg: string}
     */
    public function deleteRole(int $id): array
    {
        $role = $this->roleModel->load($id);
        if (!$role->getId()) {
            return ['success' => false, 'msg' => (string)__('角色不存在')];
        }
        if ($role->isDefault()) {
            return ['success' => false, 'msg' => (string)__('默认角色不可删除')];
        }
        $role->delete();
        return ['success' => true, 'msg' => (string)__('删除成功')];
    }

    /**
     * @return array{success: bool, msg: string, data?: array<string, mixed>}
     */
    public function toggleRole(int $id): array
    {
        $role = $this->roleModel->load($id);
        if (!$role->getId()) {
            return ['success' => false, 'msg' => (string)__('角色不存在')];
        }
        $newStatus = $role->isEnabled() ? AgentRole::STATUS_DISABLED : AgentRole::STATUS_ENABLED;
        $role->setData(AgentRole::schema_fields_STATUS, $newStatus);
        $role->save();
        return [
            'success' => true,
            'msg' => (string)__('状态已更新'),
            'data' => ['status' => $newStatus],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{success: bool, msg: string, data?: array<string, mixed>}
     */
    public function saveSchedule(array $payload): array
    {
        $id = (int)($payload['id'] ?? 0);
        $roleId = (int)($payload['role_id'] ?? 0);
        $name = trim((string)($payload['name'] ?? ''));
        $description = (string)($payload['description'] ?? '');
        $triggerExpr = (string)($payload['trigger_expr'] ?? '');
        $prompt = trim((string)($payload['prompt'] ?? ''));
        $context = $payload['context'] ?? [];
        $status = (string)($payload['status'] ?? AgentSchedule::STATUS_ENABLED);

        if ($name === '' || $prompt === '') {
            return ['success' => false, 'msg' => (string)__('任务名称和提示词不能为空')];
        }

        $schedule = $id > 0 ? $this->scheduleModel->load($id) : clone $this->scheduleModel;
        $schedule->setData(AgentSchedule::schema_fields_ROLE_ID, $roleId);
        $schedule->setData(AgentSchedule::schema_fields_NAME, $name);
        $schedule->setData(AgentSchedule::schema_fields_DESCRIPTION, $description);
        $schedule->setData(AgentSchedule::schema_fields_TRIGGER_TYPE, AgentSchedule::TRIGGER_CRON);
        $schedule->setData(AgentSchedule::schema_fields_TRIGGER_EXPR, $triggerExpr);
        $schedule->setData(AgentSchedule::schema_fields_PROMPT, $prompt);
        $schedule->setData(AgentSchedule::schema_fields_STATUS, $status);

        if (is_string($context)) {
            $decoded = json_decode($context, true);
            $context = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($context)) {
            $context = [];
        }
        $schedule->setContext($context);
        $schedule->setData(AgentSchedule::schema_fields_NEXT_RUN_AT, $schedule->calculateNextRun());
        $schedule->save();

        return [
            'success' => true,
            'msg' => (string)__('保存成功'),
            'data' => ['id' => (int)$schedule->getId()],
        ];
    }

    /**
     * @return array{success: bool, msg: string}
     */
    public function deleteSchedule(int $id): array
    {
        $schedule = $this->scheduleModel->load($id);
        if (!$schedule->getId()) {
            return ['success' => false, 'msg' => (string)__('任务不存在')];
        }
        $schedule->delete();
        return ['success' => true, 'msg' => (string)__('删除成功')];
    }

    /**
     * @return array{success: bool, msg: string, data?: array<string, mixed>}
     */
    public function toggleSchedule(int $id): array
    {
        $schedule = $this->scheduleModel->load($id);
        if (!$schedule->getId()) {
            return ['success' => false, 'msg' => (string)__('任务不存在')];
        }
        $newStatus = $schedule->isEnabled() ? AgentSchedule::STATUS_DISABLED : AgentSchedule::STATUS_ENABLED;
        $schedule->setData(AgentSchedule::schema_fields_STATUS, $newStatus);
        $schedule->save();
        return [
            'success' => true,
            'msg' => (string)__('状态已更新'),
            'data' => ['status' => $newStatus],
        ];
    }

    /**
     * @return array{success: bool, msg: string}
     */
    public function runSchedule(int $id): array
    {
        $schedule = $this->scheduleModel->load($id);
        if (!$schedule->getId()) {
            return ['success' => false, 'msg' => (string)__('任务不存在')];
        }
        return ['success' => true, 'msg' => (string)__('任务已加入执行队列')];
    }

    /**
     * @return array{success: bool, msg: string, data?: array<string, mixed>}
     */
    public function toggleSkill(int $id): array
    {
        $skill = $this->skillModel->load($id);
        if (!$skill->getId()) {
            return ['success' => false, 'msg' => (string)__('技能不存在')];
        }
        $newStatus = $skill->isActive() ? 0 : 1;
        $skill->setData(AgentSkill::schema_fields_IS_ACTIVE, $newStatus);
        $skill->save();
        return [
            'success' => true,
            'msg' => (string)__('状态已更新'),
            'data' => ['is_active' => $newStatus],
        ];
    }

    /**
     * @return array{success: bool, msg: string}
     */
    public function archiveSession(int $id): array
    {
        $session = $this->sessionModel->load($id);
        if (!$session->getId()) {
            return ['success' => false, 'msg' => (string)__('会话不存在')];
        }
        $this->sessionManager->archiveSession($session);
        return ['success' => true, 'msg' => (string)__('已归档')];
    }

    /**
     * @return array{success: bool, msg: string}
     */
    public function deleteSession(int $id): array
    {
        $session = $this->sessionModel->load($id);
        if (!$session->getId()) {
            return ['success' => false, 'msg' => (string)__('会话不存在')];
        }
        $this->sessionManager->deleteSession($session);
        return ['success' => true, 'msg' => (string)__('已删除')];
    }

    /**
     * Backend chat console: send one user message and run AgentEngine.
     *
     * @param array<string, mixed> $payload
     * @return array{success: bool, msg: string, data?: array<string, mixed>}
     */
    public function sendMessage(array $payload): array
    {
        $message = trim((string)($payload['message'] ?? ''));
        $roleCode = trim((string)($payload['role_code'] ?? ''));
        $sessionId = (int)($payload['session_id'] ?? 0);
        $contextId = trim((string)($payload['context_id'] ?? ''));
        $channel = trim((string)($payload['channel'] ?? AgentChatSession::CHANNEL_WEB));
        if ($channel === '') {
            $channel = AgentChatSession::CHANNEL_WEB;
        }

        if ($message === '') {
            return ['success' => false, 'msg' => (string)__('消息不能为空')];
        }
        if ($roleCode === '') {
            return ['success' => false, 'msg' => (string)__('请先选择角色')];
        }

        $role = $this->roleModel->reset()
            ->where(AgentRole::schema_fields_CODE, $roleCode)
            ->where(AgentRole::schema_fields_STATUS, AgentRole::STATUS_ENABLED)
            ->find()
            ->fetch();
        if (!$role->getId()) {
            return ['success' => false, 'msg' => (string)__('角色不存在或已禁用')];
        }

        if ($sessionId > 0) {
            $session = $this->sessionManager->getSession($sessionId);
            if ($session === null) {
                return ['success' => false, 'msg' => (string)__('会话不存在')];
            }
            if ((int)$session->getData(AgentChatSession::schema_fields_ROLE_ID) !== (int)$role->getId()) {
                return ['success' => false, 'msg' => (string)__('会话与角色不匹配')];
            }
        } else {
            $session = $this->sessionManager->getOrCreateSession(
                $role,
                $channel,
                $contextId !== '' ? $contextId : ('backend-console-' . (string)$role->getId())
            );
        }

        $result = $this->agentEngine->execute($message, $role, $session);
        if (!$result->success) {
            return [
                'success' => false,
                'msg' => (string)($result->error ?: __('对话失败')),
                'data' => [
                    'session_id' => (int)$session->getId(),
                    'content' => (string)$result->content,
                    'tool_calls' => is_array($result->toolCalls ?? null) ? $result->toolCalls : [],
                ],
            ];
        }

        return [
            'success' => true,
            'msg' => (string)__('回复已生成'),
            'data' => [
                'session_id' => (int)$session->getId(),
                'content' => (string)$result->content,
                'tool_calls' => is_array($result->toolCalls ?? null) ? $result->toolCalls : [],
            ],
        ];
    }

    /**
     * @return array{success: bool, msg: string, data?: array<string, mixed>}
     */
    public function getChatHistory(int $sessionId, int $limit = 50): array
    {
        if ($sessionId <= 0) {
            return ['success' => false, 'msg' => (string)__('会话 ID 无效')];
        }
        $session = $this->sessionManager->getSession($sessionId);
        if ($session === null) {
            return ['success' => false, 'msg' => (string)__('会话不存在')];
        }

        $limit = max(1, min(200, $limit));
        $messages = $this->sessionManager->getMessageHistory($session, $limit);
        $rows = [];
        foreach ($messages as $message) {
            if (is_object($message) && method_exists($message, 'getData')) {
                $rows[] = $message->getData();
            } elseif (is_array($message)) {
                $rows[] = $message;
            }
        }

        return [
            'success' => true,
            'msg' => (string)__('成功'),
            'data' => [
                'session' => $session->getData(),
                'messages' => $rows,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listActiveSkillsForSuggest(): array
    {
        $skills = $this->skillModel->reset()
            ->where(AgentSkill::schema_fields_IS_ACTIVE, 1)
            ->order(AgentSkill::schema_fields_CATEGORY, 'ASC')
            ->select()
            ->fetch();

        $data = [];
        foreach ($skills->getItems() as $skill) {
            $data[] = [
                'id' => (int)$skill->getId(),
                'code' => (string)$skill->getData(AgentSkill::schema_fields_CODE),
                'name' => (string)$skill->getData(AgentSkill::schema_fields_NAME),
                'description' => (string)$skill->getData(AgentSkill::schema_fields_DESCRIPTION),
                'category' => (string)$skill->getData(AgentSkill::schema_fields_CATEGORY),
                'is_dangerous' => (int)$skill->getData(AgentSkill::schema_fields_IS_DANGEROUS),
                'requires_confirmation' => (int)$skill->getData(AgentSkill::schema_fields_REQUIRES_CONFIRMATION),
            ];
        }
        return $data;
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function parseStringList(mixed $raw, string $textFallback = ''): array
    {
        $values = [];
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                $raw = preg_split('/[\r\n,]+/', $raw) ?: [];
            }
        }
        if (is_array($raw)) {
            foreach ($raw as $item) {
                $value = trim((string)$item);
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }
        if ($values === [] && $textFallback !== '') {
            foreach (preg_split('/[\r\n,]+/', $textFallback) ?: [] as $item) {
                $value = trim((string)$item);
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }
        return array_values(array_unique($values));
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    private function parseJsonArray(mixed $raw, string $textFallback = ''): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $candidate = is_string($raw) && trim($raw) !== '' ? $raw : $textFallback;
        if (trim($candidate) === '') {
            return [];
        }
        $decoded = json_decode($candidate, true);
        return is_array($decoded) ? $decoded : [];
    }
}
