<?php
declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * ACL 资源树服务接口工厂类
 *
 * 将 ResourceTreeServiceInterface 映射到 ResourceTreeService 实现
 */
class ResourceTreeServiceInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ResourceTreeServiceInterface
    {
        return ObjectManager::getInstance(ResourceTreeService::class);
    }
}
