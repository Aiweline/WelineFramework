<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

use Weline\Feed\Api\Data\FeedPublicDecision;
use Weline\Feed\Api\Data\FeedPublicSnapshot;

/**
 * Request-path decision for a stored artifact. It does not render or reprice.
 */
final class FeedPublicDocumentGate
{
    public function decide(?FeedPublicSnapshot $snapshot): FeedPublicDecision
    {
        if ($snapshot === null
            || !$snapshot->enabled
            || !$snapshot->artifactReady
            || !$snapshot->fileExists
            || $snapshot->body === ''
            || $this->pathIsGeoFeed($snapshot->storagePath)
        ) {
            return FeedPublicDecision::notFound();
        }

        $contentType = trim($snapshot->contentType);
        if ($contentType === '') {
            $contentType = 'text/plain; charset=utf-8';
        }

        return FeedPublicDecision::ready($snapshot->body, $contentType);
    }

    private function pathIsGeoFeed(string $path): bool
    {
        $path = strtolower(str_replace('\\', '/', $path));

        return str_contains($path, '/geo-feed.xml')
            || str_contains($path, '/geo-feed.json')
            || str_contains($path, '/geo-feeds/');
    }
}
