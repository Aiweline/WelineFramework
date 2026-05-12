<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Location\Api\Rest\V1\Frontend;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Location\Service\LocationService;

/**
 * Geo定位API控制器
 * 
 * 提供IP定位和地址解析API接口
 * 路由: /location/rest/v1/frontend/location/*
 */
class Location extends FrontendRestController
{
    /**
     * 获取IP位置信息
     * 
     * GET /location/rest/v1/frontend/location/ip?ip=xxx
     * 
     * @return array
     */
    public function getIp(): array
    {
        try {
            $ip = $this->request->getParam('ip');
            
            /** @var LocationService $geoService */
            $geoService = ObjectManager::getInstance(LocationService::class);
            
            $location = $geoService->getLocationByIp($ip);
            
            return $this->success(__('定位成功'), $location);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * 根据经纬度获取地址信息（反向地理编码）
     * 
     * POST /location/rest/v1/frontend/location/address
     * Body: {"latitude": 39.9042, "longitude": 116.4074}
     * 
     * @return array
     */
    public function getAddress(): array
    {
        try {
            $body = $this->request->getBodyParams();
            $latitude = $body['latitude'] ?? null;
            $longitude = $body['longitude'] ?? null;
            
            if (empty($latitude) || empty($longitude)) {
                return $this->error(__('经纬度参数不能为空'), 400);
            }
            
            // 验证经纬度范围
            if (!is_numeric($latitude) || !is_numeric($longitude)) {
                return $this->error(__('经纬度格式不正确'), 400);
            }
            
            $latitude = (float)$latitude;
            $longitude = (float)$longitude;
            
            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                return $this->error(__('经纬度范围不正确'), 400);
            }
            
            // 准备事件数据
            $eventData = [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'address' => null,
            ];
            
            // 触发事件，允许其他模块处理地址解析
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $eventsManager->dispatch('Weline_Location::location-to-address', $eventData);
            
            // 获取事件返回的地址数据
            $event = $eventsManager->getEventData('Weline_Location::location-to-address');
            if ($event) {
                $eventAddress = $event->getData('address');
                if (is_array($eventAddress) && !empty($eventAddress)) {
                    $eventData['address'] = $eventAddress;
                }
            }
            
            // 如果没有地址数据，使用IP定位作为降级方案
            if (empty($eventData['address'])) {
                /** @var LocationService $geoService */
                $geoService = ObjectManager::getInstance(LocationService::class);
                $ipLocation = $geoService->getLocationByIp();
                
                if ($ipLocation['success'] ?? false) {
                    $eventData['address'] = [
                        'country' => $ipLocation['data']['country'] ?? '',
                        'countryCode' => $ipLocation['data']['countryCode'] ?? '',
                        'region' => $ipLocation['data']['region'] ?? '',
                        'province' => $ipLocation['data']['region'] ?? '',
                        'city' => $ipLocation['data']['city'] ?? '',
                        'district' => $ipLocation['data']['district'] ?? '',
                        'street' => $ipLocation['data']['street'] ?? '',
                        'postalCode' => $ipLocation['data']['postalCode'] ?? '',
                        'timezone' => $ipLocation['data']['timezone'] ?? '',
                        'full_address' => $this->buildFullAddress($ipLocation['data'] ?? []),
                    ];
                }
            }
            
            // 如果仍然没有地址数据，返回基本结构
            if (empty($eventData['address'])) {
                $eventData['address'] = [
                    'country' => '',
                    'province' => '',
                    'city' => '',
                    'district' => '',
                    'full_address' => '',
                ];
            }
            
            // 触发地址更新事件，通知其他模块（如Shipping模块）
            $updateEventData = [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'address' => $eventData['address'],
            ];
            $eventsManager->dispatch('Weline_Location::address-updated', $updateEventData);
            
            return $this->success(__('获取地址成功'), $eventData['address']);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * 构建完整地址字符串
     * 
     * @param array $data
     * @return string
     */
    private function buildFullAddress(array $data): string
    {
        $parts = array_filter([
            $data['country'] ?? '',
            $data['region'] ?? $data['province'] ?? '',
            $data['city'] ?? '',
            $data['district'] ?? '',
            $data['street'] ?? '',
        ]);
        return implode('', $parts);
    }
}

