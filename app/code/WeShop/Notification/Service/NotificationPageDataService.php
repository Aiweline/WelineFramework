<?php

declare(strict_types=1);

namespace WeShop\Notification\Service;

class NotificationPageDataService
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId): array
    {
        $items = $this->mapItems($this->notificationService->getCustomerNotifications($customerId, 30));

        return [
            'notifications' => $items,
            'notification_count' => count($items),
            'notification_unread_count' => $this->notificationService->getUnreadCount($customerId),
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    protected function mapItems(array $items): array
    {
        $mapped = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $mapped[] = [
                'notification_id' => (int) ($item['notification_id'] ?? 0),
                'type' => (string) ($item['type'] ?? 'info'),
                'title' => (string) ($item['title'] ?? ''),
                'content' => (string) ($item['content'] ?? ''),
                'target_url' => (string) ($item['target_url'] ?? ''),
                'is_read' => (int) ($item['is_read'] ?? 0),
                'created_at' => (string) ($item['created_at'] ?? ''),
            ];
        }

        return $mapped;
    }
}
