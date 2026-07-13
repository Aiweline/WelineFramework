<?php

declare(strict_types=1);

namespace Weline\Frontend\Api\Runtime;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestResetterInterface;
use Weline\Frontend\Block\ThemeConfig;

final class RequestResetter implements RequestResetterInterface
{
    public function resetRequest(): void
    {
        ObjectManager::removeInstance(ThemeConfig::class);
    }
}
