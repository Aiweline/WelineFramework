<?php

declare(strict_types=1);

namespace WeShop\Analytics\Provider;

use WeShop\Analytics\Interface\PixelProviderInterface;

/**
 * Facebook像素提供者
 */
class FacebookPixel implements PixelProviderInterface
{
    /**
     * @inheritDoc
     */
    public function sendEvent(string $eventName, array $eventData): bool
    {
        // TODO: 实现Facebook像素事件发送
        return true;
    }
    
    /**
     * @inheritDoc
     */
    public function getPixelCode(): string
    {
        // TODO: 返回Facebook像素代码
        return '';
    }
}
