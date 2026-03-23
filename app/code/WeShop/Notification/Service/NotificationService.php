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

    public function markAsRead(int $notificationId, int $customerId = 0): bool
    {
        /** @var Notification $notification */
        $notification = ObjectManager::getInstance(Notification::class);
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
}
