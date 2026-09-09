<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/** Test boundary for the real ProductCatalogQueryConsumer; no runtime storage. */
function w_query(string $provider, string $operation, array $params = []): array
{
    SearchCategoryScopeFixture::$queries[] = [$provider, $operation, $params];
    return SearchCategoryScopeFixture::$rows;
}

final class SearchCategoryScopeFixture
{
    public static array $rows = [];
    public static array $queries = [];
}
