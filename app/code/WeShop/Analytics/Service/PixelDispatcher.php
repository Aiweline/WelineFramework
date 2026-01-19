<?php

declare(strict_types=1);

namespace WeShop\Analytics\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Analytics\Interface\PixelProviderInterface;

/**
 * 像素分发服务
 */
class PixelDispatcher
{
    /**
     * 分发像素事件
     * 
     * @param string $eventName 事件名称
     * @param array $eventData 事件数据
     * @return void
     */
    public function dispatch(string $eventName, array $eventData): void
    {
        // 获取所有启用的像素提供者
        $providers = $this->getActiveProviders();
        
        foreach ($providers as $provider) {
            try {
                $provider->sendEvent($eventName, $eventData);
            } catch (\Exception $e) {
                // 记录错误但继续处理其他提供者
                \Weline\Framework\App\Env::log_warning('pixel_dispatcher.log', 
                    __('像素事件发送失败: %{1}', [$e->getMessage()]));
            }
        }
    }
    
    /**
     * 获取所有启用的像素提供者
     * 
     * @return PixelProviderInterface[]
     */
    protected function getActiveProviders(): array
    {
        $providers = [];
        
        // 获取配置的提供者列表
        $providerClasses = [
            \WeShop\Analytics\Provider\FacebookPixel::class,
        ];
        
        foreach ($providerClasses as $providerClass) {
            try {
                $provider = ObjectManager::getInstance($providerClass);
                if ($provider instanceof PixelProviderInterface) {
                    $providers[] = $provider;
                }
            } catch (\Exception $e) {
                // 忽略错误
            }
        }
        
        return $providers;
    }
}
