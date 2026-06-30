<?php

declare(strict_types=1);

namespace Weline\Geo\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Geo\Service\FeedSubmitService;

class SeoUrlSubmitObserver implements ObserverInterface
{
    public function __construct(
        private readonly FeedSubmitService $feedSubmitService
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!is_array($data)) {
            return;
        }

        $this->feedSubmitService->requestSubmit(
            (string)($data['url'] ?? ''),
            (string)($data['scope'] ?? ''),
            $data
        );
    }
}
