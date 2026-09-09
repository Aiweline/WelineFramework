<?php

declare(strict_types=1);

namespace Weline\Geo\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Geo\Service\FeedSubmitService;

/**
 * Bridges SEO URL change notifications into GEO FeedItem writes.
 */
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

        $action = strtolower(trim((string)($data['action'] ?? 'upsert')));
        $scope = trim((string)($data['scope'] ?? $data['subject_type'] ?? ''));
        if ($scope === '') {
            return;
        }

        $targets = $data['targets'] ?? null;
        if (is_array($targets) && $targets !== []) {
            foreach ($targets as $target) {
                if (!is_array($target)) {
                    continue;
                }
                $payload = array_replace($data, $target);
                $this->submitOne($payload, $scope, $action);
            }
            return;
        }

        $this->submitOne($data, $scope, $action);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function submitOne(array $data, string $scope, string $action): void
    {
        $url = trim((string)($data['url'] ?? ''));
        if ($url === '') {
            return;
        }

        if (in_array($action, ['delete', 'unpublish', 'draft'], true)) {
            $data['is_published'] = 0;
        } else {
            $data['is_published'] = (int)($data['is_published'] ?? 1);
        }

        $this->feedSubmitService->requestSubmit($url, $scope, $data);
    }
}
