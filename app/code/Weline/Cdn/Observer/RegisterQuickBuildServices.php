<?php
declare(strict_types=1);

namespace Weline\Cdn\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * @DESC | 向 PageBuilder 快速建站注册 CDN 服务能力
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

        if ($category === 'all' || $category === 'cdn') {
            $services[] = [
                'module' => 'Weline_Cdn',
                'category' => 'cdn',
                'name' => __('CDN 加速服务'),
                'description' => __('Cloudflare CDN 加速、缓存管理、安全防护'),
                'admin_url' => 'cdn/backend/account/index',
                'icon' => 'mdi-shield-check',
                'order' => 30,
                'available' => true,
            ];
        }

        $data['services'] = $services;
        $event->setData('data', $data);
    }
}
