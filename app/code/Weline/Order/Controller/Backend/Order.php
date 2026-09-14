<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Controller\Backend;

use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Order\Model\Order as OrderModel;
use Weline\Order\Service\OrderObjectAccessService;
use Weline\Order\Service\OrderObjectScopeService;
use Weline\Order\Service\OrderService;
use Weline\Order\Service\OrderStateMachine;

/**
 * 订单管理控制器
 */
#[Acl('Weline_Order::order_manage', '订单管理', 'cart', '订单管理', 'Weline_Backend::order_group')]
class Order extends BackendController
{
    private OrderService $orderService;
    private OrderStateMachine $stateMachine;
    
    public function __construct(ObjectManager $objectManager)
    {
        $this->orderService = $objectManager->getInstance(OrderService::class);
        $this->stateMachine = $objectManager->getInstance(OrderStateMachine::class);
    }
    
    /**
     * 订单列表页面
     */
    #[Acl('Weline_Order::order_list', '查看订单列表', 'list', '查看订单列表')]
    public function index()
    {
        $page = max(1, (int)($this->request->getParam('page') ?? 1));
        $pageSize = (int)($this->request->getParam('page_size') ?? 20);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 20;
        
        $filters = [
            'page' => $page,
            'page_size' => $pageSize,
        ];
        
        // 搜索条件
        if ($status = $this->request->getParam('status')) {
            $filters['status'] = $status;
        }
        
        if ($customerId = $this->request->getParam('customer_id')) {
            $filters['customer_id'] = (int)$customerId;
        }
        
        if ($orderNumber = $this->request->getParam('order_number')) {
            $filters['order_number'] = $orderNumber;
        }
        
        if ($paymentStatus = $this->request->getParam('payment_status')) {
            $filters['payment_status'] = $paymentStatus;
        }
        
        if ($fulfillmentStatus = $this->request->getParam('fulfillment_status')) {
            $filters['fulfillment_status'] = $fulfillmentStatus;
        }

        $orderTypeRegistry = ObjectManager::getInstance(\Weline\Order\Service\CommerceOrderTypeRegistry::class);
        $orderTypeRows = $orderTypeRegistry->labelRows();
        $orderTypeFilter = strtolower(trim((string)$this->request->getParam('order_type', '')));
        if ($orderTypeFilter !== '' && $orderTypeRegistry->has($orderTypeFilter)) {
            $filters['order_type'] = $orderTypeFilter;
        } else {
            $orderTypeFilter = '';
        }

        $checkoutEntryFilter = strtolower(trim((string)$this->request->getParam('checkout_entry', '')));
        $checkoutEntryAllowed = ['checkout', 'express', 'quick_buy', 'helppay', 'unknown'];
        if ($checkoutEntryFilter !== '' && \in_array($checkoutEntryFilter, $checkoutEntryAllowed, true)) {
            $filters['checkout_entry'] = $checkoutEntryFilter;
        } else {
            $checkoutEntryFilter = '';
        }
        
        if ($keyword = trim((string)$this->request->getParam('keyword'))) {
            $filters['keyword'] = $keyword;
        }
        
        $loadFilters = $filters;
        unset($loadFilters['page'], $loadFilters['page_size']);
        $candidates = $this->orderService->getOrderList($loadFilters);
        $orders = [];
        $actionGrantVersions = [];
        $scopeService = ObjectManager::getInstance(OrderObjectScopeService::class);
        $guard = $this->objectAuthorizationGuard();
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof OrderModel) {
                continue;
            }
            try {
                $scope = $scopeService->fromOrder($candidate);
            } catch (\Throwable) {
                continue;
            }
            if (!$guard->isAllowed(ObjectAction::LIST, $scope)) {
                continue;
            }
            $orders[] = $candidate;
            foreach ([ObjectAction::UPDATE, ObjectAction::REFUND, ObjectAction::FULFILL] as $action) {
                $result = $guard->check($action, $scope);
                if ($result->allowed) {
                    $actionGrantVersions[(int)$candidate->getId()][$action] = $result->matchedGrantVersion;
                }
            }
        }
        $total = \count($orders);
        $totalPages = (int)ceil($total / $pageSize);
        $orders = \array_slice($orders, ($page - 1) * $pageSize, $pageSize);
        
        $this->assign('orders', $orders);
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('page_size', $pageSize);
        $this->assign('total_pages', $totalPages);
        $this->assign('filters', $filters);
        $this->assign('order_type', $orderTypeFilter);
        $this->assign('order_type_rows', $orderTypeRows);
        $this->assign('order_type_registry', $orderTypeRegistry);
        $this->assign('checkout_entry', $checkoutEntryFilter);
        $this->assign('checkout_entry_rows', [
            ['code' => 'checkout', 'label' => (string)__('万能结账')],
            ['code' => 'express', 'label' => (string)__('快捷支付')],
            ['code' => 'quick_buy', 'label' => (string)__('快捷购买')],
            ['code' => 'helppay', 'label' => (string)__('找朋友代付')],
            ['code' => 'unknown', 'label' => (string)__('未标记')],
        ]);
        $this->assign('order_action_grant_versions', $actionGrantVersions);
        
        return $this->fetch();
    }
    
    /**
     * 订单详情页面
     */
    #[Acl('Weline_Order::order_view', '查看订单详情', 'eye', '查看订单详情')]
    public function view()
    {
        $orderId = (int)$this->request->getParam('id');
        
        try {
            $record = $this->requireOrder($orderId, ObjectAction::VIEW);
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        }
        
        try {
            $order = $record['order'];
            $items = $this->orderService->getOrderItems($orderId);
            $displayItems = ObjectManager::getInstance(\Weline\Order\Service\BackendOrderLinePresenter::class)
                ->present($order, $items);

            // 支付记录由 Order 空槽 + Weline_Payment 部件/Hook 填充，禁止本控制器直灌。
            // 发货记录由 Order 空槽 + Weline_Shipping 部件/Hook 填充，禁止本控制器直灌。
            
            // 获取退款记录
            $refundService = ObjectManager::getInstance(\Weline\Order\Service\RefundService::class);
            $refunds = $refundService->getRefundHistory($orderId);
            
            // 获取发票记录
            $invoiceService = ObjectManager::getInstance(\Weline\Order\Service\InvoiceService::class);
            $invoices = $invoiceService->getInvoiceList($orderId);
            
            // 获取订单历史
            $historyModel = ObjectManager::getInstance(\Weline\Order\Model\OrderHistory::class);
            $history = $historyModel->reset()
                ->where(\Weline\Order\Model\OrderHistory::schema_fields_ORDER_ID, $orderId)
                ->order(\Weline\Order\Model\OrderHistory::schema_fields_CREATED_AT, 'DESC')
                ->select()
                ->fetch()
                ->getItems();
            
            // 获取可用状态转换
            $currentStatus = $order->getData(OrderModel::schema_fields_STATUS);
            $availableTransitions = $this->stateMachine->getAvailableTransitions($currentStatus);

            $customerPresent = ObjectManager::getInstance(\Weline\Order\Service\BackendOrderListPresenter::class)
                ->present($order);
            $paymentChrome = ObjectManager::getInstance(\Weline\Order\Service\BackendOrderPaymentChromePresenter::class)
                ->present($order);

            $orderTypeRegistry = ObjectManager::getInstance(\Weline\Order\Service\CommerceOrderTypeRegistry::class);
            $orderTypeCode = strtolower(trim((string)$order->getData(OrderModel::schema_fields_ORDER_TYPE)));
            if ($orderTypeCode === '') {
                $orderTypeCode = \Weline\Order\Service\CommerceOrderTypeRegistry::CODE_TOC;
            }
            $orderTypeTone = $orderTypeRegistry->resolveBadgeTone($orderTypeCode);
            if ($orderTypeTone === 'muted') {
                $orderTypeTone = 'info';
            }
            $typePayloadRaw = (string)$order->getData(OrderModel::schema_fields_TYPE_PAYLOAD_JSON);
            $typePayload = [];
            if ($typePayloadRaw !== '') {
                $decoded = json_decode($typePayloadRaw, true);
                if (is_array($decoded)) {
                    $typePayload = $decoded;
                }
            }
            
            $this->assign('order', $order);
            $this->assign('items', $displayItems);
            $this->assign('customer_present', $customerPresent);
            $this->assign('payment_chrome', $paymentChrome);
            $this->assign('order_type', $orderTypeCode);
            $this->assign('order_type_label', $orderTypeRegistry->resolveLabel($orderTypeCode));
            $this->assign('order_type_tone', $orderTypeTone);
            $this->assign('type_payload', $typePayload);
            $this->assign('refunds', $refunds);
            $this->assign('invoices', $invoices);
            $this->assign('history', $history);
            $this->assignStatusFlow($order, $history, $orderId);
            $this->assign('available_transitions', $availableTransitions);
            $this->assign('current_status', $currentStatus);
            $updateGrant = $this->objectAuthorizationGuard()->check(ObjectAction::UPDATE, $record['scope']);
            $this->assign(
                'expected_grant_version',
                $updateGrant->allowed ? $updateGrant->matchedGrantVersion : 0,
            );
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
            $this->redirect('*/index');
        }
    }
    
    /**
     * 订单编辑页面
     */
    #[Acl('Weline_Order::order_edit', '编辑订单', 'edit', '编辑订单')]
    public function edit()
    {
        $orderId = (int)$this->request->getParam('id');
        
        try {
            $record = $this->requireOrder($orderId, ObjectAction::VIEW);
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        }
        
        try {
            $order = $record['order'];
            $items = $this->orderService->getOrderItems($orderId);
            $displayItems = ObjectManager::getInstance(\Weline\Order\Service\BackendOrderLinePresenter::class)
                ->present($order, $items);

            $fulfillmentService = ObjectManager::getInstance(\Weline\Order\Service\FulfillmentService::class);
            $shipments = $fulfillmentService->getShipments($orderId);

            $historyModel = ObjectManager::getInstance(\Weline\Order\Model\OrderHistory::class);
            $history = $historyModel->reset()
                ->where(\Weline\Order\Model\OrderHistory::schema_fields_ORDER_ID, $orderId)
                ->order(\Weline\Order\Model\OrderHistory::schema_fields_CREATED_AT, 'DESC')
                ->select()
                ->fetch()
                ->getItems();

            $customerPresent = ObjectManager::getInstance(\Weline\Order\Service\BackendOrderListPresenter::class)
                ->present($order);

            $adjustService = ObjectManager::getInstance(\Weline\Order\Service\BackendOrderCustomerAdjustService::class);
            $shippingRaw = $order->getData(OrderModel::schema_fields_SHIPPING_ADDRESS);
            $billingRaw = $order->getData(OrderModel::schema_fields_BILLING_ADDRESS);
            $shippingMap = \is_array($shippingRaw) ? $shippingRaw : (json_decode((string)$shippingRaw, true) ?: []);
            $billingMap = \is_array($billingRaw) ? $billingRaw : (json_decode((string)$billingRaw, true) ?: []);
            if (!\is_array($shippingMap)) {
                $shippingMap = [];
            }
            if (!\is_array($billingMap)) {
                $billingMap = [];
            }

            $totals = ObjectManager::getInstance(\Weline\Order\Service\BackendOrderTotalsPresenter::class)
                ->present($order, $displayItems);

            $currentStatus = (string)$order->getData(OrderModel::schema_fields_STATUS);
            $availableTransitions = $this->stateMachine->getAvailableTransitions($currentStatus);

            $orderTypeRegistry = ObjectManager::getInstance(\Weline\Order\Service\CommerceOrderTypeRegistry::class);
            $orderTypeCode = strtolower(trim((string)$order->getData(OrderModel::schema_fields_ORDER_TYPE)));
            if ($orderTypeCode === '') {
                $orderTypeCode = \Weline\Order\Service\CommerceOrderTypeRegistry::CODE_TOC;
            }
            $orderTypeTone = $orderTypeRegistry->resolveBadgeTone($orderTypeCode);
            if ($orderTypeTone === 'muted') {
                $orderTypeTone = 'info';
            }
            $typePayloadRaw = (string)$order->getData(OrderModel::schema_fields_TYPE_PAYLOAD_JSON);
            $typePayload = [];
            if ($typePayloadRaw !== '') {
                $decoded = json_decode($typePayloadRaw, true);
                if (is_array($decoded)) {
                    $typePayload = $decoded;
                }
            }

            $customerId = (int)($order->getData(OrderModel::schema_fields_CUSTOMER_ID) ?? 0);
            $orderCustomerSelectValue = $customerId > 0 ? (string)$customerId : '';
            $orderCustomerSelectDisplay = trim(
                (string)($customerPresent['customer_name'] ?? '')
                . (
                    ($customerPresent['customer_email'] ?? '') !== ''
                        ? (' <' . (string)$customerPresent['customer_email'] . '>')
                        : ''
                )
            );

            $paymentChrome = ObjectManager::getInstance(\Weline\Order\Service\BackendOrderPaymentChromePresenter::class)
                ->present($order);

            $this->assign('order', $order);
            $this->assign('items', $displayItems);
            $this->assign('customer_present', $customerPresent);
            $this->assign('order_totals', $totals);
            $this->assign('shipping_form', $adjustService->addressFormValues($shippingMap));
            $this->assign('billing_form', $adjustService->addressFormValues($billingMap));
            $this->assign('payment_chrome', $paymentChrome);
            $this->assign('orderCustomerSelectValue', $orderCustomerSelectValue);
            $this->assign('orderCustomerSelectDisplay', $orderCustomerSelectDisplay);
            $this->assign('order_type', $orderTypeCode);
            $this->assign('order_type_label', $orderTypeRegistry->resolveLabel($orderTypeCode));
            $this->assign('order_type_tone', $orderTypeTone);
            $this->assign('type_payload', $typePayload);
            $this->assign('shipments', $shipments);
            $this->assign('history', $history);
            $this->assignStatusFlow($order, $history, $orderId, $paymentChrome);
            $this->assign('current_status', $currentStatus);
            $this->assign('available_transitions', $availableTransitions);
            $updateGrant = $this->objectAuthorizationGuard()->check(ObjectAction::UPDATE, $record['scope']);
            $this->assign(
                'expected_grant_version',
                $updateGrant->allowed ? $updateGrant->matchedGrantVersion : 0,
            );
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
            $this->redirect('*/index');
        }
    }

    /**
     * 顶栏状态轨：订单头 + 退款案例（无渠道 API）。
     *
     * @param list<mixed> $history
     * @param array<string, mixed> $paymentChrome
     */
    private function assignStatusFlow(
        OrderModel $order,
        array $history,
        int $orderId,
        array $paymentChrome = [],
    ): void {
        if ($paymentChrome === []) {
            $paymentChrome = ObjectManager::getInstance(
                \Weline\Order\Service\BackendOrderPaymentChromePresenter::class,
            )->present($order);
        }
        $cases = (new \Weline\Order\Service\BackendOrderOpsPanelPresenter(
            ObjectManager::getInstance(\Weline\Order\Service\OrderTradeAdminCommandService::class),
        ))->refundPanel($orderId)['cases'];
        $this->assign(
            'status_flow',
            ObjectManager::getInstance(
                \Weline\Order\Service\BackendOrderStatusFlowPresenter::class,
            )->present($order, $history, $cases, $paymentChrome),
        );
    }

    /**
     * 管理订单异步办理面板（发货 / 退款 / 支付沟通片段）。
     */
    #[Acl('Weline_Order::order_edit', '编辑订单', 'edit', '编辑订单')]
    public function panel(): string
    {
        $orderId = (int)$this->request->getParam('id');
        $tab = strtolower(trim((string)$this->request->getParam('tab', 'shipment')));
        try {
            $record = $this->requireOrder($orderId, ObjectAction::VIEW);
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        }

        $returnUrl = 'order/backend/order/edit?id=' . $orderId . '&tab=' . rawurlencode($tab);
        $presenter = new \Weline\Order\Service\BackendOrderOpsPanelPresenter(
            ObjectManager::getInstance(\Weline\Order\Service\OrderTradeAdminCommandService::class)
        );
        $scope = $record['scope'];

        if ($tab === 'refund') {
            $payload = $presenter->refundPanel($orderId);
            $grant = $this->objectAuthorizationGuard()->check(ObjectAction::REFUND, $scope);
            $grantVersion = $grant->allowed ? $grant->matchedGrantVersion : 0;
            foreach ($payload['candidates'] as &$row) {
                $row['expected_grant_version'] = $grantVersion;
            }
            unset($row);
            $this->assign('candidates', $payload['candidates']);
            $this->assign('cases', $payload['cases']);
            $this->assign('returnUrl', $returnUrl);

            return $this->template('Weline_Order::templates/Backend/Order/panel/refund.phtml');
        }

        if ($tab === 'comms' || $tab === 'payment') {
            $this->assign('orderId', $orderId);

            return $this->template('Weline_Order::templates/Backend/Order/panel/comms.phtml');
        }

        $payload = $presenter->shipmentPanel($orderId);
        $grant = $this->objectAuthorizationGuard()->check(ObjectAction::FULFILL, $scope);
        $grantVersion = $grant->allowed ? $grant->matchedGrantVersion : 0;
        foreach ($payload['candidates'] as &$row) {
            $row['expected_grant_version'] = $grantVersion;
        }
        unset($row);
        $this->assign('candidates', $payload['candidates']);
        $this->assign('progress', $payload['progress']);
        $this->assign('returnUrl', $returnUrl);

        return $this->template('Weline_Order::templates/Backend/Order/panel/shipment.phtml');
    }
    
    /**
     * 保存订单
     */
    #[Acl('Weline_Order::order_save', '保存订单', 'save', '保存订单')]
    public function save()
    {
        $data = $this->request->getPost();
        $orderId = isset($data['order_id']) ? (int)$data['order_id'] : 0;
        
        try {
            if ($orderId) {
                $record = $this->requireOrder($orderId, ObjectAction::UPDATE);
                $this->objectAuthorizationGuard()->requireSubmitForQuery(
                    ObjectAction::UPDATE,
                    $record['scope'],
                    $this->expectedGrantVersion(),
                );
                $order = $record['order'];
                $adjustService = ObjectManager::getInstance(\Weline\Order\Service\BackendOrderCustomerAdjustService::class);
                $shippingRaw = $order->getData(OrderModel::schema_fields_SHIPPING_ADDRESS);
                $billingRaw = $order->getData(OrderModel::schema_fields_BILLING_ADDRESS);
                $shippingMap = \is_array($shippingRaw) ? $shippingRaw : (json_decode((string)$shippingRaw, true) ?: []);
                $billingMap = \is_array($billingRaw) ? $billingRaw : (json_decode((string)$billingRaw, true) ?: []);
                $payload = $adjustService->normalizeUpdatePayload(
                    $data,
                    \is_array($shippingMap) ? $shippingMap : [],
                    \is_array($billingMap) ? $billingMap : [],
                );
                $this->orderService->updateOrder($orderId, $payload);
                $message = \__('客户调整已保存');
            } else {
                $scope = ObjectManager::getInstance(OrderObjectScopeService::class)
                    ->fromExplicitCreate($data);
                $this->objectAuthorizationGuard()->requireSubmitForQuery(
                    ObjectAction::CREATE,
                    $scope,
                    $this->expectedGrantVersion(),
                );
                $order = $this->orderService->createOrder($data);
                $orderId = $order->getId();
                $message = \__('订单创建成功');
            }
            
            $this->getMessageManager()->addSuccess($message);
            $this->redirect('*/edit?id=' . $orderId);
            
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);
            $this->getMessageManager()->addError($exception->getMessage());
            $this->redirect('*/edit' . ($orderId ? '?id=' . $orderId : ''));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
            $this->redirect('*/edit' . ($orderId ? '?id=' . $orderId : ''));
        }
    }

    /**
     * 仅追加订单备注（不改状态）
     */
    #[Acl('Weline_Order::order_update_status', '更新订单状态', 'refresh', '更新订单状态')]
    public function addComment()
    {
        $orderId = (int)$this->request->getPost('order_id');
        $comment = trim((string)$this->request->getPost('comment', ''));
        $notifyCustomer = (bool)$this->request->getPost('notify_customer', false);

        try {
            $record = $this->requireOrder($orderId, ObjectAction::UPDATE);
            $this->objectAuthorizationGuard()->requireSubmitForQuery(
                ObjectAction::UPDATE,
                $record['scope'],
                $this->expectedGrantVersion(),
            );
            $this->orderService->addOrderComment($orderId, $comment, $notifyCustomer);
            $this->getMessageManager()->addSuccess(\__('备注已添加'));
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);
            $this->getMessageManager()->addError($exception->getMessage());
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }

        $this->redirect('*/edit?id=' . $orderId);
    }
    
    /**
     * 取消订单
     */
    #[Acl('Weline_Order::order_cancel', '取消订单', 'close', '取消订单')]
    public function cancel()
    {
        $orderId = (int)$this->request->getParam('id');
        $reason = trim((string)$this->request->getPost('reason', ''));
        
        try {
            $record = $this->requireOrder($orderId, ObjectAction::UPDATE);
            $this->objectAuthorizationGuard()->requireSubmitForQuery(
                ObjectAction::UPDATE,
                $record['scope'],
                $this->expectedGrantVersion(),
            );
            $this->orderService->cancelOrder($orderId, $reason);
            $this->getMessageManager()->addSuccess(\__('订单取消成功'));
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);
            $this->getMessageManager()->addError($exception->getMessage());
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        $this->redirect('*/edit?id=' . $orderId);
    }
    
    /**
     * 更新订单状态
     */
    #[Acl('Weline_Order::order_update_status', '更新订单状态', 'refresh', '更新订单状态')]
    public function updateStatus()
    {
        $orderId = (int)$this->request->getPost('order_id');
        $newStatus = trim((string)$this->request->getPost('status'));
        $comment = trim((string)$this->request->getPost('comment', ''));
        $notifyCustomer = (bool)$this->request->getPost('notify_customer', false);
        
        if (!$newStatus) {
            $this->getMessageManager()->addError(\__('参数错误'));
            $this->redirect('*/index');
            return;
        }
        
        try {
            $record = $this->requireOrder($orderId, ObjectAction::UPDATE);
            $this->objectAuthorizationGuard()->requireSubmitForQuery(
                ObjectAction::UPDATE,
                $record['scope'],
                $this->expectedGrantVersion(),
            );
            $this->stateMachine->transition($orderId, $newStatus, $comment, $notifyCustomer);
            $this->getMessageManager()->addSuccess(\__('订单状态更新成功'));
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);
            $this->getMessageManager()->addError($exception->getMessage());
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        $this->redirect('*/edit?id=' . $orderId);
    }

    /**
     * @return array{order:OrderModel,scope:ScopeIdentity}
     */
    private function requireOrder(int $orderId, string $action): array
    {
        $record = ObjectManager::getInstance(OrderObjectAccessService::class)->find($orderId);
        if ($record === null) {
            $this->objectAuthorizationGuard()->denyForQuery($action, ScopeIdentity::global());
        }
        $this->objectAuthorizationGuard()->requireForQuery($action, $record['scope']);

        return $record;
    }

    private function objectAuthorizationGuard(): BackendObjectAuthorizationGuardInterface
    {
        return ObjectManager::getInstance(BackendObjectAuthorizationGuardInterface::class);
    }

    private function expectedGrantVersion(): int
    {
        $value = $this->request->getParam('expected_grant_version', 0);
        if (\is_int($value) && $value > 0) {
            return $value;
        }
        if (\is_string($value) && \preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            return (int)$value;
        }

        return 0;
    }
}
