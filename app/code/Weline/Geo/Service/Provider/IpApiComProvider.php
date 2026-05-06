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
 * ip-api.com 定位服务提供者
 * 
 * 完全免费，无需API Key，有速率限制（45请求/分钟）
 * API文档：http://ip-api.com/docs/api:json
 */
class IpApiComProvider implements GeoProviderInterface
{
    /**
     * 提供者名称
     */
    private const PROVIDER_NAME = 'ip-api.com';

    /**
     * 优先级
     */
    private const PRIORITY = 1;

    /**
     * API基础URL
     */
    private const API_URL = 'http://ip-api.com/json/%s?fields=status,message,country,countryCode,region,regionName,city,lat,lon,timezone,query';

    /**
     * 超时时间（秒）
     */
    private int $timeout = 5;

    /**
     * 构造函数
     * 
     * @param int $timeout 超时时间（秒）
     */
    public function __construct(int $timeout = 5)
    {
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

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception(__('ip-api.com请求失败: %{1}', $error));
        }

        if ($httpCode !== 200) {
            throw new Exception(__('ip-api.com返回错误状态码: %{1}', $httpCode));
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(__('ip-api.com返回数据解析失败: %{1}', json_last_error_msg()));
        }

        if (!isset($data['status']) || $data['status'] !== 'success') {
            $message = $data['message'] ?? __('定位失败');
            throw new Exception(__('ip-api.com定位失败: %{1}', $message));
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
            'latitude' => isset($data['lat']) ? (float)$data['lat'] : null,
            'longitude' => isset($data['lon']) ? (float)$data['lon'] : null,
            'accuracy' => null,
            'country' => $data['country'] ?? null,
            'countryCode' => $data['countryCode'] ?? null,
            'region' => $data['regionName'] ?? null,
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
            $serverIp = \Weline\Framework\Env\WelineEnv::server((string)$key);
            if (!empty($serverIp)) {
                $ip = (string)$serverIp;
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return \Weline\Framework\Env\WelineEnv::server('REMOTE_ADDR', '127.0.0.1');
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

