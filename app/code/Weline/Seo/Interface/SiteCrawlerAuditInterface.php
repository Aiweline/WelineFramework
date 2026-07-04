<?php

declare(strict_types=1);

namespace Weline\Seo\Interface;

interface SiteCrawlerAuditInterface
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function crawl(array $options): array;
}
