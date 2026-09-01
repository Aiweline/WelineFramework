<?php

declare(strict_types=1);

namespace Weline\Backend\Block\System;

use Weline\Backend\Enum\NotificationType;
use Weline\Backend\Service\BackendWarmupContext;
use Weline\Backend\Service\NotificationService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Block;

class Notification extends Block
{
    public const INBOX_REVISION_SESSION_KEY = 'backend_notification_inbox_rev';

    private const NOTIFICATION_CACHE_TTL = 15.0;

    public string $_template = 'Weline_Backend::blocks/system/notification.phtml';

    private NotificationService $notificationService;

    /**
     * @var array<int, array{expires: float, items: array, unread: int}>
     */
    private static array $notificationCache = [];

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

        return $this->getCachedNotificationData($userId)['items'];
    }

    public function getUnreadCount(): int
    {
        $userId = $this->getLoginUserId();
        if (!$userId) {
            return 0;
        }

        return $this->getCachedNotificationData($userId)['unread'];
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

    /**
     * Drop process-local unread/list snapshot after mark-as-read mutations.
     */
    public static function clearCache(?int $userId = null): void
    {
        if ($userId === null) {
            self::$notificationCache = [];
            return;
        }
        unset(self::$notificationCache[$userId]);
    }

    private function getLoginUserId(): int
    {
        // Warmup identity is valid only on the explicitly marked internal
        // warmup request. Never let a stale RequestContext value outrank the
        // authenticated browser session in a long-running WLS worker.
        $request = ObjectManager::getInstance(Request::class);
        if (BackendWarmupContext::isInternalWarmupRequest($request)) {
            $warmupUserId = BackendWarmupContext::currentUserId();
            if ($warmupUserId > 0) {
                return $warmupUserId;
            }
        }

        $session = \Weline\Framework\Session\SessionFactory::getInstance()->createBackendSession();
        if (\method_exists($session, 'getLoginUserID')) {
            return (int)($session->getLoginUserID() ?? 0);
        }

        return (int)($session->getUserId() ?? 0);
    }

    /**
     * @return array{items: array, unread: int}
     */
    private function getCachedNotificationData(int $userId): array
    {
        $now = microtime(true);
        if (isset(self::$notificationCache[$userId]) && self::$notificationCache[$userId]['expires'] >= $now) {
            return [
                'items' => self::$notificationCache[$userId]['items'],
                'unread' => self::$notificationCache[$userId]['unread'],
            ];
        }

        $result = $this->notificationService->getUserNotifications($userId, 1, 10);
        $data = [
            'expires' => $now + self::NOTIFICATION_CACHE_TTL,
            'items' => $result['items'] ?? [],
            'unread' => $this->notificationService->getUnreadCount($userId),
        ];
        self::$notificationCache[$userId] = $data;

        return [
            'items' => $data['items'],
            'unread' => $data['unread'],
        ];
    }
}
