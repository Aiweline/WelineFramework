<?php
declare(strict_types=1);

namespace Weline\Agent\Controller\Api\V1;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Agent\Model\AgentRole;
use Weline\Agent\Model\AgentChatSession;
use Weline\Agent\Service\AgentEngine;
use Weline\Agent\Service\AgentRuntimeService;
use Weline\Agent\Service\ChatSessionManager;
use Weline\Framework\Http\Sse\SseWriter;

/**
 * 聊天 API 控制器
 */
class Chat extends FrontendController
{
    public function __construct(
        private readonly AgentEngine $agentEngine,
        private readonly AgentRuntimeService $runtimeService,
        private readonly ChatSessionManager $sessionManager,
        private readonly AgentRole $roleModel,
        private readonly AgentChatSession $sessionModel,
    ) {}

    /**
     * 发送消息
     */
    public function postSend()
    {
        $bodyParams = $this->request->getBodyParams();
        $data = is_string($bodyParams) ? json_decode($bodyParams, true) : $bodyParams;

        $message = $data['message'] ?? '';
        $sessionId = (int) ($data['session_id'] ?? 0);
        $roleCode = trim((string) ($data['role_code'] ?? ''));
        $modelCode = trim((string) ($data['model_code'] ?? ''));
        $projectId = (int) ($data['project_id'] ?? 0);
        $channel = $data['channel'] ?? AgentChatSession::CHANNEL_WEB;
        $contextId = $data['context_id'] ?? $this->generateContextId();

        if (empty($message)) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('消息不能为空'),
            ]);
        }

        // 统一运行时：角色缺省回落默认角色（general），模型/项目可覆盖
        try {
            $turn = [
                'prompt' => $message,
                'session_id' => $sessionId,
                'channel' => $channel,
                'context_id' => $contextId,
            ];
            if ($roleCode !== '') {
                $turn['role_code'] = $roleCode;
            }
            if ($modelCode !== '') {
                $turn['model_code'] = $modelCode;
            }
            if ($projectId > 0) {
                $turn['project_id'] = $projectId;
            }
            $result = $this->runtimeService->runTurn($turn);
            $resolvedSessionId = $this->runtimeService->getLastSessionId() ?: $sessionId;
            $session = $resolvedSessionId > 0
                ? $this->sessionManager->getSession($resolvedSessionId)
                : null;
            $payload = [
                'success' => $result->success,
                'msg' => $result->success ? __('成功') : $result->error,
                'data' => [
                    'session_id' => $session ? (int)$session->getId() : $resolvedSessionId,
                    'content' => $result->content,
                    'tool_calls' => $result->toolCalls,
                ],
            ];
        } catch (\Throwable $e) {
            $payload = [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        return $this->fetchJson($payload);
    }

    /**
     * 流式发送消息 (SSE)
     */
    public function postStream()
    {
        $bodyParams = $this->request->getBodyParams();
        $data = is_string($bodyParams) ? json_decode($bodyParams, true) : $bodyParams;

        $message = $data['message'] ?? '';
        $sessionId = (int) ($data['session_id'] ?? 0);
        $roleCode = trim((string) ($data['role_code'] ?? ''));
        $modelCode = trim((string) ($data['model_code'] ?? ''));
        $projectId = (int) ($data['project_id'] ?? 0);
        $channel = $data['channel'] ?? AgentChatSession::CHANNEL_WEB;
        $contextId = $data['context_id'] ?? $this->generateContextId();

        if (empty($message)) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('消息不能为空'),
            ]);
        }

        // 统一运行时解析（角色缺省→默认角色；模型/项目可覆盖）
        $turn = [
            'prompt' => $message,
            'channel' => $channel,
            'context_id' => $contextId,
        ];
        if ($sessionId > 0) {
            $turn['session_id'] = $sessionId;
        }
        if ($roleCode !== '') {
            $turn['role_code'] = $roleCode;
        }
        if ($modelCode !== '') {
            $turn['model_code'] = $modelCode;
        }
        if ($projectId > 0) {
            $turn['project_id'] = $projectId;
        }

        try {
            $role = $this->runtimeService->resolveRole($turn);
            $session = $this->runtimeService->resolveSession($turn, $role, $this->runtimeService->resolveProject($turn));
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => $e->getMessage(),
            ]);
        }

        // 设置 SSE 头
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // 输出会话 ID
        echo "data: " . json_encode(['type' => 'session', 'session_id' => $session->getId()]) . "\n\n";
        flush();

        // 事件出口 → SSE；stream_callback → content 分片
        $turnOpts = [
            'model_code' => $modelCode,
            'message_meta' => [
                'role_id' => (int)$role->getId(),
                'project_id' => $projectId,
            ],
            'on_event' => static function (string $type, array $data = []): void {
                echo "data: " . json_encode(['type' => 'event', 'event' => $type, 'data' => $data], JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
            },
        ];
        if ($projectId > 0) {
            $turnOpts['project_id'] = $projectId;
        }
        $project = $this->runtimeService->resolveProject($turn);
        if ($project) {
            $turnOpts['project'] = [
                'key' => (string)$project->getData(\Weline\Agent\Model\AgentProject::schema_fields_PROJECT_KEY),
                'name' => (string)$project->getData(\Weline\Agent\Model\AgentProject::schema_fields_NAME),
            ];
        }
        $skillCodes = $this->runtimeService->resolveSkillCodes($role);
        if ($skillCodes !== []) {
            $turnOpts['skill_codes'] = $skillCodes;
        }

        // 流式执行：engine 内部完成 user/assistant 消息落库
        foreach ($this->agentEngine->executeStream($message, $role, $session, null, $turnOpts) as $chunk) {
            echo "data: " . json_encode(['type' => 'content', 'content' => $chunk]) . "\n\n";
            flush();
        }

        echo "data: " . json_encode(['type' => 'done']) . "\n\n";
        flush();

        return '';
    }

    /**
     * 获取会话历史
     */
    public function getHistory()
    {
        $sessionId = (int) $this->request->getParam('session_id', 0);
        $limit = (int) $this->request->getParam('limit', 50);

        if ($sessionId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('会话 ID 无效'),
            ]);
        }

        $session = $this->sessionManager->getSession($sessionId);
        if (!$session) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('会话不存在'),
            ]);
        }

        $messages = $this->sessionManager->getMessageHistory($session, $limit);

        return $this->fetchJson([
            'success' => true,
            'data' => [
                'session' => $session->getData(),
                'messages' => array_map(fn($m) => $m->getData(), $messages),
            ],
        ]);
    }

    /**
     * 清除会话历史
     */
    public function postClear()
    {
        $sessionId = (int) $this->request->getParam('session_id', 0);

        if ($sessionId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('会话 ID 无效'),
            ]);
        }

        $session = $this->sessionManager->getSession($sessionId);
        if (!$session) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('会话不存在'),
            ]);
        }

        $this->sessionManager->clearHistory($session);

        return $this->fetchJson([
            'success' => true,
            'msg' => __('历史已清除'),
        ]);
    }

    /**
     * 获取用户会话列表
     */
    public function getSessions()
    {
        $channel = $this->request->getParam('channel', AgentChatSession::CHANNEL_WEB);
        $contextId = $this->request->getParam('context_id', '');
        $limit = (int) $this->request->getParam('limit', 20);

        if (empty($contextId)) {
            $contextId = $this->generateContextId();
        }

        $sessions = $this->sessionManager->getActiveSessions($channel, $contextId, $limit);

        return $this->fetchJson([
            'success' => true,
            'data' => [
                'sessions' => array_map(fn($s) => $s->getData(), $sessions),
            ],
        ]);
    }

    /**
     * 生成上下文 ID
     */
    private function generateContextId(): string
    {
        return 'user_' . md5(uniqid('', true));
    }
}
