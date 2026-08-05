<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Runtime;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestResetException;
use Weline\Framework\Runtime\RequestResetterInterface;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Taglib\Slot;

final class RequestResetter implements RequestResetterInterface
{
    public function resetRequest(): void
    {
        $failures = [];
        try {
            ObjectManager::removeInstance(SlotRendererService::class);
        } catch (\Throwable $throwable) {
            RequestResetException::append($failures, 'slot_renderer_instance', $throwable);
        }

        try {
            ThemeData::resetRequestState();
        } catch (\Throwable $throwable) {
            RequestResetException::append($failures, 'theme_data', $throwable);
        }

        try {
            PreviewTokenService::resetRequestState();
        } catch (\Throwable $throwable) {
            RequestResetException::append($failures, 'preview_token', $throwable);
        }

        try {
            Slot::clearRegisteredSlots();
        } catch (\Throwable $throwable) {
            RequestResetException::append($failures, 'registered_slots', $throwable);
        }

        if ($failures !== []) {
            throw new RequestResetException('theme_request_resetter', $failures);
        }
    }
}
