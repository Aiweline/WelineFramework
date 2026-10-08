<?php

declare(strict_types=1);

namespace Weline\Admin\Api\Runtime;

use Weline\Admin\Helper\MenuUrlValidator;
use Weline\Admin\Service\MenuRenderService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestResetterInterface;
use Weline\Framework\Runtime\ProcessSharedInterface;


final class RequestResetter implements ProcessSharedInterface, RequestResetterInterface
{
    public function resetRequest(): void
    {
        MenuUrlValidator::resetRequestState();
        ObjectManager::removeInstance(MenuRenderService::class);
    }
}
