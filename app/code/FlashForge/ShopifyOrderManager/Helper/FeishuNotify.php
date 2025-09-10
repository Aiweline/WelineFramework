<?php

namespace FlashForge\ShopifyOrderManager\Helper;

use Weline\Framework\App\Helper;
use FlashForge\ShopifyOrderManager\Model\FeishuConfig;

/**
 * 飞书通知服务类
 */
class FeishuNotify extends Helper
{
    private ?array $config = null;

    /**
     * 初始化飞书配置
     */
    public function init(): bool
    {
        $feishuConfig = new FeishuConfig();
        $this->config = $feishuConfig->getActiveConfig();
        
        return !empty($this->config);
    }

    /**
     * 发送错误通知
     */
    public function sendErrorNotify(string $title, string $message, array $details = []): bool
    {
        if (!$this->init() || !$this->config['enable_error_notify']) {
            return false;
        }

        $content = [
            'msg_type' => 'text',
            'content' => [
                'text' => "🚨 Shopify订单同步错误\n\n" .
                         "错误标题：{$title}\n" .
                         "错误信息：{$message}\n" .
                         "发生时间：" . date('Y-m-d H:i:s')
            ]
        ];

        if (!empty($details)) {
            $detailText = "\n详细信息：\n";
            foreach ($details as $key => $value) {
                $detailText .= "- {$key}: {$value}\n";
            }
            $content['content']['text'] .= $detailText;
        }

        return $this->sendMessage($content);
    }

    /**
     * 发送订单超时通知
     */
    public function sendOverdueOrderNotify(array $orders): bool
    {
        if (!$this->init() || !$this->config['enable_overdue_notify'] || empty($orders)) {
            return false;
        }

        $orderCount = count($orders);
        $content = [
            'msg_type' => 'text',
            'content' => [
                'text' => "⏰ 订单发货超时提醒\n\n" .
                         "发现 {$orderCount} 个订单超过15天未发货：\n\n"
            ]
        ];

        $maxDisplay = 10; // 最多显示10个订单
        $displayOrders = array_slice($orders, 0, $maxDisplay);

        foreach ($displayOrders as $order) {
            $content['content']['text'] .= 
                "订单号：{$order['order_number']}\n" .
                "店铺：{$order['shop_name']}\n" .
                "创建时间：{$order['shopify_created_at']}\n" .
                "订单金额：{$order['currency']} {$order['total_price']}\n\n";
        }

        if ($orderCount > $maxDisplay) {
            $remaining = $orderCount - $maxDisplay;
            $content['content']['text'] .= "还有 {$remaining} 个订单未显示...\n\n";
        }

        $content['content']['text'] .= "请及时处理发货！\n检查时间：" . date('Y-m-d H:i:s');

        return $this->sendMessage($content);
    }

    /**
     * 发送同步成功通知
     */
    public function sendSyncSuccessNotify(string $shopName, int $orderCount, int $newOrderCount): bool
    {
        if (!$this->init()) {
            return false;
        }

        $content = [
            'msg_type' => 'text',
            'content' => [
                'text' => "✅ Shopify订单同步完成\n\n" .
                         "店铺：{$shopName}\n" .
                         "总订单数：{$orderCount}\n" .
                         "新增订单：{$newOrderCount}\n" .
                         "同步时间：" . date('Y-m-d H:i:s')
            ]
        ];

        return $this->sendMessage($content);
    }

    /**
     * 发送自定义消息
     */
    public function sendCustomMessage(string $message): bool
    {
        if (!$this->init()) {
            return false;
        }

        $content = [
            'msg_type' => 'text',
            'content' => [
                'text' => $message
            ]
        ];

        return $this->sendMessage($content);
    }

    /**
     * 发送富文本消息
     */
    public function sendRichTextMessage(string $title, array $content): bool
    {
        if (!$this->init()) {
            return false;
        }

        $message = [
            'msg_type' => 'post',
            'content' => [
                'post' => [
                    'zh_cn' => [
                        'title' => $title,
                        'content' => $content
                    ]
                ]
            ]
        ];

        return $this->sendMessage($message);
    }

    /**
     * 执行消息发送
     */
    private function sendMessage(array $content): bool
    {
        try {
            // 添加签名验证
            if (!empty($this->config['secret'])) {
                $timestamp = time();
                $stringToSign = $timestamp . "\n" . $this->config['secret'];
                $sign = base64_encode(hash_hmac('sha256', $stringToSign, $this->config['secret'], true));
                
                $content['timestamp'] = $timestamp;
                $content['sign'] = $sign;
            }

            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->config['webhook_url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($content),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            curl_close($ch);
            
            if ($error) {
                throw new \Exception("cURL错误: " . $error);
            }
            
            if ($httpCode !== 200) {
                throw new \Exception("HTTP错误: " . $httpCode . " - " . $response);
            }
            
            $result = json_decode($response, true);
            
            return isset($result['code']) && $result['code'] === 0;
            
        } catch (\Exception $e) {
            // 记录错误日志，但不抛出异常，避免影响主流程
            error_log("飞书通知发送失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 测试飞书连接
     */
    public function testConnection(): array
    {
        if (!$this->init()) {
            return [
                'success' => false,
                'message' => '飞书配置未找到或未启用'
            ];
        }

        $testMessage = [
            'msg_type' => 'text',
            'content' => [
                'text' => '🧪 飞书通知测试消息\n\n这是一条测试消息，用于验证飞书webhook配置是否正确。\n\n测试时间：' . date('Y-m-d H:i:s')
            ]
        ];

        $success = $this->sendMessage($testMessage);

        return [
            'success' => $success,
            'message' => $success ? '飞书通知测试成功' : '飞书通知测试失败'
        ];
    }
}
