<?php

declare(strict_types=1);

namespace WeShop\Analytics\Interface;

/**
 * 像素提供者接口
 */
interface PixelProviderInterface
{
    /**
     * 发送像素事件
     * 
     * @param string $eventName 事件名称
     * @param array $eventData 事件数据
     * @return bool
     */
    public function sendEvent(string $eventName, array $eventData): bool;
    
    /**
     * 获取像素代码
     * 
     * @return string
     */
    public function getPixelCode(): string;
}
