<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Service;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\FulfillmentAction;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;
use Weline\Order\Model\OrderShipment;
use Weline\Payment\Api\Data\PaymentEffectRecord;

/**
 * 发货服务
 *
 * MOD-P2F-006：支付 succeeded 后由 effect `fulfillment:action:v1` 触发履约动作；
 * effect_key 幂等，tombstone Store 仍允许历史履约白名单任务。
 *
 * @package Weline_Order
 */
class FulfillmentService
{
    public const EFFECT_TYPE = 'fulfillment:action:v1';

    private ObjectManager $objectManager;
    private EventsManager $eventsManager;
    private OrderService $orderService;
    private WriteIntentTransactionCoordinatorInterface $transactions;
    
    public function __construct(
        ObjectManager $objectManager,
        EventsManager $eventsManager,
        OrderService $orderService,
        WriteIntentTransactionCoordinatorInterface $transactions,
    ) {
        $this->objectManager = $objectManager;
        $this->eventsManager = $eventsManager;
        $this->orderService = $orderService;
        $this->transactions = $transactions;
    }

    /**
     * Deterministic fulfillment effect key（与 PaymentInboxConsumer 对齐）.
     */
    public static function effectKeyForAttempt(string $attemptCode): string
    {
        $attemptCode = trim($attemptCode);
        if ($attemptCode === '') {
            throw new \InvalidArgumentException('fulfillment_attempt_code_required');
        }

        return 'attempt:' . $attemptCode . ':' . self::EFFECT_TYPE;
    }

    /**
     * Ensure one durable "ready for fulfillment" action for a Payment effect.
     *
     * This does not create a Shipment and does not mark the Order as shipped.
     *
     * @return array{ok:true,replayed:bool,fulfillment_action_id:int,fulfillment_action_uuid:string,order_uuid:string,effect_key:string,status:string,resource_mode:string}
     */
    public function ensureActionFromPaymentEffect(
        PaymentEffectRecord $effect,
        string $resourceMode,
    ): array {
        if ($effect->effectType !== self::EFFECT_TYPE) {
            throw new \InvalidArgumentException('fulfillment_effect_type_invalid');
        }
        if ($effect->payableType !== 'order') {
            throw new \RuntimeException('fulfillment_effect_payable_type_invalid');
        }

        $order = $this->loadOrderForUpdate($effect->payableId);
        if (!$this->transactions->isActive($order->getConnection())
            || !$this->transactions->isWriteIntent($order->getConnection())
        ) {
            throw new \LogicException('fulfillment_effect_transaction_required');
        }
        if ((string)$order->getData(Order::schema_fields_STATUS) !== Order::STATUS_PAID
            && (string)$order->getData(Order::schema_fields_PAYMENT_STATUS)
                !== Order::PAYMENT_STATUS_PAID
        ) {
            throw new \RuntimeException('fulfillment_effect_order_not_paid');
        }

        /** @var FulfillmentAction $action */
        $action = $this->newModel(FulfillmentAction::class)
            ->where(FulfillmentAction::schema_fields_EFFECT_KEY, $effect->effectKey);
        if (!$this->isSqlite($action)) {
            $action->additional('FOR UPDATE');
        }
        $action->find()->fetch();
        if ($action->getId()) {
            if ((string)$action->getData(FulfillmentAction::schema_fields_ORDER_UUID)
                !== $effect->payableId
            ) {
                throw new \RuntimeException('fulfillment_effect_identity_conflict');
            }

            return $this->effectResult($action, true);
        }

        /** @var FulfillmentAction $action */
        $action = $this->newModel(FulfillmentAction::class);
        $action->setData([
            FulfillmentAction::schema_fields_ACTION_UUID
                => $this->deterministicUuid($effect->effectKey),
            FulfillmentAction::schema_fields_EFFECT_KEY => $effect->effectKey,
            FulfillmentAction::schema_fields_ATTEMPT_CODE => $effect->attemptCode,
            FulfillmentAction::schema_fields_ORDER_UUID => $effect->payableId,
            FulfillmentAction::schema_fields_STATUS => FulfillmentAction::STATUS_PENDING,
            FulfillmentAction::schema_fields_RESOURCE_MODE => $resourceMode,
            FulfillmentAction::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
            FulfillmentAction::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ])->save();

        return $this->effectResult($action, false);
    }
    
    /**
     * 获取发货模型实例
     * 
     * @return OrderShipment
     */
    private function getShipmentModel(): OrderShipment
    {
        return $this->objectManager->getInstance(OrderShipment::class, [], false);
    }
    
    /**
     * 创建发货记录
     * 
     * @param int $orderId 订单ID
     * @param array $shipmentData 发货数据
     * @return OrderShipment
     * @throws \Exception
     */
    public function createShipment(int $orderId, array $shipmentData): OrderShipment
    {
        $order = $this->orderService->getOrder($orderId);
        
        // 验证订单是否可以发货
        if ($order->getData(Order::schema_fields_STATUS) !== Order::STATUS_PAID) {
            throw new \Exception(\__('只有已支付的订单才能发货'));
        }
        
        // 创建发货记录
        $shipment = $this->getShipmentModel()->reset();
        $shipment->setData(OrderShipment::schema_fields_ORDER_ID, $orderId);
        $shipment->setData(OrderShipment::schema_fields_TRACKING_NUMBER, $shipmentData['tracking_number'] ?? '');
        $shipment->setData(OrderShipment::schema_fields_CARRIER, $shipmentData['carrier'] ?? '');
        $shipment->setData(OrderShipment::schema_fields_STATUS, OrderShipment::STATUS_SHIPPED);
        $shipment->setData(OrderShipment::schema_fields_SHIPPED_AT, date('Y-m-d H:i:s'));
        $shipment->save();
        
        // 更新订单发货状态
        $order->setData(Order::schema_fields_FULFILLMENT_STATUS, Order::FULFILLMENT_STATUS_SHIPPED);
        $order->save();
        
        // 使用状态机转换订单状态
        $stateMachine = $this->objectManager->getInstance(OrderStateMachine::class);
        try {
            $stateMachine->transition($orderId, Order::STATUS_FULFILLED, \__('订单已发货'));
        } catch (\Exception $e) {
            // 如果状态转换失败，不影响发货记录
        }
        
        // 触发订单发货事件
        $this->eventsManager->dispatch('Weline_Order::order_shipped', [
            'order' => $order,
            'order_id' => $orderId,
            'shipment' => $shipment,
        ]);
        
        return $shipment;
    }
    
