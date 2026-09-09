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

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function begin(array $options): array;

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public function advance(array $job, int $batchSize = 2): array;

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public function toReport(array $job): array;
}
