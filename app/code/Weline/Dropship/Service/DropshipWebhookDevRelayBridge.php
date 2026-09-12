<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\DevRelayEventStore;
use Weline\Payment\Service\DevRelayGateService;

/**
 * 货源 Webhook → 复用支付 DevRelay 会话（线上收 → 本机 SSE 重放）。
 * inbox_code 约定：dropship:{inbox_id}
 */
final class DropshipWebhookDevRelayBridge
{
    public const INBOX_PREFIX = 'dropship:';

    /**
     * @param array{endpoint_code:string,provider_code:string,external_event_id:string,event_type:string} $meta
     */
    public function publishInbox(int $inboxId, array $meta): void
    {
        if ($inboxId <= 0 || !class_exists(DevRelayGateService::class)) {
            return;
        }
        try {
            /** @var DevRelayGateService $gate */
            $gate = ObjectManager::getInstance(DevRelayGateService::class);
            if (!$gate->isOnlineRelayHost()) {
                return;
            }
            /** @var DevRelayEventStore $events */
            $events = ObjectManager::getInstance(DevRelayEventStore::class);
            $events->appendForActiveOnlineSession([
                'inbox_code' => self::INBOX_PREFIX . $inboxId,
                'endpoint_code' => (string)($meta['endpoint_code'] ?? ''),
                'provider_code' => (string)($meta['provider_code'] ?? ''),
                'provider_event_id' => (string)($meta['external_event_id'] ?? ''),
                'event_type' => (string)($meta['event_type'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            w_log_error('dropship webhook dev-relay bridge failed: ' . $e->getMessage());
        }
    }

    public static function isDropshipInboxCode(string $inboxCode): bool
    {
        return str_starts_with(trim($inboxCode), self::INBOX_PREFIX);
    }

    public static function parseInboxId(string $inboxCode): int
    {
        $inboxCode = trim($inboxCode);
        if (!self::isDropshipInboxCode($inboxCode)) {
            return 0;
        }

        return (int)substr($inboxCode, strlen(self::INBOX_PREFIX));
    }
}
