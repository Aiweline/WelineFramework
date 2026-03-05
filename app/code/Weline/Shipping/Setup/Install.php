<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Setup;

use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Shipping\Model\ShippingAddress;
use Weline\Shipping\Model\DeliveryAddress;
use Weline\Shipping\Model\Region;
use Weline\Shipping\Model\Zone;
use Weline\Shipping\Model\ZoneRegion;
use Weline\Shipping\Model\Carrier;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\FreeShippingRule;
use Weline\Shipping\Model\ShippingService;
use Weline\Shipping\Model\Tracking;
use Weline\Shipping\Model\TrackingNode;

class Install implements InstallInterface
{
    /**
     * 安装模块
     */
    public function setup(Setup $setup, Context $context): void
    {
        // 安装发货地址表
        /** @var ShippingAddress $shippingAddress */
        $shippingAddress = ObjectManager::getInstance(ShippingAddress::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($shippingAddress);
        $shippingAddress->setup($modelSetup, $context);
        
        // 安装运送地址表
        /** @var DeliveryAddress $deliveryAddress */
        $deliveryAddress = ObjectManager::getInstance(DeliveryAddress::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($deliveryAddress);
        $deliveryAddress->setup($modelSetup, $context);
        
        // 安装地区表
        /** @var Region $region */
        $region = ObjectManager::getInstance(Region::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($region);
        $region->setup($modelSetup, $context);
        
        // 安装配送区域表
        /** @var Zone $zone */
        $zone = ObjectManager::getInstance(Zone::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($zone);
        $zone->setup($modelSetup, $context);
        
        // 安装配送区域地区关联表
        /** @var ZoneRegion $zoneRegion */
        $zoneRegion = ObjectManager::getInstance(ZoneRegion::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($zoneRegion);
        $zoneRegion->setup($modelSetup, $context);
        
        // 安装快递公司表
        /** @var Carrier $carrier */
        $carrier = ObjectManager::getInstance(Carrier::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($carrier);
        $carrier->setup($modelSetup, $context);
        
        // 插入默认快递公司数据
        $this->installDefaultCarriers($carrier);
        
        // 安装配送费用模板表
        /** @var RateTemplate $rateTemplate */
        $rateTemplate = ObjectManager::getInstance(RateTemplate::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($rateTemplate);
        $rateTemplate->setup($modelSetup, $context);
        
        // 安装免邮规则表
        /** @var FreeShippingRule $freeShippingRule */
        $freeShippingRule = ObjectManager::getInstance(FreeShippingRule::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($freeShippingRule);
        $freeShippingRule->setup($modelSetup, $context);
        
        // 安装配送服务表
        /** @var ShippingService $shippingService */
        $shippingService = ObjectManager::getInstance(ShippingService::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($shippingService);
        $shippingService->setup($modelSetup, $context);
        
        // 安装物流跟踪记录表
        /** @var Tracking $tracking */
        $tracking = ObjectManager::getInstance(Tracking::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($tracking);
        $tracking->setup($modelSetup, $context);
        
        // 安装物流跟踪节点表
        /** @var TrackingNode $trackingNode */
        $trackingNode = ObjectManager::getInstance(TrackingNode::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($trackingNode);
        $trackingNode->setup($modelSetup, $context);
    }

    /**
     * 安装默认快递公司数据
     * 
     * @param Carrier $carrierModel
     * @return void
     */
    private function installDefaultCarriers(Carrier $carrierModel): void
    {
        $defaultCarriers = [
            // 国内主流（前10）
            [
                'carrier_code' => 'SF',
                'carrier_name' => '顺丰速运',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://www.sf-express.com/cn/sc/dynamic_function/waybill/#search/bill-number/{tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 1,
            ],
            [
                'carrier_code' => 'ZTO',
                'carrier_name' => '中通快递',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://www.zto.com/trace/{tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 2,
            ],
            [
                'carrier_code' => 'YTO',
                'carrier_name' => '圆通速递',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://www.yto.net.cn/trace/{tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 3,
            ],
            [
                'carrier_code' => 'STO',
                'carrier_name' => '申通快递',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://www.sto.cn/trace/{tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 4,
            ],
            [
                'carrier_code' => 'YD',
                'carrier_name' => '韵达快递',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://www.yundaex.com/track/{tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 5,
            ],
            [
                'carrier_code' => 'JD',
                'carrier_name' => '京东物流',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://www.jd.com/tracking/{tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 6,
            ],
            [
                'carrier_code' => 'EMS',
                'carrier_name' => '中国邮政 EMS',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://www.ems.com.cn/query/{tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 7,
            ],
            [
                'carrier_code' => 'HTKY',
                'carrier_name' => '百世快递',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://www.800best.com/track/{tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 8,
            ],
            [
                'carrier_code' => 'DBL',
                'carrier_name' => '德邦快递',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://www.deppon.com/track/{tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 9,
            ],
            [
                'carrier_code' => 'JNT',
                'carrier_name' => '极兔速递',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://www.jtexpress.com/track/{tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 10,
            ],
            // 国际主流（前10）
            [
                'carrier_code' => 'DHL',
                'carrier_name' => 'DHL',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://www.dhl.com/en/express/tracking.html?AWB={tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 11,
            ],
            [
                'carrier_code' => 'FedEx',
                'carrier_name' => 'FedEx',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://www.fedex.com/apps/fedextrack/?tracknumbers={tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 12,
            ],
            [
                'carrier_code' => 'UPS',
                'carrier_name' => 'UPS',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://www.ups.com/track?tracknum={tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 13,
            ],
            [
                'carrier_code' => 'TNT',
                'carrier_name' => 'TNT',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://www.tnt.com/express/zh_cn/site/shipping-tools/tracking.html?searchType=con&cons={tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 14,
            ],
            [
                'carrier_code' => 'ChinaPost',
                'carrier_name' => '中国邮政国际',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://www.17track.net/zh-cn/track?nums={tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 15,
            ],
            [
                'carrier_code' => 'USPS',
                'carrier_name' => 'USPS',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://tools.usps.com/go/TrackConfirmAction?qtc_tLabels1={tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 16,
            ],
            [
                'carrier_code' => 'RoyalMail',
                'carrier_name' => 'Royal Mail',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://www.royalmail.com/track-your-item#/tracking-results/{tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 17,
            ],
            [
                'carrier_code' => 'DPD',
                'carrier_name' => 'DPD',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://tracking.dpd.de/status/{language}/parcel/{tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 18,
            ],
            [
                'carrier_code' => 'GLS',
                'carrier_name' => 'GLS',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://gls-group.eu/app/service/open/portal/EN/search?match={tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 19,
            ],
            [
                'carrier_code' => 'Aramex',
                'carrier_name' => 'Aramex',
                'carrier_type' => Carrier::TYPE_MANUAL,
                'tracking_url_template' => 'https://www.aramex.com/track/{tracking_number}',
                'tracking_support_status' => Carrier::TRACKING_SUPPORTED,
                'is_active' => 1,
                'sort_order' => 20,
            ],
        ];

        foreach ($defaultCarriers as $carrierData) {
            $carrierModel->reset();
            $carrierModel->load(Carrier::schema_fields_CARRIER_CODE, $carrierData['carrier_code']);
            
            // 如果不存在，则插入
            if (!$carrierModel->getId()) {
                $carrierModel->setData($carrierData)->save();
            }
        }
    }
}

