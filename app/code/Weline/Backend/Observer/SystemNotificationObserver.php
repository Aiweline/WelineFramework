<?php

declare(strict_types=1);

namespace Weline\Backend\Observer;

use Weline\Backend\Enum\NotificationType;
use Weline\Backend\Model\SystemNotification;
use Weline\Backend\Service\NotificationRouter;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

class SystemNotificationObserver implements ObserverInterface
{
    private SystemNotification $notificationModel;

    public function __construct(
        SystemNotification $notificationModel
    ) {
        $this->notificationModel = $notificationModel;
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (empty($data)) {
            return;
        }

        $topic = $data['topic'] ?? 'system_info';
        $type = $data['type'] ?? 'info';
        $title = $data['title'] ?? '';
        $content = $data['content'] ?? '';

        if (empty($title)) {
            return;
        }

        $priority = $data['priority'] ?? null;
        if ($priority === null) {
            $priority = NotificationType::fromString($type)->getPriority();
        }

        $notification = clone $this->notificationModel;
        $notification->clearQuery()
            ->setTopicCode($topic)
            ->setType($type)
            ->setTitle($title)
            ->setContent($content)
            ->setPriority((int) $priority)
            ->setSourceModule($data['source_module'] ?? $this->detectSourceModule())
            ->setMetadata($data['metadata'] ?? [])
            ->setIsIcon((bool) ($data['is_icon'] ?? true))
            ->setIsImg((bool) ($data['is_img'] ?? false))
            ->setAvatar($data['avatar'] ?? 'ri-notification-line')
            ->setExternalNotified(false)
            ->setExternalChannels([]);

        $notification->save();

        $notificationId = $notification->getId();
        if ($notificationId) {
            $notifyUsers = $data['notify_users'] ?? [];

            /** @var NotificationRouter $router */
            $router = ObjectManager::getInstance(NotificationRouter::class);
            $router->route([
                'notification_id' => $notificationId,
                'topic_code'      => $topic,
                'type'            => $type,
                'title'           => $title,
                'content'         => $content,
                'priority'        => $priority,
                'metadata'        => $data['metadata'] ?? [],
                'notify_users'    => $notifyUsers,
            ]);
        }
    }

    private function detectSourceModule(): string
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        foreach ($backtrace as $trace) {
            if (isset($trace['class'])) {
                $class = $trace['class'];
                if (preg_match('/^([A-Za-z0-9_]+\\\\[A-Za-z0-9_]+)\\\\/', $class, $matches)) {
                    $moduleName = str_replace('\\', '_', $matches[1]);
                    if ($moduleName !== 'Weline_Framework' && $moduleName !== 'Weline_Backend') {
                        return $moduleName;
                    }
                }
            }
        }
        return '';
    }
}
