<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendPageController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\Tracking as TrackingModel;
use Weline\Shipping\Service\OrderShipmentTrackingQueryService;

#[Acl('Weline_Shipping::tracking', '物流跟踪管理', 'search', '物流跟踪管理', 'Weline_Backend::shipping_group')]
class Tracking extends BackendPageController
{
    use ShippingBackendEmbedTrait;

    private TrackingModel $tracking;

    public function __construct(ObjectManager $objectManager)
    {
        $this->tracking = $objectManager->getInstance(TrackingModel::class);
    }

    /**
     * 物流跟踪记录列表页（占位实现，保证页面可用）
     */
    #[Acl('Weline_Shipping::tracking_index', '查看物流跟踪', 'list', '查看物流跟踪记录')]
    public function index()
    {
        $records = $this->tracking->reset()
            ->order(TrackingModel::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetch()
            ->getItems();

        $this->assign('records', $records);
        $this->assignShippingEmbedLayout();

        return $this->fetch();
    }

    /**
     * 按订单发货记录查询当前位置（各配送 Provider 自行实现 queryTracking）。
     */
    #[Acl('Weline_Shipping::tracking_query', '查询物流位置', 'search', '按发货记录查询当前位置')]
    public function query(): string
    {
        $shipmentId = (int)$this->request->getParam('shipment_id', $this->request->getPost('shipment_id', 0));
        $force = (int)$this->request->getParam('force', $this->request->getPost('force', 0)) === 1;

        /** @var OrderShipmentTrackingQueryService $service */
        $service = ObjectManager::getInstance(OrderShipmentTrackingQueryService::class);
        $payload = $service->queryByShipmentId($shipmentId, $force);

        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');

        return (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
