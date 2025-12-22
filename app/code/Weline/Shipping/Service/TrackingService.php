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
        
        // 检查是否已有跟踪记录
        $tracking = $this->getTrackingModel()->getByTrackingNumberAndCarrier($trackingNumber, $carrierId);
        
        // 如果不是强制刷新且记录存在且最近更新过（1小时内），直接返回缓存
        if (!$forceRefresh && $tracking && $tracking->getId()) {
            $lastTracked = $tracking->getData(Tracking::fields_LAST_TRACKED_AT);
            if ($lastTracked && strtotime($lastTracked) > time() - 3600) {
                return $this->formatTrackingResponse($tracking, $carrier);
            }
        }
        
        // 根据快递公司类型查询
        $carrierType = $carrier->getData(Carrier::fields_CARRIER_TYPE);
        $trackingSupportStatus = $carrier->getData(Carrier::fields_TRACKING_SUPPORT_STATUS);
        
        if ($carrierType === Carrier::TYPE_API && $trackingSupportStatus === Carrier::TRACKING_SUPPORTED) {
            // API类型，调用第三方API
            return $this->queryByApi($trackingNumber, $carrier, $tracking);
        } else {
            // 手动类型或不支持追踪，返回标准格式
            return $this->queryManual($trackingNumber, $carrier, $tracking);
        }
    }

    /**
     * 通过API查询（API类型快递公司）
     * 
     * @param string $trackingNumber
     * @param Carrier $carrier
     * @param Tracking|null $existingTracking
     * @return array
     */
    private function queryByApi(string $trackingNumber, Carrier $carrier, ?Tracking $existingTracking): array
    {
        try {
            // TODO: 实现第三方API调用
            // 这里应该调用适配器接口，根据carrier的配置调用相应的API
            // 目前返回模拟数据
            
            $apiConfig = $carrier->getApiConfig();
            $apiEndpoint = $carrier->getData(Carrier::fields_TRACKING_API_ENDPOINT);
            $apiMethod = $carrier->getData(Carrier::fields_TRACKING_API_METHOD) ?: 'GET';
            
            // 模拟API响应
            $apiResponse = [
                'status' => Tracking::STATUS_IN_TRANSIT,
                'current_location' => '北京分拨中心',
                'estimated_delivery_date' => date('Y-m-d H:i:s', strtotime('+3 days')),
                'nodes' => [
                    [
                        'time' => date('Y-m-d H:i:s', strtotime('-2 days')),
                        'location' => '北京分拨中心',
                        'status' => '已发货',
                        'description' => '快件已从北京分拨中心发出',
                        'type' => TrackingNode::TYPE_PICKUP,
                    ],
                    [
                        'time' => date('Y-m-d H:i:s', strtotime('-1 day')),
                        'location' => '上海分拨中心',
                        'status' => '运输中',
                        'description' => '快件已到达上海分拨中心',
                        'type' => TrackingNode::TYPE_TRANSIT,
                    ],
                ],
            ];
            
            // 保存或更新跟踪记录
            $tracking = $this->saveTracking($trackingNumber, $carrier, $apiResponse, $existingTracking);
            
            return $this->formatTrackingResponse($tracking, $carrier);
            
        } catch (\Exception $e) {
            // API调用失败，返回错误信息
            return $this->formatErrorResponse($trackingNumber, $carrier, $e->getMessage());
        }
    }

    /**
     * 手动查询（手动类型快递公司或不支持追踪）
     * 
     * @param string $trackingNumber
     * @param Carrier $carrier
     * @param Tracking|null $existingTracking
     * @return array
     */
    private function queryManual(string $trackingNumber, Carrier $carrier, ?Tracking $existingTracking): array
    {
        $trackingUrl = $carrier->generateTrackingUrl($trackingNumber);
        $trackingSupportStatus = $carrier->getData(Carrier::fields_TRACKING_SUPPORT_STATUS);
        
        // 创建或更新跟踪记录
        if (!$existingTracking || !$existingTracking->getId()) {
            $tracking = $this->getTrackingModel();
            $tracking->setData([
                Tracking::fields_TRACKING_NUMBER => $trackingNumber,
                Tracking::fields_CARRIER_ID => $carrier->getId(),
                Tracking::fields_STATUS => Tracking::STATUS_NOT_SUPPORTED,
            ]);
            $tracking->save();
        } else {
            $tracking = $existingTracking;
        }
        
        // 返回标准格式（不支持追踪）
        $status = Tracking::STATUS_NOT_SUPPORTED;
        $trackingModel = $this->getTrackingModel();
        $trackingModel->setData(Tracking::fields_STATUS, $status);
        
        return [
            'success' => false,
            'tracking_number' => $trackingNumber,
            'carrier' => [
                'code' => $carrier->getData(Carrier::fields_CARRIER_CODE),
                'name' => $carrier->getData(Carrier::fields_CARRIER_NAME),
            ],
            'status' => $status,
            'status_label' => $trackingModel->getStatusLabel($status), // 添加翻译后的状态标签
            'message' => __('该快递公司暂不支持在线追踪，请联系客服查询'),
            'tracking_url' => $trackingUrl,
            'support_contact' => __('客服电话：400-xxx-xxxx'),
        ];
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
                Tracking::fields_TRACKING_NUMBER => $trackingNumber,
                Tracking::fields_CARRIER_ID => $carrier->getId(),
            ]);
        }
        
        $tracking->setData([
            Tracking::fields_STATUS => $apiResponse['status'] ?? Tracking::STATUS_PENDING,
            Tracking::fields_CURRENT_LOCATION => $apiResponse['current_location'] ?? null,
            Tracking::fields_ESTIMATED_DELIVERY_DATE => $apiResponse['estimated_delivery_date'] ?? null,
            Tracking::fields_TRACKING_DATA => json_encode($apiResponse, JSON_UNESCAPED_UNICODE),
        ]);
        
        if (isset($apiResponse['status']) && $apiResponse['status'] === Tracking::STATUS_DELIVERED) {
            $tracking->setData(Tracking::fields_ACTUAL_DELIVERY_DATE, date('Y-m-d H:i:s'));
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
                'time' => $node->getData(TrackingNode::fields_NODE_TIME),
                'location' => $node->getData(TrackingNode::fields_NODE_LOCATION),
                'status' => $node->getData(TrackingNode::fields_NODE_STATUS),
                'description' => $node->getData(TrackingNode::fields_NODE_DESCRIPTION),
                'type' => $node->getData(TrackingNode::fields_NODE_TYPE),
                'type_label' => $node->getTypeLabel(), // 添加翻译后的类型标签
            ];
        }
        
        $status = $tracking->getData(Tracking::fields_STATUS);
        
        return [
            'success' => true,
            'tracking_number' => $tracking->getData(Tracking::fields_TRACKING_NUMBER),
            'carrier' => [
                'code' => $carrier->getData(Carrier::fields_CARRIER_CODE),
                'name' => $carrier->getData(Carrier::fields_CARRIER_NAME),
            ],
            'status' => $status,
            'status_label' => $tracking->getStatusLabel(), // 添加翻译后的状态标签
            'current_location' => $tracking->getData(Tracking::fields_CURRENT_LOCATION),
            'estimated_delivery_date' => $tracking->getData(Tracking::fields_ESTIMATED_DELIVERY_DATE),
            'nodes' => $nodeList,
            'tracking_url' => $carrier->generateTrackingUrl($tracking->getData(Tracking::fields_TRACKING_NUMBER)),
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
        $trackingModel->setData(Tracking::fields_STATUS, $status);
        
        return [
            'success' => false,
            'tracking_number' => $trackingNumber,
            'carrier' => [
                'code' => $carrier->getData(Carrier::fields_CARRIER_CODE),
                'name' => $carrier->getData(Carrier::fields_CARRIER_NAME),
            ],
            'status' => $status,
            'status_label' => $trackingModel->getStatusLabel($status), // 添加翻译后的状态标签
            'message' => __('查询失败：%{1}', [$errorMessage]),
            'tracking_url' => $carrier->generateTrackingUrl($trackingNumber),
        ];
    }
}

