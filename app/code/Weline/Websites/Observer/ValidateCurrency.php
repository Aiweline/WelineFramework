<?php

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Websites\Data\WebsiteData;

/**
 * 货币验证观察者
 * 验证货币是否被当前网站允许
 */
class ValidateCurrency implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 如果还没有检测到网站，直接返回（不阻止其他观察者）
        if (!WebsiteData::getWebsite()) {
            return;
        }

        $code = $event->getData('code');
        if (empty($code)) {
            return;
        }

        // 验证货币是否被当前网站允许
        if (!WebsiteData::isCurrencyAllowed($code)) {
            // 货币不被允许，拒绝访问
            $event->setData('result', false);
            $event->setData('error', __('货币 %{1} 不被当前网站允许', $code));
        } else {
            // 货币被允许，设置检测成功
            $event->setData('result', true);
        }
    }
}

