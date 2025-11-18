<?php

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Websites\Data\WebsiteData;

/**
 * 语言验证观察者
 * 验证语言是否被当前网站允许
 */
class ValidateLanguage implements ObserverInterface
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

        // 验证语言是否被当前网站允许
        if (!WebsiteData::isLanguageAllowed($code)) {
            // 语言不被允许，拒绝访问
            $event->setData('result', false);
            $event->setData('error', __('语言 %{1} 不被当前网站允许', $code));
        } else {
            // 语言被允许，设置检测成功
            $event->setData('result', true);
        }
    }
}

