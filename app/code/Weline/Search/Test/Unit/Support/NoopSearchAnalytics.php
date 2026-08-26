<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Support;

use Weline\Search\Dto\SearchRequest;
use Weline\Search\Dto\SearchResult;
use Weline\Search\Service\SearchAnalyticsService;

final class NoopSearchAnalytics extends SearchAnalyticsService
{
    public function __construct()
    {
    }

    public function recordQuery(SearchRequest $request, SearchResult $result): void
    {
    }

    public function slowThresholdMs(): int
    {
        return 200;
    }
}
