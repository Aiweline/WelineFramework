<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\Zone;
use Weline\Shipping\Model\ZoneRegion;
use Weline\Shipping\Model\Region;

/**
 * 配送区域服务
 * 
 * @package Weline_Shipping
 */
class ZoneService
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 获取配送区域模型实例
     * 
     * @return Zone
     */
    private function getZoneModel(): Zone
    {
        return $this->objectManager->getInstance(Zone::class);
    }

    /**
     * 获取区域地区关联模型实例
     * 
     * @return ZoneRegion
     */
    private function getZoneRegionModel(): ZoneRegion
    {
        return $this->objectManager->getInstance(ZoneRegion::class);
    }

    /**
     * 获取地区模型实例
     * 
     * @return Region
     */
    private function getRegionModel(): Region
    {
        return $this->objectManager->getInstance(Region::class);
    }

    /**
     * 根据地址匹配配送区域
     * 
     * @param string $countryCode 国家代码
     * @param string|null $province 省/州
     * @param string|null $city 市
     * @param string|null $district 区县
     * @return Zone|null
     */
    public function matchZoneByAddress(
        string $countryCode,
        ?string $province = null,
        ?string $city = null,
        ?string $district = null
    ): ?Zone {
        $zoneRegionModel = $this->getZoneRegionModel();
        $regionModel = $this->getRegionModel();
        
        // 构建匹配条件
        $conditions = [];
        
        // 1. 根据国家代码匹配
        if ($countryCode) {
            $countryRegion = $regionModel->reset()
                ->where(Region::fields_COUNTRY_CODE, $countryCode)
                ->where(Region::fields_REGION_TYPE, Region::TYPE_COUNTRY)
                ->find()
                ->fetch();
            
            if ($countryRegion->getId()) {
                $conditions[] = $countryRegion->getId();
            }
        }
        
        // 2. 根据省/州匹配
        if ($province) {
            $provinceRegion = $regionModel->reset()
                ->where(Region::fields_COUNTRY_CODE, $countryCode)
                ->where(Region::fields_REGION_TYPE, Region::TYPE_PROVINCE)
                ->where(Region::fields_REGION_NAME, $province)
                ->find()
                ->fetch();
            
            if ($provinceRegion->getId()) {
                $conditions[] = $provinceRegion->getId();
            }
        }
        
        // 3. 根据市匹配
        if ($city) {
            $cityRegion = $regionModel->reset()
                ->where(Region::fields_COUNTRY_CODE, $countryCode)
                ->where(Region::fields_REGION_TYPE, Region::TYPE_CITY)
                ->where(Region::fields_REGION_NAME, $city)
                ->find()
                ->fetch();
            
            if ($cityRegion->getId()) {
                $conditions[] = $cityRegion->getId();
            }
        }
        
        // 4. 根据区县匹配（精确匹配）
        if ($district) {
            $districtRegion = $regionModel->reset()
                ->where(Region::fields_COUNTRY_CODE, $countryCode)
                ->where(Region::fields_REGION_TYPE, Region::TYPE_DISTRICT)
                ->where(Region::fields_REGION_NAME, $district)
                ->find()
                ->fetch();
            
            if ($districtRegion->getId()) {
                $conditions[] = $districtRegion->getId();
            }
        }
        
        if (empty($conditions)) {
            return null;
        }
        
        // 查找包含这些地区的配送区域
        $zoneIds = $zoneRegionModel->reset()
            ->where(ZoneRegion::fields_REGION_ID, $conditions, 'IN')
            ->select()
            ->fetch()
            ->getItems();
        
        if (empty($zoneIds)) {
            return null;
        }
        
        // 获取第一个匹配的配送区域
        $zoneId = $zoneIds[0]->getData(ZoneRegion::fields_ZONE_ID);
        $zone = $this->getZoneModel()->load($zoneId);
        
        return $zone->getId() ? $zone : null;
    }

    /**
     * 设置区域关联的地区
     * 
     * @param int $zoneId 配送区域ID
     * @param array $regionIds 地区ID数组
     * @return bool
     */
    public function setZoneRegions(int $zoneId, array $regionIds): bool
    {
        $zoneRegionModel = $this->getZoneRegionModel();
        
        // 先删除现有关联
        $zoneRegionModel->deleteByZoneId($zoneId);
        
        // 批量添加新关联
        if (!empty($regionIds)) {
            $zoneRegionModel->batchAdd($zoneId, $regionIds);
        }
        
        return true;
    }

    /**
     * 获取区域关联的地区列表
     * 
     * @param int $zoneId 配送区域ID
     * @return \Weline\Framework\Database\Model\Collection
     */
    public function getZoneRegions(int $zoneId): \Weline\Framework\Database\Model\Collection
    {
        $zoneRegionModel = $this->getZoneRegionModel();
        $regionModel = $this->getRegionModel();
        
        $zoneRegions = $zoneRegionModel->reset()
            ->where(ZoneRegion::fields_ZONE_ID, $zoneId)
            ->select()
            ->fetch();
        
        $regionIds = [];
        foreach ($zoneRegions->getItems() as $zoneRegion) {
            $regionIds[] = $zoneRegion->getData(ZoneRegion::fields_REGION_ID);
        }
        
        if (empty($regionIds)) {
            return $regionModel->reset()->select()->fetch();
        }
        
        return $regionModel->reset()
            ->where(Region::fields_ID, $regionIds, 'IN')
            ->select()
            ->fetch();
    }
}

