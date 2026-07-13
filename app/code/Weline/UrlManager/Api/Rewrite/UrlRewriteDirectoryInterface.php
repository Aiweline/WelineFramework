<?php

declare(strict_types=1);

namespace Weline\UrlManager\Api\Rewrite;

interface UrlRewriteDirectoryInterface
{
    /** @return list<UrlRewriteRecord> */
    public function listNonEmptyRewrites(): array;

    public function findByPath(string $path, int $websiteId): ?UrlRewriteRecord;

    public function currentWebsiteId(): int;
}
