<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\GenerativeEngineOptimization\Adapter;

use Weline\Framework\Manager\ObjectManager;
use Weline\GenerativeEngineOptimization\Model\PlatformAccount;
use Weline\GenerativeEngineOptimization\Service\SecretStoreService;

/**
 * DuckDuckGo平台适配器
 * 
 * @package Weline_GenerativeEngineOptimization
 */
class DuckDuckGoAdapter extends BaseAdapter
{
    public function __construct()
    {
        parent::__construct(
            'duckduckgo',
            'json_feed',
            'https://api.duckduckgo.com/v1/feeds'
        );
    }

    /**
     * @inheritDoc
     */
    public function generateFeed(array $items): string
    {
        return parent::generateFeed($items);
    }

    /**
     * @inheritDoc
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

            $requestBody = ['feed_url' => $feedUrl];

            $response = $this->sendHttpRequest(
                $this->apiEndpoint,
                $headers,
                'POST',
                json_encode($requestBody)
            );

            if ($this->validateResponse($response)) {
                return new PushResult(true, 'Feed URL推送成功', json_decode($response['body'], true), 1);
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
                $this->apiEndpoint . '/test',
                $headers,
                'GET'
            );

            if ($this->validateResponse($response)) {
                return true;
            } else {
                return false;
            }
        } catch (\Exception $e) {
            return false;
        }
    }
}

