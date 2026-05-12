<?php

declare(strict_types=1);

namespace Weline\Geo\Service;

use Weline\Framework\Event\EventsManager;

class FeedSubmitService
{
    public function __construct(
        private readonly EventsManager $eventsManager
    ) {
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function requestSubmit(string $url, string $scope, array $extra = []): void
    {
        $url = trim($url);
        $scope = trim($scope);
        if ($url === '' || $scope === '') {
            return;
        }

        $this->eventsManager->dispatch('Weline_Geo::integration::feed_submit_request', array_merge($extra, [
            'url' => $url,
            'scope' => $scope,
        ]));
    }

    /**
     * @param string[] $urls
     * @param array<string, mixed> $extra
     */
    public function requestBatch(array $urls, string $scope, array $extra = []): void
    {
        foreach ($urls as $url) {
            $this->requestSubmit((string)$url, $scope, $extra);
        }
    }
}
