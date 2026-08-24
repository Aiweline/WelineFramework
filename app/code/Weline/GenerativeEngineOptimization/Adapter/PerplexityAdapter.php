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
use Weline\GenerativeEngineOptimization\Service\SecretStoreService;
use Weline\Framework\Manager\ObjectManager;

/**
 * Perplexity适配器
 * 
 * @package Weline_GenerativeEngineOptimization
 */
class PerplexityAdapter extends BaseAdapter
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        parent::__construct('perplexity', 'json_feed', 'https://api.perplexity.ai/feeds');
    }

    /**
     * @inheritDoc
     */
    public function generateFeed(array $items): string
    {
        // 生成JSON Feed格式
        $feed = [
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 'Perplexity Feed',
            'items' => [],
        ];

        foreach ($items as $item) {
            $feed['items'][] = [
                'id' => $item['url'] ?? '',
                'url' => $item['url'] ?? '',
                'title' => $item['title'] ?? '',
                'content_text' => $item['content'] ?? '',
                'content_html' => $item['content_html'] ?? $item['content'] ?? '',
                'date_published' => $item['published_at'] ?? date('c'),
                'date_modified' => $item['updated_at'] ?? date('c'),
            ];
        }

        return json_encode($feed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @inheritDoc
     */
    public function pushFeed(string $feed, PlatformAccount $account): PushResult
    {
        try {
            /** @var SecretStoreService $secretStore */
            $secretStore = ObjectManager::getInstance(SecretStoreService::class);
            $apiKey = $secretStore->decryptApiKey($account->getData('api_key'));

            if (empty($apiKey)) {
                return new PushResult(false, 'API密钥解密失败');
            }

            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ];

            $response = $this->sendHttpRequest(
                $this->apiEndpoint,
                $headers,
                'POST',
                $feed
            );

            if ($this->validateResponse($response)) {
                $responseData = json_decode($response['body'], true);
                $itemsCount = count(json_decode($feed, true)['items'] ?? []);
                
                return new PushResult(
                    true,
                    'Feed推送成功',
                    $responseData,
                    $itemsCount
                );
            } else {
                return new PushResult(
                    false,
                    "推送失败: HTTP {$response['http_code']}",
                    ['response' => $response['body']]
                );
            }
        } catch (\Exception $e) {
            return new PushResult(false, $e->getMessage());
        }
    }

    /**
     * 使用Feed URL推送
     *
     * @param string $feedUrl
     * @param PlatformAccount $account
     * @return PushResult
     */
    public function pushFeedUrl(string $feedUrl, PlatformAccount $account): PushResult
    {
        try {
            /** @var SecretStoreService $secretStore */
            $secretStore = ObjectManager::getInstance(SecretStoreService::class);
            $apiKey = $secretStore->decryptApiKey($account->getData('api_key'));

            if (empty($apiKey)) {
                return new PushResult(false, 'API密钥解密失败');
            }

            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ];

            $body = json_encode(['feed_url' => $feedUrl], JSON_UNESCAPED_UNICODE);

            $response = $this->sendHttpRequest(
                $this->apiEndpoint,
                $headers,
                'POST',
                $body ?: ''
            );

            if ($this->validateResponse($response)) {
                $responseData = json_decode($response['body'], true);
                return new PushResult(
                    true,
                    'Feed URL 推送成功',
                    $responseData,
                    1
                );
            }

            return new PushResult(
                false,
                "推送失败: HTTP {$response['http_code']}",
                ['response' => $response['body'], 'url' => $feedUrl]
            );
        } catch (\Exception $e) {
            return new PushResult(false, $e->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    public function testConnection(PlatformAccount $account): bool
    {
        try {
            /** @var SecretStoreService $secretStore */
            $secretStore = ObjectManager::getInstance(SecretStoreService::class);
            $apiKey = $secretStore->decryptApiKey($account->getData('api_key'));

            if (empty($apiKey)) {
                return false;
            }

            $headers = [
                'Authorization: Bearer ' . $apiKey,
            ];

            $response = $this->sendHttpRequest(
                'https://api.perplexity.ai/health',
                $headers,
                'GET'
            );

            return $this->validateResponse($response);
        } catch (\Exception $e) {
            return false;
        }
    }
}
