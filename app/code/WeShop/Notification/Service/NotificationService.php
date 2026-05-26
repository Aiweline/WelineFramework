<?php

declare(strict_types=1);

namespace WeShop\Notification\Service;

use WeShop\Notification\Model\Notification;
use Weline\Framework\Manager\ObjectManager;

class NotificationService
{
    public function sendNotification(array $notificationData): Notification
    {
        /** @var Notification $notification */
        $notification = ObjectManager::getInstance(Notification::class);
        $customerId = (int) ($notificationData['customer_id'] ?? 0);
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('Customer ID is required.'));
        }

        $notification->clearData()
            ->setData(Notification::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(Notification::schema_fields_TYPE, (string) ($notificationData['type'] ?? 'info'))
            ->setData(Notification::schema_fields_TITLE, (string) ($notificationData['title'] ?? ''))
            ->setData(Notification::schema_fields_CONTENT, (string) ($notificationData['content'] ?? ''))
            ->setData(Notification::schema_fields_TARGET_URL, $this->normalizeTargetUrl($notificationData['target_url'] ?? ''))
            ->setData(Notification::schema_fields_IS_READ, 0)
            ->setData(Notification::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return $notification;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCustomerNotifications(int $customerId, int $limit = 20, bool $unreadOnly = false): array
    {
        /** @var Notification $notification */
        $notification = ObjectManager::getInstance(Notification::class);

        $query = $notification->clear()
            ->where(Notification::schema_fields_CUSTOMER_ID, $customerId);
        if ($unreadOnly) {
            $query->where(Notification::schema_fields_IS_READ, 0);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->order(Notification::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetchArray();
    }

    public function getUnreadCount(int $customerId): int
    {
        return count($this->getCustomerNotifications($customerId, 0, true));
    }

    /**
     * @return array<string, string>
     */
    public function getTypeOptions(): array
    {
        $options = [
            'info' => (string) __('Info'),
            'order' => (string) __('Order'),
            'payment' => (string) __('Payment'),
            'membership' => (string) __('Membership'),
            'promotion' => (string) __('Promotion'),
            'qa_mention' => (string) __('商品问答提及'),
            'review_reply' => (string) __('商品评价回复'),
        ];

        $rows = $this->createNotificationModel()
            ->clear()
            ->fields('DISTINCT ' . Notification::schema_fields_TYPE . ' AS type')
            ->select()
            ->fetchArray();

        foreach ($rows as $row) {
            $type = trim((string) ($row['type'] ?? ''));
            if ($type === '' || isset($options[$type])) {
                continue;
            }

            $options[$type] = ucfirst(str_replace(['-', '_'], ' ', $type));
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAdminNotifications(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        $notification = $this->createNotificationModel()->clear();
        $this->applyAdminFilters($notification, $filters);

        $notification->order(Notification::schema_fields_CREATED_AT, 'DESC')
            ->pagination(max(1, $page), max(1, $pageSize));

        return [
            'items' => $notification->select()->fetchArray(),
            'total' => $notification->getTotalCount(),
            'pagination' => $notification->getPagination(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function getAdminSummary(array $filters = []): array
    {
        return [
            'total_count' => $this->countAdminNotifications($filters),
            'unread_count' => $this->countAdminNotifications($filters, 0),
            'read_count' => $this->countAdminNotifications($filters, 1),
        ];
    }

    public function getNotificationById(int $notificationId): ?Notification
    {
        if ($notificationId <= 0) {
            return null;
        }

        $notification = $this->createNotificationModel();
        $notification->load($notificationId);

        return $notification->getId() ? $notification : null;
    }

    public function markAsRead(int $notificationId, int $customerId = 0): bool
    {
        $notification = $this->createNotificationModel();
        $notification->load($notificationId);

        if (!$notification->getId()) {
            return false;
        }

        if ($customerId > 0 && (int) $notification->getData(Notification::schema_fields_CUSTOMER_ID) !== $customerId) {
            return false;
        }

        if ((int) $notification->getData(Notification::schema_fields_IS_READ) === 1) {
            return true;
        }

        $notification->setData(Notification::schema_fields_IS_READ, 1)->save();
        return true;
    }

    private function countAdminNotifications(array $filters = [], ?int $isRead = null): int
    {
        $notification = $this->createNotificationModel()->clear();
        $this->applyAdminFilters($notification, $filters);

        if ($isRead !== null) {
            $notification->where(Notification::schema_fields_IS_READ, $isRead);
        }

        return $notification->count();
    }

    private function applyAdminFilters(Notification $notification, array $filters): void
    {
        if (!empty($filters['customer_id'])) {
            $notification->where(Notification::schema_fields_CUSTOMER_ID, (int) $filters['customer_id']);
        }

        if (!empty($filters['type'])) {
            $notification->where(Notification::schema_fields_TYPE, (string) $filters['type']);
        }

        if (array_key_exists('is_read', $filters) && $filters['is_read'] !== '' && $filters['is_read'] !== null) {
            $notification->where(Notification::schema_fields_IS_READ, (int) $filters['is_read']);
        }

        if (!empty($filters['title'])) {
            $notification->where(Notification::schema_fields_TITLE, '%' . trim((string) $filters['title']) . '%', 'LIKE');
        }
    }

    private function createNotificationModel(): Notification
    {
        /** @var Notification $notification */
        $notification = ObjectManager::getInstance(Notification::class);
        return $notification;
    }

    private function normalizeTargetUrl(mixed $targetUrl): string
    {
        $url = substr(trim((string) $targetUrl), 0, 500);
        if ($url === '') {
            return '';
        }

        $url = preg_replace('/[\x00-\x1F\x7F]/', '', $url) ?? '';
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (in_array($scheme, ['http', 'https'], true)) {
            return $url;
        }

        return '';
    }
}
