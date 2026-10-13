<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Sitemap;

use Weline\Seo\Model\SitemapUrl;
use Weline\Seo\Service\SitemapRefreshService;
use Weline\Seo\Service\WebSitemapData;

final class SitemapBakeSource implements SitemapBakeSourceInterface
{
    public function __construct(
        private readonly SitemapRefreshService $refresh,
        private readonly WebSitemapData $sitemaps,
        private readonly SitemapUrl $sitemapUrl,
    ) {
    }

    public function refreshForBake(int $websiteId, string $module = ''): array
    {
        if ($websiteId < 0) {
            return [
                'ok' => false,
                'hard_gate' => false,
                'message' => 'invalid_website_id',
                'website_id' => $websiteId,
            ];
        }
        $result = $this->refresh->refresh($websiteId, $module);
        $hardGate = !empty($result['hard_gate']);
        $ok = !empty($result['success']) || $hardGate;
        $message = (string)($result['message'] ?? ($ok ? 'ok' : 'refresh_failed'));

        return [
            'ok' => $ok,
            'hard_gate' => $hardGate,
            'message' => $message,
            'website_id' => $websiteId,
        ];
    }

    public function listActiveLocs(int $websiteId): array
    {
        if ($websiteId < 0) {
            return [];
        }
        $rows = $this->sitemaps->getActiveUrls($websiteId);
        $locs = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $loc = \trim((string)($row[SitemapUrl::schema_fields_URL] ?? $row['url'] ?? ''));
            if ($loc === '' || isset($seen[$loc])) {
                continue;
            }
            if (!\preg_match('#^https?://#i', $loc)) {
                continue;
            }
            $seen[$loc] = true;
            $locs[] = $loc;
        }

        return $locs;
    }
}
