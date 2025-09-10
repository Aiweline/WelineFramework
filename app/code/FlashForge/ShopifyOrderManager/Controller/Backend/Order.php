<?php

namespace FlashForge\ShopifyOrderManager\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use FlashForge\ShopifyOrderManager\Model\Order as OrderModel;
use FlashForge\ShopifyOrderManager\Model\OrderItem;
use FlashForge\ShopifyOrderManager\Model\Shop;

/**
 * 订单管理控制器
 */
#[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::order_manage', '订单管理', '管理Shopify订单', '')]
class Order extends BackendController
{
    private OrderModel $orderModel;
    private OrderItem $orderItemModel;
    private Shop $shopModel;

    public function __init()
    {
        parent::__init();
        $this->orderModel = ObjectManager::getInstance(OrderModel::class);
        $this->orderItemModel = ObjectManager::getInstance(OrderItem::class);
        $this->shopModel = ObjectManager::getInstance(Shop::class);
    }

    /**
     * 订单列表页面
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::order_list', '订单列表', '', '查看订单列表')]
    public function index()
    {
        // 获取店铺列表供筛选使用
        $shops = $this->shopModel->select()->fetchArray();
        
        $this->assign('shops', $shops);
        $this->assign('title', '订单管理');
        return $this->fetch();
    }

    /**
     * 获取订单列表数据
     */
    public function getList()
    {
        try {
            $page = intval($this->request->getGet('page', 1));
            $limit = intval($this->request->getGet('limit', 20));
            $shopId = intval($this->request->getGet('shop_id', 0));
            $startDate = $this->request->getGet('start_date', '');
            $endDate = $this->request->getGet('end_date', '');
            $orderStatus = $this->request->getGet('order_status', '');
            $orderNumber = $this->request->getGet('order_number', '');

            $query = $this->orderModel->select();

            // 店铺筛选
            if ($shopId > 0) {
                $query->where(OrderModel::fields_SHOP_ID, $shopId);
            }

            // 时间筛选
            if ($startDate) {
                $query->where(OrderModel::fields_SHOPIFY_CREATED_AT, $startDate . ' 00:00:00', '>=');
            }
            if ($endDate) {
                $query->where(OrderModel::fields_SHOPIFY_CREATED_AT, $endDate . ' 23:59:59', '<=');
            }

            // 订单状态筛选
            if ($orderStatus) {
                $query->where(OrderModel::fields_ORDER_STATUS, $orderStatus);
            }

            // 订单号搜索
            if ($orderNumber) {
                $query->where(OrderModel::fields_ORDER_NUMBER, '%' . $orderNumber . '%', 'like');
            }

            $orders = $query->pagination($page, $limit)
                ->order(OrderModel::fields_SHOPIFY_CREATED_AT, 'DESC')
                ->fetchArray();

            $total = $query->total();

            // 获取店铺信息
            $shopIds = array_unique(array_column($orders, 'shop_id'));
            $shops = [];
            
            if (!empty($shopIds)) {
                $shopList = $this->shopModel
                    ->where(Shop::fields_ID, $shopIds, 'in')
                    ->select()
                    ->fetchArray();
                
                foreach ($shopList as $shop) {
                    $shops[$shop['shop_id']] = $shop;
                }
            }

            // 为订单添加店铺信息
            foreach ($orders as &$order) {
                $order['shop_name'] = $shops[$order['shop_id']]['shop_name'] ?? '未知店铺';
                $order['shop_url'] = $shops[$order['shop_id']]['shop_url'] ?? '';
            }

            return $this->fetchJson([
                'code' => 0,
                'msg' => '获取成功',
                'count' => $total,
                'data' => $orders
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 1,
                'msg' => '获取失败: ' . $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * 订单详情页面
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::order_detail', '订单详情', '', '查看订单详情')]
    public function detail()
    {
        $orderId = intval($this->request->getGet('id'));
        
        if (!$orderId) {
            $this->getMessageManager()->addError('订单ID不能为空');
            return $this->redirect('*/*/index');
        }

        $order = $this->orderModel->where(OrderModel::fields_ID, $orderId)->find()->fetch();
        
        if (!$order->getId()) {
            $this->getMessageManager()->addError('订单不存在');
            return $this->redirect('*/*/index');
        }

        // 获取店铺信息
        $shop = $this->shopModel->where(Shop::fields_ID, $order->getData(OrderModel::fields_SHOP_ID))->find()->fetch();

        // 获取订单项目
        $orderItems = $this->orderItemModel->getItemsByOrderId($orderId);

        // 解析地址信息
        $shippingAddress = json_decode($order->getData(OrderModel::fields_SHIPPING_ADDRESS), true) ?: [];
        $billingAddress = json_decode($order->getData(OrderModel::fields_BILLING_ADDRESS), true) ?: [];

        $this->assign('order', $order->getData());
        $this->assign('shop', $shop->getData());
        $this->assign('orderItems', $orderItems);
        $this->assign('shippingAddress', $shippingAddress);
        $this->assign('billingAddress', $billingAddress);
        $this->assign('title', '订单详情 - ' . $order->getData(OrderModel::fields_ORDER_NUMBER));
        
        return $this->fetch();
    }

    /**
     * 导出订单数据
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::order_export', '导出订单', '', '导出订单数据')]
    public function export()
    {
        try {
            $shopId = intval($this->request->getGet('shop_id', 0));
            $startDate = $this->request->getGet('start_date', '');
            $endDate = $this->request->getGet('end_date', '');
            $format = $this->request->getGet('format', 'csv'); // csv 或 excel

            if (!$startDate || !$endDate) {
                return $this->fetchJson([
                    'code' => 1,
                    'msg' => '请选择时间范围'
                ]);
            }

            // 获取订单数据
            $orders = $this->orderModel->getOrdersByShopAndDateRange($shopId, $startDate, $endDate);

            if (empty($orders)) {
                return $this->fetchJson([
                    'code' => 1,
                    'msg' => '没有找到符合条件的订单'
                ]);
            }

            // 获取店铺信息
            $shopIds = array_unique(array_column($orders, 'shop_id'));
            $shops = [];
            
            if (!empty($shopIds)) {
                $shopList = $this->shopModel
                    ->where(Shop::fields_ID, $shopIds, 'in')
                    ->select()
                    ->fetchArray();
                
                foreach ($shopList as $shop) {
                    $shops[$shop['shop_id']] = $shop;
                }
            }

            // 获取订单项目信息
            $orderIds = array_column($orders, 'order_id');
            $allOrderItems = [];
            
            if (!empty($orderIds)) {
                $orderItems = $this->orderItemModel
                    ->where(OrderItem::fields_ORDER_ID, $orderIds, 'in')
                    ->select()
                    ->fetchArray();
                
                foreach ($orderItems as $item) {
                    $allOrderItems[$item['order_id']][] = $item;
                }
            }

            // 准备导出数据
            $exportData = [];
            
            foreach ($orders as $order) {
                $shopName = $shops[$order['shop_id']]['shop_name'] ?? '未知店铺';
                $orderItems = $allOrderItems[$order['order_id']] ?? [];
                
                if (empty($orderItems)) {
                    // 没有订单项目的情况
                    $exportData[] = [
                        '店铺名称' => $shopName,
                        '订单号' => $order['order_number'],
                        '客户邮箱' => $order['customer_email'],
                        '客户姓名' => $order['customer_name'],
                        '订单状态' => $this->getOrderStatusText($order['order_status']),
                        '支付状态' => $order['financial_status'],
                        '发货状态' => $order['fulfillment_status'],
                        '订单总价' => $order['total_price'],
                        '货币' => $order['currency'],
                        '创建时间' => $order['shopify_created_at'],
                        '产品名称' => '',
                        'SKU' => '',
                        '数量' => '',
                        '单价' => '',
                        '小计' => ''
                    ];
                } else {
                    // 有订单项目的情况，每个项目一行
                    foreach ($orderItems as $item) {
                        $exportData[] = [
                            '店铺名称' => $shopName,
                            '订单号' => $order['order_number'],
                            '客户邮箱' => $order['customer_email'],
                            '客户姓名' => $order['customer_name'],
                            '订单状态' => $this->getOrderStatusText($order['order_status']),
                            '支付状态' => $order['financial_status'],
                            '发货状态' => $order['fulfillment_status'],
                            '订单总价' => $order['total_price'],
                            '货币' => $order['currency'],
                            '创建时间' => $order['shopify_created_at'],
                            '产品名称' => $item['product_title'],
                            'SKU' => $item['sku'],
                            '数量' => $item['quantity'],
                            '单价' => $item['price'],
                            '小计' => $item['quantity'] * $item['price'] - $item['total_discount']
                        ];
                    }
                }
            }

            // 生成文件名
            $filename = 'shopify_orders_' . date('Y-m-d_H-i-s');
            
            if ($format === 'csv') {
                return $this->exportToCsv($exportData, $filename);
            } else {
                return $this->exportToExcel($exportData, $filename);
            }

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 1,
                'msg' => '导出失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 导出为CSV格式
     */
    private function exportToCsv(array $data, string $filename): void
    {
        $filename .= '.csv';
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        
        $output = fopen('php://output', 'w');
        
        // 添加BOM以支持中文
        fwrite($output, "\xEF\xBB\xBF");
        
        // 写入表头
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
            
            // 写入数据
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
        }
        
        fclose($output);
        exit;
    }

    /**
     * 导出为Excel格式（简单实现）
     */
    private function exportToExcel(array $data, string $filename): void
    {
        $filename .= '.xls';
        
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        
        echo "\xEF\xBB\xBF"; // BOM
        
        // 简单的HTML表格格式
        echo '<table border="1">';
        
        if (!empty($data)) {
            // 表头
            echo '<tr>';
            foreach (array_keys($data[0]) as $header) {
                echo '<th>' . htmlspecialchars($header) . '</th>';
            }
            echo '</tr>';
            
            // 数据行
            foreach ($data as $row) {
                echo '<tr>';
                foreach ($row as $cell) {
                    echo '<td>' . htmlspecialchars($cell) . '</td>';
                }
                echo '</tr>';
            }
        }
        
        echo '</table>';
        exit;
    }

    /**
     * 获取订单状态文本
     */
    private function getOrderStatusText(string $status): string
    {
        $statusMap = [
            OrderModel::STATUS_PENDING => '待处理',
            OrderModel::STATUS_PAID => '已支付',
            OrderModel::STATUS_FULFILLED => '已发货',
            OrderModel::STATUS_CANCELLED => '已取消',
            OrderModel::STATUS_REFUNDED => '已退款'
        ];

        return $statusMap[$status] ?? $status;
    }
}
