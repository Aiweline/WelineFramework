<?php

declare(strict_types=1);

namespace WeShop\RMA\Controller\Frontend\RMA;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Order\Service\OrderService;
use WeShop\RMA\Service\RMAService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 退换货页控制器
 * 
 * 支持1种布局变体：
 * - rma_page_1
 * 
 * 布局变体通过主题配置设置：layouts.rma = rma_page_1
 */
class Index extends BaseController
{
    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     */
    protected ?string $layoutType = 'rma';
    
    /**
     * 退换货申请页
     */
    public function index(): string
    {
        $orderId = (int)($this->request->getParam('order_id') ?? 0);
        
        /** @var CustomerSession $customerSession */
        $customerSession = ObjectManager::getInstance(CustomerSession::class);
        $customer = $customerSession->getCustomer();
        
        if (!$customer || !$customer->getId()) {
            $this->getMessageManager()->addError(__('请先登录'));
            return $this->redirect('weshop/customer/account/login');
        }
        
        // 如果有订单ID，获取订单信息
        $order = null;
        if ($orderId > 0) {
            /** @var OrderService $orderService */
            $orderService = ObjectManager::getInstance(OrderService::class);
            $order = $orderService->getOrder($orderId);
            
            // 验证订单是否属于当前用户
            if ($order && (int)$order->getData(\WeShop\Order\Model\Order::fields_customer_id) !== $customer->getId()) {
                $this->getMessageManager()->addError(__('无权访问此订单'));
                return $this->redirect('weshop/order/list');
            }
        }
        
        // 获取用户的退换货记录
        $rmaList = [];
        try {
            /** @var RMAService $rmaService */
            $rmaService = ObjectManager::getInstance(RMAService::class);
            // TODO: 调用RMA服务获取退换货记录
            // $rmaList = $rmaService->getCustomerRMA($customer->getId());
        } catch (\Throwable $e) {
            // RMA服务不存在，使用示例数据
        }
        
        // 格式化订单数据（如果有）
        $orderData = null;
        if ($order) {
            $orderData = [
                'order_id' => $order->getId(),
                'increment_id' => $order->getData(\WeShop\Order\Model\Order::fields_increment_id) ?? '',
                'status' => $order->getData(\WeShop\Order\Model\Order::fields_status) ?? '',
                'total' => $order->getData(\WeShop\Order\Model\Order::fields_total) ?? 0,
                'created_at' => $order->getData(\WeShop\Order\Model\Order::fields_created_at) ?? '',
            ];
        }
        
        // 准备模板数据
        $this->assign('order', $orderData);
        $this->assign('rma_list', $rmaList);
        
        // 设置页面标题
        $this->assign('title', __('退换货申请'));
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/rma/rma_page_1.phtml
        return $this->fetch();
    }
    
    /**
     * 创建退换货申请
     */
    public function postCreate(): string
    {
        $orderId = (int)($this->request->getPost('order_id') ?? 0);
        $type = trim((string)($this->request->getPost('type') ?? 'return')); // return or exchange
        $reason = trim((string)($this->request->getPost('reason') ?? ''));
        $description = trim((string)($this->request->getPost('description') ?? ''));
        
        if (!$orderId) {
            $this->getMessageManager()->addError(__('订单ID不能为空'));
            return $this->redirect('weshop/rma');
        }
        
        if (empty($reason)) {
            $this->getMessageManager()->addError(__('请选择退换货原因'));
            return $this->redirect('weshop/rma?order_id=' . $orderId);
        }
        
        /** @var CustomerSession $customerSession */
        $customerSession = ObjectManager::getInstance(CustomerSession::class);
        $customer = $customerSession->getCustomer();
        
        if (!$customer || !$customer->getId()) {
            $this->getMessageManager()->addError(__('请先登录'));
            return $this->redirect('weshop/customer/account/login');
        }
        
        try {
            /** @var RMAService $rmaService */
            $rmaService = ObjectManager::getInstance(RMAService::class);
            // TODO: 调用RMA服务创建退换货申请
            // $rma = $rmaService->createRMA($orderId, $customer->getId(), $type, $reason, $description);
            
            $this->getMessageManager()->addSuccess(__('退换货申请已提交，我们会在24小时内处理'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        return $this->redirect('weshop/rma?order_id=' . $orderId);
    }
}
