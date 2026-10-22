<?php

declare(strict_types=1);

namespace Weline\Feed\Api;

use Weline\Feed\Api\Data\FeedChannelAccount;
use Weline\Feed\Api\Data\FeedConnectionHandle;
use Weline\Feed\Api\Data\FeedDefinition;

/**
 * One implementation per connection mode. Callers must not catch a failure and open another mode.
 */
interface FeedConnectionInterface
{
    public function mode(): string;

    public function assertSecrets(FeedChannelAccount $account, FeedDefinition $definition): void;

    public function open(FeedChannelAccount $account, FeedDefinition $definition): FeedConnectionHandle;
}
