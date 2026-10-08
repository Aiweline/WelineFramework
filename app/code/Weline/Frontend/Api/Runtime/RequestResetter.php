<?php

declare(strict_types=1);

namespace Weline\Frontend\Api\Runtime;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestResetterInterface;
use Weline\Frontend\Block\ThemeConfig;
use Weline\Framework\Runtime\ProcessSharedInterface;


final class RequestResetter implements ProcessSharedInterface, RequestResetterInterface
{
    public function resetRequest(): void
    {
        ObjectManager::removeInstance(ThemeConfig::class);
    }
}
