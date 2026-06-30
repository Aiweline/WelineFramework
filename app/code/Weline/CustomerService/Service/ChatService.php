<?php

declare(strict_types=1);

namespace Weline\CustomerService\Service;

use Weline\CustomerService\Model\ChatMessage;
use Weline\CustomerService\Model\ChatSession;
use Weline\CustomerService\Model\CustomerLanguage;
use Weline\CustomerService\Model\ServiceAgent;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Text;

class ChatService
{
    /**
     * @var array<string, string>
     */
    private static array $customerViewDisplayCache = [];

    public function __construct(
        private readonly TranslationService $translationService
    ) {
    }

    public function getOrCreateSession(
        ?int $customerId = null,
        ?string $sessionToken = null,
        string $customerLocale = 'zh_Hans_CN'
    ): ChatSession {
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
            ->setAgentLocale('zh_Hans_CN')
            ->setStatus(ChatSession::STATUS_WAITING)
            ->setData(ChatSession::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
            ->setData(ChatSession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        $this->assignAgent($session);

        return $session;
    }

    public function assignAgent(ChatSession $session): bool
    {
        /** @var ServiceAgent $agent */
        $agent = ObjectManager::getInstance(ServiceAgent::class);

        $agents = $agent->reset()
            ->where(ServiceAgent::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch()
            ->getItems();

        foreach ($agents as $agentData) {
            $agent->setData($agentData);

            $currentSessions = $this->getAgentActiveSessionCount((int)$agent->getId());
            if ($currentSessions >= $agent->getMaxSessions()) {
                continue;
            }

            $session->setAgentId((int)$agent->getId())
                ->setAgentLocale($agent->getLocale())
                ->setStatus(ChatSession::STATUS_ACTIVE)
                ->setData(ChatSession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                ->save();

            return true;
        }

        return false;
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

        $sourceLocale = $senderType === ChatMessage::SENDER_TYPE_CUSTOMER
            ? $session->getCustomerLocale()
            : $session->getAgentLocale();
        $targetLocale = $senderType === ChatMessage::SENDER_TYPE_CUSTOMER
            ? $session->getAgentLocale()
            : $session->getCustomerLocale();

        $translatedContent = $this->translationService->translate(
            $content,
            $targetLocale,
            $sourceLocale,
            (string)$sessionId
        );

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

        $session->setData(ChatSession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return $message;
    }

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

        return array_reverse($messages);
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
        $data['display_content'] = $this->resolveCustomerDisplayContent($data, trim($viewerLocale));

        return $data;
    }

    public function formatMessageForAgentView(ChatMessage|array $message): array
    {
        $data = $this->toMessageArray($message);
        $data[ChatMessage::schema_fields_created_at] = $this->formatClientDateTime(
            isset($data[ChatMessage::schema_fields_created_at]) ? (string)$data[ChatMessage::schema_fields_created_at] : null
        );

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

        return 'zh_Hans_CN';
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

    private function generateSessionToken(): string
    {
        return Text::random_string(32);
    }
}
