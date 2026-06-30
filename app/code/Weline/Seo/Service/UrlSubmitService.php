<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

/**
 * @deprecated SEO URL push records are now discovered from url_rewrite by cron.
 */
class UrlSubmitService
{
    public function requestSubmit(string $url, string $scope, array $extra = []): void
    {
        // Intentionally no-op. URL submission is sourced from UrlRewriteSubmitSyncService.
    }

    public function requestBatch(array $urls, string $scope, array $extra = []): void
    {
        // Intentionally no-op. URL submission is sourced from UrlRewriteSubmitSyncService.
    }
}
