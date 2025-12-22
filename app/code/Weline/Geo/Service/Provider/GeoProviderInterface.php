<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Geo\Service\Provider;

/**
 * Geo定位服务提供者接口
 * 
 * 定义统一的定位服务接口，所有定位通道必须实现此接口
 */
interface GeoProviderInterface
{
    /**
     * 获取IP地址的位置信息
     * 
     * @param string|null $ip IP地址，如果为null则获取客户端IP
     * @return array 位置信息数组
     * @throws \Exception
     */
    public function getLocationByIp(?string $ip = null): array;

    /**
     * 检查提供者是否可用
     * 
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * 获取提供者名称
     * 
     * @return string
     */
    public function getName(): string;

    /**
     * 获取提供者优先级
     * 
     * @return int 优先级，数字越小优先级越高
     */
    public function getPriority(): int;
}

