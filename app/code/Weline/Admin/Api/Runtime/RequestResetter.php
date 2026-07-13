<?php

declare(strict_types=1);

namespace Weline\Admin\Api\Runtime;

use Weline\Admin\Helper\MenuUrlValidator;
use Weline\Admin\Service\MenuRenderService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestResetterInterface;

final class RequestResetter implements RequestResetterInterface
{
    public function resetRequest(): void
    {
        MenuUrlValidator::resetRequestState();
        ObjectManager::removeInstance(MenuRenderService::class);
    }
}
