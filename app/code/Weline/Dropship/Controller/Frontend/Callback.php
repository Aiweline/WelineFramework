<?php

declare(strict_types=1);

namespace Weline\Dropship\Controller\Frontend;

use Weline\Dropship\Interface\DropshipWebhookProviderInterface;
use Weline\Dropship\Model\DropshipWebhookInbox;
use Weline\Dropship\Service\DropshipChannelManager;
use Weline\Dropship\Service\DropshipWebhookDevRelayBridge;
use Weline\Dropship\Service\DropshipWebhookInboxService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * Unified webhook: dropship/frontend/callback/notify?endpoint_code={provider}.{env}.default
 * 生产开启支付 DevRelay「允许生产中转」后，会桥接到本机静默 worker 重放。
 */
class Callback extends FrontendController
{
    public function notify(): string
    {
        $endpoint = (string)$this->request->getGet('endpoint_code', '');
        $parts = explode('.', $endpoint);
        $providerCode = $parts[0] ?? '';
        // WLS 下 php://input 常为空；统一走 Request raw body（FPM/WLS 兼容）。
        $body = (string)$this->request->getBodyParams(false);
        if ($body === '' || $body === '[]' || $body === '{}') {
            $raw = '';
            try {
                $raw = (string)$this->request->getParameterBag()->getRawBody();
            } catch (\Throwable) {
                $raw = '';
            }
            if ($raw !== '') {
                $body = $raw;
            }
        }
        if (($body === '' || $body === '[]' || $body === '{}') && \is_string($GLOBALS['HTTP_RAW_POST_DATA'] ?? null)) {
            $body = (string)$GLOBALS['HTTP_RAW_POST_DATA'];
        }
        $headers = function_exists('getallheaders') ? (array)getallheaders() : [];
        if ($headers === []) {
            foreach ($_SERVER as $k => $v) {
                if (!\is_string($k) || !str_starts_with($k, 'HTTP_') || !\is_scalar($v)) {
                    continue;
                }
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                $headers[$name] = (string)$v;
            }
        }

        /** @var DropshipChannelManager $channels */
        $channels = ObjectManager::getInstance(DropshipChannelManager::class);
        $provider = $channels->getProvider($providerCode);
        if (!$provider instanceof DropshipWebhookProviderInterface) {
            http_response_code(400);

            return json_encode(['ok' => false, 'message' => 'unknown_provider']);
        }

        $verified = $provider->verifyWebhook($headers, $body);
        if (!($verified['ok'] ?? false)) {
            http_response_code(401);

            return json_encode(['ok' => false, 'message' => $verified['message'] ?? 'verify_failed']);
        }

        $parsed = $provider->parseWebhook($headers, $body);
        if (!($parsed['ok'] ?? false)) {
            http_response_code(400);

            return json_encode(['ok' => false, 'message' => $parsed['message'] ?? 'parse_failed']);
        }

        $externalId = (string)($parsed['external_id'] ?? md5($body));
        // Persist shell-standard envelope; keep original raw_body for DevRelay replay.
        $storedBody = json_encode([
            'event' => (string)($parsed['event'] ?? ''),
            'external_id' => $externalId,
            'fulfillment' => \is_array($parsed['fulfillment'] ?? null) ? $parsed['fulfillment'] : [],
            'raw' => \is_array($parsed['payload'] ?? null) ? $parsed['payload'] : [],
            'raw_body' => $body,
            'headers' => $headers,
        ], JSON_UNESCAPED_UNICODE);
        if (!\is_string($storedBody) || $storedBody === '') {
            $storedBody = $body;
        }

        /** @var DropshipWebhookInbox $inbox */
        $inbox = ObjectManager::getInstance(DropshipWebhookInbox::class);
        $existing = $inbox->clear()
            ->where(DropshipWebhookInbox::schema_fields_ENDPOINT_CODE, $endpoint)
            ->where(DropshipWebhookInbox::schema_fields_EXTERNAL_EVENT_ID, $externalId)
            ->find()->fetch();
        if ($existing && $existing->getId()) {
            return json_encode(['ok' => true, 'deduped' => true]);
        }
        $eventType = (string)($parsed['event'] ?? '');
        $inbox->clear()->setData([
            DropshipWebhookInbox::schema_fields_ENDPOINT_CODE => $endpoint,
            DropshipWebhookInbox::schema_fields_PROVIDER_CODE => $providerCode,
            DropshipWebhookInbox::schema_fields_EXTERNAL_EVENT_ID => $externalId,
            DropshipWebhookInbox::schema_fields_EVENT_TYPE => $eventType,
            DropshipWebhookInbox::schema_fields_BODY => $storedBody,
            DropshipWebhookInbox::schema_fields_STATUS => 'received',
            DropshipWebhookInbox::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
            DropshipWebhookInbox::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ])->save();
        $inboxId = (int)$inbox->getId();
        if ($inboxId > 0) {
            ObjectManager::getInstance(DropshipWebhookInboxService::class)->enqueue($inboxId);
            ObjectManager::getInstance(DropshipWebhookDevRelayBridge::class)->publishInbox($inboxId, [
                'endpoint_code' => $endpoint,
                'provider_code' => $providerCode,
                'external_event_id' => $externalId,
                'event_type' => $eventType,
            ]);
        }

        return json_encode(['ok' => true, 'inbox_id' => $inboxId]);
    }
}
