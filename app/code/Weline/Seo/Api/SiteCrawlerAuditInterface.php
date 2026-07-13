<?php

declare(strict_types=1);

namespace Weline\Seo\Api;

interface SiteCrawlerAuditInterface
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function crawl(array $options): array;
}
