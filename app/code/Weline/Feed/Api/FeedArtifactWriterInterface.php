<?php

declare(strict_types=1);

namespace Weline\Feed\Api;

use Weline\Feed\Api\Data\FeedChannelAccount;
use Weline\Feed\Api\Data\FeedDefinition;
use Weline\Feed\Api\Data\FeedDocument;

interface FeedArtifactWriterInterface
{
    public function writeFailed(
        FeedDefinition $definition,
        FeedChannelAccount $account,
        string $errorCode,
        string $connectionState,
    ): void;

    public function writeReady(
        FeedDefinition $definition,
        FeedChannelAccount $account,
        FeedDocument $document,
        int $itemCount,
        string $connectionState,
    ): void;
}
