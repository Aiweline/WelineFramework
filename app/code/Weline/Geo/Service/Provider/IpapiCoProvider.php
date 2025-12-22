<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Geo\Service\Provider;

use Weline\Framework\App\Exception;

/**
 * ipapi.co 定位服务提供者
 * 
 * 免费版有配额限制，需要API Key（可选）
 * API文档：https://ipapi.co/documentation/
 */
class IpapiCoProvider implements GeoProviderInterface
{
    /**
     * 提供者名称
     */
    private const PROVIDER_NAME = 'ipapi.co';

    /**
     * 优先级
     */
    private const PRIORITY = 5;

    /**
     * API基础URL
     */
    private const API_URL = 'https://ipapi.co/%s/json/';

    /**
     * API Key
     */
    private ?string $apiKey = null;

    /**
     * 超时时间（秒）
     */
    private int $timeout = 5;

    /**
     * 构造函数
     * 
     * @param string|null $apiKey API Key（可选）
     * @param int $timeout 超时时间（秒）
     */
    public function __construct(?string $apiKey = null, int $timeout = 5)
    {
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
    }

    /**
     * 获取IP地址的位置信息
     * 
     * @param string|null $ip IP地址
     * @return array
     * @throws Exception
     */
    public function getLocationByIp(?string $ip = null): array
    {
        if (!$ip) {
            $ip = $this->getClientIp();
        }

        $url = sprintf(self::API_URL, $ip);
        if ($this->apiKey) {
            $url .= '?key=' . urlencode($this->apiKey);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception(__('ipapi.co请求失败: %{1}', $error));
        }

        if ($httpCode !== 200) {
            throw new Exception(__('ipapi.co返回错误状态码: %{1}', $httpCode));
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(__('ipapi.co返回数据解析失败: %{1}', json_last_error_msg()));
        }

        if (isset($data['error'])) {
            $errorMessage = is_string($data['error']) ? $data['error'] : ($data['error']['reason'] ?? __('未知错误'));
            throw new Exception(__('ipapi.co定位失败: %{1}', $errorMessage));
        }

        return $this->formatResponse($data);
    }

    /**
     * 格式化响应数据
     * 
     * @param array $data 原始数据
     * @return array
     */
    private function formatResponse(array $data): array
    {
        return [
            'latitude' => isset($data['latitude']) ? (float)$data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float)$data['longitude'] : null,
            'accuracy' => null,
            'country' => $data['country_name'] ?? null,
            'countryCode' => $data['country_code'] ?? null,
            'region' => $data['region'] ?? null,
            'city' => $data['city'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'source' => self::PROVIDER_NAME,
            'success' => true
        ];
    }

    /**
     * 获取客户端IP地址
     * 
     * @return string
     */
    private function getClientIp(): string
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * 检查提供者是否可用
     * 
     * @return bool
     */
    public function isAvailable(): bool
    {
        return function_exists('curl_init');
    }

    /**
     * 获取提供者名称
     * 
     * @return string
     */
    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    /**
     * 获取提供者优先级
     * 
     * @return int
     */
    public function getPriority(): int
    {
        return self::PRIORITY;
    }
}

