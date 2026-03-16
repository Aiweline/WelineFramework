<?php

declare(strict_types=1);

namespace Weline\Framework\Event;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * EventRegistryInterface 工厂
 *
 * ObjectManager 在解析接口依赖时会查找同名 Factory。
 * 该工厂负责将 EventRegistryInterface 绑定到默认实现 EventRegistry。
 */
class EventRegistryInterfaceFactory implements FactoryObjectInterface
{
    public function create(): EventRegistryInterface
    {
        return ObjectManager::getInstance(EventRegistry::class);
    }
}

