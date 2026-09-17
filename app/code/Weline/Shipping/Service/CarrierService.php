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
use Weline\Shipping\Model\Carrier;

/**
 * 快递公司服务
 * 
 * @package Weline_Shipping
 */
class CarrierService
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 获取快递公司模型实例
     * 
     * @return Carrier
     */
    private function getModel(): Carrier
    {
        return $this->objectManager->getInstance(Carrier::class);
    }

    /**
     * 创建快递公司
     * 
     * @param array $data 快递公司数据
     * @return Carrier
     * @throws \RuntimeException
     */
    public function create(array $data): Carrier
    {
        // 验证tracking_url_template必填
        if (empty($data[Carrier::schema_fields_TRACKING_URL_TEMPLATE])) {
            throw new \RuntimeException(__('物流跟踪URL模板为必填项，所有快递公司必须支持追踪功能'));
        }
        /** @var TrackingUrlResolver $urlResolver */
        $urlResolver = $this->objectManager->getInstance(TrackingUrlResolver::class);
        $template = (string)$data[Carrier::schema_fields_TRACKING_URL_TEMPLATE];
        if (!$urlResolver->isUsableTemplate($template)) {
            throw new \RuntimeException(__('禁止使用 example.com 等占位轨迹地址，请填写真实承运商追踪模板，或留空由系统使用 17TRACK 默认'));
        }
        
        $carrier = $this->getModel();
        $carrier->setData($data);
        $carrier->save();
        
        return $carrier;
    }

    /**
     * 更新快递公司
     * 
     * @param int $id 快递公司ID
     * @param array $data 快递公司数据
     * @return Carrier
     * @throws \RuntimeException
     */
    public function update(int $id, array $data): Carrier
    {
        $carrier = $this->getModel()->load($id);
        if (!$carrier->getId()) {
            throw new \RuntimeException(__('快递公司不存在'));
        }
        
        // 如果更新tracking_url_template，验证必填
        if (isset($data[Carrier::schema_fields_TRACKING_URL_TEMPLATE]) && empty($data[Carrier::schema_fields_TRACKING_URL_TEMPLATE])) {
            throw new \RuntimeException(__('物流跟踪URL模板为必填项，所有快递公司必须支持追踪功能'));
        }
        if (isset($data[Carrier::schema_fields_TRACKING_URL_TEMPLATE])) {
            /** @var TrackingUrlResolver $urlResolver */
            $urlResolver = $this->objectManager->getInstance(TrackingUrlResolver::class);
            if (!$urlResolver->isUsableTemplate((string)$data[Carrier::schema_fields_TRACKING_URL_TEMPLATE])) {
                throw new \RuntimeException(__('禁止使用 example.com 等占位轨迹地址，请填写真实承运商追踪模板'));
            }
        }
        
        $carrier->setData($data);
        $carrier->save();
        
        return $carrier;
    }

    /**
     * 获取所有启用的快递公司
     * 
     * @return \Weline\Framework\Database\Model\Collection
     */
    public function getActiveCarriers(): \Weline\Framework\Database\Model\Collection
    {
        return $this->getModel()->reset()
            ->where(Carrier::schema_fields_IS_ACTIVE, 1)
            ->order(Carrier::schema_fields_SORT_ORDER, 'ASC')
            ->order(Carrier::schema_fields_CARRIER_NAME, 'ASC')
            ->select()
            ->fetch();
    }

    /**
     * 根据代码获取快递公司
     * 
     * @param string $code 快递公司代码
     * @return Carrier|null
     */
    public function getByCode(string $code): ?Carrier
    {
        $carrier = $this->getModel()->reset()
            ->where(Carrier::schema_fields_CARRIER_CODE, $code)
            ->find()
            ->fetch();
        
        return $carrier->getId() ? $carrier : null;
    }
}

