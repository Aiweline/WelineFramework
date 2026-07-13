<?php

declare(strict_types=1);

namespace Weline\Api\Api\Documentation;

use Weline\Api\Service\ApiDocService;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class ApiDocumentationProviderInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ApiDocumentationProviderInterface
    {
        return ObjectManager::getInstance(ApiDocService::class);
    }
}
