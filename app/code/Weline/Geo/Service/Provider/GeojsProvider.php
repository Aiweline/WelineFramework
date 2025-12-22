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
 * geojs.io 定位服务提供者
 * 
 * 完全免费，无需API Key，无配额限制
 * API文档：https://www.geojs.io/docs/v1/endpoints/geo/
 */
class GeojsProvider implements GeoProviderInterface
{
    /**
     * 提供者名称
     */
    private const PROVIDER_NAME = 'geojs.io';

    /**
     * 优先级
     */
    private const PRIORITY = 2;

    /**
     * API基础URL
     */
    private const API_URL = 'https://get.geojs.io/v1/ip/geo.json';

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
        $url = self::API_URL;
        if ($ip) {
            $url .= '?ip=' . urlencode($ip);
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
            throw new Exception(__('geojs.io请求失败: %{1}', $error));
        }

        if ($httpCode !== 200) {
            throw new Exception(__('geojs.io返回错误状态码: %{1}', $httpCode));
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(__('geojs.io返回数据解析失败: %{1}', json_last_error_msg()));
        }

        if (empty($data)) {
            throw new Exception(__('geojs.io返回空数据'));
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

