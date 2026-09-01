<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Http\Security\SecretRefCipher;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentDevRelayEvent;
use Weline\Payment\Model\PaymentDevRelaySession;
use Weline\Payment\Model\PaymentWebhookInbox;

final class DevRelayInboxPayloadService
{
    /**
     * @return array{
     *   raw_body:string,
     *   headers:array<string,mixed>,
     *   signature:string,
     *   endpoint_code:string,
     *   provider_event_id:string,
     *   provider_code:string,
     *   event_type:string
     * }
     */
    public function loadFromInboxCode(string $inboxCode): array
    {
        $inbox = ObjectManager::getInstance(PaymentWebhookInbox::class)
            ->where(PaymentWebhookInbox::schema_fields_INBOX_CODE, trim($inboxCode))
            ->find()
            ->fetch();
        if (!$inbox->getId()) {
            throw new \RuntimeException((string) __('Webhook inbox 不存在。'));
        }

        $headersJson = $this->open((string) $inbox->getData(PaymentWebhookInbox::schema_fields_ENCRYPTED_HEADERS));

        return [
            'raw_body' => $this->open((string) $inbox->getData(PaymentWebhookInbox::schema_fields_ENCRYPTED_RAW_PAYLOAD)),
            'headers' => json_decode($headersJson, true) ?: [],
            'signature' => $this->open((string) $inbox->getData(PaymentWebhookInbox::schema_fields_ENCRYPTED_SIGNATURE)),
            'endpoint_code' => (string) $inbox->getData(PaymentWebhookInbox::schema_fields_ENDPOINT_CODE),
            'provider_event_id' => (string) $inbox->getData(PaymentWebhookInbox::schema_fields_PROVIDER_EVENT_ID),
            'provider_code' => (string) $inbox->getData(PaymentWebhookInbox::schema_fields_PROVIDER_CODE),
            'event_type' => (string) $inbox->getData(PaymentWebhookInbox::schema_fields_EVENT_TYPE),
        ];
    }

    private function open(string $sealed): string
    {
        if ($sealed === '') {
            return '';
        }

        return SecretRefCipher::reveal($sealed);
    }
}

final class DevRelayEventStore
{
    public function __construct(
        private readonly DevRelaySessionService $sessions,
    ) {
    }

    /**
     * @param array<string, mixed> $inboxRow
     * @return array<string, mixed>|null
     */
    public function appendForActiveOnlineSession(array $inboxRow): ?array
    {
        $session = $this->sessions->findActiveOnlineSession();
        if ($session === null) {
            return null;
        }

        $sessionCode = (string) $session->getData(PaymentDevRelaySession::schema_fields_SESSION_CODE);
        $nextSeq = ((int) $session->getData(PaymentDevRelaySession::schema_fields_LAST_EVENT_SEQ)) + 1;
        $eventCode = 'dre_' . bin2hex(random_bytes(12));

        $event = ObjectManager::getInstance(PaymentDevRelayEvent::class);
        $event->setData([
            PaymentDevRelayEvent::schema_fields_EVENT_CODE => $eventCode,
            PaymentDevRelayEvent::schema_fields_SESSION_CODE => $sessionCode,
            PaymentDevRelayEvent::schema_fields_SEQ => $nextSeq,
            PaymentDevRelayEvent::schema_fields_INBOX_CODE => (string) ($inboxRow['inbox_code'] ?? ''),
            PaymentDevRelayEvent::schema_fields_ENDPOINT_CODE => (string) ($inboxRow['endpoint_code'] ?? ''),
            PaymentDevRelayEvent::schema_fields_PROVIDER_CODE => (string) ($inboxRow['provider_code'] ?? ''),
            PaymentDevRelayEvent::schema_fields_PROVIDER_EVENT_ID => (string) ($inboxRow['provider_event_id'] ?? ''),
            PaymentDevRelayEvent::schema_fields_EVENT_TYPE => (string) ($inboxRow['event_type'] ?? ''),
            PaymentDevRelayEvent::schema_fields_RELAY_STATUS => PaymentDevRelayEvent::RELAY_STATUS_PENDING,
        ])->save();

        $session->setData(PaymentDevRelaySession::schema_fields_LAST_EVENT_SEQ, $nextSeq)
            ->setData(PaymentDevRelaySession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return [
            'event_code' => $eventCode,
            'session_code' => $sessionCode,
            'seq' => $nextSeq,
            'endpoint_code' => (string) ($inboxRow['endpoint_code'] ?? ''),
            'provider_code' => (string) ($inboxRow['provider_code'] ?? ''),
            'provider_event_id' => (string) ($inboxRow['provider_event_id'] ?? ''),
            'event_type' => (string) ($inboxRow['event_type'] ?? ''),
            'inbox_code' => (string) ($inboxRow['inbox_code'] ?? ''),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSince(string $sessionCode, int $afterSeq, int $limit = 100): array
    {
        $rows = ObjectManager::getInstance(PaymentDevRelayEvent::class)
            ->reset()
            ->where(PaymentDevRelayEvent::schema_fields_SESSION_CODE, $sessionCode)
            ->where(PaymentDevRelayEvent::schema_fields_SEQ, $afterSeq, '>')
            ->order(PaymentDevRelayEvent::schema_fields_SEQ, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        return \is_array($rows) ? $rows : [];
    }

    public function markRelayResult(string $eventCode, bool $success, string $error = ''): void
    {
        $event = ObjectManager::getInstance(PaymentDevRelayEvent::class)
            ->where(PaymentDevRelayEvent::schema_fields_EVENT_CODE, trim($eventCode))
            ->find()
            ->fetch();
        if (!$event->getId()) {
            return;
        }

        $event->setData(PaymentDevRelayEvent::schema_fields_RELAY_STATUS, $success
            ? PaymentDevRelayEvent::RELAY_STATUS_DELIVERED
            : PaymentDevRelayEvent::RELAY_STATUS_FAILED)
            ->setData(PaymentDevRelayEvent::schema_fields_RELAY_ERROR, $success ? '' : $error)
            ->setData(PaymentDevRelayEvent::schema_fields_RELAYED_AT, date('Y-m-d H:i:s'))
            ->save();
    }

    public function loadEvent(string $eventCode): ?PaymentDevRelayEvent
    {
        $event = ObjectManager::getInstance(PaymentDevRelayEvent::class)
            ->where(PaymentDevRelayEvent::schema_fields_EVENT_CODE, trim($eventCode))
            ->find()
            ->fetch();

        return $event->getId() ? $event : null;
    }
}
