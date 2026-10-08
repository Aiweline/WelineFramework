<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Runtime;

use Weline\Backend\Block\ThemeConfig;
use Weline\Backend\Service\BackendWarmupContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestResetterInterface;
use Weline\Framework\Runtime\ProcessSharedInterface;


final class RequestResetter implements ProcessSharedInterface, RequestResetterInterface
{
    public function resetRequest(): void
    {
        ObjectManager::removeInstance(ThemeConfig::class);
        BackendWarmupContext::clear();
    }
}
