<?php

declare(strict_types=1);

namespace Weline\Shipping\Extends\Module\Weline_SiteSetupAssistant\SetupTask;

use Weline\Framework\Manager\ObjectManager;
use Weline\SiteSetupAssistant\Api\AbstractSetupTaskProvider;
use Weline\Shipping\Model\ShippingAddress;

/**
 * 建站任务：发货地址与承运商
 */
class ShippingSetupTaskProvider extends AbstractSetupTaskProvider
{
    public function provideTasks(array $context = []): array
    {
        $scope = $this->resolveStorageScope($context);
        $href = $this->backendPath('shipping/backend/shippingaddress/index');
        $done = $this->hasShippingAddress();
        $status = $done ? 'done' : 'todo';
        $tip = $done
            ? (string)__('已检测到发货地址记录。')
            : (string)__('至少一条可用发货地址 + 承运商/费用模板，结账才能选配送。');

        return $this->tasks([[
            'code' => 'shipping',
            'sort' => 90,
            'category' => (string)__('物流'),
            'module' => 'Weline_Shipping',
            'title' => (string)__('发货地址与承运商'),
            'tip' => $tip,
            'status' => $status,
            'href' => $href,
            'scenarios' => ['new'],
            'meta' => ['configured' => $done],
        ]]);
    }

    private function hasShippingAddress(): bool
    {
        try {
            /** @var ShippingAddress $model */
            $model = ObjectManager::getInstance(ShippingAddress::class);
            $items = $model->clear()->limit(1)->select()->fetch()->getItems();
            return is_array($items) && $items !== [];
        } catch (\Throwable) {
            return false;
        }
    }
}
