<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionManager;
use Weline\Shipping\Service\DeliveryAddressService;

/**
 * 配送地址前端API控制器
 * 
 * 提供配送地址的CRUD操作和session管理
 */
class DeliveryAddress extends FrontendRestController
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
     * 更新session中的配送地址
     * 
     * POST /shipping/rest/v1/frontend/delivery-address/update-session
     * Body: {
     *   "country": "中国",
     *   "province": "北京市",
     *   "city": "北京市",
     *   "district": "朝阳区",
     *   "street": "xxx街道",
     *   "postal_code": "100000",
     *   "latitude": 39.9042,
     *   "longitude": 116.4074
     * }
     * 
     * @return array
     */
    public function updateSession(): array
    {
        try {
            $body = $this->request->getBodyParams();
            
            // 验证必填字段
            $requiredFields = ['country', 'province', 'city'];
            foreach ($requiredFields as $field) {
                if (empty($body[$field])) {
                    return $this->error(__('%{1}不能为空', [$field]), 400);
                }
            }
            
            // 构建配送地址数据
            $deliveryAddress = [
                'country' => $body['country'] ?? '',
                'province' => $body['province'] ?? '',
                'city' => $body['city'] ?? '',
                'district' => $body['district'] ?? '',
                'street' => $body['street'] ?? '',
                'postal_code' => $body['postal_code'] ?? '',
                'latitude' => $body['latitude'] ?? null,
                'longitude' => $body['longitude'] ?? null,
                'full_address' => $this->buildFullAddress($body),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            
            // 保存到session
            $session = $this->sessionManager->create();
            $session->set('shipping_delivery_address', $deliveryAddress);
            
            // 如果用户已登录，尝试同步到数据库
            $customerId = $this->getCustomerId();
            if ($customerId) {
                $this->syncToDatabase($customerId, $deliveryAddress);
            }
            
            return $this->success(__('配送地址更新成功'), $deliveryAddress);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * 获取session中的配送地址
     * 
     * GET /shipping/rest/v1/frontend/delivery-address/get-session
     * 
     * @return array
     */
    public function getSession(): array
    {
        try {
            $session = $this->sessionManager->create();
            $address = $session->get('shipping_delivery_address');
            
            if (empty($address)) {
                return $this->success(__('暂无配送地址'), null);
            }
            
            return $this->success(__('获取成功'), $address);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * 同步浏览器存储的地址到session（登录后调用）
     * 
     * POST /shipping/rest/v1/frontend/delivery-address/sync-from-browser
     * Body: {
     *   "address": {...}
     * }
     * 
     * @return array
     */
    public function syncFromBrowser(): array
    {
        try {
            $customerId = $this->getCustomerId();
            if (!$customerId) {
                return $this->error(__('用户未登录'), 401);
            }
            
            $body = $this->request->getBodyParams();
            $address = $body['address'] ?? null;
            
            if (empty($address) || !is_array($address)) {
                return $this->error(__('地址数据不能为空'), 400);
            }
            
            // 同步到session
            $session = $this->sessionManager->create();
            $session->set('shipping_delivery_address', $address);
            
            // 同步到数据库
            $this->syncToDatabase($customerId, $address);
            
            return $this->success(__('同步成功'), $address);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
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
            $address['province'] ?? '',
            $address['city'] ?? '',
            $address['district'] ?? '',
            $address['street'] ?? '',
        ]);
        return implode('', $parts);
    }
}
