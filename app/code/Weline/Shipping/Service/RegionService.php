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
use Weline\Shipping\Model\Region;

/**
 * 地区服务
 * 
 * @package Weline_Shipping
 */
class RegionService
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 获取地区模型实例
     * 
     * @return Region
     */
    private function getModel(): Region
    {
        return $this->objectManager->getInstance(Region::class);
    }

    /**
     * 构建地区树形结构
     * 
     * @param string|null $countryCode 国家代码，null表示获取所有国家
     * @return array
     */
    public function buildTree(?string $countryCode = null): array
    {
        $model = $this->getModel();
        $regions = [];
        
        if ($countryCode) {
            $countryRegions = $model->getByCountryCode($countryCode);
        } else {
            $countryRegions = $model->reset()
                ->where(Region::fields_REGION_TYPE, Region::TYPE_COUNTRY)
                ->where(Region::fields_IS_ACTIVE, 1)
                ->order(Region::fields_SORT_ORDER, 'ASC')
                ->select()
                ->fetch();
        }
        
        foreach ($countryRegions->getItems() as $region) {
            $regions[] = $this->buildNode($region);
        }
        
        return $regions;
    }

    /**
     * 构建单个节点（递归）
     * 
     * @param Region $region
     * @return array
     */
    private function buildNode(Region $region): array
    {
        $node = [
            'region_id' => $region->getId(),
            'country_code' => $region->getData(Region::fields_COUNTRY_CODE),
            'region_code' => $region->getData(Region::fields_REGION_CODE),
            'region_name' => $region->getData(Region::fields_REGION_NAME),
            'region_type' => $region->getData(Region::fields_REGION_TYPE),
            'postal_code_pattern' => $region->getData(Region::fields_POSTAL_CODE_PATTERN),
            'is_active' => $region->getData(Region::fields_IS_ACTIVE),
            'sort_order' => $region->getData(Region::fields_SORT_ORDER),
            'children' => [],
        ];
        
        $children = $region->getChildren($region->getId());
        foreach ($children->getItems() as $child) {
            $node['children'][] = $this->buildNode($child);
        }
        
        return $node;
    }

    /**
     * 根据国家代码获取地区树
     * 
     * @param string $countryCode ISO国家代码
     * @return array
     */
    public function getTreeByCountryCode(string $countryCode): array
    {
        return $this->buildTree($countryCode);
    }

    /**
     * 从i18n同步国家数据
     * 
     * @param array $countries 国家数据数组
     * @return int 同步的数量
     */
    public function syncFromI18n(array $countries): int
    {
        $count = 0;
        $model = $this->getModel();
        
        foreach ($countries as $country) {
            $countryCode = $country['code'] ?? null;
            $countryName = $country['name'] ?? '';
            
            if (!$countryCode) {
                continue;
            }
            
            // 检查是否已存在
            $existing = $model->reset()
                ->where(Region::fields_COUNTRY_CODE, $countryCode)
                ->where(Region::fields_REGION_TYPE, Region::TYPE_COUNTRY)
                ->find()
                ->fetch();
            
            if (!$existing->getId()) {
                $model->reset()
                    ->setData([
                        Region::fields_COUNTRY_CODE => $countryCode,
                        Region::fields_PARENT_REGION_ID => null,
                        Region::fields_REGION_CODE => $countryCode,
                        Region::fields_REGION_NAME => $countryName,
                        Region::fields_REGION_TYPE => Region::TYPE_COUNTRY,
                        Region::fields_IS_ACTIVE => 1,
                        Region::fields_SORT_ORDER => 0,
                    ])
                    ->save();
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * 验证地区是否存在
     * 
     * @param int $regionId 地区ID
     * @return bool
     */
    public function validateRegion(int $regionId): bool
    {
        $region = $this->getModel()->load($regionId);
        return $region->getId() > 0;
    }

    /**
     * 根据地区ID获取完整路径
     * 
     * @param int $regionId 地区ID
     * @return string
     */
    public function getFullPath(int $regionId): string
    {
        $region = $this->getModel()->load($regionId);
        if (!$region->getId()) {
            return '';
        }
        return $region->getFullPath();
    }

    /**
     * 根据位置信息查找地区
     * 
     * @param string $countryCode 国家代码
     * @param string|null $province 省/州
     * @param string|null $city 市
     * @param string|null $district 区县
     * @return Region|null
     */
    public function findByLocation(
        string $countryCode,
        ?string $province = null,
        ?string $city = null,
        ?string $district = null
    ): ?Region {
        $model = $this->getModel();
        
        // 优先匹配区县
        if ($district) {
            $region = $model->reset()
                ->where(Region::fields_COUNTRY_CODE, $countryCode)
                ->where(Region::fields_REGION_TYPE, Region::TYPE_DISTRICT)
                ->where(Region::fields_REGION_NAME, $district)
                ->where(Region::fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if ($region->getId()) {
                return $region;
            }
        }
        
        // 其次匹配市
        if ($city) {
            $region = $model->reset()
                ->where(Region::fields_COUNTRY_CODE, $countryCode)
                ->where(Region::fields_REGION_TYPE, Region::TYPE_CITY)
                ->where(Region::fields_REGION_NAME, $city)
                ->where(Region::fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if ($region->getId()) {
                return $region;
            }
        }
        
        // 再次匹配省/州
        if ($province) {
            $region = $model->reset()
                ->where(Region::fields_COUNTRY_CODE, $countryCode)
                ->where(Region::fields_REGION_TYPE, Region::TYPE_PROVINCE)
                ->where(Region::fields_REGION_NAME, $province)
                ->where(Region::fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if ($region->getId()) {
                return $region;
            }
        }
        
        // 最后匹配国家
        $region = $model->reset()
            ->where(Region::fields_COUNTRY_CODE, $countryCode)
            ->where(Region::fields_REGION_TYPE, Region::TYPE_COUNTRY)
            ->where(Region::fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();
        
        return $region->getId() ? $region : null;
    }
}

