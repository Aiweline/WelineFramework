<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Storage;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Session\SessionFactory;

/**
 * SessionStorageInterface 工厂类
 *
 * 用于 DI 自动注入时创建 Storage 实例。
 * 默认返回 FileStorage，WLS 环境下由 SessionFactory 动态选择。
 */
class SessionStorageInterfaceFactory implements FactoryObjectInterface
{
    public function create(): SessionStorageInterface
    {
        // 使用 SessionFactory 创建适合当前环境的 Storage
        $factory = new SessionFactory();
        return $factory->createStorage();
    }
}
