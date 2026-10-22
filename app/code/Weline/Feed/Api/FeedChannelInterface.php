<?php

declare(strict_types=1);

namespace Weline\Feed\Api;

use Weline\Feed\Api\Data\FeedDefinition;
use Weline\Feed\Api\Data\FeedDocument;
use Weline\Feed\Api\Data\FeedItem;

/**
 * One channel implementation owns its document serialization.
 */
interface FeedChannelInterface
{
    public function channelCode(): string;

    /**
     * @return list<string>
     */
    public function acceptedModes(): array;

    public function contentType(): string;

    public function filenameSuffix(): string;

    /**
     * @param iterable<FeedItem> $items
     */
    public function render(FeedDefinition $definition, iterable $items): FeedDocument;
}
