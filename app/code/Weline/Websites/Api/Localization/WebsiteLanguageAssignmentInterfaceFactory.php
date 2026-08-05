<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Localization;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\Localization\WebsiteLanguageAssignment;

final class WebsiteLanguageAssignmentInterfaceFactory implements FactoryObjectInterface
{
    public function create(): WebsiteLanguageAssignmentInterface
    {
        return ObjectManager::getInstance(WebsiteLanguageAssignment::class);
    }
}
