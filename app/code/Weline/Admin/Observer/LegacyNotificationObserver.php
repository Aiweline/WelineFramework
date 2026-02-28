<?php

declare(strict_types=1);

namespace Weline\Admin\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 兼容旧的 Weline_Admin::msg 事件
 *
 * 将旧格式的消息事件转发到新的 Weline_Backend::application::system_notification 事件
 */
class LegacyNotificationObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (empty($data)) {
            return;
        }

        $title = $data['title'] ?? '';
        $content = $data['msg'] ?? $data['content'] ?? '';

        if (empty($title)) {
            return;
        }

        $topic = $data['topic'] ?? 'system_info';

        $type = 'info';
        if (!empty($data['type'])) {
            $type = $data['type'];
        } elseif (isset($data['is_error']) && $data['is_error']) {
            $type = 'error';
        } elseif (isset($data['is_warning']) && $data['is_warning']) {
            $type = 'warning';
        } elseif (isset($data['is_success']) && $data['is_success']) {
            $type = 'success';
        }

        $options = [
            'metadata'      => $data['metadata'] ?? [],
            'icon'          => $data['avatar'] ?? $data['icon'] ?? 'ri-notification-line',
            'source_module' => $data['source_module'] ?? 'Weline_Admin',
        ];

        if (isset($data['priority'])) {
            $options['priority'] = (int) $data['priority'];
        }

        w_msg($topic, $type, $title, $content, $options);
    }
}
