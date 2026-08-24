<?php
declare(strict_types=1);

namespace Weline\Bot\Controller\Api\V1;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Bot\Model\BotRole;
use Weline\Bot\Model\BotChatSession;
use Weline\Bot\Service\AgentEngine;
use Weline\Bot\Service\ChatSessionManager;
use Weline\Framework\Http\Sse\SseWriter;

/**
 * 聊天 API 控制器
 */
class Chat extends FrontendController
{
    public function __construct(
        private readonly AgentEngine $agentEngine,
        private readonly ChatSessionManager $sessionManager,
        private readonly BotRole $roleModel,
        private readonly BotChatSession $sessionModel,
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
        $roleCode = $data['role_code'] ?? 'assistant';
        $channel = $data['channel'] ?? BotChatSession::CHANNEL_WEB;
        $contextId = $data['context_id'] ?? $this->generateContextId();

        if (empty($message)) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('消息不能为空'),
            ]);
        }

        // 获取角色
        $role = $this->roleModel->reset()
            ->where(BotRole::schema_fields_CODE, $roleCode)
            ->where(BotRole::schema_fields_STATUS, BotRole::STATUS_ENABLED)
            ->find()
            ->fetch();

        if (!$role->getId()) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('角色不存在或已禁用'),
            ]);
        }

        // 获取或创建会话
        if ($sessionId > 0) {
            $session = $this->sessionManager->getSession($sessionId);
        } else {
            $session = $this->sessionManager->getOrCreateSession($role, $channel, $contextId);
        }

        if (!$session) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('会话不存在'),
            ]);
        }

        try {
            // 执行对话
            $result = $this->agentEngine->execute($message, $role, $session);

            return $this->fetchJson([
                'success' => $result->success,
                'msg' => $result->success ? __('成功') : $result->error,
                'data' => [
                    'session_id' => $session->getId(),
                    'content' => $result->content,
                    'tool_calls' => $result->toolCalls,
                ],
            ]);

        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'msg' => $e->getMessage(),
            ]);
        }
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
        $roleCode = $data['role_code'] ?? 'assistant';
        $channel = $data['channel'] ?? BotChatSession::CHANNEL_WEB;
        $contextId = $data['context_id'] ?? $this->generateContextId();

        if (empty($message)) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('消息不能为空'),
            ]);
        }

        // 获取角色
        $role = $this->roleModel->reset()
            ->where(BotRole::schema_fields_CODE, $roleCode)
            ->where(BotRole::schema_fields_STATUS, BotRole::STATUS_ENABLED)
            ->find()
            ->fetch();

        if (!$role->getId()) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('角色不存在或已禁用'),
            ]);
        }

        // 获取或创建会话
        if ($sessionId > 0) {
            $session = $this->sessionManager->getSession($sessionId);
        } else {
            $session = $this->sessionManager->getOrCreateSession($role, $channel, $contextId);
        }

        if (!$session) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('会话不存在'),
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

        // 流式执行
        foreach ($this->agentEngine->executeStream($message, $role, $session) as $chunk) {
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
        $channel = $this->request->getParam('channel', BotChatSession::CHANNEL_WEB);
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
