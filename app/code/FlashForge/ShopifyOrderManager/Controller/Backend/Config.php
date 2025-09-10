<?php

namespace FlashForge\ShopifyOrderManager\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use FlashForge\ShopifyOrderManager\Model\FeishuConfig;
use FlashForge\ShopifyOrderManager\Helper\FeishuNotify;

/**
 * 飞书配置控制器
 */
#[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::config_manage', '系统配置', '管理系统配置', '')]
class Config extends BackendController
{
    private FeishuConfig $feishuConfigModel;
    private FeishuNotify $feishuNotify;

    public function __init()
    {
        parent::__init();
        $this->feishuConfigModel = ObjectManager::getInstance(FeishuConfig::class);
        $this->feishuNotify = ObjectManager::getInstance(FeishuNotify::class);
    }

    /**
     * 飞书配置页面
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::feishu_config', '飞书配置', '', '配置飞书通知')]
    public function feishu()
    {
        // 获取当前配置
        $config = $this->feishuConfigModel->getActiveConfig();
        
        if ($config) {
            // 解析通知关键词
            $config['notify_keywords'] = json_decode($config['notify_keywords'] ?: '[]', true);
        }

        $this->assign('config', $config ?: []);
        $this->assign('title', '飞书通知配置');
        return $this->fetch();
    }

    /**
     * 保存飞书配置
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::feishu_save', '保存飞书配置', '', '保存飞书通知配置')]
    public function postSaveFeishu()
    {
        try {
            $data = $this->request->getPost();

            // 验证必填字段
            if (empty($data['webhook_url'])) {
                return $this->fetchJson([
                    'code' => 1,
                    'msg' => 'Webhook URL不能为空'
                ]);
            }

            // 验证URL格式
            if (!filter_var($data['webhook_url'], FILTER_VALIDATE_URL)) {
                return $this->fetchJson([
                    'code' => 1,
                    'msg' => 'Webhook URL格式不正确'
                ]);
            }

            // 处理通知关键词
            $keywords = [];
            if (!empty($data['notify_keywords'])) {
                $keywordLines = explode("\n", $data['notify_keywords']);
                foreach ($keywordLines as $line) {
                    $line = trim($line);
                    if (!empty($line)) {
                        $keywords[] = $line;
                    }
                }
            }

            // 准备保存数据
            $configData = [
                FeishuConfig::fields_WEBHOOK_URL => trim($data['webhook_url']),
                FeishuConfig::fields_SECRET => trim($data['secret'] ?? ''),
                FeishuConfig::fields_ENABLE_ERROR_NOTIFY => intval($data['enable_error_notify'] ?? 1),
                FeishuConfig::fields_ENABLE_OVERDUE_NOTIFY => intval($data['enable_overdue_notify'] ?? 1),
                FeishuConfig::fields_NOTIFY_KEYWORDS => json_encode($keywords, JSON_UNESCAPED_UNICODE)
            ];

            // 保存配置
            $success = $this->feishuConfigModel->saveConfig($configData);

            if ($success) {
                return $this->fetchJson([
                    'code' => 0,
                    'msg' => '配置保存成功'
                ]);
            } else {
                return $this->fetchJson([
                    'code' => 1,
                    'msg' => '配置保存失败'
                ]);
            }

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 1,
                'msg' => '保存失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 测试飞书连接
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::feishu_test', '测试飞书连接', '', '测试飞书通知连接')]
    public function postTestFeishu()
    {
        try {
            $result = $this->feishuNotify->testConnection();

            return $this->fetchJson([
                'code' => $result['success'] ? 0 : 1,
                'msg' => $result['message']
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 1,
                'msg' => '测试失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 发送测试消息
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::feishu_test_message', '发送测试消息', '', '发送飞书测试消息')]
    public function postSendTestMessage()
    {
        try {
            $message = $this->request->getPost('message', '这是一条测试消息');

            $success = $this->feishuNotify->sendCustomMessage($message);

            return $this->fetchJson([
                'code' => $success ? 0 : 1,
                'msg' => $success ? '测试消息发送成功' : '测试消息发送失败'
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 1,
                'msg' => '发送失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 系统状态页面
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::system_status', '系统状态', '', '查看系统状态')]
    public function status()
    {
        try {
            // 获取店铺统计
            $shopModel = ObjectManager::getInstance(\FlashForge\ShopifyOrderManager\Model\Shop::class);
            $orderModel = ObjectManager::getInstance(\FlashForge\ShopifyOrderManager\Model\Order::class);

            $shopStats = [
                'total' => $shopModel->total(),
                'active' => $shopModel->where(\FlashForge\ShopifyOrderManager\Model\Shop::fields_STATUS, 1)->total()
            ];

            // 获取订单统计
            $today = date('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $thisMonth = date('Y-m-01');

            $orderStats = [
                'total' => $orderModel->total(),
                'today' => $orderModel->where(\FlashForge\ShopifyOrderManager\Model\Order::fields_SHOPIFY_CREATED_AT, $today . ' 00:00:00', '>=')
                    ->where(\FlashForge\ShopifyOrderManager\Model\Order::fields_SHOPIFY_CREATED_AT, $today . ' 23:59:59', '<=')
                    ->total(),
                'yesterday' => $orderModel->where(\FlashForge\ShopifyOrderManager\Model\Order::fields_SHOPIFY_CREATED_AT, $yesterday . ' 00:00:00', '>=')
                    ->where(\FlashForge\ShopifyOrderManager\Model\Order::fields_SHOPIFY_CREATED_AT, $yesterday . ' 23:59:59', '<=')
                    ->total(),
                'this_month' => $orderModel->where(\FlashForge\ShopifyOrderManager\Model\Order::fields_SHOPIFY_CREATED_AT, $thisMonth . ' 00:00:00', '>=')
                    ->total(),
                'pending' => $orderModel->where(\FlashForge\ShopifyOrderManager\Model\Order::fields_ORDER_STATUS, \FlashForge\ShopifyOrderManager\Model\Order::STATUS_PENDING)->total(),
                'overdue' => count($orderModel->getOverdueOrders())
            ];

            // 获取最近同步时间
            $recentSyncs = $shopModel->where(\FlashForge\ShopifyOrderManager\Model\Shop::fields_STATUS, 1)
                ->where(\FlashForge\ShopifyOrderManager\Model\Shop::fields_LAST_SYNC, '', '!=')
                ->order(\FlashForge\ShopifyOrderManager\Model\Shop::fields_LAST_SYNC, 'DESC')
                ->limit(5)
                ->select()
                ->fetchArray();

            // 飞书配置状态
            $feishuConfig = $this->feishuConfigModel->getActiveConfig();
            $feishuStatus = [
                'configured' => !empty($feishuConfig),
                'webhook_url' => $feishuConfig['webhook_url'] ?? '',
                'error_notify_enabled' => $feishuConfig['enable_error_notify'] ?? 0,
                'overdue_notify_enabled' => $feishuConfig['enable_overdue_notify'] ?? 0
            ];

            $this->assign('shopStats', $shopStats);
            $this->assign('orderStats', $orderStats);
            $this->assign('recentSyncs', $recentSyncs);
            $this->assign('feishuStatus', $feishuStatus);
            $this->assign('title', '系统状态');
            
            return $this->fetch();

        } catch (\Exception $e) {
            $this->getMessageManager()->addError('获取系统状态失败: ' . $e->getMessage());
            return $this->redirect('*/shop/index');
        }
    }

    /**
     * 手动触发订单同步
     */
    #[\Weline\Framework\Acl\Acl('FlashForge_ShopifyOrderManager::manual_sync', '手动同步', '', '手动触发订单同步')]
    public function postManualSync()
    {
        try {
            $shopId = intval($this->request->getPost('shop_id', 0));

            $orderSyncHelper = ObjectManager::getInstance(\FlashForge\ShopifyOrderManager\Helper\OrderSync::class);

            if ($shopId > 0) {
                // 同步指定店铺
                $shopModel = ObjectManager::getInstance(\FlashForge\ShopifyOrderManager\Model\Shop::class);
                $shop = $shopModel->where(\FlashForge\ShopifyOrderManager\Model\Shop::fields_ID, $shopId)->find()->fetch();
                
                if (!$shop->getId()) {
                    return $this->fetchJson([
                        'code' => 1,
                        'msg' => '店铺不存在'
                    ]);
                }

                $result = $orderSyncHelper->syncShopOrders($shop->getData());
                $results = [$result];
            } else {
                // 同步所有店铺
                $results = $orderSyncHelper->syncAllShops();
            }

            // 统计结果
            $totalShops = count($results);
            $successShops = 0;
            $totalNewOrders = 0;
            $totalUpdatedOrders = 0;
            $errors = [];

            foreach ($results as $result) {
                if ($result['success']) {
                    $successShops++;
                    $totalNewOrders += $result['new_orders'];
                    $totalUpdatedOrders += $result['updated_orders'];
                } else {
                    $errors[] = $result['shop_name'] . ': ' . $result['error'];
                }
            }

            $message = "同步完成 - 店铺: {$successShops}/{$totalShops} 成功";
            $message .= ", 新增订单: {$totalNewOrders}, 更新订单: {$totalUpdatedOrders}";

            if (!empty($errors)) {
                $message .= " | 错误: " . implode('; ', array_slice($errors, 0, 3));
                if (count($errors) > 3) {
                    $message .= '...';
                }
            }

            return $this->fetchJson([
                'code' => 0,
                'msg' => $message,
                'data' => [
                    'total_shops' => $totalShops,
                    'success_shops' => $successShops,
                    'new_orders' => $totalNewOrders,
                    'updated_orders' => $totalUpdatedOrders,
                    'errors' => $errors
                ]
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 1,
                'msg' => '同步失败: ' . $e->getMessage()
            ]);
        }
    }
}
