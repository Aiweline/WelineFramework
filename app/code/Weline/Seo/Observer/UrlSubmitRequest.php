<?php

declare(strict_types=1);

namespace Weline\Seo\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Seo\Service\UrlSubmitService;

class UrlSubmitRequest implements ObserverInterface
{
    public function __construct(private readonly UrlSubmitService $urlSubmitService)
    {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!is_array($data)) {
            return;
        }

        $scope = trim((string)($data['scope'] ?? ''));
        if ($scope === '') {
            return;
        }

        $targets = $data['targets'] ?? null;
        if (is_array($targets)) {
            $event->setData('seo_url_submit_result', $this->urlSubmitService->requestTargets($targets, $scope, $data));
            return;
        }

        $urls = $data['urls'] ?? null;
        if (is_array($urls)) {
            $event->setData('seo_url_submit_result', $this->urlSubmitService->requestBatch($urls, $scope, $data));
            return;
        }

        $url = trim((string)($data['url'] ?? ''));
        if ($url !== '') {
            $event->setData('seo_url_submit_result', $this->urlSubmitService->requestSubmit($url, $scope, $data));
        }
    }
}
