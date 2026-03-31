<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\PreviewTokenService;

class ResolvePreviewToken implements ObserverInterface
{
    public function __construct(
        private readonly PreviewTokenService $previewTokenService,
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }

        if (!$this->previewTokenService->isPreviewMode()) {
            return;
        }

        $data->setData('is_preview', true);
        $data->setData('preview_token', (string)$this->previewTokenService->getTokenFromRequest());
    }
}

