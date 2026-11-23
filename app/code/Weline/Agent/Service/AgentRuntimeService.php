<?php
declare(strict_types=1);

namespace Weline\Agent\Service;

use Weline\Agent\Model\AgentChatSession;
use Weline\Agent\Model\AgentProject;
use Weline\Agent\Model\AgentRole;
use Weline\Framework\Manager\ObjectManager;

/**
 * 通用智能体运行时门面
 *
 * 职责：
 * - 回合解析：session / role / model / project / agent_code 决策表
 * - 项目标记注入与会话创建
 * - 委托 AgentEngine 执行（P0 唯一执行器；task-agent 委托为后续扩展）
 * - 统一事件出口（chunk/tool_call/tool_result/agent_info/done）
 */
final class AgentRuntimeService
{
    private ?int $lastSessionId = null;

    public function __construct(
        private readonly ?AgentEngine $engine = null,
        private readonly ?ChatSessionManager $sessionManager = null,
        private readonly ?EffectiveCapabilityResolver $capabilityResolver = null,
        private readonly ?AgentRole $roleModel = null,
    ) {}

    /** 最近一次 runTurn 解析/创建的会话 ID（供同步调用方回填响应） */
    public function getLastSessionId(): int
    {
        return $this->lastSessionId ?? 0;
    }

