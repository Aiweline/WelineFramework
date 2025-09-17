<?php

namespace FlashForge\ShopifyOrderManager\Helper;

use Weline\Framework\App\Helper;

/**
 * Shopify API服务类
 */
class ShopifyApi extends Helper
{
    private string $shopUrl;
    private string $accessToken;
    private array $headers;

    /**
     * 初始化API配置
     */
    public function init(string $shopUrl, string $accessToken): void
    {
        $this->shopUrl = rtrim($shopUrl, '/');
        $this->accessToken = $accessToken;
        $this->headers = [
            'X-Shopify-Access-Token: ' . $this->accessToken,
            'Content-Type: application/json'
        ];
    }

    /**
     * 获取订单列表
     */
    public function getOrders(array $params = []): array
    {
        $defaultParams = [
            'limit' => 250,
            'status' => 'any'
            // 移除fields限制，获取完整的订单数据
        ];
        
        $params = array_merge($defaultParams, $params);
        $queryString = http_build_query($params);
        
        $url = $this->shopUrl . '/admin/api/2024-10/orders.json?' . $queryString;
        
        return $this->makeRequest($url);
    }

    /**
     * 获取单个订单详情
     */
    public function getOrder(string $orderId): array
    {
        $url = $this->shopUrl . '/admin/api/2024-10/orders/' . $orderId . '.json';
        
        return $this->makeRequest($url);
    }

    /**
     * 获取订单的交易信息
     */
    public function getOrderTransactions(string $orderId): array
    {
        $url = $this->shopUrl . '/admin/api/2024-10/orders/' . $orderId . '/transactions.json';
        
        return $this->makeRequest($url);
    }

    /**
     * 获取订单的真实售价（参考用户提供的文件逻辑）
     */
    public function getOrderRealPrice(string $orderId): array
    {
        // 获取订单详情
        $orderData = $this->getOrder($orderId);
        
        if (empty($orderData['order'])) {
            return [];
        }
        
        $order = $orderData['order'];
        $realPrices = [];
        
        // 处理每个line_item
        foreach ($order['line_items'] as $item) {
            $realPrice = [
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'],
                'title' => $item['title'],
                'sku' => $item['sku'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'total_discount' => $item['total_discount'] ?? 0,
            ];
            
            // 计算实际售价（考虑折扣）
            $originalPrice = floatval($item['price']);
            $discount = floatval($item['total_discount'] ?? 0);
            $realPrice['actual_price'] = $originalPrice - ($discount / $item['quantity']);
            
            $realPrices[] = $realPrice;
        }
        
        return $realPrices;
    }

    /**
     * 获取指定时间范围内的订单
     */
    public function getOrdersByDateRange(string $createdAtMin = '', string $createdAtMax = ''): array
    {
        $params = [];
        
        if ($createdAtMin) {
            $params['created_at_min'] = $createdAtMin;
        }
        
        if ($createdAtMax) {
            $params['created_at_max'] = $createdAtMax;
        }
        
        return $this->getOrders($params);
    }

    /**
     * 获取最近更新的订单
     */
    public function getRecentlyUpdatedOrders(string $updatedAtMin = ''): array
    {
        $params = [];
        
        if ($updatedAtMin) {
            $params['updated_at_min'] = $updatedAtMin;
        } else {
            // 默认获取最近10分钟更新的订单
            $params['updated_at_min'] = date('c', strtotime('-10 minutes'));
        }
        
        return $this->getOrders($params);
    }

    /**
     * 从指定订单ID开始获取订单（避免重复同步）
     */
    public function getOrdersFromId(int $sinceId = 0, string $createdAtMin = ''): array
    {
        $params = [
            'limit' => 250,
            'status' => 'any'
            // 移除fields限制，获取完整的订单数据
        ];
        
        // 从指定ID开始获取（避免重复同步）
        if ($sinceId > 0) {
            $params['since_id'] = $sinceId;
        }
        
        // 只获取近三天的订单
        if ($createdAtMin) {
            $params['created_at_min'] = $createdAtMin;
        } else {
            $params['created_at_min'] = date('c', strtotime('-3 days'));
        }
        
        return $this->getOrders($params);
    }

    /**
     * 执行HTTP请求
     */
    private function makeRequest(string $url, string $method = 'GET', array $data = []): array
    {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        
        if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("cURL错误: " . $error . " (URL: " . $url . ")");
        }
        
        if ($httpCode >= 400) {
            $errorDetails = "HTTP错误: " . $httpCode;
            if ($httpCode === 401) {
                $errorDetails .= " - 未授权访问，请检查访问令牌是否正确";
            } elseif ($httpCode === 403) {
                $errorDetails .= " - 禁止访问，请检查API权限";
            } elseif ($httpCode === 404) {
                $errorDetails .= " - 店铺URL不存在或API端点错误";
            } elseif ($httpCode === 429) {
                $errorDetails .= " - API调用频率超限";
            }
            $errorDetails .= " (URL: " . $url . ", 响应: " . $response . ")";
            throw new \Exception($errorDetails);
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMsg = "JSON解析错误: " . json_last_error_msg();
            $errorMsg .= "\n原始响应: " . substr($response, 0, 500);
            $errorMsg .= "\nHTTP状态码: " . $httpCode;
            $errorMsg .= "\n请求URL: " . $url;
            throw new \Exception($errorMsg);
        }
        
        return $result ?? [];
    }

    /**
     * 验证API连接
     */
    public function testConnection(): bool
    {
        try {
            $url = $this->shopUrl . '/admin/api/2024-10/shop.json';
            $result = $this->makeRequest($url);
            
            return !empty($result['shop']);
        } catch (\Exception $e) {
            // 记录详细错误信息
            error_log("Shopify API连接失败: " . $e->getMessage());
            error_log("店铺URL: " . $this->shopUrl);
            error_log("访问令牌: " . (empty($this->accessToken) ? '未设置' : '已设置'));
            return false;
        }
    }

    /**
     * 获取店铺信息
     */
    public function getShopInfo(): array
    {
        try {
            $url = $this->shopUrl . '/admin/api/2024-10/shop.json';
            $result = $this->makeRequest($url);
            
            return $result['shop'] ?? [];
        } catch (\Exception $e) {
            throw new \Exception("获取店铺信息失败: " . $e->getMessage());
        }
    }

    /**
     * 获取API限制信息
     */
    public function getRateLimitInfo(): array
    {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->shopUrl . '/admin/api/2024-10/shop.json',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        
        curl_close($ch);
        
        $rateLimitInfo = [];
        
        if (preg_match('/X-Shopify-Shop-Api-Call-Limit: (\d+)\/(\d+)/', $headers, $matches)) {
            $rateLimitInfo['current'] = intval($matches[1]);
            $rateLimitInfo['limit'] = intval($matches[2]);
            $rateLimitInfo['remaining'] = $rateLimitInfo['limit'] - $rateLimitInfo['current'];
        }
        
        return $rateLimitInfo;
    }
}
