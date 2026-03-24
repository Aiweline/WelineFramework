<?php

declare(strict_types=1);

namespace WeShop\Analytics\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use WeShop\Analytics\Service\PixelDispatcher;

/**
 * 客户注册像素追踪观察者
 */
class CustomerRegisterPixel implements ObserverInterface
{
    public function __construct(
        private readonly PixelDispatcher $pixelDispatcher
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $customer = $data->getData('customer');

        if (!$customer) {
            return;
        }

        $this->pixelDispatcher->track('register', [
            'user_id' => $customer->getId(),
            'module' => 'WeShop_Customer',
            'name' => 'customer_register',
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'currency' => $customer->getCurrency() ?? 'CNY',
            'lang' => $customer->getLocale() ?? 'zh_CN',
        ]);
    }
}
