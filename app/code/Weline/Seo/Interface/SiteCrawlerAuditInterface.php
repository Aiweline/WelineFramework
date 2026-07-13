<?php

declare(strict_types=1);

namespace Weline\Seo\Interface;

/** @deprecated Implement \Weline\Seo\Api\SiteCrawlerAuditInterface. */
interface SiteCrawlerAuditInterface extends \Weline\Seo\Api\SiteCrawlerAuditInterface
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function crawl(array $options): array;
}
