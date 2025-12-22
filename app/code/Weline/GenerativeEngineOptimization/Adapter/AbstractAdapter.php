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
use Weline\GenerativeEngineOptimization\Model\Platform;
use Weline\GenerativeEngineOptimization\Model\PlatformAccount;
use Weline\GenerativeEngineOptimization\Service\SecretStoreService;

/**
 * 抽象适配器基类
 * 
 * @package Weline_GenerativeEngineOptimization
 */
abstract class AbstractAdapter implements BaseAdapter
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
        $encryptedKey = $account->getData(PlatformAccount::fields_API_KEY);
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
     * @param array $options
     * @return array
     */
    protected function sendHttpRequest(string $url, array $options = []): array
    {
        $ch = curl_init($url);
        
        $defaultOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        
        $mergedOptions = array_merge($defaultOptions, $options);
        
        foreach ($mergedOptions as $key => $value) {
            curl_setopt($ch, $key, $value);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'error' => $error,
                'http_code' => $httpCode,
            ];
        }
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'response' => $response,
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
            'title' => $this->platform->getData(Platform::fields_PLATFORM_NAME) . ' Feed',
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

