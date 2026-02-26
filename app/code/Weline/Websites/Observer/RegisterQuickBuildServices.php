<?php
declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * @DESC | 向 PageBuilder 快速建站注册域名服务能力
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

        if ($category === 'all' || $category === 'domain') {
            $services[] = [
                'module' => 'Weline_Websites',
                'category' => 'domain',
                'name' => __('域名注册服务'),
                'description' => __('通过 GName 等域名商注册和管理域名'),
                'admin_url' => 'websites/admin/domain/index',
                'icon' => 'mdi-dns',
                'order' => 10,
                'available' => true,
            ];
        }

        if ($category === 'all' || $category === 'dns') {
            $services[] = [
                'module' => 'Weline_Websites',
                'category' => 'dns',
                'name' => __('DNS 管理'),
                'description' => __('域名 NS 切换和 DNS 记录管理'),
                'admin_url' => 'websites/admin/domain/index',
                'icon' => 'mdi-server-network',
                'order' => 20,
                'available' => true,
            ];
        }

        $data['services'] = $services;
        $event->setData('data', $data);
    }
}
