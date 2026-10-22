<?php

declare(strict_types=1);

namespace Weline\Feed\Api;

use Weline\Feed\Api\Data\FeedChannelAccount;
use Weline\Feed\Api\Data\FeedDefinition;

interface FeedDefinitionRepositoryInterface
{
    public function find(int $definitionId): ?FeedDefinition;

    public function findAccount(int $accountId): ?FeedChannelAccount;
}
