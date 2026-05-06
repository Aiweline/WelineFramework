<?php

declare(strict_types=1);

namespace WeShop\Analytics\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use WeShop\Analytics\Service\PixelDispatcher;

/**
 * 客户登录像素追踪观察者
 */
class CustomerLoginPixel implements ObserverInterface
{
    public function __construct(
        private readonly PixelDispatcher $pixelDispatcher
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $customer = is_array($data) ? ($data['customer'] ?? null) : (is_object($data) && method_exists($data, 'getData') ? $data->getData('customer') : null);

        if (
            !$customer
            || !is_object($customer)
            || !method_exists($customer, 'getId')
            || !method_exists($customer, 'getCurrency')
            || !method_exists($customer, 'getLocale')
        ) {
            return;
        }

        $this->pixelDispatcher->track('login', [
            'user_id' => $customer->getId(),
            'module' => 'WeShop_Customer',
            'name' => 'customer_login',
            'url' => \Weline\Framework\Env\WelineEnv::server('REQUEST_URI', ''),
            'currency' => $customer->getCurrency() ?? 'CNY',
            'lang' => $customer->getLocale() ?? 'zh_CN',
        ]);
    }
}
