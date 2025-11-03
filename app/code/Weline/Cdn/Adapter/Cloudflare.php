<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Adapter;

use Weline\Cdn\Api\AdapterInterface;
use Weline\Framework\Exception\Core;

/**
 * Cloudflare CDN适配器
 * 
 * 实现 Cloudflare v4 API 的缓存清理和规则管理功能
 */
class Cloudflare implements AdapterInterface
{
    /**
     * Cloudflare API基础URL
     */
    private const API_BASE_URL = 'https://api.cloudflare.com/client/v4';

    /**
     * @inheritDoc
     */
    public function getAdapterCode(): string
    {
        return 'cloudflare';
    }

    /**
     * @inheritDoc
     */
    public function getAdapterName(): string
    {
        return 'Cloudflare';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Cloudflare CDN服务提供商，支持API Token认证、缓存清理和Cache Rules管理';
    }

    /**
     * @inheritDoc
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * @inheritDoc
     */
    public function purgeEverything(string $zoneId, array $credentials): array
    {
        $url = self::API_BASE_URL . '/zones/' . $zoneId . '/purge_cache';
        
        $response = $this->makeRequest('POST', $url, [
            'purge_everything' => true
        ], $credentials);

        if ($response['success'] ?? false) {
            return [
                'success' => true,
                'message' => __('缓存清理成功')
            ];
        }

        return [
            'success' => false,
            'message' => $response['errors'][0]['message'] ?? __('缓存清理失败')
        ];
    }

    /**
     * @inheritDoc
     */
    public function purgeUrls(string $zoneId, array $urls, array $credentials): array
    {
        if (empty($urls)) {
            return [
                'success' => false,
                'message' => __('URL列表不能为空')
            ];
        }

        $url = self::API_BASE_URL . '/zones/' . $zoneId . '/purge_cache';
        
        $response = $this->makeRequest('POST', $url, [
            'files' => $urls
        ], $credentials);

        if ($response['success'] ?? false) {
            return [
                'success' => true,
                'message' => __('成功清理 %{count} 个URL', ['count' => count($urls)])
            ];
        }

        return [
            'success' => false,
            'message' => $response['errors'][0]['message'] ?? __('URL清理失败')
        ];
    }

    /**
     * @inheritDoc
     */
    public function purgeHosts(string $zoneId, array $hosts, array $credentials): array
    {
        if (empty($hosts)) {
            return [
                'success' => false,
                'message' => __('Host列表不能为空')
            ];
        }

        $url = self::API_BASE_URL . '/zones/' . $zoneId . '/purge_cache';
        
        $response = $this->makeRequest('POST', $url, [
            'hosts' => $hosts
        ], $credentials);

        if ($response['success'] ?? false) {
            return [
                'success' => true,
                'message' => __('成功清理 %{count} 个Host', ['count' => count($hosts)])
            ];
        }

        return [
            'success' => false,
            'message' => $response['errors'][0]['message'] ?? __('Host清理失败')
        ];
    }

    /**
     * @inheritDoc
     */
    public function purgeTags(string $zoneId, array $tags, array $credentials): array
    {
        if (empty($tags)) {
            return [
                'success' => false,
                'message' => __('Tag列表不能为空')
            ];
        }

        $url = self::API_BASE_URL . '/zones/' . $zoneId . '/purge_cache';
        
        $response = $this->makeRequest('POST', $url, [
            'tags' => $tags
        ], $credentials);

        if ($response['success'] ?? false) {
            return [
                'success' => true,
                'message' => __('成功清理 %{count} 个Tag', ['count' => count($tags)])
            ];
        }

        return [
            'success' => false,
            'message' => $response['errors'][0]['message'] ?? __('Tag清理失败')
        ];
    }

    /**
     * @inheritDoc
     */
    public function purgeCacheKeys(string $zoneId, array $keys, array $credentials): array
    {
        if (empty($keys)) {
            return [
                'success' => false,
                'message' => __('Cache Key列表不能为空')
            ];
        }

        $url = self::API_BASE_URL . '/zones/' . $zoneId . '/purge_cache';
        
        $response = $this->makeRequest('POST', $url, [
            'prefixes' => $keys
        ], $credentials);

        if ($response['success'] ?? false) {
            return [
                'success' => true,
                'message' => __('成功清理 %{count} 个Cache Key', ['count' => count($keys)])
            ];
        }

        return [
            'success' => false,
            'message' => $response['errors'][0]['message'] ?? __('Cache Key清理失败')
        ];
    }

