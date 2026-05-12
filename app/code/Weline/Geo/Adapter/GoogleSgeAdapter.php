<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Geo\Adapter;

use Weline\Geo\Model\PlatformAccount;
use Weline\Geo\Service\SecretStoreService;
use Weline\Framework\Manager\ObjectManager;

/**
 * Google SGE适配器
 * 
 * @package Weline_Geo
 */
class GoogleSgeAdapter extends BaseAdapter
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        parent::__construct('google_sge', 'json_feed', 'https://indexing.googleapis.com/v3/urlNotifications:publish');
    }

    /**
     * @inheritDoc
     */
    public function generateFeed(array $items): string
    {
        // 生成JSON Feed格式
        $feed = [
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 'Google SGE Feed',
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

            $feedData = json_decode($feed, true);
            if (!isset($feedData['items'])) {
                return new PushResult(false, 'Feed格式错误');
            }

            $itemsCount = count($feedData['items']);
            $successCount = 0;
            $errors = [];

            // Google Indexing API需要逐个推送URL
            foreach ($feedData['items'] as $item) {
                $url = $item['url'] ?? '';
                if (empty($url)) {
                    continue;
                }

                $requestBody = [
                    'url' => $url,
                    'type' => 'URL_UPDATED',
                ];

                $headers = [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ];

                try {
                    $response = $this->sendHttpRequest(
                        $this->apiEndpoint,
                        $headers,
                        'POST',
                        json_encode($requestBody)
                    );

                    if ($this->validateResponse($response)) {
                        $successCount++;
                    } else {
                        $errors[] = "URL {$url} 推送失败: HTTP {$response['http_code']}";
                    }
                } catch (\Exception $e) {
                    $errors[] = "URL {$url} 推送失败: {$e->getMessage()}";
                }
            }

            $message = $successCount === $itemsCount 
                ? "成功推送 {$successCount} 个条目" 
                : "成功推送 {$successCount}/{$itemsCount} 个条目";

            return new PushResult(
                $successCount > 0,
                $message,
                [
                    'success_count' => $successCount,
                    'total_count' => $itemsCount,
                    'errors' => $errors,
                ],
                $successCount
            );
        } catch (\Exception $e) {
            return new PushResult(false, $e->getMessage());
        }
    }

    /**
     * 使用Feed URL推送（对Google而言，仍然是逐个URL推送，这里仅作为占位实现）
     *
     * @param string $feedUrl
     * @param PlatformAccount $account
     * @return PushResult
     */
    public function pushFeedUrl(string $feedUrl, PlatformAccount $account): PushResult
    {
        // 对于Google Indexing API，更推荐直接推送内容URL；
        // 这里简单地将Feed URL作为一个URL通知。
        try {
            /** @var SecretStoreService $secretStore */
            $secretStore = ObjectManager::getInstance(SecretStoreService::class);
            $apiKey = $secretStore->decryptApiKey($account->getData('api_key'));

            if (empty($apiKey)) {
                return new PushResult(false, 'API密钥解密失败');
            }

            $requestBody = [
                'url' => $feedUrl,
                'type' => 'URL_UPDATED',
            ];

            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ];

            $response = $this->sendHttpRequest(
                $this->apiEndpoint,
                $headers,
                'POST',
                json_encode($requestBody)
            );

            if ($this->validateResponse($response)) {
                return new PushResult(true, 'Feed URL 推送成功', ['url' => $feedUrl], 1);
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

            // 测试API连接
            $headers = [
                'Authorization: Bearer ' . $apiKey,
            ];

            $response = $this->sendHttpRequest(
                'https://www.googleapis.com/oauth2/v1/tokeninfo',
                $headers,
                'GET'
            );

            return $this->validateResponse($response);
        } catch (\Exception $e) {
            return false;
        }
    }
}
