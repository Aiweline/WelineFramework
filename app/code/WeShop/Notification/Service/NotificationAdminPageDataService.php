<?php

declare(strict_types=1);

namespace WeShop\Notification\Service;

use WeShop\Notification\Model\Notification;

class NotificationAdminPageDataService
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    public function getListData(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        $sanitizedFilters = $this->sanitizeFilters($filters);
        $result = $this->notificationService->getAdminNotifications($page, $pageSize, $sanitizedFilters);

        return [
            'notifications' => array_map(fn (array $item): array => $this->normalizeItem($item), $result['items'] ?? []),
            'filters' => $sanitizedFilters,
            'pagination' => $result['pagination'] ?? [],
            'summary' => $this->notificationService->getAdminSummary($sanitizedFilters),
            'typeOptions' => $this->notificationService->getTypeOptions(),
            'readOptions' => [
                '0' => (string) __('Unread'),
                '1' => (string) __('Read'),
            ],
        ];
    }

    public function getDetailData(int $notificationId): array
    {
        $notification = $this->notificationService->getNotificationById($notificationId);
        if (!$notification || !$notification->getId()) {
            throw new \InvalidArgumentException((string) __('Notification not found.'));
        }

        $detail = $this->normalizeModel($notification);
        $detail['type_label'] = $this->notificationService->getTypeOptions()[$detail['type']] ?? ucfirst($detail['type']);

        return [
            'notification' => $detail,
        ];
    }

    private function sanitizeFilters(array $filters): array
    {
        $sanitized = [];

        if (!empty($filters['customer_id'])) {
            $sanitized['customer_id'] = max(1, (int) $filters['customer_id']);
        }

        if (!empty($filters['type'])) {
            $sanitized['type'] = trim((string) $filters['type']);
        }

        if (array_key_exists('is_read', $filters) && $filters['is_read'] !== '') {
            $sanitized['is_read'] = ((int) $filters['is_read']) > 0 ? 1 : 0;
        }

        if (!empty($filters['title'])) {
            $sanitized['title'] = trim((string) $filters['title']);
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item): array
    {
        $isRead = (int) ($item[Notification::schema_fields_IS_READ] ?? $item['is_read'] ?? 0);

        return [
            'notification_id' => (int) ($item[Notification::schema_fields_ID] ?? $item['notification_id'] ?? 0),
            'customer_id' => (int) ($item[Notification::schema_fields_CUSTOMER_ID] ?? $item['customer_id'] ?? 0),
            'type' => (string) ($item[Notification::schema_fields_TYPE] ?? $item['type'] ?? 'info'),
            'title' => (string) ($item[Notification::schema_fields_TITLE] ?? $item['title'] ?? ''),
            'content' => (string) ($item[Notification::schema_fields_CONTENT] ?? $item['content'] ?? ''),
            'is_read' => $isRead,
            'read_label' => $isRead === 1 ? (string) __('Read') : (string) __('Unread'),
            'read_badge_class' => $isRead === 1 ? 'success' : 'warning',
            'created_at' => (string) ($item[Notification::schema_fields_CREATED_AT] ?? $item['created_at'] ?? ''),
        ];
    }

    private function normalizeModel(Notification $notification): array
    {
        $isRead = (int) $notification->getData(Notification::schema_fields_IS_READ);

        return [
            'notification_id' => (int) $notification->getId(),
            'customer_id' => (int) $notification->getData(Notification::schema_fields_CUSTOMER_ID),
            'type' => (string) $notification->getData(Notification::schema_fields_TYPE),
            'title' => (string) $notification->getData(Notification::schema_fields_TITLE),
            'content' => (string) $notification->getData(Notification::schema_fields_CONTENT),
            'is_read' => $isRead,
            'read_label' => $isRead === 1 ? (string) __('Read') : (string) __('Unread'),
            'created_at' => (string) $notification->getData(Notification::schema_fields_CREATED_AT),
        ];
    }
}

