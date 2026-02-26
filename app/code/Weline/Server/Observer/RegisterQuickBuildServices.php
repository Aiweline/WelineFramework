<?php
declare(strict_types=1);

namespace Weline\Server\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * @DESC | 向 PageBuilder 快速建站注册 SSL 证书服务能力
 */
class RegisterQuickBuildServices implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!\is_array($data)) {
            return;
        }

        $category = $data['category'] ?? 'all';
        $services = $data['services'] ?? [];

        if ($category === 'all' || $category === 'ssl') {
            $services[] = [
                'module' => 'Weline_Server',
                'category' => 'ssl',
                'name' => __('SSL 证书服务'),
                'description' => __("Let's Encrypt 免费 SSL 证书自动签发和续期"),
                'admin_url' => 'server/backend/ssl/index',
                'icon' => 'mdi-lock',
                'order' => 40,
                'available' => true,
            ];
        }

        $data['services'] = $services;
        $event->setData('data', $data);
    }
}
