<?php

declare(strict_types=1);

namespace WeShop\Notification\Service;

use Weline\Backend\Service\ChannelAdapterCollector;

class CustomerNotificationRouter
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly CustomerNotificationPreferenceService $preferenceService,
        private readonly ChannelAdapterCollector $adapterCollector
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    public function routeOrderNotification(array $payload): array
    {
        $customerId = (int) ($payload['customer_id'] ?? 0);
        $guestCheckout = !empty($payload['is_guest_checkout']);
        $guestEmail = trim((string) ($payload['guest_email'] ?? ''));
        $requestedChannels = is_array($payload['notification_channels'] ?? null)
            ? $payload['notification_channels']
            : [];

        $channels = $requestedChannels !== []
            ? $this->preferenceService->normalizeChannels($requestedChannels, $customerId, $guestCheckout)
            : ($guestCheckout ? ['email'] : $this->preferenceService->getPreferredChannels($customerId));

        $notification = [
            'topic_code' => CustomerNotificationPreferenceService::TOPIC_ORDER,
            'type' => 'order',
            'title' => (string) ($payload['title'] ?? __('订单通知')),
            'content' => (string) ($payload['content'] ?? __('您的订单已提交，我们会继续同步后续状态。')),
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        ];

        $sent = [];
        if (!$guestCheckout && $customerId > 0 && in_array(CustomerNotificationPreferenceService::CHANNEL_INBOX, $channels, true)) {
            $this->notificationService->sendNotification([
                'customer_id' => $customerId,
                'type' => 'order',
                'title' => $notification['title'],
                'content' => $notification['content'],
            ]);
            $sent[] = CustomerNotificationPreferenceService::CHANNEL_INBOX;
        }

        foreach ($channels as $channel) {
            if ($channel === CustomerNotificationPreferenceService::CHANNEL_INBOX) {
                continue;
            }

            $configList = $this->resolveChannelConfigs($channel, $customerId, $guestCheckout, $guestEmail);
            if ($configList === []) {
                continue;
            }

            $adapter = $this->adapterCollector->getAdapterByCode($channel);
            if (!$adapter) {
                continue;
            }

            foreach ($configList as $config) {
                try {
                    if ($adapter->send($notification, $config)) {
                        $sent[] = $channel;
                    }
                } catch (\Throwable $e) {
                    w_log_warning('CustomerNotificationRouter failed to send channel: ' . $e->getMessage(), [
                        'channel' => $channel,
                        'customer_id' => $customerId,
                    ], 'notification');
                }
            }
        }

        return array_values(array_unique($sent));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveChannelConfigs(string $channel, int $customerId, bool $guestCheckout, string $guestEmail): array
    {
        if ($guestCheckout) {
            if ($channel !== 'email' || !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
                return [];
            }

            return [['to_email' => $guestEmail]];
        }

        $configs = [];
        foreach ($this->preferenceService->getContacts($customerId, $channel) as $contact) {
            $rawConfig = $contact['channel_config'] ?? '';
            $decoded = is_array($rawConfig) ? $rawConfig : json_decode((string) $rawConfig, true);
            $config = is_array($decoded) ? $decoded : [];
            if ($config !== []) {
                $configs[] = $config;
            }
        }

        return $configs;
    }
}
