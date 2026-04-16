<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Session\Session;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewRequestInspector;

class ClearScopedPreviewStateAfter implements ObserverInterface
{
    public function __construct(
        private readonly PreviewRequestInspector $previewRequestInspector,
        private readonly PreviewContextService $previewContextService,
        private readonly Session $session,
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

        try {
            $this->session->delete('preview_auto_login');
        } catch (\Throwable) {
        }
    }
}
