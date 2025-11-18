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
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::order_list', '订单列表', '', '获取订单列表数据')]
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
            
            // 调试信息
            error_log("Order filter params - page: {$page}, limit: {$limit}, shop_id: {$shopId}, start_date: {$startDate}, end_date: {$endDate}");

            $query = $this->orderModel->select();

            // 店铺筛选
            if ($shopId > 0) {
                $query->where(OrderModel::fields_SHOP_ID, $shopId);
            }

            // 时间筛选
            if ($startDate) {
                $startDateTime = $startDate . ' 00:00:00';
                $query->where(OrderModel::fields_SHOPIFY_CREATED_AT, $startDateTime, '>=');
                error_log("Date filter - start_date: {$startDateTime}");
            }
            if ($endDate) {
                $endDateTime = $endDate . ' 23:59:59';
                $query->where(OrderModel::fields_SHOPIFY_CREATED_AT, $endDateTime, '<=');
                error_log("Date filter - end_date: {$endDateTime}");
            }

            // 订单状态筛选
            if ($orderStatus) {
                $query->where(OrderModel::fields_ORDER_STATUS, $orderStatus);
            }

            // 订单号搜索
            if ($orderNumber) {
                $query->where(OrderModel::fields_ORDER_NUMBER, '%' . $orderNumber . '%', 'like');
            }

            // 先获取总数
            $total = $query->total();
            
            // 重新构建查询获取分页数据
            $query = $this->orderModel->select();
            
            // 重新应用筛选条件
            if ($shopId > 0) {
                $query->where(OrderModel::fields_SHOP_ID, $shopId);
            }
            if ($startDate) {
                $startDateTime = $startDate . ' 00:00:00';
                $query->where(OrderModel::fields_SHOPIFY_CREATED_AT, $startDateTime, '>=');
            }
            if ($endDate) {
                $endDateTime = $endDate . ' 23:59:59';
                $query->where(OrderModel::fields_SHOPIFY_CREATED_AT, $endDateTime, '<=');
            }
            if ($orderStatus) {
                $query->where(OrderModel::fields_ORDER_STATUS, $orderStatus);
            }
            if ($orderNumber) {
                $query->where(OrderModel::fields_ORDER_NUMBER, '%' . $orderNumber . '%', 'like');
            }
            
            // 获取分页数据
            $orders = $query->order(OrderModel::fields_SHOPIFY_CREATED_AT, 'DESC')
                ->limit($limit, ($page - 1) * $limit)
                ->select()
                ->fetchArray();
            
            // 调试信息
            error_log("Order query result - total: {$total}, returned: " . count($orders));

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

            // 获取统计数据
            $stats = $this->getOrderStats($shopId, $startDate, $endDate, $orderStatus, $orderNumber);

            return $this->fetchJson([
                'code' => 0,
                'msg' => '获取成功',
                'count' => $total,
                'data' => $orders,
                'stats' => $stats
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
     * 获取订单统计数据
     */
    private function getOrderStats(int $shopId = 0, string $startDate = '', string $endDate = '', string $orderStatus = '', string $orderNumber = ''): array
    {
        try {
            // 构建基础查询
            $query = $this->orderModel->select();
            
            // 应用筛选条件
            if ($shopId > 0) {
                $query->where(OrderModel::fields_SHOP_ID, $shopId);
            }
            if ($startDate) {
                $startDateTime = $startDate . ' 00:00:00';
                $query->where(OrderModel::fields_SHOPIFY_CREATED_AT, $startDateTime, '>=');
            }
            if ($endDate) {
                $endDateTime = $endDate . ' 23:59:59';
                $query->where(OrderModel::fields_SHOPIFY_CREATED_AT, $endDateTime, '<=');
            }
            if ($orderStatus) {
                $query->where(OrderModel::fields_ORDER_STATUS, $orderStatus);
            }
            if ($orderNumber) {
                $query->where(OrderModel::fields_ORDER_NUMBER, '%' . $orderNumber . '%', 'like');
            }
            
            // 获取总订单数
            $totalOrders = $query->total();
            
            // 构建WHERE条件
            $whereConditions = [];
            
            if ($shopId > 0) {
                $whereConditions[] = "shop_id = {$shopId}";
            }
            if ($startDate) {
                $startDateTime = $startDate . ' 00:00:00';
                $whereConditions[] = "shopify_created_at >= '{$startDateTime}'";
            }
            if ($endDate) {
                $endDateTime = $endDate . ' 23:59:59';
                $whereConditions[] = "shopify_created_at <= '{$endDateTime}'";
            }
            if ($orderStatus) {
                $whereConditions[] = "order_status = '{$orderStatus}'";
            }
            if ($orderNumber) {
                $whereConditions[] = "order_number LIKE '%{$orderNumber}%'";
            }
            
            $whereClause = !empty($whereConditions) ? ' WHERE ' . implode(' AND ', $whereConditions) : '';
            
            // 获取总销售额
            $revenueSql = "SELECT SUM(total_price) as total_revenue FROM shopify_orders" . $whereClause;
            $revenueResult = $this->orderModel->query($revenueSql)->fetchArray();
            $totalRevenue = $revenueResult[0]['total_revenue'] ?? 0;
            
            // 获取客户数量（去重）
            $customerSql = "SELECT COUNT(DISTINCT customer_email) as customer_count FROM shopify_orders" . $whereClause;
            $customerResult = $this->orderModel->query($customerSql)->fetchArray();
            $customerCount = $customerResult[0]['customer_count'] ?? 0;
            
            return [
                'totalOrders' => $totalOrders,
                'totalRevenue' => number_format($totalRevenue, 2),
                'customerCount' => $customerCount
            ];
            
        } catch (\Exception $e) {
            error_log("Error getting order stats: " . $e->getMessage());
            return [
                'totalOrders' => 0,
                'totalRevenue' => '0.00',
                'customerCount' => 0
            ];
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
                // 返回错误提示文件而不是JSON响应
                $filename = 'shopify_orders_error_' . date('Y-m-d_H-i-s');
                $errorData = [['错误' => '请选择时间范围']];
                if ($format === 'csv') {
                    return $this->exportToCsv($errorData, $filename);
                } else {
                    return $this->exportToExcel($errorData, $filename);
                }
            }

            // 验证时间范围不超过一个季度（90天）
            $startDateTime = new \DateTime($startDate);
            $endDateTime = new \DateTime($endDate);
            $interval = $startDateTime->diff($endDateTime);
            $daysDiff = $interval->days + 1; // 包含开始和结束日期

            if ($daysDiff > 90) {
                $filename = 'shopify_orders_error_' . date('Y-m-d_H-i-s');
                $errorData = [['错误' => "时间范围超过限制：{$daysDiff}天，最多允许导出一个季度（90天）的数据。请缩小时间范围。"]];
                if ($format === 'csv') {
                    return $this->exportToCsv($errorData, $filename);
                } else {
                    return $this->exportToExcel($errorData, $filename);
                }
            }

            // 获取订单数据
            $orders = $this->orderModel->getOrdersByShopAndDateRange($shopId, $startDate, $endDate);

            if (empty($orders)) {
                // 返回空的导出文件而不是JSON响应
                $filename = 'shopify_orders_empty_' . date('Y-m-d_H-i-s');
                if ($format === 'csv') {
                    return $this->exportToCsv([], $filename);
                } else {
                    return $this->exportToExcel([], $filename);
                }
            }

            // 检查订单数量限制（10万条）
            $orderCount = count($orders);
            if ($orderCount > 100000) {
                $filename = 'shopify_orders_error_' . date('Y-m-d_H-i-s');
                $errorData = [['错误' => "订单数量超过限制：{$orderCount}条，最多允许导出10万条记录。请缩小时间范围或添加其他筛选条件。"]];
                if ($format === 'csv') {
                    return $this->exportToCsv($errorData, $filename);
                } else {
                    return $this->exportToExcel($errorData, $filename);
                }
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
                
                // 解析地址信息
                $shippingAddress = $this->parseShippingAddress($order['shipping_address']);
                
                // 计算净额 (subtotal_price - total_discounts)
                $netAmount = floatval($order['subtotal_price']) - floatval($order['total_discounts']);
                
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
                        '净额' => number_format($netAmount, 2),
                        '运费' => number_format($order['total_shipping_price'], 2),
                        '税费' => number_format($order['total_tax'], 2),
                        '价税合计' => number_format($order['total_price'], 2),
                        '数量' => '',
                        '商品名称' => '',
                        '真实价格' => '',
                        '税(单件)' => '',
                        '商品SKU' => '',
                        '州' => $shippingAddress['province'],
                        '国家' => $shippingAddress['country'],
                        '付款方式' => $order['gateway']
                    ];
                } else {
                    // 有订单项目的情况，每个项目一行
                    foreach ($orderItems as $item) {
                        // 计算单件税费
                        $unitTax = $item['quantity'] > 0 ? floatval($item['total_tax']) / intval($item['quantity']) : 0;
                        
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
                            '净额' => number_format($netAmount, 2),
                            '运费' => number_format($order['total_shipping_price'], 2),
                            '税费' => number_format($order['total_tax'], 2),
                            '价税合计' => number_format($order['total_price'], 2),
                            '数量' => $item['quantity'],
                            '商品名称' => $item['product_title'],
                            '真实价格' => number_format($item['price'], 2),
                            '税(单件)' => number_format($unitTax, 2),
                            '商品SKU' => $item['sku'],
                            '州' => $shippingAddress['province'],
                            '国家' => $shippingAddress['country'],
                            '付款方式' => $order['gateway']
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
            // 记录错误日志
            error_log('Export error: ' . $e->getMessage());
            
            // 返回错误提示文件而不是JSON响应
            $filename = 'shopify_orders_error_' . date('Y-m-d_H-i-s');
            $errorData = [['错误' => '导出失败: ' . $e->getMessage()]];
            $format = $this->request->getGet('format', 'csv');
            
            if ($format === 'csv') {
                return $this->exportToCsv($errorData, $filename);
            } else {
                return $this->exportToExcel($errorData, $filename);
            }
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
     * 获取订单详情数据（用于弹窗显示）
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::order_detail', '订单详情', '', '查看订单详情')]
    public function getDetail()
    {
        try {
            $orderId = intval($this->request->getGet('id'));
            
            // 调试信息
            error_log('Order Detail Request - ID: ' . $orderId);
            error_log('Order Detail Request - Raw ID: ' . $this->request->getGet('id'));
            
            if (!$orderId) {
                return $this->fetchJson([
                    'code' => 1,
                    'msg' => '订单ID不能为空，接收到的ID: ' . $this->request->getGet('id')
                ]);
            }

            // 获取订单基本信息
            $order = $this->orderModel->where(OrderModel::fields_ID, $orderId)->find()->fetch();
            
            // 如果通过order_id找不到，尝试通过shopify_order_id查找
            if (!$order->getId()) {
                $order = $this->orderModel->where(OrderModel::fields_SHOPIFY_ORDER_ID, $orderId)->find()->fetch();
            }
            
            if (!$order->getId()) {
                return $this->fetchJson([
                    'code' => 1,
                    'msg' => '订单不存在'
                ]);
            }

            // 获取店铺信息
            $shop = $this->shopModel->where(Shop::fields_ID, $order->getData(OrderModel::fields_SHOP_ID))->find()->fetch();

            // 获取订单项目
            $orderItems = $this->orderItemModel->getItemsByOrderId($orderId);
            
            // 检查并清理重复项目（如果有的话）
            $duplicateCount = $this->orderItemModel->cleanDuplicateItems($orderId);
            if ($duplicateCount > 0) {
                error_log("Order {$orderId}: 清理了 {$duplicateCount} 个重复的订单项目");
                // 重新获取清理后的数据
                $orderItems = $this->orderItemModel->getItemsByOrderId($orderId);
            }

            // 解析地址信息
            $shippingAddress = json_decode($order->getData(OrderModel::fields_SHIPPING_ADDRESS), true) ?: [];
            $billingAddress = json_decode($order->getData(OrderModel::fields_BILLING_ADDRESS), true) ?: [];

            // 从其他字段获取必要信息
            $discountCodes = [];
            $lineItems = [];
            $shippingLines = [];
            $taxLines = [];

            return $this->fetchJson([
                'code' => 0,
                'msg' => '获取成功',
                'data' => [
                    'order' => $order->getData(),
                    'shop' => $shop->getData(),
                    'orderItems' => $orderItems,
                    'shippingAddress' => $shippingAddress,
                    'billingAddress' => $billingAddress,
                    'discountCodes' => $discountCodes,
                    'lineItems' => $lineItems,
                    'shippingLines' => $shippingLines,
                    'taxLines' => $taxLines
                ]
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 1,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
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

    /**
     * 解析收货地址JSON
     */
    private function parseShippingAddress($shippingAddressJson): array
    {
        $address = json_decode($shippingAddressJson, true) ?: [];
        return [
            'province' => $address['province'] ?? '',
            'country' => $address['country'] ?? '',
            'city' => $address['city'] ?? '',
            'zip' => $address['zip'] ?? ''
        ];
    }
}
