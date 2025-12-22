<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\GenerativeEngineOptimization\Adapter;

use Weline\GenerativeEngineOptimization\Model\PlatformAccount;

/**
 * 推送结果类
 */
class PushResult
{
    public bool $success;
    public string $message;
    public array $responseData;
    public int $itemsCount;

    public function __construct(bool $success, string $message = '', array $responseData = [], int $itemsCount = 0)
    {
        $this->success = $success;
        $this->message = $message;
        $this->responseData = $responseData;
        $this->itemsCount = $itemsCount;
    }
}

/**
 * 基础适配器接口
 * 
 * @package Weline_GenerativeEngineOptimization
 */
interface BaseAdapterInterface
{
    /**
     * 生成Feed
     * 
     * @param array $items Feed条目数组
     * @return string 生成的Feed内容
     */
    public function generateFeed(array $items): string;

    /**
     * 推送Feed到平台
     * 
     * @param string $feed Feed内容
     * @param PlatformAccount $account 平台账户
     * @return PushResult 推送结果
     */
    public function pushFeed(string $feed, PlatformAccount $account): PushResult;

    /**
     * 推送Feed URL到平台
     *
     * 一些平台更偏好接收Feed的URL而不是完整内容。
     * 默认实现可以在具体适配器中覆盖。
     *
     * @param string $feedUrl Feed访问URL
     * @param PlatformAccount $account 平台账户
     * @return PushResult 推送结果
     */
    public function pushFeedUrl(string $feedUrl, PlatformAccount $account): PushResult;

    /**
     * 测试连接
     * 
     * @param PlatformAccount $account 平台账户
     * @return bool 是否连接成功
     */
    public function testConnection(PlatformAccount $account): bool;
}

/**
 * 基础适配器抽象类
 * 
 * @package Weline_GenerativeEngineOptimization
 */
abstract class BaseAdapter implements BaseAdapterInterface
{
    /**
     * 平台代码
     */
    protected string $platformCode;

    /**
     * Feed格式
     */
    protected string $feedFormat;

    /**
     * API端点
     */
    protected string $apiEndpoint;

    /**
     * 构造函数
     * 
     * @param string $platformCode 平台代码
     * @param string $feedFormat Feed格式
     * @param string $apiEndpoint API端点
     */
    public function __construct(string $platformCode, string $feedFormat = 'json_feed', string $apiEndpoint = '')
    {
        $this->platformCode = $platformCode;
        $this->feedFormat = $feedFormat;
        $this->apiEndpoint = $apiEndpoint;
    }

    /**
     * 获取平台代码
     * 
     * @return string
     */
    public function getPlatformCode(): string
    {
        return $this->platformCode;
    }

    /**
     * 获取Feed格式
     * 
     * @return string
     */
    public function getFeedFormat(): string
    {
        return $this->feedFormat;
    }

    /**
     * 获取API端点
     * 
     * @return string
     */
    public function getApiEndpoint(): string
    {
        return $this->apiEndpoint;
    }

    /**
     * 发送HTTP请求
     * 
     * @param string $url URL
     * @param array $headers 请求头
     * @param string $method HTTP方法
     * @param string $body 请求体
     * @return array 响应数据
     * @throws \Exception
     */
    protected function sendHttpRequest(string $url, array $headers = [], string $method = 'POST', string $body = ''): array
    {
        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);

        if ($error) {
            throw new \Exception("HTTP请求失败: {$error}");
        }

        return [
            'http_code' => $httpCode,
            'body' => $response,
        ];
    }

    /**
     * 验证推送结果
     * 
     * @param array $response 响应数据
     * @return bool 是否成功
     */
    protected function validateResponse(array $response): bool
    {
        $httpCode = $response['http_code'] ?? 0;
        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * 默认的 URL 推送实现：将URL封装为简单JSON再调用 pushFeed
     *
     * 具体平台可覆盖此方法以实现更精细的行为。
     *
     * @param string $feedUrl
     * @param PlatformAccount $account
     * @return PushResult
     */
    public function pushFeedUrl(string $feedUrl, PlatformAccount $account): PushResult
    {
        $payload = json_encode(['feed_url' => $feedUrl], JSON_UNESCAPED_UNICODE);
        return $this->pushFeed($payload ?: '', $account);
    }
}
