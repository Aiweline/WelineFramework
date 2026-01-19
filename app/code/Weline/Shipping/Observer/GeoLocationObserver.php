<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionManager;
use Weline\Shipping\Service\DeliveryAddressService;

/**
 * Geo定位观察者
 * 
 * 监听Geo模块的地址更新事件，自动更新配送地址信息到session
 */
class GeoLocationObserver implements ObserverInterface
{
    private SessionManager $sessionManager;
    private DeliveryAddressService $deliveryAddressService;

    public function __construct(
        SessionManager $sessionManager,
        DeliveryAddressService $deliveryAddressService
    ) {
        $this->sessionManager = $sessionManager;
        $this->deliveryAddressService = $deliveryAddressService;
    }

    /**
     * 执行观察者逻辑
     * 
     * 监听 Weline_Geo::address-updated 事件
     * 当Geo模块更新地址时，自动更新配送地址信息
     * 
     * @param Event $event
     */
    public function execute(Event &$event): void
    {
        try {
            // 获取事件数据
            $address = $event->getData('address');
            $latitude = $event->getData('latitude');
            $longitude = $event->getData('longitude');
            
            if (empty($address) || !is_array($address)) {
                return;
            }
            
            // 获取session
            $session = $this->sessionManager->create();
            
            // 构建配送地址数据
            $deliveryAddress = [
                'country' => $address['country'] ?? '',
                'province' => $address['province'] ?? $address['region'] ?? '',
                'city' => $address['city'] ?? '',
                'district' => $address['district'] ?? '',
                'street' => $address['street'] ?? '',
                'postal_code' => $address['postalCode'] ?? $address['postal_code'] ?? '',
                'latitude' => $latitude,
                'longitude' => $longitude,
                'full_address' => $address['full_address'] ?? $this->buildFullAddress($address),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            
            // 保存到session
            $session->set('shipping_delivery_address', $deliveryAddress);
            
            // 如果用户已登录，尝试同步到数据库
            $customerId = $this->getCustomerId();
            if ($customerId) {
                $this->syncToDatabase($customerId, $deliveryAddress);
            }
            
            // 设置事件数据，表示已处理
            $event->setData('shipping_address_updated', true);
            
        } catch (\Exception $e) {
            // 静默处理错误，不影响其他模块
            // 可以记录日志
            error_log('Shipping GeoLocationObserver error: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取当前登录用户ID
     * 
     * @return int|null
     */
    private function getCustomerId(): ?int
    {
        try {
            // 检查是否有Customer模块的session
            $session = $this->sessionManager->create();
            $customerData = $session->get('weshop_customer');
            
            if ($customerData && isset($customerData['customer_id'])) {
                return (int)$customerData['customer_id'];
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 同步地址到数据库
     * 
     * @param int $customerId
     * @param array $addressData
     * @return void
     */
    private function syncToDatabase(int $customerId, array $addressData): void
    {
        try {
            // 检查是否已有默认地址
            $defaultAddress = $this->deliveryAddressService->getDefaultByCustomer($customerId);
            
            if ($defaultAddress) {
                // 更新现有默认地址
                $updateData = [
                    'country' => $addressData['country'],
                    'province' => $addressData['province'],
                    'city' => $addressData['city'],
                    'district' => $addressData['district'],
                    'street' => $addressData['street'],
                    'postal_code' => $addressData['postal_code'],
                ];
                
                $this->deliveryAddressService->update(
                    $defaultAddress->getId(),
                    $updateData,
                    $customerId
                );
            } else {
                // 创建新地址作为默认地址
                $createData = [
                    'name' => __('自动定位地址'),
                    'contact_name' => '',
                    'contact_phone' => '',
                    'country' => $addressData['country'] ?: '中国',
                    'province' => $addressData['province'],
                    'city' => $addressData['city'],
                    'district' => $addressData['district'],
                    'street' => $addressData['street'],
                    'postal_code' => $addressData['postal_code'],
                    'is_default' => 1,
                    'is_enabled' => 1,
                ];
                
                $this->deliveryAddressService->create($customerId, $createData);
            }
        } catch (\Exception $e) {
            // 静默处理错误，不影响主流程
            error_log('Shipping syncToDatabase error: ' . $e->getMessage());
        }
    }
    
    /**
     * 构建完整地址字符串
     * 
     * @param array $address
     * @return string
     */
    private function buildFullAddress(array $address): string
    {
        $parts = array_filter([
            $address['country'] ?? '',
            $address['province'] ?? $address['region'] ?? '',
            $address['city'] ?? '',
            $address['district'] ?? '',
            $address['street'] ?? '',
        ]);
        return implode('', $parts);
    }
}