    /**
     * 执行一个对话回合
     *
     * @param array $turn 支持键：
     *   - prompt（必填）、session_id、role_id、role_code、model_code、project_id、
     *     agent_code、system_prompt_override、channel、context_id、
     *     on_event(callable(type,data))、stream_callback(callable(content))
     * @return \Weline\Ai\Agent\AgentResult
     */
    public function runTurn(array $turn)
    {
        $prompt = trim((string)($turn['prompt'] ?? ''));
        if ($prompt === '') {
            throw new \InvalidArgumentException('prompt is required');
        }

        $role = $this->resolveRole($turn);
        $project = $this->resolveProject($turn);
        $session = $this->resolveSession($turn, $role, $project);
        $this->lastSessionId = (int)$session->getId();

        // 团队 @派单：项目会话内识别 @role:code / @agent:code，派单结果注入上下文
        $prompt = $this->resolveTeamDispatches($turn, $prompt, $session, $project);

        $agentCode = $this->resolveAgentCode($turn, $role);
        $modelCode = trim((string)($turn['model_code'] ?? ''));

        $projectContext = null;
        if ($project) {
            $projectContext = [
                'key' => (string)$project->getData(AgentProject::schema_fields_PROJECT_KEY),
                'name' => (string)$project->getData(AgentProject::schema_fields_NAME),
            ];
        }

        $messageMeta = [
            'role_id' => (int)$role->getId(),
            'agent_code' => $agentCode,
            'project_id' => $project ? (int)$project->getId() : 0,
        ];
        if ($modelCode !== '') {
            $messageMeta['model_code'] = $modelCode;
        }

        $turnOpts = [
            'message_meta' => $messageMeta,
            'model_code' => $modelCode,
        ];
        $systemOverride = trim((string)($turn['system_prompt_override'] ?? ''));
        if ($systemOverride !== '') {
            $turnOpts['system_prompt_override'] = $systemOverride;
        }
        if ($projectContext !== null) {
            $turnOpts['project'] = $projectContext;
        }

        // 有效技能 = 角色技能 ∪ 插件技能（P0 起注入提示词；工具面在 P3 插件落地后并集）
        $skillCodes = $this->resolveSkillCodes($role);
        if ($skillCodes !== []) {
            $turnOpts['skill_codes'] = $skillCodes;
        }

        // P4 项目知识库：命中 SOP/轨迹注入上下文（项目会话查项目+全局，个人会话查全局）
        try {
            $knowledge = ObjectManager::getInstance(ProjectKnowledgeService::class);
            $knowledgeContext = $knowledge->buildContext($project ? (int)$project->getId() : 0, $prompt, 5);
            if ($knowledgeContext !== '') {
                $turnOpts['knowledge_context'] = $knowledgeContext;
            }
        } catch (\Throwable) {
        }

        $onEvent = $turn['on_event'] ?? null;
        if (is_callable($onEvent)) {
            $emit = static function (string $type, array $data = []) use ($onEvent): void {
                try {
                    $onEvent($type, $data);
                } catch (\Throwable) {
                }
            };
            $turnOpts['on_event'] = $emit;
            $emit('agent_info', [
                'role_id' => (int)$role->getId(),
                'role_code' => (string)$role->getData(AgentRole::schema_fields_CODE),
                'role_name' => (string)$role->getData(AgentRole::schema_fields_NAME),
                'agent_code' => $agentCode,
                'model_code' => $modelCode,
                'session_id' => (int)$session->getId(),
                'project_id' => $project ? (int)$project->getId() : 0,
            ]);
            $emit('chunk', ['content' => '']); // 占位握手，前端建立流
        }

        $streamCallback = $turn['stream_callback'] ?? null;
        $engine = $this->engine ?? ObjectManager::getInstance(AgentEngine::class);

        // P3 工时+轨迹：turn 级 begin/finish，project_id=0 也记录（个人会话全局复盘）
        $projectId = $project ? (int)$project->getId() : 0;
        $ledger = ObjectManager::getInstance(AgentLedgerService::class);
        $worklogId = $ledger->beginWork([
            'project_id' => $projectId,
            'ref_type' => AgentLedgerService::REF_TURN,
            'role_id' => (int)$role->getId(),
            'agent_code' => $agentCode,
            'expected_minutes' => $turn['expected_minutes'] ?? null,
        ]);
        $ledger->track('turn_start', [
            'project_id' => $projectId,
            'ref_type' => AgentLedgerService::REF_TURN,
            'actor_role_id' => (int)$role->getId(),
            'actor_agent_code' => $agentCode,
            'summary' => '会话回合开始：' . mb_substr($prompt, 0, 120),
            'payload' => ['session_id' => (int)$session->getId(), 'worklog_id' => $worklogId],
        ]);

        $turnStarted = microtime(true);
        try {
            $result = $engine->execute(
                $prompt,
                $role,
                $session,
                is_callable($streamCallback) ? $streamCallback : null,
                $turnOpts
            );
        } catch (\Throwable $e) {
            $ledger->finishWork($worklogId, null, 'failed', '执行异常：' . $e->getMessage());
            $ledger->track('turn_failed', [
                'project_id' => $projectId,
                'ref_type' => AgentLedgerService::REF_TURN,
                'actor_role_id' => (int)$role->getId(),
                'actor_agent_code' => $agentCode,
                'summary' => '会话回合失败：' . mb_substr($e->getMessage(), 0, 160),
                'payload' => ['session_id' => (int)$session->getId(), 'worklog_id' => $worklogId],
            ]);
            throw $e;
        }

        $ok = (bool)($result->success ?? false);

        // P5 反思闭环：解析模型输出的 <!--SOP:key--> / <!--REFLECT:摘要--> 标记，
        // 沉淀为项目知识（SOP 去重复用）与轨迹事件
        if ($ok) {
            $this->harvestReflection($projectId, (string)($result->content ?? ''), [
                'role_id' => (int)$role->getId(),
                'agent_code' => $agentCode,
                'session_id' => (int)$session->getId(),
                'prompt' => $prompt,
            ]);
        }

        $actualMinutes = max(1, (int)ceil((microtime(true) - $turnStarted) / 60));
        $ledger->finishWork($worklogId, $actualMinutes, $ok ? 'done' : 'failed', $ok ? null : '回合未成功');
        $ledger->track($ok ? 'turn_done' : 'turn_failed', [
            'project_id' => $projectId,
            'ref_type' => AgentLedgerService::REF_TURN,
            'actor_role_id' => (int)$role->getId(),
            'actor_agent_code' => $agentCode,
            'summary' => ($ok ? '会话回合完成：' : '会话回合失败：') . mb_substr($prompt, 0, 100),
            'payload' => [
                'session_id' => (int)$session->getId(),
                'worklog_id' => $worklogId,
                'actual_minutes' => $actualMinutes,
            ],
        ]);

        return $result;
    }

