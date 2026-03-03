<?php

declare(strict_types=1);

namespace Weline\Backend\Block\System;

use Weline\Backend\Enum\NotificationType;
use Weline\Backend\Model\SystemNotification;
use Weline\Backend\Model\UserNotificationStatus;
use Weline\Backend\Service\NotificationService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Block;

class Notification extends Block
{
    public string $_template = 'Weline_Backend::blocks/system/notification.phtml';

    private NotificationService $notificationService;

    public function __construct()
    {
        $this->notificationService = ObjectManager::getInstance(NotificationService::class);
    }

    public function getNotifications(): array
    {
        $userId = $this->getLoginUserId();
        if (!$userId) {
            return [];
        }

        $result = $this->notificationService->getUserNotifications($userId, 1, 10);
        return $result['items'] ?? [];
    }

    public function getUnreadCount(): int
    {
        $userId = $this->getLoginUserId();
        if (!$userId) {
            return 0;
        }

        return $this->notificationService->getUnreadCount($userId);
    }

    public function getTypeColor(string $type): string
    {
        return NotificationType::fromString($type)->getColor();
    }

    public function getTypeIcon(string $type): string
    {
        return NotificationType::fromString($type)->getIcon();
    }

    public function getTypeLabel(string $type): string
    {
        return NotificationType::fromString($type)->getLabel();
    }

    private function getLoginUserId(): int
    {
        $session = \Weline\Framework\Session\SessionFactory::getInstance()->createBackendSession();
        return (int) ($session->getUserId() ?? 0);
    }
}
