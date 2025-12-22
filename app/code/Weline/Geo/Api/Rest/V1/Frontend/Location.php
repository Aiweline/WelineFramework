<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Geo\Api\Rest\V1\Frontend;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Geo\Service\GeoLocationService;

/**
 * Geo定位API控制器
 * 
 * 提供IP定位API接口
 * 路由: /geo/rest/v1/frontend/location/ip
 */
class Location extends FrontendRestController
{
    /**
     * 获取IP位置信息
     * 
     * GET /geo/rest/v1/frontend/location/ip?ip=xxx
     * 
     * @return array
     */
    public function getIp(): array
    {
        try {
            $ip = $this->request->getParam('ip');
            
            /** @var GeoLocationService $geoService */
            $geoService = ObjectManager::getInstance(GeoLocationService::class);
            
            $location = $geoService->getLocationByIp($ip);
            
            return $this->success(__('定位成功'), $location);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}

