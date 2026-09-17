<?php

declare(strict_types=1);

namespace Weline\CustomerService\Service;

use Weline\Backend\Api\Auth\BackendUserDirectoryInterface;
use Weline\CustomerService\Model\ChatMessage;
use Weline\CustomerService\Model\ChatSession;
use Weline\CustomerService\Model\CustomerLanguage;
use Weline\CustomerService\Model\ServiceAgent;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\System\Text;

class ChatService
{
    /**
     * @var array<string, string>
     */
    private static array $customerViewDisplayCache = [];

    public function __construct(
        private readonly TranslationService $translationService,
        private readonly CustomerServiceSettings $settings,
    ) {
    }

    public function getOrCreateSession(
        ?int $customerId = null,
        ?string $sessionToken = null,
        string $customerLocale = ''
    ): ChatSession {
        if (trim($customerLocale) === '') {
            $customerLocale = $this->settings->defaultCustomerLocale();
        }

        /** @var ChatSession $session */
        $session = ObjectManager::getInstance(ChatSession::class);

        if (!empty($sessionToken)) {
            $session->reset()
                ->where(ChatSession::schema_fields_SESSION_TOKEN, $sessionToken)
                ->find()
                ->fetch();

            if ($session->getId()) {
                $this->syncSessionLocale($session, $customerLocale);
                return $session;
            }
        }

        if ($customerId) {
            $session->reset()
                ->where(ChatSession::schema_fields_CUSTOMER_ID, $customerId)
                ->where(ChatSession::schema_fields_STATUS, ChatSession::STATUS_CLOSED, '!=')
                ->order(ChatSession::schema_fields_UPDATED_AT, 'DESC')
                ->find()
                ->fetch();

            if ($session->getId()) {
                $this->syncSessionLocale($session, $customerLocale);
                return $session;
            }
        }

        $session->reset();
        $session->setCustomerId($customerId)
            ->setSessionToken($this->generateSessionToken())
            ->setCustomerLocale($customerLocale)
            ->setAgentLocale($this->settings->defaultAgentLocale())
            ->setStatus(ChatSession::STATUS_WAITING)
            ->setData(ChatSession::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
            ->setData(ChatSession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        $this->assignAgent($session);

        return $session;
    }

    public function assignAgent(ChatSession $session): bool
    {
        /** @var ServiceAgent $agentModel */
        $agentModel = ObjectManager::getInstance(ServiceAgent::class);

        $agents = $agentModel->reset()
            ->where(ServiceAgent::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch()
            ->getItems();

        $candidates = [];
        foreach ($agents as $agentRow) {
            if ($agentRow instanceof ServiceAgent) {
                $candidate = $agentRow;
            } elseif (is_array($agentRow)) {
                /** @var ServiceAgent $candidate */
                $candidate = ObjectManager::getInstance(ServiceAgent::class);
                $candidate->clear()->setData($agentRow);
            } else {
                continue;
            }

            $agentId = (int)$candidate->getId();
            if ($agentId <= 0) {
                continue;
            }

            $currentSessions = $this->getAgentActiveSessionCount($agentId);
            if ($currentSessions >= (int)$candidate->getMaxSessions()) {
                continue;
            }

            $candidates[] = [
                'agent' => $candidate,
                'agent_id' => $agentId,
                'online' => $candidate->isOnline() ? 1 : 0,
                'load' => $currentSessions,
            ];
        }

        if ($candidates === []) {
            return false;
        }

        $candidates = self::sortAssignCandidates($candidates);
        $best = $candidates[0];
        /** @var ServiceAgent $winner */
        $winner = $best['agent'];
        $session->setAgentId((int)$best['agent_id'])
            ->setAgentLocale((string)$winner->getLocale())
            ->setStatus(ChatSession::STATUS_ACTIVE)
            ->setData(ChatSession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return true;
    }

    /**
     * 分配排序：在线优先，其次最少负载，再按 agent_id 稳定排序。
     *
     * @param list<array{agent?:mixed,agent_id:int,online:int,load:int}> $candidates
     * @return list<array{agent?:mixed,agent_id:int,online:int,load:int}>
     */
    public static function sortAssignCandidates(array $candidates): array
    {
        usort($candidates, static function (array $a, array $b): int {
            if ((int)$a['online'] !== (int)$b['online']) {
                return (int)$b['online'] <=> (int)$a['online'];
            }
            if ((int)$a['load'] !== (int)$b['load']) {
                return (int)$a['load'] <=> (int)$b['load'];
            }

            return (int)$a['agent_id'] <=> (int)$b['agent_id'];
        });

        return $candidates;
    }

    /**
     * 将会话 Model/数组统一为可 JSON 的关联数组（含顶层 session_id）。
     *
     * @param list<ChatSession|array<string, mixed>> $sessions
     * @return list<array<string, mixed>>
     */
    public function normalizeSessionRows(array $sessions): array
    {
        $normalized = [];
        foreach ($sessions as $row) {
            if ($row instanceof ChatSession) {
                $data = $row->getData();
                $data = is_array($data) ? $data : [];
                if (!isset($data[ChatSession::schema_fields_ID]) && $row->getId()) {
                    $data[ChatSession::schema_fields_ID] = (int)$row->getId();
                }
                $normalized[] = $data;
            } elseif (is_array($row)) {
                if (!isset($row[ChatSession::schema_fields_ID]) && isset($row['id'])) {
                    $row[ChatSession::schema_fields_ID] = (int)$row['id'];
                }
                $normalized[] = $row;
            }
        }

        return $normalized;
    }

    /**
     * Newest-first sender types: waiting for reply when the newest message is from the customer.
     *
     * @param list<string> $senderTypesNewestFirst
     */
    public static function isAwaitingAgentReplyFromSenders(array $senderTypesNewestFirst): bool
    {
        if ($senderTypesNewestFirst === []) {
            return false;
        }

        return (string)($senderTypesNewestFirst[0] ?? '') === ChatMessage::SENDER_TYPE_CUSTOMER;
    }

    public function isSessionEmailBound(ChatSession $session): bool
    {
        if ((int)$session->getCustomerId() > 0) {
            return true;
        }

        $token = trim((string)$session->getSessionToken());
        if ($token === '') {
            return false;
        }

        return $this->getGuestEmailBySessionToken($token) !== '';
    }

    public function getGuestEmailBySessionToken(string $sessionToken): string
    {
        $sessionToken = trim($sessionToken);
        if ($sessionToken === '') {
            return '';
        }

        /** @var CustomerLanguage $language */
        $language = ObjectManager::getInstance(CustomerLanguage::class);
        $language->reset()
            ->where(CustomerLanguage::schema_fields_session_id, $sessionToken)
            ->find()
            ->fetch();

        $email = strtolower(trim((string)($language->getEmail() ?? '')));
        /** @var EmailBindingService $emailBinding */
        $emailBinding = ObjectManager::getInstance(EmailBindingService::class);

        return $emailBinding->isValidEmail($email) ? $email : '';
    }

    /**
     * @return array{
     *   kind: string,
     *   display_name: string,
     *   email: string,
     *   avatar_url: string,
     *   can_change_email: bool
     * }
     */
    public function buildSessionIdentity(ChatSession $session, bool $isLoggedIn, ?int $customerId = null): array
    {
        if ($isLoggedIn && $customerId !== null && $customerId > 0) {
            try {
                $accounts = ObjectManager::getInstance(RuntimeProviderResolver::class)
                    ->resolve(\Weline\Customer\Api\Auth\CustomerAccountFacadeInterface::class);
                if ($accounts instanceof \Weline\Customer\Api\Auth\CustomerAccountFacadeInterface) {
                    $identity = $accounts->find($customerId);
                    if ($identity !== null) {
                        $name = trim($identity->getUsername());
                        $email = trim($identity->getEmail());

                        return [
                            'kind' => 'customer',
                            'display_name' => $name !== '' ? $name : ($email !== '' ? $email : (string)__('会员')),
                            'email' => $email,
                            'avatar_url' => trim($identity->getAvatar()),
                            'can_change_email' => false,
                        ];
                    }
                }
            } catch (\Throwable) {
                // Fall through to guest-shaped identity.
            }

            return [
                'kind' => 'customer',
                'display_name' => (string)__('会员'),
                'email' => '',
                'avatar_url' => '',
                'can_change_email' => false,
            ];
        }

        $email = $this->getGuestEmailBySessionToken((string)$session->getSessionToken());

        return [
            'kind' => 'guest',
            'display_name' => $email !== '' ? $email : (string)__('访客'),
            'email' => $email,
            'avatar_url' => '',
            'can_change_email' => true,
        ];
    }

    public function postSystemMessage(int $sessionId, string $content): ChatMessage
    {
        $content = trim($content);
        if ($sessionId <= 0 || $content === '') {
            throw new \InvalidArgumentException('system message requires session and content');
        }

        return $this->sendMessage($sessionId, ChatMessage::SENDER_TYPE_SYSTEM, 0, $content);
    }

    public function isAwaitingAgentReply(int $sessionId): bool
    {
        if ($sessionId <= 0) {
            return false;
        }

        /** @var ChatMessage $message */
        $message = ObjectManager::getInstance(ChatMessage::class);
        $items = $message->reset()
            ->where(ChatMessage::schema_fields_session_id, $sessionId)
            ->order(ChatMessage::schema_fields_created_at, 'DESC')
            ->order(ChatMessage::schema_fields_ID, 'DESC')
            ->limit(8)
            ->select()
            ->fetch()
            ->getItems();

        if ($items === []) {
            return false;
        }

        foreach ($items as $row) {
            $senderType = is_array($row)
                ? (string)($row[ChatMessage::schema_fields_sender_type] ?? '')
                : (string)$row->getSenderType();
            if ($senderType === ChatMessage::SENDER_TYPE_SYSTEM) {
                continue;
            }

            return self::isAwaitingAgentReplyFromSenders([$senderType]);
        }

        return false;
    }

    /**
     * @return array{
     *   gate_active: bool,
     *   email_bound: bool,
     *   awaiting_reply: bool,
     *   can_send: bool,
     *   message: string
     * }
     */
    public function resolveGuestSendGate(int $sessionId, bool $isLoggedIn): array
    {
        $empty = [
            'gate_active' => false,
            'email_bound' => true,
            'awaiting_reply' => false,
            'can_send' => true,
            'message' => '',
        ];

        if ($sessionId <= 0) {
            return $empty;
        }

        /** @var ChatSession $session */
        $session = ObjectManager::getInstance(ChatSession::class);
        $session->load($sessionId);
        if (!$session->getId()) {
            return $empty;
        }

        if ($isLoggedIn) {
            return $empty;
        }

        $emailBound = $this->isSessionEmailBound($session);
        if ($emailBound) {
            return [
                'gate_active' => false,
                'email_bound' => true,
                'awaiting_reply' => false,
                'can_send' => true,
                'message' => '',
            ];
        }

        $awaiting = $this->isAwaitingAgentReply($sessionId);
        $message = $awaiting
            ? (string)__('请等待客服回复后再发送。验证邮箱后可跳过等待、连续发送消息。')
            : '';

        return [
            'gate_active' => true,
            'email_bound' => false,
            'awaiting_reply' => $awaiting,
            'can_send' => !$awaiting,
            'message' => $message,
        ];
    }

    /**
     * @throws \RuntimeException
     */
    public function assertCustomerMaySend(int $sessionId, bool $isLoggedIn): void
    {
        $gate = $this->resolveGuestSendGate($sessionId, $isLoggedIn);
        if (!empty($gate['can_send'])) {
            return;
        }

        throw new \RuntimeException(
            (string)($gate['message'] !== ''
                ? $gate['message']
                : __('请等待客服回复后再发送。验证邮箱后可跳过等待、连续发送消息。'))
        );
    }

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
            throw new \Exception((string)__('会话不存在'));
        }

        // waiting 会话在创建时可能无人可接；客户再发消息时重试分配（在线优先）。
        if ($senderType === ChatMessage::SENDER_TYPE_CUSTOMER
            && (string)$session->getStatus() === ChatSession::STATUS_WAITING
            && (int)$session->getAgentId() <= 0
        ) {
            $this->assignAgent($session);
            $session->load($sessionId);
        }

        $sourceLocale = $senderType === ChatMessage::SENDER_TYPE_CUSTOMER
            ? $session->getCustomerLocale()
            : ($senderType === ChatMessage::SENDER_TYPE_SYSTEM
                ? $session->getCustomerLocale()
                : $session->getAgentLocale());
        $targetLocale = $senderType === ChatMessage::SENDER_TYPE_CUSTOMER
            ? $session->getAgentLocale()
            : ($senderType === ChatMessage::SENDER_TYPE_SYSTEM
                ? $session->getCustomerLocale()
                : $session->getCustomerLocale());

        $isAttachment = ChatAttachmentCodec::isStructured($content);
        $translatedContent = $isAttachment
            ? ChatAttachmentCodec::displayFallback($content)
            : ($senderType === ChatMessage::SENDER_TYPE_SYSTEM
                ? $content
                : $this->translationService->translate(
                    $content,
                    $targetLocale,
                    $sourceLocale,
                    (string)$sessionId
                ));

        /** @var ChatMessage $message */
        $message = ObjectManager::getInstance(ChatMessage::class);
        $message->setSessionId($sessionId)
            ->setSenderType($senderType)
            ->setSenderId($senderId)
            ->setContent($content)
            ->setTranslatedContent($translatedContent)
            ->setSourceLocale($sourceLocale)
            ->setTargetLocale($targetLocale)
            ->setIsTranslated(!$isAttachment && $translatedContent !== $content)
            ->setData(ChatMessage::schema_fields_created_at, date('Y-m-d H:i:s'))
            ->save();

        $session->setData(ChatSession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        if ($senderType === ChatMessage::SENDER_TYPE_AGENT) {
            $this->markSessionReadByAgent($sessionId);
        }

        return $message;
    }

    /**
     * 客服打开会话或回复后标记已读（未读角标清零）。
     */
    public function markSessionReadByAgent(int $sessionId, ?string $readAt = null): void
    {
        if ($sessionId <= 0) {
            return;
        }

        /** @var ChatSession $session */
        $session = ObjectManager::getInstance(ChatSession::class);
        $session->load($sessionId);
        if (!$session->getId()) {
            return;
        }

        $at = $readAt !== null && trim($readAt) !== '' ? trim($readAt) : date('Y-m-d H:i:s');

        // 以会话内最新消息时间为下界，避免 PHP/DB 时区漂移导致「已读仍显示未读」
        /** @var ChatMessage $message */
        $message = ObjectManager::getInstance(ChatMessage::class);
        $latest = $message->reset()
            ->where(ChatMessage::schema_fields_session_id, $sessionId)
            ->order(ChatMessage::schema_fields_created_at, 'DESC')
            ->find()
            ->fetch();
        if ($latest->getId()) {
            $latestAt = trim((string)$latest->getData(ChatMessage::schema_fields_created_at));
            if ($latestAt !== '' && strcmp($latestAt, $at) > 0) {
                $at = $latestAt;
            }
        }

        $session->setLastReadTime($at)->save();
    }

    public function getMessages(int $sessionId, int $limit = 50, int $offset = 0): array
    {
        /** @var ChatMessage $message */
        $message = ObjectManager::getInstance(ChatMessage::class);

        // 按 message_id 取最新一页再反转为时间正序（聊天首屏）
        $messages = $message->reset()
            ->where(ChatMessage::schema_fields_session_id, $sessionId)
            ->order(ChatMessage::schema_fields_ID, 'DESC')
            ->limit($limit, $offset)
            ->select()
            ->fetch()
            ->getItems();

        $messages = is_array($messages) ? $messages : [];

        return array_reverse($messages);
    }

    /**
     * 拉取早于 beforeMessageId 的更旧消息（上滑历史）。
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    public function getMessagesBefore(int $sessionId, int $beforeMessageId, int $limit = 50): array
    {
        if ($sessionId <= 0 || $beforeMessageId <= 0) {
            return [];
        }

        /** @var ChatMessage $message */
        $message = ObjectManager::getInstance(ChatMessage::class);
        $messages = $message->reset()
            ->where(ChatMessage::schema_fields_session_id, $sessionId)
            ->where(ChatMessage::schema_fields_ID, $beforeMessageId, '<')
            ->order(ChatMessage::schema_fields_ID, 'DESC')
            ->limit(max(1, $limit), 0)
            ->select()
            ->fetch()
            ->getItems();

        $messages = is_array($messages) ? $messages : [];

        return array_reverse($messages);
    }

    /**
     * 拉取指定 message_id 之后的消息（轮询增量）。
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    public function getMessagesSince(int $sessionId, int $sinceMessageId, int $limit = 50): array
    {
        if ($sessionId <= 0) {
            return [];
        }

        /** @var ChatMessage $message */
        $message = ObjectManager::getInstance(ChatMessage::class);
        $query = $message->reset()
            ->where(ChatMessage::schema_fields_session_id, $sessionId);
        if ($sinceMessageId > 0) {
            $query->where(ChatMessage::schema_fields_ID, $sinceMessageId, '>');
        }
        $messages = $query
            ->order(ChatMessage::schema_fields_ID, 'ASC')
            ->limit($limit, 0)
            ->select()
            ->fetch()
            ->getItems();

        return is_array($messages) ? $messages : [];
    }

    public function getMessagesForCustomerView(
        int $sessionId,
        string $viewerLocale,
        int $limit = 50,
        int $offset = 0
    ): array {
        $messages = $this->getMessages($sessionId, $limit, $offset);

        return array_map(
            fn(ChatMessage|array $message): array => $this->formatMessageForCustomerView($message, $viewerLocale),
            $messages
        );
    }

    public function formatMessageForCustomerView(ChatMessage|array $message, string $viewerLocale): array
    {
        $data = $this->toMessageArray($message);
        $data[ChatMessage::schema_fields_created_at] = $this->formatClientDateTime(
            isset($data[ChatMessage::schema_fields_created_at]) ? (string)$data[ChatMessage::schema_fields_created_at] : null
        );
        $attachment = ChatAttachmentCodec::decode((string)($data[ChatMessage::schema_fields_content] ?? ''));
        $data['attachment'] = $attachment;
        $data['display_content'] = $attachment
            ? ChatAttachmentCodec::displayFallback((string)$data[ChatMessage::schema_fields_content])
            : $this->resolveCustomerDisplayContent($data, trim($viewerLocale));

        return $data;
    }

    public function formatMessageForAgentView(ChatMessage|array $message): array
    {
        $data = $this->toMessageArray($message);
        $data[ChatMessage::schema_fields_created_at] = $this->formatClientDateTime(
            isset($data[ChatMessage::schema_fields_created_at]) ? (string)$data[ChatMessage::schema_fields_created_at] : null
        );
        $attachment = ChatAttachmentCodec::decode((string)($data[ChatMessage::schema_fields_content] ?? ''));
        $data['attachment'] = $attachment;
        if ($attachment !== null) {
            $data['display_content'] = ChatAttachmentCodec::displayFallback((string)$data[ChatMessage::schema_fields_content]);
        }

        return $data;
    }

    public function formatClientDateTime(?string $dateTime): ?string
    {
        $dateTime = trim((string)$dateTime);
        if ($dateTime === '') {
            return null;
        }

        try {
            $timezone = new \DateTimeZone(date_default_timezone_get());
            $value = new \DateTimeImmutable($dateTime, $timezone);
            return $value->format(DATE_ATOM);
        } catch (\Throwable) {
            return $dateTime;
        }
    }

    public function getCustomerLocale(
        ?int $customerId = null,
        ?string $sessionToken = null,
        ?string $email = null
    ): string {
        /** @var CustomerLanguage $language */
        $language = ObjectManager::getInstance(CustomerLanguage::class);

        if ($customerId) {
            $language->reset()
                ->where(CustomerLanguage::schema_fields_customer_id, $customerId)
                ->find()
                ->fetch();
        } elseif ($sessionToken) {
            $language->reset()
                ->where(CustomerLanguage::schema_fields_session_id, $sessionToken)
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

        return $this->settings->defaultCustomerLocale();
    }

    public function setCustomerLocale(
        string $locale,
        ?int $customerId = null,
        ?string $sessionToken = null,
        ?string $email = null
    ): CustomerLanguage {
        /** @var CustomerLanguage $language */
        $language = ObjectManager::getInstance(CustomerLanguage::class);

        if ($customerId) {
            $language->reset()
                ->where(CustomerLanguage::schema_fields_customer_id, $customerId)
                ->find()
                ->fetch();
        } elseif ($sessionToken) {
            $language->reset()
                ->where(CustomerLanguage::schema_fields_session_id, $sessionToken)
                ->find()
                ->fetch();
        } elseif ($email) {
            $language->reset()
                ->where(CustomerLanguage::schema_fields_email, $email)
                ->find()
                ->fetch();
        }

        $language->setCustomerId($customerId)
            ->setSessionId($sessionToken)
            ->setEmail($email)
            ->setTargetLocale($locale)
            ->setData(CustomerLanguage::schema_fields_updated_at, date('Y-m-d H:i:s'));

        if (!$language->getId()) {
            $language->setData(CustomerLanguage::schema_fields_created_at, date('Y-m-d H:i:s'));
        }

        $language->save();
        $this->syncSessionLocaleByBinding($locale, $customerId, $sessionToken);

        return $language;
    }

    private function getAgentActiveSessionCount(int $agentId): int
    {
        /** @var ChatSession $session */
        $session = ObjectManager::getInstance(ChatSession::class);

        return (int)$session->reset()
            ->where(ChatSession::schema_fields_AGENT_ID, $agentId)
            ->where(ChatSession::schema_fields_STATUS, ChatSession::STATUS_ACTIVE)
            ->count();
    }

    /**
     * 客服名下未关闭会话数（进行中 + 仍挂在该客服上的等待中）。
     */
    public function countOpenSessionsForAgent(int $agentId): int
    {
        if ($agentId <= 0) {
            return 0;
        }

        /** @var ChatSession $session */
        $session = ObjectManager::getInstance(ChatSession::class);

        return (int)$session->reset()
            ->where(ChatSession::schema_fields_AGENT_ID, $agentId)
            ->where(
                ChatSession::schema_fields_STATUS,
                [ChatSession::STATUS_ACTIVE, ChatSession::STATUS_WAITING],
                'IN'
            )
            ->count();
    }

    /**
     * 将会话从一名客服转让给另一名客服（管理操作，可超过目标并发上限）。
     * transferred_from_agent_id = 原归属客服（$fromAgentId），与当前后台登录操作者无关。
     *
     * @return array{transferred:int,to_agent_id:int,to_agent_name:string}
     */
    public function transferAgentSessions(int $fromAgentId, int $toAgentId): array
    {
        if ($fromAgentId <= 0 || $toAgentId <= 0) {
            throw new \InvalidArgumentException(__('无效的客服ID'));
        }
        if ($fromAgentId === $toAgentId) {
            throw new \InvalidArgumentException(__('不能转让给同一客服'));
        }

        /** @var ServiceAgent $fromAgent */
        $fromAgent = ObjectManager::getInstance(ServiceAgent::class);
        $fromAgent->load($fromAgentId);
        if (!$fromAgent->getId()) {
            throw new \InvalidArgumentException(__('源客服不存在'));
        }

        /** @var ServiceAgent $toAgent */
        $toAgent = ObjectManager::getInstance(ServiceAgent::class);
        $toAgent->load($toAgentId);
        if (!$toAgent->getId()) {
            throw new \InvalidArgumentException(__('目标客服不存在'));
        }
        if (!(int)$toAgent->getIsActive()) {
            throw new \InvalidArgumentException(__('目标客服未激活'));
        }

        /** @var ChatSession $sessionModel */
        $sessionModel = ObjectManager::getInstance(ChatSession::class);
        $items = $sessionModel->reset()
            ->where(ChatSession::schema_fields_AGENT_ID, $fromAgentId)
            ->where(
                ChatSession::schema_fields_STATUS,
                [ChatSession::STATUS_ACTIVE, ChatSession::STATUS_WAITING],
                'IN'
            )
            ->select()
            ->fetch()
            ->getItems();

        $transferred = 0;
        $now = date('Y-m-d H:i:s');
        $toLocale = (string)$toAgent->getLocale();

        foreach ($items as $row) {
            /** @var ChatSession $session */
            $session = ObjectManager::getInstance(ChatSession::class);
            if ($row instanceof ChatSession) {
                $sessionId = (int)$row->getId();
            } elseif (is_array($row)) {
                $sessionId = (int)($row[ChatSession::schema_fields_ID] ?? 0);
            } else {
                continue;
            }
            if ($sessionId <= 0) {
                continue;
            }
            $session->load($sessionId);
            if (!$session->getId()) {
                continue;
            }
            $session->setAgentId($toAgentId)
                ->setAgentLocale($toLocale)
                ->setStatus(ChatSession::STATUS_ACTIVE)
                ->setTransferredFromAgentId($fromAgentId)
                ->setTransferredAt($now)
                ->setLastReadTime($now)
                ->setData(ChatSession::schema_fields_UPDATED_AT, $now)
                ->save();
            $transferred++;
        }

        return [
            'transferred' => $transferred,
            'to_agent_id' => $toAgentId,
            'to_agent_name' => (string)$toAgent->getName(),
        ];
    }

    /**
     * 释放客服名下未关闭会话回等待池（删除前无转让目标时使用）。
     */
    public function releaseAgentSessions(int $agentId): int
    {
        if ($agentId <= 0) {
            return 0;
        }

        /** @var ChatSession $sessionModel */
        $sessionModel = ObjectManager::getInstance(ChatSession::class);
        $items = $sessionModel->reset()
            ->where(ChatSession::schema_fields_AGENT_ID, $agentId)
            ->where(
                ChatSession::schema_fields_STATUS,
                [ChatSession::STATUS_ACTIVE, ChatSession::STATUS_WAITING],
                'IN'
            )
            ->select()
            ->fetch()
            ->getItems();

        $released = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($items as $row) {
            /** @var ChatSession $session */
            $session = ObjectManager::getInstance(ChatSession::class);
            if ($row instanceof ChatSession) {
                $sessionId = (int)$row->getId();
            } elseif (is_array($row)) {
                $sessionId = (int)($row[ChatSession::schema_fields_ID] ?? 0);
            } else {
                continue;
            }
            if ($sessionId <= 0) {
                continue;
            }
            $session->load($sessionId);
            if (!$session->getId()) {
                continue;
            }
            $session->setAgentId(null)
                ->setStatus(ChatSession::STATUS_WAITING)
                ->setTransferredFromAgentId(null)
                ->setTransferredAt(null)
                ->setData(ChatSession::schema_fields_UPDATED_AT, $now)
                ->save();
            $released++;
        }

        return $released;
    }

    /**
     * 为工作台会话列表补充转让标记展示字段。
     *
     * @param list<ChatSession|array<string, mixed>> $sessions
     * @return list<array<string, mixed>>
     */
    public function withTransferBadges(array $sessions): array
    {
        $normalized = $this->normalizeSessionRows($sessions);

        $fromIds = [];
        foreach ($normalized as $row) {
            $fromId = (int)($row[ChatSession::schema_fields_TRANSFERRED_FROM_AGENT_ID] ?? 0);
            if ($fromId > 0) {
                $fromIds[$fromId] = $fromId;
            }
        }

        $names = [];
        $userNames = [];
        if ($fromIds !== []) {
            /** @var ServiceAgent $agentModel */
            $agentModel = ObjectManager::getInstance(ServiceAgent::class);
            $agents = $agentModel->reset()
                ->where(ServiceAgent::schema_fields_ID, array_values($fromIds), 'IN')
                ->select()
                ->fetch()
                ->getItems();
            $userIds = [];
            foreach ($agents as $agentRow) {
                if ($agentRow instanceof ServiceAgent) {
                    $id = (int)$agentRow->getId();
                    $names[$id] = (string)$agentRow->getName();
                    $uid = (int)$agentRow->getUserId();
                } elseif (is_array($agentRow)) {
                    $id = (int)($agentRow[ServiceAgent::schema_fields_ID] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $names[$id] = (string)($agentRow[ServiceAgent::schema_fields_NAME] ?? '');
                    $uid = (int)($agentRow[ServiceAgent::schema_fields_USER_ID] ?? 0);
                } else {
                    continue;
                }
                if ($uid > 0) {
                    $userIds[$id] = $uid;
                }
            }
            if ($userIds !== []) {
                try {
                    $userDirectory = ObjectManager::getInstance(RuntimeProviderResolver::class)
                        ->resolve(BackendUserDirectoryInterface::class);
                    if ($userDirectory instanceof BackendUserDirectoryInterface) {
                        foreach ($userIds as $agentId => $uid) {
                            $user = $userDirectory->find($uid);
                            if ($user !== null) {
                                $userNames[$agentId] = (string)$user->getUsername();
                            }
                        }
                    }
                } catch (\Throwable) {
                    // Display can fall back to agent name only.
                }
            }
        }

        foreach ($normalized as &$row) {
            $fromId = (int)($row[ChatSession::schema_fields_TRANSFERRED_FROM_AGENT_ID] ?? 0);
            $fromName = $fromId > 0 ? trim((string)($names[$fromId] ?? '')) : '';
            $fromUserName = $fromId > 0 ? trim((string)($userNames[$fromId] ?? '')) : '';
            $row['is_transferred'] = $fromId > 0;
            $row['transferred_from_agent_id'] = $fromId > 0 ? $fromId : null;
            $row['transferred_from_agent_name'] = $fromName;
            $row['transferred_from_user_name'] = $fromUserName !== '' ? $fromUserName : null;
            // 「转让来源」= 会话原归属客服（人员页点转让的那一行），不是当前后台操作者。
            $displayName = $fromName !== '' ? $fromName : '';
            if ($fromUserName !== '' && strcasecmp($fromUserName, $displayName) !== 0) {
                $displayName = $displayName !== ''
                    ? ($displayName . ' · ' . $fromUserName)
                    : $fromUserName;
            }
            $row['transfer_badge_title'] = $fromId > 0
                ? (
                    $displayName !== ''
                        ? str_replace('__AGENT__', $displayName, (string)__('由客服「__AGENT__」转出'))
                        : (string)__('由其他客服转出')
                )
                : '';
            $row['transfer_from_label'] = $fromId > 0
                ? (
                    $displayName !== ''
                        ? str_replace('__AGENT__', $displayName, (string)__('来自客服「__AGENT__」'))
                        : (string)__('来自其他客服')
                )
                : '';
        }
        unset($row);

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function toMessageArray(ChatMessage|array $message): array
    {
        if (is_array($message)) {
            return $message;
        }

        $data = $message->getData();
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $message
     */
    private function resolveCustomerDisplayContent(array $message, string $viewerLocale): string
    {
        $original = trim((string)($message[ChatMessage::schema_fields_content] ?? ''));
        if ($original === '' || $viewerLocale === '') {
            return $original;
        }

        $translatedContent = trim((string)($message[ChatMessage::schema_fields_translated_content] ?? ''));
        $targetLocale = trim((string)($message[ChatMessage::schema_fields_target_locale] ?? ''));
        if ($translatedContent !== '' && $targetLocale === $viewerLocale) {
            return $translatedContent;
        }

        $messageId = (string)($message[ChatMessage::schema_fields_ID] ?? md5($original));
        $sessionId = isset($message[ChatMessage::schema_fields_session_id])
            ? (string)$message[ChatMessage::schema_fields_session_id]
            : null;
        $cacheKey = $messageId . '|' . $viewerLocale . '|' . md5($original);
        if (isset(self::$customerViewDisplayCache[$cacheKey])) {
            return self::$customerViewDisplayCache[$cacheKey];
        }

        $displayContent = $this->translationService->translate(
            $original,
            $viewerLocale,
            'auto',
            $sessionId
        );

        return self::$customerViewDisplayCache[$cacheKey] = $displayContent !== '' ? $displayContent : $original;
    }

    private function syncSessionLocale(ChatSession $session, string $customerLocale): void
    {
        $customerLocale = trim($customerLocale);
        if ($customerLocale === '' || $session->getCustomerLocale() === $customerLocale) {
            return;
        }

        $session->setCustomerLocale($customerLocale)
            ->setData(ChatSession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();
    }

    private function syncSessionLocaleByBinding(string $locale, ?int $customerId = null, ?string $sessionToken = null): void
    {
        /** @var ChatSession $session */
        $session = ObjectManager::getInstance(ChatSession::class);

        if (!empty($sessionToken)) {
            $session->reset()
                ->where(ChatSession::schema_fields_SESSION_TOKEN, $sessionToken)
                ->find()
                ->fetch();

            if ($session->getId()) {
                $this->syncSessionLocale($session, $locale);
            }
            return;
        }

        if (!$customerId) {
            return;
        }

        $session->reset()
            ->where(ChatSession::schema_fields_CUSTOMER_ID, $customerId)
            ->where(ChatSession::schema_fields_STATUS, ChatSession::STATUS_CLOSED, '!=')
            ->order(ChatSession::schema_fields_UPDATED_AT, 'DESC')
            ->find()
            ->fetch();

        if ($session->getId()) {
            $this->syncSessionLocale($session, $locale);
        }
    }

    /**
     * 工作台侧栏标题：登录用户显示昵称/邮箱，其余回退 #session_id。
     *
     * @param array<string, mixed> $sessionData
     */
    public function consoleSessionListTitle(array $sessionData): string
    {
        $sessionId = (int)($sessionData[ChatSession::schema_fields_ID] ?? $sessionData['session_id'] ?? 0);
        $kind = (string)($sessionData['customer_kind'] ?? '');
        $name = trim((string)($sessionData['customer_display_name'] ?? ''));
        if ($kind === 'customer' && $name !== '') {
            return $name;
        }

        return '#' . max(0, $sessionId);
    }

    /**
     * @param array<string, mixed> $sessionData
     * @return array<string, mixed>
     */
    private function attachConsoleSessionIdentity(array $sessionData): array
    {
        $sessionId = (int)($sessionData[ChatSession::schema_fields_ID] ?? $sessionData['session_id'] ?? 0);
        if ($sessionId <= 0) {
            $sessionData['customer_kind'] = (string)($sessionData['customer_kind'] ?? 'guest');
            $sessionData['customer_display_name'] = (string)($sessionData['customer_display_name'] ?? '');
            $sessionData['customer_email'] = (string)($sessionData['customer_email'] ?? '');
            $sessionData['list_title'] = $this->consoleSessionListTitle($sessionData);

            return $sessionData;
        }

        /** @var ChatSession $sessionModel */
        $sessionModel = ObjectManager::getInstance(ChatSession::class);
        $sessionModel->clear()->load($sessionId);
        if (!$sessionModel->getId()) {
            $sessionModel->clear();
            $sessionModel->setData(ChatSession::schema_fields_ID, $sessionId);
            $sessionModel->setData(
                ChatSession::schema_fields_CUSTOMER_ID,
                (int)($sessionData[ChatSession::schema_fields_CUSTOMER_ID] ?? $sessionData['customer_id'] ?? 0) ?: null
            );
            $sessionModel->setData(
                ChatSession::schema_fields_SESSION_TOKEN,
                (string)($sessionData[ChatSession::schema_fields_SESSION_TOKEN] ?? $sessionData['session_token'] ?? '')
            );
        }

        $customerId = (int)($sessionModel->getCustomerId()
            ?: ($sessionData[ChatSession::schema_fields_CUSTOMER_ID] ?? $sessionData['customer_id'] ?? 0));
        $isCustomer = $customerId > 0;
        $identity = $this->buildSessionIdentity($sessionModel, $isCustomer, $isCustomer ? $customerId : null);

        $sessionData['customer_id'] = $customerId > 0 ? $customerId : null;
        $sessionData['customer_kind'] = (string)($identity['kind'] ?? 'guest');
        $sessionData['customer_display_name'] = (string)($identity['display_name'] ?? '');
        $sessionData['customer_email'] = (string)($identity['email'] ?? '');
        $sessionData['list_title'] = $this->consoleSessionListTitle($sessionData);

        return $sessionData;
    }

    /**
     * 工作台侧栏：补充最后消息、未读与等待起点。
     *
     * @param list<ChatSession|array<string, mixed>> $sessions
     * @return list<array<string, mixed>>
     */
    public function enrichConsoleSessionRows(array $sessions): array
    {
        $sessions = $this->normalizeSessionRows($sessions);
        foreach ($sessions as &$sessionData) {
            $sessionId = (int)($sessionData[ChatSession::schema_fields_ID] ?? $sessionData['session_id'] ?? 0);
            if ($sessionId <= 0) {
                $sessionData['unread_count'] = (int)($sessionData['unread_count'] ?? 0);
                $sessionData = $this->attachConsoleSessionIdentity($sessionData);
                continue;
            }

            $sessionData = $this->attachConsoleSessionIdentity($sessionData);

            /** @var ChatMessage $message */
            $message = ObjectManager::getInstance(ChatMessage::class);
            $lastMessage = $message->reset()
                ->where(ChatMessage::schema_fields_session_id, $sessionId)
                ->order(ChatMessage::schema_fields_created_at, 'DESC')
                ->order(ChatMessage::schema_fields_ID, 'DESC')
                ->find()
                ->fetch();

            if ($lastMessage->getId()) {
                $rawContent = (string)$lastMessage->getContent();
                $display = (string)($lastMessage->getTranslatedContent() ?: $rawContent);
                if (ChatAttachmentCodec::isStructured($rawContent)) {
                    $display = ChatAttachmentCodec::displayFallback($rawContent);
                }
                $createdAt = (string)$lastMessage->getData(ChatMessage::schema_fields_created_at);
                $sessionData['last_message'] = $display;
                $sessionData['last_message_time'] = $this->formatClientDateTime($createdAt);
                $sessionData['last_message_at'] = $this->formatClientDateTime($createdAt);
                $sessionData['last_sender_type'] = (string)$lastMessage->getSenderType();
            } else {
                $sessionData['last_message'] = $sessionData['last_message'] ?? null;
                $sessionData['last_message_time'] = $sessionData['last_message_time'] ?? null;
                $sessionData['last_message_at'] = $this->formatClientDateTime(
                    (string)($sessionData[ChatSession::schema_fields_UPDATED_AT] ?? '')
                );
                $sessionData['last_sender_type'] = '';
            }

            $readFloor = (string)($sessionData[ChatSession::schema_fields_LAST_READ_TIME]
                ?? $sessionData[ChatSession::schema_fields_TRANSFERRED_AT]
                ?? '1970-01-01 00:00:00');
            $unreadCount = $message->reset()
                ->where(ChatMessage::schema_fields_session_id, $sessionId)
                ->where(ChatMessage::schema_fields_sender_type, ChatMessage::SENDER_TYPE_CUSTOMER)
                ->where(ChatMessage::schema_fields_created_at, $readFloor, '>')
                ->count();
            $sessionData['unread_count'] = (int)$unreadCount;

            $waitingSince = null;
            if ((int)$sessionData['unread_count'] > 0) {
                $oldestUnread = $message->reset()
                    ->where(ChatMessage::schema_fields_session_id, $sessionId)
                    ->where(ChatMessage::schema_fields_sender_type, ChatMessage::SENDER_TYPE_CUSTOMER)
                    ->where(ChatMessage::schema_fields_created_at, $readFloor, '>')
                    ->order(ChatMessage::schema_fields_created_at, 'ASC')
                    ->order(ChatMessage::schema_fields_ID, 'ASC')
                    ->find()
                    ->fetch();
                if ($oldestUnread->getId()) {
                    $waitingSince = (string)$oldestUnread->getData(ChatMessage::schema_fields_created_at);
                }
            }
            $sessionData['waiting_since_at'] = $this->formatClientDateTime($waitingSince);
        }
        unset($sessionData);

        return $sessions;
    }

    /**
     * 侧栏排序：未读（待回复）优先且等待最久更靠前；其余按最新消息靠前。
     *
     * @param list<array<string, mixed>> $sessions
     * @return list<array<string, mixed>>
     */
    public function sortConsoleSessionsByPriority(array $sessions): array
    {
        usort($sessions, static function (array $a, array $b): int {
            $aUnread = (int)($a['unread_count'] ?? 0) > 0;
            $bUnread = (int)($b['unread_count'] ?? 0) > 0;
            if ($aUnread !== $bUnread) {
                return $aUnread ? -1 : 1;
            }
            if ($aUnread && $bUnread) {
                $aWait = strtotime((string)($a['waiting_since_at'] ?? '')) ?: PHP_INT_MAX;
                $bWait = strtotime((string)($b['waiting_since_at'] ?? '')) ?: PHP_INT_MAX;
                if ($aWait !== $bWait) {
                    return $aWait <=> $bWait;
                }
            }
            $aLast = strtotime((string)($a['last_message_at'] ?? $a['last_message_time'] ?? '')) ?: 0;
            $bLast = strtotime((string)($b['last_message_at'] ?? $b['last_message_time'] ?? '')) ?: 0;
            if ($aLast !== $bLast) {
                return $bLast <=> $aLast;
            }

            return ((int)($b['session_id'] ?? $b['id'] ?? 0)) <=> ((int)($a['session_id'] ?? $a['id'] ?? 0));
        });

        return array_values($sessions);
    }

    private function generateSessionToken(): string
    {
        return Text::random_string(32);
    }
}
