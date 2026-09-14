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
use Weline\Shipping\Model\Tracking;
use Weline\Shipping\Model\TrackingNode;
use Weline\Shipping\Model\Carrier;

/**
 * 物流跟踪服务
 * 
 * @package Weline_Shipping
 */
class TrackingService
{
    private ObjectManager $objectManager;
    private CarrierService $carrierService;

    public function __construct(
        ObjectManager $objectManager,
        CarrierService $carrierService
    ) {
        $this->objectManager = $objectManager;
        $this->carrierService = $carrierService;
    }

    /**
     * 获取跟踪记录模型实例
     * 
     * @return Tracking
     */
    private function getTrackingModel(): Tracking
    {
        return $this->objectManager->getInstance(Tracking::class);
    }

    /**
     * 获取跟踪节点模型实例
     * 
     * @return TrackingNode
     */
    private function getTrackingNodeModel(): TrackingNode
    {
        return $this->objectManager->getInstance(TrackingNode::class);
    }

    /**
     * 查询物流跟踪（统一接口）
     * 
     * @param string $trackingNumber 物流单号
     * @param int $carrierId 快递公司ID
     * @param bool $forceRefresh 是否强制刷新
     * @return array 统一的跟踪信息格式
     */
    public function query(string $trackingNumber, int $carrierId, bool $forceRefresh = false): array
    {
        $carrier = $this->carrierService->getModel()->load($carrierId);
        if (!$carrier->getId()) {
            throw new \RuntimeException(__('快递公司不存在'));
        }

        $tracking = $this->getTrackingModel()->getByTrackingNumberAndCarrier($trackingNumber, $carrierId);

        if (!$forceRefresh && $tracking && $tracking->getId()) {
            $lastTracked = $tracking->getData(Tracking::schema_fields_LAST_TRACKED_AT);
            if ($lastTracked && strtotime($lastTracked) > time() - 3600) {
                return $this->formatTrackingResponse($tracking, $carrier);
            }
        }

        /** @var ShippingFacade $facade */
        $facade = $this->objectManager->getInstance(ShippingFacade::class);
        $result = $facade->queryTracking(new \Weline\Shipping\Api\Data\Shipping\ShippingTrackingRequest(
            $trackingNumber,
            $carrierId,
            '',
            $forceRefresh,
        ));

        if ($result->status !== \Weline\Shipping\Api\Data\Shipping\ShippingTrackingResult::STATUS_OK) {
            return $this->formatErrorResponse(
                $trackingNumber,
                $carrier,
                $result->message !== '' ? $result->message : $result->status,
            );
        }

        $apiResponse = [
            'status' => $result->trackingStatus !== '' ? $result->trackingStatus : Tracking::STATUS_IN_TRANSIT,
            'current_location' => $result->currentLocation,
            'estimated_delivery_date' => null,
            'nodes' => $result->nodes,
            'tracking_url' => $result->trackingUrl,
            'payload' => $result->payload,
        ];
        $saved = $this->saveTracking($trackingNumber, $carrier, $apiResponse, $tracking);
        $formatted = $this->formatTrackingResponse($saved, $carrier);
        if ($result->trackingUrl !== '') {
            $formatted['tracking_url'] = $result->trackingUrl;
        }

        return $formatted;
    }

    /**
     * @deprecated Kept for binary compatibility; routing is via ShippingFacade.
     */
    private function queryByApi(string $trackingNumber, Carrier $carrier, ?Tracking $existingTracking): array
    {
        return $this->query($trackingNumber, (int)$carrier->getId(), true);
    }

    /**
     * @deprecated Kept for binary compatibility; routing is via ShippingFacade.
     */
    private function queryManual(string $trackingNumber, Carrier $carrier, ?Tracking $existingTracking): array
    {
        return $this->query($trackingNumber, (int)$carrier->getId(), true);
    }

