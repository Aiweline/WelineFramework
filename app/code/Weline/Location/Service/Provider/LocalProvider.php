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
 * 本地自建定位通道
 * 
 * 默认通道，提供基础的IP定位功能
 * 可以扩展为使用本地数据库或缓存
 */
class LocalProvider implements LocationProviderInterface
{
    /**
     * 提供者名称
     */
    private const PROVIDER_NAME = 'local';

    /**
     * 优先级（数字越小优先级越高）
     */
    private const PRIORITY = 0;

    /**
     * 获取IP地址的位置信息
     * 
     * @param string|null $ip IP地址
     * @return array
     * @throws Exception
     */
    public function getLocationByIp(?string $ip = null): array
    {
        // 本地通道暂时返回空数据，触发fallback到其他通道
        // 后续可以扩展为使用本地数据库或缓存
        throw new Exception(__('本地定位服务暂未实现，将使用备用通道'));
    }

    /**
     * 检查提供者是否可用
     * 
     * @return bool
     */
    public function isAvailable(): bool
    {
        // 本地通道默认可用，但实际功能暂未实现
        // 返回false以触发fallback
        return false;
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

