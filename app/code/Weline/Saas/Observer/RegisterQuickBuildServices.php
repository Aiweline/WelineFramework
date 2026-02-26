<?php
declare(strict_types=1);

namespace Weline\Saas\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * @DESC | 向 PageBuilder 快速建站注册一站式配置服务能力
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

        if ($category === 'all' || $category === 'provisioning') {
            $services[] = [
                'module' => 'Weline_Saas',
                'category' => 'provisioning',
                'name' => __('一站式配置'),
                'description' => __('域名购买→DNS→CDN→SSL 全自动配置流程'),
                'admin_url' => 'saas/backend/provisioning/index',
                'icon' => 'mdi-cogs',
                'order' => 50,
                'available' => true,
            ];
        }

        $data['services'] = $services;
        $event->setData('data', $data);
    }
}