    /**
     * 更新物流单号
     * 
     * @param int $shipmentId 发货ID
     * @param string $trackingNumber 物流单号
     * @return OrderShipment
     * @throws \Exception
     */
    public function updateTracking(int $shipmentId, string $trackingNumber): OrderShipment
    {
        $shipment = $this->getShipmentModel()->reset()->load($shipmentId);
        
        if (!$shipment->getId()) {
            throw new \Exception(\__('发货记录不存在'));
        }
        
        $shipment->setData(OrderShipment::schema_fields_TRACKING_NUMBER, $trackingNumber);
        $shipment->save();
        
        return $shipment;
    }
    
    /**
     * 标记为已送达
     * 
     * @param int $shipmentId 发货ID
     * @return OrderShipment
     * @throws \Exception
     */
    public function markAsDelivered(int $shipmentId): OrderShipment
    {
        $shipment = $this->getShipmentModel()->reset()->load($shipmentId);
        
        if (!$shipment->getId()) {
            throw new \Exception(\__('发货记录不存在'));
        }
        
        $shipment->setData(OrderShipment::schema_fields_STATUS, OrderShipment::STATUS_DELIVERED);
        $shipment->setData(OrderShipment::schema_fields_DELIVERED_AT, date('Y-m-d H:i:s'));
        $shipment->save();
        
        // 更新订单状态为已完成
        $orderId = (int)$shipment->getData(OrderShipment::schema_fields_ORDER_ID);
        $order = $this->orderService->getOrder($orderId);
        
        $order->setData(Order::schema_fields_FULFILLMENT_STATUS, Order::FULFILLMENT_STATUS_DELIVERED);
        $order->save();
        
        // 使用状态机转换订单状态
        $stateMachine = $this->objectManager->getInstance(OrderStateMachine::class);
        try {
            $stateMachine->transition($orderId, Order::STATUS_COMPLETED, \__('订单已完成'));
            
            // 触发订单完成事件
            $this->eventsManager->dispatch('Weline_Order::order_completed', [
                'order' => $order,
                'order_id' => $orderId,
                'shipment' => $shipment,
            ]);
        } catch (\Exception $e) {
            // 如果状态转换失败，不影响发货记录
        }
        
        return $shipment;
    }
    
    /**
     * 获取发货记录列表
     * 
     * @param int $orderId 订单ID
     * @return array
     */
    public function getShipments(int $orderId): array
    {
        $collection = $this->getShipmentModel()->reset()
            ->where(OrderShipment::schema_fields_ORDER_ID, $orderId)
            ->order(OrderShipment::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetch();
        
        return $collection->getItems();
    }

    private function loadOrderForUpdate(string $orderUuid): Order
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '') {
            throw new \RuntimeException('fulfillment_effect_order_not_found');
        }
        /** @var Order $order */
        $order = $this->newModel(Order::class)
            ->where(Order::schema_fields_ORDER_UUID, $orderUuid);
        if (!$this->isSqlite($order)) {
            $order->additional('FOR UPDATE');
        }
        $order->find()->fetch();
        if (!$order->getId()
            || (string)$order->getData(Order::schema_fields_ORDER_UUID) !== $orderUuid
        ) {
            throw new \RuntimeException('fulfillment_effect_order_not_found');
        }

        return $order;
    }

    /** @return array{ok:true,replayed:bool,fulfillment_action_id:int,fulfillment_action_uuid:string,order_uuid:string,effect_key:string,status:string,resource_mode:string} */
    private function effectResult(FulfillmentAction $action, bool $replayed): array
    {
        return [
            'ok' => true,
            'replayed' => $replayed,
            'fulfillment_action_id' => (int)$action->getId(),
            'fulfillment_action_uuid' => (string)$action->getData(
                FulfillmentAction::schema_fields_ACTION_UUID,
            ),
            'order_uuid' => (string)$action->getData(
                FulfillmentAction::schema_fields_ORDER_UUID,
            ),
            'effect_key' => (string)$action->getData(
                FulfillmentAction::schema_fields_EFFECT_KEY,
            ),
            'status' => (string)$action->getData(FulfillmentAction::schema_fields_STATUS),
            'resource_mode' => (string)$action->getData(
                FulfillmentAction::schema_fields_RESOURCE_MODE,
            ),
        ];
    }

    private function deterministicUuid(string $effectKey): string
    {
        $hex = substr(hash('sha256', 'fulfillment-action|' . $effectKey), 0, 32);
        $hex[12] = '4';
        $variant = hexdec($hex[16]);
        $hex[16] = dechex(($variant & 0x3) | 0x8);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * @template T of Model
     * @param class-string<T> $class
     * @return T
     */
    private function newModel(string $class): Model
    {
        return $this->objectManager->getInstance($class, [], false);
    }

    private function isSqlite(Model $model): bool
    {
        return strtolower((string)$model->getConnection()
            ->getConnector()
            ->getConfigProvider()
            ->getDbType()) === 'sqlite';
    }
}
