<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Geo\Adapter;

use Weline\Framework\Manager\ObjectManager;
use Weline\Geo\Model\PlatformAccount;
use Weline\Geo\Service\SecretStoreService;

/**
 * 百度AI平台适配器
 * 
 * @package Weline_Geo
 */
class BaiduAiAdapter extends BaseAdapter
{
    public function __construct()
    {
        parent::__construct(
            'baidu_ai',
            'json_feed',
            'https://aip.baidubce.com/rest/2.0/feed/v1/submit'
        );
    }

    /**
     * @inheritDoc
     */
    public function generateFeed(array $items): string
    {
        return json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
            $apiSecret = $secretStore->decryptApiKey($account->getData('api_secret'));

            if (empty($apiKey) || empty($apiSecret)) {
                return new PushResult(false, 'API密钥解密失败');
            }

            $accessToken = $this->getBaiduAccessToken($apiKey, $apiSecret);
            if (empty($accessToken)) {
                return new PushResult(false, '获取Access Token失败');
            }

            $headers = [
                'Content-Type: application/json',
            ];

            $url = $this->apiEndpoint . '?access_token=' . $accessToken;

            $response = $this->sendHttpRequest(
                $url,
                $headers,
                'POST',
                $feed
            );

            if ($this->validateResponse($response)) {
                return new PushResult(true, 'Feed推送成功', json_decode($response['body'], true), 1);
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
    public function pushFeedUrl(string $feedUrl, PlatformAccount $account): PushResult
    {
        try {
            /** @var SecretStoreService $secretStore */
            $secretStore = ObjectManager::getInstance(SecretStoreService::class);
            $apiKey = $secretStore->decryptApiKey($account->getData('api_key'));
            $apiSecret = $secretStore->decryptApiKey($account->getData('api_secret'));

            if (empty($apiKey) || empty($apiSecret)) {
                return new PushResult(false, 'API密钥解密失败');
            }

            // 百度AI使用access_token认证
            $accessToken = $this->getBaiduAccessToken($apiKey, $apiSecret);
            if (empty($accessToken)) {
                return new PushResult(false, '获取Access Token失败');
            }

            $headers = [
                'Content-Type: application/x-www-form-urlencoded',
            ];

            $requestBody = http_build_query([
                'access_token' => $accessToken,
                'feed_url' => $feedUrl,
            ]);

            $response = $this->sendHttpRequest(
                $this->apiEndpoint,
                $headers,
                'POST',
                $requestBody
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
     * 获取百度Access Token
     * 
     * @param string $apiKey
     * @param string $apiSecret
     * @return string
     */
    protected function getBaiduAccessToken(string $apiKey, string $apiSecret): string
    {
        $url = 'https://aip.baidubce.com/oauth/2.0/token';
        $params = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $apiKey,
            'client_secret' => $apiSecret,
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . '?' . $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        return $data['access_token'] ?? '';
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
            $apiSecret = $secretStore->decryptApiKey($account->getData('api_secret'));

            if (empty($apiKey) || empty($apiSecret)) {
                return false;
            }

            $accessToken = $this->getBaiduAccessToken($apiKey, $apiSecret);
            if (empty($accessToken)) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

