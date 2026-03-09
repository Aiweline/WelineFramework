<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Service;

use Weline\CustomerService\Model\ChatMessage;
use Weline\CustomerService\Model\ChatSession;
use Weline\CustomerService\Model\CustomerLanguage;
use Weline\CustomerService\Model\ServiceAgent;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Text;

/**
 * 聊天服务
 * 处理聊天会话和消息的核心逻辑
 */
class ChatService
{
    private TranslationService $translationService;

    public function __construct(
        TranslationService $translationService
    ) {
        $this->translationService = $translationService;
    }

    /**
     * 创建或获取会话
     * 
     * @param int|null $customerId 客户ID
     * @param string|null $sessionToken 会话令牌（如果已存在）
     * @param string $customerLocale 客户语言
     * @return ChatSession
     */
    public function getOrCreateSession(
        ?int $customerId = null,
        ?string $sessionToken = null,
        string $customerLocale = 'zh_Hans_CN'
    ): ChatSession {
        /** @var ChatSession $session */
        $session = ObjectManager::getInstance(ChatSession::class);

        // 如果提供了会话令牌，尝试查找现有会话
        if (!empty($sessionToken)) {
            $session->where(ChatSession::schema_fields_SESSION_TOKEN, $sessionToken)
                ->find()
                ->fetch();
            
            if ($session->getId()) {
                return $session;
            }
        }

        // 如果提供了客户ID，尝试查找该客户的活跃会话
        if ($customerId) {
            $session->reset()
                ->where(ChatSession::schema_fields_CUSTOMER_ID, $customerId)
                ->where(ChatSession::schema_fields_STATUS, ChatSession::STATUS_ACTIVE)
                ->find()
                ->fetch();
            
            if ($session->getId()) {
                return $session;
            }
        }

        // 创建新会话
        $session->reset();
        $session->setCustomerId($customerId)
            ->setSessionToken($this->generateSessionToken())
            ->setCustomerLocale($customerLocale)
            ->setAgentLocale('zh_Hans_CN') // 默认客服语言
            ->setStatus(ChatSession::STATUS_WAITING)
            ->setData(ChatSession::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
            ->setData(ChatSession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        // 尝试分配客服
        $this->assignAgent($session);

        return $session;
    }

    /**
     * 分配客服
     * 
     * @param ChatSession $session
     * @return bool
     */
    public function assignAgent(ChatSession $session): bool
    {
        // 查找可用的客服（激活状态且未达到最大会话数）
        /** @var ServiceAgent $agent */
        $agent = ObjectManager::getInstance(ServiceAgent::class);
        
        $agents = $agent->reset()
            ->where(ServiceAgent::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch()
            ->getItems();

        foreach ($agents as $agentData) {
            $agent->setData($agentData);
            
            // 检查该客服的当前会话数
            $currentSessions = $this->getAgentActiveSessionCount($agent->getId());
            
            if ($currentSessions < $agent->getMaxSessions()) {
                // 分配客服
                $session->setAgentId($agent->getId())
                    ->setAgentLocale($agent->getLocale())
                    ->setStatus(ChatSession::STATUS_ACTIVE)
                    ->setData(ChatSession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                    ->save();
                
                return true;
            }
        }

        return false;
    }

    /**
     * 获取客服的活跃会话数
     * 
     * @param int $agentId
     * @return int
     */
    private function getAgentActiveSessionCount(int $agentId): int
    {
        /** @var ChatSession $session */
        $session = ObjectManager::getInstance(ChatSession::class);
        
        $count = $session->reset()
            ->where(ChatSession::schema_fields_AGENT_ID, $agentId)
            ->where(ChatSession::schema_fields_STATUS, ChatSession::STATUS_ACTIVE)
            ->count();
        
        return (int)$count;
    }

    /**
     * 发送消息
     * 
     * @param int $sessionId 会话ID
     * @param string $senderType 发送者类型（customer/agent）
     * @param int $senderId 发送者ID
     * @param string $content 消息内容
     * @return ChatMessage
     */
    public function sendMessage(
        int $sessionId,
        string $senderType,
        int $senderId,
        string $content
    ): ChatMessage {
        /** @var ChatSession $session */
        $session = ObjectManager::getInstance(ChatSession::class);
        $session->load($sessionId);
        
        if (!$session->getId()) {
            throw new \Exception(__('会话不存在'));
        }

        // 确定源语言和目标语言
        $sourceLocale = $senderType === ChatMessage::SENDER_TYPE_CUSTOMER 
            ? $session->getCustomerLocale() 
            : $session->getAgentLocale();
        
        $targetLocale = $senderType === ChatMessage::SENDER_TYPE_CUSTOMER 
            ? $session->getAgentLocale() 
            : $session->getCustomerLocale();

        // 翻译消息
        $translatedContent = $this->translationService->translate(
            $content,
            $targetLocale,
            $sourceLocale,
            (string)$sessionId
        );

        // 保存消息
        /** @var ChatMessage $message */
        $message = ObjectManager::getInstance(ChatMessage::class);
        $message->setSessionId($sessionId)
            ->setSenderType($senderType)
            ->setSenderId($senderId)
            ->setContent($content)
            ->setTranslatedContent($translatedContent)
            ->setSourceLocale($sourceLocale)
            ->setTargetLocale($targetLocale)
            ->setIsTranslated($translatedContent !== $content)
            ->setData(ChatMessage::schema_fields_created_at, date('Y-m-d H:i:s'))
            ->save();

        // 更新会话时间
        $session->setData(ChatSession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return $message;
    }

    /**
     * 获取会话消息列表
     * 
     * @param int $sessionId 会话ID
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array
     */
    public function getMessages(int $sessionId, int $limit = 50, int $offset = 0): array
    {
        /** @var ChatMessage $message */
        $message = ObjectManager::getInstance(ChatMessage::class);
        
        $messages = $message->reset()
            ->where(ChatMessage::schema_fields_session_id, $sessionId)
            ->order(ChatMessage::schema_fields_created_at, 'DESC')
            ->limit($limit, $offset)
            ->select()
            ->fetch()
            ->getItems();

        // 反转数组，按时间正序排列
        return array_reverse($messages);
    }

    /**
     * 获取客户语言配置
     * 
     * @param int|null $customerId 客户ID
     * @param string|null $sessionId 会话ID
     * @param string|null $email 邮箱
     * @return string 语言代码
     */
    public function getCustomerLocale(
        ?int $customerId = null,
        ?string $sessionId = null,
        ?string $email = null
    ): string {
        /** @var CustomerLanguage $language */
        $language = ObjectManager::getInstance(CustomerLanguage::class);

        if ($customerId) {
            $language->where(CustomerLanguage::schema_fields_customer_id, $customerId)
                ->find()
                ->fetch();
        } elseif ($sessionId) {
            $language->reset()
                ->where(CustomerLanguage::schema_fields_session_id, $sessionId)
                ->find()
                ->fetch();
        } elseif ($email) {
            $language->reset()
                ->where(CustomerLanguage::schema_fields_email, $email)
                ->find()
                ->fetch();
        }

        if ($language->getId()) {
            return $language->getTargetLocale();
        }

        return 'zh_Hans_CN'; // 默认语言
    }

    /**
     * 设置客户语言配置
     * 
     * @param string $locale 语言代码
     * @param int|null $customerId 客户ID
     * @param string|null $sessionId 会话ID
     * @param string|null $email 邮箱
     * @return CustomerLanguage
     */
    public function setCustomerLocale(
        string $locale,
        ?int $customerId = null,
        ?string $sessionId = null,
        ?string $email = null
    ): CustomerLanguage {
        /** @var CustomerLanguage $language */
        $language = ObjectManager::getInstance(CustomerLanguage::class);

        // 查找现有配置
        if ($customerId) {
            $language->where(CustomerLanguage::schema_fields_customer_id, $customerId)
                ->find()
                ->fetch();
        } elseif ($sessionId) {
            $language->reset()
                ->where(CustomerLanguage::schema_fields_session_id, $sessionId)
                ->find()
                ->fetch();
        } elseif ($email) {
            $language->reset()
                ->where(CustomerLanguage::schema_fields_email, $email)
                ->find()
                ->fetch();
        }

        // 更新或创建
        $language->setCustomerId($customerId)
            ->setSessionId($sessionId)
            ->setEmail($email)
            ->setTargetLocale($locale)
            ->setData(CustomerLanguage::schema_fields_updated_at, date('Y-m-d H:i:s'));
        
        if (!$language->getId()) {
            $language->setData(CustomerLanguage::schema_fields_created_at, date('Y-m-d H:i:s'));
        }
        
        $language->save();

        return $language;
    }

    /**
     * 生成会话令牌
     * 
     * @return string
     */
    private function generateSessionToken(): string
    {
        return Text::random_string(32);
    }
}

