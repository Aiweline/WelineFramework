<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Strategy;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Session\SessionFactory;

/**
 * SessionStrategyInterface 工厂类
 *
 * 用于 DI 自动注入时创建 Strategy 实例。
 * 默认返回 FpmStrategy，WLS 环境下返回 WlsStrategy。
 */
class SessionStrategyInterfaceFactory implements FactoryObjectInterface
{
    public function create(): SessionStrategyInterface
    {
        // 使用 SessionFactory 创建适合当前环境的 Strategy
        $factory = new SessionFactory();
        return $factory->createStrategy();
    }
}
