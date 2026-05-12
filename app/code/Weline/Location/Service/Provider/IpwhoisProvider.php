<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Location\Service\Provider;

use Weline\Framework\App\Exception;

/**
 * ipwhois.app 定位服务提供者
 * 
 * 免费，无需API Key，有配额限制
 * API文档：https://ipwhois.io/documentation
 */
class IpwhoisProvider implements LocationProviderInterface
{
    /**
     * 提供者名称
     */
    private const PROVIDER_NAME = 'ipwhois.app';

    /**
     * 优先级
     */
    private const PRIORITY = 3;

    /**
     * API基础URL
     */
    private const API_URL = 'http://ipwhois.app/json/%s';

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
            throw new Exception(__('ipwhois.app请求失败: %{1}', $error));
        }

        if ($httpCode !== 200) {
            throw new Exception(__('ipwhois.app返回错误状态码: %{1}', $httpCode));
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(__('ipwhois.app返回数据解析失败: %{1}', json_last_error_msg()));
        }

        if (isset($data['success']) && $data['success'] === false) {
            $message = $data['message'] ?? __('定位失败');
            throw new Exception(__('ipwhois.app定位失败: %{1}', $message));
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
            'country' => $data['country'] ?? null,
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

