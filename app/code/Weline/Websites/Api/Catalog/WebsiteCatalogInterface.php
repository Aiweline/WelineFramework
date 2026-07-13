<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Catalog;

use Weline\Websites\Api\Catalog\Data\WebsiteSummary;

interface WebsiteCatalogInterface
{
    public function defaultWebsiteId(): int;

    /** @return list<WebsiteSummary> */
    public function all(): array;

    public function count(): int;
}
