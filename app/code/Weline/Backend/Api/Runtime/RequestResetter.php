<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Runtime;

use Weline\Backend\Block\ThemeConfig;
use Weline\Backend\Service\BackendWarmupContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestResetterInterface;

final class RequestResetter implements RequestResetterInterface
{
    public function resetRequest(): void
    {
        ObjectManager::removeInstance(ThemeConfig::class);
        BackendWarmupContext::clear();
    }
}
