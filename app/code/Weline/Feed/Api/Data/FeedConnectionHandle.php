<?php

declare(strict_types=1);

namespace Weline\Feed\Api\Data;

final readonly class FeedConnectionHandle
{
    public function __construct(
        public string $mode,
        public string $channelCode,
        public string $publicPath,
    ) {
    }
}