    /** 角色解析：role_id > role_code > 会话默认 > is_default > 首个启用角色 */
    public function resolveRole(array $turn): AgentRole
    {
        $roleId = (int)($turn['role_id'] ?? 0);
        $roleCode = trim((string)($turn['role_code'] ?? ''));

        if ($roleId > 0) {
            $role = $this->loadRole($roleId);
            if ($role) {
                return $role;
            }
        }
        if ($roleCode !== '') {
            $role = $this->findRoleByCode($roleCode);
            if ($role) {
                return $role;
            }
        }

        // 会话默认角色
        $sessionId = (int)($turn['session_id'] ?? 0);
        if ($sessionId > 0) {
            try {
                $sm = $this->sessionManager ?? ObjectManager::getInstance(ChatSessionManager::class);
                $session = $sm->getSession($sessionId);
                if ($session) {
                    $defaultRoleId = (int)$session->getData(AgentChatSession::schema_fields_DEFAULT_ROLE_ID);
                    if ($defaultRoleId <= 0) {
                        $defaultRoleId = (int)$session->getData(AgentChatSession::schema_fields_ROLE_ID);
                    }
                    if ($defaultRoleId > 0) {
                        $role = $this->loadRole($defaultRoleId);
                        if ($role) {
                            return $role;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        // 默认角色
        $role = $this->findDefaultRole();
        if ($role === null) {
            throw new \RuntimeException('No active agent role found');
        }
        return $role;
    }

    /** 项目解析：project_id > project_key；0/缺省 = 个人会话（无项目边界） */
    public function resolveProject(array $turn): ?AgentProject
    {
        $projectId = (int)($turn['project_id'] ?? 0);
        $projectKey = trim((string)($turn['project_key'] ?? ''));
        if ($projectId <= 0 && $projectKey === '') {
            return null;
        }

        try {
            $prototype = ObjectManager::getInstance(AgentProject::class);
        } catch (\Throwable) {
            return null;
        }

        $project = clone $prototype;
        if ($projectId > 0) {
            $project->load($projectId);
        } else {
            $project->reset()->clearQuery()
                ->where(AgentProject::schema_fields_PROJECT_KEY, $projectKey)
                ->where(AgentProject::schema_fields_STATUS, AgentProject::STATUS_ACTIVE)
                ->find()->fetch();
        }
        if (!$project->getId() || $project->getData(AgentProject::schema_fields_STATUS) !== AgentProject::STATUS_ACTIVE) {
            return null;
        }
        return $project;
    }

    /** 会话解析：session_id 校验归属；否则按 channel/context/project 创建 */
    public function resolveSession(array $turn, AgentRole $role, ?AgentProject $project): AgentChatSession
    {
        $sm = $this->sessionManager ?? ObjectManager::getInstance(ChatSessionManager::class);
        $sessionId = (int)($turn['session_id'] ?? 0);

        if ($sessionId > 0) {
            $session = $sm->getSession($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found");
            }
            // 项目边界校验：会话已绑定项目时，请求必须同项目
            $sessionProjectId = (int)$session->getData(AgentChatSession::schema_fields_PROJECT_ID);
            $requestProjectId = $project ? (int)$project->getId() : 0;
            if ($sessionProjectId > 0 && $requestProjectId > 0 && $sessionProjectId !== $requestProjectId) {
                throw new \RuntimeException('Session belongs to a different project');
            }
            return $session;
        }

        $channel = (string)($turn['channel'] ?? AgentChatSession::CHANNEL_WEB);
        $contextId = trim((string)($turn['context_id'] ?? ''));
        $options = [];
        if ($project) {
            $options['project_id'] = (int)$project->getId();
        }
        $modelCode = trim((string)($turn['model_code'] ?? ''));
        if ($modelCode !== '') {
            $options['default_model_code'] = $modelCode;
        }
        $options['default_role_id'] = (int)$role->getId();

        return $sm->createSession($role, $channel, $contextId, [], $options);
    }

    /** 智能体代码解析：显式 > role.agent_code > 约定 role_<code>；仅作元数据与后续 task-agent 委托依据 */
    public function resolveAgentCode(array $turn, AgentRole $role): string
    {
        $explicit = trim((string)($turn['agent_code'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }
        $bound = trim((string)$role->getData(AgentRole::schema_fields_AGENT_CODE));
        if ($bound !== '') {
            return $bound;
        }
        return 'role_' . (string)$role->getData(AgentRole::schema_fields_CODE);
    }

    /** @return list<string> */
    public function resolveSkillCodes(AgentRole $role): array
    {
        try {
            $resolver = $this->capabilityResolver ?? ObjectManager::getInstance(EffectiveCapabilityResolver::class);
            return $resolver->resolveSkillCodes($role);
        } catch (\Throwable) {
            return $role->getSkills();
        }
    }

    /**
     * 团队 @派单前置处理（P4）
     *
     * 仅项目会话生效；dispatch 内部同步执行被派角色回合，结果文本
     * 以「团队派单结果」追加到 prompt 供当前角色汇总。skip_mentions
     * 防止派单回合再次触发递归派单。
     */
    private function resolveTeamDispatches(array $turn, string $prompt, AgentChatSession $session, ?AgentProject $project): string
    {
        if (!empty($turn['skip_mentions'])) {
            return $prompt;
        }
        $sessionProjectId = (int)$session->getData(AgentChatSession::schema_fields_PROJECT_ID);
        if ($sessionProjectId <= 0 && $project === null) {
            return $prompt;
        }

        try {
            $dispatcher = ObjectManager::getInstance(TeamDispatchService::class);
            $mentions = $dispatcher->parseMentions($prompt);
        } catch (\Throwable) {
            return $prompt;
        }
        if ($mentions === []) {
            return $prompt;
        }

        $results = [];
        foreach ($mentions as $mention) {
            $targetCode = $mention['kind'] === 'agent'
                ? $this->resolveRoleCodeByAgent($mention['code'])
                : $mention['code'];
            if ($targetCode === '') {
                $results[] = "@{$mention['kind']}:{$mention['code']} —— 无法解析目标角色，已跳过";
                continue;
            }
            // 派单内容 = 去掉 @ 提及后的剩余文本
            $dispatchPrompt = trim((string)preg_replace('/@(role|agent):[A-Za-z0-9_-]+\s*/', '', $prompt));
            if ($dispatchPrompt === '') {
                $dispatchPrompt = '请处理以下请求：' . $prompt;
            }
            $res = $dispatcher->dispatch(
                (int)$session->getId(),
                $targetCode,
                $dispatchPrompt,
                false,
                'user',
                'session_' . (int)$session->getId()
            );
            if (!empty($res['success'])) {
                $results[] = "@{$mention['kind']}:{$mention['code']} ⇒ " . mb_substr((string)($res['result'] ?? ''), 0, 2000);
            } else {
                $results[] = "@{$mention['kind']}:{$mention['code']} ⇒ 派单失败：" . (string)($res['error'] ?? 'unknown');
            }
        }

        if ($results === []) {
            return $prompt;
        }
        $emit = $turn['on_event'] ?? null;
        if (is_callable($emit)) {
            foreach ($results as $line) {
                try {
                    $emit('team_dispatch', ['line' => $line]);
                } catch (\Throwable) {
                }
            }
        }

        return $prompt . "\n\n【团队派单结果（同项目）】\n" . implode("\n", $results);
    }

    /** @agent:code → role_code 反查（agent_code 绑定在角色上） */
    private function resolveRoleCodeByAgent(string $agentCode): string
    {
        try {
            $prototype = $this->roleModel ?? ObjectManager::getInstance(AgentRole::class);
            $role = clone $prototype;
            $role->reset()->clearQuery()
                ->where(AgentRole::schema_fields_AGENT_CODE, $agentCode)
                ->where(AgentRole::schema_fields_STATUS, AgentRole::STATUS_DISABLED, '!=')
                ->limit(1)
                ->find()->fetch();
            return $role->getId() ? (string)$role->getData(AgentRole::schema_fields_CODE) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function loadRole(int $roleId): ?AgentRole
    {
        try {
            $prototype = $this->roleModel ?? ObjectManager::getInstance(AgentRole::class);
            $role = clone $prototype;
            $role->load($roleId);
            if (!$role->getId() || $role->getData(AgentRole::schema_fields_STATUS) === AgentRole::STATUS_DISABLED) {
                return null;
            }
            return $role;
        } catch (\Throwable) {
            return null;
        }
    }

    private function findRoleByCode(string $code): ?AgentRole
    {
        try {
            $prototype = $this->roleModel ?? ObjectManager::getInstance(AgentRole::class);
            $role = clone $prototype;
            $role->reset()->clearQuery()
                ->where(AgentRole::schema_fields_CODE, $code)
                ->find()->fetch();
            if (!$role->getId() || $role->getData(AgentRole::schema_fields_STATUS) === AgentRole::STATUS_DISABLED) {
                return null;
            }
            return $role;
        } catch (\Throwable) {
            return null;
        }
    }

    private function findDefaultRole(): ?AgentRole
    {
        try {
            $prototype = $this->roleModel ?? ObjectManager::getInstance(AgentRole::class);
            $role = clone $prototype;
            $role->reset()->clearQuery()
                ->where(AgentRole::schema_fields_IS_DEFAULT, 1)
                ->find()->fetch();
            if ($role->getId()) {
                return $role;
            }
            $role = clone $prototype;
            $role->reset()->clearQuery()
                ->where(AgentRole::schema_fields_STATUS, AgentRole::STATUS_DISABLED, '!=')
                ->limit(1)
                ->find()->fetch();
            return $role->getId() ? $role : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * P5 反思收割：从回合输出解析 <!--SOP:key-->…<!--/SOP--> 与
     * <!--REFLECT:摘要-->…<!--/REFLECT-->，写入项目知识库/轨迹。
     * 标记为模型自愿输出；解析失败静默（不阻断回合）。
     */
    private function harvestReflection(int $projectId, string $content, array $meta): void
    {
        if ($content === '' || (!str_contains($content, '<!--SOP:') && !str_contains($content, '<!--REFLECT:'))) {
            return;
        }
        try {
            $knowledge = ObjectManager::getInstance(ProjectKnowledgeService::class);
            $ledger = ObjectManager::getInstance(AgentLedgerService::class);

            if (preg_match_all('/<!--SOP:([^>\-]+)-->(.*?)<!--\/SOP-->/su', $content, $mSop, PREG_SET_ORDER)) {
                foreach ($mSop as $m) {
                    $sopKey = trim($m[1]);
                    $sopBody = trim($m[2]);
                    if ($sopKey === '' || $sopBody === '') {
                        continue;
                    }
                    $chunkId = $knowledge->index(
                        $projectId,
                        'sop',
                        'SOP:' . $sopKey,
                        $sopBody . "\n\n来源任务：" . mb_substr((string)($meta['prompt'] ?? ''), 0, 300),
                        'turn/' . ((int)($meta['session_id'] ?? 0)),
                        $sopKey
                    );
                    $ledger->track('sop_recorded', [
                        'project_id' => $projectId,
                        'ref_type' => AgentLedgerService::REF_TURN,
                        'actor_role_id' => (int)($meta['role_id'] ?? 0),
                        'actor_agent_code' => (string)($meta['agent_code'] ?? ''),
                        'summary' => '沉淀 SOP：' . $sopKey,
                        'payload' => ['chunk_id' => $chunkId, 'sop_key' => $sopKey],
                    ]);
                }
            }

            if (preg_match_all('/<!--REFLECT:([^>\-]+)-->(.*?)<!--\/REFLECT-->/su', $content, $mRef, PREG_SET_ORDER)) {
                foreach ($mRef as $m) {
                    $summary = trim($m[1] . ' ' . $m[2]);
                    if ($summary === '') {
                        continue;
                    }
                    $ledger->track('reflection', [
                        'project_id' => $projectId,
                        'ref_type' => AgentLedgerService::REF_TURN,
                        'actor_role_id' => (int)($meta['role_id'] ?? 0),
                        'actor_agent_code' => (string)($meta['agent_code'] ?? ''),
                        'summary' => '任务反思：' . mb_substr($summary, 0, 200),
                        'payload' => ['session_id' => (int)($meta['session_id'] ?? 0)],
                    ]);
                }
            }
        } catch (\Throwable) {
        }
    }
}
