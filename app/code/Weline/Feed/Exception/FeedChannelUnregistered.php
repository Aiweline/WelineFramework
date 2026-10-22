<?php

declare(strict_types=1);

namespace Weline\Feed\Exception;

final class FeedChannelUnregistered extends \RuntimeException
{
    public function __construct(public readonly string $channelCode)
    {
        parent::__construct($channelCode);
    }
}
