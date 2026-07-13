<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Runtime;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestResetterInterface;
use Weline\Theme\Helper\LayoutDependencyTracker;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Taglib\Slot;

final class RequestResetter implements RequestResetterInterface
{
    public function resetRequest(): void
    {
        ObjectManager::removeInstance(SlotRendererService::class);
        ThemeData::resetRequestState();
        PreviewTokenService::resetRequestState();
        Slot::clearRegisteredSlots();
        LayoutDependencyTracker::clearCache();
    }
}
