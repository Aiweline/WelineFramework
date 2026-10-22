<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

use Weline\Feed\Api\Data\FeedConnectionMode;
use Weline\Feed\Api\FeedConnectionInterface;
use Weline\Feed\Exception\FeedCredentialsMissing;
use Weline\Feed\Service\Connection\ApiKeyConnection;
use Weline\Feed\Service\Connection\FeedUrlConnection;
use Weline\Feed\Service\Connection\OauthConnection;

/**
 * Returns exactly one connection. A failure must not be retried as another mode.
 */
final class FeedConnectionRegistry
{
    public function __construct(
        private readonly OauthConnection $oauth = new OauthConnection(),
        private readonly FeedUrlConnection $feedUrl = new FeedUrlConnection(),
        private readonly ApiKeyConnection $apiKey = new ApiKeyConnection(),
    ) {
    }

    public function forMode(string $mode): FeedConnectionInterface
    {
        return match ($mode) {
            FeedConnectionMode::OAUTH => $this->oauth,
            FeedConnectionMode::FEED_URL => $this->feedUrl,
            FeedConnectionMode::API_KEY => $this->apiKey,
            default => throw new FeedCredentialsMissing('', $mode, ['connection_mode']),
        };
    }
}
