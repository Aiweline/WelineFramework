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
    
    // 环境标志
    private bool $isLocal = true;
    private bool $isProd = false;

    /**
     * 构造函数 - 自动检测环境
     */
    public function __construct()
    {
        $this->detectEnvironment();
    }

    /**
     * 检测当前环境
     */
    private function detectEnvironment(): void
    {
        // 检测是否为本地开发环境
        $this->isLocal = $this->isLocalEnvironment();
        
        // 检测是否为生产环境
        $this->isProd = $this->isProductionEnvironment();
    }

    /**
     * 判断是否为本地环境
     */
    private function isLocalEnvironment(): bool
    {
        // 检查多个本地环境标识
        $localIndicators = [
            'localhost',
            '127.0.0.1',
            '::1',
            'local',
            'dev',
            'development'
        ];
        
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? '';
        
        // 检查主机名
        foreach ($localIndicators as $indicator) {
            if (strpos($host, $indicator) !== false) {
                return true;
            }
        }
        
        // 检查环境变量
        if (in_array(strtolower($appEnv), ['local', 'dev', 'development'])) {
            return true;
        }
        
        // 检查是否为CLI环境且没有设置生产标志
        if (php_sapi_name() === 'cli' && !$this->isProductionEnvironment()) {
            return true;
        }
        
        return false;
    }

    /**
     * 判断是否为生产环境
     */
    private function isProductionEnvironment(): bool
    {
        $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? '';
        
        // 检查环境变量
        if (in_array(strtolower($appEnv), ['prod', 'production', 'live'])) {
            return true;
        }
        
        // 检查是否为生产域名（示例）
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        if (strpos($host, '.com') !== false && strpos($host, 'localhost') === false) {
            return true;
        }
        
        return false;
    }

    /**
     * 手动设置环境标志
     */
    public function setEnvironment(bool $isLocal = false, bool $isProd = false): void
    {
        $this->isLocal = $isLocal;
        $this->isProd = $isProd;
    }

    /**
     * 获取当前环境信息
     */
    public function getEnvironmentInfo(): array
    {
        return [
            'isLocal' => $this->isLocal,
            'isProd' => $this->isProd,
            'host' => $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'unknown',
            'app_env' => $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'unknown',
            'sapi' => php_sapi_name()
        ];
    }

    /**
     * 本地环境消息日志记录
     */
    private function logLocalMessage(string $type, string $message, array $keywords = []): void
    {
        $logData = [
            'type' => $type,
            'message' => $message,
            'keywords' => $keywords,
            'timestamp' => date('Y-m-d H:i:s'),
            'environment' => $this->getEnvironmentInfo()
        ];

        // 输出到控制台（CLI环境）
        if (php_sapi_name() === 'cli') {
            echo "\n" . str_repeat('=', 60) . "\n";
            echo "🔔 飞书通知 (本地环境 - 未实际发送)\n";
            echo str_repeat('=', 60) . "\n";
            echo "类型: {$type}\n";
            echo "时间: " . $logData['timestamp'] . "\n";
            if (!empty($keywords)) {
                echo "关键词: " . implode(' | ', $keywords) . "\n";
            }
            echo "消息内容:\n";
            echo $message . "\n";
            echo str_repeat('=', 60) . "\n\n";
        }

        // 记录到日志文件
        $logMessage = "[{$type}] " . json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $logFile = BP . 'var' . DS . 'log' . DS . 'feishu_local.log';
        error_log($logMessage, 3, $logFile);
    }

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

        $keywords = ['订单异常', '系统故障'];
        $fullMessage = "🚨 Shopify订单同步错误\n\n" .
                      "错误标题：{$title}\n" .
                      "错误信息：{$message}";

        if (!empty($details)) {
            $fullMessage .= "\n\n详细信息：";
            foreach ($details as $key => $value) {
                $fullMessage .= "\n- {$key}: {$value}";
            }
        }

        $fullMessage .= "\n\n发生时间：" . date('Y-m-d H:i:s');

        // 本地环境不实际发送消息，只记录日志
        if ($this->isLocal) {
            $this->logLocalMessage('ERROR_NOTIFY', $fullMessage, $keywords);
            return true; // 返回true表示"发送成功"
        }

        return $this->sendMessageWithKeywords($fullMessage, $keywords);
    }

    /**
     * 发送订单超时通知
     */
    public function sendOverdueOrderNotify(array $orders): bool
    {
        if (!$this->init() || !$this->config['enable_overdue_notify'] || empty($orders)) {
            return false;
        }

        $keywords = ['超时提醒', '导单系统'];
        $orderCount = count($orders);
        $fullMessage = "⏰ 订单发货超时提醒\n\n" .
                      "发现 {$orderCount} 个订单超过15天未发货：\n\n";

        $maxDisplay = 10; // 最多显示10个订单
        $displayOrders = array_slice($orders, 0, $maxDisplay);

        foreach ($displayOrders as $order) {
            $fullMessage .= 
                "订单号：{$order['order_number']}\n" .
                "店铺：{$order['shop_name']}\n" .
                "创建时间：{$order['shopify_created_at']}\n" .
                "订单金额：{$order['currency']} {$order['total_price']}\n\n";
        }

        if ($orderCount > $maxDisplay) {
            $remaining = $orderCount - $maxDisplay;
            $fullMessage .= "还有 {$remaining} 个订单未显示...\n\n";
        }

        $fullMessage .= "请及时处理发货！\n检查时间：" . date('Y-m-d H:i:s');

        return $this->sendMessageWithKeywords($fullMessage, $keywords);
    }

    /**
     * 发送同步成功通知
     */
    public function sendSyncSuccessNotify(string $shopName, int $orderCount, int $newOrderCount): bool
    {
        if (!$this->init()) {
            return false;
        }

        $keywords = ['同步成功', '导单系统'];
        $fullMessage = "✅ Shopify订单同步完成\n\n" .
                      "店铺：{$shopName}\n" .
                      "总订单数：{$orderCount}\n" .
                      "新增订单：{$newOrderCount}\n" .
                      "同步时间：" . date('Y-m-d H:i:s');

        return $this->sendMessageWithKeywords($fullMessage, $keywords);
    }

    /**
     * 发送自定义消息
     */
    public function sendCustomMessage(string $message): bool
    {
        if (!$this->init()) {
            return false;
        }

        // 本地环境不实际发送消息，只记录日志
        if ($this->isLocal) {
            $this->logLocalMessage('CUSTOM_MESSAGE', $message);
            return true; // 返回true表示"发送成功"
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
     * 发送带关键词的消息
     */
    public function sendMessageWithKeywords(string $message, array $keywords = []): bool
    {
        if (!$this->init()) {
            return false;
        }

        // 获取配置的关键词
        $configKeywords = [];
        if (!empty($this->config['notify_keywords'])) {
            $configKeywords = json_decode($this->config['notify_keywords'], true) ?: [];
        }

        // 合并关键词
        $allKeywords = array_unique(array_merge($keywords, $configKeywords));
        
        // 构建消息内容
        $messageText = $message;
        if (!empty($allKeywords)) {
            $keywordText = implode(' | ', $allKeywords);
            $messageText = "[{$keywordText}]\n\n{$message}";
        }

        // 本地环境不实际发送消息，只记录日志
        if ($this->isLocal) {
            $this->logLocalMessage('MESSAGE_WITH_KEYWORDS', $messageText, $allKeywords);
            return true; // 返回true表示"发送成功"
        }

        $content = [
            'msg_type' => 'text',
            'content' => [
                'text' => $messageText
            ]
        ];

        return $this->sendMessage($content);
    }

    /**
     * 发送导单系统消息
     */
    public function sendOrderImportMessage(string $title, string $message, array $details = []): bool
    {
        $keywords = ['导单系统'];
        $fullMessage = "📦 {$title}\n\n{$message}";
        
        if (!empty($details)) {
            $fullMessage .= "\n\n详细信息：";
            foreach ($details as $key => $value) {
                $fullMessage .= "\n- {$key}: {$value}";
            }
        }
        
        $fullMessage .= "\n\n时间：" . date('Y-m-d H:i:s');
        
        return $this->sendMessageWithKeywords($fullMessage, $keywords);
    }

    /**
     * 发送富文本消息
     */
    public function sendRichTextMessage(string $title, array $content): bool
    {
        if (!$this->init()) {
            return false;
        }

        // 本地环境不实际发送消息，只记录日志
        if ($this->isLocal) {
            $messageText = "标题: {$title}\n内容: " . json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $this->logLocalMessage('RICH_TEXT_MESSAGE', $messageText);
            return true; // 返回true表示"发送成功"
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
            // 验证Webhook URL格式
            if (!$this->validateWebhookUrl($this->config['webhook_url'])) {
                throw new \Exception("Webhook URL格式不正确");
            }

            // 添加签名验证（根据飞书官方PHP示例）
            if (!empty($this->config['secret'])) {
                // 飞书使用秒级时间戳
                $timestamp = time();
                
                // 飞书签名算法：timestamp + "\n" + secret
                $stringToSign = $timestamp . "\n" . $this->config['secret'];
                $sign = base64_encode(hash_hmac('sha256', '', $stringToSign, true));
                
                // 将签名信息添加到请求体中
                $content['timestamp'] = $timestamp;
                $content['sign'] = $sign;
                
                $headers = [
                    'Content-Type: application/json; charset=utf-8'
                ];
            } else {
                $headers = [
                    'Content-Type: application/json; charset=utf-8'
                ];
            }

            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->config['webhook_url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($content, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => 'WelineFramework-FeishuBot/1.0',
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            curl_close($ch);
            
            if ($error) {
                throw new \Exception("网络连接错误: " . $error);
            }
            
            // 根据飞书API文档，成功响应应该是200状态码
            if ($httpCode !== 200) {
                $errorMsg = $this->parseErrorResponse($httpCode, $response);
                throw new \Exception("飞书API错误 ({$httpCode}): " . $errorMsg);
            }
            
            $result = json_decode($response, true);
            
            // 检查飞书API响应
            if (isset($result['code']) && $result['code'] === 0) {
                return true;
            } else {
                $errorMsg = $result['msg'] ?? '未知错误';
                throw new \Exception("飞书API返回错误: " . $errorMsg);
            }
            
        } catch (\Exception $e) {
            // 记录错误日志，但不抛出异常，避免影响主流程
            error_log("飞书通知发送失败: " . $e->getMessage());
            // 在测试连接时抛出异常，以便获取详细错误信息
            if (debug_backtrace()[1]['function'] === 'testConnection') {
                throw $e;
            }
            return false;
        }
    }

    /**
     * 验证Webhook URL格式
     */
    private function validateWebhookUrl(string $url): bool
    {
        // 检查URL格式
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // 检查是否是飞书Webhook URL - 修复正则表达式
        if (!preg_match('/^https:\/\/open\.feishu\.cn\/open-apis\/bot\/v2\/hook\/[a-zA-Z0-9\-]+$/', $url)) {
            return false;
        }
        
        return true;
    }

    /**
     * 解析错误响应
     */
    private function parseErrorResponse(int $httpCode, string $response): string
    {
        // 尝试解析飞书错误响应
        $errorData = json_decode($response, true);
        if ($errorData && isset($errorData['code'])) {
            switch ($errorData['code']) {
                case 19021:
                    return "签名验证失败或时间戳超出1小时范围，请检查签名密钥和时间同步";
                case 19022:
                    return "签名验证失败，请检查签名密钥是否正确";
                case 19023:
                    return "时间戳超出有效范围，请检查系统时间";
                case 99991663:
                    return "机器人不存在或已被删除";
                case 99991664:
                    return "机器人已被禁用";
                default:
                    return "飞书错误 (代码: {$errorData['code']}): " . ($errorData['msg'] ?? '未知错误');
            }
        }
        
        // 检查是否是关键词错误
        if (strpos($response, 'Key Words Not Found') !== false) {
            return "关键词验证失败：消息中未包含机器人配置的关键词，请检查关键词配置";
        }
        
        // HTTP状态码错误
        switch ($httpCode) {
            case 400:
                return "请求参数错误，请检查消息格式";
            case 401:
                return "认证失败，请检查签名密钥";
            case 403:
                return "权限不足，请检查机器人权限";
            case 404:
                return "Webhook URL不存在，请检查URL是否正确";
            case 429:
                return "请求频率过高，请稍后重试";
            case 500:
                return "飞书服务器内部错误";
            default:
                return "HTTP错误 ({$httpCode}): " . $response;
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

        // 检查必要的配置
        if (empty($this->config['webhook_url'])) {
            return [
                'success' => false,
                'message' => 'Webhook URL未配置'
            ];
        }

        // 获取配置的关键词
        $keywords = [];
        if (!empty($this->config['notify_keywords'])) {
            $keywords = json_decode($this->config['notify_keywords'], true) ?: [];
        }
        
        // 如果没有配置关键词，使用默认关键词
        if (empty($keywords)) {
            $keywords = ['导单系统', '系统测试'];
        }
        
        $keywordText = implode(' | ', $keywords);
        $testMessage = [
            'msg_type' => 'text',
            'content' => [
                'text' => "[{$keywordText}]\n\n🧪 飞书通知测试消息\n\n这是一条测试消息，用于验证飞书webhook配置是否正确。\n\n测试时间：" . date('Y-m-d H:i:s')
            ]
        ];

        try {
            $success = $this->sendMessage($testMessage);
            
            if ($success) {
                return [
                    'success' => true,
                    'message' => '飞书通知测试成功'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '飞书通知测试失败：请检查Webhook URL和Secret配置'
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => '飞书通知测试失败：' . $e->getMessage()
            ];
        }
    }
}
