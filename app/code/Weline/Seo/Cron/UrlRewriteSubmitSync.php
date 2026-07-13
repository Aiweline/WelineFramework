<?php

declare(strict_types=1);

namespace Weline\Seo\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Seo\Service\UrlRewriteSubmitSyncService;

class UrlRewriteSubmitSync implements CronTaskInterface
{
    public function __construct(private readonly UrlRewriteSubmitSyncService $syncService)
    {
    }

    public function name(): string
    {
        return 'SEO URL rewrite submit sync';
    }

    public function execute_name(): string
    {
        return 'seo_url_rewrite_submit_sync';
    }

    public function tip(): string
    {
        return 'Compares url_rewrite with SEO URL push records and enqueues missing submissions.';
    }

    public function cron_time(): string
    {
        return '*/5 * * * *';
    }

    public function execute(): string
    {
        try {
            $stats = $this->syncService->sync();
            return sprintf(
                'SEO URL rewrite submit sync done: rewrites=%d, urls=%d, accounts=%d, created_tasks=%d, existing=%d, unbound=%d.',
                $stats['rewrites'],
                $stats['urls'],
                $stats['accounts'],
                $stats['created_tasks'],
                $stats['skipped_existing'],
                $stats['skipped_unbound'] ?? 0
            );
        } catch (\Throwable $e) {
            return 'SEO URL rewrite submit sync failed: ' . $e->getMessage();
        }
    }

    public function unlock_timeout(int $minute = 10): int
    {
        return $minute;
    }
}
