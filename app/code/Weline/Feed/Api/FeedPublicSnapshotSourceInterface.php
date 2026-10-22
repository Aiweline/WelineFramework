<?php

declare(strict_types=1);

namespace Weline\Feed\Api;

use Weline\Feed\Api\Data\FeedPublicSnapshot;

interface FeedPublicSnapshotSourceInterface
{
    public function findByToken(string $token): ?FeedPublicSnapshot;
}
