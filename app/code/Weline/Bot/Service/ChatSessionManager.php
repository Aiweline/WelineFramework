<?php
declare(strict_types=1);

namespace Weline\Bot\Service;

use Weline\Bot\Model\BotRole;
use Weline\Bot\Model\BotChatSession;
use Weline\Bot\Model\BotChatMessage;

/**
 * 聊天会话管理器
 *
 * 管理用户与 AI 的对话会话
 */
class ChatSessionManager
{
    public function __construct(
        private readonly BotChatSession $sessionModel,
        private readonly BotChatMessage $messageModel,
    ) {}

    /**
     * 创建新会话
     */
    public function createSession(
        BotRole $role,
        string $channel = BotChatSession::CHANNEL_WEB,
        string $contextId = '',
        array $metadata = []
    ): BotChatSession {
        $session = $this->sessionModel;
        $session->setData(BotChatSession::schema_fields_ROLE_ID, $role->getId());
        $session->setData(BotChatSession::schema_fields_CHANNEL, $channel);
        $session->setData(BotChatSession::schema_fields_CONTEXT_ID, $contextId ?: $this->generateContextId());
        $session->setData(BotChatSession::schema_fields_STATUS, BotChatSession::STATUS_ACTIVE);
        $session->setMetadata($metadata);
        $session->save();

        return $session;
    }

    /**
     * 获取或创建会话
     */
    public function getOrCreateSession(
        BotRole $role,
        string $channel,
        string $contextId
    ): BotChatSession {
        // 尝试查找现有活跃会话
        $existingSession = $this->sessionModel->reset()
            ->where(BotChatSession::schema_fields_ROLE_ID, $role->getId())
            ->where(BotChatSession::schema_fields_CHANNEL, $channel)
            ->where(BotChatSession::schema_fields_CONTEXT_ID, $contextId)
            ->where(BotChatSession::schema_fields_STATUS, BotChatSession::STATUS_ACTIVE)
            ->find()
            ->fetch();

        if ($existingSession->getId()) {
            return $existingSession;
        }

        return $this->createSession($role, $channel, $contextId);
    }

    /**
     * 获取会话
     */
    public function getSession(int $sessionId): ?BotChatSession
    {
        $session = $this->sessionModel->load($sessionId);
        return $session->getId() ? $session : null;
    }

    /**
     * 添加消息到会话
     */
    public function addMessage(
        BotChatSession $session,
        string $role,
        string $content,
        array $toolCalls = [],
        string $toolName = '',
        string $toolCallId = ''
    ): BotChatMessage {
        $message = $this->messageModel;
        $message->setData(BotChatMessage::schema_fields_SESSION_ID, $session->getId());
        $message->setData(BotChatMessage::schema_fields_ROLE, $role);
        $message->setData(BotChatMessage::schema_fields_CONTENT, $content);

        if (!empty($toolCalls)) {
            $message->setToolCalls($toolCalls);
        }

        if ($role === BotChatMessage::ROLE_TOOL) {
            if (!empty($toolName)) {
                $message->setData(BotChatMessage::schema_fields_TOOL_NAME, $toolName);
            }
            if (!empty($toolCallId)) {
                $message->setData(BotChatMessage::schema_fields_TOOL_CALL_ID, $toolCallId);
            }
        }

        $message->save();

        // 更新会话统计
        $session->incrementMessageCount();
        $session->save();

        return $message;
    }

    /**
     * 获取会话消息历史
     *
     * @param BotChatSession $session
     * @param int $limit 最大消息数
     * @return array
     */
    public function getMessageHistory(BotChatSession $session, int $limit = 50): array
    {
        $messages = $this->messageModel->reset()
            ->where(BotChatMessage::schema_fields_SESSION_ID, $session->getId())
            ->order(BotChatMessage::schema_fields_CREATED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetch();

        // 反转顺序，使最早的消息在前
        $items = $messages->getItems();
        return array_reverse($items);
    }

    /**
     * 获取会话消息（OpenAI 格式）
     */
    public function getMessagesForOpenAI(BotChatSession $session, int $limit = 50): array
    {
        $history = $this->getMessageHistory($session, $limit);
        $messages = [];

        foreach ($history as $message) {
            $messages[] = $message->toOpenAIMessage();
        }

        return $messages;
    }

    /**
     * 清除会话历史
     */
    public function clearHistory(BotChatSession $session): void
    {
        // 删除所有消息
        $this->messageModel->reset()
            ->where(BotChatMessage::schema_fields_SESSION_ID, $session->getId())
            ->delete();

        // 重置消息计数
        $session->setData(BotChatSession::schema_fields_MESSAGE_COUNT, 0);
        $session->setData(BotChatSession::schema_fields_TOTAL_TOKENS, 0);
        $session->save();
    }

    /**
     * 归档会话
     */
    public function archiveSession(BotChatSession $session): void
    {
        $session->setData(BotChatSession::schema_fields_STATUS, BotChatSession::STATUS_ARCHIVED);
        $session->save();
    }

    /**
     * 删除会话
     */
    public function deleteSession(BotChatSession $session): void
    {
        // 软删除
        $session->setData(BotChatSession::schema_fields_STATUS, BotChatSession::STATUS_DELETED);
        $session->save();
    }

    /**
     * 获取用户的活跃会话列表
     */
    public function getActiveSessions(
        string $channel,
        string $contextId,
        int $limit = 20
    ): array {
        $sessions = $this->sessionModel->reset()
            ->where(BotChatSession::schema_fields_CHANNEL, $channel)
            ->where(BotChatSession::schema_fields_CONTEXT_ID, $contextId)
            ->where(BotChatSession::schema_fields_STATUS, BotChatSession::STATUS_ACTIVE)
            ->order(BotChatSession::schema_fields_UPDATED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetch();

        return $sessions->getItems();
    }

    /**
     * 生成上下文 ID
     */
    private function generateContextId(): string
    {
        return uniqid('ctx_', true);
    }
}
