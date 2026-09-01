<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Http\Security\SecretRefCipher;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentWebhookInbox;

/**
 * 线上连调探针：向当前活跃 online 会话注入合成 inbox + relay 事件。
 */
final class DevRelayProbeService
{
    public function __construct(
        private readonly DevRelayGateService $gate,
        private readonly DevRelaySessionService $sessions,
        private readonly DevRelayEventStore $events,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function injectSyntheticEvent(string $marker = ''): array
    {
        if (!$this->gate->isOnlineRelayHost()) {
            throw new \RuntimeException((string) __('仅线上中继主机可注入连调探针事件。'));
        }

        $session = $this->sessions->findActiveOnlineSession();
        if ($session === null) {
            throw new \RuntimeException((string) __('没有活跃的线上 Relay 会话；请先 pair。'));
        }

        $marker = trim($marker);
        if ($marker === '') {
            $marker = 'probe_' . bin2hex(random_bytes(6));
        }

        $providerEventId = 'devrelay_probe_' . bin2hex(random_bytes(8));
        $endpointCode = 'devrelay.probe';
        $rawBody = json_encode([
            'probe' => true,
            'marker' => $marker,
            'provider_event_id' => $providerEventId,
            'ts' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $headers = [
            'content-type' => 'application/json',
            'x-dev-relay-probe' => $marker,
        ];
        $signature = 'devrelay-probe';
        $inboxCode = 'wi_' . bin2hex(random_bytes(16));

        /** @var PaymentWebhookInbox $inbox */
        $inbox = ObjectManager::getInstance(PaymentWebhookInbox::class);
        $inbox->setData([
            PaymentWebhookInbox::schema_fields_INBOX_CODE => $inboxCode,
            PaymentWebhookInbox::schema_fields_ENDPOINT_CODE => $endpointCode,
            PaymentWebhookInbox::schema_fields_PROVIDER_EVENT_ID => $providerEventId,
            PaymentWebhookInbox::schema_fields_PROVIDER_CODE => 'devrelay',
            PaymentWebhookInbox::schema_fields_MERCHANT_ACCOUNT => 'probe',
            PaymentWebhookInbox::schema_fields_ENVIRONMENT => 'sandbox',
            PaymentWebhookInbox::schema_fields_SCHEMA_VERSION => '1',
            PaymentWebhookInbox::schema_fields_VERIFICATION_SECRET_VERSION => 'probe',
            PaymentWebhookInbox::schema_fields_PAYLOAD_HASH => hash('sha256', $rawBody),
            PaymentWebhookInbox::schema_fields_ENCRYPTED_RAW_PAYLOAD => SecretRefCipher::seal($rawBody),
            PaymentWebhookInbox::schema_fields_ENCRYPTED_HEADERS => SecretRefCipher::seal(
                json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
            ),
            PaymentWebhookInbox::schema_fields_ENCRYPTED_SIGNATURE => SecretRefCipher::seal($signature),
            PaymentWebhookInbox::schema_fields_STATUS => PaymentWebhookInbox::STATUS_RECEIVED,
            PaymentWebhookInbox::schema_fields_EVENT_TYPE => 'devrelay.probe',
            PaymentWebhookInbox::schema_fields_RECEIVED_AT => date('Y-m-d H:i:s'),
        ])->save();

        $relay = $this->events->appendForActiveOnlineSession([
            'inbox_code' => $inboxCode,
            'endpoint_code' => $endpointCode,
            'provider_code' => 'devrelay',
            'provider_event_id' => $providerEventId,
            'event_type' => 'devrelay.probe',
            'status' => PaymentWebhookInbox::STATUS_RECEIVED,
        ]);
        if ($relay === null) {
            throw new \RuntimeException((string) __('注入 inbox 成功，但未能挂到活跃会话。'));
        }

        return [
            'marker' => $marker,
            'inbox_code' => $inboxCode,
            'event' => $relay,
        ];
    }
}
