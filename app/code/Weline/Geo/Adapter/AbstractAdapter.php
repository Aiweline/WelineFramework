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
use Weline\Geo\Model\Platform;
use Weline\Geo\Model\PlatformAccount;
use Weline\Geo\Service\SecretStoreService;

/**
 * 抽象适配器基类
 * 
 * @package Weline_Geo
 */
abstract class AbstractAdapter extends BaseAdapter
{
    protected Platform $platform;
    protected SecretStoreService $secretStore;

    public function __construct(Platform $platform)
    {
        $this->platform = $platform;
        $this->secretStore = ObjectManager::getInstance(SecretStoreService::class);
    }

    /**
     * 获取解密后的API密钥
     * 
     * @param PlatformAccount $account
     * @return string
     */
    protected function getDecryptedApiKey(PlatformAccount $account): string
    {
        $encryptedKey = $account->getData(PlatformAccount::schema_fields_API_KEY);
        if (empty($encryptedKey)) {
            return '';
        }
        
        $decrypted = $this->secretStore->decryptApiKey($encryptedKey);
        return $decrypted ?? '';
    }

    /**
     * 发送HTTP请求
     * 
     * @param string $url
     * @param array $headers
     * @param string $method
     * @param string $body
     * @return array
     */
    protected function sendHttpRequest(string $url, array $headers = [], string $method = 'POST', string $body = ''): array
    {
        $ch = curl_init($url);
        
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if (!empty($headers)) {
            $curlOptions[CURLOPT_HTTPHEADER] = $headers;
        }
        if ($body !== '') {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }
        
        curl_setopt_array($ch, $curlOptions);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'error' => $error,
                'http_code' => $httpCode,
                'body' => '',
            ];
        }
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'body' => $response,
        ];
    }

    /**
     * 生成JSON Feed格式
     * 
     * @param array $items
     * @return string
     */
    protected function generateJsonFeed(array $items): string
    {
        $feed = [
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => $this->platform->getData(Platform::schema_fields_PLATFORM_NAME) . ' Feed',
            'items' => [],
        ];
        
        foreach ($items as $item) {
            $feedItem = [
                'id' => $item['id'] ?? '',
                'title' => $item['title'] ?? '',
                'content_text' => $item['content'] ?? '',
                'url' => $item['url'] ?? '',
            ];
            
            if (isset($item['published_at'])) {
                $feedItem['date_published'] = date('c', $item['published_at']);
            }
            
            if (isset($item['updated_at'])) {
                $feedItem['date_modified'] = date('c', $item['updated_at']);
            }
            
            $feed['items'][] = $feedItem;
        }
        
        return json_encode($feed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

