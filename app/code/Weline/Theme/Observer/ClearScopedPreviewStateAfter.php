<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewRequestInspector;

class ClearScopedPreviewStateAfter implements ObserverInterface
{
    public function __construct(
        private readonly PreviewRequestInspector $previewRequestInspector,
        private readonly PreviewContextService $previewContextService,
    ) {
    }

    public function execute(Event &$event): void
    {
        if (!$this->previewRequestInspector->shouldKeepPreviewStateOnlyForCurrentRequest()) {
            return;
        }

        try {
            $this->previewContextService->clearContext();
        } catch (\Throwable) {
        }
    }
}
