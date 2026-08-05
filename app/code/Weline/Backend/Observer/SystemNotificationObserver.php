<?php

declare(strict_types=1);

namespace Weline\Backend\Observer;

use Weline\Backend\Enum\NotificationType;
use Weline\Backend\Service\NotificationRouter;
use Weline\Backend\Service\ScopedNotificationWriter;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

class SystemNotificationObserver implements ObserverInterface
{
    public function __construct(
        private readonly ScopedNotificationWriter $writer,
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!\is_array($data) || $data === []) {
            return;
        }

        $title = \trim((string)($data['title'] ?? ''));
        if ($title === '') {
            return;
        }

        $type = (string)($data['type'] ?? 'info');
        if (!\array_key_exists('priority', $data) || $data['priority'] === null) {
            $data['priority'] = NotificationType::fromString($type)->getPriority();
        }
        if (($data['source_module'] ?? '') === '') {
            $data['source_module'] = $this->detectSourceModule();
        }

        $result = $this->writer->write($data);
        $notification = $result['notification'];
        $notificationId = (int)$notification->getId();
        if ($notificationId <= 0) {
            return;
        }

        // 受控审计行已落库；无授权接收人时禁止广播（含外部渠道）。
        if ($result['suppress_broadcast']) {
            return;
        }

        /** @var NotificationRouter $router */
        $router = ObjectManager::getInstance(NotificationRouter::class);
        $router->route([
            'notification_id' => $notificationId,
            'topic_code' => $notification->getTopicCode(),
            'type' => $notification->getType(),
            'title' => $notification->getTitle(),
            'content' => $notification->getContent(),
            'priority' => $notification->getPriority(),
            'metadata' => $notification->getMetadata(),
            'notify_users' => $result['notify_users'],
            'require_explicit_recipients' => $result['require_explicit_recipients'],
            'scope_hash' => $notification->getScopeHash(),
            'dedupe_key' => $notification->getDedupeKey(),
        ]);
    }

    private function detectSourceModule(): string
    {
        $backtrace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        foreach ($backtrace as $trace) {
            if (isset($trace['class'])) {
                $class = $trace['class'];
                if (\preg_match('/^([A-Za-z0-9_]+\\\\[A-Za-z0-9_]+)\\\\/', $class, $matches)) {
                    $moduleName = \str_replace('\\', '_', $matches[1]);
                    if ($moduleName !== 'Weline_Framework' && $moduleName !== 'Weline_Backend') {
                        return $moduleName;
                    }
                }
            }
        }

        return '';
    }
}