    /**
     * @inheritDoc
     */
    public function getRules(string $zoneId, array $credentials): array
    {
        $url = self::API_BASE_URL . '/zones/' . $zoneId . '/rulesets/phases/http_request_cache_settings/entrypoint';
        
        $response = $this->makeRequest('GET', $url, [], $credentials);

        if ($response['success'] ?? false) {
            $rules = $response['result']['rules'] ?? [];
            return is_array($rules) ? $rules : [];
        }

        // 如果获取失败，返回空数组
        return [];
    }

    /**
     * @inheritDoc
     */
    public function putRules(string $zoneId, array $rules, array $credentials): array
    {
        // Cloudflare Cache Rules使用rulesets API
        $url = self::API_BASE_URL . '/zones/' . $zoneId . '/rulesets/phases/http_request_cache_settings/entrypoint';
        
        // 先获取现有ruleset ID
        $getResponse = $this->makeRequest('GET', $url, [], $credentials);
        
        if (!($getResponse['success'] ?? false)) {
            return [
                'success' => false,
                'message' => __('获取现有规则失败')
            ];
        }

        $rulesetId = $getResponse['result']['id'] ?? null;
        
        if ($rulesetId) {
            // 更新现有ruleset
            $updateUrl = self::API_BASE_URL . '/zones/' . $zoneId . '/rulesets/' . $rulesetId;
            $response = $this->makeRequest('PUT', $updateUrl, [
                'rules' => $rules
            ], $credentials);
        } else {
            // 创建新ruleset
            $response = $this->makeRequest('POST', $url, [
                'rules' => $rules
            ], $credentials);
        }

        if ($response['success'] ?? false) {
            return [
                'success' => true,
                'message' => __('规则推送成功，共 %{count} 条', ['count' => count($rules)]),
                'data' => $response['result'] ?? null
            ];
        }

        return [
            'success' => false,
            'message' => $response['errors'][0]['message'] ?? __('规则推送失败')
        ];
    }

    /**
     * @inheritDoc
     */
    public function ensureZone(string $domain, array $credentials): array
    {
        // 搜索Zone
        $url = self::API_BASE_URL . '/zones?name=' . urlencode($domain);
        $response = $this->makeRequest('GET', $url, [], $credentials);

        if ($response['success'] ?? false) {
            $zones = $response['result'] ?? [];
            if (!empty($zones) && isset($zones[0])) {
                return [
                    'zone_id' => (string)($zones[0]['id'] ?? ''),
                    'zone_name' => (string)($zones[0]['name'] ?? $domain)
                ];
            }
        }

        throw new Core(__('Zone不存在: %{1}', [$domain]));
    }

    /**
     * @DESC          # 发送API请求
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param string $method HTTP方法
     * @param string $url 请求URL
     * @param array $data 请求数据
     * @param array $credentials 凭据
     * @return array
     * @throws Core
     */
    private function makeRequest(string $method, string $url, array $data = [], array $credentials = []): array
    {
        // 验证凭据
        $apiToken = $credentials['api_token'] ?? '';
        if (empty($apiToken)) {
            throw new Core(__('Cloudflare API Token未配置'));
        }

        // 初始化cURL
        $ch = curl_init($url);
        
        $headers = [
            'Authorization: Bearer ' . $apiToken,
            'Content-Type: application/json'
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        // 如果是POST或PUT，添加请求体
        if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        // 执行请求
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);

        if ($error) {
            throw new Core(__('请求失败: %{1}', [$error]));
        }

        if ($httpCode >= 400) {
            $decodedResponse = json_decode($response, true);
            $errorMessage = $decodedResponse['errors'][0]['message'] ?? __('HTTP错误: %{1}', [$httpCode]);
            throw new Core($errorMessage);
        }

        $decodedResponse = json_decode($response, true);
        return is_array($decodedResponse) ? $decodedResponse : [];
    }
}