    /**
     * 保存跟踪记录
     * 
     * @param string $trackingNumber
     * @param Carrier $carrier
     * @param array $apiResponse
     * @param Tracking|null $existingTracking
     * @return Tracking
     */
    private function saveTracking(
        string $trackingNumber,
        Carrier $carrier,
        array $apiResponse,
        ?Tracking $existingTracking
    ): Tracking {
        if ($existingTracking && $existingTracking->getId()) {
            $tracking = $existingTracking;
        } else {
            $tracking = $this->getTrackingModel();
            $tracking->setData([
                Tracking::schema_fields_TRACKING_NUMBER => $trackingNumber,
                Tracking::schema_fields_CARRIER_ID => $carrier->getId(),
            ]);
        }
        
        $tracking->setData([
            Tracking::schema_fields_STATUS => $apiResponse['status'] ?? Tracking::STATUS_PENDING,
            Tracking::schema_fields_CURRENT_LOCATION => $apiResponse['current_location'] ?? null,
            Tracking::schema_fields_ESTIMATED_DELIVERY_DATE => $apiResponse['estimated_delivery_date'] ?? null,
            Tracking::schema_fields_TRACKING_DATA => json_encode($apiResponse, JSON_UNESCAPED_UNICODE),
        ]);
        
        if (isset($apiResponse['status']) && $apiResponse['status'] === Tracking::STATUS_DELIVERED) {
            $tracking->setData(Tracking::schema_fields_ACTUAL_DELIVERY_DATE, date('Y-m-d H:i:s'));
        }
        
        $tracking->incrementTrackingCount();
        $tracking->save();
        
        // 保存跟踪节点
        if (isset($apiResponse['nodes']) && is_array($apiResponse['nodes'])) {
            $this->getTrackingNodeModel()->batchAdd($tracking->getId(), $apiResponse['nodes']);
        }
        
        return $tracking;
    }

    /**
     * 格式化跟踪响应（支持追踪）
     * 
     * @param Tracking $tracking
     * @param Carrier $carrier
     * @return array
     */
    private function formatTrackingResponse(Tracking $tracking, Carrier $carrier): array
    {
        $nodes = $this->getTrackingNodeModel()->getByTrackingId($tracking->getId());
        $nodeList = [];
        foreach ($nodes->getItems() as $node) {
            $nodeList[] = [
                'time' => $node->getData(TrackingNode::schema_fields_NODE_TIME),
                'location' => $node->getData(TrackingNode::schema_fields_NODE_LOCATION),
                'status' => $node->getData(TrackingNode::schema_fields_NODE_STATUS),
                'description' => $node->getData(TrackingNode::schema_fields_NODE_DESCRIPTION),
                'type' => $node->getData(TrackingNode::schema_fields_NODE_TYPE),
                'type_label' => $node->getTypeLabel(), // 添加翻译后的类型标签
            ];
        }
        
        $status = $tracking->getData(Tracking::schema_fields_STATUS);
        
        return [
            'success' => true,
            'tracking_number' => $tracking->getData(Tracking::schema_fields_TRACKING_NUMBER),
            'carrier' => [
                'code' => $carrier->getData(Carrier::schema_fields_CARRIER_CODE),
                'name' => $carrier->getData(Carrier::schema_fields_CARRIER_NAME),
            ],
            'status' => $status,
            'status_label' => $tracking->getStatusLabel(), // 添加翻译后的状态标签
            'current_location' => $tracking->getData(Tracking::schema_fields_CURRENT_LOCATION),
            'estimated_delivery_date' => $tracking->getData(Tracking::schema_fields_ESTIMATED_DELIVERY_DATE),
            'nodes' => $nodeList,
            'tracking_url' => $carrier->generateTrackingUrl($tracking->getData(Tracking::schema_fields_TRACKING_NUMBER)),
        ];
    }

    /**
     * 格式化错误响应
     * 
     * @param string $trackingNumber
     * @param Carrier $carrier
     * @param string $errorMessage
     * @return array
     */
    private function formatErrorResponse(string $trackingNumber, Carrier $carrier, string $errorMessage): array
    {
        $status = Tracking::STATUS_EXCEPTION;
        $trackingModel = $this->getTrackingModel();
        $trackingModel->setData(Tracking::schema_fields_STATUS, $status);
        
        return [
            'success' => false,
            'tracking_number' => $trackingNumber,
            'carrier' => [
                'code' => $carrier->getData(Carrier::schema_fields_CARRIER_CODE),
                'name' => $carrier->getData(Carrier::schema_fields_CARRIER_NAME),
            ],
            'status' => $status,
            'status_label' => $trackingModel->getStatusLabel($status), // 添加翻译后的状态标签
            'message' => __('查询失败：%{1}', [$errorMessage]),
            'tracking_url' => $carrier->generateTrackingUrl($trackingNumber),
        ];
    }
}

